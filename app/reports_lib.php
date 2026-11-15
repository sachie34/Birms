<?php
/**
 * Reporting queries for BIRMS.
 *
 * All figures come from the configured working database. This file contains no sample totals.
 * Personal identifiers are not selected for statistical reports.
 */
declare(strict_types=1);

/** Return a validated reporting range. End dates are exclusive. */
function report_period_range(string $period, ?int $year = null, ?int $month = null, ?int $quarter = null, ?string $anchorDate = null): array
{
    $now = new DateTimeImmutable('today');
    $year = ($year !== null && $year >= 2000 && $year <= 2100) ? $year : (int) $now->format('Y');
    $period = in_array($period, ['day', 'week', 'month', 'quarter', 'year'], true) ? $period : 'month';
    $anchorDate = $anchorDate !== null && v_date($anchorDate) ? $anchorDate : $now->format('Y-m-d');

    if ($period === 'day') {
        $start = new DateTimeImmutable($anchorDate);
        $end = $start->modify('+1 day');
        return ['period' => $period, 'year' => (int) $start->format('Y'), 'month' => (int) $start->format('n'), 'quarter' => null,
            'start' => $start->format('Y-m-d 00:00:00'), 'end' => $end->format('Y-m-d 00:00:00'), 'label' => $start->format('j F Y')];
    }
    if ($period === 'week') {
        $anchor = new DateTimeImmutable($anchorDate);
        $start = $anchor->modify('-' . ((int) $anchor->format('N') - 1) . ' days');
        $end = $start->modify('+7 days');
        return ['period' => $period, 'year' => (int) $start->format('Y'), 'month' => (int) $start->format('n'), 'quarter' => null,
            'start' => $start->format('Y-m-d 00:00:00'), 'end' => $end->format('Y-m-d 00:00:00'), 'label' => 'Week of ' . $start->format('j F Y')];
    }

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
        "SELECT r.birthdate, r.sex,
                COALESCE(NULLIF(r.marital_status, ''), r.civil_status) AS marital_status,
                r.voter_status,
                COALESCE(emp.label, 'Not specified') AS employment_status,
                COALESCE(pk.label, 'Purok not assigned') AS purok,
                COALESCE(rs.label, CASE WHEN r.residency_status = 'Relocated' THEN 'Transferred' ELSE r.residency_status END) AS resident_status,
                COALESCE(rt.label, oldcat.label, 'Not specified') AS resident_type
         FROM residents r
         LEFT JOIN lookups pk ON pk.lookup_id = r.purok_id AND pk.list_key = 'purok'
         LEFT JOIN lookups rs ON rs.lookup_id = r.resident_status_id AND rs.list_key = 'resident_status'
         LEFT JOIN lookups rt ON rt.lookup_id = r.resident_type_id AND rt.list_key = 'resident_type'
         LEFT JOIN lookups oldcat ON oldcat.lookup_id = r.category_id AND oldcat.list_key = 'resident_category'
         LEFT JOIN lookups emp ON emp.lookup_id = r.employment_status_id AND emp.list_key = 'employment_status'
         WHERE r.archived_at IS NULL
           AND COALESCE(rs.label, CASE WHEN r.residency_status = 'Relocated' THEN 'Transferred' ELSE r.residency_status END) = 'Active'
         ORDER BY r.resident_id"
    );
    $data = [
        'total' => count($rows), 'age_brackets' => [], 'sex' => [], 'marital_status' => [],
        'civil_status' => [], 'employment_status' => [], 'voter_status' => [],
        'purok' => [], 'resident_type' => [], 'resident_status' => [],
    ];
    $brackets = age_brackets();
    foreach ($rows as $r) {
        report_count_value($data['sex'], (string) $r['sex']);
        report_count_value($data['marital_status'], (string) $r['marital_status']);
        // Keep the old key for older report integrations while the revised UI uses marital_status.
        report_count_value($data['civil_status'], (string) $r['marital_status']);
        report_count_value($data['employment_status'], (string) $r['employment_status']);
        report_count_value($data['voter_status'], (string) $r['voter_status']);
        report_count_value($data['purok'], (string) $r['purok']);
        report_count_value($data['resident_type'], (string) $r['resident_type']);
        report_count_value($data['resident_status'], (string) $r['resident_status']);

        $age = age_on((string) $r['birthdate']);
        $ageLabel = 'Outside configured age brackets';
        foreach ($brackets as [$lo, $hi, $label]) {
            if ($age >= $lo && $age <= $hi) { $ageLabel = $label; break; }
        }
        report_count_value($data['age_brackets'], $ageLabel);
    }
    foreach (['sex','marital_status','civil_status','employment_status','voter_status','purok','resident_type','resident_status','age_brackets'] as $key) {
        ksort($data[$key], SORT_NATURAL | SORT_FLAG_CASE);
    }
    return $data;
}

/** Cash-collection ledger totals for a reporting window. Only reconciled entries count as collected revenue. */
function report_cash_collections(array $range): array
{
    return db_one(
        "SELECT
            COALESCE(SUM(CASE WHEN reconciliation_status = 'reconciled' THEN fee_amount ELSE 0 END), 0) AS collected_amount,
            COALESCE(SUM(CASE WHEN reconciliation_status = 'pending' THEN fee_amount ELSE 0 END), 0) AS pending_amount,
            COUNT(*) AS ledger_entry_count
         FROM cash_collection_ledger
         WHERE transaction_date >= ? AND transaction_date < ?",
        [$range['start'], $range['end']]
    ) ?? ['collected_amount' => 0, 'pending_amount' => 0, 'ledger_entry_count' => 0];
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
    $byFeeRule = db_all(
        "SELECT COALESCE(fee_category_snapshot, 'Per-document rate') AS fee_category,
                COALESCE(fee_tier_snapshot, 'Legacy fee') AS fee_tier,
                COUNT(*) AS issued_count, COALESCE(SUM(fee_amount), 0) AS recorded_fees
         FROM issuances WHERE issued_at >= ? AND issued_at < ?
         GROUP BY COALESCE(fee_category_snapshot, 'Per-document rate'), COALESCE(fee_tier_snapshot, 'Legacy fee')
         ORDER BY fee_category, fee_tier", $params
    );
    return ['summary' => $summary, 'by_type' => $byType, 'by_fee_rule' => $byFeeRule];
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

/** Queue statistics for a monthly, quarterly or annual reporting window. */
function report_queueing(array $range): array
{
    $summary = db_one(
        "SELECT COUNT(*) AS total,
                COALESCE(SUM(status = 'Waiting'), 0) AS waiting_count,
                COALESCE(SUM(status = 'Called'), 0) AS called_count,
                COALESCE(SUM(status = 'Served'), 0) AS served_count,
                COALESCE(SUM(status = 'Skipped'), 0) AS skipped_count,
                COALESCE(SUM(status = 'Cancelled'), 0) AS cancelled_count,
                AVG(CASE WHEN status = 'Served' AND served_at IS NOT NULL
                    THEN TIMESTAMPDIFF(SECOND, issued_at, served_at) ELSE NULL END) AS average_wait_seconds
         FROM queue_entries
         WHERE issued_at >= ? AND issued_at < ?",
        [$range['start'], $range['end']]
    ) ?? [
        'total' => 0, 'waiting_count' => 0, 'called_count' => 0, 'served_count' => 0,
        'skipped_count' => 0, 'cancelled_count' => 0, 'average_wait_seconds' => null,
    ];
    $byService = db_all(
        "SELECT service, COUNT(*) AS total,
                COALESCE(SUM(status = 'Waiting'), 0) AS waiting_count,
                COALESCE(SUM(status = 'Called'), 0) AS called_count,
                COALESCE(SUM(status = 'Served'), 0) AS served_count,
                COALESCE(SUM(status = 'Skipped'), 0) AS skipped_count,
                COALESCE(SUM(status = 'Cancelled'), 0) AS cancelled_count,
                AVG(CASE WHEN status = 'Served' AND served_at IS NOT NULL
                    THEN TIMESTAMPDIFF(SECOND, issued_at, served_at) ELSE NULL END) AS average_wait_seconds
         FROM queue_entries
         WHERE issued_at >= ? AND issued_at < ?
         GROUP BY service
         ORDER BY FIELD(service, 'Blotter', 'Documents', 'Venue')",
        [$range['start'], $range['end']]
    );
    return ['summary' => $summary, 'by_service' => $byService];
}

/** Compile the data-backed sections used by the monthly/quarterly administrative summary. */
function report_periodic_administrative(array $range): array
{
    return [
        'population' => report_demographics(),
        'issuance' => report_issuances($range),
        'collections' => report_cash_collections($range),
        'requests' => report_requests($range),
        'blotter' => report_blotters($range),
        'queues' => report_queueing($range),
    ];
}

/** Non-archived incidents filtered by incident date; current case statuses and type/Purok counts are all database-derived. */
function report_blotters(array $range): array
{
    $params = [$range['start'], $range['end']];
    $summary = db_one(
        "SELECT COUNT(*) AS total,
                COALESCE(SUM(msit_status = 'Ongoing'), 0) AS ongoing_count,
                COALESCE(SUM(msit_status = 'Unresolved'), 0) AS unresolved_count,
                COALESCE(SUM(msit_status = 'Resolved'), 0) AS resolved_count,
                COALESCE(SUM(msit_status = 'Dismissed'), 0) AS dismissed_count,
                COALESCE(SUM(msit_status = 'Archived'), 0) AS archived_count
         FROM blotters WHERE incident_date >= ? AND incident_date < ?", $params
    ) ?? [
        'total' => 0, 'ongoing_count' => 0, 'unresolved_count' => 0,
        'resolved_count' => 0, 'dismissed_count' => 0, 'archived_count' => 0,
    ];
    $activeTotal = (int) $summary['total'] - (int) $summary['archived_count'];
    $summary['resolution_rate'] = $activeTotal > 0
        ? round(((int) $summary['resolved_count'] / $activeTotal) * 100, 1) : 0.0;

    $byType = db_all(
        "SELECT COALESCE(it.label, 'Unclassified') AS incident_type, COUNT(*) AS case_count,
                COALESCE(SUM(b.msit_status = 'Resolved'), 0) AS resolved_count,
                COALESCE(SUM(b.msit_status = 'Dismissed'), 0) AS dismissed_count,
                COALESCE(SUM(b.msit_status = 'Archived'), 0) AS archived_count
         FROM blotters b LEFT JOIN lookups it ON it.lookup_id = b.incident_type_id AND it.list_key = 'incident_type'
         WHERE b.incident_date >= ? AND b.incident_date < ?
         GROUP BY COALESCE(it.label, 'Unclassified') ORDER BY case_count DESC, incident_type", $params
    );
    $byPurok = db_all(
        "SELECT COALESCE(pk.label, 'Purok not assigned') AS purok, COUNT(*) AS case_count
         FROM blotters b LEFT JOIN lookups pk ON pk.lookup_id = b.purok_id AND pk.list_key = 'purok'
         WHERE b.incident_date >= ? AND b.incident_date < ?
         GROUP BY COALESCE(pk.label, 'Purok not assigned') ORDER BY case_count DESC, purok", $params
    );
    $byStatus = db_all(
        "SELECT msit_status AS status, COUNT(*) AS case_count
         FROM blotters WHERE incident_date >= ? AND incident_date < ?
         GROUP BY msit_status
         ORDER BY FIELD(msit_status, 'Ongoing', 'Unresolved', 'Resolved', 'Dismissed', 'Archived')", $params
    );
    return ['summary' => $summary, 'by_type' => $byType, 'by_purok' => $byPurok, 'by_status' => $byStatus];
}
