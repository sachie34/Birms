<?php
/** Residents: search, filter, list. */
declare(strict_types=1);
require_once __DIR__ . '/../../app/bootstrap.php';

require_login();
require_permission('residents.view');
$canArchive = can('residents.archive');
$f = resident_filters($_GET, $canArchive);
$page = max(1, get_int('page') ?? 1); $per = 25;
[$rows, $total] = resident_search($f, $per, ($page - 1) * $per);
$query = array_filter($f, fn($x) => $x !== '');
$puroks = lookup_list('purok', false); $cats = lookup_list('resident_category', false);

render_header('Residents', [['Residents', null]], 'residents');
$actions = (can('residents.export') ? btn_link('residents/export.php' . ($query ? '?' . http_build_query($query) : ''), 'Export CSV', 'btn-light border', 'bi-download') : '')
         . (can('residents.add') ? btn_link('residents/form.php', 'Add resident', 'btn-primary', 'bi-plus-lg') : '');
page_head($f['show'] === 'archived' ? 'Archived residents' : 'Residents', 'Search by name or household code. Residents are archived, never deleted.', $actions);
?>
<div class="card-b">
  <form method="get" class="filters" action="<?= e(base_url('residents/index.php')) ?>">
    <div><label class="form-label" for="q">Search</label><input class="form-control" id="q" name="q" value="<?= e($f['q']) ?>" maxlength="60" placeholder="Name or household code"></div>
    <div><label class="form-label" for="purok">Purok</label><select class="form-select" id="purok" name="purok"><option value="">All</option><?php foreach ($puroks as $id => $l): ?><option value="<?= (int) $id ?>"<?= $f['purok'] === (string) $id ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
    <div><label class="form-label" for="status">Residency</label><select class="form-select" id="status" name="status"><option value="">All</option><option value="Active"<?= $f['status'] === 'Active' ? ' selected' : '' ?>>Active</option><option value="Relocated"<?= $f['status'] === 'Relocated' ? ' selected' : '' ?>>Relocated</option></select></div>
    <?php if ($cats): ?><div><label class="form-label" for="category">Category</label><select class="form-select" id="category" name="category"><option value="">All</option><?php foreach ($cats as $id => $l): ?><option value="<?= (int) $id ?>"<?= $f['category'] === (string) $id ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div><?php endif; ?>
    <?php if ($canArchive): ?><div><label class="form-label" for="show">Show</label><select class="form-select" id="show" name="show"><option value="">Current</option><option value="archived"<?= $f['show'] === 'archived' ? ' selected' : '' ?>>Archived</option></select></div><?php endif; ?>
    <div><button class="btn btn-light border" type="submit"><i class="bi bi-search me-1"></i>Search</button></div>
  </form>
  <?php if (!$rows): ?>
    <?= empty_state('bi-person-vcard', $f['q'] !== '' || $query ? 'No residents match these filters.' : 'No residents registered yet.') ?>
  <?php else: ?>
  <div class="table-responsive"><table class="table table-b">
    <thead><tr><th>Name</th><th>Age</th><th>Sex</th><th>Household</th><th>Residency</th><th>Voter</th><th class="text-end">Actions</th></tr></thead><tbody>
    <?php foreach ($rows as $r): ?>
      <tr>
        <td><a class="fw-semibold text-decoration-none" href="<?= e(base_url('residents/view.php?id=' . (int) $r['resident_id'])) ?>"><?= e(resident_name($r)) ?></a><?= (int) $r['is_household_head'] ? ' ' . pill('Head', 'blue') : '' ?></td>
        <td><?= (int) age_on((string) $r['birthdate']) ?></td><td><?= e((string) $r['sex']) ?></td>
        <td><?= e((string) ($r['household_code'] ?? '—')) ?><div class="small text-muted"><?= e((string) ($r['purok'] ?? '')) ?></div></td>
        <td><?= residency_pill((string) $r['residency_status']) ?></td><td><?= status_pill((string) $r['voter_status']) ?></td>
        <td class="text-end"><a class="btn btn-sm btn-light border" href="<?= e(base_url('residents/view.php?id=' . (int) $r['resident_id'])) ?>">View</a><?php if (can('residents.edit') && $r['archived_at'] === null): ?> <a class="btn btn-sm btn-light border" href="<?= e(base_url('residents/form.php?id=' . (int) $r['resident_id'])) ?>">Edit</a><?php endif; ?></td>
      </tr>
    <?php endforeach; ?></tbody></table></div>
  <?php render_pagination($total, $page, $per, 'residents/index.php', $query); endif; ?>
</div>
<?php render_footer();
