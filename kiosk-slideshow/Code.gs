/**
 * Code.gs
 * ---------------------------------------------------------------------------
 * Web app entry points and the batched RPC dispatcher.
 *
 * Everything the browser can call goes through here. Two things worth knowing:
 *
 * 1. bootstrap() replaces five separate startup round trips (grabConfigurations,
 *    grabLanguages, grabCommands, grabBottomMenuValues, grabSettingsMenu) with
 *    one. Each google.script.run call costs 300-800ms of latency, so this alone
 *    takes several seconds off a cold start.
 *
 * 2. processBatch() dispatches through an explicit allowlist. The old version
 *    did `this[task.method](...task.params)`, which does not reliably resolve
 *    top-level functions in Apps Script's V8 runtime and would let any name the
 *    client sent be invoked. The allowlist is both correct and safe.
 * ---------------------------------------------------------------------------
 */

/** Serves the kiosk page. */
function doGet(e) {
  var template = HtmlService.createTemplateFromFile('Index');
  // Inlined into the page: zero extra round trips on first paint.
  // `</` is escaped so a stray "</script>" inside a spreadsheet value cannot
  // terminate the script tag it is being written into.
  template.bootData = JSON.stringify(bootstrap()).replace(/<\//g, '<\\/');

  return template.evaluate()
      .setTitle('Allegany Archive Kiosk')
      .addMetaTag('viewport',
                  'width=device-width, initial-scale=1, maximum-scale=1, ' +
                  'user-scalable=no, viewport-fit=cover')
      .setXFrameOptionsMode(HtmlService.XFrameOptionsMode.ALLOWALL);
}

/**
 * Fallback log receiver for when google.script.run is unavailable (for example
 * a navigator.sendBeacon fired during page unload).
 */
function doPost(e) {
  var result;
  try {
    var payload = JSON.parse(e.postData.contents);
    logBatch(payload.entries || [], payload.sessionKey, payload.deviceId);
    result = {result: 'success'};
  } catch (err) {
    result = {result: 'error', error: String(err)};
  }
  return ContentService.createTextOutput(JSON.stringify(result))
      .setMimeType(ContentService.MimeType.JSON);
}

/** Lets Index.html pull in the CSS and JS partials. */
function include(filename) {
  return HtmlService.createHtmlOutputFromFile(filename).getContent();
}

/* ===========================================================================
 * Bootstrap
 * =========================================================================*/

/**
 * Everything the client needs to render its first frame, in one call.
 * @return {!Object}
 */
function bootstrap() {
  var cfg, lang, commands, menu, settings;

  // Each section is independently guarded: a broken Language sheet should not
  // stop the slideshow from starting.
  try { cfg = Config_get(); }        catch (e) { cfg = Config_defaults_(); Log_error_('bootstrap cfg', e); }
  try { lang = Lang_get(); }         catch (e) { lang = Lang_defaults_();  Log_error_('bootstrap lang', e); }
  try { commands = Commands_get(); } catch (e) { commands = [];            Log_error_('bootstrap cmds', e); }
  try { menu = BottomMenu_get(); }   catch (e) { menu = [];                Log_error_('bootstrap menu', e); }
  try { settings = SettingsMenu_get(); } catch (e) { settings = [];        Log_error_('bootstrap settings', e); }

  return {
    ok: true,
    config: cfg,
    lang: lang,
    commands: commands,
    menu: menu,
    settingsMenu: settings,
    serverTime: new Date().toISOString(),
    scriptUrl: getScriptURL(),
    version: KIOSK_VERSION_
  };
}

var KIOSK_VERSION_ = '3.0.0';

/** Deployed web app URL, used by the self-reload path. */
function getScriptURL() {
  try {
    return ScriptApp.getService().getUrl();
  } catch (err) {
    return '';
  }
}

/* ===========================================================================
 * Batched RPC
 * =========================================================================*/

/**
 * Methods the browser is allowed to invoke through processBatch().
 * Anything not listed here is rejected.
 */
function Rpc_allowlist_() {
  return {
    logBatch:          logBatch,
    updateStats:       updateStats,
    updateImageData:   updateImageData,
    registerDevice:    registerDevice,
    reportDevice:      reportDevice,
    ping:              ping,
    getStateToken:     getStateToken,
    getImageManifest:  getImageManifest,
    getImageBlobs:     getImageBlobs,
    refreshImageManifest: refreshImageManifest,
    repairImage:       repairImage,
    setImageQuality:   setImageQuality,
    setImageClassImage: setImageClassImage,
    setImageTeamImage: setImageTeamImage,
    setImageSchoolImage: setImageSchoolImage,
    setImageIndividualImage: setImageIndividualImage,
    setImageOverride:  setImageOverride,
    setImageDisplayName: setImageDisplayName,
    startLogSession:   startLogSession,
    processUploads:    processUploads
  };
}

/**
 * Runs several calls in one round trip.
 *
 * @param {string} payloadJson JSON array of
 *     {id:number, method:string, params:!Array}
 * @return {!Array<{id:number, ok:boolean, result:*, error:(string|undefined)}>}
 */
function processBatch(payloadJson) {
  var tasks = Json_parse_(payloadJson);
  if (!Array.isArray(tasks)) return [];

  var allowed = Rpc_allowlist_();

  return tasks.slice(0, 50).map(function (task) {
    var id = task && task.id;
    try {
      var fn = allowed[task.method];
      if (typeof fn !== 'function') {
        return {id: id, ok: false, error: 'Unknown method: ' + task.method};
      }
      var params = Array.isArray(task.params) ? task.params : [];
      return {id: id, ok: true, result: fn.apply(null, params)};
    } catch (err) {
      Log_error_('processBatch ' + (task && task.method), err);
      return {id: id, ok: false, error: String(err && err.message || err)};
    }
  });
}

/* ===========================================================================
 * Client-facing single calls
 * =========================================================================*/

/**
 * Saves a viewer's edit to a photo's title/description.
 * @param {{id:string, DisplayName:string, Description:string}} data
 */
function updateImageData(data) {
  return Meta_applyEdit_(data);
}

/**
 * Self-heal hook: the client calls this when it finds a photo whose stored
 * metadata will not parse. Rewrites the description to the current schema.
 */
function repairImage(fileId) {
  try {
    var meta = Meta_repairFile(fileId, false);
    Cache_remove_('manifest.main');
    return {ok: !!meta, id: fileId};
  } catch (err) {
    return {ok: false, id: fileId, error: String(err)};
  }
}

/* ===========================================================================
 * Scheduled jobs
 *
 * Run installTriggers() once from the editor to set these up.
 * =========================================================================*/

/** Creates (and de-duplicates) every trigger the kiosk relies on. */
function installTriggers() {
  var wanted = {
    // One heartbeat drives every recurring maintenance job. Apps Script caps
    // a project at 20 triggers, so a single minute trigger that dispatches
    // internally scales better than one trigger per job.
    tick: function () {
      ScriptApp.newTrigger('tick').timeBased().everyMinutes(1).create();
    },
    nightlyMaintenance: function () {
      ScriptApp.newTrigger('nightlyMaintenance').timeBased()
               .atHour(3).everyDays(1).create();
    }
  };

  // Remove existing copies first so re-running never stacks duplicates - the
  // original SetupForYouRunMe only cleaned up one handler and left the rest.
  ScriptApp.getProjectTriggers().forEach(function (trigger) {
    if (wanted[trigger.getHandlerFunction()]) ScriptApp.deleteTrigger(trigger);
  });

  Object.keys(wanted).forEach(function (name) { wanted[name](); });

  Log_info_('Installed triggers: ' + Object.keys(wanted).join(', '));
  return Object.keys(wanted);
}

/**
 * Nightly deep maintenance. Everything here is either slow or destructive
 * enough that it should not run in the minute heartbeat.
 */
function nightlyMaintenance() {
  var report = {};

  Config_invalidate();
  report.photos = Try_('warmImageManifest', warmImageManifest);
  report.resources = Try_('verifyResources', verifyResources);
  report.quarantine = Try_('quarantineBrokenMetadata', quarantineBrokenMetadata);
  report.readMe = Try_('publishReadMe', publishReadMe);

  Log_info_('Nightly maintenance:\n' + JSON.stringify(report, null, 2));
  return report;
}

/** Runs a job, turning a thrown error into a logged result. */
function Try_(name, fn) {
  try {
    return fn();
  } catch (err) {
    Log_error_('job:' + name, err);
    return {error: String(err)};
  }
}

/* ===========================================================================
 * Legacy aliases
 *
 * The previous client and the spreadsheets may still reference these names.
 * They forward to the current implementations so nothing breaks mid-migration.
 * =========================================================================*/

function grabConfigurations()   { return Config_get(); }
function grabLanguages()        { return Lang_get(); }
function grabCommands()         { return Commands_get(); }
function grabBottomMenuValues() { return BottomMenu_get(); }
function grabSettingsMenu()     { return SettingsMenu_get(); }
function getIds()               { return JSON.stringify(listDevices()); }
function fixImageData(id)       { return repairImage(id); }
function recieveData(info)      { return reportDevice(Json_parse_(info) || info); }

/** Old paged blob fetch. Answers with manifest pages instead of base64. */
function improvedGetDriveImageBatch(batchIndex, batchSize) {
  var page = getImageManifest({page: Number(batchIndex) || 0,
                               pageSize: Number(batchSize) || 200});
  return {
    batchIndex: page.page,
    batchSize: page.pageSize,
    totalImages: page.total,
    images: page.items || []
  };
}

/** Old alternate-set fetch; still returns a JSON string as callers expect. */
function getAlternateImages() {
  return JSON.stringify(getImageManifest({set: 'alternate', pageSize: 5000}).items || []);
}

/* ===========================================================================
 * Maintenance heartbeat
 *
 * One trigger fires every minute and this decides what is due.
 *
 * The utilities script did the same job by testing whether the current minute
 * appeared in a list - `[15,30,45,0,60].indexOf(now.getMinutes())`. That has
 * two problems: Apps Script does not guarantee a trigger fires on the exact
 * minute, so a run that slips by 60 seconds skips its whole slot until the
 * next one comes round; and the schedule is fixed in code rather than
 * configurable.
 *
 * Recording when each job last ran and comparing against an interval means a
 * late tick still runs the work, and the intervals live in the settings sheet.
 * =========================================================================*/

/**
 * The recurring jobs, in one table.
 *
 * Both tick() and resetTickSchedule() read this, so adding a job means
 * touching a single place. Keeping two hand-maintained lists in sync is
 * exactly the kind of thing that quietly rots.
 *
 * @return {!Array<{name:string, minutes:number, run:function()}>}
 */
function Tick_jobs_(cfg) {
  return [
    {name: 'intake',    minutes: cfg.IntakeEveryMinutes,        run: processUploads},
    {name: 'integrity', minutes: cfg.IntegrityEveryMinutes,     run: verifyResources},
    {name: 'protect',   minutes: cfg.ProtectSheetsEveryMinutes, run: protectConfigSheets},
    {name: 'enhance',   minutes: cfg.EnhanceEveryMinutes,       run: processEnhancementQueue},
    {name: 'manifest',  minutes: cfg.ManifestWarmEveryMinutes,  run: warmImageManifest}
  ];
}

function tick() {
  var cfg = Config_get();
  var ran = [];

  Tick_jobs_(cfg).forEach(function (job) {
    if (Tick_due_(job.name, job.minutes, job.run)) ran.push(job.name);
  });

  if (ran.length) Log_info_('tick ran: ' + ran.join(', '));
  return ran;
}

/**
 * Runs `fn` if at least `everyMinutes` have passed since it last ran.
 *
 * The timestamp is written BEFORE the job runs. If a job dies part-way it
 * therefore waits a full interval before trying again, instead of retrying
 * every single minute and burning the daily execution quota.
 *
 * @param {string} name
 * @param {number} everyMinutes 0 or blank disables the job.
 * @param {function()} fn
 * @return {boolean} Whether it ran.
 */
function Tick_due_(name, everyMinutes, fn) {
  var interval = Number(everyMinutes);
  if (!interval || interval <= 0) return false;

  var props = PropertiesService.getScriptProperties();
  var key = TICK_PREFIX_ + name;
  var last = Number(props.getProperty(key) || 0);
  var now = Date.now();

  if (last && (now - last) < interval * 60 * 1000) return false;

  props.setProperty(key, String(now));
  try {
    fn();
  } catch (err) {
    Log_error_('tick:' + name, err);
  }
  return true;
}

var TICK_PREFIX_ = 'TICK_LAST.';

/**
 * Clears every heartbeat timer so all jobs run on the next tick.
 * Scans the stored properties rather than a fixed list, so a job added later
 * is still cleared.
 */
function resetTickSchedule() {
  var props = PropertiesService.getScriptProperties();
  var all = props.getProperties();
  var cleared = [];

  Object.keys(all).forEach(function (key) {
    if (key.indexOf(TICK_PREFIX_) === 0) {
      props.deleteProperty(key);
      cleared.push(key.substring(TICK_PREFIX_.length));
    }
  });
  return cleared;
}

/**
 * One-time setup, kept under its original name so existing notes still apply.
 * Installs the triggers and reports anything that still needs attention.
 */
function SetupForYouRunMe() {
  var triggers = installTriggers();
  var check = checkSetup();
  Log_info_('Setup complete. Triggers: ' + triggers.join(', '));
  return {triggers: triggers, setup: check};
}
