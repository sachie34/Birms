<?php
/** Shared B7 reporting queries. Uses only the current B1-B6 schema. */
declare(strict_types=1);

function report_years(): array
{
    $years = db_all("SELECT DISTINCT y FROM (
        SELECT YEAR(issued_at) AS y FROM issuances
        UNION SELECT YEAR(requested_at) AS y FROM document_requests
        UNION SELECT YEAR(incident_date) AS y FROM blotters
    ) years WHERE y IS NOT NULL ORDER BY y DESC");
    $out = [];
    foreach ($years as $row) { $out[] = (int) $row['y']; }
    $current = (int) date('Y');
    if (!in_array($current, $out, true)) { array_unshift($out, $current); }
    return $out;
}

function report_period(array $input): array
{
    $period = in_array((string) ($input['period'] ?? ''), ['month', 'quarter', 'year'], true) ? (string) $input['period'] : 'month';
    $year = (int) ($input['year'] ?? date('Y'));
    $years = report_years();
    if ($year < 2000 || $year > ((int) date('Y') + 1)) { $year = (int) date('Y'); }
    $month = max(1, min(12, (int) ($input['month'] ?? date('n'))));
    $quarter = max(1, min(4, (int) ($input['quarter'] ?? (int) ceil($month / 3))));
    if ($period === 'quarter') { $month = (($quarter - 1) * 3) + 1; }
    $start = match ($period) {
        'year' => sprintf('%04d-01-01', $year),
        'quarter' => sprintf('%04d-%02d-01', $year, $month),
        default => sprintf('%04d-%02d-01', $year, $month),
    };
    $end = match ($period) {
        'year' => sprintf('%04d-01-01', $year + 1),
        'quarter' => date('Y-m-d', strtotime($start . ' +3 months')),
        default => date('Y-m-d', strtotime($start . ' +1 month')),
    };
    return compact('period', 'year', 'month', 'quarter', 'start', 'end', 'years');
}

function report_period_label(array $p): string
{
    return match ($p['period']) {
        'year' => (string) $p['year'],
        'quarter' => 'Q' . $p['quarter'] . ' ' . $p['year'],
        default => date('F Y', strtotime($p['start'])),
    };
}

function report_age_brackets(): array
{
    $raw = (string) setting('age_brackets', '0-17,18-59,60-200');
    $out = [];
    foreach (preg_split('/\s*,\s*/', $raw, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $part) {
        if (preg_match('/^(\d+)\s*-\s*(\d+)$/', $part, $m) !== 1) { continue; }
        $min = (int) $m[1]; $max = (int) $m[2];
        if ($min <= $max) { $out[] = ['label' => $part, 'min' => $min, 'max' => $max]; }
    }
    return $out ?: [['label' => '0-17', 'min' => 0, 'max' => 17], ['label' => '18-59', 'min' => 18, 'max' => 59], ['label' => '60+', 'min' => 60, 'max' => 200]];
}

function report_age_on(string $birthdate): int
{
    try { return (int) (new DateTimeImmutable($birthdate))->diff(new DateTimeImmutable('today'))->y; }
    catch (Throwable) { return -1; }
}

function report_data(array $p): array
{
    $residents = db_all("SELECT birthdate, sex, civil_status, voter_status FROM residents WHERE archived_at IS NULL AND residency_status = 'Active'");
    $age = []; foreach (report_age_brackets() as $b) { $age[$b['label']] = 0; }
    $sex = []; $civil = []; $voter = [];
    foreach ($residents as $r) {
        $a = report_age_on((string) $r['birthdate']);
        foreach (report_age_brackets() as $b) { if ($a >= $b['min'] && $a <= $b['max']) { $age[$b['label']]++; break; } }
        $sex[(string) $r['sex']] = ($sex[(string) $r['sex']] ?? 0) + 1;
        $civil[(string) $r['civil_status']] = ($civil[(string) $r['civil_status']] ?? 0) + 1;
        $voter[(string) $r['voter_status']] = ($voter[(string) $r['voter_status']] ?? 0) + 1;
    }

    $issuanceWhere = 'WHERE issued_at >= ? AND issued_at < ?';
    $issuanceParams = [$p['start'], $p['end']];
    $issuances = db_all("SELECT type_name, COUNT(*) AS total, COALESCE(SUM(fee_amount), 0) AS fees FROM issuances {$issuanceWhere} GROUP BY type_name ORDER BY type_name", $issuanceParams);
    $issuanceTotal = (int) db_val("SELECT COUNT(*) FROM issuances {$issuanceWhere}", $issuanceParams);
    $feesTotal = (float) db_val("SELECT COALESCE(SUM(fee_amount), 0) FROM issuances {$issuanceWhere}", $issuanceParams);

    $requestRows = db_all("SELECT status, COUNT(*) AS total FROM document_requests WHERE requested_at >= ? AND requested_at < ? GROUP BY status ORDER BY status", [$p['start'], $p['end']]);
    $blotterRows = db_all("SELECT status, COUNT(*) AS total FROM blotters WHERE incident_date >= ? AND incident_date < ? AND archived_at IS NULL GROUP BY status ORDER BY status", [$p['start'], $p['end']]);
    $purokRows = db_all("SELECT COALESCE(l.label, 'Not stated') AS purok, COUNT(*) AS total
        FROM blotters b LEFT JOIN lookups l ON l.lookup_id = b.purok_id
        WHERE b.incident_date >= ? AND b.incident_date < ? AND b.archived_at IS NULL
        GROUP BY COALESCE(l.label, 'Not stated') ORDER BY total DESC, purok", [$p['start'], $p['end']]);

    return [
        'population' => count($residents), 'age' => $age, 'sex' => $sex, 'civil' => $civil, 'voter' => $voter,
        'issuances' => $issuances, 'issuance_total' => $issuanceTotal, 'fees_total' => $feesTotal,
        'requests' => $requestRows, 'blotters' => $blotterRows, 'puroks' => $purokRows,
    ];
}
