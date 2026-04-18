---
type:     bug
project:  tch
severity: low
status:   draft (not yet filed)
drafted:  2026-04-18
source:   autonomous-cleanup-session
---

# Kanban drag-drop doesn't work on mobile / touch devices

## 2a. Reporter description

Noticed during the FR-L cleanup pass on 2026-04-18 that the new
`/admin/pipeline` Kanban is built on native HTML5 drag events,
which don't fire reliably on touch devices. Tuniti on a phone
would see cards that look draggable but can't actually be moved
between stages. The page otherwise loads fine — it's just the
drag interaction that's broken on touch.

## 2b. Agent-enriched CxO framing

**Outcome**: Kanban view at `/admin/pipeline` becomes usable on
mobile / tablet so Tuniti can move opportunities through stages
from her phone between appointments.

**What it does currently**: Desktop drag-and-drop works. On
touch devices, the card accepts focus but the `dragstart` event
doesn't fire (this is the iOS + Android default behaviour for
HTML5 drag events), so cards can't be moved between columns.

**Why it matters**: Tuniti is often out of the office when deals
move. Forcing her back to a desktop to update pipeline stage
slows down the sales feedback loop and makes the Kanban a
"nice-to-have" rather than a day-to-day tool.

**If we don't do it**: Mobile users either ignore the Kanban and
use the `/admin/opportunities` list view (it works fine on mobile
— you edit each opp's stage via the dropdown), or they carry
unmoved cards in their head until they're back at a desk. Both
are fine as a workaround — hence "low" severity.

## 3. Technical description

**Architecture context**: `templates/admin/pipeline.php` uses
vanilla JS with `dragstart` / `dragover` / `drop` event
listeners. This is intentional — matches the "no heavy JS
framework in the admin" pattern throughout TCH. But HTML5
drag-drop on touch requires a polyfill or a touch-event fallback
because mobile browsers don't translate touch gestures into
drag events.

**Proposed approach** — two realistic options:

1. **Small touch fallback layer.** Listen for `touchstart` →
   `touchmove` → `touchend` on `.kanban-card`, visually move the
   card via CSS transform, on `touchend` detect which column the
   touch ended in and POST the same AJAX move as the desktop
   path. Probably 60–80 lines of vanilla JS. Native drag-drop
   for desktop stays.

2. **Route mobile users away.** Detect viewport < 768px in
   server-side CSS and either hide drag-drop affordances or
   redirect `/admin/pipeline` to `/admin/opportunities` with a
   note. Pipeline becomes desktop-only by design; list view
   covers mobile. Simplest fix.

The `/admin/help` page currently tells users to use the list
view on phone — option 2 just makes that a hard rule. Option 1
keeps feature parity across devices.

**Dependencies / risks**:
- HTML5 touch-drag libraries exist (DragDropTouch shim, 15KB) —
  drop-in for option 1, but adds a dependency.
- Option 2 works today, zero dependencies.

**Acceptance criteria**:
- Tuniti (or any touch user) can either move cards on mobile
  (option 1) OR is clearly directed to the list view instead
  (option 2).
- Desktop drag-drop continues to work unchanged.

## Notes

- Ross greenlit parking this as a Hub bug rather than fixing
  in the 2026-04-18 cleanup session.
- Existing workaround is already documented in the user guide
  (`/admin/help` — "Pipeline (Kanban)" tips block).
- See also `docs/sessions/2026-04-18-autonomous-cleanup.md` for
  the discovery context.
