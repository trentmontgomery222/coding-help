/**
 * Card.gs — Drive add-on UI (CardService).
 *
 * The user selects an image in Drive, the card shows the filename and a
 * form (alt text required, location, target subsite), and Submit appends
 * a "pending" row to the queue sheet.
 */

var MIN_ALT_TEXT_LENGTH = 3;

/** Homepage card — shown when no file is selected. */
function onDriveHomepage(e) {
  var card = CardService.newCardBuilder()
    .setHeader(CardService.newCardHeader()
      .setTitle('Send to Media Library')
      .setSubtitle('WordPress image importer'))
    .addSection(CardService.newCardSection()
      .addWidget(CardService.newTextParagraph()
        .setText('Select an image file in Drive to send it to a WordPress media library.')))
    .build();
  return [card];
}

/** Card shown when the user selects one or more items in Drive. */
function onDriveItemsSelected(e) {
  var items = (e && e.drive && e.drive.selectedItems) || [];
  if (!items.length) return onDriveHomepage(e);

  var item = items[0];
  if (item.mimeType && item.mimeType.indexOf('image/') !== 0) {
    return [buildMessageCard_(
      'Not an image',
      '"' + item.title + '" is not an image file. Select a JPEG, PNG, GIF, or WebP image.'
    )];
  }

  return [buildQueueFormCard_(item.id, item.title, null)];
}

/**
 * Builds the metadata form card.
 * @param {string} fileId
 * @param {string} filename
 * @param {?string} errorText inline validation error, or null
 */
function buildQueueFormCard_(fileId, filename, errorText) {
  var section = CardService.newCardSection();

  section.addWidget(CardService.newDecoratedText()
    .setTopLabel('Selected file')
    .setText(filename)
    .setWrapText(true));

  if (errorText) {
    section.addWidget(CardService.newTextParagraph()
      .setText('<font color="#c5221f"><b>' + errorText + '</b></font>'));
  }

  section.addWidget(CardService.newTextInput()
    .setFieldName('alt_text')
    .setTitle('Alt text (required)')
    .setHint('Describe the image for screen-reader users, e.g. "Students planting trees at the spring service day."'));

  section.addWidget(CardService.newTextInput()
    .setFieldName('location')
    .setTitle('Location')
    .setHint('Where the photo was taken (optional)'));

  var sites = getTargetSites_();
  var siteIds = Object.keys(sites);
  if (siteIds.length > 1) {
    var dropdown = CardService.newSelectionInput()
      .setType(CardService.SelectionInputType.DROPDOWN)
      .setFieldName('target_site')
      .setTitle('Target site');
    siteIds.forEach(function (id, i) {
      dropdown.addItem(sites[id], id, i === 0);
    });
    section.addWidget(dropdown);
  }

  var action = CardService.newAction()
    .setFunctionName('onSubmitQueueForm')
    .setParameters({ file_id: fileId, filename: filename });

  section.addWidget(CardService.newTextButton()
    .setText('Send to media library')
    .setTextButtonStyle(CardService.TextButtonStyle.FILLED)
    .setOnClickAction(action));

  return CardService.newCardBuilder()
    .setHeader(CardService.newCardHeader()
      .setTitle('Send to Media Library')
      .setSubtitle('Queue this image for import'))
    .addSection(section)
    .build();
}

/** Submit handler: validate, append a pending row, confirm. */
function onSubmitQueueForm(e) {
  var params = e.parameters || {};
  var fileId = params.file_id;
  var filename = params.filename || '';
  var form = (e.commonEventObject && e.commonEventObject.formInputs) || {};

  var altText = readFormValue_(form, 'alt_text');
  var location = readFormValue_(form, 'location');
  var targetSite = readFormValue_(form, 'target_site');

  if (!targetSite) {
    var sites = getTargetSites_();
    targetSite = Object.keys(sites)[0];
  }

  if (!altText || altText.trim().length < MIN_ALT_TEXT_LENGTH) {
    // WCAG 1.1.1: alt text is required at the point of capture.
    return rerenderWithError_(fileId, filename,
      'Alt text is required and must describe the image (at least ' +
      MIN_ALT_TEXT_LENGTH + ' characters).');
  }

  var rowId = Utilities.getUuid();
  var uploader = Session.getActiveUser().getEmail();

  var lock = LockService.getScriptLock();
  lock.waitLock(20000);
  try {
    var sheet = getQueueSheet_();
    sheet.appendRow([
      rowId,
      new Date().toISOString(),
      fileId,
      filename,
      altText.trim(),
      (location || '').trim(),
      uploader,
      targetSite,
      STATUS_PENDING,
      '', '', ''
    ]);
  } finally {
    lock.releaseLock();
  }

  var confirmCard = buildMessageCard_(
    'Queued',
    '"' + filename + '" is queued — it will appear in the media library shortly. ' +
    'Check the queue sheet for status.'
  );

  return CardService.newActionResponseBuilder()
    .setNavigation(CardService.newNavigation().updateCard(confirmCard))
    .setNotification(CardService.newNotification()
      .setText('Queued for import to WordPress'))
    .build();
}

function rerenderWithError_(fileId, filename, message) {
  return CardService.newActionResponseBuilder()
    .setNavigation(CardService.newNavigation()
      .updateCard(buildQueueFormCard_(fileId, filename, message)))
    .setNotification(CardService.newNotification().setText(message))
    .build();
}

function buildMessageCard_(title, text) {
  return CardService.newCardBuilder()
    .setHeader(CardService.newCardHeader().setTitle(title))
    .addSection(CardService.newCardSection()
      .addWidget(CardService.newTextParagraph().setText(text)))
    .build();
}

function readFormValue_(formInputs, fieldName) {
  var input = formInputs[fieldName];
  if (!input || !input.stringInputs || !input.stringInputs.value) return '';
  return String(input.stringInputs.value[0] || '');
}
