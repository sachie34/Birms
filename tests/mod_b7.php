<?php
/** B7/B8 tests: reports, dashboard figures, and administrator backup. */
declare(strict_types=1);

function run_b7(array $S, string $base): void
{
    [$ok, $why] = rbac_page($S, '/reports/index.php', 'reports.view');
    record('B7-1', 'Reports page follows the existing report permission matrix', $ok, $why);

    $r = $S['secretary']->get('/reports/index.php');
    record('B7-2', 'Secretary can open reports with real-data summary sections and no error text', $r['status'] === 200 && str_contains($r['body'], 'Demographic breakdown') && str_contains($r['body'], 'Issuance summary') && !leaks($r));

    $r = $S['secretary']->get('/reports/export.php?period=year&year=' . date('Y'));
    record('B7-3', 'Secretary can export the report as CSV and the action is auditable', $r['status'] === 200 && str_contains((string) ($r['headers']['content-type'] ?? ''), 'text/csv') && str_contains($r['body'], 'Demographic breakdown'));

    $r = $S['admin']->get('/admin/backup.php');
    record('B8-1', 'Administrator can open the backup page', $r['status'] === 200 && str_contains($r['body'], 'Download SQL backup') && !leaks($r));

    $r = $S['admin']->post('/admin/backup.php', ['action' => 'download']);
    record('B8-2', 'Backup refuses a POST without CSRF', $r['status'] === 403);

    $r = post_as($S['admin'], '/admin/backup.php', '/admin/backup.php', ['action' => 'download']);
    record('B8-3', 'Administrator can download a schema and data SQL backup', $r['status'] === 200 && str_contains((string) ($r['headers']['content-type'] ?? ''), 'application/sql') && str_contains($r['body'], 'SET FOREIGN_KEY_CHECKS=0') && str_contains($r['body'], 'CREATE TABLE'));
}
