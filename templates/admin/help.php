<?php
/**
 * User Manual / UX guide — /admin/help
 *
 * A living user manual for first-time admin users. Structured around
 * the three lifecycle phases:
 *
 *   Acquire — enquiry → opportunity → quote → won
 *   Manage  — active contract → care scheduling → roster → billing
 *   Exit    — contract ending → reasons-for-leaving → churn reporting
 *
 * Role-aware: each page block is gated by userCan(<code>, 'read').
 * Users only see help for functionality they actually have access to.
 * Phase headings only render if at least one block under them is
 * visible. The table of contents, likewise, only lists visible phases.
 *
 * Maintenance: this is a LIVING document. When a new admin page lands,
 * add a block (with its userCan() gate) and update the footer's
 * "last updated" stamp. If a page is renamed or retired, update the
 * relevant block — a stale entry is a bug worth reporting.
 */
$pageTitle = 'User Guide';
$activeNav = 'help';

// Resolve permissions once up front so we can use them to gate content
// AND to decide which phase headings / ToC entries to render.
$can = [
    // Records
    'dashboard'            => userCan('dashboard',            'read'),
    'pipeline'             => userCan('pipeline',             'read'),
    'opportunities'        => userCan('opportunities',        'read'),
    'quotes'               => userCan('quotes',               'read'),
    'quotes_rate_override' => userCan('quotes_rate_override', 'edit'),
    'contracts'            => userCan('contracts',            'read'),
    'engagements'          => userCan('engagements',          'read'),
    'roster'               => userCan('roster',               'read'),
    'roster_input'         => userCan('roster_input',         'read'),
    'caregivers_list'      => userCan('caregivers_list',      'read'),
    'clients_list'         => userCan('clients_list',         'read'),
    'patients_list'        => userCan('patients_list',        'read'),
    'student_tracking'     => userCan('student_tracking',     'read'),
    'unbilled_care'        => true, // bound to auth-only; visible to any logged-in admin

    // Reports
    'reports_client_profitability' => userCan('reports_client_profitability', 'read'),
    'reports_client_billing'       => userCan('reports_client_billing',       'read'),
    'reports_caregiver_earnings'   => userCan('reports_caregiver_earnings',   'read'),
    'reports_days_worked'          => userCan('reports_days_worked',          'read'),

    // Inbox
    'enquiries'  => userCan('enquiries',  'read'),
    'onboarding' => userCan('onboarding', 'read'),

    // Data
    'people_review' => userCan('people_review', 'read'),

    // Admin + Config
    'users'                 => userCan('users',                 'read'),
    'roles'                 => userCan('roles',                 'read'),
    'activity_log'          => userCan('activity_log',          'read'),
    'email_log'             => userCan('email_log',             'read'),
    'products'              => userCan('products',              'read'),
    'config_activity_types' => userCan('config_activity_types', 'read'),
    'config_fx_rates'       => userCan('config_fx_rates',       'read'),
    'config_aliases'        => userCan('config_aliases',        'read'),
];

// Which phases have any visible content?
$phaseHasContent = [
    'acquire' => $can['enquiries'] || $can['pipeline'] || $can['opportunities'] || $can['quotes'],
    'manage'  => $can['contracts'] || $can['engagements'] || $can['roster'] || $can['roster_input']
                 || $can['caregivers_list'] || $can['clients_list'] || $can['patients_list']
                 || $can['student_tracking']
                 || $can['reports_client_profitability'] || $can['reports_client_billing']
                 || $can['reports_caregiver_earnings'] || $can['reports_days_worked']
                 || $can['unbilled_care'],
    'exit'    => $can['contracts'], // exit is contract-driven; only show if they see contracts
    'admin'   => $can['users'] || $can['roles'] || $can['activity_log'] || $can['email_log']
                 || $can['products'] || $can['config_activity_types'] || $can['config_fx_rates']
                 || $can['config_aliases'] || $can['onboarding'] || $can['people_review'],
];

require APP_ROOT . '/templates/layouts/admin.php';
?>

<style>
.help-page { max-width: 900px; }
.help-page h2 { margin-top: 2rem; border-bottom: 2px solid #e2e8f0; padding-bottom: 0.4rem; color: #1e293b; }
.help-page h3 { margin-top: 1.5rem; color: #334155; }
.help-page .phase-pill { display:inline-block; padding: 2px 10px; border-radius: 10px; font-size: 0.72rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.04em; color: #fff; margin-right: 0.4rem; }
.help-page .phase-acquire { background: #0d6efd; }
.help-page .phase-manage  { background: #15803d; }
.help-page .phase-exit    { background: #6c757d; }
.help-page .phase-admin   { background: #7c3aed; }
.help-page .page-block { background: #fff; border: 1px solid #e2e8f0; border-radius: 6px; padding: 1rem 1.2rem; margin-bottom: 1rem; }
.help-page .page-block h4 { margin: 0 0 0.4rem 0; display: flex; justify-content: space-between; align-items: baseline; gap: 0.5rem; flex-wrap: wrap; }
.help-page .page-block h4 a { font-family: monospace; font-size: 0.82rem; color: #0d6efd; text-decoration: none; font-weight: 400; }
.help-page .page-block h4 a:hover { text-decoration: underline; }
.help-page .what  { color: #64748b; font-size: 0.92rem; }
.help-page .do    { margin: 0.6rem 0 0 0; }
.help-page .tips  { background: #fff7ed; border-left: 3px solid #fb923c; padding: 0.5rem 0.8rem; margin: 0.6rem 0 0 0; font-size: 0.9rem; color: #7c2d12; border-radius: 0 4px 4px 0; }
.help-page .tips strong { color: #431407; }
.help-page ul { padding-left: 1.2rem; }
.help-page li { margin: 0.2rem 0; }
.help-page .toc { background: #f1f5f9; border: 1px solid #cbd5e1; border-radius: 6px; padding: 0.8rem 1rem; margin-bottom: 1rem; }
.help-page .toc ol { margin: 0; padding-left: 1.4rem; }
.help-page .glossary dt { font-weight: 600; color: #1e293b; }
.help-page .glossary dd { margin: 0 0 0.5rem 0; color: #475569; }
.help-page .empty-role { background: #fff7ed; border: 1px solid #fed7aa; color: #7c2d12; padding: 0.8rem 1rem; border-radius: 4px; font-size: 0.9rem; margin-bottom: 1rem; }
</style>

<div class="help-page">

<p style="color:#64748b;font-size:0.95rem;">
    A guide to working in TCH. This page only shows you the parts of
    the system you have access to —  features outside your permissions
    are hidden here to keep things relevant.
</p>

<?php if (!$phaseHasContent['acquire'] && !$phaseHasContent['manage'] && !$phaseHasContent['exit'] && !$phaseHasContent['admin']): ?>
    <div class="empty-role">
        You don't currently have access to any admin features. Contact
        your account administrator if you think this is a mistake.
    </div>
<?php else: ?>

<div class="toc">
    <strong>Contents</strong>
    <ol>
        <li><a href="#overview">Overview — how TCH works end to end</a></li>
        <?php if ($phaseHasContent['acquire']): ?>
            <li><a href="#acquire">Acquire — find &amp; win new clients</a></li>
        <?php endif; ?>
        <?php if ($phaseHasContent['manage']): ?>
            <li><a href="#manage">Manage — deliver care, bill, retain</a></li>
        <?php endif; ?>
        <?php if ($phaseHasContent['exit']): ?>
            <li><a href="#exit">Exit — offboarding &amp; churn</a></li>
        <?php endif; ?>
        <?php if ($phaseHasContent['admin']): ?>
            <li><a href="#admin">Admin &amp; config</a></li>
        <?php endif; ?>
        <li><a href="#glossary">Glossary of terms</a></li>
        <li><a href="#help">Getting help &amp; reporting bugs</a></li>
    </ol>
</div>

<h2 id="overview">Overview — how TCH works end to end</h2>

<p>TCH runs the lifecycle of a homecare client through three stages:</p>

<ol>
    <li>
        <span class="phase-pill phase-acquire">Acquire</span>
        A prospective client's <strong>enquiry</strong> comes in via the public website
        (or directly by phone / referral). We <strong>qualify</strong> it,
        build a <strong>quote</strong> they can accept, and turn the accepted
        quote into an <strong>active contract</strong>.
    </li>
    <li>
        <span class="phase-pill phase-manage">Manage</span>
        An active contract drives day-to-day operations:
        caregivers are <strong>assigned</strong>, shifts go onto the
        <strong>roster</strong>, care is <strong>delivered and approved</strong>,
        and clients get <strong>billed</strong>.
    </li>
    <li>
        <span class="phase-pill phase-exit">Exit</span>
        When a contract ends, we capture why, log the final figures,
        and feed that into retention / churn reporting so we can
        understand <em>why</em> clients leave.
    </li>
</ol>

<p>Each stage has its own pages and its own reporting surface. The
sidebar on the left groups things by category. Only the categories
relevant to your permissions are visible to you.</p>

<!-- ═════════════════════════════════════════════════════════════
     ACQUIRE
     ═════════════════════════════════════════════════════════════ -->

<?php if ($phaseHasContent['acquire']): ?>
<h2 id="acquire"><span class="phase-pill phase-acquire">Acquire</span> Phase 1 — Find &amp; win new clients</h2>

<p>The order you usually hit these pages:</p>

<p style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:4px;padding:0.6rem 0.8rem;font-size:0.9rem;color:#1e3a8a;">
    <strong>Enquiry</strong> → <strong>Opportunity</strong> → <strong>Quote</strong> → <strong>Closed-Won</strong> → <strong>Active Contract</strong>
</p>

<?php if ($can['enquiries']): ?>
<div class="page-block">
    <h4>Enquiries inbox <a href="<?= APP_URL ?>/admin/enquiries">/admin/enquiries</a></h4>
    <p class="what"><strong>What this is:</strong> the inbox of all submissions from the public enquiry form on the TCH website. Every "I need a caregiver" or "I'd like to be a caregiver" form lands here.</p>
    <div class="do"><strong>What you do here:</strong>
        <ul>
            <li>Open a new enquiry to see the submitter's contact details and what they said.</li>
            <li>Change status as you work it: <em>New → Contacted → Converted</em> (or Closed / Spam).</li>
            <li>Add notes as you speak to them. Everything is time-stamped and attributed.</li>
            <li>For client-type enquiries worth pursuing: click <strong>+ Convert to Opportunity</strong> (the green button at the top). This pre-fills a new opportunity from the enquiry data.</li>
        </ul>
    </div>
    <div class="tips"><strong>Tips:</strong> Don't use this page for the sales work itself — convert to an Opportunity early, so you've got a proper pipeline card you can track. Caregiver-type enquiries don't convert to opportunities (they're caregiver applicants, not clients).</div>
</div>
<?php endif; ?>

<?php if ($can['pipeline']): ?>
<div class="page-block">
    <h4>Pipeline (Kanban) <a href="<?= APP_URL ?>/admin/pipeline">/admin/pipeline</a></h4>
    <p class="what"><strong>What this is:</strong> a drag-and-drop board of every open opportunity, grouped by stage. The "glance" view of sales health — one look tells you how much business is in each stage and how much it's worth.</p>
    <div class="do"><strong>What you do here:</strong>
        <ul>
            <li>Drag a card right to advance it to the next stage. The database updates instantly.</li>
            <li>Drag to <strong>Closed — Won</strong> to mark a deal won. If there's a draft quote linked to the opp, it automatically flips to an active contract.</li>
            <li>Drag to <strong>Closed — Lost</strong> to mark a deal lost. You'll be asked for a reason — this feeds churn reporting later.</li>
            <li>Click any card to open that opportunity's detail page.</li>
        </ul>
    </div>
    <div class="tips"><strong>Tips:</strong> Each column shows a running total in R. Watch the Quoted column — if it's much bigger than Negotiating, you've got quotes out that aren't being chased. The Kanban is desktop-only; drag-drop doesn't work reliably on mobile. Use the opportunities list view on phone.</div>
</div>
<?php endif; ?>

<?php if ($can['opportunities']): ?>
<div class="page-block">
    <h4>Opportunities list <a href="<?= APP_URL ?>/admin/opportunities">/admin/opportunities</a></h4>
    <p class="what"><strong>What this is:</strong> a table view of the same data as the Kanban. Filter by stage, owner, or status (open / closed / archived / all).</p>
    <div class="do"><strong>What you do here:</strong>
        <ul>
            <li>Find opportunities by any filter combination.</li>
            <li>Click through to detail to edit fields or advance stages without dragging.</li>
            <li>Create a new opportunity directly (<strong>+ New Opportunity</strong>) — useful for referrals or phone calls that didn't come through the enquiry form.</li>
        </ul>
    </div>
    <div class="tips"><strong>Tips:</strong> Status "closed" means the opportunity is done (won or lost). Status "archived" is for things you want to keep but hide from default views — old test data, duplicate records, etc.</div>
</div>

<div class="page-block">
    <h4>Opportunity detail <code>/admin/opportunities/{id}</code></h4>
    <p class="what"><strong>What this is:</strong> the full record of one opportunity. Everything about a single deal you're working.</p>
    <div class="do"><strong>What you do here:</strong>
        <ul>
            <li><strong>Stage transition buttons</strong> — same actions as the Kanban, but via clickable buttons. Useful if you're already on the page.</li>
            <?php if ($can['quotes']): ?>
                <li><strong>Build quote</strong> — green button in the "Quote / contract" panel. Opens the quote builder with client/patient/start date pre-filled from this opportunity. Once a quote exists, it becomes "Continue editing quote".</li>
            <?php endif; ?>
            <li><strong>Link a client and patient record</strong> — once you know who the opp is for. Early on these can stay blank.</li>
            <li><strong>Notes + Tasks panel</strong> (the "Notes" section at the bottom) — log calls, meetings, emails, follow-ups. Tasks can be assigned to someone else and marked complete later.</li>
            <li><strong>Edit</strong> — change any field.</li>
        </ul>
    </div>
    <div class="tips"><strong>Tips:</strong> The reason-lost breakdown matters for reporting. Don't just guess — the more honest the reason, the more useful the churn data later.</div>
</div>
<?php endif; ?>

<?php if ($can['quotes']): ?>
<div class="page-block">
    <h4>Quotes list <a href="<?= APP_URL ?>/admin/quotes">/admin/quotes</a></h4>
    <p class="what"><strong>What this is:</strong> every quote document that exists. Filter by status (Draft / Sent / Accepted / Rejected / Expired) or see them all.</p>
    <div class="do"><strong>What you do here:</strong>
        <ul>
            <li>Find a quote and click through to edit or view it.</li>
            <li>See which quotes are still "Draft" (not yet sent to the client) — those are where to chase yourself.</li>
            <li>Create a cold quote (<strong>+ New Quote</strong>) — but you'll usually reach the builder via the "Build quote" button on an opportunity instead.</li>
        </ul>
    </div>
    <div class="tips"><strong>Tips:</strong> "Open" tab = Draft + Sent + Accepted combined. Accepted quotes with a linked opportunity don't become contracts on their own — they wait for the opp's Closed-Won transition. Accepted quotes without an opportunity show a manual "Activate as live contract" button.</div>
</div>

<div class="page-block">
    <h4>Quote builder <code>/admin/quotes/new</code></h4>
    <p class="what"><strong>What this is:</strong> the form where you actually construct a quote. Pick a client + patient, add product lines with unit and quantity, see a live running total.</p>
    <div class="do"><strong>What you do here:</strong>
        <ol>
            <li>Pick the patient. The bill-payer (client) auto-fills if the patient has a default client linked.</li>
            <li>Set start + end dates (leave end blank for "ongoing until cancelled").</li>
            <li>Add one line per product the client is buying. For each line:
                <ul>
                    <li>Pick the product. The unit dropdown narrows to only the units that product supports.</li>
                    <li>The rate prefills from the product's standard rate for that unit.</li>
                    <li>Set the quantity per billing period.</li>
                    <li>Set per-line start/end dates if they differ from the contract dates (e.g. Day Care May–July, Errand Care June onwards).</li>
                </ul>
            </li>
            <li>Watch the running total at the bottom update as you type.</li>
            <li>Save as Draft. You can come back and edit until you're ready to send.</li>
        </ol>
    </div>
    <?php if ($can['quotes_rate_override']): ?>
        <div class="tips"><strong>Tips:</strong> <strong>Rate override</strong> is available on your account — changing the rate away from the product default highlights the field orange and requires a reason. Every override is audit-logged. Use sparingly; defaults should be the rule, not the exception.</div>
    <?php else: ?>
        <div class="tips"><strong>Tips:</strong> Rate fields are locked to the product default. If you need to quote a non-standard rate, your account administrator can grant the rate-override permission; every override gets a required reason and is audit-logged.</div>
    <?php endif; ?>
</div>

<div class="page-block">
    <h4>Quote detail <code>/admin/quotes/{id}</code></h4>
    <p class="what"><strong>What this is:</strong> the read view of a quote. Status transitions (Send / Accept / Reject / Expire) live here.</p>
    <div class="do"><strong>What you do here:</strong>
        <ul>
            <li><strong>Download PDF</strong> — opens a print-friendly version of the quote. Use your browser's Print → Save as PDF to get a file you can email the client.</li>
            <li><strong>Mark as Sent</strong> once you've emailed / given the quote to the client.</li>
            <li><strong>Record acceptance</strong> when they agree — a dialog asks how (email / phone / in-person / signed-PDF / portal) and lets you add a note. The quote transitions to <em>Accepted</em>.</li>
            <li><strong>Mark Rejected</strong> if they decline, or <strong>Mark Expired</strong> if time runs out and they went quiet.</li>
            <?php if ($can['contracts']): ?>
                <li>If there's no linked opportunity: <strong>Activate as live contract</strong> on an accepted quote flips it to status=active.</li>
            <?php endif; ?>
        </ul>
    </div>
    <div class="tips"><strong>Tips:</strong> The typical flow is: Draft (building) → Sent (off to client) → Accepted (they said yes). Then the opportunity's Closed-Won transition triggers the contract activation. You don't need to click "Activate" separately if the quote came from an opportunity. The PDF uses a generic TCH template; a branded version is coming.</div>
</div>
<?php endif; ?>

<?php endif; // phase acquire ?>

<!-- ═════════════════════════════════════════════════════════════
     MANAGE
     ═════════════════════════════════════════════════════════════ -->

<?php if ($phaseHasContent['manage']): ?>
<h2 id="manage"><span class="phase-pill phase-manage">Manage</span> Phase 2 — Deliver care, bill, retain</h2>

<?php if ($can['caregivers_list']): ?>
<div class="page-block">
    <h4>Caregivers <a href="<?= APP_URL ?>/admin/caregivers">/admin/caregivers</a></h4>
    <p class="what"><strong>What this is:</strong> your caregiver database — every person we can place on a care engagement. Includes core profile, contact details, working pattern, status (active, on-hold, etc.).</p>
    <div class="do"><strong>What you do here:</strong>
        <ul>
            <li>Search for a caregiver by name, TCH ID, or status.</li>
            <li>Click through to the caregiver detail page to review their profile, notes, and activity history.</li>
            <li>Update personal details, flag them as unavailable, or change status as their situation changes.</li>
        </ul>
    </div>
</div>
<?php endif; ?>

<?php if ($can['clients_list']): ?>
<div class="page-block">
    <h4>Clients <a href="<?= APP_URL ?>/admin/clients">/admin/clients</a></h4>
    <p class="what"><strong>What this is:</strong> every bill-payer — the people/entities invoiced for care. Shows account number (TCH-C####), name, patient they pay for, revenue months, and totals.</p>
    <div class="do"><strong>What you do here:</strong>
        <ul>
            <li>Find a client by name or account number.</li>
            <li>Click through to the client detail page to see contact details, the linked patient, billing history, and notes.</li>
            <li>Edit billing defaults (frequency, entity) and manage the client-to-patient link.</li>
            <li>Create a new client record (<strong>+ New Client</strong>) when a new bill-payer comes onboard.</li>
        </ul>
    </div>
    <div class="tips"><strong>Tips:</strong> A <em>client</em> is the bill-payer. A <em>patient</em> is the care recipient. Sometimes they're the same person. When they're different (e.g. a family member paying for a parent), link them via the Patient detail page.</div>
</div>
<?php endif; ?>

<?php if ($can['patients_list']): ?>
<div class="page-block">
    <h4>Patients <a href="<?= APP_URL ?>/admin/patients">/admin/patients</a></h4>
    <p class="what"><strong>What this is:</strong> every care recipient. The people caregivers are placed with. Shows name, TCH ID, linked client (bill-payer), and engagement status.</p>
    <div class="do"><strong>What you do here:</strong>
        <ul>
            <li>Find a patient by name or TCH ID.</li>
            <li>Click through to the patient detail page to review the full record and change their linked client.</li>
            <li>Create a new patient record (<strong>+ New Patient</strong>) when someone new starts receiving care.</li>
        </ul>
    </div>
    <div class="tips"><strong>Tips:</strong> Patients with no linked client show up as "Unbilled" — scheduling care for them is blocked until a bill-payer is attached (see the bill-payer guardrail in the glossary).</div>
</div>
<?php endif; ?>

<?php if ($can['student_tracking']): ?>
<div class="page-block">
    <h4>Students <a href="<?= APP_URL ?>/admin/students">/admin/students</a></h4>
    <p class="what"><strong>What this is:</strong> trainee caregivers going through the TCH training programme. Tracks cohort, module progress, attendance, and outcome.</p>
    <div class="do"><strong>What you do here:</strong>
        <ul>
            <li>Find a student by name or cohort.</li>
            <li>View module scores, attendance, and progression.</li>
            <li>Promote a student to caregiver when they complete the programme.</li>
        </ul>
    </div>
</div>
<?php endif; ?>

<?php if ($can['contracts']): ?>
<div class="page-block">
    <h4>Contracts list <a href="<?= APP_URL ?>/admin/contracts">/admin/contracts</a></h4>
    <p class="what"><strong>What this is:</strong> every contract, grouped by status (Active / Draft / On Hold / Completed / Cancelled).</p>
    <div class="do"><strong>What you do here:</strong>
        <ul>
            <li>Default view is Active — that's what's happening right now.</li>
            <li>Click any contract for the detail page with lines, invoice info, and roster history.</li>
        </ul>
    </div>
    <div class="tips"><strong>Tips:</strong> Direct contract creation is possible but uncommon — most contracts arrive here by winning an opportunity. If you find yourself cutting a contract directly, consider whether it should've been an opportunity + quote first, so the sales pipeline reporting stays accurate.</div>
</div>
<?php endif; ?>

<?php if ($can['engagements']): ?>
<div class="page-block">
    <h4>Care Scheduling <a href="<?= APP_URL ?>/admin/engagements">/admin/engagements</a></h4>
    <p class="what"><strong>What this is:</strong> the link between a contract and the caregiver who actually delivers the care. One engagement = one caregiver assigned to one contract.</p>
    <div class="do"><strong>What you do here:</strong>
        <ul>
            <li>Assign a caregiver to a contract.</li>
            <li>Set the working pattern (days of the week, hours).</li>
            <li>Change or end the assignment when caregivers rotate.</li>
        </ul>
    </div>
    <div class="tips"><strong>Tips:</strong> Scheduling is guardrailed — a patient with no linked client (no bill-payer) cannot have care scheduled. This prevents us delivering free care by mistake.</div>
</div>
<?php endif; ?>

<?php if ($can['roster']): ?>
<div class="page-block">
    <h4>Roster View <a href="<?= APP_URL ?>/admin/roster">/admin/roster</a></h4>
    <p class="what"><strong>What this is:</strong> a patient-centric monthly calendar view of who delivered care when.</p>
    <div class="do"><strong>What you do here:</strong>
        <ul>
            <li>See the month at a glance — one row per patient, one column per day.</li>
            <li>Colour-coded by caregiver.</li>
            <li>Patients with unbilled care flagged red.</li>
            <li>Export to CSV or print landscape A4.</li>
        </ul>
    </div>
    <div class="tips"><strong>Tips:</strong> This page is read-only — you don't edit the roster here. Data comes from the ingested Timesheet + Panel workbooks (via Onboarding).</div>
</div>
<?php endif; ?>

<?php if ($can['roster_input']): ?>
<div class="page-block">
    <h4>Care Approval <a href="<?= APP_URL ?>/admin/roster/input">/admin/roster/input</a></h4>
    <p class="what"><strong>What this is:</strong> the workflow page where delivered shifts get approved and added to the roster.</p>
    <div class="do"><strong>What you do here:</strong>
        <ul>
            <li>Approve shifts that have been delivered so they land in the roster and feed billing.</li>
        </ul>
    </div>
    <div class="tips"><strong>Tips:</strong> In the current phase this feeds off the monthly workbook ingest rather than real-time approvals. Once the caregiver portal ships, caregivers will log shifts directly.</div>
</div>
<?php endif; ?>

<?php if ($can['unbilled_care']): ?>
<div class="page-block">
    <h4>Unbilled Care <a href="<?= APP_URL ?>/admin/unbilled-care">/admin/unbilled-care</a></h4>
    <p class="what"><strong>What this is:</strong> every shift delivered where we haven't yet linked the patient to a bill-payer, so no invoice goes out. The "money on the table" surface.</p>
    <div class="do"><strong>What you do here:</strong>
        <ul>
            <li>See all unbilled shifts grouped by patient.</li>
            <li>For each patient, identify the real bill-payer and link them via the Patient detail page.</li>
            <li>Watch the unbilled-R figure shrink as you work through the list.</li>
        </ul>
    </div>
    <div class="tips"><strong>Tips:</strong> This list exists because historic roster data didn't always know who was paying. Every patient linked here means an invoice we can actually raise — treat it as a priority inbox.</div>
</div>
<?php endif; ?>

<?php if ($can['reports_client_profitability'] || $can['reports_client_billing'] || $can['reports_caregiver_earnings'] || $can['reports_days_worked']): ?>
<div class="page-block">
    <h4>Financial reports</h4>
    <p class="what"><strong>What this is:</strong> cross-cutting financial reports. <?= (
        ($can['reports_client_profitability'] ? 1 : 0)
      + ($can['reports_client_billing']       ? 1 : 0)
      + ($can['reports_caregiver_earnings']   ? 1 : 0)
      + ($can['reports_days_worked']          ? 1 : 0)
    ) ?> available to you:</p>
    <div class="do"><strong>What you do here:</strong>
        <ul>
            <?php if ($can['reports_client_profitability']): ?>
                <li><strong><a href="<?= APP_URL ?>/admin/reports/client-profitability">Client Profitability</a></strong> — per client, what we bill vs. what we pay caregivers. Gross margin surface.</li>
            <?php endif; ?>
            <?php if ($can['reports_client_billing']): ?>
                <li><strong><a href="<?= APP_URL ?>/admin/reports/client-billing">Client Billing</a></strong> — a matrix of client × month showing revenue.</li>
            <?php endif; ?>
            <?php if ($can['reports_caregiver_earnings']): ?>
                <li><strong><a href="<?= APP_URL ?>/admin/reports/caregiver-earnings">Caregiver Earnings</a></strong> — per caregiver, what they earned per month.</li>
            <?php endif; ?>
            <?php if ($can['reports_days_worked']): ?>
                <li><strong><a href="<?= APP_URL ?>/admin/reports/days-worked">Days Worked</a></strong> — per caregiver, days × months attendance.</li>
            <?php endif; ?>
        </ul>
    </div>
    <div class="tips"><strong>Tips:</strong> All reports read from a single source of truth: the roster (cost side) and client revenue (billing side). Figures across reports should always agree — if they don't, flag it.</div>
</div>
<?php endif; ?>

<?php endif; // phase manage ?>

<!-- ═════════════════════════════════════════════════════════════
     EXIT
     ═════════════════════════════════════════════════════════════ -->

<?php if ($phaseHasContent['exit']): ?>
<h2 id="exit"><span class="phase-pill phase-exit">Exit</span> Phase 3 — Offboarding &amp; churn</h2>

<p>The Exit phase is lightly developed today — the rich reporting surface (churn reasons, length-of-relationship curves, lifetime value at exit) is on the roadmap. For now:</p>

<div class="page-block">
    <h4>Ending a contract</h4>
    <p class="what"><strong>What this is:</strong> the process of marking a contract Complete or Cancelled.</p>
    <div class="do"><strong>What you do here:</strong>
        <ul>
            <li>Open the contract detail page.</li>
            <li>Change status to <em>Completed</em> (natural end) or <em>Cancelled</em> (terminated early).</li>
            <li>Optionally set a cancellation reason.</li>
        </ul>
    </div>
    <div class="tips"><strong>Tips:</strong> Dedicated exit reporting is planned — reason-for-leaving, length-of-relationship curves, lifetime value distributions. Until it lands, capture context in the notes field so it's there when we build the reports.</div>
</div>
<?php endif; // phase exit ?>

<!-- ═════════════════════════════════════════════════════════════
     ADMIN / CONFIG
     ═════════════════════════════════════════════════════════════ -->

<?php if ($phaseHasContent['admin']): ?>
<h2 id="admin"><span class="phase-pill phase-admin">Admin</span> Admin &amp; config</h2>

<?php if ($can['onboarding']): ?>
<div class="page-block">
    <h4>Monthly Onboarding <a href="<?= APP_URL ?>/admin/onboarding">/admin/onboarding</a></h4>
    <p class="what"><strong>What this is:</strong> a guided task list for monthly data ingestion — upload Timesheet + Revenue workbooks, reconcile aliases, handle caregiver patterns, etc.</p>
    <div class="tips"><strong>Tips:</strong> Run through the tasks in order each month. The dashboard shows which are done vs. outstanding.</div>
</div>
<?php endif; ?>

<?php if ($can['people_review']): ?>
<div class="page-block">
    <h4>Pending Approvals <a href="<?= APP_URL ?>/admin/people/review">/admin/people/review</a></h4>
    <p class="what"><strong>What this is:</strong> the queue of new person records awaiting review (typically caregivers imported from spreadsheets that need a human glance before going live).</p>
    <div class="do"><strong>What you do here:</strong>
        <ul>
            <li>Review each record in the queue.</li>
            <li>Approve to move the record into active status, reject to archive it.</li>
        </ul>
    </div>
</div>
<?php endif; ?>

<?php if ($can['users']): ?>
<div class="page-block">
    <h4>Users <a href="<?= APP_URL ?>/admin/users">/admin/users</a></h4>
    <p class="what"><strong>What this is:</strong> everyone who can log into the system. Invite new users, reset passwords, manage roles.</p>
</div>
<?php endif; ?>

<?php if ($can['roles']): ?>
<div class="page-block">
    <h4>Roles &amp; Permissions <a href="<?= APP_URL ?>/admin/roles">/admin/roles</a></h4>
    <p class="what"><strong>What this is:</strong> the matrix of roles × pages × actions (read / create / edit / delete). Controls who can see and do what across the system.</p>
    <div class="tips"><strong>Tips:</strong> New sensitive actions (like rate-override on quotes) are off by default for most roles — enable them per role as needed. Changes take effect on the affected user's next page load.</div>
</div>
<?php endif; ?>

<?php if ($can['activity_log']): ?>
<div class="page-block">
    <h4>Activity Log <a href="<?= APP_URL ?>/admin/activity">/admin/activity</a></h4>
    <p class="what"><strong>What this is:</strong> every mutating action taken by any user, with field-level before/after snapshots. The audit trail of the system.</p>
    <div class="tips"><strong>Tips:</strong> Use this to answer "who changed X, when, and what did it used to be?". Filter by user, page, or entity.</div>
</div>
<?php endif; ?>

<?php if ($can['email_log']): ?>
<div class="page-block">
    <h4>Email Outbox <a href="<?= APP_URL ?>/admin/email-log">/admin/email-log</a></h4>
    <p class="what"><strong>What this is:</strong> every email the system has sent or queued — invites, password resets, notifications.</p>
    <div class="do"><strong>What you do here:</strong>
        <ul>
            <li>Confirm an email was actually sent.</li>
            <li>Read the full body and headers.</li>
            <li>Diagnose delivery failures.</li>
        </ul>
    </div>
</div>
<?php endif; ?>

<?php if ($can['products']): ?>
<div class="page-block">
    <h4>Products <a href="<?= APP_URL ?>/admin/products">/admin/products</a></h4>
    <p class="what"><strong>What this is:</strong> the catalogue of care services TCH offers (Day Care, Post-Op, Palliative, etc.) and their standard rates per billing unit.</p>
    <div class="do"><strong>What you do here:</strong>
        <ul>
            <li>Add new products.</li>
            <li>Set the rates per billing unit (hourly / daily / weekly / monthly / per-visit / upfront).</li>
            <li>Mark one unit per product as the default — that's what prefills on quote lines.</li>
        </ul>
    </div>
</div>
<?php endif; ?>

<?php if ($can['config_activity_types']): ?>
<div class="page-block">
    <h4>Activity Types <a href="<?= APP_URL ?>/admin/config/activity-types">/admin/config/activity-types</a></h4>
    <p class="what"><strong>What this is:</strong> the list of activity types available in the Notes + Tasks panel (Call / Email / Meeting / Demo / Follow-up / Note, etc.). Managing this list controls what users can tag their notes with.</p>
</div>
<?php endif; ?>

<?php if ($can['config_fx_rates']): ?>
<div class="page-block">
    <h4>FX Rates <a href="<?= APP_URL ?>/admin/config/fx-rates">/admin/config/fx-rates</a></h4>
    <p class="what"><strong>What this is:</strong> foreign exchange rates used when displaying values in different currencies. v1 is ZAR-only, so this is mostly dormant — reserved for multi-currency reporting.</p>
</div>
<?php endif; ?>

<?php if ($can['config_aliases']): ?>
<div class="page-block">
    <h4>Timesheet Aliases <a href="<?= APP_URL ?>/admin/config/aliases">/admin/config/aliases</a></h4>
    <p class="what"><strong>What this is:</strong> the name-mapping table used during monthly timesheet ingestion. Maps the variations that appear in the spreadsheet ("J. Smith", "Jane S", "Smith Jane") to the canonical person record.</p>
    <div class="do"><strong>What you do here:</strong>
        <ul>
            <li>Add or correct aliases when new name variants surface.</li>
            <li>Promote an unmapped alias to a real person (e.g. a new caregiver's first appearance in the timesheet).</li>
        </ul>
    </div>
    <div class="tips"><strong>Tips:</strong> This is the bridge between "names as they appear in Tuniti's spreadsheet" and "persons as they exist in the system". Keep it tidy and the monthly ingest stays clean.</div>
</div>
<?php endif; ?>

<?php endif; // phase admin ?>

<?php endif; // any phase has content ?>

<!-- ═════════════════════════════════════════════════════════════
     GLOSSARY — always visible
     ═════════════════════════════════════════════════════════════ -->

<h2 id="glossary">Glossary</h2>

<dl class="glossary">
    <?php if ($can['enquiries']): ?>
        <dt>Enquiry</dt>
        <dd>A raw submission from the public website form, or any inbound "interested in TCH" first contact. Lives in the Enquiries inbox.</dd>
    <?php endif; ?>

    <?php if ($can['opportunities'] || $can['pipeline']): ?>
        <dt>Opportunity</dt>
        <dd>A qualified potential deal we're actively working toward Closed-Won or Closed-Lost. Has stages, an owner, an expected value.</dd>
    <?php endif; ?>

    <?php if ($can['quotes']): ?>
        <dt>Quote</dt>
        <dd>A document we build and send the client for acceptance. Structurally it's a draft-status contract in the database — once accepted and activated it becomes a live contract without a schema change.</dd>
    <?php endif; ?>

    <?php if ($can['contracts']): ?>
        <dt>Contract</dt>
        <dd>The commercial agreement — client pays TCH to deliver care to a specific patient at specific rates. Contains one or more <em>lines</em>, one per product.</dd>
    <?php endif; ?>

    <?php if ($can['engagements']): ?>
        <dt>Engagement</dt>
        <dd>A caregiver's assignment to a contract. Many engagements per contract if caregivers rotate over time.</dd>
    <?php endif; ?>

    <?php if ($can['roster'] || $can['roster_input']): ?>
        <dt>Roster</dt>
        <dd>The per-day record of who delivered care to whom. Cost side of the P&amp;L.</dd>
    <?php endif; ?>

    <?php if ($can['clients_list'] || $can['patients_list']): ?>
        <dt>Client vs. Patient</dt>
        <dd>The <strong>client</strong> is the bill-payer. The <strong>patient</strong> is the care recipient. Sometimes they're the same person (e.g. a self-paying client). Other times a family member pays for a parent.</dd>

        <dt>Bill-payer guardrail</dt>
        <dd>A rule that prevents scheduling care for a patient with no linked client. Stops us delivering free care by mistake.</dd>
    <?php endif; ?>

    <?php if ($can['opportunities'] || $can['pipeline']): ?>
        <dt>Stage vs. Status</dt>
        <dd>An opportunity has a <strong>stage</strong> (New / Qualifying / Quoted / Negotiating / Closed-Won / Closed-Lost) — where it is in the sales pipeline. It also has a <strong>status</strong> (open / closed / archived) — whether the record itself is still in play. Stage drives reporting; status drives list filters.</dd>
    <?php endif; ?>

    <?php if ($can['quotes_rate_override']): ?>
        <dt>Rate override</dt>
        <dd>Quote-line rate that differs from the product's standard rate for the chosen billing unit. Requires a special permission and a reason. Audit-logged.</dd>
    <?php endif; ?>

    <?php if ($can['opportunities'] || $can['quotes']): ?>
        <dt>OPP-YYYY-NNNN / Q-YYYY-NNNN</dt>
        <dd>Auto-generated human-friendly references. Every opportunity gets an <code>OPP-2026-0001</code>-style ref on creation. Every quote gets a <code>Q-2026-0001</code>-style ref on first save. Use them when referring to records in email or conversation.</dd>
    <?php endif; ?>

    <?php if ($can['clients_list']): ?>
        <dt>TCH-C####</dt>
        <dd>Client account number. Every client gets one auto-assigned on creation, used as the canonical reference on invoices and the clients list.</dd>
    <?php endif; ?>
</dl>

<!-- ═════════════════════════════════════════════════════════════
     HELP + BUG REPORTS
     ═════════════════════════════════════════════════════════════ -->

<h2 id="help">Getting help &amp; reporting bugs</h2>

<p>Found something confusing, broken, or missing?</p>

<ul>
    <li><strong>In-app bug / feature reporter</strong> — bottom-right floating button on every admin page. Submits directly to the central tracking hub. Use it for anything: typos, broken workflows, "I wish I could do X", confusing terms.</li>
    <li><strong>Your account administrator</strong> — for sensitive or business-critical issues that shouldn't go through the tracker, or to request additional permissions.</li>
</ul>

<p style="color:#64748b;font-size:0.85rem;border-top:1px solid #e2e8f0;padding-top:0.6rem;margin-top:2rem;">
    This guide is a living document. When workflows change, this page
    is updated in the same release — if something here doesn't match
    what you see on the screen, that's a bug worth reporting. You only
    see guidance for features your account has access to.
    <br><br>
    <strong>Last updated:</strong> 2026-04-19 (role-aware gating + full admin coverage).
</p>

</div>

<?php require APP_ROOT . '/templates/layouts/admin_footer.php'; ?>
