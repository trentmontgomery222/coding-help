/**
 * Metadata.gs
 * ---------------------------------------------------------------------------
 * Every photo carries a JSON blob in its Drive file description. This file
 * owns that schema: the canonical shape, how to read it safely, how to repair
 * an old or corrupt one, and how to compute the display weight the slideshow
 * uses to decide how often a photo appears.
 *
 * The old code stored template strings such as
 *     "Resolution": ".MetaData.XResolution \"x\" .MetaData.YResolution"
 * inside the saved JSON and then string-replaced them on the client at render
 * time. Those placeholders leaked onto the screen whenever the replace missed.
 * Values are now resolved once, here, and stored resolved.
 * ---------------------------------------------------------------------------
 */

/** The canonical description schema. */
function Meta_schema_() {
  return {
    SchemaVersion: 3,
    ImageId: null,

    OriginalItemInfo: {Name: null, ID: null, Description: null, Parents: null},

    Stats: {UpVotes: 0, DownVotes: 0, ImageShows: 0},

    /** Flags that feed Meta_weight_() below. */
    ImageChance: {
      IsTeamImage: false,
      IsClassImage: false,
      IsIndividualImage: false,
      IsSchoolImage: false,
      IsQuality: false,
      IsRandom: false,
      IsCopyrighted: false,
      TimeOfDayLocked: false,
      Overridden: false,
      Interaction: 0
    },

    Properties: {
      ID: null,
      DisplayName: null,
      Description: null,
      Year: null,
      Decade: null,
      QualityRating: 3,              // 1-5, 5 is best
      InteractionTimes: 0,
      IsClassPicture: false,
      IsImageOfSchool: false,
      IsHomecomingPicture: false,    // false | 'General' | 'Court' | 'Dance'
      IsSpecial: false,
      IsStarred: false,
      WasConvertedToJPG: false,
      IsPictureOfYearbook: false,
      IsPictureFromYearbook: false,
      UploadDate: null,              // when intake filed it into the archive
      Copyright: {
        License: 'All Rights Reserved',
        Producer: 'Allegany Archive Group',
        Downloadable: true,
        ImageLicenseDate: null,
        Display: null,
        Notice: 'Contact the Allegany Archive Group for image rights.'
      }
    },

    /** Raw EXIF, populated by Exif.gs. */
    MetaData: {},

    /** Presentation-ready values resolved from MetaData. */
    ImageSetup: {Resolution: null, Software: null, DateTaken: null}
  };
}

/**
 * Parses a file description into a complete schema object, filling in anything
 * missing. Never throws - a blank or broken description yields defaults.
 *
 * @param {string} description Raw Drive file description.
 * @param {{id:string, name:string}} file For deriving fallbacks.
 * @return {!Object}
 */
function Meta_parse_(description, file) {
  var parsed = Json_parse_(description);
  var meta = Obj_merge_(Meta_schema_(), parsed || {});

  meta.ImageId = file.id;
  meta.Properties.ID = file.id;

  // Display name: prefer the curated one, else clean up the file name.
  if (!meta.Properties.DisplayName) {
    meta.Properties.DisplayName = Meta_cleanName_(file.name);
  }

  // Year: prefer the curated one, else the leading number in the file name.
  if (meta.Properties.Year === null || meta.Properties.Year === '') {
    meta.Properties.Year = Meta_yearFromName_(file.name);
  }
  meta.Properties.Year = Number(meta.Properties.Year) || 0;

  if (!meta.Properties.Decade && meta.Properties.Year) {
    meta.Properties.Decade = String(Math.floor(meta.Properties.Year / 10) * 10) + 's';
  }

  // Resolve the presentation fields once, here, instead of templating them
  // into the stored JSON and patching them up on the client.
  var exif = meta.MetaData || {};
  if (!meta.ImageSetup.Resolution && exif.XResolution && exif.YResolution) {
    meta.ImageSetup.Resolution =
        Meta_round_(exif.XResolution) + ' x ' + Meta_round_(exif.YResolution);
  }
  if (!meta.ImageSetup.Software && exif.Software) {
    meta.ImageSetup.Software = exif.Software;
  }
  if (!meta.ImageSetup.DateTaken) {
    meta.ImageSetup.DateTaken = exif.DateTimeOriginal || exif.DateTime || null;
  }

  var copyright = meta.Properties.Copyright;
  if (!copyright.Display) {
    copyright.Display = copyright.License + ' @ ' + copyright.Producer;
  }
  if (!copyright.ImageLicenseDate) {
    copyright.ImageLicenseDate = meta.ImageSetup.DateTaken;
  }

  return meta;
}

/** Strips extensions and the import marker from a raw file name. */
function Meta_cleanName_(name) {
  return String(name || '')
      .replace(/AUTOMATED_ADDITION/g, '')
      .replace(/image\/(jpeg|jpg|png|tiff?)/gi, '')
      .replace(/\.(jpe?g|png|tiff?|webp|heic)$/i, '')
      .replace(/\s{2,}/g, ' ')
      .trim();
}

/** Pulls a plausible 4-digit year out of a file name. */
function Meta_yearFromName_(name) {
  var match = String(name || '').match(/\b(18\d{2}|19\d{2}|20\d{2})\b/);
  return match ? Number(match[1]) : 0;
}

function Meta_round_(value) {
  var num = Number(value);
  return isNaN(num) ? value : Math.round(num);
}

/**
 * Scores how likely a photo is to be selected for a rotation.
 *
 * The original scoring lived on the client and ran on every single save, with
 * a hardcoded `if (year == 2026) weight += 23456` that effectively pinned the
 * show to one year. That override is now an explicit, configurable
 * `FeaturedYear` boost rather than a magic number buried in a loop.
 *
 * @param {!Object} meta   Parsed description.
 * @param {!Object} cfg    Live config (for FeaturedYear).
 * @return {number} 1-100.
 */
function Meta_weight_(meta, cfg) {
  var chance = meta.ImageChance || {};
  var props = meta.Properties || {};
  var score = 20;                              // everything starts eligible

  if (chance.IsClassImage || props.IsClassPicture) score += 45;
  if (chance.Overridden)          score += 45;
  if (chance.IsQuality)           score += 15;
  if (chance.IsTeamImage)         score += 12;
  if (chance.IsIndividualImage)   score += 8;
  if (chance.IsSchoolImage)       score += 8;
  if (chance.IsRandom)            score += 4;
  if (chance.IsCopyrighted)       score -= 12;

  var rating = Number(props.QualityRating);
  if (!isNaN(rating)) score += (rating - 3) * 6;   // 1..5 -> -12..+12

  var interactions = Number(chance.Interaction || props.InteractionTimes || 0);
  if (!isNaN(interactions)) score += Math.min(interactions, 20);

  // Optional spotlight on a graduating year, set in the Control Values sheet.
  var featured = Number(cfg && cfg.FeaturedYear);
  if (featured && Number(props.Year) === featured) score += 60;

  return Math.max(1, Math.min(100, Math.round(score)));
}

/**
 * Flattens a full description into the compact record the client caches and
 * renders. Keeping this small matters: it is multiplied by every photo in the
 * library and shipped over the wire.
 */
function Meta_toManifestItem_(meta, file, cfg) {
  var props = meta.Properties;
  var exif = meta.MetaData || {};
  var setup = meta.ImageSetup || {};

  return {
    id: file.id,
    name: Meta_cleanName_(file.name),
    displayName: props.DisplayName || Meta_cleanName_(file.name),
    description: props.Description || '',
    year: Number(props.Year) || 0,
    decade: props.Decade || '',
    producer: props.Copyright.Producer || '',
    license: props.Copyright.Display || '',
    downloadable: props.Copyright.Downloadable !== false,
    camera: exif.Model || exif.Make || '',
    lens: exif.LensModel || exif.LensMake || '',
    resolution: setup.Resolution || '',
    software: setup.Software || '',
    dateTaken: setup.DateTaken || '',
    isClassPhoto: !!(meta.ImageChance.IsClassImage || props.IsClassPicture),
    weight: Meta_weight_(meta, cfg),
    modified: file.modifiedTime || ''
  };
}

/**
 * Reads, patches and writes a single file's description so it matches the
 * current schema. Used by the maintenance jobs and by the self-heal path when
 * the client reports a photo it could not parse.
 *
 * @param {string} fileId
 * @param {boolean=} withExif Re-read EXIF from the image bytes (slow).
 * @return {?Object} The repaired description, or null on failure.
 */
function Meta_repairFile(fileId, withExif) {
  var file = Drive_getFile_(fileId);
  if (!file) return null;

  var meta = Meta_parse_(file.description, file);

  if (withExif && !meta.MetaData.ImageWidth) {
    try {
      meta.MetaData = Obj_merge_(meta.MetaData, Exif_readFromDrive_(fileId));
      // Re-resolve now that EXIF may have arrived.
      meta = Meta_parse_(JSON.stringify(meta), file);
    } catch (err) {
      Log_warn_('EXIF read failed for ' + fileId, err);
    }
  }

  // Infer flags from naming conventions the archive already uses.
  var name = String(file.name || '').toLowerCase();
  if (name.indexOf('resize') !== -1 || name.indexOf('class image') !== -1) {
    meta.ImageChance.IsClassImage = true;
    meta.Properties.IsClassPicture = true;
  }
  if (name.indexOf('team') !== -1)  meta.ImageChance.IsTeamImage = true;
  if (name.indexOf('hoco') !== -1 || name.indexOf('homecoming') !== -1) {
    meta.Properties.IsHomecomingPicture =
        name.indexOf('court') !== -1 ? 'Court' :
        name.indexOf('dance') !== -1 ? 'Dance' : 'General';
  }
  if (String(meta.MetaData.Artist || '').indexOf('Steven R.Bittner') !== -1) {
    meta.Properties.Copyright.Producer = 'Times News Photographer';
    meta.Properties.Copyright.License = 'The Cumberland Times News';
    meta.Properties.Copyright.Downloadable = false;
    meta.Properties.Copyright.Display = 'The Cumberland Times News @ Times News Photographer';
  }

  Drive_setDescription_(fileId, JSON.stringify(meta, null, 2));
  return meta;
}

/**
 * Applies a viewer's edits (title / description) to a photo.
 * Only the two editable fields are ever written - a viewer can never overwrite
 * copyright, EXIF or scoring data.
 *
 * @param {{id:string, DisplayName:(string|undefined),
 *          Description:(string|undefined)}} edit
 * @return {{ok:boolean, id:string, error:(string|undefined)}}
 */
function Meta_applyEdit_(edit) {
  if (!edit || !edit.id) return {ok: false, id: '', error: 'missing id'};

  var cfg = Config_get();
  if (cfg.AllowViewerEdits === false) {
    return {ok: false, id: edit.id, error: 'edits disabled'};
  }

  var file = Drive_getFile_(edit.id);
  if (!file) return {ok: false, id: edit.id, error: 'file not found'};

  var meta = Meta_parse_(file.description, file);
  var changed = false;

  if (typeof edit.DisplayName === 'string') {
    var title = edit.DisplayName.trim().slice(0, 120);
    if (title && title !== meta.Properties.DisplayName) {
      meta.Properties.DisplayName = title;
      changed = true;
    }
  }
  if (typeof edit.Description === 'string') {
    var body = edit.Description.trim().slice(0, 2000);
    if (body !== (meta.Properties.Description || '')) {
      meta.Properties.Description = body;
      changed = true;
    }
  }

  if (!changed) return {ok: true, id: edit.id};

  meta.Properties.InteractionTimes = Number(meta.Properties.InteractionTimes || 0) + 1;
  meta.ImageChance.Interaction = Number(meta.ImageChance.Interaction || 0) + 1;

  Drive_setDescription_(edit.id, JSON.stringify(meta, null, 2));
  refreshImageManifest();   // the cached manifest is now stale
  return {ok: true, id: edit.id};
}

/* ===========================================================================
 * Flag setters, called from the settings menu / remote commands.
 * =========================================================================*/

function Meta_setFlag_(fileId, path, value) {
  var file = Drive_getFile_(fileId);
  if (!file) return null;

  var meta = Meta_parse_(file.description, file);
  var parts = path.split('.');
  var node = meta;
  for (var i = 0; i < parts.length - 1; i++) {
    if (!node[parts[i]]) node[parts[i]] = {};
    node = node[parts[i]];
  }
  node[parts[parts.length - 1]] = value;

  Drive_setDescription_(fileId, JSON.stringify(meta, null, 2));
  refreshImageManifest();
  return meta;
}

function setImageClassImage(id)      { return Meta_setFlag_(id, 'ImageChance.IsClassImage', true); }
function setImageTeamImage(id)       { return Meta_setFlag_(id, 'ImageChance.IsTeamImage', true); }
function setImageSchoolImage(id)     { return Meta_setFlag_(id, 'ImageChance.IsSchoolImage', true); }
function setImageIndividualImage(id) { return Meta_setFlag_(id, 'ImageChance.IsIndividualImage', true); }
function setImageOverride(id)        { return Meta_setFlag_(id, 'ImageChance.Overridden', true); }
function setImageDisplayName(id, n)  { return Meta_setFlag_(id, 'Properties.DisplayName', String(n)); }

function setImageQuality(id, rating) {
  var value = Math.max(1, Math.min(5, Number(rating) || 3));
  Meta_setFlag_(id, 'Properties.QualityRating', value);
  return Meta_setFlag_(id, 'ImageChance.IsQuality', value > 3);
}
