/**
 * Utils.gs
 * ---------------------------------------------------------------------------
 * Small shared helpers: caching, logging and object merging.
 * ---------------------------------------------------------------------------
 */

var CACHE_PREFIX_     = 'kiosk.v3.';
var CACHE_CHUNK_SIZE_ = 90 * 1024;   // CacheService caps a single value at 100KB
var CACHE_MAX_CHUNKS_ = 60;
var CACHE_CHUNK_TAG_  = '~chunks:';  // marks a value that was split up

/** Namespaced cache key. */
function Cache_key_(key) {
  return CACHE_PREFIX_ + key;
}

/**
 * Reads a JSON value that may have been split across several cache entries.
 * @return {?*} null when absent or unreadable.
 */
function Cache_getJson_(key) {
  try {
    var cache = CacheService.getScriptCache();
    var head = cache.get(Cache_key_(key));
    if (head === null) return null;

    // Small values are stored inline; large ones leave a pointer behind.
    if (head.indexOf(CACHE_CHUNK_TAG_) !== 0) return JSON.parse(head);

    var count = Number(head.substring(CACHE_CHUNK_TAG_.length));
    if (!count || count > CACHE_MAX_CHUNKS_) return null;

    var keys = [];
    for (var i = 0; i < count; i++) keys.push(Cache_key_(key) + '.' + i);

    var parts = cache.getAll(keys);
    var joined = '';
    for (var j = 0; j < count; j++) {
      var piece = parts[Cache_key_(key) + '.' + j];
      // Chunks can be evicted individually; a partial value is unusable.
      if (piece === undefined || piece === null) return null;
      joined += piece;
    }
    return JSON.parse(joined);
  } catch (err) {
    return null;
  }
}

/**
 * Stores a JSON value, transparently splitting it when it exceeds the 100KB
 * per-entry limit.
 * @return {boolean} false when the value is too large to cache at all.
 */
function Cache_putJson_(key, value, seconds) {
  try {
    var cache = CacheService.getScriptCache();
    var text = JSON.stringify(value);
    var ttl = seconds || 300;

    if (text.length <= CACHE_CHUNK_SIZE_) {
      cache.put(Cache_key_(key), text, ttl);
      return true;
    }

    var count = Math.ceil(text.length / CACHE_CHUNK_SIZE_);
    if (count > CACHE_MAX_CHUNKS_) return false;

    var batch = {};
    for (var i = 0; i < count; i++) {
      batch[Cache_key_(key) + '.' + i] =
          text.substr(i * CACHE_CHUNK_SIZE_, CACHE_CHUNK_SIZE_);
    }
    cache.putAll(batch, ttl);
    cache.put(Cache_key_(key), CACHE_CHUNK_TAG_ + count, ttl);
    return true;
  } catch (err) {
    return false;
  }
}

/** Removes a cached value and any chunks belonging to it. */
function Cache_remove_(key) {
  try {
    var cache = CacheService.getScriptCache();
    var keys = [Cache_key_(key)];
    for (var i = 0; i < CACHE_MAX_CHUNKS_; i++) {
      keys.push(Cache_key_(key) + '.' + i);
    }
    cache.removeAll(keys);
  } catch (err) { /* nothing useful to do here */ }
}

/* ===========================================================================
 * Logging
 *
 * Deliberately plain console calls. The old client overrode console.log to
 * forward every message to the backend, which logged with console.log again -
 * an infinite loop only avoided by a comment asking readers not to touch it.
 * The client now calls Kiosk.log() explicitly, so console stays console.
 * =========================================================================*/

function Log_info_(message) {
  console.log(message);
}

function Log_warn_(message, err) {
  console.warn(message + (err ? ' :: ' + err : ''));
}

function Log_error_(message, err) {
  console.error(message + (err ? ' :: ' + (err.stack || err) : ''));
}

/* ===========================================================================
 * Objects
 * =========================================================================*/

/**
 * Deep-merges `source` onto a copy of `base`. Plain objects merge recursively;
 * arrays and primitives from `source` win outright. Used to bring older image
 * descriptions up to the current schema without losing what they already say.
 */
function Obj_merge_(base, source) {
  var out = {};
  var key;

  for (key in base) {
    if (Object.prototype.hasOwnProperty.call(base, key)) out[key] = base[key];
  }
  if (!source || typeof source !== 'object') return out;

  for (key in source) {
    if (!Object.prototype.hasOwnProperty.call(source, key)) continue;
    var incoming = source[key];
    var existing = out[key];

    if (Obj_isPlain_(incoming) && Obj_isPlain_(existing)) {
      out[key] = Obj_merge_(existing, incoming);
    } else if (incoming !== null && incoming !== undefined && incoming !== '') {
      out[key] = incoming;
    } else if (!(key in out)) {
      out[key] = incoming;
    }
  }
  return out;
}

function Obj_isPlain_(value) {
  return !!value && typeof value === 'object' && !Array.isArray(value) &&
         !(value instanceof Date);
}

/** Safe JSON.parse that returns null instead of throwing. */
function Json_parse_(text) {
  if (!text) return null;
  try {
    return JSON.parse(text);
  } catch (err) {
    return null;
  }
}

/** Short, stable hash used to tell clients whether the library changed. */
function Hash_(text) {
  var bytes = Utilities.computeDigest(
      Utilities.DigestAlgorithm.MD5, String(text), Utilities.Charset.UTF_8);
  var hex = '';
  for (var i = 0; i < bytes.length; i++) {
    var b = (bytes[i] + 256) % 256;
    hex += (b < 16 ? '0' : '') + b.toString(16);
  }
  return hex;
}
