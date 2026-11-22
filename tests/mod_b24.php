<?php
/** B24: end-to-end resident document request, office handoffs, issuance and public tracking. */
declare(strict_types=1);

require_once __DIR__ . '/../app/certificates_lib.php';

function run_b24(array $S, string $base): void
{
    $staff = $S['clerk'];
    $secretary = $S['secretary'];
    $chairman = $S['captain'];
    $admin = $S['admin'];

    $suffix = strtoupper(bin2hex(random_bytes(4)));
    $residentNo = 'RES-B24-' . $suffix;
    $residentEmail = 'b24-' . strtolower($suffix) . '@example.test';
    $purpose = 'B24 end-to-end document request ' . $suffix;
    $documentName = 'ZZ B24 Clearance ' . $suffix;
    $residentId = 0;
    $requestId = 0;
    $issuanceId = 0;
    $reference = '';
    $signatureFixture = null;
    $oldCaptainName = db_val("SELECT setting_value FROM settings WHERE setting_key = 'captain_name'");

    try {
        $statusId = (int) db_val("SELECT lookup_id FROM lookups WHERE list_key = 'resident_status' AND label = 'Active' LIMIT 1");
        $residentTypeId = (int) db_val("SELECT lookup_id FROM lookups WHERE list_key = 'resident_type' AND label = 'None' LIMIT 1");
        $purok = db_one("SELECT lookup_id FROM lookups WHERE list_key = 'purok' AND is_active = 1 ORDER BY sort_order, lookup_id LIMIT 1");
        if ($statusId < 1 || $residentTypeId < 1) {
            throw new RuntimeException('Required Active resident status or None resident type fixture is missing.');
        }

        db_exec(
            "INSERT INTO residents
             (resident_no, address, purok_id, resident_status_id, marital_status, resident_type_id,
              last_name, first_name, birthdate, sex, civil_status, contact_number, email_address,
              notification_preference, residency_status, household_id, is_household_head, archived_at)
             VALUES (?, 'B24 Test Street', ?, ?, 'Single', ?, 'B24TestResident', 'Jamie', '1991-04-18',
                     'Male', 'Single', '09171230024', ?, 'email', 'Active', NULL, 0, NULL)",
            [$residentNo, $purok['lookup_id'] ?? null, $statusId, $residentTypeId, $residentEmail]
        );
        $residentId = (int) db()->lastInsertId();

        db_exec(
            'INSERT INTO document_types (name, fee_amount, template_body, is_active, sort_order)
             VALUES (?, 0.00, ?, 1, 9998)',
            [$documentName, 'This certifies {{full_name}} for {{purpose}}.']
        );
        $documentTypeId = (int) db()->lastInsertId();

        // Set up the approved signatory configuration before issuance.
        $signatureFixture = fx_upload_chairman_signature($admin);
        db_exec("UPDATE settings SET setting_value = 'B24 Test Chairman' WHERE setting_key = 'captain_name'");

        // 1. Resident identifies at the public kiosk and submits a document request.
        $kiosk = new Http($base);
        $identity = post_as($kiosk, '/kiosk/index.php', '/kiosk/identify.php', [
            'resident_no' => $residentNo,
            'birthdate' => '1991-04-18',
            'next' => 'home',
        ]);
        $home = $kiosk->get('/kiosk/index.php');
        $requestForm = $kiosk->get('/kiosk/request.php');
        record(
            'B24-01',
            'Resident can identify at the kiosk and open the document request form',
            $identity['status'] === 302
            && $home['status'] === 200
            && str_contains($home['body'], 'IDENTITY VERIFIED')
            && $requestForm['status'] === 200
            && str_contains($requestForm['body'], 'Request a document')
        );

        $submitted = post_as($kiosk, '/kiosk/request.php', '/kiosk/request.php', [
            'type_id' => (string) $documentTypeId,
            'purpose' => $purpose,
            'notification_preference' => 'email',
        ]);
        $requestRow = db_one(
            'SELECT request_id, reference_no, status, msit_status, requirements_status, resident_id
             FROM document_requests WHERE resident_id = ? AND purpose = ? ORDER BY request_id DESC LIMIT 1',
            [$residentId, $purpose]
        );
        $requestId = (int) ($requestRow['request_id'] ?? 0);
        $reference = (string) ($requestRow['reference_no'] ?? '');
        $queueRow = $reference !== '' ? db_one(
            "SELECT queue_id, service, queue_number, status, reference_no
             FROM queue_entries WHERE reference_no = ? AND service = 'Documents' ORDER BY queue_id DESC LIMIT 1",
            [$reference]
        ) : null;
        $receipt = $kiosk->get('/kiosk/request_receipt.php');
        record(
            'B24-02',
            'Kiosk submission saves a Pending request, unique reference, Documents queue ticket and resident receipt',
            $submitted['status'] === 302
            && str_contains(loc($submitted), 'request_receipt.php')
            && $requestRow !== null
            && preg_match('/^REQ-' . date('Y') . '-\\d{4,}$/', $reference) === 1
            && $requestRow['status'] === 'Pending'
            && $requestRow['msit_status'] === 'Pending'
            && $requestRow['requirements_status'] === 'Not checked'
            && $queueRow !== null
            && $queueRow['service'] === 'Documents'
            && $queueRow['status'] === 'Waiting'
            && $receipt['status'] === 200
            && str_contains($receipt['body'], $reference)
            && str_contains($receipt['body'], '#' . (int) $queueRow['queue_number'])
        );

        // 2. Secretary owns requirements review; Staff cannot start it or pre-complete it.
        $pendingForm = $secretary->get('/requests/form.php?id=' . $requestId);
        $requirementsLocked = preg_match('~<select[^>]*name="requirements_status"[^>]*disabled~', $pendingForm['body']) === 1
            && str_contains($pendingForm['body'], 'Start formal review from the request details first');
        $staffReviewAttempt = post_as($staff, '/requests/view.php?id=' . $requestId, '/requests/action.php', [
            'id' => (string) $requestId, 'to' => 'Review', 'note' => '',
        ]);
        $secretaryReview = post_as($secretary, '/requests/view.php?id=' . $requestId, '/requests/action.php', [
            'id' => (string) $requestId, 'to' => 'Review', 'note' => '',
        ]);
        $reviewForm = $secretary->get('/requests/form.php?id=' . $requestId);
        $requirementsUnlocked = $reviewForm['status'] === 200
            && preg_match('~<select[^>]*name="requirements_status"[^>]*disabled~', $reviewForm['body']) !== 1;
        $afterReview = db_one('SELECT status, requirements_status FROM document_requests WHERE request_id = ?', [$requestId]);
        record(
            'B24-03',
            'Secretary starts the formal review; unassigned Staff access is hidden and requirements fields unlock only after review starts',
            $requirementsLocked
            && $staffReviewAttempt['status'] === 404
            && $secretaryReview['status'] === 302
            && $afterReview !== null
            && $afterReview['status'] === 'Review'
            && $afterReview['requirements_status'] === 'Not checked'
            && $requirementsUnlocked,
            'pending-form=' . $pendingForm['status'] . '; locked=' . ($requirementsLocked ? 'yes' : 'no')
            . '; staff-review=' . $staffReviewAttempt['status'] . '; secretary-review=' . $secretaryReview['status']
            . '; after=' . json_encode($afterReview) . '; review-form=' . $reviewForm['status']
            . '; unlocked=' . ($requirementsUnlocked ? 'yes' : 'no')
        );

        $staffEarlyApproval = post_as($staff, '/requests/view.php?id=' . $requestId, '/requests/action.php', [
            'id' => (string) $requestId, 'to' => 'Ready', 'note' => '',
        ]);
        $earlyStatus = (string) db_val('SELECT status FROM document_requests WHERE request_id = ?', [$requestId]);
        $completeRequirements = post_as($secretary, '/requests/form.php?id=' . $requestId, '/requests/form.php?id=' . $requestId, [
            'resident_id' => (string) $residentId,
            'type_id' => (string) $documentTypeId,
            'purpose' => $purpose,
            'assigned_to' => '',
            'requirements_status' => 'Complete',
            'requirements_note' => '',
            'fee_rule_id' => '',
            'fee_quantity' => '1',
        ]);
        $requirementsSaved = db_val('SELECT requirements_status FROM document_requests WHERE request_id = ?', [$requestId]) === 'Complete';
        $staffApproval = post_as($staff, '/requests/view.php?id=' . $requestId, '/requests/action.php', [
            'id' => (string) $requestId, 'to' => 'Ready', 'note' => '',
        ]);
        $approved = db_one('SELECT status, msit_status FROM document_requests WHERE request_id = ?', [$requestId]);
        $generatedDraft = db_one('SELECT draft_id, certificate_reference_no, resident_name, body_text, chairman_signature_path, generated_at FROM certificate_drafts WHERE request_id = ?', [$requestId]);
        $prematureIssuances = (int) db_val('SELECT COUNT(*) FROM issuances WHERE request_id = ?', [$requestId]);
        $prematureLedger = (int) db_val('SELECT COUNT(*) FROM cash_collection_ledger WHERE issuance_id IN (SELECT issuance_id FROM issuances WHERE request_id = ?)', [$requestId]);
        $approvalNotice = db_one(
            "SELECT recipient, channel, delivery_status, message FROM notification_outbox
             WHERE resident_id = ? AND reference_no = ? AND event_type = 'document_request_status_changed'
             ORDER BY notification_id DESC LIMIT 1",
            [$residentId, $reference]
        );
        $draftPdf = $staff->get('/certificates/draft_pdf.php?request=' . $requestId);
        $secretaryDraftPdf = $secretary->get('/certificates/draft_pdf.php?request=' . $requestId);
        $chairmanDraftPdf = $chairman->get('/certificates/draft_pdf.php?request=' . $requestId);
        $postPreviewIssuances = (int) db_val('SELECT COUNT(*) FROM issuances WHERE request_id = ?', [$requestId]);
        $postPreviewLedger = (int) db_val('SELECT COUNT(*) FROM cash_collection_ledger WHERE issuance_id IN (SELECT issuance_id FROM issuances WHERE request_id = ?)', [$requestId]);
        record(
            'B24-04',
            'Staff cannot access the unassigned request before requirements are complete; after Secretary completes them, Staff approves and the resident is notified',
            $staffEarlyApproval['status'] === 404
            && $earlyStatus === 'Review'
            && $completeRequirements['status'] === 302
            && $requirementsSaved
            && $staffApproval['status'] === 302
            && $approved !== null
            && $approved['status'] === 'Ready'
            && $approved['msit_status'] === 'Approved'
            && $generatedDraft !== null
            && preg_match('/^CERT-' . date('Y') . '-\\d{4,}$/', (string) $generatedDraft['certificate_reference_no']) === 1
            && str_contains((string) $generatedDraft['body_text'], (string) $generatedDraft['certificate_reference_no'])
            && stripos((string) $generatedDraft['resident_name'], 'B24TESTRESIDENT') !== false
            && str_contains((string) $generatedDraft['body_text'], 'B24TESTRESIDENT')
            && !empty($generatedDraft['chairman_signature_path'])
            && $prematureIssuances === 0
            && $prematureLedger === 0
            && $draftPdf['status'] === 200
            && str_starts_with((string) $draftPdf['body'], '%PDF-1.4')
            && $secretaryDraftPdf['status'] === 403
            && $chairmanDraftPdf['status'] === 403
            && $postPreviewIssuances === 0
            && $postPreviewLedger === 0
            && $approvalNotice !== null
            && $approvalNotice['recipient'] === $residentEmail
            && $approvalNotice['channel'] === 'email'
            && in_array($approvalNotice['delivery_status'], ['queued', 'sent'], true)
            && str_contains((string) $approvalNotice['message'], 'Approved'),
            'early=' . $staffEarlyApproval['status'] . '; state-after-early=' . $earlyStatus
            . '; requirements-post=' . $completeRequirements['status'] . '; saved-complete=' . ($requirementsSaved ? 'yes' : 'no')
            . '; staff-approve=' . $staffApproval['status'] . '; approved=' . json_encode($approved)
            . '; draft=' . json_encode($generatedDraft) . '; premature-issuances=' . $prematureIssuances
            . '; premature-ledger=' . $prematureLedger . '; draft-pdf=' . $draftPdf['status']
            . '; secretary-pdf=' . $secretaryDraftPdf['status'] . '; chairman-pdf=' . $chairmanDraftPdf['status']
            . '; post-preview-issuances=' . $postPreviewIssuances . '; post-preview-ledger=' . $postPreviewLedger
            . '; notice=' . json_encode($approvalNotice)
        );

        // 3. The public reference tracker reveals only the service/status, not resident PII.
        $publicBeforeRelease = $kiosk->get('/kiosk/status.php?ref=' . rawurlencode($reference));
        $beforeHistoryItems = substr_count($publicBeforeRelease['body'], '<li><strong>');
        record(
            'B24-05',
            'Public tracker shows Approved once, omits the internal Pending-to-Pending review handoff and exposes no resident personal information',
            $publicBeforeRelease['status'] === 200
            && str_contains($publicBeforeRelease['body'], $reference)
            && str_contains($publicBeforeRelease['body'], 'Approved')
            && $beforeHistoryItems === 2
            && !str_contains($publicBeforeRelease['body'], $residentNo)
            && !str_contains($publicBeforeRelease['body'], 'B24TestResident')
            && !str_contains($publicBeforeRelease['body'], $residentEmail)
            && !str_contains($publicBeforeRelease['body'], 'B24 Test Street')
            && !str_contains($publicBeforeRelease['body'], $purpose)
        );

        // 4. Staff generates/issues the approved certificate; the issuance and Released handoff are atomic.
        $issuePage = $staff->get('/certificates/issue.php?request=' . $requestId);
        $secretaryIssue = $secretary->get('/certificates/issue.php?request=' . $requestId);
        $issued = post_as($staff, '/certificates/issue.php?request=' . $requestId, '/certificates/issue.php?request=' . $requestId, [
            'request_id' => (string) $requestId,
            'or_number' => '',
            'payment_method' => 'cash',
        ]);
        $issuance = db_one('SELECT issuance_id, reference_no, request_id, body_text, chairman_signature_path FROM issuances WHERE request_id = ?', [$requestId]);
        $issuanceId = (int) ($issuance['issuance_id'] ?? 0);
        $released = db_one('SELECT status, msit_status FROM document_requests WHERE request_id = ?', [$requestId]);
        $draftAfterRelease = db_one('SELECT draft_id FROM certificate_drafts WHERE request_id = ?', [$requestId]);
        $releaseNotice = db_one(
            "SELECT recipient, channel, delivery_status, message FROM notification_outbox
             WHERE resident_id = ? AND reference_no = ? AND event_type = 'document_request_status_changed'
             ORDER BY notification_id DESC LIMIT 1",
            [$residentId, $reference]
        );
        $releaseHistory = db_one(
            "SELECT previous_status, new_status FROM request_status_history
             WHERE request_id = ? AND service = 'Documents' AND previous_status = 'Approved' AND new_status = 'Released'
             ORDER BY history_id DESC LIMIT 1",
            [$requestId]
        );
        record(
            'B24-06',
            'Only Staff/Admin can issue; Staff issuance generates a certificate, snapshots the Chairman signature and moves the request to Released',
            $issuePage['status'] === 200
            && str_contains($issuePage['body'], 'Release certificate')
            && $secretaryIssue['status'] === 403
            && $issued['status'] === 302
            && str_contains(loc($issued), 'print.php')
            && $issuance !== null
            && preg_match('/^CERT-' . date('Y') . '-\\d{4,}$/', (string) $issuance['reference_no']) === 1
            && str_contains((string) $issuance['body_text'], 'JAMIE')
            && str_contains((string) $issuance['body_text'], 'B24TESTRESIDENT')
            && !empty($issuance['chairman_signature_path'])
            && $released !== null
            && $released['status'] === 'Claimed'
            && $released['msit_status'] === 'Released'
            && $draftAfterRelease === null
            && $releaseHistory !== null
            && $releaseNotice !== null
            && $releaseNotice['recipient'] === $residentEmail
            && in_array($releaseNotice['delivery_status'], ['queued', 'sent'], true)
            && str_contains((string) $releaseNotice['message'], 'Released'),
            'issue-page=' . $issuePage['status'] . '; secretary-issue=' . $secretaryIssue['status']
            . '; issue-post=' . $issued['status'] . '; location=' . (string) loc($issued)
            . '; issuance=' . json_encode($issuance) . '; released=' . json_encode($released)
            . '; release-history=' . json_encode($releaseHistory) . '; release-notice=' . json_encode($releaseNotice)
        );

        $publicAfterRelease = $kiosk->get('/kiosk/status.php?ref=' . rawurlencode($reference));
        $afterHistoryItems = substr_count($publicAfterRelease['body'], '<li><strong>');
        $pdf = $issuanceId > 0 ? $staff->get('/certificates/pdf.php?id=' . $issuanceId) : ['status' => 0, 'body' => ''];
        record(
            'B24-07',
            'Resident tracker shows the complete Pending → Approved → Released timeline; issued PDF is valid',
            $publicAfterRelease['status'] === 200
            && str_contains($publicAfterRelease['body'], 'Released')
            && $afterHistoryItems === 3
            && $pdf['status'] === 200
            && str_starts_with($pdf['body'], '%PDF-')
            && !str_contains($publicAfterRelease['body'], $residentEmail)
        );

        $historyCount = (int) db_val(
            "SELECT COUNT(*) FROM request_status_history
             WHERE request_id = ? AND service = 'Documents'
               AND (previous_status IS NULL OR previous_status <> new_status)",
            [$requestId]
        );
        record(
            'B24-08',
            'Document request and certificate release each retain a saved public status-history record',
            $historyCount === 3
            && (int) db_val("SELECT COUNT(*) FROM audit_log WHERE action = 'certificate_issued' AND entity_id = ?", [$issuanceId]) === 1
            && (int) db_val("SELECT COUNT(*) FROM audit_log WHERE action = 'request_status_changed' AND entity_id = ?", [$requestId]) >= 2
        );
    } catch (Throwable $e) {
        record('B24-EXCEPTION', 'End-to-end document lifecycle test completes without an exception', false,
            get_class($e) . ': ' . substr(preg_replace('/[\\r\\n]+/', ' ', $e->getMessage()) ?? '', 0, 350));
    } finally {
        if ($requestId > 0) {
            db_exec('DELETE FROM notification_outbox WHERE resident_id = ?', [$residentId]);
            db_exec('DELETE FROM request_status_history WHERE request_id = ?', [$requestId]);
            db_exec('DELETE FROM sms_outbox WHERE request_id = ?', [$requestId]);
            if ($issuanceId > 0) {
                db_exec('DELETE FROM cash_collection_ledger WHERE issuance_id = ?', [$issuanceId]);
                db_exec('DELETE FROM issuances WHERE issuance_id = ?', [$issuanceId]);
            }
            db_exec('DELETE FROM queue_entries WHERE reference_no = ?', [$reference]);
            db_exec('DELETE FROM document_attachments WHERE request_id = ?', [$requestId]);
            db_exec('DELETE FROM certificate_drafts WHERE request_id = ?', [$requestId]);
            db_exec('DELETE FROM document_requests WHERE request_id = ?', [$requestId]);
        }
        if ($residentId > 0) {
            db_exec('DELETE FROM resident_verification_challenges WHERE resident_id = ?', [$residentId]);
            db_exec('DELETE FROM residents WHERE resident_id = ?', [$residentId]);
        }
        db_exec('DELETE FROM document_types WHERE name = ?', [$documentName]);
        db_exec("UPDATE settings SET setting_value = ? WHERE setting_key = 'captain_name'", [$oldCaptainName]);
        if (is_array($signatureFixture)) { fx_restore_chairman_signature($signatureFixture); }
    }
}
