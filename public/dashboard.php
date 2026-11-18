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
            COALESCE(SUM(COALESCE(rs.label, r.residency_status) = 'Active'), 0) AS active_n,
            COALESCE(SUM(COALESCE(rs.label, CASE WHEN r.residency_status = 'Relocated' THEN 'Transferred' ELSE r.residency_status END) = 'Transferred'), 0) AS transferred_n,
            COALESCE(SUM(COALESCE(rs.label, r.residency_status) = 'Deceased'), 0) AS deceased_n,
            COALESCE(SUM(COALESCE(rs.label, r.residency_status) = 'Non-resident'), 0) AS nonresident_n
     FROM residents r
     LEFT JOIN lookups rs ON rs.lookup_id = r.resident_status_id AND rs.list_key = 'resident_status'
     WHERE r.archived_at IS NULL"
) ?? ['total_n' => 0, 'active_n' => 0, 'transferred_n' => 0, 'deceased_n' => 0, 'nonresident_n' => 0]) : null;

$requestCounts = can('requests.view') ? (db_one(
    "SELECT COUNT(*) AS total_n,
            COALESCE(SUM(msit_status = 'Pending'), 0) AS pending_n,
            COALESCE(SUM(msit_status = 'Approved'), 0) AS approved_n,
            COALESCE(SUM(msit_status = 'Rejected'), 0) AS rejected_n,
            COALESCE(SUM(msit_status = 'Released'), 0) AS released_n
     FROM document_requests"
) ?? ['total_n' => 0, 'pending_n' => 0, 'approved_n' => 0, 'rejected_n' => 0, 'released_n' => 0]) : null;

$queueCounts = can('queue.view') ? (db_one(
    "SELECT COUNT(*) AS total_n,
            COALESCE(SUM(status = 'Waiting'), 0) AS waiting_n,
            COALESCE(SUM(status = 'Called'), 0) AS called_n,
            COALESCE(SUM(status = 'Served'), 0) AS served_n
     FROM queue_entries WHERE queue_date = CURDATE()"
) ?? ['total_n' => 0, 'waiting_n' => 0, 'called_n' => 0, 'served_n' => 0]) : null;

$venueCounts = can('venues.view') ? (db_one(
    "SELECT COUNT(*) AS pending_n FROM venue_reservations WHERE status = 'Pending'"
) ?? ['pending_n' => 0]) : null;

$monthlyIssuance = can('certificates.history') ? (db_one(
    'SELECT COUNT(*) AS issued_n FROM issuances WHERE issued_at >= ? AND issued_at < ?',
    [$monthStart, $nextMonth]
) ?? ['issued_n' => 0]) : null;

$monthlyCollections = can('reports.view')
    ? report_cash_collections(['start' => $monthStart, 'end' => $nextMonth])
    : null;

$blotterCounts = can('blotters.view') ? (db_one(
    "SELECT COUNT(*) AS total_n,
            COALESCE(SUM(msit_status = 'Ongoing'), 0) AS ongoing_n,
            COALESCE(SUM(msit_status = 'Unresolved'), 0) AS unresolved_n,
            COALESCE(SUM(msit_status = 'Resolved'), 0) AS resolved_n,
            COALESCE(SUM(msit_status = 'Dismissed'), 0) AS dismissed_n,
            COALESCE(SUM(msit_status = 'Archived'), 0) AS archived_n
     FROM blotters"
) ?? ['total_n' => 0, 'ongoing_n' => 0, 'unresolved_n' => 0, 'resolved_n' => 0, 'dismissed_n' => 0, 'archived_n' => 0]) : null;

$populationByPurok = can('reports.view') ? db_all(
    "SELECT COALESCE(pk.label, 'Purok not assigned') AS label, COUNT(*) AS total_n
     FROM residents r
     LEFT JOIN lookups pk ON pk.lookup_id = r.purok_id AND pk.list_key = 'purok'
     LEFT JOIN lookups rs ON rs.lookup_id = r.resident_status_id AND rs.list_key = 'resident_status'
     WHERE r.archived_at IS NULL
       AND COALESCE(rs.label, r.residency_status) = 'Active'
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
  <div class="col-sm-6 col-xl-3"><div class="card-b h-100"><div class="card-h"><h2>Residents</h2><p>Current registry</p></div><div class="card-body-b"><div class="fs-2 fw-bold"><?= number_format((int) $residentCounts['total_n']) ?></div><div class="small text-muted"><?= number_format((int) $residentCounts['active_n']) ?> active · <?= number_format((int) $residentCounts['transferred_n']) ?> transferred</div></div></div></div>
  <?php endif; ?>
  <?php if ($queueCounts !== null): ?>
  <div class="col-sm-6 col-xl-3"><div class="card-b h-100"><div class="card-h"><h2>Queue tickets today</h2><p>Blotter · Documents · Venue</p></div><div class="card-body-b"><div class="fs-2 fw-bold"><?= number_format((int) $queueCounts['total_n']) ?></div><div class="small text-muted"><?= number_format((int) $queueCounts['waiting_n']) ?> waiting · <?= number_format((int) $queueCounts['called_n']) ?> called · <?= number_format((int) $queueCounts['served_n']) ?> served</div></div></div></div>
  <?php endif; ?>
  <?php if ($venueCounts !== null): ?>
  <div class="col-sm-6 col-xl-3"><div class="card-b h-100"><div class="card-h"><h2>Venue requests</h2><p>Awaiting staff decision</p></div><div class="card-body-b"><div class="fs-2 fw-bold"><?= number_format((int) $venueCounts['pending_n']) ?></div><div class="small text-muted">Pending reservations</div></div></div></div>
  <?php endif; ?>
  <?php if ($requestCounts !== null): ?>
  <div class="col-sm-6 col-xl-3"><div class="card-b h-100"><div class="card-h"><h2>Document requests</h2><p>Pending, approved, rejected and released</p></div><div class="card-body-b"><div class="fs-2 fw-bold"><?= number_format((int) $requestCounts['total_n']) ?></div><div class="small text-muted"><?= number_format((int) $requestCounts['pending_n']) ?> pending · <?= number_format((int) $requestCounts['approved_n']) ?> approved · <?= number_format((int) $requestCounts['released_n']) ?> released</div></div></div></div>
  <?php endif; ?>
  <?php if ($monthlyIssuance !== null): ?>
  <div class="col-sm-6 col-xl-3"><div class="card-b h-100"><div class="card-h"><h2>Certificates issued</h2><p><?= e($today->format('F Y')) ?></p></div><div class="card-body-b"><div class="fs-2 fw-bold"><?= number_format((int) $monthlyIssuance['issued_n']) ?></div><div class="small text-muted">Issued documents recorded this month</div></div></div></div>
  <?php endif; ?>
  <?php if ($monthlyCollections !== null): ?>
  <div class="col-sm-6 col-xl-3"><div class="card-b h-100"><div class="card-h"><h2>Revenue collected</h2><p><?= e($today->format('F Y')) ?> · reconciled ledger</p></div><div class="card-body-b"><div class="fs-2 fw-bold">₱<?= number_format((float) $monthlyCollections['collected_amount'], 2) ?></div><div class="small text-muted">Pending reconciliation: ₱<?= number_format((float) $monthlyCollections['pending_amount'], 2) ?></div></div></div></div>
  <?php endif; ?>
  <?php if ($blotterCounts !== null): ?>
  <div class="col-sm-6 col-xl-3"><div class="card-b h-100"><div class="card-h"><h2>Open blotter cases</h2><p>Ongoing and unresolved</p></div><div class="card-body-b"><div class="fs-2 fw-bold"><?= number_format((int) $blotterCounts['ongoing_n'] + (int) $blotterCounts['unresolved_n']) ?></div><div class="small text-muted"><?= number_format((int) $blotterCounts['ongoing_n']) ?> ongoing · <?= number_format((int) $blotterCounts['unresolved_n']) ?> unresolved</div></div></div></div>
  <?php endif; ?>
</div>

<div class="card-b mb-3" id="quick-start">
  <div class="card-h">
    <h2>Start here: quick workflows</h2>
    <p>Choose the task you need. Each shortcut follows your role's permissions. Residents use the public kiosk or the shared request QR code.</p>
  </div>
  <div class="card-body-b">
    <div class="border rounded-3 p-3 mb-3">
      <strong>Document request flow</strong>
      <p class="mb-1 mt-1 small">Resident submits through the kiosk/request QR <span class="text-muted">→</span> Secretary reviews records and requirements <span class="text-muted">→</span> Staff approves or rejects <span class="text-muted">→</span> BIRMS generates the approved document <span class="text-muted">→</span> Staff releases it.</p>
      <p class="mb-0 small text-muted">The request tracker shows Pending, Approved, Rejected or Released. Every submitted document request receives a reference number that residents can use to check its status.</p>
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
        <p class="small text-muted">Search and filter cases by date, Purok and the five required statuses: Ongoing, Unresolved, Resolved, Dismissed and Archived.</p>
        <a class="btn btn-sm btn-light border" href="<?= e(base_url('blotters/index.php')) ?>">Open blotter</a>
        <?php if (can('blotters.add')): ?><a class="btn btn-sm btn-primary mt-1" href="<?= e(base_url('blotters/form.php')) ?>">File a case</a><?php endif; ?>
        <?php if (can('hearings.view')): ?><a class="btn btn-sm btn-light border mt-1" href="<?= e(base_url('blotters/hearings.php')) ?>">Hearings</a><?php endif; ?>
      </div></div>
      <?php endif; ?>

      <?php if (can('queue.view')): ?>
      <div class="col-md-6 col-xl-4"><div class="border rounded-3 p-3 h-100">
        <div class="small text-muted mb-1">05 · SERVICE QUEUE</div><h3 class="h6">Call and monitor queue numbers</h3>
        <p class="small text-muted">Manage separate Blotter, Documents and Venue queues, or open the public number display.</p>
        <a class="btn btn-sm btn-light border" href="<?= e(base_url('queue/index.php')) ?>">Open service queue</a>
        <a class="btn btn-sm btn-primary mt-1" href="<?= e(base_url('queue/display.php')) ?>" target="_blank" rel="noopener">Public display</a>
      </div></div>
      <?php endif; ?>

      <?php if (can('venues.view')): ?>
      <div class="col-md-6 col-xl-4"><div class="border rounded-3 p-3 h-100">
        <div class="small text-muted mb-1">06 · VENUES</div><h3 class="h6">Review venue availability</h3>
        <p class="small text-muted">Check green/red venue availability and review reservation requests.</p>
        <a class="btn btn-sm btn-light border" href="<?= e(base_url('venues/index.php')) ?>">Open venues</a>
      </div></div>
      <?php endif; ?>

      <?php if (can('reports.view')): ?>
      <div class="col-md-6 col-xl-4"><div class="border rounded-3 p-3 h-100">
        <div class="small text-muted mb-1">05 · REPORTS</div><h3 class="h6">Review totals and export</h3>
        <p class="small text-muted">Choose the report type and period, then review resident, request, blotter, venue and queue statistics.</p>
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
  <div class="card-h d-flex justify-content-between align-items-start flex-wrap gap-2"><div><h2>Population by Purok</h2><p>Active, non-archived resident profiles grouped by their saved Purok.</p></div><a class="btn btn-light border btn-sm" href="<?= e(base_url('reports/index.php?type=demographics')) ?>">Open demographic report</a></div>
  <div class="card-body-b">
    <?php if (!$populationByPurok): ?><?= empty_state('bi-people', 'No resident records are available for this summary.') ?>
    <?php else: ?><div class="table-responsive"><table class="table table-b mb-0"><thead><tr><th>Purok</th><th class="text-end">Residents</th></tr></thead><tbody>
      <?php foreach ($populationByPurok as $row): ?><tr><td><?= e((string) $row['label']) ?></td><td class="text-end"><?= number_format((int) $row['total_n']) ?></td></tr><?php endforeach; ?>
    </tbody></table></div><?php endif; ?>
  </div>
</div>
<?php endif; ?>
<?php render_footer();
