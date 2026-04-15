<?php
/**
 * Onboarding upload helpers.
 *
 * Reusable upload handler + widget renderer for any onboarding task.
 * Physical files live at storage/onboarding/YYYY-MM/<id>-<sha256>.<ext>
 * outside the webroot. Metadata in onboarding_uploads.
 */

if (!defined('ONBOARDING_STORAGE_ROOT')) {
    define('ONBOARDING_STORAGE_ROOT', APP_ROOT . '/storage/onboarding');
}

/**
 * Handle a POSTed file upload for a specific task.
 * Returns array: ['ok' => bool, 'msg' => string, 'upload_id' => int|null]
 */
function onboardingHandleUpload(PDO $db, string $taskKey, int $uploaderUserId, array $file): array {
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'msg' => 'Upload failed — please try again.', 'upload_id' => null];
    }
    $size = (int)($file['size'] ?? 0);
    if ($size <= 0 || $size > 25 * 1024 * 1024) {
        return ['ok' => false, 'msg' => 'File must be between 0 and 25MB.', 'upload_id' => null];
    }
    $orig = basename((string)$file['name']);
    $ext  = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
    if ($ext === '' || strlen($ext) > 10) $ext = 'bin';

    $sha = hash_file('sha256', (string)$file['tmp_name']);
    if (!$sha) {
        return ['ok' => false, 'msg' => 'Could not read uploaded file.', 'upload_id' => null];
    }

    $monthDir = date('Y-m');
    $targetDir = ONBOARDING_STORAGE_ROOT . '/' . $monthDir;
    if (!is_dir($targetDir)) {
        @mkdir($targetDir, 0775, true);
    }
    if (!is_dir($targetDir)) {
        return ['ok' => false, 'msg' => 'Storage directory not writable. Contact admin.', 'upload_id' => null];
    }

    // Insert metadata first so we have an ID for the filename
    $stmt = $db->prepare(
        "INSERT INTO onboarding_uploads
           (task_key, uploader_user_id, filename, stored_path, sha256, mime, size_bytes, status)
         VALUES (?, ?, ?, ?, ?, ?, ?, 'uploaded')"
    );
    // Placeholder stored_path, we'll update after we know the id
    $stmt->execute([
        $taskKey, $uploaderUserId ?: null, $orig, '', $sha,
        (string)($file['type'] ?? 'application/octet-stream'), $size,
    ]);
    $id = (int)$db->lastInsertId();

    $safeExt = preg_replace('/[^a-z0-9]/i', '', $ext) ?: 'bin';
    $relPath = $monthDir . '/' . $id . '-' . substr($sha, 0, 12) . '.' . $safeExt;
    $absPath = ONBOARDING_STORAGE_ROOT . '/' . $relPath;

    if (!move_uploaded_file((string)$file['tmp_name'], $absPath)) {
        $db->prepare("DELETE FROM onboarding_uploads WHERE id = ?")->execute([$id]);
        return ['ok' => false, 'msg' => 'Could not save uploaded file.', 'upload_id' => null];
    }

    $db->prepare("UPDATE onboarding_uploads SET stored_path = ? WHERE id = ?")
       ->execute([$relPath, $id]);

    if (function_exists('logActivity')) {
        logActivity('onboarding_upload', 'onboarding', 'onboarding_uploads', $id,
            'Uploaded "' . $orig . '" against task ' . $taskKey,
            null,
            ['task_key' => $taskKey, 'filename' => $orig, 'size_bytes' => $size]);
    }

    return ['ok' => true, 'msg' => 'Uploaded. We will review and extract what we need.', 'upload_id' => $id];
}

/**
 * Render the "Upload what you have" widget for a task subpage.
 * Assumes csrfField() is available from includes/auth.php.
 */
function renderOnboardingUploadWidget(string $taskKey, string $hint = '', ?string $currentFlash = null): string {
    $hint = htmlspecialchars($hint);
    $taskKey = htmlspecialchars($taskKey);
    $flash = '';
    if ($currentFlash !== null && $currentFlash !== '') {
        $flash = '<div style="background:#e7f5ee;border:1px solid #b6e0c8;color:#165d36;padding:0.5rem 0.75rem;border-radius:6px;margin-top:0.5rem;font-size:0.9rem;">' .
                 htmlspecialchars($currentFlash) . '</div>';
    }
    $csrf = function_exists('csrfField') ? csrfField() : '';
    return <<<HTML
<div style="background:#f8f9fa;border:1px dashed #cfd5da;border-radius:8px;padding:1rem 1.25rem;margin-bottom:1.5rem;">
    <div style="font-weight:600;font-size:1.0rem;margin-bottom:0.25rem;">Upload what you have</div>
    <div style="color:#6c757d;font-size:0.85rem;margin-bottom:0.6rem;">{$hint}</div>
    <form method="POST" enctype="multipart/form-data" style="display:flex;gap:0.5rem;align-items:center;flex-wrap:wrap;">
        {$csrf}
        <input type="hidden" name="action" value="onboarding_upload">
        <input type="hidden" name="task_key" value="{$taskKey}">
        <input type="file" name="file" required style="flex:1;min-width:250px;">
        <button class="btn btn-primary btn-sm" type="submit">Upload</button>
    </form>
    {$flash}
</div>
HTML;
}
