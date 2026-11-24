(function () {
    'use strict';

    function ready(callback) {
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', callback, { once: true });
        } else {
            callback();
        }
    }

    ready(function () {
        var target = document.getElementById('resident-request-qr');
        var link = document.getElementById('resident-request-link');
        var button = document.getElementById('download-request-qr');
        var message = document.getElementById('qr-message');

        if (!target || !link || !button || typeof QRCode === 'undefined') {
            if (message) message.textContent = 'The local QR generator could not be loaded. Check the installed BIRMS assets.';
            return;
        }

        try {
            var candidate = new URL(link.getAttribute('href'), window.location.href);
            if (candidate.protocol !== 'http:' && candidate.protocol !== 'https:') {
                throw new Error('Unsupported URL scheme.');
            }

            var url = candidate.href;
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

                if (canvas && typeof canvas.toDataURL === 'function') {
                    dataUrl = canvas.toDataURL('image/png');
                } else if (image && image.src) {
                    dataUrl = image.src;
                }

                if (!dataUrl || !/^data:image\/(png|gif);base64,/.test(dataUrl)) {
                    message.textContent = 'The browser could not export the QR image. Use the browser print command instead.';
                    return;
                }

                var anchor = document.createElement('a');
                anchor.href = dataUrl;
                anchor.download = 'birms-resident-self-service-qr.png';
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
    });
}());
