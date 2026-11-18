<?php
/** Permission-checked display of a private resident portrait. */
declare(strict_types=1);
require_once __DIR__ . '/../../app/bootstrap.php';

require_login();
require_permission('residents.view');
$id = get_int('id');
$resident = $id === null
    ? null
    : db_one('SELECT photo_path, archived_at FROM residents WHERE resident_id = ?', [$id]);
if ($resident === null || ($resident['archived_at'] !== null && !can('residents.archive')) || empty($resident['photo_path'])) {
    abort(404, 'That resident photo was not found.');
}

$privateRoot = realpath(APP_ROOT . '/storage/resident_photos');
$path = realpath(APP_ROOT . '/' . ltrim((string) $resident['photo_path'], '/\\'));
if ($privateRoot === false || $path === false
    || !str_starts_with($path, $privateRoot . DIRECTORY_SEPARATOR)
    || !is_file($path)) {
    abort(404, 'That resident photo is no longer available.');
}
if (!function_exists('finfo_open')) { abort(415, 'The server cannot verify the resident photo.'); }
$finfo = finfo_open(FILEINFO_MIME_TYPE);
if ($finfo === false) { abort(415, 'The server cannot verify the resident photo.'); }
$mime = finfo_file($finfo, $path);
finfo_close($finfo);
if (!in_array($mime, ['image/jpeg','image/png'], true) || !is_array(@getimagesize($path))) {
    abort(415, 'The stored resident photo is not a valid image.');
}

header('Content-Type: ' . $mime);
header('Content-Length: ' . (int) filesize($path));
header('Content-Disposition: inline; filename="resident-photo"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store, max-age=0');
readfile($path);
exit;
