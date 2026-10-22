<?php
/** B10 integration/security checks for the resident CSV import. */
declare(strict_types=1);

function b10_csv_file(array $headers, array $records): string
{
    $path = tempnam(sys_get_temp_dir(), 'birms-csv-');
    if ($path === false) { throw new RuntimeException('Unable to create test CSV.'); }
    $fh = fopen($path, 'wb');
    if ($fh === false) { throw new RuntimeException('Unable to open test CSV.'); }
    fputcsv($fh, $headers);
    foreach ($records as $record) {
        $cells = [];
        foreach ($headers as $key) { $cells[] = (string) ($record[$key] ?? ''); }
        fputcsv($fh, $cells);
    }
    fclose($fh);
    return $path;
}
function b10_row(array $overrides = []): array
{
    return array_replace([
        'last_name' => 'ZZ Importer', 'first_name' => 'CsvPerson', 'middle_name' => '', 'suffix' => '',
        'birthdate' => '1991-04-18', 'sex' => 'Female', 'civil_status' => 'Single', 'contact_number' => '',
        'household_code' => '', 'purok' => '', 'category' => '', 'employment_status' => '',
        'voter_status' => 'Unverified', 'residency_status' => 'Active', 'is_household_head' => '0',
    ], $overrides);
}
function b10_upload_preview(Http $client, array $headers, array $records, string $filename = 'residents.csv'): array
{
    $path = b10_csv_file($headers, $records);
    try {
        return $client->upload('/residents/import.php', [
            '_csrf' => $client->token('/residents/import.php'), 'action' => 'preview',
        ], 'csv_file', $path, $filename, 'text/csv');
    } finally {
        @unlink($path);
    }
}

function run_b10(array $S, string $base): void
{
    $headers = [
        'last_name', 'first_name', 'middle_name', 'suffix', 'birthdate', 'sex', 'civil_status',
        'contact_number', 'household_code', 'purok', 'category', 'employment_status',
        'voter_status', 'residency_status', 'is_household_head',
    ];

    // Isolated test fixtures. Remove only named test records before setup.
    db_exec("DELETE FROM residents WHERE last_name LIKE 'ZZ Import%'");
    db_exec("DELETE FROM households WHERE household_code LIKE 'ZZI-%'");
    db_exec("DELETE FROM lookups WHERE list_key IN ('purok','resident_category','employment_status') AND label LIKE 'ZZ Import%'");

    $suffix = substr(hash('sha256', (string) microtime(true)), 0, 6);
    $purokLabel = 'ZZ Import Purok ' . $suffix;
    $categoryLabel = 'ZZ Import Category ' . $suffix;
    $employmentLabel = 'ZZ Import Employment ' . $suffix;
    $householdCode = 'ZZI-' . $suffix;

    db_exec("INSERT INTO lookups (list_key, label, sort_order, is_active) VALUES ('purok', ?, 9900, 1)", [$purokLabel]);
    $purokId = (int) db()->lastInsertId();
    db_exec("INSERT INTO lookups (list_key, label, sort_order, is_active) VALUES ('resident_category', ?, 9900, 1)", [$categoryLabel]);
    $categoryId = (int) db()->lastInsertId();
    db_exec("INSERT INTO lookups (list_key, label, sort_order, is_active) VALUES ('employment_status', ?, 9900, 1)", [$employmentLabel]);
    $employmentId = (int) db()->lastInsertId();
    db_exec('INSERT INTO households (household_code, purok_id, street_address) VALUES (?, ?, ?)', [$householdCode, $purokId, 'ZZ Import Test Address']);
    $householdId = (int) db()->lastInsertId();

    db_exec(
        'INSERT INTO residents (household_id, last_name, first_name, birthdate, sex, civil_status, category_id, employment_status_id, voter_status, residency_status, is_household_head, created_by)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, NULL)',
        [$householdId, 'ZZ Importer', 'CsvDuplicate', '1980-01-01', 'Female', 'Single', $categoryId, $employmentId, 'Registered', 'Active']
    );
    $duplicateId = (int) db()->lastInsertId();

    $rbacOk = true; $why = '';
    foreach ($S as $role => $client) {
        $response = $client->get('/residents/import.php');
        $expected = role_can($role, 'residents.add') ? 200 : 403;
        if ($response['status'] !== $expected || leaks($response)) {
            $rbacOk = false; $why .= $role . ' got ' . $response['status'] . ' expected ' . $expected . '; ';
        }
    }
    record('B10-01', 'CSV import screen enforces the existing residents.add permission on the server', $rbacOk, $why);

    $template = $S['secretary']->get('/residents/import_template.php');
    record('B10-02', 'Authorized user can download the schema-backed CSV template',
        $template['status'] === 200
        && str_contains(strtolower((string) ($template['headers']['content-type'] ?? '')), 'text/csv')
        && str_contains($template['body'], 'last_name')
        && str_contains($template['body'], 'household_code')
        && str_contains($template['body'], 'is_household_head'));

    $beforeCount = (int) db_val('SELECT COUNT(*) FROM residents');
    $missing = b10_row(['first_name' => '', 'birthdate' => '1994-02-10']);
    $badDate = b10_row(['first_name' => 'CsvBadDate', 'birthdate' => '2004-02-30']);
    $invalidRequired = b10_upload_preview($S['secretary'], $headers, [$missing, $badDate]);
    record('B10-03', 'Preview rejects missing required values and impossible dates without saving residents',
        $invalidRequired['status'] === 200
        && str_contains($invalidRequired['body'], 'First name is required')
        && str_contains($invalidRequired['body'], 'real date in YYYY-MM-DD')
        && (int) db_val('SELECT COUNT(*) FROM residents') === $beforeCount);

    $badReference = b10_row([
        'first_name' => 'CsvBadRef', 'birthdate' => '1992-01-12',
        'household_code' => 'NO-SUCH-HOUSEHOLD', 'purok' => 'NO SUCH PUROK',
        'category' => 'NO SUCH CATEGORY', 'employment_status' => 'NO SUCH EMPLOYMENT',
    ]);
    $invalidRefs = b10_upload_preview($S['secretary'], $headers, [$badReference]);
    $errorDownloadOk = false;
    if (preg_match('/href="([^"]*import_errors\.php\?token=[a-f0-9]+)"/', $invalidRefs['body'], $link) === 1) {
        $path = html_entity_decode($link[1], ENT_QUOTES, 'UTF-8');
        $urlPath = (string) (parse_url($path, PHP_URL_PATH) ?: '/residents/import_errors.php');
        $query = (string) (parse_url($path, PHP_URL_QUERY) ?: '');
        $download = $S['secretary']->get($urlPath . ($query !== '' ? '?' . $query : ''));
        $errorDownloadOk = $download['status'] === 200
            && str_contains(strtolower((string) ($download['headers']['content-type'] ?? '')), 'text/csv')
            && str_contains($download['body'], 'Rejected');
    }
    record('B10-04', 'Unknown household/Purok/category/employment references are rejected and error details download as CSV',
        $invalidRefs['status'] === 200
        && str_contains($invalidRefs['body'], 'does not match an existing household')
        && str_contains($invalidRefs['body'], 'Purok belongs to a household')
        && str_contains($invalidRefs['body'], 'not an active configured resident category')
        && str_contains($invalidRefs['body'], 'not an active configured employment status')
        && $errorDownloadOk);

    $duplicate = b10_row([
        'first_name' => 'CsvDuplicate', 'birthdate' => '1980-01-01',
        'household_code' => $householdCode, 'purok' => $purokLabel,
        'category' => $categoryLabel, 'employment_status' => $employmentLabel,
    ]);
    $duplicateResponse = b10_upload_preview($S['secretary'], $headers, [$duplicate]);
    $unchanged = db_one('SELECT resident_id, voter_status, category_id FROM residents WHERE resident_id = ?', [$duplicateId]);
    record('B10-05', 'Known duplicate is skipped and existing resident fields are not overwritten',
        $duplicateResponse['status'] === 200
        && str_contains($duplicateResponse['body'], 'Skipped')
        && str_contains($duplicateResponse['body'], 'Existing records are never overwritten')
        && $unchanged !== null && $unchanged['voter_status'] === 'Registered'
        && (int) $unchanged['category_id'] === $categoryId
        && (int) db_val('SELECT COUNT(*) FROM residents WHERE first_name = ? AND last_name = ? AND birthdate = ?', ['CsvDuplicate', 'ZZ Importer', '1980-01-01']) === 1);

    $wrongType = b10_upload_preview($S['secretary'], $headers, [b10_row(['first_name' => 'CsvWrongFile'])], 'residents.txt');
    record('B10-06', 'Non-CSV extension is rejected before preview or database changes',
        $wrongType['status'] === 200 && str_contains($wrongType['body'], 'Only files with a .csv extension')
        && (int) db_val('SELECT COUNT(*) FROM residents') === $beforeCount);

    $wrongHeaderPath = b10_csv_file(['last_name', 'first_name'], [['last_name' => 'ZZ Importer', 'first_name' => 'CsvWrongHeader']]);
    try {
        $badHeader = $S['secretary']->upload('/residents/import.php', [
            '_csrf' => $S['secretary']->token('/residents/import.php'), 'action' => 'preview',
        ], 'csv_file', $wrongHeaderPath, 'bad-template.csv', 'text/csv');
    } finally {
        @unlink($wrongHeaderPath);
    }
    record('B10-07', 'Malformed/header-mismatched file fails safely without inserting residents',
        $badHeader['status'] === 200 && str_contains($badHeader['body'], 'header does not match')
        && (int) db_val('SELECT COUNT(*) FROM residents') === $beforeCount + 1);

    $valid = b10_row([
        'first_name' => 'CsvValidPerson', 'birthdate' => '1993-06-15',
        'household_code' => $householdCode, 'purok' => $purokLabel,
        'category' => $categoryLabel, 'employment_status' => $employmentLabel,
    ]);
    $validPreview = b10_upload_preview($S['secretary'], $headers, [$valid]);
    $tokenFound = preg_match('/name="import_token" value="([a-f0-9]{48})"/', $validPreview['body'], $tokenMatch) === 1;
    $committed = ['status' => 0, 'location' => null, 'body' => ''];
    $summary = ['status' => 0, 'body' => ''];
    if ($validPreview['status'] === 200 && $tokenFound) {
        $committed = $S['secretary']->post('/residents/import.php', [
            '_csrf' => $S['secretary']->token('/residents/import.php'),
            'action' => 'commit', 'import_token' => $tokenMatch[1],
        ]);
        $location = (string) ($committed['location'] ?? '');
        $path = (string) (parse_url($location, PHP_URL_PATH) ?: '/residents/import.php');
        $query = (string) (parse_url($location, PHP_URL_QUERY) ?: '');
        $summary = $S['secretary']->get($path . ($query !== '' ? '?' . $query : ''));
    }
    $saved = db_one('SELECT resident_id, household_id, category_id, employment_status_id, created_by FROM residents WHERE first_name = ? AND last_name = ? AND birthdate = ?', ['CsvValidPerson', 'ZZ Importer', '1993-06-15']);
    $secretaryId = (int) db_val("SELECT user_id FROM users WHERE username = 'zz_test_secretary'");
    record('B10-08', 'Valid row imports after confirmation and resolves real household/category/employment IDs',
        $validPreview['status'] === 200 && str_contains($validPreview['body'], 'Ready')
        && $committed['status'] === 302 && $summary['status'] === 200
        && str_contains($summary['body'], 'Import transaction completed')
        && $saved !== null && (int) $saved['household_id'] === $householdId
        && (int) $saved['category_id'] === $categoryId && (int) $saved['employment_status_id'] === $employmentId
        && (int) $saved['created_by'] === $secretaryId);

    $unauthorized = $S['captain']->get('/residents/import_errors.php?token=guess');
    record('B10-09', 'Unauthorized role cannot download import error reports', $unauthorized['status'] === 403 && !leaks($unauthorized));

    // Exercise the transaction's rollback behavior after a successful insert statement followed by failure.
    $rollbackName = 'ZZ Import Rollback';
    $rollbackBefore = (int) db_val('SELECT COUNT(*) FROM residents WHERE last_name = ?', [$rollbackName]);
    $rolledBack = false;
    try {
        db_transaction(function () use ($householdId, $rollbackName): void {
            db_exec(
                'INSERT INTO residents (household_id, last_name, first_name, birthdate, sex, civil_status, voter_status, residency_status, is_household_head)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0)',
                [$householdId, $rollbackName, 'CsvRollback', '1994-05-06', 'Male', 'Single', 'Unverified', 'Active']
            );
            throw new RuntimeException('intentional test failure after first insert');
        });
    } catch (RuntimeException $e) {
        $rolledBack = str_contains($e->getMessage(), 'intentional test failure');
    }
    record('B10-10', 'Database transaction rollback leaves no partial resident writes after a simulated failure',
        $rolledBack && (int) db_val('SELECT COUNT(*) FROM residents WHERE last_name = ?', [$rollbackName]) === $rollbackBefore);

    db_exec("DELETE FROM residents WHERE last_name LIKE 'ZZ Import%'");
    db_exec("DELETE FROM households WHERE household_code LIKE 'ZZI-%'");
    db_exec("DELETE FROM lookups WHERE list_key IN ('purok','resident_category','employment_status') AND label LIKE 'ZZ Import%'");
}
