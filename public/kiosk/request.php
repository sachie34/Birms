<?php
/** Public self-service document request form. */
declare(strict_types=1);
require_once __DIR__ . '/../../app/bootstrap.php';
$resident = kiosk_require_resident();
$types = db_all('SELECT type_id, name FROM document_types WHERE is_active = 1 ORDER BY sort_order, name');
$errors = [];

if (is_post()) {
    require_csrf();
    rate_limit_require('kiosk_document_request', (string) $resident['resident_id'], 5, 3600,
        'Too many document requests were submitted from this resident session. Please ask Staff for help.');
    $typeId = post_int('type_id');
    $purpose = post_str('purpose');
    $type = $typeId ? db_one('SELECT type_id, name FROM document_types WHERE type_id = ? AND is_active = 1', [$typeId]) : null;
    if ($type === null) { $errors[] = 'Choose an available document type.'; }
    if (!v_len($purpose, 3, 255)) { $errors[] = 'Purpose must be 3-255 characters.'; }
    if (!$errors) {
        try {
            $result = db_transaction(function () use ($resident, $type, $purpose): array {
                $reference = new_request_reference();
                db_exec(
                    "INSERT INTO document_requests
                     (reference_no, resident_id, type_id, purpose, assigned_to, requirements_status, requirements_note, requested_at, created_by, status, msit_status)
                     VALUES (?, ?, ?, ?, NULL, 'Not checked', NULL, NOW(), NULL, 'Pending', 'Pending')",
                    [$reference, (int) $resident['resident_id'], (int) $type['type_id'], $purpose]
                );
                $requestId = (int) db()->lastInsertId();
                db_exec(
                    "INSERT INTO request_status_history
                     (service, reference_no, previous_status, new_status, changed_by, note, request_id)
                     VALUES ('Documents', ?, NULL, 'Pending', NULL, 'Submitted by resident through the self-service kiosk.', ?)",
                    [$reference, $requestId]
                );
                audit('request_created', 'Requests', 'Success', $reference . ' submitted through the resident kiosk.', 'request', $requestId, 'resident-kiosk', null);
                $ticket = queue_issue_number('Documents', (int) $resident['resident_id'], $reference, 'kiosk', $purpose);
                return ['request_id' => $requestId, 'reference_no' => $reference, 'ticket' => $ticket];
            });
            $_SESSION['kiosk_last_request'] = $result;
            $_SESSION['kiosk_last_queue_ticket'] = $result['ticket'];
            kiosk_flash('Your request has been recorded as Pending. Keep the reference number below.', 'success');
            redirect('kiosk/request_receipt.php');
        } catch (Throwable $e) {
            error_log('BIRMS kiosk request failed: ' . get_class($e));
            $errors[] = 'The request could not be submitted. Please ask Staff for help.';
        }
    }
}
kiosk_page_start('Request a document', 'Choose a document, state its purpose and submit. The kiosk will issue a reference number and Documents queue ticket.');
if ($errors) { echo '<div class="kiosk-alert kiosk-alert-error">' . e(implode(' ', $errors)) . '</div>'; }
echo '<section class="kiosk-card"><form method="post" action="' . e(base_url('kiosk/request.php')) . '" class="kiosk-form">' . csrf_field()
    . '<div class="kiosk-field"><label for="type_id">Document type</label><select id="type_id" name="type_id" required><option value="">Choose a document</option>';
foreach ($types as $type) { echo '<option value="' . (int) $type['type_id'] . '">' . e((string) $type['name']) . '</option>'; }
echo '</select></div><div class="kiosk-field"><label for="purpose">Purpose</label><textarea id="purpose" name="purpose" maxlength="255" rows="3" required></textarea></div>'
    . '<button class="kiosk-primary" type="submit">Submit document request</button></form>'
    . '<a class="kiosk-secondary-link" href="' . e(base_url('kiosk/index.php')) . '">Back to home</a></section>';
kiosk_page_end();
