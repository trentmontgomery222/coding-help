/**
 * Config.gs — central configuration for the Drive → Sheet → WordPress pipeline.
 *
 * All secrets and environment-specific values live in Script Properties
 * (File > Project Settings > Script Properties), never in code:
 *
 *   SHARED_TOKEN     Required. Long random string (32+ bytes hex/base64).
 *                    Must match the token configured in the WordPress plugin.
 *   QUEUE_SHEET_ID   Required. Spreadsheet ID of the queue sheet.
 *   TARGET_SITES     Optional. JSON object mapping multisite blog IDs to
 *                    display labels, e.g. {"1":"Main site","3":"Athletics"}.
 *                    If it contains exactly one entry the dropdown is omitted
 *                    and that site is used automatically.
 *   MAX_FILE_BYTES   Optional. Size cap for the "file" action (Option A).
 *                    Default 20971520 (20 MB) raw bytes.
 *   STALE_MINUTES    Optional. Minutes after which a "processing" row is
 *                    swept back to "pending". Default 30.
 */

var SHEET_NAME = 'queue';

var COLUMNS = [
  'row_id',
  'timestamp',
  'file_id',
  'filename',
  'alt_text',
  'location',
  'uploader',
  'target_site',
  'status',
  'wp_url',
  'wp_attachment_id',
  'error_message'
];

// 1-based column indexes derived from COLUMNS.
var COL = (function () {
  var m = {};
  COLUMNS.forEach(function (name, i) { m[name] = i + 1; });
  return m;
})();

var STATUS_PENDING = 'pending';
var STATUS_PROCESSING = 'processing';
var STATUS_DONE = 'done';
var STATUS_ERROR = 'error';

function getProps_() {
  return PropertiesService.getScriptProperties();
}

function getSharedToken_() {
  var t = getProps_().getProperty('SHARED_TOKEN');
  if (!t) throw new Error('SHARED_TOKEN script property is not set.');
  return t;
}

function getQueueSheet_() {
  var id = getProps_().getProperty('QUEUE_SHEET_ID');
  if (!id) throw new Error('QUEUE_SHEET_ID script property is not set.');
  var ss = SpreadsheetApp.openById(id);
  var sheet = ss.getSheetByName(SHEET_NAME) || ss.getSheets()[0];
  ensureHeaderRow_(sheet);
  return sheet;
}

function ensureHeaderRow_(sheet) {
  if (sheet.getLastRow() === 0) {
    sheet.appendRow(COLUMNS);
    sheet.setFrozenRows(1);
  }
}

function getTargetSites_() {
  var raw = getProps_().getProperty('TARGET_SITES');
  if (!raw) return { '1': 'Main site' };
  try {
    var parsed = JSON.parse(raw);
    if (parsed && typeof parsed === 'object' && Object.keys(parsed).length) return parsed;
  } catch (e) { /* fall through to default */ }
  return { '1': 'Main site' };
}

function getMaxFileBytes_() {
  var v = parseInt(getProps_().getProperty('MAX_FILE_BYTES'), 10);
  return (v > 0) ? v : 20 * 1024 * 1024;
}

function getStaleMinutes_() {
  var v = parseInt(getProps_().getProperty('STALE_MINUTES'), 10);
  return (v > 0) ? v : 30;
}

/**
 * Constant-time-ish token comparison. Apps Script has no native
 * timing-safe compare; hashing both sides first removes the
 * length/prefix side channel from a naive string compare.
 */
function tokenMatches_(candidate) {
  if (typeof candidate !== 'string' || candidate.length === 0) return false;
  var expected = getSharedToken_();
  var a = Utilities.computeDigest(Utilities.DigestAlgorithm.SHA_256, candidate);
  var b = Utilities.computeDigest(Utilities.DigestAlgorithm.SHA_256, expected);
  var diff = 0;
  for (var i = 0; i < a.length; i++) diff |= (a[i] ^ b[i]);
  return diff === 0;
}
