<?php
/** Printable shared QR for the public resident self-service kiosk. It contains no resident-specific data. */
declare(strict_types=1);
require_once __DIR__ . '/../../app/bootstrap.php';

require_login();
require_permission('settings.view');
$relativePath = base_url('kiosk/index.php');

render_header('Resident Self-Service QR', [['Administration', null], ['Resident Self-Service QR', null]], 'request_qr');
page_head('Resident Self-Service QR', 'Print this shared QR code and post it at the barangay hall or kiosk. It opens one public service menu for document requests, blotter reports, venue reservations, queue tickets and request tracking. Each resident must verify their identity before accessing personal services.');
?>
<link rel="stylesheet" href="<?= e(base_url('assets/css/request_qr.css')) ?>">
<div class="row g-3">
  <div class="col-lg-7">
    <div class="card-b">
      <div class="card-h"><h2>One QR code for all resident services</h2><p>The QR opens the public BIRMS self-service menu. Residents can choose Document Request (including Indigency), Blotter / Complaint, Venue Reservation, Get a Queue Number or Track My Request after identity verification. The QR contains no Resident ID, birthdate, name or other personal information.</p></div>
      <div class="card-body-b text-center">
        <div id="resident-request-qr" class="resident-request-qr" role="img" aria-label="QR code for the BIRMS resident self-service menu"></div>
        <p class="mt-3 mb-1"><strong>Scan for barangay services</strong></p>
        <p class="text-break"><a id="resident-request-link" href="<?= e($relativePath) ?>"><?= e($relativePath) ?></a></p>
        <button id="download-request-qr" class="btn btn-primary mt-2" type="button" disabled>Download QR image</button>
        <p id="qr-message" class="text-muted small mt-2" role="status">Preparing the QR code…</p>
      </div>
    </div>
  </div>
  <div class="col-lg-5">
    <div class="card-b">
      <div class="card-h"><h2>Before printing</h2></div>
      <div class="card-body-b">
        <ol class="mb-0">
          <li>Open this page using the barangay server’s LAN address, not <code>localhost</code> or <code>127.0.0.1</code>.</li>
          <li>Download and print the QR code.</li>
          <li>Post it where residents can scan it using their phone camera.</li>
          <li>Residents verify their identity, then choose the service they need from the menu. Document types include Indigency when enabled by the barangay.</li>
        </ol>
        <div class="alert alert-info mt-3 mb-0">For phones on the barangay Wi-Fi, the QR link must use an address that those phones can reach. Check the link on a phone before printing many copies.</div>
      </div>
    </div>
  </div>
</div>
<script defer src="<?= e(base_url('assets/vendor/qrcodejs/qrcode.min.js')) ?>"></script>
<script defer src="<?= e(base_url('assets/js/request_qr.js')) ?>"></script>
<?php render_footer(); ?>
