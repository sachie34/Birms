<?php
/**
 * Role permissions aligned with BRMS_SRS_Revised.docx.
 * Legacy internal role ids are retained for existing accounts:
 * captain = Chairman (view-only); clerk = Staff (resident-service processing).
 */
declare(strict_types=1);

const PERMISSIONS_STATUS = 'MSIT_SRS_ALIGNED';

function permission_map(): array
{
    return [
        // Admin is the technical/data administrator: accounts, resident directory, reference data,
        // system settings, backup, audit and read access to operational records.
        // Admin does not process resident requests, issue certificates, run queues or edit blotter cases.
        'admin' => [
            'dashboard.view',
            'residents.view', 'residents.add', 'residents.edit', 'residents.archive', 'residents.restore', 'residents.export',
            'requests.view', 'requests.tracker',
            'certificates.history',
            'settings.view', 'settings.edit', 'documents.templates.manage',
            'audit.view', 'audit.export', 'users.manage', 'backup.run', 'cash_ledger.view',
            'blotters.view', 'blotter_requests.view', 'hearings.view',
            'venues.view', 'venues.configure',
            'queue.view',
            'reports.view', 'reports.export',
        ],
        // Chairman / Punong Barangay is strictly view-only. Staff approval uses the stored e-signature.
        'captain' => [
            'dashboard.view', 'residents.view', 'requests.view',
            'certificates.history', 'blotters.view', 'blotter_requests.view', 'hearings.view', 'venues.view', 'queue.view',
            'reports.view', 'reports.export', 'cash_ledger.view',
        ],
        // Secretary reviews requests, manages blotter/hearing records, and sets blotter-room availability.
        'secretary' => [
            'dashboard.view', 'documents.templates.manage',
            'residents.view',
            'requests.view', 'requests.add', 'requests.edit', 'requests.review',
            'certificates.history',
            'blotters.view', 'blotters.add', 'blotters.edit', 'blotters.archive',
            'blotter_requests.view',
            'hearings.view', 'hearings.add', 'hearings.edit',
            'venues.view', 'venues.availability.manage', 'queue.view',
            'reports.view', 'reports.export',
        ],
        // Staff handles resident-facing services, final request decisions, certificates, venues and queues.
        'clerk' => [
            'dashboard.view', 'residents.view',
            'requests.view', 'requests.add', 'requests.edit', 'requests.approve', 'requests.tracker',
            'certificates.history', 'certificates.issue', 'certificates.print',
            'blotters.view', 'blotter_requests.view', 'blotter_requests.review', 'hearings.view',
            'venues.view', 'venues.manage', 'venues.approve',
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
