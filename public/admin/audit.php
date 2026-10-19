<?php
/** Audit Trail viewer (Administrator and Captain). */
declare(strict_types=1);
require_once __DIR__ . '/../../app/bootstrap.php';
require __DIR__ . '/../../app/audit_query.php';

require_login();
require_permission('audit.view');

[$where, $params, $f] = audit_build_query($_GET);
$page = max(1, get_int('page') ?? 1); $per = 25;
$total = (int) db_val("SELECT COUNT(*) FROM audit_log a $where", $params);
$rows = db_all("SELECT a.occurred_at, a.username_snapshot, a.action, a.module, a.status, a.remarks, a.ip_address FROM audit_log a $where ORDER BY a.audit_id DESC LIMIT " . (int) $per . ' OFFSET ' . (int) (($page - 1) * $per), $params);
$users = db_all('SELECT user_id, full_name FROM users ORDER BY full_name');
$modules = array_column(db_all('SELECT DISTINCT module FROM audit_log ORDER BY module'), 'module');
$query = array_filter($f, fn($x) => $x !== '');

render_header('Audit Trail', [['Audit Trail', null]], 'audit');
?>
<div class="page-head">
  <div><h1>Audit Trail</h1><p>Who did what, and when. Entries cannot be edited or deleted here.</p></div>
  <?php if (can('audit.export')): ?><a class="btn btn-light border" href="<?= e(base_url('admin/audit_export.php') . ($query ? '?' . http_build_query($query) : '')) ?>"><i class="bi bi-download me-1"></i>Export CSV</a><?php endif; ?>
</div>
<div class="card-b">
  <form method="get" class="filters" action="<?= e(base_url('admin/audit.php')) ?>">
    <div><label class="form-label" for="from">From</label><input class="form-control" type="date" id="from" name="from" value="<?= e($f['from']) ?>"></div>
    <div><label class="form-label" for="to">To</label><input class="form-control" type="date" id="to" name="to" value="<?= e($f['to']) ?>"></div>
    <div><label class="form-label" for="user">User</label><select class="form-select" id="user" name="user"><option value="">Anyone</option><?php foreach ($users as $u): ?><option value="<?= (int) $u['user_id'] ?>"<?= $f['user'] === (string) $u['user_id'] ? ' selected' : '' ?>><?= e((string) $u['full_name']) ?></option><?php endforeach; ?></select></div>
    <div><label class="form-label" for="module">Module</label><select class="form-select" id="module" name="module"><option value="">All</option><?php foreach ($modules as $m): ?><option value="<?= e((string) $m) ?>"<?= $f['module'] === $m ? ' selected' : '' ?>><?= e((string) $m) ?></option><?php endforeach; ?></select></div>
    <div><label class="form-label" for="status">Result</label><select class="form-select" id="status" name="status"><option value="">All</option><option value="Success"<?= $f['status'] === 'Success' ? ' selected' : '' ?>>Success</option><option value="Failed"<?= $f['status'] === 'Failed' ? ' selected' : '' ?>>Failed</option></select></div>
    <div><label class="form-label" for="q">Search</label><input class="form-control" id="q" name="q" value="<?= e($f['q']) ?>" maxlength="60" placeholder="Action or remarks"></div>
    <div><button class="btn btn-light border" type="submit"><i class="bi bi-funnel me-1"></i>Filter</button></div>
  </form>
  <?php if (!$rows): ?>
    <div class="empty-state"><i class="bi bi-journal-check"></i>No audit entries match these filters.</div>
  <?php else: ?>
  <div class="table-responsive"><table class="table table-b">
    <thead><tr><th>When</th><th>User</th><th>Action</th><th>Module</th><th>Result</th><th>Remarks</th><th>IP</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
      <tr>
        <td class="text-nowrap"><?= e(fmt_dt((string) $r['occurred_at'])) ?></td>
        <td><?= e((string) ($r['username_snapshot'] ?? 'system')) ?></td>
        <td><?= e((string) $r['action']) ?></td>
        <td><?= e((string) $r['module']) ?></td>
        <td><?= $r['status'] === 'Success' ? pill('Success', 'green') : pill('Failed', 'red') ?></td>
        <td><?= e((string) $r['remarks']) ?></td>
        <td class="text-muted small"><?= e((string) $r['ip_address']) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody></table></div>
  <?php render_pagination($total, $page, $per, 'admin/audit.php', $query); endif; ?>
</div>
<?php render_footer();
