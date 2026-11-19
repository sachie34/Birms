<?php
/**
 * Role permissions aligned with BRMS_SRS_Revised.docx.
 * The legacy internal role ids captain/clerk are retained for existing accounts:
 * captain = Chairman (read-only); clerk = Staff (service processing).
 */
declare(strict_types=1);

const PERMISSIONS_STATUS = 'MSIT_SRS_ALIGNED';

function permission_map(): array
{
    return [
        'admin' => [
            'dashboard.view',
            'residents.view', 'residents.add', 'residents.edit', 'residents.archive', 'residents.restore', 'residents.export',
            'requests.view', 'requests.add', 'requests.edit', 'requests.review', 'requests.approve', 'requests.tracker',
            'certificates.history', 'certificates.issue', 'certificates.print',
            'settings.view', 'settings.edit',
            'audit.view', 'audit.export', 'users.manage', 'backup.run', 'cash_ledger.view', 'cash_ledger.manage',
            'blotters.view', 'blotters.add', 'blotters.edit', 'blotters.archive',
            'blotter_requests.view', 'blotter_requests.review',
            'hearings.view', 'hearings.add', 'hearings.edit',
            'venues.view', 'venues.manage', 'venues.override', 'venues.approve',
            'queue.view', 'queue.manage',
            'reports.view', 'reports.export',
        ],
        // Chairman / Punong Barangay is strictly read-only. The stored signature is applied by staff.
        'captain' => [
            'dashboard.view', 'residents.view', 'requests.view',
            'certificates.history', 'blotters.view', 'blotter_requests.view', 'hearings.view', 'venues.view', 'queue.view',
            'reports.view', 'reports.export', 'cash_ledger.view',
        ],
        // Secretary maintains resident, document and blotter records and reviews document requests.
        'secretary' => [
            'dashboard.view',
            'residents.view',
            'requests.view', 'requests.add', 'requests.edit', 'requests.review',
            'certificates.history',
            'blotters.view', 'blotters.add', 'blotters.edit', 'blotters.archive',
            'blotter_requests.view',
            'hearings.view', 'hearings.add', 'hearings.edit',
            'venues.view', 'venues.override', 'queue.view',
            'reports.view', 'reports.export',
        ],
        // Staff handles resident-facing services, final request decisions, certificate generation, venues and queues.
        'clerk' => [
            'dashboard.view', 'residents.view',
            'requests.view', 'requests.add', 'requests.edit', 'requests.approve', 'requests.tracker',
            'certificates.history', 'certificates.issue', 'certificates.print',
            'blotters.view', 'blotter_requests.view', 'blotter_requests.review', 'hearings.view',
            'venues.view', 'venues.manage', 'venues.override', 'venues.approve',
            'queue.view', 'queue.manage',
        ],
    ];
}

function role_can(string $role, string $permission): bool
{
    return in_array($permission, permission_map()[$role] ?? [], true);
}

function can(string $permission): bool
{
    $u = auth_user();
    return $u !== null && role_can((string) $u['role'], $permission);
}

/** Server-side gate. Hiding a menu item is never the protection. */
function require_permission(string $permission): void
{
    if (!can($permission)) {
        $u = auth_user();
        audit_best_effort('access_denied', 'Security', 'Failed', 'Needs ' . $permission . ' on ' . basename((string) ($_SERVER['SCRIPT_NAME'] ?? '')), $u['username'] ?? null);
        abort(403, 'Your role does not have access to this page.');
    }
}
