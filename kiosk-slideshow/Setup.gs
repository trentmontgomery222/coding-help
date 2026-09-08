/**
 * Setup.gs
 * ---------------------------------------------------------------------------
 * Builds the configuration spreadsheet: every tab, its headings, its help
 * text, its data validation and its formatting.
 *
 * Run `setupConfigurationSpreadsheet()` once. It is safe to re-run - existing
 * values are read first and written back, so nothing you have set is lost.
 *
 * What it does to the sheet you have today:
 *   - rewrites Control Values with a Setting / Value / What it does layout,
 *     migrating renamed keys and their old value formats ("5MB" -> 5,
 *     "7PM" -> 19)
 *   - moves retired settings to a "Retired settings" tab with a note saying
 *     why each one went, rather than deleting anything outright
 *   - adds a Help tab explaining the whole system
 *   - leaves Bottom Menu Controls, Keyboard Commandor, Language Values and
 *     Settings Menu Controller alone apart from headings and formatting,
 *     because those hold your own work
 *   - archives the duplicate `Config` tab and the generated `Sheet6` index
 * ---------------------------------------------------------------------------
 */

var SETUP_DONE_KEY_ = 'CONFIG_SHEET_BUILT';

var TAB_HELP_     = 'Help';
var TAB_RETIRED_  = 'Retired settings';

/** Colours, kept in one place so every tab looks like the same document. */
var SHEET_STYLE_ = {
  header:     '#1c2733',
  headerText: '#ffffff',
  group:      '#e8eef5',
  helpText:   '#5f6b7a',
  accent:     '#1a73e8'
};

/**
 * Builds or repairs the whole configuration spreadsheet.
 *
 * @param {boolean=} force Re-run even if it has been built before.
 * @return {!Object} What changed.
 */
function setupConfigurationSpreadsheet(force) {
  var props = PropertiesService.getScriptProperties();
  if (props.getProperty(SETUP_DONE_KEY_) && !force) {
    Log_info_('Configuration sheet already built. Pass true to rebuild.');
    return {skipped: true, builtAt: props.getProperty(SETUP_DONE_KEY_)};
  }

  var ss = SpreadsheetApp.openById(Config_spreadsheetId_());
  var report = {migrated: 0, retired: [], archived: [], tabs: []};

  // Read what is there before touching anything.
  var existing = Setup_readExistingValues_(ss);

  Setup_buildHelpTab_(ss);                        report.tabs.push(TAB_HELP_);
  Setup_buildControlValues_(ss, existing, report); report.tabs.push(SHEET_CONTROL_);
  Setup_buildRetiredTab_(ss, existing, report);
  Setup_formatWorkingTabs_(ss, report);
  Setup_archiveUnused_(ss, report);
  Setup_orderTabs_(ss);

  Config_invalidate();
  props.setProperty(SETUP_DONE_KEY_, new Date().toISOString());

  Log_info_('Configuration sheet built:\n' + JSON.stringify(report, null, 2));
  return report;
}

/** Reads the current Control Values (and its duplicate) into a plain map. */
function Setup_readExistingValues_(ss) {
  var found = {};

  ['Control Values', 'Config'].forEach(function (name) {
    var sheet = ss.getSheetByName(name);
    if (!sheet || sheet.getLastRow() < 2) return;

    var rows = sheet.getRange(1, 1, sheet.getLastRow(), 2).getValues();
    for (var i = 1; i < rows.length; i++) {
      var key = String(rows[i][0] || '').trim();
      if (!key || key in found) continue;
      found[key] = rows[i][1];
    }
  });
  return found;
}

/* ===========================================================================
 * Control Values
 * =========================================================================*/

/**
 * Rewrites Control Values as Setting / Value / What it does, grouped, with
 * validation and the current value carried over.
 */
function Setup_buildControlValues_(ss, existing, report) {
  var sheet = Setup_sheet_(ss, SHEET_CONTROL_);
  sheet.clear();
  sheet.clearNotes();
  Setup_clearValidation_(sheet);

  var rows = [['Key', 'Value', 'What it does']];
  var groupRowIndexes = [];
  var validations = [];

  SETTING_GROUPS_.forEach(function (group) {
    var settings = Settings_inGroup_(group.id);
    if (!settings.length) return;

    groupRowIndexes.push(rows.length + 1);
    rows.push([group.name.toUpperCase(), '', group.blurb]);

    settings.forEach(function (setting) {
      var value = Setup_valueFor_(setting, existing, report);
      var help = setting.help + (setting.unit ? '  (' + setting.unit + ')' : '');

      rows.push([setting.key, value, help]);
      validations.push({row: rows.length, setting: setting});
    });
  });

  sheet.getRange(1, 1, rows.length, 3).setValues(rows);

  /* --- Formatting --- */
  sheet.setFrozenRows(1);
  sheet.setColumnWidth(1, 260);
  sheet.setColumnWidth(2, 220);
  sheet.setColumnWidth(3, 620);

  sheet.getRange(1, 1, 1, 3)
       .setBackground(SHEET_STYLE_.header)
       .setFontColor(SHEET_STYLE_.headerText)
       .setFontWeight('bold');

  groupRowIndexes.forEach(function (rowIndex) {
    sheet.getRange(rowIndex, 1, 1, 3)
         .setBackground(SHEET_STYLE_.group)
         .setFontWeight('bold');
  });

  sheet.getRange(2, 3, rows.length - 1, 1)
       .setFontColor(SHEET_STYLE_.helpText)
       .setFontSize(10)
       .setWrap(true);

  sheet.getRange(1, 1, rows.length, 3).setVerticalAlignment('middle');

  /* --- Validation, so a typo cannot break the display --- */
  validations.forEach(function (entry) {
    Setup_applyValidation_(sheet, entry.row, entry.setting);
  });

  // The help column is documentation, not input.
  Setup_protectRange_(sheet, sheet.getRange(1, 3, rows.length, 1),
                      'Help text - edited from the script, not by hand.');
}

/** Current value if there is one, else the migrated legacy value, else default. */
function Setup_valueFor_(setting, existing, report) {
  if (setting.key in existing && String(existing[setting.key]).trim() !== '') {
    return Setup_normalise_(existing[setting.key], setting);
  }

  // Look for an old key that maps to this one.
  var legacyMap = Settings_legacy_();
  for (var oldKey in legacyMap) {
    if (!Object.prototype.hasOwnProperty.call(legacyMap, oldKey)) continue;
    var rule = legacyMap[oldKey];
    if (rule.to !== setting.key) continue;
    if (!(oldKey in existing)) continue;

    var raw = existing[oldKey];
    if (String(raw).trim() === '') continue;

    var moved = rule.parse ? Settings_parseLegacy_(raw, rule.parse) : raw;
    if (moved === '') continue;

    report.migrated++;
    Log_info_('Migrated ' + oldKey + ' (' + raw + ') -> ' + setting.key +
              ' (' + moved + ')');
    return Setup_normalise_(moved, setting);
  }

  return setting.value;
}

/** Coerces a stored value into the shape its setting expects. */
function Setup_normalise_(value, setting) {
  if (setting.type === 'boolean') {
    var text = String(value).trim().toLowerCase();
    return value === true || text === 'true' || text === 'yes' || text === '1';
  }
  if (setting.type === 'number') {
    var num = parseFloat(String(value).replace(/[^0-9.\-]/g, ''));
    return isNaN(num) ? setting.value : num;
  }
  return value;
}

/** Checkbox for booleans, dropdown for choices, number rule for numbers. */
function Setup_applyValidation_(sheet, row, setting) {
  var cell = sheet.getRange(row, 2);

  if (setting.type === 'boolean') {
    cell.setDataValidation(
        SpreadsheetApp.newDataValidation().requireCheckbox().build());
    return;
  }
  if (setting.type === 'choice') {
    cell.setDataValidation(SpreadsheetApp.newDataValidation()
        .requireValueInList(setting.choices, true)
        .setAllowInvalid(false)
        .setHelpText('Choose one of: ' + setting.choices.join(', '))
        .build());
    return;
  }
  if (setting.type === 'number') {
    cell.setDataValidation(SpreadsheetApp.newDataValidation()
        .requireNumberGreaterThanOrEqualTo(-1)
        .setAllowInvalid(false)
        .setHelpText(setting.label + ' must be a number.')
        .build());
    return;
  }
  if (setting.type === 'folder' || setting.type === 'sheet') {
    cell.setNote('Paste the Drive link or just the ID - either works.');
  }
}

/* ===========================================================================
 * Retired settings
 * =========================================================================*/

/**
 * Anything in the old sheet that no longer does anything is listed here with
 * the reason, instead of vanishing. If a value was carried over to a new
 * setting, that is named too.
 */
function Setup_buildRetiredTab_(ss, existing, report) {
  var legacyMap = Settings_legacy_();
  var rows = [['Old setting', 'Value it had', 'What happened to it']];

  Object.keys(existing).forEach(function (key) {
    var rule = legacyMap[key];
    if (!rule) return;

    rows.push([
      key,
      existing[key] === '' ? '(blank)' : existing[key],
      rule.to ? 'Renamed to "' + rule.to + '". Your value was carried across.'
              : rule.why
    ]);
    report.retired.push(key);
  });

  if (rows.length === 1) {
    rows.push(['(none)', '', 'Nothing in this spreadsheet is out of date.']);
  }

  var sheet = Setup_sheet_(ss, TAB_RETIRED_);
  sheet.clear();
  sheet.getRange(1, 1, rows.length, 3).setValues(rows);
  sheet.setFrozenRows(1);
  sheet.setColumnWidth(1, 300);
  sheet.setColumnWidth(2, 200);
  sheet.setColumnWidth(3, 640);
  sheet.getRange(1, 1, 1, 3)
       .setBackground(SHEET_STYLE_.header)
       .setFontColor(SHEET_STYLE_.headerText)
       .setFontWeight('bold');
  sheet.getRange(2, 3, rows.length - 1, 1).setWrap(true)
       .setFontColor(SHEET_STYLE_.helpText);
}

/* ===========================================================================
 * Help
 * =========================================================================*/

/** The guide. Plain sentences, no jargon - it is read by whoever inherits this. */
function Setup_buildHelpTab_(ss) {
  var lines = [
    ['Allegany Archive Kiosk - how this spreadsheet works', ''],
    ['', ''],
    ['This spreadsheet is the control panel for the photo display.', ''],
    ['Change something here and the display picks it up within five minutes.', ''],
    ['', ''],

    ['THE EASY WAY TO EDIT', ''],
    ['Open the "Kiosk" menu at the top of this window and choose', ''],
    ['"Edit settings". You get a panel with a proper control for each', ''],
    ['setting and a description of what it does. Nothing can be mistyped.', ''],
    ['', ''],
    ['You can edit the Value column by hand instead, but the drop-downs', ''],
    ['and tick boxes are there to stop typos, so prefer the editor.', ''],
    ['', ''],

    ['WHAT EACH TAB IS FOR', ''],
    ['Control Values', 'Every setting, grouped, with a description of each.'],
    ['Bottom Menu Controls', 'The row of buttons along the bottom of the display.'],
    ['Keyboard Commandor', 'Keyboard shortcuts, for a display with a keyboard.'],
    ['Language Values', 'The wording of on-screen messages.'],
    ['Settings Menu Controller', 'The entries in the on-screen settings menu.'],
    ['Retired settings', 'Old settings that no longer do anything, and why.'],
    ['Logs / Stats / Devices', 'Written by the display. Nothing to edit here.'],
    ['', ''],

    ['ADDING PHOTOS', ''],
    ['Put the photo in the "Upload Your New Photos Here" folder in Drive.', ''],
    ['Name it with the year first, like "2027 Homecoming.jpg", and it is', ''],
    ['filed into that year automatically within a few minutes.', ''],
    ['Photos without a year at the front go to the review folder instead.', ''],
    ['JPEG and PNG only - a browser cannot display a TIFF.', ''],
    ['', ''],

    ['THE BUTTON BAR', ''],
    ['Each row in Bottom Menu Controls is one slot along the bottom bar.', ''],
    ['A row with an image is a button. A row with the word "nameplate" in', ''],
    ['the image column is part of the photo title area - the number of', ''],
    ['those rows sets how wide the title is, which is what pushes the', ''],
    ['playback buttons into the middle. A row left blank is a gap.', ''],
    ['', ''],
    ['So: to move the playback buttons right, add nameplate rows. To move', ''],
    ['them left, remove some. To space buttons apart, insert blank rows.', ''],
    ['', ''],

    ['THE COMMAND COLUMNS', ''],
    ['The Command column can hold a short action name:', ''],
    ['next / previous / forward5 / back5', 'move through the photos'],
    ['pause(60) / resume / togglePause', 'hold the display'],
    ['openInfo / closeInfo / toggleInfo', 'the photo details panel'],
    ['browse / search', 'the searchable photo list'],
    ['resync / reload / restart', 'reload photos or the whole page'],
    ['toggleHud', 'the statistics overlay'],
    ['', ''],
    ['Older rows contain JavaScript instead, like controllerButtonFoward().', ''],
    ['Those still work. There is no need to change them.', ''],
    ['', ''],

    ['IF SOMETHING LOOKS WRONG', ''],
    ['1. Open the Kiosk menu and choose "Check setup". It tests the', ''],
    ['   folders, permissions and schedules and lists anything broken.', ''],
    ['2. Check the Logs tab for the display\'s own account of what it did.', ''],
    ['3. Kiosk > "Reload every display" restarts them without a site visit.', ''],
    ['', ''],

    ['A NOTE ON PASSWORDS', ''],
    ['The old sheet had a login username and password in plain text.', ''],
    ['Anyone the spreadsheet is shared with could read them, so they have', ''],
    ['been removed. Control who can change things with Drive sharing on', ''],
    ['this file instead - that is what it is for.', '']
  ];

  var sheet = Setup_sheet_(ss, TAB_HELP_);
  sheet.clear();
  sheet.getRange(1, 1, lines.length, 2).setValues(lines);

  sheet.setColumnWidth(1, 560);
  sheet.setColumnWidth(2, 460);
  sheet.getRange(1, 1).setFontSize(16).setFontWeight('bold')
       .setFontColor(SHEET_STYLE_.header);

  // Bold the section headings - the all-caps lines with no second column.
  for (var i = 0; i < lines.length; i++) {
    var text = String(lines[i][0]);
    if (text && text === text.toUpperCase() && text.length > 3 && i > 0) {
      sheet.getRange(i + 1, 1, 1, 2).setFontWeight('bold')
           .setFontColor(SHEET_STYLE_.accent);
    }
  }
  sheet.getRange(1, 1, lines.length, 2).setVerticalAlignment('top');
  sheet.setHiddenGridlines(true);
}

/* ===========================================================================
 * The tabs holding your own work
 * =========================================================================*/

/**
 * Headings and formatting only. These tabs hold hand-built configuration, so
 * their contents are never rewritten.
 */
function Setup_formatWorkingTabs_(ss, report) {
  var tabs = [
    {name: SHEET_BOTTOM_MENU_, widths: [90, 320, 380, 320, 300, 300],
     headers: ['ID', 'Default Image', 'Command', 'Active Image',
               'Active Check', 'Active Command']},
    {name: SHEET_COMMANDS_, widths: [220, 620],
     headers: ['Key', 'Command']},
    {name: SHEET_LANGUAGE_, widths: [340, 620],
     headers: ['Key', 'Message']},
    {name: SHEET_SETTINGS_MENU_, widths: [220, 300, 380, 300, 200],
     headers: ['Name', 'Shown When', 'Command', 'Active Check', 'Colour']}
  ];

  tabs.forEach(function (tab) {
    var sheet = ss.getSheetByName(tab.name);
    if (!sheet) {
      sheet = ss.insertSheet(tab.name);
      sheet.appendRow(tab.headers);
    }
    report.tabs.push(tab.name);

    sheet.getRange(1, 1, 1, tab.headers.length).setValues([tab.headers]);
    sheet.setFrozenRows(1);
    sheet.getRange(1, 1, 1, tab.headers.length)
         .setBackground(SHEET_STYLE_.header)
         .setFontColor(SHEET_STYLE_.headerText)
         .setFontWeight('bold');

    tab.widths.forEach(function (width, index) {
      sheet.setColumnWidth(index + 1, width);
    });
  });
}

/* ===========================================================================
 * Housekeeping
 * =========================================================================*/

/**
 * Hides tabs that are duplicates or machine-generated. They are hidden rather
 * than deleted - deleting somebody's data on their behalf is not this
 * function's call to make.
 */
function Setup_archiveUnused_(ss, report) {
  ['Config', 'Sheet6', 'GO HERE TO EDIT!'].forEach(function (name) {
    var sheet = ss.getSheetByName(name);
    if (!sheet) return;
    try {
      sheet.hideSheet();
      report.archived.push(name);
    } catch (err) {
      Log_warn_('Could not hide ' + name, err);
    }
  });
}

/** Help first, then settings, then the working tabs. */
function Setup_orderTabs_(ss) {
  var order = [TAB_HELP_, SHEET_CONTROL_, SHEET_BOTTOM_MENU_, SHEET_COMMANDS_,
               SHEET_LANGUAGE_, SHEET_SETTINGS_MENU_, TAB_RETIRED_];
  order.forEach(function (name, index) {
    var sheet = ss.getSheetByName(name);
    if (!sheet) return;
    ss.setActiveSheet(sheet);
    ss.moveActiveSheet(index + 1);
  });
  var help = ss.getSheetByName(TAB_HELP_);
  if (help) ss.setActiveSheet(help);
}

/** Gets a tab, creating it when missing. */
function Setup_sheet_(ss, name) {
  return ss.getSheetByName(name) || ss.insertSheet(name);
}

function Setup_clearValidation_(sheet) {
  try {
    sheet.getRange(1, 1, Math.max(1, sheet.getMaxRows()),
                   Math.max(1, sheet.getMaxColumns())).clearDataValidations();
  } catch (err) { /* nothing to clear */ }
}

/** Protects a range, reusing an existing protection rather than stacking. */
function Setup_protectRange_(sheet, range, description) {
  try {
    var existing = sheet.getProtections(SpreadsheetApp.ProtectionType.RANGE);
    for (var i = 0; i < existing.length; i++) {
      if (existing[i].getDescription() === description) {
        existing[i].setRange(range);
        return;
      }
    }
    sheet.protect().setRange(range).setDescription(description)
         .setWarningOnly(true);
  } catch (err) {
    Log_warn_('Could not protect range', err);
  }
}
