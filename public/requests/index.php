<?php
/** Request Tracker: every request from filing to completion. */
declare(strict_types=1);
require_once __DIR__ . '/../../app/bootstrap.php';

$me = require_login();
require_permission('requests.view');

$role = (string) $me['role'];
$myLabel = match ($role) {
    'admin' => 'Needs Action',
    'captain' => 'Pending Final Approval',
    'secretary' => 'Needs Review',
    default => 'My Requests',
};
$myHint = match ($role) {
    'admin' => 'Review new requests, check requirements, or make final decisions when requirements are complete.',
    'captain' => 'Only requests already reviewed with complete requirements belong in your final-decision queue.',
    'secretary' => 'Check submitted requests and record whether the required documents are complete. Final approval belongs to the Captain or Administrator.',
    default => 'Submit requests and track their progress here. You cannot complete the formal requirements review or make the final decision.',
};
$tabs = ['all' => 'All requests', 'my' => $myLabel, 'Pending' => 'Pending', 'Review' => 'Under Review', 'Ready' => 'Ready for Pickup', 'Claimed' => 'Issued', 'closed' => 'Closed'];
$tab = get_str('tab'); $tab = isset($tabs[$tab]) ? $tab : 'all';
$q = get_str('q'); $page = max(1, get_int('page') ?? 1); $per = 25;

$countScope = '';
$countParams = [];
if ($role === 'clerk') {
    $countScope = ' WHERE (created_by = ? OR assigned_to = ?)';
    $countParams = [(int) $me['user_id'], (int) $me['user_id']];
}
$counts = ['all' => 0];
foreach (db_all('SELECT status, COUNT(*) AS n FROM document_requests' . $countScope . ' GROUP BY status', $countParams) as $c) {
    $counts[$c['status']] = (int) $c['n'];
    $counts['all'] += (int) $c['n'];
    if (in_array($c['status'], ['Rejected', 'Cancelled'], true)) {
        $counts['closed'] = ($counts['closed'] ?? 0) + (int) $c['n'];
    }
}
$myParams = [];
if ($role === 'admin') {
    // Admin can do both the Secretary's requirements review and final-decision work.
    $myCondition = "dr.status IN ('Pending', 'Review')";
} elseif ($role === 'captain') {
    // Captain's final-decision queue only includes requests with completed requirements.
    $myCondition = "dr.status = 'Review' AND dr.requirements_status = 'Complete'";
} elseif ($role === 'secretary') {
    $myCondition = "(dr.status = 'Pending' OR (dr.status = 'Review' AND dr.requirements_status <> 'Complete'))";
} else {
    $myCondition = '(dr.created_by = ? OR dr.assigned_to = ?)';
    $myParams = [(int) $me['user_id'], (int) $me['user_id']];
}
$counts['my'] = (int) db_val('SELECT COUNT(*) FROM document_requests dr WHERE ' . $myCondition, $myParams);

$w = []; $p = [];
// Restrict every Clerk view (all/status/search/pagination), not only the My Requests tab.
if ($role === 'clerk') {
    $w[] = '(dr.created_by = ? OR dr.assigned_to = ?)';
    array_push($p, (int) $me['user_id'], (int) $me['user_id']);
}
if ($tab === 'my') {
    if ($role !== 'clerk') {
        $w[] = '(' . $myCondition . ')';
        array_push($p, ...$myParams);
    }
} elseif ($tab === 'closed') {
    $w[] = "dr.status IN ('Rejected','Cancelled')";
} elseif ($tab !== 'all') {
    $w[] = 'dr.status = ?';
    $p[] = $tab;
}
foreach (preg_split('/[\s,]+/u', $q, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $tok) {
    $like = '%' . addcslashes($tok, '%_\\') . '%';
    $w[] = '(dr.reference_no LIKE ? OR r.last_name LIKE ? OR r.first_name LIKE ?)';
    array_push($p, $like, $like, $like);
}
$where = $w ? 'WHERE ' . implode(' AND ', $w) : '';
$from = "FROM document_requests dr JOIN residents r ON r.resident_id = dr.resident_id JOIN document_types dt ON dt.type_id = dr.type_id LEFT JOIN users u ON u.user_id = dr.assigned_to $where";
$total = (int) db_val("SELECT COUNT(*) $from", $p);
$rows = db_all("SELECT dr.request_id, dr.reference_no, dr.requested_at, dr.status, r.last_name, r.first_name, r.middle_name, r.suffix, dt.name AS type_name, u.full_name AS assigned $from ORDER BY dr.requested_at DESC, dr.request_id DESC LIMIT " . (int) $per . ' OFFSET ' . (int) (($page - 1) * $per), $p);

render_header('Request Tracker', [['Request Tracker', null]], 'requests');
page_head('Request Tracker', 'Viewing as ' . role_label($role) . '. Use “' . $myLabel . '” for the work relevant to your role.', can('requests.add') ? btn_link('requests/form.php', 'New request', 'btn-primary', 'bi-plus-lg') : '');
?>
<div class="alert alert-info d-flex gap-2 align-items-start" role="status">
  <i class="bi bi-info-circle mt-1" aria-hidden="true"></i>
  <div><strong>Your next step</strong><div><?= e($myHint) ?></div></div>
</div>
<?php
echo '<ul class="nav nav-pills mb-3" aria-label="Request filters">';
foreach ($tabs as $k => $lab) {
    $count = (int) ($counts[$k] ?? 0);
    echo '<li class="nav-item"><a class="nav-link' . ($k === $tab ? ' active' : '') . '" href="' . e(base_url('requests/index.php?tab=' . $k)) . '"' . ($k === $tab ? ' aria-current="page"' : '') . '>' . e($lab) . ' <span class="badge text-bg-light">' . $count . '</span></a></li>';
}
echo '</ul>';
?>
<div class="card-b">
  <form method="get" class="filters" action="<?= e(base_url('requests/index.php')) ?>"><input type="hidden" name="tab" value="<?= e($tab) ?>"><div><label class="form-label" for="q">Search</label><input class="form-control" id="q" name="q" value="<?= e($q) ?>" maxlength="60" placeholder="Reference or resident name"></div><div><button class="btn btn-light border" type="submit">Search</button></div></form>
  <?php if (!$rows): ?>
    <div class="p-4 text-center">
      <div class="fs-2 text-muted mb-2"><i class="bi bi-inbox" aria-hidden="true"></i></div>
      <h2 class="h6">No requests in this view</h2>
      <p class="text-muted mb-3"><?= $tab === 'my' ? 'There is nothing waiting for your role right now.' : 'Try another status filter or search term.' ?></p>
      <?php if ($tab !== 'all'): ?><a class="btn btn-sm btn-light border" href="<?= e(base_url('requests/index.php?tab=all')) ?>">View all requests</a><?php endif; ?>
      <?php if (can('requests.add')): ?><a class="btn btn-sm btn-primary" href="<?= e(base_url('requests/form.php')) ?>">Submit a request</a><?php endif; ?>
    </div>
  <?php else: ?>
  <div class="table-responsive"><table class="table table-b"><thead><tr><th>Reference</th><th>Requester</th><th>Document</th><th>Submitted</th><th>Time since submission</th><th>Status</th><th>Assigned to</th></tr></thead><tbody>
  <?php foreach ($rows as $r):
      $submittedAt = new DateTimeImmutable((string) $r['requested_at']);
      $daysSinceSubmitted = max(0, (int) $submittedAt->diff(new DateTimeImmutable())->format('%r%a'));
      $ageLabel = $daysSinceSubmitted === 0 ? 'Today' : ($daysSinceSubmitted === 1 ? '1 day ago' : $daysSinceSubmitted . ' days ago');
  ?>
    <tr>
      <td><a class="fw-semibold text-decoration-none" href="<?= e(base_url('requests/view.php?id=' . (int) $r['request_id'])) ?>"><?= e((string) $r['reference_no']) ?></a></td>
      <td class="fw-semibold"><?= e(resident_name($r)) ?></td><td><?= e((string) $r['type_name']) ?></td><td class="text-nowrap"><?= e(fmt_dt((string) $r['requested_at'])) ?></td>
      <td class="text-nowrap"><?= e($ageLabel) ?></td><td><?= status_pill((string) $r['status']) ?></td><td><?= e((string) ($r['assigned'] ?? '—')) ?></td>
    </tr>
  <?php endforeach; ?></tbody></table></div>
  <?php render_pagination($total, $page, $per, 'requests/index.php', array_filter(['tab' => $tab === 'all' ? '' : $tab, 'q' => $q])); endif; ?>
</div>
<p class="small text-muted mt-2 mb-0">“Time since submission” measures from the original submission date, not the time spent at the current review step.</p>
<?php render_footer();