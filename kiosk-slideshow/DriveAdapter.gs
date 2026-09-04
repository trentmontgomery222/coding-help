/**
 * DriveAdapter.gs
 * ---------------------------------------------------------------------------
 * One place for every Drive read/write the kiosk performs.
 *
 * Why this file exists:
 *   The previous version called DriveApp.getFileById() three or four separate
 *   times per image (once for the name, once for the description, once for the
 *   URL) and re-applied sharing permissions on every single sync. For a folder
 *   of ~1000 images that is several thousand round trips per refresh, which is
 *   what made image syncing take minutes and frequently blow the 6-minute
 *   Apps Script execution limit.
 *
 *   Everything here uses the Drive v3 Advanced Service instead, which returns
 *   up to 1000 files - names, descriptions and all - in ONE request, and only
 *   touches sharing for files that are not already shared.
 *
 * Requires: Advanced Google Service "Drive" (v3) enabled. See appsscript.json.
 *           Falls back to DriveApp automatically if it is missing.
 * ---------------------------------------------------------------------------
 */

/** True when the Drive Advanced Service is available in this project. */
function Drive_available_() {
  try {
    return typeof Drive !== 'undefined' && !!Drive.Files;
  } catch (err) {
    return false;
  }
}

/**
 * Lists every file under a folder, optionally recursing into sub-folders.
 * Returns lightweight plain objects - never Drive File handles, which are slow
 * to touch repeatedly.
 *
 * @param {string} folderId       Folder to read.
 * @param {boolean} recursive     Walk sub-folders too.
 * @return {!Array<{id:string,name:string,mimeType:string,description:string,
 *                  size:number,modifiedTime:string,parentId:string,
 *                  parentName:string,shared:boolean}>}
 */
function Drive_listFolder_(folderId, recursive) {
  return Drive_available_()
      ? Drive_listFolderAdvanced_(folderId, recursive)
      : Drive_listFolderLegacy_(folderId, recursive);
}

/** Fast path: Drive v3 Advanced Service, 1000 files per request. */
function Drive_listFolderAdvanced_(rootId, recursive) {
  var out = [];
  var queue = [{id: rootId, name: Drive_folderName_(rootId)}];
  var seen = {};

  while (queue.length) {
    var folder = queue.shift();
    if (seen[folder.id]) continue;
    seen[folder.id] = true;

    var pageToken = null;
    do {
      var res = Drive.Files.list({
        q: "'" + folder.id + "' in parents and trashed = false",
        // Asking for exactly the fields we need keeps the payload small and
        // the request fast. `description` is the important one - that is where
        // all of the image metadata JSON lives.
        fields: 'nextPageToken, files(id, name, mimeType, description, size, ' +
                'modifiedTime, shared)',
        pageSize: 1000,
        pageToken: pageToken,
        supportsAllDrives: true,
        includeItemsFromAllDrives: true
      });

      (res.files || []).forEach(function (f) {
        if (f.mimeType === MIME_FOLDER_) {
          if (recursive) queue.push({id: f.id, name: f.name});
          return;
        }
        out.push({
          id: f.id,
          name: f.name || '',
          mimeType: f.mimeType || '',
          description: f.description || '',
          size: Number(f.size || 0),
          modifiedTime: f.modifiedTime || '',
          parentId: folder.id,
          parentName: folder.name,
          shared: f.shared === true
        });
      });

      pageToken = res.nextPageToken;
    } while (pageToken);
  }
  return out;
}

/** Slow path used only when the Advanced Service is not enabled. */
function Drive_listFolderLegacy_(rootId, recursive) {
  var out = [];
  var queue = [DriveApp.getFolderById(rootId)];

  while (queue.length) {
    var folder = queue.shift();
    var folderId = folder.getId();
    var folderName = folder.getName();

    var files = folder.getFiles();
    while (files.hasNext()) {
      var f = files.next();
      out.push({
        id: f.getId(),
        name: f.getName(),
        mimeType: f.getMimeType(),
        description: f.getDescription() || '',
        size: f.getSize(),
        modifiedTime: f.getLastUpdated().toISOString(),
        parentId: folderId,
        parentName: folderName,
        shared: false
      });
    }
    if (recursive) {
      var subs = folder.getFolders();
      while (subs.hasNext()) queue.push(subs.next());
    }
  }
  return out;
}

var MIME_FOLDER_ = 'application/vnd.google-apps.folder';

/** @return {string} Folder display name, or '' if unreadable. */
function Drive_folderName_(folderId) {
  try {
    return Drive_available_()
        ? Drive.Files.get(folderId, {fields: 'name', supportsAllDrives: true}).name
        : DriveApp.getFolderById(folderId).getName();
  } catch (err) {
    return '';
  }
}

/**
 * Reads a single file's core fields in ONE API call.
 * @return {?{id:string,name:string,description:string,mimeType:string,
 *            shared:boolean,url:string}}
 */
function Drive_getFile_(fileId) {
  try {
    if (Drive_available_()) {
      var f = Drive.Files.get(fileId, {
        fields: 'id, name, description, mimeType, shared, webViewLink',
        supportsAllDrives: true
      });
      return {
        id: f.id,
        name: f.name || '',
        description: f.description || '',
        mimeType: f.mimeType || '',
        shared: f.shared === true,
        url: f.webViewLink || Drive_viewUrl_(fileId)
      };
    }
    var file = DriveApp.getFileById(fileId);
    return {
      id: file.getId(),
      name: file.getName(),
      description: file.getDescription() || '',
      mimeType: file.getMimeType(),
      shared: false,
      url: file.getUrl()
    };
  } catch (err) {
    Log_warn_('Drive_getFile_ failed for ' + fileId, err);
    return null;
  }
}

/**
 * Writes a file's description (our metadata blob) without touching anything
 * else. Uses a patch so we never accidentally clobber the name.
 */
function Drive_setDescription_(fileId, description) {
  if (Drive_available_()) {
    Drive.Files.update({description: description}, fileId, null,
                       {supportsAllDrives: true});
  } else {
    DriveApp.getFileById(fileId).setDescription(description);
  }
}

/**
 * Makes a file readable by anyone with the link, so the kiosk can load it
 * straight from Google's image CDN instead of streaming base64 through
 * Apps Script.
 *
 * This used to run on EVERY file on EVERY sync. It now runs once per file and
 * remembers what it has already done, because permission writes are the single
 * most rate-limited call in the whole pipeline.
 *
 * @param {string} fileId
 * @param {boolean=} alreadyShared Skip the call when the listing already told
 *     us the file is shared.
 */
function Drive_ensurePublic_(fileId, alreadyShared) {
  if (alreadyShared) return true;

  var cache = CacheService.getScriptCache();
  var key = 'pub:' + fileId;
  if (cache.get(key)) return true;

  try {
    if (Drive_available_()) {
      Drive.Permissions.create(
          {role: 'reader', type: 'anyone', allowFileDiscovery: false},
          fileId,
          {supportsAllDrives: true, sendNotificationEmail: false});
    } else {
      DriveApp.getFileById(fileId)
          .setSharing(DriveApp.Access.ANYONE_WITH_LINK, DriveApp.Permission.VIEW);
    }
    cache.put(key, '1', 21600); // 6h - the longest CacheService allows.
    return true;
  } catch (err) {
    // Already-public files throw a duplicate error; that is a success for us.
    var msg = String(err);
    if (msg.indexOf('duplicate') !== -1 || msg.indexOf('already') !== -1) {
      cache.put(key, '1', 21600);
      return true;
    }
    Log_warn_('Could not share ' + fileId, err);
    return false;
  }
}

/** Google image CDN URL. `=w<px>` asks Google to resize server-side. */
function Drive_cdnUrl_(fileId, width) {
  var base = 'https://lh3.googleusercontent.com/d/' + fileId;
  return width ? base + '=w' + width : base;
}

/** Human-facing "open in Drive" link, used for the download QR code. */
function Drive_viewUrl_(fileId) {
  return 'https://drive.google.com/file/d/' + fileId + '/view?usp=sharing';
}

/** Direct download link. */
function Drive_downloadUrl_(fileId) {
  return 'https://drive.google.com/uc?export=download&id=' + fileId;
}
