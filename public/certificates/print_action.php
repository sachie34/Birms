<?php
/** Explicit, CSRF-protected certificate print action. Preview and PDF download do not increment print_count. */
declare(strict_types=1);
require_once __DIR__ . '/../../app/bootstrap.php';

require_login();
require_permission('certificates.history');
require_permission('certificates.print');
if (!is_post()) { abort(405, 'Use the Print button on the certificate preview.'); }
require_csrf();

$id = post_int('id');
if ($id === null || $id < 1) { abort(400, 'A valid certificate ID is required.'); }

$printed = db_transaction(function () use ($id): bool {
    $certificate = db_one('SELECT issuance_id, reference_no FROM issuances WHERE issuance_id = ? FOR UPDATE', [$id]);
    if ($certificate === null) { return false; }
    db_exec('UPDATE issuances SET print_count = print_count + 1, last_printed_at = NOW() WHERE issuance_id = ?', [$id]);
    audit('certificate_printed', 'Certificates', 'Success',
        (string) $certificate['reference_no'] . ' explicit Print button action',
        'issuance', $id);
    return true;
});
if (!$printed) { abort(404, 'That certificate was not found.'); }
redirect('certificates/print.php', ['id' => $id, 'print' => '1']);
