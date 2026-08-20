/**
 * Cayden Link Shortener — two-way Google Sheet bridge (companion web app).
 *
 * WordPress POSTs its links here every few minutes. This script:
 *   1. Returns the spreadsheet's current rows so WordPress can apply the sheet's
 *      adds / edits / deletes.
 *   2. Mirrors WordPress's links back into the sheet — appending any that are
 *      missing (e.g. links staff made through the shortcode) and refreshing the
 *      read-only columns (source, clicks, short_url) for rows it already has.
 *
 * WordPress is the caller (WordPress -> Google), so this only needs to be
 * reachable from the public internet; it never calls back into WordPress.
 *
 * SHEET FORMAT (row 1 = headers, matched case-insensitively):
 *   slug         (you edit)  the short name; may include a "/" namespace
 *   destination  (you edit)  the real URL
 *   active       (you edit)  TRUE / FALSE
 *   delete       (you edit)  set TRUE to remove a sheet-made link
 *   source       (managed)   where the link came from (manual/shortcode/sheet)
 *   clicks       (managed)   click count from WordPress
 *   short_url    (managed)   the full short URL
 *
 * DEPLOY: Extensions -> Apps Script, paste this, set SECRET + SHEET_NAME, then
 * Deploy -> New deployment -> Web app -> Execute as: Me, Who has access: Anyone.
 * Put the /exec URL and the same SECRET into the plugin settings.
 */

// ---- Configuration -------------------------------------------------------
var SHEET_NAME = 'Links';
var SECRET     = ''; // must match the plugin's "Shared secret"; '' disables the check.
var HEADERS    = ['slug', 'destination', 'active', 'delete', 'source', 'clicks', 'short_url'];
// --------------------------------------------------------------------------

/**
 * Main entry point. WordPress POSTs { secret, links: [...] }.
 */
function doPost(e) {
  var body = {};
  try {
    body = ( e && e.postData && e.postData.contents ) ? JSON.parse(e.postData.contents) : {};
  } catch (err) {
    return json({ error: 'bad_json' });
  }

  if (SECRET !== '' && String(body.secret || '') !== SECRET) {
    return json({ error: 'unauthorized' });
  }

  var links = Array.isArray(body.links) ? body.links : [];
  var sheet = getSheet();
  ensureHeader(sheet);

  var table = readSheet(sheet);          // { bySlug, order, colIndex, rows }
  var rows  = buildReturnRows(table);    // the sheet's user-facing state -> WordPress

  mirrorWordPressLinks(sheet, table, links);

  return json({ rows: rows });
}

/**
 * Allow a simple GET for a browser sanity check.
 */
function doGet(e) {
  if (SECRET !== '' && (!e || !e.parameter || e.parameter.token !== SECRET)) {
    return json({ error: 'unauthorized' });
  }
  var table = readSheet(getSheet());
  return json({ rows: buildReturnRows(table) });
}

/* ----------------------------------------------------------------------- */

function getSheet() {
  var ss = SpreadsheetApp.getActiveSpreadsheet();
  return ss.getSheetByName(SHEET_NAME) || ss.insertSheet(SHEET_NAME);
}

function ensureHeader(sheet) {
  var first = sheet.getRange(1, 1, 1, HEADERS.length).getValues()[0];
  var empty = first.every(function (c) { return c === '' || c === null; });
  if (empty) {
    sheet.getRange(1, 1, 1, HEADERS.length).setValues([HEADERS]);
  }
}

/**
 * Read the sheet into a lookup keyed by slug, plus a header->column index map.
 */
function readSheet(sheet) {
  var values = sheet.getDataRange().getValues();
  var colIndex = {};
  if (values.length) {
    values[0].forEach(function (h, i) { colIndex[String(h).trim().toLowerCase()] = i; });
  }

  var bySlug = {};
  var order = [];
  for (var r = 1; r < values.length; r++) {
    var row = values[r];
    var slug = String(get(row, colIndex, 'slug')).trim();
    if (!slug) { continue; }
    bySlug[slug] = {
      rowNumber:   r + 1, // 1-based sheet row
      destination: String(get(row, colIndex, 'destination')).trim(),
      active:      toBool(get(row, colIndex, 'active'), true),
      delete:      toBool(get(row, colIndex, 'delete'), false)
    };
    order.push(slug);
  }
  return { bySlug: bySlug, order: order, colIndex: colIndex };
}

function buildReturnRows(table) {
  return table.order.map(function (slug) {
    var r = table.bySlug[slug];
    return { slug: slug, destination: r.destination, active: r.active, delete: r.delete };
  });
}

/**
 * Push WordPress's links into the sheet: refresh managed columns for existing
 * rows, and append any WordPress links the sheet does not have yet.
 */
function mirrorWordPressLinks(sheet, table, links) {
  var colIndex = table.colIndex;

  links.forEach(function (link) {
    var slug = String(link.slug || '').trim();
    if (!slug) { return; }

    if (table.bySlug[slug]) {
      // Existing row: only refresh the managed columns.
      var rowNumber = table.bySlug[slug].rowNumber;
      setCell(sheet, rowNumber, colIndex, 'source', link.source || '');
      setCell(sheet, rowNumber, colIndex, 'clicks', link.clicks || 0);
      setCell(sheet, rowNumber, colIndex, 'short_url', link.short_url || '');
    } else {
      // Missing (e.g. a shortcode link): append a full row.
      appendLink(sheet, colIndex, link);
    }
  });
}

function appendLink(sheet, colIndex, link) {
  var width = Math.max.apply(null, HEADERS.map(function (h) { return colIndex[h]; }).concat([HEADERS.length - 1])) + 1;
  var row = new Array(width).fill('');
  put(row, colIndex, 'slug', link.slug || '');
  put(row, colIndex, 'destination', link.destination || '');
  put(row, colIndex, 'active', link.active ? true : false);
  put(row, colIndex, 'delete', false);
  put(row, colIndex, 'source', link.source || '');
  put(row, colIndex, 'clicks', link.clicks || 0);
  put(row, colIndex, 'short_url', link.short_url || '');
  sheet.appendRow(row);
}

/* --- small helpers ------------------------------------------------------ */

function get(row, colIndex, name) {
  var i = colIndex[name];
  return (i === undefined || i >= row.length) ? '' : row[i];
}

function put(row, colIndex, name, value) {
  var i = colIndex[name];
  if (i !== undefined) { row[i] = value; }
}

function setCell(sheet, rowNumber, colIndex, name, value) {
  var i = colIndex[name];
  if (i !== undefined) { sheet.getRange(rowNumber, i + 1).setValue(value); }
}

function toBool(v, dflt) {
  if (v === '' || v === null || v === undefined) { return dflt; }
  if (typeof v === 'boolean') { return v; }
  var s = String(v).trim().toLowerCase();
  return s === 'true' || s === '1' || s === 'yes' || s === 'y';
}

function json(obj) {
  return ContentService.createTextOutput(JSON.stringify(obj)).setMimeType(ContentService.MimeType.JSON);
}
