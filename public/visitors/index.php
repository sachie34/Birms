<?php
/** Visitor logbook: record who came to the counter and why. */
declare(strict_types=1);
require_once __DIR__ . '/../../app/bootstrap.php';

require_login();
require_permission('requests.view');
$canAdd = can('requests.add'); $classes = lookup_list('visit_class'); $errors = [];
$v = ['visitor_name' => '', 'purpose' => '', 'class_id' => '', 'visited_at' => date('Y-m-d\TH:i')];

if (is_post()) {
    require_csrf();
    require_permission('requests.add');
    $v = ['visitor_name' => post_str('visitor_name'), 'purpose' => post_str('purpose'), 'class_id' => post_str('class_id'), 'visited_at' => post_str('visited_at')];
    $file = $_FILES['attachment'] ?? null;
    $when = v_datetime_local($v['visited_at']);
    if (!v_person_name($v['visitor_name'])) { $errors[] = 'Enter the visitor\'s full name (letters, spaces, . \' - only).'; }
    if (!v_len($v['purpose'], 3, 150)) { $errors[] = 'Purpose of visit must be 3-150 characters.'; }
    if ($when === null) { $errors[] = 'Enter a valid date and time.'; }
    elseif (strtotime($when) > time() + 300) { $errors[] = 'The visit time cannot be in the future.'; }
    elseif (strtotime($when) < strtotime('-1 year')) { $errors[] = 'The visit time is more than a year ago. Check it.'; }
    if ($classes && !isset($classes[(int) $v['class_id']])) { $errors[] = 'Choose the classification.'; }
    if (!$classes && $v['class_id'] !== '') { $errors[] = 'No classifications are set up yet.'; }
    $uploadError = attachment_upload_error($file);
    if ($uploadError !== null) { $errors[] = $uploadError; }
    if (!$errors) {
        try {
        $vid = db_transaction(function () use ($v, $when, $file): int {
            db_exec('INSERT INTO visitor_log (visited_at, visitor_name, purpose, class_id, logged_by) VALUES (?, ?, ?, ?, ?)', [$when, $v['visitor_name'], $v['purpose'], $v['class_id'] !== '' ? (int) $v['class_id'] : null, audit_actor()['user_id'] ?? null]);
            $id = (int) db()->lastInsertId();
            store_document_upload('visitor', $id, $file);
            audit('visit_logged', 'Visitors', 'Success', 'Visit logged: ' . $v['visitor_name'], 'visit', $id);
            return $id;
        });
        flash_add('success', 'Visitor entry saved.');
        redirect('visitors/index.php', ['new' => $vid]);
        } catch (RuntimeException $e) { $errors[] = $e->getMessage(); }
    }
    audit_best_effort('visit_rejected', 'Visitors', 'Failed', 'Validation failed (' . count($errors) . ' problem(s))');
}
$q = get_str('q'); $page = max(1, get_int('page') ?? 1); $per = 20; $w = ''; $p = [];
if ($q !== '') { $w = 'WHERE vl.visitor_name LIKE ? OR vl.purpose LIKE ?'; $like = '%' . addcslashes($q, '%_\\') . '%'; $p = [$like, $like]; }
$total = (int) db_val("SELECT COUNT(*) FROM visitor_log vl $w", $p);
$rows = db_all("SELECT vl.visit_id, vl.visited_at, vl.visitor_name, vl.purpose, c.label AS class_label, u.full_name AS logged_by
    FROM visitor_log vl LEFT JOIN lookups c ON c.lookup_id = vl.class_id LEFT JOIN users u ON u.user_id = vl.logged_by $w ORDER BY vl.visited_at DESC, vl.visit_id DESC LIMIT " . (int) $per . ' OFFSET ' . (int) (($page - 1) * $per), $p);
$filesByVisit = [];
$visitIds = array_values(array_unique(array_map(fn($row) => (int) $row['visit_id'], $rows)));
if ($visitIds) {
    $fileRows = db_all('SELECT attachment_id, visit_id, original_name FROM document_attachments WHERE visit_id IN (' . implode(',', $visitIds) . ') ORDER BY attachment_id');
    foreach ($fileRows as $fr) { $filesByVisit[(int) $fr['visit_id']][] = $fr; }
}
$newId = get_int('new');

render_header('Visitor Logbook', [['Visitor Logbook', null]], 'visitors');
page_head('Visitor Logbook', 'Record visitors at the counter and classify what they came for.');
echo errors_box($errors);
if ($newId !== null && can('requests.add')) { echo '<div class="alert alert-info">Entry saved. ' . '<a href="' . e(base_url('requests/form.php?visit=' . $newId)) . '">Start a document request for this visitor</a></div>'; }
?>
<div class="row g-3">
  <div class="col-lg-8"><div class="card-b">
    <form method="get" class="filters" action="<?= e(base_url('visitors/index.php')) ?>"><div><label class="form-label" for="q">Search</label><input class="form-control" id="q" name="q" value="<?= e($q) ?>" maxlength="60" placeholder="Name or purpose"></div><div><button class="btn btn-light border" type="submit">Search</button></div></form>
    <?php if (!$rows): ?><?= empty_state('bi-journal-text', 'No visitor entries yet.') ?><?php else: ?>
    <div class="table-responsive"><table class="table table-b"><thead><tr><th>When</th><th>Visitor</th><th>Purpose</th><th>Class</th><th>Logged by</th><th>Attachments</th><th></th></tr></thead><tbody>
    <?php foreach ($rows as $r): ?><tr><td class="text-nowrap"><?= e(fmt_dt((string) $r['visited_at'])) ?></td><td class="fw-semibold"><?= e((string) $r['visitor_name']) ?></td><td><?= e((string) $r['purpose']) ?></td><td><?= e((string) ($r['class_label'] ?? '—')) ?></td><td class="small text-muted"><?= e((string) ($r['logged_by'] ?? '—')) ?></td><td><?php foreach (($filesByVisit[(int) $r['visit_id']] ?? []) as $attachment): ?><a class="d-block small" href="<?= e(base_url('files/download.php?id=' . (int) $attachment['attachment_id'])) ?>"><?= e((string) $attachment['original_name']) ?></a><?php endforeach; ?><?php if (empty($filesByVisit[(int) $r['visit_id']])): ?><span class="text-muted small">—</span><?php endif; ?></td>
      <td class="text-end"><?php if ($canAdd): ?><a class="btn btn-sm btn-light border" href="<?= e(base_url('requests/form.php?visit=' . (int) $r['visit_id'])) ?>">New request</a><?php endif; ?></td></tr><?php endforeach; ?></tbody></table></div>
    <?php render_pagination($total, $page, $per, 'visitors/index.php', array_filter(['q' => $q])); endif; ?>
  </div></div>
  <div class="col-lg-4">
    <?php if ($canAdd): card_start('New visitor entry', 'Record visitor details and classify the visit.'); ?>
      <form method="post" enctype="multipart/form-data" action="<?= e(base_url('visitors/index.php')) ?>" class="needs-validation" novalidate autocomplete="off"><?= csrf_field() ?><div class="row">
        <?= f_text('visitor_name', 'Visitor full name', $v['visitor_name'], ['required' => true, 'maxlength' => 100, 'col' => 'col-12']) ?>
        <?= f_text('visited_at', 'Date and time', $v['visited_at'], ['type' => 'datetime-local', 'required' => true, 'col' => 'col-12']) ?>
        <?= f_text('purpose', 'Purpose of visit', $v['purpose'], ['required' => true, 'maxlength' => 150, 'col' => 'col-12']) ?>
        <div class="col-12 mb-3"><label class="form-label" for="attachment">Supporting document (optional)</label><input class="form-control" id="attachment" name="attachment" type="file" accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png"><div class="form-text">PDF, JPG or PNG; maximum 10 MB. Files are stored privately.</div></div>
        <?php if ($classes): ?><?= f_select('class_id', 'Classification', $classes, $v['class_id'], ['required' => true, 'blank' => 'Select', 'col' => 'col-12']) ?><?php else: ?><div class="col-12 text-muted small mb-3">No classifications entered yet (Settings, Other lists).</div><?php endif; ?>
      </div><button class="btn btn-primary w-100" type="submit"><i class="bi bi-check-lg me-1"></i>Save entry</button></form>
    <?php card_end(); endif; ?>
  </div>
</div>
<?php render_footer();
