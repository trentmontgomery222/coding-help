# Allegany Archive Kiosk — v3

A Google Apps Script web app that plays a Google Drive photo archive as a
touch-screen slideshow, with per-photo metadata, an editable details panel and
a download QR code.

This is a full rewrite of the previous single-file version. It does the same
job with roughly a quarter of the code, and it is built to run unattended for
a full day without being restarted.

---

## What's in here

| File | What it does |
|---|---|
| `Code.gs` | Web app entry points (`doGet`/`doPost`), `bootstrap()`, batched RPC dispatch, scheduled jobs |
| `Config.gs` | Reads the control spreadsheet: settings, language strings, key bindings, menus |
| `Images.gs` | Builds the photo manifest the slideshow plays from |
| `Metadata.gs` | The per-photo metadata schema, plus reading, repairing and scoring it |
| `Exif.gs` | EXIF/TIFF reader for JPEG and TIFF |
| `DriveAdapter.gs` | Every Drive read/write, in one place |
| `Telemetry.gs` | Device registry, logs, stats, remote commands |
| `Intake.gs` | Upload pipeline: files new photos into the archive |
| `Integrity.gs` | Watches critical Drive items; protects the settings sheets |
| `SessionLog.gs` | Optional per-session log spreadsheets |
| `Maintenance.gs` | Run-by-hand curation jobs (`checkSetup`, `repairFolder`, `auditLibrary`, …) |
| `Settings.gs` | Every setting: name, type, default and explanation — one list |
| `Setup.gs` | Builds the configuration spreadsheet, Help tab and validation |
| `Editor.gs` | CardService settings editor, plus the Kiosk menu |
| `Editor_Sidebar.html` | The same editor as a spreadsheet sidebar |
| `Utils.gs` | Caching, logging, object merging |
| `Index.html` | Page structure |
| `Styles.html` | All CSS |
| `Client_Core.html` | Client state, server calls, storage, action registry |
| `Client_Slideshow.html` | The slideshow engine |
| `Client_UI.html` | Panels, toolbar, browser, input, startup |
| `Client_Legacy.html` | Compatibility shims for the spreadsheet's raw-JS actions |
| `appsscript.json` | Manifest — scopes and the Drive advanced service |

---

## Setup

1. **Create the project.** Add every file above to an Apps Script project.
   `.gs` files go in as script files; `.html` files as HTML files, keeping the
   exact names (they reference each other by name).

2. **Enable the Drive advanced service.** Editor sidebar → **Services** → add
   **Drive API v3**, identifier `Drive`. This is what makes syncing fast; the
   code falls back to the slow path without it, and `checkSetup()` will tell
   you if it is missing.

3. **Point it at your content.** Either edit the defaults in
   `Config_defaults_()` or — better — set these in the **Control Values** tab
   of your settings spreadsheet:
   - `ImagesFolderId` — the Drive folder holding the photos
   - `SETTINGS_SHEET_ID` — set as a *Script Property* if your settings
     spreadsheet is not the default one

4. **Deploy.** Deploy → New deployment → Web app. Execute as **you**, access
   **Anyone**. (Anonymous access is what lets a kiosk display run it without a
   login.)

5. **Run `checkSetup()`** from the editor. It verifies the Drive service, the
   spreadsheet, the photo folder, sharing, and the deployment, and prints
   whatever is still missing.

6. **Run `publishFolder()`** once. Photos must be readable by "anyone with the
   link" for the browser to load them from Google's image CDN. Re-running is
   cheap — it skips files that are already shared.

7. **Run `installTriggers()`** once, to schedule the heartbeat and nightly
   maintenance.

8. **Run `setupConfigurationSpreadsheet()`** once. It rebuilds the settings
   spreadsheet: a Help tab, a grouped Control Values tab with an explanation
   against every setting, drop-downs and tick boxes so nothing can be mistyped,
   and a "Retired settings" tab listing anything that no longer does anything
   and why. Your current values are read first and written back, and your
   button, keyboard and language tabs are left alone.

---

## Editing the settings

Three ways, in order of preference.

**The Kiosk menu.** Reopen the spreadsheet and there is a `Kiosk` menu:
*Edit settings…* opens a sidebar with a proper control for each setting and a
description of what it does. It also carries *Check setup*, *Collect new
photos*, *Rebuild photo index* and *Reload every display*. This works as soon
as the script is saved — nothing to deploy.

**The card editor.** The same editor as a Workspace add-on card. Install it
with **Deploy → Test deployments → Install**, then open the spreadsheet and
pick it from the side panel. It needs that install step; the menu does not.

**By hand.** Edit column B on the Control Values tab. The drop-downs and tick
boxes are there to stop typos, so prefer the editor.

Both editors are generated from `Settings.gs`, so they can never offer
different settings from each other or from the sheet.

---

## Settings (Control Values tab)

Column A is the key, column B the value, column C explains it. Anything you
leave out uses the default.

Settings that were renamed are translated on read, including their old value
formats — a sheet still saying `MAX_IMAGE_SIZE_IN_MB: 5MB` or
`DAILY_IMAGE_REFRESH_TIME: 7PM` keeps working, and
`setupConfigurationSpreadsheet()` migrates them properly.

### Slideshow
| Key | Default | Meaning |
|---|---|---|
| `ImageDisplayTimeByDefault` | `12` | Seconds per photo |
| `ClassImageTimeMultiplier` | `2` | Class photos stay up this much longer |
| `TransitionMs` | `1200` | Transition length |
| `ImageOrder` | `name` | `name` \| `year` \| `shuffle` \| `weighted` |
| `MinImagesPerYear` | `7` | Floor per year in `weighted` mode |
| `FeaturedYear` | `0` | Spotlight one graduating year (0 = off) |
| `ImageSource` | `cdn` | `cdn` (fast) or `inline` (works without CDN access) |
| `ImageWidth` | `0` | 0 picks a size from the screen |
| `BackdropBlurPx` | `28` | Blur on the background fill |
| `BackdropBrightness` | `0.55` | Dimming on the background fill |

### Content
| Key | Default | Meaning |
|---|---|---|
| `ImagesFolderId` | — | Main photo folder |
| `AlternateFolderId` | — | Secondary folder |
| `UseAlternateSet` | `FALSE` | Play the alternate folder instead |
| `IncludeSubFolders` | `TRUE` | Walk sub-folders |
| `MaxImageSizeMB` | `25` | Skip anything larger |
| `AllowTiffs` | `FALSE` | TIFFs render only in Safari and are enormous |

### Interaction
| Key | Default | Meaning |
|---|---|---|
| `MinYMovementSwipe` | `80` | Pixels of upward swipe to open the panel |
| `MaxXMovementSwipe` | `120` | Horizontal slop allowed in that swipe |
| `InfoPanelIdleCloseMs` | `60000` | Auto-close after inactivity |
| `InfoPanelHardCloseMs` | `120000` | Auto-close regardless |
| `AllowViewerEdits` | `TRUE` | Let visitors edit titles and descriptions |
| `MaxImageDisplayNameLength` | `64` | Title truncation |

### Behaviour
| Key | Default | Meaning |
|---|---|---|
| `DailyRefreshHour` | `23` | Hour the page reloads itself (−1 disables) |
| `CommandPollSeconds` | `30` | Remote-command poll interval |
| `BackendFlushSeconds` | `15` | How often queued calls are sent |
| `EnableLogs` | `TRUE` | Forward client logs to the sheet |
| `ShowDebugHud` | `FALSE` | Show the developer overlay at boot |
| `AllowLegacyEvalCommands` | `TRUE` | Run raw JS from the command sheets |

---

## Adding photos

Drop a photo into the **Upload Your New Photos Here** folder. Within a few
minutes `tick()` picks it up and:

1. checks it is a JPEG or PNG (anything else is left alone and the uploader
   gets one email about it),
2. waits `IntakeSettleSeconds` so a half-finished upload is never moved,
3. reads the year off the front of the file name — `2027 Homecoming.jpg` goes
   to the `2027` folder, creating it if it does not exist,
4. skips it if a photo of that name is already there,
5. writes the metadata schema onto it,
6. shares it so the browser can load it,
7. logs a row to the upload log.

Anything **not** named with a leading year goes to the review folder rather
than being guessed at. Nothing is moved until its metadata has been written,
so a failure leaves the photo in the upload folder to be retried instead of
burying it in the archive with no description.

### Intake settings
| Key | Default | Meaning |
|---|---|---|
| `UploadFolderId` | — | Where people drop new photos |
| `IntakeFallbackFolderId` | — | Review folder for un-year-named photos |
| `UploadLogSheetId` | — | Spreadsheet that receives the intake log |
| `AllowedUploadTypes` | `image/jpeg,image/png` | Accepted types |
| `IntakeSettleSeconds` | `60` | Wait this long before touching a new file |
| `CreateMissingYearFolders` | `TRUE` | Make a year folder when one is absent |
| `ReadExifOnUpload` | `FALSE` | Slow; the nightly backfill handles it |
| `EmailOnRejectedUpload` | `TRUE` | Tell the uploader about a bad file type |

---

## The maintenance heartbeat

One trigger fires `tick()` every minute, and it decides what is due. Set any
interval to `0` to switch that job off.

| Key | Default | Job |
|---|---|---|
| `IntakeEveryMinutes` | `5` | Process the upload folder |
| `IntegrityEveryMinutes` | `60` | Verify critical Drive items |
| `ProtectSheetsEveryMinutes` | `720` | Re-apply settings-sheet protection |
| `ManifestWarmEveryMinutes` | `360` | Rebuild the photo manifest cache |
| `EnhanceEveryMinutes` | `0` | Enhancement queue (off by default) |

`resetTickSchedule()` clears the timers so everything runs on the next tick.

### Integrity watchdog
`verifyResources()` checks each critical folder and file: if one is in the
trash it is restored, if a workflow folder was renamed the name is put back,
and if something is genuinely unreadable the admins get **one** email per hour
(not one per minute). Set `AdminEmails` — the original `ALL_ADMIN_EMAILS` key
is also read.

The settings are snapshotted to Script Properties after every successful read,
so if the spreadsheet is deleted the kiosk boots from the last known-good
configuration instead of reverting to bare defaults.

---

## Logging

`LogDestination` chooses where client logs land:

- **`sheet`** (default) — one `Logs` tab. Easier to search, cheaper to write.
- **`session`** — a fresh spreadsheet per kiosk boot, in `SessionLogFolderId`.
  Star a log in Drive to keep it; unstarred logs are pruned oldest-first once
  there are more than `SessionLogKeep` (default 25).

---

## Actions

Buttons, key bindings and remote commands all name an **action** rather than
carrying a snippet of JavaScript:

```
next            previous        forward5        back5
goto(12)        restart         resync          reload
pause(60)       resume          togglePause     paused
openInfo        closeInfo       toggleInfo      infoOpen
toggleHud       hudVisible      notify(text)
```

Use them in the **Bottom Menu Controls** tab (column C = click action,
column E = "is this button active?") and in **Keyboard Commandor**
(column A = `q` or `ctrl+alt+p`, column B = the action).

Your existing sheets keep working: anything that is not a known action is run
as raw JavaScript while `AllowLegacyEvalCommands` is on. Turn it off once your
sheets use action names — `eval` cannot be JIT-compiled, and the old code
called it on every button on every slide.

### Your existing sheets keep working

The action columns in **Bottom Menu Controls**, **Settings Menu Controller**
and **Keyboard Commandor** hold raw JavaScript against the old globals —
`controllerButtonFoward5()`, `PauseSlideshowFor(30)`, `pressButtonFor("z")`,
`SLIDESTATES.currentImgData.id`, `toggleUsage()`, and so on. Those names are
restored by `Client_Legacy.html` as thin wrappers over the current engine, so
every button in your sheet works unchanged. Nothing there holds state — the
values read through to the live slideshow.

Migrate a row to an action name whenever you like; both styles work side by
side. Set `AllowLegacyEvalCommands` to `FALSE` once no rows need it.

### The bottom bar

The bar is laid out from the sheet exactly as before:

- a row whose icon column says `nameplate` contributes to the **title slot** —
  the number of those rows sets its width, which is what slides the playback
  buttons left and right,
- a row with an icon URL becomes an **icon button**,
- a row with neither becomes a **spacer**.

Icons are scaled so the row fits the screen. A row with no icon is never
labelled with its own name: many rows are named with bare numbers, and printing
those put `46 16 17 18 19` across the middle of the bar.

### Browsing the archive

The list and search buttons (`makeObjectList()` / `search()`) open a searchable
index of the library — filter by name or year, tap a photo to jump straight to
it. Press `l` for the same thing.

### The bottom bar, in detail

Each row in **Bottom Menu Controls** is one slot along the bar, and every slot
is the same width. That is what positions everything:

- a row with an **image** is a button,
- a row whose image column says **`nameplate`** is part of the photo title —
  the number of those rows is how wide the title is, which is what pushes the
  playback buttons into the middle (yours has 15),
- a row left **blank** is a gap,
- a row with an action but **no image** is an invisible tap target, as before.

So to move the playback buttons right, add `nameplate` rows; to move them left,
remove some; to space buttons apart, insert blank rows.

### Built-in keys
`←` `→` step · `space` next · `↑` `↓` details panel · `p` pause · `r` resync ·
`d` developer overlay · `l` browse · `Esc` close

---

## Maintenance

Run from the Apps Script editor:

| Function | Use |
|---|---|
| `checkSetup()` | Verify the whole install; prints what is wrong |
| `auditLibrary()` | Photo counts, missing metadata, year coverage, oversized files |
| `publishFolder()` | Share photos so the browser can load them |
| `repairFolder()` | Bring every description up to the current schema |
| `backfillExif()` | Read EXIF for photos that have none |
| `refreshImageManifest()` | Pick up newly added photos immediately |
| `queueCommand('reload')` | Push a command to the kiosks |

`repairFolder` and `backfillExif` stop before the 6-minute execution limit and
remember where they were — just run them again until they report `done: true`.

---

## Why this version is faster

**Photos no longer travel as base64.** The old sync downloaded each image into
Apps Script, base64-encoded it (+33%), sent it to the browser, stored it in
IndexedDB and rendered it as a `data:` URL. A 4MB photo cost ~5.4MB four times
over. The browser now loads pixels directly from Google's image CDN at the size
the screen actually needs — a 1920px display fetches ~300KB instead of 8MB.

**One Drive call per 1000 photos, not four per photo.** The old loop called
`getFileData()` three times per file plus a permission write, every sync. The
Drive v3 list endpoint returns names *and* descriptions together, and sharing is
only touched for files that are not already shared.

**One startup round trip, not five.** `bootstrap()` returns config, language,
key bindings and menus together, inlined into the page — so the browser has
them before the first line of script runs. The old `init()` also slept
`1 + 15 + 9 + 60` seconds on hardcoded timers before showing a photo.

**Transitions are GPU-only.** The old transition animated `left` and `right`,
forcing a full layout recalculation on every frame for eight seconds. Only
`transform` and `opacity` are animated now, and each photo is fully decoded
before it goes on screen.

**Nothing burns CPU in the background.** The old debug overlay ran a
200,000-iteration busy loop every second and rebuilt itself inside
`requestAnimationFrame` (60×/sec), and a `MutationObserver` re-scanned every
element on the page every 1.5 seconds. All of that is now off unless you press
`d`.

**Sheet reads are bulk.** Config was read one cell at a time — one API round
trip per cell. It is now a single `getValues()`, cached for five minutes.

Measured over 300 transitions in Chromium: heap flat at 9.5MB, DOM flat at
68 nodes, image elements flat at 4.

---

## Bugs fixed from the previous version

- **Queued server calls never returned.** `QueueManager.callBackend()` created a
  Promise but never stored its `resolve`/`reject`, so every `await` on it hung
  forever. Anything downstream of one silently never ran.
- **`getImagesByYear()` always returned undefined** — it read `req.result`
  before the `onsuccess` handler had fired.
- **IndexedDB writes were never awaited.** The code returned `tx.complete`,
  which is not a real property and was always `undefined`.
- **Every touch threw.** `handleTouchEnd` compared against
  `MaxYMovementSwipe`, a variable that was never declared.
- **Notifications vanished instantly.** A stray `setTimeout(…, 500)` at the end
  of `showNotification` removed the box regardless of the requested duration.
- **The last photo was skipped.** The end-of-list check used `>` instead of
  `>=`, so `slideshowData[length]` — `undefined` — was rendered.
- **Pauses could strand the kiosk.** The default pause was 15,278,971,598 ms
  (about 177 days). Pauses are now capped at 30 minutes.
- **Half the file never ran.** `getDriveImageMetadata`, `getImageSizeInMB`,
  `parseImageMetadata` and `tagToName` were each defined twice; in Apps Script
  the later definition silently wins.
- **`processBatch` could not find its methods.** `this[task.method]` does not
  reliably resolve top-level functions under V8, and it would have invoked any
  name the client sent. It now dispatches through an explicit allowlist.
- **Logging could recurse.** `console.log` was overridden to call a forwarder
  that itself logged to `console` — avoided only by a comment asking readers not
  to touch it.
- **Bulk sheet writes died at 1000 rows** with "range exceeds grid limits".
- **A double tap did nothing.** A transition-in-progress flag dropped the second
  request instead of superseding the first.
- **The screen flashed empty at boot**, because the loading curtain lifted
  before the first photo had decoded.
- **Half the resource watchdog never ran.** Every entry was read with
  `DriveApp.getFolderById()`, which throws for anything that is not a folder —
  so the spreadsheets and scripts in the list always hit the catch block and
  were never actually checked.
- **One watched folder was invisible.** The registry object had the key
  `"Configurations"` twice; in a JavaScript object literal the second wins, so
  the first folder was silently dropped.
- **Sheet protections stacked up.** `sheet.protect()` was called five times in
  a row, and each call creates a *new* protection range — so every maintenance
  pass added more.
- **Alert emails had no rate limit**, on a job that runs every minute.
- **The log rotation's sort was undefined.** Its comparator returned a boolean
  (`a < b`) rather than a number, and the prune loop spliced the array it was
  iterating with `forEach`, so it skipped entries and never reached the limit.
- **A late tick skipped its whole slot.** Scheduling was
  `[15,30,45,0,60].indexOf(now.getMinutes())`; Apps Script does not guarantee a
  trigger fires on the exact minute, so a run that slipped by 60 seconds
  dropped the work until the next slot came round.
- **The uploader's description was always undefined** — `.descripion` was
  misspelled at the call site.
- **A photo could be filed into the wrong archive.** The destination lookup
  searched all of Drive for a folder named e.g. `1962` and took the first hit,
  without checking it was inside the archive.
- **An un-year-named file searched for a folder called "NaN"**, because
  `parseInt(name.split(' ')[0])` was used unguarded.
- **Uploads were moved before their metadata was written**, so a failure left
  a photo in the archive with no description at all.
- **The "has the upload finished?" check was one second**, short enough to move
  a file that was still streaming in.
- **`getConfigurationSpreadsheetWithFallbackCreation` skipped its last row**
  (`r < getLastRow()`) and read every cell individually.
- **Every sheet-configured button was dead.** The rewrite removed the globals
  the action columns call, so the registry found no match, the fallback threw a
  ReferenceError, and each click was logged and dropped. Restored by
  `Client_Legacy.html`.
- **The pause button resumed instead of pausing.** Stepping with the arrows
  leaves a short browse hold in place, and `togglePause` tested "is anything
  holding the slideshow" — so the very next tap on Pause released it. It now
  toggles its own explicit pause and clears incidental holds.
- **Spacer rows printed their names.** Rows named with bare numbers rendered as
  visible text across the bar.
- **The title appeared twice** — once floating above the bar and once inside it.
- **A keyboard row containing `await` never ran.** The legacy fallback built a
  plain `Function`, where a top-level `await` is a SyntaxError; it now builds an
  async function.
- **Photos were being upscaled.** The CDN request was `=w1920`, which forces
  the width — a 900px scan came back stretched to 1920 and the browser drew
  those invented pixels full-screen. That is the soft, jagged look. It now asks
  for `=s<n>`, which bounds the longest side and returns a smaller original
  untouched.
- **The blurred backdrop loaded the full-size photo** and blurred it across the
  whole screen during every transition. It now loads a 64px thumbnail.
- **Tall photos ran off the bottom of the screen.** The stage centred each
  photo in a CSS grid and capped it with `max-width/max-height: 100%`. The
  width cap held; the height cap did nothing, because a percentage resolves
  against the containing block and the `auto` grid row had already grown to
  the photo's own height — `max-height: 100%` of "as tall as the photo" is not
  a limit. A 400x2400 scan drew at 2400px and hung 1701px past a 768px screen,
  so only its middle band was ever visible. Sizing is now explicit; see below.
- **Spacer rows collapsed the bar's spacing.** They were a flat 6px rather than
  a full slot, which slid the playback buttons off-centre.
- **The 14 rows after the first `nameplate` were counted twice** — rendered as
  spacers *and* included in the title width — overflowing the bar and squeezing
  the title to 28px.
- **Rows whose only action was `console.log("12")` drew as buttons**, putting a
  line of dots across the middle of the bar.
- **The pause banner read "Paused - Paused"** because the reason was itself
  named `Paused`.
- **A duplicate `Config` tab** held a byte-for-byte copy of Control Values, so
  it was never clear which one the kiosk read. (It reads Control Values.)
- **One year was hardcoded into the scoring.** `if (img.year == 2026) rawPower
  += 23456` pinned the rotation to a single year; that is now the optional
  `FeaturedYear` setting.

## How a photo is sized

`fitSlide()` in `Client_Slideshow.html` measures the stage and sets **one**
axis in pixels, leaving the other `auto`:

- **Height first.** The photo is drawn the full height of the view area.
- **Width instead**, when drawing at full height would make the photo wider
  than the view area. The height then follows from the ratio.

Because only one axis is ever set, the box always carries the photo's own
aspect ratio, nothing is distorted, and `object-fit` is not set at all. The
photo is centred by `inset: 0` plus `margin: auto` — for an absolutely
positioned element with opposite edges pinned and a resolved size, the two
auto margins resolve equal to each other, on both axes. That leaves
`transform` free for the slide animation.

The view area is the whole window (`window.innerWidth` x
`window.innerHeight`) while nothing is open, so the bottom bar sits over the
lower edge of the photo. It shrinks when the details panel opens, and
`fitSlide()` re-runs for the 460ms the stage takes to settle, so the photo
tracks the space it actually has rather than snapping at the end.

Re-fitting is triggered on: every photo change, the window `resize` handler,
and opening or closing the details panel. It is a no-op when the numbers have
not changed.

One consequence worth knowing: a scan smaller than the screen **is** enlarged,
because the height is set to the view height regardless. `photoUrl()` still
asks Drive for `=s<n>` rather than `=w<n>`, so the CDN never invents pixels —
the browser does the enlarging from the largest real file available.

## What was deliberately left out

The previous project carried several thousand lines of one-off migration code
(`testingBoth`, `FIXIMAGEALLNEW`, `fixImageFromOld`, `doNewFolderBoth`,
`trytofixmname`, `myTHINGFunction`, and the `testingOLD`/`testingNEW` maps of
hardcoded file IDs). Those migrations have already run, several functions were
duplicated, and most were variations on one idea: walk a folder and bring each
description up to schema. That idea is now `repairFolder()`.

The old files are still in your Drive version history if you need to refer back
to them.

Two things from the utilities script were deliberately **not** ported:

- **`h()`** — it stripped the description off every photo in the archive and
  moved the lot into the upload folder. That is an unrecoverable wipe of all
  curation work with no confirmation step, and `repairFolder()` covers the
  legitimate "rebuild the metadata" use.
- **The Drive-file key/value store** (`storeInDriveStorage`,
  `retrieveFromDriveStorage`, `getImageDataFileSystem`) — `retrieveFromDriveStorage`
  returned inside a `forEach`, which does not return from the enclosing
  function, so it always answered `0`. CacheService and Script Properties do
  the same job correctly.

The `makeMultiVarReturn` / `codes` return-code convention was also dropped:
`getCodeFromString` looped on `codes.length`, which is `undefined` for an
object, so it always returned "Code 4". Functions now return named fields.

The enhancement queue (`checkForNewImagesFromCleaner`) **is** ported as
`processEnhancementQueue()`, but its enhancement step was already commented out
in your version — what remained wrote the base64 *text* of the image into a new
file, producing a text document rather than a picture. It is now an explicit
`Intake_enhance_()` hook that passes photos through untouched until you wire a
library into it, and it is off by default (`EnhanceEveryMinutes: 0`).
