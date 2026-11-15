<?php
/** Private Chairman signature-image storage for certificate issuance. */
declare(strict_types=1);

const CHAIRMAN_SIGNATURE_MAX_BYTES = 2097152;
const CHAIRMAN_SIGNATURE_MIN_WIDTH = 160;
const CHAIRMAN_SIGNATURE_MIN_HEIGHT = 60;
const CHAIRMAN_SIGNATURE_MAX_WIDTH = 3000;
const CHAIRMAN_SIGNATURE_MAX_HEIGHT = 1600;

function chairman_signature_storage_prefix(): string
{
    return 'storage/private_uploads/chairman-signatures/';
}

/**
 * Resolve only the exact private signature path format. User-controlled paths are never accepted.
 */
function chairman_signature_path_to_absolute(?string $relativePath): ?string
{
    $relativePath = str_replace('\\', '/', trim((string) $relativePath));
    if (!preg_match('~^storage/private_uploads/chairman-signatures/[a-f0-9]{32}\\.jpg$~', $relativePath)) {
        return null;
    }
    $root = realpath(APP_ROOT . '/storage/private_uploads/chairman-signatures');
    $candidate = realpath(APP_ROOT . '/' . $relativePath);
    if ($root === false || $candidate === false || !is_file($candidate)) {
        return null;
    }
    $prefix = rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    if (!str_starts_with($candidate, $prefix)) {
        return null;
    }
    return $candidate;
}

function chairman_signature_current_relative(): ?string
{
    $value = db_val("SELECT setting_value FROM settings WHERE setting_key = 'chairman_signature_path' LIMIT 1");
    $value = is_string($value) ? trim($value) : '';
    return $value !== '' ? $value : null;
}

function chairman_signature_current_absolute(): ?string
{
    return chairman_signature_path_to_absolute(chairman_signature_current_relative());
}

/**
 * Validate and move a JPEG upload into non-public storage.
 * PNG/SVG/WebP and arbitrary renamed files are intentionally refused so the stored asset
 * remains safe for the built-in PDF writer on offline XAMPP installations.
 *
 * @param array<string,mixed> $upload
 */
function chairman_signature_store_upload(array $upload): string
{
    $error = (int) ($upload['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($error !== UPLOAD_ERR_OK) {
        throw new InvalidArgumentException($error === UPLOAD_ERR_NO_FILE
            ? 'Choose a Chairman signature JPG image.'
            : 'The signature image upload did not complete. Try again.');
    }
    $tmp = (string) ($upload['tmp_name'] ?? '');
    $size = (int) ($upload['size'] ?? 0);
    if ($tmp === '' || !is_uploaded_file($tmp) || $size < 1 || $size > CHAIRMAN_SIGNATURE_MAX_BYTES) {
        throw new InvalidArgumentException('Upload a valid JPG image no larger than 2 MB.');
    }
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($tmp);
    $image = @getimagesize($tmp);
    if ($mime !== 'image/jpeg' || !is_array($image) || (int) ($image[2] ?? 0) !== IMAGETYPE_JPEG) {
        throw new InvalidArgumentException('The Chairman signature must be a genuine JPG/JPEG image. Renaming another file type is not accepted.');
    }
    $width = (int) ($image[0] ?? 0);
    $height = (int) ($image[1] ?? 0);
    if ($width < CHAIRMAN_SIGNATURE_MIN_WIDTH || $height < CHAIRMAN_SIGNATURE_MIN_HEIGHT
        || $width > CHAIRMAN_SIGNATURE_MAX_WIDTH || $height > CHAIRMAN_SIGNATURE_MAX_HEIGHT) {
        throw new InvalidArgumentException('Use a clear signature image between 160×60 and 3000×1600 pixels.');
    }
    $directory = APP_ROOT . '/storage/private_uploads/chairman-signatures';
    if (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) {
        throw new RuntimeException('Private signature storage could not be created. Check folder permissions.');
    }
    $filename = bin2hex(random_bytes(16)) . '.jpg';
    $target = $directory . '/' . $filename;
    if (!move_uploaded_file($tmp, $target)) {
        throw new RuntimeException('The signature image could not be saved to private storage.');
    }
    @chmod($target, 0640);
    return chairman_signature_storage_prefix() . $filename;
}

/** Return verified JPEG bytes and image metadata for a private stored signature. */
function chairman_signature_read(?string $relativePath): ?array
{
    $absolute = chairman_signature_path_to_absolute($relativePath);
    if ($absolute === null) { return null; }
    $image = @getimagesize($absolute);
    $bytes = @file_get_contents($absolute);
    if (!is_array($image) || (int) ($image[2] ?? 0) !== IMAGETYPE_JPEG || !is_string($bytes) || $bytes === '') {
        return null;
    }
    return [
        'path' => $absolute,
        'bytes' => $bytes,
        'width' => (int) $image[0],
        'height' => (int) $image[1],
        'channels' => (int) ($image['channels'] ?? 3),
    ];
}
