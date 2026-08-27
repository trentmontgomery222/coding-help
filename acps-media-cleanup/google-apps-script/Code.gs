/**
 * ACPS FileMedia — Google Drive → WordPress drip uploader (PUSH mode).
 *
 * Runs inside YOUR Google account (script.google.com), reads a Drive folder,
 * and sends files to your WordPress site a few at a time — slower during the
 * day, faster at night — so a big dump of photos uploads over hours instead of
 * all at once. Files that upload successfully are moved into an "Imported to
 * WordPress" sub-folder; skipped/failed ones into "Skipped (not imported)", so
 * nothing is ever sent twice.
 *
 * HEIC/HEIF files are skipped on purpose (WordPress can't convert them without a
 * browser). Convert those in FileMedia instead, or export them as JPEG first.
 *
 * ── SETUP ─────────────────────────────────────────────────────────────────
 * 1. In WordPress: Media ▸ FileMedia ▸ … ▸ Settings ▸ "Google Drive import".
 *    Copy the "Push token" and the "Endpoint URL".
 * 2. Go to https://script.google.com ▸ New project. Paste this whole file in.
 * 3. Fill in the CONFIG values below (endpoint URL, token, and the Drive folder
 *    ID — the long string in the folder's URL after /folders/).
 * 4. Run the function `installTrigger` once (authorize it when asked). That
 *    schedules `runImport` to fire every 5 minutes.
 * 5. Drop your photos into the Drive folder. They'll trickle into WordPress.
 *    Watch progress in WordPress under the same settings page, or in this
 *    script's Executions log.
 *
 * To pause: run `removeTriggers`. To do a one-off burst now: run `runImport`.
 * ───────────────────────────────────────────────────────────────────────────
 */

/* ============================ CONFIG ============================ */
var CONFIG = {
  // From WordPress ▸ FileMedia Settings ▸ Google Drive import:
  WP_INGEST_URL: 'https://YOUR-SITE.example/wp-json/acps-mc/v1/ingest',
  PUSH_TOKEN:    'PASTE-YOUR-PUSH-TOKEN-HERE',

  // The Drive folder to import FROM (the ID after /folders/ in its URL):
  SOURCE_FOLDER_ID: 'PASTE-DRIVE-FOLDER-ID-HERE',

  // How many files to send per 5-minute run (daytime vs. night):
  DAY_RATE:   3,
  NIGHT_RATE: 40,

  // Day window (24h clock, this script's timezone). Outside it = night rate.
  DAY_START_HOUR:   7,   // 7am
  NIGHT_START_HOUR: 20,  // 8pm

  // Sub-folder names used to move files aside after processing.
  IMPORTED_FOLDER_NAME: 'Imported to WordPress',
  SKIPPED_FOLDER_NAME:  'Skipped (not imported)'
};
/* =============================================================== */

/** Install the every-5-minutes trigger (run this once). */
function installTrigger() {
  removeTriggers();
  ScriptApp.newTrigger('runImport').timeBased().everyMinutes(5).create();
  Logger.log('Trigger installed: runImport every 5 minutes.');
}

/** Remove all triggers for this script (pause importing). */
function removeTriggers() {
  ScriptApp.getProjectTriggers().forEach(function (t) { ScriptApp.deleteTrigger(t); });
  Logger.log('All triggers removed.');
}

/** Main worker — fired by the trigger. */
function runImport() {
  var lock = LockService.getScriptLock();
  if (!lock.tryLock(1000)) { Logger.log('Another run is in progress; skipping.'); return; }
  try {
    var rate = currentRate_();
    if (rate <= 0) { return; }

    var source   = DriveApp.getFolderById(CONFIG.SOURCE_FOLDER_ID);
    var imported = getOrCreateSubfolder_(source, CONFIG.IMPORTED_FOLDER_NAME);
    var skipped  = getOrCreateSubfolder_(source, CONFIG.SKIPPED_FOLDER_NAME);

    var files = source.getFiles();
    var sent = 0, moved = 0;
    while (files.hasNext() && sent < rate) {
      var file = files.next();
      var name = file.getName();

      // Skip HEIC/HEIF (WordPress can't convert them server-side).
      if (/\.(heic|heif)$/i.test(name) || /hei[cf]/i.test(file.getMimeType())) {
        moveTo_(file, skipped);
        moved++;
        continue;
      }

      var result = uploadFile_(file);
      if (result === 'ok') {
        moveTo_(file, imported); moved++; sent++;
      } else if (result === 'skipped') {
        moveTo_(file, skipped); moved++; sent++;
      } else {
        // Transient error — leave the file where it is and retry next run.
        Logger.log('Upload error for "' + name + '": ' + result);
      }
    }
    Logger.log('runImport done. rate=' + rate + ', sent=' + sent + ', movedAside=' + moved);
  } catch (e) {
    Logger.log('runImport failed: ' + e);
  } finally {
    lock.releaseLock();
  }
}

/** POST one file to WordPress. Returns 'ok', 'skipped', or an error string. */
function uploadFile_(file) {
  var options = {
    method: 'post',
    headers: { 'X-ACPS-Token': CONFIG.PUSH_TOKEN },
    payload: {
      file: file.getBlob().setName(file.getName()),
      filename: file.getName()
    },
    muteHttpExceptions: true
  };
  var resp = UrlFetchApp.fetch(CONFIG.WP_INGEST_URL, options);
  var code = resp.getResponseCode();
  var body = {};
  try { body = JSON.parse(resp.getContentText()); } catch (e) { /* non-JSON */ }

  if (code >= 200 && code < 300) {
    if (body.status === 'skipped') { return 'skipped'; }
    return 'ok';
  }
  return 'HTTP ' + code + ' ' + (body && body.message ? body.message : resp.getContentText()).slice(0, 200);
}

/** Files-per-run right now, from the day/night window. */
function currentRate_() {
  var hour = Number(Utilities.formatDate(new Date(), Session.getScriptTimeZone(), 'H'));
  var ds = CONFIG.DAY_START_HOUR, ns = CONFIG.NIGHT_START_HOUR;
  var isDay = (ds <= ns) ? (hour >= ds && hour < ns) : (hour >= ds || hour < ns);
  return isDay ? CONFIG.DAY_RATE : CONFIG.NIGHT_RATE;
}

/** Find (or create) a direct sub-folder by name. */
function getOrCreateSubfolder_(parent, name) {
  var it = parent.getFoldersByName(name);
  return it.hasNext() ? it.next() : parent.createFolder(name);
}

/** Move a file into a folder (and out of every other parent). */
function moveTo_(file, folder) {
  try {
    file.moveTo(folder); // modern Drive service
  } catch (e) {
    // Fallback for older runtimes.
    folder.addFile(file);
    var parents = file.getParents();
    while (parents.hasNext()) {
      var p = parents.next();
      if (p.getId() !== folder.getId()) { p.removeFile(file); }
    }
  }
}
