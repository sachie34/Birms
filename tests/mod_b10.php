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
        $results[] = ($path === '/residents/import_errors.php?token=invalid') ? $sec->get($path) : $adm->get($path);
    }
    $retired = $results[0]['status'] === 302 && str_contains((string) $results[0]['location'], 'residents/index.php')
        && $results[1]['status'] === 302 && str_contains((string) $results[1]['location'], 'residents/index.php')
        && $results[2]['status'] === 404;
    record('B10-01', 'Household-based CSV import and its template are retired; stale error-report tokens are not found', $retired);

    $attempts = [
        post_as($sec, '/residents/index.php', '/residents/import.php', ['action' => 'commit']),
        post_as($clk, '/residents/index.php', '/residents/import.php', ['action' => 'preview']),
        post_as($adm, '/residents/index.php', '/residents/import.php', ['action' => 'preview']),
    ];
    $noWrites = $attempts[0]['status'] === 403
        && $attempts[1]['status'] === 403
        && $attempts[2]['status'] === 302
        && str_contains((string) $attempts[2]['location'], 'residents/index.php')
        && (int) db_val('SELECT COUNT(*) FROM residents') === $before;
    record('B10-02', 'POST attempts cannot invoke the retired importer or modify resident records', $noWrites);

    $menu = $sec->get('/dashboard.php');
    record('B10-03', 'Resident import is not exposed as a live dashboard module', $menu['status'] === 200
        && !str_contains($menu['body'], 'residents/import.php'));
}
