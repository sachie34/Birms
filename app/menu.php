<?php
/**
 * app/menu.php  One list drives the sidebar. An item shows only if it is built AND the role may use it.
 * Pages that do not exist yet are marked 'built' => false, so there are no dead links.
 */
declare(strict_types=1);

function menu_definition(): array
{
    return [
        ['group' => 'Workspace', 'key' => 'dashboard', 'label' => 'Operations Overview', 'icon' => 'bi-grid-1x2',     'path' => 'dashboard.php',     'perm' => 'dashboard.view', 'built' => true],
        // Each link is shown only when the route is implemented and the user's role has its permission.
        ['group' => 'Workspace', 'key' => 'residents', 'label' => 'Residents',            'icon' => 'bi-person-vcard', 'path' => 'residents/index.php', 'perm' => 'residents.view', 'built' => true],
        ['group' => 'Workspace', 'key' => 'households', 'label' => 'Households',          'icon' => 'bi-house',        'path' => 'residents/households.php', 'perm' => 'residents.view', 'built' => true],
        ['group' => 'Workspace', 'key' => 'visitors',  'label' => 'Visitor Logbook',      'icon' => 'bi-journal-text', 'path' => 'visitors/index.php',  'perm' => 'requests.view',  'built' => true],
        ['group' => 'Workspace', 'key' => 'requests',  'label' => 'Request Tracker',      'icon' => 'bi-inbox',        'path' => 'requests/index.php',  'perm' => 'requests.view',  'built' => true],
        ['group' => 'Workspace', 'key' => 'certs',     'label' => 'Certificate Generation','icon' => 'bi-file-earmark-check', 'path' => 'certificates/issue.php', 'perm' => 'certificates.issue', 'built' => true],
        ['group' => 'Workspace', 'key' => 'history',   'label' => 'Issuance History',     'icon' => 'bi-clock-history','path' => 'certificates/history.php', 'perm' => 'certificates.history', 'built' => true],
        ['group' => 'Workspace', 'key' => 'blotters',  'label' => 'Blotter',              'icon' => 'bi-shield-exclamation', 'path' => 'blotters/index.php', 'perm' => 'blotters.view', 'built' => true],
        ['group' => 'Workspace', 'key' => 'hearings',  'label' => 'Hearings',             'icon' => 'bi-calendar-event', 'path' => 'blotters/hearings.php', 'perm' => 'hearings.view', 'built' => true],
        ['group' => 'Workspace', 'key' => 'lookup',    'label' => 'Case Look-up',         'icon' => 'bi-search',       'path' => 'blotters/lookup.php',   'perm' => 'blotters.view',  'built' => true],
        ['group' => 'Workspace', 'key' => 'reports',   'label' => 'Reports',              'icon' => 'bi-bar-chart-line','path' => 'reports/index.php',  'perm' => 'reports.view',   'built' => true],
        ['group' => 'Administration', 'key' => 'users',    'label' => 'User Accounts',    'icon' => 'bi-people',       'path' => 'admin/users.php',     'perm' => 'users.manage',   'built' => true],
        ['group' => 'Administration', 'key' => 'settings', 'label' => 'Fees and Puroks',  'icon' => 'bi-sliders',      'path' => 'admin/settings.php',  'perm' => 'settings.view',  'built' => true],
        ['group' => 'Administration', 'key' => 'audit',    'label' => 'Audit Trail',      'icon' => 'bi-journal-check','path' => 'admin/audit.php',     'perm' => 'audit.view',     'built' => true],
        ['group' => 'Administration', 'key' => 'backup',   'label' => 'Backup',           'icon' => 'bi-download',     'path' => 'admin/backup.php',    'perm' => 'backup.run',     'built' => true],
        ['group' => 'Administration', 'key' => 'ledger',   'label' => 'Cash Collection Ledger', 'icon' => 'bi-cash-stack', 'path' => 'admin/ledger.php', 'perm' => 'backup.run', 'built' => true],
    ];
}

function visible_menu(): array
{
    $out = [];
    foreach (menu_definition() as $item) {
        if ($item['built'] && can($item['perm'])) { $out[$item['group']][] = $item; }
    }
    return $out;
}
