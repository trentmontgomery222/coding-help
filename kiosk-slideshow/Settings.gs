/**
 * Settings.gs
 * ---------------------------------------------------------------------------
 * The single description of every kiosk setting: its name, what it does, what
 * kind of value it takes and what it defaults to.
 *
 * Everything else reads from here:
 *   Config.gs  - defaults, and the legacy key translation
 *   Setup.gs   - builds the spreadsheet, its help column and its validation
 *   Editor.gs  - builds the CardService editor's inputs
 *
 * One list, so the sheet, the editor and the code can never disagree about
 * what a setting is called or what it accepts.
 * ---------------------------------------------------------------------------
 */

var SETTING_GROUPS_ = [
  {id: 'slideshow',   name: 'Slideshow',   icon: 'SLIDESHOW',
   blurb: 'How long photos stay up and how they move.'},
  {id: 'photos',      name: 'Photos',      icon: 'PHOTO',
   blurb: 'Which folders play, and in what order.'},
  {id: 'intake',      name: 'Adding photos', icon: 'UPLOAD',
   blurb: 'What happens when someone drops a photo in the upload folder.'},
  {id: 'interaction', name: 'Touch screen', icon: 'TOUCH',
   blurb: 'Swipes, the details panel and visitor edits.'},
  {id: 'maintenance', name: 'Maintenance', icon: 'CLOCK',
   blurb: 'How often the background jobs run.'},
  {id: 'logging',     name: 'Logs & alerts', icon: 'DESCRIPTION',
   blurb: 'Where logs go and who hears about problems.'}
];

/**
 * @return {!Array<!Object>} Every setting, in display order.
 *   key      - the name used in the sheet and in code
 *   group    - which group id it belongs to
 *   label    - short human name
 *   help     - one sentence explaining it, shown in the sheet and the editor
 *   type     - number | boolean | text | choice | url | email | folder | sheet
 *   value    - the default
 *   choices  - for type 'choice'
 *   unit     - appended to the help text
 */
function Settings_all_() {
  return [
    /* ---------------- Slideshow ---------------- */
    {key: 'ImageDisplayTimeByDefault', group: 'slideshow', type: 'number', value: 13,
     label: 'Seconds per photo', unit: 'seconds',
     help: 'How long each photo stays on screen before the next one slides in.'},

    {key: 'ClassImageTimeMultiplier', group: 'slideshow', type: 'number', value: 2.5,
     label: 'Class photo multiplier',
     help: 'Class and team photos stay up this many times longer, because there ' +
           'is more to look at.'},

    {key: 'TransitionMs', group: 'slideshow', type: 'number', value: 1200,
     label: 'Transition length', unit: 'milliseconds',
     help: 'How long one photo takes to slide out while the next slides in. ' +
           '1200 is a calm cross-fade; 400 is brisk.'},

    {key: 'BackdropBlurPx', group: 'slideshow', type: 'number', value: 9,
     label: 'Background blur', unit: 'pixels',
     help: 'The photo is repeated behind itself, blurred, to fill the screen ' +
           'edge to edge. Higher is softer.'},

    {key: 'BackdropBrightness', group: 'slideshow', type: 'number', value: 0.5,
     label: 'Background brightness', unit: '0 to 1',
     help: 'How bright that blurred background is. Lower keeps attention on ' +
           'the photo itself.'},

    /* ---------------- Photos ---------------- */
    {key: 'ImagesFolderId', group: 'photos', type: 'folder',
     value: '1Xo-4k1TSv4BWaedaBWpgHsaBLIdjvtq9',
     label: 'Photo library folder',
     help: 'The Drive folder holding the year folders. This is what the kiosk plays.'},

    {key: 'IncludeSubFolders', group: 'photos', type: 'boolean', value: true,
     label: 'Include sub-folders',
     help: 'Play photos inside the year folders too, not just loose files.'},

    {key: 'ImageOrder', group: 'photos', type: 'choice', value: 'name',
     choices: ['name', 'year', 'shuffle', 'weighted'],
     label: 'Play order',
     help: 'name plays alphabetically, which for this archive is roughly ' +
           'chronological. weighted mixes the years up and favours the better ' +
           'photos. shuffle is fully random.'},

    {key: 'MinImagesPerYear', group: 'photos', type: 'number', value: 7,
     label: 'Minimum per year',
     help: 'In weighted order, every year gets at least this many photos so no ' +
           'year disappears from the rotation.'},

    {key: 'FeaturedYear', group: 'photos', type: 'number', value: 0,
     label: 'Spotlight a year',
     help: 'Show one graduating year far more often - useful around a reunion. ' +
           '0 turns it off.'},

    {key: 'MaxImageSizeMB', group: 'photos', type: 'number', value: 5,
     label: 'Largest photo', unit: 'MB',
     help: 'Photos bigger than this are skipped. Very large scans are slow to ' +
           'load and can stall the display.'},

    {key: 'AllowTiffs', group: 'photos', type: 'boolean', value: false,
     label: 'Allow TIFF files',
     help: 'Leave off. No browser except Safari can display a TIFF, and the ' +
           'files are enormous.'},

    {key: 'AlternateFolderId', group: 'photos', type: 'folder', value: '',
     label: 'Alternate folder',
     help: 'An optional second folder you can switch the kiosk to.'},

    {key: 'UseAlternateSet', group: 'photos', type: 'boolean', value: false,
     label: 'Play the alternate folder',
     help: 'Switches the display over to the alternate folder above.'},

    /* ---------------- Intake ---------------- */
    {key: 'UploadFolderId', group: 'intake', type: 'folder',
     value: '1Utxk3HjJqdaAQbU_EAgrhSnV7HPU1idP',
     label: 'Upload folder',
     help: 'Where people drop new photos. The kiosk files them into the right ' +
           'year folder automatically.'},

    {key: 'IntakeFallbackFolderId', group: 'intake', type: 'folder',
     value: '1kTxvtjehPyje2tdWe7cJWCCCyJi3WLj7',
     label: 'Review folder',
     help: 'Photos that are not named with a year at the front go here instead ' +
           'of being guessed at.'},

    {key: 'UploadLogSheetId', group: 'intake', type: 'sheet', value: '',
     label: 'Upload log spreadsheet',
     help: 'Every filed, skipped and rejected photo gets a row here. Leave ' +
           'blank to skip logging.'},

    {key: 'IntakeSettleSeconds', group: 'intake', type: 'number', value: 60,
     label: 'Wait before filing', unit: 'seconds',
     help: 'How long a new file must sit still before it is moved. Stops a ' +
           'half-finished upload being filed.'},

    {key: 'CreateMissingYearFolders', group: 'intake', type: 'boolean', value: true,
     label: 'Create year folders',
     help: 'Make a new year folder when a photo arrives for a year that has none.'},

    {key: 'EmailOnRejectedUpload', group: 'intake', type: 'boolean', value: true,
     label: 'Email about bad uploads',
     help: 'Tell the admins when someone uploads something the kiosk cannot ' +
           'display, such as a TIFF or a PDF.'},

    /* ---------------- Interaction ---------------- */
    {key: 'MinYMovementSwipe', group: 'interaction', type: 'number', value: 90,
     label: 'Swipe-up distance', unit: 'pixels',
     help: 'How far up someone must swipe to open the photo details.'},

    {key: 'MaxYMovementSwipe', group: 'interaction', type: 'number', value: 250,
     label: 'Swipe-up limit', unit: 'pixels',
     help: 'A swipe longer than this is ignored, so a stray drag does not open ' +
           'the panel.'},

    {key: 'MaxXMovementSwipe', group: 'interaction', type: 'number', value: 120,
     label: 'Swipe wobble allowed', unit: 'pixels',
     help: 'How far sideways a swipe can wander and still count as an upward one.'},

    {key: 'MaxImageDisplayNameLength', group: 'interaction', type: 'number', value: 27,
     label: 'Longest title shown', unit: 'characters',
     help: 'Titles longer than this are shortened so they fit on one line.'},

    {key: 'AllowViewerEdits', group: 'interaction', type: 'boolean', value: true,
     label: 'Let visitors edit',
     help: 'Allows anyone at the screen to correct a photo title or write a ' +
           'description. Only those two fields can ever be changed.'},

    {key: 'InfoPanelIdleCloseMs', group: 'interaction', type: 'number', value: 60000,
     label: 'Close details after', unit: 'milliseconds idle',
     help: 'The details panel closes itself once nobody has touched it for this long.'},

    {key: 'WebsiteURLOnClick', group: 'interaction', type: 'url',
     value: 'https://www.alleganyarchive.com/',
     label: 'Archive website',
     help: 'Shown on the download QR code and the details panel.'},

    /* ---------------- Maintenance ---------------- */
    {key: 'IntakeEveryMinutes', group: 'maintenance', type: 'number', value: 5,
     label: 'Check uploads every', unit: 'minutes',
     help: 'How often new photos are collected from the upload folder. 0 turns ' +
           'it off.'},

    {key: 'IntegrityEveryMinutes', group: 'maintenance', type: 'number', value: 60,
     label: 'Check folders every', unit: 'minutes',
     help: 'How often the kiosk checks that its folders and spreadsheets still ' +
           'exist and are not in the trash.'},

    {key: 'ManifestWarmEveryMinutes', group: 'maintenance', type: 'number', value: 360,
     label: 'Rebuild photo index every', unit: 'minutes',
     help: 'How often the list of photos is rebuilt from Drive.'},

    {key: 'ProtectSheetsEveryMinutes', group: 'maintenance', type: 'number', value: 720,
     label: 'Re-lock settings every', unit: 'minutes',
     help: 'Re-applies protection to these settings tabs so they are edited ' +
           'through the editor rather than by hand.'},

    {key: 'DailyRefreshHour', group: 'maintenance', type: 'number', value: 19,
     label: 'Nightly reload hour', unit: '0-23, -1 to disable',
     help: 'The hour the display reloads itself, to clear anything that has ' +
           'drifted during the day. 19 is 7pm.'},

    {key: 'CommandPollSeconds', group: 'maintenance', type: 'number', value: 180,
     label: 'Check for commands every', unit: 'seconds',
     help: 'How often the display asks the server whether you have queued a ' +
           'command for it, such as a reload.'},

    /* ---------------- Logging ---------------- */
    {key: 'EnableLogs', group: 'logging', type: 'boolean', value: true,
     label: 'Keep logs',
     help: 'Record what the display is doing. Useful when something goes wrong.'},

    {key: 'LogDestination', group: 'logging', type: 'choice', value: 'sheet',
     choices: ['sheet', 'session'],
     label: 'Where logs go',
     help: 'sheet keeps everything in one Logs tab. session makes a separate ' +
           'spreadsheet per start-up - star one in Drive to keep it.'},

    {key: 'SessionLogKeep', group: 'logging', type: 'number', value: 25,
     label: 'Session logs to keep',
     help: 'Older unstarred session logs are removed once there are more than this.'},

    {key: 'AdminEmails', group: 'logging', type: 'email',
     value: 'riddle.cayden@acpsmd.org brian.white@acpsmd.org',
     label: 'Admin emails',
     help: 'Who hears about problems. Separate several with spaces or commas.'},

    {key: 'EnableAlertEmails', group: 'logging', type: 'boolean', value: true,
     label: 'Send alert emails',
     help: 'Email the admins when something needs attention. At most one message ' +
           'an hour per problem.'},

    {key: 'EnableDeviceReporting', group: 'logging', type: 'boolean', value: true,
     label: 'Record display details',
     help: 'Notes the screen size and browser of each display, once per start-up.'},

    {key: 'DeveloperMode', group: 'logging', type: 'boolean', value: false,
     label: 'Developer mode',
     help: 'Extra logging and diagnostics. Leave off in normal use.'},

    {key: 'ShowDebugHud', group: 'logging', type: 'boolean', value: false,
     label: 'Show the stats overlay',
     help: 'Puts frame rate and memory figures in the corner of the display. ' +
           'The d key toggles it too.'},

    {key: 'AllowLegacyEvalCommands', group: 'logging', type: 'boolean', value: true,
     label: 'Allow old-style button code',
     help: 'Lets the button and keyboard tabs run raw JavaScript, as they did ' +
           'before. Turn off once every row uses an action name.'}
  ];
}

/** @return {!Object<string,!Object>} Settings keyed by name. */
function Settings_byKey_() {
  var map = {};
  Settings_all_().forEach(function (setting) { map[setting.key] = setting; });
  return map;
}

/** @return {!Array<!Object>} The settings in one group, in order. */
function Settings_inGroup_(groupId) {
  return Settings_all_().filter(function (s) { return s.group === groupId; });
}

/**
 * Settings that used to exist and no longer do.
 *
 * `to` renames a key; `parse` converts the old value format; a null `to` means
 * the setting is simply gone, and `why` explains that in the sheet so nobody
 * has to guess whether deleting the row is safe.
 */
function Settings_legacy_() {
  return {
    /* Renamed, with the value format carried across */
    'COMMAND_REQUEST_TIME_IN_SECONDS':          {to: 'CommandPollSeconds'},
    'BLUR_SLIDE_BLUR':                          {to: 'BackdropBlurPx'},
    'BLUR_SLIDE_BRIGHTNESS':                    {to: 'BackdropBrightness'},
    'SLIDESHOW_CLASS_PICTURE_TIME_MULTIPLIER':  {to: 'ClassImageTimeMultiplier'},
    'MAX_IMAGE_SIZE_IN_MB':                     {to: 'MaxImageSizeMB', parse: 'number'},
    'DAILY_IMAGE_REFRESH_TIME':                 {to: 'DailyRefreshHour', parse: 'hour'},
    'EnableBackendLogging':                     {to: 'EnableLogs'},
    'ENABLE_DEVICE_DETAILS_REPORTING':          {to: 'EnableDeviceReporting'},
    'DEVELOPER_MODE':                           {to: 'DeveloperMode'},
    'ALL_ADMIN_EMAILS':                         {to: 'AdminEmails'},
    'MinDBStorageSpace':                        {to: null,
      why: 'Photos are no longer copied into the browser, so there is no ' +
           'storage limit to set.'},

    /* Gone */
    'IndexedDBDatabaseName':   {to: null, why: 'The browser database is managed automatically.'},
    'IndexedDBStorageName':    {to: null, why: 'The browser database is managed automatically.'},
    'ImageSyncBatchSize':      {to: null, why: 'Photos load straight from Drive; there are no batches.'},
    'STATIC_IMAGE_URL':        {to: null, why: 'Replaced by the built-in loading screen.'},
    'DEVICE_DETAILS_BEACON_URL': {to: null, why: 'Reporting goes through the script itself now.'},
    'USE_LOCALSTORAGE':        {to: null, why: 'Always on; it is how the display resumes where it left off.'},
    'ENABLE_KEYBOARD_FUNCTIONS': {to: null, why: 'Keyboard shortcuts are always available.'},
    'MainImageSetEnabled':     {to: null, why: 'Replaced by "Play the alternate folder".'},
    'ALLOW_DEBUGGER':          {to: null, why: 'Replaced by "Show the stats overlay".'},
    'ALLOW_VIEWER_COMMENTATION': {to: null, why: 'Replaced by "Let visitors edit".'},
    'SELF_DIGNOISTICS_ENABLED': {to: null, why: 'Diagnostics run as part of maintenance.'},
    'IMAGE_REFRESH_ONLY_PROCESSES_NEW_IMAGES': {to: null,
      why: 'Only changed photos are ever re-read; this is automatic.'},
    'CHECK_ALL_IMAGES_FOR_CORRUPTED': {to: null,
      why: 'Handled by the nightly check. Run auditLibrary() to see the report.'},
    'ALLOW_NOT_SUPPORTED_IMAGE_TYPES': {to: null, why: 'Replaced by "Allow TIFF files".'},
    'CLASS_IMAGE_DISPLAY_TIME_LENGTH_IN_SECONDS': {to: null,
      why: 'Replaced by "Class photo multiplier", which scales with the normal time.'},
    'NIGHTLY_PROMISE_EMAILS':  {to: null, why: 'Replaced by "Admin emails".'},
    'DO_NIGHTLY_PROMISE_EMAILS': {to: null, why: 'Replaced by "Send alert emails".'},
    'FORCED_IMAGE_REDOWNLOAD_TIME': {to: null,
      why: 'The photo index refreshes on its own schedule now - see ' +
           '"Rebuild photo index every".'},
    'Loaded':                  {to: null, why: 'Was a leftover marker; it did nothing.'},

    /* Removed on purpose - see the note in the Help tab */
    'LoginAuthercatorUsername': {to: null,
      why: 'A password kept in a spreadsheet is readable by everyone the sheet ' +
           'is shared with. Use Drive sharing to control who can edit.'},
    'LoginAuthercatorPassword': {to: null,
      why: 'A password kept in a spreadsheet is readable by everyone the sheet ' +
           'is shared with. Use Drive sharing to control who can edit.'}
  };
}

/**
 * Converts a value written in the old sheet's format.
 * "5MB" -> 5, "7PM" -> 19, "17s" -> 17.
 */
function Settings_parseLegacy_(value, how) {
  var text = String(value === null || value === undefined ? '' : value).trim();
  if (!text) return '';

  if (how === 'number') {
    var num = parseFloat(text.replace(/[^0-9.\-]/g, ''));
    return isNaN(num) ? '' : num;
  }

  if (how === 'hour') {
    var match = text.toUpperCase().match(/^(\d{1,2})\s*(AM|PM)?/);
    if (!match) return '';
    var hour = parseInt(match[1], 10);
    if (match[2] === 'PM' && hour < 12) hour += 12;
    if (match[2] === 'AM' && hour === 12) hour = 0;
    return hour;
  }

  return value;
}
