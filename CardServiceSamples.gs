/**
 * CardService samples for Google Apps Script.
 *
 * NOTE: CardService.newCarousel() is ONLY supported in Google Chat apps.
 * In a Gmail / Workspace add-on it will throw an error. Everything else in
 * this file works in both Chat apps and Workspace add-ons.
 */

// ---------------------------------------------------------------------------
// 1. FIXED version of your expDash function
// ---------------------------------------------------------------------------
// What was wrong:
//   - A Carousel is a WIDGET. It must be added to a CardSection with
//     addWidget(), and the section added to the card builder.
//   - Navigation.pushCard() needs a built Card (builder.build()),
//     not a carousel object.
function expDash(par) {
  const builder = CardService.newCardBuilder();

  builder.setHeader(
    CardService.newCardHeader()
      .setTitle("Caydens Experimental New Dashboard")
      .setSubtitle("The Subtitle")
  );

  const carousel = CardService.newCarousel()
    .addCarouselCard(
      CardService.newCarouselCard().addWidget(
        CardService.newTextParagraph().setText("The first text paragraph in carousel")
      )
    )
    .addCarouselCard(
      CardService.newCarouselCard().addWidget(
        CardService.newTextParagraph().setText("The second text paragraph in carousel")
      )
    )
    .addCarouselCard(
      CardService.newCarouselCard().addWidget(
        CardService.newTextParagraph().setText("The third text paragraph in carousel")
      )
    );

  // The carousel goes inside a section, like any other widget.
  builder.addSection(CardService.newCardSection().addWidget(carousel));

  return CardService.newActionResponseBuilder()
    .setNavigation(CardService.newNavigation().pushCard(builder.build()))
    .build();
}

// ---------------------------------------------------------------------------
// 2. Widget gallery — one card that shows what every common widget looks like
// ---------------------------------------------------------------------------
// Use this as your add-on's homepage function (or push it from a button)
// to see each widget rendered.
function widgetGallery() {
  const builder = CardService.newCardBuilder();

  builder.setHeader(
    CardService.newCardHeader()
      .setTitle("Widget Gallery")
      .setSubtitle("One of everything")
      .setImageUrl("https://www.gstatic.com/images/branding/product/1x/apps_script_48dp.png")
      .setImageStyle(CardService.ImageStyle.CIRCLE)
  );

  // --- TextParagraph ---
  builder.addSection(
    CardService.newCardSection()
      .setHeader("TextParagraph")
      .addWidget(
        CardService.newTextParagraph().setText(
          "Plain text. Supports <b>bold</b>, <i>italic</i>, <u>underline</u>, " +
          '<font color="#ff0000">color</font> and <a href="https://google.com">links</a>.'
        )
      )
  );

  // --- DecoratedText (icon + label + text + switch) ---
  builder.addSection(
    CardService.newCardSection()
      .setHeader("DecoratedText")
      .addWidget(
        CardService.newDecoratedText()
          .setTopLabel("Top label")
          .setText("The main text")
          .setBottomLabel("Bottom label")
          .setStartIcon(CardService.newIconImage().setIcon(CardService.Icon.EMAIL))
          .setWrapText(true)
      )
      .addWidget(
        CardService.newDecoratedText()
          .setText("With a toggle switch")
          .setSwitchControl(
            CardService.newSwitch()
              .setFieldName("my_switch")
              .setValue("on")
              .setSelected(true)
          )
      )
  );

  // --- Image ---
  builder.addSection(
    CardService.newCardSection()
      .setHeader("Image")
      .addWidget(
        CardService.newImage()
          .setImageUrl("https://cataas.com/cat?width=600&height=300")
          .setAltText("A cat")
      )
  );

  // --- Buttons ---
  builder.addSection(
    CardService.newCardSection()
      .setHeader("Buttons")
      .addWidget(
        CardService.newButtonSet()
          .addButton(
            CardService.newTextButton()
              .setText("Filled button")
              .setTextButtonStyle(CardService.TextButtonStyle.FILLED)
              .setOnClickAction(CardService.newAction().setFunctionName("onButtonClick"))
          )
          .addButton(
            CardService.newTextButton()
              .setText("Open a link")
              .setOpenLink(CardService.newOpenLink().setUrl("https://developers.google.com/apps-script/reference/card-service"))
          )
          .addButton(
            CardService.newImageButton()
              .setIcon(CardService.Icon.STAR)
              .setAltText("Star")
              .setOnClickAction(CardService.newAction().setFunctionName("onButtonClick"))
          )
      )
  );

  // --- TextInput ---
  builder.addSection(
    CardService.newCardSection()
      .setHeader("TextInput")
      .addWidget(
        CardService.newTextInput()
          .setFieldName("name_input")
          .setTitle("Your name")
          .setHint("Type something here")
      )
      .addWidget(
        CardService.newTextInput()
          .setFieldName("notes_input")
          .setTitle("Notes (multiline)")
          .setMultiline(true)
      )
  );

  // --- SelectionInput: dropdown, checkboxes, radio buttons ---
  builder.addSection(
    CardService.newCardSection()
      .setHeader("SelectionInput")
      .addWidget(
        CardService.newSelectionInput()
          .setFieldName("dropdown")
          .setTitle("Dropdown")
          .setType(CardService.SelectionInputType.DROPDOWN)
          .addItem("Option A", "a", true)
          .addItem("Option B", "b", false)
          .addItem("Option C", "c", false)
      )
      .addWidget(
        CardService.newSelectionInput()
          .setFieldName("checkboxes")
          .setTitle("Checkboxes")
          .setType(CardService.SelectionInputType.CHECK_BOX)
          .addItem("Check me", "1", true)
          .addItem("Me too", "2", false)
      )
      .addWidget(
        CardService.newSelectionInput()
          .setFieldName("radios")
          .setTitle("Radio buttons")
          .setType(CardService.SelectionInputType.RADIO_BUTTON)
          .addItem("This one", "x", true)
          .addItem("Or this one", "y", false)
      )
  );

  // --- DateTimePicker ---
  builder.addSection(
    CardService.newCardSection()
      .setHeader("Date & time pickers")
      .addWidget(
        CardService.newDatePicker()
          .setFieldName("date")
          .setTitle("Pick a date")
          .setValueInMsSinceEpoch(Date.now())
      )
      .addWidget(
        CardService.newDateTimePicker()
          .setFieldName("datetime")
          .setTitle("Pick date + time")
          .setValueInMsSinceEpoch(Date.now())
      )
  );

  // --- Grid ---
  builder.addSection(
    CardService.newCardSection()
      .setHeader("Grid")
      .addWidget(
        CardService.newGrid()
          .setTitle("A 2-column grid")
          .setNumColumns(2)
          .addItem(
            CardService.newGridItem()
              .setIdentifier("item1")
              .setTitle("Grid item 1")
              .setSubtitle("Subtitle")
              .setImage(CardService.newImageComponent().setImageUrl("https://cataas.com/cat?width=300&height=200&r=1"))
          )
          .addItem(
            CardService.newGridItem()
              .setIdentifier("item2")
              .setTitle("Grid item 2")
              .setSubtitle("Subtitle")
              .setImage(CardService.newImageComponent().setImageUrl("https://cataas.com/cat?width=300&height=200&r=2"))
          )
          .setOnClickAction(CardService.newAction().setFunctionName("onButtonClick"))
      )
  );

  // --- Divider + collapsible section ---
  builder.addSection(
    CardService.newCardSection()
      .setHeader("Collapsible section (click to expand)")
      .setCollapsible(true)
      .setNumUncollapsibleWidgets(1)
      .addWidget(CardService.newTextParagraph().setText("Always visible."))
      .addWidget(CardService.newDivider())
      .addWidget(CardService.newTextParagraph().setText("Hidden until you expand."))
  );

  // --- Fixed footer ---
  builder.setFixedFooter(
    CardService.newFixedFooter()
      .setPrimaryButton(
        CardService.newTextButton()
          .setText("Primary footer button")
          .setOnClickAction(CardService.newAction().setFunctionName("onButtonClick"))
      )
      .setSecondaryButton(
        CardService.newTextButton()
          .setText("Secondary")
          .setOnClickAction(CardService.newAction().setFunctionName("onButtonClick"))
      )
  );

  return builder.build();
}

// Simple handler so the buttons above do something visible.
function onButtonClick(e) {
  return CardService.newActionResponseBuilder()
    .setNotification(CardService.newNotification().setText("You clicked something!"))
    .build();
}
