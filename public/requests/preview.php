<?php
/** Limited Staff preview for incoming document requests before Secretary review is complete. */
declare(strict_types=1);
require_once __DIR__ . '/../../app/bootstrap.php';

require_login();
require_permission('requests.tracker');
$me = auth_user();
$id = get_int('id');
$request = $id === null ? null : db_one(
    'SELECT dr.request_id, dr.reference_no, dr.status, dr.msit_status, dr.requirements_status,
            dr.created_by, dr.assigned_to, dr.requested_at, dt.name AS type_name
     FROM document_requests dr
     JOIN document_types dt ON dt.type_id = dr.type_id
     WHERE dr.request_id = ?',
    [$id]
);
if ($request === null) { abort(404, 'That request was not found.'); }

// Administrators and Staff already authorized for the full request use the normal detail page.
if ((string) $me['role'] !== 'clerk') {
    redirect('requests/view.php', ['id' => $id]);
}
if (request_visible_to_current_user($request)) {
    redirect('requests/view.php', ['id' => $id]);
}

$workflowMessage = match ((string) $request['status']) {
    'Pending' => 'This request has arrived from the resident service channel and is waiting for the Secretary to begin requirements review. No Staff action is required yet.',
    'Review' => 'The Secretary is checking the request requirements. Staff can open the full request after it is assigned or the requirements review is complete.',
    default => 'This request is outside your current action queue. Resident details remain protected.',
};

render_header('Incoming Document Request', [['All Services Tracker', 'requests/all.php'], ['Incoming Document Request', null]], 'all_requests');
page_head('Incoming Document Request', 'Limited Staff visibility. Resident details and supporting files stay protected until the request enters your authorized work queue.');
?>
<div class="card-b">
  <div class="card-h"><h2><?= e((string) $request['reference_no']) ?></h2><p>Document request received by BIRMS</p></div>
  <div class="card-body-b">
    <div class="row g-3 mb-3">
      <div class="col-md-4"><div class="small text-muted">Document type</div><strong><?= e((string) $request['type_name']) ?></strong></div>
      <div class="col-md-4"><div class="small text-muted">Date submitted</div><strong><?= e(fmt_dt((string) $request['requested_at'])) ?></strong></div>
      <div class="col-md-4"><div class="small text-muted">Current status</div><?= status_pill((string) $request['msit_status']) ?></div>
    </div>
    <div class="alert alert-info mb-3" role="status"><?= e($workflowMessage) ?></div>
    <p class="text-muted small mb-3">Resident name, Resident ID, purpose, contact information and uploaded files are not displayed in this limited view.</p>
    <a class="btn btn-light border" href="<?= e(base_url('requests/all.php?service=Documents')) ?>">Back to Documents tracker</a>
  </div>
</div>
<?php render_footer(); ?>
