<?php
/**
 * Password set confirmation — sent after a user successfully sets/changes
 * their password (either from an invite or a reset).
 *
 * Required vars:
 *   $fullName  — recipient's full name
 *   $loginUrl  — login page URL
 *   $eventTime — Y-m-d H:i UTC string
 *   $eventIp   — IP that performed the change
 */

$subject = 'TCH Placements — your password was changed';

$body = <<<TXT
Hi {$fullName},

This is a confirmation that the password on your TCH Placements account
was changed at {$eventTime} UTC from IP address {$eventIp}.

You can log in here:
{$loginUrl}

If you did NOT make this change, please contact your administrator
immediately — your account may be compromised.

— TCH Placements
https://tch.intelligentae.co.uk/
TXT;
