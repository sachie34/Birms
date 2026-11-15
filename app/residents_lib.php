<?php
/** Shared resident helpers: names, direct Address/Purok fields, SRS filters. */
declare(strict_types=1);

function resident_name(array $r): string
{
    $n = $r['last_name'] . ', ' . $r['first_name'];
    if (!empty($r['middle_name'])) { $n .= ' ' . $r['middle_name']; }
    if (!empty($r['suffix'])) { $n .= ' ' . $r['suffix']; }
    return $n;
}
function resident_name_natural(array $r): string
{
    return trim($r['first_name'] . ' ' . ($r['middle_name'] ?? '') . ' ' . $r['last_name'] . ' ' . ($r['suffix'] ?? ''));
}

function resident_filters(array $src, bool $canSeeArchived): array
{
    $status = input_str($src, 'status');
    $validStatuses = array_values(lookup_list('resident_status', false));
    $type = input_int($src, 'type') ?? input_int($src, 'category');
    return [
        'q' => input_str($src, 'q'),
        'purok' => (string) (input_int($src, 'purok') ?? ''),
        'status' => in_array($status, $validStatuses, true) ? $status : '',
        'type' => (string) ($type ?? ''),
        'show' => ($canSeeArchived && input_str($src, 'show') === 'archived') ? 'archived' : '',
    ];
}

/** @return array{0:string,1:array} FROM/WHERE fragment and parameters. Household joins are read-only fallbacks for old rows. */
function resident_query_parts(array $f): array
{
    $from = "FROM residents r
             LEFT JOIN households h ON h.household_id = r.household_id
             LEFT JOIN lookups pk ON pk.lookup_id = COALESCE(r.purok_id, h.purok_id) AND pk.list_key = 'purok'
             LEFT JOIN lookups rs ON rs.lookup_id = r.resident_status_id AND rs.list_key = 'resident_status'
             LEFT JOIN lookups rt ON rt.lookup_id = r.resident_type_id AND rt.list_key = 'resident_type'
             LEFT JOIN lookups oldcat ON oldcat.lookup_id = r.category_id AND oldcat.list_key = 'resident_category'";
    $w = [$f['show'] === 'archived' ? 'r.archived_at IS NOT NULL' : 'r.archived_at IS NULL'];
    $p = [];
    foreach (preg_split('/[\s,]+/u', $f['q'], -1, PREG_SPLIT_NO_EMPTY) ?: [] as $tok) {
        $like = '%' . addcslashes($tok, '%_\\') . '%';
        $w[] = '(r.last_name LIKE ? OR r.first_name LIKE ? OR r.middle_name LIKE ? OR r.resident_no LIKE ? OR COALESCE(r.address, h.street_address, \'\') LIKE ? OR COALESCE(r.email_address, \'\') LIKE ?)';
        array_push($p, $like, $like, $like, $like, $like, $like);
    }
    if ($f['purok'] !== '') {
        $w[] = 'COALESCE(r.purok_id, h.purok_id) = ?';
        $p[] = (int) $f['purok'];
    }
    if ($f['status'] !== '') {
        $legacy = $f['status'] === 'Active' ? 'Active' : ($f['status'] === 'Transferred' ? 'Relocated' : null);
        if ($legacy !== null) {
            $w[] = '(rs.label = ? OR (r.resident_status_id IS NULL AND r.residency_status = ?))';
            array_push($p, $f['status'], $legacy);
        } else {
            $w[] = 'rs.label = ?';
            $p[] = $f['status'];
        }
    }
    if ($f['type'] !== '') {
        $w[] = 'r.resident_type_id = ?';
        $p[] = (int) $f['type'];
    }
    return [$from . ' WHERE ' . implode(' AND ', $w), $p];
}

function resident_search(array $f, int $limit, int $offset): array
{
    [$q, $p] = resident_query_parts($f);
    $total = (int) db_val('SELECT COUNT(*) ' . $q, $p);
    $rows = db_all('SELECT r.*, h.household_code, COALESCE(r.address, h.street_address) AS address, pk.label AS purok,
                           COALESCE(rs.label, CASE WHEN r.residency_status = \'Relocated\' THEN \'Transferred\' ELSE r.residency_status END) AS resident_status,
                           COALESCE(rt.label, oldcat.label) AS resident_type
                    ' . $q . ' ORDER BY r.last_name, r.first_name LIMIT ' . (int) $limit . ' OFFSET ' . (int) $offset, $p);
    return [$rows, $total];
}

/** Retained only for legacy migration/support pages; the revised resident form does not use households. */
function households_for_select(): array
{
    $out = [];
    foreach (db_all('SELECT h.household_id, h.household_code, h.street_address, pk.label AS purok FROM households h LEFT JOIN lookups pk ON pk.lookup_id = h.purok_id ORDER BY h.household_code') as $h) {
        $out[(int) $h['household_id']] = $h['household_code'] . ' · ' . $h['street_address'] . ($h['purok'] ? ' · ' . $h['purok'] : '');
    }
    return $out;
}

/** Validate an optional resident portrait upload. Only genuine JPEG/PNG raster images are accepted. */
function resident_photo_upload_error(?array $file): ?string
{
    if ($file === null || !isset($file['error'])) { return null; }
    $error = is_int($file['error']) ? $file['error']
        : (is_string($file['error']) && ctype_digit($file['error']) ? (int) $file['error'] : -1);
    if ($error === UPLOAD_ERR_NO_FILE) { return null; }
    if ($error !== UPLOAD_ERR_OK) {
        return match ($error) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'The resident photo is larger than the server upload limit.',
            UPLOAD_ERR_PARTIAL => 'The photo upload was incomplete. Try again.',
            UPLOAD_ERR_NO_TMP_DIR, UPLOAD_ERR_CANT_WRITE => 'The server could not store the photo. Contact the administrator.',
            default => 'The resident photo upload failed.',
        };
    }
    if (!isset($file['tmp_name'], $file['name'], $file['size'])
        || !is_string($file['tmp_name']) || !is_string($file['name']) || !is_numeric($file['size'])
        || (int) $file['size'] < 1 || (int) $file['size'] > 5 * 1024 * 1024) {
        return 'Choose a resident photo no larger than 5 MB.';
    }
    if (!is_uploaded_file($file['tmp_name'])) { return 'The photo upload could not be verified.'; }
    $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($extension, ['jpg','jpeg','png'], true)) { return 'Resident photos must be JPG or PNG images.'; }
    if (!function_exists('finfo_open') || !function_exists('getimagesize')) { return 'The server image-inspection extensions are required to accept photos.'; }
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    if ($finfo === false) { return 'The server could not inspect the photo.'; }
    $mime = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);
    $image = @getimagesize($file['tmp_name']);
    $expected = $extension === 'png' ? 'image/png' : 'image/jpeg';
    if (!is_string($mime) || $mime !== $expected || !is_array($image) || ($image['mime'] ?? '') !== $expected) {
        return 'The image content does not match its JPG or PNG extension.';
    }
    $width = (int) ($image[0] ?? 0);
    $height = (int) ($image[1] ?? 0);
    if ($width < 1 || $height < 1 || $width > 6000 || $height > 6000) {
        return 'The photo dimensions must not exceed 6000 by 6000 pixels.';
    }
    return null;
}

/** Store a validated resident photo outside the public web root with an unguessable filename. */
function resident_photo_store(array $file): string
{
    $error = resident_photo_upload_error($file);
    if ($error !== null) { throw new RuntimeException($error); }
    $extension = strtolower(pathinfo((string) $file['name'], PATHINFO_EXTENSION)) === 'png' ? 'png' : 'jpg';
    $relative = 'storage/resident_photos/' . date('Y/m') . '/' . bin2hex(random_bytes(24)) . '.' . $extension;
    $absolute = APP_ROOT . '/' . $relative;
    $directory = dirname($absolute);
    if (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) {
        throw new RuntimeException('The server could not create the private resident-photo folder.');
    }
    if (!move_uploaded_file((string) $file['tmp_name'], $absolute)) {
        throw new RuntimeException('The server could not save the resident photo.');
    }
    @chmod($absolute, 0640);
    return $relative;
}

/** Delete only files inside the dedicated resident-photo storage directory. */
function resident_photo_delete(?string $relative): void
{
    if ($relative === null || $relative === '') { return; }
    $root = realpath(APP_ROOT . '/storage/resident_photos');
    $path = realpath(APP_ROOT . '/' . ltrim($relative, '/\\\\'));
    if ($root !== false && $path !== false
        && str_starts_with($path, $root . DIRECTORY_SEPARATOR)
        && is_file($path)) {
        @unlink($path);
    }
}
