/**
 * ACPS Link Shortener — Google Sheet feed (companion web app).
 *
 * WHAT THIS IS
 * ------------
 * A tiny, read-only Google Apps Script web app that exposes ONE Google Sheet as
 * JSON. The WordPress plugin polls it every 3 minutes and creates a short link
 * for each NEW row. That's the whole job.
 *
 * This is the transparent replacement for the snippet that was floating around
 * which called doPost() with a "photo_sync" action and pasted the result into a
 * hardcoded Google Doc via DocumentApp.openByUrl(...).getBody().setText(...).
 * This script deliberately does NONE of that: it never writes to a document,
 * never runs remote "actions", and only returns the rows of your sheet. All
 * link creation, validation and sanitization happen in WordPress.
 *
 * SHEET FORMAT
 * ------------
 * Put a header row in row 1 with (at least) these columns — order doesn't
 * matter, matching is by header name (case-insensitive):
 *
 *   slug          -> the shortened link name (e.g. "open-house")  [required]
 *   destination   -> the real URL to redirect to                  [required]
 *   title         -> optional friendly label
 *   redirect_type -> 301 or 302 (optional; blank = plugin default)
 *   active        -> TRUE/FALSE (optional; blank = TRUE)
 *
 * DEPLOY
 * ------
 * 1. Extensions -> Apps Script inside your Google Sheet, paste this file.
 * 2. Set SECRET below to a long random string (or leave '' for no auth).
 * 3. Set SHEET_NAME to the tab that holds the links.
 * 4. Deploy -> New deployment -> type "Web app".
 *      - Execute as: Me
 *      - Who has access: Anyone with the link
 * 5. Copy the /exec URL into the plugin's Settings -> Web app URL, and put the
 *    same SECRET in the plugin's "Shared secret" field.
 */

// ---- Configuration -------------------------------------------------------
var SHEET_NAME = 'Links';           // Tab name that holds the link rows.
var SECRET     = '';                // Shared secret; must match the plugin. '' = no check.
// --------------------------------------------------------------------------

/**
 * GET handler — returns the sheet as { "rows": [ ... ] } JSON.
 *
 * @param {Object} e Event with query parameters.
 * @return {ContentService.TextOutput}
 */
function doGet(e) {
  if (SECRET !== '') {
    var token = (e && e.parameter && e.parameter.token) ? e.parameter.token : '';
    if (token !== SECRET) {
      return json({ error: 'unauthorized' });
    }
  }
  return json({ rows: readRows() });
}

/**
 * POST handler — same behavior as GET so either verb works.
 *
 * @param {Object} e Event.
 * @return {ContentService.TextOutput}
 */
function doPost(e) {
  return doGet(e);
}

/**
 * Read the configured sheet into an array of row objects keyed by header.
 *
 * @return {Object[]}
 */
function readRows() {
  var ss = SpreadsheetApp.getActiveSpreadsheet();
  var sheet = ss.getSheetByName(SHEET_NAME) || ss.getSheets()[0];
  var values = sheet.getDataRange().getValues();
  if (values.length < 2) {
    return [];
  }

  var headers = values[0].map(function (h) {
    return String(h).trim().toLowerCase();
  });

  var rows = [];
  for (var i = 1; i < values.length; i++) {
    var raw = values[i];
    var obj = {};
    for (var c = 0; c < headers.length; c++) {
      if (headers[c]) {
        obj[headers[c]] = raw[c];
      }
    }
    // Skip blank rows (no slug or no destination).
    if (!obj.slug || !obj.destination) {
      continue;
    }
    rows.push({
      slug: String(obj.slug).trim(),
      destination: String(obj.destination).trim(),
      title: obj.title ? String(obj.title).trim() : '',
      redirect_type: obj.redirect_type ? Number(obj.redirect_type) : undefined,
      active: (obj.active === '' || obj.active === undefined) ? true : toBool(obj.active)
    });
  }
  return rows;
}

/**
 * Coerce a spreadsheet cell to a boolean.
 *
 * @param {*} v Value.
 * @return {boolean}
 */
function toBool(v) {
  if (typeof v === 'boolean') {
    return v;
  }
  var s = String(v).trim().toLowerCase();
  return s === 'true' || s === '1' || s === 'yes' || s === 'y';
}

/**
 * JSON response helper.
 *
 * @param {Object} obj Payload.
 * @return {ContentService.TextOutput}
 */
function json(obj) {
  return ContentService
    .createTextOutput(JSON.stringify(obj))
    .setMimeType(ContentService.MimeType.JSON);
}
