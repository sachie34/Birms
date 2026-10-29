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

    // Regression test: the Clerk global-search result set must match row-level request access.
    $tag = 'ZZB9P' . strtoupper(bin2hex(random_bytes(4)));
    $foreignId = $ownId = $assignedId = 0;
    $residentId = 0;
    try {
        db_exec("INSERT INTO residents (last_name, first_name, birthdate, sex, civil_status, residency_status, voter_status)
                 VALUES (?, 'PrivacyProbe', '1990-01-01', 'Male', 'Single', 'Active', 'Unverified')", [$tag]);
        $residentId = (int) db()->lastInsertId();
        $typeId = (int) db_val("SELECT type_id FROM document_types WHERE name = 'Barangay Clearance'");
        $adminId = (int) db_val("SELECT user_id FROM users WHERE username = 'zz_test_admin'");
        $clerkId = (int) db_val("SELECT user_id FROM users WHERE username = 'zz_test_clerk'");
        $foreignRef = $tag . '-F';
        $ownRef = $tag . '-O';
        $assignedRef = $tag . '-A';

        db_exec("INSERT INTO document_requests (reference_no, resident_id, type_id, purpose, status, requested_at, created_by)
                 VALUES (?, ?, ?, ?, 'Pending', NOW(), ?)",
            [$foreignRef, $residentId, $typeId, $tag . ' foreign request', $adminId]);
        $foreignId = (int) db()->lastInsertId();
        db_exec("INSERT INTO document_requests (reference_no, resident_id, type_id, purpose, status, requested_at, created_by)
                 VALUES (?, ?, ?, ?, 'Pending', NOW(), ?)",
            [$ownRef, $residentId, $typeId, $tag . ' own request', $clerkId]);
        $ownId = (int) db()->lastInsertId();
        db_exec("INSERT INTO document_requests (reference_no, resident_id, type_id, purpose, status, requested_at, created_by, assigned_to)
                 VALUES (?, ?, ?, ?, 'Pending', NOW(), ?, ?)",
            [$assignedRef, $residentId, $typeId, $tag . ' assigned request', $adminId, $clerkId]);
        $assignedId = (int) db()->lastInsertId();

        $search = $S['clerk']->get('/search.php?q=' . urlencode($tag));
        $foreignDetails = $S['clerk']->get('/requests/view.php?id=' . $foreignId);
        $assignedDetails = $S['clerk']->get('/requests/view.php?id=' . $assignedId);
        $privacyOk = $search['status'] === 200
            && !str_contains($search['body'], 'requests/view.php?id=' . $foreignId)
            && !str_contains($search['body'], $foreignRef)
            && str_contains($search['body'], 'requests/view.php?id=' . $ownId)
            && str_contains($search['body'], 'requests/view.php?id=' . $assignedId)
            && $foreignDetails['status'] === 404
            && $assignedDetails['status'] === 200;
        record('B9-6', 'Clerk global search hides foreign requests but shows own/assigned requests; direct access remains enforced',
            $privacyOk,
            'search=' . $search['status'] . ', foreign-link=' . (str_contains($search['body'], 'requests/view.php?id=' . $foreignId) ? 'visible' : 'hidden')
            . ', own-link=' . (str_contains($search['body'], 'requests/view.php?id=' . $ownId) ? 'visible' : 'hidden')
            . ', assigned-link=' . (str_contains($search['body'], 'requests/view.php?id=' . $assignedId) ? 'visible' : 'hidden')
            . ', foreign-detail=' . $foreignDetails['status'] . ', assigned-detail=' . $assignedDetails['status']);
    } finally {
        if ($foreignId || $ownId || $assignedId) {
            db_exec('DELETE FROM document_requests WHERE request_id IN (?, ?, ?)', [$foreignId ?: 0, $ownId ?: 0, $assignedId ?: 0]);
        }
        if ($residentId > 0) { db_exec('DELETE FROM residents WHERE resident_id = ?', [$residentId]); }
    }

    $expectedHelp = [
        'admin' => 'Use the queue that matches the task you need to complete.',
        'captain' => 'Your main task here is the final decision on eligible requests.',
        'secretary' => 'Your main task here is to check request requirements.',
        'clerk' => 'Use My Requests to follow requests you filed or are assigned to.',
    ];
    $helpProblems = [];
    foreach ($expectedHelp as $role => $expectedText) {
        $page = $S[$role]->get('/requests/index.php');
        if ($page['status'] !== 200 || !str_contains($page['body'], 'page-help')
            || !str_contains($page['body'], 'How to use this page') || !str_contains($page['body'], $expectedText)) {
            $helpProblems[] = $role . ': status=' . $page['status'] . ', help=' . (str_contains($page['body'], 'page-help') ? 'yes' : 'no');
        }
    }
    record('B9-5', 'Page-help panel and role-specific Request Tracker instructions render for all four roles', !$helpProblems, implode(', ', $helpProblems));
}
