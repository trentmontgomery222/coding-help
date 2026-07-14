/**
 * WebApp.gs — the doPost API that the WordPress plugin polls.
 *
 * Deployment (critical — see build brief §3b):
 *   Execute as:     Me (the script owner)
 *   Who has access: Anyone
 * To ship changes WITHOUT changing the URL: Deploy > Manage deployments >
 * edit the existing deployment > Version: "New version".
 *
 * All requests are POST with a JSON body carrying `token`. The token is
 * the only barrier on a public URL — validate it before anything else.
 * All responses are JSON via ContentService (an uncaught exception would
 * return an HTML error page and break the plugin's JSON parsing, so
 * everything is wrapped in try/catch).
 */

function doPost(e) {
  var payload;
  try {
    payload = JSON.parse((e && e.postData && e.postData.contents) || '{}');
  } catch (err) {
    return jsonResponse_({ ok: false, error: 'invalid_json', message: 'Request body was not valid JSON.' });
  }

  try {
    if (!tokenMatches_(payload.token)) {
      return jsonResponse_({ ok: false, error: 'unauthorized', message: 'Invalid or missing token.' });
    }

    switch (payload.action) {
      case 'pending':
        return jsonResponse_(handlePending_(payload));
      case 'file':
        return jsonResponse_(handleFile_(payload));
      case 'ack':
        return jsonResponse_(handleAck_(payload));
      default:
        return jsonResponse_({ ok: false, error: 'unknown_action', message: 'Unknown action: ' + String(payload.action) });
    }
  } catch (err) {
    console.error('doPost failure: ' + err + (err && err.stack ? '\n' + err.stack : ''));
    return jsonResponse_({ ok: false, error: 'internal_error', message: String(err && err.message || err) });
  }
}

/** Simple health check so the URL can be sanity-tested in a browser. */
function doGet() {
  return jsonResponse_({ ok: true, service: 'drive-media-pipeline', message: 'POST JSON to this URL.' });
}

// ---------------------------------------------------------------------------
// action: "pending" — claim up to `limit` pending rows (flip to processing).
// ---------------------------------------------------------------------------
function handlePending_(payload) {
  var limit = Math.max(1, Math.min(parseInt(payload.limit, 10) || 10, 50));
  var lock = LockService.getScriptLock();
  lock.waitLock(30000);
  try {
    var sheet = getQueueSheet_();
    sweepStaleProcessing_(sheet);

    var lastRow = sheet.getLastRow();
    if (lastRow < 2) return { ok: true, rows: [] };

    var data = sheet.getRange(2, 1, lastRow - 1, COLUMNS.length).getValues();
    var claimed = [];
    var nowIso = new Date().toISOString();

    for (var i = 0; i < data.length && claimed.length < limit; i++) {
      if (String(data[i][COL.status - 1]) !== STATUS_PENDING) continue;
      var sheetRow = i + 2;
      // Claim BEFORE returning: flip to processing and stamp the claim time
      // (reusing timestamp column semantics is avoided — we track staleness
      // via a note on the status cell so the original queue time is kept).
      var statusCell = sheet.getRange(sheetRow, COL.status);
      statusCell.setValue(STATUS_PROCESSING);
      statusCell.setNote('claimed_at=' + nowIso);
      claimed.push({
        row_id: String(data[i][COL.row_id - 1]),
        file_id: String(data[i][COL.file_id - 1]),
        filename: String(data[i][COL.filename - 1]),
        alt_text: String(data[i][COL.alt_text - 1]),
        location: String(data[i][COL.location - 1]),
        uploader: String(data[i][COL.uploader - 1]),
        target_site: String(data[i][COL.target_site - 1])
      });
    }
    return { ok: true, rows: claimed };
  } finally {
    lock.releaseLock();
  }
}

/**
 * Reset rows stuck in "processing" longer than STALE_MINUTES back to
 * "pending" (WordPress crashed after claiming but before acking).
 * Runs at the start of each pending call, inside the caller's lock.
 */
function sweepStaleProcessing_(sheet) {
  var lastRow = sheet.getLastRow();
  if (lastRow < 2) return;
  var staleMs = getStaleMinutes_() * 60 * 1000;
  var now = Date.now();
  var statuses = sheet.getRange(2, COL.status, lastRow - 1, 1);
  var values = statuses.getValues();
  var notes = statuses.getNotes();

  for (var i = 0; i < values.length; i++) {
    if (String(values[i][0]) !== STATUS_PROCESSING) continue;
    var match = /claimed_at=([^\s]+)/.exec(notes[i][0] || '');
    var claimedAt = match ? Date.parse(match[1]) : NaN;
    if (isNaN(claimedAt) || (now - claimedAt) > staleMs) {
      var cell = sheet.getRange(i + 2, COL.status);
      cell.setValue(STATUS_PENDING);
      cell.setNote('');
    }
  }
}

// ---------------------------------------------------------------------------
// action: "file" — return base64 bytes for a row currently in processing.
// ---------------------------------------------------------------------------
function handleFile_(payload) {
  var rowId = String(payload.row_id || '');
  var fileId = String(payload.file_id || '');
  if (!rowId || !fileId) {
    return { ok: false, error: 'bad_request', message: 'row_id and file_id are required.' };
  }

  // Security check: only serve files referenced by a row currently in
  // "processing". A leaked token must not become a Drive exfiltration key.
  var sheet = getQueueSheet_();
  var row = findRowByRowId_(sheet, rowId);
  if (!row || String(row.values[COL.file_id - 1]) !== fileId ||
      String(row.values[COL.status - 1]) !== STATUS_PROCESSING) {
    return { ok: false, error: 'forbidden', message: 'File is not part of a row currently being processed.' };
  }

  var file;
  try {
    file = DriveApp.getFileById(fileId);
  } catch (err) {
    return { ok: false, error: 'file_not_found', message: 'File no longer exists in Drive or is not accessible.' };
  }

  var blob = file.getBlob();
  var bytes = blob.getBytes();
  var maxBytes = getMaxFileBytes_();
  if (bytes.length > maxBytes) {
    return {
      ok: false,
      error: 'file_too_large',
      message: 'File is ' + bytes.length + ' bytes; the cap is ' + maxBytes + ' bytes. Resize the image and re-queue it.'
    };
  }

  return {
    ok: true,
    row_id: rowId,
    file_id: fileId,
    filename: file.getName(),
    mime_type: blob.getContentType(),
    byte_length: bytes.length,
    data_base64: Utilities.base64Encode(bytes)
  };
}

// ---------------------------------------------------------------------------
// action: "ack" — write outcomes back to the sheet (batched).
// ---------------------------------------------------------------------------
function handleAck_(payload) {
  var results = payload.results;
  if (!Array.isArray(results)) {
    return { ok: false, error: 'bad_request', message: 'results must be an array.' };
  }

  var lock = LockService.getScriptLock();
  lock.waitLock(30000);
  try {
    var sheet = getQueueSheet_();
    var updated = 0;
    var missing = [];

    results.forEach(function (r) {
      var row = findRowByRowId_(sheet, String(r.row_id || ''));
      if (!row) { missing.push(r.row_id); return; }
      var status = (r.status === STATUS_DONE) ? STATUS_DONE : STATUS_ERROR;
      var sheetRow = row.rowNumber;
      var statusCell = sheet.getRange(sheetRow, COL.status);
      statusCell.setValue(status);
      statusCell.setNote('');
      sheet.getRange(sheetRow, COL.wp_url).setValue(String(r.wp_url || ''));
      sheet.getRange(sheetRow, COL.wp_attachment_id).setValue(String(r.wp_attachment_id || ''));
      sheet.getRange(sheetRow, COL.error_message).setValue(String(r.error_message || ''));
      updated++;
    });

    return { ok: true, updated: updated, missing: missing };
  } finally {
    lock.releaseLock();
  }
}

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

/** Find a row by its row_id UUID. Returns {rowNumber, values} or null. */
function findRowByRowId_(sheet, rowId) {
  if (!rowId) return null;
  var lastRow = sheet.getLastRow();
  if (lastRow < 2) return null;
  var data = sheet.getRange(2, 1, lastRow - 1, COLUMNS.length).getValues();
  for (var i = 0; i < data.length; i++) {
    if (String(data[i][COL.row_id - 1]) === rowId) {
      return { rowNumber: i + 2, values: data[i] };
    }
  }
  return null;
}

function jsonResponse_(obj) {
  return ContentService.createTextOutput(JSON.stringify(obj))
    .setMimeType(ContentService.MimeType.JSON);
}
