<?php
/** Households list. */
declare(strict_types=1);
require_once __DIR__ . '/../../app/bootstrap.php';

require_login();
require_permission('residents.view');
$q = get_str('q'); $page = max(1, get_int('page') ?? 1); $per = 25;
$w = ''; $p = [];
if ($q !== '') { $like = '%' . addcslashes($q, '%_\\') . '%'; $w = 'WHERE h.household_code LIKE ? OR h.street_address LIKE ?'; $p = [$like, $like]; }
$total = (int) db_val("SELECT COUNT(*) FROM households h $w", $p);
$rows = db_all("SELECT h.household_id, h.household_code, h.street_address, pk.label AS purok,
    (SELECT COUNT(*) FROM residents r WHERE r.household_id = h.household_id AND r.archived_at IS NULL) AS members
    FROM households h LEFT JOIN lookups pk ON pk.lookup_id = h.purok_id $w ORDER BY h.household_code LIMIT " . (int) $per . ' OFFSET ' . (int) (($page - 1) * $per), $p);

render_header('Households', [['Households', null]], 'households');
page_head('Households', 'Each household groups the residents who live together.', can('residents.add') ? btn_link('residents/household_edit.php', 'Add household', 'btn-primary', 'bi-plus-lg') : '');
?>
<div class="card-b">
  <form method="get" class="filters" action="<?= e(base_url('residents/households.php')) ?>"><div><label class="form-label" for="q">Search</label><input class="form-control" id="q" name="q" value="<?= e($q) ?>" maxlength="60" placeholder="Code or address"></div><div><button class="btn btn-light border" type="submit">Search</button></div></form>
  <?php if (!$rows): ?><?= empty_state('bi-house', 'No households found.') ?><?php else: ?>
  <div class="table-responsive"><table class="table table-b"><thead><tr><th>Code</th><th>Address</th><th>Purok</th><th>Members</th><th class="text-end">Actions</th></tr></thead><tbody>
  <?php foreach ($rows as $r): ?><tr><td class="fw-semibold"><?= e((string) $r['household_code']) ?></td><td><?= e((string) $r['street_address']) ?></td><td><?= e((string) ($r['purok'] ?? '—')) ?></td><td><?= (int) $r['members'] ?></td>
    <td class="text-end"><?php if (can('residents.edit')): ?><a class="btn btn-sm btn-light border" href="<?= e(base_url('residents/household_edit.php?id=' . (int) $r['household_id'])) ?>">Edit</a><?php endif; ?> <a class="btn btn-sm btn-light border" href="<?= e(base_url('residents/index.php?q=' . urlencode((string) $r['household_code']))) ?>">Members</a></td></tr><?php endforeach; ?></tbody></table></div>
  <?php render_pagination($total, $page, $per, 'residents/households.php', array_filter(['q' => $q])); endif; ?>
</div>
<?php render_footer();
