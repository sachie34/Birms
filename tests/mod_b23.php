<?php
/** B23: Admin management of resident statuses and resident types from the revised MSIT SRS. */
declare(strict_types=1);

function run_b23(array $S, string $base): void
{
    $admin = $S['admin'];
    $staff = $S['clerk'];
    $suffix = strtoupper(bin2hex(random_bytes(4)));
    $typeLabel = 'ZZ B23 Type ' . $suffix;
    $renamedType = 'ZZ B23 Type Renamed ' . $suffix;
    $statusLabel = 'ZZ B23 Status ' . $suffix;
    $renamedStatus = 'ZZ B23 Status Renamed ' . $suffix;
    $typeId = null;
    $statusId = null;

    try {
        $page = $admin->get('/admin/settings.php?tab=lists');
        record(
            'B23-01',
            'Admin Other lists screen exposes revised Resident statuses and Resident types',
            $page['status'] === 200
            && str_contains($page['body'], 'Resident statuses')
            && str_contains($page['body'], 'Resident types')
            && str_contains($page['body'], 'Active')
            && str_contains($page['body'], 'PWD'),
            'HTTP=' . $page['status']
        );

        $staffPage = $staff->get('/admin/settings.php?tab=lists');
        record(
            'B23-02',
            'Staff cannot open Admin reference-list management',
            $staffPage['status'] === 403,
            'Staff HTTP=' . $staffPage['status']
        );

        $addType = post_as($admin, '/admin/settings.php?tab=lists', '/admin/settings.php', [
            'do' => 'lookup_add', 'tab' => 'lists', 'list_key' => 'resident_type', 'label' => $typeLabel,
        ]);
        $foundType = db_val(
            "SELECT lookup_id FROM lookups WHERE list_key = 'resident_type' AND label = ?",
            [$typeLabel]
        );
        $typeId = $foundType !== null ? (int) $foundType : null;
        record(
            'B23-03',
            'Admin can add an active resident type',
            $addType['status'] === 302 && $typeId !== null
            && (int) db_val('SELECT is_active FROM lookups WHERE lookup_id = ?', [$typeId]) === 1,
            'HTTP=' . $addType['status'] . '; lookup ID=' . ($typeId ?? 'missing')
        );

        $renameType = $typeId !== null
            ? post_as($admin, '/admin/settings.php?tab=lists', '/admin/settings.php', [
                'do' => 'lookup_rename', 'tab' => 'lists', 'id' => $typeId, 'label' => $renamedType,
            ])
            : ['status' => 0];
        $typeAfterRename = db_val('SELECT label FROM lookups WHERE lookup_id = ?', [$typeId]);
        record(
            'B23-04',
            'Admin can rename a resident type without changing its lookup ID',
            $renameType['status'] === 302 && $typeAfterRename === $renamedType
            && (int) db_val('SELECT lookup_id FROM lookups WHERE lookup_id = ?', [$typeId]) === $typeId,
            'HTTP=' . $renameType['status'] . '; label=' . (string) $typeAfterRename
        );

        $hideType = $typeId !== null
            ? post_as($admin, '/admin/settings.php?tab=lists', '/admin/settings.php', [
                'do' => 'lookup_toggle', 'tab' => 'lists', 'id' => $typeId,
            ])
            : ['status' => 0];
        record(
            'B23-05',
            'Admin can remove a resident type from new selections without deleting its historical lookup row',
            $hideType['status'] === 302
            && (int) db_val('SELECT is_active FROM lookups WHERE lookup_id = ?', [$typeId]) === 0
            && (int) db_val('SELECT COUNT(*) FROM lookups WHERE lookup_id = ?', [$typeId]) === 1,
            'HTTP=' . $hideType['status'] . '; row preserved='
            . ((int) db_val('SELECT COUNT(*) FROM lookups WHERE lookup_id = ?', [$typeId]) === 1 ? 'yes' : 'no')
        );

        $addStatus = post_as($admin, '/admin/settings.php?tab=lists', '/admin/settings.php', [
            'do' => 'lookup_add', 'tab' => 'lists', 'list_key' => 'resident_status', 'label' => $statusLabel,
        ]);
        $foundStatus = db_val(
            "SELECT lookup_id FROM lookups WHERE list_key = 'resident_status' AND label = ?",
            [$statusLabel]
        );
        $statusId = $foundStatus !== null ? (int) $foundStatus : null;
        record(
            'B23-06',
            'Admin can add a resident status',
            $addStatus['status'] === 302 && $statusId !== null
            && (int) db_val('SELECT is_active FROM lookups WHERE lookup_id = ?', [$statusId]) === 1,
            'HTTP=' . $addStatus['status'] . '; lookup ID=' . ($statusId ?? 'missing')
        );

        $renameStatus = $statusId !== null
            ? post_as($admin, '/admin/settings.php?tab=lists', '/admin/settings.php', [
                'do' => 'lookup_rename', 'tab' => 'lists', 'id' => $statusId, 'label' => $renamedStatus,
            ])
            : ['status' => 0];
        record(
            'B23-07',
            'Admin can rename a resident status without changing its lookup ID',
            $renameStatus['status'] === 302
            && db_val('SELECT label FROM lookups WHERE lookup_id = ?', [$statusId]) === $renamedStatus,
            'HTTP=' . $renameStatus['status'] . '; label=' . (string) db_val('SELECT label FROM lookups WHERE lookup_id = ?', [$statusId])
        );

        $hideStatus = $statusId !== null
            ? post_as($admin, '/admin/settings.php?tab=lists', '/admin/settings.php', [
                'do' => 'lookup_toggle', 'tab' => 'lists', 'id' => $statusId,
            ])
            : ['status' => 0];
        record(
            'B23-08',
            'Admin can hide a resident status from future selections while preserving the historical lookup row',
            $hideStatus['status'] === 302
            && (int) db_val('SELECT is_active FROM lookups WHERE lookup_id = ?', [$statusId]) === 0
            && (int) db_val('SELECT COUNT(*) FROM lookups WHERE lookup_id = ?', [$statusId]) === 1,
            'HTTP=' . $hideStatus['status']
        );
    } finally {
        if ($typeId !== null) { db_exec('DELETE FROM lookups WHERE lookup_id = ?', [$typeId]); }
        if ($statusId !== null) { db_exec('DELETE FROM lookups WHERE lookup_id = ?', [$statusId]); }
    }
}
