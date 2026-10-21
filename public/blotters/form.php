<?php
/** File a blotter case (with its first complainant and an optional respondent) or correct the details of an open case. */
declare(strict_types=1);
require_once __DIR__ . '/../../app/bootstrap.php';

require_login();
$id = get_int('id'); $ex = null;
if ($id !== null) {
    require_permission('blotters.edit');
    $ex = db_one('SELECT * FROM blotters WHERE blotter_id = ?', [$id]);
    if ($ex === null) { abort(404, 'That case was not found.'); }
    if (!case_is_open($ex)) { abort(403, 'Resolved or archived cases cannot be edited.'); }
} else { require_permission('blotters.add'); }

$puroks = lookup_list('purok');
$incidentTypes = lookup_list('incident_type');
$v = ['incident_date' => (string) ($ex['incident_date'] ?? date('Y-m-d')), 'incident_place' => (string) ($ex['incident_place'] ?? ''), 'purok_id' => (string) ($ex['purok_id'] ?? ''), 'incident_type_id' => (string) ($ex['incident_type_id'] ?? ''), 'narrative' => (string) ($ex['narrative'] ?? ''),
      'c_name' => '', 'c_age' => '', 'c_address' => '', 'c_contact' => '', 'r_name' => '', 'r_age' => '', 'r_address' => '', 'r_contact' => ''];
$errors = [];
$oldest = date('Y-m-d', strtotime('-10 years'));

if (is_post()) {
    require_csrf();
    foreach (array_keys($v) as $k) { $v[$k] = $k === 'narrative' ? post_text($k) : post_str($k); }
    if (!v_date($v['incident_date'])) { $errors[] = 'Enter the date of the incident.'; }
    elseif ($v['incident_date'] > date('Y-m-d')) { $errors[] = 'The incident date cannot be in the future.'; }
    elseif ($v['incident_date'] < $oldest) { $errors[] = 'The incident date cannot be more than 10 years ago.'; }
    if ($v['incident_place'] !== '' && !v_len($v['incident_place'], 3, 150)) { $errors[] = 'The place must be 3-150 characters.'; }
    if ($v['purok_id'] !== '' && !isset($puroks[(int) $v['purok_id']]) && (string) ($ex['purok_id'] ?? '') !== $v['purok_id']) { $errors[] = 'Choose a Purok from the list.'; }
    if ($v['incident_type_id'] !== '' && !isset($incidentTypes[(int) $v['incident_type_id']]) && (string) ($ex['incident_type_id'] ?? '') !== $v['incident_type_id']) { $errors[] = 'Choose an incident type from the configured list.'; }
    if (!v_len($v['narrative'], 10, 5000)) { $errors[] = 'Describe what happened (10-5000 characters).'; }
    $c = $r = null;
    if (!$ex) {
        $c = party_clean($v['c_name'], $v['c_age'], $v['c_address'], $v['c_contact'], 'Complainant', $errors);
        if ($v['r_name'] . $v['r_age'] . $v['r_address'] . $v['r_contact'] !== '') { $r = party_clean($v['r_name'], $v['r_age'], $v['r_address'], $v['r_contact'], 'Respondent', $errors); }
    }
    if (!$errors) {
        $nid = db_transaction(function () use ($v, $ex, $c, $r): int {
            $purok = $v['purok_id'] !== '' ? (int) $v['purok_id'] : null; $incidentType = $v['incident_type_id'] !== '' ? (int) $v['incident_type_id'] : null; $place = $v['incident_place'] !== '' ? $v['incident_place'] : null;
            if ($ex) {
                db_exec("UPDATE blotters SET incident_date = ?, incident_place = ?, purok_id = ?, incident_type_id = ?, narrative = ? WHERE blotter_id = ? AND status <> 'Resolved' AND archived_at IS NULL", [$v['incident_date'], $place, $purok, $incidentType, $v['narrative'], $ex['blotter_id']]);
                audit('blotter_updated', 'Blotter', 'Success', $ex['case_no'] . ' details corrected', 'blotter', (int) $ex['blotter_id']);
                return (int) $ex['blotter_id'];
            }
            $no = new_case_no();
            db_exec('INSERT INTO blotters (case_no, incident_date, incident_place, purok_id, incident_type_id, narrative, recorded_at, recorded_by) VALUES (?, ?, ?, ?, ?, ?, NOW(), ?)',
                [$no, $v['incident_date'], $place, $purok, $incidentType, $v['narrative'], audit_actor()['user_id'] ?? null]);
            $new = (int) db()->lastInsertId();
            foreach ([['Complainant', $c], ['Respondent', $r]] as [$role, $pty]) {
                if ($pty) { db_exec('INSERT INTO blotter_parties (blotter_id, party_role, full_name, age, address, contact_number) VALUES (?, ?, ?, ?, ?, ?)', [$new, $role, $pty['name'], $pty['age'], $pty['address'], $pty['contact']]); }
            }
            audit('blotter_filed', 'Blotter', 'Success', $no . ' filed: ' . $c['name'] . ($r ? ' vs ' . $r['name'] : ''), 'blotter', $new);
            return $new;
        });
        flash_add('success', $ex ? 'Case details saved.' : 'Case filed.');
        redirect('blotters/view.php', ['id' => $nid]);
    }
    audit_best_effort('blotter_rejected', 'Blotter', 'Failed', ($ex ? 'edit' : 'file') . ': ' . implode(' ', array_slice($errors, 0, 3)));
}

$title = $ex ? 'Edit ' . $ex['case_no'] : 'File a case';
render_header($title, [['Blotter', 'blotters/index.php'], [$title, null]], 'blotters');
page_head($title, $ex ? 'Correct the incident details. Parties and hearings are managed on the case page.' : 'Record the incident, the complainant and, if known, the respondent.');
echo errors_box($errors);
echo '<form method="post" action="' . e(base_url('blotters/form.php' . ($ex ? '?id=' . (int) $ex['blotter_id'] : ''))) . '" class="needs-validation" novalidate autocomplete="off">' . csrf_field();
card_start('Incident'); echo '<div class="row">';
echo f_text('incident_date', 'Date of incident', $v['incident_date'], ['type' => 'date', 'required' => true, 'max' => date('Y-m-d'), 'min' => $oldest, 'col' => 'col-md-4']);
echo f_text('incident_place', 'Place of incident', $v['incident_place'], ['maxlength' => 150, 'col' => 'col-md-5']);
if ($puroks) { echo f_select('purok_id', 'Purok', $puroks, $v['purok_id'], ['blank' => 'Not stated', 'col' => 'col-md-3']); }
if ($incidentTypes) { echo f_select('incident_type_id', 'Incident type', $incidentTypes, $v['incident_type_id'], ['blank' => 'Not classified', 'col' => 'col-md-6', 'help' => 'Categories are configured in Settings → Other lists.']); }
else { echo '<div class="col-12 text-muted small mb-3">Incident type categories are not configured yet. Add the approved list under Settings → Other lists. Cases can still be recorded as unclassified.</div>'; }
echo f_textarea('narrative', 'What happened', $v['narrative'], ['rows' => 6, 'maxlength' => 5000, 'required' => true, 'help' => 'Facts only: who, what, when, where.']);
echo '</div>'; card_end();
if (!$ex) {
    echo '<div class="my-3"></div>';
    card_start('Complainant', 'The person reporting the incident.'); echo '<div class="row">';
    echo f_text('c_name', 'Full name', $v['c_name'], ['required' => true, 'maxlength' => 100, 'col' => 'col-md-6']) . f_text('c_age', 'Age', $v['c_age'], ['type' => 'number', 'min' => 0, 'max' => 120, 'col' => 'col-md-2']) . f_text('c_contact', 'Contact number', $v['c_contact'], ['maxlength' => 20, 'placeholder' => '09171234567', 'col' => 'col-md-4']);
    echo f_text('c_address', 'Address', $v['c_address'], ['maxlength' => 255, 'col' => 'col-12']); echo '</div>'; card_end();
    echo '<div class="my-3"></div>';
    card_start('Respondent (optional)', 'Leave blank if not known yet. You can add parties later.'); echo '<div class="row">';
    echo f_text('r_name', 'Full name', $v['r_name'], ['maxlength' => 100, 'col' => 'col-md-6']) . f_text('r_age', 'Age', $v['r_age'], ['type' => 'number', 'min' => 0, 'max' => 120, 'col' => 'col-md-2']) . f_text('r_contact', 'Contact number', $v['r_contact'], ['maxlength' => 20, 'placeholder' => '09171234567', 'col' => 'col-md-4']);
    echo f_text('r_address', 'Address', $v['r_address'], ['maxlength' => 255, 'col' => 'col-12']); echo '</div>'; card_end();
}
echo '<div class="mt-3"><button class="btn btn-primary" type="submit">' . ($ex ? 'Save changes' : 'File case') . '</button> <a class="btn btn-light border" href="' . e(base_url($ex ? 'blotters/view.php?id=' . (int) $ex['blotter_id'] : 'blotters/index.php')) . '">Cancel</a></div></form>';
render_footer();
