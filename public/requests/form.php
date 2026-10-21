<?php
/** File a document request (pick a resident first) or edit one that is not yet Ready. */
declare(strict_types=1);
require_once __DIR__ . '/../../app/bootstrap.php';

require_login();
$id = get_int('id'); $ex = null;
if ($id !== null) {
    require_permission('requests.edit');
    $ex = db_one('SELECT * FROM document_requests WHERE request_id = ?', [$id]);
    if ($ex === null) { abort(404, 'That request was not found.'); }
    if (!in_array($ex['status'], ['Pending', 'Review'], true)) { abort(403, 'Only Pending or In-review requests can be edited.'); }
} else { require_permission('requests.add'); require_permission('residents.view'); }

$types = []; foreach (db_all('SELECT type_id, name FROM document_types WHERE is_active = 1 ORDER BY sort_order, name') as $t) { $types[(int) $t['type_id']] = (string) $t['name']; }
$staff = []; foreach (db_all("SELECT user_id, full_name FROM users WHERE status = 'active' AND role IN ('secretary','clerk') ORDER BY full_name") as $u) { $staff[(int) $u['user_id']] = (string) $u['full_name']; }
$visitId = get_int('visit') ?? post_int('visit_id');
$visit = $visitId !== null ? db_one('SELECT visit_id, visitor_name FROM visitor_log WHERE visit_id = ?', [$visitId]) : null;
$residentId = $ex ? (int) $ex['resident_id'] : (get_int('resident') ?? post_int('resident_id'));
$resident = $residentId !== null ? db_one('SELECT resident_id, last_name, first_name, middle_name, suffix, residency_status, archived_at FROM residents WHERE resident_id = ?', [$residentId]) : null;
if ($resident !== null && $resident['archived_at'] !== null) { $resident = null; }
$errors = []; $v = [
    'type_id' => (string) ($ex['type_id'] ?? ''), 'purpose' => $ex['purpose'] ?? '',
    'assigned_to' => (string) ($ex['assigned_to'] ?? (audit_actor()['user_id'] ?? '')),
    'requirements_status' => (string) ($ex['requirements_status'] ?? 'Not checked'),
    'requirements_note' => (string) ($ex['requirements_note'] ?? ''),
];

if (is_post() && $resident !== null) {
    require_csrf();
    $v = [
        'type_id' => post_str('type_id'), 'purpose' => post_str('purpose'), 'assigned_to' => post_str('assigned_to'),
        'requirements_status' => post_str('requirements_status'), 'requirements_note' => post_text('requirements_note'),
    ];
    if (!isset($types[(int) $v['type_id']]) && (string) ($ex['type_id'] ?? '') !== $v['type_id']) { $errors[] = 'Choose an available document.'; }
    if (!v_len($v['purpose'], 3, 255)) { $errors[] = 'Purpose must be 3-255 characters.'; }
    if ($v['assigned_to'] !== '' && !isset($staff[(int) $v['assigned_to']])) { $errors[] = 'Choose a valid staff member or leave it blank.'; }
    if (!in_array($v['requirements_status'], ['Not checked', 'Incomplete', 'Complete'], true)) { $errors[] = 'Choose a valid requirements-check status.'; }
    if (str_len($v['requirements_note']) > 1000) { $errors[] = 'Requirements notes are limited to 1,000 characters.'; }
    if ($v['requirements_status'] === 'Incomplete' && !v_len($v['requirements_note'], 3, 1000)) { $errors[] = 'Explain which requirements are missing (3-1,000 characters).'; }
    if ($v['requirements_status'] === 'Not checked') { $v['requirements_note'] = ''; }
    if (!$errors) {
        $rid = db_transaction(function () use ($v, $ex, $resident, $visit): int {
            $assigned = $v['assigned_to'] !== '' ? (int) $v['assigned_to'] : null;
            if ($ex) {
                db_exec('UPDATE document_requests SET type_id = ?, purpose = ?, assigned_to = ?, requirements_status = ?, requirements_note = ? WHERE request_id = ? AND status IN (\'Pending\',\'Review\')',
                    [(int) $v['type_id'], $v['purpose'], $assigned, $v['requirements_status'], $v['requirements_note'] !== '' ? $v['requirements_note'] : null, $ex['request_id']]);
                audit('request_updated', 'Requests', 'Success', $ex['reference_no'] . ' edited', 'request', (int) $ex['request_id']); return (int) $ex['request_id'];
            }
            $ref = new_request_reference();
            db_exec('INSERT INTO document_requests (reference_no, visit_id, resident_id, type_id, purpose, assigned_to, requirements_status, requirements_note, requested_at, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?)',
                [$ref, $visit['visit_id'] ?? null, $resident['resident_id'], (int) $v['type_id'], $v['purpose'], $assigned, $v['requirements_status'], $v['requirements_note'] !== '' ? $v['requirements_note'] : null, audit_actor()['user_id'] ?? null]);
            $new = (int) db()->lastInsertId();
            audit('request_created', 'Requests', 'Success', $ref . ' filed for ' . $resident['last_name'] . ', ' . $resident['first_name'], 'request', $new);
            return $new;
        });
        flash_add('success', $ex ? 'Request updated.' : 'Request filed.');
        redirect('requests/view.php', ['id' => $rid]);
    }
    audit_best_effort('request_rejected', 'Requests', 'Failed', 'Validation failed (' . count($errors) . ' problem(s))');
}

$title = $ex ? 'Edit request' : 'New document request';
render_header($title, [['Request Tracker', 'requests/index.php'], [$title, null]], 'requests');
page_head($title, $resident ? 'For ' . resident_name($resident) . ($resident['residency_status'] === 'Relocated' ? ' (relocated resident)' : '') : 'First find the resident.');
echo errors_box($errors);
if ($resident === null) {
    $q = get_str('q') !== '' ? get_str('q') : (string) ($visit['visitor_name'] ?? '');
    $found = $q !== '' ? resident_search(['q' => $q, 'purok' => '', 'status' => '', 'category' => '', 'show' => ''], 10, 0)[0] : [];
    card_start('Find the resident', 'Search by name or household code.');
    echo '<form method="get" action="' . e(base_url('requests/form.php')) . '" class="filters px-0 border-0">' . ($visit ? '<input type="hidden" name="visit" value="' . (int) $visit['visit_id'] . '">' : '')
       . '<div><label class="form-label" for="q">Name</label><input class="form-control" id="q" name="q" value="' . e($q) . '" maxlength="60" required autofocus></div><div><button class="btn btn-primary" type="submit">Search</button></div></form>';
    if ($q !== '' && !$found) { echo empty_state('bi-person-x', 'No resident matches. Register the resident first.') . (can('residents.add') ? '<p class="text-center">' . btn_link('residents/form.php', 'Register resident', 'btn-light border') . '</p>' : ''); }
    foreach ($found as $r) { echo '<div class="d-flex justify-content-between align-items-center border-bottom py-2"><div><strong>' . e(resident_name($r)) . '</strong><div class="small text-muted">' . e((string) ($r['household_code'] ?? 'no household')) . ' · ' . e((string) ($r['purok'] ?? '')) . '</div></div>'
        . '<a class="btn btn-sm btn-primary" href="' . e(base_url('requests/form.php?resident=' . (int) $r['resident_id'] . ($visit ? '&visit=' . (int) $visit['visit_id'] : ''))) . '">Select</a></div>'; }
    card_end();
} else {
    $action = base_url('requests/form.php' . ($ex ? '?id=' . (int) $ex['request_id'] : ''));
    card_start();
    echo '<form method="post" action="' . e($action) . '" class="needs-validation" novalidate autocomplete="off">' . csrf_field() . '<input type="hidden" name="resident_id" value="' . (int) $resident['resident_id'] . '">' . ($visit ? '<input type="hidden" name="visit_id" value="' . (int) $visit['visit_id'] . '">' : '') . '<div class="row">';
    if (!$types) { echo '<div class="col-12 alert alert-warning">No document types are available. Ask the Secretary to set them up in Settings.</div>'; }
    echo f_select('type_id', 'Document', $types, $v['type_id'], ['required' => true, 'blank' => 'Select a document']);
    echo f_select('assigned_to', 'Assigned to', $staff, $v['assigned_to'], ['blank' => 'Nobody yet']);
    echo f_select('requirements_status', 'Requirements review', [
        'Not checked' => 'Not checked yet', 'Incomplete' => 'Incomplete — needs follow-up', 'Complete' => 'Complete',
    ], $v['requirements_status'], ['required' => true, 'col' => 'col-12', 'help' => 'Review supporting information before approving a request as Ready. No specific supporting documents are assumed by the system.']);
    echo f_textarea('requirements_note', 'Requirements / follow-up notes', $v['requirements_note'], ['rows' => 2, 'maxlength' => 1000, 'help' => 'Required when marking the requirements as incomplete.']);
    echo f_textarea('purpose', 'Purpose / request details', $v['purpose'], ['required' => true, 'rows' => 3, 'maxlength' => 255]);
    echo '</div><button class="btn btn-primary" type="submit">' . ($ex ? 'Save changes' : 'File request') . '</button> <a class="btn btn-light border" href="' . e(base_url('requests/index.php')) . '">Cancel</a></form>';
    card_end();
}
render_footer();
