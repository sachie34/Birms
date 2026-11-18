<?php
/** Resident-only blotter report submission from an identity-verified kiosk session. */
declare(strict_types=1);
require_once __DIR__ . '/../../app/bootstrap.php';

$resident = kiosk_require_resident();
$puroks = lookup_list('purok');
$incidentTypes = lookup_list('incident_type');
$errors = [];
$values = [
    'incident_date' => date('Y-m-d'),
    'incident_time' => date('H:i'),
    'incident_place' => '',
    'purok_id' => '',
    'incident_type_id' => '',
    'narrative' => '',
    'respondent_name' => '',
    'respondent_age' => '',
    'respondent_address' => '',
    'respondent_contact' => '',
    'notification_preference' => (string) ($resident['notification_preference'] ?? 'email'),
];

if (is_post()) {
    require_csrf();
    rate_limit_require('kiosk_blotter_request', (string) $resident['resident_id'], 4, 3600,
        'Too many blotter reports were submitted from this resident session. Please ask Staff for help.');
    foreach ($values as $key => $_) {
        $values[$key] = $key === 'narrative' ? post_text($key) : post_str($key);
    }
    $result = resident_blotter_request_submit((int) $resident['resident_id'], $_POST, $errors);
    if ($result !== null) {
        $_SESSION['kiosk_last_blotter_request'] = $result;
        kiosk_flash('Your blotter report has been recorded. Keep the reference number and watch the status tracker.', 'success');
        redirect('kiosk/blotter_receipt.php');
    }
}

kiosk_page_start('File a blotter report', 'Tell the barangay what happened. Your report receives a reference number and Blotter queue ticket. Staff will review it.');
if ($errors) {
    echo '<div class="kiosk-alert kiosk-alert-error">' . e(implode(' ', $errors)) . '</div>';
}
echo '<section class="kiosk-card"><form method="post" action="' . e(base_url('kiosk/blotter.php')) . '" class="kiosk-form">' . csrf_field()
    . '<div class="kiosk-grid kiosk-time-grid">'
    . '<div class="kiosk-field"><label for="incident_date">Date of incident</label><input id="incident_date" name="incident_date" type="date" min="' . e(date('Y-m-d', strtotime('-10 years'))) . '" max="' . e(date('Y-m-d')) . '" value="' . e($values['incident_date']) . '" required></div>'
    . '<div class="kiosk-field"><label for="incident_time">Time of incident</label><input id="incident_time" name="incident_time" type="time" value="' . e($values['incident_time']) . '" required></div>'
    . '</div>'
    . '<div class="kiosk-field"><label for="incident_place">Place of incident</label><input id="incident_place" name="incident_place" maxlength="150" value="' . e($values['incident_place']) . '" required></div>'
    . '<div class="kiosk-field"><label for="purok_id">Purok (optional)</label><select id="purok_id" name="purok_id"><option value="">Not stated</option>';
foreach ($puroks as $id => $label) {
    echo '<option value="' . (int) $id . '"' . ($values['purok_id'] === (string) $id ? ' selected' : '') . '>' . e($label) . '</option>';
}
echo '</select></div>';
if ($incidentTypes) {
    echo '<div class="kiosk-field"><label for="incident_type_id">Incident type (optional)</label><select id="incident_type_id" name="incident_type_id"><option value="">Not classified</option>';
    foreach ($incidentTypes as $id => $label) {
        echo '<option value="' . (int) $id . '"' . ($values['incident_type_id'] === (string) $id ? ' selected' : '') . '>' . e($label) . '</option>';
    }
    echo '</select></div>';
}
echo '<div class="kiosk-field"><label for="narrative">What happened?</label><textarea id="narrative" name="narrative" rows="5" maxlength="5000" required>' . e($values['narrative']) . '</textarea><p class="kiosk-helper">Describe the facts: who was involved, what happened, and where and when it happened. Do not include guesses.</p></div>'
    . '<h2 class="kiosk-form-section-title">Respondent (optional)</h2>'
    . '<div class="kiosk-field"><label for="respondent_name">Respondent full name</label><input id="respondent_name" name="respondent_name" maxlength="100" value="' . e($values['respondent_name']) . '"></div>'
    . '<div class="kiosk-grid kiosk-time-grid"><div class="kiosk-field"><label for="respondent_age">Respondent age</label><input id="respondent_age" name="respondent_age" type="number" min="0" max="120" value="' . e($values['respondent_age']) . '"></div>'
    . '<div class="kiosk-field"><label for="respondent_contact">Respondent contact</label><input id="respondent_contact" name="respondent_contact" maxlength="20" value="' . e($values['respondent_contact']) . '"></div></div>'
    . '<div class="kiosk-field"><label for="respondent_address">Respondent address</label><input id="respondent_address" name="respondent_address" maxlength="255" value="' . e($values['respondent_address']) . '"></div>'
    . '<div class="kiosk-field"><label for="notification_preference">How should we notify you?</label><select id="notification_preference" name="notification_preference" required>'
    . queue_notification_preference_options($values['notification_preference'])
    . '</select><p class="kiosk-helper">Email is the default. If SMS is unavailable, BIRMS will use your registered email when available. Watch the queue display too.</p></div>'
    . '<button class="kiosk-primary" type="submit">Submit blotter report</button></form>'
    . '<a class="kiosk-secondary-link" href="' . e(base_url('kiosk/index.php')) . '">Back to home</a></section>';
kiosk_page_end();
