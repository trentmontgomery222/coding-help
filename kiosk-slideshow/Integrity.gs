/**
 * Integrity.gs
 * ---------------------------------------------------------------------------
 * Watches the handful of Drive items the kiosk cannot run without, and keeps
 * the configuration spreadsheets locked down.
 *
 * Ported from the Kiosk Utilities script
 * (`ensureNoFoldersAndFilesAreInTrash`, `checkAllForProperCredentials`,
 * `InitRecreationProtocl`, `getDriveFolderIdsWithFallbackHandlingDrive`).
 *
 * Fixed while porting:
 *   - The resource registry had the key "Configurations" twice. In a JavaScript
 *     object literal the second wins, so one of the two folders was never
 *     actually being watched.
 *   - Every entry was read with `DriveApp.getFolderById()`, which throws for
 *     anything that is not a folder - so the spreadsheets and scripts in the
 *     list always landed in the catch block and were never checked.
 *   - Each entry was renamed to its registry key on every pass, which quietly
 *     renamed spreadsheets. Renaming is now opt-in per entry.
 *   - `sheet.protect()` was called five times in a row; each call creates a
 *     NEW protection range, so a sheet accumulated protections every run.
 *   - Alert emails had no rate limit and fired on every failing pass.
 * ---------------------------------------------------------------------------
 */

/**
 * The items the kiosk depends on.
 *
 * `rename: true` means the item's name is restored if someone changes it -
 * only set that on folders whose name is part of the workflow, never on
 * spreadsheets people have titled themselves.
 */
function Integrity_registry_() {
  var cfg = Config_get();
  return [
    {key: 'Settings spreadsheet', id: Config_spreadsheetId_(), kind: 'file'},
    {key: 'Upload Your New Photos Here', id: cfg.UploadFolderId,
     kind: 'folder', rename: true},
    {key: 'Allegany Kiosk Slideshow Images', id: cfg.ImagesFolderId,
     kind: 'folder', rename: true},
    {key: 'Review folder', id: cfg.IntakeFallbackFolderId, kind: 'folder'},
    {key: 'Alternate photo set', id: cfg.AlternateFolderId, kind: 'folder'},
    {key: 'Kiosk logs', id: cfg.SessionLogFolderId, kind: 'folder'},
    {key: 'Upload log', id: cfg.UploadLogSheetId, kind: 'file'},
    {key: 'Read me', id: cfg.ReadMeFileId, kind: 'file'}
  ].filter(function (entry) { return !!entry.id; });
}

/**
 * Confirms every critical item still exists, is not in the trash, and is
 * named what we expect.
 *
 * @return {{checked:number, restored:!Array<string>, renamed:!Array<string>,
 *           missing:!Array<string>, ok:boolean}}
 */
function verifyResources() {
  var report = {checked: 0, restored: [], renamed: [], missing: [], ok: true};

  Integrity_registry_().forEach(function (entry) {
    report.checked++;
    // One generic metadata read - works for folders, sheets and scripts alike.
    var meta = Drive_getMeta_(entry.id, 'id, name, trashed, mimeType');

    if (!meta) {
      report.missing.push(entry.key + ' (' + entry.id + ')');
      report.ok = false;
      return;
    }

    if (meta.trashed) {
      try {
        Drive_setTrashed_(entry.id, false);
        report.restored.push(entry.key);
        Log_warn_('Restored "' + entry.key + '" from the trash');
      } catch (err) {
        report.missing.push(entry.key + ' (trashed, could not restore)');
        report.ok = false;
        return;
      }
    }

    if (entry.rename && meta.name !== entry.key) {
      try {
        Drive_setName_(entry.id, entry.key);
        report.renamed.push(meta.name + ' -> ' + entry.key);
      } catch (err) {
        Log_warn_('Could not rename ' + entry.id, err);
      }
    }
  });

  if (report.missing.length) {
    Integrity_alert_(
        'Kiosk: critical Drive items are missing',
        'These items could not be read, so the kiosk may stop working:\n\n' +
        report.missing.join('\n') +
        '\n\nIf they were deleted, restore them from the Drive trash. If they ' +
        'were permanently removed, the IDs in the settings sheet need updating.');
  } else if (report.restored.length) {
    Integrity_alert_(
        'Kiosk: items were restored from the trash',
        'These were in the trash and have been put back:\n\n' +
        report.restored.join('\n'));
  }

  Log_info_('verifyResources: ' + JSON.stringify(report));
  return report;
}

/**
 * Re-applies editors and sheet protection to the settings spreadsheet.
 *
 * The point is that the config should only be edited through the kiosk's own
 * UI, so the sheets stay protected but the admins remain editors.
 *
 * @return {{sheets:number, protected:number}}
 */
function protectConfigSheets() {
  var cfg = Config_get();
  var admins = Integrity_adminEmails_(cfg);
  var result = {sheets: 0, protected: 0};

  if (cfg.ProtectConfigSheets === false) return result;

  try {
    var ss = SpreadsheetApp.openById(Config_spreadsheetId_());
    if (admins.length) {
      try { ss.addEditors(admins); } catch (err) { Log_warn_('addEditors', err); }
    }

    ss.getSheets().forEach(function (sheet) {
      result.sheets++;
      try {
        // Reuse the existing protection instead of creating another one.
        // Calling sheet.protect() repeatedly is what left these spreadsheets
        // with dozens of stacked protection ranges.
        var existing = sheet.getProtections(SpreadsheetApp.ProtectionType.SHEET);
        var protection = existing.length ? existing[0] : sheet.protect();

        protection.setDescription(
            'Edit through the kiosk configuration UI. See the help menu.');
        if (admins.length) protection.addEditors(admins);
        protection.setDomainEdit(false);

        result.protected++;
      } catch (err) {
        // Domain policy can forbid setDomainEdit on personal accounts.
        Log_warn_('Could not protect sheet ' + sheet.getName(), err);
      }
    });
  } catch (err) {
    Log_error_('protectConfigSheets', err);
  }

  Log_info_('protectConfigSheets: ' + JSON.stringify(result));
  return result;
}

/**
 * Resolves a folder by id, falling back to a search by name if the id no
 * longer resolves. This is the useful half of
 * `getDriveFolderIdsWithFallbackHandlingDrive` without the panic protocol.
 *
 * @return {?string} A usable folder id, or null.
 */
function Integrity_resolveFolder_(folderId, expectedName) {
  var meta = folderId ? Drive_getMeta_(folderId, 'id, name, trashed') : null;

  if (meta && !meta.trashed) return meta.id;
  if (meta && meta.trashed) {
    try {
      Drive_setTrashed_(folderId, false);
      return folderId;
    } catch (err) { /* fall through to the name search */ }
  }

  if (!expectedName) return null;

  var found = Drive_findByName_(expectedName, {foldersOnly: true});
  if (found.length) {
    Log_warn_('Folder id ' + folderId + ' no longer resolves; using "' +
              expectedName + '" (' + found[0].id + ') instead. Update the ' +
              'settings sheet.');
    return found[0].id;
  }
  return null;
}

/** @return {!Array<string>} Admin addresses from config. */
function Integrity_adminEmails_(cfg) {
  cfg = cfg || Config_get();
  // Accept the original sheet's key name as well as the new one.
  var raw = cfg.AdminEmails || cfg.ALL_ADMIN_EMAILS || '';
  return String(raw)
      .split(/[,;\s]+/)
      .map(function (email) { return email.trim(); })
      .filter(function (email) { return email.indexOf('@') > 0; });
}

/**
 * Sends an alert, at most once per subject per hour.
 *
 * The original mailed on every failing pass, which - on a job that runs every
 * minute - is 60 identical emails an hour telling you the same thing.
 */
function Integrity_alert_(subject, body) {
  var cfg = Config_get();
  var recipients = Integrity_adminEmails_(cfg);
  if (!recipients.length || cfg.EnableAlertEmails === false) {
    Log_warn_('ALERT (not emailed): ' + subject + ' :: ' + body);
    return false;
  }

  var cache = CacheService.getScriptCache();
  var key = 'alert:' + Hash_(subject);
  if (cache.get(key)) return false;
  cache.put(key, '1', 3600);

  try {
    MailApp.sendEmail(recipients.join(','), subject,
                      body + '\n\n-- Allegany Archive Kiosk');
    return true;
  } catch (err) {
    Log_error_('Integrity_alert_', err);
    return false;
  }
}

/**
 * Writes the visitor-facing "read me" into its Drive file.
 * This is `t()` from the utilities script, with the text pulled out of the
 * code so it can be edited without touching the script.
 */
function publishReadMe() {
  var cfg = Config_get();
  if (!cfg.ReadMeFileId) return false;

  var text = cfg.ReadMeText || [
    'Allegany Archive Kiosk - how to add photos',
    '',
    'Drop your photo into the "Upload Your New Photos Here" folder.',
    '',
    'Name it with the year first, for example "2027 Homecoming.jpg". The',
    'kiosk reads that year off the front of the name and files the photo into',
    'the matching year folder automatically. Anything without a year at the',
    'front goes to the review folder instead.',
    '',
    'JPEG and PNG only. Other formats (including TIFF) cannot be displayed in',
    'a browser and will be sent back to you.',
    '',
    'New photos appear on the kiosk within a few minutes.'
  ].join('\n');

  Drive_setTextContent_(cfg.ReadMeFileId, text);
  return true;
}
