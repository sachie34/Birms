<?php
/** Issuance History: a read-only, searchable record of every certificate issued. */
declare(strict_types=1);
require_once __DIR__ . '/../../app/bootstrap.php';

require_login();
require_permission('certificates.history');
function history_filters(array $src): array
{
    $d = fn(string $k) => (function () use ($src, $k): string { $v = input_str($src, $k); return v_date($v) ? $v : ''; })();
    $reconciliation = input_str($src, 'reconciliation');
    if (!in_array($reconciliation, ['', 'unreconciled', 'reconciled'], true)) { $reconciliation = ''; }
    return ['q' => input_str($src, 'q'), 'type' => (string) (input_int($src, 'type') ?? ''), 'from' => $d('from'), 'to' => $d('to'), 'reconciliation' => $reconciliation];
}
function history_where(array $f): array
{
    $w = []; $p = [];
    foreach (preg_split('/[\s,]+/u', $f['q'], -1, PREG_SPLIT_NO_EMPTY) ?: [] as $t) { $l = '%' . addcslashes($t, '%_\\') . '%'; $w[] = '(i.resident_name LIKE ? OR i.reference_no LIKE ? OR i.or_number LIKE ?)'; array_push($p, $l, $l, $l); }
    if ($f['type'] !== '') { $w[] = 'i.type_id = ?'; $p[] = (int) $f['type']; }
    if ($f['reconciliation'] === 'reconciled') { $w[] = "cc.reconciliation_status = 'reconciled'"; }
    elseif ($f['reconciliation'] === 'unreconciled') { $w[] = "(cc.ledger_id IS NULL OR cc.reconciliation_status = 'pending')"; }
    if ($f['from'] !== '') { $w[] = 'i.issued_at >= ?'; $p[] = $f['from'] . ' 00:00:00'; }
    if ($f['to'] !== '')   { $w[] = 'i.issued_at < (? + INTERVAL 1 DAY)'; $p[] = $f['to'] . ' 00:00:00'; }
    return [$w ? 'WHERE ' . implode(' AND ', $w) : '', $p];
}
if (basename((string) $_SERVER['SCRIPT_NAME']) !== 'history.php') { return; }   // when included by the export, stop here

$f = history_filters($_GET); [$where, $p] = history_where($f);
$page = max(1, get_int('page') ?? 1); $per = 25;
$join = ' LEFT JOIN cash_collection_ledger cc ON cc.issuance_id = i.issuance_id ';
$total = (int) db_val("SELECT COUNT(*) FROM issuances i $join $where", $p);
$sum = (float) db_val("SELECT COALESCE(SUM(cc.fee_amount), 0) FROM issuances i $join $where", $p);
$rows = db_all("SELECT i.issuance_id, i.reference_no, i.resident_name, i.type_name, i.issued_at, i.issued_by_name, i.fee_amount, i.or_number,
    cc.ledger_id, COALESCE(cc.fee_amount, 0) AS ledger_amount, cc.payment_method, cc.reconciliation_status, cc.transaction_date
    FROM issuances i $join $where ORDER BY i.issued_at DESC, i.issuance_id DESC LIMIT " . (int) $per . ' OFFSET ' . (int) (($page - 1) * $per), $p);
$types = []; foreach (db_all('SELECT type_id, name FROM document_types ORDER BY sort_order, name') as $t) { $types[(int) $t['type_id']] = (string) $t['name']; }
$query = array_filter($f, fn($x) => $x !== '');

render_header('Issuance History', [['Issuance History', null]], 'history');
page_head('Issuance History', 'Search completed issuances. Reference and O.R. numbers support verification.', can('reports.export') ? btn_link('certificates/history_export.php' . ($query ? '?' . http_build_query($query) : ''), 'Export CSV', 'btn-light border', 'bi-download') : '');
?>
<div class="card-b">
  <form method="get" class="filters" action="<?= e(base_url('certificates/history.php')) ?>">
    <div><label class="form-label" for="q">Search</label><input class="form-control" id="q" name="q" value="<?= e($f['q']) ?>" maxlength="60" placeholder="Name, reference or O.R."></div>
    <div><label class="form-label" for="type">Document</label><select class="form-select" id="type" name="type"><option value="">All</option><?php foreach ($types as $id => $n): ?><option value="<?= (int) $id ?>"<?= $f['type'] === (string) $id ? ' selected' : '' ?>><?= e($n) ?></option><?php endforeach; ?></select></div>
    <div><label class="form-label" for="from">From</label><input class="form-control" type="date" id="from" name="from" value="<?= e($f['from']) ?>"></div>
    <div><label class="form-label" for="to">To</label><input class="form-control" type="date" id="to" name="to" value="<?= e($f['to']) ?>"></div>
    <div><label class="form-label" for="reconciliation">Cash ledger</label><select class="form-select" id="reconciliation" name="reconciliation"><option value="">All</option><option value="unreconciled"<?= $f['reconciliation'] === 'unreconciled' ? ' selected' : '' ?>>Unreconciled / missing entry</option><option value="reconciled"<?= $f['reconciliation'] === 'reconciled' ? ' selected' : '' ?>>Reconciled</option></select></div>
    <div><button class="btn btn-light border" type="submit"><i class="bi bi-funnel me-1"></i>Filter</button></div>
  </form>
  <?php if (!$rows): ?><?= empty_state('bi-clock-history', 'No certificates issued yet.') ?><?php else: ?>
  <div class="px-3 py-2 small text-muted border-bottom"><?= $total ?> certificate(s) · recorded cash-ledger amount: <strong><?= e(money($sum)) ?></strong>. This is ledger-recorded money, not independent proof of a bank deposit.</div>
  <div class="table-responsive"><table class="table table-b"><thead><tr><th>Reference</th><th>Resident</th><th>Document</th><th>Transaction date</th><th>Date issued</th><th>Issued by</th><th>Ledger amount</th><th>Reconciliation</th><th>O.R.</th><th></th></tr></thead><tbody>
  <?php foreach ($rows as $r): ?><tr><td class="fw-semibold"><?= e((string) $r['reference_no']) ?></td><td><?= e((string) $r['resident_name']) ?></td><td><?= e((string) $r['type_name']) ?></td><td class="text-nowrap"><?= e(fmt_dt($r['transaction_date'] === null ? '' : (string) $r['transaction_date'])) ?></td><td class="text-nowrap"><?= e(fmt_dt((string) $r['issued_at'])) ?></td><td><?= e((string) $r['issued_by_name']) ?></td><td><?= e(money($r['ledger_amount'])) ?><div class="small text-muted">Issuance snapshot: <?= e(money($r['fee_amount'])) ?></div></td><td><?php if ($r['ledger_id'] === null): ?><?= pill('No ledger entry', 'red') ?><?php elseif ($r['reconciliation_status'] === 'reconciled'): ?><?= pill('Reconciled', 'green') ?><?php else: ?><?= pill('Unreconciled', 'grey') ?><?php endif; ?></td><td><?= e((string) ($r['or_number'] ?? '—')) ?></td>
    <td class="text-end"><a class="btn btn-sm btn-light border" aria-label="<?= can('certificates.print') ? 'Open and print certificate' : 'Preview certificate' ?>" title="<?= can('certificates.print') ? 'Open and print certificate' : 'Preview certificate' ?>" href="<?= e(base_url('certificates/print.php?id=' . (int) $r['issuance_id'])) ?>"><i class="bi <?= can('certificates.print') ? 'bi-printer' : 'bi-eye' ?>"></i></a></td></tr><?php endforeach; ?></tbody></table></div>
  <?php render_pagination($total, $page, $per, 'certificates/history.php', $query); endif; ?>
</div>
<?php render_footer();
