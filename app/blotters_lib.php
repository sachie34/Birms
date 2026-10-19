<?php
/** Blotter rules: statuses, who may move a case, hearing rules, name matching for the look-up, summons wording checks. */
declare(strict_types=1);

const BLOTTER_STATUSES = ['Pending', 'Active', 'Scheduled', 'Resolved'];
const HEARING_OUTCOMES = ['Held', 'Postponed', 'Cancelled'];
/** current status => [new status => permission]. 'Scheduled' is never set by hand: scheduling a hearing sets it and recording the last open hearing clears it. */
const BLOTTER_MOVES = [
    'Pending'   => ['Active' => 'blotters.edit', 'Resolved' => 'blotters.edit'],
    'Active'    => ['Resolved' => 'blotters.edit'],
    'Scheduled' => ['Resolved' => 'blotters.edit'],
];
/** Placeholders allowed inside the official summons wording. The wording itself is NOT shipped. */
const SUMMONS_TOKENS = ['case_no', 'complainant_names', 'respondent_names', 'hearing_date', 'hearing_time', 'venue', 'barangay', 'municipality', 'province', 'captain_name', 'secretary_name', 'date_issued'];

function blotter_allowed_moves(string $status): array
{
    $out = [];
    foreach (BLOTTER_MOVES[$status] ?? [] as $to => $perm) { if (can($perm)) { $out[] = $to; } }
    return $out;
}
function new_case_no(): string
{
    $y = (int) date('Y');
    return sprintf('BLT-%d-%04d', $y, next_counter('blotter', $y));
}
/** A case can be edited or get new parties and hearings only while it is neither resolved nor archived. */
function case_is_open(array $c): bool { return $c['status'] !== 'Resolved' && $c['archived_at'] === null; }

function case_parties(int $caseId): array
{
    return db_all('SELECT party_id, party_role, full_name, age, address, contact_number FROM blotter_parties WHERE blotter_id = ? ORDER BY party_role = \'Respondent\', party_id', [$caseId]);
}
function party_names(array $parties, string $role): string
{
    return implode(', ', array_map(fn($p) => (string) $p['full_name'], array_filter($parties, fn($p) => $p['party_role'] === $role)));
}
/** Validate one party. Returns the cleaned values, or null after adding messages to $errors. */
function party_clean(string $name, string $age, string $address, string $contact, string $label, array &$errors): ?array
{
    $before = count($errors);
    if (!v_person_name($name)) { $errors[] = $label . ": enter a name (letters, spaces, . ' - only)."; }
    $ageN = null;
    if ($age !== '') { if (preg_match('/^\d{1,3}$/', $age) === 1 && (int) $age <= 120) { $ageN = (int) $age; } else { $errors[] = $label . ': age must be 0 to 120.'; } }
    if ($address !== '' && !v_len($address, 3, 255)) { $errors[] = $label . ': address must be 3-255 characters.'; }
    if ($contact !== '' && !v_phone($contact)) { $errors[] = $label . ': contact number is not valid (example 09171234567).'; }
    if (count($errors) > $before) { return null; }
    return ['name' => $name, 'age' => $ageN, 'address' => $address !== '' ? $address : null, 'contact' => $contact !== '' ? preg_replace('/[\s-]/', '', $contact) : null];
}

/** Split a typed name into search words (2+ letters each, at most 5). */
function name_tokens(string $q): array
{
    $t = preg_split('/[\s,]+/u', $q, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    return array_slice(array_values(array_filter($t, fn($w) => str_len($w) >= 2)), 0, 5);
}
/** Parties whose name contains EVERY word. Wildcard characters typed by the user are treated as plain text. */
function blotter_people_search(array $tokens, int $limit = 100): array
{
    if (!$tokens) { return []; }
    $w = []; $p = [];
    foreach ($tokens as $t) { $w[] = 'p.full_name LIKE ?'; $p[] = '%' . addcslashes($t, '%_\\') . '%'; }
    return db_all('SELECT p.party_id, p.party_role, p.full_name, p.age, p.address, b.blotter_id, b.case_no, b.status, b.incident_date, b.archived_at
                   FROM blotter_parties p JOIN blotters b ON b.blotter_id = p.blotter_id WHERE ' . implode(' AND ', $w) . ' ORDER BY b.incident_date DESC, p.party_id LIMIT ' . (int) $limit, $p);
}
/** Possible blotter records for a resident (same first and last name). A hint for staff, never proof. */
function blotter_matches_for_resident(array $r): array
{
    return blotter_people_search(name_tokens(trim((string) $r['first_name'] . ' ' . (string) $r['last_name'])), 20);
}

function summons_vars(array $case, array $h, array $parties): array
{
    $when = new DateTimeImmutable((string) $h['scheduled_at']);
    return [
        'case_no' => (string) $case['case_no'], 'complainant_names' => party_names($parties, 'Complainant'), 'respondent_names' => party_names($parties, 'Respondent'),
        'hearing_date' => $when->format('j F Y'), 'hearing_time' => $when->format('g:i A'), 'venue' => (string) ($h['venue'] ?? ''),
        'barangay' => (string) setting('barangay_name', ''), 'municipality' => (string) setting('municipality', ''), 'province' => (string) setting('province', ''),
        'captain_name' => (string) setting('captain_name', ''), 'secretary_name' => (string) setting('secretary_name', ''), 'date_issued' => date('j F Y'),
    ];
}
/** Reasons a summons cannot be printed yet. Empty array = ready. Nothing is guessed. */
function summons_blockers(string $body, array $vars, array $parties, array $hearing): array
{
    $b = [];
    if (trim($body) === '') { $b[] = 'The official summons wording is not set yet. The Secretary must enter it in Settings, Hearing summons.'; }
    if (party_names($parties, 'Respondent') === '') { $b[] = 'This case has no respondent yet. Add the respondent first.'; }
    if ($hearing['status'] !== 'Scheduled') { $b[] = 'A summons can only be printed for a hearing that is still Scheduled.'; }
    $what = ['venue' => 'the hearing venue', 'captain_name' => 'the Barangay Captain\'s name (Settings, Barangay details)', 'secretary_name' => 'the Barangay Secretary\'s name (Settings, Barangay details)',
             'barangay' => 'the barangay name', 'municipality' => 'the municipality', 'province' => 'the province'];
    foreach ($what as $tok => $desc) {
        if (str_contains($body, '{{' . $tok . '}}') && $vars[$tok] === '') { $b[] = 'The wording uses {{' . $tok . '}} but ' . $desc . ' is empty.'; }
    }
    return $b;
}
