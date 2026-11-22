<?php
/** Serve the immutable signature snapshot attached to an issued certificate. */
declare(strict_types=1);
require_once __DIR__ . '/../../app/bootstrap.php';

require_login();
require_permission('certificates.history');
$id = get_int('id');
$issuance = $id === null ? null : db_one('SELECT chairman_signature_path FROM issuances WHERE issuance_id = ?', [$id]);
if ($issuance === null) { abort(404, 'That certificate was not found.'); }
$image = chairman_signature_read((string) ($issuance['chairman_signature_path'] ?? ''));
if ($image === null) { abort(404, 'No Chairman signature image was captured for this certificate.'); }
header('Content-Type: image/jpeg');
header('Content-Length: ' . strlen($image['bytes']));
header('Content-Disposition: inline; filename="chairman-signature-' . (int) $id . '.jpg"');
header('Cache-Control: private, no-store, max-age=0');
header('X-Content-Type-Options: nosniff');
echo $image['bytes'];
