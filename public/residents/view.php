<?php
/** One resident: details, household, record history. */
declare(strict_types=1);
require_once __DIR__ . '/../../app/bootstrap.php';

require_login();
require_permission('residents.view');
$id = get_int('id');
$r = $id === null ? null : db_one("SELECT r.*, h.household_code, h.street_address, pk.label AS purok, cat.label AS category, emp.label AS employment_status
    FROM residents r LEFT JOIN households h ON h.household_id = r.household_id LEFT JOIN lookups pk ON pk.lookup_id = h.purok_id LEFT JOIN lookups cat ON cat.lookup_id = r.category_id LEFT JOIN lookups emp ON emp.lookup_id = r.employment_status_id AND emp.list_key = 'employment_status' WHERE r.resident_id = ?", [$id]);
if ($r === null || ($r['archived_at'] !== null && !can('residents.archive'))) { abort(404, 'That resident was not found.'); }
$members = $r['household_id'] ? db_all('SELECT resident_id, last_name, first_name, middle_name, suffix, is_household_head, residency_status FROM residents WHERE household_id = ? AND archived_at IS NULL AND resident_id <> ? ORDER BY last_name, first_name', [$r['household_id'], $id]) : [];
$history = can('audit.view') ? db_all("SELECT occurred_at, username_snapshot, action, remarks FROM audit_log WHERE entity_type = 'resident' AND entity_id = ? ORDER BY audit_id DESC LIMIT 15", [$id]) : [];
$archived = $r['archived_at'] !== null;
$docs = can('certificates.history') ? db_all('SELECT issuance_id, reference_no, type_name, issued_at FROM issuances WHERE resident_id = ? ORDER BY issued_at DESC LIMIT 10', [$id]) : null;

render_header(resident_name($r), [['Residents', 'residents/index.php'], [resident_name($r), null]], 'residents');
$actions = '';
if (!$archived && can('residents.edit')) {
    $actions .= btn_link('residents/form.php?id=' . $id, 'Edit', 'btn-primary', 'bi-pencil');
    $actions .= btn_post('residents/action.php', ['id' => $id, 'action' => $r['residency_status'] === 'Active' ? 'set_relocated' : 'set_active'], $r['residency_status'] === 'Active' ? 'Mark as relocated' : 'Mark as active', 'btn-light border', 'Change the residency status of this resident?');
}
if (can('residents.archive')) { $actions .= $archived ? btn_post('residents/action.php', ['id' => $id, 'action' => 'restore'], 'Restore', 'btn-light border') : btn_post('residents/action.php', ['id' => $id, 'action' => 'archive'], 'Archive', 'btn-outline-danger', 'Archive this resident? They disappear from normal lists but nothing is deleted.'); }
page_head(resident_name($r), ($archived ? 'ARCHIVED · ' : '') . 'Record #' . $id, $actions);
$row = fn(string $k, string $v) => '<tr><th class="text-muted fw-normal w-25">' . e($k) . '</th><td>' . $v . '</td></tr>';
?>
<div class="row g-3">
  <div class="col-lg-7"><div class="card-b"><div class="card-h"><h2>Details</h2></div><div class="card-body-b"><table class="table table-sm mb-0"><tbody>
    <?= $row('Birthdate / age', e(date('d M Y', strtotime((string) $r['birthdate']))) . ' · ' . (int) age_on((string) $r['birthdate'])) ?>
    <?= $row('Sex', e((string) $r['sex'])) ?><?= $row('Civil status', e((string) $r['civil_status'])) ?>
    <?= $row('Contact', e((string) ($r['contact_number'] ?? '—'))) ?><?= $row('Category', e((string) ($r['category'] ?? '—'))) ?><?= $row('Employment status', e((string) ($r['employment_status'] ?? 'Not specified'))) ?>
    <?= $row('Residency', residency_pill((string) $r['residency_status'])) ?><?= $row('Voter status', status_pill((string) $r['voter_status'])) ?>
    <?= $row('Household', $r['household_id'] ? e((string) $r['household_code']) . ' · ' . e((string) $r['street_address']) . ($r['purok'] ? ' · ' . e((string) $r['purok']) : '') . ((int) $r['is_household_head'] ? ' ' . pill('Head of household', 'blue') : '') : '—') ?>
    <?= $row('Registered', e(fmt_dt((string) $r['created_at']))) ?>
  </tbody></table></div></div></div>
  <div class="col-lg-5">
    <div class="card-b mb-3"><div class="card-h"><h2>Household members</h2></div><div class="card-body-b">
      <?php if (!$members): ?><?= empty_state('bi-people', $r['household_id'] ? 'No other members.' : 'Not linked to a household.') ?><?php else: foreach ($members as $m): ?>
        <div class="d-flex justify-content-between border-bottom py-2"><a class="text-decoration-none" href="<?= e(base_url('residents/view.php?id=' . (int) $m['resident_id'])) ?>"><?= e(resident_name($m)) ?></a><span><?= (int) $m['is_household_head'] ? pill('Head', 'blue') . ' ' : '' ?><?= residency_pill((string) $m['residency_status']) ?></span></div>
      <?php endforeach; endif; ?></div></div>
    <?php if ($docs !== null): ?><div class="card-b mb-3"><div class="card-h"><h2>Documents issued</h2></div><div class="card-body-b">
      <?php if (!$docs): ?><?= empty_state('bi-file-earmark', 'No documents issued yet.') ?><?php else: foreach ($docs as $d): ?>
        <div class="d-flex justify-content-between border-bottom py-2"><div><a class="text-decoration-none" href="<?= e(base_url('certificates/print.php?id=' . (int) $d['issuance_id'])) ?>"><?= e((string) $d['type_name']) ?></a><div class="small text-muted"><?= e((string) $d['reference_no']) ?></div></div><span class="small text-muted"><?= e(fmt_dt((string) $d['issued_at'])) ?></span></div>
      <?php endforeach; endif; ?></div></div><?php endif; ?>
    <?php if (can('audit.view')): ?><div class="card-b"><div class="card-h"><h2>Record history</h2></div><div class="card-body-b">
      <?php if (!$history): ?><?= empty_state('bi-clock-history', 'No history yet.') ?><?php else: foreach ($history as $h): ?>
        <div class="border-bottom py-2"><strong><?= e((string) $h['action']) ?></strong> <span class="text-muted small">· <?= e((string) ($h['username_snapshot'] ?? 'system')) ?> · <?= e(fmt_dt((string) $h['occurred_at'])) ?></span><div class="small text-muted"><?= e((string) $h['remarks']) ?></div></div>
      <?php endforeach; endif; ?></div></div><?php endif; ?>
  </div>
</div>
<?php render_footer();
