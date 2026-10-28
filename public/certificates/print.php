<?php
/** Printable certificate. Opening it as an issuer counts as a print and is audited. */
declare(strict_types=1);
require_once __DIR__ . '/../../app/bootstrap.php';

require_login();
require_permission('certificates.history');
$id = get_int('id');
$c = $id === null ? null : db_one('SELECT * FROM issuances WHERE issuance_id = ?', [$id]);
if ($c === null) { abort(404, 'That certificate was not found.'); }
if (can('certificates.print') || can('certificates.issue')) {
    db_exec('UPDATE issuances SET print_count = print_count + 1, last_printed_at = NOW() WHERE issuance_id = ?', [$id]);
    audit_best_effort('certificate_printed', 'Certificates', 'Success', $c['reference_no'] . ' opened for printing', null);
    $c['print_count'] = (int) $c['print_count'] + 1;
}
$asset = fn(string $p): string => e(base_url('assets/' . $p));
$signatureDisplay = trim((string) ($c['signature_text'] ?? ''));
if ($signatureDisplay === '') { $signatureDisplay = (string) ($c['captain_name'] ?? ''); }
?>
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e((string) $c['reference_no']) ?> | <?= e((string) config('app_name')) ?></title>
<link rel="stylesheet" href="<?= $asset('vendor/bootstrap/css/bootstrap.min.css') ?>"><link rel="stylesheet" href="<?= $asset('vendor/bootstrap-icons/bootstrap-icons.min.css') ?>"><link rel="stylesheet" href="<?= $asset('css/birms.css') ?>"></head>
<body><div class="cert-wrap">
  <div class="cert-bar no-print">
    <a class="btn btn-light border" href="<?= e(base_url('certificates/history.php')) ?>"><i class="bi bi-arrow-left me-1"></i>Back</a>
    <a class="btn btn-light border" href="<?= e(base_url('certificates/pdf.php?id=' . (int) $c['issuance_id'])) ?>"><i class="bi bi-file-earmark-pdf me-1"></i>Download PDF</a>
    <span class="text-muted small align-self-center">Printed <?= (int) $c['print_count'] ?> time(s). Letterhead and layout are a provisional default until approved by the Barangay Secretary.</span>
    <button type="button" class="btn btn-primary" data-print><i class="bi bi-printer me-1"></i>Print</button>
  </div>
  <div class="cert-sheet">
    <div class="cert-draft">DRAFT — PENDING OFFICIAL LAYOUT APPROVAL</div>
    <div class="cert-head"><div class="l1">Republic of the Philippines</div><div class="l1">Province of <?= e((string) setting('province', '')) ?> · Municipality of <?= e((string) setting('municipality', '')) ?></div><div class="l3"><?= e(barangay_title()) ?></div></div>
    <div class="cert-title"><?= e((string) $c['type_name']) ?></div>
    <div class="cert-body"><?= e((string) $c['body_text']) ?></div>
    <div class="cert-sign"><span class="name"><?= e($signatureDisplay) ?>&nbsp;</span><span class="role">Typed signature — not digitally verified</span></div>
    <div class="cert-foot"><span>Ref. <?= e((string) $c['reference_no']) ?> · Issued <?= e(date('j F Y', strtotime((string) $c['issued_at']))) ?> by <?= e((string) $c['issued_by_name']) ?></span><span><?= ((float) $c['fee_amount'] > 0 || $c['or_number'] !== null) ? 'Recorded fee: ' . e(money($c['fee_amount'])) . ($c['or_number'] !== null ? ' · O.R. No. ' . e((string) $c['or_number']) : '') : 'No fee' ?></span></div>
  </div>
</div>
<script src="<?= $asset('vendor/bootstrap/js/bootstrap.bundle.min.js') ?>"></script><script src="<?= $asset('js/app.js') ?>"></script>
</body></html>
