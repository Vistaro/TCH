<?php
/**
 * User Manual / UX guide — /admin/help
 *
 * A living user manual aimed at first-time admin users (primarily
 * Tuniti and her team). Structured around the three lifecycle phases
 * of a TCH client relationship:
 *
 *   Acquire — enquiry → opportunity → quote → won
 *   Manage  — active contract → care scheduling → billing
 *   Exit    — contract ending → reasons-for-leaving → churn reporting
 *
 * Each page in the admin gets a "What this is / What you do here /
 * Tips" block so a new user can land, read, and act.
 *
 * Maintenance: this is a LIVING document. When a new admin page or
 * workflow lands, add a block. When a page changes meaningfully,
 * update the relevant block. Version tag at the bottom.
 */
$pageTitle = 'User Guide';
$activeNav = 'help';

// Anchor headings so the TOC can jump-link.
$anchor = function(string $text): string {
    return strtolower(preg_replace('/[^a-z0-9]+/i', '-', trim($text)));
};

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
</style>

<div class="help-page">

<p style="color:#64748b;font-size:0.95rem;">
    A first-time user's guide to working in TCH. Walks through the
    three lifecycle phases — <strong class="phase-pill phase-acquire">Acquire</strong>
    <strong class="phase-pill phase-manage">Manage</strong>
    <strong class="phase-pill phase-exit">Exit</strong> — and explains what each page is for and what to do on it.
</p>

<div class="toc">
    <strong>Contents</strong>
    <ol>
        <li><a href="#overview">Overview — how TCH works end to end</a></li>
        <li><a href="#acquire">Phase 1 — Acquire (find &amp; win new clients)</a></li>
        <li><a href="#manage">Phase 2 — Manage (deliver care, bill, keep clients happy)</a></li>
        <li><a href="#exit">Phase 3 — Exit (offboarding &amp; churn)</a></li>
        <li><a href="#admin">Admin &amp; config (users, roles, settings)</a></li>
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
sidebar on the left groups things by category:
<strong>Records</strong> (Pipeline, Opportunities, Quotes, Contracts, Caregivers,
Clients, Patients), <strong>Reports</strong>, <strong>Inbox</strong>,
and <strong>Admin</strong>.</p>

<!-- ═════════════════════════════════════════════════════════════
     ACQUIRE
     ═════════════════════════════════════════════════════════════ -->

<h2 id="acquire"><span class="phase-pill phase-acquire">Acquire</span> Phase 1 — Find &amp; win new clients</h2>

<p>The order you usually hit these pages:</p>

<p style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:4px;padding:0.6rem 0.8rem;font-size:0.9rem;color:#1e3a8a;">
    <strong>Enquiry</strong> → <strong>Opportunity</strong> → <strong>Quote</strong> → <strong>Closed-Won</strong> → <strong>Active Contract</strong>
</p>

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
    <div class="tips"><strong>Tips:</strong> Each column shows a running total in R. Watch the Quoted column — if it's much bigger than Negotiating, you've got quotes out that aren't being chased. The Kanban is desktop-only; drag-drop doesn't work reliably on mobile. Use the <a href="<?= APP_URL ?>/admin/opportunities">list view</a> on phone.</div>
</div>

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
            <li><strong>Build quote</strong> — green button in the "Quote / contract" panel. Opens the quote builder with client/patient/start date pre-filled from this opportunity. Once a quote exists, it becomes "Continue editing quote".</li>
            <li><strong>Link a client and patient record</strong> — once you know who the opp is for. Early on these can stay blank.</li>
            <li><strong>Notes + Tasks panel</strong> (the "Notes" section at the bottom) — log calls, meetings, emails, follow-ups. Tasks can be assigned to someone else and marked complete later.</li>
            <li><strong>Edit</strong> — change any field.</li>
        </ul>
    </div>
    <div class="tips"><strong>Tips:</strong> The reason-lost breakdown matters for reporting. Don't just guess — the more honest the reason, the more useful the churn data later.</div>
</div>

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
    <div class="tips"><strong>Tips:</strong> <strong>Rate override</strong> — if you have permission, changing the rate away from the product default highlights the field orange and requires a reason. Every override is audit-logged. Not everyone has this permission — ask Ross if you need it.</div>
</div>

<div class="page-block">
    <h4>Quote detail <code>/admin/quotes/{id}</code></h4>
    <p class="what"><strong>What this is:</strong> the read view of a quote. Status transitions (Send / Accept / Reject / Expire) live here.</p>
    <div class="do"><strong>What you do here:</strong>
        <ul>
            <li><strong>Mark as Sent</strong> once you've emailed / given the quote to the client.</li>
            <li><strong>Record acceptance</strong> when they agree — a dialog asks how (email / phone / in-person / signed-PDF / portal) and lets you add a note. The quote transitions to <em>Accepted</em>.</li>
            <li><strong>Mark Rejected</strong> if they decline, or <strong>Mark Expired</strong> if time runs out and they went quiet.</li>
            <li>If there's no linked opportunity: <strong>Activate as live contract</strong> on an accepted quote flips it to status=active.</li>
        </ul>
    </div>
    <div class="tips"><strong>Tips:</strong> The typical flow is: Draft (building) → Sent (off to client) → Accepted (they said yes). Then the opportunity's Closed-Won transition triggers the contract activation. You don't need to click "Activate" separately if the quote came from an opportunity.</div>
</div>

<!-- ═════════════════════════════════════════════════════════════
     MANAGE
     ═════════════════════════════════════════════════════════════ -->

<h2 id="manage"><span class="phase-pill phase-manage">Manage</span> Phase 2 — Deliver care, bill, retain</h2>

<p>Once a quote is won, the contract goes live and these pages become your daily workflow:</p>

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
    <div class="tips"><strong>Tips:</strong> This page is read-only — you don't edit the roster here. Data comes from the ingested Timesheet + Panel workbooks (via Tuniti Onboarding).</div>
</div>

<div class="page-block">
    <h4>Care Approval <a href="<?= APP_URL ?>/admin/roster/input">/admin/roster/input</a></h4>
    <p class="what"><strong>What this is:</strong> the workflow page where delivered shifts get approved and added to the roster.</p>
    <div class="do"><strong>What you do here:</strong>
        <ul>
            <li>Approve shifts that have been delivered so they land in the roster and feed billing.</li>
        </ul>
    </div>
    <div class="tips"><strong>Tips:</strong> In the current phase this feeds off the monthly workbook ingest rather than real-time approvals. Once the Caregiver Portal (future FR) lands, caregivers will log shifts directly.</div>
</div>

<div class="page-block">
    <h4>Reports (billing, earnings, profitability) <a href="<?= APP_URL ?>/admin/reports/client-profitability">/admin/reports/...</a></h4>
    <p class="what"><strong>What this is:</strong> four cross-cutting financial reports: Client Profitability, Client Billing, Caregiver Earnings, Days Worked.</p>
    <div class="do"><strong>What you do here:</strong>
        <ul>
            <li><strong>Client Profitability</strong> — per client, what we bill vs. what we pay caregivers. Gross margin surface.</li>
            <li><strong>Client Billing</strong> — a matrix of client × month showing revenue.</li>
            <li><strong>Caregiver Earnings</strong> — per caregiver, what they earned per month.</li>
            <li><strong>Days Worked</strong> — per caregiver, days × months attendance.</li>
        </ul>
    </div>
    <div class="tips"><strong>Tips:</strong> All reports read from a single source of truth: the <code>daily_roster</code> table (cost side) and <code>client_revenue</code> (billing side). Figures across reports should always agree — if they don't, flag it.</div>
</div>

<!-- ═════════════════════════════════════════════════════════════
     EXIT
     ═════════════════════════════════════════════════════════════ -->

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

<!-- ═════════════════════════════════════════════════════════════
     ADMIN / CONFIG
     ═════════════════════════════════════════════════════════════ -->

<h2 id="admin">Admin &amp; config</h2>

<div class="page-block">
    <h4>Users <a href="<?= APP_URL ?>/admin/users">/admin/users</a></h4>
    <p class="what"><strong>What this is:</strong> everyone who can log into the system. Invite new users here, reset passwords, manage roles.</p>
</div>

<div class="page-block">
    <h4>Roles &amp; Permissions <a href="<?= APP_URL ?>/admin/roles">/admin/roles</a></h4>
    <p class="what"><strong>What this is:</strong> the matrix of roles × pages × actions (read / create / edit / delete). Controls who can see and do what.</p>
    <div class="tips"><strong>Tips:</strong> Most users are <em>Admin</em> (can see and edit everything except delete). <em>Super Admin</em> is Ross only. New sensitive actions (like rate-override on quotes) come off by default — ask Ross to grant them if you need them.</div>
</div>

<div class="page-block">
    <h4>Activity Log <a href="<?= APP_URL ?>/admin/activity">/admin/activity</a></h4>
    <p class="what"><strong>What this is:</strong> every mutating action taken by any user, with field-level before/after snapshots. The audit trail of the system.</p>
    <div class="tips"><strong>Tips:</strong> Use this to answer "who changed X, when, and what did it used to be?". Filter by user, page, or entity.</div>
</div>

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

<div class="page-block">
    <h4>Tuniti Onboarding <a href="<?= APP_URL ?>/admin/onboarding">/admin/onboarding</a></h4>
    <p class="what"><strong>What this is:</strong> a guided task list for monthly data ingestion — upload Timesheet + Revenue workbooks, reconcile aliases, handle caregiver patterns, etc.</p>
    <div class="tips"><strong>Tips:</strong> Run through the tasks in order each month. The dashboard shows which are done vs. outstanding.</div>
</div>

<!-- ═════════════════════════════════════════════════════════════
     GLOSSARY
     ═════════════════════════════════════════════════════════════ -->

<h2 id="glossary">Glossary</h2>

<dl class="glossary">
    <dt>Enquiry</dt>
    <dd>A raw submission from the public website form, or any inbound "interested in TCH" first contact. Lives in the Enquiries inbox.</dd>

    <dt>Opportunity</dt>
    <dd>A qualified potential deal we're actively working toward Closed-Won or Closed-Lost. Has stages, an owner, an expected value.</dd>

    <dt>Quote</dt>
    <dd>A document we build and send the client for acceptance. Structurally it's a draft-status contract in the database — once accepted and activated it becomes a live contract without a schema change.</dd>

    <dt>Contract</dt>
    <dd>The commercial agreement — client pays TCH to deliver care to a specific patient at specific rates. Contains one or more <em>lines</em>, one per product.</dd>

    <dt>Engagement</dt>
    <dd>A caregiver's assignment to a contract. Many engagements per contract if caregivers rotate over time.</dd>

    <dt>Roster</dt>
    <dd>The per-day record of who delivered care to whom. Cost side of the P&amp;L.</dd>

    <dt>Client vs. Patient</dt>
    <dd>The <strong>client</strong> is the bill-payer. The <strong>patient</strong> is the care recipient. Sometimes they're the same person (e.g. a self-paying client). Other times a family member pays for a parent.</dd>

    <dt>Bill-payer guardrail</dt>
    <dd>A rule that prevents scheduling care for a patient with no linked client. Stops us delivering free care by mistake.</dd>

    <dt>Stage vs. Status</dt>
    <dd>An opportunity has a <strong>stage</strong> (New / Qualifying / Quoted / Negotiating / Closed-Won / Closed-Lost) — where it is in the sales pipeline. It also has a <strong>status</strong> (open / closed / archived) — whether the record itself is still in play. Stage drives reporting; status drives list filters.</dd>

    <dt>Rate override</dt>
    <dd>Quote-line rate that differs from the product's standard rate for the chosen billing unit. Requires a special permission and a reason. Audit-logged.</dd>

    <dt>OPP-YYYY-NNNN / Q-YYYY-NNNN</dt>
    <dd>Auto-generated human-friendly references. Every opportunity gets an <code>OPP-2026-0001</code>-style ref on creation. Every quote gets a <code>Q-2026-0001</code>-style ref on first save. Use them when referring to records in email or conversation.</dd>
</dl>

<!-- ═════════════════════════════════════════════════════════════
     HELP + BUG REPORTS
     ═════════════════════════════════════════════════════════════ -->

<h2 id="help">Getting help &amp; reporting bugs</h2>

<p>Found something confusing, broken, or missing?</p>

<ul>
    <li><strong>In-app bug / feature reporter</strong> — bottom-right floating button on every admin page. Submits directly to the central tracking hub. Use it for anything: typos, broken workflows, "I wish I could do X", confusing terms.</li>
    <li><strong>Ross directly</strong> — for sensitive or business-critical issues that shouldn't go through the tracker.</li>
</ul>

<p style="color:#64748b;font-size:0.85rem;border-top:1px solid #e2e8f0;padding-top:0.6rem;margin-top:2rem;">
    This guide is a living document. When workflows change, this page
    is updated in the same release — if something here doesn't match
    what you see on the screen, that's a bug worth reporting.
    <br><br>
    <strong>Last updated:</strong> 2026-04-18 (after FR-L sales pipeline + FR-C quote builder shipped).
</p>

</div>

<?php require APP_ROOT . '/templates/layouts/admin_footer.php'; ?>
