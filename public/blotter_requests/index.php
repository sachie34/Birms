<?php
/** Staff/Admin tracker for resident-submitted blotter reports; Chairman and Secretary have read-only visibility. */
declare(strict_types=1);
require_once __DIR__ . '/../../app/bootstrap.php';

require_login();
require_permission('blotter_requests.view');

$reviewFilter = get_str('review');
if (!in_array($reviewFilter, RESIDENT_BLOTTER_REVIEW_STATUSES, true)) { $reviewFilter = ''; }
$statusFilter = get_str('status');
if (!in_array($statusFilter, MSIT_BLOTTER_STATUSES, true)) { $statusFilter = ''; }
$dateFrom = get_str('from');
$dateTo = get_str('to');
$q = get_str('q');
if ($dateFrom !== '' && !v_date($dateFrom)) { $dateFrom = ''; }
if ($dateTo !== '' && !v_date($dateTo)) { $dateTo = ''; }
if ($dateFrom !== '' && $dateTo !== '' && $dateFrom > $dateTo) { [$dateFrom, $dateTo] = [$dateTo, $dateFrom]; }

$where = [];
$params = [];
if ($q !== '') {
    $like = '%' . addcslashes($q, '%_\\\\') . '%';
    $where[] = '(br.reference_no LIKE ? OR r.resident_no LIKE ? OR r.first_name LIKE ? OR r.last_name LIKE ?)';
    array_push($params, $like, $like, $like, $like);
}
if ($reviewFilter !== '') { $where[] = 'br.review_status = ?'; $params[] = $reviewFilter; }
if ($statusFilter !== '') { $where[] = 'br.msit_status = ?'; $params[] = $statusFilter; }
if ($dateFrom !== '') { $where[] = 'br.submitted_at >= ?'; $params[] = $dateFrom . ' 00:00:00'; }
if ($dateTo !== '') { $where[] = 'br.submitted_at < DATE_ADD(?, INTERVAL 1 DAY)'; $params[] = $dateTo; }
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$rows = db_all(
    "SELECT br.*, r.resident_no, r.first_name, r.last_name, r.middle_name,
            pk.label AS purok, it.label AS incident_type, reviewer.full_name AS reviewer_name
     FROM resident_blotter_requests br
     JOIN residents r ON r.resident_id = br.resident_id
     LEFT JOIN lookups pk ON pk.lookup_id = br.purok_id AND pk.list_key = 'purok'
     LEFT JOIN lookups it ON it.lookup_id = br.incident_type_id AND it.list_key = 'incident_type'
     LEFT JOIN users reviewer ON reviewer.user_id = br.reviewed_by
     {$whereSql}
     ORDER BY CASE br.review_status WHEN 'Pending' THEN 0 ELSE 1 END, br.submitted_at DESC, br.request_id DESC
     LIMIT 250",
    $params
);
$canReview = can('blotter_requests.review');

render_header('Resident Blotter Requests', [['Workspace', null], ['Resident Blotter Requests', null]], 'blotter_requests');
page_head('Resident Blotter Requests', $canReview
    ? 'Review reports submitted through the resident kiosk. Accepting a report creates one official blotter case; rejection requires a reason and remains in request history.'
    : 'View reports submitted through the resident kiosk and their saved review status. Staff/Admin perform acceptance or rejection actions.');
?>
<div class="card-b mb-3">
  <form method="get" action="<?= e(base_url('blotter_requests/index.php')) ?>" class="filters">
    <div><label class="form-label" for="q">Reference or resident</label><input class="form-control" id="q" name="q" value="<?= e($q) ?>" maxlength="80" placeholder="Search reference, Resident ID or name"></div>
    <div><label class="form-label" for="review">Review status</label><select class="form-select" id="review" name="review"><option value="">All</option><?php foreach (RESIDENT_BLOTTER_REVIEW_STATUSES as $s): ?><option value="<?= e($s) ?>"<?= $reviewFilter === $s ? ' selected' : '' ?>><?= e($s) ?></option><?php endforeach; ?></select></div>
    <div><label class="form-label" for="status">Blotter status</label><select class="form-select" id="status" name="status"><option value="">All</option><?php foreach (MSIT_BLOTTER_STATUSES as $s): ?><option value="<?= e($s) ?>"<?= $statusFilter === $s ? ' selected' : '' ?>><?= e($s) ?></option><?php endforeach; ?></select></div>
    <div><label class="form-label" for="from">Submitted from</label><input class="form-control" id="from" name="from" type="date" value="<?= e($dateFrom) ?>"></div>
    <div><label class="form-label" for="to">Submitted to</label><input class="form-control" id="to" name="to" type="date" value="<?= e($dateTo) ?>"></div>
    <div><button class="btn btn-light border" type="submit">Filter</button></div>
  </form>
  <div class="px-3 py-2 small text-muted">Showing up to 250 matching reports. Filter by review status, case status, or submission date.</div>
</div>
<div class="row g-3">
<?php if (!$rows): ?>
  <div class="col-12"><div class="card-b"><?= empty_state('bi-clipboard-check', 'No resident blotter reports match these filters.') ?></div></div>
<?php else: foreach ($rows as $row):
  $residentName = trim((string) $row['last_name'] . ', ' . (string) $row['first_name'] . ' ' . (string) ($row['middle_name'] ?? ''));
?>
  <div class="col-12">
    <article class="card-b">
      <div class="card-h d-flex justify-content-between align-items-start flex-wrap gap-2">
        <div>
          <h2 class="h5 mb-1"><strong><?= e((string) $row['reference_no']) ?></strong> · <?= e($residentName) ?></h2>
          <p>Submitted <?= e(fmt_dt((string) $row['submitted_at'])) ?> · Resident ID <?= e((string) $row['resident_no']) ?> · Purok <?= e((string) ($row['purok'] ?? 'Not stated')) ?></p>
        </div>
        <div class="d-flex flex-wrap gap-2">
          <?= pill((string) $row['review_status'], $row['review_status'] === 'Accepted' ? 'green' : ($row['review_status'] === 'Rejected' ? 'red' : 'amber')) ?>
          <?= status_pill((string) $row['msit_status']) ?>
        </div>
      </div>
      <div class="card-body-b">
        <div class="row g-3">
          <div class="col-lg-4">
            <div><strong>When:</strong> <?= e(date('d M Y', strtotime((string) $row['incident_date']))) ?> · <?= e(date('g:i A', strtotime((string) $row['incident_time']))) ?></div>
            <div><strong>Where:</strong> <?= e((string) $row['incident_place']) ?></div>
            <div><strong>Type:</strong> <?= e((string) ($row['incident_type'] ?? 'Not classified')) ?></div>
            <div><strong>Respondent:</strong> <?= e((string) ($row['respondent_name'] ?? 'Not provided')) ?></div>
            <?php if ($row['blotter_id'] !== null): ?>
              <div class="mt-2"><a href="<?= e(base_url('blotters/view.php?id=' . (int) $row['blotter_id'])) ?>">Open official blotter case</a></div>
            <?php endif; ?>
          </div>
          <div class="col-lg-8">
            <strong>Incident details</strong>
            <p class="mb-0"><?= nl2br(e((string) $row['narrative'])) ?></p>
            <?php if ($row['status_note']): ?><p class="small text-muted mt-2 mb-0"><strong>Review/status note:</strong> <?= e((string) $row['status_note']) ?></p><?php endif; ?>
            <?php if ($row['reviewer_name']): ?><p class="small text-muted mt-1 mb-0">Reviewed by <?= e((string) $row['reviewer_name']) ?><?= $row['reviewed_at'] ? ' · ' . e(fmt_dt((string) $row['reviewed_at'])) : '' ?></p><?php endif; ?>
          </div>
        </div>
        <?php if ($canReview && $row['review_status'] === 'Pending'): ?>
          <div class="border-top mt-3 pt-3">
            <div class="row g-3">
              <div class="col-lg-6">
                <form method="post" action="<?= e(base_url('blotter_requests/action.php')) ?>" data-confirm="Accept this resident report and create an official blotter case?">
                  <?= csrf_field() ?><input type="hidden" name="request_id" value="<?= (int) $row['request_id'] ?>"><input type="hidden" name="do" value="accept">
                  <label class="form-label" for="accept-note-<?= (int) $row['request_id'] ?>">Review note (optional)</label>
                  <input class="form-control mb-2" id="accept-note-<?= (int) $row['request_id'] ?>" name="note" maxlength="500" placeholder="Optional note">
                  <button class="btn btn-success" type="submit">Accept and create case</button>
                </form>
              </div>
              <div class="col-lg-6">
                <form method="post" action="<?= e(base_url('blotter_requests/action.php')) ?>" data-confirm="Reject this report? It will be recorded as Dismissed in the resident tracker.">
                  <?= csrf_field() ?><input type="hidden" name="request_id" value="<?= (int) $row['request_id'] ?>"><input type="hidden" name="do" value="reject">
                  <label class="form-label req" for="reject-note-<?= (int) $row['request_id'] ?>">Reason for rejection</label>
                  <input class="form-control mb-2" id="reject-note-<?= (int) $row['request_id'] ?>" name="note" maxlength="500" minlength="3" required>
                  <button class="btn btn-outline-danger" type="submit">Reject report</button>
                </form>
              </div>
            </div>
          </div>
        <?php endif; ?>
      </div>
    </article>
  </div>
<?php endforeach; endif; ?>
</div>
<?php render_footer(); ?>
