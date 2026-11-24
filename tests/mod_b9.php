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
            $activeMatches = preg_match('/<a class="nav-link-item active" href="([^"]+)"/', $page['body'], $activeMatch) === 1;
            if ($activeMatches) {
                $activeHref = html_entity_decode($activeMatch[1], ENT_QUOTES, 'UTF-8');
                $activePath = (string) (parse_url($activeHref, PHP_URL_PATH) ?? '');
                $activeQuery = (string) (parse_url($activeHref, PHP_URL_QUERY) ?? '');
                $expectedPath = '/' . ltrim((string) (parse_url((string) $item['path'], PHP_URL_PATH) ?? ''), '/');
                $expectedQuery = (string) (parse_url((string) $item['path'], PHP_URL_QUERY) ?? '');
                $activeMatches = str_ends_with(rtrim($activePath, '/'), $expectedPath)
                    && $activeQuery === $expectedQuery;
            }
            if (!$activeMatches) {
                $layoutProblems[] = $role . ':' . $item['path'] . ' active navigation item missing or points to a different tab';
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
        'admin' => 'Administrator can oversee document request processing and perform authorized corrections.',
        'captain' => 'Chairman access is read-only.',
        'secretary' => 'Your task is to review request details and supporting requirements.',
        'clerk' => 'Process requests that have completed the Secretary requirements review.',
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
    $breadcrumbOk = preg_match('~<a href="([^"]+)"[^>]*>Settings</a>~', $signature['body'], $breadcrumbMatch) === 1;
    if ($breadcrumbOk) {
        $breadcrumbHref = html_entity_decode($breadcrumbMatch[1], ENT_QUOTES, 'UTF-8');
        $breadcrumbOk = str_ends_with((string) (parse_url($breadcrumbHref, PHP_URL_PATH) ?? ''), '/admin/settings.php')
            && (string) (parse_url($breadcrumbHref, PHP_URL_QUERY) ?? '') === 'tab=general';
    }
    $signatureActiveOk = preg_match('/<a class="nav-link-item active" href="([^"]+)"/', $signature['body'], $activeMatch) === 1;
    if ($signatureActiveOk) {
        $activeHref = html_entity_decode($activeMatch[1], ENT_QUOTES, 'UTF-8');
        $signatureActiveOk = str_ends_with((string) (parse_url($activeHref, PHP_URL_PATH) ?? ''), '/admin/chairman_signature.php');
    }
    record('B9-6', 'Chairman Signature page highlights its own sidebar item and links back to the valid General settings tab',
        $signature['status'] === 200 && $breadcrumbOk && $signatureActiveOk,
        'HTTP=' . $signature['status']
        . '; settings breadcrumb=' . ($breadcrumbOk ? 'yes' : 'no')
        . '; active item=' . ($signatureActiveOk ? 'yes' : 'no'));

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

    $roleGuidance = [
        'admin' => [
            '/blotters/index.php' => 'View official blotter cases without changing them.',
            '/blotters/hearings.php' => 'View hearing schedules and related cases without changing hearing records.',
            '/queue/index.php' => 'Monitor current queue numbers and waiting counts for each service.',
            '/venues/index.php' => 'Maintain the official venue list as the Technical Administrator.',
            '/blotter_requests/index.php' => 'View resident-submitted blotter reports and their saved review status.',
        ],
        'captain' => [
            '/blotters/index.php' => 'View official blotter cases without changing them.',
            '/blotters/hearings.php' => 'View hearing schedules and related cases without changing hearing records.',
            '/queue/index.php' => 'Monitor current queue numbers and waiting counts for each service.',
            '/venues/index.php' => 'View room availability and reservation statuses without changing them.',
            '/blotter_requests/index.php' => 'View resident-submitted blotter reports and their saved review status.',
        ],
        'secretary' => [
            '/blotters/index.php' => 'Manage official blotter records using the permissions available to your role.',
            '/blotters/hearings.php' => 'Manage hearing schedules and record outcomes for assigned blotter cases.',
            '/queue/index.php' => 'Monitor current queue numbers and waiting counts for each service.',
            '/venues/index.php' => 'Set whether a room is available for blotter and hearing schedules.',
            '/blotter_requests/index.php' => 'View resident-submitted blotter reports and their saved review status.',
        ],
        'clerk' => [
            '/blotters/index.php' => 'View official blotter cases without changing them.',
            '/blotters/hearings.php' => 'View hearing schedules and related cases without changing hearing records.',
            '/queue/index.php' => 'Issue and manage daily queue tickets for Blotter, Documents and Venue.',
            '/venues/index.php' => 'Manage resident venue reservations and counter requests.',
            '/blotter_requests/index.php' => 'Review resident-submitted blotter reports before accepting or rejecting them.',
        ],
    ];
    $roleGuidanceProblems = [];
    foreach ($roleGuidance as $role => $routes) {
        foreach ($routes as $route => $expectedText) {
            $page = $S[$role]->get($route);
            if ($page['status'] !== 200 || !str_contains($page['body'], 'page-help')
                || !str_contains($page['body'], $expectedText)) {
                $roleGuidanceProblems[] = $role . ':' . $route . '=' . $page['status'];
            }
        }
    }
    record('B9-8', 'Blotter, hearing, queue, venue and resident-blotter pages explain each role’s actual permitted actions',
        !$roleGuidanceProblems, implode(', ', $roleGuidanceProblems));


    $secretaryVenue = $S['secretary']->get('/venues/index.php');
    $clerkVenue = $S['clerk']->get('/venues/index.php');
    $captainVenue = $S['captain']->get('/venues/index.php');
    $overrideForm = 'name="do" value="override"';
    $overrideVisibleOnlyToSecretary = str_contains($secretaryVenue['body'], $overrideForm)
        && !str_contains($clerkVenue['body'], $overrideForm)
        && !str_contains($captainVenue['body'], $overrideForm);
    record('B9-9', 'Only Secretary sees the blotter/hearing room availability toggle; Staff and Chairman are view-only for this setting',
        $secretaryVenue['status'] === 200 && $clerkVenue['status'] === 200 && $captainVenue['status'] === 200
        && $overrideVisibleOnlyToSecretary,
        'Secretary=' . $secretaryVenue['status'] . '; Staff=' . $clerkVenue['status']
        . '; Chairman=' . $captainVenue['status'] . '; toggle secretary/staff/chairman='
        . (str_contains($secretaryVenue['body'], $overrideForm) ? 'yes' : 'no') . '/'
        . (str_contains($clerkVenue['body'], $overrideForm) ? 'yes' : 'no') . '/'
        . (str_contains($captainVenue['body'], $overrideForm) ? 'yes' : 'no'));

    $venueName = 'ZZ-B9-ROOM-' . strtoupper(bin2hex(random_bytes(3)));
    db_exec("INSERT INTO venues (name, description, availability_override, is_active, created_by)
             VALUES (?, 'B9 temporary availability permission test', 'automatic', 1, NULL)", [$venueName]);
    $venueId = (int) db()->lastInsertId();
    try {
        $secretarySave = post_as($S['secretary'], '/venues/index.php', '/venues/action.php', [
            'do' => 'override', 'venue_id' => (string) $venueId, 'override' => 'available',
        ]);
        $afterSecretary = (string) db_val('SELECT availability_override FROM venues WHERE venue_id = ?', [$venueId]);
        $staffAttempt = post_as($S['clerk'], '/venues/index.php', '/venues/action.php', [
            'do' => 'override', 'venue_id' => (string) $venueId, 'override' => 'in_use',
        ]);
        $afterStaff = (string) db_val('SELECT availability_override FROM venues WHERE venue_id = ?', [$venueId]);
        record('B9-10', 'Secretary can save room availability while a direct Staff POST is denied server-side',
            $secretarySave['status'] === 302 && $afterSecretary === 'available'
            && $staffAttempt['status'] === 403 && $afterStaff === 'available',
            'Secretary POST=' . $secretarySave['status'] . '; Staff POST=' . $staffAttempt['status']
            . '; final override=' . $afterStaff);
    } finally {
        db_exec('DELETE FROM venues WHERE venue_id = ?', [$venueId]);
    }

}
