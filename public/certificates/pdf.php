<?php
/** Download a server-generated PDF copy of an issued certificate. */
declare(strict_types=1);
require_once __DIR__ . '/../../app/bootstrap.php';

require_login();
require_permission('certificates.history');
$id = get_int('id');
$c = $id === null ? null : db_one('SELECT * FROM issuances WHERE issuance_id = ?', [$id]);
if ($c === null) { abort(404, 'That certificate was not found.'); }
$pdf = certificate_pdf_bytes($c);
if (!str_starts_with($pdf, '%PDF-')) { throw new RuntimeException('PDF generation did not return a valid PDF document.'); }
audit_best_effort('certificate_pdf_downloaded', 'Certificates', 'Success', (string) $c['reference_no'] . ' downloaded as PDF', 'issuance', (int) $id);
$name = preg_replace('/[^A-Za-z0-9._-]/', '_', (string) $c['reference_no']) ?: ('certificate_' . (int) $id);
header('Content-Type: application/pdf');
header('Content-Length: ' . strlen($pdf));
header('Content-Disposition: attachment; filename="' . $name . '.pdf"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');
echo $pdf;
exit;
