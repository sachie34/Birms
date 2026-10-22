<?php
/** Resident CSV import with a review step and an all-or-nothing database transaction. */
declare(strict_types=1);
require_once __DIR__ . '/../../app/bootstrap.php';

require_login();
require_permission('residents.add');

$user = auth_user();
$userId = (int) ($user['user_id'] ?? 0);
$errors = [];
$previewRows = [];
$stage = null;
$resultRecord = null;
$resultToken = get_str('result');

function resident_import_save_error_report(array $items, int $userId): ?string
{
    if (!$items) {
        unset($_SESSION['resident_import_error_report']);
        return null;
    }
    $token = bin2hex(random_bytes(24));
    $_SESSION['resident_import_error_report'] = [
        'token' => $token, 'user_id' => $userId, 'created_at' => time(), 'rows' => $items,
    ];
    return $token;
}

if (is_post()) {
    require_csrf();
    $action = post_str('action');

    if ($action === 'preview') {
        unset($_SESSION['resident_import_stage']);
        $file = $_FILES['csv_file'] ?? null;

        if (!is_array($file) || !isset($file['error'], $file['tmp_name'], $file['name'], $file['size'])
            || !is_string($file['tmp_name']) || !is_string($file['name']) || !is_numeric($file['size'])) {
            $errors[] = 'Choose a CSV file to preview.';
        } elseif ((int) $file['error'] !== UPLOAD_ERR_OK) {
            $errors[] = match ((int) $file['error']) {
                UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'The upload is larger than the server allows.',
                UPLOAD_ERR_PARTIAL => 'The file upload was incomplete. Try again.',
                UPLOAD_ERR_NO_FILE => 'Choose a CSV file to preview.',
                default => 'The file could not be uploaded. Try again.',
            };
        } elseif (strtolower(pathinfo((string) $file['name'], PATHINFO_EXTENSION)) !== 'csv') {
            $errors[] = 'Only files with a .csv extension are accepted.';
        } elseif (!is_uploaded_file((string) $file['tmp_name'])) {
            $errors[] = 'The upload could not be verified. Select the file again.';
        } elseif ((int) ($file['size'] ?? 0) < 1 || (int) $file['size'] > RESIDENT_IMPORT_MAX_BYTES
            || (int) @filesize((string) $file['tmp_name']) > RESIDENT_IMPORT_MAX_BYTES) {
            $errors[] = 'CSV files must be between 1 byte and 2 MiB. Split larger files into smaller CSV files.';
        } else {
            $sample = file_get_contents((string) $file['tmp_name'], false, null, 0, 4096);
            if ($sample === false || str_contains($sample, "\0")) {
                $errors[] = 'The file does not look like a text CSV. Download the BIRMS template and try again.';
            } else {
                $parsed = resident_import_parse_csv((string) $file['tmp_name']);
                if (!$parsed['ok']) {
                    $errors[] = $parsed['error'];
                    audit_best_effort('resident_import_rejected', 'Residents', 'Failed', $parsed['error']);
                } else {
                    $stage = [
                        'token' => bin2hex(random_bytes(24)), 'user_id' => $userId,
                        'created_at' => time(), 'rows' => $parsed['rows'],
                    ];
                    $_SESSION['resident_import_stage'] = $stage;
                    $previewRows = resident_import_analyze_rows($stage['rows']);
                    $count = resident_import_counts($previewRows);
                    $reportToken = resident_import_save_error_report(resident_import_error_rows($previewRows), $userId);
                    audit('resident_csv_previewed', 'Residents', 'Success',
                        'CSV preview: ' . count($previewRows) . ' data rows; ready ' . $count['ready'] . '; skipped ' . $count['skipped'] . '; rejected ' . $count['rejected']);
                }
            }
        }
    } elseif ($action === 'commit') {
        $stage = $_SESSION['resident_import_stage'] ?? null;
        $postedToken = post_str('import_token');
        if (!is_array($stage) || (int) ($stage['user_id'] ?? 0) !== $userId
            || (int) ($stage['created_at'] ?? 0) < time() - RESIDENT_IMPORT_STAGE_TTL
            || !is_string($stage['token'] ?? null) || !hash_equals((string) $stage['token'], $postedToken)) {
            unset($_SESSION['resident_import_stage']);
            $errors[] = 'The preview expired or does not belong to this sign-in. Upload the CSV and preview it again.';
        } else {
            try {
                $result = resident_import_commit_rows($stage['rows'], $userId);
                unset($_SESSION['resident_import_stage']);
                $resultToken = bin2hex(random_bytes(24));
                $errorToken = resident_import_save_error_report($result['errors'], $userId);
                $resultRecord = [
                    'token' => $resultToken, 'error_token' => $errorToken, 'user_id' => $userId, 'created_at' => time(),
                    'imported' => $result['imported'], 'skipped' => $result['skipped'], 'rejected' => $result['rejected'],
                    'rolled_back' => false,
                ];
                $_SESSION['resident_import_result'] = $resultRecord;
                flash_add('success', 'Resident import finished. ' . $result['imported'] . ' imported, ' . $result['skipped'] . ' skipped and ' . $result['rejected'] . ' rejected.');
                redirect('residents/import.php', ['result' => $resultToken]);
            } catch (Throwable $e) {
                // db_transaction() rolls back every resident in the batch on a database failure.
                error_log('BIRMS resident CSV import rolled back: ' . get_class($e) . ': ' . $e->getMessage());
                $rechecked = resident_import_analyze_rows($stage['rows']);
                $failedErrors = resident_import_error_rows($rechecked);
                $failedReady = 0;
                foreach ($rechecked as $row) {
                    if ($row['status'] === 'ready') {
                        $failedReady++;
                        $failedErrors[] = [
                            'line' => $row['line'], 'status' => 'Rejected',
                            'first_name' => (string) ($row['values']['first_name'] ?? ''),
                            'last_name' => (string) ($row['values']['last_name'] ?? ''),
                            'birthdate' => (string) ($row['values']['birthdate'] ?? ''),
                            'details' => 'The database rejected the batch. The entire transaction was rolled back; this row was not saved.',
                        ];
                    }
                }
                $counts = resident_import_counts($rechecked);
                unset($_SESSION['resident_import_stage']);
                $resultToken = bin2hex(random_bytes(24));
                $errorToken = resident_import_save_error_report($failedErrors, $userId);
                $resultRecord = [
                    'token' => $resultToken, 'error_token' => $errorToken, 'user_id' => $userId, 'created_at' => time(),
                    'imported' => 0, 'skipped' => $counts['skipped'], 'rejected' => $counts['rejected'] + $failedReady,
                    'rolled_back' => true,
                ];
                $_SESSION['resident_import_result'] = $resultRecord;
                audit_best_effort('resident_import_rolled_back', 'Residents', 'Failed', 'CSV import transaction rolled back; no resident rows were saved.');
                flash_add('danger', 'The database rejected this import. The full batch was rolled back; no resident rows were saved. Check the error CSV and server log before trying again.');
                redirect('residents/import.php', ['result' => $resultToken]);
            }
        }
    } else {
        $errors[] = 'Choose Preview CSV before importing.';
    }
}

if (!$resultRecord && $resultToken !== '') {
    $saved = $_SESSION['resident_import_result'] ?? null;
    if (is_array($saved) && (int) ($saved['user_id'] ?? 0) === $userId
        && (int) ($saved['created_at'] ?? 0) >= time() - RESIDENT_IMPORT_STAGE_TTL
        && is_string($saved['token'] ?? null) && hash_equals((string) $saved['token'], $resultToken)) {
        $resultRecord = $saved;
    }
}

if (!$stage) {
    $savedStage = $_SESSION['resident_import_stage'] ?? null;
    if (is_array($savedStage) && (int) ($savedStage['user_id'] ?? 0) === $userId
        && (int) ($savedStage['created_at'] ?? 0) >= time() - RESIDENT_IMPORT_STAGE_TTL) {
        $stage = $savedStage;
    } else {
        unset($_SESSION['resident_import_stage']);
    }
}
if ($stage && !$previewRows && (int) ($stage['user_id'] ?? 0) === $userId) {
    $previewRows = resident_import_analyze_rows($stage['rows']);
}
if ($previewRows) {
    $counts = resident_import_counts($previewRows);
    $report = $_SESSION['resident_import_error_report'] ?? null;
    if (is_array($report) && (int) ($report['user_id'] ?? 0) === $userId
        && (int) ($report['created_at'] ?? 0) >= time() - RESIDENT_IMPORT_STAGE_TTL) {
        $reportToken = (string) ($report['token'] ?? '');
    } else {
        $reportToken = resident_import_save_error_report(resident_import_error_rows($previewRows), $userId) ?? '';
    }
}

render_header('Import residents', [['Residents', 'residents/index.php'], ['Import residents', null]], 'residents');
page_head('Import residents', 'Upload a CSV, review every row, then confirm. Existing resident records are never overwritten.',
    btn_link('residents/index.php', 'Back to residents', 'btn-light border', 'bi-arrow-left'));
?>
<div class="row g-3">
  <div class="col-lg-5">
    <div class="card-b">
      <div class="card-h"><h2>1. Upload a CSV</h2></div>
      <div class="card-body-b">
        <p>Start with the official BIRMS import template. Keep its column names and order unchanged.</p>
        <p><a class="btn btn-light border" href="<?= e(base_url('residents/import_template.php')) ?>"><i class="bi bi-download me-1"></i>Download CSV template</a></p>
        <form method="post" enctype="multipart/form-data" action="<?= e(base_url('residents/import.php')) ?>">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="preview">
          <label class="form-label" for="csv_file">Resident CSV file</label>
          <input class="form-control" id="csv_file" type="file" name="csv_file" accept=".csv,text/csv" required>
          <div class="form-text">Maximum 2 MiB and 2,000 non-empty data rows. Previewing does not save anything.</div>
          <button class="btn btn-primary mt-3" type="submit"><i class="bi bi-clipboard-check me-1"></i>Preview and validate</button>
        </form>
        <hr>
        <h3 class="h6">Import rules</h3>
        <ul class="mb-0">
          <li>Birthdate must use <code>YYYY-MM-DD</code>.</li>
          <li>Household code must already exist. Purok, category and employment labels must match configured records.</li>
          <li>Possible duplicates (same first name, last name and birthdate) are skipped, never updated.</li>
          <li>Rejected rows are not saved. Ready rows are inserted together in one transaction; a database failure rolls back the whole batch.</li>
        </ul>
      </div>
    </div>
  </div>
  <div class="col-lg-7">
    <div class="card-b">
      <div class="card-h"><h2>2. Review the result</h2></div>
      <div class="card-body-b">
        <?php if ($errors): ?>
          <div class="alert alert-danger" role="alert"><ul class="mb-0"><?php foreach ($errors as $message): ?><li><?= e($message) ?></li><?php endforeach; ?></ul></div>
        <?php elseif (!$resultRecord && !$previewRows): ?>
          <p class="text-muted mb-0">Choose a CSV file and click “Preview and validate.” No data changes occur at the preview stage.</p>
        <?php endif; ?>

        <?php if ($resultRecord): ?>
          <?php if (!empty($resultRecord['rolled_back'])): ?>
            <div class="alert alert-danger" role="alert"><strong>Batch rolled back.</strong> No residents from this batch were saved.</div>
          <?php else: ?>
            <div class="alert alert-success" role="status">Import transaction completed.</div>
          <?php endif; ?>
          <div class="row g-2 mb-3">
            <div class="col-4"><div class="border rounded p-3"><div class="small text-muted">Imported</div><div class="fs-4 fw-semibold"><?= (int) $resultRecord['imported'] ?></div></div></div>
            <div class="col-4"><div class="border rounded p-3"><div class="small text-muted">Skipped</div><div class="fs-4 fw-semibold"><?= (int) $resultRecord['skipped'] ?></div></div></div>
            <div class="col-4"><div class="border rounded p-3"><div class="small text-muted">Rejected</div><div class="fs-4 fw-semibold"><?= (int) $resultRecord['rejected'] ?></div></div></div>
          </div>
          <?php if (!empty($resultRecord['error_token'])): ?>
            <p><a class="btn btn-light border" href="<?= e(base_url('residents/import_errors.php?token=' . rawurlencode((string) $resultRecord['error_token']))) ?>"><i class="bi bi-download me-1"></i>Download skipped/rejected rows</a></p>
          <?php endif; ?>
          <p class="text-muted mb-0">Review the result and check the Residents page. The import does not modify existing resident records.</p>
        <?php endif; ?>

        <?php if ($previewRows && $stage && !$resultRecord): ?>
          <?php $counts = resident_import_counts($previewRows); ?>
          <div class="row g-2 mb-3">
            <div class="col-4"><div class="border rounded p-3"><div class="small text-muted">Ready</div><div class="fs-4 fw-semibold"><?= (int) $counts['ready'] ?></div></div></div>
            <div class="col-4"><div class="border rounded p-3"><div class="small text-muted">Skipped</div><div class="fs-4 fw-semibold"><?= (int) $counts['skipped'] ?></div></div></div>
            <div class="col-4"><div class="border rounded p-3"><div class="small text-muted">Rejected</div><div class="fs-4 fw-semibold"><?= (int) $counts['rejected'] ?></div></div></div>
          </div>
          <?php if (!empty($reportToken)): ?>
            <p><a class="btn btn-light border btn-sm" href="<?= e(base_url('residents/import_errors.php?token=' . rawurlencode((string) $reportToken))) ?>"><i class="bi bi-download me-1"></i>Download validation details</a></p>
          <?php endif; ?>
          <p class="small text-muted">Preview includes all rows below. Only rows marked Ready can be inserted; Skipped and Rejected rows will not be saved.</p>
          <div class="table-responsive mb-3"><table class="table table-sm table-b align-middle">
            <thead><tr><th>CSV row</th><th>Resident</th><th>Birthdate</th><th>Household</th><th>Result</th><th>Reason</th></tr></thead>
            <tbody>
            <?php foreach ($previewRows as $row): ?>
              <?php $v = $row['values']; $why = $row['status'] === 'skipped' ? $row['skip_reason'] : implode(' ', $row['errors']); ?>
              <tr>
                <td><?= (int) $row['line'] ?></td>
                <td><?= e(trim((string) ($v['last_name'] ?? '') . ', ' . (string) ($v['first_name'] ?? ''))) ?></td>
                <td><?= e((string) ($v['birthdate'] ?? '')) ?></td>
                <td><?= e((string) ($v['household_code'] ?? '')) ?></td>
                <td><?php if ($row['status'] === 'ready'): ?><?= pill('Ready', 'green') ?><?php elseif ($row['status'] === 'skipped'): ?><?= pill('Skipped', 'amber') ?><?php else: ?><?= pill('Rejected', 'red') ?><?php endif; ?></td>
                <td><?= e($why !== '' ? $why : 'Valid row; no changes have been saved.') ?></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table></div>
          <?php if ($counts['ready'] > 0): ?>
            <form method="post" action="<?= e(base_url('residents/import.php')) ?>">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="commit">
              <input type="hidden" name="import_token" value="<?= e((string) $stage['token']) ?>">
              <button class="btn btn-primary" type="submit"><i class="bi bi-check2-circle me-1"></i>Import <?= (int) $counts['ready'] ?> ready row(s)</button>
              <span class="small text-muted ms-2">Skipped/rejected rows will remain unchanged.</span>
            </form>
          <?php else: ?>
            <div class="alert alert-warning mb-0">There are no valid rows to import. Fix the CSV and preview it again.</div>
          <?php endif; ?>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>
<?php render_footer();
