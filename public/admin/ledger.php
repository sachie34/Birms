<?php
/** Cash collection ledger: viewing is separate from administrator-only ledger changes. */
declare(strict_types=1);
require_once __DIR__ . '/../../app/bootstrap.php';

require_login();
require_permission('cash_ledger.view');
$canManage = can('cash_ledger.manage');
$actor = audit_actor();
$errors = [];
$methods = ['cash' => 'Cash', 'bank_transfer' => 'Bank transfer', 'other' => 'Other'];

if (is_post()) {
    require_csrf();
    require_permission('cash_ledger.manage');
    $do = post_str('do');
    $id = post_int('id');
    try {
        if ($do === 'create') {
            $issuanceId = post_int('issuance_id');
            $method = post_str('payment_method');
            $transactionDate = v_datetime_local(post_str('transaction_date'));
            $collectorName = trim(post_str('collector_name'));
            if ($issuanceId === null) { throw new RuntimeException('Choose an issued certificate.'); }
            if (!isset($methods[$method])) { throw new RuntimeException('Choose a valid payment method.'); }
            if ($transactionDate === null) { throw new RuntimeException('Enter the actual transaction date and time from the receipt or office record.'); }
            if (!v_len($collectorName, 2, 100)) { throw new RuntimeException('Enter the actual collector name (2-100 characters).'); }
            db_transaction(function () use ($issuanceId, $method, $transactionDate, $collectorName): void {
                $issuance = db_one('SELECT i.issuance_id, i.resident_name, i.fee_amount, i.or_number, i.reference_no
                    FROM issuances i LEFT JOIN cash_collection_ledger cc ON cc.issuance_id = i.issuance_id
                    WHERE i.issuance_id = ? AND cc.ledger_id IS NULL FOR UPDATE', [$issuanceId]);
                if ($issuance === null || (float) $issuance['fee_amount'] <= 0 || trim((string) ($issuance['or_number'] ?? '')) === '') {
                    throw new RuntimeException('Only a paid issuance with an O.R. and no existing ledger entry can be added.');
                }
                // Legacy backfills must identify the actual collector and transaction time, not the admin who enters the record.
                db_exec('INSERT INTO cash_collection_ledger
                    (issuance_id, transaction_date, requester_name, fee_amount, payment_method, collector_id, collector_name, reconciliation_status)
                    VALUES (?, ?, ?, ?, ?, NULL, ?, \'pending\')',
                    [(int) $issuance['issuance_id'], $transactionDate, (string) $issuance['resident_name'], (float) $issuance['fee_amount'], $method, $collectorName]);
                audit('cash_ledger_created', 'Cash Ledger', 'Success',
                    'Added missing ledger entry for ' . $issuance['reference_no'] . '; entered collector ' . $collectorName . '; transaction date ' . $transactionDate,
                    'cash_ledger', (int) db()->lastInsertId());
            });
            flash_add('success', 'Ledger transaction recorded as pending reconciliation.');
            redirect('admin/ledger.php');
        } elseif ($do === 'update') {
            if ($id === null) { throw new RuntimeException('The ledger transaction was not found.'); }
            $dateText = post_str('transaction_date');
            $date = v_datetime_local($dateText);
            $amount = post_str('fee_amount');
            $method = post_str('payment_method');
            $notes = post_text('notes');
            if ($date === null) { $errors[] = 'Enter a valid transaction date and time.'; }
            if (!v_money($amount) || (float) $amount < 0) { $errors[] = 'Enter a valid non-negative amount.'; }
            if (!isset($methods[$method])) { $errors[] = 'Choose a valid payment method.'; }
            if (str_len($notes) > 500) { $errors[] = 'Notes are limited to 500 characters.'; }
            if ($errors) { throw new RuntimeException('invalid'); }
            db_transaction(function () use ($id, $date, $amount, $method, $notes): void {
                $old = db_one('SELECT ledger_id, reconciliation_status FROM cash_collection_ledger WHERE ledger_id = ? FOR UPDATE', [$id]);
                if ($old === null) { throw new RuntimeException('The ledger transaction was not found.'); }
                if ($old['reconciliation_status'] !== 'pending') {
                    throw new RuntimeException('Mark this transaction Pending before editing reconciled details.');
                }
                db_exec('UPDATE cash_collection_ledger SET transaction_date = ?, fee_amount = ?, payment_method = ?, notes = ? WHERE ledger_id = ?',
                    [$date, $amount, $method, $notes !== '' ? $notes : null, $id]);
                audit('cash_ledger_updated', 'Cash Ledger', 'Success', 'Updated ledger transaction ' . $id, 'cash_ledger', $id);
            });
            flash_add('success', 'Ledger transaction updated.');
            redirect('admin/ledger.php');
        } elseif ($do === 'reconcile') {
            if ($id === null) { throw new RuntimeException('The ledger transaction was not found.'); }
            $to = post_str('to');
            if (!in_array($to, ['pending', 'reconciled'], true)) { throw new RuntimeException('Choose a valid reconciliation status.'); }
            db_transaction(function () use ($id, $to, $actor): void {
                $row = db_one('SELECT ledger_id, reconciliation_status FROM cash_collection_ledger WHERE ledger_id = ? FOR UPDATE', [$id]);
                if ($row === null) { throw new RuntimeException('The ledger transaction was not found.'); }
                db_exec('UPDATE cash_collection_ledger SET reconciliation_status = ?, reconciled_by = ?, reconciled_at = ? WHERE ledger_id = ?',
                    [$to, $to === 'reconciled' ? ($actor['user_id'] ?? null) : null, $to === 'reconciled' ? date('Y-m-d H:i:s') : null, $id]);
                audit('cash_ledger_' . $to, 'Cash Ledger', 'Success', 'Transaction ' . $id . ' marked ' . $to, 'cash_ledger', $id);
            });
            flash_add('success', 'Reconciliation status updated.');
            redirect('admin/ledger.php?status=' . urlencode($to));
        } elseif ($do === 'delete') {
            if ($id === null) { throw new RuntimeException('The ledger transaction was not found.'); }
            db_transaction(function () use ($id): void {
                $row = db_one('SELECT ledger_id, reconciliation_status, issuance_id FROM cash_collection_ledger WHERE ledger_id = ? FOR UPDATE', [$id]);
                if ($row === null) { throw new RuntimeException('The ledger transaction was not found.'); }
                if ($row['reconciliation_status'] !== 'pending') { throw new RuntimeException('Reconciled records cannot be deleted. Mark it pending first, then correct the record.'); }
                audit('cash_ledger_deleted', 'Cash Ledger', 'Success', 'Deleted un-reconciled ledger transaction ' . $id . ' for issuance ' . $row['issuance_id'], 'cash_ledger', $id);
                db_exec('DELETE FROM cash_collection_ledger WHERE ledger_id = ?', [$id]);
            });
            flash_add('success', 'Pending ledger record deleted and audited.');
            redirect('admin/ledger.php');
        } else { throw new RuntimeException('Unknown ledger action.'); }
    } catch (RuntimeException $e) {
        if ($e->getMessage() !== 'invalid') { $errors[] = $e->getMessage(); }
    } catch (PDOException $e) {
        $errors[] = 'The ledger change could not be saved. Check for an existing transaction for that issuance.';
    }
}

$status = get_str('status');
if (!in_array($status, ['', 'pending', 'reconciled'], true)) { $status = ''; }
$q = get_str('q');
$from = v_date(get_str('from')) ? get_str('from') : '';
$to = v_date(get_str('to')) ? get_str('to') : '';
$where = []; $params = [];
if ($status !== '') { $where[] = 'cc.reconciliation_status = ?'; $params[] = $status; }
if ($q !== '') {
    $where[] = '(cc.requester_name LIKE ? OR i.reference_no LIKE ? OR i.or_number LIKE ?)';
    $like = '%' . addcslashes($q, '%_\\') . '%'; array_push($params, $like, $like, $like);
}
if ($from !== '') { $where[] = 'cc.transaction_date >= ?'; $params[] = $from . ' 00:00:00'; }
if ($to !== '') { $where[] = 'cc.transaction_date < DATE_ADD(?, INTERVAL 1 DAY)'; $params[] = $to . ' 00:00:00'; }
$sqlWhere = $where ? 'WHERE ' . implode(' AND ', $where) : '';
$rows = db_all("SELECT cc.*, i.reference_no, i.or_number, u.full_name AS reconciled_by_name
    FROM cash_collection_ledger cc JOIN issuances i ON i.issuance_id = cc.issuance_id
    LEFT JOIN users u ON u.user_id = cc.reconciled_by $sqlWhere
    ORDER BY cc.transaction_date DESC, cc.ledger_id DESC LIMIT 500", $params);
$totals = db_one("SELECT COUNT(*) AS transaction_count, COALESCE(SUM(cc.fee_amount),0) AS recorded_total,
    COALESCE(SUM(CASE WHEN cc.reconciliation_status = 'reconciled' THEN cc.fee_amount ELSE 0 END),0) AS reconciled_total,
    COALESCE(SUM(CASE WHEN cc.reconciliation_status = 'pending' THEN cc.fee_amount ELSE 0 END),0) AS pending_total
    FROM cash_collection_ledger cc JOIN issuances i ON i.issuance_id = cc.issuance_id $sqlWhere", $params);
$missing = db_all('SELECT i.issuance_id, i.reference_no, i.resident_name, i.fee_amount, i.or_number, i.issued_at, i.issued_by_name
    FROM issuances i LEFT JOIN cash_collection_ledger cc ON cc.issuance_id = i.issuance_id
    WHERE i.fee_amount > 0 AND i.or_number IS NOT NULL AND cc.ledger_id IS NULL ORDER BY i.issued_at DESC LIMIT 200');

render_header('Cash Collection Ledger', [['Administration', null], ['Cash Collection Ledger', null]], 'ledger');
page_head('Cash Collection Ledger', 'Track recorded cash-collection transactions and reconciliation. A pending item is not yet reconciled; totals are not proof of a bank deposit.');
echo errors_box($errors);
?>
<div class="row g-3 mb-3">
  <div class="col-md-4"><div class="card-b p-3"><div class="text-muted small">Ledger transactions</div><div class="fs-4 fw-bold"><?= (int) ($totals['transaction_count'] ?? 0) ?></div></div></div>
  <div class="col-md-4"><div class="card-b p-3"><div class="text-muted small">Reconciled amount</div><div class="fs-4 fw-bold"><?= e(money($totals['reconciled_total'] ?? 0)) ?></div></div></div>
  <div class="col-md-4"><div class="card-b p-3"><div class="text-muted small">Pending reconciliation</div><div class="fs-4 fw-bold"><?= e(money($totals['pending_total'] ?? 0)) ?></div></div></div>
</div>
<?php if ($canManage): ?>
<div class="card-b mb-3">
  <div class="card-h"><h2>Record a missing transaction</h2><p>Paid certificates issued before the ledger was enabled can be added once. Enter the actual transaction date and actual collector from the receipt/office record; the Administrator entering the backfill is not automatically recorded as the collector. New paid issuances create a pending entry automatically.</p></div>
  <div class="card-body-b">
    <?php if (!$missing): ?><p class="text-muted mb-0">No eligible issuance is missing a ledger entry.</p><?php else: ?>
    <form method="post" action="<?= e(base_url('admin/ledger.php')) ?>" class="row g-2 align-items-end"><?= csrf_field() ?><input type="hidden" name="do" value="create">
      <div class="col-lg-6"><label class="form-label" for="issuance_id">Issued certificate (must have an O.R.)</label><select class="form-select" id="issuance_id" name="issuance_id" required><option value="">Select</option><?php foreach ($missing as $m): ?><option value="<?= (int) $m['issuance_id'] ?>"><?= e((string) $m['reference_no'] . ' · ' . $m['resident_name'] . ' · ' . money($m['fee_amount']) . ' · O.R. ' . $m['or_number'] . ' · issued ' . date('Y-m-d', strtotime((string) $m['issued_at']))) ?></option><?php endforeach; ?></select></div>
      <div class="col-lg-2"><label class="form-label" for="legacy_date">Actual transaction date/time</label><input class="form-control" type="datetime-local" id="legacy_date" name="transaction_date" required></div>
      <div class="col-lg-2"><label class="form-label" for="collector_name">Actual collector name</label><input class="form-control" type="text" id="collector_name" name="collector_name" maxlength="100" required></div>
      <div class="col-lg-1"><label class="form-label" for="new_method">Method</label><select class="form-select" id="new_method" name="payment_method"><?php foreach ($methods as $k => $label): ?><option value="<?= e($k) ?>"><?= e($label) ?></option><?php endforeach; ?></select></div>
      <div class="col-lg-1"><button class="btn btn-primary w-100" type="submit">Add</button></div>
    </form><?php endif; ?>
  </div>
</div>
<?php endif; ?>
<div class="card-b">
  <form method="get" class="filters" action="<?= e(base_url('admin/ledger.php')) ?>">
    <div><label class="form-label" for="q">Search</label><input class="form-control" id="q" name="q" value="<?= e($q) ?>" placeholder="Requester, reference or O.R."></div>
    <div><label class="form-label" for="status">Reconciliation</label><select class="form-select" id="status" name="status"><option value="">All</option><option value="pending"<?= $status === 'pending' ? ' selected' : '' ?>>Pending</option><option value="reconciled"<?= $status === 'reconciled' ? ' selected' : '' ?>>Reconciled</option></select></div>
    <div><label class="form-label" for="from">From</label><input class="form-control" id="from" name="from" type="date" value="<?= e($from) ?>"></div>
    <div><label class="form-label" for="to">To</label><input class="form-control" id="to" name="to" type="date" value="<?= e($to) ?>"></div>
    <div><button class="btn btn-light border" type="submit">Filter</button></div>
  </form>
  <?php if (!$rows): ?><?= empty_state('bi-cash-stack', 'No ledger transactions match this filter.') ?><?php else: ?>
  <div class="px-3 py-2 border-bottom small text-muted"><?= (int) ($totals['transaction_count'] ?? 0) ?> transaction(s) · recorded ledger amount <?= e(money($totals['recorded_total'] ?? 0)) ?></div>
  <div class="table-responsive"><table class="table table-b align-middle"><thead><tr><th>Date</th><th>Requester / certificate</th><th>Amount</th><th>Method</th><th>Collector</th><th>Status</th><?php if ($canManage): ?><th>Actions</th><?php endif; ?></tr></thead><tbody>
  <?php foreach ($rows as $row): $dateValue = date('Y-m-d\TH:i', strtotime((string) $row['transaction_date'])); ?>
    <tr>
      <td class="text-nowrap"><?= e(fmt_dt((string) $row['transaction_date'])) ?></td>
      <td><strong><?= e((string) $row['requester_name']) ?></strong><div class="small text-muted"><?= e((string) $row['reference_no']) ?> · O.R. <?= e((string) ($row['or_number'] ?? '—')) ?></div></td>
      <td><?= e(money($row['fee_amount'])) ?></td><td><?= e($methods[(string) $row['payment_method']] ?? (string) $row['payment_method']) ?></td><td><?= e((string) $row['collector_name']) ?></td>
      <td><?= $row['reconciliation_status'] === 'reconciled' ? pill('Reconciled', 'green') : pill('Pending', 'grey') ?><?php if ($row['reconciliation_status'] === 'reconciled' && $row['reconciled_by_name']): ?><div class="small text-muted">By <?= e((string) $row['reconciled_by_name']) ?></div><?php endif; ?></td>
      <?php if ($canManage): ?><td><details><summary class="small">Edit</summary><form method="post" action="<?= e(base_url('admin/ledger.php')) ?>" class="mt-2 ledger-editor"><?= csrf_field() ?><input type="hidden" name="do" value="update"><input type="hidden" name="id" value="<?= (int) $row['ledger_id'] ?>">
        <label class="form-label small">Transaction date</label><input class="form-control form-control-sm mb-2" type="datetime-local" name="transaction_date" value="<?= e($dateValue) ?>" required>
        <label class="form-label small">Recorded amount (PHP)</label><input class="form-control form-control-sm mb-2" name="fee_amount" inputmode="decimal" value="<?= e(number_format((float) $row['fee_amount'], 2, '.', '')) ?>" required>
        <label class="form-label small">Method</label><select class="form-select form-select-sm mb-2" name="payment_method"><?php foreach ($methods as $k => $label): ?><option value="<?= e($k) ?>"<?= $row['payment_method'] === $k ? ' selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select>
        <label class="form-label small">Notes</label><textarea class="form-control form-control-sm mb-2" name="notes" maxlength="500"><?= e((string) ($row['notes'] ?? '')) ?></textarea><button class="btn btn-sm btn-primary" type="submit">Save edit</button></form>
        <form method="post" action="<?= e(base_url('admin/ledger.php')) ?>" class="mt-2"><?= csrf_field() ?><input type="hidden" name="do" value="reconcile"><input type="hidden" name="id" value="<?= (int) $row['ledger_id'] ?>"><input type="hidden" name="to" value="<?= $row['reconciliation_status'] === 'reconciled' ? 'pending' : 'reconciled' ?>"><button class="btn btn-sm btn-light border w-100" type="submit"><?= $row['reconciliation_status'] === 'reconciled' ? 'Mark pending' : 'Mark reconciled' ?></button></form>
        <?php if ($row['reconciliation_status'] === 'pending'): ?><form method="post" action="<?= e(base_url('admin/ledger.php')) ?>" class="mt-2" data-confirm="Delete this pending ledger record? The action is audited."><?= csrf_field() ?><input type="hidden" name="do" value="delete"><input type="hidden" name="id" value="<?= (int) $row['ledger_id'] ?>"><button class="btn btn-sm btn-outline-danger w-100" type="submit">Delete pending record</button></form><?php endif; ?>
      </details></td><?php endif; ?>
    </tr>
  <?php endforeach; ?></tbody></table></div><?php endif; ?>
</div>
<?php render_footer();
