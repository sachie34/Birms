(function () {
  'use strict';
  var body = document.body;
  if (!body) return;
  var timeout = Number(body.getAttribute('data-kiosk-timeout') || 90000);
  if (!Number.isFinite(timeout) || timeout < 15000) timeout = 90000;
  var handle = null;
  function reset() {
    if (handle !== null) window.clearTimeout(handle);
    handle = window.setTimeout(function () {
      // end.php is POST-only to prevent cross-site logouts. Submit its server-rendered
      // CSRF form so the inactivity timer actually clears the resident session.
      var form = document.getElementById('kiosk-timeout-form');
      if (form && form.method.toLowerCase() === 'post') {
        form.submit();
      } else {
        window.location.replace('index.php?timeout=1');
      }
    }, timeout);
  }
  ['pointerdown', 'keydown', 'touchstart', 'click', 'input', 'change'].forEach(function (eventName) {
    document.addEventListener(eventName, reset, { passive: true });
  });
  reset();
}());
