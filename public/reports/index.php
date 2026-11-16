<?php
/** Data-backed demographic, issuance, request-processing and blotter reports. */
declare(strict_types=1);
require_once __DIR__ . '/../../app/bootstrap.php';

require_login();
$types = [
    'demographics' => 'Demographic report',
    'issuance' => 'Issuance summary',
    'requests' => 'Request processing summary',
    'blotter' => 'Blotter summary',
    'venues' => 'Venue usage summary',
    'queues' => 'Queue utilization and waiting time',
    'periodic' => 'Periodic Administrative Report',
];
$type = get_str('type');
if (!isset($types[$type])) { $type = 'demographics'; }
$recipients = [
    'internal' => 'Internal administrative use',
    'dilg' => 'Department of the Interior and Local Government (DILG)',
    'mayor' => 'Office of the Mayor',
    'pnp' => 'Philippine National Police (PNP)',
];
$recipient = get_str('recipient');
if (!isset($recipients[$recipient])) { $recipient = 'internal'; }
$recipientLabel = $recipients[$recipient];
$isCsv = get_str('format') === 'csv';
$isExcel = get_str('format') === 'excel';
$isExport = $isCsv || $isExcel;
require_permission($isExport ? 'reports.export' : 'reports.view');

$anchorDate = get_str('anchor_date');
$range = report_period_range(get_str('period'), get_int('year'), get_int('month'), get_int('quarter'), $anchorDate);
$data = match ($type) {
    'demographics' => report_demographics(),
    'issuance' => report_issuances($range),
    'requests' => report_requests($range),
    'blotter' => report_blotters($range),
    'venues' => report_venues($range),
    'queues' => report_queueing($range),
    'periodic' => report_periodic_administrative($range),
};
$reportLabel = $types[$type];
$periodLabel = $type === 'demographics' ? date('j F Y') . ' (current active resident records)' : $range['label'];
audit_best_effort($isExport ? 'report_exported' : 'report_generated', 'Reports', 'Success',
    $reportLabel . ' for ' . $recipientLabel . ($type === 'demographics' ? ' current snapshot' : ' for ' . $range['label'])
    . ($isExcel ? ' Excel-compatible workbook' : ($isCsv ? ' CSV' : ' viewed')));

if ($isExport) {
    $stem = 'birms-' . $recipient . '-' . $type . '-' . preg_replace('/[^A-Za-z0-9_-]+/', '-', strtolower($type === 'demographics' ? date('Y-m-d') : $range['label']));
    if ($isExcel) {
        $filename = $stem . '.xls';
        header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: private, no-store');
        echo "\xEF\xBB\xBF";
        echo '<!doctype html><html><head><meta charset="UTF-8"><title>' . e($reportLabel) . '</title>'
            . '<style>body{font-family:Arial,sans-serif}table{border-collapse:collapse}td{border:1px solid #999;padding:5px;mso-number-format:"\\@";}tr:first-child td{font-weight:bold}</style>'
            . '</head><body><table>';
        $write = static function (array $row): void {
            echo '<tr>';
            foreach ($row as $cell) {
                $value = is_string($cell) ? $cell : (string) $cell;
                if (is_string($cell) && preg_match('/^\\s*[=+@-]/', $value) === 1) { $value = "'" . $value; }
                echo '<td>' . e($value) . '</td>';
            }
            echo '</tr>';
        };
    } else {
        $filename = $stem . '.csv';
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('X-Content-Type-Options: nosniff');
        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF");
        $write = static function (array $row) use ($out): void {
            fputcsv($out, array_map('csv_safe_cell', $row), ',', '"', '\\');
        };
    }
    $write(['Prepared for', $recipientLabel]);
    $write(['Template status', 'Draft standardized BIRMS summary; official agency-specific format pending recipient approval']);
    $write(['Report period', $periodLabel]);
    if ($type === 'demographics') {
        $write(['Section', 'Category', 'Count']);
        $write(['Population', 'Current active residents', $data['total']]);
        foreach ([
            'Age bracket' => $data['age_brackets'], 'Sex' => $data['sex'], 'Marital status' => $data['marital_status'],
            'Resident type' => $data['resident_type'], 'Employment status' => $data['employment_status'],
            'Voter status' => $data['voter_status'], 'Population by Purok' => $data['purok'],
        ] as $section => $items) {
            foreach ($items as $category => $count) { $write([$section, $category, $count]); }
        }
    } elseif ($type === 'issuance') {
        $write(['Report period', $range['label']]);
        $write(['Document type', 'Issued count', 'Recorded issuance fees (PHP)']);
        foreach ($data['by_type'] as $row) { $write([$row['type_name'], (int) $row['issued_count'], number_format((float) $row['recorded_fees'], 2, '.', '')]); }
        $write(['TOTAL', (int) $data['summary']['issued_count'], number_format((float) $data['summary']['recorded_fees'], 2, '.', '')]);
        $write([]);
        $write(['Recorded fee breakdown by saved category/tier', 'Category', 'Tier', 'Issued count', 'Recorded fees (PHP)']);
        foreach ($data['by_fee_rule'] as $row) {
            $write(['Fee category/tier', $row['fee_category'], $row['fee_tier'], (int) $row['issued_count'], number_format((float) $row['recorded_fees'], 2, '.', '')]);
        }
    } elseif ($type === 'requests') {
        $s = $data['summary'];
        $write(['Report', 'Request processing summary']);
        $write(['Report period', $range['label']]);
        $write(['Metric', 'Status / category', 'Count']);
        $write(['Requests', 'Total requests created in period', (int) $s['total']]);
        foreach ([
            'Pending' => $s['pending_count'], 'Approved' => $s['approved_count'],
            'Rejected' => $s['rejected_count'], 'Released' => $s['released_count'],
        ] as $status => $count) { $write(['Current status', $status, (int) $count]); }
        foreach ($data['by_type'] as $row) {
            $write(['Document type', $row['type_name'], (int) $row['request_count']]);
        }
        foreach ($data['by_assignee'] as $row) {
            $write(['Assigned to', $row['assigned_to'], (int) $row['request_count']]);
        }
        $write(['Note', 'Status reflects the saved current status when the report is generated.', '']);
    } elseif ($type === 'venues') {
        $s = $data['summary'];
        $write(['Report', 'Venue usage summary']);
        $write(['Report period (reservation date)', $range['label']]);
        foreach (['Total reservations'=>'total','Pending'=>'pending_count','Approved'=>'approved_count','Rejected'=>'rejected_count','Completed'=>'completed_count'] as $label => $key) {
            $write([$label, (int) $s[$key]]);
        }
        $write(['Booked hours (Approved and Completed)', number_format((float) $s['booked_hours'], 2, '.', '')]);
        $write([]);
        $write(['Venue', 'Reservations', 'Pending', 'Approved', 'Rejected', 'Completed', 'Booked hours']);
        foreach ($data['by_venue'] as $row) {
            $write([$row['venue_name'], (int) $row['reservation_count'], (int) $row['pending_count'],
                (int) $row['approved_count'], (int) $row['rejected_count'], (int) $row['completed_count'],
                number_format((float) $row['booked_hours'], 2, '.', '')]);
        }
        $write([]);
        $write(['Status', 'Reservations']);
        foreach ($data['by_status'] as $row) { $write([$row['status'], (int) $row['reservation_count']]); }
    } elseif ($type === 'queues') {
        $s = $data['summary'];
        $write(['Report', 'Queue utilization and waiting time']);
        $write(['Report period', $range['label']]);
        $write(['Metric', 'Count / duration']);
        foreach ([
            'Tickets issued' => 'total', 'Waiting' => 'waiting_count', 'Called' => 'called_count',
            'Served' => 'served_count', 'Skipped' => 'skipped_count', 'Cancelled' => 'cancelled_count',
        ] as $label => $key) { $write([$label, (int) $s[$key]]); }
        $write(['Average waiting time (minutes)', $s['average_wait_seconds'] === null ? 'Not available' : number_format((float) $s['average_wait_seconds'] / 60, 1, '.', '')]);
        $write([]);
        $write(['Service', 'Tickets issued', 'Waiting', 'Called', 'Served', 'Skipped', 'Cancelled', 'Average wait (minutes)']);
        foreach ($data['by_service'] as $row) {
            $write([$row['service'], (int) $row['total'], (int) $row['waiting_count'], (int) $row['called_count'],
                (int) $row['served_count'], (int) $row['skipped_count'], (int) $row['cancelled_count'],
                $row['average_wait_seconds'] === null ? 'Not available' : number_format((float) $row['average_wait_seconds'] / 60, 1, '.', '')]);
        }
    } elseif ($type === 'periodic') {
        $population = $data['population'];
        $issuance = $data['issuance'];
        $collections = $data['collections'];
        $requests = $data['requests'];
        $blotter = $data['blotter'];
        $queues = $data['queues'];
        $venues = $data['venues'];
        $write(['Section', 'Metric', 'Value']);
        $write(['Population', 'Current active resident snapshot (not historical population)', (int) $population['total']]);
        foreach ($population['purok'] as $purok => $count) { $write(['Population by Purok', $purok, (int) $count]); }
        foreach ($population['resident_type'] as $residentType => $count) { $write(['Population by resident type', $residentType, (int) $count]); }
        $write(['Certificates', 'Documents issued in selected period', (int) $issuance['summary']['issued_count']]);
        $write(['Certificates', 'Recorded issuance fee amounts (not confirmed collections)', number_format((float) $issuance['summary']['recorded_fees'], 2, '.', '')]);
        $write(['Collections', 'Reconciled cash-collection ledger amount', number_format((float) $collections['collected_amount'], 2, '.', '')]);
        $write(['Collections', 'Pending reconciliation amount', number_format((float) $collections['pending_amount'], 2, '.', '')]);
        $write(['Collections', 'Ledger entry count', (int) $collections['ledger_entry_count']]);
        $write(['Requests', 'Total requests created in selected period', (int) $requests['summary']['total']]);
        foreach (['Pending'=>'pending_count','Review'=>'review_count','Ready'=>'ready_count','Claimed'=>'claimed_count','Rejected'=>'rejected_count','Cancelled'=>'cancelled_count'] as $status => $key) {
            $write(['Requests by current status', $status, (int) $requests['summary'][$key]]);
        }
        foreach ($requests['by_type'] as $row) { $write(['Requests by document type', $row['type_name'], (int) $row['request_count']]); }
        $write(['Blotters', 'Total incidents in selected period', (int) $blotter['summary']['total']]);
        foreach (['Ongoing'=>'ongoing_count','Unresolved'=>'unresolved_count','Resolved'=>'resolved_count','Dismissed'=>'dismissed_count','Archived'=>'archived_count'] as $status => $key) {
            $write(['Blotters by status', $status, (int) $blotter['summary'][$key]]);
        }
        $write(['Blotters', 'Resolution rate among non-archived cases (%)', number_format((float) $blotter['summary']['resolution_rate'], 1, '.', '')]);
        foreach ($blotter['by_type'] as $row) { $write(['Blotters by incident type', $row['incident_type'], (int) $row['case_count']]); }
        foreach ($blotter['by_purok'] as $row) { $write(['Blotters by Purok', $row['purok'], (int) $row['case_count']]); }
        $write(['Queues', 'Total tickets issued', (int) $queues['summary']['total']]);
        foreach (['Waiting'=>'waiting_count','Called'=>'called_count','Served'=>'served_count','Skipped'=>'skipped_count','Cancelled'=>'cancelled_count'] as $status => $key) {
            $write(['Queue by status', $status, (int) $queues['summary'][$key]]);
        }
        $write(['Queues', 'Average wait in minutes', $queues['summary']['average_wait_seconds'] === null ? 'Not available' : number_format((float) $queues['summary']['average_wait_seconds'] / 60, 1, '.', '')]);
        foreach ($queues['by_service'] as $row) { $write(['Queue by service', $row['service'], (int) $row['total']]); }
        $write(['Venues', 'Total reservations in period', (int) $venues['summary']['total']]);
        foreach (['Pending'=>'pending_count','Approved'=>'approved_count','Rejected'=>'rejected_count','Completed'=>'completed_count'] as $status => $key) {
            $write(['Venue reservations by status', $status, (int) $venues['summary'][$key]]);
        }
        $write(['Venues', 'Booked hours (Approved and Completed)', number_format((float) $venues['summary']['booked_hours'], 2, '.', '')]);
        foreach ($venues['by_venue'] as $row) { $write(['Venue usage', $row['venue_name'], (int) $row['reservation_count']]); }
        $write(['Note', 'Request status counts are the current saved statuses at report time; official agency-specific templates remain pending approval.', '']);
    } else {
        $s = $data['summary'];
        $write(['Report period', $range['label']]);
        $write(['Section', 'Category', 'Count / rate']);
        $write(['Cases', 'Total incidents', (int) $s['total']]);
        foreach (['Ongoing'=>'ongoing_count','Unresolved'=>'unresolved_count','Resolved'=>'resolved_count','Dismissed'=>'dismissed_count','Archived'=>'archived_count'] as $status => $key) {
            $write(['Cases by MSIT status', $status, (int) $s[$key]]);
        }
        $write(['Cases', 'Resolution rate among non-archived cases (%)', number_format((float) $s['resolution_rate'], 1, '.', '')]);
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
    if ($isExcel) {
        echo '</table></body></html>';
    } else {
        fclose($out);
    }
    exit;
}

$months = [];
for ($m = 1; $m <= 12; $m++) { $months[$m] = date('F', mktime(0, 0, 0, $m, 1, 2000)); }
$quarters = [1 => 'Quarter 1 (Jan–Mar)', 2 => 'Quarter 2 (Apr–Jun)', 3 => 'Quarter 3 (Jul–Sep)', 4 => 'Quarter 4 (Oct–Dec)'];
$period = $range['period'];
$filterQuery = ['type' => $type, 'period' => $period, 'year' => $range['year'], 'recipient' => $recipient];
if ($period === 'month') { $filterQuery['month'] = $range['month']; }
if ($period === 'quarter') { $filterQuery['quarter'] = $range['quarter']; }
if (in_array($period, ['day', 'week'], true)) { $filterQuery['anchor_date'] = $anchorDate !== '' ? $anchorDate : date('Y-m-d'); }
$excelExportUrl = base_url('reports/index.php') . '?' . http_build_query($filterQuery + ['format' => 'excel']);
$csvExportUrl = base_url('reports/index.php') . '?' . http_build_query($filterQuery + ['format' => 'csv']);
$reportActions = '<button class="btn btn-light border" type="button" data-print><i class="bi bi-printer me-1"></i>Print / Save as PDF</button>';
if (can('reports.export')) {
    $reportActions .= ' <a class="btn btn-primary" href="' . e($excelExportUrl) . '"><i class="bi bi-file-earmark-spreadsheet me-1"></i>Export Excel</a>'
        . ' <a class="btn btn-light border" href="' . e($csvExportUrl) . '"><i class="bi bi-download me-1"></i>Export CSV</a>';
}

render_header('Reports & Analytics', [['Reports & Analytics', null]], 'reports');
page_head('Reports & Analytics', 'Generate data-backed administrative summaries. Select the intended recipient and reporting period.', $reportActions);
?>
<div class="card-b mb-3">
  <div class="card-h"><h2>Report options</h2><p>Choose the report and, for issuance and incident reports, the reporting period.</p></div>
  <div class="card-body-b">
    <form method="get" action="<?= e(base_url('reports/index.php')) ?>" class="row g-2 align-items-end">
      <div class="col-md-3"><label class="form-label" for="reportType">Report</label>
        <select class="form-select" name="type" id="reportType">
          <?php foreach ($types as $key => $label): ?><option value="<?= e($key) ?>"<?= $key === $type ? ' selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-3"><label class="form-label" for="recipient">Output recipient</label>
        <select class="form-select" name="recipient" id="recipient">
          <?php foreach ($recipients as $key => $label): ?><option value="<?= e($key) ?>"<?= $key === $recipient ? ' selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-2"><label class="form-label" for="period">Period</label>
        <select class="form-select" name="period" id="period">
          <option value="day"<?= $period === 'day' ? ' selected' : '' ?>>Daily</option>
          <option value="week"<?= $period === 'week' ? ' selected' : '' ?>>Weekly</option>
          <option value="month"<?= $period === 'month' ? ' selected' : '' ?>>Monthly</option>
          <option value="quarter"<?= $period === 'quarter' ? ' selected' : '' ?>>Quarterly</option>
          <option value="year"<?= $period === 'year' ? ' selected' : '' ?>>Annual</option>
        </select>
      </div>
      <div class="col-md-2"><label class="form-label" for="anchor_date">Date / week of</label><input class="form-control" type="date" name="anchor_date" id="anchor_date" value="<?= e($anchorDate !== '' ? $anchorDate : date('Y-m-d')) ?>"></div>
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
  <div><h2 class="h4 mb-1"><?= e($reportLabel) ?></h2><div class="text-muted small">Prepared for: <?= e($recipientLabel) ?> · Period: <?= e($periodLabel) ?></div></div>
  <span class="badge text-bg-light border">Generated <?= e(date('d M Y, H:i')) ?></span>
</div>

<div class="alert alert-warning">This is a draft standardized BIRMS summary for <?= e($recipientLabel) ?>. The official agency-specific form, required fields, and submission rules must be confirmed with the receiving office before this output is treated as an official report.</div>

<?php if ($type === 'demographics'): ?>
  <div class="row g-3 mb-3">
    <div class="col-md-4"><div class="card-b h-100"><div class="card-h"><h2>Current population</h2><p>Active, non-archived residents</p></div><div class="card-body-b"><div class="fs-2 fw-bold"><?= number_format((int) $data['total']) ?></div><div class="text-muted small">Based on saved resident profiles; relocated and archived profiles are excluded.</div></div></div></div>
    <div class="col-md-4"><div class="card-b h-100"><div class="card-h"><h2>Registered voters</h2></div><div class="card-body-b"><div class="fs-2 fw-bold"><?= number_format((int) ($data['voter_status']['Registered'] ?? 0)) ?></div><div class="text-muted small">Unverified: <?= number_format((int) ($data['voter_status']['Unverified'] ?? 0)) ?> · Not registered: <?= number_format((int) ($data['voter_status']['Not registered'] ?? 0)) ?></div></div></div></div>
    <div class="col-md-4"><div class="card-b h-100"><div class="card-h"><h2>Data classification</h2></div><div class="card-body-b"><div class="fs-2 fw-bold"><?= number_format(array_sum($data['employment_status'])) ?></div><div class="text-muted small">Active residents included in employment-status summary. Unspecified entries remain visible as “Not specified.”</div></div></div></div>
  </div>
  <?php foreach ([
      'Age brackets' => $data['age_brackets'], 'Sex' => $data['sex'], 'Marital status' => $data['marital_status'],
      'Resident type' => $data['resident_type'], 'Employment status' => $data['employment_status'],
      'Voter status' => $data['voter_status'], 'Population by Purok' => $data['purok'],
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
  <div class="alert alert-info">Resident types and statuses are managed under Settings → Other lists. The employment-status summary is retained as optional legacy data. The system does not assume a barangay-approved employment classification; residents without a selection are counted as “Not specified.”</div>

<?php elseif ($type === 'issuance'): ?>
  <div class="row g-3 mb-3">
    <div class="col-md-6"><div class="card-b"><div class="card-h"><h2>Documents issued</h2></div><div class="card-body-b"><div class="fs-2 fw-bold"><?= number_format((int) $data['summary']['issued_count']) ?></div><div class="text-muted small"><?= e($range['label']) ?></div></div></div></div>
    <div class="col-md-6"><div class="card-b"><div class="card-h"><h2>Recorded issuance fees</h2></div><div class="card-body-b"><div class="fs-2 fw-bold">₱<?= number_format((float) $data['summary']['recorded_fees'], 2) ?></div><div class="text-muted small">Sum of fee amounts saved in issued-document records; not a live payment-gateway total.</div></div></div></div>
  </div>
  <div class="card-b mb-3"><div class="card-h"><h2>Issuance by document type</h2></div><div class="card-body-b">
    <?php if (!$data['by_type']): ?><?= empty_state('bi-file-earmark', 'No documents were issued in this period.') ?>
    <?php else: ?><div class="table-responsive"><table class="table table-b mb-0"><thead><tr><th>Document type</th><th class="text-end">Issued</th><th class="text-end">Recorded fees</th></tr></thead><tbody>
      <?php foreach ($data['by_type'] as $row): ?><tr><td><?= e((string) $row['type_name']) ?></td><td class="text-end"><?= number_format((int) $row['issued_count']) ?></td><td class="text-end">₱<?= number_format((float) $row['recorded_fees'], 2) ?></td></tr><?php endforeach; ?>
    </tbody><tfoot><tr class="fw-semibold"><td>Total</td><td class="text-end"><?= number_format((int) $data['summary']['issued_count']) ?></td><td class="text-end">₱<?= number_format((float) $data['summary']['recorded_fees'], 2) ?></td></tr></tfoot></table></div><?php endif; ?>
  </div></div>
  <div class="card-b"><div class="card-h"><h2>Recorded fees by category and tier</h2><p>Amounts are read from saved issuance snapshots. Older issuances without a matrix snapshot appear as legacy fee entries.</p></div><div class="card-body-b">
    <?php if (!$data['by_fee_rule']): ?><?= empty_state('bi-cash-stack', 'No recorded issuance fees are available for this period.') ?>
    <?php else: ?><div class="table-responsive"><table class="table table-b mb-0"><thead><tr><th>Fee category</th><th>Fee tier</th><th class="text-end">Issued</th><th class="text-end">Recorded fees</th></tr></thead><tbody>
      <?php foreach ($data['by_fee_rule'] as $row): ?><tr><td><?= e((string) $row['fee_category']) ?></td><td><?= e((string) $row['fee_tier']) ?></td><td class="text-end"><?= number_format((int) $row['issued_count']) ?></td><td class="text-end">₱<?= number_format((float) $row['recorded_fees'], 2) ?></td></tr><?php endforeach; ?>
    </tbody></table></div><?php endif; ?>
  </div></div>
  <div class="card-b mt-3"><div class="card-h"><h2>Issuance by saved fee category and tier</h2><p>Based on fee category/tier captured on issued records. Amounts are recorded issuance amounts, not independently reconciled cash receipts.</p></div><div class="card-body-b">
    <?php if (!$data['by_fee_rule']): ?><?= empty_state('bi-cash-stack', 'No issuances were recorded in this period.') ?>
    <?php else: ?><div class="table-responsive"><table class="table table-b mb-0"><thead><tr><th>Fee category</th><th>Fee tier</th><th class="text-end">Issued</th><th class="text-end">Recorded fees</th></tr></thead><tbody>
      <?php foreach ($data['by_fee_rule'] as $row): ?><tr><td><?= e((string) $row['fee_category']) ?></td><td><?= e((string) $row['fee_tier']) ?></td><td class="text-end"><?= number_format((int) $row['issued_count']) ?></td><td class="text-end">₱<?= number_format((float) $row['recorded_fees'], 2) ?></td></tr><?php endforeach; ?>
    </tbody></table></div><?php endif; ?>
  </div></div>

<?php elseif ($type === 'requests'): ?>
  <?php $s = $data['summary']; ?>
  <div class="alert alert-info">This report counts requests created during <?= e($range['label']) ?>. Status counts reflect the current saved status when the report is generated, not a historical status snapshot.</div>
  <div class="row g-3 mb-3">
    <?php foreach ([
      'Total requests' => (int) $s['total'], 'Pending' => (int) $s['pending_count'],
      'Approved' => (int) $s['approved_count'], 'Rejected' => (int) $s['rejected_count'],
      'Released' => (int) $s['released_count'],
    ] as $label => $count): ?>
      <div class="col-6 col-lg-3"><div class="card-b h-100"><div class="card-h"><h2><?= e($label) ?></h2></div><div class="card-body-b"><div class="fs-3 fw-bold"><?= number_format($count) ?></div></div></div></div>
    <?php endforeach; ?>
  </div>
  <div class="card-b mb-3"><div class="card-h"><h2>Requests by document type</h2></div><div class="card-body-b">
    <?php if (!$data['by_type']): ?><?= empty_state('bi-inbox', 'No requests were filed in this period.') ?>
    <?php else: ?><div class="table-responsive"><table class="table table-b mb-0"><thead><tr><th>Document type</th><th class="text-end">Total</th><th class="text-end">Pending</th><th class="text-end">Approved</th><th class="text-end">Rejected</th><th class="text-end">Released</th></tr></thead><tbody>
      <?php foreach ($data['by_type'] as $row): ?><tr><td><?= e((string) $row['type_name']) ?></td><td class="text-end"><?= number_format((int) $row['request_count']) ?></td><td class="text-end"><?= number_format((int) $row['pending_count']) ?></td><td class="text-end"><?= number_format((int) $row['approved_count']) ?></td><td class="text-end"><?= number_format((int) $row['rejected_count']) ?></td><td class="text-end"><?= number_format((int) $row['released_count']) ?></td></tr><?php endforeach; ?>
    </tbody></table></div><?php endif; ?>
  </div></div>
  <div class="card-b mb-3"><div class="card-h"><h2>Requests by assignee</h2></div><div class="card-body-b">
    <?php if (!$data['by_assignee']): ?><?= empty_state('bi-people', 'No requests were assigned in this period.') ?>
    <?php else: ?><div class="table-responsive"><table class="table table-b mb-0"><thead><tr><th>Assigned to</th><th class="text-end">Requests</th></tr></thead><tbody>
      <?php foreach ($data['by_assignee'] as $row): ?><tr><td><?= e((string) $row['assigned_to']) ?></td><td class="text-end"><?= number_format((int) $row['request_count']) ?></td></tr><?php endforeach; ?>
    </tbody></table></div><?php endif; ?>
  </div></div>

<?php elseif ($type === 'venues'): ?>
  <?php $s = $data['summary']; ?>
  <div class="alert alert-info">Venue usage is grouped by the reservation date. Booked hours include reservations that are Approved or Completed; Pending and Rejected requests are shown separately and are not counted as used hours.</div>
  <div class="row g-3 mb-3">
    <?php foreach ([
      'Total reservations' => (int) $s['total'],
      'Approved' => (int) $s['approved_count'],
      'Completed' => (int) $s['completed_count'],
      'Booked hours' => number_format((float) $s['booked_hours'], 2),
    ] as $label => $value): ?>
      <div class="col-6 col-lg-3"><div class="card-b h-100"><div class="card-h"><h2><?= e($label) ?></h2></div><div class="card-body-b"><div class="fs-3 fw-bold"><?= e((string) $value) ?></div></div></div></div>
    <?php endforeach; ?>
  </div>
  <div class="card-b mb-3"><div class="card-h"><h2>Reservations by venue</h2><p>Counts use the current reservation status for each reservation date in the selected period.</p></div><div class="card-body-b">
    <?php if (!$data['by_venue']): ?><?= empty_state('bi-building', 'No venues or reservations are available for this report.') ?>
    <?php else: ?><div class="table-responsive"><table class="table table-b mb-0"><thead><tr><th>Venue</th><th class="text-end">Total</th><th class="text-end">Pending</th><th class="text-end">Approved</th><th class="text-end">Rejected</th><th class="text-end">Completed</th><th class="text-end">Booked hours</th></tr></thead><tbody>
      <?php foreach ($data['by_venue'] as $row): ?><tr>
        <td><?= e((string) $row['venue_name']) ?></td>
        <td class="text-end"><?= number_format((int) $row['reservation_count']) ?></td>
        <td class="text-end"><?= number_format((int) $row['pending_count']) ?></td>
        <td class="text-end"><?= number_format((int) $row['approved_count']) ?></td>
        <td class="text-end"><?= number_format((int) $row['rejected_count']) ?></td>
        <td class="text-end"><?= number_format((int) $row['completed_count']) ?></td>
        <td class="text-end"><?= number_format((float) $row['booked_hours'], 2) ?></td>
      </tr><?php endforeach; ?>
    </tbody></table></div><?php endif; ?>
  </div></div>
  <div class="card-b"><div class="card-h"><h2>Reservations by status</h2></div><div class="card-body-b">
    <?php if (!$data['by_status']): ?><?= empty_state('bi-calendar-check', 'No reservation status records are available for this period.') ?>
    <?php else: ?><div class="table-responsive"><table class="table table-b mb-0"><thead><tr><th>Status</th><th class="text-end">Reservations</th></tr></thead><tbody>
      <?php foreach ($data['by_status'] as $row): ?><tr><td><?= e((string) $row['status']) ?></td><td class="text-end"><?= number_format((int) $row['reservation_count']) ?></td></tr><?php endforeach; ?>
    </tbody></table></div><?php endif; ?>
  </div></div>

<?php elseif ($type === 'queues'): ?>
  <?php $s = $data['summary']; ?>
  <div class="alert alert-info">Queue totals are counted by ticket issue time. Average waiting time uses served tickets only; waiting, called, skipped and cancelled tickets are not included in that average.</div>
  <div class="row g-3 mb-3">
    <?php foreach ([
      'Tickets issued' => (int) $s['total'], 'Waiting' => (int) $s['waiting_count'], 'Called' => (int) $s['called_count'],
      'Served' => (int) $s['served_count'], 'Skipped' => (int) $s['skipped_count'], 'Cancelled' => (int) $s['cancelled_count'],
    ] as $label => $count): ?>
      <div class="col-6 col-lg-4"><div class="card-b h-100"><div class="card-h"><h2><?= e($label) ?></h2></div><div class="card-body-b"><div class="fs-3 fw-bold"><?= number_format($count) ?></div></div></div></div>
    <?php endforeach; ?>
    <div class="col-6 col-lg-4"><div class="card-b h-100"><div class="card-h"><h2>Average wait</h2></div><div class="card-body-b"><div class="fs-3 fw-bold"><?= $s['average_wait_seconds'] === null ? '—' : number_format((float) $s['average_wait_seconds'] / 60, 1) . ' min' ?></div></div></div></div>
  </div>
  <div class="card-b mb-3"><div class="card-h"><h2>Queue performance by service</h2></div><div class="card-body-b">
    <?php if (!$data['by_service']): ?><?= empty_state('bi-ticket', 'No queue tickets were issued in this period.') ?>
    <?php else: ?><div class="table-responsive"><table class="table table-b mb-0"><thead><tr><th>Service</th><th class="text-end">Issued</th><th class="text-end">Waiting</th><th class="text-end">Called</th><th class="text-end">Served</th><th class="text-end">Skipped</th><th class="text-end">Cancelled</th><th class="text-end">Average wait</th></tr></thead><tbody>
      <?php foreach ($data['by_service'] as $row): ?><tr><td><?= e((string) $row['service']) ?></td><td class="text-end"><?= number_format((int) $row['total']) ?></td><td class="text-end"><?= number_format((int) $row['waiting_count']) ?></td><td class="text-end"><?= number_format((int) $row['called_count']) ?></td><td class="text-end"><?= number_format((int) $row['served_count']) ?></td><td class="text-end"><?= number_format((int) $row['skipped_count']) ?></td><td class="text-end"><?= number_format((int) $row['cancelled_count']) ?></td><td class="text-end"><?= $row['average_wait_seconds'] === null ? '—' : number_format((float) $row['average_wait_seconds'] / 60, 1) . ' min' ?></td></tr><?php endforeach; ?>
    </tbody></table></div><?php endif; ?>
  </div></div>

<?php elseif ($type === 'periodic'): ?>
  <?php
    $population = $data['population'];
    $issuance = $data['issuance'];
    $collections = $data['collections'];
    $requests = $data['requests'];
    $blotter = $data['blotter'];
    $queues = $data['queues'];
  ?>
  <div class="alert alert-info">This is a consolidated BIRMS administrative summary for <?= e($range['label']) ?>. Resident population is a current snapshot, not a historical period-end count. Collected revenue includes reconciled cash-ledger rows only. Agency-specific forms and mandatory report fields still require confirmation by the receiving office.</div>
  <div class="row g-3 mb-3">
    <div class="col-md-3"><div class="card-b h-100"><div class="card-h"><h2>Current population</h2></div><div class="card-body-b"><div class="fs-3 fw-bold"><?= number_format((int) $population['total']) ?></div><small class="text-muted">Active, non-archived residents</small></div></div></div>
    <div class="col-md-3"><div class="card-b h-100"><div class="card-h"><h2>Documents issued</h2></div><div class="card-body-b"><div class="fs-3 fw-bold"><?= number_format((int) $issuance['summary']['issued_count']) ?></div><small class="text-muted"><?= e($range['label']) ?></small></div></div></div>
    <div class="col-md-3"><div class="card-b h-100"><div class="card-h"><h2>Revenue collected</h2></div><div class="card-body-b"><div class="fs-3 fw-bold">₱<?= number_format((float) $collections['collected_amount'], 2) ?></div><small class="text-muted">Reconciled ledger only</small></div></div></div>
    <div class="col-md-3"><div class="card-b h-100"><div class="card-h"><h2>Pending collection</h2></div><div class="card-body-b"><div class="fs-3 fw-bold">₱<?= number_format((float) $collections['pending_amount'], 2) ?></div><small class="text-muted">Awaiting reconciliation</small></div></div></div>
  </div>
  <div class="card-b mb-3">
    <div class="card-h"><h2>Current population by Purok</h2><p>Grouped from active resident records by Purok.</p></div>
    <div class="card-body-b">
      <?php if (!$population['purok']): ?><?= empty_state('bi-people', 'No active resident records are available.') ?>
      <?php else: ?><div class="table-responsive"><table class="table table-b mb-0"><thead><tr><th>Purok</th><th class="text-end">Residents</th></tr></thead><tbody>
        <?php foreach ($population['purok'] as $purok => $count): ?><tr><td><?= e((string) $purok) ?></td><td class="text-end"><?= number_format((int) $count) ?></td></tr><?php endforeach; ?>
      </tbody></table></div><?php endif; ?>
    </div>
  </div>
  <div class="card-b mb-3">
    <div class="card-h"><h2>Request queue summary</h2><p>Requests created in the selected period; displayed status counts reflect current status at generation time.</p></div>
    <div class="card-body-b"><div class="table-responsive"><table class="table table-b mb-0"><thead><tr><th>Status</th><th class="text-end">Requests</th></tr></thead><tbody>
      <?php foreach (['Pending'=>'pending_count','Approved'=>'approved_count','Rejected'=>'rejected_count','Released'=>'released_count'] as $status => $key): ?><tr><td><?= e($status) ?></td><td class="text-end"><?= number_format((int) $requests['summary'][$key]) ?></td></tr><?php endforeach; ?>
    </tbody></table></div></div>
  </div>
  <div class="row g-3 mb-3">
    <div class="col-lg-6"><div class="card-b h-100"><div class="card-h"><h2>Certificate issuance</h2><p>Recorded fee amounts are not proof of collected payments.</p></div><div class="card-body-b">
      <div class="mb-2">Issuances: <strong><?= number_format((int) $issuance['summary']['issued_count']) ?></strong></div>
      <div class="mb-2">Saved issuance fees: <strong>₱<?= number_format((float) $issuance['summary']['recorded_fees'], 2) ?></strong></div>
      <div>Reconciled cash ledger entries: <strong><?= number_format((int) $collections['ledger_entry_count']) ?></strong></div>
      <?php if (!$issuance['by_type']): ?><div class="text-muted mt-3">No certificates were issued in this period.</div><?php else: ?><div class="table-responsive mt-3"><table class="table table-b mb-0"><thead><tr><th>Document</th><th class="text-end">Issued</th></tr></thead><tbody><?php foreach ($issuance['by_type'] as $row): ?><tr><td><?= e((string) $row['type_name']) ?></td><td class="text-end"><?= number_format((int) $row['issued_count']) ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?>
    </div></div></div>
    <div class="col-lg-6"><div class="card-b h-100"><div class="card-h"><h2>Blotter summary</h2></div><div class="card-body-b">
      <div class="mb-2">Total incidents in period: <strong><?= number_format((int) $blotter['summary']['total']) ?></strong></div>
      <div class="mb-2">Resolved: <strong><?= number_format((int) $blotter['summary']['resolved_count']) ?></strong> · Dismissed: <strong><?= number_format((int) $blotter['summary']['dismissed_count']) ?></strong></div>
      <div class="mb-3">Resolution rate (non-archived): <strong><?= number_format((float) $blotter['summary']['resolution_rate'], 1) ?>%</strong></div>
      <div class="small text-muted">Ongoing <?= (int) $blotter['summary']['ongoing_count'] ?> · Unresolved <?= (int) $blotter['summary']['unresolved_count'] ?> · Archived <?= (int) $blotter['summary']['archived_count'] ?></div>
      <?php if (!$blotter['by_type']): ?><div class="text-muted">No incidents were recorded in this period.</div><?php else: ?><div class="table-responsive"><table class="table table-b mb-0"><thead><tr><th>Incident type</th><th class="text-end">Cases</th></tr></thead><tbody><?php foreach ($blotter['by_type'] as $row): ?><tr><td><?= e((string) $row['incident_type']) ?></td><td class="text-end"><?= number_format((int) $row['case_count']) ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?>
    </div></div></div>
  </div>
  <div class="card-b mb-3"><div class="card-h"><h2>Blotter cases by Purok</h2></div><div class="card-body-b">
    <?php if (!$blotter['by_purok']): ?><?= empty_state('bi-shield-exclamation', 'No cases were recorded in this period.') ?>
    <?php else: ?><div class="table-responsive"><table class="table table-b mb-0"><thead><tr><th>Purok</th><th class="text-end">Cases</th></tr></thead><tbody><?php foreach ($blotter['by_purok'] as $row): ?><tr><td><?= e((string) $row['purok']) ?></td><td class="text-end"><?= number_format((int) $row['case_count']) ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?>
  </div></div>
  <div class="card-b mb-3"><div class="card-h"><h2>Queue statistics</h2><p>Daily service queues summarized across the selected period.</p></div><div class="card-body-b">
    <div class="row g-3 mb-3">
      <div class="col-md-3"><div class="border rounded p-3"><div class="small text-muted">Tickets issued</div><div class="fs-4 fw-bold"><?= number_format((int) $queues['summary']['total']) ?></div></div></div>
      <div class="col-md-3"><div class="border rounded p-3"><div class="small text-muted">Served</div><div class="fs-4 fw-bold"><?= number_format((int) $queues['summary']['served_count']) ?></div></div></div>
      <div class="col-md-3"><div class="border rounded p-3"><div class="small text-muted">Waiting + called</div><div class="fs-4 fw-bold"><?= number_format((int) $queues['summary']['waiting_count'] + (int) $queues['summary']['called_count']) ?></div></div></div>
      <div class="col-md-3"><div class="border rounded p-3"><div class="small text-muted">Average wait</div><div class="fs-4 fw-bold"><?= $queues['summary']['average_wait_seconds'] === null ? '—' : number_format((float) $queues['summary']['average_wait_seconds'] / 60, 1) . ' min' ?></div></div></div>
    </div>
    <?php if (!$queues['by_service']): ?><?= empty_state('bi-ticket', 'No queue tickets were issued in this period.') ?>
    <?php else: ?><div class="table-responsive"><table class="table table-b mb-0"><thead><tr><th>Service</th><th class="text-end">Issued</th><th class="text-end">Served</th><th class="text-end">Average wait</th></tr></thead><tbody>
      <?php foreach ($queues['by_service'] as $row): ?><tr><td><?= e((string) $row['service']) ?></td><td class="text-end"><?= number_format((int) $row['total']) ?></td><td class="text-end"><?= number_format((int) $row['served_count']) ?></td><td class="text-end"><?= $row['average_wait_seconds'] === null ? '—' : number_format((float) $row['average_wait_seconds'] / 60, 1) . ' min' ?></td></tr><?php endforeach; ?>
    </tbody></table></div><?php endif; ?>
  </div></div>
<?php else: ?>
  <?php $s = $data['summary']; ?>
  <div class="row g-3 mb-3">
    <?php foreach ([
      'Total incidents' => (int) $s['total'], 'Ongoing' => (int) $s['ongoing_count'], 'Unresolved' => (int) $s['unresolved_count'],
      'Resolved' => (int) $s['resolved_count'], 'Dismissed' => (int) $s['dismissed_count'], 'Archived' => (int) $s['archived_count'],
    ] as $label => $count): ?><div class="col-6 col-lg-4"><div class="card-b h-100"><div class="card-h"><h2><?= e($label) ?></h2></div><div class="card-body-b"><div class="fs-3 fw-bold"><?= number_format($count) ?></div></div></div></div><?php endforeach; ?>
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