<?php
/** POST-only manual identity and voter-list verification; no external database/API calls. */
declare(strict_types=1);
require_once __DIR__ . '/../../app/bootstrap.php';

require_login();
$id = get_int('id');
if ($id === null) { abort(400, 'A resident ID is required.'); }
require_permission('residents.edit');
if (!is_post()) { abort(405, 'This action accepts POST only.'); }
require_csrf();

$resident = db_one('SELECT resident_id, last_name, first_name, voter_status, archived_at FROM residents WHERE resident_id = ?', [$id]);
if ($resident === null) { abort(404, 'That resident was not found.'); }
if ($resident['archived_at'] !== null) {
    flash_add('warning', 'Restore this resident before adding a verification record.');
    redirect('residents/view.php', ['id' => $id]);
}

$subject = post_str('verification_subject');
$method = post_str('verification_method');
$outcome = post_str('verification_outcome');
$reference = post_str('reference_note');
$remarks = post_text('remarks');
$errors = [];

if (!in_array($subject, ['identity', 'voter_registration'], true)) { $errors[] = 'Choose identity or voter-registration verification.'; }
if (!in_array($method, ['government_id', 'barangay_voter_list', 'other_document'], true)) { $errors[] = 'Choose a valid verification method.'; }
if (!in_array($outcome, ['verified', 'unable_to_verify', 'registered', 'not_registered'], true)) { $errors[] = 'Choose a valid verification outcome.'; }
if ($subject === 'identity') {
    if (!in_array($method, ['government_id', 'other_document'], true)) { $errors[] = 'Identity verification must use a Government ID or other supporting document.'; }
    if (!in_array($outcome, ['verified', 'unable_to_verify'], true)) { $errors[] = 'Identity outcome must be Verified or Unable to verify.'; }
} elseif ($subject === 'voter_registration') {
    if (!in_array($method, ['barangay_voter_list', 'other_document'], true)) { $errors[] = 'Voter-registration verification must use the local voter list or other supporting document.'; }
    if (!in_array($outcome, ['registered', 'not_registered', 'unable_to_verify'], true)) { $errors[] = 'Choose Registered, Not registered, or Unable to verify.'; }
}
if (str_len($reference) > 150) { $errors[] = 'The reference note is limited to 150 characters.'; }
if (str_len($remarks) > 500) { $errors[] = 'Notes are limited to 500 characters.'; }
if ($errors) {
    flash_add('danger', implode(' ', $errors));
    redirect('residents/view.php', ['id' => $id]);
}

try {
    db_transaction(function () use ($id, $resident, $subject, $method, $outcome, $reference, $remarks): void {
        db_exec('INSERT INTO resident_verifications
                 (resident_id, verification_subject, verification_method, outcome, reference_note, remarks, verified_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$id, $subject, $method, $outcome, $reference !== '' ? $reference : null, $remarks !== '' ? $remarks : null, audit_actor()['user_id'] ?? null]);

        $statusChange = '';
        if ($subject === 'voter_registration') {
            $newStatus = match ($outcome) {
                'registered' => 'Registered',
                'not_registered' => 'Not registered',
                default => 'Unverified',
            };
            if ($newStatus !== (string) $resident['voter_status']) {
                db_exec('UPDATE residents SET voter_status = ? WHERE resident_id = ? AND archived_at IS NULL', [$newStatus, $id]);
                $statusChange = '; voter status ' . $resident['voter_status'] . ' -> ' . $newStatus;
            }
        }

        $subjectLabel = $subject === 'identity' ? 'Identity' : 'Voter registration';
        $methodLabels = [
            'government_id' => 'Government ID checked in person',
            'barangay_voter_list' => 'Local barangay voter list checked',
            'other_document' => 'Other supporting document',
        ];
        $outcomeLabels = [
            'verified' => 'Verified',
            'unable_to_verify' => 'Unable to verify',
            'registered' => 'Registered',
            'not_registered' => 'Not registered',
        ];
        // Never copy reference notes or ID data into the audit log.
        audit('resident_verification_recorded', 'Residents', 'Success',
            $subjectLabel . ': ' . $outcomeLabels[$outcome] . ' using ' . $methodLabels[$method] . $statusChange,
            'resident', $id);
    });
} catch (Throwable $e) {
    audit_best_effort('resident_verification_rejected', 'Residents', 'Failed', 'Verification could not be saved for resident #' . $id);
    error_log('BIRMS verification record failed: ' . $e->getMessage());
    flash_add('danger', 'The verification record could not be saved. No confirmation was recorded; check the application log.');
    redirect('residents/view.php', ['id' => $id]);
}

flash_add('success', 'Verification saved to the resident history. This records a manual staff check; it does not contact COMELEC or another external database.');
redirect('residents/view.php', ['id' => $id]);
