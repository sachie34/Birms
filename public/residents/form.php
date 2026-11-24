<?php
/** Register or edit a resident using the revised MSIT SRS resident profile. */
declare(strict_types=1);
require_once __DIR__ . '/../../app/bootstrap.php';

require_login();
$id = get_int('id');
$ex = null;
if ($id !== null) {
    require_permission('residents.edit');
    $ex = db_one('SELECT * FROM residents WHERE resident_id = ?', [$id]);
    if ($ex === null || $ex['archived_at'] !== null) { abort(404, 'That resident was not found or is archived.'); }
} else {
    require_permission('residents.add');
}

$puroks = lookup_list('purok');
$statuses = lookup_list('resident_status');
$types = lookup_list('resident_type');
$defaultStatusId = array_search('Active', $statuses, true);
$defaultTypeId = array_search('None', $types, true);

$existingStatusId = (string) ($ex['resident_status_id'] ?? '');
if ($ex !== null && $existingStatusId === '') {
    $legacyStatus = (string) ($ex['residency_status'] ?? 'Active');
    $wanted = $legacyStatus === 'Relocated' ? 'Transferred' : 'Active';
    $found = array_search($wanted, $statuses, true);
    $existingStatusId = $found === false ? '' : (string) $found;
}
$existingTypeId = (string) ($ex['resident_type_id'] ?? '');
if ($ex !== null && $existingTypeId === '') {
    $oldCategory = $ex['category_id'] ? db_val('SELECT label FROM lookups WHERE lookup_id = ?', [(int) $ex['category_id']]) : null;
    $found = $oldCategory ? array_search((string) $oldCategory, $types, true) : false;
    $existingTypeId = $found === false ? (string) ($defaultTypeId === false ? '' : $defaultTypeId) : (string) $found;
}

$legacyAddress = '';
$legacyPurokId = '';
if ($ex !== null && ($ex['household_id'] ?? null) !== null) {
    $legacyHousehold = db_one('SELECT street_address, purok_id FROM households WHERE household_id = ?', [(int) $ex['household_id']]);
    if ($legacyHousehold !== null) {
        $legacyAddress = (string) $legacyHousehold['street_address'];
        $legacyPurokId = (string) ($legacyHousehold['purok_id'] ?? '');
    }
}

$errors = [];
$dupFound = false;
$v = [
    'last_name' => $ex['last_name'] ?? '',
    'first_name' => $ex['first_name'] ?? '',
    'middle_name' => $ex['middle_name'] ?? '',
    'suffix' => $ex['suffix'] ?? '',
    'birthdate' => $ex['birthdate'] ?? '',
    'sex' => $ex['sex'] ?? '',
    'marital_status' => $ex['marital_status'] ?? $ex['civil_status'] ?? 'Single',
    'address' => $ex['address'] ?? $legacyAddress,
    'purok_id' => (string) ($ex['purok_id'] ?? $legacyPurokId),
    'resident_status_id' => $existingStatusId !== '' ? $existingStatusId : (string) ($defaultStatusId === false ? '' : $defaultStatusId),
    'resident_type_id' => $existingTypeId !== '' ? $existingTypeId : (string) ($defaultTypeId === false ? '' : $defaultTypeId),
    'contact_number' => $ex['contact_number'] ?? '',
    'email_address' => $ex['email_address'] ?? '',
    'photo_path' => $ex['photo_path'] ?? null,
    'confirm_dup' => false,
];

if (is_post()) {
    require_csrf();
    foreach (['last_name','first_name','middle_name','suffix','birthdate','sex','marital_status','address','purok_id',
              'resident_status_id','resident_type_id','contact_number','email_address'] as $k) {
        $v[$k] = post_str($k);
    }
    $v['confirm_dup'] = post_str('confirm_dup') === '1';

    if (!v_person_name($v['last_name'])) { $errors[] = 'Last name is required (letters, spaces, . \' - only).'; }
    if (!v_person_name($v['first_name'])) { $errors[] = 'First name is required (letters, spaces, . \' - only).'; }
    if ($v['middle_name'] !== '' && !v_person_name($v['middle_name'])) { $errors[] = 'Middle name has invalid characters.'; }
    if ($v['suffix'] !== '' && !preg_match('/^[A-Za-z0-9.]{1,10}$/', $v['suffix'])) { $errors[] = 'Suffix is not valid (example: Jr., III).'; }
    if (!v_date($v['birthdate'])) { $errors[] = 'Enter a valid birthdate.'; }
    elseif ($v['birthdate'] > date('Y-m-d')) { $errors[] = 'Birthdate cannot be in the future.'; }
    elseif (age_on($v['birthdate']) > 120) { $errors[] = 'Birthdate is more than 120 years ago. Check it.'; }
    if (!in_array($v['sex'], ['Male','Female'], true)) { $errors[] = 'Choose the sex.'; }
    if (!in_array($v['marital_status'], ['Single','Married','Widowed','Separated','Divorced'], true)) { $errors[] = 'Choose a valid marital status.'; }
    if (!v_len($v['address'], 3, 255)) { $errors[] = 'Enter the resident\'s complete address (3-255 characters).'; }
    if ($v['purok_id'] === '' || !isset($puroks[(int) $v['purok_id']])) { $errors[] = 'Choose the resident\'s Purok.'; }
    if ($v['resident_status_id'] === '' || !isset($statuses[(int) $v['resident_status_id']])) { $errors[] = 'Choose a valid resident status.'; }
    if ($v['resident_type_id'] === '' || !isset($types[(int) $v['resident_type_id']])) { $errors[] = 'Choose a valid resident type.'; }
    if ($v['contact_number'] !== '' && !v_phone($v['contact_number'])) { $errors[] = 'Mobile number is not valid (example: 09171234567).'; }
    if ($v['email_address'] !== '' && !v_email($v['email_address'])) { $errors[] = 'Enter a valid email address.'; }
    $photoFile = $_FILES['photo'] ?? null;
    $photoError = resident_photo_upload_error(is_array($photoFile) ? $photoFile : null);
    if ($photoError !== null) { $errors[] = $photoError; }

    $identityChanged = $ex === null
        || $ex['first_name'] !== $v['first_name']
        || $ex['last_name'] !== $v['last_name']
        || (string) $ex['birthdate'] !== $v['birthdate'];
    if (!$errors && $identityChanged) {
        $dup = db_one('SELECT resident_id FROM residents WHERE first_name = ? AND last_name = ? AND birthdate = ?'
            . ($ex ? ' AND resident_id <> ' . (int) $ex['resident_id'] : ''),
            [$v['first_name'], $v['last_name'], $v['birthdate']]);
        if ($dup && !$v['confirm_dup']) {
            $dupFound = true;
            $errors[] = 'A resident with the same name and birthdate already exists (record #' . (int) $dup['resident_id'] . '). If this is a different person, tick the box below and save again.';
        }
    }

    $newPhotoPath = null;
    if (!$errors && is_array($photoFile) && (int) ($photoFile['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
        try {
            $newPhotoPath = resident_photo_store($photoFile);
        } catch (RuntimeException $photoException) {
            $errors[] = $photoException->getMessage();
        }
    }

    if (!$errors) {
        $photoPath = $newPhotoPath ?? ($ex['photo_path'] ?? null);
        try {
        $savedId = db_transaction(function () use ($v, $ex, $photoPath): int {
            $statusId = (int) $v['resident_status_id'];
            $typeId = (int) $v['resident_type_id'];
            $statusLabel = (string) db_val('SELECT label FROM lookups WHERE lookup_id = ? AND list_key = \'resident_status\'', [$statusId]);
            $typeLabel = (string) db_val('SELECT label FROM lookups WHERE lookup_id = ? AND list_key = \'resident_type\'', [$typeId]);
            if ($statusLabel === '' || $typeLabel === '') { throw new RuntimeException('The selected resident status or type is no longer available. Refresh the form and try again.'); }
            $legacyStatus = $statusLabel === 'Active' ? 'Active' : 'Relocated';
            $legacyCategory = db_val('SELECT lookup_id FROM lookups WHERE list_key = \'resident_category\' AND label = ?', [$typeLabel]);
            $residentNo = $ex !== null && !empty($ex['resident_no'])
                ? (string) $ex['resident_no']
                : sprintf('RES-%d-%06d', (int) date('Y'), next_counter('resident', (int) date('Y')));
            $previousStatusId = (int) ($ex['resident_status_id'] ?? 0);
            $previousStatusLabel = $previousStatusId > 0
                ? (string) db_val('SELECT label FROM lookups WHERE lookup_id = ? AND list_key = \'resident_status\'', [$previousStatusId])
                : '';
            $legacyVoter = $ex['voter_status'] ?? 'Unverified';
            $employmentId = $ex['employment_status_id'] ?? null;
            $common = [
                $residentNo, $v['address'], (int) $v['purok_id'], $statusId, $v['marital_status'], $typeId,
                $v['contact_number'] !== '' ? preg_replace('/[\s-]/', '', $v['contact_number']) : null,
                $v['email_address'] !== '' ? strtolower($v['email_address']) : null, $photoPath,
                $v['last_name'], $v['first_name'], $v['middle_name'] !== '' ? $v['middle_name'] : null,
                $v['suffix'] !== '' ? $v['suffix'] : null, $v['birthdate'], $v['sex'], $v['marital_status'],
                $legacyCategory ? (int) $legacyCategory : null, $employmentId, $legacyVoter, $legacyStatus,
            ];
            if ($ex === null) {
                db_exec('INSERT INTO residents
                    (resident_no,address,purok_id,resident_status_id,marital_status,resident_type_id,contact_number,email_address,photo_path,
                     last_name,first_name,middle_name,suffix,birthdate,sex,civil_status,category_id,employment_status_id,voter_status,residency_status,
                     household_id,is_household_head,created_by)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NULL,0,?)',
                    array_merge($common, [audit_actor()['user_id'] ?? null]));
                $rid = (int) db()->lastInsertId();
                audit('resident_created', 'Residents', 'Success', 'Registered ' . $residentNo . ': ' . $v['last_name'] . ', ' . $v['first_name'], 'resident', $rid);
                return $rid;
            }

            $rid = (int) $ex['resident_id'];
            db_exec('UPDATE residents SET
                resident_no=?, address=?, purok_id=?, resident_status_id=?, marital_status=?, resident_type_id=?,
                contact_number=?, email_address=?, photo_path=?,
                last_name=?, first_name=?, middle_name=?, suffix=?, birthdate=?, sex=?, civil_status=?,
                category_id=?, employment_status_id=?, voter_status=?, residency_status=?
                WHERE resident_id=?',
                array_merge($common, [$rid]));

            $changes = [];
            foreach (['last_name','first_name','middle_name','suffix','birthdate','sex','marital_status','address','contact_number','email_address'] as $key) {
                if ((string) ($ex[$key] ?? '') !== (string) ($v[$key] ?? '')) { $changes[] = $key . ' changed'; }
            }
            foreach (['purok_id','resident_status_id','resident_type_id'] as $key) {
                if ((string) ($ex[$key] ?? '') !== (string) ($v[$key] ?? '')) { $changes[] = $key . ' changed'; }
            }
            audit('resident_updated', 'Residents', 'Success', ($changes ? implode('; ', $changes) : 'No field changed') . ' [' . $residentNo . ']',
                'resident', $rid);
            if ($previousStatusId !== $statusId) {
                audit('resident_status_changed', 'Residents', 'Success', $residentNo . ': ' . ($previousStatusLabel !== '' ? $previousStatusLabel : 'Unassigned') . ' -> ' . $statusLabel, 'resident', $rid);
            }
            return $rid;
        });
        if ($newPhotoPath !== null && !empty($ex['photo_path'])) {
            resident_photo_delete((string) $ex['photo_path']);
        }
        flash_add('success', $ex ? 'Resident record updated.' : 'Resident registered.');
        redirect('residents/view.php', ['id' => $savedId]);
        } catch (Throwable $saveException) {
            if ($newPhotoPath !== null) { resident_photo_delete($newPhotoPath); }
            error_log('BIRMS resident save failed: ' . $saveException->getMessage());
            $errors[] = 'The resident record could not be saved. Check the entered information and try again.';
        }
    }
    audit_best_effort('resident_rejected', 'Residents', 'Failed', 'Validation failed (' . count($errors) . ' problem(s))');
}

$title = $ex ? 'Edit resident' : 'Register resident';
render_header($title, [['Residents', 'residents/index.php'], [$title, null]], 'residents');
page_head($title, 'Maintain the resident\'s individual address, Purok, status and resident type. Household grouping is no longer used.');
echo errors_box($errors);
$action = base_url('residents/form.php' . ($ex ? '?id=' . (int) $ex['resident_id'] : ''));
?>
<form method="post" action="<?= e($action) ?>" class="needs-validation" novalidate autocomplete="off" enctype="multipart/form-data"><?= csrf_field() ?>
<div class="row g-3">
  <div class="col-lg-4"><div class="card-b h-100"><div class="card-h"><h2>Personal information</h2></div><div class="card-body-b"><div class="row">
    <?php if ($ex): ?><div class="col-12 mb-3"><span class="text-muted small">Resident ID</span><div class="fw-semibold"><?= e((string) $ex['resident_no']) ?></div></div><?php endif; ?>
    <?= f_text('last_name', 'Last name', $v['last_name'], ['required' => true, 'maxlength' => 50, 'col' => 'col-12']) ?>
    <?= f_text('first_name', 'First name', $v['first_name'], ['required' => true, 'maxlength' => 60, 'col' => 'col-12']) ?>
    <?= f_text('middle_name', 'Middle name', $v['middle_name'], ['maxlength' => 50, 'col' => 'col-8']) ?>
    <?= f_text('suffix', 'Suffix', $v['suffix'], ['maxlength' => 10, 'placeholder' => 'Jr., III', 'col' => 'col-4']) ?>
    <?= f_text('birthdate', 'Birthdate', (string) $v['birthdate'], ['type' => 'date', 'required' => true, 'max' => date('Y-m-d'), 'col' => 'col-12']) ?>
    <?= f_select('sex', 'Sex', ['Male' => 'Male', 'Female' => 'Female'], (string) $v['sex'], ['required' => true, 'blank' => 'Select', 'col' => 'col-6']) ?>
    <?= f_select('marital_status', 'Marital status', ['Single'=>'Single','Married'=>'Married','Widowed'=>'Widowed','Separated'=>'Separated','Divorced'=>'Divorced'], (string) $v['marital_status'], ['required' => true, 'blank' => 'Select', 'col' => 'col-6']) ?>
  </div></div></div></div>
  <div class="col-lg-4"><div class="card-b h-100"><div class="card-h"><h2>Address and classification</h2></div><div class="card-body-b"><div class="row">
    <?= f_text('address', 'Complete address', $v['address'], ['required' => true, 'maxlength' => 255, 'placeholder' => 'House number, street, subdivision or sitio', 'col' => 'col-12']) ?>
    <?= f_select('purok_id', 'Purok', $puroks, (string) $v['purok_id'], ['required' => true, 'blank' => 'Select Purok', 'col' => 'col-12']) ?>
    <?= f_select('resident_status_id', 'Resident status', $statuses, (string) $v['resident_status_id'], ['required' => true, 'blank' => 'Select status', 'col' => 'col-12']) ?>
    <?= f_select('resident_type_id', 'Resident type', $types, (string) $v['resident_type_id'], ['required' => true, 'blank' => 'Select type', 'col' => 'col-12']) ?>
  </div></div></div></div>
  <div class="col-lg-4"><div class="card-b h-100"><div class="card-h"><h2>Contact information</h2></div><div class="card-body-b"><div class="row">
    <?= f_text('contact_number', 'Mobile number', (string) $v['contact_number'], ['maxlength' => 20, 'placeholder' => '09171234567', 'col' => 'col-12']) ?>
    <?= f_text('email_address', 'Email address', (string) $v['email_address'], ['type' => 'email', 'maxlength' => 100, 'placeholder' => 'resident@example.com', 'col' => 'col-12']) ?>
    <div class="col-12">
      <label class="form-label" for="photo">Resident photo (JPG or PNG, maximum 5 MB)</label>
      <input class="form-control" type="file" id="photo" name="photo" accept=".jpg,.jpeg,.png,image/jpeg,image/png">
      <?php if ($ex && !empty($ex['photo_path'])): ?><div class="mt-2"><img src="<?= e(base_url('residents/photo.php?id=' . (int) $ex['resident_id'])) ?>" alt="Current resident photo" width="112" height="112" class="img-thumbnail"><div class="form-text">Choose a new image to replace the current photo.</div></div><?php endif; ?>
    </div>
    <p class="form-text">Resident status updates and kiosk verification use the registered email address only. Residents without email can be verified at the Staff counter.</p>
  </div></div></div></div>
</div>
<?php if ($dupFound): ?><div class="card-b mt-3"><div class="card-body-b pt-3"><?= f_check('confirm_dup', 'This is a different person with the same name and birthdate', false) ?></div></div><?php endif; ?>
<div class="mt-3"><button class="btn btn-primary" type="submit"><?= $ex ? 'Save changes' : 'Register resident' ?></button> <a class="btn btn-light border" href="<?= e(base_url($ex ? 'residents/view.php?id=' . (int) $ex['resident_id'] : 'residents/index.php')) ?>">Cancel</a></div>
</form>
<?php render_footer();
