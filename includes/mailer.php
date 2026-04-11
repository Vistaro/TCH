<?php
/**
 * Mailer — wraps PHP mail() with an outbox table.
 *
 * Every send is recorded in `email_log` BEFORE mail() is called. The row
 * starts as 'queued', flips to 'sent' or 'failed' depending on the result.
 * This guarantees that a developer can always retrieve the link from the
 * outbox even when mail() silently drops the message (a routine failure
 * mode on shared hosts without a real MTA).
 *
 * Templates are plain PHP files in templates/emails/. Each template is
 * passed an array of variables and returns subject + body via `return`.
 *
 * No HTML for v1 — text/plain only. Real provider (Mailgun / SES) will
 * be wired up in a later session.
 */

if (!defined('APP_ROOT')) {
    die('Direct access not permitted.');
}

class Mailer
{
    /**
     * Render a template and queue+send the email.
     *
     * @param string      $template e.g. 'invite', 'reset', 'reset_confirm'
     * @param string      $toEmail
     * @param string|null $toName
     * @param array       $vars     Template variables
     * @param int|null    $relatedUserId
     * @return int  email_log row id (always returned, even on send failure)
     */
    public static function send(
        string $template,
        string $toEmail,
        ?string $toName,
        array $vars,
        ?int $relatedUserId = null
    ): int {
        [$subject, $body] = self::renderTemplate($template, $vars);

        $fromEmail = $_ENV['MAIL_FROM_EMAIL'] ?? ('no-reply@' . self::hostFromAppUrl());
        $fromName  = $_ENV['MAIL_FROM_NAME']  ?? 'TCH Placements';

        $db = getDB();
        $stmt = $db->prepare(
            'INSERT INTO email_log
                (to_email, to_name, from_email, from_name, subject, body_text,
                 template, related_user_id, status, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, "queued", NOW())'
        );
        $stmt->execute([
            $toEmail,
            $toName,
            $fromEmail,
            $fromName,
            $subject,
            $body,
            $template,
            $relatedUserId,
        ]);
        $logId = (int)$db->lastInsertId();

        // Attempt delivery
        $headers  = "From: " . self::formatAddress($fromEmail, $fromName) . "\r\n";
        $headers .= "Reply-To: " . $fromEmail . "\r\n";
        $headers .= "X-Mailer: TCH/Mailer\r\n";
        $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";

        $ok = false;
        $err = null;
        try {
            $ok = @mail($toEmail, self::encodeSubject($subject), $body, $headers);
        } catch (Throwable $e) {
            $ok = false;
            $err = substr($e->getMessage(), 0, 500);
        }

        $upd = $db->prepare(
            'UPDATE email_log SET status = ?, sent_at = ?, error_message = ? WHERE id = ?'
        );
        if ($ok) {
            $upd->execute(['sent', date('Y-m-d H:i:s'), null, $logId]);
        } else {
            $upd->execute(['failed', null, $err ?? 'mail() returned false', $logId]);
        }

        // Audit: surface every send attempt in the main activity log too,
        // so "I never got an email" claims can be answered in the same UI
        // as every other audit question. entity_id is the email_log row so
        // the detail page can deep-link back to the full outbox entry.
        logActivity(
            'email_sent',
            null,
            'email_log',
            $logId,
            ($ok ? 'Sent: ' : 'Send FAILED: ') . $template . ' -> ' . $toEmail,
            null,
            [
                'template' => $template,
                'to'       => $toEmail,
                'subject'  => $subject,
                'status'   => $ok ? 'sent' : 'failed',
            ]
        );

        return $logId;
    }

    /**
     * Render a template file and return [$subject, $body].
     * Templates live in templates/emails/<name>.php and must define
     * $subject and $body as locals.
     */
    private static function renderTemplate(string $template, array $vars): array {
        $path = APP_ROOT . '/templates/emails/' . basename($template) . '.php';
        if (!is_file($path)) {
            throw new RuntimeException("Email template not found: $template");
        }
        // Extract vars into the template scope
        extract($vars, EXTR_SKIP);
        $subject = '';
        $body    = '';
        require $path;
        return [$subject, $body];
    }

    private static function formatAddress(string $email, ?string $name): string {
        if ($name === null || $name === '') {
            return $email;
        }
        // Quote the display name to be safe
        return '"' . addslashes($name) . '" <' . $email . '>';
    }

    private static function encodeSubject(string $subject): string {
        // RFC 2047 encoded-word for non-ASCII subjects
        if (preg_match('/[\\x80-\\xff]/', $subject)) {
            return '=?UTF-8?B?' . base64_encode($subject) . '?=';
        }
        return $subject;
    }

    private static function hostFromAppUrl(): string {
        $host = parse_url(APP_URL, PHP_URL_HOST);
        return $host ?: 'tch.intelligentae.co.uk';
    }
}
