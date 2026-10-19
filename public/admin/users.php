<?php
/** User accounts list (Administrator only). */
declare(strict_types=1);
require_once __DIR__ . '/../../app/bootstrap.php';

$me = require_login();
require_permission('users.manage');

$q = get_str('q'); $role = get_str('role'); $status = get_str('status');
$role = in_array($role, ROLES, true) ? $role : '';
$status = in_array($status, ['active', 'inactive'], true) ? $status : '';
$page = max(1, get_int('page') ?? 1); $per = 25;

$w = []; $p = [];
if ($q !== '')      { $w[] = '(username LIKE ? OR full_name LIKE ?)'; $like = '%' . addcslashes($q, '%_\\') . '%'; array_push($p, $like, $like); }
if ($role !== '')   { $w[] = 'role = ?'; $p[] = $role; }
if ($status !== '') { $w[] = 'status = ?'; $p[] = $status; }
$where = $w ? 'WHERE ' . implode(' AND ', $w) : '';

$total = (int) db_val("SELECT COUNT(*) FROM users $where", $p);
$rows = db_all("SELECT user_id, username, full_name, role, status, last_login_at FROM users $where ORDER BY status = 'active' DESC, full_name LIMIT " . (int) $per . ' OFFSET ' . (int) (($page - 1) * $per), $p);

render_header('User Accounts', [['User Accounts', null]], 'users');
?>
<div class="page-head">
  <div><h1>User Accounts</h1><p>Create accounts, assign roles and deactivate people who no longer need access.</p></div>
  <a class="btn btn-primary" href="<?= e(base_url('admin/user_edit.php')) ?>"><i class="bi bi-plus-lg me-1"></i>New user</a>
</div>
<div class="card-b">
  <form method="get" class="filters" action="<?= e(base_url('admin/users.php')) ?>">
    <div><label class="form-label" for="q">Search</label><input class="form-control" id="q" name="q" value="<?= e($q) ?>" placeholder="Name or username" maxlength="60"></div>
    <div><label class="form-label" for="role">Role</label><select class="form-select" id="role" name="role"><option value="">All roles</option><?php foreach (ROLES as $r): ?><option value="<?= e($r) ?>"<?= $role === $r ? ' selected' : '' ?>><?= e(role_label($r)) ?></option><?php endforeach; ?></select></div>
    <div><label class="form-label" for="status">Status</label><select class="form-select" id="status" name="status"><option value="">All</option><option value="active"<?= $status === 'active' ? ' selected' : '' ?>>Active</option><option value="inactive"<?= $status === 'inactive' ? ' selected' : '' ?>>Inactive</option></select></div>
    <div><button class="btn btn-light border" type="submit"><i class="bi bi-funnel me-1"></i>Filter</button></div>
  </form>
  <?php if (!$rows): ?>
    <div class="empty-state"><i class="bi bi-people"></i>No users match these filters.</div>
  <?php else: ?>
  <div class="table-responsive"><table class="table table-b">
    <thead><tr><th>Name</th><th>Role</th><th>Status</th><th>Last sign-in</th><th class="text-end">Actions</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
      <tr>
        <td><strong><?= e((string) $r['full_name']) ?></strong><div class="small text-muted"><?= e((string) $r['username']) ?><?= (int) $r['user_id'] === (int) $me['user_id'] ? ' · you' : '' ?></div></td>
        <td><?= pill(role_label((string) $r['role']), 'blue') ?></td>
        <td><?= $r['status'] === 'active' ? pill('Active', 'green') : pill('Inactive', 'grey') ?></td>
        <td><?= e(fmt_dt($r['last_login_at'])) ?></td>
        <td class="text-end"><a class="btn btn-sm btn-light border" href="<?= e(base_url('admin/user_edit.php?id=' . (int) $r['user_id'])) ?>">Manage</a></td>
      </tr>
    <?php endforeach; ?>
    </tbody></table></div>
  <?php render_pagination($total, $page, $per, 'admin/users.php', array_filter(['q' => $q, 'role' => $role, 'status' => $status])); endif; ?>
</div>
<?php render_footer();
