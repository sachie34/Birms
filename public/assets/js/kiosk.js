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
      window.location.replace('end.php?timeout=1');
    }, timeout);
  }
  ['pointerdown', 'keydown', 'touchstart', 'click', 'input', 'change'].forEach(function (eventName) {
    document.addEventListener(eventName, reset, { passive: true });
  });
  reset();
}());
