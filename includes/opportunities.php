<?php
/**
 * Shared helpers for the sales pipeline (FR-L).
 *
 * Kept in one place so opportunities.php, opportunities_detail.php,
 * pipeline.php, the AJAX move handler, and enquiries.php (Convert to
 * Opportunity button) all speak the same vocabulary.
 */

if (!defined('APP_ROOT')) {
    die('Direct access not permitted.');
}

/**
 * Generate the next opp_ref in the OPP-YYYY-NNNN series.
 *
 * Single source of truth: the max existing ref for the current year.
 * No cached counter — the ref is derived at insert time.
 */
function nextOppRef(PDO $db): string {
    $year = (int)date('Y');
    $prefix = sprintf('OPP-%04d-', $year);
    $stmt = $db->prepare(
        "SELECT opp_ref FROM opportunities
          WHERE opp_ref LIKE ?
          ORDER BY id DESC LIMIT 1"
    );
    $stmt->execute([$prefix . '%']);
    $last = $stmt->fetchColumn();
    $next = 1;
    if ($last && preg_match('/-(\d+)$/', (string)$last, $m)) {
        $next = (int)$m[1] + 1;
    }
    return sprintf('%s%04d', $prefix, $next);
}

/**
 * Fetch all active stages ordered for Kanban display.
 * Returns rows keyed by id for O(1) lookup.
 */
function fetchSalesStages(PDO $db, bool $onlyActive = true): array {
    $sql = "SELECT * FROM sales_stages";
    if ($onlyActive) $sql .= " WHERE is_active = 1";
    $sql .= " ORDER BY sort_order, id";
    $rows = $db->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    $out = [];
    foreach ($rows as $r) $out[(int)$r['id']] = $r;
    return $out;
}

/**
 * Load an opportunity by id with its joined display fields.
 * Returns null if not found.
 */
function fetchOpportunity(PDO $db, int $id): ?array {
    $stmt = $db->prepare(
        "SELECT o.*,
                s.name AS stage_name, s.slug AS stage_slug,
                s.is_closed_won, s.is_closed_lost, s.probability_percent,
                cl.account_number AS client_account_number,
                cp.full_name AS client_name,
                pp.full_name AS patient_name,
                pp.tch_id   AS patient_tch_id,
                u.full_name AS owner_name,
                u.email     AS owner_email,
                e.full_name AS enquiry_submitter,
                e.created_at AS enquiry_created_at,
                c.status    AS contract_status,
                c.start_date AS contract_start_date
           FROM opportunities o
      LEFT JOIN sales_stages s ON s.id = o.stage_id
      LEFT JOIN clients    cl  ON cl.id = o.client_id
      LEFT JOIN persons    cp  ON cp.id = cl.person_id
      LEFT JOIN persons    pp  ON pp.id = o.patient_person_id
      LEFT JOIN users      u   ON u.id  = o.owner_user_id
      LEFT JOIN enquiries  e   ON e.id  = o.source_enquiry_id
      LEFT JOIN contracts  c   ON c.id  = o.contract_id
          WHERE o.id = ?"
    );
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/**
 * Reason-lost options (for closed_lost transitions).
 * Keys match the ENUM in the opportunities table.
 */
function reasonLostOptions(): array {
    return [
        'price'         => 'Price — too expensive',
        'timing'        => 'Timing — not ready now',
        'competitor'    => 'Lost to competitor',
        'lost_contact'  => 'Lost contact with client',
        'not_a_fit'     => 'Not a fit for our service',
        'other'         => 'Other (see note)',
    ];
}

/**
 * Source options (for opportunity creation).
 */
function oppSourceOptions(): array {
    return [
        'enquiry'     => 'Enquiry (public form)',
        'referral'    => 'Referral',
        'direct_call' => 'Direct call',
        'walk_in'     => 'Walk-in',
        'other'       => 'Other',
    ];
}
