/**
 * Images.gs
 * ---------------------------------------------------------------------------
 * Builds the photo manifest the kiosk plays from.
 *
 * WHAT CHANGED AND WHY IT IS FASTER
 * ---------------------------------
 * Old flow, per sync:
 *   for each of ~1000 files:
 *     getFileData(id)  x3   (name, description, url  -> 3 API calls)
 *     setSharing(id)   x1   (permission write, heavily rate limited)
 *     setPrivate(parent)x1
 *     getBlob()             (downloads the ENTIRE image into memory)
 *     base64Encode()        (inflates it by ~33%)
 *   ...then shipped every byte to the browser and stored it in IndexedDB.
 *
 * A 4MB photo became ~5.4MB of base64 in the payload, in IndexedDB, and again
 * as a data: URL in the DOM. That is what filled memory and made the kiosk
 * stutter and eventually die.
 *
 * New flow, per sync:
 *   ONE Drive.Files.list call per 1000 files, returning names AND descriptions
 *   together. No blobs. No per-file permission writes for files that are
 *   already shared. The browser loads pixels straight from Google's image CDN
 *   at exactly the size the screen needs.
 *
 * Base64 mode still exists (ImageSource: 'inline') for a kiosk that must run
 * without internet access to lh3.googleusercontent.com, but it is no longer
 * the default.
 * ---------------------------------------------------------------------------
 */

var MANIFEST_CACHE_SECONDS_ = 1800;   // 30 minutes

/**
 * The main entry point the client calls.
 *
 * @param {{page:(number|undefined), pageSize:(number|undefined),
 *          set:(string|undefined), revision:(string|undefined)}=} opts
 *     set:      'main' (default) or 'alternate'
 *     revision: the client's cached revision; when it still matches, we reply
 *               {unchanged:true} and send no photo data at all.
 * @return {!Object}
 */
function getImageManifest(opts) {
  opts = opts || {};
  var cfg = Config_get();
  var set = opts.set === 'alternate' ? 'alternate' : 'main';
  var pageSize = Number(opts.pageSize) || Number(cfg.ManifestPageSize) || 400;
  var page = Math.max(0, Number(opts.page) || 0);

  var manifest = Images_buildManifest_(set, cfg);

  // Nothing changed since the client last synced - cheapest possible answer.
  if (opts.revision && opts.revision === manifest.revision && page === 0) {
    return {
      unchanged: true,
      revision: manifest.revision,
      total: manifest.items.length,
      set: set
    };
  }

  var start = page * pageSize;
  var slice = manifest.items.slice(start, start + pageSize);

  return {
    unchanged: false,
    set: set,
    revision: manifest.revision,
    total: manifest.items.length,
    page: page,
    pageSize: pageSize,
    hasMore: start + slice.length < manifest.items.length,
    items: slice
  };
}

/**
 * Builds (or reuses a cached) manifest for a photo set.
 * @return {{revision:string, items:!Array<!Object>, builtAt:string}}
 */
function Images_buildManifest_(set, cfg) {
  var cacheKey = 'manifest.' + set;
  var cached = Cache_getJson_(cacheKey);
  if (cached) return cached;

  // A build can take a while on a big library; keep concurrent kiosks from all
  // doing the same work at once.
  var lock = LockService.getScriptLock();
  var haveLock = false;
  try {
    haveLock = lock.tryLock(30000);
    if (haveLock) {
      var again = Cache_getJson_(cacheKey);   // another run may have finished
      if (again) return again;
    }

    var folderId = set === 'alternate'
        ? cfg.AlternateFolderId
        : cfg.ImagesFolderId;

    var files = Drive_listFolder_(folderId, cfg.IncludeSubFolders !== false);
    var items = [];
    var revisionSeed = [];
    var maxBytes = (Number(cfg.MaxImageSizeMB) || 25) * 1024 * 1024;

    for (var i = 0; i < files.length; i++) {
      var file = files[i];
      if (!Images_isUsable_(file, cfg, maxBytes)) continue;

      var meta = Meta_parse_(file.description, file);
      var item = Meta_toManifestItem_(meta, file, cfg);

      // Only touch sharing for files Drive told us are not shared yet.
      if (!file.shared) Drive_ensurePublic_(file.id, false);

      items.push(item);
      revisionSeed.push(file.id + ':' + file.modifiedTime);
    }

    Images_sort_(items, cfg.ImageOrder);

    var manifest = {
      revision: Hash_(revisionSeed.join('|')),
      items: items,
      builtAt: new Date().toISOString()
    };

    Cache_putJson_(cacheKey, manifest, MANIFEST_CACHE_SECONDS_);
    return manifest;
  } finally {
    if (haveLock) lock.releaseLock();
  }
}

/** Filters out folders, non-images, oversized files and (usually) TIFFs. */
function Images_isUsable_(file, cfg, maxBytes) {
  var mime = String(file.mimeType || '');
  if (mime.indexOf('image/') !== 0) return false;

  var isTiff = mime === 'image/tiff' || mime === 'image/tif';
  if (isTiff && cfg.AllowTiffs !== true) {
    // Browsers outside Safari cannot render TIFF at all, and the files are
    // enormous. Skipping them is the difference between a kiosk that runs and
    // one that hangs on a 90MB scan.
    return false;
  }

  if (file.size && maxBytes && file.size > maxBytes) {
    Log_warn_('Skipping oversized image (' +
              Math.round(file.size / 1048576) + 'MB): ' + file.name);
    return false;
  }
  return true;
}

/**
 * Orders the manifest.
 *   name     - alphabetical, which for this archive is chronological
 *   year     - by year then name
 *   shuffle  - random each build
 *   weighted - grouped by year, weighted pick within each year
 */
function Images_sort_(items, order) {
  switch (String(order || 'name').toLowerCase()) {
    case 'year':
      items.sort(function (a, b) {
        return (a.year - b.year) || a.name.localeCompare(b.name);
      });
      break;

    case 'shuffle':
      for (var i = items.length - 1; i > 0; i--) {
        var j = Math.floor(Math.random() * (i + 1));
        var tmp = items[i]; items[i] = items[j]; items[j] = tmp;
      }
      break;

    case 'weighted':
      // Higher weight first inside each year, years in order.
      items.sort(function (a, b) {
        return (a.year - b.year) || (b.weight - a.weight) ||
               a.name.localeCompare(b.name);
      });
      break;

    default:
      items.sort(function (a, b) { return a.name.localeCompare(b.name); });
  }
}

/* ===========================================================================
 * Inline (base64) mode - opt-in only
 * =========================================================================*/

/**
 * Returns raw image bytes for a handful of ids, for kiosks configured with
 * ImageSource: 'inline'.
 *
 * Hard-capped at a small batch because each image can be several megabytes and
 * Apps Script kills any single execution at 6 minutes.
 *
 * @param {!Array<string>} ids
 * @return {!Array<{id:string, mimeType:string, data:string, error:boolean}>}
 */
function getImageBlobs(ids) {
  if (!ids || !ids.length) return [];

  var cfg = Config_get();
  var maxBytes = (Number(cfg.MaxImageSizeMB) || 25) * 1024 * 1024;
  var out = [];
  var budget = Math.min(ids.length, 8);

  for (var i = 0; i < budget; i++) {
    var id = ids[i];
    try {
      var blob = DriveApp.getFileById(id).getBlob();
      var bytes = blob.getBytes();

      if (bytes.length > maxBytes) {
        out.push({id: id, mimeType: '', data: '', error: true});
        continue;
      }
      out.push({
        id: id,
        mimeType: blob.getContentType(),
        data: Utilities.base64Encode(bytes),
        error: false
      });
    } catch (err) {
      Log_warn_('getImageBlobs failed for ' + id, err);
      out.push({id: id, mimeType: '', data: '', error: true});
    }
  }
  return out;
}

/* ===========================================================================
 * Maintenance
 * =========================================================================*/

/**
 * Forces the next manifest request to rebuild from Drive.
 * Call this after adding photos rather than waiting out the cache.
 */
function refreshImageManifest() {
  Cache_remove_('manifest.main');
  Cache_remove_('manifest.alternate');
  return {ok: true, refreshedAt: new Date().toISOString()};
}

/**
 * Warms the manifest cache. Wire this to a time-driven trigger (hourly is
 * plenty) so a kiosk restarting mid-day never pays the full rebuild cost.
 */
function warmImageManifest() {
  var cfg = Config_get();
  refreshImageManifest();
  var main = Images_buildManifest_('main', cfg);
  Log_info_('Warmed manifest: ' + main.items.length + ' photos, revision ' +
            main.revision);
  return main.items.length;
}
