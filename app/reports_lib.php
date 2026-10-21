<?php
/**
 * Reporting queries for BIRMS.
 *
 * All figures come from the configured working database. This file contains no sample totals.
 * Personal identifiers are not selected for statistical reports.
 */
declare(strict_types=1);

/** Return a validated reporting range. End dates are exclusive. */
function report_period_range(string $period, ?int $year = null, ?int $month = null, ?int $quarter = null): array
{
    $now = new DateTimeImmutable('today');
    $year = ($year !== null && $year >= 2000 && $year <= 2100) ? $year : (int) $now->format('Y');
    $period = in_array($period, ['month', 'quarter', 'year'], true) ? $period : 'month';

    if ($period === 'month') {
        $month = ($month !== null && $month >= 1 && $month <= 12) ? $month : (int) $now->format('n');
        $start = new DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month));
        $end = $start->modify('+1 month');
        $label = $start->format('F Y');
        return ['period' => $period, 'year' => $year, 'month' => $month, 'quarter' => null,
            'start' => $start->format('Y-m-d 00:00:00'), 'end' => $end->format('Y-m-d 00:00:00'), 'label' => $label];
    }
    if ($period === 'quarter') {
        $quarter = ($quarter !== null && $quarter >= 1 && $quarter <= 4) ? $quarter : (int) ceil((int) $now->format('n') / 3);
        $monthStart = (($quarter - 1) * 3) + 1;
        $start = new DateTimeImmutable(sprintf('%04d-%02d-01', $year, $monthStart));
        $end = $start->modify('+3 months');
        return ['period' => $period, 'year' => $year, 'month' => null, 'quarter' => $quarter,
            'start' => $start->format('Y-m-d 00:00:00'), 'end' => $end->format('Y-m-d 00:00:00'),
            'label' => 'Q' . $quarter . ' ' . $year];
    }
    $start = new DateTimeImmutable(sprintf('%04d-01-01', $year));
    $end = $start->modify('+1 year');
    return ['period' => 'year', 'year' => $year, 'month' => null, 'quarter' => null,
        'start' => $start->format('Y-m-d 00:00:00'), 'end' => $end->format('Y-m-d 00:00:00'), 'label' => (string) $year];
}

/** Count values without losing categories whose label happens to be the same as a fallback. */
function report_count_value(array &$counts, string $label): void
{
    $label = trim($label) !== '' ? trim($label) : 'Not specified';
    $counts[$label] = ($counts[$label] ?? 0) + 1;
}

/** Active resident demographics; archived and relocated residents are excluded from current population. */
function report_demographics(): array
{
    $rows = db_all(
        "SELECT r.birthdate, r.sex, r.civil_status, r.voter_status,
                COALESCE(emp.label, 'Not specified') AS employment_status,
                COALESCE(pk.label, 'Purok not assigned') AS purok
         FROM residents r
         LEFT JOIN households h ON h.household_id = r.household_id
         LEFT JOIN lookups pk ON pk.lookup_id = h.purok_id AND pk.list_key = 'purok'
         LEFT JOIN lookups emp ON emp.lookup_id = r.employment_status_id AND emp.list_key = 'employment_status'
         WHERE r.archived_at IS NULL AND r.residency_status = 'Active'
         ORDER BY r.resident_id"
    );
    $data = [
        'total' => count($rows), 'age_brackets' => [], 'sex' => [], 'civil_status' => [],
        'employment_status' => [], 'voter_status' => [], 'purok' => [],
    ];
    $brackets = age_brackets();
    foreach ($rows as $r) {
        report_count_value($data['sex'], (string) $r['sex']);
        report_count_value($data['civil_status'], (string) $r['civil_status']);
        report_count_value($data['employment_status'], (string) $r['employment_status']);
        report_count_value($data['voter_status'], (string) $r['voter_status']);
        report_count_value($data['purok'], (string) $r['purok']);

        $age = age_on((string) $r['birthdate']);
        $ageLabel = 'Outside configured age brackets';
        foreach ($brackets as [$lo, $hi, $label]) {
            if ($age >= $lo && $age <= $hi) { $ageLabel = $label; break; }
        }
        report_count_value($data['age_brackets'], $ageLabel);
    }
    foreach (['sex', 'civil_status', 'employment_status', 'voter_status', 'purok', 'age_brackets'] as $key) {
        ksort($data[$key], SORT_NATURAL | SORT_FLAG_CASE);
    }
    return $data;
}

/** Issued documents within [start, end); fee amounts are the amounts saved on issuance records, not a payment gateway total. */
function report_issuances(array $range): array
{
    $params = [$range['start'], $range['end']];
    $summary = db_one(
        'SELECT COUNT(*) AS issued_count, COALESCE(SUM(fee_amount), 0) AS recorded_fees
         FROM issuances WHERE issued_at >= ? AND issued_at < ?', $params
    ) ?? ['issued_count' => 0, 'recorded_fees' => 0];
    $byType = db_all(
        'SELECT type_name, COUNT(*) AS issued_count, COALESCE(SUM(fee_amount), 0) AS recorded_fees
         FROM issuances WHERE issued_at >= ? AND issued_at < ?
         GROUP BY type_name ORDER BY type_name', $params
    );
    return ['summary' => $summary, 'by_type' => $byType];
}


/** Requests created within [start, end); status counts reflect each request's current saved status at report time. */
function report_requests(array $range): array
{
    $params = [$range['start'], $range['end']];
    $summary = db_one(
        "SELECT COUNT(*) AS total,
                COALESCE(SUM(status = 'Pending'), 0) AS pending_count,
                COALESCE(SUM(status = 'Review'), 0) AS review_count,
                COALESCE(SUM(status = 'Ready'), 0) AS ready_count,
                COALESCE(SUM(status = 'Claimed'), 0) AS claimed_count,
                COALESCE(SUM(status = 'Rejected'), 0) AS rejected_count,
                COALESCE(SUM(status = 'Cancelled'), 0) AS cancelled_count
         FROM document_requests WHERE requested_at >= ? AND requested_at < ?", $params
    ) ?? ['total' => 0, 'pending_count' => 0, 'review_count' => 0, 'ready_count' => 0,
          'claimed_count' => 0, 'rejected_count' => 0, 'cancelled_count' => 0];

    $byType = db_all(
        "SELECT dt.name AS type_name, COUNT(*) AS request_count,
                COALESCE(SUM(dr.status = 'Pending'), 0) AS pending_count,
                COALESCE(SUM(dr.status = 'Review'), 0) AS review_count,
                COALESCE(SUM(dr.status = 'Ready'), 0) AS ready_count,
                COALESCE(SUM(dr.status = 'Claimed'), 0) AS claimed_count
         FROM document_requests dr JOIN document_types dt ON dt.type_id = dr.type_id
         WHERE dr.requested_at >= ? AND dr.requested_at < ?
         GROUP BY dt.type_id, dt.name ORDER BY dt.name", $params
    );

    $byAssignee = db_all(
        "SELECT COALESCE(u.full_name, 'Unassigned') AS assigned_to, COUNT(*) AS request_count
         FROM document_requests dr LEFT JOIN users u ON u.user_id = dr.assigned_to
         WHERE dr.requested_at >= ? AND dr.requested_at < ?
         GROUP BY COALESCE(u.full_name, 'Unassigned') ORDER BY request_count DESC, assigned_to", $params
    );

    $byStatus = db_all(
        "SELECT status, COUNT(*) AS request_count
         FROM document_requests
         WHERE requested_at >= ? AND requested_at < ?
         GROUP BY status
         ORDER BY FIELD(status, 'Pending', 'Review', 'Ready', 'Claimed', 'Rejected', 'Cancelled')", $params
    );

    return ['summary' => $summary, 'by_type' => $byType, 'by_assignee' => $byAssignee, 'by_status' => $byStatus];
}

/** Non-archived incidents filtered by incident date; current case statuses and type/Purok counts are all database-derived. */
function report_blotters(array $range): array
{
    $params = [$range['start'], $range['end']];
    $summary = db_one(
        "SELECT COUNT(*) AS total,
                COALESCE(SUM(status = 'Pending'), 0) AS pending_count,
                COALESCE(SUM(status = 'Active'), 0) AS active_count,
                COALESCE(SUM(status = 'Scheduled'), 0) AS scheduled_count,
                COALESCE(SUM(status = 'Resolved'), 0) AS resolved_count
         FROM blotters WHERE archived_at IS NULL AND incident_date >= ? AND incident_date < ?", $params
    ) ?? ['total' => 0, 'pending_count' => 0, 'active_count' => 0, 'scheduled_count' => 0, 'resolved_count' => 0];
    $total = (int) $summary['total'];
    $resolved = (int) $summary['resolved_count'];
    $summary['resolution_rate'] = $total > 0 ? round(($resolved / $total) * 100, 1) : 0.0;

    $byType = db_all(
        "SELECT COALESCE(it.label, 'Unclassified') AS incident_type, COUNT(*) AS case_count,
                COALESCE(SUM(b.status = 'Resolved'), 0) AS resolved_count
         FROM blotters b LEFT JOIN lookups it ON it.lookup_id = b.incident_type_id AND it.list_key = 'incident_type'
         WHERE b.archived_at IS NULL AND b.incident_date >= ? AND b.incident_date < ?
         GROUP BY COALESCE(it.label, 'Unclassified') ORDER BY case_count DESC, incident_type", $params
    );
    $byPurok = db_all(
        "SELECT COALESCE(pk.label, 'Purok not assigned') AS purok, COUNT(*) AS case_count
         FROM blotters b LEFT JOIN lookups pk ON pk.lookup_id = b.purok_id AND pk.list_key = 'purok'
         WHERE b.archived_at IS NULL AND b.incident_date >= ? AND b.incident_date < ?
         GROUP BY COALESCE(pk.label, 'Purok not assigned') ORDER BY case_count DESC, purok", $params
    );
    $byStatus = db_all(
        "SELECT status, COUNT(*) AS case_count
         FROM blotters WHERE archived_at IS NULL AND incident_date >= ? AND incident_date < ?
         GROUP BY status ORDER BY FIELD(status, 'Pending', 'Active', 'Scheduled', 'Resolved')", $params
    );
    return ['summary' => $summary, 'by_type' => $byType, 'by_purok' => $byPurok, 'by_status' => $byStatus];
}
