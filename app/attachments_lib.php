<?php
/** Private attachment storage for visitor entries and document requests. */
declare(strict_types=1);

function attachment_upload_error(?array $file): ?string
{
    if ($file === null || !isset($file['error'])) { return null; }
    $err = is_int($file['error']) ? $file['error'] : (is_string($file['error']) && ctype_digit($file['error']) ? (int) $file['error'] : -1);
    if ($err === UPLOAD_ERR_NO_FILE) { return null; }
    if ($err !== UPLOAD_ERR_OK) {
        return match ($err) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'Attachment is too large. The limit is 10 MB.',
            UPLOAD_ERR_PARTIAL => 'The attachment upload was incomplete. Please try again.',
            UPLOAD_ERR_NO_TMP_DIR, UPLOAD_ERR_CANT_WRITE => 'The server could not store the attachment. Contact the administrator.',
            default => 'The attachment upload failed.',
        };
    }
    if (!isset($file['tmp_name'], $file['name'], $file['size']) || !is_string($file['tmp_name']) || !is_string($file['name'])
        || !is_numeric($file['size']) || (int) $file['size'] < 1 || (int) $file['size'] > 10 * 1024 * 1024) {
        return 'Choose a non-empty attachment no larger than 10 MB.';
    }
    if (!is_uploaded_file($file['tmp_name'])) { return 'The uploaded file could not be verified.'; }
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $allowed = ['pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png'];
    if (!isset($allowed[$ext])) { return 'Allowed attachment formats are PDF, JPG and PNG.'; }
    if (!function_exists('finfo_open')) { return 'The server file-inspection extension is required to accept uploads.'; }
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    if ($finfo === false) { return 'The server could not inspect the uploaded file.'; }
    $mime = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);
    if (!is_string($mime) || $mime !== $allowed[$ext]) { return 'The file content does not match its PDF, JPG or PNG extension.'; }
    return null;
}

/** Save the validated upload under an unguessable name outside the public web root. */
function store_document_upload(string $scope, int $parentId, ?array $file): ?int
{
    if ($file === null || (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE)) { return null; }
    $error = attachment_upload_error($file);
    if ($error !== null) { throw new RuntimeException($error); }
    if (!in_array($scope, ['visitor', 'request'], true) || $parentId < 1) { throw new RuntimeException('Invalid attachment owner.'); }

    $extension = strtolower(pathinfo((string) $file['name'], PATHINFO_EXTENSION));
    if ($extension === 'jpeg') { $extension = 'jpg'; }
    $relative = 'storage/private_uploads/' . date('Y/m') . '/' . bin2hex(random_bytes(24)) . '.' . $extension;
    $absolute = APP_ROOT . '/' . $relative;
    $directory = dirname($absolute);
    if (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) {
        throw new RuntimeException('The server could not create the private attachment folder.');
    }
    if (!move_uploaded_file((string) $file['tmp_name'], $absolute)) {
        throw new RuntimeException('The server could not save the attachment.');
    }
    @chmod($absolute, 0640);
    $mime = match ($extension) { 'pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'png' => 'image/png', default => 'application/octet-stream' };
    $original = preg_replace('/[\x00-\x1F\x7F]/u', '', basename((string) $file['name'])) ?? 'attachment';
    $original = function_exists('mb_substr') ? mb_substr($original, 0, 255, 'UTF-8') : substr($original, 0, 255);
    try {
        $actor = audit_actor();
        $visitId = $scope === 'visitor' ? $parentId : null;
        $requestId = $scope === 'request' ? $parentId : null;
        db_exec('INSERT INTO document_attachments (visit_id, request_id, original_name, storage_path, mime_type, byte_size, sha256, uploaded_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [$visitId, $requestId, $original, $relative, $mime, (int) filesize($absolute), hash_file('sha256', $absolute), $actor['user_id'] ?? null]);
        $id = (int) db()->lastInsertId();
        audit('attachment_uploaded', $scope === 'visitor' ? 'Visitors' : 'Requests', 'Success', 'Attachment stored: ' . $original, 'attachment', $id);
        return $id;
    } catch (Throwable $e) {
        @unlink($absolute);
        throw $e;
    }
}

function attachments_for_parent(string $scope, int $parentId): array
{
    if ($scope === 'visitor') {
        return db_all('SELECT attachment_id, visit_id, original_name, mime_type, byte_size, uploaded_at FROM document_attachments WHERE visit_id = ? ORDER BY attachment_id', [$parentId]);
    }
    if ($scope === 'request') {
        return db_all('SELECT attachment_id, request_id, original_name, mime_type, byte_size, uploaded_at FROM document_attachments WHERE request_id = ? ORDER BY attachment_id', [$parentId]);
    }
    return [];
}
