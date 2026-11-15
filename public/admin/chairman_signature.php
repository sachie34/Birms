<?php
/** Admin-managed private Chairman/Punong Barangay signature image. */
declare(strict_types=1);
require_once __DIR__ . '/../../app/bootstrap.php';

require_login();
require_permission('settings.edit');
$errors = [];

if (is_post()) {
    require_csrf();
    $action = post_str('do');
    try {
        if ($action === 'upload') {
            $relativePath = chairman_signature_store_upload($_FILES['signature_file'] ?? []);
            try {
                db_transaction(function () use ($relativePath): void {
                    db_exec(
                        "INSERT INTO settings (setting_key, setting_value) VALUES ('chairman_signature_path', ?)
                         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)",
                        [$relativePath]
                    );
                    audit('chairman_signature_uploaded', 'Settings', 'Success', 'Admin uploaded a new Chairman signature image.', 'settings', null);
                });
            } catch (Throwable $e) {
                $absolute = chairman_signature_path_to_absolute($relativePath);
                if ($absolute !== null) { @unlink($absolute); }
                throw $e;
            }
            flash_add('success', 'Chairman signature image saved. New certificates will use this version automatically.');
            redirect('admin/settings.php', ['tab' => 'chairman_signature']);
        }
        if ($action === 'remove') {
            db_transaction(function (): void {
                db_exec(
                    "INSERT INTO settings (setting_key, setting_value) VALUES ('chairman_signature_path', NULL)
                     ON DUPLICATE KEY UPDATE setting_value = NULL"
                );
                audit('chairman_signature_removed', 'Settings', 'Success', 'Admin disabled the Chairman signature for future certificate issuance.', 'settings', null);
            });
            flash_add('success', 'The current Chairman signature has been disabled for future issuances. Existing certificates retain their original signature snapshot.');
            redirect('admin/settings.php', ['tab' => 'chairman_signature']);
        }
        abort(400, 'Unknown signature action.');
    } catch (InvalidArgumentException $e) {
        $errors[] = $e->getMessage();
    } catch (Throwable $e) {
        error_log('BIRMS Chairman signature update failed: ' . $e->getMessage());
        $errors[] = 'The signature image could not be updated. No success was recorded. Check the server error log and private storage permissions.';
    }
}

$currentPath = chairman_signature_current_relative();
$currentImage = $currentPath !== null ? chairman_signature_path_to_absolute($currentPath) : null;
render_header('Chairman Signature', [['Administration', null], ['Settings', 'admin/settings.php?tab=chairman_signature'], ['Chairman Signature', null]], 'settings');
page_head('Chairman / Punong Barangay Signature', 'Admin uploads the official signature image. The system snapshots it automatically when an eligible certificate is issued.');
echo errors_box($errors);
if ($currentImage !== null) {
    echo '<div class="card-b mb-3"><div class="card-h"><h2>Current signature</h2><p>This image is used for new certificates that require the Chairman signature.</p></div><div class="card-body-b">'
        . '<img class="chairman-signature-preview" src="' . e(base_url('admin/chairman_signature_image.php')) . '" alt="Current Chairman signature">'
        . '</div></div>';
} else {
    echo '<div class="alert alert-warning">No valid Chairman signature image is configured. Certificate types marked as requiring the signature cannot be issued until Admin uploads one.</div>';
}
echo '<div class="card-b mb-3"><div class="card-h"><h2>Upload signature image</h2><p>Use a clear JPEG/JPG scan on a plain background. The file is stored outside the public web folder.</p></div><div class="card-body-b">'
    . '<form method="post" enctype="multipart/form-data" action="' . e(base_url('admin/chairman_signature.php')) . '">'
    . csrf_field() . '<input type="hidden" name="do" value="upload">'
    . '<div class="mb-3"><label class="form-label" for="signature_file">Chairman signature JPG</label>'
    . '<input class="form-control" type="file" id="signature_file" name="signature_file" accept=".jpg,.jpeg,image/jpeg" required>'
    . '<div class="form-text">JPG/JPEG only · up to 2 MB · 160×60 to 3000×1600 pixels. A renamed PNG/PDF is refused.</div></div>'
    . '<button class="btn btn-primary" type="submit">Upload and use signature</button></form></div></div>';
if ($currentImage !== null) {
    echo '<div class="card-b"><div class="card-h"><h2>Disable for future certificates</h2><p>Previously issued certificates keep their stored signature image.</p></div><div class="card-body-b">'
        . '<form method="post" action="' . e(base_url('admin/chairman_signature.php')) . '" data-confirm="Disable the current Chairman signature for future certificate issuance?">'
        . csrf_field() . '<input type="hidden" name="do" value="remove">'
        . '<button class="btn btn-outline-danger" type="submit">Disable current signature</button></form></div></div>';
}
render_footer();
