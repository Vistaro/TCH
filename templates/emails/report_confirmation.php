<?php
/**
 * Confirmation email — sent after a user submits a bug or feature
 * request via the in-app reporter. The submission has already been
 * recorded on the Nexus Hub at this point; this email carries the ref
 * and a link back to the Hub issue view.
 *
 * Required vars:
 *   $userName      — reporter's display name
 *   $ref           — Hub reference (e.g. "BUG-0042" or "FR-0017")
 *   $typeLabel     — "Bug" or "Feature Request"
 *   $severityLabel — "🔴 Fatal" or "🔵 Improvement"
 *   $pageLabel     — Human-readable page title (or slug)
 *   $pageUrl       — Full URL of the page the report was raised from
 *   $description   — User's free-text description (may be empty)
 *   $issueUrl      — Nexus Hub issue view URL
 */

$subject = "Your {$typeLabel} has been logged — {$ref}";

$descBlock = $description !== ''
    ? "What you said:\n{$description}\n\n"
    : '';

$body = <<<TXT
Hi {$userName},

Thanks for taking the time to report this. Your {$typeLabel} has
been logged on Nexus Hub, our central tracker across all projects.

Reference:  {$ref}
Type:       {$typeLabel}
Severity:   {$severityLabel}
Page:       {$pageLabel}
URL:        {$pageUrl}

{$descBlock}You can view the issue (and follow status updates) here:

{$issueUrl}

If you have more detail to add, reply to this email or leave a
comment on the Hub.

— TCH Placements
https://tch.intelligentae.co.uk/
TXT;
