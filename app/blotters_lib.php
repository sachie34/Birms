<?php
/** Blotter rules: statuses, who may move a case, hearing rules, name matching for the look-up, summons wording checks. */
declare(strict_types=1);

const MSIT_BLOTTER_STATUSES = ['Ongoing', 'Unresolved', 'Resolved', 'Dismissed', 'Archived'];
const HEARING_OUTCOMES = ['Held', 'Postponed', 'Cancelled'];
/** Placeholders allowed inside the official summons wording. The wording itself is NOT shipped. */
const SUMMONS_TOKENS = ['case_no', 'complainant_names', 'respondent_names', 'hearing_date', 'hearing_time', 'venue', 'barangay', 'municipality', 'province', 'captain_name', 'secretary_name', 'date_issued'];

function new_case_no(): string
{
    $y = (int) date('Y');
    return sprintf('BLT-%d-%04d', $y, next_counter('blotter', $y));
}
/** SRS status transitions. Hearing scheduling is tracked separately and never changes the five-state case workflow. */
const MSIT_BLOTTER_MOVES = [
    'Ongoing' => ['Unresolved' => 'blotters.edit', 'Resolved' => 'blotters.edit', 'Dismissed' => 'blotters.edit'],
    'Unresolved' => ['Ongoing' => 'blotters.edit', 'Resolved' => 'blotters.edit', 'Dismissed' => 'blotters.edit'],
    'Resolved' => ['Archived' => 'blotters.archive'],
    'Dismissed' => ['Archived' => 'blotters.archive'],
];

function blotter_srs_allowed_moves(string $status): array
{
    $out = [];
    foreach (MSIT_BLOTTER_MOVES[$status] ?? [] as $to => $permission) {
        if (can($permission)) { $out[] = $to; }
    }
    return $out;
}

/** Change the authoritative SRS status and write shared history and audit events atomically. */
function blotter_srs_change_status(int $blotterId, string $to, string $note = ''): void
{
    if (!in_array($to, MSIT_BLOTTER_STATUSES, true)) {
        throw new InvalidArgumentException('Choose a valid case status.');
    }

    db_transaction(function () use ($blotterId, $to, $note): void {
        $case = db_one(
            'SELECT blotter_id, case_no, msit_status, archived_at FROM blotters WHERE blotter_id = ? FOR UPDATE',
            [$blotterId]
        );
        if ($case === null) { throw new RuntimeException('That case was not found.'); }

        $from = (string) ($case['msit_status'] ?? 'Ongoing');
        $permission = MSIT_BLOTTER_MOVES[$from][$to] ?? null;
        if ($permission === null) {
            throw new InvalidArgumentException('A ' . $from . ' case cannot move to ' . $to . '.');
        }
        if (!can($permission)) {
            throw new DomainException('Your role cannot make this case-status change.');
        }
        if ($from === 'Archived' || $case['archived_at'] !== null) {
            throw new InvalidArgumentException('Archived cases cannot be changed or restored.');
        }
        if (in_array($to, ['Resolved', 'Dismissed', 'Archived'], true)) {
            $openHearings = (int) db_val(
                "SELECT COUNT(*) FROM hearings WHERE blotter_id = ? AND status = 'Scheduled'",
                [$blotterId]
            );
            if ($openHearings > 0) {
                throw new InvalidArgumentException('Record the outcome of every scheduled hearing before resolving, dismissing or archiving this case.');
            }
        }
        if (in_array($to, ['Resolved', 'Dismissed'], true) && !v_len($note, 3, 500)) {
            throw new InvalidArgumentException('Enter the resolution or dismissal reason (3-500 characters).');
        }

        $archiveAt = $to === 'Archived' ? 'NOW()' : 'NULL';
        $archivedFrom = $to === 'Archived' ? $from : null;
        $resolutionNote = in_array($to, ['Resolved', 'Dismissed'], true) ? $note : null;
        $resolvedAt = $to === 'Resolved' ? 'NOW()' : ($to === 'Dismissed' ? 'NULL' : 'resolved_at');
        $changed = db_exec(
            'UPDATE blotters
             SET msit_status = ?,
                 archived_at = ' . $archiveAt . ',
                 archived_from_status = ?,
                 resolution_note = ?,
                 resolved_at = ' . $resolvedAt . '
             WHERE blotter_id = ? AND msit_status = ? AND archived_at IS NULL',
            [$to, $archivedFrom, $resolutionNote, $blotterId, $from]
        );
        if ($changed !== 1) {
            throw new RuntimeException('The case changed before this action completed. Refresh and try again.');
        }

        $actorId = audit_actor()['user_id'] ?? null;
        db_exec(
            "INSERT INTO request_status_history
             (service, reference_no, previous_status, new_status, changed_by, note, blotter_id)
             VALUES ('Blotter', ?, ?, ?, ?, ?, ?)",
            [$case['case_no'], $from, $to, $actorId, $note !== '' ? $note : null, $blotterId]
        );
        db_exec(
            'UPDATE resident_blotter_requests SET msit_status = ?, status_note = ? WHERE blotter_id = ?',
            [$to, $note !== '' ? $note : null, $blotterId]
        );
        audit('blotter_srs_status_changed', 'Blotter', 'Success',
            $case['case_no'] . ': ' . $from . ' -> ' . $to . ($note !== '' ? ' (' . $note . ')' : ''),
            'blotter', $blotterId);
        if ($to === 'Archived') {
            audit('blotter_archived', 'Blotter', 'Success',
                $case['case_no'] . ' archived from ' . $from, 'blotter', $blotterId);
        }
    });

    // Notifications are sent after commit; a delivery failure cannot roll back the saved case status.
    $requesters = db_all(
        'SELECT resident_id, reference_no FROM resident_blotter_requests WHERE blotter_id = ?',
        [$blotterId]
    );
    foreach ($requesters as $requester) {
        try {
            resident_notify_status_change(
                (int) $requester['resident_id'],
                'Blotter',
                (string) $requester['reference_no'],
                $to,
                'blotter_case_status_changed',
                $note
            );
        } catch (Throwable $notificationError) {
            error_log('BIRMS blotter status notification failed for ' . (string) $requester['reference_no'] . ': ' . get_class($notificationError) . ': ' . $notificationError->getMessage());
            audit_best_effort('resident_notification_failed', 'Notifications', 'Failed',
                'Blotter case ' . (string) $requester['reference_no'] . ' changed status but the resident notification could not be queued.');
        }
    }
}

/** A case is editable and may receive hearings only while Ongoing or Unresolved. */
function case_is_open(array $c): bool
{
    return in_array((string) ($c['msit_status'] ?? 'Ongoing'), ['Ongoing', 'Unresolved'], true)
        && ($c['archived_at'] ?? null) === null;
}

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
    return db_all('SELECT p.party_id, p.party_role, p.full_name, p.age, p.address, b.blotter_id, b.case_no, b.msit_status AS status, b.incident_date, b.archived_at
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
