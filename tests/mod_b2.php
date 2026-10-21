<?php
/** B2 tests: settings, lists, document fees and wording, schema tools. */
declare(strict_types=1);

function run_b2(array $S, string $base): void
{
    $snapKeys = ['barangay_name', 'municipality', 'province', 'captain_name', 'secretary_name', 'age_brackets', 'sms_ready_text'];
    $snap = []; foreach ($snapKeys as $k) { $snap[$k] = db_val('SELECT setting_value FROM settings WHERE setting_key = ?', [$k]); }
    $types = db_all('SELECT type_id, fee_amount, template_body, is_active FROM document_types');

    $bad = ''; foreach (['general', 'puroks', 'lists', 'documents', 'fee_matrix', 'sms'] as $tab) { [$ok, $why] = rbac_page($S, '/admin/settings.php?tab=' . $tab, 'settings.view'); if (!$ok) { $bad .= "{$tab}: {$why}"; } }
    record('B2-1', 'Settings pages: every role gets exactly what the permission map says (view = admin, captain, secretary)', $bad === '', $bad);

    $sec = $S['secretary']; $adm = $S['admin']; $cap = $S['captain'];
    $r = post_as($cap, '/admin/settings.php', '/admin/settings.php', ['do' => 'general_save', 'tab' => 'general', 'barangay_name' => 'Hacked', 'municipality' => 'X1', 'province' => 'Y1', 'age_brackets' => '0-17']);
    record('B2-2', 'A view-only role cannot save settings even with a valid token (403, nothing changed)', $r['status'] === 403 && db_val("SELECT setting_value FROM settings WHERE setting_key = 'barangay_name'") === $snap['barangay_name']);
    $r = $sec->post('/admin/settings.php', ['do' => 'general_save', 'tab' => 'general', 'barangay_name' => 'Wawa', 'municipality' => 'Balagtas', 'province' => 'Bulacan', 'age_brackets' => '0-17']);
    record('B2-3', 'Saving settings without a CSRF token is rejected', $r['status'] === 403);

    $post = fn(Http $h, array $d) => post_as($h, '/admin/settings.php', '/admin/settings.php', $d);
    $r = $post($sec, ['do' => 'general_save', 'tab' => 'general', 'barangay_name' => 'Wawa', 'municipality' => 'Balagtas', 'province' => 'Bulacan', 'captain_name' => 'x1', 'age_brackets' => '0-17,10-59']);
    record('B2-4', 'Invalid captain name and overlapping age brackets are rejected; stored values unchanged', $r['status'] === 200 && str_contains($r['body'], 'alert-danger') && db_val("SELECT setting_value FROM settings WHERE setting_key = 'age_brackets'") === $snap['age_brackets']);
    $r = $post($sec, ['do' => 'general_save', 'tab' => 'general', 'barangay_name' => 'Wawa', 'municipality' => 'Balagtas', 'province' => 'Bulacan', 'captain_name' => 'Juan Dela Cruz', 'secretary_name' => 'Maria Santos', 'age_brackets' => '0-12,13-59,60-200']);
    record('B2-5', 'Secretary saves barangay details; values stored', $r['status'] === 302 && db_val("SELECT setting_value FROM settings WHERE setting_key = 'captain_name'") === 'Juan Dela Cruz' && setting('age_brackets') !== null);

    // lists
    $r = $post($sec, ['do' => 'lookup_add', 'tab' => 'puroks', 'list_key' => 'purok', 'label' => 'ZZ Test Purok A']);
    $dup = $post($sec, ['do' => 'lookup_add', 'tab' => 'puroks', 'list_key' => 'purok', 'label' => 'ZZ Test Purok A']);
    $badKey = $post($sec, ['do' => 'lookup_add', 'tab' => 'puroks', 'list_key' => 'hacker', 'label' => 'ZZ Test']);
    $id = (int) db_val("SELECT lookup_id FROM lookups WHERE list_key = 'purok' AND label = 'ZZ Test Purok A'");
    record('B2-6', 'Add a Purok: saved; duplicate and unknown list refused', $r['status'] === 302 && $id > 0 && str_contains($dup['body'], 'already exists') && str_contains($badKey['body'], 'Unknown list'));
    $post($sec, ['do' => 'lookup_toggle', 'tab' => 'puroks', 'id' => (string) $id]);
    $hiddenOk = !in_array('ZZ Test Purok A', lookup_list('purok'), true) && in_array('ZZ Test Purok A', lookup_list('purok', false), true);
    $post($sec, ['do' => 'lookup_toggle', 'tab' => 'puroks', 'id' => (string) $id]);
    record('B2-7', 'Hiding a Purok removes it from choices but keeps it on record; showing restores it', $hiddenOk && in_array('ZZ Test Purok A', lookup_list('purok'), true));
    $r = $post($sec, ['do' => 'lookup_add', 'tab' => 'lists', 'list_key' => 'resident_category', 'label' => 'ZZ Test Category']);
    record('B2-8', 'Other lists (resident category) can be added', $r['status'] === 302 && (bool) db_val("SELECT COUNT(*) FROM lookups WHERE list_key = 'resident_category' AND label = 'ZZ Test Category'"));

    // document types
    $tid = (int) db_val("SELECT type_id FROM document_types WHERE name = 'Barangay Clearance'");
    $seed = (int) db_val("SELECT COUNT(*) FROM document_types WHERE name IN ('Barangay Clearance','Certificate of Residency','Certificate of Indigency')");
    $sql = (string) file_get_contents(APP_ROOT . '/database/migrations/002_settings.sql');
    preg_match('/INSERT INTO document_types \(([^)]*)\)/', $sql, $m);
    record('B2-9', 'The three documented document names exist; the migration seeds no fee and no wording', $seed === 3 && isset($m[1]) && trim($m[1]) === 'name, sort_order');
    $bads = [['fee_amount' => 'abc'], ['fee_amount' => '-5'], ['fee_amount' => '1234567'], ['fee_amount' => '10', 'template_body' => 'Hello {{nonsense}}']];
    $allBad = true; foreach ($bads as $b) { $r = $post($sec, ['do' => 'doctype_save', 'tab' => 'documents', 'id' => (string) $tid, 'is_active' => '1'] + $b); if (!str_contains($r['body'], 'alert-danger')) { $allBad = false; } }
    record('B2-10', 'Bad fees (abc, -5, too large) and unknown wording placeholders are rejected', $allBad);
    $r = $post($sec, ['do' => 'doctype_save', 'tab' => 'documents', 'id' => (string) $tid, 'is_active' => '1', 'fee_amount' => '0', 'template_body' => 'This certifies {{full_name}} of {{purok}}.']);
    $row = db_one('SELECT fee_amount, template_body FROM document_types WHERE type_id = ?', [$tid]);
    record('B2-11', 'A fee of 0 (free) and wording save correctly', $r['status'] === 302 && (float) $row['fee_amount'] === 0.0 && $row['fee_amount'] !== null && str_contains((string) $row['template_body'], '{{full_name}}'));
    $post($sec, ['do' => 'doctype_save', 'tab' => 'documents', 'id' => (string) $tid, 'is_active' => '1', 'fee_amount' => '', 'template_body' => '']);
    $row = db_one('SELECT fee_amount, template_body FROM document_types WHERE type_id = ?', [$tid]);
    record('B2-12', 'Blank fee and wording go back to "not set" (NULL), not zero', $row['fee_amount'] === null && $row['template_body'] === null);
    $r = $post($sec, ['do' => 'doctype_add', 'tab' => 'documents', 'name' => 'Barangay Clearance']);
    record('B2-13', 'Adding a duplicate document type is refused', str_contains($r['body'], 'already exists'));
    $r = $post($sec, ['do' => 'sms_save', 'tab' => 'sms', 'sms_ready_text' => 'Hi {{bogus}}']);
    $ok2 = $post($sec, ['do' => 'sms_save', 'tab' => 'sms', 'sms_ready_text' => 'Ready: {{reference_no}}']);
    record('B2-14', 'SMS text: unknown placeholder refused, valid text saved', str_contains($r['body'], 'Unknown placeholder') && $ok2['status'] === 302 && db_val("SELECT setting_value FROM settings WHERE setting_key = 'sms_ready_text'") === 'Ready: {{reference_no}}');

    // Multi-tier fee matrix: rules are configured only by an authorized Secretary; no rates are seeded.
    $ruleStart = date('Y-m-d');
    $feeRuleData = [
        'do' => 'fee_rule_add', 'tab' => 'fee_matrix', 'type_id' => (string) $tid,
        'fee_category' => 'ZZ B2 Fee Category', 'fee_tier' => 'ZZ B2 Tier',
        'unit_rate' => '12.50', 'min_quantity' => '1', 'max_quantity' => '10',
        'effective_from' => $ruleStart, 'effective_to' => '',
    ];
    $feeRuleResponse = $post($sec, $feeRuleData);
    $feeRuleId = (int) db_val("SELECT fee_rule_id FROM fee_matrix WHERE type_id = ? AND fee_category = 'ZZ B2 Fee Category' AND fee_tier = 'ZZ B2 Tier'", [$tid]);
    record('B2-15', 'Secretary can configure an approved effective-dated fee rule; rate, quantity range and audit are saved',
        $feeRuleResponse['status'] === 302 && $feeRuleId > 0
        && abs((float) db_val('SELECT unit_rate FROM fee_matrix WHERE fee_rule_id = ?', [$feeRuleId]) - 12.50) < 0.001
        && (int) db_val('SELECT COUNT(*) FROM audit_log WHERE action = ? AND entity_id = ?', ['fee_rule_added', $feeRuleId]) === 1);

    $overlap = $post($sec, array_replace($feeRuleData, ['unit_rate' => '15.00']));
    $invalidRange = $post($sec, array_replace($feeRuleData, ['max_quantity' => '0']));
    $captainFeeEdit = post_as($cap, '/admin/settings.php', '/admin/settings.php', array_replace($feeRuleData, ['unit_rate' => '99.00']));
    record('B2-16', 'Duplicate/overlapping fee rules, invalid quantity ranges and unprivileged fee edits are refused',
        str_contains($overlap['body'], 'overlapping effective dates')
        && str_contains($invalidRange['body'], 'Maximum quantity must be a positive whole number')
        && $captainFeeEdit['status'] === 403
        && (int) db_val("SELECT COUNT(*) FROM fee_matrix WHERE type_id = ? AND fee_category = 'ZZ B2 Fee Category' AND fee_tier = 'ZZ B2 Tier'", [$tid]) === 1);

    $disableFeeRule = $post($sec, ['do' => 'fee_rule_disable', 'tab' => 'fee_matrix', 'id' => (string) $feeRuleId]);
    record('B2-17', 'Disabling a fee rule preserves its row, marks it inactive, and is audited',
        $disableFeeRule['status'] === 302
        && (int) db_val('SELECT is_active FROM fee_matrix WHERE fee_rule_id = ?', [$feeRuleId]) === 0
        && (int) db_val('SELECT COUNT(*) FROM audit_log WHERE action = ? AND entity_id = ?', ['fee_rule_disabled', $feeRuleId]) === 1);

    // robustness + audit
    $odd = [$sec->get('/admin/settings.php?tab[]=x'), $sec->post('/admin/settings.php', ['_csrf' => $sec->token('/admin/settings.php'), 'do' => ['x'], 'tab' => ['y'], 'label' => ['z'], 'id' => ['1']])];
    $bad = array_filter($odd, fn($x) => $x['status'] >= 500 || leaks($x));
    record('B2-18', 'Array and odd parameters on the settings page never crash it', !$bad);
    $need = ['settings_saved', 'lookup_added', 'lookup_toggled', 'doctype_saved', 'settings_rejected', 'fee_rule_added', 'fee_rule_disabled'];
    $miss = array_filter($need, fn($a) => (int) db_val('SELECT COUNT(*) FROM audit_log WHERE action = ?', [$a]) < 1);
    record('B2-19', 'Every settings change and rejection left an audit row', !$miss, implode(',', $miss));

    // schema tools
    $out = []; $code = 0; exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(APP_ROOT . '/tools/schema_check.php') . ' 2>&1', $out, $code);
    record('B2-20', 'schema_check.php agrees with the migrated database (expected_schema.json is in sync)', $code === 0, $code === 0 ? '' : implode(' | ', array_slice($out, 0, 4)));
    $out = []; exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(APP_ROOT . '/tools/migrate.php') . ' status 2>&1', $out, $code);
    record('B2-21', 'No migration is pending on the working database', $code === 0 && preg_match('/(^|\n)0 pending\./', implode("\n", $out)) === 1);

    // cleanup: put everything back
    foreach ($snap as $k => $v) { db_exec('UPDATE settings SET setting_value = ? WHERE setting_key = ?', [$v, $k]); }
    foreach ($types as $t) { db_exec('UPDATE document_types SET fee_amount = ?, template_body = ?, is_active = ? WHERE type_id = ?', [$t['fee_amount'], $t['template_body'], $t['is_active'], $t['type_id']]); }
    if (isset($feeRuleId) && $feeRuleId > 0) { db_exec('DELETE FROM fee_matrix WHERE fee_rule_id = ?', [$feeRuleId]); }
    db_exec("DELETE FROM lookups WHERE label LIKE 'ZZ Test%'");
}
