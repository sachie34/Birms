<?php
/** Data-backed demographic, issuance and blotter reports. */
declare(strict_types=1);
require_once __DIR__ . '/../../app/bootstrap.php';

require_login();
$types = [
    'demographics' => 'Demographic report',
    'issuance' => 'Issuance summary',
    'blotter' => 'Blotter summary',
];
$type = get_str('type');
if (!isset($types[$type])) { $type = 'demographics'; }
$isCsv = get_str('format') === 'csv';
require_permission($isCsv ? 'reports.export' : 'reports.view');

$range = report_period_range(get_str('period'), get_int('year'), get_int('month'), get_int('quarter'));
$data = match ($type) {
    'demographics' => report_demographics(),
    'issuance' => report_issuances($range),
    'blotter' => report_blotters($range),
};
$reportLabel = $types[$type];
$periodLabel = $type === 'demographics' ? date('j F Y') . ' (current active resident records)' : $range['label'];
$audit_best_effort($isCsv ? 'report_exported' : 'report_generated', 'Reports', 'Success',
    $reportLabel . ($type === 'demographics' ? ' current snapshot' : ' for ' . $range['label']) . ($isCsv ? ' CSV' : ' viewed'));

if ($isCsv) {
    $filename = 'birms-' . $type . '-' . preg_replace('/[^A-Za-z0-9_-]+/', '-', strtolower($type === 'demographics' ? date('Y-m-d') : $range['label'])) . '.csv';
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('X-Content-Type-Options: nosniff');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    $write = static function (array $row) use ($out): void {
        $safe = array_map(static function ($v) {
            if (!is_string($v)) { return $v; }
            return preg_match('/^[=+@\t\r]/', $v) ? "'" . $v : $v;
        }, $row);
        fputcsv($out, $safe, ',', '"', '\\');
    };
    if ($type === 'demographics') {
        $write(['Section', 'Category', 'Count']);
        $write(['Population', 'Current active residents', $data['total']]);
        foreach ([
            'Age bracket' => $data['age_brackets'], 'Sex' => $data['sex'], 'Civil status' => $data['civil_status'],
            'Employment status' => $data['employment_status'], 'Voter status' => $data['voter_status'], 'Population by Purok' => $data['purok'],
        ] as $section => $items) {
            foreach ($items as $category => $count) { $write([$section, $category, $count]); }
        }
    } elseif ($type === 'issuance') {
        $write(['Report period', $range['label']]);
        $write(['Document type', 'Issued count', 'Recorded issuance fees (PHP)']);
        foreach ($data['by_type'] as $row) { $write([$row['type_name'], (int) $row['issued_count'], number_format((float) $row['recorded_fees'], 2, '.', '')]); }
        $write(['TOTAL', (int) $data['summary']['issued_count'], number_format((float) $data['summary']['recorded_fees'], 2, '.', '')]);
    } else {
        $s = $data['summary'];
        $write(['Report period', $range['label']]);
        $write(['Section', 'Category', 'Count / rate']);
        $write(['Cases', 'Total non-archived incidents', (int) $s['total']]);
        $write(['Cases', 'Pending', (int) $s['pending_count']]);
        $write(['Cases', 'Active', (int) $s['active_count']]);
        $write(['Cases', 'Scheduled', (int) $s['scheduled_count']]);
        $write(['Cases', 'Resolved', (int) $s['resolved_count']]);
        $write(['Cases', 'Resolution rate (%)', number_format((float) $s['resolution_rate'], 1, '.', '')]);
        foreach ([
            'Incident type' => $data['by_type'], 'Purok' => $data['by_purok'], 'Status' => $data['by_status'],
        ] as $section => $rows) {
            foreach ($rows as $row) {
                $category = $row['incident_type'] ?? $row['purok'] ?? $row['status'] ?? 'Not specified';
                $count = $row['case_count'] ?? $row['count'] ?? 0;
                $write([$section, $category, (int) $count]);
            }
        }
    }
    fclose($out);
    exit;
}

$months = [];
for ($m = 1; $m <= 12; $m++) { $months[$m] = date('F', mktime(0, 0, 0, $m, 1, 2000)); }
$quarters = [1 => 'Quarter 1 (Jan–Mar)', 2 => 'Quarter 2 (Apr–Jun)', 3 => 'Quarter 3 (Jul–Sep)', 4 => 'Quarter 4 (Oct–Dec)'];
$period = $range['period'];
$filterQuery = ['type' => $type, 'period' => $period, 'year' => $range['year']];
if ($period === 'month') { $filterQuery['month'] = $range['month']; }
if ($period === 'quarter') { $filterQuery['quarter'] = $range['quarter']; }
$exportUrl = base_url('reports/index.php') . '?' . http_build_query($filterQuery + ['format' => 'csv']);

render_header('Reports & Analytics', [['Reports & Analytics', null]], 'reports');
page_head('Reports & Analytics', 'Generate demographic, issuance and blotter summaries from actual BIRMS records.',
    can('reports.export') ? '<a class="btn btn-primary" href="' . e($exportUrl) . '"><i class="bi bi-download me-1"></i>Export CSV</a>' : '');
?>
<div class="card-b mb-3">
  <div class="card-h"><h2>Report options</h2><p>Choose the report and, for issuance and incident reports, the reporting period.</p></div>
  <div class="card-body-b">
    <form method="get" action="<?= e(base_url('reports/index.php')) ?>" class="row g-2 align-items-end">
      <div class="col-md-4"><label class="form-label" for="reportType">Report</label>
        <select class="form-select" name="type" id="reportType">
          <?php foreach ($types as $key => $label): ?><option value="<?= e($key) ?>"<?= $key === $type ? ' selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-2"><label class="form-label" for="period">Period</label>
        <select class="form-select" name="period" id="period">
          <option value="month"<?= $period === 'month' ? ' selected' : '' ?>>Monthly</option>
          <option value="quarter"<?= $period === 'quarter' ? ' selected' : '' ?>>Quarterly</option>
          <option value="year"<?= $period === 'year' ? ' selected' : '' ?>>Annual</option>
        </select>
      </div>
      <div class="col-md-2"><label class="form-label" for="year">Year</label>
        <select class="form-select" name="year" id="year"><?php for ($y = (int) date('Y'); $y >= 2000; $y--): ?><option value="<?= $y ?>"<?= $y === (int) $range['year'] ? ' selected' : '' ?>><?= $y ?></option><?php endfor; ?></select>
      </div>
      <div class="col-md-2"><label class="form-label" for="month">Month</label>
        <select class="form-select" name="month" id="month"><?php foreach ($months as $m => $label): ?><option value="<?= $m ?>"<?= $m === (int) ($range['month'] ?? 0) ? ' selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select>
      </div>
      <div class="col-md-2"><label class="form-label" for="quarter">Quarter</label>
        <select class="form-select" name="quarter" id="quarter"><?php foreach ($quarters as $q => $label): ?><option value="<?= $q ?>"<?= $q === (int) ($range['quarter'] ?? 0) ? ' selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select>
      </div>
      <div class="col-12"><button class="btn btn-primary" type="submit"><i class="bi bi-bar-chart-line me-1"></i>Generate report</button></div>
    </form>
  </div>
</div>

<div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
  <div><h2 class="h4 mb-1"><?= e($reportLabel) ?></h2><div class="text-muted small">Period: <?= e($periodLabel) ?></div></div>
  <span class="badge text-bg-light border">Generated <?= e(date('d M Y, H:i')) ?></span>
</div>

<?php if ($type === 'demographics'): ?>
  <div class="row g-3 mb-3">
    <div class="col-md-4"><div class="card-b h-100"><div class="card-h"><h2>Current population</h2><p>Active, non-archived residents</p></div><div class="card-body-b"><div class="fs-2 fw-bold"><?= number_format((int) $data['total']) ?></div><div class="text-muted small">Based on saved resident profiles; relocated and archived profiles are excluded.</div></div></div></div>
    <div class="col-md-4"><div class="card-b h-100"><div class="card-h"><h2>Registered voters</h2></div><div class="card-body-b"><div class="fs-2 fw-bold"><?= number_format((int) ($data['voter_status']['Registered'] ?? 0)) ?></div><div class="text-muted small">Unverified: <?= number_format((int) ($data['voter_status']['Unverified'] ?? 0)) ?> · Not registered: <?= number_format((int) ($data['voter_status']['Not registered'] ?? 0)) ?></div></div></div></div>
    <div class="col-md-4"><div class="card-b h-100"><div class="card-h"><h2>Data classification</h2></div><div class="card-body-b"><div class="fs-2 fw-bold"><?= number_format(array_sum($data['employment_status'])) ?></div><div class="text-muted small">Active residents included in employment-status summary. Unspecified entries remain visible as “Not specified.”</div></div></div></div>
  </div>
  <?php foreach ([
      'Age brackets' => $data['age_brackets'], 'Sex' => $data['sex'], 'Civil status' => $data['civil_status'],
      'Employment status' => $data['employment_status'], 'Voter status' => $data['voter_status'], 'Population by Purok' => $data['purok'],
  ] as $section => $items): ?>
    <div class="card-b mb-3">
      <div class="card-h"><h2><?= e($section) ?></h2></div>
      <div class="card-body-b">
        <?php if (!$items): ?><?= empty_state('bi-bar-chart', 'No data is available for this summary yet.') ?>
        <?php else: ?><div class="table-responsive"><table class="table table-b mb-0"><thead><tr><th><?= e($section === 'Population by Purok' ? 'Purok' : 'Category') ?></th><th class="text-end">Residents</th><th class="text-end">Share</th></tr></thead><tbody>
          <?php foreach ($items as $label => $count): ?><tr><td><?= e((string) $label) ?></td><td class="text-end"><?= number_format((int) $count) ?></td><td class="text-end"><?= $data['total'] > 0 ? number_format(((int) $count / (int) $data['total']) * 100, 1) : '0.0' ?>%</td></tr><?php endforeach; ?>
        </tbody></table></div><?php endif; ?>
      </div>
    </div>
  <?php endforeach; ?>
  <div class="alert alert-info">The employment-status categories are managed under Settings → Other lists. The system does not assume a barangay-approved employment classification; residents without a selection are counted as “Not specified.”</div>

<?php elseif ($type === 'issuance'): ?>
  <div class="row g-3 mb-3">
    <div class="col-md-6"><div class="card-b"><div class="card-h"><h2>Documents issued</h2></div><div class="card-body-b"><div class="fs-2 fw-bold"><?= number_format((int) $data['summary']['issued_count']) ?></div><div class="text-muted small"><?= e($range['label']) ?></div></div></div></div>
    <div class="col-md-6"><div class="card-b"><div class="card-h"><h2>Recorded issuance fees</h2></div><div class="card-body-b"><div class="fs-2 fw-bold">₱<?= number_format((float) $data['summary']['recorded_fees'], 2) ?></div><div class="text-muted small">Sum of fee amounts saved in issued-document records; not a live payment-gateway total.</div></div></div></div>
  </div>
  <div class="card-b"><div class="card-h"><h2>Issuance by document type</h2></div><div class="card-body-b">
    <?php if (!$data['by_type']): ?><?= empty_state('bi-file-earmark', 'No documents were issued in this period.') ?>
    <?php else: ?><div class="table-responsive"><table class="table table-b mb-0"><thead><tr><th>Document type</th><th class="text-end">Issued</th><th class="text-end">Recorded fees</th></tr></thead><tbody>
      <?php foreach ($data['by_type'] as $row): ?><tr><td><?= e((string) $row['type_name']) ?></td><td class="text-end"><?= number_format((int) $row['issued_count']) ?></td><td class="text-end">₱<?= number_format((float) $row['recorded_fees'], 2) ?></td></tr><?php endforeach; ?>
    </tbody><tfoot><tr class="fw-semibold"><td>Total</td><td class="text-end"><?= number_format((int) $data['summary']['issued_count']) ?></td><td class="text-end">₱<?= number_format((float) $data['summary']['recorded_fees'], 2) ?></td></tr></tfoot></table></div><?php endif; ?>
  </div></div>

<?php else: ?>
  <?php $s = $data['summary']; ?>
  <div class="row g-3 mb-3">
    <?php foreach ([
      'Total incidents' => (int) $s['total'], 'Pending' => (int) $s['pending_count'], 'Active' => (int) $s['active_count'],
      'Scheduled' => (int) $s['scheduled_count'], 'Resolved' => (int) $s['resolved_count'],
    ] as $label => $count): ?><div class="col-6 col-lg"><div class="card-b h-100"><div class="card-h"><h2><?= e($label) ?></h2></div><div class="card-body-b"><div class="fs-3 fw-bold"><?= number_format($count) ?></div></div></div></div><?php endforeach; ?>
  </div>
  <div class="card-b mb-3"><div class="card-h"><h2>Resolution rate</h2></div><div class="card-body-b"><div class="fs-2 fw-bold"><?= number_format((float) $s['resolution_rate'], 1) ?>%</div><div class="text-muted small">Resolved cases divided by all non-archived cases recorded in the selected incident-date period.</div></div></div>
  <?php foreach ([
      'Common incident types' => ['key' => 'by_type', 'category' => 'incident_type', 'count' => 'case_count'],
      'Incidents by Purok' => ['key' => 'by_purok', 'category' => 'purok', 'count' => 'case_count'],
      'Incidents by status' => ['key' => 'by_status', 'category' => 'status', 'count' => 'case_count'],
  ] as $section => $opts): $items = $data[$opts['key']]; ?>
    <div class="card-b mb-3"><div class="card-h"><h2><?= e($section) ?></h2></div><div class="card-body-b">
      <?php if (!$items): ?><?= empty_state('bi-clipboard-data', 'No incidents were recorded in this period.') ?>
      <?php else: ?><div class="table-responsive"><table class="table table-b mb-0"><thead><tr><th>Category</th><th class="text-end">Cases</th><?php if ($opts['key'] === 'by_type'): ?><th class="text-end">Resolved</th><?php endif; ?></tr></thead><tbody>
        <?php foreach ($items as $row): ?><tr><td><?= e((string) $row[$opts['category']]) ?></td><td class="text-end"><?= number_format((int) $row[$opts['count']]) ?></td><?php if ($opts['key'] === 'by_type'): ?><td class="text-end"><?= number_format((int) $row['resolved_count']) ?></td><?php endif; ?></tr><?php endforeach; ?>
      </tbody></table></div><?php endif; ?>
    </div></div>
  <?php endforeach; ?>
  <div class="alert alert-info">Incident types are configured under Settings → Other lists. Existing cases without a classification appear as “Unclassified” rather than being guessed. Archived cases are excluded from this report.</div>
<?php endif; ?>

<?php render_footer();