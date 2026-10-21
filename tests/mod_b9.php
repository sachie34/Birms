<?php
/** B9 tests: global search and shared application chrome. */
declare(strict_types=1);
require_once __DIR__ . '/../app/menu.php';

function run_b9(array $S, string $base): void
{
    $roles = ['admin', 'captain', 'secretary', 'clerk'];
    $layoutProblems = [];
    $checkedPages = 0;
    foreach ($roles as $role) {
        foreach (menu_definition() as $item) {
            if (empty($item['built']) || !role_can($role, (string) $item['perm'])) { continue; }
            $page = $S[$role]->get('/' . ltrim((string) $item['path'], '/'));
            $checkedPages++;
            if ($page['status'] !== 200 || !str_contains($page['body'], 'class="app-shell"')
                || !str_contains($page['body'], 'class="sidebar"')
                || !str_contains($page['body'], 'class="topbar"')
                || !str_contains($page['body'], 'class="topbar-search"')
                || !str_contains($page['body'], 'class="content"')) {
                $layoutProblems[] = $role . ':' . $item['path'] . '=' . $page['status'];
            }
        }
        $searchPage = $S[$role]->get('/search.php');
        if ($searchPage['status'] !== 200 || !str_contains($searchPage['body'], 'name="q"')) {
            $layoutProblems[] = $role . ':search.php=' . $searchPage['status'];
        }
    }
    record('B9-1', 'Shared UI shell is consistent across all permission-accessible workspace/admin tabs and all four roles (' . $checkedPages . ' menu routes)', !$layoutProblems, implode(', ', $layoutProblems));

    $odd = $S['clerk']->get('/search.php?q=%3Cscript%3Ealert%281%29%3C%2Fscript%3E');
    record('B9-2', 'Global search safely handles HTML/script input without reflected markup or a server error',
        $odd['status'] === 200 && !str_contains($odd['body'], '<script>alert(1)</script>') && !leaks($odd));

    db_exec("DELETE FROM residents WHERE last_name = 'ZZB9Search'");
    db_exec("INSERT INTO residents (last_name, first_name, birthdate, sex, civil_status, residency_status, voter_status)
             VALUES ('ZZB9Search', 'GlobalProbe', '1990-01-01', 'Male', 'Single', 'Active', 'Unverified')");
    $residentId = (int) db()->lastInsertId();
    try {
        $result = $S['secretary']->get('/search.php?q=GlobalProbe');
        $hasName = str_contains($result['body'], 'ZZB9Search');
        $hasLink = str_contains($result['body'], 'residents/view.php?id=' . $residentId);
        record('B9-3', 'Global search finds a stored resident and links to the permitted resident profile',
            $result['status'] === 200 && $hasName && $hasLink,
            'status=' . $result['status'] . ', name=' . ($hasName ? 'yes' : 'no') . ', link=' . ($hasLink ? 'yes' : 'no')
            . ', matching output=' . (preg_match('/.{0,60}GlobalProbe.{0,100}/s', $result['body'], $m) ? trim($m[0]) : 'none'));

        $clerk = $S['clerk']->get('/search.php?q=GlobalProbe');
        record('B9-4', 'Global search is usable by staff through the shared layout without a server error',
            $clerk['status'] === 200 && str_contains($clerk['body'], 'Residents') && !leaks($clerk));
    } finally {
        db_exec('DELETE FROM residents WHERE resident_id = ?', [$residentId]);
    }
}
