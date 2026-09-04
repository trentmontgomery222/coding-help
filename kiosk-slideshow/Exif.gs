/**
 * Exif.gs
 * ---------------------------------------------------------------------------
 * Minimal EXIF / TIFF reader for JPEG and TIFF files stored in Drive.
 *
 * The original had this parser defined twice in the same project (along with
 * two copies of getImageSizeInMB and two of tagToName). In Apps Script the
 * later definition silently wins, so half the code you were reading was never
 * the code that ran. There is exactly one copy of each here.
 *
 * This is only invoked by maintenance jobs, never in the slideshow hot path -
 * downloading image bytes to read a header is far too slow to do per frame.
 * ---------------------------------------------------------------------------
 */

var EXIF_MAX_STRING_ = 8192;   // never read more than 8KB out of one field

/**
 * Downloads just enough of a Drive image to read its header, then parses it.
 * @return {!Object} Tag map, or {error} on failure.
 */
function Exif_readFromDrive_(fileId) {
  try {
    var url = 'https://www.googleapis.com/drive/v3/files/' + fileId +
              '?alt=media&supportsAllDrives=true';
    var response = UrlFetchApp.fetch(url, {
      headers: {
        Authorization: 'Bearer ' + ScriptApp.getOAuthToken(),
        // EXIF lives in the first few KB; asking for a range keeps us from
        // pulling a 20MB scan across the wire to read a camera model.
        Range: 'bytes=0-262143'
      },
      muteHttpExceptions: true
    });

    var code = response.getResponseCode();
    if (code !== 200 && code !== 206) {
      return {error: 'fetch failed (' + code + ')'};
    }
    return Exif_parse_(response.getBlob().getBytes());
  } catch (err) {
    return {error: String(err)};
  }
}

/** Detects the container and dispatches to the right reader. */
function Exif_parse_(bytes) {
  try {
    var view = new DataView(Uint8Array.from(bytes).buffer);
    if (view.byteLength < 4) return {};

    var b0 = view.getUint8(0);
    var b1 = view.getUint8(1);

    if (b0 === 0xFF && b1 === 0xD8) return Exif_fromJpeg_(view);          // JPEG
    if ((b0 === 0x49 && b1 === 0x49) || (b0 === 0x4D && b1 === 0x4D)) {   // TIFF
      return Exif_readTiff_(view, 0);
    }
    return {};   // PNG/WebP carry no EXIF we care about
  } catch (err) {
    return {error: String(err)};
  }
}

/** Walks JPEG segments looking for the APP1/Exif block. */
function Exif_fromJpeg_(view) {
  var offset = 2;
  while (offset + 4 < view.byteLength) {
    if (view.getUint8(offset) !== 0xFF) break;

    var marker = view.getUint8(offset + 1);
    if (marker === 0xDA) break;                       // start of scan: done
    var length = view.getUint16(offset + 2);
    if (length < 2) break;

    if (marker === 0xE1) {
      // Skip the 6-byte "Exif\0\0" identifier to land on the TIFF header.
      return Exif_readTiff_(view, offset + 4 + 6);
    }
    offset += 2 + length;
  }
  return {};
}

/** Reads the TIFF header and its first IFD (plus GPS, if present). */
function Exif_readTiff_(view, start) {
  try {
    if (start + 8 > view.byteLength) return {};

    var littleEndian = Exif_string_(view, start, 2) === 'II';
    var firstIfd = view.getUint32(start + 4, littleEndian);
    var tags = Exif_readIfd_(view, start + firstIfd, start, littleEndian);

    if (tags.GPSInfoIFDPointer) {
      var gps = Exif_readIfd_(
          view, start + tags.GPSInfoIFDPointer, start, littleEndian);
      tags.GPSInfoIFDPointer =
          (gps.GPSLatitude && gps.GPSLongitude)
              ? Exif_gpsDecimal_(gps.GPSLatitude, gps.GPSLatitudeRef) + ', ' +
                Exif_gpsDecimal_(gps.GPSLongitude, gps.GPSLongitudeRef)
              : null;
    }

    if (tags.XMLPacket) tags.XMLPacket = Exif_parseXmp_(tags.XMLPacket);
    return tags;
  } catch (err) {
    return {error: 'TIFF parse failed: ' + err};
  }
}

/** Reads one image file directory into a {tagName: value} map. */
function Exif_readIfd_(view, dirStart, tiffStart, littleEndian) {
  var tags = {};
  if (dirStart + 2 > view.byteLength) return tags;

  var entries = view.getUint16(dirStart, littleEndian);
  var typeSizes = [0, 1, 1, 2, 4, 8, 1, 1, 2, 4, 8, 4, 8];

  for (var i = 0; i < entries; i++) {
    var entry = dirStart + 2 + i * 12;
    if (entry + 12 > view.byteLength) break;

    var tagName = Exif_tagName_(view.getUint16(entry, littleEndian));
    if (!tagName) continue;                       // unmapped tag: skip entirely

    var type = view.getUint16(entry + 2, littleEndian);
    var count = view.getUint32(entry + 4, littleEndian);
    var size = (typeSizes[type] || 0) * count;
    if (!size || count > 65535) continue;         // corrupt entry

    var valueAt = size > 4
        ? tiffStart + view.getUint32(entry + 8, littleEndian)
        : entry + 8;
    if (valueAt < 0 || valueAt >= view.byteLength) continue;

    tags[tagName] = Exif_readValue_(view, type, count, valueAt, littleEndian);
  }
  return tags;
}

/** Decodes a single tag value according to its TIFF type code. */
function Exif_readValue_(view, type, count, offset, littleEndian) {
  try {
    var out;
    var i;

    switch (type) {
      case 1:                                  // BYTE
      case 7:                                  // UNDEFINED
        if (count === 1) return view.getUint8(offset);
        out = [];
        for (i = 0; i < Math.min(count, EXIF_MAX_STRING_); i++) {
          out.push(view.getUint8(offset + i));
        }
        return out;

      case 2:                                  // ASCII
        return Exif_string_(view, offset, count - 1);

      case 3:                                  // SHORT
        if (count === 1) return view.getUint16(offset, littleEndian);
        out = [];
        for (i = 0; i < count; i++) {
          out.push(view.getUint16(offset + 2 * i, littleEndian));
        }
        return out;

      case 4:                                  // LONG
        if (count === 1) return view.getUint32(offset, littleEndian);
        out = [];
        for (i = 0; i < count; i++) {
          out.push(view.getUint32(offset + 4 * i, littleEndian));
        }
        return out;

      case 5:                                  // RATIONAL
        if (count === 1) {
          return Exif_divide_(view.getUint32(offset, littleEndian),
                              view.getUint32(offset + 4, littleEndian));
        }
        out = [];
        for (i = 0; i < count; i++) {
          out.push(Exif_divide_(
              view.getUint32(offset + 8 * i, littleEndian),
              view.getUint32(offset + 8 * i + 4, littleEndian)));
        }
        return out;

      default:
        return null;
    }
  } catch (err) {
    return null;
  }
}

/** Reads a NUL-terminated ASCII run, bounded so a corrupt length cannot hang us. */
function Exif_string_(view, start, length) {
  var end = Math.min(start + Math.max(0, length), view.byteLength,
                     start + EXIF_MAX_STRING_);
  var out = '';
  for (var i = start; i < end; i++) {
    var ch = view.getUint8(i);
    if (ch === 0) break;
    out += String.fromCharCode(ch);
  }
  return out;
}

function Exif_divide_(numerator, denominator) {
  return denominator ? numerator / denominator : 0;
}

/** Converts an EXIF degrees/minutes/seconds triple to a decimal coordinate. */
function Exif_gpsDecimal_(coord, ref) {
  if (!coord || coord.length < 3) return null;
  var value = coord[0] + coord[1] / 60 + coord[2] / 3600;
  return (ref === 'S' || ref === 'W') ? -value : value;
}

/** Pulls the handful of useful fields out of an embedded XMP packet. */
function Exif_parseXmp_(raw) {
  var xml = Array.isArray(raw)
      ? raw.map(function (b) { return String.fromCharCode(b); }).join('')
      : String(raw || '');
  xml = xml.substring(0, EXIF_MAX_STRING_);

  function tag(name) {
    var match = xml.match(new RegExp('<' + name + '[^>]*>(.*?)</' + name + '>', 'i'));
    return match ? match[1] : null;
  }

  return {
    Creator: tag('dc:creator'),
    Lens: tag('aux:Lens'),
    ExposureTime: tag('exif:ExposureTime'),
    FNumber: tag('exif:FNumber'),
    ISO: tag('exif:ISOSpeedRatings'),
    FocalLength: tag('exif:FocalLength')
  };
}

/**
 * The one and only tag map. Anything not listed here is deliberately dropped -
 * unmapped tags were the bulk of the stored JSON and none of it was displayed.
 */
function Exif_tagName_(tag) {
  var map = {
    /* Image basics */
    0x0100: 'ImageWidth',        0x0101: 'ImageHeight',
    0x0102: 'BitsPerSample',     0x0103: 'Compression',
    0x0106: 'PhotometricInterpretation',
    0x010F: 'Make',              0x0110: 'Model',
    0x0112: 'Orientation',       0x011A: 'XResolution',
    0x011B: 'YResolution',       0x0128: 'ResolutionUnit',
    0x0131: 'Software',          0x0132: 'DateTime',
    0x013B: 'Artist',            0x8298: 'Copyright',

    /* Exposure */
    0x829A: 'ExposureTime',      0x829D: 'FNumber',
    0x8822: 'ExposureProgram',   0x8827: 'ISO',
    0x9003: 'DateTimeOriginal',  0x9004: 'DateTimeDigitized',
    0x9201: 'ShutterSpeedValue', 0x9202: 'ApertureValue',
    0x9203: 'BrightnessValue',   0x9204: 'ExposureBias',
    0x9207: 'MeteringMode',      0x9208: 'LightSource',
    0x9209: 'Flash',             0x920A: 'FocalLength',
    0xA402: 'ExposureMode',      0xA403: 'WhiteBalance',
    0xA405: 'FocalLengthIn35mm',

    /* Frame */
    0xA001: 'ColorSpace',        0xA002: 'PixelXDimension',
    0xA003: 'PixelYDimension',

    /* Lens */
    0xA432: 'LensSpecification', 0xA433: 'LensMake',
    0xA434: 'LensModel',         0xA435: 'LensSerialNumber',

    /* Other */
    0x8825: 'GPSInfoIFDPointer', 0x02BC: 'XMLPacket',
    0x4746: 'Rating',            0x4749: 'RatingPercent'
  };
  return map[tag] || null;
}

/** Convenience wrapper kept for the maintenance scripts. */
function getDriveImageMetadata(fileId) {
  var file = Drive_getFile_(fileId);
  if (!file) return null;
  return {
    id: fileId,
    name: file.name,
    mimeType: file.mimeType,
    metadata: Exif_readFromDrive_(fileId)
  };
}
