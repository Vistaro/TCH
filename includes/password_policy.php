<?php
/**
 * Password policy — one source of truth for validation + history.
 *
 * Rules:
 *   - Min 10 characters
 *   - At least 1 letter (upper OR lower — treat case as coverage, not a rule of its own)
 *   - At least 1 digit
 *   - At least 1 symbol from: ! @ # $ % ^ & * _ - + = ? . , / \ [ ] { } ( ) < > ~ ` ; : ' "
 *   - Must not contain the user's email local-part or full name (case-insensitive)
 *   - Must not match any of the last 5 password hashes stored for the user
 *
 * Public API:
 *   passwordPolicyRulesText(): string  — human-readable list for UI
 *   validatePasswordPolicy(string $pw, array $user, PDO $db): ?string
 *       Returns error message or null if OK.
 *   recordPasswordInHistory(int $userId, string $hash, PDO $db): void
 *       Inserts new history row + prunes anything older than the last 5.
 */

const PWD_HISTORY_KEEP = 5;

function passwordPolicyRulesText(): string {
    return 'At least 10 characters, with one letter, one number and one symbol. '
         . 'Cannot contain your name or email and cannot reuse your last ' . PWD_HISTORY_KEEP . ' passwords.';
}

/**
 * Returns error message or null if OK. `$user` should contain `email` and
 * `full_name`. Pass `null` for `$db` if history-check is not needed yet
 * (e.g. during an invite-acceptance where there's no prior history).
 */
function validatePasswordPolicy(string $pw, array $user, ?PDO $db = null, ?int $userIdForHistory = null): ?string {
    if (strlen($pw) < 10) {
        return 'Password must be at least 10 characters.';
    }
    if (!preg_match('/[A-Za-z]/', $pw)) {
        return 'Password must include at least one letter.';
    }
    if (!preg_match('/\d/', $pw)) {
        return 'Password must include at least one number.';
    }
    if (!preg_match('/[!@#$%^&*_\-+=?.,\/\\\\\[\]{}()<>~`;:\'"]/', $pw)) {
        return 'Password must include at least one symbol (e.g. ! @ # $ % &).';
    }

    $lower = mb_strtolower($pw);
    $email = isset($user['email']) ? (string)$user['email'] : '';
    if ($email !== '') {
        $local = strstr($email, '@', true) ?: $email;
        if ($local !== '' && str_contains($lower, mb_strtolower($local))) {
            return 'Password cannot contain your email address.';
        }
    }
    $name = isset($user['full_name']) ? trim((string)$user['full_name']) : '';
    if ($name !== '') {
        // Block exact full name OR any word in the name that's 4+ chars
        foreach (preg_split('/\s+/', $name) as $part) {
            $part = trim($part);
            if (mb_strlen($part) >= 4 && str_contains($lower, mb_strtolower($part))) {
                return 'Password cannot contain your name.';
            }
        }
    }

    if ($db !== null && $userIdForHistory !== null && $userIdForHistory > 0) {
        $stmt = $db->prepare(
            'SELECT password_hash FROM user_password_history
             WHERE user_id = ? ORDER BY created_at DESC LIMIT ?'
        );
        $stmt->bindValue(1, $userIdForHistory, PDO::PARAM_INT);
        $stmt->bindValue(2, PWD_HISTORY_KEEP, PDO::PARAM_INT);
        $stmt->execute();
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $oldHash) {
            if (password_verify($pw, $oldHash)) {
                return 'Password cannot match any of your last ' . PWD_HISTORY_KEEP . ' passwords.';
            }
        }
        // Also reject match against current live hash (belt-and-braces)
        $stmt = $db->prepare('SELECT password_hash FROM users WHERE id = ?');
        $stmt->execute([$userIdForHistory]);
        $current = $stmt->fetchColumn();
        if ($current && password_verify($pw, $current)) {
            return 'New password must be different from your current password.';
        }
    }

    return null;
}

function recordPasswordInHistory(int $userId, string $newHash, PDO $db): void {
    $db->prepare('INSERT INTO user_password_history (user_id, password_hash) VALUES (?, ?)')
       ->execute([$userId, $newHash]);
    // Keep only the most recent PWD_HISTORY_KEEP rows per user
    $stmt = $db->prepare(
        'SELECT id FROM user_password_history WHERE user_id = ? ORDER BY created_at DESC LIMIT ?, 1000'
    );
    $stmt->bindValue(1, $userId, PDO::PARAM_INT);
    $stmt->bindValue(2, PWD_HISTORY_KEEP, PDO::PARAM_INT);
    $stmt->execute();
    $toDelete = $stmt->fetchAll(PDO::FETCH_COLUMN);
    if ($toDelete) {
        $ph = implode(',', array_fill(0, count($toDelete), '?'));
        $del = $db->prepare("DELETE FROM user_password_history WHERE id IN ($ph)");
        $del->execute($toDelete);
    }
}
