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
 * Defaults. Any key present in the spreadsheet overrides the value here, so
 * the kiosk still boots correctly against an empty or half-filled sheet.
 */
function Config_defaults_() {
  return {
    /* --- Slideshow --------------------------------------------------- */
    ImageDisplayTimeByDefault: 12,      // seconds per slide
    ClassImageTimeMultiplier: 2,        // class photos linger longer
    TransitionMs: 1200,                 // slide/cross-fade duration
    ImageOrder: 'name',                 // name | year | shuffle | weighted
    MinImagesPerYear: 7,                // weighted-selection floor
    ImageSource: 'cdn',                 // cdn | inline
    ImageWidth: 0,                      // 0 = auto from screen size
    BackdropBlurPx: 28,
    BackdropBrightness: 0.55,

    /* --- Content ----------------------------------------------------- */
    ImagesFolderId: '1Xo-4k1TSv4BWaedaBWpgHsaBLIdjvtq9',
    AlternateFolderId: '1yWR1vwL2sJrzG5CMLUVmbx4F5pQd-AyN',
    UseAlternateSet: false,             // play the alternate folder instead
    FeaturedYear: 0,                    // 0 = no year is spotlighted
    IncludeSubFolders: true,
    MaxImageSizeMB: 25,
    AllowTiffs: false,
    ManifestPageSize: 400,

    /* --- Interaction -------------------------------------------------- */
    MinYMovementSwipe: 80,              // px before an upward swipe counts
    MaxYMovementSwipe: 2000,
    MaxXMovementSwipe: 120,             // horizontal slop allowed in a swipe
    InfoPanelIdleCloseMs: 60000,
    InfoPanelHardCloseMs: 120000,
    MaxImageDisplayNameLength: 64,
    AllowViewerEdits: true,
    WebsiteURLOnClick: '',

    /* --- Behaviour ---------------------------------------------------- */
    DailyRefreshHour: 23,               // 24h clock; page reloads itself
    WeeklyResyncDay: 'monday',
    WeeklyResyncHour: 19,
    CommandPollSeconds: 30,
    BackendFlushSeconds: 15,
    StatsIntervalSeconds: 900,
    EnableLogs: true,
    EnableDeviceReporting: true,
    AllowLegacyEvalCommands: true,      // run raw JS from the command sheets
    DeveloperMode: false,
    ShowDebugHud: false
  };
}

/**
 * @return {!Object} Merged {defaults, ...spreadsheet overrides}.
 */
function Config_get() {
  var cached = Cache_getJson_('cfg');
  if (cached) return cached;

  var cfg = Config_defaults_();
  var rows = Config_readSheet_(SHEET_CONTROL_);

  for (var i = 1; i < rows.length; i++) {
    var key = String(rows[i][0] || '').trim();
    if (!key) continue;
    cfg[key] = Config_coerce_(rows[i][1]);
  }

  Cache_putJson_('cfg', cfg, CONFIG_CACHE_SECONDS_);
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
