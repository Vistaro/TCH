<?php
/**
 * Invitation email — sent when an admin invites a new user.
 *
 * Required vars:
 *   $fullName       — recipient's full name
 *   $inviterName    — name of the admin who sent the invite
 *   $roleName       — display name of the role being assigned
 *   $setupUrl       — full URL including ?token=
 *   $expiresHours   — integer hours until the link expires
 */

$subject = 'You are invited to TCH Placements';

$body = <<<TXT
Hi {$fullName},

{$inviterName} has invited you to join TCH Placements as a {$roleName}.

To set your password and activate your account, click the link below:

{$setupUrl}

This link will expire in {$expiresHours} hours.

If you weren't expecting this invitation, you can safely ignore this email.

— TCH Placements
https://tch.intelligentae.co.uk/
TXT;
