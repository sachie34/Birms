<?php
/**
 * app/permissions.php
 * Role-permission map. STATUS: PROVISIONAL, pending validation by the Barangay Secretary.
 * Deny by default: a permission not listed for a role is refused. Enforced on the SERVER in every page/handler.
 * Mirrors the "Role Matrix (Provisional)" sheet (Admin 7, Captain 14, Secretary 23, Clerk 12 permissions).
 */
declare(strict_types=1);

const PERMISSIONS_STATUS = 'PROVISIONAL';

function permission_map(): array
{
    return [
        // Administrator is the highest-privilege role and may perform operational tasks as needed.
        // The map remains provisional until the barangay validates all role assignments.
        'admin' => [
            'dashboard.view',
            'residents.view', 'residents.add', 'residents.edit', 'residents.archive', 'residents.export',
            'requests.view', 'requests.add', 'requests.edit', 'requests.approve',
            'certificates.history', 'certificates.issue',
            'settings.view', 'settings.edit',
            'audit.view', 'audit.export', 'users.manage', 'backup.run',
            'blotters.view', 'blotters.add', 'blotters.edit', 'blotters.archive',
            'hearings.view', 'hearings.add', 'hearings.edit',
            'reports.view', 'reports.export',
        ],
        'captain' => [
            'dashboard.view', 'residents.view', 'residents.export', 'requests.view', 'requests.approve',
            'certificates.history', 'settings.view', 'blotters.view', 'blotters.edit', 'hearings.view', 'hearings.edit',
            'reports.view', 'reports.export', 'audit.view',
        ],
        'secretary' => [
            'dashboard.view', 'residents.view', 'residents.add', 'residents.edit', 'residents.archive', 'residents.export',
            'requests.view', 'requests.add', 'requests.edit', 'requests.approve',
            'certificates.history', 'certificates.issue', 'settings.view', 'settings.edit',
            'blotters.view', 'blotters.add', 'blotters.edit', 'blotters.archive',
            'hearings.view', 'hearings.add', 'hearings.edit', 'reports.view', 'reports.export',
        ],
        'clerk' => [
            'dashboard.view', 'residents.view', 'residents.add', 'residents.edit',
            'requests.view', 'requests.add', 'requests.edit', 'certificates.history', 'certificates.issue',
            'blotters.view', 'blotters.add', 'hearings.view',
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

/** Server-side gate. Hiding a menu item is never the protection; this is. */
function require_permission(string $permission): void
{
    if (!can($permission)) {
        $u = auth_user();
        audit_best_effort('access_denied', 'Security', 'Failed', 'Needs ' . $permission . ' on ' . basename((string) ($_SERVER['SCRIPT_NAME'] ?? '')), $u['username'] ?? null);
        abort(403, 'Your role does not have access to this page.');
    }
}
