<?php
/** Blotter tracker: revised MSIT statuses, search, date and Purok filters. */
declare(strict_types=1);
require_once __DIR__ . '/../../app/bootstrap.php';

require_login();
require_permission('blotters.view');

$tab = get_str('tab');
if (!in_array($tab, array_merge(['all'], MSIT_BLOTTER_STATUSES), true)) { $tab = 'all'; }
$q = get_str('q');
$from = get_str('from');
$to = get_str('to');
$puroks = lookup_list('purok');
$purokId = get_int('purok');
if ($purokId === null || !isset($puroks[$purokId])) { $purokId = null; }
$page = max(1, get_int('page') ?? 1);
$per = 25;

$counts = ['all' => 0];
foreach (db_all('SELECT msit_status, COUNT(*) AS n FROM blotters GROUP BY msit_status') as $countRow) {
    $counts[(string) $countRow['msit_status']] = (int) $countRow['n'];
    $counts['all'] += (int) $countRow['n'];
}

$w = ['1=1']; $params = [];
if ($tab !== 'all') { $w[] = 'b.msit_status = ?'; $params[] = $tab; }
if ($from !== '' && v_date($from)) { $w[] = 'b.incident_date >= ?'; $params[] = $from; }
if ($to !== '' && v_date($to)) { $w[] = 'b.incident_date <= ?'; $params[] = $to; }
if ($purokId !== null) { $w[] = 'b.purok_id = ?'; $params[] = $purokId; }
foreach (name_tokens($q) as $token) {
    $like = '%' . addcslashes($token, '%_\\') . '%';
    $w[] = '(b.case_no LIKE ? OR EXISTS (SELECT 1 FROM blotter_parties px WHERE px.blotter_id = b.blotter_id AND px.full_name LIKE ?))';
    array_push($params, $like, $like);
}
$where = 'WHERE ' . implode(' AND ', $w);
$total = (int) db_val("SELECT COUNT(*) FROM blotters b $where", $params);
$rows = db_all(
    "SELECT b.blotter_id, b.case_no, b.incident_date, b.incident_time, b.msit_status, pk.label AS purok,
        (SELECT GROUP_CONCAT(x.full_name ORDER BY x.party_id SEPARATOR ', ') FROM blotter_parties x WHERE x.blotter_id = b.blotter_id AND x.party_role = 'Complainant') AS complainants,
        (SELECT GROUP_CONCAT(y.full_name ORDER BY y.party_id SEPARATOR ', ') FROM blotter_parties y WHERE y.blotter_id = b.blotter_id AND y.party_role = 'Respondent') AS respondents,
        (SELECT MIN(h.scheduled_at) FROM hearings h WHERE h.blotter_id = b.blotter_id AND h.status = 'Scheduled') AS next_hearing
     FROM blotters b
     LEFT JOIN lookups pk ON pk.lookup_id = b.purok_id AND pk.list_key = 'purok'
     $where
     ORDER BY b.incident_date DESC, b.incident_time DESC, b.blotter_id DESC
     LIMIT " . (int) $per . ' OFFSET ' . (int) (($page - 1) * $per),
    $params
);

render_header('Blotter', [['Blotter', null]], 'blotters');
page_head('Blotter', 'Track incidents using the revised Ongoing, Unresolved, Resolved, Dismissed and Archived statuses.',
    can('blotters.add') ? btn_link('blotters/form.php', 'File a case', 'btn-primary', 'bi-plus-lg') : '');
echo '<ul class="nav nav-pills mb-3">';
$tabs = ['all' => 'All cases'] + array_combine(MSIT_BLOTTER_STATUSES, MSIT_BLOTTER_STATUSES);
foreach ($tabs as $key => $label) {
    echo '<li class="nav-item"><a class="nav-link' . ($key === $tab ? ' active' : '') . '" href="' . e(base_url('blotters/index.php?tab=' . rawurlencode($key))) . '">'
        . e($label) . ' <span class="badge text-bg-light">' . (int) ($counts[$key] ?? 0) . '</span></a></li>';
}
echo '</ul>';
?>
<div class="card-b">
  <form method="get" class="filters" action="<?= e(base_url('blotters/index.php')) ?>">
    <input type="hidden" name="tab" value="<?= e($tab) ?>">
    <div><label class="form-label" for="q">Case number or person's name</label><input class="form-control" id="q" name="q" value="<?= e($q) ?>" maxlength="80" placeholder="Search blotter records"></div>
    <div><label class="form-label" for="from">Incident from</label><input class="form-control" type="date" id="from" name="from" value="<?= e($from) ?>"></div>
    <div><label class="form-label" for="to">Incident to</label><input class="form-control" type="date" id="to" name="to" value="<?= e($to) ?>"></div>
    <div><label class="form-label" for="purok">Purok</label><select class="form-select" id="purok" name="purok"><option value="">All Puroks</option>
      <?php foreach ($puroks as $id => $label): ?><option value="<?= (int) $id ?>"<?= $purokId === (int) $id ? ' selected' : '' ?>><?= e((string) $label) ?></option><?php endforeach; ?>
    </select></div>
    <div><button class="btn btn-light border" type="submit"><i class="bi bi-search me-1"></i>Filter</button></div>
  </form>
  <?php if (!$rows): ?><?= empty_state('bi-shield-exclamation', 'No cases match these filters.') ?><?php else: ?>
  <div class="table-responsive"><table class="table table-b"><thead><tr><th>Case</th><th>Incident date / time</th><th>Complainant</th><th>Respondent</th><th>Purok</th><th>MSIT status</th><th>Next hearing</th></tr></thead><tbody>
  <?php foreach ($rows as $row): ?>
    <tr>
      <td><a class="fw-semibold text-decoration-none" href="<?= e(base_url('blotters/view.php?id=' . (int) $row['blotter_id'])) ?>"><?= e((string) $row['case_no']) ?></a></td>
      <td class="text-nowrap"><?= e(date('d M Y', strtotime((string) $row['incident_date']))) ?><div class="small text-muted"><?= e(!empty($row['incident_time']) ? date('g:i A', strtotime((string) $row['incident_time'])) : 'Time not recorded') ?></div></td>
      <td><?= e((string) ($row['complainants'] ?? '—')) ?></td><td><?= e((string) ($row['respondents'] ?? '—')) ?></td>
      <td><?= e((string) ($row['purok'] ?? '—')) ?></td><td><?= status_pill((string) $row['msit_status']) ?></td>
      <td class="text-nowrap"><?= e(fmt_dt($row['next_hearing'])) ?></td>
    </tr>
  <?php endforeach; ?></tbody></table></div>
  <?php render_pagination($total, $page, $per, 'blotters/index.php', array_filter([
      'tab' => $tab === 'all' ? '' : $tab, 'q' => $q, 'from' => $from, 'to' => $to, 'purok' => $purokId,
  ])); endif; ?>
</div>
<?php render_footer();
