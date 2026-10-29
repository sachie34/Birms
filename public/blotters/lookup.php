<?php
/** Case look-up: find every blotter record that names a person. A match is a hint for staff, never proof. */
declare(strict_types=1);
require_once __DIR__ . '/../../app/bootstrap.php';

require_login();
require_permission('blotters.view');
$q = get_str('q'); $tokens = name_tokens($q); $rows = [];
if ($q !== '' && $tokens) {
    rate_limit_enforce('blotter_person_lookup', 20, 60, null, 60);
    $rows = blotter_people_search($tokens);
    audit_best_effort('blotter_lookup', 'Blotter', 'Success', 'Searched: ' . mb_substr_safe($q, 100) . ' (' . count($rows) . ' found)');
}
render_header('Case Look-up', [['Blotter', 'blotters/index.php'], ['Case Look-up', null]], 'lookup');
page_head('Case Look-up', 'Search every blotter record by a person\'s name, including resolved and archived cases.');
?>
<div class="alert alert-info">A match is a <strong>possible</strong> record, not proof that it is the same person. Compare age and address with the person before deciding anything. Every search is recorded in the audit trail.</div>
<div class="card-b">
  <form method="get" class="filters" action="<?= e(base_url('blotters/lookup.php')) ?>">
    <div><label class="form-label" for="q">Name</label><input class="form-control" id="q" name="q" value="<?= e($q) ?>" maxlength="80" placeholder="e.g. Dela Cruz or Juan Cruz" autofocus></div>
    <div><button class="btn btn-primary" type="submit"><i class="bi bi-search me-1"></i>Search</button></div>
  </form>
  <?php if ($q !== '' && !$tokens): ?><?= empty_state('bi-search', 'Type at least two letters of a name.') ?>
  <?php elseif ($q !== '' && !$rows): ?><?= empty_state('bi-check2-circle', 'No blotter record names this person.') ?>
  <?php elseif ($rows): ?>
  <div class="table-responsive"><table class="table table-b"><thead><tr><th>Name</th><th>Role</th><th>Age</th><th>Address</th><th>Case</th><th>Status</th><th>Incident</th></tr></thead><tbody>
  <?php foreach ($rows as $r): ?><tr>
    <td class="fw-semibold"><?= e((string) $r['full_name']) ?></td><td><?= pill((string) $r['party_role'], $r['party_role'] === 'Complainant' ? 'blue' : 'amber') ?></td>
    <td><?= $r['age'] === null ? '—' : (int) $r['age'] ?></td><td><?= e((string) ($r['address'] ?? '—')) ?></td>
    <td><a class="text-decoration-none" href="<?= e(base_url('blotters/view.php?id=' . (int) $r['blotter_id'])) ?>"><?= e((string) $r['case_no']) ?></a></td>
    <td><?= status_pill((string) $r['status']) ?><?= $r['archived_at'] !== null ? ' ' . pill('Archived', 'grey') : '' ?></td><td class="text-nowrap"><?= e(date('d M Y', strtotime((string) $r['incident_date']))) ?></td></tr><?php endforeach; ?></tbody></table></div>
  <?php else: ?><?= empty_state('bi-search', 'Enter a name to search.') ?><?php endif; ?>
</div>
<?php render_footer();
