<?php
/** Blotter tracker: every case from filing to resolution. */
declare(strict_types=1);
require_once __DIR__ . '/../../app/bootstrap.php';

require_login();
require_permission('blotters.view');
$canArch = can('blotters.archive');
$tabs = ['all' => 'All cases', 'Pending' => 'Pending', 'Active' => 'Active', 'Scheduled' => 'Scheduled', 'Resolved' => 'Resolved'] + ($canArch ? ['archived' => 'Archived'] : []);
$tab = get_str('tab'); $tab = isset($tabs[$tab]) ? $tab : 'all';
$q = get_str('q'); $page = max(1, get_int('page') ?? 1); $per = 25;

$counts = ['all' => 0, 'archived' => 0];
foreach (db_all('SELECT status, (archived_at IS NOT NULL) AS arch, COUNT(*) AS n FROM blotters GROUP BY status, arch') as $c) {
    if ((int) $c['arch'] === 1) { $counts['archived'] += (int) $c['n']; continue; }
    $counts[(string) $c['status']] = (int) $c['n']; $counts['all'] += (int) $c['n'];
}
$w = [$tab === 'archived' ? 'b.archived_at IS NOT NULL' : 'b.archived_at IS NULL']; $p = [];
if (!in_array($tab, ['all', 'archived'], true)) { $w[] = 'b.status = ?'; $p[] = $tab; }
foreach (name_tokens($q) as $tok) {
    $like = '%' . addcslashes($tok, '%_\\') . '%';
    $w[] = '(b.case_no LIKE ? OR EXISTS (SELECT 1 FROM blotter_parties px WHERE px.blotter_id = b.blotter_id AND px.full_name LIKE ?))';
    array_push($p, $like, $like);
}
$where = 'WHERE ' . implode(' AND ', $w);
$total = (int) db_val("SELECT COUNT(*) FROM blotters b $where", $p);
$rows = db_all("SELECT b.blotter_id, b.case_no, b.incident_date, b.status, pk.label AS purok,
    (SELECT GROUP_CONCAT(x.full_name ORDER BY x.party_id SEPARATOR ', ') FROM blotter_parties x WHERE x.blotter_id = b.blotter_id AND x.party_role = 'Complainant') AS complainants,
    (SELECT GROUP_CONCAT(y.full_name ORDER BY y.party_id SEPARATOR ', ') FROM blotter_parties y WHERE y.blotter_id = b.blotter_id AND y.party_role = 'Respondent') AS respondents,
    (SELECT MIN(h.scheduled_at) FROM hearings h WHERE h.blotter_id = b.blotter_id AND h.status = 'Scheduled') AS next_hearing
    FROM blotters b LEFT JOIN lookups pk ON pk.lookup_id = b.purok_id $where ORDER BY b.recorded_at DESC, b.blotter_id DESC LIMIT " . (int) $per . ' OFFSET ' . (int) (($page - 1) * $per), $p);

render_header('Blotter', [['Blotter', null]], 'blotters');
page_head('Blotter', 'Record incidents and keep every case visible until it is resolved.', can('blotters.add') ? btn_link('blotters/form.php', 'File a case', 'btn-primary', 'bi-plus-lg') : '');
echo '<ul class="nav nav-pills mb-3">';
foreach ($tabs as $k => $lab) { echo '<li class="nav-item"><a class="nav-link' . ($k === $tab ? ' active' : '') . '" href="' . e(base_url('blotters/index.php?tab=' . $k)) . '">' . e($lab) . ' <span class="badge text-bg-light">' . (int) ($counts[$k] ?? 0) . '</span></a></li>'; }
echo '</ul>';
?>
<div class="card-b">
  <form method="get" class="filters" action="<?= e(base_url('blotters/index.php')) ?>"><input type="hidden" name="tab" value="<?= e($tab) ?>">
    <div><label class="form-label" for="q">Search</label><input class="form-control" id="q" name="q" value="<?= e($q) ?>" maxlength="60" placeholder="Case number or a person's name"></div>
    <div><button class="btn btn-light border" type="submit"><i class="bi bi-search me-1"></i>Search</button></div>
  </form>
  <?php if (!$rows): ?><?= empty_state('bi-shield-exclamation', 'No cases here.') ?><?php else: ?>
  <div class="table-responsive"><table class="table table-b"><thead><tr><th>Case</th><th>Incident</th><th>Complainant</th><th>Respondent</th><th>Purok</th><th>Status</th><th>Next hearing</th></tr></thead><tbody>
  <?php foreach ($rows as $r): ?><tr>
    <td><a class="fw-semibold text-decoration-none" href="<?= e(base_url('blotters/view.php?id=' . (int) $r['blotter_id'])) ?>"><?= e((string) $r['case_no']) ?></a></td>
    <td class="text-nowrap"><?= e(date('d M Y', strtotime((string) $r['incident_date']))) ?></td>
    <td><?= e((string) ($r['complainants'] ?? '—')) ?></td><td><?= e((string) ($r['respondents'] ?? '—')) ?></td><td><?= e((string) ($r['purok'] ?? '—')) ?></td>
    <td><?= status_pill((string) $r['status']) ?></td><td class="text-nowrap"><?= e(fmt_dt($r['next_hearing'])) ?></td></tr><?php endforeach; ?></tbody></table></div>
  <?php render_pagination($total, $page, $per, 'blotters/index.php', array_filter(['tab' => $tab === 'all' ? '' : $tab, 'q' => $q])); endif; ?>
</div>
<?php render_footer();
