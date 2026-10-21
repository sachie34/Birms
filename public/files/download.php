<?php
/** Authorized download endpoint for private visitor/request attachments. */
declare(strict_types=1);
require_once __DIR__ . '/../../app/bootstrap.php';

require_login();
require_permission('requests.view');
$id = get_int('id');
$att = $id === null ? null : db_one('SELECT attachment_id, original_name, storage_path, mime_type, byte_size, sha256 FROM document_attachments WHERE attachment_id = ?', [$id]);
if ($att === null) { abort(404, 'That attachment was not found.'); }
$privateRoot = realpath(APP_ROOT . '/storage/private_uploads');
$path = realpath(APP_ROOT . '/' . ltrim((string) $att['storage_path'], '/\\'));
if ($privateRoot === false || $path === false || !str_starts_with($path, $privateRoot . DIRECTORY_SEPARATOR) || !is_file($path)) {
    abort(404, 'That attachment is no longer available.');
}
if (!hash_equals((string) $att['sha256'], hash_file('sha256', $path))) {
    audit_best_effort('attachment_integrity_failed', 'Security', 'Failed', 'Attachment checksum mismatch', 'attachment', (int) $id);
    abort(410, 'The attachment failed its integrity check.');
}
audit_best_effort('attachment_downloaded', 'Files', 'Success', 'Private attachment downloaded: ' . (string) $att['original_name'], 'attachment', (int) $id);
$name = basename((string) $att['original_name']);
$name = str_replace(["\r", "\n", '"', "\\"], '_', $name);
header('Content-Type: ' . (string) $att['mime_type']);
header('Content-Length: ' . (int) filesize($path));
header('Content-Disposition: attachment; filename="' . addcslashes($name, '"\\') . '"; filename*=UTF-8\'\'' . rawurlencode($name));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');
readfile($path);
exit;
