<?php
/** Printable issued certificate. Authorized print roles are counted and audited separately from issuance. */
declare(strict_types=1);
require_once __DIR__ . '/../../app/bootstrap.php';

require_login();
require_permission('certificates.history');
$id = get_int('id');
$c = $id === null ? null : db_one('SELECT * FROM issuances WHERE issuance_id = ?', [$id]);
if ($c === null) { abort(404, 'That certificate was not found.'); }
$canPrint = can('certificates.print');
if (is_post()) {
    require_csrf();
    if (!$canPrint) { abort(403, 'You are not allowed to print this certificate.'); }
    if (post_str('do') !== 'record_print') { abort(400, 'Unknown print action.'); }
    $printCount = db_transaction(function () use ($id, $c): int {
        db_exec('UPDATE issuances SET print_count = print_count + 1, last_printed_at = NOW() WHERE issuance_id = ?', [$id]);
        audit('certificate_printed', 'Certificates', 'Success', $c['reference_no'] . ' printed', 'issuance', $id);
        return (int) db_val('SELECT print_count FROM issuances WHERE issuance_id = ?', [$id]);
    });
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(['ok' => true, 'print_count' => $printCount], JSON_THROW_ON_ERROR);
    exit;
}
$asset = fn(string $p): string => e(base_url('assets/' . $p));
$signatureDisplay = trim((string) ($c['captain_name'] ?? ''));
$signatureUrl = !empty($c['chairman_signature_path'])
    ? base_url('certificates/signature_image.php?id=' . (int) $c['issuance_id'])
    : '';
?>
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e((string) $c['reference_no']) ?> | <?= e((string) config('app_name')) ?></title>
<link rel="stylesheet" href="<?= $asset('vendor/bootstrap/css/bootstrap.min.css') ?>"><link rel="stylesheet" href="<?= $asset('vendor/bootstrap-icons/bootstrap-icons.min.css') ?>"><link rel="stylesheet" href="<?= $asset('css/birms.css') ?>"></head>
<body class="<?= $canPrint ? '' : 'certificate-preview-only' ?>"><div class="cert-wrap">
  <div class="cert-bar no-print">
    <a class="btn btn-light border" href="<?= e(base_url('certificates/history.php')) ?>"><i class="bi bi-arrow-left me-1"></i>Back</a>
    <?php if ($canPrint): ?>
      <a class="btn btn-light border" href="<?= e(base_url('certificates/pdf.php?id=' . (int) $c['issuance_id'])) ?>"><i class="bi bi-file-earmark-pdf me-1"></i>Download PDF</a>
      <span class="text-muted small align-self-center"><span data-print-count>Printed <?= (int) $c['print_count'] ?> time(s).</span> Letterhead and layout are a provisional default until approved by the Barangay Secretary.</span>
      <form id="certificate-print-form" class="d-none" method="post" action="<?= e(base_url('certificates/print.php?id=' . (int) $c['issuance_id'])) ?>"><?= csrf_field() ?><input type="hidden" name="do" value="record_print"></form>
      <button type="button" class="btn btn-primary" data-print data-print-record-form="certificate-print-form"><i class="bi bi-printer me-1"></i>Print</button>
    <?php else: ?>
      <span class="text-muted small align-self-center"><i class="bi bi-eye me-1"></i>Preview only. Printing and PDF download are not available for this role.</span>
    <?php endif; ?>
  </div>
  <div class="cert-sheet">
    <div class="cert-draft">DRAFT — PENDING OFFICIAL LAYOUT APPROVAL</div>
    <div class="cert-head"><div class="l1">Republic of the Philippines</div><div class="l1">Province of <?= e((string) setting('province', '')) ?> · Municipality of <?= e((string) setting('municipality', '')) ?></div><div class="l3"><?= e(barangay_title()) ?></div></div>
    <div class="cert-title"><?= e((string) $c['type_name']) ?></div>
    <div class="cert-body"><?= certificate_body_html((string) $c['body_text']) ?></div>
    <div class="cert-sign">
      <?php if ($signatureUrl !== ''): ?><img class="chairman-signature-image" src="<?= e($signatureUrl) ?>" alt="Stored Chairman / Punong Barangay signature"><?php endif; ?>
      <span class="name"><?= e($signatureDisplay) ?>&nbsp;</span><span class="role">Chairman / Punong Barangay</span>
    </div>
    <div class="cert-foot"><span>Ref. <?= e((string) $c['reference_no']) ?> · Issued <?= e(date('j F Y', strtotime((string) $c['issued_at']))) ?> by <?= e((string) $c['issued_by_name']) ?></span><span><?= ((float) $c['fee_amount'] > 0 || $c['or_number'] !== null) ? 'Recorded fee: ' . e(money($c['fee_amount'])) . ($c['or_number'] !== null ? ' · O.R. No. ' . e((string) $c['or_number']) : '') : 'No fee' ?></span></div>
  </div>
</div>
<script src="<?= $asset('vendor/bootstrap/js/bootstrap.bundle.min.js') ?>"></script><script src="<?= $asset('js/app.js') ?>"></script>
</body></html>
