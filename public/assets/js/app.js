/* BIRMS UI behaviour. No inline scripts are used anywhere (strict Content-Security-Policy). */
(function () {
  'use strict';

  // Sidebar collapse (remembered in this browser only)
  var shell = document.getElementById('appShell');
  var toggle = document.getElementById('sidebarToggle');
  if (shell) {
    try { if (localStorage.getItem('birms.sidebar') === 'collapsed') { shell.classList.add('collapsed'); } } catch (e) {}
    if (toggle) {
      toggle.addEventListener('click', function () {
        shell.classList.toggle('collapsed');
        try { localStorage.setItem('birms.sidebar', shell.classList.contains('collapsed') ? 'collapsed' : 'open'); } catch (e) {}
      });
    }
  }

  // Confirmation dialog for forms that carry data-confirm="..."
  var modalEl = document.getElementById('confirmModal');
  if (modalEl && window.bootstrap) {
    var modal = new window.bootstrap.Modal(modalEl);
    var pending = null;
    document.addEventListener('submit', function (ev) {
      var f = ev.target;
      if (f && f.dataset && f.dataset.confirm && !f.dataset.confirmed) {
        ev.preventDefault();
        pending = f;
        document.getElementById('confirmText').textContent = f.dataset.confirm;
        modal.show();
      }
    });
    document.getElementById('confirmOk').addEventListener('click', function () {
      if (pending) { pending.dataset.confirmed = '1'; modal.hide(); pending.submit(); }
    });
  }

  // Print buttons (no inline scripts are allowed)
  document.querySelectorAll('[data-print]').forEach(function (b) { b.addEventListener('click', function () { window.print(); }); });

  // Client-side checks (convenience only: the server always re-validates)
  document.querySelectorAll('form.needs-validation').forEach(function (f) {
    var pw = f.querySelector('[data-match-source]');
    var pw2 = f.querySelector('[data-match-target]');
    function matchCheck() {
      if (pw && pw2) { pw2.setCustomValidity(pw.value === pw2.value ? '' : 'Passwords do not match'); }
    }
    if (pw && pw2) { pw.addEventListener('input', matchCheck); pw2.addEventListener('input', matchCheck); }
    f.addEventListener('submit', function (ev) {
      matchCheck();
      if (!f.checkValidity()) { ev.preventDefault(); ev.stopPropagation(); }
      f.classList.add('was-validated');
    }, false);
  });
})();
