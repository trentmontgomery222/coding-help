/**
 * Editor.gs
 * ---------------------------------------------------------------------------
 * A settings editor built with CardService, plus a Kiosk menu in the
 * spreadsheet for the day-to-day jobs.
 *
 * Every control is generated from Settings.gs, so a setting added there shows
 * up here automatically with the right kind of input - a tick box for a yes/no,
 * a drop-down for a fixed set of choices, a plain box for a number - and the
 * same one-line explanation the sheet carries.
 *
 * Two ways in:
 *   - The card editor, in the sidebar of the spreadsheet. Needs the project
 *     installed as an Editor add-on: Deploy > Test deployments > Install.
 *   - The "Kiosk" menu, which works the moment the script is saved.
 * ---------------------------------------------------------------------------
 */

/** Editor add-on homepage: the list of setting groups. */
function onHomepage(e) {
  return Editor_homeCard_();
}

/** Same card when a spreadsheet is opened with the add-on installed. */
function onFileScopeGrantedSheets(e) {
  return Editor_homeCard_();
}

/* ===========================================================================
 * Cards
 * =========================================================================*/

function Editor_homeCard_() {
  var card = CardService.newCardBuilder()
      .setHeader(CardService.newCardHeader()
          .setTitle('Kiosk settings')
          .setSubtitle('Allegany Archive photo display'));

  var intro = CardService.newCardSection().addWidget(
      CardService.newTextParagraph().setText(
          'Pick a group to edit. Changes reach the displays within five ' +
          'minutes, or straight away if you use <b>Apply now</b>.'));
  card.addSection(intro);

  var groups = CardService.newCardSection().setHeader('Settings');
  SETTING_GROUPS_.forEach(function (group) {
    groups.addWidget(CardService.newDecoratedText()
        .setText(group.name)
        .setBottomLabel(group.blurb)
        .setWrapText(true)
        .setStartIcon(CardService.newIconImage()
            .setIcon(CardService.Icon[group.icon] || CardService.Icon.STAR))
        .setOnClickAction(CardService.newAction()
            .setFunctionName('editorShowGroup')
            .setParameters({group: group.id})));
  });
  card.addSection(groups);

  var tools = CardService.newCardSection().setHeader('Jobs');
  [
    {label: 'Check setup',        sub: 'Test folders, sharing and schedules',   fn: 'editorCheckSetup'},
    {label: 'Collect new photos', sub: 'Empty the upload folder now',           fn: 'editorRunIntake'},
    {label: 'Rebuild photo index',sub: 'Pick up photos added outside the kiosk', fn: 'editorRefreshPhotos'},
    {label: 'Reload every display', sub: 'Restart the screens remotely',        fn: 'editorReloadDisplays'}
  ].forEach(function (tool) {
    tools.addWidget(CardService.newDecoratedText()
        .setText(tool.label)
        .setBottomLabel(tool.sub)
        .setWrapText(true)
        .setOnClickAction(CardService.newAction().setFunctionName(tool.fn)));
  });
  card.addSection(tools);

  return card.build();
}

/** One group's settings, as editable widgets. */
function editorShowGroup(e) {
  var groupId = e.parameters.group;
  var group = SETTING_GROUPS_.filter(function (g) { return g.id === groupId; })[0];
  if (!group) return Editor_notify_('That group no longer exists.');

  var cfg = Config_get();
  var card = CardService.newCardBuilder()
      .setHeader(CardService.newCardHeader()
          .setTitle(group.name)
          .setSubtitle(group.blurb));

  var section = CardService.newCardSection();

  Settings_inGroup_(groupId).forEach(function (setting) {
    section.addWidget(Editor_widget_(setting, cfg[setting.key]));
  });

  section.addWidget(CardService.newButtonSet()
      .addButton(CardService.newTextButton()
          .setText('Save')
          .setTextButtonStyle(CardService.TextButtonStyle.FILLED)
          .setOnClickAction(CardService.newAction()
              .setFunctionName('editorSaveGroup')
              .setParameters({group: groupId})))
      .addButton(CardService.newTextButton()
          .setText('Back')
          .setOnClickAction(CardService.newAction()
              .setFunctionName('editorGoHome'))));

  card.addSection(section);

  return CardService.newActionResponseBuilder()
      .setNavigation(CardService.newNavigation().pushCard(card.build()))
      .build();
}

/**
 * Builds the right input for a setting's type.
 * The explanation is attached to every control, so nobody has to guess what a
 * field does or go hunting in a separate document.
 */
function Editor_widget_(setting, current) {
  var hint = setting.help + (setting.unit ? ' (' + setting.unit + ')' : '');

  if (setting.type === 'boolean') {
    return CardService.newDecoratedText()
        .setText(setting.label)
        .setBottomLabel(hint)
        .setWrapText(true)
        .setSwitchControl(CardService.newSwitch()
            .setFieldName(setting.key)
            .setValue('true')
            .setSelected(current === true || String(current) === 'true'));
  }

  if (setting.type === 'choice') {
    var dropdown = CardService.newSelectionInput()
        .setType(CardService.SelectionInputType.DROPDOWN)
        .setTitle(setting.label)
        .setFieldName(setting.key);

    setting.choices.forEach(function (choice) {
      dropdown.addItem(choice, choice, String(current) === choice);
    });
    return dropdown;
  }

  return CardService.newTextInput()
      .setFieldName(setting.key)
      .setTitle(setting.label)
      .setHint(hint)
      .setValue(current === null || current === undefined ? '' : String(current));
}

/** Writes a group's values back to the sheet. */
function editorSaveGroup(e) {
  var groupId = e.parameters.group;
  var inputs = (e.commonEventObject && e.commonEventObject.formInputs) || {};
  var updates = {};

  Settings_inGroup_(groupId).forEach(function (setting) {
    updates[setting.key] = Editor_readInput_(inputs, setting);
  });

  var saved = Editor_writeSettings_(updates);

  return CardService.newActionResponseBuilder()
      .setNotification(CardService.newNotification()
          .setText(saved.written + ' setting' + (saved.written === 1 ? '' : 's') +
                   ' saved. Displays update within five minutes.'))
      .setNavigation(CardService.newNavigation().popToRoot()
          .updateCard(Editor_homeCard_()))
      .build();
}

/** Pulls one field out of the card's form payload, typed. */
function Editor_readInput_(inputs, setting) {
  var field = inputs[setting.key];

  if (setting.type === 'boolean') {
    // An unticked switch is simply absent from the payload.
    return !!(field && field.stringInputs &&
              field.stringInputs.value && field.stringInputs.value.length);
  }

  var raw = (field && field.stringInputs && field.stringInputs.value &&
             field.stringInputs.value[0]) || '';

  if (setting.type === 'number') {
    var num = parseFloat(String(raw).replace(/[^0-9.\-]/g, ''));
    return isNaN(num) ? setting.value : num;
  }

  if (setting.type === 'folder' || setting.type === 'sheet') {
    // Accept a pasted Drive link as readily as a bare id.
    var match = String(raw).match(/[-\w]{25,}/);
    return match ? match[0] : String(raw).trim();
  }

  return String(raw).trim();
}

function editorGoHome(e) {
  return CardService.newActionResponseBuilder()
      .setNavigation(CardService.newNavigation().popToRoot()
          .updateCard(Editor_homeCard_()))
      .build();
}

/* ===========================================================================
 * Writing settings
 * =========================================================================*/

/**
 * Writes values into Control Values, adding any row that is missing.
 *
 * One read and one write for the whole batch, rather than a setValue() per
 * setting.
 *
 * @param {!Object<string,*>} updates
 * @return {{written:number}}
 */
function Editor_writeSettings_(updates) {
  var lock = LockService.getScriptLock();
  try {
    lock.waitLock(15000);

    var ss = SpreadsheetApp.openById(Config_spreadsheetId_());
    var sheet = ss.getSheetByName(SHEET_CONTROL_);
    if (!sheet) throw new Error('The Control Values tab is missing. ' +
                                'Run setupConfigurationSpreadsheet().');

    var lastRow = Math.max(1, sheet.getLastRow());
    var values = sheet.getRange(1, 1, lastRow, 2).getValues();

    var rowByKey = {};
    for (var i = 0; i < values.length; i++) {
      var key = String(values[i][0] || '').trim();
      if (key) rowByKey[key] = i;
    }

    var written = 0;
    var appended = [];

    Object.keys(updates).forEach(function (key) {
      if (key in rowByKey) {
        values[rowByKey[key]][1] = updates[key];
      } else {
        appended.push([key, updates[key]]);
      }
      written++;
    });

    sheet.getRange(1, 1, values.length, 2).setValues(values);
    if (appended.length) {
      Telemetry_ensureRows_(sheet, appended.length);
      sheet.getRange(sheet.getLastRow() + 1, 1, appended.length, 2)
           .setValues(appended);
    }

    Config_invalidate();
    return {written: written};
  } finally {
    try { lock.releaseLock(); } catch (err) { /* never held */ }
  }
}

/* ===========================================================================
 * Job buttons
 * =========================================================================*/

function editorCheckSetup(e) {
  var report = checkSetup();
  var text = report.ok
      ? 'Everything checks out.\n\n' + report.notes.join('\n')
      : 'Needs attention:\n\n' + report.problems.join('\n\n') +
        '\n\nWorking:\n' + report.notes.join('\n');
  return Editor_resultCard_('Setup check', text);
}

function editorRunIntake(e) {
  var report = processUploads();
  return Editor_resultCard_('Collect new photos',
      report.filed + ' filed into the archive.\n' +
      report.duplicates + ' already there or still uploading.\n' +
      report.rejected + ' could not be used.\n' +
      report.failed + ' failed.');
}

function editorRefreshPhotos(e) {
  refreshImageManifest();
  var count = warmImageManifest();
  return Editor_resultCard_('Photo index rebuilt',
      count + ' photos are now in the rotation.');
}

function editorReloadDisplays(e) {
  queueCommand('reload');
  return Editor_resultCard_('Reload sent',
      'Every display reloads the next time it checks in - within a few ' +
      'minutes, depending on "Check for commands every".');
}

/** A plain card showing the outcome of a job. */
function Editor_resultCard_(title, body) {
  var card = CardService.newCardBuilder()
      .setHeader(CardService.newCardHeader().setTitle(title))
      .addSection(CardService.newCardSection()
          .addWidget(CardService.newTextParagraph()
              .setText(Editor_escape_(body).replace(/\n/g, '<br>')))
          .addWidget(CardService.newTextButton()
              .setText('Back')
              .setOnClickAction(CardService.newAction()
                  .setFunctionName('editorGoHome'))));

  return CardService.newActionResponseBuilder()
      .setNavigation(CardService.newNavigation().pushCard(card.build()))
      .build();
}

/** Card text is limited HTML, so escape anything that came from Drive. */
function Editor_escape_(text) {
  return String(text)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
}

function Editor_notify_(message) {
  return CardService.newActionResponseBuilder()
      .setNotification(CardService.newNotification().setText(message))
      .build();
}

/* ===========================================================================
 * Spreadsheet menu
 *
 * Works immediately, with no add-on deployment. The card editor above is the
 * nicer surface, but this is the one that is always there.
 * =========================================================================*/

function onOpen(e) {
  try {
    SpreadsheetApp.getUi()
        .createMenu('Kiosk')
        .addItem('Edit settings…', 'showSettingsSidebar')
        .addSeparator()
        .addItem('Check setup', 'menuCheckSetup')
        .addItem('Collect new photos', 'menuRunIntake')
        .addItem('Rebuild photo index', 'menuRefreshPhotos')
        .addItem('Reload every display', 'menuReloadDisplays')
        .addSeparator()
        .addItem('Rebuild this spreadsheet', 'menuRebuildSheet')
        .addToUi();
  } catch (err) {
    // No UI when the trigger runs headless; nothing to do.
  }
}

/**
 * The same settings editor as a sidebar, for when the add-on is not installed.
 * It renders the identical schema, so both surfaces stay in step.
 */
function showSettingsSidebar() {
  var html = HtmlService.createTemplateFromFile('Editor_Sidebar')
      .evaluate()
      .setTitle('Kiosk settings')
      .setWidth(400);
  SpreadsheetApp.getUi().showSidebar(html);
}

/** Feeds the sidebar. @return {{groups:!Array, settings:!Array, values:!Object}} */
function editorLoadSettings() {
  return {
    groups: SETTING_GROUPS_,
    settings: Settings_all_(),
    values: Config_get()
  };
}

/** Saves from the sidebar. */
function editorSaveSettings(updates) {
  var byKey = Settings_byKey_();
  var clean = {};

  Object.keys(updates || {}).forEach(function (key) {
    var setting = byKey[key];
    if (!setting) return;                       // ignore anything unrecognised

    var value = updates[key];
    if (setting.type === 'boolean') {
      clean[key] = value === true || value === 'true';
    } else if (setting.type === 'number') {
      var num = parseFloat(String(value).replace(/[^0-9.\-]/g, ''));
      clean[key] = isNaN(num) ? setting.value : num;
    } else if (setting.type === 'folder' || setting.type === 'sheet') {
      var match = String(value).match(/[-\w]{25,}/);
      clean[key] = match ? match[0] : String(value).trim();
    } else {
      clean[key] = String(value).trim();
    }
  });

  return Editor_writeSettings_(clean);
}

function menuCheckSetup() {
  var report = checkSetup();
  Editor_alert_('Setup check', report.ok
      ? 'Everything checks out.\n\n' + report.notes.join('\n')
      : 'Needs attention:\n\n' + report.problems.join('\n\n'));
}

function menuRunIntake() {
  var report = processUploads();
  Editor_alert_('Collect new photos',
      report.filed + ' filed, ' + report.duplicates + ' skipped, ' +
      report.rejected + ' unusable, ' + report.failed + ' failed.');
}

function menuRefreshPhotos() {
  refreshImageManifest();
  Editor_alert_('Photo index rebuilt',
                warmImageManifest() + ' photos are now in the rotation.');
}

function menuReloadDisplays() {
  queueCommand('reload');
  Editor_alert_('Reload sent',
      'Every display reloads the next time it checks in.');
}

function menuRebuildSheet() {
  var ui = SpreadsheetApp.getUi();
  var answer = ui.alert('Rebuild this spreadsheet',
      'This rewrites the Control Values and Help tabs, keeping your current ' +
      'values. Your button, keyboard and language tabs are not touched.\n\n' +
      'Continue?', ui.ButtonSet.YES_NO);

  if (answer !== ui.Button.YES) return;

  var report = setupConfigurationSpreadsheet(true);
  Editor_alert_('Spreadsheet rebuilt',
      report.migrated + ' old settings carried across.\n' +
      report.retired.length + ' retired settings listed on the ' +
      '"Retired settings" tab.');
}

function Editor_alert_(title, body) {
  try {
    SpreadsheetApp.getUi().alert(title, body, SpreadsheetApp.getUi().ButtonSet.OK);
  } catch (err) {
    Log_info_(title + ': ' + body);
  }
}
