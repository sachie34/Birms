<?php
/** Printable shared QR for the public document-request entry. It contains no resident-specific data. */
declare(strict_types=1);
require_once __DIR__ . '/../../app/bootstrap.php';

require_login();
require_permission('settings.view');
$relativePath = base_url('kiosk/index.php?next=request');

render_header('Resident Request QR', [['Administration', null], ['Resident Request QR', null]], 'request_qr');
page_head('Resident Document Request QR', 'Print this shared QR code and post it at the barangay hall or kiosk. It opens the resident document-request flow; each resident must verify their identity first.');
?>
<div class="row g-3">
  <div class="col-lg-7">
    <div class="card-b">
      <div class="card-h"><h2>Shared request QR code</h2><p>The QR contains only the public document-request entry link. It does not contain a Resident ID, birthdate, name, or other personal information.</p></div>
      <div class="card-body-b text-center">
        <div id="resident-request-qr" class="d-inline-flex align-items-center justify-content-center bg-white border rounded-3 p-3" style="min-width:340px;min-height:340px" aria-label="QR code for resident document requests"></div>
        <p class="mt-3 mb-1"><strong>Document request entry</strong></p>
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
          <li>Residents enter their Resident ID and birthdate, or use the name and birthdate verification process.</li>
        </ol>
        <div class="alert alert-info mt-3 mb-0">For phones on the barangay Wi-Fi, the QR link must use an address that those phones can reach. Check the link on a phone before printing many copies.</div>
      </div>
    </div>
  </div>
</div>
<script src="<?= e(base_url('assets/vendor/qrcodejs/qrcode.min.js')) ?>"></script>
<script>
(function () {
    'use strict';
    var target = document.getElementById('resident-request-qr');
    var link = document.getElementById('resident-request-link');
    var button = document.getElementById('download-request-qr');
    var message = document.getElementById('qr-message');
    if (!target || !link || !button || typeof QRCode === 'undefined') {
        if (message) message.textContent = 'The local QR generator could not be loaded. Check the installed BIRMS assets.';
        return;
    }
    var url;
    try {
        var candidate = new URL(link.getAttribute('href'), window.location.href);
        if (candidate.protocol !== 'http:' && candidate.protocol !== 'https:') throw new Error('Unsupported URL scheme.');
        url = candidate.href;
        link.href = url;
        link.textContent = url;
        new QRCode(target, {
            text: url,
            width: 320,
            height: 320,
            colorDark: '#101820',
            colorLight: '#ffffff',
            correctLevel: QRCode.CorrectLevel.H
        });
        button.disabled = false;
        message.textContent = 'QR ready. Scan it with a phone connected to the barangay network to verify the address.';
        button.addEventListener('click', function () {
            var canvas = target.querySelector('canvas');
            var image = target.querySelector('img');
            var dataUrl = null;
            if (canvas && typeof canvas.toDataURL === 'function') dataUrl = canvas.toDataURL('image/png');
            else if (image && image.src) dataUrl = image.src;
            if (!dataUrl || !/^data:image\/(png|gif);base64,/.test(dataUrl)) {
                message.textContent = 'The browser could not export the QR image. Use the browser print command instead.';
                return;
            }
            var anchor = document.createElement('a');
            anchor.href = dataUrl;
            anchor.download = 'birms-resident-document-request-qr.png';
            document.body.appendChild(anchor);
            anchor.click();
            anchor.remove();
        });
        if (window.location.hostname === 'localhost' || window.location.hostname === '127.0.0.1') {
            message.textContent = 'Warning: this QR currently uses localhost. Open this page from the barangay LAN address before downloading.';
            message.className = 'text-warning small mt-2';
        }
    } catch (error) {
        message.textContent = 'The request QR could not be generated. Check the server address and try again.';
    }
}());
</script>
<?php render_footer(); ?>
