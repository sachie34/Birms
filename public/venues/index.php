<?php
/** Venue directory, availability board and reservation tracker. */
declare(strict_types=1);
require_once __DIR__ . '/../../app/bootstrap.php';

require_login();
require_permission('venues.view');

$statusFilter = get_str('status');
if (!in_array($statusFilter, VENUE_RESERVATION_STATUSES, true)) { $statusFilter = ''; }
$dateFilter = get_str('date');
if ($dateFilter !== '' && !v_date($dateFilter)) { $dateFilter = ''; }
$venues = venue_list(false);
$where = [];
$params = [];
if ($statusFilter !== '') { $where[] = 'vr.status = ?'; $params[] = $statusFilter; }
if ($dateFilter !== '') { $where[] = 'vr.reservation_date = ?'; $params[] = $dateFilter; }
$condition = $where ? 'WHERE ' . implode(' AND ', $where) : '';
$reservations = db_all(
    'SELECT vr.*, v.name AS venue_name, r.resident_no, r.last_name, r.first_name, r.middle_name, r.suffix
     FROM venue_reservations vr
     JOIN venues v ON v.venue_id = vr.venue_id
     JOIN residents r ON r.resident_id = vr.resident_id
     ' . $condition . '
     ORDER BY vr.reservation_date DESC, vr.start_time DESC, vr.reservation_id DESC
     LIMIT 200',
    $params
);

render_header('Venue Reservations', [['Workspace', null], ['Venue Reservations', null]], 'venues');
page_head('Venue and Room Availability', 'Check shared rooms and venues. The Secretary controls the manual Available / Unavailable setting for blotter and hearing rooms. Staff processes resident reservations.');
if (can('venues.configure')) {
    card_start('Add a venue', 'Configure the names of spaces that residents can reserve.');
    echo '<form method="post" action="' . e(base_url('venues/action.php')) . '" class="row g-3 align-items-end">' . csrf_field()
        . '<input type="hidden" name="do" value="create_venue">'
        . '<div class="col-md-4"><label class="form-label" for="venue_name">Venue name</label><input class="form-control" id="venue_name" name="name" maxlength="120" required></div>'
        . '<div class="col-md-5"><label class="form-label" for="venue_description">Description (optional)</label><input class="form-control" id="venue_description" name="description" maxlength="500"></div>'
        . '<div class="col-md-3"><button class="btn btn-primary w-100" type="submit">Add venue</button></div></form>';
    card_end();
}
card_start('Blotter / Hearing Room Availability', 'Automatic follows approved reservations. The Secretary can manually mark a room Available or Unavailable for blotter and hearing schedules.');
if (!$venues) {
    echo empty_state('bi-building', 'No venues have been configured yet.');
} else {
    echo '<div class="row g-3">';
    foreach ($venues as $venue) {
        $state = $venue['current_state'];
        $label = $state === 'in_use' ? 'Red · Unavailable / In Use' : 'Green · Available';
        $class = $state === 'in_use' ? 'border-danger' : 'border-success';
        echo '<div class="col-md-6 col-xl-4"><div class="card h-100 ' . $class . '"><div class="card-body">'
            . '<div class="d-flex align-items-center justify-content-between gap-2"><h2 class="h5 mb-0">' . e((string) $venue['name']) . '</h2>'
            . '<span class="badge ' . ($state === 'in_use' ? 'text-bg-danger' : 'text-bg-success') . '">' . e($label) . '</span></div>'
            . '<p class="text-muted mt-2 mb-3">' . e((string) ($venue['description'] ?? '')) . '</p>';
        if (can('venues.availability.manage')) {
            echo '<form method="post" action="' . e(base_url('venues/action.php')) . '" class="d-flex flex-wrap gap-2">' . csrf_field()
                . '<input type="hidden" name="do" value="override"><input type="hidden" name="venue_id" value="' . (int) $venue['venue_id'] . '">'
                . '<select class="form-select form-select-sm flex-grow-1" name="override" aria-label="Venue availability override">'
                . '<option value="automatic"' . ($venue['availability_override'] === 'automatic' ? ' selected' : '') . '>Automatic</option>'
                . '<option value="available"' . ($venue['availability_override'] === 'available' ? ' selected' : '') . '>Green / Available</option>'
                . '<option value="in_use"' . ($venue['availability_override'] === 'in_use' ? ' selected' : '') . '>Red / Unavailable</option>'
                . '</select><button class="btn btn-sm btn-outline-primary" type="submit">Save status</button></form>';
        }
        echo '</div></div></div>';
    }
    echo '</div>';
}
card_end();

if (can('venues.manage')) {
    card_start('Record a reservation', 'For an in-person request received at the barangay counter. Kiosk requests use the resident identity flow.');
    echo '<form method="post" action="' . e(base_url('venues/action.php')) . '" class="row g-3">' . csrf_field()
        . '<input type="hidden" name="do" value="reserve">'
        . '<div class="col-md-3"><label class="form-label" for="venue_id">Venue</label><select class="form-select" name="venue_id" id="venue_id" required><option value="">Choose venue</option>';
    foreach ($venues as $venue) { echo '<option value="' . (int) $venue['venue_id'] . '">' . e((string) $venue['name']) . '</option>'; }
    echo '</select></div>'
        . '<div class="col-md-3"><label class="form-label" for="resident_id">Resident ID</label><input class="form-control" type="number" min="1" step="1" name="resident_id" id="resident_id" required></div>'
        . '<div class="col-md-6"><label class="form-label" for="purpose">Purpose</label><input class="form-control" name="purpose" id="purpose" maxlength="255" required></div>'
        . '<div class="col-md-4"><label class="form-label" for="reservation_date">Date</label><input class="form-control" type="date" name="reservation_date" id="reservation_date" min="' . e(date('Y-m-d')) . '" required></div>'
        . '<div class="col-md-4"><label class="form-label" for="start_time">Start time</label><input class="form-control" type="time" name="start_time" id="start_time" required></div>'
        . '<div class="col-md-4"><label class="form-label" for="end_time">End time</label><input class="form-control" type="time" name="end_time" id="end_time" required></div>'
        . '<div class="col-12"><button class="btn btn-primary" type="submit">Submit reservation request</button></div></form>';
    card_end();
}

card_start('Reservation tracker', 'Filter by status and date. Approved reservations block overlapping times.');
echo '<form method="get" action="' . e(base_url('venues/index.php')) . '" class="filters mb-3">'
    . '<div><label class="form-label" for="status">Status</label><select class="form-select" name="status" id="status"><option value="">All statuses</option>';
foreach (VENUE_RESERVATION_STATUSES as $status) { echo '<option value="' . e($status) . '"' . ($statusFilter === $status ? ' selected' : '') . '>' . e($status) . '</option>'; }
echo '</select></div><div><label class="form-label" for="date">Reservation date</label><input class="form-control" type="date" name="date" id="date" value="' . e($dateFilter) . '"></div><div><button class="btn btn-light border" type="submit">Filter</button></div></form>';
if (!$reservations) {
    echo empty_state('bi-calendar-x', 'No reservations match the selected filters.');
} else {
    echo '<div class="table-responsive"><table class="table table-b align-middle"><thead><tr><th>Reference</th><th>Venue / resident</th><th>Purpose</th><th>Date and time</th><th>Status</th><th>Next action</th></tr></thead><tbody>';
    foreach ($reservations as $reservation) {
        $name = trim((string) $reservation['last_name'] . ', ' . (string) $reservation['first_name'] . ' ' . (string) ($reservation['middle_name'] ?? '') . ' ' . (string) ($reservation['suffix'] ?? ''));
        echo '<tr><td><strong>' . e((string) $reservation['reference_no']) . '</strong><div class="small text-muted">' . e((string) ($reservation['resident_no'] ?? '')) . '</div></td>'
            . '<td>' . e((string) $reservation['venue_name']) . '<div class="small text-muted">' . e($name) . '</div></td>'
            . '<td>' . e((string) $reservation['purpose']) . '</td>'
            . '<td>' . e(date('d M Y', strtotime((string) $reservation['reservation_date']))) . '<div class="small text-muted">' . e(substr((string) $reservation['start_time'], 0, 5) . '–' . substr((string) $reservation['end_time'], 0, 5)) . '</div></td>'
            . '<td>' . pill((string) $reservation['status'], $reservation['status'] === 'Approved' ? 'green' : ($reservation['status'] === 'Rejected' ? 'red' : 'blue')) . '</td><td>';
        if (can('venues.approve') && $reservation['status'] === 'Pending') {
            foreach (['approve' => 'Approve', 'reject' => 'Reject'] as $action => $label) {
                echo '<form method="post" action="' . e(base_url('venues/action.php')) . '" class="d-inline" ' . ($action === 'reject' ? 'data-confirm="Reject this reservation?"' : '') . '>' . csrf_field()
                    . '<input type="hidden" name="do" value="' . e($action) . '"><input type="hidden" name="reservation_id" value="' . (int) $reservation['reservation_id'] . '">'
                    . '<button class="btn btn-sm ' . ($action === 'approve' ? 'btn-success' : 'btn-outline-danger') . ' me-1 mb-1" type="submit">' . e($label) . '</button></form>';
            }
        } elseif (can('venues.manage') && $reservation['status'] === 'Approved') {
            echo '<form method="post" action="' . e(base_url('venues/action.php')) . '" class="d-inline">' . csrf_field()
                . '<input type="hidden" name="do" value="complete"><input type="hidden" name="reservation_id" value="' . (int) $reservation['reservation_id'] . '">'
                . '<button class="btn btn-sm btn-primary" type="submit">Mark completed</button></form>';
        } else { echo '<span class="text-muted small">—</span>'; }
        echo '</td></tr>';
    }
    echo '</tbody></table></div>';
}
card_end();
render_footer();
