<?php
/** POST-only Staff/Admin review of resident-submitted blotter requests. */
declare(strict_types=1);
require_once __DIR__ . '/../../app/bootstrap.php';

require_login();
if (!is_post()) { abort(405, 'Blotter request actions must use POST.'); }
require_csrf();
require_permission('blotter_requests.review');

$requestId = post_int('request_id');
$do = post_str('do');
$note = post_str('note');
if ($requestId === null || $requestId < 1 || !in_array($do, ['accept', 'reject'], true)) {
    abort(400, 'Choose a valid resident blotter request action.');
}
try {
    if ($do === 'accept') {
        $blotterId = resident_blotter_request_accept($requestId, $note);
        flash_add('success', 'Resident report accepted and official blotter case created.');
        redirect('blotters/view.php', ['id' => $blotterId]);
    }
    resident_blotter_request_reject($requestId, $note);
    flash_add('success', 'Resident report rejected. The resident tracker now shows Dismissed.');
} catch (DomainException | InvalidArgumentException $e) {
    flash_add('danger', $e->getMessage());
} catch (RuntimeException $e) {
    flash_add('warning', $e->getMessage());
} catch (Throwable $e) {
    error_log('BIRMS resident blotter review failed: ' . get_class($e));
    flash_add('danger', 'The report could not be reviewed. No success was recorded; check the application log.');
}
redirect('blotter_requests/index.php');
