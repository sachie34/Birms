<?php
/** One blotter case: details, parties, hearings, the next allowed steps for your role, and its history. */
declare(strict_types=1);
require_once __DIR__ . '/../../app/bootstrap.php';

require_login();
require_permission('blotters.view');
$id = get_int('id');
$b = $id === null ? null : db_one('SELECT b.*, pk.label AS purok, it.label AS incident_type, u.full_name AS recorder FROM blotters b LEFT JOIN lookups pk ON pk.lookup_id = b.purok_id LEFT JOIN lookups it ON it.lookup_id = b.incident_type_id AND it.list_key = 'incident_type' LEFT JOIN users u ON u.user_id = b.recorded_by WHERE b.blotter_id = ?', [$id]);
if ($b === null) { abort(404, 'That case was not found.'); }
$open = case_is_open($b);
$parties = case_parties((int) $id);
$hearings = can('hearings.view') ? db_all('SELECT hearing_id, scheduled_at, venue, notes, status, status_note, summons_count FROM hearings WHERE blotter_id = ? ORDER BY scheduled_at DESC, hearing_id DESC', [$id]) : [];
$moves = $b['archived_at'] === null ? blotter_allowed_moves((string) $b['status']) : [];
$history = db_all("SELECT occurred_at, username_snapshot, action, remarks FROM audit_log WHERE entity_type = 'blotter' AND entity_id = ? ORDER BY audit_id DESC LIMIT 25", [$id]);

render_header((string) $b['case_no'], [['Blotter', 'blotters/index.php'], [(string) $b['case_no'], null]], 'blotters');
$actions = ($open && can('blotters.edit')) ? btn_link('blotters/form.php?id=' . $id, 'Edit details', 'btn-light border', 'bi-pencil') : '';
page_head((string) $b['case_no'], 'Incident on ' . date('j F Y', strtotime((string) $b['incident_date'])), $actions);
if ($b['archived_at'] !== null) { echo '<div class="alert alert-secondary">This case is archived (' . e(fmt_dt((string) $b['archived_at'])) . ').</div>'; }
$row = fn(string $k, string $v) => '<tr><th class="text-muted fw-normal w-25">' . e($k) . '</th><td>' . $v . '</td></tr>';
?>
<div class="row g-3">
  <div class="col-lg-7">
    <div class="card-b mb-3"><div class="card-h"><h2>Details</h2></div><div class="card-body-b"><table class="table table-sm mb-0"><tbody>
      <?= $row('Status', status_pill((string) $b['status'])) ?>
      <?= $row('Place', e((string) ($b['incident_place'] ?? '—'))) ?><?= $row('Purok', e((string) ($b['purok'] ?? '—'))) ?><?= $row('Incident type', e((string) ($b['incident_type'] ?? 'Unclassified'))) ?>
      <?= $row('Recorded', e(fmt_dt((string) $b['recorded_at'])) . ' by ' . e((string) ($b['recorder'] ?? '—'))) ?>
      <?php if ($b['status'] === 'Resolved'): ?><?= $row('Resolved', e(fmt_dt((string) $b['resolved_at'])) . ' · ' . e((string) $b['resolution_note'])) ?><?php endif; ?>
    </tbody></table></div></div>
    <div class="card-b mb-3"><div class="card-h"><h2>What happened</h2></div><div class="card-body-b"><p class="mb-0"><?= nl2br(e((string) $b['narrative'])) ?></p></div></div>

    <div class="card-b mb-3"><div class="card-h d-flex justify-content-between align-items-start"><div><h2>Parties</h2></div><?= ($open && can('blotters.edit')) ? btn_link('blotters/party.php?case=' . $id, 'Add party', 'btn-light border btn-sm', 'bi-plus-lg') : '' ?></div><div class="card-body-b">
      <?php if (!$parties): ?><?= empty_state('bi-people', 'No parties recorded.') ?><?php else: ?>
      <div class="table-responsive"><table class="table table-b"><thead><tr><th>Role</th><th>Name</th><th>Age</th><th>Address</th><th>Contact</th><th></th></tr></thead><tbody>
      <?php foreach ($parties as $pt): ?><tr><td><?= pill((string) $pt['party_role'], $pt['party_role'] === 'Complainant' ? 'blue' : 'amber') ?></td><td class="fw-semibold"><?= e((string) $pt['full_name']) ?></td><td><?= $pt['age'] === null ? '—' : (int) $pt['age'] ?></td><td><?= e((string) ($pt['address'] ?? '—')) ?></td><td><?= e((string) ($pt['contact_number'] ?? '—')) ?></td>
        <td class="text-end"><?php if ($open && can('blotters.edit')): ?><a class="btn btn-sm btn-light border" href="<?= e(base_url('blotters/party.php?id=' . (int) $pt['party_id'])) ?>">Edit</a><?php endif; ?></td></tr><?php endforeach; ?></tbody></table></div>
      <?php endif; ?></div></div>

    <?php if (can('hearings.view')): ?>
    <div class="card-b"><div class="card-h"><h2>Hearings</h2><p>Scheduling a hearing marks the case Scheduled. Recording the outcome of the last open hearing returns it to Active.</p></div><div class="card-body-b">
      <?php if (!$hearings): ?><?= empty_state('bi-calendar-event', 'No hearings yet.') ?><?php endif; ?>
      <?php foreach ($hearings as $h): ?>
        <div class="border rounded-3 p-3 mb-2">
          <div class="d-flex justify-content-between flex-wrap gap-2"><div><strong><?= e(fmt_dt((string) $h['scheduled_at'])) ?></strong> <?= status_pill((string) $h['status']) ?><div class="small text-muted"><?= e((string) ($h['venue'] ?? 'Venue not stated')) ?><?= $h['notes'] ? ' · ' . e((string) $h['notes']) : '' ?><?= $h['status_note'] ? ' · ' . e((string) $h['status_note']) : '' ?></div></div>
          <div><?php if ($h['status'] === 'Scheduled'): ?><a class="btn btn-sm btn-light border" href="<?= e(base_url('blotters/summons.php?id=' . (int) $h['hearing_id'])) ?>"><i class="bi bi-printer me-1"></i>Summons</a><?php endif; ?></div></div>
          <?php if ($h['status'] === 'Scheduled' && can('hearings.edit')): ?>
          <form method="post" action="<?= e(base_url('blotters/hearing_action.php')) ?>" class="row g-2 mt-1" data-confirm="Record this outcome? It cannot be changed afterwards."><?= csrf_field() ?>
            <input type="hidden" name="do" value="outcome"><input type="hidden" name="hearing" value="<?= (int) $h['hearing_id'] ?>">
            <div class="col-md-3"><select class="form-select form-select-sm" name="outcome" aria-label="Outcome"><?php foreach (HEARING_OUTCOMES as $o): ?><option value="<?= e($o) ?>"><?= e($o) ?></option><?php endforeach; ?></select></div>
            <div class="col-md-6"><input class="form-control form-control-sm" name="reason" maxlength="255" placeholder="Reason (needed for Postponed or Cancelled)"></div>
            <div class="col-md-3"><button class="btn btn-sm btn-primary w-100" type="submit">Record outcome</button></div></form>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
      <?php if ($open && can('hearings.add')): ?>
        <form method="post" action="<?= e(base_url('blotters/hearing_action.php')) ?>" class="needs-validation mt-3" novalidate><?= csrf_field() ?><input type="hidden" name="do" value="schedule"><input type="hidden" name="id" value="<?= (int) $id ?>"><div class="row">
          <?= f_text('scheduled_at', 'New hearing: date and time', '', ['type' => 'datetime-local', 'required' => true, 'col' => 'col-md-4']) ?>
          <?= f_text('venue', 'Venue', '', ['maxlength' => 150, 'col' => 'col-md-4']) ?><?= f_text('notes', 'Notes', '', ['maxlength' => 500, 'col' => 'col-md-4']) ?></div>
          <button class="btn btn-primary btn-sm" type="submit">Schedule hearing</button></form>
      <?php endif; ?>
    </div></div>
    <?php endif; ?>
  </div>
  <div class="col-lg-5">
    <?php if ($moves || ($b['status'] === 'Resolved' && can('blotters.archive'))): ?><div class="card-b mb-3"><div class="card-h"><h2>Next step</h2><p>Only steps your role may take are shown.</p></div><div class="card-body-b">
      <?php foreach ($moves as $to): $needNote = $to === 'Resolved'; ?>
        <form method="post" action="<?= e(base_url('blotters/action.php')) ?>" class="mb-3" data-confirm="<?= e(($to === 'Active' ? 'Start handling this case' : 'Mark this case as resolved') . '?') ?>"><?= csrf_field() ?>
          <input type="hidden" name="id" value="<?= (int) $id ?>"><input type="hidden" name="do" value="move"><input type="hidden" name="to" value="<?= e($to) ?>">
          <?php if ($needNote): ?><div class="mb-2"><label class="form-label req" for="note_res">How was it resolved?</label><input class="form-control" id="note_res" name="note" maxlength="500" required></div><?php endif; ?>
          <button class="btn <?= $needNote ? 'btn-outline-success' : 'btn-primary' ?>" type="submit"><?= e($to === 'Active' ? 'Start handling (Active)' : 'Mark resolved') ?></button></form>
      <?php endforeach; ?>
      <?php if ($b['status'] === 'Resolved' && can('blotters.archive')): ?>
        <?= $b['archived_at'] === null ? btn_post('blotters/action.php', ['id' => $id, 'do' => 'archive'], 'Archive case', 'btn-light border', 'Archive this resolved case? It leaves the main list but stays searchable.', 'bi-archive') : btn_post('blotters/action.php', ['id' => $id, 'do' => 'restore'], 'Restore case', 'btn-light border', 'Restore this case to the main list?', 'bi-arrow-counterclockwise') ?>
      <?php endif; ?></div></div><?php endif; ?>
    <div class="card-b"><div class="card-h"><h2>History</h2></div><div class="card-body-b">
      <?php if (!$history): ?><?= empty_state('bi-clock-history', 'No history yet.') ?><?php else: foreach ($history as $h): ?>
        <div class="border-bottom py-2"><strong><?= e((string) $h['action']) ?></strong> <span class="text-muted small">· <?= e((string) ($h['username_snapshot'] ?? 'system')) ?> · <?= e(fmt_dt((string) $h['occurred_at'])) ?></span><div class="small text-muted"><?= e((string) $h['remarks']) ?></div></div>
      <?php endforeach; endif; ?></div></div>
  </div>
</div>
<?php render_footer();
