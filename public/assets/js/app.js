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
  document.querySelectorAll('[data-print]').forEach(function (b) {
    b.addEventListener('click', function () {
      var formId = b.getAttribute('data-print-record-form');
      if (!formId) { window.print(); return; }
      var form = document.getElementById(formId);
      if (!form) { return; }
      b.disabled = true;
      fetch(form.action, {
        method: 'POST',
        body: new FormData(form),
        credentials: 'same-origin',
        headers: { 'Accept': 'application/json' }
      }).then(function (response) {
        if (!response.ok) { throw new Error('Print event could not be recorded.'); }
        return response.json();
      }).then(function (result) {
        if (!result.ok) { throw new Error('Print event could not be recorded.'); }
        var counter = document.querySelector('[data-print-count]');
        if (counter && typeof result.print_count === 'number') {
          counter.textContent = 'Printed ' + result.print_count + ' time(s).';
        }
        window.print();
      }).catch(function () {
        window.alert('The print could not be recorded. Refresh the page and try again.');
      }).finally(function () {
        b.disabled = false;
      });
    });
  });

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

  // Lightweight document editor: toolbar writes a safe Markdown subset into the saved template.
  function escapePreview(value) {
    return String(value).replace(/&/g, '&amp;').replace(/</g, '&lt;')
      .replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#39;');
  }
  function inlinePreview(value) {
    var text = escapePreview(value);
    text = text.replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>');
    text = text.replace(/__(.+?)__/g, '<u>$1</u>');
    text = text.replace(/(^|[^*])\*([^*]+)\*(?!\*)/g, '$1<em>$2</em>');
    return text;
  }
  function documentPreview(source) {
    var html = [], paragraph = [], list = '';
    function flushParagraph() {
      if (paragraph.length) { html.push('<p>' + paragraph.map(inlinePreview).join('<br>') + '</p>'); paragraph = []; }
    }
    function closeList() { if (list) { html.push('</' + list + '>'); list = ''; } }
    String(source || '').replace(/\r\n?/g, '\n').split('\n').forEach(function (line) {
      var value = line.trim(), match;
      if (!value) { flushParagraph(); closeList(); return; }
      if (/^---+$/.test(value)) { flushParagraph(); closeList(); html.push('<hr>'); return; }
      match = value.match(/^(#{1,3})\s+(.+)$/);
      if (match) { flushParagraph(); closeList(); var level = match[1].length + 1; html.push('<h' + level + '>' + inlinePreview(match[2]) + '</h' + level + '>'); return; }
      match = value.match(/^[-*]\s+(.+)$/);
      if (match) { flushParagraph(); if (list !== 'ul') { closeList(); html.push('<ul>'); list = 'ul'; } html.push('<li>' + inlinePreview(match[1]) + '</li>'); return; }
      match = value.match(/^\d+[.)]\s+(.+)$/);
      if (match) { flushParagraph(); if (list !== 'ol') { closeList(); html.push('<ol>'); list = 'ol'; } html.push('<li>' + inlinePreview(match[1]) + '</li>'); return; }
      closeList(); paragraph.push(line);
    });
    flushParagraph(); closeList();
    return html.join('\n') || '<p class="text-muted">Start typing to see the document preview.</p>';
  }
  document.querySelectorAll('[data-document-editor]').forEach(function (editor) {
    var wrap = editor.closest('.document-editor-wrap');
    var preview = wrap ? wrap.querySelector('[data-document-preview]') : null;
    if (!wrap || !preview) { return; }
    function refresh() { preview.innerHTML = documentPreview(editor.value); }
    function insertText(value, wrapSelection) {
      if (editor.readOnly || editor.disabled) { return; }
      var start = editor.selectionStart, end = editor.selectionEnd;
      var selected = editor.value.slice(start, end);
      var inserted = wrapSelection ? value + (selected || 'text') + value : value;
      editor.setRangeText(inserted, start, end, 'select');
      editor.focus(); refresh();
    }
    wrap.querySelectorAll('[data-editor-wrap]').forEach(function (button) {
      button.addEventListener('click', function () { insertText(button.dataset.editorWrap, true); });
    });
    wrap.querySelectorAll('[data-editor-prefix]').forEach(function (button) {
      button.addEventListener('click', function () {
        if (editor.readOnly || editor.disabled) { return; }
        var start = editor.selectionStart, end = editor.selectionEnd;
        var value = editor.value.slice(start, end) || 'Text';
        var prefix = button.dataset.editorPrefix;
        var changed = prefix === '\n---\n' ? prefix + value + '\n' : value.split('\n').map(function (line) { return prefix + line; }).join('\n');
        editor.setRangeText(changed, start, end, 'select'); editor.focus(); refresh();
      });
    });
    wrap.querySelectorAll('[data-editor-token]').forEach(function (button) {
      button.addEventListener('click', function () { insertText(button.dataset.editorToken, false); });
    });
    editor.addEventListener('input', refresh);
    refresh();
  });
})();
