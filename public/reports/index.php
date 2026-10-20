<?php
/** B7 Reports: demographic, issuance, request and blotter summaries. */
declare(strict_types=1);
require_once __DIR__ . '/../../app/bootstrap.php';
require_once APP_ROOT . '/app/reports_lib.php';

require_login();
require_permission('reports.view');
$p = report_period($_GET);
$data = report_data($p);
$query = http_build_query(['period' => $p['period'], 'year' => $p['year'], 'month' => $p['month'], 'quarter' => $p['quarter']]);

render_header('Reports', [['Reports', null]], 'reports');
page_head('Reports', 'Summaries are calculated from the current BIRMS records for ' . report_period_label($p) . '.', can('reports.export') ? btn_link('reports/export.php?' . $query, 'Export CSV', 'btn-light border', 'bi-download') : '');
?>
<div class="card-b mb-3">
  <form method="get" class="filters" action="<?= e(base_url('reports/index.php')) ?>">
    <div><label class="form-label" for="period">Period</label><select class="form-select" id="period" name="period"><option value="month"<?= $p['period'] === 'month' ? ' selected' : '' ?>>Monthly</option><option value="quarter"<?= $p['period'] === 'quarter' ? ' selected' : '' ?>>Quarterly</option><option value="year"<?= $p['period'] === 'year' ? ' selected' : '' ?>>Annual</option></select></div>
    <div><label class="form-label" for="year">Year</label><select class="form-select" id="year" name="year"><?php foreach ($p['years'] as $y): ?><option value="<?= (int) $y ?>"<?= $p['year'] === $y ? ' selected' : '' ?>><?= (int) $y ?></option><?php endforeach; ?></select></div>
    <div><label class="form-label" for="month">Month</label><select class="form-select" id="month" name="month"><?php for ($m = 1; $m <= 12; $m++): ?><option value="<?= $m ?>"<?= $p['month'] === $m ? ' selected' : '' ?>><?= e(date('F', mktime(0, 0, 0, $m, 1))) ?></option><?php endfor; ?></select></div>
    <div><label class="form-label" for="quarter">Quarter</label><select class="form-select" id="quarter" name="quarter"><?php for ($q = 1; $q <= 4; $q++): ?><option value="<?= $q ?>"<?= $p['quarter'] === $q ? ' selected' : '' ?>>Q<?= $q ?></option><?php endfor; ?></select></div>
    <div><button class="btn btn-primary" type="submit"><i class="bi bi-funnel me-1"></i>Apply</button></div>
  </form>
</div>
<div class="row g-3 mb-3">
  <div class="col-md-3"><div class="card-b h-100"><div class="card-h"><h2>Active residents</h2></div><div class="card-body-b"><div class="fs-3 fw-bold text-primary"><?= (int) $data['population'] ?></div><div class="text-muted small">Current resident records</div></div></div></div>
  <div class="col-md-3"><div class="card-b h-100"><div class="card-h"><h2>Certificates issued</h2></div><div class="card-body-b"><div class="fs-3 fw-bold text-primary"><?= (int) $data['issuance_total'] ?></div><div class="text-muted small">During selected period</div></div></div></div>
  <div class="col-md-3"><div class="card-b h-100"><div class="card-h"><h2>Fees recorded</h2></div><div class="card-body-b"><div class="fs-3 fw-bold text-primary">₱<?= e(number_format((float) $data['fees_total'], 2)) ?></div><div class="text-muted small">From issued certificates</div></div></div></div>
  <div class="col-md-3"><div class="card-b h-100"><div class="card-h"><h2>Blotter cases</h2></div><div class="card-body-b"><div class="fs-3 fw-bold text-primary"><?= (int) array_sum(array_map(fn($r) => (int) $r['total'], $data['blotters'])) ?></div><div class="text-muted small">During selected period</div></div></div></div>
</div>
<div class="row g-3">
  <div class="col-lg-6"><div class="card-b h-100"><div class="card-h"><h2>Demographic breakdown</h2><p>Active residents by age, sex, civil status, and voter field.</p></div><div class="card-body-b"><div class="row g-3"><div class="col-sm-6"><h3 class="h6">Age brackets</h3><?= report_table(['Age bracket', 'Residents'], array_map(fn($k, $v) => [$k, $v], array_keys($data['age']), array_values($data['age']))) ?></div><div class="col-sm-6"><h3 class="h6">Voter field</h3><?= report_table(['Status', 'Residents'], array_map(fn($k, $v) => [$k, $v], array_keys($data['voter']), array_values($data['voter']))) ?></div><div class="col-sm-6"><h3 class="h6">Sex</h3><?= report_table(['Sex', 'Residents'], array_map(fn($k, $v) => [$k, $v], array_keys($data['sex']), array_values($data['sex']))) ?></div><div class="col-sm-6"><h3 class="h6">Civil status</h3><?= report_table(['Status', 'Residents'], array_map(fn($k, $v) => [$k, $v], array_keys($data['civil']), array_values($data['civil']))) ?></div></div></div></div></div>
  <div class="col-lg-6"><div class="card-b h-100"><div class="card-h"><h2>Issuance summary</h2><p>Certificates and fees recorded for the selected period.</p></div><div class="card-body-b"><?= report_table(['Document', 'Issued', 'Fees'], array_map(fn($r) => [$r['type_name'], $r['total'], '₱' . number_format((float) $r['fees'], 2)], $data['issuances'])) ?></div></div></div>
  <div class="col-lg-6"><div class="card-b h-100"><div class="card-h"><h2>Request status summary</h2><p>Requests submitted during the selected period.</p></div><div class="card-body-b"><?= report_table(['Status', 'Requests'], array_map(fn($r) => [$r['status'], $r['total']], $data['requests'])) ?></div></div></div>
  <div class="col-lg-6"><div class="card-b h-100"><div class="card-h"><h2>Blotter summary by Purok</h2><p>Open and resolved incident records for the selected period.</p></div><div class="card-body-b"><?= report_table(['Purok', 'Cases'], array_map(fn($r) => [$r['purok'], $r['total']], $data['puroks'])) ?></div></div></div>
</div>
<?php
function report_table(array $headers, array $rows): string
{
    $h = '<div class="table-responsive"><table class="table table-b table-sm mb-0"><thead><tr>';
    foreach ($headers as $x) { $h .= '<th>' . e((string) $x) . '</th>'; }
    $h .= '</tr></thead><tbody>';
    if (!$rows) { return $h . '<tr><td colspan="' . count($headers) . '" class="text-muted">No records for this period.</td></tr></tbody></table></div>'; }
    foreach ($rows as $row) { $h .= '<tr>'; foreach ($row as $x) { $h .= '<td>' . e((string) $x) . '</td>'; } $h .= '</tr>'; }
    return $h . '</tbody></table></div>';
}
render_footer();
