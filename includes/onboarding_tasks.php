<?php
/**
 * Onboarding task registry.
 *
 * Each entry describes one outstanding item we need Tuniti (or anyone
 * assigned) to resolve. The landing page at /admin/onboarding iterates
 * this registry, asks each task for its pending count (structured data
 * + pending uploads), renders a card, and deep-links to the task's own
 * subpage.
 *
 * To add a new task: append a registry entry. That's it. The landing
 * page, review queue, and upload widget all key off task_key and pick
 * the entry up automatically.
 *
 * Contract:
 *   task_key        string   - unique, stable key used in DB + URLs
 *   title           string   - card title
 *   description     string   - one-liner shown on the card
 *   subpage         string   - relative URL to the task's own page
 *   permission_page string   - pages.code guarding the task
 *   priority        string   - 'high' | 'med' | 'low' (affects ordering)
 *   accepts_upload  bool     - show "Upload what you have" on subpage
 *   upload_hint     string   - helper text in the upload widget
 *   count_fn        callable - fn(PDO): int. Returns pending-item count.
 *                             0 = task complete, no badge shown.
 *   added_at        string   - YYYY-MM-DD the task was added (for "NEW" flag)
 */

function onboardingTasks(): array {
    return [

        // ─── Task 1 ────────────────────────────────────────────────
        'contracts' => [
            'title'          => 'Active contracts to confirm',
            'description'    => 'Add or confirm each live caregiver-to-client contract so shifts can be billed.',
            'subpage'        => '/admin/onboarding/contracts',
            'permission_page'=> 'contracts',
            'priority'       => 'high',
            'accepts_upload' => true,
            'upload_hint'    => 'Spreadsheet, Word doc, PDF — whatever format you keep contracts in.',
            'added_at'       => '2026-04-15',
            'count_fn'       => function (PDO $db): int {
                $drafts  = (int)$db->query("SELECT COUNT(*) FROM contracts WHERE status = 'draft'")->fetchColumn();
                $uploads = (int)$db->query(
                    "SELECT COUNT(*) FROM onboarding_uploads
                      WHERE task_key = 'contracts' AND status IN ('uploaded','in_review')"
                )->fetchColumn();
                return $drafts + $uploads;
            },
        ],

        // ─── Task 2 ────────────────────────────────────────────────
        'product_defaults' => [
            'title'          => 'Product billing defaults',
            'description'    => 'For each product, confirm how often we bill (weekly / monthly / per-visit), minimum term, and default day rate.',
            'subpage'        => '/admin/onboarding/products',
            'permission_page'=> 'products',
            'priority'       => 'high',
            'accepts_upload' => false,
            'upload_hint'    => '',
            'added_at'       => '2026-04-15',
            'count_fn'       => function (PDO $db): int {
                return (int)$db->query(
                    "SELECT COUNT(*) FROM products
                      WHERE is_active = 1
                        AND (default_price IS NULL OR default_price = 0
                             OR default_billing_freq IS NULL
                             OR default_min_term_months IS NULL)"
                )->fetchColumn();
            },
        ],

        // ─── Task 3 ────────────────────────────────────────────────
        'caregiver_patterns' => [
            'title'          => 'Caregiver working patterns',
            'description'    => 'For each caregiver, set which days they work, day/night shift preference, and whether they accept live-in placements.',
            'subpage'        => '/admin/onboarding/caregiver-patterns',
            'permission_page'=> 'onboarding',
            'priority'       => 'med',
            'accepts_upload' => true,
            'upload_hint'    => 'Availability list, WhatsApp thread, spreadsheet — anything you have.',
            'added_at'       => '2026-04-15',
            'count_fn'       => function (PDO $db): int {
                // caregivers with the default working_pattern still set
                $structured = (int)$db->query(
                    "SELECT COUNT(*) FROM caregivers
                      WHERE working_pattern = 'MON-SUN' OR working_pattern IS NULL OR working_pattern = ''"
                )->fetchColumn();
                $uploads = (int)$db->query(
                    "SELECT COUNT(*) FROM onboarding_uploads
                      WHERE task_key = 'caregiver_patterns' AND status IN ('uploaded','in_review')"
                )->fetchColumn();
                return $structured + $uploads;
            },
        ],

        // ─── Task 4 ────────────────────────────────────────────────
        'alias_disambig' => [
            'title'          => 'Alias disambiguation',
            'description'    => 'Unresolved raw names from the Timesheet / Panel workbooks that need a canonical person confirmed.',
            'subpage'        => '/admin/onboarding/aliases',
            'permission_page'=> 'config_aliases',
            'priority'       => 'med',
            'accepts_upload' => false,
            'upload_hint'    => '',
            'added_at'       => '2026-04-15',
            'count_fn'       => function (PDO $db): int {
                return (int)$db->query(
                    "SELECT COUNT(*) FROM timesheet_name_aliases
                      WHERE confidence = 'unresolved'"
                )->fetchColumn();
            },
        ],

        // ─── Task 5 ────────────────────────────────────────────────
        'timesheet_recon' => [
            'title'          => 'Timesheet reconciliation — pay discrepancies',
            'description'    => 'Caregiver-month items where the Timesheet cells do not match the sheet total. For each, confirm whether it was a loan deduction, a bonus, a rate correction, or something to investigate.',
            'subpage'        => '/admin/onboarding/reconciliation',
            'permission_page'=> 'onboarding',
            'priority'       => 'high',
            'accepts_upload' => false,
            'upload_hint'    => '',
            'added_at'       => '2026-04-15',
            'count_fn'       => function (PDO $db): int {
                return (int)$db->query(
                    "SELECT COUNT(*) FROM timesheet_reconciliation_items
                      WHERE resolution_status = 'pending'"
                )->fetchColumn();
            },
        ],

        // ─── Task 7 — periodic: monthly Timesheet workbook upload ────
        'periodic_timesheet_upload' => [
            'title'          => 'Upload latest Caregiver Timesheet workbook',
            'description'    => 'Each month, upload the latest Tuniti Caregiver Timesheet Excel so shift cost data is current. Counts 1 until a current-month file has been ingested.',
            'subpage'        => '/admin/onboarding/upload-timesheet',
            'permission_page'=> 'onboarding',
            'priority'       => 'high',
            'accepts_upload' => true,
            'upload_hint'    => 'Tuniti Caregiver Timesheets Apr-26.xlsx (or later). One tab per month.',
            'added_at'       => '2026-04-15',
            'count_fn'       => function (PDO $db): int {
                $done = (int)$db->query(
                    "SELECT COUNT(*) FROM onboarding_uploads
                      WHERE task_key = 'periodic_timesheet_upload'
                        AND status = 'ingested'
                        AND DATE_FORMAT(ingested_at, '%Y-%m') = DATE_FORMAT(NOW(), '%Y-%m')"
                )->fetchColumn();
                return $done > 0 ? 0 : 1;
            },
        ],

        // ─── Task 8 — periodic: monthly Revenue Panel workbook upload ─
        'periodic_revenue_upload' => [
            'title'          => 'Upload latest Revenue Panel workbook',
            'description'    => 'Each month, upload the latest Tuniti Revenue to Clients Excel so client billing data is current. Counts 1 until a current-month file has been ingested.',
            'subpage'        => '/admin/onboarding/upload-revenue',
            'permission_page'=> 'onboarding',
            'priority'       => 'high',
            'accepts_upload' => true,
            'upload_hint'    => 'Tuniti Revenue to Clients Apr-26.xlsx (or later). One tab per month, client panels in a grid.',
            'added_at'       => '2026-04-15',
            'count_fn'       => function (PDO $db): int {
                $done = (int)$db->query(
                    "SELECT COUNT(*) FROM onboarding_uploads
                      WHERE task_key = 'periodic_revenue_upload'
                        AND status = 'ingested'
                        AND DATE_FORMAT(ingested_at, '%Y-%m') = DATE_FORMAT(NOW(), '%Y-%m')"
                )->fetchColumn();
                return $done > 0 ? 0 : 1;
            },
        ],

        // ─── Task 6 ────────────────────────────────────────────────
        'jan2026_date_ack' => [
            'title'          => 'Jan 2026 Timesheet date acknowledgement',
            'description'    => 'The Jan 2026 tab in the Timesheet workbook had Jan 2025 date serials. The parser now forces year-month from the tab name — please acknowledge the fix is in place.',
            'subpage'        => '/admin/onboarding/jan2026-ack',
            'permission_page'=> 'onboarding',
            'priority'       => 'low',
            'accepts_upload' => false,
            'upload_hint'    => '',
            'added_at'       => '2026-04-15',
            'count_fn'       => function (PDO $db): int {
                $done = (int)$db->query(
                    "SELECT COUNT(*) FROM system_acknowledgements WHERE ack_key = 'jan2026_date_serials'"
                )->fetchColumn();
                return $done > 0 ? 0 : 1;
            },
        ],

    ];
}

/**
 * Count uploads by status across all tasks. Used by the Review page.
 */
function onboardingUploadQueueCount(PDO $db): int {
    return (int)$db->query(
        "SELECT COUNT(*) FROM onboarding_uploads
          WHERE status IN ('uploaded','in_review')"
    )->fetchColumn();
}
