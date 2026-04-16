# Quote + Client Portal Plan

**Created:** 2026-04-16
**Status:** Draft — pending Ross's review + Hub-filing
**Scope summary:** Take TCH from "Tuniti sends a WhatsApp / email and a contract magically appears" to a structured quote → client acceptance → contract → scheduling pipeline, with a lightweight self-service portal for clients and patients who want it.

This document lists each discrete piece of work as an FR ready to file into Nexus Hub. Each entry uses the standing three-layer format (title + business description + technical description). FRs are listed in delivery order; dependencies are flagged explicitly.

---

## 1. The end state — what Ross is asking for

1. **Tuniti (or any admin-role user) drafts a quote** for a named client + patient
2. **Each quote line picks a product** (e.g. Day Care, Errand Care) and a **billing unit** (hourly / daily / weekly / monthly / per visit / upfront) chosen from the options the product supports
3. **The quoter enters quantity + start date + end date** per line; the rate prefills from the product's standard rate for that unit, and a role-gated override can adjust the rate with a reason
4. **The line total and quote total compute live** at render; nothing is stored as a cached number
5. **A quote with no end date represents ongoing service** (the contract auto-renews, which is already how contracts work today)
6. **The quote is sent to the client** by email, as a PDF download, or via the portal — the quoter picks per quote, defaulting to the client's stored preference
7. **Clients + patients don't exist in the system as users until they accept a quote** — acceptance creates their user record and triggers their first contract
8. **Caregivers submit their availability** (recurring pattern + ad-hoc exceptions) via a mobile-friendly form so future scheduling can match requirement to availability

Currency is ZAR only for v1 — design supports future multi-currency without a rewrite.

---

## 2. Delivery sequence (dependency-ordered)

| # | FR | Depends on | Notes |
|---|----|----|---|
| FR-A | Product billing rates (multi-unit pricing) | — | Schema foundation; everything else wants this first |
| FR-B | Per-line dates on `contract_lines` | — | Schema, trivial |
| FR-C | Quote builder screen | A + B | The big UI piece |
| FR-D | Quote state machine + admin-triggered acceptance | C | Draft → sent → accepted → active |
| FR-E | Quoter rate-override role + permission + audit | C | Small permission + UI gate; can ship with or just after C |
| FR-F | Quote PDF (boilerplate v1) + Tuniti onboarding task for proper template | D | Dompdf or similar |
| FR-G | Quote email delivery + client preference field | D + F | Uses existing mailer |
| FR-H | Client / Patient user classes + invite-on-accept + portal login | D | Auth + token flow |
| FR-I | Portal acceptance flow (tokenised one-shot "Accept" link) | G + H | The portal delivery option becomes real here |
| FR-J | Mid-contract term change workflow | (contract model already supports) | Uses `superseded_by` chain |
| FR-K | Caregiver availability profile + diary | (parallel track — not a quoting dependency) | Prerequisite for scheduling (Phase 3) |

Rough phasing if you want to chunk it:

- **Phase 1 (foundation):** FR-A, FR-B
- **Phase 2 (quoting internal-only):** FR-C, FR-D, FR-E — admin can draft, accept on client's behalf, move to contract. No client-facing surface yet
- **Phase 3 (quote delivery):** FR-F, FR-G — PDF + email go out
- **Phase 4 (portal):** FR-H, FR-I — clients can log in to accept, view contracts, receive future quotes
- **Phase 5 (lifecycle):** FR-J — mid-contract changes without manual reset
- **Phase K (parallel / independent):** FR-K can run alongside any of the above and is required before the scheduling build

---

## 3. Open design questions (to resolve before or during Phase 1)

These cut across multiple FRs; flag answers back into the FRs below when decided.

1. **Multi-currency shape** — even though ZAR is all we need for v1, should `product_billing_rates` carry a `currency_code` column now so the schema is future-proof? Cost: one extra column. Benefit: no rework if TCH ever quotes in GBP or USD. **Recommendation: yes — add the column, default to `'ZAR'`, don't build UI around it yet.**
2. **Per-line rate override — role or permission bit?** Introducing a new role ("Quoter") vs. extending an existing permission (`contracts.edit_rate` or similar). **Recommendation: extend permissions, don't invent a role — more flexible across the portfolio.**
3. **PDF generator choice** — Dompdf is the PHP-native default, works without external services. Alternative: mPDF, TCPDF. **Recommendation: Dompdf v1; revisit if the branded template demands anything Dompdf can't render cleanly.**
4. **Caregiver availability UX — how does Tuniti enter on a caregiver's behalf?** Options: (a) Tuniti impersonates the caregiver via the existing impersonation system; (b) separate "enter for" workflow on the caregiver detail page. **Recommendation: (b) — the availability form is a sub-page on the caregiver detail; both Tuniti and the caregiver themselves reach the same form. Impersonation is overkill here.**
5. **Portal subdomain or path?** `portal.tch.intelligentae.co.uk` vs. `tch.intelligentae.co.uk/portal`. **Recommendation: path for now, subdomain later if white-labelling ever arrives.**
6. **PDF storage — regenerate on demand or persist?** If persist, each quote gets a stored PDF per version (quote + every revision). Disk cost is tiny, audit value is large. **Recommendation: persist; store under `storage/quotes/YYYY-MM/` per the existing out-of-webroot pattern.**

---

## 4. FR writeups

Each entry below is a ready-to-file Hub FR.

---

### FR-A — Product billing rates (multi-unit pricing)

**Business description**

- **Outcome:** Each product (Day Care, Errand Care, etc.) carries a small list of billing units it can be sold in — hourly, daily, weekly, monthly, per visit, upfront — with a separate standard rate for each allowed unit and one marked as the default.
- **What it does:** On the Product setup page, admin sees a sub-table per product titled "Supported billing rates". They add / remove rows (each row = billing unit + rate), and tick one as the default. When the product is later picked for a quote line, only the units in its list are selectable, and its default rate prefills.
- **Why it matters:** Today a product has one billing frequency and one rate. Real TCH pricing already varies — "a day of Day Care" is R800, "an hour of Day Care" is R150, "a week of Day Care" is R4,500. Forcing one frequency per product either misrepresents pricing or spawns duplicate products ("Day Care Hourly", "Day Care Daily") that drift out of sync.
- **If we don't do it:** Tuniti can't quote the same service at different grains, or maintains multiple confusingly-similar products. The quoting build can't proceed without this.

**Technical description**

- **Architecture context:** New child table `product_billing_rates`: `(id, product_id FK, billing_freq ENUM, rate DECIMAL(10,2), currency_code CHAR(3) DEFAULT 'ZAR', is_default TINYINT(1), is_active TINYINT(1))`. One row per (product, billing_freq). A unique constraint on `(product_id, billing_freq)` prevents duplicates. Existing `products.default_billing_freq` + `products.default_price` become seed data for the migration (migrate each product's current row into the new table as its default) and are then dropped in a follow-up migration once all call sites read from the new table.
- **Proposed approach:**
  1. New migration: creates `product_billing_rates`, backfills from existing `products.default_billing_freq` + `products.default_price` (one row per product, `is_default = 1`).
  2. Update `/admin/onboarding/products` — product row expands to show a mini editable table for supported rates with add/remove buttons; default selector is a radio; validation blocks save if no row marked default.
  3. Update contract-line creation and quote-line creation to pull allowed units from `product_billing_rates` rather than the dropped `products.default_billing_freq`.
  4. Follow-up migration drops the now-unused columns from `products`.
- **Dependencies / risks:**
  - Touches migration path; requires a data-migration script that preserves every product's current default. Ross review before PROD per the destructive-migration rule.
  - `contract_lines` today stores `billing_freq` + `bill_rate` directly — fine; nothing to change there.
  - `currency_code` column is forward-looking; no UI around it in v1.
- **Acceptance criteria:**
  - Existing products visible on `/admin/onboarding/products` still carry their pre-migration rate (now marked as default).
  - Admin can add a second billing unit + rate to a product, pick any one as default, save.
  - Quote line for that product only offers the billing units that exist as active rows for it.
  - No regression on existing contract detail screens (they read from `contract_lines.billing_freq` / `.bill_rate` directly, not from `products`).

---

### FR-B — Per-line dates on `contract_lines`

**Business description**

- **Outcome:** Each line in a contract has its own start and end date. Lines can be "ongoing" (no end).
- **What it does:** `contract_lines` gains `start_date` + `end_date` (nullable). The contract's overall start is computed as `MIN(line start_date)` and end as `MAX(line end_date)` (null if any line is ongoing). Reports that need the contract's effective dates read through to the lines.
- **Why it matters:** A quote frequently bundles multiple products with different runs — "Day Care from 1 May to 31 July, plus Errand Care from 1 June ongoing". Today the contract holds one start + one end, so either the lines get flattened to the shared window or a separate contract is needed per run, fragmenting the commercial record.
- **If we don't do it:** The quote builder (FR-C) can't represent real customer needs without either forcing identical dates across lines or creating multiple contracts per customer. Both are worse than the per-line model.

**Technical description**

- **Architecture context:** Touches `contract_lines` only. No change to `contracts` (its `start_date` + `end_date` become derived / display-level rather than authoritative).
- **Proposed approach:**
  1. Migration: `ALTER TABLE contract_lines ADD COLUMN start_date DATE NULL, ADD COLUMN end_date DATE NULL`. Backfill existing rows from the parent contract's dates.
  2. Contract detail screen: show per-line dates. Editable if contract not yet accepted.
  3. Contract list / reports: switch to computing contract effective-dates from lines (MIN start, MAX end with NULL-wins-for-ongoing) rather than reading `contracts.start_date` / `.end_date` directly.
  4. Follow-up: mark the `contracts.start_date` / `.end_date` columns as display-cache-only in a DECISIONS.md entry, OR remove them entirely after verifying no read sites remain. (Single-source-of-truth rule.)
- **Dependencies / risks:**
  - Scheduling (Phase 3) will need per-line dates to know when to generate roster rows for each line. Making this change now is a prerequisite, not extra scope.
  - Reports that currently read `contracts.start_date` / `.end_date` need auditing. Expected small — maybe 3-4 places.
- **Acceptance criteria:**
  - A contract with 2 lines at different runs shows both runs on its detail page.
  - Changing one line's end date doesn't touch the other line.
  - A line with `end_date = NULL` is treated as ongoing in every report.
  - The contract's effective end-date on list views reflects the latest line end (or blank for any ongoing line).

---

### FR-C — Quote builder screen

**Business description**

- **Outcome:** A dedicated page where Tuniti (or any admin-role quoter) builds a quote by picking a client + patient, adding product lines with unit + quantity + dates, and seeing a live running total.
- **What it does:**
  - Pick client and patient from searchable pickers (reuses existing person pickers from contract creation).
  - Add product lines one at a time. For each line: product dropdown, billing-unit dropdown (narrowed to the product's supported units per FR-A), quantity input, start + end date (end optional = ongoing), rate (prefilled from product's default rate for the chosen unit, overridable by role per FR-E).
  - Line total = quantity × rate, computed live client-side for immediate feedback.
  - Quote total = sum of line totals, computed server-side on render (never stored — standing rule on single source of truth).
  - Quote can be saved as draft and returned to before sending.
- **Why it matters:** This is the central workflow change. Quoting today is email + spreadsheet; the output is uncaptured. A quote built in the system is structured data that flows directly into contract + invoicing.
- **If we don't do it:** No structured quoting flow. All downstream work (client acceptance, contract auto-generation, scheduling) rests on this surface existing.

**Technical description**

- **Architecture context:** New screens at `/admin/quotes` (list) and `/admin/quotes/new` / `/admin/quotes/{id}` (edit). A quote lives in the existing `contracts` + `contract_lines` tables with `contracts.status = 'draft'` — per the decision that quote and draft-contract share a table. No new table required unless `contracts` is missing fields the quote surface needs (e.g. `quote_reference`, `sent_at`, `accepted_at`). Audit every mutation per the standing rule.
- **Proposed approach:**
  1. Migration: add whichever columns `contracts` lacks — at minimum `quote_reference VARCHAR(30) UNIQUE NULL`, `sent_at TIMESTAMP NULL`, `accepted_at TIMESTAMP NULL`. Others identified during implementation.
  2. New `/admin/quotes` list template showing contracts with `status IN ('draft', 'sent')` (quotes = not-yet-active). Sort + filter per existing table patterns.
  3. New `/admin/quotes/new` form: client + patient pickers up top; line-item editor below (HTML table with add/remove row buttons); running total at the bottom. Save draft button.
  4. Line-item editor uses FR-A's allowed-units dropdown and FR-B's per-line dates.
  5. Every save logs to `activity_log` with before/after per standing rule.
- **Dependencies / risks:**
  - Depends on FR-A (allowed-units per product) and FR-B (per-line dates).
  - Rate-override (FR-E) is a gated UI element on the line rate input. Ships together.
  - Client + patient pickers are reusable from contract-create — no new work.
  - If a quote references a patient that doesn't exist in the system yet, the create-patient flow has to be reachable inline (or we force patient-first creation before quoting). **Recommendation: inline patient creation from the quote form.**
- **Acceptance criteria:**
  - New quote created with 3 lines, each with different billing units and dates, saves successfully and renders correctly.
  - Running total updates as quantities change (client-side JS).
  - Saved quote can be reopened for edit.
  - Activity log shows the create + every subsequent edit with before/after snapshots.

---

### FR-D — Quote state machine + admin-triggered acceptance

**Business description**

- **Outcome:** A quote moves through `draft` → `sent` → `accepted` → `active` states. Admin can trigger each transition with appropriate audit trail. Acceptance spawns a contract (same record, status flipped + `accepted_at` stamped).
- **What it does:**
  - `draft`: editable, not yet gone to client. Lives on `/admin/quotes`.
  - `sent`: frozen (edits create a revision / new version). Client has been told about it. Lives on `/admin/quotes` with a "sent" filter.
  - `accepted`: client has said yes. Admin clicks "Mark accepted", picks acceptance method (email reply / phone / in person / signed PDF attached), adds optional note. Quote flips to `active` contract automatically.
  - `active`: contract is running, drives scheduling + billing. Lives on `/admin/contracts`.
  - `rejected` / `expired` / `cancelled`: optional terminal states for completeness.
- **Why it matters:** Without states, every quote is a single mutable blob. We lose the ability to freeze terms when they go out, to know what was accepted, or to bring a stale quote back into a new round of negotiation without corrupting the history.
- **If we don't do it:** Every accepted contract has no traceable record of what was quoted, when, or by whom. Disputes ("but the quote said...") have no answer.

**Technical description**

- **Architecture context:** `contracts.status` exists but currently enum is small. Expand to include the state-machine values above. `accepted_by_user_id`, `accepted_at`, `acceptance_method` (ENUM), `acceptance_note` (TEXT) added.
- **Proposed approach:**
  1. Migration: widen `contracts.status` enum; add the four acceptance columns.
  2. State-transition handler — one endpoint that takes (quote_id, target_status, method, note) and validates the transition against allowed moves (draft → sent, sent → accepted, etc.). Illegal moves error.
  3. Each transition writes an `activity_log` entry with full before/after.
  4. Acceptance specifically triggers: `status → active`, `accepted_at = NOW()`, `accepted_by_user_id = current user` (representing who marked it), `acceptance_method` + `note` saved.
  5. "Mark accepted" button on `/admin/quotes/{id}` opens a small modal for method + note.
- **Dependencies / risks:**
  - Depends on FR-C being in place (quote exists to transition).
  - The "active" transition is the moment scheduling would start (once FR-K + scheduling are built). For now, it's just a status flip.
  - Revision handling if a sent quote needs editing: either bump to a new quote (recommended — quote-revision chain mirrors the contract supersede chain) or allow edits that invalidate the sent version. **Recommendation: if a sent quote needs changes, the admin flips it back to draft (which records a revision note) or creates a new quote linked to the old one.**
- **Acceptance criteria:**
  - Admin can move a draft quote through sent → accepted → active with audit-logged transitions.
  - Illegal transitions (e.g. draft → accepted without sent) are refused.
  - Acceptance transition captures method + note and stamps `accepted_at` + `accepted_by_user_id`.
  - Activity log shows the full lineage per quote.

---

### FR-E — Quoter rate-override permission + audit

**Business description**

- **Outcome:** Users with a new permission can type a non-standard rate into a quote line (overriding the product's default rate for that unit). Users without the permission see the rate as display-only. Overrides are logged with reason.
- **What it does:**
  - New permission on the existing permission model — something like `quotes.override_rate`.
  - On the quote builder's rate input: if user has the permission, input is editable; if not, read-only text.
  - When a rate is overridden, a small "Why?" text input appears and is required; the reason saves alongside the quote line and shows on the contract detail.
- **Why it matters:** Real quoting always involves discounts — returning client, bulk booking, relationship deal. Hard-coding product rates without an override path means quoting happens outside the system; capturing overrides keeps the record honest.
- **If we don't do it:** Quoters either can't discount (blocking real deals) or they bypass the system entirely.

**Technical description**

- **Architecture context:** Uses the existing `role_permissions` / `pages` schema. New page code `quotes` or extend the existing contracts code with a new per-action permission `override_rate`.
- **Proposed approach:**
  1. Migration: add `quotes` page to `pages` table; grant `override_rate` to Super Admin + Admin by default. Other roles opt-in.
  2. Contract-line schema already has `bill_rate`; add a nullable `rate_override_reason VARCHAR(255)` column.
  3. UI: rate input conditionally editable based on `userCan('quotes', 'override_rate')`; reason input shown when rate differs from the product's standard.
  4. Audit log captures (product_id, standard_rate, override_rate, reason) in the `after_json`.
- **Dependencies / risks:**
  - Small. Ship with or just after FR-C.
- **Acceptance criteria:**
  - User without the permission sees read-only rate; can't submit an overridden value.
  - User with the permission sees editable rate; reason field is required when rate ≠ standard.
  - Saved override + reason show on the quote detail and downstream contract detail.

---

### FR-F — Quote PDF boilerplate + Tuniti template onboarding task

**Business description**

- **Outcome:** Every quote can be rendered as a PDF — boilerplate v1 (logo, client, patient, line items, totals, accept instructions), upgradeable to a Tuniti-provided branded template.
- **What it does:**
  - A "Generate PDF" button on the quote detail page produces a PDF file, stored under `storage/quotes/YYYY-MM/` outside the webroot and linked from the quote record.
  - PDF content is driven by the quote data (products, units, quantities, dates, rates, totals, client info).
  - The boilerplate layout is a blank, functional template. A new task on the Tuniti onboarding dashboard — "Provide branded quote PDF template" — prompts Tuniti to supply a designed template. Once supplied, the PDF generator swaps it in.
- **Why it matters:** Clients want something they can see, save, forward, or print. A PDF artefact is how quotes are sent in this industry. Without it, quote delivery is either plain-text email (unprofessional) or nothing.
- **If we don't do it:** The quoting system is internal-only — no client-facing output.

**Technical description**

- **Architecture context:** Add Dompdf as a vendored dependency (or lightweight PSR-loaded equivalent) in `vendor/` or `includes/vendor/`. PDF generation in a new `includes/quote_pdf.php` helper. Storage pattern mirrors the existing `storage/onboarding/` layout — outside webroot, signed download URLs gated by auth.
- **Proposed approach:**
  1. Bring in Dompdf via Composer or drop-in include (no Composer runtime deps is the existing convention; drop-in path is likely).
  2. New helper `quote_pdf.php` with `renderQuotePdf(quote_id): string` that returns the stored path.
  3. Template file `templates/quotes/boilerplate.php` — a plain HTML+inline-CSS layout Dompdf can render.
  4. Abstraction layer: the PDF helper reads template path from a config key (`QUOTE_PDF_TEMPLATE = 'boilerplate.php'`). Swapping to Tuniti's template is one config change + a new template file.
  5. New Tuniti-onboarding task `quote_pdf_template` — `permission_page => 'onboarding'`, pending until a template file is dropped into `templates/quotes/branded.php` and the config flipped.
- **Dependencies / risks:**
  - Dompdf dep size is ~2 MB; not a problem on current hosting.
  - PDF rendering is CPU-y but quote PDFs are small — no throughput concern.
- **Acceptance criteria:**
  - "Generate PDF" on a saved quote produces a PDF file that renders the quote's data correctly.
  - PDF downloads with the correct filename (`TCH-Quote-{ref}-{date}.pdf`) and auth-gated URL.
  - Swapping `QUOTE_PDF_TEMPLATE` from boilerplate to a Tuniti-provided branded template works without code changes.
  - Tuniti onboarding dashboard shows the template-provision task as pending.

---

### FR-G — Quote email delivery + client preference field

**Business description**

- **Outcome:** A quote can be emailed directly to the client from the quote detail page, with the PDF attached. The client's profile carries a "preferred quote delivery" field (email / PDF download / portal); the quote sender defaults to that preference.
- **What it does:**
  - On the quote detail: a "Send quote" button opens a modal — recipient emails (prefilled from client + patient records), delivery method radio (email / PDF / portal, defaulting to client's preference), optional message.
  - On send: if email — sends via existing `mailer.php` with the PDF attached. If PDF — renders PDF, flags the quote as "sent" without emailing. If portal — handled in FR-I.
  - The client's person record gains a `preferred_quote_delivery` field.
- **Why it matters:** Clients have preferences; imposing a delivery channel adds friction. Email is the 80% path.
- **If we don't do it:** Delivery is manual — the admin downloads the PDF and emails it outside the system, losing the delivery-recorded audit trail.

**Technical description**

- **Architecture context:** `mailer.php` already exists (SMTP via server config). `clients.preferred_quote_delivery` ENUM new column.
- **Proposed approach:**
  1. Migration: add `clients.preferred_quote_delivery ENUM('email','pdf','portal') DEFAULT 'email'`.
  2. Extend `mailer.php` with `sendQuoteEmail(quote_id, recipients, message)` helper — attaches the PDF path from FR-F.
  3. "Send quote" modal on quote detail: prefills recipient from client email, picks delivery method from client preference, sends via helper, transitions quote `draft → sent` per FR-D.
  4. Email template in `templates/emails/quote_sent.php` — subject, body with quote ref + accept instructions, PDF attachment.
- **Dependencies / risks:**
  - Depends on FR-D (state transition) and FR-F (PDF exists to attach).
  - Client email must exist on the person record before send. UI blocks send with helpful error if not.
- **Acceptance criteria:**
  - Send with preference = email → recipient receives the quote email with PDF attached; quote flips to `sent` state.
  - Preference = pdf → PDF renders + downloads; quote flips to `sent` state; no email.
  - Preference = portal → deferred to FR-I.
  - Audit log captures the send event with recipient + method.

---

### FR-H — Client / Patient user classes + invite-on-accept + portal login

**Business description**

- **Outcome:** Clients and patients can have user accounts in the system — but only if they choose to. Accounts are created on quote acceptance via a tokenised link (invite-on-accept), not at quote-draft time.
- **What it does:**
  - Quote sent via portal (or any channel that includes the portal-accept link) contains a one-time tokenised URL.
  - Clicking the link lands on the public acceptance page. No login needed to review and accept.
  - If the client wants to set up an account for future quotes or to view their active contract, they set a password at that point. If not, they just accept and go — no account created.
  - New roles `Client` and `Patient` (separate because they see different things) with minimal permissions: view own quotes, view own active contracts, accept pending quotes.
- **Why it matters:** Most clients won't want an account and Ross doesn't expect many to use the portal. But the ones who do need a clean, low-friction flow. Forcing account setup at quote time would kill the "easy to say yes" shape.
- **If we don't do it:** No portal option. Delivery is email + PDF only.

**Technical description**

- **Architecture context:** Extends the existing `users` + `roles` + `role_permissions` schema. Adds a `users.person_id` link that already exists. The tokenised accept flow is a new public route.
- **Proposed approach:**
  1. Migration: seed `Client` + `Patient` roles with appropriate `role_permissions` (read on `quotes`, `contracts` — self-scoped via `users.person_id`).
  2. New table `quote_access_tokens`: `(id, quote_id FK, token CHAR(64) UNIQUE, expires_at DATETIME, used_at DATETIME NULL)`. Token generated on quote send, 30-day expiry, one-time-use on acceptance.
  3. Public route `/quotes/accept/{token}` — no auth required; renders the quote summary + accept/decline buttons + optional "create account" section at the bottom.
  4. Accept action: flip quote state via FR-D's handler, invalidate token, optionally create `users` row tied to the person record (if client supplied a password).
  5. For logged-in clients: standard login at `/login`; post-login redirect to `/portal/dashboard` showing their own quotes + contracts.
  6. Self-scoping on every query: `WHERE client_id = current_user.person_id` or equivalent. Hard-enforced at the handler level, not the UI.
- **Dependencies / risks:**
  - Security surface. Tokens must be unguessable, rate-limited on accept endpoint, one-time-use. Password setup flow reuses existing `templates/auth/setup-password.php`.
  - Self-scoping bugs are the main risk — a client seeing another client's quote is a worst-case outcome. Every query that touches quote/contract data from a client-role session MUST include a self-scope filter; add a unit-test-style check if practical.
  - Invite-on-accept means the token flow is the ONLY way to create a new client account. No admin-creates-client-account flow. That's deliberate but worth flagging.
- **Acceptance criteria:**
  - Tokenised accept link allows review + accept without login.
  - Accept without password creates no account — the client is known only via the quote record.
  - Accept with password creates a `Client`-role user account; the client can log in at `/login` and see only their own data.
  - A `Client`-role user cannot see any other client's quote or contract via URL manipulation (self-scope verified).

---

### FR-I — Portal acceptance flow (end-to-end tokenised delivery option)

**Business description**

- **Outcome:** When the quoter picks "Portal" as delivery, the client receives a tokenised accept link and nothing else — no attached PDF, no manual follow-up. The portal URL is the single call-to-action.
- **What it does:**
  - Quote sent via portal-only method: an email goes out with a short body + one big "Review and accept your quote" button linking to the public accept URL.
  - Client clicks → public accept page renders the quote summary + accept/decline.
  - Accept → FR-H invite-on-accept flow.
- **Why it matters:** It's the cleanest client experience for the subset of clients who want self-service. No PDF to download, no attachment to deal with.
- **If we don't do it:** Portal delivery option exists in name (FR-G radio) but doesn't work. Only email + PDF are viable.

**Technical description**

- **Architecture context:** Ties FR-G's send-quote modal to FR-H's token flow when the delivery method is `portal`.
- **Proposed approach:**
  1. In `sendQuoteEmail()`: if `method === 'portal'`, skip the PDF attachment, generate a token per FR-H, render the portal-email template with the tokenised URL baked in.
  2. Public route `/quotes/accept/{token}` is already built in FR-H — reuse.
  3. Email template `templates/emails/quote_portal.php` — short, one button, no attachment.
- **Dependencies / risks:**
  - Depends on FR-G (send infrastructure) and FR-H (token flow + accept page).
  - Email deliverability — the button-heavy short email is more likely to hit spam than a long PDF-attached one. Flag for monitoring.
- **Acceptance criteria:**
  - Send with method = portal generates a token, sends a button-linked email, no PDF attached.
  - Clicking the email link lands on the accept page with the right quote summary.
  - Full token → accept → contract → account (optional) flow works end-to-end without the client logging in at any prior point.

---

### FR-J — Mid-contract term change workflow

**Business description**

- **Outcome:** When contract terms change part-way through (rate bump, new product added, extended scope), the system creates a successor contract linked to the original via the existing supersede chain — old contract's effective-end becomes the change date, new contract's effective-start is the day after.
- **What it does:**
  - Admin opens an active contract, clicks "Change terms", picks change date.
  - System duplicates the contract's lines into a new `draft` contract, sets `superseded_by` on the old one pointing to the new, freezes the old at its change-date end.
  - Admin edits the new draft (change rates, add/remove lines, extend dates), sends to client via standard quote flow (FR-D through FR-I). Accepted → new contract active, old one superseded, lineage visible.
- **Why it matters:** Care contracts evolve. Rates go up annually, products get added (night shift added to day care), clients ask for reduction. Today this either requires manual contract rewrite or goes undocumented. Either way, the audit trail and billing path break.
- **If we don't do it:** Mid-contract changes happen informally in Tuniti's records; the system drifts out of alignment with reality; disputes become unresolvable.

**Technical description**

- **Architecture context:** `contracts.superseded_by` already exists (per DECISIONS.md 2026-04-14 — "Contracts auto-renew until actively cancelled" entry). Mechanism is in place; needs UI + workflow.
- **Proposed approach:**
  1. "Change terms" button on active contract detail opens a modal: change-date picker, preview of what will happen.
  2. Handler duplicates the contract + lines into a new record with `status = 'draft'`, `superseded = old_contract_id`. Old contract gets `effective_end_date = change_date - 1day`; `superseded_by = new_contract_id`.
  3. New draft surfaces in `/admin/quotes` as a normal draft quote — goes through the same state machine (FR-D) and delivery (FR-G/I). Acceptance flips the old contract to `superseded` and activates the new one.
  4. Contract detail renders the supersede chain — a small "Revision history" section showing prior versions, effective windows, why (from acceptance notes).
- **Dependencies / risks:**
  - Depends on the full quote + acceptance chain (FR-C through FR-I) being in place.
  - Billing continuity across the supersede boundary is a real concern: the last day of the old contract and the first day of the new must not double-bill or gap. Worth a test case.
  - Roster rows generated under the old contract stay linked to it; roster rows from the change date forward link to the new contract. Scheduling build (Phase 3) will need to understand this boundary.
- **Acceptance criteria:**
  - A change-terms action produces a new draft linked to the old.
  - Sending + accepting the new draft leaves the old contract `superseded` at the correct effective-end date and the new one `active` starting the correct day.
  - Billing for the change-month correctly bills the old rate up to and including the change date, new rate from the day after.
  - Contract detail shows the full supersede chain.

---

### FR-K — Caregiver availability profile + diary (parallel track)

**Business description**

- **Outcome:** Every caregiver has an availability profile — a recurring pattern (days of the week + hours of the day) plus one-off exceptions (holidays, unavailability windows). The profile is filled in by the caregiver themselves, or by Tuniti on their behalf, from any device including a low-end mobile browser.
- **What it does:**
  - Caregiver detail page gains an "Availability" tab.
  - Recurring pattern section: a 7-day × 24-hour grid (or simpler day-of-week + start/end time rows) where the caregiver marks when they're typically available. Supports different hours per day (e.g. Mon–Fri 18:00–22:00, Sun 09:00–17:00).
  - Exceptions section: list of date-range + reason rows ("2026-06-01 to 2026-06-14 — family holiday", "2026-07-20 — medical appointment").
  - Same form works for both self-entry and Tuniti-on-behalf-of — Tuniti opens the caregiver detail page, fills in for them, saves. Audit log captures who saved it.
  - Mobile-friendly: the existing `/admin` CSS already responds; the form uses standard inputs (no drag-to-select grid that requires JS on a low-end browser).
- **Why it matters:** Matching a patient's care requirement to a caregiver's availability is the core scheduling problem. Without structured availability data, scheduling either happens in Tuniti's head or degrades to "pick whoever picks up the phone". Getting real availability data captured, per caregiver, is a prerequisite for any automated or even semi-automated scheduling.
- **If we don't do it:** Phase 3 scheduling can't be built — there's nothing to match against.

**Technical description**

- **Architecture context:** Existing `caregivers.working_pattern` is a compact string for "which days + shift preference + live-in". Too coarse for scheduling — doesn't carry hours. New tables needed.
- **Proposed approach:**
  1. Migration: new table `caregiver_availability_recurring` — one row per (caregiver, day_of_week, time range): `(id, caregiver_id FK, day_of_week TINYINT 0-6, start_time TIME, end_time TIME, is_active TINYINT(1))`. Multiple rows per day allowed (morning slot + evening slot).
  2. Migration: new table `caregiver_availability_exceptions` — `(id, caregiver_id FK, start_date DATE, end_date DATE, is_available TINYINT(1) DEFAULT 0, reason VARCHAR(255))`. Supports both "unavailable during this window" (default) and "additionally available" in case of an ad-hoc open slot.
  3. New page template `templates/admin/caregiver_availability.php` — tab on the caregiver detail page. Simple HTML form, no heavy JS. Post-handler saves rows in a transaction, logs to `activity_log` with before/after.
  4. `caregivers.working_pattern` stays for now as a backwards-compat summary; flagged for retirement once the new model is in use.
  5. Future scheduling queries join through these tables to find "caregivers available on date X at time Y not having an exception".
- **Dependencies / risks:**
  - Independent track. Doesn't block quoting; doesn't depend on any quoting FR.
  - Data capture burden on Tuniti — 139 caregivers to fill in. Consider a bulk-entry option as a v2.
  - Low-end mobile usability needs real testing — no JS-heavy UIs, must work on old Android browsers.
- **Acceptance criteria:**
  - Caregiver (or Tuniti on their behalf) can set a recurring pattern like "Mon/Wed/Fri 18:00–22:00 + alternate Sundays 09:00–17:00" and save.
  - Can add an exception "2026-06-01 to 2026-06-14 unavailable" and it displays on the profile.
  - Form works on a low-end mobile browser (test target: one-handed on a 4-inch screen, 3G connection).
  - Activity log captures every save with who did it + before/after.

---

## 5. Explicitly out of scope (for now)

These came up in the discussion and are deliberately deferred.

- **Half-day billing unit** — dropped per Ross's decision.
- **Fully branded quote PDF template design** — Tuniti supplies. FR-F covers the infrastructure to slot it in.
- **SMS / WhatsApp quote delivery** — not on the roadmap. Email + PDF + portal cover it.
- **Multi-currency quote UI** — data model prepared via the `currency_code` column in FR-A, but no UI in v1.
- **Electronic signature on accept (verified identity, audit-trail-bound)** — the v1 accept flow is click-button-with-logged-IP. DocuSign-level signing is a separate FR if ever needed.
- **Automated scheduling itself** — Phase 3, depends on FR-K being in place first.
- **Caregiver bulk availability entry / Tuniti-sends-spreadsheet workflow** — FR-K covers one-at-a-time entry. Bulk is a v2.

---

## 6. Follow-up: Hub filing

Once Ross has reviewed this plan, these 11 FRs (FR-A through FR-K) should land in Nexus Hub under the `tch` project. I'll file them there once the Hub API token is wired per the SessionStart briefing, or Ross can file them manually from this doc as time permits. Each writeup above is already in the three-layer format Hub expects.

---

*This plan is a living document. If design questions in §3 get answered, feed the answers back into the affected FR writeups rather than leaving this plan and the Hub out of sync.*
