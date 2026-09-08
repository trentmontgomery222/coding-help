/**
 * Config.gs
 * ---------------------------------------------------------------------------
 * Everything the kiosk reads out of the control spreadsheet:
 *   - Control Values          -> CFG   (numbers, flags, timings)
 *   - Language Values         -> LANG  (user-facing strings)
 *   - Keyboard Commandor      -> key bindings
 *   - Bottom Menu Controls    -> the on-screen button bar
 *   - Settings Menu Controller-> the settings overlay entries
 *
 * The old version read these one cell at a time inside a loop
 * (`ss.getRange(i+1, 2).getValue()` per row, per column). Each of those is a
 * separate round trip to the Sheets backend and they dominated page load.
 * Reading the whole sheet once with getValues() is typically 50-100x faster.
 *
 * Results are cached in CacheService so repeated kiosk loads and the 10-second
 * polling loop do not hammer the spreadsheet at all.
 * ---------------------------------------------------------------------------
 */

/** The control spreadsheet. Override with the SETTINGS_SHEET_ID script property. */
function Config_spreadsheetId_() {
  return PropertiesService.getScriptProperties().getProperty('SETTINGS_SHEET_ID') ||
         '1-j3vbxGd4X3Np2jwjW8DWM8GlAUtvtMRwH4pwrDa08g';
}

var CONFIG_CACHE_SECONDS_ = 300;   // 5 minutes
var SHEET_CONTROL_        = 'Control Values';
var SHEET_LANGUAGE_       = 'Language Values';
var SHEET_COMMANDS_       = 'Keyboard Commandor';
var SHEET_BOTTOM_MENU_    = 'Bottom Menu Controls';
var SHEET_SETTINGS_MENU_  = 'Settings Menu Controller';

/**
 * Reads an entire sheet in one call.
 * @return {!Array<!Array<*>>} Rows including the header row. [] if missing.
 */
function Config_readSheet_(sheetName) {
  try {
    var sheet = SpreadsheetApp.openById(Config_spreadsheetId_())
                              .getSheetByName(sheetName);
    if (!sheet) {
      Log_warn_('Missing sheet: ' + sheetName);
      return [];
    }
    var lastRow = sheet.getLastRow();
    var lastCol = sheet.getLastColumn();
    if (lastRow < 2 || lastCol < 1) return [];
    return sheet.getRange(1, 1, lastRow, lastCol).getValues();
  } catch (err) {
    Log_error_('Config_readSheet_ ' + sheetName, err);
    return [];
  }
}

/** Coerces a spreadsheet cell into a real JS type instead of a string. */
function Config_coerce_(value) {
  if (value === null || value === undefined || value === '') return '';
  if (typeof value === 'boolean' || typeof value === 'number') return value;
  if (value instanceof Date) return value.toISOString();

  var text = String(value).trim();
  var lower = text.toLowerCase();
  if (lower === 'true')  return true;
  if (lower === 'false') return false;
  // Only convert things that are unambiguously numeric - "2018 Band" must stay
  // a string, and so must "007".
  if (/^-?\d+(\.\d+)?$/.test(text) && String(Number(text)) === text) {
    return Number(text);
  }
  return text;
}

/* ===========================================================================
 * CFG - Control Values
 * =========================================================================*/

/**
 * Defaults.
 *
 * The settings people actually change come from Settings.gs, so the sheet, the
 * editor and this list cannot drift apart. The rest are internal knobs that do
 * not belong in a spreadsheet.
 */
function Config_defaults_() {
  var cfg = {};

  Settings_all_().forEach(function (setting) {
    cfg[setting.key] = setting.value;
  });

  /* Internal - not surfaced in the editor. */
  cfg.ImageSource = 'cdn';            // cdn | inline
  cfg.ImageWidth = 0;                 // 0 = size from the screen
  cfg.ManifestPageSize = 400;
  cfg.BackendFlushSeconds = 15;
  cfg.StatsIntervalSeconds = 900;
  cfg.InfoPanelHardCloseMs = 120000;
  cfg.WeeklyResyncDay = 'monday';
  cfg.WeeklyResyncHour = 19;
  cfg.AllowedUploadTypes = 'image/jpeg,image/png';
  cfg.ReadExifOnUpload = false;
  cfg.EnhanceInboxFolderId = '';
  cfg.EnhanceOutputFolderId = '';
  cfg.EnhanceArchiveFolderId = '';
  cfg.EnhanceEveryMinutes = 0;
  cfg.ProtectConfigSheets = true;
  cfg.SessionLogFolderId = '1dx6WTpUqZgjq7BBSVLnwuNGRvte2_0DB';
  cfg.ReadMeFileId = '17XDLe11aLUJ8_dSDHXrR2JfB4miTACFl';
  cfg.ReadMeText = '';

  return cfg;
}

/**
 * Applies one row from the Control Values sheet, translating a key that has
 * since been renamed or retired.
 *
 * Without this, a sheet that still says `BLUR_SLIDE_BLUR` or `MAX_IMAGE_SIZE_IN_MB`
 * would be silently ignored and the kiosk would quietly run on defaults.
 *
 * @param {!Object} cfg  Mutated in place.
 * @param {string} key
 * @param {*} rawValue
 */
function Config_applyRow_(cfg, key, rawValue) {
  var legacy = Settings_legacy_()[key];

  if (!legacy) {
    cfg[key] = Config_coerce_(rawValue);
    return;
  }

  if (!legacy.to) return;    // retired; the Help tab explains why

  var value = legacy.parse
      ? Settings_parseLegacy_(rawValue, legacy.parse)
      : Config_coerce_(rawValue);

  // A renamed key never overwrites the new one, so a half-migrated sheet
  // holding both `EnableBackendLogging` and `EnableLogs` uses the new value.
  if (value !== '' && !(legacy.to in cfg.__fromSheet)) {
    cfg[legacy.to] = value;
  }
}

/**
 * @return {!Object} Merged {defaults, ...spreadsheet overrides}.
 */
function Config_get() {
  var cached = Cache_getJson_('cfg');
  if (cached) return cached;

  var cfg = Config_defaults_();
  var rows = Config_readSheet_(SHEET_CONTROL_);

  if (!rows.length) {
    // The settings sheet is unreadable - deleted, renamed, or permissions
    // changed. Boot from the last known-good snapshot rather than reverting
    // to bare defaults and, say, playing the wrong folder.
    var snapshot = Config_restoreBackup_();
    if (snapshot) {
      Cache_putJson_('cfg', snapshot, 60);   // short TTL: retry the sheet soon
      return snapshot;
    }
    return cfg;
  }

  // Tracks which keys the sheet set under their current name, so a legacy
  // alias later in the sheet cannot overwrite them.
  cfg.__fromSheet = {};

  for (var i = 1; i < rows.length; i++) {
    var key = String(rows[i][0] || '').trim();
    if (!key) continue;
    if (!Settings_legacy_()[key]) cfg.__fromSheet[key] = true;
  }
  for (var j = 1; j < rows.length; j++) {
    var rowKey = String(rows[j][0] || '').trim();
    if (!rowKey) continue;
    Config_applyRow_(cfg, rowKey, rows[j][1]);
  }
  delete cfg.__fromSheet;

  Cache_putJson_('cfg', cfg, CONFIG_CACHE_SECONDS_);
  Config_backup_(cfg);
  PropertiesService.getScriptProperties()
      .setProperty('LAST_CONFIG_UPDATE', new Date().toISOString());
  return cfg;
}

/* ===========================================================================
 * LANG - Language Values
 * =========================================================================*/

/** Fallback copy so the UI never shows "undefined". */
function Lang_defaults_() {
  return {
    boot_Starting:            'Starting up...',
    boot_Ready:               'Ready',
    welcome_Greeting:         'Welcome',
    sync_Checking:            'Checking for new photos...',
    sync_UpToDate:            'Photo library is up to date',
    sync_Updated:             'Loaded {count} photos',
    sync_Failed:              'Could not reach the photo library',
    slideshow_Empty:          'No photos to show yet',
    slideshow_Restarted:      'Starting over from the beginning',
    paused_Reason:            'Paused - {reason}. Resuming in {seconds}s',
    info_NoDescription:       'Be the first to write something about this photo',
    info_Saved:               'Saved',
    info_SaveFailed:          'Could not save your changes',
    qr_Label:                 'Scan to open this photo',
    offline_Notice:           'Working offline from saved photos'
  };
}

/** @return {!Object<string,string>} */
function Lang_get() {
  var cached = Cache_getJson_('lang');
  if (cached) return cached;

  var lang = Lang_defaults_();
  var rows = Config_readSheet_(SHEET_LANGUAGE_);
  for (var i = 1; i < rows.length; i++) {
    var key = String(rows[i][0] || '').trim();
    if (!key) continue;
    lang[key] = String(rows[i][1] === undefined ? '' : rows[i][1]);
  }

  Cache_putJson_('lang', lang, CONFIG_CACHE_SECONDS_);
  return lang;
}

/* ===========================================================================
 * Keyboard commands
 * =========================================================================*/

/**
 * Column A is the key (or a legacy JS condition), column B is the action
 * (an action name like `next` or `pause(30)`, or legacy raw JS).
 * @return {!Array<{key:string, action:string}>}
 */
function Commands_get() {
  var cached = Cache_getJson_('cmds');
  if (cached) return cached;

  var list = [];
  var rows = Config_readSheet_(SHEET_COMMANDS_);
  for (var i = 1; i < rows.length; i++) {
    var key = String(rows[i][0] || '').trim();
    var action = String(rows[i][1] || '').trim();
    if (!key || !action) continue;
    list.push({key: key, action: action});
  }

  Cache_putJson_('cmds', list, CONFIG_CACHE_SECONDS_);
  return list;
}

/* ===========================================================================
 * Bottom button bar
 * =========================================================================*/

/**
 * Columns: A name/id | B idle icon | C click action | D active icon
 *          E "is active?" check | F active-state click action
 * Icons pasted as Drive share links are rewritten to CDN links, and the files
 * are shared once so they actually render.
 *
 * @return {!Array<!Object>}
 */
function BottomMenu_get() {
  var cached = Cache_getJson_('menu');
  if (cached) return cached;

  var rows = Config_readSheet_(SHEET_BOTTOM_MENU_);
  var items = [];

  for (var i = 1; i < rows.length; i++) {
    var name = String(rows[i][0] || '').trim();
    if (!name) continue;

    var idleIcon   = Menu_iconUrl_(rows[i][1]);
    var activeIcon = Menu_iconUrl_(rows[i][3]) || idleIcon;
    var click      = String(rows[i][2] || '').trim();
    var check      = String(rows[i][4] || '').trim();
    var activeClick = String(rows[i][5] || '').trim() || click;

    items.push({
      id: Menu_slug_(name, i),
      name: name,
      icon: idleIcon,
      activeIcon: activeIcon,
      click: click,
      activeClick: activeClick,
      check: check,
      // "nameplate" is the special marker meaning "put the photo title here".
      isNamePlate: String(rows[i][1] || '').trim().toLowerCase() === 'nameplate'
    });
  }

  Cache_putJson_('menu', items, CONFIG_CACHE_SECONDS_);
  return items;
}

/** Turns a Drive share link into a directly loadable image URL. */
function Menu_iconUrl_(raw) {
  var text = String(raw || '').trim();
  if (!text || text.toLowerCase() === 'nameplate') return '';
  if (/^https?:\/\//.test(text) === false) return text;

  var match = text.match(/[-\w]{25,}/);
  if (!match) return text;

  var id = match[0];
  Drive_ensurePublic_(id, false);
  return 'https://lh3.googleusercontent.com/d/' + id;
}

/** Stable, DOM-safe id for a menu button. */
function Menu_slug_(name, index) {
  var slug = String(name).toLowerCase().replace(/[^a-z0-9]+/g, '-')
                         .replace(/^-|-$/g, '');
  return 'btn-' + (slug || 'item') + '-' + index;
}

/* ===========================================================================
 * Settings menu
 * =========================================================================*/

/** @return {!Array<!Object>} */
function SettingsMenu_get() {
  var cached = Cache_getJson_('settings');
  if (cached) return cached;

  var rows = Config_readSheet_(SHEET_SETTINGS_MENU_);
  var items = [];
  for (var i = 1; i < rows.length; i++) {
    var name = String(rows[i][0] || '').trim();
    if (!name) continue;
    items.push({
      name: name,
      icon: String(rows[i][1] || ''),
      action: String(rows[i][2] || ''),
      check: String(rows[i][3] || ''),
      color: String(rows[i][4] || '')
    });
  }

  Cache_putJson_('settings', items, CONFIG_CACHE_SECONDS_);
  return items;
}

/* ===========================================================================
 * Cache invalidation
 * =========================================================================*/

/**
 * Drops every cached config blob. Wire this to an onEdit installable trigger
 * on the control spreadsheet so edits go live within seconds instead of
 * waiting out the 5-minute cache.
 */
function Config_invalidate() {
  CacheService.getScriptCache()
      .removeAll(['cfg', 'lang', 'cmds', 'menu', 'settings'].map(Cache_key_));
  return true;
}

/** Installable onEdit target for the control spreadsheet. */
function onSettingsEdit(e) {
  Config_invalidate();
}

/* ===========================================================================
 * Disaster recovery
 *
 * The utilities script's `getConfigurationSpreadsheetWithFallbackCreation`
 * mirrored every config row into Script Properties, then logged
 * "FALL BACK NEEDED!!!! Recreation of files required!" and stopped - the
 * recovery half was never written.
 *
 * These two functions finish the idea: the settings are snapshotted after
 * every successful read, and if the spreadsheet is ever unreadable the kiosk
 * boots from the snapshot instead of falling back to bare defaults.
 * =========================================================================*/

var CONFIG_BACKUP_KEY_ = 'CONFIG_BACKUP';
var CONFIG_BACKUP_AT_  = 'CONFIG_BACKUP_AT';

/** Stores the current settings so they survive the spreadsheet being lost. */
function Config_backup_(cfg) {
  try {
    var props = PropertiesService.getScriptProperties();
    var text = JSON.stringify(cfg);
    // A single property caps at 9KB; a config this size is well under it, but
    // refuse rather than throw if someone adds something enormous.
    if (text.length > 8500) {
      Log_warn_('Config too large to back up (' + text.length + ' bytes)');
      return false;
    }
    props.setProperty(CONFIG_BACKUP_KEY_, text);
    props.setProperty(CONFIG_BACKUP_AT_, new Date().toISOString());
    return true;
  } catch (err) {
    Log_warn_('Config_backup_ failed', err);
    return false;
  }
}

/** @return {?Object} The last known-good settings, or null. */
function Config_restoreBackup_() {
  try {
    var props = PropertiesService.getScriptProperties();
    var saved = Json_parse_(props.getProperty(CONFIG_BACKUP_KEY_));
    if (!saved) return null;
    Log_warn_('Using the settings snapshot from ' +
              props.getProperty(CONFIG_BACKUP_AT_));
    return saved;
  } catch (err) {
    return null;
  }
}

/**
 * Reads any two-or-more column sheet into an object keyed by column A.
 *
 * Two columns give {key: value}; three or more give
 * {key: {Header: value, ...}}. This is the shape
 * `getConfigurationSpreadsheetWithFallbackCreation` returned, but read in one
 * getValues() call instead of one per cell, and without skipping the last row
 * (the original's loop condition was `r < getLastRow()`).
 *
 * @return {!Object}
 */
function Config_readKeyed(sheetName) {
  var rows = Config_readSheet_(sheetName);
  if (rows.length < 2) return {};

  var headers = rows[0].map(function (h) { return String(h || '').trim(); });
  var out = {};

  for (var r = 1; r < rows.length; r++) {
    var key = String(rows[r][0] || '').trim();
    if (!key) continue;

    if (headers.length <= 2) {
      out[key] = Config_coerce_(rows[r][1]);
    } else {
      var record = {};
      for (var c = 1; c < headers.length; c++) {
        if (headers[c]) record[headers[c]] = Config_coerce_(rows[r][c]);
      }
      out[key] = record;
    }
  }
  return out;
}
