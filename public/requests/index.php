<?php
/** Request Tracker: every request from filing to completion. */
declare(strict_types=1);
require_once __DIR__ . '/../../app/bootstrap.php';

require_login();
require_permission('requests.view');
$tabs = ['all' => 'All requests', 'Pending' => 'Pending', 'Review' => 'Under Review', 'Ready' => 'Ready for Pickup', 'Claimed' => 'Issued', 'closed' => 'Closed'];
$tab = get_str('tab'); $tab = isset($tabs[$tab]) ? $tab : 'all';
$q = get_str('q'); $page = max(1, get_int('page') ?? 1); $per = 25;

$counts = ['all' => 0]; foreach (db_all('SELECT status, COUNT(*) AS n FROM document_requests GROUP BY status') as $c) {
    $counts[$c['status']] = (int) $c['n']; $counts['all'] += (int) $c['n']; if (in_array($c['status'], ['Rejected', 'Cancelled'], true)) { $counts['closed'] = ($counts['closed'] ?? 0) + (int) $c['n']; }
}
$w = []; $p = [];
if ($tab === 'closed') { $w[] = "dr.status IN ('Rejected','Cancelled')"; } elseif ($tab !== 'all') { $w[] = 'dr.status = ?'; $p[] = $tab; }
foreach (preg_split('/[\s,]+/u', $q, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $tok) { $like = '%' . addcslashes($tok, '%_\\') . '%'; $w[] = '(dr.reference_no LIKE ? OR r.last_name LIKE ? OR r.first_name LIKE ?)'; array_push($p, $like, $like, $like); }
$where = $w ? 'WHERE ' . implode(' AND ', $w) : '';
$from = "FROM document_requests dr JOIN residents r ON r.resident_id = dr.resident_id JOIN document_types dt ON dt.type_id = dr.type_id LEFT JOIN users u ON u.user_id = dr.assigned_to $where";
$total = (int) db_val("SELECT COUNT(*) $from", $p);
$rows = db_all("SELECT dr.request_id, dr.reference_no, dr.requested_at, dr.status, r.last_name, r.first_name, r.middle_name, r.suffix, dt.name AS type_name, u.full_name AS assigned $from ORDER BY dr.requested_at DESC, dr.request_id DESC LIMIT " . (int) $per . ' OFFSET ' . (int) (($page - 1) * $per), $p);

render_header('Request Tracker', [['Request Tracker', null]], 'requests');
page_head('Request Tracker', 'Keep every request visible from intake to completion.', can('requests.add') ? btn_link('requests/form.php', 'New request', 'btn-primary', 'bi-plus-lg') : '');
echo '<ul class="nav nav-pills mb-3">';
foreach ($tabs as $k => $lab) { echo '<li class="nav-item"><a class="nav-link' . ($k === $tab ? ' active' : '') . '" href="' . e(base_url('requests/index.php?tab=' . $k)) . '">' . e($lab) . ' <span class="badge text-bg-light">' . (int) ($counts[$k] ?? 0) . '</span></a></li>'; }
echo '</ul>';
?>
<div class="card-b">
  <form method="get" class="filters" action="<?= e(base_url('requests/index.php')) ?>"><input type="hidden" name="tab" value="<?= e($tab) ?>"><div><label class="form-label" for="q">Search</label><input class="form-control" id="q" name="q" value="<?= e($q) ?>" maxlength="60" placeholder="Reference or resident name"></div><div><button class="btn btn-light border" type="submit">Search</button></div></form>
  <?php if (!$rows): ?><?= empty_state('bi-inbox', 'No requests here.') ?><?php else: ?>
  <div class="table-responsive"><table class="table table-b"><thead><tr><th>Reference</th><th>Requester</th><th>Document</th><th>Submitted</th><th>Status</th><th>Assigned to</th></tr></thead><tbody>
  <?php foreach ($rows as $r): ?><tr>
    <td><a class="fw-semibold text-decoration-none" href="<?= e(base_url('requests/view.php?id=' . (int) $r['request_id'])) ?>"><?= e((string) $r['reference_no']) ?></a></td>
    <td class="fw-semibold"><?= e(resident_name($r)) ?></td><td><?= e((string) $r['type_name']) ?></td><td class="text-nowrap"><?= e(fmt_dt((string) $r['requested_at'])) ?></td>
    <td><?= status_pill((string) $r['status']) ?></td><td><?= e((string) ($r['assigned'] ?? '—')) ?></td></tr><?php endforeach; ?></tbody></table></div>
  <?php render_pagination($total, $page, $per, 'requests/index.php', array_filter(['tab' => $tab === 'all' ? '' : $tab, 'q' => $q])); endif; ?>
</div>
<?php render_footer();
