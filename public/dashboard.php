<?php
/** Operations Overview. Shows only real data; modules appear here as later batches install them. */
declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';

$user = require_login();
require_permission('dashboard.view');

$menu = visible_menu();
$modules = array_values(array_filter($menu['Workspace'] ?? [], fn($i) => $i['key'] !== 'dashboard'));
$admin = array_merge($menu['Administration'] ?? []);
$recent = can('audit.view') ? db_all('SELECT occurred_at, username_snapshot, action, module, status, remarks FROM audit_log ORDER BY audit_id DESC LIMIT 8') : [];
$userCounts = can('users.manage') ? db_one("SELECT SUM(status = 'active') AS active_n, SUM(status = 'inactive') AS inactive_n FROM users") : null;
$activeResidents = (int) db_val("SELECT COUNT(*) FROM residents WHERE archived_at IS NULL AND residency_status = 'Active'");
$openRequests = (int) db_val("SELECT COUNT(*) FROM document_requests WHERE status IN ('Pending', 'Review', 'Ready')");
$monthIssuances = (int) db_val("SELECT COUNT(*) FROM issuances WHERE issued_at >= DATE_FORMAT(CURRENT_DATE, '%Y-%m-01') AND issued_at < DATE_FORMAT(CURRENT_DATE + INTERVAL 1 MONTH, '%Y-%m-01')");
$openBlotters = (int) db_val("SELECT COUNT(*) FROM blotters WHERE archived_at IS NULL AND status <> 'Resolved'");

render_header('Operations Overview', [['Operations Overview', null]], 'dashboard');
?>
<div class="page-head">
  <div><h1>Operations Overview</h1><p>Welcome, <?= e((string) $user['full_name']) ?>. You are signed in as <?= e(role_label((string) $user['role'])) ?>.</p></div>
</div>

<div class="row g-3 mb-3">
  <div class="col-sm-6 col-xl-3"><div class="card-b h-100"><div class="card-h"><h2>Active residents</h2></div><div class="card-body-b"><div class="fs-3 fw-bold text-primary"><?= $activeResidents ?></div><div class="text-muted small">Current resident records</div></div></div></div>
  <div class="col-sm-6 col-xl-3"><div class="card-b h-100"><div class="card-h"><h2>Open requests</h2></div><div class="card-body-b"><div class="fs-3 fw-bold text-primary"><?= $openRequests ?></div><div class="text-muted small">Pending, in review, or ready</div></div></div></div>
  <div class="col-sm-6 col-xl-3"><div class="card-b h-100"><div class="card-h"><h2>Issued this month</h2></div><div class="card-body-b"><div class="fs-3 fw-bold text-primary"><?= $monthIssuances ?></div><div class="text-muted small">Certificate issuances</div></div></div></div>
  <div class="col-sm-6 col-xl-3"><div class="card-b h-100"><div class="card-h"><h2>Open blotters</h2></div><div class="card-body-b"><div class="fs-3 fw-bold text-primary"><?= $openBlotters ?></div><div class="text-muted small">Unresolved case records</div></div></div></div>
</div>

<div class="row g-3">
  <div class="col-lg-7">
    <div class="card-b h-100">
      <div class="card-h"><h2>Your workspace</h2><p>The areas your role can use.</p></div>
      <div class="card-body-b">
        <?php $all = array_merge($modules, $admin); if (!$all): ?>
          <div class="empty-state"><i class="bi bi-box"></i>No modules are available to your role yet. They appear here as they are installed.</div>
        <?php else: foreach ($all as $it): ?>
          <a class="d-flex align-items-center gap-3 p-3 border rounded-3 mb-2 text-decoration-none text-reset" href="<?= e(base_url($it['path'])) ?>">
            <i class="bi <?= e($it['icon']) ?> fs-4 text-primary"></i><strong><?= e($it['label']) ?></strong><i class="bi bi-chevron-right ms-auto text-muted"></i>
          </a>
        <?php endforeach; endif; ?>
      </div>
    </div>
  </div>
  <div class="col-lg-5">
    <?php if ($userCounts): ?>
    <div class="card-b mb-3">
      <div class="card-h"><h2>User accounts</h2></div>
      <div class="card-body-b d-flex gap-4">
        <div><div class="fs-3 fw-bold"><?= (int) $userCounts['active_n'] ?></div><div class="text-muted small">Active</div></div>
        <div><div class="fs-3 fw-bold"><?= (int) $userCounts['inactive_n'] ?></div><div class="text-muted small">Inactive</div></div>
      </div>
    </div>
    <?php endif; ?>
    <div class="card-b">
      <div class="card-h"><h2>Recent activity</h2><p>Latest entries in the audit trail.</p></div>
      <div class="card-body-b">
        <?php if (!can('audit.view')): ?>
          <div class="empty-state"><i class="bi bi-lock"></i>Recent activity is visible to the Administrator and Captain.</div>
        <?php elseif (!$recent): ?>
          <div class="empty-state"><i class="bi bi-clock-history"></i>No activity recorded yet.</div>
        <?php else: foreach ($recent as $r): ?>
          <div class="d-flex justify-content-between border-bottom py-2">
            <div><strong><?= e((string) $r['action']) ?></strong> <span class="text-muted">· <?= e((string) ($r['username_snapshot'] ?? 'system')) ?></span><div class="small text-muted"><?= e((string) $r['module']) ?></div></div>
            <div class="text-end small text-muted"><?= e(fmt_dt((string) $r['occurred_at'])) ?></div>
          </div>
        <?php endforeach; endif; ?>
      </div>
    </div>
  </div>
</div>
<?php render_footer();
