<?php
/**
 * AJAX: In-App Bug / FR Reporter — Server-side proxy to Nexus Hub
 *
 * POST /ajax/report-issue
 * Body (JSON):
 *   type        — "bug" | "feature"
 *   severity    — "fatal" | "improvement"
 *   description — Free text (optional, max 2000 chars)
 *   page_slug   — TCH page slug (e.g. "activity", "users")
 *   page_url    — Full URL of the page the report was submitted from
 *   page_title  — Human-readable page title
 *   force       — bool; true when the user clicked "No — submit as new"
 *                 after a duplicate warning
 *
 * Why a proxy?
 *   - The Hub API token must NEVER reach the browser. This file holds it.
 *   - Duplicate detection + confirmation email + activity log integration
 *     are centralised so the widget stays dumb.
 *
 * Talks to the Hub via https://hub.intelligentae.co.uk. The Hub API
 * dispatches on HTTP method (POST = create), accepts a Bearer token in
 * the Authorization header, and reads the project slug from the `project`
 * body field (NOT `project_slug`).
 */

if (!defined('APP_ROOT')) {
    die('Direct access not permitted.');
}

// Mailer is NOT loaded by the front controller — every handler that sends
// email must require it explicitly (same pattern as users_detail.php,
// users_invite.php, forgot_password.php, etc.).
require_once APP_ROOT . '/includes/mailer.php';

header('Content-Type: application/json');

// ── Start the session so $_SESSION is populated ─────────────────────────
// The front controller does NOT start sessions automatically — normal page
// handlers pick it up via requirePagePermission() → requireAuth() →
// initSession(). This AJAX handler bypasses that chain, so we must call
// initSession() explicitly before any isLoggedIn() / $_SESSION read.
initSession();

// ── Auth gate ───────────────────────────────────────────────────────────
// The reporter is admin-only; if someone hits this endpoint unauthenticated
// we return 401 so the widget surfaces a graceful error in the UI.
if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Not authenticated.']);
    exit;
}

$user = currentRealUser(); // real user, not impersonated — we want the
                           // actual human identity on the report
if (!$user) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Session expired.']);
    exit;
}

// ── CSRF gate (header-based for AJAX) ───────────────────────────────────
$csrfHeader = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
if (!validateCsrfToken($csrfHeader)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Invalid CSRF token.']);
    exit;
}

// ── Parse + sanitise input ──────────────────────────────────────────────
$raw   = file_get_contents('php://input');
$input = $raw ? json_decode($raw, true) : null;

if (!is_array($input)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid JSON body.']);
    exit;
}

$type        = in_array($input['type'] ?? '', ['bug', 'feature'], true) ? $input['type'] : 'bug';
$severity    = in_array($input['severity'] ?? '', ['fatal', 'improvement'], true) ? $input['severity'] : 'improvement';
$description = trim(substr((string)($input['description'] ?? ''), 0, 2000));
$pageSlug    = trim(substr((string)($input['page_slug']   ?? ''), 0, 100));
$pageUrl     = trim(substr((string)($input['page_url']    ?? ''), 0, 500));
$pageTitle   = trim(substr((string)($input['page_title']  ?? ''), 0, 200));
$forceSubmit = !empty($input['force']);

// ── Hub configuration ───────────────────────────────────────────────────
$hubUrl   = defined('NEXUS_HUB_URL')          ? rtrim(NEXUS_HUB_URL, '/') : '';
$hubToken = defined('NEXUS_HUB_TOKEN')        ? NEXUS_HUB_TOKEN           : '';
$project  = defined('NEXUS_HUB_PROJECT_SLUG') ? NEXUS_HUB_PROJECT_SLUG    : 'tch';

if ($hubUrl === '' || $hubToken === '' || $hubToken === 'REPLACE_WITH_HUB_API_TOKEN') {
    http_response_code(503);
    echo json_encode(['ok' => false, 'error' => 'Hub integration not configured. Ask Ross for the API token.']);
    exit;
}

// ── Resource name on the Hub API ────────────────────────────────────────
$resource = ($type === 'bug') ? 'bugs' : 'features';

// ── Duplicate check ─────────────────────────────────────────────────────
// GET the Hub's list endpoint for this project + resource, then scan for
// an open item whose title carries the [pageSlug] prefix OR whose
// description contains "Page: pageSlug". If one exists and the client
// didn't send force=true, bounce back a duplicate response so the widget
// can offer "view existing" or "submit as new".
//
// The Hub API reads the project filter from `?project=` on GET requests.
// The Nexus CRM reporter sends `project_slug=` which is silently ignored —
// it only works there because their token is project-scoped. We send
// `project=` correctly as defence-in-depth.
$dupCheckUrl = $hubUrl . '/?page=api&resource=' . $resource
             . '&project=' . urlencode($project)
             . '&status=open';

$dupCtx = stream_context_create(['http' => [
    'method'        => 'GET',
    'header'        => "Authorization: Bearer {$hubToken}\r\nContent-Type: application/json\r\n",
    'timeout'       => 5,
    'ignore_errors' => true,
]]);

$existingRef = null;
$existingId  = null;

$dupResponse = @file_get_contents($dupCheckUrl, false, $dupCtx);
$dupData     = $dupResponse ? json_decode($dupResponse, true) : null;

if ($dupData && !empty($dupData['ok']) && isset($dupData['data'])) {
    // Features come back under `features`, bugs under `bugs`. Accept either
    // the plural key or a generic `items` fallback.
    $items = $dupData['data'][$resource] ?? $dupData['data']['items'] ?? [];
    foreach ($items as $item) {
        $status = $item['status'] ?? '';
        $openStatuses = ['open', 'submitted', 'in_progress'];
        if (!in_array($status, $openStatuses, true)) {
            continue;
        }
        if ($pageSlug === '') {
            continue;
        }
        $title = (string)($item['title']       ?? '');
        $desc  = (string)($item['description'] ?? '');
        if (str_contains($title, "[{$pageSlug}]") || str_contains($desc, "Page: {$pageSlug}")) {
            $existingRef = $item['ref'] ?? null;
            $existingId  = $item['id']  ?? null;
            break;
        }
    }
}

if ($existingRef && !$forceSubmit) {
    $existingUrl = $existingId
        ? $hubUrl . '/?page=' . $resource . '&action=view&id=' . (int)$existingId
        : null;
    echo json_encode([
        'ok'           => true,
        'duplicate'    => true,
        'existing_ref' => $existingRef,
        'existing_id'  => $existingId,
        'issue_url'    => $existingUrl,
        'message'      => "There's already an open report for this page: {$existingRef}. Is this the same issue?",
    ]);
    exit;
}

// ── Build title + description for the Hub record ───────────────────────
$severityLabel = ($severity === 'fatal') ? '🔴 Fatal' : '🔵 Improvement';
$typeLabel     = ($type === 'bug') ? 'Bug' : 'Feature Request';
$pageLabel     = $pageTitle !== '' ? $pageTitle : ($pageSlug !== '' ? $pageSlug : 'Unknown page');

// Title format: [slug] Type: first-80-chars-of-description
$descSnippet = $description !== ''
    ? (mb_strlen($description) > 80 ? mb_substr($description, 0, 77) . '...' : $description)
    : "{$typeLabel} reported on {$pageLabel}";
$title = "[{$pageSlug}] {$typeLabel}: {$descSnippet}";

$userName = trim($user['full_name'] ?? $user['email'] ?? '(unknown)');
$roleName = $user['role_name'] ?? $user['role_slug'] ?? ($user['role'] ?? 'Unknown');

$fullDescription = implode("\n", array_filter([
    $description,
    '',
    '---',
    "**Reported by:** {$userName} ({$user['email']})",
    "**Page:** {$pageLabel}",
    "**URL:** {$pageUrl}",
    "**Severity:** {$severityLabel}",
    "**Role:** {$roleName}",
    '**Submitted:** ' . gmdate('Y-m-d H:i') . ' UTC',
], function ($v) { return $v !== null; }));

// Hub priority mapping
$priority = ($severity === 'fatal') ? 'high' : 'low';

// ── POST to the Hub ─────────────────────────────────────────────────────
// Endpoint: https://hub.intelligentae.co.uk/?page=api&resource={bugs|features}
// The Hub dispatches on HTTP method (POST = create). No &action=create.
$postEndpoint = $hubUrl . '/?page=api&resource=' . $resource;
$postPayload  = json_encode([
    'project'     => $project,
    'title'       => $title,
    'description' => $fullDescription,
    'priority'    => $priority,
]);

$postCtx = stream_context_create(['http' => [
    'method'        => 'POST',
    'header'        => "Authorization: Bearer {$hubToken}\r\n"
                     . "Content-Type: application/json\r\n"
                     . 'Content-Length: ' . strlen($postPayload) . "\r\n",
    'content'       => $postPayload,
    'timeout'       => 10,
    'ignore_errors' => true,
]]);

$postResponse = @file_get_contents($postEndpoint, false, $postCtx);
$postData     = $postResponse ? json_decode($postResponse, true) : null;

if (!$postData || empty($postData['ok'])) {
    $errMsg = $postData['error'] ?? 'Hub API unavailable.';
    http_response_code(502);
    echo json_encode(['ok' => false, 'error' => "Failed to submit to Hub: {$errMsg}"]);
    exit;
}

$ref   = $postData['data']['ref'] ?? 'Unknown';
$hubId = isset($postData['data']['id']) ? (int)$postData['data']['id'] : null;

// ── Build the issue URL for the confirmation link ───────────────────────
$issueUrl = $hubId
    ? $hubUrl . '/?page=' . $resource . '&action=view&id=' . $hubId
    : $hubUrl . '/?page=' . $resource;

// ── Log to TCH's own activity_log ───────────────────────────────────────
// Per the standing order: every user action on a transactional site gets
// a log entry. Submitting a bug/FR is a user action — it goes in the log
// with the Hub ref + page + type captured. entity_type = 'nexus_hub'
// (not a real TCH table — it's an external pointer) and entity_id is the
// Hub's numeric id so the activity detail page can reference it.
logActivity(
    $type === 'bug' ? 'bug_reported' : 'feature_requested',
    $pageSlug !== '' ? $pageSlug : null,
    'nexus_hub',
    $hubId,
    "Submitted {$ref} ({$typeLabel}, {$severity}) from {$pageLabel}",
    null,
    [
        'ref'       => $ref,
        'hub_id'    => $hubId,
        'type'      => $type,
        'severity'  => $severity,
        'page_slug' => $pageSlug,
        'issue_url' => $issueUrl,
    ]
);

// ── Send confirmation email via TCH's mailer ────────────────────────────
// TCH's mailer is text/plain by convention. Template file lives at
// templates/emails/report_confirmation.php and defines $subject + $body.
try {
    Mailer::send(
        'report_confirmation',
        $user['email'],
        $userName,
        [
            'userName'     => $userName,
            'ref'          => $ref,
            'typeLabel'    => $typeLabel,
            'severityLabel'=> $severityLabel,
            'pageLabel'    => $pageLabel,
            'pageUrl'      => $pageUrl,
            'description'  => $description,
            'issueUrl'     => $issueUrl,
        ],
        (int)$user['id']
    );
} catch (Throwable $e) {
    // Non-fatal — the Hub record already exists, email is a nice-to-have.
    // The Mailer::send() call itself writes a failed-status row to email_log
    // so Ross can see the failure in the outbox.
    error_log('Reporter confirmation email failed: ' . $e->getMessage());
}

// ── Return success to the widget ────────────────────────────────────────
echo json_encode([
    'ok'        => true,
    'duplicate' => false,
    'ref'       => $ref,
    'id'        => $hubId,
    'issue_url' => $issueUrl,
    'message'   => "Logged as {$ref}. A confirmation has been sent to {$user['email']}.",
]);
