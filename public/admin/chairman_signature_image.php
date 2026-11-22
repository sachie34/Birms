<?php
/** Private preview of the current Chairman signature for authorized settings users. */
declare(strict_types=1);
require_once __DIR__ . '/../../app/bootstrap.php';

require_login();
require_permission('settings.view');
$image = chairman_signature_read(chairman_signature_current_relative());
if ($image === null) { abort(404, 'No valid Chairman signature image is configured.'); }
header('Content-Type: image/jpeg');
header('Content-Length: ' . strlen($image['bytes']));
header('Content-Disposition: inline; filename="chairman-signature.jpg"');
header('Cache-Control: private, no-store, max-age=0');
header('X-Content-Type-Options: nosniff');
echo $image['bytes'];
