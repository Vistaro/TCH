<?php
/**
 * Enquiry form handler — POST /enquire
 *
 * Validates the public enquiry form, drops bot submissions, and writes
 * the enquiry to the `enquiries` table. Redirects back to the homepage
 * with a success or error flag in the query string.
 */

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . APP_URL . '/');
    exit;
}

initSession();

// CSRF check
if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
    header('Location: ' . APP_URL . '/?enquiry=error#enquire');
    exit;
}

// Honeypot — if the hidden `website` field is filled in, it's a bot.
if (!empty($_POST['website'])) {
    // Silently accept and discard
    header('Location: ' . APP_URL . '/?enquiry=success#enquire');
    exit;
}

// Required fields
$fullName = trim((string)($_POST['full_name'] ?? ''));
$phone    = trim((string)($_POST['phone'] ?? ''));
$careType = trim((string)($_POST['care_type'] ?? ''));
$consent  = !empty($_POST['consent_terms']);

if ($fullName === '' || $phone === '' || $careType === '' || !$consent) {
    header('Location: ' . APP_URL . '/?enquiry=error#enquire');
    exit;
}

// Optional fields
$email     = trim((string)($_POST['email'] ?? '')) ?: null;
$area      = trim((string)($_POST['suburb_or_area'] ?? '')) ?: null;
$urgency   = trim((string)($_POST['urgency'] ?? '')) ?: null;
$message   = trim((string)($_POST['message'] ?? '')) ?: null;
$regionId  = (int)($_POST['region_id'] ?? 0) ?: null;

// Length sanity (silent truncation rather than rejection)
$fullName = mb_substr($fullName, 0, 200);
$phone    = mb_substr($phone, 0, 30);
$careType = mb_substr($careType, 0, 50);
if ($email)   { $email   = mb_substr($email, 0, 150); }
if ($area)    { $area    = mb_substr($area, 0, 150); }
if ($urgency) { $urgency = mb_substr($urgency, 0, 50); }
if ($message) { $message = mb_substr($message, 0, 2000); }

// Validate care type against the allowed list
$allowedCareTypes = [
    'permanent', 'temporary', 'post_op', 'palliative',
    'respite', 'errand', 'other',
];
if (!in_array($careType, $allowedCareTypes, true)) {
    $careType = 'other';
}

// Audit metadata
$userAgent = mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);
$ipAddress = $_SERVER['REMOTE_ADDR'] ?? null;
$referrer  = mb_substr((string)($_SERVER['HTTP_REFERER'] ?? ''), 0, 255);

try {
    $db = getDB();
    $stmt = $db->prepare(
        "INSERT INTO enquiries
            (region_id, enquiry_type, full_name, email, phone, suburb_or_area,
             care_type, urgency, message, consent_terms, consent_marketing,
             source_page, user_agent, ip_address, referrer_url, status)
         VALUES
            (?, 'client', ?, ?, ?, ?, ?, ?, ?, 1, 0, ?, ?, ?, ?, 'new')"
    );
    $stmt->execute([
        $regionId,
        $fullName,
        $email,
        $phone,
        $area,
        $careType,
        $urgency,
        $message,
        '/',
        $userAgent,
        $ipAddress,
        $referrer,
    ]);
    $enquiryId = (int)$db->lastInsertId();

    // Audit: anonymous public submission (real_user_id = NULL)
    logActivity('enquiry_submitted', 'enquiries', 'enquiries', $enquiryId,
        'Public enquiry from ' . $fullName . ' (' . $careType . ')');

    header('Location: ' . APP_URL . '/?enquiry=success#enquire');
    exit;
} catch (\PDOException $e) {
    // Log internally; tell the user something went wrong without exposing details.
    error_log('Enquiry insert failed: ' . $e->getMessage());
    header('Location: ' . APP_URL . '/?enquiry=error#enquire');
    exit;
}
