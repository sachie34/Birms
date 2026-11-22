<?php
/** Staff/Admin-only PDF preview of an approved, saved certificate draft. */
declare(strict_types=1);
require_once __DIR__ . '/../../app/bootstrap.php';

require_login();
require_permission('certificates.issue');
$requestId = get_int('request');
if ($requestId === null) { abort(404, 'Certificate draft not found.'); }
$request = db_one('SELECT request_id, status FROM document_requests WHERE request_id = ?', [$requestId]);
$draft = certificate_draft_for_request($requestId);
if ($request === null || $request['status'] !== 'Ready' || $draft === null) {
    abort(404, 'Certificate draft not found or no longer awaiting release.');
}

try {
    $pdf = certificate_pdf_bytes([
        '_is_draft' => true,
        'reference_no' => (string) $draft['certificate_reference_no'],
        'issued_at' => (string) $draft['generated_at'],
        'fee_amount' => $draft['fee_amount'],
        'or_number' => null,
        'body_text' => (string) $draft['body_text'],
        'type_name' => (string) $draft['type_name'],
        'captain_name' => (string) ($draft['signature_text'] ?? ''),
        'chairman_signature_path' => $draft['chairman_signature_path'] === null ? null : (string) $draft['chairman_signature_path'],
    ]);
} catch (Throwable $e) {
    error_log('BIRMS certificate draft PDF preview failed for request ' . $requestId . ': ' . get_class($e) . ': ' . $e->getMessage());
    abort(500, 'The saved certificate draft PDF could not be generated. No issuance or payment was recorded.');
}

audit_best_effort('certificate_draft_previewed', 'Certificates', 'Success',
    'Previewed unissued certificate draft ' . (string) $draft['certificate_reference_no'] . '.');
$filename = preg_replace('/[^A-Za-z0-9_-]/', '_', (string) $draft['certificate_reference_no']) ?: 'certificate-draft';
header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="' . $filename . '-DRAFT.pdf"');
header('Content-Length: ' . strlen($pdf));
header('Cache-Control: private, no-store, max-age=0');
echo $pdf;
exit;
