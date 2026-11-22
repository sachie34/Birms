<?php
/** POST-only queue actions with CSRF, role permissions and transactional state transitions. */
declare(strict_types=1);
require_once __DIR__ . '/../../app/bootstrap.php';

require_login();
require_permission('queue.manage');
if (!is_post()) { abort(405, 'Queue actions must use POST.'); }
require_csrf();

$do = post_str('do');
try {
    if ($do === 'issue') {
        $service = post_str('service');
        $residentText = post_str('resident_id');
        $residentId = $residentText === '' ? null : (ctype_digit($residentText) && (int) $residentText > 0 ? (int) $residentText : -1);
        if ($residentId === -1) { throw new InvalidArgumentException('Enter a valid Resident ID or leave the field blank for a walk-in.'); }
        $purpose = post_str('purpose');
        $result = queue_issue_number($service, $residentId, post_str('reference_no'), 'staff', $purpose);
        flash_add('success', $service . ' queue number #' . $result['queue_number'] . ' issued.');
    } elseif ($do === 'call_next') {
        $service = post_str('service');
        $next = queue_call_next($service);
        if ($next === null) {
            flash_add('warning', 'There are no waiting numbers in the ' . $service . ' queue.');
        } else {
            flash_add('success', $service . ' queue number #' . (int) $next['queue_number'] . ' is now being called.');
        }
    } else {
        $id = post_int('queue_id');
        if ($id === null || $id < 1) { throw new InvalidArgumentException('A valid queue entry is required.'); }
        $status = match ($do) {
            'serve' => 'Served',
            'skip' => 'Skipped',
            'cancel' => 'Cancelled',
            default => throw new InvalidArgumentException('Unknown queue action.'),
        };
        queue_finish_entry($id, $status);
        flash_add('success', 'Queue entry marked ' . strtolower($status) . '.');
    }
} catch (InvalidArgumentException $e) {
    flash_add('danger', $e->getMessage());
} catch (RuntimeException $e) {
    flash_add('warning', $e->getMessage());
} catch (Throwable $e) {
    error_log('BIRMS queue action failed: ' . $e->getMessage());
    flash_add('danger', 'The queue action could not be completed. No success was recorded; check the application log.');
}

redirect('queue/index.php');
