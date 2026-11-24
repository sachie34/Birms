<?php
/** Staff service-queue console for Blotter, Documents and Venue. */
declare(strict_types=1);
require_once __DIR__ . '/../../app/bootstrap.php';

require_login();
require_permission('queue.view');

$snapshots = queue_all_snapshots();
$canManage = can('queue.manage');
$services = QUEUE_SERVICES;

render_header('Service Queue', [['Workspace', null], ['Service Queue', null]], 'queue');
page_head('Service Queue', $canManage
    ? 'Issue and manage separate daily numbers for Blotter, Documents and Venue. The public display shows queue numbers only.'
    : 'Monitor separate Blotter, Documents and Venue queue numbers. This role has read-only access; the public display shows numbers only.');

if ($canManage) {
    card_start('Issue a queue number', 'For a resident at the counter. Residents will also be able to get numbers from the kiosk when the kiosk workflow is enabled.');
    echo '<form method="post" action="' . e(base_url('queue/action.php')) . '" class="row g-3 align-items-end">' . csrf_field()
        . '<input type="hidden" name="do" value="issue">'
        . '<div class="col-md-3"><label class="form-label" for="service">Service</label><select class="form-select" id="service" name="service" required><option value="">Choose service</option>';
    foreach ($services as $service) { echo '<option value="' . e($service) . '">' . e($service) . '</option>'; }
    echo '</select></div>'
        . '<div class="col-md-3"><label class="form-label" for="resident_id">Resident ID (optional for walk-ins)</label><input class="form-control" id="resident_id" name="resident_id" type="number" min="1" step="1"></div>'
        . '<div class="col-md-3"><label class="form-label" for="reference_no">Request reference (optional)</label><input class="form-control" id="reference_no" name="reference_no" maxlength="24"></div>'
        . '<div class="col-md-6"><label class="form-label" for="purpose">Purpose of visit</label><input class="form-control" id="purpose" name="purpose" maxlength="255" required></div>'
        . '<div class="col-md-3"><button class="btn btn-primary w-100" type="submit">Issue queue number</button></div></form>';
    card_end();
}

echo '<div class="d-flex justify-content-end mb-3"><a class="btn btn-light border" target="_blank" rel="noopener" href="' . e(base_url('queue/display.php')) . '"><i class="bi bi-display me-1"></i>Open public display</a></div>';

foreach ($snapshots as $service => $snapshot) {
    $current = $snapshot['current_number'];
    card_start($service . ' queue', 'Today: ' . date('F j, Y'));
    echo '<div class="row g-3 mb-3">';
    echo '<div class="col-md-4"><div class="border rounded p-3 h-100"><div class="small text-muted">Now serving</div><div class="display-5 fw-bold">' . ($current === null ? '—' : (int) $current) . '</div></div></div>';
    echo '<div class="col-md-4"><div class="border rounded p-3 h-100"><div class="small text-muted">Waiting</div><div class="display-5 fw-bold">' . (int) $snapshot['waiting_count'] . '</div></div></div>';
    $average = $snapshot['average_wait_seconds'] !== null ? number_format($snapshot['average_wait_seconds'] / 60, 1) . ' min' : 'Not available';
    echo '<div class="col-md-4"><div class="border rounded p-3 h-100"><div class="small text-muted">Average completed wait</div><div class="h2 fw-semibold mb-0">' . e($average) . '</div></div></div>';
    echo '</div>';

    if ($canManage) {
        if ($current === null) {
            echo '<form method="post" action="' . e(base_url('queue/action.php')) . '" class="mb-3">' . csrf_field()
                . '<input type="hidden" name="do" value="call_next"><input type="hidden" name="service" value="' . e($service) . '">'
                . '<button class="btn btn-primary" type="submit">Call next ' . e(strtolower($service)) . ' number</button></form>';
        } else {
            $calledId = (int) db_val(
                'SELECT queue_id FROM queue_entries WHERE service = ? AND queue_date = ? AND status = \'Called\' LIMIT 1',
                [$service, date('Y-m-d')]
            );
            echo '<div class="d-flex flex-wrap gap-2 mb-3">';
            echo '<form method="post" action="' . e(base_url('queue/action.php')) . '">' . csrf_field()
                . '<input type="hidden" name="do" value="serve"><input type="hidden" name="queue_id" value="' . $calledId . '">'
                . '<button class="btn btn-success" type="submit">Mark #' . (int) $current . ' served</button></form>';
            echo '<form method="post" action="' . e(base_url('queue/action.php')) . '" data-confirm="Skip the currently called number?">' . csrf_field()
                . '<input type="hidden" name="do" value="skip"><input type="hidden" name="queue_id" value="' . $calledId . '">'
                . '<button class="btn btn-outline-warning" type="submit">Skip current number</button></form>';
            echo '</div>';
        }
    }

    if (!$snapshot['waiting']) {
        echo empty_state('bi-ticket', 'No one is waiting in this queue.');
    } else {
        echo '<div class="table-responsive"><table class="table align-middle"><thead><tr><th>Queue number</th><th>Issued at</th><th>Purpose</th><th>Reference</th>';
        if ($canManage) { echo '<th>Actions</th>'; }
        echo '</tr></thead><tbody>';
        foreach ($snapshot['waiting'] as $entry) {
            echo '<tr><td class="fw-bold fs-5">#' . (int) $entry['queue_number'] . '</td>'
                . '<td>' . e(fmt_dt((string) $entry['issued_at'])) . '</td>'
                . '<td>' . e((string) ($entry['purpose'] ?? '—')) . '</td>'
                . '<td>' . e((string) ($entry['reference_no'] ?? '—')) . '</td>';
            if ($canManage) {
                echo '<td><div class="d-flex flex-wrap gap-2">';
                foreach (['skip' => 'Skip', 'cancel' => 'Cancel'] as $action => $label) {
                    echo '<form method="post" action="' . e(base_url('queue/action.php')) . '"' . ($action === 'cancel' ? ' data-confirm="Cancel this queue number?"' : '') . '>' . csrf_field()
                        . '<input type="hidden" name="do" value="' . e($action) . '"><input type="hidden" name="queue_id" value="' . (int) $entry['queue_id'] . '">'
                        . '<button class="btn btn-sm ' . ($action === 'cancel' ? 'btn-outline-danger' : 'btn-outline-secondary') . '" type="submit">' . e($label) . '</button></form>';
                }
                echo '</div></td>';
            }
            echo '</tr>';
        }
        echo '</tbody></table></div>';
    }
    card_end();
}
render_footer();
