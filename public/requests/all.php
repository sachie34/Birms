<?php
/** Unified FR-T6 tracker for Documents, Blotter and Venue requests (Admin and Staff only). */
declare(strict_types=1);
require_once __DIR__ . '/../../app/bootstrap.php';

require_login();
require_permission('requests.tracker');
$role = (string) auth_user()['role'];

$services = ['Documents', 'Blotter', 'Venue'];
$statuses = ['Pending', 'Approved', 'Rejected', 'Released', 'Ongoing', 'Unresolved', 'Resolved', 'Dismissed', 'Archived', 'Completed'];
$service = get_str('service');
$status = get_str('status');
$from = get_str('from');
$to = get_str('to');
$q = get_str('q');
if (!in_array($service, $services, true)) { $service = ''; }
if (!in_array($status, $statuses, true)) { $status = ''; }
if ($from !== '' && !v_date($from)) { $from = ''; }
if ($to !== '' && !v_date($to)) { $to = ''; }
if ($from !== '' && $to !== '' && $from > $to) { [$from, $to] = [$to, $from]; }
$page = max(1, get_int('page') ?? 1);
$per = 50;

/*
 * Keep the public SRS status for each service. Internal document review states are not
 * exposed here; venue reservations and resident blotter requests use their own SRS values.
 */
$union = "
    SELECT
        'Documents' AS service,
        dr.reference_no,
        dr.msit_status AS status,
        dr.status AS internal_status,
        dr.created_by,
        dr.assigned_to,
        dr.requirements_status,
        dr.requested_at AS submitted_at,
        TRIM(CONCAT(r.last_name, ', ', r.first_name, ' ', COALESCE(r.middle_name, ''), ' ', COALESCE(r.suffix, ''))) AS resident_name,
        r.resident_no AS resident_no,
        dt.name AS item,
        dr.purpose,
        dr.request_id AS document_request_id,
        NULL AS blotter_request_id,
        NULL AS blotter_id,
        NULL AS reservation_id,
        NULL AS review_status,
        NULL AS reservation_date
    FROM document_requests dr
    JOIN residents r ON r.resident_id = dr.resident_id
    JOIN document_types dt ON dt.type_id = dr.type_id

    UNION ALL

    SELECT
        'Blotter' AS service,
        br.reference_no,
        br.msit_status AS status,
        NULL AS internal_status,
        NULL AS created_by,
        NULL AS assigned_to,
        NULL AS requirements_status,
        br.submitted_at AS submitted_at,
        TRIM(CONCAT(r.last_name, ', ', r.first_name, ' ', COALESCE(r.middle_name, ''), ' ', COALESCE(r.suffix, ''))) AS resident_name,
        r.resident_no AS resident_no,
        COALESCE(it.label, 'Blotter report') AS item,
        br.incident_place AS purpose,
        NULL AS document_request_id,
        br.request_id AS blotter_request_id,
        br.blotter_id,
        NULL AS reservation_id,
        br.review_status,
        NULL AS reservation_date
    FROM resident_blotter_requests br
    JOIN residents r ON r.resident_id = br.resident_id
    LEFT JOIN lookups it ON it.lookup_id = br.incident_type_id AND it.list_key = 'incident_type'

    UNION ALL

    SELECT
        'Venue' AS service,
        vr.reference_no,
        vr.status,
        NULL AS internal_status,
        NULL AS created_by,
        NULL AS assigned_to,
        NULL AS requirements_status,
        vr.created_at AS submitted_at,
        TRIM(CONCAT(r.last_name, ', ', r.first_name, ' ', COALESCE(r.middle_name, ''), ' ', COALESCE(r.suffix, ''))) AS resident_name,
        r.resident_no AS resident_no,
        v.name AS item,
        vr.purpose,
        NULL AS document_request_id,
        NULL AS blotter_request_id,
        NULL AS blotter_id,
        vr.reservation_id,
        NULL AS review_status,
        vr.reservation_date
    FROM venue_reservations vr
    JOIN residents r ON r.resident_id = vr.resident_id
    JOIN venues v ON v.venue_id = vr.venue_id
";

$where = [];
$params = [];
if ($service !== '') { $where[] = 'all_requests.service = ?'; $params[] = $service; }
if ($status !== '') { $where[] = 'all_requests.status = ?'; $params[] = $status; }
if ($from !== '') { $where[] = 'all_requests.submitted_at >= ?'; $params[] = $from . ' 00:00:00'; }
if ($to !== '') { $where[] = 'all_requests.submitted_at < DATE_ADD(?, INTERVAL 1 DAY)'; $params[] = $to; }
if ($q !== '') {
    $like = '%' . addcslashes($q, '%_\\') . '%';
    if ($role === 'clerk') {
        // The Staff tracker can identify incoming work by reference/type without exposing
        // resident names, Resident IDs or free-text purpose before the Secretary review gate.
        $where[] = '(all_requests.reference_no LIKE ? OR all_requests.item LIKE ?)';
        array_push($params, $like, $like);
    } else {
        $where[] = '(all_requests.reference_no LIKE ? OR all_requests.resident_no LIKE ? OR all_requests.resident_name LIKE ? OR all_requests.item LIKE ? OR all_requests.purpose LIKE ?)';
        array_push($params, $like, $like, $like, $like, $like);
    }
}
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';
$source = '(' . $union . ') all_requests';
$total = (int) db_val('SELECT COUNT(*) FROM ' . $source . ' ' . $whereSql, $params);
$rows = db_all(
    'SELECT * FROM ' . $source . ' ' . $whereSql
    . ' ORDER BY submitted_at DESC, reference_no DESC LIMIT ' . (int) $per
    . ' OFFSET ' . (int) (($page - 1) * $per),
    $params
);

render_header('All Services Tracker', [['Workspace', null], ['All Services Tracker', null]], 'all_requests');
page_head('All Services Tracker', 'Staff and Admin can monitor document, blotter and venue requests in one list. Filter by service, current status and submission date.');
?>
<div class="card-b mb-3">
  <div class="card-h"><h2>Filter requests</h2><p>Dates filter the date the request was submitted. A venue’s reservation date is shown in its details.</p></div>
  <div class="card-body-b">
    <form method="get" action="<?= e(base_url('requests/all.php')) ?>" class="row g-3 align-items-end">
      <div class="col-md-3"><label class="form-label" for="service">Service</label>
        <select class="form-select" id="service" name="service"><option value="">All services</option>
          <?php foreach ($services as $option): ?><option value="<?= e($option) ?>"<?= $service === $option ? ' selected' : '' ?>><?= e($option) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-3"><label class="form-label" for="status">Current status</label>
        <select class="form-select" id="status" name="status"><option value="">All statuses</option>
          <?php foreach ($statuses as $option): ?><option value="<?= e($option) ?>"<?= $status === $option ? ' selected' : '' ?>><?= e($option) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-2"><label class="form-label" for="from">Submitted from</label><input class="form-control" id="from" name="from" type="date" value="<?= e($from) ?>"></div>
      <div class="col-md-2"><label class="form-label" for="to">Submitted to</label><input class="form-control" id="to" name="to" type="date" value="<?= e($to) ?>"></div>
      <div class="col-md-2"><label class="form-label" for="q">Search</label><input class="form-control" id="q" name="q" value="<?= e($q) ?>" maxlength="80" placeholder="Reference or resident"></div>
      <div class="col-12 d-flex flex-wrap gap-2"><button class="btn btn-primary" type="submit"><i class="bi bi-funnel me-1"></i>Apply filters</button><a class="btn btn-light border" href="<?= e(base_url('requests/all.php')) ?>">Clear filters</a></div>
    </form>
  </div>
</div>
<div class="card-b">
  <div class="card-h d-flex justify-content-between align-items-center flex-wrap gap-2">
    <div><h2>Request records</h2><p><?= number_format($total) ?> matching record(s); showing up to 50 per page.</p></div>
  </div>
  <?php if (!$rows): ?>
    <div class="p-4"><?= empty_state('bi-inboxes', 'No requests match the selected filters.') ?></div>
  <?php else: ?>
    <div class="table-responsive"><table class="table table-b align-middle">
      <thead><tr><th>Reference</th><th>Service</th><th>Resident</th><th>Request / purpose</th><th>Submitted</th><th>Status</th><th>Review</th></tr></thead>
      <tbody>
      <?php foreach ($rows as $row):
          $restrictedDocument = $row['service'] === 'Documents' && $role === 'clerk'
              && !request_visible_to_current_user([
                  'created_by' => $row['created_by'],
                  'assigned_to' => $row['assigned_to'],
                  'status' => $row['internal_status'],
                  'requirements_status' => $row['requirements_status'],
              ]);
          if ($row['service'] === 'Documents') {
              $target = $restrictedDocument
                  ? base_url('requests/preview.php?id=' . (int) $row['document_request_id'])
                  : base_url('requests/view.php?id=' . (int) $row['document_request_id']);
              $description = (string) $row['item'] . ($restrictedDocument ? '' : ' · ' . (string) $row['purpose']);
              $reviewLabel = $restrictedDocument
                  ? ((string) $row['internal_status'] === 'Pending' ? 'Awaiting Secretary review' : 'Secretary review in progress')
                  : '—';
          } elseif ($row['service'] === 'Blotter') {
              $target = base_url('blotter_requests/index.php?q=' . rawurlencode((string) $row['reference_no']));
              if ($row['blotter_id'] !== null) {
                  $target = base_url('blotters/view.php?id=' . (int) $row['blotter_id']);
              }
              $description = (string) $row['item'] . ' · ' . (string) $row['purpose'];
              $reviewLabel = (string) ($row['review_status'] ?? '—');
          } else {
              $target = base_url('venues/index.php?status=' . rawurlencode((string) $row['status']) . '&date=' . rawurlencode((string) $row['reservation_date']));
              $description = (string) $row['item'] . ' · ' . (string) $row['purpose'] . ' · Reservation ' . date('d M Y', strtotime((string) $row['reservation_date']));
              $reviewLabel = '—';
          }
      ?>
        <tr>
          <td><a class="fw-semibold text-decoration-none" href="<?= e($target) ?>"><?= e((string) $row['reference_no']) ?></a></td>
          <td><?= pill((string) $row['service'], 'blue') ?></td>
          <td><?= $restrictedDocument ? '<span class="text-muted">Resident details protected until review is complete</span>' : e((string) $row['resident_name']) ?></td>
          <td><?= e($description) ?></td>
          <td class="text-nowrap"><?= e(fmt_dt((string) $row['submitted_at'])) ?></td>
          <td><?= status_pill((string) $row['status']) ?></td>
          <td><?= e($reviewLabel) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
    <?php render_pagination($total, $page, $per, 'requests/all.php', array_filter([
        'service' => $service, 'status' => $status, 'from' => $from, 'to' => $to, 'q' => $q,
    ])); ?>
  <?php endif; ?>
</div>
<?php render_footer();