<?php
/** One request: details, history and the next allowed steps for your role. */
declare(strict_types=1);
require_once __DIR__ . '/../../app/bootstrap.php';

require_login();
require_permission('requests.view');
$id = get_int('id');
$r = $id === null ? null : db_one('SELECT dr.*, r.last_name, r.first_name, r.middle_name, r.suffix, r.contact_number, r.residency_status, dt.name AS type_name, u.full_name AS assigned, c.full_name AS creator
    FROM document_requests dr JOIN residents r ON r.resident_id = dr.resident_id JOIN document_types dt ON dt.type_id = dr.type_id
    LEFT JOIN users u ON u.user_id = dr.assigned_to LEFT JOIN users c ON c.user_id = dr.created_by WHERE dr.request_id = ?', [$id]);
if ($r === null) { abort(404, 'That request was not found.'); }
$moves = request_allowed_moves((string) $r['status']);
$iss = db_one('SELECT issuance_id, reference_no FROM issuances WHERE request_id = ?', [$id]);
$blockers = [];
if ($r['status'] === 'Ready' && can('certificates.issue')) { $ir = issuable_request($id); $blockers = $ir ? certificate_blockers($ir, certificate_vars($ir)) : []; }
$history = db_all("SELECT occurred_at, username_snapshot, action, remarks FROM audit_log WHERE entity_type = 'request' AND entity_id = ? ORDER BY audit_id DESC LIMIT 20", [$id]);

render_header((string) $r['reference_no'], [['Request Tracker', 'requests/index.php'], [(string) $r['reference_no'], null]], 'requests');
$actions = (can('requests.edit') && in_array($r['status'], ['Pending', 'Review'], true)) ? btn_link('requests/form.php?id=' . $id, 'Edit', 'btn-light border', 'bi-pencil') : '';
if ($r['status'] === 'Ready' && can('certificates.issue')) { $actions .= btn_link('certificates/issue.php?request=' . $id, 'Issue certificate', 'btn-primary', 'bi-file-earmark-check'); }
if ($iss && can('certificates.history')) { $actions .= btn_link('certificates/print.php?id=' . (int) $iss['issuance_id'], 'Open certificate ' . $iss['reference_no'], 'btn-light border', 'bi-printer'); }
page_head((string) $r['reference_no'], $r['type_name'] . ' for ' . resident_name($r), $actions);
if ($blockers) { echo '<div class="alert alert-warning"><strong>Cannot be issued yet:</strong><ul class="mb-0">'; foreach ($blockers as $bk) { echo '<li>' . e($bk) . '</li>'; } echo '</ul></div>'; }
// Background check for clearances: possible blotter records under this name. A hint only; it never blocks the request.
if (can('blotters.view') && stripos((string) $r['type_name'], 'clearance') !== false) {
    $hits = blotter_matches_for_resident($r);
    if ($hits) {
        echo '<div class="alert alert-warning"><strong>Possible blotter record(s) for this name.</strong> A match is a hint, not proof: check age and address with the person before deciding. This never blocks the request.<ul class="mb-0 mt-2">';
        foreach ($hits as $h) { echo '<li><a href="' . e(base_url('blotters/view.php?id=' . (int) $h['blotter_id'])) . '">' . e((string) $h['case_no']) . '</a> · ' . e((string) $h['party_role']) . ' · ' . e((string) $h['status']) . ' · incident ' . e(date('j M Y', strtotime((string) $h['incident_date']))) . ($h['age'] !== null ? ' · age ' . (int) $h['age'] : '') . ($h['address'] ? ' · ' . e((string) $h['address']) : '') . '</li>'; }
        echo '</ul></div>';
    } else { echo '<div class="alert alert-light border">No blotter record matches this name.</div>'; }
}
$row = fn(string $k, string $v) => '<tr><th class="text-muted fw-normal w-25">' . e($k) . '</th><td>' . $v . '</td></tr>';
?>
<div class="row g-3">
  <div class="col-lg-7">
    <div class="card-b mb-3"><div class="card-h"><h2>Details</h2></div><div class="card-body-b"><table class="table table-sm mb-0"><tbody>
      <?= $row('Status', status_pill((string) $r['status']) . ($r['status_note'] ? ' <span class="text-muted small">' . e((string) $r['status_note']) . '</span>' : '')) ?>
      <?= $row('Resident', can('residents.view') ? '<a href="' . e(base_url('residents/view.php?id=' . (int) $r['resident_id'])) . '">' . e(resident_name($r)) . '</a> ' . residency_pill((string) $r['residency_status']) : e(resident_name($r))) ?>
      <?= $row('Document', e((string) $r['type_name'])) ?><?= $row('Purpose', e((string) $r['purpose'])) ?>
      <?= $row('Requirements review', status_pill((string) $r['requirements_status'])) ?><?= $row('Requirements / follow-up notes', e((string) ($r['requirements_note'] ?? '—') ?: '—')) ?>
      <?php if ($r['fee_rule_id'] !== null && $r['quoted_fee_amount'] !== null): ?>
        <?= $row('Approved fee category / tier', e((string) ($r['fee_category_snapshot'] ?? '') . ' / ' . (string) ($r['fee_tier_snapshot'] ?? ''))) ?>
        <?= $row('Quoted fee calculation', e((int) $r['fee_quantity'] . ' × ' . money($r['fee_unit_rate']) . ' = ' . money($r['quoted_fee_amount']))) ?>
      <?php else: ?>
        <?= $row('Fee rule', 'Per-document fee from Settings will be applied at issuance because no effective matrix rule was used for this request.') ?>
      <?php endif; ?>
      <?= $row('Submitted', e(fmt_dt((string) $r['requested_at']))) ?><?= $row('Assigned to', e((string) ($r['assigned'] ?? '—'))) ?><?= $row('Filed by', e((string) ($r['creator'] ?? '—'))) ?>
    </tbody></table></div></div>
    <?php if ($moves): ?><div class="card-b"><div class="card-h"><h2>Next step</h2><p>Only steps your role may take are shown.</p></div><div class="card-body-b">
      <?php foreach ($moves as $to):
        $label = ['Review' => 'Start review', 'Ready' => 'Approve (ready for pickup)', 'Rejected' => 'Reject', 'Cancelled' => 'Cancel request'][$to] ?? $to;
        $needNote = $to === 'Rejected'; ?>
        <form method="post" action="<?= e(base_url('requests/action.php')) ?>" class="mb-3" data-confirm="<?= e($label . '?') ?>"><?= csrf_field() ?>
          <input type="hidden" name="id" value="<?= (int) $id ?>"><input type="hidden" name="to" value="<?= e($to) ?>">
          <?php if ($needNote || $to === 'Cancelled'): ?><div class="mb-2"><label class="form-label<?= $needNote ? ' req' : '' ?>" for="note_<?= e($to) ?>"><?= $needNote ? 'Reason' : 'Note (optional)' ?></label><input class="form-control" id="note_<?= e($to) ?>" name="note" maxlength="255"<?= $needNote ? ' required' : '' ?>></div><?php endif; ?>
          <button class="btn <?= $to === 'Rejected' || $to === 'Cancelled' ? 'btn-outline-danger' : 'btn-primary' ?>" type="submit"><?= e($label) ?></button>
        </form>
      <?php endforeach; ?></div></div><?php endif; ?>
  </div>
  <div class="col-lg-5"><div class="card-b"><div class="card-h"><h2>History</h2></div><div class="card-body-b">
    <?php if (!$history): ?><?= empty_state('bi-clock-history', 'No history yet.') ?><?php else: foreach ($history as $h): ?>
      <div class="border-bottom py-2"><strong><?= e((string) $h['action']) ?></strong> <span class="text-muted small">· <?= e((string) ($h['username_snapshot'] ?? 'system')) ?> · <?= e(fmt_dt((string) $h['occurred_at'])) ?></span><div class="small text-muted"><?= e((string) $h['remarks']) ?></div></div>
    <?php endforeach; endif; ?></div></div></div>
</div>
<?php render_footer();
