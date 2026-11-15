<?php
/** B10 regression tests: the legacy household-based resident importer is retired by the revised MSIT SRS. */
declare(strict_types=1);

function run_b10(array $S, string $base): void
{
    $sec = $S['secretary'];
    $clk = $S['clerk'];
    $adm = $S['admin'];
    $before = (int) db_val('SELECT COUNT(*) FROM residents');

    $paths = [
        '/residents/import.php',
        '/residents/import_template.php',
        '/residents/import_errors.php?token=invalid',
    ];
    $results = [];
    foreach ($paths as $path) {
        $results[] = $sec->get($path);
    }
    $retired = count(array_filter($results, static fn(array $r): bool => $r['status'] === 302
        && str_contains((string) $r['location'], 'residents/index.php'))) === 3;
    record('B10-01', 'Household-based CSV import and its template/error-report routes are retired in the revised resident model', $retired);

    $attempts = [
        post_as($sec, '/residents/index.php', '/residents/import.php', ['action' => 'commit']),
        post_as($clk, '/residents/index.php', '/residents/import.php', ['action' => 'preview']),
        post_as($adm, '/residents/index.php', '/residents/import.php', ['action' => 'preview']),
    ];
    $noWrites = count(array_filter($attempts, static fn(array $r): bool => $r['status'] === 302
        && str_contains((string) $r['location'], 'residents/index.php'))) === 3
        && (int) db_val('SELECT COUNT(*) FROM residents') === $before;
    record('B10-02', 'POST attempts cannot invoke the retired importer or modify resident records', $noWrites);

    $menu = $sec->get('/dashboard.php');
    record('B10-03', 'Resident import is not exposed as a live dashboard module', $menu['status'] === 200
        && !str_contains($menu['body'], 'residents/import.php'));
}
