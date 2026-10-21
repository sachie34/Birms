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
$or = '';

if (is_post()) {
    require_csrf();
    $or = post_str('or_number');
    if ($blockers) { $errors = $blockers; }
    else {
        $fee = (float) $r['fee_amount'];
        if ($fee > 0 && $or === '') { $errors[] = 'Enter the Official Receipt (O.R.) number for this payment.'; }
        if ($or !== '' && !v_or_number($or)) { $errors[] = 'O.R. number may contain letters, numbers and dashes only (max 30).'; }
        elseif ($or !== '' && db_val('SELECT COUNT(*) FROM issuances WHERE or_number = ?', [$or])) { $errors[] = 'That O.R. number was already used for another certificate.'; }
    }
    if (!$errors) {
        try {
            $iid = db_transaction(function () use ($rid, $or): int {
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
                          or_number, body_text, captain_name, issued_by, issued_by_name, issued_at)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())',
                    [$ref, $rid, $x['resident_id'], $x['type_id'], resident_name_natural($x), $x['type_name'], $x['purpose'], $x['fee_amount'],
                     $x['fee_rule_id'] ?? null, $x['fee_category_snapshot'] ?? null, $x['fee_tier_snapshot'] ?? null,
                     $x['fee_quantity'] ?? 1, $x['fee_unit_rate'] ?? null,
                     $or !== '' ? $or : null, $body, $v['captain_name'] !== '' ? $v['captain_name'] : null, $actor['user_id'] ?? null, (string) ($actor['full_name'] ?? 'system')]);
                $iid = (int) db()->lastInsertId();
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
  <div class="col-lg-7"><div class="card-b"><div class="card-h"><h2>Preview of the wording</h2><p>Check the details before issuing. An issued certificate cannot be edited.</p></div><div class="card-body-b"><div class="border rounded-3 p-3 bg-light cert-body"><?= e($preview) ?></div></div></div></div>
  <div class="col-lg-5"><div class="card-b"><div class="card-h"><h2>Payment</h2></div><div class="card-body-b">
    <p class="mb-2">Fee: <strong><?= e(money($r['fee_amount'])) ?></strong> <span class="text-muted small">(set by the Secretary, not editable here)</span></p>
    <form method="post" action="<?= e(base_url('certificates/issue.php?request=' . $rid)) ?>" class="needs-validation" novalidate data-confirm="Issue this certificate? This cannot be undone."><?= csrf_field() ?><input type="hidden" name="request_id" value="<?= (int) $rid ?>"><div class="row">
      <?= f_text('or_number', 'Official Receipt (O.R.) number', $or, ['maxlength' => 30, 'required' => (float) $r['fee_amount'] > 0, 'col' => 'col-12', 'help' => (float) $r['fee_amount'] > 0 ? 'Required because a fee is charged. Each O.R. number can be used only once.' : 'Optional: this document is free.']) ?>
    </div><button class="btn btn-primary" type="submit"><i class="bi bi-file-earmark-check me-1"></i>Issue certificate</button></form>
  </div></div></div>
</div>
<?php }
render_footer();
