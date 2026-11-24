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
            $activeLink = 'class="nav-link-item active" href="' . e(base_url((string) $item['path'])) . '"';
            if (!str_contains($page['body'], $activeLink)) {
                $layoutProblems[] = $role . ':' . $item['path'] . ' active navigation item missing';
            }
        }
        $searchPage = $S[$role]->get('/search.php');
        if ($searchPage['status'] !== 200 || !str_contains($searchPage['body'], 'name="q"')) {
            $layoutProblems[] = $role . ':search.php=' . $searchPage['status'];
        }
    }
    record('B9-1', 'Shared UI shell and correct active sidebar item render on every permission-accessible route for all four roles (' . $checkedPages . ' menu routes)', !$layoutProblems, implode(', ', $layoutProblems));

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
    $signature = $S['admin']->get('/admin/chairman_signature.php');
    $signatureBreadcrumb = 'href="' . e(base_url('admin/settings.php?tab=general')) . '"';
    $signatureActive = 'class="nav-link-item active" href="' . e(base_url('admin/chairman_signature.php')) . '"';
    record('B9-6', 'Chairman Signature page highlights its own sidebar item and links back to the valid General settings tab',
        $signature['status'] === 200
        && str_contains($signature['body'], $signatureBreadcrumb)
        && str_contains($signature['body'], $signatureActive),
        'HTTP=' . $signature['status']
        . '; settings breadcrumb=' . (str_contains($signature['body'], $signatureBreadcrumb) ? 'yes' : 'no')
        . '; active item=' . (str_contains($signature['body'], $signatureActive) ? 'yes' : 'no'));

    $portalCss = $S['admin']->get('/assets/css/birms.css');
    $kioskCss = $S['admin']->get('/assets/css/kiosk.css');
    $appJs = $S['admin']->get('/assets/js/app.js');
    $bootstrapJs = $S['admin']->get('/assets/vendor/bootstrap/js/bootstrap.bundle.min.js');
    $assetsOk = $portalCss['status'] === 200
        && $kioskCss['status'] === 200
        && $appJs['status'] === 200
        && $bootstrapJs['status'] === 200
        && preg_match('/html\\s*\\{\\s*font-size:\\s*18px\\s*;\\s*\\}/', $portalCss['body']) === 1
        && str_contains($portalCss['body'], 'min-height: 48px;')
        && preg_match('/html\\s*\\{\\s*font-size:\\s*24px\\s*;\\s*\\}/', $kioskCss['body']) === 1
        && str_contains($kioskCss['body'], 'min-height: 60px;')
        && str_contains($appJs['body'], 'sidebarToggle');
    record('B9-7', 'Portal and kiosk styles and browser scripts load and retain the revised SRS text and touch-target sizing',
        $assetsOk,
        'portal CSS=' . $portalCss['status'] . '; kiosk CSS=' . $kioskCss['status']
        . '; app JS=' . $appJs['status'] . '; Bootstrap JS=' . $bootstrapJs['status']);

}
