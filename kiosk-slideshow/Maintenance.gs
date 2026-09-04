/**
 * Maintenance.gs
 * ---------------------------------------------------------------------------
 * Run-by-hand jobs for curating the archive.
 *
 * This file replaces the pile of one-off migration functions in the old
 * project (testingBoth, FIXIMAGEALLNEW, fixImageFromOld, doNewFolderBoth,
 * trytoreadimage, myTHINGFunction, clearTheMess, grabnew, agains, ...). Those
 * carried thousands of lines of hardcoded file IDs from migrations that have
 * already happened, several were defined twice, and most were variations on
 * the same idea: walk a folder and bring each description up to schema.
 *
 * That idea is now one function: repairFolder().
 *
 * Everything here is resumable. Apps Script kills any execution at 6 minutes,
 * so each job saves its position and picks up where it left off next run.
 * ---------------------------------------------------------------------------
 */

var MAINTENANCE_BUDGET_MS_ = 4.5 * 60 * 1000;   // stop before the 6-min limit

/**
 * Brings every photo in a folder up to the current metadata schema.
 *
 * Safe to run repeatedly. Re-run until it reports done:true.
 *
 * @param {string=} folderId  Defaults to the configured images folder.
 * @param {boolean=} withExif Also read EXIF from image bytes (much slower).
 * @return {{processed:number, repaired:number, failed:number, done:boolean}}
 */
function repairFolder(folderId, withExif) {
  var cfg = Config_get();
  var target = folderId || cfg.ImagesFolderId;
  var props = PropertiesService.getScriptProperties();
  var cursorKey = 'REPAIR_CURSOR.' + target;

  var files = Drive_listFolder_(target, cfg.IncludeSubFolders !== false);
  var start = Number(props.getProperty(cursorKey) || 0);
  var startedAt = Date.now();

  var processed = 0, repaired = 0, failed = 0;
  var index = start;

  for (; index < files.length; index++) {
    if (Date.now() - startedAt > MAINTENANCE_BUDGET_MS_) break;   // out of time

    var file = files[index];
    if (String(file.mimeType).indexOf('image/') !== 0) continue;

    processed++;
    try {
      if (Meta_repairFile(file.id, withExif === true)) repaired++;
    } catch (err) {
      failed++;
      Log_warn_('repairFolder failed on ' + file.name, err);
    }
  }

  var done = index >= files.length;
  if (done) {
    props.deleteProperty(cursorKey);
    Cache_remove_('manifest.main');
  } else {
    props.setProperty(cursorKey, String(index));
  }

  var summary = {
    processed: processed, repaired: repaired, failed: failed,
    position: index, total: files.length, done: done
  };
  Log_info_('repairFolder: ' + JSON.stringify(summary));
  return summary;
}

/** Clears a stuck repairFolder cursor so the next run starts from the top. */
function resetRepairCursor(folderId) {
  var target = folderId || Config_get().ImagesFolderId;
  PropertiesService.getScriptProperties()
      .deleteProperty('REPAIR_CURSOR.' + target);
  return true;
}

/**
 * Shares every photo in the library with "anyone with the link", which is what
 * lets the browser load them from Google's image CDN.
 *
 * Only touches files Drive reports as unshared, so re-running is cheap.
 *
 * @return {{checked:number, shared:number, done:boolean}}
 */
function publishFolder(folderId) {
  var cfg = Config_get();
  var target = folderId || cfg.ImagesFolderId;
  var files = Drive_listFolder_(target, cfg.IncludeSubFolders !== false);
  var startedAt = Date.now();

  var checked = 0, shared = 0, index = 0;
  for (; index < files.length; index++) {
    if (Date.now() - startedAt > MAINTENANCE_BUDGET_MS_) break;
    checked++;
    if (files[index].shared) continue;
    if (Drive_ensurePublic_(files[index].id, false)) shared++;
  }

  return {checked: checked, shared: shared, done: index >= files.length};
}

/**
 * Prints a health report for the library: how many photos, how many are
 * missing metadata, year coverage, and the largest files.
 *
 * Read the output in the Apps Script execution log.
 */
function auditLibrary() {
  var cfg = Config_get();
  var files = Drive_listFolder_(cfg.ImagesFolderId, cfg.IncludeSubFolders !== false);

  var report = {
    totalFiles: files.length,
    images: 0,
    withMetadata: 0,
    missingMetadata: [],
    unparseable: [],
    notShared: 0,
    oversized: [],
    byDecade: {},
    noYear: 0
  };

  var maxBytes = (Number(cfg.MaxImageSizeMB) || 25) * 1024 * 1024;

  files.forEach(function (file) {
    if (String(file.mimeType).indexOf('image/') !== 0) return;
    report.images++;

    if (!file.shared) report.notShared++;
    if (file.size > maxBytes) {
      report.oversized.push(file.name + ' (' +
                            Math.round(file.size / 1048576) + 'MB)');
    }

    if (!file.description) {
      report.missingMetadata.push(file.name);
    } else if (!Json_parse_(file.description)) {
      report.unparseable.push(file.name);
    } else {
      report.withMetadata++;
    }

    var meta = Meta_parse_(file.description, file);
    var year = Number(meta.Properties.Year) || 0;
    if (!year) {
      report.noYear++;
    } else {
      var decade = String(Math.floor(year / 10) * 10) + 's';
      report.byDecade[decade] = (report.byDecade[decade] || 0) + 1;
    }
  });

  // Keep the log readable on a large archive.
  report.missingMetadata = report.missingMetadata.slice(0, 25);
  report.unparseable = report.unparseable.slice(0, 25);
  report.oversized = report.oversized.slice(0, 25);

  Log_info_('Library audit:\n' + JSON.stringify(report, null, 2));
  return report;
}

/**
 * Re-reads EXIF for photos that have none stored yet.
 * Slow (it downloads part of each file), so it is resumable like repairFolder.
 */
function backfillExif(folderId) {
  var cfg = Config_get();
  var target = folderId || cfg.ImagesFolderId;
  var props = PropertiesService.getScriptProperties();
  var cursorKey = 'EXIF_CURSOR.' + target;

  var files = Drive_listFolder_(target, cfg.IncludeSubFolders !== false);
  var index = Number(props.getProperty(cursorKey) || 0);
  var startedAt = Date.now();
  var updated = 0;

  for (; index < files.length; index++) {
    if (Date.now() - startedAt > MAINTENANCE_BUDGET_MS_) break;

    var file = files[index];
    if (String(file.mimeType).indexOf('image/') !== 0) continue;

    var meta = Meta_parse_(file.description, file);
    if (meta.MetaData && meta.MetaData.ImageWidth) continue;   // already done

    try {
      Meta_repairFile(file.id, true);
      updated++;
    } catch (err) {
      Log_warn_('backfillExif failed on ' + file.name, err);
    }
  }

  var done = index >= files.length;
  if (done) props.deleteProperty(cursorKey);
  else props.setProperty(cursorKey, String(index));

  return {updated: updated, position: index, total: files.length, done: done};
}

/**
 * First-run helper. Verifies the project is wired up correctly and reports
 * anything still missing.
 */
function checkSetup() {
  var problems = [];
  var notes = [];

  if (!Drive_available_()) {
    problems.push('Advanced Drive Service is not enabled. Add it under ' +
                  'Services > Drive (v3) or the sync will fall back to the ' +
                  'slow DriveApp path.');
  } else {
    notes.push('Advanced Drive Service: OK');
  }

  var cfg;
  try {
    cfg = Config_get();
    notes.push('Control Values sheet: OK (' + Object.keys(cfg).length + ' settings)');
  } catch (err) {
    problems.push('Cannot read the control spreadsheet: ' + err);
    cfg = Config_defaults_();
  }

  try {
    var files = Drive_listFolder_(cfg.ImagesFolderId, cfg.IncludeSubFolders !== false);
    var images = files.filter(function (f) {
      return String(f.mimeType).indexOf('image/') === 0;
    });
    notes.push('Images folder: OK (' + images.length + ' images found)');
    if (!images.length) problems.push('The images folder contains no images.');

    var unshared = images.filter(function (f) { return !f.shared; }).length;
    if (unshared) {
      problems.push(unshared + ' images are not shared yet. Run publishFolder() ' +
                    'so the browser can load them.');
    }
  } catch (err) {
    problems.push('Cannot read the images folder: ' + err);
  }

  if (!getScriptURL()) {
    problems.push('No web app deployment yet. Deploy > New deployment > Web app.');
  } else {
    notes.push('Web app URL: ' + getScriptURL());
  }

  /* --- Intake pipeline --------------------------------------------------- */
  if (cfg.UploadFolderId) {
    var uploadMeta = Drive_getMeta_(cfg.UploadFolderId, 'id, name, trashed');
    if (!uploadMeta) {
      problems.push('The upload folder (' + cfg.UploadFolderId + ') cannot be ' +
                    'read. Check UploadFolderId in the settings sheet.');
    } else {
      notes.push('Upload folder: OK ("' + uploadMeta.name + '")');
      var waiting = Drive_listFolder_(cfg.UploadFolderId, false).length;
      if (waiting) notes.push(waiting + ' file(s) waiting in the upload folder');
    }
  } else {
    notes.push('Upload intake is off (no UploadFolderId set)');
  }

  if (cfg.IntakeEveryMinutes > 0) {
    var hasTick = ScriptApp.getProjectTriggers().some(function (trigger) {
      return trigger.getHandlerFunction() === 'tick';
    });
    if (hasTick) {
      notes.push('Maintenance heartbeat: OK (tick every minute)');
    } else {
      problems.push('The tick() trigger is missing, so uploads will not be ' +
                    'processed. Run installTriggers().');
    }
  }

  /* --- Alerting ---------------------------------------------------------- */
  var admins = Integrity_adminEmails_(cfg);
  if (admins.length) {
    notes.push('Alert emails go to: ' + admins.join(', '));
  } else {
    notes.push('No AdminEmails set - alerts will only appear in the logs.');
  }

  /* --- Critical resources ------------------------------------------------ */
  var resources = verifyResources();
  if (resources.missing.length) {
    problems.push('Unreadable critical items: ' + resources.missing.join(', '));
  } else {
    notes.push('Critical Drive items: OK (' + resources.checked + ' checked)');
  }

  var report = {ok: problems.length === 0, notes: notes, problems: problems};
  Log_info_('Setup check:\n' + JSON.stringify(report, null, 2));
  return report;
}
