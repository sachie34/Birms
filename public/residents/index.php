<?php
/** Resident directory: direct resident Address and Purok with revised SRS status/type filters. */
declare(strict_types=1);
require_once __DIR__ . '/../../app/bootstrap.php';

require_login();
require_permission('residents.view');
$canArchive = can('residents.archive');
$f = resident_filters($_GET, $canArchive);
$page = max(1, get_int('page') ?? 1);
$per = 25;
[$rows, $total] = resident_search($f, $per, ($page - 1) * $per);
$query = array_filter($f, static fn($x) => $x !== '');
$puroks = lookup_list('purok', false);
$statuses = lookup_list('resident_status', false);
$types = lookup_list('resident_type', false);

render_header('Residents', [['Residents', null]], 'residents');
$actions = (can('residents.export') ? btn_link('residents/export.php' . ($query ? '?' . http_build_query($query) : ''), 'Export CSV', 'btn-light border', 'bi-download') : '')
         . (can('residents.add') ? btn_link('residents/form.php', 'Add resident', 'btn-primary', 'bi-plus-lg') : '');
page_head($f['show'] === 'archived' ? 'Archived residents' : 'Residents',
    'Search the resident directory by name, Resident ID, address, email, Purok, status or resident type.', $actions);
?>
<div class="card-b">
  <form method="get" class="filters" action="<?= e(base_url('residents/index.php')) ?>">
    <div><label class="form-label" for="q">Search residents</label><input class="form-control" id="q" name="q" value="<?= e($f['q']) ?>" maxlength="80" placeholder="Name, Resident ID, address or email"></div>
    <div><label class="form-label" for="purok">Purok</label><select class="form-select" id="purok" name="purok"><option value="">All Puroks</option><?php foreach ($puroks as $id => $label): ?><option value="<?= (int) $id ?>"<?= $f['purok'] === (string) $id ? ' selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></div>
    <div><label class="form-label" for="status">Resident status</label><select class="form-select" id="status" name="status"><option value="">All statuses</option><?php foreach ($statuses as $label): ?><option value="<?= e($label) ?>"<?= $f['status'] === $label ? ' selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></div>
    <div><label class="form-label" for="type">Resident type</label><select class="form-select" id="type" name="type"><option value="">All types</option><?php foreach ($types as $id => $label): ?><option value="<?= (int) $id ?>"<?= $f['type'] === (string) $id ? ' selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></div>
    <?php if ($canArchive): ?><div><label class="form-label" for="show">Records</label><select class="form-select" id="show" name="show"><option value="">Current</option><option value="archived"<?= $f['show'] === 'archived' ? ' selected' : '' ?>>Archived</option></select></div><?php endif; ?>
    <div><button class="btn btn-light border" type="submit"><i class="bi bi-search me-1"></i>Search</button></div>
  </form>
  <?php if (!$rows): ?>
    <?= empty_state('bi-person-vcard', $f['q'] !== '' || $query ? 'No residents match these filters.' : 'No residents registered yet.') ?>
  <?php else: ?>
  <div class="table-responsive"><table class="table table-b align-middle">
    <thead><tr><th>Resident ID</th><th>Name</th><th>Address</th><th>Purok</th><th>Resident status</th><th>Resident type</th><th class="text-end">Actions</th></tr></thead><tbody>
    <?php foreach ($rows as $r): ?>
      <tr>
        <td class="text-nowrap"><span class="fw-semibold"><?= e((string) ($r['resident_no'] ?? 'Not assigned')) ?></span></td>
        <td><a class="fw-semibold text-decoration-none" href="<?= e(base_url('residents/view.php?id=' . (int) $r['resident_id'])) ?>"><?= e(resident_name($r)) ?></a><div class="small text-muted"><?= e((string) $r['birthdate']) ?></div></td>
        <td><?= e((string) ($r['address'] ?? '—')) ?></td>
        <td><?= e((string) ($r['purok'] ?? 'Not assigned')) ?></td>
        <td><?= status_pill((string) ($r['resident_status'] ?? 'Not set')) ?></td>
        <td><?= e((string) ($r['resident_type'] ?? 'Not set')) ?></td>
        <td class="text-end text-nowrap"><a class="btn btn-sm btn-light border" href="<?= e(base_url('residents/view.php?id=' . (int) $r['resident_id'])) ?>">View</a><?php if (can('residents.edit') && $r['archived_at'] === null): ?> <a class="btn btn-sm btn-light border" href="<?= e(base_url('residents/form.php?id=' . (int) $r['resident_id'])) ?>">Edit</a><?php endif; ?></td>
      </tr>
    <?php endforeach; ?></tbody></table></div>
  <?php render_pagination($total, $page, $per, 'residents/index.php', $query); endif; ?>
</div>
<?php render_footer();
