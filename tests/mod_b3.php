<?php
/** B3 tests: resident directory aligned with the revised MSIT SRS. */
declare(strict_types=1);

function run_b3(array $S, string $base): void
{
    $sec = $S['secretary'];
    $clk = $S['clerk'];
    $cap = $S['captain'];
    $adm = $S['admin'];
    $purokLabel = 'ZZ B3 MSIT Purok';
    $email = 'b3-resident@example.test';
    $createdIds = [];

    db_exec("DELETE FROM residents WHERE last_name = 'ZZBThree'");
    db_exec("INSERT IGNORE INTO lookups (list_key, label, sort_order, is_active) VALUES ('purok', ?, 9991, 1)", [$purokLabel]);
    $purokId = (int) db_val("SELECT lookup_id FROM lookups WHERE list_key = 'purok' AND label = ?", [$purokLabel]);
    $statuses = lookup_list('resident_status');
    $types = lookup_list('resident_type');
    $activeId = (int) array_search('Active', $statuses, true);
    $transferredId = (int) array_search('Transferred', $statuses, true);
    $deceasedId = (int) array_search('Deceased', $statuses, true);
    $noneId = (int) array_search('None', $types, true);
    $pwdId = (int) array_search('PWD', $types, true);

    $defaults = [
        'last_name' => 'ZZBThree',
        'first_name' => 'Resident',
        'middle_name' => 'MSIT',
        'suffix' => '',
        'birthdate' => '1990-05-12',
        'sex' => 'Male',
        'marital_status' => 'Single',
        'address' => '123 B3 Test Street',
        'purok_id' => (string) $purokId,
        'resident_status_id' => (string) $activeId,
        'resident_type_id' => (string) $noneId,
        'contact_number' => '09171234567',
        'email_address' => $email,
        'notification_preference' => 'email',
    ];
    $mk = static fn(array $values = []): array => $values + $defaults;
    $create = static fn(Http $h, array $values = []) => post_as($h, '/residents/index.php', '/residents/form.php', $mk($values));
    $count = static fn(): int => (int) db_val("SELECT COUNT(*) FROM residents WHERE last_name = 'ZZBThree'");

    try {
        $routeChecks = [];
        foreach ([
            ['/residents/index.php', 'residents.view'],
            ['/residents/form.php', 'residents.add'],
            ['/residents/export.php', 'residents.export'],
        ] as [$path, $permission]) {
            [$ok, $why] = rbac_page($S, $path, $permission);
            if (!$ok) { $routeChecks[] = $path . ': ' . $why; }
        }
        $captainForm = $cap->get('/residents/form.php');
        record('B3-1', 'Resident directory pages enforce server-side permissions and the current view-only Captain cannot open resident registration',
            !$routeChecks && $captainForm['status'] === 403, implode('; ', $routeChecks) . '; captain form=' . $captainForm['status']);

        $before = $count();
        $denied = post_as($cap, '/residents/index.php', '/residents/form.php', $mk());
        $noCsrf = $sec->post('/residents/form.php', $mk());
        record('B3-2', 'A role without resident-add permission and a request without CSRF cannot create a resident',
            $denied['status'] === 403 && $noCsrf['status'] === 403 && $count() === $before,
            'permission=' . $denied['status'] . '; CSRF=' . $noCsrf['status']);

        $invalid = [
            'future birthdate' => ['birthdate' => date('Y-m-d', strtotime('+1 day'))],
            'impossible date' => ['birthdate' => '2023-02-31'],
            'too old' => ['birthdate' => '1850-01-01'],
            'bad mobile' => ['contact_number' => '-------'],
            'bad email' => ['email_address' => 'not-an-email'],
            'missing email for default channel' => ['email_address' => ''],
            'short address' => ['address' => 'x'],
            'missing Purok' => ['purok_id' => ''],
            'unknown Purok' => ['purok_id' => '99999999'],
            'unknown status' => ['resident_status_id' => '99999999'],
            'unknown resident type' => ['resident_type_id' => '99999999'],
            'bad marital status' => ['marital_status' => 'Complicated'],
            'bad sex' => ['sex' => ''],
            'array value' => ['last_name' => ['x']],
        ];
        $badRows = [];
        foreach ($invalid as $label => $values) {
            $values['first_name'] = 'Invalid' . substr(hash('sha256', $label), 0, 6);
            $response = $create($sec, $values);
            if ($response['status'] !== 200 || !str_contains($response['body'], 'alert-danger')) {
                $badRows[] = $label . '=' . $response['status'];
            }
        }
        record('B3-3', 'Resident registration rejects invalid birthdate, contact, address, Purok, status, type, marital status and malformed values',
            !$badRows && $count() === $before, implode(', ', $badRows));

        $created = $create($sec, ['first_name' => 'First']);
        $resident = db_one("SELECT * FROM residents WHERE last_name = 'ZZBThree' AND first_name = 'First' ORDER BY resident_id DESC LIMIT 1");
        $residentId = (int) ($resident['resident_id'] ?? 0);
        if ($residentId > 0) { $createdIds[] = $residentId; }
        $profile = $residentId > 0 ? $clk->get('/residents/view.php?id=' . $residentId) : ['status' => 0, 'body' => ''];
        $b34Ok = $created['status'] === 302 && $resident !== null
            && preg_match('/^RES-\d{4}-\d{6}$/', (string) $resident['resident_no']) === 1
            && $resident['address'] === '123 B3 Test Street'
            && (int) $resident['purok_id'] === $purokId
            && (int) $resident['resident_status_id'] === $activeId
            && (int) $resident['resident_type_id'] === $noneId
            && $resident['notification_preference'] === 'email'
            && $profile['status'] === 200
            && str_contains($profile['body'], (string) $resident['resident_no'])
            && str_contains($profile['body'], '123 B3 Test Street')
            && !str_contains($profile['body'], 'Household members');
        record('B3-4', 'Secretary can register a resident with direct Address and Purok, and BIRMS assigns a unique Resident ID with Email as the default notification channel',
            $b34Ok,
            'POST=' . $created['status'] . '; location=' . (string) ($created['location'] ?? '')
            . '; resident=' . ($resident === null ? 'not saved' : (string) $resident['resident_no'])
            . '; response=' . substr(trim(strip_tags((string) $created['body'])), 0, 1200)
            . '; validation=' . (preg_match('/alert-danger[^>]*>(.*?)<\\/div>/s', (string) $created['body'], $errMatch) ? substr(trim(strip_tags($errMatch[1])), 0, 1200) : 'no alert-danger')
            . '; profile=' . $profile['status']);

        $duplicateRejected = $create($sec, ['first_name' => 'First']);
        $duplicateAllowed = $create($sec, ['first_name' => 'First', 'confirm_dup' => '1', 'address' => '456 Different B3 Street']);
        $twin = db_one("SELECT resident_id FROM residents WHERE last_name = 'ZZBThree' AND first_name = 'First' ORDER BY resident_id DESC LIMIT 1");
        if ($twin !== null && (int) $twin['resident_id'] !== $residentId) { $createdIds[] = (int) $twin['resident_id']; }
        record('B3-5', 'Duplicate name and birthdate is warned about and only allowed after explicit confirmation',
            str_contains($duplicateRejected['body'], 'already exists')
            && $duplicateAllowed['status'] === 302
            && (int) db_val("SELECT COUNT(*) FROM residents WHERE last_name = 'ZZBThree' AND first_name = 'First'") === 2);

        $edit = post_as($sec, '/residents/view.php?id=' . $residentId, '/residents/form.php?id=' . $residentId,
            $mk([
                'first_name' => 'First',
                'marital_status' => 'Married',
                'address' => '789 Changed B3 Avenue',
                'resident_status_id' => (string) $transferredId,
                'resident_type_id' => (string) $pwdId,
                'notification_preference' => 'both',
            ]));
        $updated = db_one('SELECT marital_status,address,resident_status_id,resident_type_id,notification_preference,residency_status FROM residents WHERE resident_id = ?', [$residentId]);
        $statusAudit = db_one("SELECT remarks FROM audit_log WHERE action = 'resident_status_changed' AND entity_id = ? ORDER BY audit_id DESC LIMIT 1", [$residentId]);
        record('B3-6', 'Resident edits save marital status, address, resident status/type and notification preference, with status-change audit history',
            $edit['status'] === 302
            && $updated !== null
            && $updated['marital_status'] === 'Married'
            && $updated['address'] === '789 Changed B3 Avenue'
            && (int) $updated['resident_status_id'] === $transferredId
            && (int) $updated['resident_type_id'] === $pwdId
            && $updated['notification_preference'] === 'both'
            && $updated['residency_status'] === 'Relocated'
            && $statusAudit !== null
            && str_contains((string) $statusAudit['remarks'], 'Active -> Transferred'),
            'status=' . ($statusAudit['remarks'] ?? 'not logged'));

        $searchById = $sec->get('/residents/index.php?q=' . urlencode((string) $resident['resident_no']));
        $searchByAddress = $sec->get('/residents/index.php?q=' . urlencode('789 Changed B3 Avenue'));
        $filterPurok = $sec->get('/residents/index.php?purok=' . $purokId . '&q=ZZBThree');
        $filterStatus = $sec->get('/residents/index.php?status=Transferred&q=ZZBThree');
        $filterType = $sec->get('/residents/index.php?type=' . $pwdId . '&q=ZZBThree');
        record('B3-7', 'Resident search and filters use Resident ID, individual address, Purok, revised resident status and resident type',
            str_contains($searchById['body'], (string) $resident['resident_no'])
            && str_contains($searchByAddress['body'], '789 Changed B3 Avenue')
            && str_contains($filterPurok['body'], 'ZZBThree')
            && str_contains($filterStatus['body'], 'Transferred')
            && str_contains($filterType['body'], 'PWD'));

        $deniedArchive = post_as($clk, '/residents/view.php?id=' . $residentId, '/residents/action.php',
            ['id' => (string) $residentId, 'action' => 'archive']);
        $archive = post_as($sec, '/residents/view.php?id=' . $residentId, '/residents/action.php',
            ['id' => (string) $residentId, 'action' => 'archive']);
        $archivedAt = db_val('SELECT archived_at FROM residents WHERE resident_id = ?', [$residentId]);
        $clerkArchivedView = $clk->get('/residents/view.php?id=' . $residentId);
        $secretaryArchivedList = $sec->get('/residents/index.php?q=ZZBThree&show=archived');
        $secretaryRestore = post_as($sec, '/residents/view.php?id=' . $residentId, '/residents/action.php',
            ['id' => (string) $residentId, 'action' => 'restore']);
        $stillArchived = db_val('SELECT archived_at FROM residents WHERE resident_id = ?', [$residentId]) !== null;
        $adminRestore = post_as($adm, '/residents/view.php?id=' . $residentId, '/residents/action.php',
            ['id' => (string) $residentId, 'action' => 'restore']);
        record('B3-8', 'Residents are archived, not deleted; unauthorized archive/restore actions are denied and archived records remain available to authorized staff',
            $deniedArchive['status'] === 403
            && $archive['status'] === 302
            && $archivedAt !== null
            && $clerkArchivedView['status'] === 404
            && str_contains($secretaryArchivedList['body'], 'ZZBThree')
            && $secretaryRestore['status'] === 403
            && $stillArchived
            && $adminRestore['status'] === 302
            && db_val('SELECT archived_at FROM residents WHERE resident_id = ?', [$residentId]) === null);

        $oldAction = post_as($sec, '/residents/view.php?id=' . $residentId, '/residents/action.php',
            ['id' => (string) $residentId, 'action' => 'set_relocated']);
        record('B3-9', 'Legacy Active/Relocated status toggles are no longer available; status is changed through the revised resident profile',
            $oldAction['status'] === 404);

        db_exec("UPDATE residents SET address = '=HYPERLINK(\"http://example.test\",\"resident\")' WHERE resident_id = ?", [$residentId]);
        $csv = $sec->get('/residents/export.php?q=ZZBThree');
        $csvContent = ltrim($csv['body'], "\xEF\xBB\xBF");
        $csvLines = preg_split('/\r?\n/', $csvContent) ?: [];
        $formula = false;
        foreach ($csvLines as $line) {
            foreach (str_getcsv($line, ',', '"', '\\') as $cell) {
                if (is_string($cell) && $cell !== '' && strpos('=+-@', $cell[0]) !== false) { $formula = true; }
            }
        }
        $clerkExport = $clk->get('/residents/export.php');
        record('B3-10', 'Resident CSV export includes revised SRS columns, neutralizes spreadsheet formulas and enforces export permissions',
            $csv['status'] === 200
            && str_starts_with($csvContent, '"Resident ID","Last name","First name"')
            && str_contains($csvContent, 'Notification preference')
            && !$formula
            && $clerkExport['status'] === 403
            && (int) db_val("SELECT COUNT(*) FROM audit_log WHERE action = 'resident_exported'") >= 1);

        $legacyHouseholds = $sec->get('/residents/households.php');
        $legacyHouseholdEdit = $sec->get('/residents/household_edit.php');
        $legacyImport = $sec->get('/residents/import.php');
        $legacyTemplate = $sec->get('/residents/import_template.php');
        record('B3-11', 'Household grouping and the old household-based CSV importer are retired from resident navigation and direct routes',
            $legacyHouseholds['status'] === 302
            && $legacyHouseholdEdit['status'] === 302
            && $legacyImport['status'] === 302
            && $legacyTemplate['status'] === 302);

        $odd = [
            $sec->get('/residents/index.php?page[]=1&q[]=x&purok[]=1&status[]=a&type[]=2&show[]=archived'),
            $sec->get('/residents/view.php?id[]=1'),
            $sec->get('/residents/view.php?id=99999999'),
            $sec->get('/residents/form.php?id[]=1'),
            $sec->get('/residents/index.php?page=-9&q=' . str_repeat('A', 4000)),
            $sec->post('/residents/action.php', ['_csrf' => $sec->token('/residents/index.php'), 'id' => ['1'], 'action' => ['archive']]),
        ];
        $badOdd = [];
        foreach ($odd as $i => $response) {
            if ($response['status'] >= 500 || leaks($response)) { $badOdd[] = $i . ':' . $response['status']; }
        }
        record('B3-12', 'Malformed, array, oversized and unknown-id parameters do not crash resident pages or leak database errors',
            !$badOdd, implode(', ', $badOdd));
    } finally {
        if ($createdIds) {
            $ids = array_values(array_unique(array_filter($createdIds)));
            $marks = implode(',', array_fill(0, count($ids), '?'));
            db_exec('DELETE FROM resident_verification_challenges WHERE resident_id IN (' . $marks . ')', $ids);
            db_exec('DELETE FROM notification_outbox WHERE resident_id IN (' . $marks . ')', $ids);
            db_exec('DELETE FROM residents WHERE resident_id IN (' . $marks . ')', $ids);
        }
        db_exec("DELETE FROM residents WHERE last_name = 'ZZBThree'");
        db_exec("DELETE FROM lookups WHERE list_key = 'purok' AND label = ?", [$purokLabel]);
    }
}
