<?php
/** Issue a certificate for a Ready request using approved template/fee settings and its saved fee quote. */
declare(strict_types=1);
require_once __DIR__ . '/../../app/bootstrap.php';

require_login();
require_permission('certificates.issue');
$rid = get_int('request') ?? post_int('request_id');
$errors = [];

if ($rid === null) {
    $ready = db_all("SELECT dr.request_id, dr.reference_no, dr.requested_at, r.last_name, r.first_name, r.middle_name, r.suffix, dt.name AS type_name
        FROM document_requests dr JOIN residents r ON r.resident_id = dr.resident_id JOIN document_types dt ON dt.type_id = dr.type_id WHERE dr.status = 'Ready' ORDER BY dr.requested_at, dr.request_id LIMIT 100");
    render_header('Certificate Generation', [['Certificate Generation', null]], 'certs');
    page_head('Certificate Generation', 'Requests that are approved and ready to be issued.');
    echo '<div class="card-b">';
    if (!$ready) { echo empty_state('bi-file-earmark-check', 'No request is ready for issuance. Approved requests appear here.'); }
    else {
        echo '<div class="table-responsive"><table class="table table-b"><thead><tr><th>Reference</th><th>Resident</th><th>Document</th><th>Submitted</th><th></th></tr></thead><tbody>';
        foreach ($ready as $r) { echo '<tr><td class="fw-semibold">' . e((string) $r['reference_no']) . '</td><td>' . e(resident_name($r)) . '</td><td>' . e((string) $r['type_name']) . '</td><td>' . e(fmt_dt((string) $r['requested_at'])) . '</td><td class="text-end">' . btn_link('certificates/issue.php?request=' . (int) $r['request_id'], 'Issue', 'btn-sm btn-primary') . '</td></tr>'; }
        echo '</tbody></table></div>';
    }
    echo '</div>'; render_footer(); exit;
}

$r = issuable_request($rid);
if ($r === null) { abort(404, 'That request was not found.'); }
$existing = db_one('SELECT issuance_id FROM issuances WHERE request_id = ?', [$rid]);
$vars = certificate_vars($r); $blockers = $r['status'] === 'Ready' ? certificate_blockers($r, $vars) : ['This request is ' . $r['status'] . ', not Ready, so it cannot be issued.'];
$or = ''; $paymentMethod = 'cash'; $signatureText = trim((string) ($vars['captain_name'] ?? ''));

if (is_post()) {
    require_csrf();
    $or = post_str('or_number');
    $paymentMethod = post_str('payment_method') !== '' ? post_str('payment_method') : 'cash';
    $signatureText = trim(post_str('signature_text'));
    if ($signatureText === '') { $signatureText = trim((string) ($vars['captain_name'] ?? '')); }
    if (!in_array($paymentMethod, ['cash', 'bank_transfer', 'other'], true)) { $errors[] = 'Choose a valid payment method.'; }
    if (str_len($signatureText) > 150) { $errors[] = 'Typed signature is limited to 150 characters.'; }
    if ($blockers) { $errors = $blockers; }
    else {
        $fee = (float) $r['fee_amount'];
        if ($fee > 0 && $or === '') { $errors[] = 'Enter the Official Receipt (O.R.) number for this payment.'; }
        if ($or !== '' && !v_or_number($or)) { $errors[] = 'O.R. number may contain letters, numbers and dashes only (max 30).'; }
        elseif ($or !== '' && db_val('SELECT COUNT(*) FROM issuances WHERE or_number = ?', [$or])) { $errors[] = 'That O.R. number was already used for another certificate.'; }
    }
    if (!$errors) {
        try {
            $iid = db_transaction(function () use ($rid, $or, $paymentMethod, $signatureText): int {
                $x = issuable_request($rid);                                    // fresh read inside the transaction
                if ($x === null || $x['status'] !== 'Ready') { throw new RuntimeException('state'); }
                $v = certificate_vars($x);
                if (certificate_blockers($x, $v)) { throw new RuntimeException('blocked'); }
                $n = db_exec("UPDATE document_requests SET status = 'Claimed', status_note = NULL WHERE request_id = ? AND status = 'Ready'", [$rid]);
                if ($n !== 1) { throw new RuntimeException('state'); }
                $y = (int) date('Y'); $ref = sprintf('CERT-%d-%04d', $y, next_counter('certificate', $y));
                $x['reference_no'] = $ref; $v = certificate_vars($x);
                $body = render_template((string) $x['template_body'], $v);
                $actor = audit_actor();
                db_exec('INSERT INTO issuances
                         (reference_no, request_id, resident_id, type_id, resident_name, type_name, purpose, fee_amount,
                          fee_rule_id, fee_category_snapshot, fee_tier_snapshot, fee_quantity, fee_unit_rate,
                          or_number, body_text, captain_name, signature_text, issued_by, issued_by_name, issued_at)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())',
                    [$ref, $rid, $x['resident_id'], $x['type_id'], resident_name_natural($x), $x['type_name'], $x['purpose'], $x['fee_amount'],
                     $x['fee_rule_id'] ?? null, $x['fee_category_snapshot'] ?? null, $x['fee_tier_snapshot'] ?? null,
                     $x['fee_quantity'] ?? 1, $x['fee_unit_rate'] ?? null,
                     $or !== '' ? $or : null, $body, $v['captain_name'] !== '' ? $v['captain_name'] : null, $signatureText !== '' ? $signatureText : null, $actor['user_id'] ?? null, (string) ($actor['full_name'] ?? 'system')]);
                // Capture the issuance ID before a cash-ledger insert changes PDO::lastInsertId().
                $iid = (int) db()->lastInsertId();
                if ((float) $x['fee_amount'] > 0) {
                    db_exec('INSERT INTO cash_collection_ledger
                             (issuance_id, transaction_date, requester_name, fee_amount, payment_method, collector_id, collector_name, reconciliation_status)
                             VALUES (?, NOW(), ?, ?, ?, ?, ?, \'pending\')',
                        [$iid, resident_name_natural($x), (float) $x['fee_amount'], $paymentMethod, $actor['user_id'] ?? null, (string) ($actor['full_name'] ?? 'system')]);
                }
                audit('certificate_issued', 'Certificates', 'Success', $ref . ' (' . $x['type_name'] . ') for ' . $x['last_name'] . ', ' . $x['first_name'] . '; fee ' . $x['fee_amount'] . ($or !== '' ? '; O.R. ' . $or : ''), 'issuance', $iid);
                audit('request_status_changed', 'Requests', 'Success', $x['reference_no'] . ' -> Claimed via ' . $ref, 'request', $rid);
                return $iid;
            });
            flash_add('success', 'Certificate issued.');
            redirect('certificates/print.php', ['id' => $iid]);
        } catch (RuntimeException $e) { $errors[] = 'This request changed while you were issuing it. Check its status and try again.'; }
        catch (PDOException $e) { if (str_contains($e->getMessage(), 'uq_issuance_or')) { $errors[] = 'That O.R. number was already used for another certificate.'; } else { throw $e; } }
    }
    audit_best_effort('certificate_refused', 'Certificates', 'Failed', $r['reference_no'] . ': ' . implode(' ', array_slice($errors, 0, 2)));
}

render_header('Issue certificate', [['Certificate Generation', 'certificates/issue.php'], [(string) $r['reference_no'], null]], 'certs');
page_head('Issue certificate', $r['type_name'] . ' for ' . resident_name($r) . ' · ' . $r['reference_no']);
echo errors_box($errors);
if ($existing) { echo '<div class="alert alert-info">A certificate was already issued for this request. ' . (can('certificates.history') ? '<a href="' . e(base_url('certificates/print.php?id=' . (int) $existing['issuance_id'])) . '">Open it</a>' : '') . '</div>'; }
elseif ($blockers && !is_post()) { echo errors_box($blockers); }
if (!$blockers && !$existing) {
    $preview = render_template((string) $r['template_body'], $vars);
    ?>
<div class="row g-3">
  <div class="col-lg-7"><div class="card-b"><div class="card-h"><h2>Preview of the wording</h2><p>Check the details before issuing. An issued certificate cannot be edited.</p></div><div class="card-body-b"><div class="alert alert-warning mb-2"><strong>DRAFT — PENDING OFFICIAL LAYOUT APPROVAL.</strong> Stakeholders must approve this wording and layout before the certificate is used as an official form.</div><div class="border rounded-3 p-3 bg-light cert-body"><?= e($preview) ?></div></div></div></div>
  <div class="col-lg-5"><div class="card-b"><div class="card-h"><h2>Payment</h2></div><div class="card-body-b">
    <p class="mb-2">Fee: <strong><?= e(money($r['fee_amount'])) ?></strong> <span class="text-muted small">(set by the Secretary, not editable here)</span></p>
    <form method="post" action="<?= e(base_url('certificates/issue.php?request=' . $rid)) ?>" class="needs-validation" novalidate data-confirm="Issue this certificate? This cannot be undone."><?= csrf_field() ?><input type="hidden" name="request_id" value="<?= (int) $rid ?>"><div class="row">
      <?= f_text('or_number', 'Official Receipt (O.R.) number', $or, ['maxlength' => 30, 'required' => (float) $r['fee_amount'] > 0, 'col' => 'col-12', 'help' => (float) $r['fee_amount'] > 0 ? 'Required because a fee is charged. Each O.R. number can be used only once.' : 'Optional: this document is free.']) ?>
      <?= f_select('payment_method', 'Payment method', ['cash' => 'Cash', 'bank_transfer' => 'Bank transfer', 'other' => 'Other'], $paymentMethod, ['required' => true, 'col' => 'col-12', 'help' => 'For paid issuances, a pending cash-collection ledger entry is created for reconciliation.']) ?>
      <?= f_text('signature_text', 'Typed signatory / signature name', $signatureText, ['maxlength' => 150, 'col' => 'col-12', 'help' => 'A typed name is not a cryptographically verified digital signature. This certificate remains marked as a draft until the official layout is approved.']) ?>
    </div><button class="btn btn-primary" type="submit"><i class="bi bi-file-earmark-check me-1"></i>Issue certificate</button></form>
  </div></div></div>
</div>
<?php }
render_footer();
