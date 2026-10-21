<?php
/** Register or edit a resident (with optional new household). */
declare(strict_types=1);
require_once __DIR__ . '/../../app/bootstrap.php';

require_login();
$id = get_int('id'); $ex = null;
if ($id !== null) {
    require_permission('residents.edit');
    $ex = db_one('SELECT * FROM residents WHERE resident_id = ?', [$id]);
    if ($ex === null || $ex['archived_at'] !== null) { abort(404, 'That resident was not found or is archived.'); }
} else { require_permission('residents.add'); }

$puroks = lookup_list('purok'); $cats = lookup_list('resident_category'); $employmentStatuses = lookup_list('employment_status'); $households = households_for_select();
$errors = []; $dupFound = false;
$v = ['last_name' => $ex['last_name'] ?? '', 'first_name' => $ex['first_name'] ?? '', 'middle_name' => $ex['middle_name'] ?? '', 'suffix' => $ex['suffix'] ?? '',
      'birthdate' => $ex['birthdate'] ?? '', 'sex' => $ex['sex'] ?? '', 'civil_status' => $ex['civil_status'] ?? '', 'contact_number' => $ex['contact_number'] ?? '',
      'category_id' => (string) ($ex['category_id'] ?? ''), 'employment_status_id' => (string) ($ex['employment_status_id'] ?? ''), 'voter_status' => (string) ($ex['voter_status'] ?? 'Unverified'), 'household_id' => (string) ($ex['household_id'] ?? ''), 'is_head' => (int) ($ex['is_household_head'] ?? 0) === 1,
      'residency_status' => $ex['residency_status'] ?? 'Active', 'new_address' => '', 'new_code' => '', 'new_purok' => '', 'confirm_dup' => false];

if (is_post()) {
    require_csrf();
    foreach (['last_name', 'first_name', 'middle_name', 'suffix', 'birthdate', 'sex', 'civil_status', 'contact_number', 'category_id', 'employment_status_id', 'voter_status', 'household_id', 'new_address', 'new_code', 'new_purok'] as $k) { $v[$k] = post_str($k); }
    $v['is_head'] = post_str('is_head') === '1'; $v['confirm_dup'] = post_str('confirm_dup') === '1';
    $v['residency_status'] = $ex ? post_str('residency_status') : 'Active';

    if (!v_person_name($v['last_name']))  { $errors[] = 'Last name is required (letters, spaces, . \' - only).'; }
    if (!v_person_name($v['first_name'])) { $errors[] = 'First name is required (letters, spaces, . \' - only).'; }
    if ($v['middle_name'] !== '' && !v_person_name($v['middle_name'])) { $errors[] = 'Middle name has invalid characters.'; }
    if ($v['suffix'] !== '' && !preg_match('/^[A-Za-z0-9.]{1,10}$/', $v['suffix'])) { $errors[] = 'Suffix is not valid (example: Jr., III).'; }
    if (!v_date($v['birthdate'])) { $errors[] = 'Enter a valid birthdate.'; }
    elseif ($v['birthdate'] > date('Y-m-d')) { $errors[] = 'Birthdate cannot be in the future.'; }
    elseif (age_on($v['birthdate']) > 120) { $errors[] = 'Birthdate is more than 120 years ago. Check it.'; }
    if (!in_array($v['sex'], ['Male', 'Female'], true)) { $errors[] = 'Choose the sex.'; }
    if (!in_array($v['civil_status'], ['Single', 'Married', 'Widowed', 'Separated', 'Divorced'], true)) { $errors[] = 'Choose the civil status.'; }
    if ($v['contact_number'] !== '' && !v_phone($v['contact_number'])) { $errors[] = 'Contact number is not valid (example: 09171234567).'; }
    if ($ex && !in_array($v['residency_status'], ['Active', 'Relocated'], true)) { $errors[] = 'Choose a residency status.'; }
    if ($v['category_id'] !== '' && !isset($cats[(int) $v['category_id']]) && (string) ($ex['category_id'] ?? '') !== $v['category_id']) { $errors[] = 'That category is not available.'; }
    if ($v['employment_status_id'] !== '' && !isset($employmentStatuses[(int) $v['employment_status_id']]) && (string) ($ex['employment_status_id'] ?? '') !== $v['employment_status_id']) { $errors[] = 'Choose an employment status from the configured list.'; }
    if (!in_array($v['voter_status'], ['Unverified', 'Registered', 'Not registered'], true)) { $errors[] = 'Choose a valid voter registration status.'; }

    $newHh = $v['new_address'] !== '';
    if ($newHh && $v['household_id'] !== '') { $errors[] = 'Choose an existing household OR create a new one, not both.'; }
    if ($newHh) {
        if (!v_len($v['new_address'], 3, 150)) { $errors[] = 'Household address must be 3-150 characters.'; }
        if ($v['new_code'] !== '' && !preg_match('/^[A-Za-z0-9-]{1,20}$/', $v['new_code'])) { $errors[] = 'Household code may contain letters, numbers and dashes only (max 20).'; }
        elseif ($v['new_code'] !== '' && db_val('SELECT COUNT(*) FROM households WHERE household_code = ?', [$v['new_code']])) { $errors[] = 'That household code is already used.'; }
        if ($puroks && !isset($puroks[(int) $v['new_purok']])) { $errors[] = 'Choose the Purok of the new household.'; }
        if (!$puroks && $v['new_purok'] !== '') { $errors[] = 'No Puroks are set up yet.'; }
    } elseif ($v['household_id'] !== '' && !isset($households[(int) $v['household_id']])) { $errors[] = 'That household does not exist.'; }
    if ($v['is_head'] && !$newHh && $v['household_id'] === '') { $errors[] = 'A household head needs a household.'; }

    $identityChanged = $ex === null || $ex['first_name'] !== $v['first_name'] || $ex['last_name'] !== $v['last_name'] || (string) $ex['birthdate'] !== $v['birthdate'];
    if (!$errors && v_date($v['birthdate']) && $identityChanged) {
        $dup = db_one('SELECT resident_id FROM residents WHERE first_name = ? AND last_name = ? AND birthdate = ?' . ($ex ? ' AND resident_id <> ' . (int) $ex['resident_id'] : ''), [$v['first_name'], $v['last_name'], $v['birthdate']]);
        if ($dup && !$v['confirm_dup']) { $dupFound = true; $errors[] = 'A resident with the same name and birthdate already exists (record #' . (int) $dup['resident_id'] . '). If this is a different person, tick the box below and save again.'; }
    }

    if (!$errors) {
        $newId = db_transaction(function () use ($v, $ex, $newHh): int {
            $hid = $v['household_id'] !== '' ? (int) $v['household_id'] : null;
            if ($newHh) {
                $code = $v['new_code'] !== '' ? $v['new_code'] : 'HH-' . sprintf('%04d', next_counter('household', 0));
                db_exec('INSERT INTO households (household_code, purok_id, street_address) VALUES (?, ?, ?)', [$code, $v['new_purok'] !== '' ? (int) $v['new_purok'] : null, $v['new_address']]);
                $hid = (int) db()->lastInsertId();
                audit('household_created', 'Residents', 'Success', 'Household ' . $code, 'household', $hid);
            }
            $cols = [$hid, $v['last_name'], $v['first_name'], $v['middle_name'] ?: null, $v['suffix'] ?: null, $v['birthdate'], $v['sex'], $v['civil_status'], $v['contact_number'] ?: null,
                     $v['category_id'] !== '' ? (int) $v['category_id'] : null, $v['employment_status_id'] !== '' ? (int) $v['employment_status_id'] : null, $v['voter_status'], $hid !== null && $v['is_head'] ? 1 : 0];
            if ($ex === null) {
                db_exec('INSERT INTO residents (household_id, last_name, first_name, middle_name, suffix, birthdate, sex, civil_status, contact_number, category_id, employment_status_id, voter_status, is_household_head, created_by)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', array_merge($cols, [audit_actor()['user_id'] ?? null]));
                $rid = (int) db()->lastInsertId();
                audit('resident_created', 'Residents', 'Success', 'Registered: ' . $v['last_name'] . ', ' . $v['first_name'], 'resident', $rid);
            } else {
                $rid = (int) $ex['resident_id'];
                db_exec('UPDATE residents SET household_id = ?, last_name = ?, first_name = ?, middle_name = ?, suffix = ?, birthdate = ?, sex = ?, civil_status = ?, contact_number = ?, category_id = ?, employment_status_id = ?, voter_status = ?, is_household_head = ?, residency_status = ? WHERE resident_id = ?',
                    array_merge($cols, [$v['residency_status'], $rid]));
                $ch = [];
                foreach (['last_name', 'first_name', 'middle_name', 'suffix', 'birthdate', 'sex', 'civil_status', 'contact_number', 'residency_status', 'employment_status_id', 'voter_status'] as $k) { if ((string) $ex[$k] !== (string) ($v[$k] ?? '')) { $ch[] = $k . ($k === 'civil_status' || $k === 'residency_status' || $k === 'voter_status' ? ': ' . $ex[$k] . ' -> ' . $v[$k] : ' changed'); } }
                if ((int) ($ex['household_id'] ?? 0) !== (int) ($hid ?? 0)) { $ch[] = 'household changed'; }
                audit('resident_updated', 'Residents', 'Success', ($ch ? implode('; ', $ch) : 'No field changed') . ' [' . $v['last_name'] . ', ' . $v['first_name'] . ']', 'resident', $rid);
            }
            if ($hid !== null && $cols[12] === 1) { db_exec('UPDATE residents SET is_household_head = 0 WHERE household_id = ? AND resident_id <> ?', [$hid, $rid]); }
            return $rid;
        });
        flash_add('success', $ex ? 'Resident updated.' : 'Resident registered.');
        redirect('residents/view.php', ['id' => $newId]);
    }
    audit_best_effort('resident_rejected', 'Residents', 'Failed', 'Validation failed (' . count($errors) . ' problem(s))');
}

$title = $ex ? 'Edit resident' : 'Register resident';
render_header($title, [['Residents', 'residents/index.php'], [$title, null]], 'residents');
page_head($title, 'Fields marked with a red asterisk are required.');
echo errors_box($errors);
$action = base_url('residents/form.php' . ($ex ? '?id=' . (int) $ex['resident_id'] : ''));
?>
<form method="post" action="<?= e($action) ?>" class="needs-validation" novalidate autocomplete="off"><?= csrf_field() ?>
<div class="row g-3">
  <div class="col-lg-4"><div class="card-b h-100"><div class="card-h"><h2>Personal information</h2></div><div class="card-body-b"><div class="row">
    <?= f_text('last_name', 'Last name', $v['last_name'], ['required' => true, 'maxlength' => 50, 'col' => 'col-12']) ?>
    <?= f_text('first_name', 'First name', $v['first_name'], ['required' => true, 'maxlength' => 60, 'col' => 'col-12']) ?>
    <?= f_text('middle_name', 'Middle name', $v['middle_name'], ['maxlength' => 50, 'col' => 'col-8']) ?>
    <?= f_text('suffix', 'Suffix', $v['suffix'], ['maxlength' => 10, 'placeholder' => 'Jr., III', 'col' => 'col-4']) ?>
  </div></div></div></div>
  <div class="col-lg-4"><div class="card-b h-100"><div class="card-h"><h2>Demographic information</h2></div><div class="card-body-b"><div class="row">
    <?= f_text('birthdate', 'Birthdate', (string) $v['birthdate'], ['type' => 'date', 'required' => true, 'max' => date('Y-m-d'), 'col' => 'col-12']) ?>
    <?= f_select('sex', 'Sex', ['Male' => 'Male', 'Female' => 'Female'], (string) $v['sex'], ['required' => true, 'blank' => 'Select', 'col' => 'col-6']) ?>
    <?= f_select('civil_status', 'Civil status', array_combine(['Single', 'Married', 'Widowed', 'Separated', 'Divorced'], ['Single', 'Married', 'Widowed', 'Separated', 'Divorced']), (string) $v['civil_status'], ['required' => true, 'blank' => 'Select', 'col' => 'col-6']) ?>
    <?= f_text('contact_number', 'Contact number', (string) $v['contact_number'], ['maxlength' => 20, 'placeholder' => '09171234567', 'col' => 'col-12']) ?>
    <?php if ($cats): ?><?= f_select('category_id', 'Category', $cats, (string) $v['category_id'], ['blank' => 'None', 'col' => 'col-12']) ?><?php endif; ?>
    <?php if ($employmentStatuses): ?><?= f_select('employment_status_id', 'Employment status', $employmentStatuses, (string) $v['employment_status_id'], ['blank' => 'Not specified', 'col' => 'col-12', 'help' => 'Values are configured in Settings → Other lists.']) ?><?php else: ?><div class="col-12 text-muted small">Employment status options are not configured yet. Add the approved list under Settings → Other lists.</div><?php endif; ?>
    <?= f_select('voter_status', 'Voter registration status', ['Unverified' => 'Unverified', 'Registered' => 'Registered', 'Not registered' => 'Not registered'], (string) $v['voter_status'], ['required' => true, 'col' => 'col-12', 'help' => 'This is the status recorded by barangay staff, not an automatic COMELEC verification.']) ?>
    <?php if ($ex): ?><?= f_select('residency_status', 'Residency status', ['Active' => 'Active', 'Relocated' => 'Relocated'], (string) $v['residency_status'], ['col' => 'col-12']) ?><?php endif; ?>
  </div></div></div></div>
  <div class="col-lg-4"><div class="card-b h-100"><div class="card-h"><h2>Household and address</h2></div><div class="card-body-b"><div class="row">
    <?= f_select('household_id', 'Existing household', $households, (string) $v['household_id'], ['blank' => 'None / choose below to create', 'col' => 'col-12']) ?>
    <div class="col-12 text-muted small mb-2">Or create a new household:</div>
    <?= f_text('new_address', 'New household address', $v['new_address'], ['maxlength' => 150, 'col' => 'col-12']) ?>
    <?= f_text('new_code', 'Household code (optional)', $v['new_code'], ['maxlength' => 20, 'help' => 'Blank = automatic', 'col' => 'col-6']) ?>
    <?php if ($puroks): ?><?= f_select('new_purok', 'Purok (new household)', $puroks, (string) $v['new_purok'], ['blank' => 'Select', 'col' => 'col-6']) ?><?php else: ?><div class="col-6 text-muted small">No Puroks entered yet (Settings, Puroks).</div><?php endif; ?>
    <?= f_check('is_head', 'Head of this household', (bool) $v['is_head']) ?>
  </div></div></div></div>
</div>
<?php if ($dupFound): ?><div class="card-b mt-3"><div class="card-body-b pt-3"><?= f_check('confirm_dup', 'This is a different person with the same name and birthdate', false) ?></div></div><?php endif; ?>
<div class="mt-3"><button class="btn btn-primary" type="submit"><?= $ex ? 'Save changes' : 'Register resident' ?></button> <a class="btn btn-light border" href="<?= e(base_url($ex ? 'residents/view.php?id=' . (int) $ex['resident_id'] : 'residents/index.php')) ?>">Cancel</a></div>
</form>
<?php render_footer();
