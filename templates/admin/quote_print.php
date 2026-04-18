<?php
/**
 * Quote print view — /admin/quotes/{id}/print
 *
 * Standalone, print-optimised page. No admin sidebar, no nav — a
 * single-purpose surface for generating a PDF via the browser's
 * native Print → Save as PDF flow.
 *
 * This is FR-F Phase 1 (generic, zero-dependencies). Phase 2 will
 * use a real PHP PDF library (Dompdf) to attach a server-rendered
 * PDF to quote-delivery emails (FR-G). For now: user clicks
 * "Download PDF" on the quote detail → lands here → uses browser's
 * Print button → saves as PDF.
 *
 * Branding layer:
 *   - Company header data comes from regions.is_primary = 1.
 *   - Bank details + terms placeholders at the bottom — Ross edits
 *     the system_settings entries (or the template defaults below)
 *     when ready.
 *   - Tuniti will supply the final branded header (FR-F Phase 1b
 *     onboarding task) — this template is the infrastructure
 *     that receives that branding.
 */
require_once APP_ROOT . '/includes/opportunities.php';

$db = getDB();

// Minimal perm gate — anyone who can read quotes can print them
if (!userCan('quotes', 'read')) {
    http_response_code(403);
    die('You do not have permission to view this quote.');
}

$quoteId = (int)($_GET['contract_id'] ?? 0);
if ($quoteId < 1) { http_response_code(404); die('Not found.'); }

$stmt = $db->prepare(
    "SELECT c.*,
            pp.full_name AS patient_name, pp.tch_id AS patient_tch_id,
            cp.full_name AS client_name,
            cl.account_number,
            o.opp_ref, o.title AS opp_title,
            r.name AS region_name, r.phone_primary, r.email_primary,
            r.physical_address, r.office_hours
       FROM contracts c
  LEFT JOIN persons pp ON pp.id = c.patient_person_id
  LEFT JOIN clients cl ON cl.id = c.client_id
  LEFT JOIN persons cp ON cp.id = cl.person_id
  LEFT JOIN opportunities o ON o.id = c.opportunity_id
  LEFT JOIN regions r ON r.is_primary = 1 AND r.is_active = 1
      WHERE c.id = ?"
);
$stmt->execute([$quoteId]);
$q = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$q) { http_response_code(404); die('Quote not found.'); }

// Client contact block — pull primary email + phone where available
$clientPhonesStmt = $db->prepare(
    "SELECT phone FROM person_phones
      WHERE person_id = (SELECT person_id FROM clients WHERE id = ?)
        AND is_active = 1
      ORDER BY is_primary DESC, id
      LIMIT 1"
);
try { $clientPhonesStmt->execute([$q['client_id']]); } catch (Throwable $e) { /* person_phones may not exist in older deployments */ }
$clientPhone = $clientPhonesStmt ? ($clientPhonesStmt->fetchColumn() ?: null) : null;

$clientEmailsStmt = $db->prepare(
    "SELECT email FROM person_emails
      WHERE person_id = (SELECT person_id FROM clients WHERE id = ?)
        AND is_active = 1
      ORDER BY is_primary DESC, id
      LIMIT 1"
);
try { $clientEmailsStmt->execute([$q['client_id']]); } catch (Throwable $e) { /* ignore if table not present */ }
$clientEmail = $clientEmailsStmt ? ($clientEmailsStmt->fetchColumn() ?: null) : null;

// Line items
$linesStmt = $db->prepare(
    "SELECT cl.*, p.name AS product_name, p.code AS product_code
       FROM contract_lines cl
       JOIN products p ON p.id = cl.product_id
      WHERE cl.contract_id = ?
      ORDER BY cl.id"
);
$linesStmt->execute([$quoteId]);
$lines = $linesStmt->fetchAll(PDO::FETCH_ASSOC);

$quoteTotal = 0;
foreach ($lines as $ln) {
    $quoteTotal += (float)$ln['bill_rate'] * (float)$ln['units_per_period'];
}

// Quote validity — 30 days from sent_at if sent, else 30 days from today
$validUntil = $q['sent_at']
    ? date('Y-m-d', strtotime($q['sent_at'] . ' +30 days'))
    : date('Y-m-d', strtotime('+30 days'));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Quote <?= htmlspecialchars($q['quote_reference'] ?: '#' . $quoteId) ?> — TCH Placements</title>
<style>
/* === Screen styling === */
body { font-family: Georgia, 'Times New Roman', serif; color: #1e293b; margin: 0; background: #e5e7eb; }

.print-wrapper { max-width: 210mm; margin: 1rem auto; padding: 20mm 18mm; background: #fff; box-shadow: 0 2px 20px rgba(0,0,0,0.1); min-height: 297mm; box-sizing: border-box; position: relative; }
.print-toolbar { position: sticky; top: 0; background: #0f172a; color: #fff; padding: 0.6rem 1rem; text-align: center; font-family: system-ui, -apple-system, sans-serif; z-index: 100; }
.print-toolbar button { background: #3b82f6; color: #fff; border: 0; padding: 0.4rem 1rem; border-radius: 4px; cursor: pointer; font-size: 0.9rem; margin-left: 0.5rem; }
.print-toolbar button:hover { background: #2563eb; }
.print-toolbar a { color: #cbd5e1; text-decoration: none; margin-left: 1rem; font-size: 0.85rem; }

/* === Content === */
.quote-header { display: flex; justify-content: space-between; align-items: flex-start; border-bottom: 3px solid #1e40af; padding-bottom: 1rem; margin-bottom: 1.5rem; }
.quote-header .brand-block h1 { margin: 0; font-size: 2rem; color: #1e40af; letter-spacing: 0.02em; }
.quote-header .brand-block .tagline { color: #64748b; font-size: 0.85rem; font-style: italic; margin-top: 0.2rem; }
.quote-header .contact-block { text-align: right; font-size: 0.82rem; color: #475569; line-height: 1.5; }

.quote-title { text-align: center; margin-bottom: 1.5rem; }
.quote-title h2 { margin: 0; font-size: 1.5rem; color: #1e293b; letter-spacing: 0.04em; }
.quote-title .quote-ref { font-family: 'Courier New', monospace; color: #64748b; font-size: 1rem; margin-top: 0.2rem; }

.quote-meta { display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; margin-bottom: 1.5rem; font-size: 0.9rem; }
.quote-meta .meta-block { background: #f8fafc; border-left: 3px solid #1e40af; padding: 0.6rem 0.8rem; }
.quote-meta .meta-block h4 { margin: 0 0 0.3rem 0; font-size: 0.78rem; color: #64748b; text-transform: uppercase; letter-spacing: 0.06em; font-weight: 600; }
.quote-meta .meta-block p { margin: 0; line-height: 1.4; }

.lines-table { width: 100%; border-collapse: collapse; font-size: 0.88rem; margin-bottom: 1.5rem; }
.lines-table thead th { background: #1e40af; color: #fff; padding: 0.5rem 0.6rem; text-align: left; font-size: 0.82rem; text-transform: uppercase; letter-spacing: 0.04em; }
.lines-table thead th.num { text-align: right; }
.lines-table thead th.center { text-align: center; }
.lines-table tbody td { padding: 0.5rem 0.6rem; border-bottom: 1px solid #e2e8f0; vertical-align: top; }
.lines-table tbody td.num { text-align: right; font-variant-numeric: tabular-nums; }
.lines-table tbody td.center { text-align: center; }
.lines-table tbody tr:nth-child(even) td { background: #f8fafc; }
.lines-table .line-dates { font-size: 0.76rem; color: #64748b; margin-top: 0.15rem; }
.lines-table tfoot td { padding: 0.6rem; font-size: 1rem; font-weight: 700; }
.lines-table tfoot .total-label { text-align: right; color: #1e293b; }
.lines-table tfoot .total-value { text-align: right; color: #15803d; font-size: 1.2rem; background: #f0fdf4; }

.section { margin-bottom: 1.2rem; font-size: 0.88rem; }
.section h3 { margin: 0 0 0.4rem 0; color: #1e40af; font-size: 0.95rem; border-bottom: 1px solid #e2e8f0; padding-bottom: 0.2rem; }
.section p { margin: 0.3rem 0; line-height: 1.5; }

.terms-placeholder { background: #fffbeb; border: 1px dashed #f59e0b; padding: 0.8rem 1rem; border-radius: 4px; font-size: 0.82rem; color: #78350f; }

.signature-block { margin-top: 2rem; display: grid; grid-template-columns: 1fr 1fr; gap: 2rem; font-size: 0.88rem; }
.signature-block .sig-box { border-top: 1px solid #334155; padding-top: 0.4rem; }
.signature-block .sig-box strong { display: block; font-size: 0.8rem; color: #64748b; font-weight: 500; text-transform: uppercase; letter-spacing: 0.04em; }

.footer { position: absolute; bottom: 10mm; left: 18mm; right: 18mm; text-align: center; font-size: 0.72rem; color: #94a3b8; border-top: 1px solid #e2e8f0; padding-top: 0.4rem; }

/* === Print overrides === */
@media print {
    body { background: #fff; }
    .print-toolbar { display: none; }
    .print-wrapper { box-shadow: none; margin: 0; padding: 15mm; max-width: none; }
    .terms-placeholder { border-color: #94a3b8; color: #334155; }
    @page { margin: 0; size: A4; }
}
</style>
</head>
<body>

<div class="print-toolbar">
    Press <strong>Ctrl+P</strong> (or ⌘+P on Mac) → <strong>Save as PDF</strong> to download this quote.
    <button onclick="window.print()">Print / Save PDF</button>
    <a href="<?= APP_URL ?>/admin/quotes/<?= $quoteId ?>">← Back to quote</a>
</div>

<div class="print-wrapper">

    <!-- Company header -->
    <div class="quote-header">
        <div class="brand-block">
            <h1>TCH Placements</h1>
            <div class="tagline">Compassionate in-home care across Gauteng</div>
        </div>
        <div class="contact-block">
            <?= htmlspecialchars($q['physical_address'] ?? 'Pretoria, Gauteng') ?><br>
            <?= htmlspecialchars($q['phone_primary'] ?? '') ?><br>
            <?= htmlspecialchars($q['email_primary'] ?? 'hello@tch.intelligentae.co.uk') ?><br>
            <?= htmlspecialchars($q['office_hours'] ?? '') ?>
        </div>
    </div>

    <!-- Title + quote reference -->
    <div class="quote-title">
        <h2>QUOTATION</h2>
        <div class="quote-ref">
            <?= htmlspecialchars($q['quote_reference'] ?: 'Q-PENDING-' . $quoteId) ?>
        </div>
    </div>

    <!-- Meta: for / date / valid / our-ref -->
    <div class="quote-meta">
        <div class="meta-block">
            <h4>Prepared for</h4>
            <p>
                <strong><?= htmlspecialchars($q['client_name'] ?? '—') ?></strong><br>
                <?php if ($q['patient_name'] && $q['patient_name'] !== $q['client_name']): ?>
                    for care recipient <em><?= htmlspecialchars($q['patient_name']) ?></em><br>
                <?php endif; ?>
                <?php if ($clientEmail): ?><?= htmlspecialchars($clientEmail) ?><br><?php endif; ?>
                <?php if ($clientPhone): ?><?= htmlspecialchars($clientPhone) ?><?php endif; ?>
            </p>
        </div>
        <div class="meta-block">
            <h4>Quote details</h4>
            <p>
                <strong>Issued:</strong> <?= htmlspecialchars(date('j F Y', strtotime($q['sent_at'] ?: $q['created_at']))) ?><br>
                <strong>Valid until:</strong> <?= htmlspecialchars(date('j F Y', strtotime($validUntil))) ?><br>
                <strong>Care starting:</strong> <?= htmlspecialchars(date('j F Y', strtotime($q['start_date']))) ?><br>
                <?php if ($q['end_date']): ?>
                    <strong>Care ending:</strong> <?= htmlspecialchars(date('j F Y', strtotime($q['end_date']))) ?>
                <?php else: ?>
                    <strong>Care continues:</strong> ongoing until cancelled
                <?php endif; ?>
            </p>
        </div>
    </div>

    <!-- Line items -->
    <table class="lines-table">
        <thead>
            <tr>
                <th>Service</th>
                <th class="center">Unit</th>
                <th class="num">Rate</th>
                <th class="num">Qty</th>
                <th class="num">Line total</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($lines)): ?>
                <tr><td colspan="5" style="text-align:center;color:#94a3b8;padding:1rem;font-style:italic;">No line items on this quote yet.</td></tr>
            <?php endif; ?>
            <?php foreach ($lines as $ln):
                $lineTotal = (float)$ln['bill_rate'] * (float)$ln['units_per_period'];
                $unitLabel = str_replace('_', ' ', $ln['billing_freq']);
            ?>
                <tr>
                    <td>
                        <strong><?= htmlspecialchars($ln['product_name']) ?></strong>
                        <?php if ($ln['start_date'] || $ln['end_date']): ?>
                            <div class="line-dates">
                                <?= $ln['start_date'] ? htmlspecialchars(date('j M Y', strtotime($ln['start_date']))) : 'now' ?>
                                —
                                <?= $ln['end_date'] ? htmlspecialchars(date('j M Y', strtotime($ln['end_date']))) : 'ongoing' ?>
                            </div>
                        <?php endif; ?>
                        <?php if (!empty($ln['notes'])): ?>
                            <div class="line-dates"><?= htmlspecialchars($ln['notes']) ?></div>
                        <?php endif; ?>
                    </td>
                    <td class="center"><?= htmlspecialchars($unitLabel) ?></td>
                    <td class="num">R<?= number_format((float)$ln['bill_rate'], 2) ?></td>
                    <td class="num"><?= number_format((float)$ln['units_per_period'], 2) ?></td>
                    <td class="num"><strong>R<?= number_format($lineTotal, 2) ?></strong></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
        <tfoot>
            <tr>
                <td colspan="4" class="total-label">Total per billing period</td>
                <td class="total-value">R<?= number_format($quoteTotal, 2) ?></td>
            </tr>
        </tfoot>
    </table>

    <!-- Notes (if any) -->
    <?php if (!empty($q['notes'])): ?>
        <div class="section">
            <h3>Notes</h3>
            <p style="white-space:pre-wrap;"><?= htmlspecialchars($q['notes']) ?></p>
        </div>
    <?php endif; ?>

    <!-- Terms placeholder -->
    <div class="section">
        <h3>Terms &amp; conditions</h3>
        <div class="terms-placeholder">
            <em>[Terms &amp; conditions placeholder.]</em><br>
            TCH will replace this block with the formal T&amp;Cs Tuniti has
            agreed. Typical inclusions: cancellation notice period, minimum
            term, billing cadence (monthly in advance), VAT treatment, scope
            of care, after-hours coverage, caregiver substitution policy.
        </div>
    </div>

    <!-- Banking placeholder -->
    <div class="section">
        <h3>Payment</h3>
        <p>
            Invoices are raised <strong>monthly in advance</strong>.
            Payment due within <strong>7 days</strong> of invoice date by EFT to:
        </p>
        <p style="background:#f8fafc;border:1px solid #e2e8f0;padding:0.6rem 0.8rem;border-radius:4px;font-family:'Courier New',monospace;font-size:0.85rem;">
            <em>[Bank name placeholder]</em><br>
            <em>[Account name placeholder]</em><br>
            <em>[Account number placeholder]</em> &middot;
            <em>[Branch code placeholder]</em><br>
            <strong>Reference:</strong> <?= htmlspecialchars($q['quote_reference'] ?: 'Q-' . $quoteId) ?>
        </p>
    </div>

    <!-- Acceptance -->
    <div class="section">
        <h3>Acceptance</h3>
        <p>
            To accept this quote, sign below and return by email to
            <?= htmlspecialchars($q['email_primary'] ?? 'hello@tch.intelligentae.co.uk') ?>.
            Alternatively, confirm by phone on
            <?= htmlspecialchars($q['phone_primary'] ?? '') ?> and TCH will
            log your verbal acceptance.
        </p>
        <div class="signature-block">
            <div class="sig-box">
                <strong>Signed (client)</strong><br>
                &nbsp;
            </div>
            <div class="sig-box">
                <strong>Date</strong><br>
                &nbsp;
            </div>
        </div>
    </div>

    <div class="footer">
        TCH Placements &middot; Quote <?= htmlspecialchars($q['quote_reference'] ?: 'Q-' . $quoteId) ?>
        &middot; Issued <?= htmlspecialchars(date('j M Y', strtotime($q['sent_at'] ?: $q['created_at']))) ?>
    </div>

</div>
</body>
</html>
