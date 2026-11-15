<?php
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';

$db = aec_db();
$action = (string)($_GET['action'] ?? '');

function aec_media_storage_dir(): string
{
    return aec_private_storage_dir('media');
}

function aec_media_safe_filename(string $name): string
{
    $name = basename(str_replace('\\', '/', $name));
    $name = preg_replace('/[^A-Za-z0-9._ -]/u', '_', $name) ?? 'upload';
    $name = trim($name, ". _");
    return mb_substr($name !== '' ? $name : 'upload', 0, 255);
}

function aec_media_audit(PDO $db, array $actor, string $action, int $assetId, array $metadata = []): void
{
    $stmt = $db->prepare(
        'INSERT INTO audit_logs (user_id, action, entity_type, entity_id, ip_address, metadata)
         VALUES (?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        (int)$actor['id'],
        $action,
        'media_asset',
        $assetId,
        substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45) ?: null,
        json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ]);
}

function aec_media_can_download(PDO $db, array $asset, array $actor): bool
{
    // Media administration is global today; branch operators must not gain access
    // merely because a content.manage permission row was added.
    if (in_array((string)($actor['role'] ?? ''), ['branch_manager', 'support_staff'], true)) return false;
    if ($actor['role'] === 'admin') return true;
    $permission = $db->prepare("SELECT allowed FROM role_permissions WHERE role = ? AND permission_key = 'content.manage' LIMIT 1");
    $permission->execute([$actor['role']]);
    if ((bool)$permission->fetchColumn()) return true;

    $stmt = $db->prepare(
        "SELECT 1 FROM course_content cc
         INNER JOIN content_items ci ON ci.id = cc.content_item_id
         INNER JOIN course_enrollments ce ON ce.course_id = cc.course_id
         INNER JOIN courses c ON c.id = cc.course_id
         WHERE cc.content_item_id = ? AND ce.user_id = ?
           AND ce.status IN ('active','completed')
           AND ci.deleted_at IS NULL AND ci.status = 'published'
           AND c.deleted_at IS NULL AND c.status = 'published'
           AND (c.branch_id IS NULL OR EXISTS (
             SELECT 1 FROM branches b WHERE b.id = c.branch_id AND b.is_active = 1
           ))
         LIMIT 1"
    );
    $stmt->execute([(int)$asset['content_item_id'], (int)$actor['id']]);
    return (bool)$stmt->fetchColumn();
}

if ($action === 'list') {
    aec_method(['GET']);
    aec_require_unscoped_permission('content.manage');
    $contentId = filter_input(INPUT_GET, 'content_id', FILTER_VALIDATE_INT);
    $sql = 'SELECT id, content_item_id, original_filename, mime_type, size_bytes, sha256, uploaded_at FROM media_assets WHERE deleted_at IS NULL';
    if ($contentId) {
        $sql .= ' AND content_item_id = ?';
        $stmt = $db->prepare($sql . ' ORDER BY uploaded_at DESC, id DESC');
        $stmt->execute([$contentId]);
    } else {
        $stmt = $db->query($sql . ' ORDER BY uploaded_at DESC, id DESC LIMIT 500');
    }
    aec_ok($stmt->fetchAll());
}

if ($action === 'trash') {
    aec_method(['GET']);
    $actor = aec_require_unscoped_permission('content.manage');
    if (($actor['role'] ?? '') !== 'admin') aec_fail('Only Admin can view the private media retention area.', 403);
    $stmt = $db->query(
        'SELECT id, content_item_id, original_filename, mime_type, size_bytes, sha256, uploaded_at,
                deleted_at, deleted_by, purge_after, (purge_after <= NOW()) AS can_purge
         FROM media_assets WHERE deleted_at IS NOT NULL
         ORDER BY deleted_at DESC, id DESC LIMIT 500'
    );
    aec_ok($stmt->fetchAll());
}

if ($action === 'upload') {
    aec_method(['POST']);
    $actor = aec_require_unscoped_permission('content.manage');
    aec_csrf_check();
    $contentId = filter_var($_POST['content_item_id'] ?? null, FILTER_VALIDATE_INT);
    if (!$contentId || $contentId < 1) aec_fail('A valid content item is required before uploading a file.', 422);
    $content = $db->prepare('SELECT id FROM content_items WHERE id = ? AND deleted_at IS NULL');
    $content->execute([$contentId]);
    if (!$content->fetchColumn()) aec_fail('Content item not found.', 404);

    $file = $_FILES['file'] ?? null;
    if (!is_array($file) || (int)($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) aec_fail('Select a file to upload.', 422);
    $size = (int)($file['size'] ?? 0);
    if ($size < 1 || $size > 20971520) aec_fail('File size must be between 1 byte and 20 MB.', 413);
    $tmp = (string)($file['tmp_name'] ?? '');
    if ($tmp === '' || !is_uploaded_file($tmp)) aec_fail('Uploaded file could not be verified.', 422);

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($tmp);
    $allowed = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/gif' => 'gif',
        'application/pdf' => 'pdf',
        'audio/mpeg' => 'mp3',
        'audio/mp4' => 'm4a',
        'audio/ogg' => 'ogg',
        'video/mp4' => 'mp4',
        'video/webm' => 'webm',
    ];
    if (!is_string($mime) || !isset($allowed[$mime])) aec_fail('This file type is not allowed. Upload an image, PDF, MP3, M4A, OGG, MP4 or WebM file.', 415);

    $directory = aec_media_storage_dir();
    if (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) aec_fail('Private media storage is unavailable.', 500);
    $storageName = bin2hex(random_bytes(32));
    $destination = $directory . '/' . $storageName;
    if (!move_uploaded_file($tmp, $destination)) aec_fail('Could not save the uploaded file.', 500);
    chmod($destination, 0640);

    $sha256 = hash_file('sha256', $destination);
    if (!is_string($sha256)) {
        @unlink($destination);
        aec_fail('Could not verify the uploaded media file.', 500);
    }
    $originalFilename = aec_media_safe_filename((string)($file['name'] ?? 'upload.' . $allowed[$mime]));
    try {
        $db->beginTransaction();
        $stmt = $db->prepare(
            'INSERT INTO media_assets (content_item_id, original_filename, storage_filename, mime_type, size_bytes, sha256, uploaded_by)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$contentId, $originalFilename, $storageName, $mime, $size, $sha256, (int)$actor['id']]);
        $id = (int)$db->lastInsertId();
        aec_media_audit($db, $actor, 'media.uploaded', $id, [
            'content_item_id' => $contentId,
            'original_filename' => $originalFilename,
            'mime_type' => $mime,
            'size_bytes' => $size,
            'sha256' => $sha256,
        ]);
        $db->commit();
    } catch (Throwable $error) {
        if ($db->inTransaction()) $db->rollBack();
        @unlink($destination);
        throw $error;
    }
    aec_ok(['id' => $id, 'content_item_id' => $contentId, 'filename' => aec_media_safe_filename((string)($file['name'] ?? 'upload')), 'mime_type' => $mime, 'size_bytes' => $size, 'download_url' => '/api/media.php?action=download&id=' . $id], 'Media uploaded to private storage.', 201);
}

if ($action === 'download') {
    aec_method(['GET']);
    $actor = aec_current_user();
    $id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
    if (!$id || $id < 1) aec_fail('A valid media identifier is required.', 422);
    $stmt = $db->prepare('SELECT * FROM media_assets WHERE id = ? AND deleted_at IS NULL LIMIT 1');
    $stmt->execute([$id]);
    $asset = $stmt->fetch();
    if (!$asset || !aec_media_can_download($db, $asset, $actor)) aec_fail('Media file not found.', 404);
    $directory = realpath(aec_media_storage_dir());
    $storageName = (string)$asset['storage_filename'];
    if (!$directory || !preg_match('/^[a-f0-9]{64}$/', $storageName)) aec_fail('Stored media path is invalid.', 500);
    $path = realpath($directory . DIRECTORY_SEPARATOR . $storageName);
    if (!$path || !str_starts_with($path, $directory . DIRECTORY_SEPARATOR) || !is_file($path) || !is_readable($path)) {
        aec_fail('Media file is unavailable.', 404);
    }
    $actualSize = filesize($path);
    if ($actualSize === false || $actualSize < 1 || $actualSize !== (int)$asset['size_bytes']) {
        aec_fail('Stored media file failed its integrity check.', 500);
    }
    $size = (int)$actualSize;
    $start = 0;
    $end = $size - 1;
    $status = 200;
    $range = (string)($_SERVER['HTTP_RANGE'] ?? '');
    if ($range !== '') {
        if (!preg_match('/^bytes=(\d*)-(\d*)$/', $range, $matches) || ($matches[1] === '' && $matches[2] === '')) {
            header('Content-Range: bytes */' . $size);
            aec_fail('Invalid byte range.', 416);
        }
        if ($matches[1] === '') {
            $suffixLength = (int)$matches[2];
            $start = max(0, $size - $suffixLength);
        } else {
            $start = (int)$matches[1];
            if ($matches[2] !== '') $end = min($size - 1, (int)$matches[2]);
        }
        if ($start > $end || $start >= $size) {
            header('Content-Range: bytes */' . $size);
            aec_fail('Requested byte range is not satisfiable.', 416);
        }
        $status = 206;
    }
    $inline = str_starts_with((string)$asset['mime_type'], 'image/')
        || str_starts_with((string)$asset['mime_type'], 'audio/')
        || str_starts_with((string)$asset['mime_type'], 'video/');
    http_response_code($status);
    header('Content-Type: ' . $asset['mime_type']);
    header('Accept-Ranges: bytes');
    header('Content-Length: ' . (string)($end - $start + 1));
    if ($status === 206) header("Content-Range: bytes {$start}-{$end}/{$size}");
    header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . $asset['original_filename'] . '"');
    header('Cache-Control: private, no-store, max-age=0');
    header('X-Content-Type-Options: nosniff');
    $handle = fopen($path, 'rb');
    if ($handle === false) aec_fail('Media file is unavailable.', 404);
    fseek($handle, $start);
    $remaining = $end - $start + 1;
    while ($remaining > 0 && !feof($handle)) {
        $chunk = fread($handle, min(8192, $remaining));
        if ($chunk === false || $chunk === '') break;
        echo $chunk;
        $remaining -= strlen($chunk);
    }
    fclose($handle);
    exit;
}

if ($action === 'delete') {
    aec_method(['POST']);
    $actor = aec_require_unscoped_permission('content.manage');
    aec_csrf_check();
    $input = aec_input();
    $id = filter_var($input['id'] ?? null, FILTER_VALIDATE_INT);
    if (!$id || $id < 1) aec_fail('A valid media identifier is required.', 422);
    $stmt = $db->prepare('SELECT content_item_id, original_filename, mime_type, size_bytes, sha256 FROM media_assets WHERE id = ? AND deleted_at IS NULL LIMIT 1');
    $stmt->execute([$id]);
    $asset = $stmt->fetch();
    if (!$asset) aec_fail('Media file not found.', 404);
    $db->beginTransaction();
    try {
        $update = $db->prepare("UPDATE media_assets SET deleted_at = NOW(), deleted_by = ?, purge_after = DATE_ADD(NOW(), INTERVAL 30 DAY) WHERE id = ? AND deleted_at IS NULL");
        $update->execute([(int)$actor['id'], (int)$id]);
        if ($update->rowCount() !== 1) aec_fail('Media asset changed during deletion; refresh and retry.', 409);
        aec_media_audit($db, $actor, 'media.trashed', (int)$id, [
            'content_item_id' => (int)$asset['content_item_id'],
            'original_filename' => (string)$asset['original_filename'],
            'mime_type' => (string)$asset['mime_type'],
            'size_bytes' => (int)$asset['size_bytes'],
            'sha256' => (string)$asset['sha256'],
            'retention_days' => 30,
        ]);
        $db->commit();
    } catch (Throwable $error) {
        if ($db->inTransaction()) $db->rollBack();
        throw $error;
    }
    aec_ok(['id' => (int)$id, 'purge_after' => date('Y-m-d H:i:s', time() + 30 * 86400)], 'Media moved to trash. It can be restored for 30 days.');
}

if ($action === 'restore') {
    aec_method(['POST']);
    $actor = aec_require_unscoped_permission('content.manage');
    if (($actor['role'] ?? '') !== 'admin') aec_fail('Only Admin can restore deleted private media.', 403);
    aec_csrf_check();
    $input = aec_input();
    $id = filter_var($input['id'] ?? null, FILTER_VALIDATE_INT);
    if (!$id || $id < 1) aec_fail('A valid media identifier is required.', 422);
    $stmt = $db->prepare('SELECT storage_filename, content_item_id, original_filename, mime_type, size_bytes, sha256 FROM media_assets WHERE id = ? AND deleted_at IS NOT NULL AND purge_after > NOW() LIMIT 1');
    $stmt->execute([$id]);
    $asset = $stmt->fetch();
    if (!$asset) aec_fail('Media asset is not in its retention window or has already been purged.', 404);
    $directory = realpath(aec_media_storage_dir());
    $storageName = (string)$asset['storage_filename'];
    if (!$directory || !preg_match('/^[a-f0-9]{64}$/', $storageName)) aec_fail('Stored media path is invalid.', 500);
    $path = realpath($directory . DIRECTORY_SEPARATOR . $storageName);
    if (!$path || !str_starts_with($path, $directory . DIRECTORY_SEPARATOR) || !is_file($path) || !is_readable($path)) {
        aec_fail('Stored media file is unavailable; it cannot be restored.', 404);
    }
    if (filesize($path) !== (int)$asset['size_bytes'] || !hash_equals((string)$asset['sha256'], (string)hash_file('sha256', $path))) {
        aec_fail('Stored media file failed its integrity check; it cannot be restored.', 409);
    }
    $db->beginTransaction();
    try {
        $update = $db->prepare('UPDATE media_assets SET deleted_at = NULL, deleted_by = NULL, purge_after = NULL WHERE id = ? AND deleted_at IS NOT NULL AND purge_after > NOW()');
        $update->execute([(int)$id]);
        if ($update->rowCount() !== 1) aec_fail('Media asset changed during restore; refresh and retry.', 409);
        aec_media_audit($db, $actor, 'media.restored', (int)$id, [
            'content_item_id' => (int)$asset['content_item_id'],
            'original_filename' => (string)$asset['original_filename'],
            'mime_type' => (string)$asset['mime_type'],
            'size_bytes' => (int)$asset['size_bytes'],
            'sha256' => (string)$asset['sha256'],
        ]);
        $db->commit();
    } catch (Throwable $error) {
        if ($db->inTransaction()) $db->rollBack();
        throw $error;
    }
    aec_ok(['id' => (int)$id], 'Private media restored.');
}

if ($action === 'purge') {
    aec_method(['POST']);
    $actor = aec_require_unscoped_permission('content.manage');
    if (($actor['role'] ?? '') !== 'admin') aec_fail('Only Admin can permanently purge private media.', 403);
    aec_csrf_check();
    $input = aec_input();
    $id = filter_var($input['id'] ?? null, FILTER_VALIDATE_INT);
    if (!$id || $id < 1) aec_fail('A valid media identifier is required.', 422);
    $stmt = $db->prepare('SELECT storage_filename, content_item_id, original_filename, mime_type, size_bytes, sha256 FROM media_assets WHERE id = ? AND deleted_at IS NOT NULL AND purge_after <= NOW() LIMIT 1');
    $stmt->execute([$id]);
    $asset = $stmt->fetch();
    if (!$asset) aec_fail('Media retention period has not expired; permanent purge is not allowed.', 409);
    $directory = realpath(aec_media_storage_dir());
    $storageName = (string)$asset['storage_filename'];
    if (!$directory || !preg_match('/^[a-f0-9]{64}$/', $storageName) || !is_writable($directory)) aec_fail('Stored media path is invalid or unavailable.', 500);
    $path = realpath($directory . DIRECTORY_SEPARATOR . $storageName);
    if ($path !== false && (!str_starts_with($path, $directory . DIRECTORY_SEPARATOR) || !is_file($path))) {
        aec_fail('Stored media path is unsafe for deletion.', 500);
    }
    $db->beginTransaction();
    try {
        $delete = $db->prepare('DELETE FROM media_assets WHERE id = ? AND deleted_at IS NOT NULL AND purge_after <= NOW()');
        $delete->execute([(int)$id]);
        if ($delete->rowCount() !== 1) aec_fail('Media asset changed during purge; refresh and retry.', 409);
        aec_media_audit($db, $actor, 'media.purged', (int)$id, [
            'content_item_id' => (int)$asset['content_item_id'],
            'original_filename' => (string)$asset['original_filename'],
            'mime_type' => (string)$asset['mime_type'],
            'size_bytes' => (int)$asset['size_bytes'],
            'sha256' => (string)$asset['sha256'],
            'file_was_present' => $path !== false,
        ]);
        if ($path !== false && !@unlink($path)) aec_fail('Could not remove expired private media; database transaction was rolled back.', 500);
        $db->commit();
    } catch (Throwable $error) {
        if ($db->inTransaction()) $db->rollBack();
        throw $error;
    }
    aec_ok(['id' => (int)$id], 'Expired private media permanently purged.');
}

aec_fail('Unknown media action.', 404);
