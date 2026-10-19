<?php
/** Create or edit a household. */
declare(strict_types=1);
require_once __DIR__ . '/../../app/bootstrap.php';

require_login();
$id = get_int('id'); $ex = null;
if ($id !== null) { require_permission('residents.edit'); $ex = db_one('SELECT * FROM households WHERE household_id = ?', [$id]); if ($ex === null) { abort(404, 'That household was not found.'); } }
else { require_permission('residents.add'); }
$puroks = lookup_list('purok'); $errors = [];
$v = ['code' => $ex['household_code'] ?? '', 'purok' => (string) ($ex['purok_id'] ?? ''), 'address' => $ex['street_address'] ?? ''];

if (is_post()) {
    require_csrf();
    $v = ['code' => post_str('code'), 'purok' => post_str('purok'), 'address' => post_str('address')];
    if ($v['code'] !== '' && !preg_match('/^[A-Za-z0-9-]{1,20}$/', $v['code'])) { $errors[] = 'Code may contain letters, numbers and dashes only (max 20).'; }
    if ($ex && $v['code'] === '') { $errors[] = 'The code cannot be blank.'; }
    if ($v['code'] !== '' && db_val('SELECT COUNT(*) FROM households WHERE household_code = ?' . ($ex ? ' AND household_id <> ' . (int) $ex['household_id'] : ''), [$v['code']])) { $errors[] = 'That code is already used.'; }
    if (!v_len($v['address'], 3, 150)) { $errors[] = 'Address must be 3-150 characters.'; }
    if ($puroks && !isset($puroks[(int) $v['purok']]) && (string) ($ex['purok_id'] ?? '') !== $v['purok']) { $errors[] = 'Choose the Purok.'; }
    if (!$errors) {
        $hid = db_transaction(function () use ($v, $ex): int {
            $purok = $v['purok'] !== '' ? (int) $v['purok'] : null;
            if ($ex) {
                db_exec('UPDATE households SET household_code = ?, purok_id = ?, street_address = ? WHERE household_id = ?', [$v['code'], $purok, $v['address'], $ex['household_id']]);
                audit('household_updated', 'Residents', 'Success', 'Household ' . $v['code'], 'household', (int) $ex['household_id']); return (int) $ex['household_id'];
            }
            $code = $v['code'] !== '' ? $v['code'] : 'HH-' . sprintf('%04d', next_counter('household', 0));
            db_exec('INSERT INTO households (household_code, purok_id, street_address) VALUES (?, ?, ?)', [$code, $purok, $v['address']]);
            $new = (int) db()->lastInsertId(); audit('household_created', 'Residents', 'Success', 'Household ' . $code, 'household', $new); return $new;
        });
        flash_add('success', 'Household saved.'); redirect('residents/households.php');
    }
}
$title = $ex ? 'Edit household' : 'Add household';
render_header($title, [['Households', 'residents/households.php'], [$title, null]], 'households');
page_head($title);
echo errors_box($errors);
card_start();
echo '<form method="post" action="' . e(base_url('residents/household_edit.php' . ($ex ? '?id=' . (int) $ex['household_id'] : ''))) . '" class="needs-validation" novalidate>' . csrf_field() . '<div class="row">';
echo f_text('code', 'Household code', $v['code'], ['maxlength' => 20, 'required' => (bool) $ex, 'help' => $ex ? '' : 'Blank = automatic']);
if ($puroks) { echo f_select('purok', 'Purok', $puroks, $v['purok'], ['required' => true, 'blank' => 'Select']); } else { echo '<div class="col-md-6 mb-3 text-muted small pt-4">No Puroks entered yet (Settings, Puroks).</div>'; }
echo f_text('address', 'Address', $v['address'], ['required' => true, 'maxlength' => 150, 'col' => 'col-12']);
echo '</div><button class="btn btn-primary" type="submit">Save</button> <a class="btn btn-light border" href="' . e(base_url('residents/households.php')) . '">Cancel</a></form>';
card_end();
render_footer();
