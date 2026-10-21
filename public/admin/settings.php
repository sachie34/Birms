<?php
/** Settings: barangay details, Puroks and lists, document types with fees and wording, SMS text. */
declare(strict_types=1);
require_once __DIR__ . '/../../app/bootstrap.php';

require_login();
require_permission('settings.view');
$canEdit = can('settings.edit');
$tabs = ['general' => 'Barangay details', 'puroks' => 'Puroks', 'lists' => 'Other lists', 'documents' => 'Documents and fees', 'fee_matrix' => 'Multi-tier fee matrix', 'summons' => 'Hearing summons', 'sms' => 'SMS text'];
$tab = get_str('tab'); $tab = isset($tabs[$tab]) ? $tab : 'general';
$errors = [];

if (is_post()) {
    require_csrf();
    require_permission('settings.edit');
    $do = post_str('do'); $tab = isset($tabs[post_str('tab')]) ? post_str('tab') : 'general';
    try {
        db_transaction(function () use ($do, &$errors): void {
            switch ($do) {
                case 'general_save':
                    $vals = ['barangay_name' => post_str('barangay_name'), 'municipality' => post_str('municipality'), 'province' => post_str('province'),
                             'captain_name' => post_str('captain_name'), 'secretary_name' => post_str('secretary_name'), 'age_brackets' => post_str('age_brackets')];
                    foreach (['barangay_name', 'municipality', 'province'] as $k) { if (!v_len($vals[$k], 2, 60)) { $errors[] = ucfirst(str_replace('_', ' ', $k)) . ' must be 2-60 characters.'; } }
                    foreach (['captain_name', 'secretary_name'] as $k) { if ($vals[$k] !== '' && !v_person_name($vals[$k])) { $errors[] = ucfirst(str_replace('_', ' ', $k)) . ' is not a valid name.'; } }
                    if (!v_age_brackets($vals['age_brackets'])) { $errors[] = 'Age brackets must look like 0-17,18-59,60-200 (ascending, no overlap).'; }
                    if ($errors) { throw new RuntimeException('invalid'); }
                    foreach ($vals as $k => $v) { setting_set($k, $v); }
                    audit('settings_saved', 'Settings', 'Success', 'Barangay details updated', 'settings', null);
                    flash_add('success', 'Barangay details saved.');
                    break;
                case 'lookup_add':
                    $key = post_str('list_key'); $label = post_str('label');
                    if (!isset(LOOKUP_LISTS[$key])) { $errors[] = 'Unknown list.'; }
                    elseif (!v_len($label, 1, 100)) { $errors[] = 'Enter a name (1-100 characters).'; }
                    elseif (db_val('SELECT COUNT(*) FROM lookups WHERE list_key = ? AND label = ?', [$key, $label])) { $errors[] = 'That name already exists in this list.'; }
                    if ($errors) { throw new RuntimeException('invalid'); }
                    db_exec('INSERT INTO lookups (list_key, label, sort_order) VALUES (?, ?, ?)', [$key, $label, (int) db_val('SELECT COALESCE(MAX(sort_order), 0) + 1 FROM lookups WHERE list_key = ?', [$key])]);
                    audit('lookup_added', 'Settings', 'Success', LOOKUP_LISTS[$key] . ': ' . $label, 'lookup', (int) db()->lastInsertId());
                    flash_add('success', 'Added.');
                    break;
                case 'lookup_toggle':
                    $id = post_int('id'); $row = $id === null ? null : db_one('SELECT lookup_id, list_key, label, is_active FROM lookups WHERE lookup_id = ?', [$id]);
                    if ($row === null) { $errors[] = 'That entry does not exist.'; throw new RuntimeException('invalid'); }
                    db_exec('UPDATE lookups SET is_active = 1 - is_active WHERE lookup_id = ?', [$id]);
                    audit('lookup_toggled', 'Settings', 'Success', $row['label'] . ($row['is_active'] ? ' hidden' : ' shown'), 'lookup', (int) $id);
                    flash_add('success', 'Updated. Hidden entries stay on old records but cannot be chosen for new ones.');
                    break;
                case 'doctype_add':
                    $name = post_str('name');
                    if (!v_len($name, 3, 100)) { $errors[] = 'Enter the document name (3-100 characters).'; }
                    elseif (db_val('SELECT COUNT(*) FROM document_types WHERE name = ?', [$name])) { $errors[] = 'That document already exists.'; }
                    if ($errors) { throw new RuntimeException('invalid'); }
                    db_exec('INSERT INTO document_types (name, sort_order) VALUES (?, ?)', [$name, (int) db_val('SELECT COALESCE(MAX(sort_order), 0) + 1 FROM document_types')]);
                    audit('doctype_added', 'Settings', 'Success', $name, 'document_type', (int) db()->lastInsertId());
                    flash_add('success', 'Document added. Set its fee and wording before it can be issued.');
                    break;
                case 'doctype_save':
                    $id = post_int('id'); $dt = $id === null ? null : db_one('SELECT type_id, name FROM document_types WHERE type_id = ?', [$id]);
                    $fee = post_str('fee_amount'); $body = post_text('template_body');
                    if ($dt === null) { $errors[] = 'That document does not exist.'; }
                    if ($fee !== '' && !v_money($fee)) { $errors[] = 'Fee must be an amount like 50 or 50.00 (0 means free).'; }
                    if (str_len($body) > 4000) { $errors[] = 'Wording is limited to 4,000 characters.'; }
                    if ($unknown = template_unknown_tokens($body, TEMPLATE_TOKENS)) { $errors[] = 'Unknown placeholder(s): {{' . implode('}}, {{', $unknown) . '}}.'; }
                    if ($errors) { throw new RuntimeException('invalid'); }
                    db_exec('UPDATE document_types SET fee_amount = ?, template_body = ?, is_active = ? WHERE type_id = ?', [$fee === '' ? null : $fee, $body === '' ? null : $body, post_str('is_active') === '1' ? 1 : 0, $id]);
                    audit('doctype_saved', 'Settings', 'Success', $dt['name'] . ': fee ' . ($fee === '' ? 'not set' : $fee) . ', wording ' . ($body === '' ? 'not set' : 'set'), 'document_type', (int) $id);
                    flash_add('success', 'Saved ' . $dt['name'] . '.');
                    break;
                case 'fee_rule_add':
                    $typeId = post_int('type_id');
                    $category = post_str('fee_category');
                    $tier = post_str('fee_tier');
                    $rate = post_str('unit_rate');
                    $minQuantity = post_int('min_quantity');
                    $maxRaw = post_str('max_quantity');
                    $maxQuantity = $maxRaw === '' ? null : post_int('max_quantity');
                    $from = post_str('effective_from');
                    $toRaw = post_str('effective_to');
                    $to = $toRaw === '' ? null : $toRaw;
                    $dt = $typeId === null ? null : db_one("SELECT type_id, name FROM document_types WHERE type_id = ? AND is_active = 1", [$typeId]);
                    if ($dt === null) { $errors[] = 'Choose an active document/service type.'; }
                    if (!v_len($category, 1, 100)) { $errors[] = 'Fee category must be 1-100 characters.'; }
                    if (!v_len($tier, 1, 100)) { $errors[] = 'Fee tier must be 1-100 characters.'; }
                    if (!v_money($rate)) { $errors[] = 'Unit rate must be an amount like 50 or 50.00 (0 means free).'; }
                    if ($minQuantity === null || $minQuantity < 1) { $errors[] = 'Minimum quantity must be at least 1.'; }
                    if ($maxRaw !== '' && ($maxQuantity === null || $maxQuantity < 1)) { $errors[] = 'Maximum quantity must be a positive whole number, or left blank.'; }
                    if ($maxQuantity !== null && $minQuantity !== null && $maxQuantity < $minQuantity) { $errors[] = 'Maximum quantity cannot be less than minimum quantity.'; }
                    if (!v_date($from)) { $errors[] = 'Enter a valid effective-from date.'; }
                    if ($to !== null && !v_date($to)) { $errors[] = 'Enter a valid effective-to date or leave it blank.'; }
                    if ($to !== null && v_date($from) && v_date($to) && $to < $from) { $errors[] = 'Effective-to date cannot be earlier than effective-from date.'; }
                    if ($errors) { throw new RuntimeException('invalid'); }
                    $overlap = (int) db_val(
                        'SELECT COUNT(*) FROM fee_matrix
                         WHERE type_id = ? AND fee_category = ? AND fee_tier = ? AND is_active = 1
                           AND (effective_to IS NULL OR effective_to >= ?)
                           AND (? IS NULL OR effective_from <= ?)',
                        [$typeId, $category, $tier, $from, $to, $to]
                    );
                    if ($overlap > 0) { $errors[] = 'This category and tier already has an active rule with overlapping effective dates. Disable the old rule before creating a replacement.'; throw new RuntimeException('invalid'); }
                    db_exec('INSERT INTO fee_matrix (type_id, fee_category, fee_tier, unit_rate, min_quantity, max_quantity, effective_from, effective_to, created_by)
                             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
                        [$typeId, $category, $tier, $rate, $minQuantity, $maxQuantity, $from, $to, audit_actor()['user_id'] ?? null]);
                    $ruleId = (int) db()->lastInsertId();
                    audit('fee_rule_added', 'Fee Matrix', 'Success', $dt['name'] . ': ' . $category . ' / ' . $tier . ' at ' . $rate . ' per unit, effective ' . $from . ($to !== null ? ' to ' . $to : ' onward'), 'fee_rule', $ruleId);
                    flash_add('success', 'Approved fee rule saved. It will be offered for new requests while active and within its effective dates.');
                    break;
                case 'fee_rule_disable':
                    $ruleId = post_int('id');
                    $rule = $ruleId === null ? null : db_one('SELECT fm.fee_rule_id, fm.fee_category, fm.fee_tier, fm.is_active, dt.name AS type_name FROM fee_matrix fm JOIN document_types dt ON dt.type_id = fm.type_id WHERE fm.fee_rule_id = ?', [$ruleId]);
                    if ($rule === null) { $errors[] = 'That fee rule does not exist.'; throw new RuntimeException('invalid'); }
                    if ((int) $rule['is_active'] !== 1) { $errors[] = 'That fee rule is already disabled.'; throw new RuntimeException('invalid'); }
                    db_exec('UPDATE fee_matrix SET is_active = 0 WHERE fee_rule_id = ?', [$ruleId]);
                    audit('fee_rule_disabled', 'Fee Matrix', 'Success', $rule['type_name'] . ': ' . $rule['fee_category'] . ' / ' . $rule['fee_tier'] . ' disabled', 'fee_rule', (int) $ruleId);
                    flash_add('success', 'Fee rule disabled. Existing request/issuance snapshots are retained.');
                    break;
                case 'summons_save':
                    $t = input_str($_POST, 'summons_wording', true);
                    if (str_len($t) > 4000) { $errors[] = 'The summons wording is limited to 4000 characters.'; }
                    if ($unknown = template_unknown_tokens($t, SUMMONS_TOKENS)) { $errors[] = 'Unknown placeholder(s): {{' . implode('}}, {{', $unknown) . '}}.'; }
                    if ($errors) { throw new RuntimeException('invalid'); }
                    setting_set('summons_wording', $t);
                    audit('settings_saved', 'Settings', 'Success', 'Hearing summons wording ' . ($t === '' ? 'cleared' : 'updated'), 'settings', null);
                    flash_add('success', 'Summons wording saved.');
                    break;
                case 'sms_save':
                    $t = post_str('sms_ready_text');
                    if (str_len($t) > 300) { $errors[] = 'SMS text is limited to 300 characters.'; }
                    if ($unknown = template_unknown_tokens($t, SMS_TOKENS)) { $errors[] = 'Unknown placeholder(s): {{' . implode('}}, {{', $unknown) . '}}.'; }
                    if ($errors) { throw new RuntimeException('invalid'); }
                    setting_set('sms_ready_text', $t);
                    audit('settings_saved', 'Settings', 'Success', 'SMS text updated', 'settings', null);
                    flash_add('success', 'SMS text saved.');
                    break;
                default:
                    $errors[] = 'Unknown action.'; throw new RuntimeException('invalid');
            }
        });
        redirect('admin/settings.php', ['tab' => $tab]);
    } catch (RuntimeException $e) {
        if (!$errors) { throw $e; }
        audit_best_effort('settings_rejected', 'Settings', 'Failed', $do . ': ' . implode(' ', $errors));
    }
}

render_header('Settings', [['Settings', null]], 'settings');
page_head('Settings', 'Official details are entered by the Barangay Secretary. Anything not set stays blank and blocks the feature that needs it.');
echo errors_box($errors);
echo '<ul class="nav nav-pills mb-3">';
foreach ($tabs as $k => $lab) { echo '<li class="nav-item"><a class="nav-link' . ($k === $tab ? ' active' : '') . '" href="' . e(base_url('admin/settings.php?tab=' . $k)) . '">' . e($lab) . '</a></li>'; }
echo '</ul>';
if (!$canEdit) { echo '<div class="alert alert-info">You can view settings but not change them.</div>'; }
$hidden = fn(string $do) => csrf_field() . '<input type="hidden" name="do" value="' . e($do) . '"><input type="hidden" name="tab" value="' . e($tab) . '">';

if ($tab === 'general') {
    card_start('Barangay details', 'Used on printed documents and reports.');
    echo '<form method="post" action="' . e(base_url('admin/settings.php')) . '" class="needs-validation" novalidate><div class="row">' . $hidden('general_save');
    $d = !$canEdit;
    echo f_text('barangay_name', 'Barangay', (string) setting('barangay_name', ''), ['required' => true, 'maxlength' => 60, 'disabled' => $d]);
    echo f_text('municipality', 'Municipality', (string) setting('municipality', ''), ['required' => true, 'maxlength' => 60, 'disabled' => $d]);
    echo f_text('province', 'Province', (string) setting('province', ''), ['required' => true, 'maxlength' => 60, 'disabled' => $d]);
    echo f_text('captain_name', 'Barangay Captain (as printed)', (string) setting('captain_name', ''), ['maxlength' => 100, 'help' => 'Leave blank until confirmed. Certificates cannot be issued without it if the wording uses it.', 'disabled' => $d]);
    echo f_text('secretary_name', 'Barangay Secretary (as printed)', (string) setting('secretary_name', ''), ['maxlength' => 100, 'disabled' => $d]);
    echo f_text('age_brackets', 'Age brackets for reports', (string) setting('age_brackets', ''), ['required' => true, 'maxlength' => 60, 'help' => 'Default only; official brackets are pending. Example: 0-17,18-59,60-200', 'disabled' => $d]);
    echo '</div>' . ($canEdit ? '<button class="btn btn-primary" type="submit">Save</button>' : '') . '</form>';
    card_end();
} elseif ($tab === 'puroks' || $tab === 'lists') {
    $keys = $tab === 'puroks' ? ['purok'] : ['resident_category', 'visit_class', 'id_type', 'employment_status', 'incident_type'];
    foreach ($keys as $key) {
        $items = db_all('SELECT lookup_id, label, is_active FROM lookups WHERE list_key = ? ORDER BY sort_order, label', [$key]);
        card_start(LOOKUP_LISTS[$key], $key === 'purok' ? 'The official Purok names have not been provided. Enter them here.' : 'Enter the categories approved for your barangay. Reporting will group records using these values.', 'mb-3');
        if (!$items) { echo empty_state('bi-list-ul', 'Nothing entered yet.'); }
        else {
            echo '<table class="table table-b mb-3"><tbody>';
            foreach ($items as $it) {
                echo '<tr><td>' . e((string) $it['label']) . '</td><td>' . ($it['is_active'] ? pill('Shown', 'green') : pill('Hidden', 'grey')) . '</td><td class="text-end">'
                   . ($canEdit ? btn_post('admin/settings.php', ['do' => 'lookup_toggle', 'tab' => $tab, 'id' => $it['lookup_id']], $it['is_active'] ? 'Hide' : 'Show', 'btn-sm btn-light border') : '') . '</td></tr>';
            }
            echo '</tbody></table>';
        }
        if ($canEdit) {
            echo '<form method="post" action="' . e(base_url('admin/settings.php')) . '" class="row g-2 align-items-end">' . $hidden('lookup_add') . '<input type="hidden" name="list_key" value="' . e($key) . '">'
               . '<div class="col-md-6"><label class="form-label" for="label_' . e($key) . '">Add to this list</label><input class="form-control" id="label_' . e($key) . '" name="label" maxlength="100" required></div>'
               . '<div class="col-auto"><button class="btn btn-primary" type="submit">Add</button></div></form>';
        }
        card_end();
    }
} elseif ($tab === 'fee_matrix') {
    $rows = db_all('SELECT fm.fee_rule_id, fm.type_id, fm.fee_category, fm.fee_tier, fm.unit_rate, fm.min_quantity, fm.max_quantity, fm.effective_from, fm.effective_to, fm.is_active, fm.created_at, dt.name AS type_name, u.full_name AS created_by_name
                    FROM fee_matrix fm JOIN document_types dt ON dt.type_id = fm.type_id
                    LEFT JOIN users u ON u.user_id = fm.created_by
                    ORDER BY dt.name, fm.fee_category, fm.fee_tier, fm.effective_from DESC, fm.fee_rule_id DESC');
    card_start('Configured fee matrix', 'No fee rates are preloaded. Add only categories, tiers, dates, and rates approved by the barangay. Existing request and issuance fee snapshots are not changed when a rule is disabled.');
    echo '<div class="alert alert-secondary">For a document type with an active rule effective today, a new request must select a fee category/tier and quantity. The system calculates the quoted amount as approved unit rate × quantity. If no effective matrix rule exists for a document type, the existing per-document fee setting remains available. This does not process online payments.</div>';
    if (!$rows) { echo empty_state('bi-cash-stack', 'No fee-matrix rules have been entered yet.'); }
    else {
        echo '<div class="table-responsive"><table class="table table-b mb-0"><thead><tr><th>Document/service</th><th>Category</th><th>Tier</th><th class="text-end">Rate per unit</th><th>Quantity range</th><th>Effective dates</th><th>Status</th><th></th></tr></thead><tbody>';
        foreach ($rows as $r) {
            $range = (int) $r['min_quantity'] . '–' . ($r['max_quantity'] === null ? 'no limit' : (string) $r['max_quantity']);
            $dates = (string) $r['effective_from'] . ' → ' . ($r['effective_to'] === null ? 'No end date' : (string) $r['effective_to']);
            echo '<tr><td>' . e((string) $r['type_name']) . '</td><td>' . e((string) $r['fee_category']) . '</td><td>' . e((string) $r['fee_tier']) . '</td>'
               . '<td class="text-end">' . e(money($r['unit_rate'])) . '</td><td>' . e($range) . '</td><td>' . e($dates) . '</td>'
               . '<td>' . ((int) $r['is_active'] === 1 ? pill('Active', 'green') : pill('Disabled', 'grey')) . '</td><td class="text-end">'
               . ($canEdit && (int) $r['is_active'] === 1 ? btn_post('admin/settings.php', ['do' => 'fee_rule_disable', 'tab' => 'fee_matrix', 'id' => $r['fee_rule_id']], 'Disable', 'btn-sm btn-light border') : '')
               . '</td></tr>';
        }
        echo '</tbody></table></div>';
    }
    card_end();
    if ($canEdit) {
        $docOptions = [];
        foreach (db_all('SELECT type_id, name FROM document_types WHERE is_active = 1 ORDER BY sort_order, name') as $dt) { $docOptions[(int) $dt['type_id']] = (string) $dt['name']; }
        card_start('Add an approved fee rule', 'Use one row per document/service category and tier. Keep effective dates non-overlapping for the same document type, category, and tier.');
        echo '<form method="post" action="' . e(base_url('admin/settings.php')) . '"><div class="row">' . $hidden('fee_rule_add');
        echo f_select('type_id', 'Document/service type', $docOptions, '', ['required' => true, 'col' => 'col-md-6']);
        echo f_text('fee_category', 'Fee category', '', ['required' => true, 'maxlength' => 100, 'help' => 'Use a category approved by the barangay, such as a document/service classification.', 'col' => 'col-md-6']);
        echo f_text('fee_tier', 'Fee tier', '', ['required' => true, 'maxlength' => 100, 'help' => 'Name the approved tier; do not assume one from a sample amount.', 'col' => 'col-md-6']);
        echo f_text('unit_rate', 'Approved rate per unit (PHP)', '', ['required' => true, 'maxlength' => 9, 'inputmode' => 'decimal', 'help' => 'Enter the formally approved rate. 0 means free.', 'col' => 'col-md-6']);
        echo f_text('min_quantity', 'Minimum quantity', '1', ['required' => true, 'type' => 'number', 'min' => 1, 'step' => 1, 'col' => 'col-md-3']);
        echo f_text('max_quantity', 'Maximum quantity (optional)', '', ['type' => 'number', 'min' => 1, 'step' => 1, 'help' => 'Leave blank for no upper limit.', 'col' => 'col-md-3']);
        echo f_text('effective_from', 'Effective from', date('Y-m-d'), ['required' => true, 'type' => 'date', 'col' => 'col-md-3']);
        echo f_text('effective_to', 'Effective to (optional)', '', ['type' => 'date', 'help' => 'Leave blank if no end date is approved.', 'col' => 'col-md-3']);
        echo '</div><button class="btn btn-primary" type="submit">Save fee rule</button></form>';
        card_end();
    }
} elseif ($tab === 'documents') {
    $types = db_all('SELECT type_id, name, fee_amount, template_body, is_active FROM document_types ORDER BY sort_order, name');
    echo '<div class="alert alert-secondary">Placeholders for wording: ' . e('{{' . implode('}} {{', TEMPLATE_TOKENS) . '}}') . '. {{barangay}}, {{municipality}} and {{province}} insert only the name (for example Wawa). A document cannot be issued until its fee (0 means free) <strong>and</strong> wording are set.</div>';
    foreach ($types as $t) {
        card_start((string) $t['name'], 'Fee: ' . money($t['fee_amount']) . ' · Wording: ' . ($t['template_body'] === null ? 'not set' : 'set'), 'mb-3');
        echo '<form method="post" action="' . e(base_url('admin/settings.php')) . '"><div class="row">' . $hidden('doctype_save') . '<input type="hidden" name="id" value="' . (int) $t['type_id'] . '">';
        echo f_text('fee_amount', 'Fee (PHP)', $t['fee_amount'] === null ? '' : (string) $t['fee_amount'], ['maxlength' => 9, 'inputmode' => 'decimal', 'help' => 'Blank = not set yet. 0 = free.', 'disabled' => !$canEdit, 'col' => 'col-md-3']);
        echo f_select('is_active', 'Available', ['1' => 'Yes', '0' => 'No'], $t['is_active'] ? '1' : '0', ['disabled' => !$canEdit, 'col' => 'col-md-3']);
        echo f_textarea('template_body', 'Official wording', (string) $t['template_body'], ['rows' => 5, 'maxlength' => 4000, 'help' => 'Enter the official text. Nothing is pre-filled.']);
        if (!$canEdit) { echo '</div></form>'; } else { echo '</div><button class="btn btn-primary" type="submit">Save</button></form>'; }
        card_end();
    }
    if ($canEdit) {
        card_start('Add a document type', 'For example Business certificate or First-time jobseeker, once the barangay confirms them.');
        echo '<form method="post" action="' . e(base_url('admin/settings.php')) . '" class="row g-2 align-items-end">' . $hidden('doctype_add')
           . '<div class="col-md-6"><label class="form-label" for="dt_name">Name</label><input class="form-control" id="dt_name" name="name" maxlength="100" required></div><div class="col-auto"><button class="btn btn-primary" type="submit">Add</button></div></form>';
        card_end();
    }
} elseif ($tab === 'summons') {
    card_start('Hearing summons wording', 'The official text printed on a summons. Nothing is pre-filled; a summons cannot be printed until this is set.');
    echo '<div class="alert alert-secondary">Placeholders: ' . e('{{' . implode('}} {{', SUMMONS_TOKENS) . '}}') . '</div>';
    echo '<form method="post" action="' . e(base_url('admin/settings.php')) . '"><div class="row">' . $hidden('summons_save');
    echo f_textarea('summons_wording', 'Official wording', (string) setting('summons_wording', ''), ['rows' => 7, 'maxlength' => 4000, 'help' => 'Enter the official text. Nothing is pre-filled.']);
    echo '</div>' . ($canEdit ? '<button class="btn btn-primary" type="submit">Save</button>' : '') . '</form>';
    card_end();
} else {
    card_start('SMS text', 'SMS is log-only in this version: messages are recorded, never sent. Blank uses a plain default.');
    echo '<form method="post" action="' . e(base_url('admin/settings.php')) . '"><div class="row">' . $hidden('sms_save');
    echo f_textarea('sms_ready_text', 'Message when a request is ready (placeholders: {{reference_no}} {{document_name}})', (string) setting('sms_ready_text', ''), ['rows' => 3, 'maxlength' => 300, 'help' => 'Default: Your request {{reference_no}} ({{document_name}}) is ready for pickup at the barangay office.']);
    echo '</div>' . ($canEdit ? '<button class="btn btn-primary" type="submit">Save</button>' : '') . '</form>';
    card_end();
}
render_footer();
