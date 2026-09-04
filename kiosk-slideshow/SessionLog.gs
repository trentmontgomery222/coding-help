/**
 * SessionLog.gs
 * ---------------------------------------------------------------------------
 * Per-session log spreadsheets, ported from the Kiosk Utilities script
 * (`InitLogFilesForSessionKey`, `pushToLog`).
 *
 * Each time a kiosk boots it gets its own spreadsheet in the logs folder.
 * Star a log in Drive to keep it; unstarred logs are pruned oldest-first once
 * there are more than `SessionLogKeep` of them.
 *
 * Fixed while porting:
 *   - The sort comparator returned a boolean (`a < b`) rather than a number,
 *     which is not a valid comparator - the "oldest" file it deleted was
 *     effectively arbitrary.
 *   - The prune loop spliced the array it was iterating with forEach, so it
 *     skipped every other entry and never actually got down to the limit.
 *   - It called getLastUpdated() once per file - one API round trip each -
 *     when the folder listing already carries modifiedTime.
 *   - The header row was rewritten on every single log line.
 *
 * This is opt-in: set `LogDestination` to `session` in the settings sheet.
 * The default (`sheet`) keeps everything in one Logs tab, which is easier to
 * search and cheaper to write.
 * ---------------------------------------------------------------------------
 */

/**
 * Creates a log spreadsheet for a kiosk session and prunes old ones.
 * @param {string} sessionKey
 * @return {?string} The new spreadsheet's id, or null if logging is off.
 */
function startLogSession(sessionKey) {
  var cfg = Config_get();
  var folderId = cfg.SessionLogFolderId;
  if (!folderId || !cfg.EnableLogs) return null;

  try {
    SessionLog_prune_(folderId, Number(cfg.SessionLogKeep) || 25);

    var name = Utilities.formatDate(new Date(),
        Session.getScriptTimeZone(), 'yyyy-MM-dd HH:mm:ss') +
        ' - ' + String(sessionKey).slice(0, 8);

    var ss = SpreadsheetApp.create(name);
    var sheet = ss.getSheets()[0];
    sheet.setName('Log');
    sheet.getRange(1, 1, 1, 4)
         .setValues([['Timestamp', 'Level', 'Key', 'Value']]);
    sheet.setFrozenRows(1);
    sheet.setColumnWidth(4, 800);

    Drive_moveTo_(ss.getId(), folderId);
    return ss.getId();
  } catch (err) {
    Log_error_('startLogSession', err);
    return null;
  }
}

/**
 * Appends entries to a session log in a single write.
 *
 * @param {string} spreadsheetId
 * @param {!Array<{level:string, message:string, at:(string|undefined)}>} entries
 * @return {number} Rows written.
 */
function appendLogSession(spreadsheetId, entries) {
  if (!spreadsheetId || !entries || !entries.length) return 0;

  try {
    var ss = SpreadsheetApp.openById(spreadsheetId);
    var sheet = ss.getSheetByName('Log') || ss.getSheets()[0];

    var rows = entries.slice(0, 200).map(function (entry) {
      return [
        entry.at ? new Date(entry.at) : new Date(),
        String(entry.level || 'info').toUpperCase(),
        String(entry.key || ''),
        String(entry.message || '').substring(0, 5000)
      ];
    });

    Telemetry_ensureRows_(sheet, rows.length);
    sheet.getRange(sheet.getLastRow() + 1, 1, rows.length, 4).setValues(rows);
    return rows.length;
  } catch (err) {
    Log_error_('appendLogSession', err);
    return 0;
  }
}

/**
 * Deletes the oldest unstarred logs until at most `keep` remain.
 * Starred logs are never counted and never deleted - starring one in Drive is
 * how you say "hang on to this".
 */
function SessionLog_prune_(folderId, keep) {
  var files = Drive_listFolder_(folderId, false);

  // The listing already told us modifiedTime and shared status; we only need
  // the starred flag, which the list call does not include by default.
  var candidates = files.filter(function (file) {
    return !Drive_isStarred_(file.id);
  });

  if (candidates.length <= keep) return 0;

  // Oldest first. A real numeric comparator, so the sort is actually defined.
  candidates.sort(function (a, b) {
    return new Date(a.modifiedTime).getTime() -
           new Date(b.modifiedTime).getTime();
  });

  var excess = candidates.length - keep;
  var removed = 0;

  // Slice off exactly what we intend to delete, rather than mutating the
  // array while walking it.
  candidates.slice(0, excess).forEach(function (file) {
    try {
      Drive_setTrashed_(file.id, true);
      removed++;
    } catch (err) {
      Log_warn_('Could not prune log ' + file.name, err);
    }
  });

  if (removed) Log_info_('Pruned ' + removed + ' old session logs');
  return removed;
}

/**
 * Legacy entry point. The old client called `init(sessionKey)` on boot and
 * `logThis(sessionKey, id, key, value)` per line.
 */
function init(sessionKey) {
  return startLogSession(sessionKey);
}

/** Legacy single-line logger; forwards into the batched path. */
function logThis(sessionKey, spreadsheetId, key, value) {
  return appendLogSession(spreadsheetId, [{
    level: 'info', key: key, message: JSON.stringify(value),
    at: new Date().toISOString()
  }]);
}
