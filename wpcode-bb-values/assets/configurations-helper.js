// ---------------------------------------------------------------------
// Paste this directly BELOW your "var configurations = [ ... ];" block.
//
// It gives you one object, CONFIG, for reading and changing settings by
// key, so you never have to walk the array by hand.
//
//   CONFIG.get('calendarID')                    -> 'c_a13c7383...'
//   CONFIG.get('noSchoolEvent.badgeText')       -> 'No School'
//   CONFIG.bool('noSchoolEvent.showBottomBadge')-> true   (real boolean)
//   CONFIG.list('noSchoolEvent.searchForWords') -> ['schools closed']
//   CONFIG.num('someNumber', 0)                 -> a number
//   CONFIG.set('eventColor', 'crimson')         -> also updates the array
//   CONFIG.has('customEventOne')                -> true / false
//   CONFIG.all()                                -> a plain object of everything
//   CONFIG.match('Schools Closed Friday')       -> 'noSchoolEvent'
//
// Values arrive as STRINGS, including 'true' and 'false' - that is how
// they are written in the array and how the Beaver Builder module writes
// them back. Use CONFIG.bool() for on/off settings rather than testing
// the string yourself: 'false' is a non-empty string, so a plain
// if (CONFIG.get('x')) is true even when the setting says false.
// ---------------------------------------------------------------------

var CONFIG = (function (list) {
	var index = {};
	var api = {};

	function isObject(value) {
		return Object.prototype.toString.call(value) === '[object Object]';
	}

	// Hide the siteWide markers - they configure the editor, not the snippet.
	function clean(value) {
		if (!isObject(value)) {
			return value;
		}

		var copy = {};

		for (var key in value) {
			if (Object.prototype.hasOwnProperty.call(value, key) && key !== 'siteWide') {
				copy[key] = value[key];
			}
		}

		return copy;
	}

	for (var i = 0; i < (list || []).length; i++) {
		var entry = list[i];

		if (entry && typeof entry.key === 'string') {
			index[entry.key] = entry;
		}
	}

	/** 'calendarID' or 'noSchoolEvent.badgeText'. */
	api.get = function (path, fallback) {
		var parts = String(path).split('.');
		var entry = index[parts[0]];

		if (!entry) {
			return fallback;
		}

		if (parts.length === 1) {
			return entry.value === undefined ? fallback : clean(entry.value);
		}

		if (!isObject(entry.value)) {
			return fallback;
		}

		var found = entry.value[parts[1]];

		return found === undefined ? fallback : found;
	};

	/** Sets a value, and updates the array itself so later code sees it. */
	api.set = function (path, value) {
		var parts = String(path).split('.');
		var entry = index[parts[0]];

		if (!entry) {
			entry = { key: parts[0], value: parts.length === 1 ? value : {} };
			index[parts[0]] = entry;
			list.push(entry);
		}

		if (parts.length === 1) {
			entry.value = value;
		} else {
			if (!isObject(entry.value)) {
				entry.value = {};
			}

			entry.value[parts[1]] = value;
		}

		return value;
	};

	api.has = function (path) {
		return api.get(path) !== undefined;
	};

	/** 'true'/'false' as written in the array become real booleans. */
	api.bool = function (path, fallback) {
		var value = api.get(path);

		if (typeof value === 'boolean') {
			return value;
		}

		if (value === undefined || value === null || value === '') {
			return fallback === undefined ? false : !!fallback;
		}

		value = String(value).trim().toLowerCase();

		return value === 'true' || value === '1' || value === 'yes' || value === 'on';
	};

	/** Always an array, whether written as a list or as "a, b, c". */
	api.list = function (path) {
		var value = api.get(path);

		if (Object.prototype.toString.call(value) === '[object Array]') {
			return value.slice();
		}

		if (value === undefined || value === null || String(value).trim() === '') {
			return [];
		}

		return String(value).split(',').map(function (part) {
			return part.trim();
		}).filter(function (part) {
			return part !== '';
		});
	};

	api.num = function (path, fallback) {
		var value = parseFloat(api.get(path));

		return isNaN(value) ? (fallback === undefined ? 0 : fallback) : value;
	};

	api.keys = function () {
		var keys = [];

		for (var key in index) {
			if (Object.prototype.hasOwnProperty.call(index, key)) {
				keys.push(key);
			}
		}

		return keys;
	};

	api.all = function () {
		var out = {};

		for (var key in index) {
			if (Object.prototype.hasOwnProperty.call(index, key)) {
				out[key] = clean(index[key].value);
			}
		}

		return out;
	};

	/**
	 * The one this calendar actually needs: given an event title, returns
	 * the key of the first block whose searchForWords appear in it, or
	 * null. Blocks with activated:'false' are skipped.
	 */
	api.match = function (title) {
		var haystack = String(title || '').toLowerCase();
		var keys = api.keys();

		for (var i = 0; i < keys.length; i++) {
			var key = keys[i];
			var words = api.list(key + '.searchForWords');

			if (!words.length) {
				continue;
			}

			if (api.has(key + '.activated') && !api.bool(key + '.activated')) {
				continue;
			}

			for (var w = 0; w < words.length; w++) {
				if (words[w] && haystack.indexOf(String(words[w]).toLowerCase()) !== -1) {
					return key;
				}
			}
		}

		return null;
	};

	return api;
})(configurations);
