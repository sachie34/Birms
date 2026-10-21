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
$feeRules = db_all("SELECT fm.fee_rule_id, fm.type_id, fm.fee_category, fm.fee_tier, fm.unit_rate, fm.min_quantity, fm.max_quantity, fm.effective_from, fm.effective_to, fm.is_active, dt.name AS type_name
                    FROM fee_matrix fm JOIN document_types dt ON dt.type_id = fm.type_id
                    WHERE fm.is_active = 1 AND fm.effective_from <= CURDATE() AND (fm.effective_to IS NULL OR fm.effective_to >= CURDATE())
                    ORDER BY dt.name, fm.fee_category, fm.fee_tier, fm.min_quantity");
$existingFeeRule = null;
if ($ex !== null && !empty($ex['fee_rule_id'])) {
    $existingFeeRule = db_one('SELECT fm.fee_rule_id, fm.type_id, fm.fee_category, fm.fee_tier, fm.unit_rate, fm.min_quantity, fm.max_quantity, fm.effective_from, fm.effective_to, fm.is_active, dt.name AS type_name
                               FROM fee_matrix fm JOIN document_types dt ON dt.type_id = fm.type_id WHERE fm.fee_rule_id = ?', [(int) $ex['fee_rule_id']]);
}
$feeRuleOptions = [];
foreach ($feeRules as $rule) {
    $range = (int) $rule['min_quantity'] . '–' . ($rule['max_quantity'] === null ? 'no limit' : (string) $rule['max_quantity']);
    $feeRuleOptions[(int) $rule['fee_rule_id']] = $rule['type_name'] . ' — ' . $rule['fee_category'] . ' / ' . $rule['fee_tier'] . ' · ' . money($rule['unit_rate']) . ' per unit · quantity ' . $range;
}
if ($existingFeeRule !== null && !isset($feeRuleOptions[(int) $existingFeeRule['fee_rule_id']])) {
    $range = (int) $existingFeeRule['min_quantity'] . '–' . ($existingFeeRule['max_quantity'] === null ? 'no limit' : (string) $existingFeeRule['max_quantity']);
    $feeRuleOptions[(int) $existingFeeRule['fee_rule_id']] = $existingFeeRule['type_name'] . ' — ' . $existingFeeRule['fee_category'] . ' / ' . $existingFeeRule['fee_tier'] . ' · ' . money($existingFeeRule['unit_rate']) . ' per unit · quantity ' . $range . ' (existing saved quote; not available for new pricing)';
}
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
    'fee_rule_id' => (string) ($ex['fee_rule_id'] ?? ''),
    'fee_quantity' => (string) ($ex['fee_quantity'] ?? '1'),
];

if (is_post() && $resident !== null) {
    require_csrf();
    $file = $_FILES['attachment'] ?? null;
    $uploadError = attachment_upload_error($file);
    if ($uploadError !== null) { $errors[] = $uploadError; }
    $v = [
        'type_id' => post_str('type_id'), 'purpose' => post_str('purpose'), 'assigned_to' => post_str('assigned_to'),
        'requirements_status' => post_str('requirements_status'), 'requirements_note' => post_text('requirements_note'),
        'fee_rule_id' => post_str('fee_rule_id'),
        // Legacy request-form clients may omit fee quantity. Quantity 1 preserves the prior workflow.
        'fee_quantity' => post_str('fee_quantity') !== '' ? post_str('fee_quantity') : '1',
    ];
    if (!isset($types[(int) $v['type_id']]) && (string) ($ex['type_id'] ?? '') !== $v['type_id']) { $errors[] = 'Choose an available document.'; }
    if (!v_len($v['purpose'], 3, 255)) { $errors[] = 'Purpose must be 3-255 characters.'; }
    if ($v['assigned_to'] !== '' && !isset($staff[(int) $v['assigned_to']])) { $errors[] = 'Choose a valid staff member or leave it blank.'; }
    if (!in_array($v['requirements_status'], ['Not checked', 'Incomplete', 'Complete'], true)) { $errors[] = 'Choose a valid requirements-check status.'; }
    if (str_len($v['requirements_note']) > 1000) { $errors[] = 'Requirements notes are limited to 1,000 characters.'; }
    if ($v['requirements_status'] === 'Incomplete' && !v_len($v['requirements_note'], 3, 1000)) { $errors[] = 'Explain which requirements are missing (3-1,000 characters).'; }
    if ($v['requirements_status'] === 'Not checked') { $v['requirements_note'] = ''; }

    // Apply the approved fee matrix only when configured for this document type.
    // This records a quote/billing basis; it does not process online payments.
    $pricing = [
        'fee_rule_id' => null, 'fee_category_snapshot' => null, 'fee_tier_snapshot' => null,
        'fee_quantity' => 1, 'fee_unit_rate' => null, 'quoted_fee_amount' => null,
    ];
    $quantityText = $v['fee_quantity'];
    $quantity = ctype_digit($quantityText) ? (int) $quantityText : 0;
    if ($quantity < 1 || $quantity > 1000000) {
        $errors[] = 'Quantity must be a whole number from 1 to 1,000,000.';
    }
    $selectedRuleId = $v['fee_rule_id'] === '' ? null : (ctype_digit($v['fee_rule_id']) && (int) $v['fee_rule_id'] > 0 ? (int) $v['fee_rule_id'] : -1);
    if ($selectedRuleId === -1) { $errors[] = 'Choose a valid approved fee category and tier.'; }

    $currentRuleById = [];
    foreach ($feeRules as $candidate) { $currentRuleById[(int) $candidate['fee_rule_id']] = $candidate; }
    if ($existingFeeRule !== null) { $currentRuleById[(int) $existingFeeRule['fee_rule_id']] = $existingFeeRule; }
    $hasActiveMatrixForType = false;
    foreach ($feeRules as $candidate) {
        if ((int) $candidate['type_id'] === (int) $v['type_id']) { $hasActiveMatrixForType = true; break; }
    }

    if ($selectedRuleId !== null && $selectedRuleId > 0) {
        $rule = $currentRuleById[$selectedRuleId] ?? null;
        if ($rule === null) {
            $errors[] = 'That fee rule is no longer available. Select a currently effective approved rule.';
        } elseif ((int) $rule['type_id'] !== (int) $v['type_id']) {
            $errors[] = 'The selected fee rule belongs to a different document type.';
        } else {
            $sameSavedRule = $ex !== null
                && (int) ($ex['fee_rule_id'] ?? 0) === $selectedRuleId
                && (int) ($ex['fee_quantity'] ?? 1) === $quantity
                && $ex['quoted_fee_amount'] !== null;
            $isCurrentRule = (int) $rule['is_active'] === 1
                && (string) $rule['effective_from'] <= date('Y-m-d')
                && ($rule['effective_to'] === null || (string) $rule['effective_to'] >= date('Y-m-d'));
            if (!$isCurrentRule && !$sameSavedRule) {
                $errors[] = 'That fee rule is not currently effective. Existing saved quotes may be retained, but new pricing needs a currently effective rule.';
            } elseif ($sameSavedRule && !$isCurrentRule) {
                // Keep an older quote unchanged when editing a request after a rule expires or is disabled.
                $pricing = [
                    'fee_rule_id' => $selectedRuleId,
                    'fee_category_snapshot' => (string) ($ex['fee_category_snapshot'] ?? $rule['fee_category']),
                    'fee_tier_snapshot' => (string) ($ex['fee_tier_snapshot'] ?? $rule['fee_tier']),
                    'fee_quantity' => $quantity,
                    'fee_unit_rate' => $ex['fee_unit_rate'] ?? $rule['unit_rate'],
                    'quoted_fee_amount' => $ex['quoted_fee_amount'],
                ];
            } else {
                if ($quantity < (int) $rule['min_quantity'] || ($rule['max_quantity'] !== null && $quantity > (int) $rule['max_quantity'])) {
                    $errors[] = 'Quantity does not fit the selected fee tier range.';
                }
                $quote = round((float) $rule['unit_rate'] * $quantity, 2);
                if (!is_finite($quote) || $quote < 0 || $quote > 9999999999.99) {
                    $errors[] = 'The calculated amount exceeds the supported billing range.';
                }
                $pricing = [
                    'fee_rule_id' => $selectedRuleId,
                    'fee_category_snapshot' => (string) $rule['fee_category'],
                    'fee_tier_snapshot' => (string) $rule['fee_tier'],
                    'fee_quantity' => $quantity,
                    'fee_unit_rate' => (float) $rule['unit_rate'],
                    'quoted_fee_amount' => $quote,
                ];
            }
        }
    } else {
        if ($ex !== null && !empty($ex['fee_rule_id'])) {
            $errors[] = 'This request already has a matrix quote. Keep the selected rule or choose another currently effective approved rule; do not clear the quote.';
        } elseif ($hasActiveMatrixForType) {
            $errors[] = 'This document type has an approved fee matrix. Select a category/tier and quantity before filing.';
        } else {
            // No approved matrix rule applies: the legacy per-document fee is a single-document amount.
            if ($quantity !== 1) { $errors[] = 'Quantity must be 1 when using the per-document fee. Configure and select an approved fee-matrix rule to price multiple units.'; }
            $pricing['fee_quantity'] = 1;
        }
    }

    if (!$errors) {
        $rid = db_transaction(function () use ($v, $pricing, $ex, $resident, $visit, $file): int {
            $assigned = $v['assigned_to'] !== '' ? (int) $v['assigned_to'] : null;
            if ($ex) {
                db_exec('UPDATE document_requests
                         SET type_id = ?, purpose = ?, assigned_to = ?, requirements_status = ?, requirements_note = ?,
                             fee_rule_id = ?, fee_category_snapshot = ?, fee_tier_snapshot = ?, fee_quantity = ?, fee_unit_rate = ?, quoted_fee_amount = ?
                         WHERE request_id = ? AND status IN (\'Pending\',\'Review\')',
                    [(int) $v['type_id'], $v['purpose'], $assigned, $v['requirements_status'], $v['requirements_note'] !== '' ? $v['requirements_note'] : null,
                     $pricing['fee_rule_id'], $pricing['fee_category_snapshot'], $pricing['fee_tier_snapshot'], $pricing['fee_quantity'], $pricing['fee_unit_rate'], $pricing['quoted_fee_amount'], $ex['request_id']]);
                store_document_upload('request', (int) $ex['request_id'], $file);
                audit('request_updated', 'Requests', 'Success',
                    $ex['reference_no'] . ' edited' . ($pricing['quoted_fee_amount'] !== null ? '; quoted fee ' . number_format((float) $pricing['quoted_fee_amount'], 2, '.', '') : ''),
                    'request', (int) $ex['request_id']);
                return (int) $ex['request_id'];
            }
            $ref = new_request_reference();
            db_exec('INSERT INTO document_requests
                     (reference_no, visit_id, resident_id, type_id, purpose, assigned_to, requirements_status, requirements_note,
                      fee_rule_id, fee_category_snapshot, fee_tier_snapshot, fee_quantity, fee_unit_rate, quoted_fee_amount, requested_at, created_by)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?)',
                [$ref, $visit['visit_id'] ?? null, $resident['resident_id'], (int) $v['type_id'], $v['purpose'], $assigned, $v['requirements_status'], $v['requirements_note'] !== '' ? $v['requirements_note'] : null,
                 $pricing['fee_rule_id'], $pricing['fee_category_snapshot'], $pricing['fee_tier_snapshot'], $pricing['fee_quantity'], $pricing['fee_unit_rate'], $pricing['quoted_fee_amount'], audit_actor()['user_id'] ?? null]);
            $new = (int) db()->lastInsertId();
            store_document_upload('request', $new, $file);
            audit('request_created', 'Requests', 'Success',
                $ref . ' filed for ' . $resident['last_name'] . ', ' . $resident['first_name'] . ($pricing['quoted_fee_amount'] !== null ? '; quoted fee ' . number_format((float) $pricing['quoted_fee_amount'], 2, '.', '') : ''),
                'request', $new);
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
    if ($ex !== null) {
        $existingAttachments = attachments_for_parent('request', (int) $ex['request_id']);
        if ($existingAttachments) {
            echo '<div class="alert alert-light border"><strong>Attached supporting documents</strong>';
            foreach ($existingAttachments as $att) { echo '<a class="d-block" href="' . e(base_url('files/download.php?id=' . (int) $att['attachment_id'])) . '">' . e((string) $att['original_name']) . '</a>'; }
            echo '</div>';
        }
    }
    echo '<form method="post" enctype="multipart/form-data" action="' . e($action) . '" class="needs-validation" novalidate autocomplete="off">' . csrf_field() . '<input type="hidden" name="resident_id" value="' . (int) $resident['resident_id'] . '">' . ($visit ? '<input type="hidden" name="visit_id" value="' . (int) $visit['visit_id'] . '">' : '') . '<div class="row">';
    if (!$types) { echo '<div class="col-12 alert alert-warning">No document types are available. Ask the Secretary to set them up in Settings.</div>'; }
    echo f_select('type_id', 'Document', $types, $v['type_id'], ['required' => true, 'blank' => 'Select a document']);
    echo f_select('fee_rule_id', 'Approved fee category / tier', $feeRuleOptions, $v['fee_rule_id'], [
        'blank' => 'Use per-document fee only when no active matrix rule applies',
        'col' => 'col-12',
        'help' => 'Each option names its document type, approved category/tier, rate per unit, and quantity range. The server checks that the selected rule matches the document. Add only rates formally approved by the barangay in Settings → Multi-tier fee matrix.',
    ]);
    echo f_text('fee_quantity', 'Quantity for selected fee tier', $v['fee_quantity'], [
        'required' => true, 'type' => 'number', 'min' => 1, 'step' => 1,
        'help' => 'If a fee matrix rule is selected, the amount is calculated as approved rate × quantity. Without a matrix rule, the legacy document fee is used and quantity remains 1.',
    ]);
    if ($ex !== null && $ex['quoted_fee_amount'] !== null) {
        echo '<div class="col-12"><div class="alert alert-info">Saved fee quote: <strong>' . e(money($ex['quoted_fee_amount'])) . '</strong>'
           . ' · ' . e((string) ($ex['fee_category_snapshot'] ?? '')) . ' / ' . e((string) ($ex['fee_tier_snapshot'] ?? ''))
           . ' · ' . (int) ($ex['fee_quantity'] ?? 1) . ' × ' . e(money($ex['fee_unit_rate'] ?? null))
           . '. The quote is saved with the request and does not record a payment.</div></div>';
    }
    echo f_select('assigned_to', 'Assigned to', $staff, $v['assigned_to'], ['blank' => 'Nobody yet']);
    echo f_select('requirements_status', 'Requirements review', [
        'Not checked' => 'Not checked yet', 'Incomplete' => 'Incomplete — needs follow-up', 'Complete' => 'Complete',
    ], $v['requirements_status'], ['required' => true, 'col' => 'col-12', 'help' => 'Review supporting information before approving a request as Ready. No specific supporting documents are assumed by the system.']);
    echo f_textarea('requirements_note', 'Requirements / follow-up notes', $v['requirements_note'], ['rows' => 2, 'maxlength' => 1000, 'help' => 'Required when marking the requirements as incomplete.']);
    echo f_textarea('purpose', 'Purpose / request details', $v['purpose'], ['required' => true, 'rows' => 3, 'maxlength' => 255]);
    echo '<div class="col-12 mb-3"><label class="form-label" for="attachment">Supporting document (optional)</label><input class="form-control" id="attachment" name="attachment" type="file" accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png"><div class="form-text">PDF, JPG or PNG; maximum 10 MB. Uploads are private and available to authorized staff.</div></div>';
    echo '</div><button class="btn btn-primary" type="submit">' . ($ex ? 'Save changes' : 'File request') . '</button> <a class="btn btn-light border" href="' . e(base_url('requests/index.php')) . '">Cancel</a></form>';
    card_end();
}
render_footer();
