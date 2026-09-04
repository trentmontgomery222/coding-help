/**
 * Telemetry.gs
 * ---------------------------------------------------------------------------
 * Device registry, log capture, usage stats and the remote command queue.
 *
 * The previous version wrote a row to a Sheet (or a Drive doc) for every
 * single log line, from a client that forwarded every console.log. On a kiosk
 * that runs for twelve hours that is tens of thousands of writes, and it was
 * competing for quota with the image sync.
 *
 * Now: the client batches, and the server appends in bulk with a single
 * setValues() per flush.
 * ---------------------------------------------------------------------------
 */

var LOG_SHEET_NAME_    = 'Logs';
var STATS_SHEET_NAME_  = 'Stats';
var DEVICE_SHEET_NAME_ = 'Devices';
var LOG_MAX_ROWS_      = 20000;   // trimmed from the top when exceeded

/** Where operational data goes. Separate from the settings sheet by default. */
function Telemetry_spreadsheetId_() {
  var props = PropertiesService.getScriptProperties();
  return props.getProperty('TELEMETRY_SHEET_ID') || Config_spreadsheetId_();
}

/** Gets a sheet, creating it with headers if it is missing. */
function Telemetry_sheet_(name, headers) {
  var ss = SpreadsheetApp.openById(Telemetry_spreadsheetId_());
  var sheet = ss.getSheetByName(name);
  if (!sheet) {
    sheet = ss.insertSheet(name);
    sheet.appendRow(headers);
    sheet.setFrozenRows(1);
  }
  return sheet;
}

/* ===========================================================================
 * Devices
 * =========================================================================*/

/**
 * Records that a kiosk checked in and returns what we know about it, so the
 * client can greet the right person.
 *
 * The old version stored this as a JSON blob inside a Drive file, read and
 * rewrote the whole blob on every page load, and had no locking - two kiosks
 * starting at once would clobber each other's entries.
 *
 * @param {string} deviceId Client-generated UUID.
 * @return {{id:string, name:string, firstSeen:string, lastSeen:string}}
 */
function registerDevice(deviceId) {
  var id = String(deviceId || '').trim();
  if (!id) return {id: '', name: '', firstSeen: '', lastSeen: ''};

  var lock = LockService.getScriptLock();
  try {
    lock.waitLock(10000);

    var sheet = Telemetry_sheet_(
        DEVICE_SHEET_NAME_, ['Device ID', 'Name', 'First Seen', 'Last Seen', 'Visits']);
    var values = sheet.getDataRange().getValues();
    var now = new Date();

    for (var i = 1; i < values.length; i++) {
      if (String(values[i][0]) === id) {
        sheet.getRange(i + 1, 4).setValue(now);
        sheet.getRange(i + 1, 5).setValue(Number(values[i][4] || 0) + 1);
        return {
          id: id,
          name: String(values[i][1] || ''),
          firstSeen: Telemetry_iso_(values[i][2]),
          lastSeen: now.toISOString()
        };
      }
    }

    sheet.appendRow([id, '', now, now, 1]);
    return {id: id, name: '', firstSeen: now.toISOString(), lastSeen: now.toISOString()};
  } catch (err) {
    Log_error_('registerDevice', err);
    return {id: id, name: '', firstSeen: '', lastSeen: ''};
  } finally {
    try { lock.releaseLock(); } catch (e) { /* never held */ }
  }
}

function Telemetry_iso_(value) {
  if (value instanceof Date) return value.toISOString();
  return String(value || '');
}

/** Every registered kiosk. Used by the admin view. */
function listDevices() {
  var sheet = Telemetry_sheet_(
      DEVICE_SHEET_NAME_, ['Device ID', 'Name', 'First Seen', 'Last Seen', 'Visits']);
  var values = sheet.getDataRange().getValues();
  var out = [];
  for (var i = 1; i < values.length; i++) {
    if (!values[i][0]) continue;
    out.push({
      id: String(values[i][0]),
      name: String(values[i][1] || ''),
      firstSeen: Telemetry_iso_(values[i][2]),
      lastSeen: Telemetry_iso_(values[i][3]),
      visits: Number(values[i][4] || 0)
    });
  }
  return out;
}

/* ===========================================================================
 * Logs
 * =========================================================================*/

/**
 * Appends a batch of client log entries in a single write.
 *
 * @param {!Array<{level:string, message:string, at:(string|undefined)}>} entries
 * @param {string} sessionKey
 * @param {string} deviceId
 * @return {{written:number}}
 */
function logBatch(entries, sessionKey, deviceId) {
  var cfg = Config_get();
  if (!cfg.EnableLogs) return {written: 0};
  if (!entries || !entries.length) return {written: 0};

  // Optional per-session log spreadsheets (see SessionLog.gs). The sheet is
  // created lazily on the session's first log line, so a kiosk that never
  // logs anything never creates a file.
  if (String(cfg.LogDestination).toLowerCase() === 'session') {
    var spreadsheetId = Telemetry_sessionLogId_(sessionKey);
    if (spreadsheetId) {
      return {written: appendLogSession(spreadsheetId, entries),
              destination: spreadsheetId};
    }
    // Creating it failed - fall through to the shared sheet rather than
    // silently dropping the logs.
  }

  try {
    var sheet = Telemetry_sheet_(
        LOG_SHEET_NAME_,
        ['Timestamp', 'Session', 'Device', 'Level', 'Message']);

    var rows = entries.slice(0, 200).map(function (entry) {
      return [
        entry.at ? new Date(entry.at) : new Date(),
        String(sessionKey || ''),
        String(deviceId || ''),
        String(entry.level || 'info').toUpperCase(),
        String(entry.message || '').substring(0, 5000)
      ];
    });

    // One setValues() call for the whole batch, instead of appendRow per line.
    Telemetry_ensureRows_(sheet, rows.length);
    sheet.getRange(sheet.getLastRow() + 1, 1, rows.length, 5).setValues(rows);
    Telemetry_trim_(sheet, LOG_MAX_ROWS_);
    return {written: rows.length};
  } catch (err) {
    Log_error_('logBatch', err);
    return {written: 0};
  }
}

/**
 * Makes sure the sheet physically has room for `needed` more rows.
 * A new sheet has a fixed 1000-row grid, and getRange() past the end throws
 * "range exceeds grid limits" - which would have silently killed logging once
 * the sheet filled up.
 */
function Telemetry_ensureRows_(sheet, needed) {
  var required = sheet.getLastRow() + needed;
  var available = sheet.getMaxRows();
  if (required > available) {
    sheet.insertRowsAfter(available, Math.max(needed, 500));
  }
}

/** Keeps a sheet from growing without bound. */
function Telemetry_trim_(sheet, maxRows) {
  var rows = sheet.getLastRow() - 1;
  if (rows <= maxRows) return;
  sheet.deleteRows(2, rows - maxRows);
}

/* ===========================================================================
 * Stats
 * =========================================================================*/

/**
 * Adds a batch of counters to the running totals.
 * @param {!Object<string, number>} counters
 */
function updateStats(counters) {
  if (!counters) return {ok: false};

  try {
    var sheet = Telemetry_sheet_(
        STATS_SHEET_NAME_, ['Metric', 'Total', 'Last Updated']);
    var values = sheet.getDataRange().getValues();

    var rowByMetric = {};
    for (var i = 1; i < values.length; i++) {
      rowByMetric[String(values[i][0])] = {row: i + 1, total: Number(values[i][1] || 0)};
    }

    var appends = [];
    var now = new Date();

    Object.keys(counters).forEach(function (metric) {
      var delta = Number(counters[metric]) || 0;
      if (!delta) return;

      var existing = rowByMetric[metric];
      if (existing) {
        sheet.getRange(existing.row, 2, 1, 2)
             .setValues([[existing.total + delta, now]]);
      } else {
        appends.push([metric, delta, now]);
      }
    });

    if (appends.length) {
      Telemetry_ensureRows_(sheet, appends.length);
      sheet.getRange(sheet.getLastRow() + 1, 1, appends.length, 3)
           .setValues(appends);
    }
    return {ok: true};
  } catch (err) {
    Log_error_('updateStats', err);
    return {ok: false};
  }
}

/* ===========================================================================
 * Remote commands
 * =========================================================================*/

/**
 * Returns queued commands for a kiosk and clears them.
 *
 * Commands are action names the client knows how to run (`reload`, `next`,
 * `pause(60)`), not arbitrary JavaScript. See Client_Core.html -> Actions.
 *
 * @param {string} deviceId
 * @return {!Array<string>}
 */
function ping(deviceId) {
  var id = String(deviceId || '').trim();
  var props = PropertiesService.getScriptProperties();

  var queued = props.getProperty('CMD_QUEUE');
  var broadcast = Json_parse_(queued) || [];
  if (broadcast.length) props.deleteProperty('CMD_QUEUE');

  var targeted = [];
  if (id) {
    var key = 'CMD_QUEUE.' + id;
    targeted = Json_parse_(props.getProperty(key)) || [];
    if (targeted.length) props.deleteProperty(key);
  }

  return broadcast.concat(targeted);
}

/**
 * Queues a command. Call from the Apps Script editor or a custom menu.
 * @param {string} command    e.g. 'reload', 'next', 'resync'
 * @param {string=} deviceId  Omit to broadcast to every kiosk.
 */
function queueCommand(command, deviceId) {
  var props = PropertiesService.getScriptProperties();
  var key = deviceId ? 'CMD_QUEUE.' + deviceId : 'CMD_QUEUE';
  var list = Json_parse_(props.getProperty(key)) || [];
  list.push(String(command));
  props.setProperty(key, JSON.stringify(list.slice(-20)));
  return list.length;
}

/**
 * Lightweight poll: tells the client whether anything it caches has changed,
 * without shipping any of it. The client only re-fetches when a token moves.
 */
function getStateToken() {
  var props = PropertiesService.getScriptProperties();
  return {
    config: props.getProperty('LAST_CONFIG_UPDATE') || '',
    serverTime: new Date().toISOString()
  };
}

/**
 * Records a device profile (screen size, browser, connection) once per boot.
 * @param {!Object} info
 */
function reportDevice(info) {
  if (!Config_get().EnableDeviceReporting) return {ok: false};
  try {
    var sheet = Telemetry_sheet_(
        'Device Reports', ['Timestamp', 'Device', 'Report']);
    sheet.appendRow([
      new Date(),
      String((info && info.deviceId) || ''),
      JSON.stringify(info).substring(0, 40000)
    ]);
    Telemetry_trim_(sheet, 2000);
    return {ok: true};
  } catch (err) {
    Log_error_('reportDevice', err);
    return {ok: false};
  }
}

/**
 * Maps a session key to its log spreadsheet, creating one on first use.
 * Cached for six hours, which comfortably outlives a kiosk session.
 * @return {?string}
 */
function Telemetry_sessionLogId_(sessionKey) {
  var key = String(sessionKey || '').trim();
  if (!key) return null;

  var cache = CacheService.getScriptCache();
  var cacheKey = Cache_key_('sessionlog.' + key);

  var existing = cache.get(cacheKey);
  if (existing) return existing;

  // Two log flushes can arrive at once on a busy boot; without the lock both
  // would create a spreadsheet and one would be orphaned.
  var lock = LockService.getScriptLock();
  try {
    lock.waitLock(10000);

    existing = cache.get(cacheKey);
    if (existing) return existing;

    var created = startLogSession(key);
    if (created) cache.put(cacheKey, created, 21600);
    return created;
  } catch (err) {
    Log_error_('Telemetry_sessionLogId_', err);
    return null;
  } finally {
    try { lock.releaseLock(); } catch (e) { /* never held */ }
  }
}
