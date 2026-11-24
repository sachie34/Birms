<?php
/** Resident-only self-service kiosk. The shared QR always opens the service-selection home screen. */
declare(strict_types=1);
require_once __DIR__ . '/../../app/bootstrap.php';

kiosk_page_start('Welcome to Barangay Services', 'Verify your identity, then choose the service you need: Blotter, Documents, Venue, Queue or Request Status.');

if ($resident !== null) {
    $name = trim((string) $resident['first_name'] . ' ' . (string) ($resident['middle_name'] ?? '') . ' ' . (string) $resident['last_name']);
    $purok = $resident['purok_id'] ? (string) db_val('SELECT label FROM lookups WHERE lookup_id = ? AND list_key = \'purok\'', [(int) $resident['purok_id']]) : 'Not assigned';
    echo '<section class="kiosk-card"><p class="kiosk-eyebrow">IDENTITY VERIFIED</p><h2>Welcome, ' . e($name) . '</h2>'
        . '<p>Resident ID: <strong>' . e((string) $resident['resident_no']) . '</strong> · Purok: <strong>' . e($purok) . '</strong></p>'
        . '<div class="kiosk-grid">'
        . '<a class="kiosk-action" href="' . e(base_url('kiosk/queue.php')) . '"><span class="kiosk-icon">#</span><strong>Get a queue number</strong><small>Blotter · Documents · Venue</small></a>'
        . '<a class="kiosk-action" href="' . e(base_url('kiosk/request.php')) . '"><span class="kiosk-icon">▤</span><strong>Request a document</strong><small>Submit a new request</small></a>'
        . '<a class="kiosk-action" href="' . e(base_url('kiosk/blotter.php')) . '"><span class="kiosk-icon">!</span><strong>File a blotter report</strong><small>Report an incident</small></a>'
        . '<a class="kiosk-action" href="' . e(base_url('kiosk/venue.php')) . '"><span class="kiosk-icon">⌂</span><strong>Reserve a venue</strong><small>Check available venues</small></a>'
        . '<a class="kiosk-action" href="' . e(base_url('kiosk/status.php')) . '"><span class="kiosk-icon">✓</span><strong>Check request status</strong><small>Use your reference number</small></a>'
        . '</div><form method="post" action="' . e(base_url('kiosk/end.php')) . '">' . csrf_field() . '<button class="kiosk-secondary" type="submit">End session</button></form></section>';
} else {
    echo '<section class="kiosk-card"><p class="kiosk-eyebrow">RESIDENT IDENTITY CHECK</p>'
        . '<h2>How would you like to verify?</h2>'
        . '<p>Use your Resident ID and birthdate. If you do not know your ID, enter your name and birthdate to receive a one-time code at your registered email address. If no email is on file, Staff can verify you at the counter.</p>'
        . '<form method="post" action="' . e(base_url('kiosk/identify.php')) . '" class="kiosk-form">'
        . csrf_field()
                . '<div class="kiosk-field"><label for="resident_no">Resident ID (if known)</label><input id="resident_no" name="resident_no" maxlength="30" autocomplete="off" placeholder="Example: RES-2026-000001"></div>'
        . '<div class="kiosk-field"><label for="last_name">Last name (for code verification)</label><input id="last_name" name="last_name" maxlength="100" autocomplete="family-name"></div>'
        . '<div class="kiosk-field"><label for="first_name">First name</label><input id="first_name" name="first_name" maxlength="100" autocomplete="given-name"></div>'
        . '<div class="kiosk-field"><label for="birthdate">Birthdate</label><input id="birthdate" name="birthdate" type="date" required></div>'
        . '<button class="kiosk-primary" type="submit">Continue</button></form>'
        . '<p class="kiosk-helper">No email address registered? Please ask Staff to verify your identity at the counter.</p></section>';
}
kiosk_page_end();
