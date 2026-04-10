<?php
/**
 * Password reset email — sent when a user requests a reset.
 *
 * Required vars:
 *   $fullName       — recipient's full name
 *   $resetUrl       — full URL including ?token=
 *   $expiresHours   — integer hours until the link expires
 *   $requestIp      — IP address that requested the reset
 */

$subject = 'TCH Placements — password reset';

$body = <<<TXT
Hi {$fullName},

We received a request to reset the password on your TCH Placements account.
The request came from IP address {$requestIp}.

To set a new password, click the link below:

{$resetUrl}

This link will expire in {$expiresHours} hours.

If you did not request a password reset, you can safely ignore this email
and your password will remain unchanged.

— TCH Placements
https://tch.intelligentae.co.uk/
TXT;
