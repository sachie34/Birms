<?php
/** Printable summons for a scheduled hearing. The official wording comes from Settings; nothing is guessed. */
declare(strict_types=1);
require_once __DIR__ . '/../../app/bootstrap.php';

require_login();
require_permission('hearings.view');
$hid = get_int('id');
$h = $hid === null ? null : db_one('SELECT h.hearing_id, h.scheduled_at, h.venue, h.status, h.summons_count, b.blotter_id, b.case_no, b.status AS case_status, b.archived_at
    FROM hearings h JOIN blotters b ON b.blotter_id = h.blotter_id WHERE h.hearing_id = ?', [$hid]);
if ($h === null) { abort(404, 'That hearing was not found.'); }
$case = ['case_no' => $h['case_no']];
$parties = case_parties((int) $h['blotter_id']);
$body = (string) setting('summons_wording', '');
$vars = summons_vars($case, $h, $parties);
$blockers = summons_blockers($body, $vars, $parties, $h);

if ($blockers) {
    render_header('Summons', [['Hearings', 'blotters/hearings.php'], ['Summons', null]], 'hearings');
    page_head('Summons cannot be printed yet', (string) $h['case_no']);
    echo '<div class="alert alert-warning"><ul class="mb-0">'; foreach ($blockers as $bl) { echo '<li>' . e($bl) . '</li>'; } echo '</ul></div>';
    echo btn_link('blotters/view.php?id=' . (int) $h['blotter_id'], 'Back to the case', 'btn-light border', 'bi-arrow-left');
    render_footer(); exit;
}
// Only people who schedule hearings count as printing it; a viewer opening the page does not.
if (can('hearings.add')) {
    db_transaction(function () use ($h): void {
        db_exec('UPDATE hearings SET summons_count = summons_count + 1 WHERE hearing_id = ?', [$h['hearing_id']]);
        audit('summons_printed', 'Blotter', 'Success', $h['case_no'] . ': summons for hearing of ' . date('j M Y g:i A', strtotime((string) $h['scheduled_at'])), 'blotter', (int) $h['blotter_id']);
    });
}
$asset = fn(string $p): string => e(base_url('assets/' . $p));
$text = render_template($body, $vars);
?>
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Summons <?= e((string) $h['case_no']) ?> | <?= e((string) config('app_name')) ?></title>
<link rel="stylesheet" href="<?= $asset('vendor/bootstrap/css/bootstrap.min.css') ?>"><link rel="stylesheet" href="<?= $asset('vendor/bootstrap-icons/bootstrap-icons.min.css') ?>"><link rel="stylesheet" href="<?= $asset('css/birms.css') ?>"></head>
<body>
  <div class="cert-wrap">
    <div class="cert-bar no-print"><a class="btn btn-light border" href="<?= e(base_url('blotters/view.php?id=' . (int) $h['blotter_id'])) ?>"><i class="bi bi-arrow-left me-1"></i>Back to the case</a>
      <button class="btn btn-primary" type="button" data-print><i class="bi bi-printer me-1"></i>Print</button>
      <span class="text-muted small">Provisional layout until the Barangay Secretary approves the official format.</span></div>
    <div class="cert-sheet">
      <div class="cert-head"><div class="l1">Republic of the Philippines</div><div class="l1">Province of <?= e($vars['province']) ?> · Municipality of <?= e($vars['municipality']) ?></div><div class="l3"><?= e(barangay_title()) ?></div></div>
      <div class="cert-title">SUMMONS</div>
      <div class="cert-body"><?= e($text) ?></div>
      <div class="cert-sign"><span class="name"><?= e($vars['captain_name']) ?>&nbsp;</span><span class="role"><?= $vars['captain_name'] !== '' ? 'Barangay Captain' : 'Authorized signatory' ?></span></div>
      <div class="cert-foot"><span>Case <?= e((string) $h['case_no']) ?> · Hearing <?= e(fmt_dt((string) $h['scheduled_at'])) ?></span><span>Printed <?= e(date('j F Y')) ?></span></div>
    </div>
  </div>
<script src="<?= $asset('vendor/bootstrap/js/bootstrap.bundle.min.js') ?>"></script><script src="<?= $asset('js/app.js') ?>"></script>
</body></html>
