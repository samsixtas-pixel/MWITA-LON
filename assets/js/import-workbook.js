/* ============================================================
   import-workbook.js — drag-and-drop upload with real progress
   Sends the workbook with XMLHttpRequest so the browser reports
   bytes sent (1% → 100%), then asks the server to analyze it.
   ============================================================ */
(function () {
  'use strict';

  const zone = document.getElementById('importDropZone');
  if (!zone) return;

  const endpoint = zone.dataset.endpoint || (window.BASE_URL + 'import.php');
  const input    = document.getElementById('workbookFile');
  const browse   = document.getElementById('workbookBrowse');
  const wrap     = document.getElementById('importProgressWrap');
  const bar      = document.getElementById('importBar');
  const percent  = document.getElementById('importPercent');
  const status   = document.getElementById('importStatus');
  const phase    = document.getElementById('analysisPhase');
  const analysisMessage = document.getElementById('analysisMessage');
  const errorBox = document.getElementById('importError');
  const csrf     = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';

  let busy = false;

  const strings = {
    uploading: 'Uploading…',
    uploaded: 'Upload complete. Analyzing…',
    ready: 'Ready. Opening the review…',
    failed: 'Upload failed',
    choose: 'Choose an Excel workbook (.xlsx or .xls).',
    uploadFailed: 'The upload failed. Please try again.',
    connection: 'The connection dropped during upload. Check the network and try again.',
    analyzeFailed: 'The workbook could not be analyzed.',
    retryStored: 'The analysis request failed. The file is stored; open “Previous imports” to retry.',
    analysisDone: 'Analysis complete.',
    steps: []
  };

  try {
    Object.assign(strings, JSON.parse(zone.dataset.strings || '{}'));
  } catch (error) {
    /* keep the English fallbacks */
  }

  const messages = Array.isArray(strings.steps) && strings.steps.length ? strings.steps : [
    'Upload complete. Reading customers, loans and payments…'
  ];

  function setPercent(value) {
    const pct = Math.max(1, Math.min(100, Math.round(value)));
    if (bar) bar.style.width = pct + '%';
    if (percent) percent.textContent = pct + '%';
    const progress = bar ? bar.closest('.progress') : null;
    if (progress) progress.setAttribute('aria-valuenow', String(pct));
  }

  function showError(message) {
    busy = false;
    if (errorBox) {
      errorBox.textContent = message;
      errorBox.classList.remove('d-none');
    }
    if (status) status.textContent = strings.failed;
    if (phase) phase.classList.add('d-none');
  }

  function reset() {
    busy = true;
    if (wrap) wrap.classList.remove('d-none');
    if (errorBox) {
      errorBox.textContent = '';
      errorBox.classList.add('d-none');
    }
    if (phase) phase.classList.add('d-none');
    if (status) status.textContent = strings.uploading;
    setPercent(1);
  }

  function selectedFile(file) {
    if (!file) return;

    const name = (file.name || '').toLowerCase();
    if (!/\.(xlsx|xls)$/.test(name)) {
      showError(strings.choose);
      return;
    }

    reset();

    const form = new FormData();
    form.append('action', 'upload');
    form.append('csrf_token', csrf);
    form.append('workbook', file);

    const xhr = new XMLHttpRequest();
    xhr.open('POST', endpoint, true);
    xhr.setRequestHeader('X-CSRF-Token', csrf);
    xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');

    xhr.upload.addEventListener('progress', function (event) {
      if (event.lengthComputable && event.total > 0) {
        setPercent((event.loaded / event.total) * 100);
      }
    });

    xhr.addEventListener('load', function () {
      let response = null;
      try { response = JSON.parse(xhr.responseText); } catch (e) { /* handled below */ }

      if (xhr.status < 200 || xhr.status >= 300 || !response || !response.ok) {
        showError((response && response.message) || strings.uploadFailed);
        return;
      }

      setPercent(100);
      if (status) status.textContent = strings.uploaded;
      if (phase) phase.classList.remove('d-none');

      // Keep the message moving while the server reads the sheets.
      let step = 0;
      const timer = window.setInterval(function () {
        step = (step + 1) % messages.length;
        if (analysisMessage) analysisMessage.textContent = messages[step];
      }, 2200);

      analyze(response.batch_id, timer);
    });

    xhr.addEventListener('error', function () {
      showError(strings.connection);
    });

    xhr.send(form);
  }

  function analyze(batchId, timer) {
    const body = 'action=analyze&batch_id=' + encodeURIComponent(batchId)
               + '&csrf_token=' + encodeURIComponent(csrf);

    const xhr = new XMLHttpRequest();
    xhr.open('POST', endpoint, true);
    xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
    xhr.setRequestHeader('X-CSRF-Token', csrf);
    xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');

    xhr.addEventListener('load', function () {
      window.clearInterval(timer);

      let response = null;
      try { response = JSON.parse(xhr.responseText); } catch (e) { /* handled below */ }

      if (xhr.status < 200 || xhr.status >= 300 || !response || !response.ok) {
        showError((response && response.message) || strings.analyzeFailed);
        return;
      }

      if (status) status.textContent = strings.ready;
      if (analysisMessage) analysisMessage.textContent = strings.analysisDone;

      window.setTimeout(function () {
        window.location.href = response.redirect;
      }, 550);
    });

    xhr.addEventListener('error', function () {
      window.clearInterval(timer);
      showError(strings.retryStored);
    });

    xhr.send(body);
  }

  if (browse) {
    browse.addEventListener('click', function (event) {
      event.stopPropagation();
      if (!busy && input) input.click();
    });
  }

  zone.addEventListener('click', function () {
    if (!busy && input) input.click();
  });

  zone.addEventListener('keydown', function (event) {
    if ((event.key === 'Enter' || event.key === ' ') && !busy && input) {
      event.preventDefault();
      input.click();
    }
  });

  if (input) {
    input.addEventListener('change', function () {
      selectedFile(input.files && input.files[0]);
    });
  }

  ['dragenter', 'dragover'].forEach(function (name) {
    zone.addEventListener(name, function (event) {
      event.preventDefault();
      event.stopPropagation();
      zone.classList.add('dragging');
    });
  });

  ['dragleave', 'drop'].forEach(function (name) {
    zone.addEventListener(name, function (event) {
      event.preventDefault();
      event.stopPropagation();
      zone.classList.remove('dragging');
    });
  });

  zone.addEventListener('drop', function (event) {
    if (busy) return;
    const file = event.dataTransfer && event.dataTransfer.files ? event.dataTransfer.files[0] : null;
    selectedFile(file);
  });
})();
