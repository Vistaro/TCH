/**
 * TCH Placements — Shared sortable + filterable table component
 * ─────────────────────────────────────────────────────────────
 *
 * Progressive-enhancement library. Any <table class="tch-data-table">
 * on any admin page gets:
 *
 *   - Sortable column headers (click to toggle asc / desc / off)
 *   - Per-column filter inputs below the header row (text "contains"
 *     filter, case-insensitive, client-side row hiding)
 *   - Auto-detected numeric vs text sort (numbers in currency / plain
 *     form are sorted numerically)
 *
 * Opt-in per column:
 *
 *   <th>...</th>                            → sortable + filterable (default)
 *   <th data-sortable="false">...</th>      → not sortable
 *   <th data-filterable="false">...</th>    → not filterable (no input rendered)
 *   <th data-no-filter>...</th>             → shorthand for data-filterable="false"
 *
 * Row exclusion from filtering / sorting:
 *
 *   <tr class="tch-total-row">...</tr>      → always visible, never sorted
 *   <tr class="tch-drill-row">...</tr>      → filtered/sorted with its parent row
 *
 * Drill-down rows (tch-drill-row) stay with their logical parent row
 * during sort operations (parent row id points to child rows via
 * data-drill / data-parent attributes — same pattern the existing
 * report pages already use).
 *
 * No dependencies, no framework. Safe to load on every admin page.
 */
(function () {
    'use strict';

    function qs(sel, ctx) { return (ctx || document).querySelector(sel); }
    function qsa(sel, ctx) { return Array.prototype.slice.call((ctx || document).querySelectorAll(sel)); }

    /**
     * Parse a cell's text for sorting. Returns a number when the cell
     * looks numeric (stripping currency prefixes, commas, and trailing
     * units), or a lowercased string otherwise.
     */
    function parseCellSortValue(cell) {
        var raw = (cell.textContent || '').trim();
        if (raw === '' || raw === '—' || raw === '-') {
            return null; // null sorts as "lowest" in asc, "highest" in desc
        }
        // Strip currency symbols, commas, spaces → try to parse as number
        var cleaned = raw.replace(/[R$€£,\s]/g, '').replace(/\u00A0/g, '');
        var num = parseFloat(cleaned);
        if (!isNaN(num) && /^-?[\d.]+$/.test(cleaned)) {
            return num;
        }
        return raw.toLowerCase();
    }

    /**
     * Compare two sort values with consistent null handling.
     * nulls go to the bottom in asc, top in desc.
     */
    function compareValues(a, b, direction) {
        if (a === null && b === null) return 0;
        if (a === null) return 1;
        if (b === null) return -1;
        if (typeof a === 'number' && typeof b === 'number') {
            return direction === 'asc' ? a - b : b - a;
        }
        var sa = String(a);
        var sb = String(b);
        if (sa < sb) return direction === 'asc' ? -1 : 1;
        if (sa > sb) return direction === 'asc' ? 1 : -1;
        return 0;
    }

    /**
     * Initialise sortable + filterable behaviour on one table.
     */
    function initTable(table) {
        var thead = qs('thead', table);
        var tbody = qs('tbody', table);
        if (!thead || !tbody) return;

        var headerRow = qs('tr', thead);
        if (!headerRow) return;
        var headers = qsa('th', headerRow);

        // Annotate sortable headers with click handlers + arrows
        headers.forEach(function (th, colIdx) {
            if (th.dataset.sortable === 'false') return;
            th.classList.add('tch-sortable');
            th.setAttribute('data-col-idx', String(colIdx));

            // Append a sort indicator
            var arrow = document.createElement('span');
            arrow.className = 'tch-sort-arrow';
            arrow.innerHTML = ' &#x2195;'; // ↕ placeholder
            th.appendChild(arrow);

            th.addEventListener('click', function (ev) {
                // Clicks inside the filter input row bubble up via the
                // filter row itself, not the header cell — we still need
                // to guard against accidental text-selection clicks.
                if (ev.target.tagName === 'INPUT') return;
                sortByColumn(table, colIdx, th);
            });
        });

        // Insert the filter row under the header row if any column is filterable
        var anyFilterable = headers.some(function (th) {
            return th.dataset.filterable !== 'false' && !th.hasAttribute('data-no-filter');
        });
        if (anyFilterable) {
            var filterRow = document.createElement('tr');
            filterRow.className = 'tch-filter-row';
            headers.forEach(function (th) {
                var cell = document.createElement('th');
                if (th.dataset.filterable === 'false' || th.hasAttribute('data-no-filter')) {
                    filterRow.appendChild(cell);
                    return;
                }
                var input = document.createElement('input');
                input.type = 'text';
                input.className = 'tch-filter-input';
                input.setAttribute('placeholder', 'filter…');
                input.setAttribute('aria-label', 'Filter ' + (th.firstChild ? th.firstChild.textContent.trim() : 'column'));
                input.addEventListener('input', function () { applyFilters(table); });
                cell.appendChild(input);
                filterRow.appendChild(cell);
            });
            thead.appendChild(filterRow);
        }
    }

    /**
     * Sort table rows by column index. Cycles asc → desc → original order.
     * Total rows and drill children are handled specially.
     */
    function sortByColumn(table, colIdx, th) {
        var tbody = qs('tbody', table);
        if (!tbody) return;
        var currentDir = th.getAttribute('data-sort-dir') || 'none';
        var nextDir = currentDir === 'none' ? 'asc' : (currentDir === 'asc' ? 'desc' : 'none');

        // Clear sort indicators on all headers in this table
        qsa('th', qs('thead', table)).forEach(function (other) {
            other.removeAttribute('data-sort-dir');
            other.classList.remove('tch-sorted-asc', 'tch-sorted-desc');
        });

        // Original order recovery: we stashed the original index on each row
        // the first time we touched it.
        var rows = qsa('tr', tbody);
        rows.forEach(function (r, i) {
            if (!r.hasAttribute('data-tch-orig-idx')) {
                r.setAttribute('data-tch-orig-idx', String(i));
            }
        });

        if (nextDir === 'none') {
            // Restore original order
            rows.sort(function (a, b) {
                return parseInt(a.dataset.tchOrigIdx, 10) - parseInt(b.dataset.tchOrigIdx, 10);
            });
            rows.forEach(function (r) { tbody.appendChild(r); });
            th.setAttribute('data-sort-dir', 'none');
            return;
        }

        // Group drill-row children with their parent so we can sort as groups
        var groups = [];
        var totals = [];
        var currentGroup = null;
        rows.forEach(function (r) {
            if (r.classList.contains('tch-total-row') || r.classList.contains('total-row')) {
                totals.push(r);
                return;
            }
            if (r.classList.contains('tch-drill-row') || r.classList.contains('drill-row')) {
                if (currentGroup) currentGroup.children.push(r);
                return;
            }
            currentGroup = { parent: r, children: [] };
            groups.push(currentGroup);
        });

        // Sort the groups by the parent row's column value
        groups.sort(function (g1, g2) {
            var cells1 = qsa('td', g1.parent);
            var cells2 = qsa('td', g2.parent);
            var v1 = cells1[colIdx] ? parseCellSortValue(cells1[colIdx]) : null;
            var v2 = cells2[colIdx] ? parseCellSortValue(cells2[colIdx]) : null;
            return compareValues(v1, v2, nextDir);
        });

        // Re-append in sorted order
        groups.forEach(function (g) {
            tbody.appendChild(g.parent);
            g.children.forEach(function (c) { tbody.appendChild(c); });
        });
        totals.forEach(function (t) { tbody.appendChild(t); });

        th.setAttribute('data-sort-dir', nextDir);
        th.classList.add(nextDir === 'asc' ? 'tch-sorted-asc' : 'tch-sorted-desc');
    }

    /**
     * Apply all active filter inputs to the table's tbody rows.
     * A row matches when every non-empty filter input's text is
     * found (case-insensitive) in the corresponding column's cell.
     * Drill children inherit their parent row's visibility.
     */
    function applyFilters(table) {
        var thead = qs('thead', table);
        var tbody = qs('tbody', table);
        if (!thead || !tbody) return;

        var filterRow = qs('.tch-filter-row', thead);
        if (!filterRow) return;

        var filterInputs = qsa('input.tch-filter-input', filterRow);
        var filters = filterInputs.map(function (input) {
            return (input.value || '').trim().toLowerCase();
        });

        var anyActive = filters.some(function (f) { return f !== ''; });

        var rows = qsa('tr', tbody);
        var currentParent = null;
        var currentParentVisible = true;

        rows.forEach(function (r) {
            if (r.classList.contains('tch-total-row') || r.classList.contains('total-row')) {
                return;
            }
            if (r.classList.contains('tch-drill-row') || r.classList.contains('drill-row')) {
                r.style.display = currentParentVisible ? '' : 'none';
                return;
            }
            currentParent = r;
            if (!anyActive) {
                r.style.display = '';
                currentParentVisible = true;
                return;
            }
            var cells = qsa('td', r);
            var match = filters.every(function (f, colIdx) {
                if (f === '') return true;
                var cell = cells[colIdx];
                if (!cell) return false;
                return (cell.textContent || '').toLowerCase().indexOf(f) !== -1;
            });
            r.style.display = match ? '' : 'none';
            currentParentVisible = match;
        });
    }

    /**
     * Scan the page for eligible tables and initialise them.
     */
    function init() {
        qsa('table.tch-data-table').forEach(initTable);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

}());
