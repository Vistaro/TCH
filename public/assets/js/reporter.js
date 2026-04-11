/**
 * TCH Placements — In-App Bug / Feature-Request Reporter Widget
 * ─────────────────────────────────────────────────────────────
 *
 * Renders a floating "Help" button bottom-right of every admin page.
 * Clicking it opens a slide-in panel for reporting bugs or feature
 * requests. Submissions POST to a secure PHP proxy that forwards to
 * the Nexus Hub API using a server-side token (never exposed here).
 *
 * Features:
 *  - Page context (url, title, slug) captured automatically from the
 *    data attributes on <body>
 *  - Duplicate detection: warns if an open report already exists for
 *    the current page, with Yes (view existing) / No (submit anyway)
 *  - Confirmation email sent server-side on success
 *  - Graceful failure: network errors surface as a red inline box
 *    without breaking the host page
 *
 * No Quick Links menu in TCH v1 — a single Help button opens the
 * reporter panel directly.
 *
 * Expects two globals to be injected by the server-side template:
 *   TCH_BASE_URL — site root URL (e.g. https://dev.tch.intelligentae.co.uk)
 *   TCH_CSRF     — CSRF token string for the current session
 */
(function () {
    'use strict';

    // ── Helpers ───────────────────────────────────────────────────────────
    function qs(sel, ctx) { return (ctx || document).querySelector(sel); }

    function escapeHtml(str) {
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    // ── Build widget HTML ─────────────────────────────────────────────────
    function buildWidget() {
        const pageSlug  = document.body.dataset.pageSlug  || '';
        const pageTitle = document.body.dataset.pageTitle || document.title || '';
        const pageUrl   = window.location.href;

        const html = `
<!-- Floating trigger button -->
<button class="tch-reporter-btn" id="tch-reporter-trigger" aria-label="Report a bug or feature request" aria-haspopup="dialog">
  <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
    <circle cx="12" cy="12" r="10"/>
    <path d="M9.09 9a3 3 0 015.83 1c0 2-3 3-3 3m.08 4h.01"/>
  </svg>
  Help
</button>

<!-- Backdrop -->
<div class="tch-reporter-backdrop" id="tch-reporter-backdrop"></div>

<!-- Slide-in panel -->
<div class="tch-reporter-panel" id="tch-reporter-panel" role="dialog" aria-modal="true" aria-label="Report a bug or feature request">

  <div class="tch-reporter-header">
    <h3>Report a Bug or Feature Request</h3>
    <button class="tch-reporter-close" id="tch-reporter-close" aria-label="Close">&times;</button>
  </div>

  <div class="tch-reporter-body" id="tch-reporter-form">

    <div>
      <label>What are you reporting?</label>
      <div class="tch-reporter-type-toggle">
        <button class="active" data-type="bug" id="tch-type-bug" type="button">🐛 Bug</button>
        <button data-type="feature" id="tch-type-feature" type="button">💡 Feature Request</button>
      </div>
    </div>

    <div>
      <label>Impact</label>
      <div class="tch-reporter-severity-toggle">
        <button data-severity="fatal" id="tch-sev-fatal" type="button">🔴 Fatal — can't progress</button>
        <button class="active" data-severity="improvement" id="tch-sev-improvement" type="button">🔵 Improvement</button>
      </div>
    </div>

    <div class="tch-reporter-page-context">
      <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
        <rect x="3" y="3" width="18" height="18" rx="2"/><path d="M3 9h18M9 21V9"/>
      </svg>
      Page: <span id="tch-reporter-page-label">${escapeHtml(pageTitle || pageSlug || 'this page')}</span>
    </div>

    <div>
      <label for="tch-reporter-short-desc">
        Short description
        <span class="tch-reporter-hint">(optional — this becomes the Hub title)</span>
      </label>
      <input type="text" id="tch-reporter-short-desc" maxlength="140" placeholder="One-line summary (e.g. 'Caregiver Earnings should show totals by carer')">
    </div>

    <div>
      <label for="tch-reporter-desc">
        What happened?
        <span class="tch-reporter-hint">(optional — more detail helps)</span>
      </label>
      <textarea id="tch-reporter-desc" placeholder="Describe what you saw or what you'd like…" maxlength="2000" rows="4"></textarea>
    </div>

    <div class="tch-reporter-duplicate" id="tch-reporter-dup">
      <strong>⚠ Possible duplicate</strong><br>
      <span id="tch-reporter-dup-msg"></span>
      <div class="tch-reporter-dup-actions">
        <button class="tch-btn-yes" id="tch-dup-yes" type="button">Yes — view existing</button>
        <button class="tch-btn-no"  id="tch-dup-no"  type="button">No — submit as new</button>
      </div>
    </div>

    <div class="tch-reporter-error" id="tch-reporter-error"></div>

    <button class="tch-reporter-submit" id="tch-reporter-submit" type="button">Submit Report</button>

  </div>

  <!-- Success state -->
  <div class="tch-reporter-success" id="tch-reporter-success">
    <div class="tch-reporter-success-icon">
      <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
        <polyline points="20 6 9 17 4 12"/>
      </svg>
    </div>
    <h4>Report submitted</h4>
    <p>Logged as <span class="tch-ref-badge" id="tch-success-ref"></span></p>
    <p id="tch-success-email-note"></p>
    <a href="#" id="tch-success-link" target="_blank" rel="noopener">View in Nexus Hub →</a>
    <button class="tch-reporter-submit" id="tch-reporter-done" type="button" style="margin-top:8px;">Done</button>
  </div>

</div><!-- /.tch-reporter-panel -->`;

        const wrapper = document.createElement('div');
        wrapper.innerHTML = html.trim();
        // Append every top-level child so we don't nest the whole widget in a div
        while (wrapper.firstChild) {
            document.body.appendChild(wrapper.firstChild);
        }

        initWidget(pageSlug, pageTitle, pageUrl);
    }

    // ── Widget logic ──────────────────────────────────────────────────────
    function initWidget(pageSlug, pageTitle, pageUrl) {
        const trigger   = qs('#tch-reporter-trigger');
        const backdrop  = qs('#tch-reporter-backdrop');
        const panel     = qs('#tch-reporter-panel');
        const closeBtn  = qs('#tch-reporter-close');
        const form      = qs('#tch-reporter-form');
        const success   = qs('#tch-reporter-success');
        const submitBtn = qs('#tch-reporter-submit');
        const doneBtn   = qs('#tch-reporter-done');
        const dupBox    = qs('#tch-reporter-dup');
        const dupMsg    = qs('#tch-reporter-dup-msg');
        const dupYes    = qs('#tch-dup-yes');
        const dupNo     = qs('#tch-dup-no');
        const errorBox  = qs('#tch-reporter-error');
        const shortIn   = qs('#tch-reporter-short-desc');
        const descTA    = qs('#tch-reporter-desc');

        let selectedType     = 'bug';
        let selectedSeverity = 'improvement';
        let pendingDupUrl    = null;
        let forceSubmit      = false;

        // ── Trigger opens panel directly ──────────────────────────────────
        trigger.addEventListener('click', function () {
            resetForm();
            openPanel();
        });

        // ── Panel open / close ────────────────────────────────────────────
        function openPanel() {
            panel.classList.add('open');
            backdrop.classList.add('open');
            // Focus the short-description input first — it's the most
            // important field; user types a one-line title, tabs to the
            // textarea for more detail if they want.
            setTimeout(function () { shortIn.focus(); }, 50);
        }
        function closePanel() {
            panel.classList.remove('open');
            backdrop.classList.remove('open');
        }
        function resetForm() {
            shortIn.value = '';
            descTA.value = '';
            errorBox.classList.remove('visible');
            dupBox.classList.remove('visible');
            success.classList.remove('visible');
            form.style.display = '';
            forceSubmit = false;
            setSubmitIdle();
        }

        closeBtn.addEventListener('click', closePanel);
        backdrop.addEventListener('click', closePanel);
        doneBtn.addEventListener('click', function () { resetForm(); closePanel(); });

        // Escape closes the panel
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && panel.classList.contains('open')) {
                closePanel();
            }
        });

        // ── Type toggle ───────────────────────────────────────────────────
        qs('#tch-type-bug').addEventListener('click', function () { setType('bug'); });
        qs('#tch-type-feature').addEventListener('click', function () { setType('feature'); });
        function setType(t) {
            selectedType = t;
            qs('#tch-type-bug').classList.toggle('active', t === 'bug');
            qs('#tch-type-feature').classList.toggle('active', t === 'feature');
        }

        // ── Severity toggle ───────────────────────────────────────────────
        qs('#tch-sev-fatal').addEventListener('click', function () { setSeverity('fatal'); });
        qs('#tch-sev-improvement').addEventListener('click', function () { setSeverity('improvement'); });
        function setSeverity(s) {
            selectedSeverity = s;
            qs('#tch-sev-fatal').classList.toggle('active', s === 'fatal');
            qs('#tch-sev-improvement').classList.toggle('active', s === 'improvement');
        }

        // ── Duplicate actions ─────────────────────────────────────────────
        dupYes.addEventListener('click', function () {
            if (pendingDupUrl) window.open(pendingDupUrl, '_blank', 'noopener');
            closePanel();
        });
        dupNo.addEventListener('click', function () {
            forceSubmit = true;
            dupBox.classList.remove('visible');
            doSubmit();
        });

        // ── Submit ────────────────────────────────────────────────────────
        submitBtn.addEventListener('click', function () { doSubmit(); });

        function setSubmitLoading() {
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<span class="tch-reporter-spinner"></span>Submitting…';
        }
        function setSubmitIdle() {
            submitBtn.disabled = false;
            submitBtn.textContent = 'Submit Report';
        }

        function doSubmit() {
            errorBox.classList.remove('visible');
            dupBox.classList.remove('visible');
            setSubmitLoading();

            const payload = {
                type:              selectedType,
                severity:          selectedSeverity,
                short_description: shortIn.value.trim(),
                description:       descTA.value.trim(),
                page_slug:         pageSlug,
                page_url:          pageUrl,
                page_title:        pageTitle,
                force:             forceSubmit,
            };

            fetch(TCH_BASE_URL + '/ajax/report-issue', {
                method:  'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type':  'application/json',
                    'X-CSRF-Token':  (typeof TCH_CSRF !== 'undefined' ? TCH_CSRF : ''),
                },
                body: JSON.stringify(payload),
            })
            .then(function (r) { return r.json().catch(function () { return { ok: false, error: 'Invalid server response' }; }); })
            .then(function (data) {
                setSubmitIdle();

                if (!data.ok) {
                    errorBox.textContent = data.error || 'Something went wrong. Please try again.';
                    errorBox.classList.add('visible');
                    return;
                }

                if (data.duplicate && !forceSubmit) {
                    pendingDupUrl = data.issue_url || null;
                    dupMsg.textContent = data.message || 'An open report already exists for this page.';
                    dupYes.style.display = pendingDupUrl ? '' : 'none';
                    dupBox.classList.add('visible');
                    return;
                }

                // Success
                qs('#tch-success-ref').textContent        = data.ref || '';
                qs('#tch-success-link').href              = data.issue_url || '#';
                qs('#tch-success-link').style.display     = data.issue_url ? '' : 'none';
                qs('#tch-success-email-note').textContent = data.message || '';

                form.style.display = 'none';
                success.classList.add('visible');
            })
            .catch(function () {
                setSubmitIdle();
                errorBox.textContent = 'Network error. Please check your connection and try again.';
                errorBox.classList.add('visible');
            });
        }
    }

    // ── Init on DOM ready ─────────────────────────────────────────────────
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', buildWidget);
    } else {
        buildWidget();
    }

}());
