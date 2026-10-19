<?php
/** Certificate rules: wording variables, what blocks issuing, O.R. numbers. */
declare(strict_types=1);

function ordinal(int $n): string
{
    $s = ($n % 100 >= 11 && $n % 100 <= 13) ? 'th' : ['th', 'st', 'nd', 'rd', 'th', 'th', 'th', 'th', 'th', 'th'][$n % 10];
    return $n . $s;
}
/** Values for the {{placeholders}}. $r needs resident + household + request columns. */
function certificate_vars(array $r, ?DateTimeImmutable $now = null): array
{
    $now ??= new DateTimeImmutable('now');
    return [
        'full_name' => strtoupper(resident_name_natural($r)), 'age' => (string) age_on((string) $r['birthdate'], $now->format('Y-m-d')), 'civil_status' => (string) $r['civil_status'],
        'address' => (string) ($r['street_address'] ?? ''), 'purok' => (string) ($r['purok'] ?? ''), 'purpose' => (string) $r['purpose'],
        'date_issued' => $now->format('j F Y'), 'day_ordinal' => ordinal((int) $now->format('j')), 'month_year' => $now->format('F Y'),
        'barangay' => (string) setting('barangay_name', ''), 'municipality' => (string) setting('municipality', ''), 'province' => (string) setting('province', ''),
        'reference_no' => (string) ($r['reference_no'] ?? ''), 'captain_name' => (string) setting('captain_name', ''), 'document_name' => (string) $r['type_name'],
    ];
}
/** Reasons a certificate cannot be issued yet. Empty array = ready. Nothing here is guessed. */
function certificate_blockers(array $r, array $vars): array
{
    $b = [];
    if ($r['fee_amount'] === null) { $b[] = 'The fee for ' . $r['type_name'] . ' is not set yet. The Secretary must enter it in Settings, Documents and fees (0 means free).'; }
    $body = (string) ($r['template_body'] ?? '');
    if (trim($body) === '') { $b[] = 'The official wording for ' . $r['type_name'] . ' is not set yet. The Secretary must enter it in Settings, Documents and fees.'; }
    foreach (['captain_name' => 'the Barangay Captain\'s name (Settings, Barangay details)', 'address' => 'this resident\'s household address', 'purok' => 'this resident\'s Purok'] as $tok => $what) {
        if (str_contains($body, '{{' . $tok . '}}') && $vars[$tok] === '') { $b[] = 'The wording uses {{' . $tok . '}} but ' . $what . ' is empty.'; }
    }
    if (($r['resident_archived'] ?? null) !== null) { $b[] = 'This resident is archived. Restore the resident first.'; }
    return $b;
}
function v_or_number(string $s): bool { return preg_match('/^[A-Za-z0-9-]{1,30}$/', $s) === 1; }

/** Request + resident + household + document type, for issuing. */
function issuable_request(int $id): ?array
{
    return db_one('SELECT dr.request_id, dr.reference_no, dr.status, dr.purpose, dr.resident_id, dr.type_id, r.last_name, r.first_name, r.middle_name, r.suffix, r.birthdate, r.civil_status,
            r.archived_at AS resident_archived, h.street_address, pk.label AS purok, dt.name AS type_name, dt.fee_amount, dt.template_body
        FROM document_requests dr JOIN residents r ON r.resident_id = dr.resident_id JOIN document_types dt ON dt.type_id = dr.type_id
        LEFT JOIN households h ON h.household_id = r.household_id LEFT JOIN lookups pk ON pk.lookup_id = h.purok_id WHERE dr.request_id = ?', [$id]);
}
