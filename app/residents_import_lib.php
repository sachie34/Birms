<?php
/** CSV resident import: strict template parsing, live reference validation, and atomic insert. */
declare(strict_types=1);

const RESIDENT_IMPORT_MAX_BYTES = 2097152; // 2 MiB
const RESIDENT_IMPORT_MAX_ROWS = 2000;
const RESIDENT_IMPORT_STAGE_TTL = 1800;

function resident_import_headers(): array
{
    return [
        'last_name', 'first_name', 'middle_name', 'suffix', 'birthdate', 'sex', 'civil_status',
        'contact_number', 'household_code', 'purok', 'category', 'employment_status',
        'voter_status', 'residency_status', 'is_household_head',
    ];
}

function resident_import_parse_csv(string $path): array
{
    $fh = @fopen($path, 'rb');
    if ($fh === false) {
        return ['ok' => false, 'error' => 'The uploaded CSV could not be read.', 'rows' => []];
    }

    try {
        $header = fgetcsv($fh, 0, ',', '"', '\\');
        if (!is_array($header) || $header === []) {
            return ['ok' => false, 'error' => 'The CSV is empty or has no header row.', 'rows' => []];
        }
        $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $header[0]) ?? (string) $header[0];
        $actual = array_map(static fn($v): string => strtolower(trim((string) $v)), $header);
        if ($actual !== resident_import_headers()) {
            return [
                'ok' => false,
                'error' => 'The CSV header does not match the BIRMS resident-import template. Download a fresh template and keep its column names and order unchanged.',
                'rows' => [],
            ];
        }

        $rows = [];
        $line = 1;
        while (($cells = fgetcsv($fh, 0, ',', '"', '\\')) !== false) {
            $line++;
            if ($cells === [null] || (is_array($cells) && count(array_filter($cells, static fn($v): bool => trim((string) $v) !== '')) === 0)) {
                continue;
            }
            if (count($rows) >= RESIDENT_IMPORT_MAX_ROWS) {
                return ['ok' => false, 'error' => 'This file has more than ' . RESIDENT_IMPORT_MAX_ROWS . ' data rows. Split it into smaller CSV files.', 'rows' => []];
            }

            $csvError = '';
            if (count($cells) !== count($actual)) {
                $csvError = 'This row has ' . count($cells) . ' columns; the template requires ' . count($actual) . '.';
            }
            $values = [];
            foreach (resident_import_headers() as $i => $key) {
                $value = (string) ($cells[$i] ?? '');
                if (!preg_match('//u', $value)) {
                    $csvError = 'One or more values are not valid UTF-8 text.';
                } elseif (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value)) {
                    $csvError = 'Control characters are not allowed in CSV values.';
                }
                $values[$key] = trim($value);
            }
            $rows[] = ['line' => $line, 'values' => $values, 'csv_error' => $csvError];
        }

        if (!$rows) {
            return ['ok' => false, 'error' => 'The CSV contains a header but no resident rows.', 'rows' => []];
        }
        return ['ok' => true, 'error' => '', 'rows' => $rows];
    } finally {
        fclose($fh);
    }
}

function resident_import_normalize(string $value): string
{
    return function_exists('mb_strtolower') ? mb_strtolower(trim($value), 'UTF-8') : strtolower(trim($value));
}

/** Return the canonical value from an allow-list, allowing case-insensitive labels. */
function resident_import_enum(string $value, array $allowed): ?string
{
    foreach ($allowed as $option) {
        if (resident_import_normalize($value) === resident_import_normalize($option)) {
            return $option;
        }
    }
    return null;
}

/** @return array<string,array{id:int,label:string}> normalized lookup label => value */
function resident_import_lookup_map(string $listKey): array
{
    $out = [];
    foreach (lookup_list($listKey) as $id => $label) {
        $out[resident_import_normalize((string) $label)] = ['id' => (int) $id, 'label' => (string) $label];
    }
    return $out;
}

/**
 * Validate every CSV row against current data. Existing residents and repeated identities
 * in the same file are skipped, never updated. The function is also called inside the
 * eventual transaction so references/duplicates are checked again immediately before insert.
 */
function resident_import_analyze_rows(array $rows): array
{
    $categories = resident_import_lookup_map('resident_category');
    $employment = resident_import_lookup_map('employment_status');
    $householdRows = db_all(
        'SELECT h.household_id, h.household_code, h.purok_id, h.street_address, p.label AS purok
         FROM households h LEFT JOIN lookups p ON p.lookup_id = h.purok_id'
    );
    $households = [];
    foreach ($householdRows as $h) {
        $households[resident_import_normalize((string) $h['household_code'])] = $h;
    }
    $householdHeads = [];
    foreach (db_all('SELECT DISTINCT household_id FROM residents WHERE household_id IS NOT NULL AND is_household_head = 1') as $h) {
        $householdHeads[(int) $h['household_id']] = true;
    }

    $seenIdentity = [];
    $out = [];
    foreach ($rows as $row) {
        $v = is_array($row['values'] ?? null) ? $row['values'] : [];
        $errors = [];
        $skipReason = '';
        $resolved = ['household_id' => null, 'category_id' => null, 'employment_status_id' => null, 'is_household_head' => 0];
        if (($row['csv_error'] ?? '') !== '') { $errors[] = (string) $row['csv_error']; }

        $last = trim((string) ($v['last_name'] ?? ''));
        $first = trim((string) ($v['first_name'] ?? ''));
        $middle = trim((string) ($v['middle_name'] ?? ''));
        $suffix = trim((string) ($v['suffix'] ?? ''));
        $birthdate = trim((string) ($v['birthdate'] ?? ''));

        if (!v_person_name($last) || !v_len($last, 2, 50)) { $errors[] = 'Last name is required and must be a valid name of 2–50 characters.'; }
        if (!v_person_name($first) || !v_len($first, 2, 60)) { $errors[] = 'First name is required and must be a valid name of 2–60 characters.'; }
        if ($middle !== '' && (!v_person_name($middle) || !v_len($middle, 2, 50))) { $errors[] = 'Middle name must be a valid name of at most 50 characters.'; }
        if ($suffix !== '' && !preg_match('/^[A-Za-z0-9.]{1,10}$/', $suffix)) { $errors[] = 'Suffix must use letters, numbers or periods (maximum 10 characters).'; }
        if (!v_date($birthdate)) {
            $errors[] = 'Birthdate must be a real date in YYYY-MM-DD format.';
        } elseif ($birthdate > date('Y-m-d')) {
            $errors[] = 'Birthdate cannot be in the future.';
        } elseif (age_on($birthdate) > 120) {
            $errors[] = 'Birthdate is more than 120 years ago; check it.';
        }

        $sex = resident_import_enum((string) ($v['sex'] ?? ''), ['Male', 'Female']);
        if ($sex === null) { $errors[] = 'Sex must be Male or Female.'; } else { $v['sex'] = $sex; }
        $civil = resident_import_enum((string) ($v['civil_status'] ?? ''), ['Single', 'Married', 'Widowed', 'Separated', 'Divorced']);
        if ($civil === null) { $errors[] = 'Civil status must be Single, Married, Widowed, Separated or Divorced.'; } else { $v['civil_status'] = $civil; }

        $contact = trim((string) ($v['contact_number'] ?? ''));
        if ($contact !== '' && (str_len($contact) > 20 || !v_phone($contact))) { $errors[] = 'Contact number is not valid.'; }

        $voter = resident_import_enum((string) ($v['voter_status'] ?? ''), ['Unverified', 'Registered', 'Not registered']);
        if ($voter === null) {
            if (trim((string) ($v['voter_status'] ?? '')) === '') { $voter = 'Unverified'; }
            else { $errors[] = 'Voter status must be Unverified, Registered or Not registered.'; }
        }
        $residency = resident_import_enum((string) ($v['residency_status'] ?? ''), ['Active', 'Relocated']);
        if ($residency === null) {
            if (trim((string) ($v['residency_status'] ?? '')) === '') { $residency = 'Active'; }
            else { $errors[] = 'Residency status must be Active or Relocated.'; }
        }
        $headValue = resident_import_normalize((string) ($v['is_household_head'] ?? ''));
        if (in_array($headValue, ['1', 'yes', 'true'], true)) {
            $resolved['is_household_head'] = 1;
        } elseif (!in_array($headValue, ['', '0', 'no', 'false'], true)) {
            $errors[] = 'Household head must be 1/yes or 0/no.';
        }

        $householdCode = trim((string) ($v['household_code'] ?? ''));
        $purokText = trim((string) ($v['purok'] ?? ''));
        if ($householdCode !== '') {
            $h = $households[resident_import_normalize($householdCode)] ?? null;
            if ($h === null) {
                $errors[] = 'Household code "' . $householdCode . '" does not match an existing household.';
            } else {
                $resolved['household_id'] = (int) $h['household_id'];
                if ($purokText !== '' && resident_import_normalize($purokText) !== resident_import_normalize((string) ($h['purok'] ?? ''))) {
                    $errors[] = 'Purok does not match the selected household. Use its existing Purok label or leave the Purok cell blank.';
                }
            }
        } elseif ($purokText !== '') {
            $errors[] = 'Purok belongs to a household. Provide an existing household_code or leave Purok blank.';
        }
        if ($resolved['is_household_head'] === 1 && $resolved['household_id'] === null) {
            $errors[] = 'A household head must reference an existing household.';
        }
        if ($resolved['is_household_head'] === 1 && $resolved['household_id'] !== null && isset($householdHeads[$resolved['household_id']])) {
            $errors[] = 'This household already has a recorded household head. The importer will not change that record.';
        }

        $categoryText = trim((string) ($v['category'] ?? ''));
        if ($categoryText !== '') {
            $match = $categories[resident_import_normalize($categoryText)] ?? null;
            if ($match === null) { $errors[] = 'Category "' . $categoryText . '" is not an active configured resident category.'; }
            else { $resolved['category_id'] = $match['id']; }
        }
        $employmentText = trim((string) ($v['employment_status'] ?? ''));
        if ($employmentText !== '') {
            $match = $employment[resident_import_normalize($employmentText)] ?? null;
            if ($match === null) { $errors[] = 'Employment status "' . $employmentText . '" is not an active configured employment status.'; }
            else { $resolved['employment_status_id'] = $match['id']; }
        }

        $identityKey = resident_import_normalize($first) . '|' . resident_import_normalize($last) . '|' . $birthdate;
        if (!$errors) {
            $existing = db_one(
                'SELECT resident_id FROM residents WHERE LOWER(first_name) = LOWER(?) AND LOWER(last_name) = LOWER(?) AND birthdate = ? LIMIT 1',
                [$first, $last, $birthdate]
            );
            if ($existing !== null) {
                $skipReason = 'Possible duplicate of existing resident record #' . (int) $existing['resident_id'] . '. Existing records are never overwritten.';
            } elseif (isset($seenIdentity[$identityKey])) {
                $skipReason = 'Duplicate of row ' . (int) $seenIdentity[$identityKey] . ' in this CSV. Only the first valid occurrence can be imported.';
            } else {
                $seenIdentity[$identityKey] = (int) ($row['line'] ?? 0);
                if ($resolved['is_household_head'] === 1 && $resolved['household_id'] !== null) {
                    $householdHeads[$resolved['household_id']] = true;
                }
            }
        }

        $status = $errors ? 'rejected' : ($skipReason !== '' ? 'skipped' : 'ready');
        $out[] = [
            'line' => (int) ($row['line'] ?? 0), 'values' => $v, 'resolved' => $resolved,
            'status' => $status, 'errors' => $errors, 'skip_reason' => $skipReason,
        ];
    }
    return $out;
}

function resident_import_counts(array $rows): array
{
    $counts = ['ready' => 0, 'skipped' => 0, 'rejected' => 0];
    foreach ($rows as $row) {
        $key = (string) ($row['status'] ?? 'rejected');
        if (isset($counts[$key])) { $counts[$key]++; }
    }
    return $counts;
}

/** Insert all currently valid rows as a single transaction; a DB failure rolls back the full batch. */
function resident_import_commit_rows(array $rows, ?int $userId): array
{
    return db_transaction(function () use ($rows, $userId): array {
        $checked = resident_import_analyze_rows($rows);
        $counts = resident_import_counts($checked);
        $imported = 0;
        $errors = [];

        foreach ($checked as $row) {
            if ($row['status'] === 'skipped') {
                $errors[] = [
                    'line' => $row['line'], 'status' => 'Skipped', 'first_name' => (string) ($row['values']['first_name'] ?? ''),
                    'last_name' => (string) ($row['values']['last_name'] ?? ''), 'birthdate' => (string) ($row['values']['birthdate'] ?? ''),
                    'details' => $row['skip_reason'],
                ];
                continue;
            }
            if ($row['status'] === 'rejected') {
                $errors[] = [
                    'line' => $row['line'], 'status' => 'Rejected', 'first_name' => (string) ($row['values']['first_name'] ?? ''),
                    'last_name' => (string) ($row['values']['last_name'] ?? ''), 'birthdate' => (string) ($row['values']['birthdate'] ?? ''),
                    'details' => implode(' ', $row['errors']),
                ];
                continue;
            }

            $v = $row['values']; $resolved = $row['resolved'];
            db_exec(
                'INSERT INTO residents
                 (household_id, last_name, first_name, middle_name, suffix, birthdate, sex, civil_status, contact_number,
                  category_id, employment_status_id, voter_status, residency_status, is_household_head, created_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    $resolved['household_id'], $v['last_name'], $v['first_name'], $v['middle_name'] !== '' ? $v['middle_name'] : null,
                    $v['suffix'] !== '' ? $v['suffix'] : null, $v['birthdate'], $v['sex'], $v['civil_status'],
                    $v['contact_number'] !== '' ? $v['contact_number'] : null, $resolved['category_id'], $resolved['employment_status_id'],
                    $v['voter_status'] !== '' ? resident_import_enum($v['voter_status'], ['Unverified', 'Registered', 'Not registered']) : 'Unverified',
                    $v['residency_status'] !== '' ? resident_import_enum($v['residency_status'], ['Active', 'Relocated']) : 'Active',
                    (int) $resolved['is_household_head'], $userId,
                ]
            );
            $residentId = (int) db()->lastInsertId();
            audit('resident_imported', 'Residents', 'Success', 'CSV row ' . $row['line'] . ': ' . $v['last_name'] . ', ' . $v['first_name'], 'resident', $residentId);
            $imported++;
        }

        audit('resident_csv_imported', 'Residents', 'Success',
            'Imported ' . $imported . '; skipped ' . $counts['skipped'] . '; rejected ' . $counts['rejected']);
        return [
            'imported' => $imported, 'skipped' => $counts['skipped'], 'rejected' => $counts['rejected'],
            'errors' => $errors, 'rows' => $checked, 'rolled_back' => false,
        ];
    });
}

function resident_import_error_rows(array $rows): array
{
    $out = [];
    foreach ($rows as $row) {
        if (($row['status'] ?? '') === 'ready') { continue; }
        $out[] = [
            'line' => (int) ($row['line'] ?? 0),
            'status' => ($row['status'] ?? '') === 'skipped' ? 'Skipped' : 'Rejected',
            'first_name' => (string) ($row['values']['first_name'] ?? ''),
            'last_name' => (string) ($row['values']['last_name'] ?? ''),
            'birthdate' => (string) ($row['values']['birthdate'] ?? ''),
            'details' => ($row['status'] ?? '') === 'skipped'
                ? (string) ($row['skip_reason'] ?? 'Duplicate row.')
                : implode(' ', is_array($row['errors'] ?? null) ? $row['errors'] : ['Invalid row.']),
        ];
    }
    return $out;
}
