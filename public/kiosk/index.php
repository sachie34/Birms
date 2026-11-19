<?php
/** Public resident self-service menu and verified kiosk entry. */
declare(strict_types=1);
require_once __DIR__ . '/../../app/bootstrap.php';

$serviceDestinations = [
    'request' => 'kiosk/request.php',
    'blotter' => 'kiosk/blotter.php',
    'venue' => 'kiosk/venue.php',
    'queue' => 'kiosk/queue.php',
];
$queryNext = get_str('next');
$sessionNext = (string) ($_SESSION['kiosk_after_verify'] ?? '');
$requestedNext = isset($serviceDestinations[$queryNext])
    ? $queryNext
    : (isset($serviceDestinations[$sessionNext]) ? $sessionNext : '');
if ($requestedNext !== '') {
    $_SESSION['kiosk_after_verify'] = $requestedNext;
}

$resident = kiosk_current_resident();
if ($resident !== null && $requestedNext !== '') {
    unset($_SESSION['kiosk_after_verify']);
    redirect($serviceDestinations[$requestedNext]);
}

kiosk_page_start('Welcome to Barangay Services', 'Choose the service you need. You will verify your identity before submitting a personal request.');

if ($resident !== null) {
    $name = trim((string) $resident['first_name'] . ' ' . (string) ($resident['middle_name'] ?? '') . ' ' . (string) $resident['last_name']);
    $purok = $resident['purok_id'] ? (string) db_val('SELECT label FROM lookups WHERE lookup_id = ? AND list_key = \'purok\'', [(int) $resident['purok_id']]) : 'Not assigned';
    echo '<section class="kiosk-card"><p class="kiosk-eyebrow">IDENTITY VERIFIED</p><h2>Welcome, ' . e($name) . '</h2>'
        . '<p>Resident ID: <strong>' . e((string) $resident['resident_no']) . '</strong> · Purok: <strong>' . e($purok) . '</strong></p>';
} else {
    echo '<section class="kiosk-card"><p class="kiosk-eyebrow">BIRMS SELF-SERVICE</p><h2>What service do you need?</h2>'
        . '<p>Select a service below. You can scan this same QR code whenever you visit the barangay.</p>';
}

echo '<div class="kiosk-grid">'
    . '<a class="kiosk-action" href="' . e(base_url('kiosk/index.php?next=request')) . '"><span class="kiosk-icon">▤</span><strong>Document Request</strong><small>Indigency, residency, clearance and other certificates</small></a>'
    . '<a class="kiosk-action" href="' . e(base_url('kiosk/index.php?next=blotter')) . '"><span class="kiosk-icon">!</span><strong>Blotter / Complaint</strong><small>Submit an incident report for Staff review</small></a>'
    . '<a class="kiosk-action" href="' . e(base_url('kiosk/index.php?next=venue')) . '"><span class="kiosk-icon">⌂</span><strong>Venue Reservation</strong><small>Request a barangay facility reservation</small></a>'
    . '<a class="kiosk-action" href="' . e(base_url('kiosk/index.php?next=queue')) . '"><span class="kiosk-icon">#</span><strong>Get a Queue Number</strong><small>Blotter · Documents · Venue</small></a>'
    . '<a class="kiosk-action" href="' . e(base_url('kiosk/status.php')) . '"><span class="kiosk-icon">✓</span><strong>Track My Request</strong><small>Check progress using your reference number</small></a>'
    . '</div>';

if ($resident !== null) {
    echo '<form method="post" action="' . e(base_url('kiosk/end.php')) . '">' . csrf_field()
        . '<button class="kiosk-secondary" type="submit">End session</button></form>';
} elseif ($requestedNext !== '') {
    $serviceLabels = [
        'request' => 'Document Request',
        'blotter' => 'Blotter / Complaint',
        'venue' => 'Venue Reservation',
        'queue' => 'Queue Number',
    ];
    echo '<div class="kiosk-alert kiosk-alert-info"><strong>Selected:</strong> ' . e($serviceLabels[$requestedNext]) . '</div>'
        . '<h2 class="kiosk-form-section-title">Verify your identity to continue</h2>'
        . '<p>Use your Resident ID and birthdate. If you do not know your ID, enter your name and birthdate to receive a one-time code at your registered email or mobile number.</p>'
        . '<form method="post" action="' . e(base_url('kiosk/identify.php')) . '" class="kiosk-form">'
        . csrf_field()
        . '<input type="hidden" name="next" value="' . e($requestedNext) . '">'
        . '<div class="kiosk-field"><label for="resident_no">Resident ID (if known)</label><input id="resident_no" name="resident_no" maxlength="30" autocomplete="off" placeholder="Example: RES-2026-000001"></div>'
        . '<div class="kiosk-field"><label for="last_name">Last name (for code verification)</label><input id="last_name" name="last_name" maxlength="100" autocomplete="family-name"></div>'
        . '<div class="kiosk-field"><label for="first_name">First name</label><input id="first_name" name="first_name" maxlength="100" autocomplete="given-name"></div>'
        . '<div class="kiosk-field"><label for="birthdate">Birthdate</label><input id="birthdate" name="birthdate" type="date" required></div>'
        . '<button class="kiosk-primary" type="submit">Continue</button></form>'
        . '<p class="kiosk-helper">No email or mobile number registered? Please ask Staff to verify your identity at the counter.</p>';
} else {
    echo '<p class="kiosk-helper">Choose a service above to continue. Request details and resident information are shown only after identity verification.</p>';
}

echo '</section>';
kiosk_page_end();
