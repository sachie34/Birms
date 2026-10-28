<?php
/** Operations Overview with current indicators derived from saved BIRMS records. */
declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';

$user = require_login();
require_permission('dashboard.view');

$menu = visible_menu();
$modules = array_values(array_filter($menu['Workspace'] ?? [], fn($i) => $i['key'] !== 'dashboard'));
$adminMenu = $menu['Administration'] ?? [];

$today = new DateTimeImmutable('today');
$monthStart = $today->modify('first day of this month')->format('Y-m-d 00:00:00');
$nextMonth = $today->modify('first day of next month')->format('Y-m-d 00:00:00');

$residentCounts = can('residents.view') ? (db_one(
    "SELECT COUNT(*) AS total_n,
            COALESCE(SUM(residency_status = 'Active'), 0) AS active_n,
            COALESCE(SUM(residency_status = 'Relocated'), 0) AS relocated_n
     FROM residents WHERE archived_at IS NULL"
) ?? ['total_n' => 0, 'active_n' => 0, 'relocated_n' => 0]) : null;

$requestCounts = can('requests.view') ? (db_one(
    "SELECT COUNT(*) AS total_n,
            COALESCE(SUM(status IN ('Pending','Review','Ready')), 0) AS active_n,
            COALESCE(SUM(status = 'Claimed'), 0) AS claimed_n,
            COALESCE(SUM(status = 'Rejected'), 0) AS rejected_n
     FROM document_requests"
) ?? ['total_n' => 0, 'active_n' => 0, 'claimed_n' => 0, 'rejected_n' => 0]) : null;

$householdCounts = can('residents.view') ? (db_one(
    'SELECT COUNT(*) AS total_n FROM households'
) ?? ['total_n' => 0]) : null;

$monthlyIssuance = can('certificates.history') ? (db_one(
    'SELECT COUNT(*) AS issued_n FROM issuances WHERE issued_at >= ? AND issued_at < ?',
    [$monthStart, $nextMonth]
) ?? ['issued_n' => 0]) : null;

$monthlyCollections = can('reports.view')
    ? report_cash_collections(['start' => $monthStart, 'end' => $nextMonth])
    : null;

$blotterCounts = can('blotters.view') ? (db_one(
    "SELECT COUNT(*) AS total_n,
            COALESCE(SUM(status = 'Pending'), 0) AS pending_n,
            COALESCE(SUM(status = 'Active'), 0) AS active_n,
            COALESCE(SUM(status = 'Scheduled'), 0) AS scheduled_n,
            COALESCE(SUM(status = 'Resolved'), 0) AS resolved_n
     FROM blotters WHERE archived_at IS NULL"
) ?? ['total_n' => 0, 'pending_n' => 0, 'active_n' => 0, 'scheduled_n' => 0, 'resolved_n' => 0]) : null;

$populationByPurok = can('reports.view') ? db_all(
    "SELECT COALESCE(pk.label, 'Purok not assigned') AS label, COUNT(*) AS total_n
     FROM residents r
     LEFT JOIN households h ON h.household_id = r.household_id
     LEFT JOIN lookups pk ON pk.lookup_id = h.purok_id AND pk.list_key = 'purok'
     WHERE r.archived_at IS NULL AND r.residency_status = 'Active'
     GROUP BY COALESCE(pk.label, 'Purok not assigned')
     ORDER BY total_n DESC, label"
) : [];

$recent = can('audit.view') ? db_all('SELECT occurred_at, username_snapshot, action, module, status, remarks FROM audit_log ORDER BY audit_id DESC LIMIT 8') : [];
$userCounts = can('users.manage') ? (db_one("SELECT COALESCE(SUM(status = 'active'), 0) AS active_n, COALESCE(SUM(status = 'inactive'), 0) AS inactive_n FROM users") ?? ['active_n' => 0, 'inactive_n' => 0]) : null;

render_header('Operations Overview', [['Operations Overview', null]], 'dashboard');
?>
<div class="page-head">
  <div><h1>Operations Overview</h1><p>Welcome, <?= e((string) $user['full_name']) ?>. You are signed in as <?= e(role_label((string) $user['role'])) ?>.</p></div>
</div>

<div class="row g-3 mb-3">
  <?php if ($residentCounts !== null): ?>
  <div class="col-sm-6 col-xl-3"><div class="card-b h-100"><div class="card-h"><h2>Residents</h2><p>Current registry</p></div><div class="card-body-b"><div class="fs-2 fw-bold"><?= number_format((int) $residentCounts['total_n']) ?></div><div class="small text-muted"><?= number_format((int) $residentCounts['active_n']) ?> active · <?= number_format((int) $residentCounts['relocated_n']) ?> relocated</div></div></div></div>
  <?php endif; ?>
  <?php if ($householdCounts !== null): ?>
  <div class="col-sm-6 col-xl-3"><div class="card-b h-100"><div class="card-h"><h2>Households</h2><p>Registered household records</p></div><div class="card-body-b"><div class="fs-2 fw-bold"><?= number_format((int) $householdCounts['total_n']) ?></div><div class="small text-muted">Centralized household directory</div></div></div></div>
  <?php endif; ?>
  <?php if ($requestCounts !== null): ?>
  <div class="col-sm-6 col-xl-3"><div class="card-b h-100"><div class="card-h"><h2>Active requests</h2><p>Pending, under review or ready for pickup</p></div><div class="card-body-b"><div class="fs-2 fw-bold"><?= number_format((int) $requestCounts['active_n']) ?></div><div class="small text-muted"><?= number_format((int) $requestCounts['claimed_n']) ?> issued · <?= number_format((int) $requestCounts['rejected_n']) ?> rejected</div></div></div></div>
  <?php endif; ?>
  <?php if ($monthlyIssuance !== null): ?>
  <div class="col-sm-6 col-xl-3"><div class="card-b h-100"><div class="card-h"><h2>Certificates issued</h2><p><?= e($today->format('F Y')) ?></p></div><div class="card-body-b"><div class="fs-2 fw-bold"><?= number_format((int) $monthlyIssuance['issued_n']) ?></div><div class="small text-muted">Issued documents recorded this month</div></div></div></div>
  <?php endif; ?>
  <?php if ($monthlyCollections !== null): ?>
  <div class="col-sm-6 col-xl-3"><div class="card-b h-100"><div class="card-h"><h2>Revenue collected</h2><p><?= e($today->format('F Y')) ?> · reconciled ledger</p></div><div class="card-body-b"><div class="fs-2 fw-bold">₱<?= number_format((float) $monthlyCollections['collected_amount'], 2) ?></div><div class="small text-muted">Pending reconciliation: ₱<?= number_format((float) $monthlyCollections['pending_amount'], 2) ?></div></div></div></div>
  <?php endif; ?>
  <?php if ($blotterCounts !== null): ?>
  <div class="col-sm-6 col-xl-3"><div class="card-b h-100"><div class="card-h"><h2>Open blotter cases</h2><p>Not archived or resolved</p></div><div class="card-body-b"><div class="fs-2 fw-bold"><?= number_format((int) $blotterCounts['pending_n'] + (int) $blotterCounts['active_n'] + (int) $blotterCounts['scheduled_n']) ?></div><div class="small text-muted"><?= number_format((int) $blotterCounts['pending_n']) ?> pending · <?= number_format((int) $blotterCounts['scheduled_n']) ?> scheduled</div></div></div></div>
  <?php endif; ?>
</div>

<div class="card-b mb-3" id="quick-start">
  <div class="card-h">
    <h2>Start here: quick workflows</h2>
    <p>Choose the task you need. These shortcuts follow your current role's permissions.</p>
  </div>
  <div class="card-body-b">
    <div class="border rounded-3 p-3 mb-3">
      <strong>Document request flow</strong>
      <p class="mb-1 mt-1 small">Find or register resident <span class="text-muted">→</span> create request <span class="text-muted">→</span> complete requirements check <span class="text-muted">→</span> Administrator/Captain approval <span class="text-muted">→</span> Ready for Pickup <span class="text-muted">→</span> issue and print certificate.</p>
      <p class="mb-0 small text-muted">Incomplete requirements block issuance. Approval moves a request directly to Ready for Pickup; there is no separate saved “Approved” status. Issuance displays the final request status as Issued.</p>
    </div>
    <div class="row g-3">
      <?php if (can('residents.view')): ?>
      <div class="col-md-6 col-xl-4"><div class="border rounded-3 p-3 h-100">
        <div class="small text-muted mb-1">01 · RESIDENTS</div><h3 class="h6">Find or register a resident</h3>
        <p class="small text-muted">Search before adding a record to help avoid duplicates.</p>
        <a class="btn btn-sm btn-light border" href="<?= e(base_url('residents/index.php')) ?>">Open residents</a>
        <?php if (can('residents.add')): ?><a class="btn btn-sm btn-primary mt-1" href="<?= e(base_url('residents/form.php')) ?>">Register resident</a><?php endif; ?>
      </div></div>
      <?php endif; ?>

      <?php if (can('requests.view')): ?>
      <div class="col-md-6 col-xl-4"><div class="border rounded-3 p-3 h-100">
        <div class="small text-muted mb-1">02 · REQUESTS</div><h3 class="h6">Create or review a request</h3>
        <p class="small text-muted"><?= can('requests.add') ? 'Choose an existing active resident, then select the document and review its requirements.' : 'Open the queue to see requests and the next permitted steps.' ?></p>
        <a class="btn btn-sm btn-light border" href="<?= e(base_url('requests/index.php')) ?>">Open request queue</a>
        <?php if (can('requests.add')): ?><a class="btn btn-sm btn-primary mt-1" href="<?= e(base_url('requests/form.php')) ?>">New request</a><?php endif; ?>
        <?php if (can('requests.add')): ?><a class="btn btn-sm btn-light border mt-1" href="<?= e(base_url('visitors/index.php')) ?>">Visitor logbook</a><?php endif; ?>
      </div></div>
      <?php endif; ?>

      <?php if (can('certificates.issue') || can('certificates.history')): ?>
      <div class="col-md-6 col-xl-4"><div class="border rounded-3 p-3 h-100">
        <div class="small text-muted mb-1">03 · CERTIFICATES</div><h3 class="h6">Issue or find a certificate</h3>
        <p class="small text-muted"><?= can('certificates.issue') ? 'Only ready requests with complete requirements can be issued. Check the draft notice before printing.' : 'Search previously issued certificates by resident, reference or O.R. number.' ?></p>
        <?php if (can('certificates.issue')): ?><a class="btn btn-sm btn-primary" href="<?= e(base_url('certificates/issue.php')) ?>">Open ready requests</a><?php endif; ?>
        <?php if (can('certificates.history')): ?><a class="btn btn-sm btn-light border mt-1" href="<?= e(base_url('certificates/history.php')) ?>">Issuance history</a><?php endif; ?>
      </div></div>
      <?php endif; ?>

      <?php if (can('blotters.view')): ?>
      <div class="col-md-6 col-xl-4"><div class="border rounded-3 p-3 h-100">
        <div class="small text-muted mb-1">04 · BLOTTER</div><h3 class="h6">Record or follow up a case</h3>
        <p class="small text-muted">Open the case list to search, check status and review hearing dates.</p>
        <a class="btn btn-sm btn-light border" href="<?= e(base_url('blotters/index.php')) ?>">Open blotter</a>
        <?php if (can('blotters.add')): ?><a class="btn btn-sm btn-primary mt-1" href="<?= e(base_url('blotters/form.php')) ?>">File a case</a><?php endif; ?>
        <?php if (can('hearings.view')): ?><a class="btn btn-sm btn-light border mt-1" href="<?= e(base_url('blotters/hearings.php')) ?>">Hearings</a><?php endif; ?>
      </div></div>
      <?php endif; ?>

      <?php if (can('reports.view')): ?>
      <div class="col-md-6 col-xl-4"><div class="border rounded-3 p-3 h-100">
        <div class="small text-muted mb-1">05 · REPORTS</div><h3 class="h6">Review totals and export</h3>
        <p class="small text-muted">Choose the report type and period, then validate totals before printing or exporting CSV.</p>
        <a class="btn btn-sm btn-light border" href="<?= e(base_url('reports/index.php')) ?>">Open reports</a>
      </div></div>
      <?php endif; ?>

      <?php if (can('settings.view') || can('users.manage') || can('audit.view') || can('backup.run')): ?>
      <div class="col-md-6 col-xl-4"><div class="border rounded-3 p-3 h-100">
        <div class="small text-muted mb-1">ADMINISTRATION</div><h3 class="h6">Settings and system checks</h3>
        <p class="small text-muted">Maintain only owner-approved settings, review actions or download a private local backup.</p>
        <?php if (can('settings.view')): ?><a class="btn btn-sm btn-light border mt-1" href="<?= e(base_url('admin/settings.php')) ?>">Settings</a><?php endif; ?>
        <?php if (can('users.manage')): ?><a class="btn btn-sm btn-light border mt-1" href="<?= e(base_url('admin/users.php')) ?>">User accounts</a><?php endif; ?>
        <?php if (can('audit.view')): ?><a class="btn btn-sm btn-light border mt-1" href="<?= e(base_url('admin/audit.php')) ?>">Audit trail</a><?php endif; ?>
        <?php if (can('backup.run')): ?><a class="btn btn-sm btn-light border mt-1" href="<?= e(base_url('admin/backup.php')) ?>">Backup</a><?php endif; ?>
      </div></div>
      <?php endif; ?>
    </div>
  </div>
</div>

<div class="row g-3">
  <div class="col-lg-7">
    <div class="card-b h-100">
      <div class="card-h"><h2>Your workspace</h2><p>The areas your role can use.</p></div>
      <div class="card-body-b">
        <?php $all = array_merge($modules, $adminMenu); if (!$all): ?>
          <div class="empty-state"><i class="bi bi-box"></i>No modules are available to your role yet.</div>
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
        <div><div class="fs-3 fw-bold"><?= number_format((int) $userCounts['active_n']) ?></div><div class="text-muted small">Active</div></div>
        <div><div class="fs-3 fw-bold"><?= number_format((int) $userCounts['inactive_n']) ?></div><div class="text-muted small">Inactive</div></div>
      </div>
    </div>
    <?php endif; ?>
    <div class="card-b">
      <div class="card-h"><h2>Recent activity</h2><p>Latest entries in the audit trail.</p></div>
      <div class="card-body-b">
        <?php if (!can('audit.view')): ?>
          <div class="empty-state"><i class="bi bi-lock"></i>Recent activity is restricted to roles with audit access.</div>
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

<?php if (can('reports.view')): ?>
<div class="card-b mt-3">
  <div class="card-h d-flex justify-content-between align-items-start flex-wrap gap-2"><div><h2>Population by Purok</h2><p>Active, non-archived resident profiles, grouped from household assignments.</p></div><a class="btn btn-light border btn-sm" href="<?= e(base_url('reports/index.php?type=demographics')) ?>">Open demographic report</a></div>
  <div class="card-body-b">
    <?php if (!$populationByPurok): ?><?= empty_state('bi-people', 'No resident records are available for this summary.') ?>
    <?php else: ?><div class="table-responsive"><table class="table table-b mb-0"><thead><tr><th>Purok</th><th class="text-end">Residents</th></tr></thead><tbody>
      <?php foreach ($populationByPurok as $row): ?><tr><td><?= e((string) $row['label']) ?></td><td class="text-end"><?= number_format((int) $row['total_n']) ?></td></tr><?php endforeach; ?>
    </tbody></table></div><?php endif; ?>
  </div>
</div>
<?php endif; ?>
<?php render_footer();
