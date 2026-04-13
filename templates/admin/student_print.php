<?php
/**
 * Printable student record — /admin/students/{id}/print
 *
 * Single-page A4 portrait view that mirrors the Tuniti intake PDF layout
 * (photo top-left, two-column field block, NoK block, summary footer).
 *
 * Auto-prints on load via window.print() — user picks "Save as PDF" from
 * the browser's print dialog or sends to a real printer.
 */

require_once APP_ROOT . '/includes/countries.php';

$personId = (int)($_GET['student_id'] ?? 0);
$db = getDB();

$stmt = $db->prepare(
    "SELECT p.*, s.cohort, s.course_start, s.avg_score, s.practical_status, s.qualified
     FROM persons p
     LEFT JOIN students s ON s.person_id = p.id
     WHERE p.id = ?"
);
$stmt->execute([$personId]);
$person = $stmt->fetch();
if (!$person) { http_response_code(404); echo 'Not found'; return; }

$stmt = $db->prepare(
    'SELECT enrolled_at, graduated_at, status FROM student_enrollments
     WHERE student_person_id = ? ORDER BY enrolled_at DESC LIMIT 1'
);
$stmt->execute([$personId]);
$enrol = $stmt->fetch() ?: [];

$stmt = $db->prepare(
    "SELECT file_path FROM attachments
     WHERE person_id = ? AND is_active = 1
     AND attachment_type_id = (SELECT id FROM attachment_types WHERE code='profile_photo')
     ORDER BY uploaded_at DESC LIMIT 1"
);
$stmt->execute([$personId]);
$photoPath = $stmt->fetchColumn();

function _ph($v) { return $v !== null && $v !== '' ? htmlspecialchars((string)$v) : '&nbsp;'; }
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Student record — <?= htmlspecialchars($person['full_name']) ?></title>
<style>
  @page { size: A4 portrait; margin: 12mm; }
  body { font-family: Arial, Helvetica, sans-serif; font-size: 11pt; color: #000; margin: 0; }
  .wrap { max-width: 186mm; margin: 0 auto; }
  .header-bar { display:flex; justify-content:space-between; align-items:flex-start;
                border-bottom: 2px solid #10B2B4; padding-bottom: 8pt; margin-bottom: 12pt; }
  .header-bar h1 { font-size: 16pt; margin: 0; color: #10B2B4; }
  .header-bar .meta { text-align:right; font-size: 9pt; color: #555; }
  .top { display:flex; gap: 12pt; margin-bottom: 12pt; }
  .photo { width: 38mm; height: 50mm; border: 1px solid #999; object-fit: cover; flex-shrink:0; background:#f5f5f5; }
  .photo-blank { display:flex; align-items:center; justify-content:center; color:#999; font-size: 9pt; }
  .name-block { flex: 1; }
  .name-block h2 { font-size: 18pt; margin: 0 0 4pt; }
  .name-block .tch-id { font-family: monospace; color: #10B2B4; font-size: 12pt; margin-bottom: 6pt; }
  .name-block .meta { color:#555; font-size: 10pt; }
  table.fields { width: 100%; border-collapse: collapse; font-size: 10pt; margin-bottom: 10pt; }
  table.fields td { padding: 3pt 6pt; vertical-align: top; }
  table.fields td.label { width: 28%; color: #555; }
  table.fields td.value { /* no underline — keeps the page uncluttered */ }
  h3.section { font-size: 11pt; color: #10B2B4; margin: 12pt 0 4pt; padding-bottom: 2pt; border-bottom: 1px solid #10B2B4; }
  .two-col { display:grid; grid-template-columns: 1fr 1fr; gap: 0 16pt; }
  .footer { margin-top: 16pt; padding-top: 6pt; border-top: 1px solid #999; font-size: 8pt; color: #777; display:flex; justify-content:space-between; }
  .no-print { padding: 8mm 0; text-align:center; }
  .no-print a, .no-print button { background:#10B2B4; color:#fff; padding: 6pt 14pt; border:none;
                                  border-radius:3pt; text-decoration:none; font-size:10pt; cursor:pointer; margin:0 4pt; }
  @media print { .no-print { display:none; } }
</style>
</head>
<body>
<div class="no-print">
  <button onclick="window.print()">Print / Save as PDF</button>
  <a href="<?= APP_URL ?>/admin/students/<?= $personId ?>">Back to record</a>
</div>

<div class="wrap">
  <div class="header-bar">
    <h1>The Care Hero — Student Record</h1>
    <div class="meta">
      <strong><?= htmlspecialchars($person['tch_id'] ?? '') ?></strong><br>
      Printed <?= date('d M Y') ?>
    </div>
  </div>

  <div class="top">
    <?php if ($photoPath): ?>
      <img class="photo" src="<?= APP_URL ?>/uploads/<?= htmlspecialchars($photoPath) ?>" alt="">
    <?php else: ?>
      <div class="photo photo-blank">No photo on file</div>
    <?php endif; ?>
    <div class="name-block">
      <h2><?= htmlspecialchars($person['full_name']) ?></h2>
      <div class="tch-id"><?= htmlspecialchars($person['tch_id'] ?? '') ?></div>
      <div class="meta">
        Known as: <strong><?= _ph($person['known_as']) ?></strong><br>
        Cohort: <strong><?= _ph($enrol['cohort'] ?? $person['cohort']) ?></strong>
        &nbsp;·&nbsp; Status: <strong><?= _ph($enrol['status'] ?? '') ?></strong>
        <?php if (!empty($enrol['graduated_at'])): ?>
          &nbsp;·&nbsp; Graduated: <strong><?= htmlspecialchars($enrol['graduated_at']) ?></strong>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <h3 class="section">Personal</h3>
  <div class="two-col">
    <table class="fields">
      <tr><td class="label">Title</td>          <td class="value"><?= _ph($person['title']) ?></td></tr>
      <tr><td class="label">Initials</td>       <td class="value"><?= _ph($person['initials']) ?></td></tr>
      <tr><td class="label">ID / Passport</td>  <td class="value"><?= _ph($person['id_passport']) ?></td></tr>
      <tr><td class="label">Date of Birth</td>  <td class="value"><?= _ph($person['dob']) ?></td></tr>
      <tr><td class="label">Gender</td>         <td class="value"><?= _ph($person['gender']) ?></td></tr>
    </table>
    <table class="fields">
      <tr><td class="label">Nationality</td>    <td class="value"><?= _ph($person['nationality']) ?></td></tr>
      <tr><td class="label">Home Language</td>  <td class="value"><?= _ph($person['home_language']) ?></td></tr>
      <tr><td class="label">Other Languages</td><td class="value"><?= _ph($person['other_language']) ?></td></tr>
    </table>
  </div>

  <h3 class="section">Contact</h3>
  <table class="fields">
    <tr>
      <td class="label">Mobile</td>        <td class="value"><?= _ph(formatPhoneForDisplay($person['mobile'])) ?></td>
      <td class="label">Secondary</td>     <td class="value"><?= _ph(formatPhoneForDisplay($person['secondary_number'])) ?></td>
    </tr>
    <tr>
      <td class="label">Email</td>         <td class="value" colspan="3"><?= _ph($person['email']) ?></td>
    </tr>
  </table>

  <h3 class="section">Home Address</h3>
  <table class="fields">
    <tr><td class="label">Complex / Estate</td><td class="value" colspan="3"><?= _ph($person['complex_estate']) ?></td></tr>
    <tr><td class="label">Street Address</td>  <td class="value" colspan="3"><?= _ph($person['street_address']) ?></td></tr>
    <tr>
      <td class="label">Suburb</td>   <td class="value"><?= _ph($person['suburb']) ?></td>
      <td class="label">City</td>     <td class="value"><?= _ph($person['city']) ?></td>
    </tr>
    <tr>
      <td class="label">Province</td> <td class="value"><?= _ph($person['province']) ?></td>
      <td class="label">Postal Code</td><td class="value"><?= _ph($person['postal_code']) ?></td>
    </tr>
    <tr>
      <td class="label">Country</td>  <td class="value" colspan="3"><?= _ph($person['country'] ?? 'South Africa') ?></td>
    </tr>
  </table>

  <h3 class="section">Emergency Contact</h3>
  <div class="two-col">
    <table class="fields">
      <tr><td class="label">Name</td>          <td class="value"><?= _ph($person['nok_name']) ?></td></tr>
      <tr><td class="label">Relationship</td>  <td class="value"><?= _ph($person['nok_relationship']) ?></td></tr>
      <tr><td class="label">Contact</td>       <td class="value"><?= _ph(formatPhoneForDisplay($person['nok_contact'])) ?></td></tr>
      <tr><td class="label">Email</td>         <td class="value"><?= _ph($person['nok_email']) ?></td></tr>
    </table>
    <?php if (!empty($person['nok_2_name'])): ?>
    <table class="fields">
      <tr><td class="label">Name (2nd)</td>    <td class="value"><?= _ph($person['nok_2_name']) ?></td></tr>
      <tr><td class="label">Relationship</td>  <td class="value"><?= _ph($person['nok_2_relationship']) ?></td></tr>
      <tr><td class="label">Contact</td>       <td class="value"><?= _ph(formatPhoneForDisplay($person['nok_2_contact'])) ?></td></tr>
      <tr><td class="label">Email</td>         <td class="value"><?= _ph($person['nok_2_email']) ?></td></tr>
    </table>
    <?php else: ?>
    <table class="fields">
      <tr><td class="label">Name (2nd)</td>    <td class="value">&nbsp;</td></tr>
      <tr><td class="label">Relationship</td>  <td class="value">&nbsp;</td></tr>
      <tr><td class="label">Contact</td>       <td class="value">&nbsp;</td></tr>
      <tr><td class="label">Email</td>         <td class="value">&nbsp;</td></tr>
    </table>
    <?php endif; ?>
  </div>

  <h3 class="section">Training</h3>
  <table class="fields">
    <tr>
      <td class="label">Course Start</td>     <td class="value"><?= _ph($person['course_start']) ?></td>
      <td class="label">Average Score</td>    <td class="value"><?= $person['avg_score'] !== null
                                                                    ? number_format((float)$person['avg_score'] * 100, 1) . '%'
                                                                    : '&nbsp;' ?></td>
    </tr>
    <tr>
      <td class="label">Practical / OJT</td>  <td class="value"><?= _ph($person['practical_status']) ?></td>
      <td class="label">Qualified</td>        <td class="value"><?= _ph($person['qualified']) ?></td>
    </tr>
  </table>

  <div class="footer">
    <span><?= htmlspecialchars($person['tch_id'] ?? '') ?> · <?= htmlspecialchars($person['full_name']) ?></span>
    <span>tch.intelligentae.co.uk</span>
  </div>
</div>

<script>
  // Auto-open the print dialog after the page renders. User can cancel.
  window.addEventListener('load', function() { setTimeout(window.print, 300); });
</script>
</body>
</html>
