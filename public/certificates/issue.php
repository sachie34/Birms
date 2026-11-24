<?php
/** Release an automatically generated certificate draft for an approved request. */
declare(strict_types=1);
require_once __DIR__ . '/../../app/bootstrap.php';

require_login();
require_permission('certificates.issue');
$rid = get_int('request') ?? post_int('request_id');
$errors = [];

if ($rid === null) {
    $ready = db_all("SELECT dr.request_id, dr.reference_no, dr.requested_at, r.last_name, r.first_name, r.middle_name, r.suffix, dt.name AS type_name
        FROM document_requests dr JOIN residents r ON r.resident_id = dr.resident_id JOIN document_types dt ON dt.type_id = dr.type_id
        WHERE dr.status = 'Ready' ORDER BY dr.requested_at, dr.request_id LIMIT 100");
    render_header('Certificate Generation', [['Certificate Generation', null]], 'certs');
    page_head('Certificate Generation', 'Approved requests with automatically generated certificate drafts ready for Staff release.');
    echo '<div class="card-b">';
    if (!$ready) { echo empty_state('bi-file-earmark-check', 'No approved request is waiting for certificate release.'); }
    else {
        echo '<div class="table-responsive"><table class="table table-b"><thead><tr><th>Reference</th><th>Resident</th><th>Document</th><th>Submitted</th><th></th></tr></thead><tbody>';
        foreach ($ready as $row) {
            echo '<tr><td class="fw-semibold">' . e((string) $row['reference_no']) . '</td><td>' . e(resident_name($row)) . '</td><td>' . e((string) $row['type_name']) . '</td><td>' . e(fmt_dt((string) $row['requested_at'])) . '</td><td class="text-end">' . btn_link('certificates/issue.php?request=' . (int) $row['request_id'], 'Review draft', 'btn-sm btn-primary') . '</td></tr>';
        }
        echo '</tbody></table></div>';
    }
    echo '</div>'; render_footer(); exit;
}

$r = issuable_request($rid);
if ($r === null) { abort(404, 'That request was not found.'); }
$existing = db_one('SELECT issuance_id FROM issuances WHERE request_id = ?', [$rid]);
$draft = certificate_draft_for_request($rid);

// Compatibility for requests that were already Ready before migration 019 was applied.
// This creates only a draft; it never issues the certificate or records payment.
if ($r['status'] === 'Ready' && $existing === null && $draft === null) {
    try {
        $draft = db_transaction(static fn(): array => certificate_draft_create($rid));
    } catch (Throwable $draftError) {
        $errors[] = $draftError instanceof InvalidArgumentException
            ? $draftError->getMessage()
            : 'The certificate draft could not be generated. No issuance or payment was recorded. Check the application error log.';
        if (!$draftError instanceof InvalidArgumentException) {
            error_log('BIRMS legacy certificate draft generation failed for request ' . $rid . ': ' . get_class($draftError) . ': ' . $draftError->getMessage());
        }
    }
}

$or = '';
$paymentMethod = 'cash';
$signaturePathValid = $draft !== null
    && (empty($draft['chairman_signature_path']) || chairman_signature_read((string) $draft['chairman_signature_path']) !== null);
$canRelease = $r['status'] === 'Ready' && $draft !== null && $signaturePathValid && $existing === null;
if ($draft !== null && !$signaturePathValid) {
    $errors[] = 'The Chairman signature snapshot for this draft is missing or invalid. Restore the matching private signature file before releasing the certificate.';
}
if ($r['status'] !== 'Ready' && $existing === null) {
    $errors[] = 'This request is no longer approved and ready for release.';
}

if (is_post()) {
    require_csrf();
    $or = post_str('or_number');
    $paymentMethod = post_str('payment_method') !== '' ? post_str('payment_method') : 'cash';
    if (!in_array($paymentMethod, ['cash', 'bank_transfer', 'other'], true)) { $errors[] = 'Choose a valid payment method.'; }
    if (!$canRelease) { $errors[] = 'A valid approved certificate draft is required before release.'; }
    if ($draft !== null) {
        $fee = (float) $draft['fee_amount'];
        if ($fee > 0 && $or === '') { $errors[] = 'Enter the Official Receipt (O.R.) number for this payment.'; }
        if ($or !== '' && !v_or_number($or)) { $errors[] = 'O.R. number may contain letters, numbers and dashes only (max 30).'; }
        elseif ($or !== '' && db_val('SELECT COUNT(*) FROM issuances WHERE or_number = ?', [$or])) { $errors[] = 'That O.R. number was already used for another certificate.'; }
    }

    if (!$errors) {
        try {
            $iid = db_transaction(function () use ($rid, $or, $paymentMethod): int {
                $draftRow = db_one('SELECT * FROM certificate_drafts WHERE request_id = ? FOR UPDATE', [$rid]);
                $requestRow = issuable_request($rid);
                if ($requestRow === null || $requestRow['status'] !== 'Ready' || $draftRow === null) {
                    throw new RuntimeException('state');
                }
                if (!empty($draftRow['chairman_signature_path'])
                    && chairman_signature_read((string) $draftRow['chairman_signature_path']) === null) {
                    throw new RuntimeException('The saved Chairman signature image is unavailable. Restore the private signature file before release.');
                }
                $n = db_exec("UPDATE document_requests SET status = 'Claimed', msit_status = 'Released', status_note = NULL WHERE request_id = ? AND status = 'Ready'", [$rid]);
                if ($n !== 1) { throw new RuntimeException('state'); }

                $reference = (string) $draftRow['certificate_reference_no'];
                if (preg_match('/^CERT-\\d{4}-\\d{4,}$/', $reference) !== 1) {
                    throw new RuntimeException('The saved certificate reference is invalid. The draft remains available for administrator review.');
                }
                $actor = audit_actor();
                db_exec(
                    'INSERT INTO issuances
                     (reference_no, request_id, resident_id, type_id, resident_name, type_name, purpose, fee_amount,
                      fee_rule_id, fee_category_snapshot, fee_tier_snapshot, fee_quantity, fee_unit_rate,
                      or_number, body_text, captain_name, signature_text, chairman_signature_path, issued_by, issued_by_name, issued_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())',
                    [
                        $reference, $rid, $draftRow['resident_id'], $draftRow['type_id'],
                        $draftRow['resident_name'], $draftRow['type_name'], $draftRow['purpose'], $draftRow['fee_amount'],
                        $draftRow['fee_rule_id'], $draftRow['fee_category_snapshot'], $draftRow['fee_tier_snapshot'],
                        $draftRow['fee_quantity'], $draftRow['fee_unit_rate'], $or !== '' ? $or : null,
                        $draftRow['body_text'], $draftRow['captain_name'], $draftRow['signature_text'],
                        $draftRow['chairman_signature_path'], $actor['user_id'] ?? null,
                        (string) ($actor['full_name'] ?? 'system'),
                    ]
                );
                $issuanceId = (int) db()->lastInsertId();

                if ((float) $draftRow['fee_amount'] > 0) {
                    db_exec(
                        'INSERT INTO cash_collection_ledger
                         (issuance_id, transaction_date, requester_name, fee_amount, payment_method, collector_id, collector_name, reconciliation_status)
                         VALUES (?, NOW(), ?, ?, ?, ?, ?, \'pending\')',
                        [$issuanceId, $draftRow['resident_name'], (float) $draftRow['fee_amount'], $paymentMethod,
                         $actor['user_id'] ?? null, (string) ($actor['full_name'] ?? 'system')]
                    );
                }

                db_exec('DELETE FROM certificate_drafts WHERE draft_id = ?', [(int) $draftRow['draft_id']]);
                audit('certificate_issued', 'Certificates', 'Success',
                    $reference . ' (' . $draftRow['type_name'] . ') for ' . $draftRow['resident_name']
                    . '; fee ' . $draftRow['fee_amount'] . ($or !== '' ? '; O.R. ' . $or : ''),
                    'issuance', $issuanceId);
                db_exec(
                    "INSERT INTO request_status_history
                     (service, reference_no, previous_status, new_status, changed_by, note, request_id)
                     VALUES ('Documents', ?, 'Approved', 'Released', ?, ?, ?)",
                    [$draftRow['request_reference_no'], $actor['user_id'] ?? null,
                     'Certificate released: ' . $reference, $rid]
                );
                audit('request_status_changed', 'Requests', 'Success',
                    $draftRow['request_reference_no'] . ' -> Released via ' . $reference, 'request', $rid);
                return $issuanceId;
            });

            flash_add('success', 'The generated certificate draft has been released and recorded.');
            try {
                resident_notify_status_change(
                    (int) $r['resident_id'],
                    'Documents',
                    (string) $r['reference_no'],
                    'Released',
                    'document_request_status_changed',
                    'Your approved certificate has been generated and is ready for release.'
                );
            } catch (Throwable $notificationError) {
                error_log('BIRMS certificate-release notification failed for ' . (string) $r['reference_no'] . ': ' . get_class($notificationError) . ': ' . $notificationError->getMessage());
                audit_best_effort('resident_notification_failed', 'Notifications', 'Failed',
                    'Document request ' . (string) $r['reference_no'] . ' was released but the resident notification could not be queued.');
            }
            redirect('certificates/print.php', ['id' => $iid]);
        } catch (PDOException $e) {
            if (str_contains($e->getMessage(), 'uq_issuance_or')) {
                $errors[] = 'That O.R. number was already used for another certificate.';
            } else {
                error_log('BIRMS certificate release database error: ' . $e->getMessage());
                $errors[] = 'The certificate could not be released due to a database error. The draft remains available; try again or contact Admin.';
            }
        } catch (RuntimeException $e) {
            $errors[] = $e->getMessage() === 'state'
                ? 'This request changed while you were releasing it. Check its current status and try again.'
                : $e->getMessage();
        }
    }
    if ($errors) {
        audit_best_effort('certificate_release_refused', 'Certificates', 'Failed',
            (string) $r['reference_no'] . ': ' . implode(' ', array_slice($errors, 0, 2)));
    }
}

render_header('Review certificate draft', [['Certificate Generation', 'certificates/issue.php'], [(string) $r['reference_no'], null]], 'certs');
page_head('Review certificate draft', $r['type_name'] . ' for ' . resident_name($r) . ' · ' . $r['reference_no']);
echo errors_box($errors);
if ($existing) {
    echo '<div class="alert alert-info">A certificate has already been released for this request. ' . (can('certificates.history') ? '<a href="' . e(base_url('certificates/print.php?id=' . (int) $existing['issuance_id'])) . '">Open it</a>' : '') . '</div>';
} elseif ($draft !== null && $canRelease) {
    ?>
<div class="row g-3">
  <div class="col-lg-7"><div class="card-b"><div class="card-h"><h2>Automatically generated certificate</h2><p>Generated when Staff approved the request. Review the saved resident details and wording before release.</p></div><div class="card-body-b"><div class="alert alert-warning mb-2"><strong>DRAFT — PENDING OFFICIAL LAYOUT APPROVAL.</strong> Stakeholders must approve the wording and layout before this document is used as an official form.</div><div class="border rounded-3 p-3 bg-light cert-body"><?= e((string) $draft['body_text']) ?></div><div class="small text-muted mt-2">Draft generated <?= e(fmt_dt((string) $draft['generated_at'])) ?> by <?= e((string) $draft['generated_by_name']) ?>. This saved draft will not change if the resident record or template is edited later.</div><div class="mt-3"><?= btn_link('certificates/draft_pdf.php?request=' . $rid, 'Open PDF draft', 'btn-light border', 'bi-file-earmark-pdf') ?></div></div></div></div>
  <div class="col-lg-5"><div class="card-b"><div class="card-h"><h2>Release and payment record</h2></div><div class="card-body-b">
    <p class="mb-2">Fee snapshot: <strong><?= e(money($draft['fee_amount'])) ?></strong> <span class="text-muted small">(captured when the certificate draft was generated)</span></p>
    <form method="post" action="<?= e(base_url('certificates/issue.php?request=' . $rid)) ?>" class="needs-validation" novalidate data-confirm="Release this generated certificate? This records the issuance and cannot be undone."><?= csrf_field() ?><input type="hidden" name="request_id" value="<?= (int) $rid ?>"><div class="row">
      <?= f_text('or_number', 'Official Receipt (O.R.) number', $or, ['maxlength' => 30, 'required' => (float) $draft['fee_amount'] > 0, 'col' => 'col-12', 'help' => (float) $draft['fee_amount'] > 0 ? 'Required because a fee is charged. Each O.R. number can be used only once.' : 'Optional: this document is free.']) ?>
      <?= f_select('payment_method', 'Payment method', ['cash' => 'Cash', 'bank_transfer' => 'Bank transfer', 'other' => 'Other'], $paymentMethod, ['required' => true, 'col' => 'col-12', 'help' => 'For paid issuances, a pending cash-collection ledger entry is created for reconciliation.']) ?>
      <div class="col-12"><div class="alert alert-info mb-2">The Chairman / Punong Barangay signature saved with this draft will be applied automatically. Staff cannot replace it while releasing the certificate.</div></div>
    </div><button class="btn btn-primary" type="submit"><i class="bi bi-file-earmark-check me-1"></i>Release certificate</button></form>
  </div></div></div>
</div>
<?php
} elseif ($draft === null && !$existing) {
    echo '<div class="alert alert-warning">No certificate draft is available. Resolve the generation blockers shown above before approval can be completed.</div>';
}
render_footer();
