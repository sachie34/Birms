<?php
/** Permission-aware global search across the record modules available to the signed-in user. */
declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';

require_login();
$q = trim(get_str('q'));
$q = mb_substr_safe($q, 0, 80);
$results = ['residents' => [], 'requests' => [], 'issuances' => [], 'blotters' => []];
$searched = str_len($q) >= 2;

if ($searched) {
    // Treat %, _ and backslash as ordinary characters rather than broad wildcard input.
    $like = '%' . addcslashes($q, '%_\\') . '%';

    if (can('residents.view')) {
        $results['residents'] = db_all(
            "SELECT r.resident_id, r.first_name, r.middle_name, r.last_name, r.suffix,
                    r.residency_status, h.household_code
             FROM residents r LEFT JOIN households h ON h.household_id = r.household_id
             WHERE r.archived_at IS NULL
               AND (CONCAT_WS(' ', r.first_name, r.middle_name, r.last_name, r.suffix) LIKE ?
                    OR CONCAT_WS(' ', r.last_name, r.first_name, r.middle_name, r.suffix) LIKE ?
                    OR h.household_code LIKE ?)
             ORDER BY r.last_name, r.first_name LIMIT 10",
            [$like, $like, $like]
        );
    }
    if (can('requests.view')) {
        $results['requests'] = db_all(
            "SELECT dr.request_id, dr.reference_no, dr.status, dr.purpose, dr.requested_at,
                    CONCAT_WS(' ', r.first_name, r.middle_name, r.last_name, r.suffix) AS resident_name,
                    dt.name AS type_name
             FROM document_requests dr
             JOIN residents r ON r.resident_id = dr.resident_id
             JOIN document_types dt ON dt.type_id = dr.type_id
             WHERE dr.reference_no LIKE ? OR dr.purpose LIKE ?
                OR CONCAT_WS(' ', r.first_name, r.middle_name, r.last_name, r.suffix) LIKE ?
                OR CONCAT_WS(' ', r.last_name, r.first_name, r.middle_name, r.suffix) LIKE ?
                OR dt.name LIKE ?
             ORDER BY dr.requested_at DESC, dr.request_id DESC LIMIT 10",
            [$like, $like, $like, $like, $like]
        );
    }
    if (can('certificates.history')) {
        $results['issuances'] = db_all(
            "SELECT issuance_id, reference_no, resident_name, type_name, issued_at, or_number
             FROM issuances
             WHERE reference_no LIKE ? OR resident_name LIKE ? OR type_name LIKE ? OR COALESCE(or_number, '') LIKE ?
             ORDER BY issued_at DESC, issuance_id DESC LIMIT 10",
            [$like, $like, $like, $like]
        );
    }
    if (can('blotters.view')) {
        $results['blotters'] = db_all(
            "SELECT DISTINCT b.blotter_id, b.case_no, b.incident_date, b.incident_place, b.status,
                    p.full_name AS party_name
             FROM blotters b LEFT JOIN blotter_parties p ON p.blotter_id = b.blotter_id
             WHERE b.case_no LIKE ? OR COALESCE(b.incident_place, '') LIKE ?
                    OR b.narrative LIKE ? OR p.full_name LIKE ?
             ORDER BY b.incident_date DESC, b.blotter_id DESC LIMIT 10",
            [$like, $like, $like, $like]
        );
    }
}

$totalFound = array_sum(array_map('count', $results));
render_header('Search Records', [['Search Records', null]], '');
page_head('Search Records', 'Search the record areas available to your account.');
?>
<div class="card-b mb-3">
  <form method="get" class="filters" action="<?= e(base_url('search.php')) ?>" role="search">
    <div class="global-search-field">
      <label class="form-label" for="q">Name, reference number, document type or case</label>
      <input class="form-control" id="q" name="q" value="<?= e($q) ?>" maxlength="80" placeholder="Enter at least 2 characters" autofocus>
    </div>
    <div><button class="btn btn-primary" type="submit"><i class="bi bi-search me-1"></i>Search records</button></div>
  </form>
</div>

<?php if (!$searched): ?>
  <div class="card-b"><div class="empty-state"><i class="bi bi-search"></i>Enter at least two characters to search permitted resident, request, issuance and blotter records.</div></div>
<?php else: ?>
  <p class="text-muted small mb-3"><?= number_format($totalFound) ?> matching result(s) shown for “<?= e($q) ?>”. Up to 10 results per section are shown.</p>

  <?php if (can('residents.view')): ?>
  <div class="card-b mb-3">
    <div class="card-h"><h2><i class="bi bi-people me-2 text-primary"></i>Residents (<?= count($results['residents']) ?>)</h2><p>Matches by name or household code.</p></div>
    <?php if (!$results['residents']): ?><div class="empty-state">No matching resident records.</div>
    <?php else: ?><div class="table-responsive"><table class="table table-b"><thead><tr><th>Resident</th><th>Household</th><th>Status</th><th class="text-end">Action</th></tr></thead><tbody>
      <?php foreach ($results['residents'] as $r): ?><tr>
        <td class="fw-semibold"><?= e(trim(implode(' ', array_filter([(string) $r['last_name'] . ',', (string) $r['first_name'], (string) ($r['middle_name'] ?? ''), (string) ($r['suffix'] ?? '')]))) ?></td>
        <td><?= e((string) ($r['household_code'] ?? '—')) ?></td><td><?= residency_pill((string) $r['residency_status']) ?></td>
        <td class="text-end"><a class="btn btn-sm btn-light border" href="<?= e(base_url('residents/view.php?id=' . (int) $r['resident_id'])) ?>">Open</a></td>
      </tr><?php endforeach; ?></tbody></table></div><?php endif; ?>
  </div>
  <?php endif; ?>

  <?php if (can('requests.view')): ?>
  <div class="card-b mb-3">
    <div class="card-h"><h2><i class="bi bi-inbox me-2 text-primary"></i>Document Requests (<?= count($results['requests']) ?>)</h2><p>Matches by reference, requester, document type or purpose.</p></div>
    <?php if (!$results['requests']): ?><div class="empty-state">No matching document requests.</div>
    <?php else: ?><div class="table-responsive"><table class="table table-b"><thead><tr><th>Reference</th><th>Requester</th><th>Document</th><th>Status</th><th class="text-end">Action</th></tr></thead><tbody>
      <?php foreach ($results['requests'] as $r): ?><tr>
        <td class="fw-semibold"><?= e((string) $r['reference_no']) ?></td><td><?= e((string) $r['resident_name']) ?></td><td><?= e((string) $r['type_name']) ?></td>
        <td><?= status_pill((string) $r['status']) ?></td><td class="text-end"><a class="btn btn-sm btn-light border" href="<?= e(base_url('requests/view.php?id=' . (int) $r['request_id'])) ?>">Open</a></td>
      </tr><?php endforeach; ?></tbody></table></div><?php endif; ?>
  </div>
  <?php endif; ?>

  <?php if (can('certificates.history')): ?>
  <div class="card-b mb-3">
    <div class="card-h"><h2><i class="bi bi-file-earmark-check me-2 text-primary"></i>Issued Certificates (<?= count($results['issuances']) ?>)</h2><p>Matches by reference, resident, document type or official receipt.</p></div>
    <?php if (!$results['issuances']): ?><div class="empty-state">No matching issued certificates.</div>
    <?php else: ?><div class="table-responsive"><table class="table table-b"><thead><tr><th>Reference</th><th>Resident</th><th>Document</th><th>Date issued</th><th class="text-end">Action</th></tr></thead><tbody>
      <?php foreach ($results['issuances'] as $r): ?><tr>
        <td class="fw-semibold"><?= e((string) $r['reference_no']) ?></td><td><?= e((string) $r['resident_name']) ?></td><td><?= e((string) $r['type_name']) ?></td>
        <td><?= e(fmt_dt((string) $r['issued_at'])) ?></td><td class="text-end"><a class="btn btn-sm btn-light border" href="<?= e(base_url('certificates/print.php?id=' . (int) $r['issuance_id'])) ?>">Open</a></td>
      </tr><?php endforeach; ?></tbody></table></div><?php endif; ?>
  </div>
  <?php endif; ?>

  <?php if (can('blotters.view')): ?>
  <div class="card-b mb-3">
    <div class="card-h"><h2><i class="bi bi-shield-exclamation me-2 text-primary"></i>Blotter Cases (<?= count($results['blotters']) ?>)</h2><p>Matches by case number, party, incident place or narrative.</p></div>
    <?php if (!$results['blotters']): ?><div class="empty-state">No matching blotter records.</div>
    <?php else: ?><div class="table-responsive"><table class="table table-b"><thead><tr><th>Case</th><th>Party match</th><th>Incident date</th><th>Status</th><th class="text-end">Action</th></tr></thead><tbody>
      <?php foreach ($results['blotters'] as $r): ?><tr>
        <td class="fw-semibold"><?= e((string) $r['case_no']) ?></td><td><?= e((string) ($r['party_name'] ?? '—')) ?></td><td><?= e(date('j M Y', strtotime((string) $r['incident_date']))) ?></td>
        <td><?= status_pill((string) $r['status']) ?></td><td class="text-end"><a class="btn btn-sm btn-light border" href="<?= e(base_url('blotters/view.php?id=' . (int) $r['blotter_id'])) ?>">Open</a></td>
      </tr><?php endforeach; ?></tbody></table></div><?php endif; ?>
  </div>
  <?php endif; ?>
<?php endif; ?>
<?php render_footer(); ?>
