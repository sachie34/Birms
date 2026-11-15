<?php
/** POST-only venue setup, reservation and status actions. */
declare(strict_types=1);
require_once __DIR__ . '/../../app/bootstrap.php';

require_login();
if (!is_post()) { abort(405, 'Venue actions must use POST.'); }
require_csrf();
$do = post_str('do');

try {
    if ($do === 'create_venue' || $do === 'override') {
        require_permission('venues.manage');
        if ($do === 'create_venue') {
            $name = post_str('name');
            $description = post_str('description');
            if (!v_len($name, 2, 120)) { throw new InvalidArgumentException('Venue name must be 2-120 characters.'); }
            if (str_len($description) > 500) { throw new InvalidArgumentException('Venue description is limited to 500 characters.'); }
            if ((int) db_val('SELECT COUNT(*) FROM venues WHERE name = ?', [$name]) > 0) { throw new InvalidArgumentException('That venue name already exists.'); }
            db_transaction(function () use ($name, $description): void {
                db_exec('INSERT INTO venues (name, description, availability_override, is_active, created_by) VALUES (?, ?, \'automatic\', 1, ?)',
                    [$name, $description !== '' ? $description : null, audit_actor()['user_id'] ?? null]);
                $id = (int) db()->lastInsertId();
                audit('venue_created', 'Venues', 'Success', 'Venue created: ' . $name, 'venue', $id);
            });
            flash_add('success', 'Venue added.');
        } else {
            $id = post_int('venue_id');
            if ($id === null) { throw new InvalidArgumentException('Choose a valid venue.'); }
            venue_set_override($id, post_str('override'));
            flash_add('success', 'Venue availability override saved.');
        }
    } elseif ($do === 'reserve') {
        require_permission('venues.manage');
        $venueId = post_int('venue_id');
        $residentId = post_int('resident_id');
        if ($venueId === null || $residentId === null) { throw new InvalidArgumentException('Choose a venue and enter a valid Resident ID.'); }
        $result = venue_create_reservation($venueId, $residentId, post_str('purpose'), post_str('reservation_date'), post_str('start_time'), post_str('end_time'), (int) audit_actor()['user_id']);
        flash_add('success', 'Reservation request ' . $result['reference_no'] . ' recorded as Pending approval.');
    } elseif (in_array($do, ['approve','reject','complete'], true)) {
        require_permission('venues.approve');
        $id = post_int('reservation_id');
        if ($id === null) { throw new InvalidArgumentException('Choose a valid reservation.'); }
        $target = ['approve' => 'Approved', 'reject' => 'Rejected', 'complete' => 'Completed'][$do];
        venue_change_status($id, $target, post_str('note'));
        flash_add('success', 'Reservation updated to ' . $target . '.');
    } else {
        abort(400, 'Unknown venue action.');
    }
} catch (InvalidArgumentException $e) {
    flash_add('danger', $e->getMessage());
} catch (RuntimeException $e) {
    flash_add('warning', $e->getMessage());
} catch (Throwable $e) {
    error_log('BIRMS venue action failed: ' . $e->getMessage());
    flash_add('danger', 'The venue action could not be completed. No successful change was recorded.');
}
redirect('venues/index.php');
