<?php
/** B9 tests: global search and shared application chrome. */
declare(strict_types=1);

function run_b9(array $S, string $base): void
{
    $roles = ['admin', 'captain', 'secretary', 'clerk'];
    $layoutOk = true;
    foreach ($roles as $role) {
        $r = $S[$role]->get('/search.php');
        if ($r['status'] !== 200 || !str_contains($r['body'], 'topbar-search')
            || !str_contains($r['body'], 'Search Records') || !str_contains($r['body'], 'name="q"')) {
            $layoutOk = false;
        }
    }
    record('B9-1', 'Global search is available inside the shared signed-in layout for all four roles', $layoutOk);

    $odd = $S['clerk']->get('/search.php?q=%3Cscript%3Ealert%281%29%3C%2Fscript%3E');
    record('B9-2', 'Global search safely handles HTML/script input without reflected markup or a server error',
        $odd['status'] === 200 && !str_contains($odd['body'], '<script>alert(1)</script>') && !leaks($odd));

    db_exec("DELETE FROM residents WHERE last_name = 'ZZB9Search'");
    db_exec("INSERT INTO residents (last_name, first_name, birthdate, sex, civil_status, residency_status, voter_status)
             VALUES ('ZZB9Search', 'GlobalProbe', '1990-01-01', 'Male', 'Single', 'Active', 'Unverified')");
    $residentId = (int) db()->lastInsertId();
    try {
        $result = $S['secretary']->get('/search.php?q=ZZB9GlobalProbe');
        record('B9-3', 'Global search finds a stored resident and links to the permitted resident profile',
            $result['status'] === 200 && str_contains($result['body'], 'ZZB9Search') && str_contains($result['body'], 'residents/view.php?id=' . $residentId));

        $clerk = $S['clerk']->get('/search.php?q=ZZB9GlobalProbe');
        record('B9-4', 'Global search remains available through the common layout for staff roles without exposing a database error',
            $clerk['status'] === 200 && str_contains($clerk['body'], 'Residents') && !leaks($clerk));
    } finally {
        db_exec('DELETE FROM residents WHERE resident_id = ?', [$residentId]);
    }
}
