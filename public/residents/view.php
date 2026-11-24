<?php
/** Resident profile: direct Address, Purok, status and type; no household grouping. */
declare(strict_types=1);
require_once __DIR__ . '/../../app/bootstrap.php';

require_login();
require_permission('residents.view');
$id = get_int('id');
$r = $id === null ? null : db_one(
    "SELECT r.*,
            COALESCE(r.address, h.street_address) AS resident_address,
            pk.label AS purok,
            COALESCE(rs.label, CASE WHEN r.residency_status = 'Relocated' THEN 'Transferred' ELSE r.residency_status END) AS resident_status,
            COALESCE(rt.label, oldcat.label) AS resident_type,
            COALESCE(NULLIF(r.marital_status, ''), r.civil_status) AS display_marital_status
     FROM residents r
     LEFT JOIN households h ON h.household_id = r.household_id
     LEFT JOIN lookups pk ON pk.lookup_id = COALESCE(r.purok_id, h.purok_id) AND pk.list_key = 'purok'
     LEFT JOIN lookups rs ON rs.lookup_id = r.resident_status_id AND rs.list_key = 'resident_status'
     LEFT JOIN lookups rt ON rt.lookup_id = r.resident_type_id AND rt.list_key = 'resident_type'
     LEFT JOIN lookups oldcat ON oldcat.lookup_id = r.category_id AND oldcat.list_key = 'resident_category'
     WHERE r.resident_id = ?", [$id]
);
if ($r === null || ($r['archived_at'] !== null && !can('residents.archive'))) { abort(404, 'That resident was not found.'); }
$history = can('audit.view')
    ? db_all("SELECT occurred_at, username_snapshot, action, remarks FROM audit_log
              WHERE entity_type = 'resident' AND entity_id = ? ORDER BY audit_id DESC LIMIT 15", [$id])
    : [];
$docs = can('certificates.history')
    ? db_all('SELECT issuance_id, reference_no, type_name, issued_at FROM issuances WHERE resident_id = ? ORDER BY issued_at DESC LIMIT 10', [$id])
    : null;
$archived = $r['archived_at'] !== null;

render_header(resident_name($r), [['Residents', 'residents/index.php'], [resident_name($r), null]], 'residents');
$actions = '';
if (!$archived && can('residents.edit')) {
    $actions .= btn_link('residents/form.php?id=' . $id, 'Edit resident', 'btn-primary', 'bi-pencil');
}
if (!$archived && can('residents.archive')) {
    $actions .= btn_post('residents/action.php', ['id' => $id, 'action' => 'archive'], 'Archive', 'btn-outline-danger', 'Archive this resident? The record will be retained.');
}
if ($archived && can('residents.restore')) {
    $actions .= btn_post('residents/action.php', ['id' => $id, 'action' => 'restore'], 'Restore', 'btn-light border');
}
page_head(resident_name($r), ($archived ? 'ARCHIVED · ' : '') . (string) ($r['resident_no'] ?? 'Resident ID not assigned'), $actions);
$row = static fn(string $label, string $value) => '<tr><th class="text-muted fw-normal w-25">' . e($label) . '</th><td>' . $value . '</td></tr>';
?>
<div class="row g-3">
  <div class="col-lg-7">
    <?php if (!empty($r['photo_path'])): ?><div class="mb-3"><img src="<?= e(base_url('residents/photo.php?id=' . (int) $id)) ?>" alt="<?= e('Photo of ' . resident_name($r)) ?>" class="img-thumbnail" width="144"></div><?php endif; ?>
    <div class="card-b"><div class="card-h"><h2>Resident information</h2></div><div class="card-body-b">
      <table class="table table-sm mb-0"><tbody>
        <?= $row('Resident ID', e((string) ($r['resident_no'] ?? 'Not assigned'))) ?>
        <?= $row('Full name', e(resident_name($r))) ?>
        <?= $row('Birthdate / age', e(date('d M Y', strtotime((string) $r['birthdate']))) . ' · ' . (int) age_on((string) $r['birthdate'])) ?>
        <?= $row('Sex', e((string) $r['sex'])) ?>
        <?= $row('Marital status', e((string) ($r['display_marital_status'] ?? 'Not specified'))) ?>
        <?= $row('Address', e((string) ($r['resident_address'] ?? 'Not specified'))) ?>
        <?= $row('Purok', e((string) ($r['purok'] ?? 'Not assigned'))) ?>
        <?= $row('Resident status', status_pill((string) ($r['resident_status'] ?? 'Not set'))) ?>
        <?= $row('Resident type', e((string) ($r['resident_type'] ?? 'Not set'))) ?>
        <?= $row('Mobile number', e((string) ($r['contact_number'] ?? '—'))) ?>
        <?= $row('Email address', e((string) ($r['email_address'] ?? '—'))) ?>
        <?= $row('Registered', e(fmt_dt((string) $r['created_at']))) ?>
      </tbody></table>
    </div></div>
    <?php if ($docs !== null): ?><div class="card-b mt-3"><div class="card-h"><h2>Documents issued</h2></div><div class="card-body-b">
      <?php if (!$docs): ?><?= empty_state('bi-file-earmark', 'No documents issued yet.') ?><?php else: foreach ($docs as $d): ?>
        <div class="d-flex justify-content-between border-bottom py-2"><div><a class="text-decoration-none" href="<?= e(base_url('certificates/print.php?id=' . (int) $d['issuance_id'])) ?>"><?= e((string) $d['type_name']) ?></a><div class="small text-muted"><?= e((string) $d['reference_no']) ?></div></div><span class="small text-muted"><?= e(fmt_dt((string) $d['issued_at'])) ?></span></div>
      <?php endforeach; endif; ?></div></div><?php endif; ?>
  </div>
  <div class="col-lg-5">
    <div class="card-b"><div class="card-h"><h2>Record history</h2></div><div class="card-body-b">
      <?php if (!$history): ?><?= empty_state('bi-clock-history', 'No history yet.') ?><?php else: foreach ($history as $h): ?>
        <div class="border-bottom py-2"><strong><?= e((string) $h['action']) ?></strong>
          <span class="text-muted small">· <?= e((string) ($h['username_snapshot'] ?? 'system')) ?> · <?= e(fmt_dt((string) $h['occurred_at'])) ?></span>
          <div class="small text-muted"><?= e((string) $h['remarks']) ?></div></div>
      <?php endforeach; endif; ?>
    </div></div>
  </div>
</div>
<?php render_footer();
