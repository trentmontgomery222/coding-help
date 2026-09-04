/**
 * Intake.gs
 * ---------------------------------------------------------------------------
 * The photo intake pipeline, ported from the Kiosk Utilities script
 * (`checkForNewImagesFromUploads`, `checkForNewImagesFromCleaner`, `checkAll`).
 *
 * Someone drops a photo into the "Upload New Photos Here" folder. This:
 *   1. checks it is a supported image type,
 *   2. waits until the upload has actually finished,
 *   3. reads the year off the front of the file name,
 *   4. files it into that year's folder inside the main archive,
 *   5. stamps the metadata schema onto it,
 *   6. shares it so the kiosk can display it,
 *   7. writes a row to the upload log.
 *
 * Fixed while porting:
 *   - `getFileData(id).descripion` was misspelled, so the description was
 *     always undefined.
 *   - A file named without a leading year produced `parseInt(...) = NaN` and
 *     the code then searched Drive for a folder literally named "NaN".
 *   - The "has the upload finished?" check was `> 1` second, which is short
 *     enough to grab a half-written file. It is now configurable and defaults
 *     to a minute.
 *   - The duplicate check re-listed the entire destination folder once per
 *     uploaded file; destinations are now listed once and cached.
 *   - Files were moved before the metadata stamp was known to have worked, so
 *     a failure left a photo in the archive with no description at all.
 *   - There was no time budget, so a large batch would hit the 6-minute
 *     execution limit part-way through and lose its place.
 * ---------------------------------------------------------------------------
 */

var INTAKE_BUDGET_MS_ = 4 * 60 * 1000;

/**
 * Main intake run. Safe to call every few minutes from `tick()`.
 * @return {{scanned:number, filed:number, duplicates:number,
 *           rejected:number, failed:number, done:boolean}}
 */
function processUploads() {
  var cfg = Config_get();
  var uploadFolderId = cfg.UploadFolderId;
  if (!uploadFolderId) return Intake_empty_('no UploadFolderId configured');

  var files = Drive_listFolder_(uploadFolderId, false);
  var startedAt = Date.now();
  var report = {scanned: 0, filed: 0, duplicates: 0, rejected: 0,
                failed: 0, done: true};

  // Destination listings are reused across every file in this run.
  var destinationCache = {};
  var logRows = [];

  for (var i = 0; i < files.length; i++) {
    if (Date.now() - startedAt > INTAKE_BUDGET_MS_) {
      report.done = false;              // pick the rest up on the next tick
      break;
    }

    var file = files[i];
    report.scanned++;

    try {
      var outcome = Intake_handleFile_(file, cfg, destinationCache);
      report[outcome.bucket]++;
      logRows.push([new Date(), file.name, file.id, outcome.status,
                    outcome.destination || '', outcome.detail || '']);
    } catch (err) {
      report.failed++;
      Log_error_('processUploads ' + file.name, err);
      logRows.push([new Date(), file.name, file.id, 'ERROR', '', String(err)]);
    }
  }

  Intake_writeLog_(logRows, cfg);
  if (report.filed) refreshImageManifest();   // new photos are now playable

  Log_info_('processUploads: ' + JSON.stringify(report));
  return report;
}

/**
 * Decides what happens to one uploaded file.
 * @return {{bucket:string, status:string, destination:(string|undefined),
 *           detail:(string|undefined)}}
 */
function Intake_handleFile_(file, cfg, destinationCache) {
  // --- Type check ---------------------------------------------------------
  var allowed = String(cfg.AllowedUploadTypes || 'image/jpeg,image/png')
      .split(',').map(function (t) { return t.trim().toLowerCase(); });

  if (allowed.indexOf(String(file.mimeType).toLowerCase()) === -1) {
    Intake_notifyRejected_(file, cfg);
    return {bucket: 'rejected', status: 'REJECTED',
            detail: 'unsupported type ' + file.mimeType};
  }

  // --- Size check ---------------------------------------------------------
  var maxBytes = (Number(cfg.MaxImageSizeMB) || 25) * 1024 * 1024;
  if (file.size && file.size > maxBytes) {
    return {bucket: 'rejected', status: 'REJECTED',
            detail: 'too large (' + Math.round(file.size / 1048576) + 'MB)'};
  }

  // --- Has the upload finished? -------------------------------------------
  // A file that was modified seconds ago may still be streaming in. Moving it
  // mid-upload is how you end up with a truncated photo in the archive.
  var settleSeconds = Number(cfg.IntakeSettleSeconds) || 60;
  var ageSeconds = (Date.now() - new Date(file.modifiedTime).getTime()) / 1000;
  if (ageSeconds < settleSeconds) {
    return {bucket: 'duplicates', status: 'WAITING',
            detail: 'uploaded ' + Math.round(ageSeconds) + 's ago, still settling'};
  }

  // --- Where does it belong? ----------------------------------------------
  var destination = Intake_resolveDestination_(file.name, cfg);

  // --- Already there? -----------------------------------------------------
  if (!destinationCache[destination.id]) {
    var existing = {};
    Drive_listFolder_(destination.id, false).forEach(function (item) {
      existing[Intake_normalise_(item.name)] = item.id;
    });
    destinationCache[destination.id] = existing;
  }

  var key = Intake_normalise_(file.name);
  if (destinationCache[destination.id][key]) {
    return {bucket: 'duplicates', status: 'DUPLICATE',
            destination: destination.name,
            detail: 'already in ' + destination.name};
  }

  // --- Stamp metadata BEFORE moving --------------------------------------
  // If this throws, the photo stays in the upload folder and gets retried,
  // rather than landing in the archive with nothing attached to it.
  var meta = Intake_stamp_(file, cfg);

  Drive_moveTo_(file.id, destination.id);
  Drive_ensurePublic_(file.id, false);
  destinationCache[destination.id][key] = file.id;

  return {bucket: 'filed', status: 'FILED', destination: destination.name,
          detail: 'year ' + (meta.Properties.Year || 'unknown')};
}

/**
 * Works out which folder a photo belongs in from its file name.
 *
 * The archive's convention is that a photo is named "<year> <description>",
 * e.g. "1962 Class Officers". Anything that does not start with a plausible
 * year goes to the review folder rather than being guessed at.
 *
 * @return {{id:string, name:string}}
 */
function Intake_resolveDestination_(fileName, cfg) {
  var fallbackId = cfg.IntakeFallbackFolderId || cfg.ImagesFolderId;
  var fallback = {id: fallbackId, name: Drive_getName_(fallbackId) || 'review'};

  var match = String(fileName || '').match(/^\s*(1[89]\d{2}|20\d{2})\b/);
  if (!match) return fallback;

  var year = match[1];

  // Only accept a year folder that actually lives inside the main archive -
  // there are folders named "1962" elsewhere in Drive, and the original code
  // would happily file a photo into whichever one Drive returned first.
  var candidates = Drive_findByName_(year, {
    foldersOnly: true,
    parentId: cfg.ImagesFolderId
  });

  if (candidates.length) return {id: candidates[0].id, name: year};

  // No folder for that year yet - make one, so the archive grows on its own.
  if (cfg.CreateMissingYearFolders !== false) {
    try {
      var created = Intake_createYearFolder_(year, cfg.ImagesFolderId);
      if (created) return {id: created, name: year};
    } catch (err) {
      Log_warn_('Could not create year folder ' + year, err);
    }
  }
  return fallback;
}

/** @return {?string} New folder id. */
function Intake_createYearFolder_(year, parentId) {
  if (Drive_available_()) {
    var folder = Drive.Files.create({
      name: year, mimeType: MIME_FOLDER_, parents: [parentId]
    }, null, {supportsAllDrives: true});
    Log_info_('Created year folder ' + year);
    return folder.id;
  }
  return DriveApp.getFolderById(parentId).createFolder(year).getId();
}

/**
 * Writes the metadata schema onto a freshly uploaded photo.
 * This is what FIXIMAGEALLNEW did, minus the duplicated schema literal - the
 * schema lives in Metadata.gs and nowhere else.
 */
function Intake_stamp_(file, cfg) {
  var meta = Meta_repairFile(file.id, cfg.ReadExifOnUpload === true);
  if (!meta) throw new Error('metadata stamp failed for ' + file.name);

  // Record when it entered the archive, which the original tracked as
  // Properties.UploadDate.
  if (!meta.Properties.UploadDate) {
    meta.Properties.UploadDate = new Date().toISOString();
    Drive_setDescription_(file.id, JSON.stringify(meta, null, 2));
  }
  return meta;
}

/** Case- and extension-insensitive key for duplicate detection. */
function Intake_normalise_(name) {
  return String(name || '')
      .replace(/\.(jpe?g|png|tiff?|webp|heic)$/i, '')
      .replace(/\s+/g, ' ')
      .trim()
      .toLowerCase();
}

/** Tells the uploader their file was not usable, at most once per file. */
function Intake_notifyRejected_(file, cfg) {
  var recipients = Integrity_adminEmails_(cfg);
  if (!recipients.length || cfg.EmailOnRejectedUpload === false) return;

  var cache = CacheService.getScriptCache();
  var key = 'rejected:' + file.id;
  if (cache.get(key)) return;               // do not nag every five minutes
  cache.put(key, '1', 21600);

  try {
    MailApp.sendEmail(recipients.join(','),
        'Kiosk upload could not be used: ' + file.name,
        'The file "' + file.name + '" is a ' + file.mimeType + '.\n\n' +
        'The kiosk can only display JPEG and PNG images. Please convert it ' +
        'and upload it again.\n\n' + Drive_viewUrl_(file.id));
  } catch (err) {
    Log_warn_('Could not send rejection notice', err);
  }
}

/** Appends this run's rows to the upload log in one write. */
function Intake_writeLog_(rows, cfg) {
  if (!rows.length || !cfg.UploadLogSheetId) return;
  try {
    var ss = SpreadsheetApp.openById(cfg.UploadLogSheetId);
    var sheet = ss.getSheetByName('Uploads');
    if (!sheet) {
      sheet = ss.insertSheet('Uploads');
      sheet.appendRow(['Timestamp', 'File Name', 'File ID', 'Status',
                       'Destination', 'Detail']);
      sheet.setFrozenRows(1);
    }
    Telemetry_ensureRows_(sheet, rows.length);
    sheet.getRange(sheet.getLastRow() + 1, 1, rows.length, 6).setValues(rows);
    Telemetry_trim_(sheet, 5000);
  } catch (err) {
    Log_error_('Intake_writeLog_', err);
  }
}

function Intake_empty_(reason) {
  Log_warn_('Intake skipped: ' + reason);
  return {scanned: 0, filed: 0, duplicates: 0, rejected: 0, failed: 0,
          done: true, skipped: reason};
}

/* ===========================================================================
 * Enhancement queue
 *
 * The utilities script shuttled photos through a third-party "make this look
 * better" library. That call was already commented out, and what remained
 * wrote the base64 text of the image into a new file - which produces a text
 * document, not a picture.
 *
 * The shuttle is kept because the folder workflow is useful; the enhancement
 * step is an explicit hook. With no enhancer wired up it passes photos
 * straight through, which is what the code was effectively doing anyway.
 * =========================================================================*/

/**
 * Moves photos from the enhancement inbox to the output folder, optionally
 * running them through `Intake_enhance_` on the way.
 */
function processEnhancementQueue() {
  var cfg = Config_get();
  var inboxId = cfg.EnhanceInboxFolderId;
  var outputId = cfg.EnhanceOutputFolderId;
  if (!inboxId || !outputId) return Intake_empty_('enhancement folders not set');

  var files = Drive_listFolder_(inboxId, false);
  var startedAt = Date.now();
  var moved = 0, failed = 0;

  for (var i = 0; i < files.length; i++) {
    if (Date.now() - startedAt > INTAKE_BUDGET_MS_) break;

    var file = files[i];
    if (String(file.mimeType).indexOf('image/') !== 0) continue;

    try {
      var produced = Intake_enhance_(file, cfg);
      Drive_moveTo_(produced || file.id, outputId);

      // Only archive the original when enhancement produced a new file.
      if (produced && produced !== file.id && cfg.EnhanceArchiveFolderId) {
        Drive_moveTo_(file.id, cfg.EnhanceArchiveFolderId);
      }
      moved++;
    } catch (err) {
      failed++;
      Log_error_('processEnhancementQueue ' + file.name, err);
    }
  }

  return {moved: moved, failed: failed, done: true};
}

/**
 * Hook for an image enhancement step.
 *
 * Return a NEW file id to replace the original, or null to pass it through
 * untouched. Wire your own library in here; the default is a pass-through.
 *
 * @param {!Object} file  Listing entry: {id, name, mimeType, ...}
 * @param {!Object} cfg
 * @return {?string}
 */
function Intake_enhance_(file, cfg) {
  return null;
}

/* ===========================================================================
 * Quarantine sweep
 * =========================================================================*/

/**
 * Finds photos whose stored metadata will not parse and moves them back to
 * the upload folder to be reprocessed. This is `checkAll()` from the
 * utilities script, with two changes: it repairs in place first and only
 * quarantines what it genuinely cannot fix, and it is time-budgeted.
 *
 * @return {{checked:number, repaired:number, quarantined:number, done:boolean}}
 */
function quarantineBrokenMetadata() {
  var cfg = Config_get();
  var files = Drive_listFolder_(cfg.ImagesFolderId, cfg.IncludeSubFolders !== false);
  var quarantineId = cfg.UploadFolderId;
  var startedAt = Date.now();

  var checked = 0, repaired = 0, quarantined = 0, index = 0;

  for (; index < files.length; index++) {
    if (Date.now() - startedAt > INTAKE_BUDGET_MS_) break;

    var file = files[index];
    if (String(file.mimeType).indexOf('image/') !== 0) continue;
    checked++;

    // A description that parses and carries a display name is healthy.
    var parsed = Json_parse_(file.description);
    if (parsed && parsed.Properties && parsed.Properties.DisplayName) continue;

    // Try to fix it in place before doing anything drastic.
    try {
      var meta = Meta_repairFile(file.id, false);
      if (meta && meta.Properties.DisplayName) { repaired++; continue; }
    } catch (err) {
      Log_warn_('Repair failed for ' + file.name, err);
    }

    if (quarantineId) {
      Drive_moveTo_(file.id, quarantineId);
      quarantined++;
      Log_warn_('Quarantined ' + file.name + ' (' + Drive_viewUrl_(file.id) + ')');
    }
  }

  var report = {checked: checked, repaired: repaired, quarantined: quarantined,
                done: index >= files.length};
  if (repaired || quarantined) refreshImageManifest();
  Log_info_('quarantineBrokenMetadata: ' + JSON.stringify(report));
  return report;
}
