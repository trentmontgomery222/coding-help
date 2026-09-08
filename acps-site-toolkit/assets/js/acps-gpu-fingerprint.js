/**
 * gpu-fingerprint.js — part of Device Bridge (see device-bridge.php for full docs)
 *
 * WHAT IT DOES
 *   Exposes window.GPUFingerprint.get() which returns a Promise resolving to:
 *     { supported: bool, hash: string|null, rendererInfo: object|null }
 *   The hash is a SHA-256 hex string derived from how this specific GPU/driver
 *   combination rounds floating-point math when rasterizing a non-linear shader.
 *   Different GPUs produce different pixel values; same GPU always produces the
 *   same values — making the pixel buffer stably hashable as a device identifier.
 *
 * PERFORMANCE CHANGES vs v2
 *   - Canvas reduced from 256×256 to 32×32.
 *     readPixels data drops from 256 KB → 4 KB (64× less).
 *     Fingerprint quality is unchanged — GPU rendering quirks are per-pixel
 *     and consistent regardless of canvas size.
 *   - This file itself does NO caching. Caching (localStorage 7-day TTL +
 *     sessionStorage session flag) is handled entirely in report.js so that
 *     on returning visits this file's WebGL code is never even called.
 *
 * EXTENSION
 *   report.js calls GPUFingerprint.get() and GPUFingerprint.profile() (from
 *   gpu-benchmark.js) in parallel and ships both results in one POST.
 *   A companion plugin can hook `device_bridge_capabilities` (PHP filter) to
 *   enrich or normalize what gets stored without touching this file.
 */

(function (global) {
  'use strict';

  var CANVAS_SIZE = 32; // px — enough entropy, 64× less data than 256px

  function getGL(canvas) {
    return (
      canvas.getContext('webgl2') ||
      canvas.getContext('webgl') ||
      canvas.getContext('experimental-webgl')
    );
  }

  function getRendererInfo(gl) {
    var ext = gl.getExtension('WEBGL_debug_renderer_info');
    if (!ext) return { vendor: 'unknown', renderer: 'unknown' };
    return {
      vendor:   gl.getParameter(ext.UNMASKED_VENDOR_WEBGL),
      renderer: gl.getParameter(ext.UNMASKED_RENDERER_WEBGL),
    };
  }

  function renderProbe(gl, canvas) {
    // The shader below uses sin/fract chains — deliberately non-linear.
    // Each GPU/driver rounds these differently at the sub-ULP level.
    // The resulting pixel differences are invisible to humans but stable
    // and device-specific, which is exactly what we need for hashing.
    var vs = [
      'attribute vec2 position;',
      'varying vec3 vColor;',
      'void main() {',
      '  vColor = vec3(',
      '    position.x * 0.5 + 0.5,',
      '    position.y * 0.5 + 0.5,',
      '    sin(position.x * 12.9898) * 0.5 + 0.5',
      '  );',
      '  gl_Position = vec4(position, 0.0, 1.0);',
      '}'
    ].join('\n');

    var fs = [
      'precision highp float;',
      'varying vec3 vColor;',
      'void main() {',
      '  vec3 c = fract(sin(vColor * 78.233) * 43758.5453);',
      '  gl_FragColor = vec4(c, 1.0);',
      '}'
    ].join('\n');

    function compile(type, src) {
      var s = gl.createShader(type);
      gl.shaderSource(s, src);
      gl.compileShader(s);
      return s;
    }

    var prog = gl.createProgram();
    gl.attachShader(prog, compile(gl.VERTEX_SHADER, vs));
    gl.attachShader(prog, compile(gl.FRAGMENT_SHADER, fs));
    gl.linkProgram(prog);
    gl.useProgram(prog);

    var verts = new Float32Array([-1,-1, 1,-1, -1,1, -1,1, 1,-1, 1,1]);
    var buf = gl.createBuffer();
    gl.bindBuffer(gl.ARRAY_BUFFER, buf);
    gl.bufferData(gl.ARRAY_BUFFER, verts, gl.STATIC_DRAW);

    var loc = gl.getAttribLocation(prog, 'position');
    gl.enableVertexAttribArray(loc);
    gl.vertexAttribPointer(loc, 2, gl.FLOAT, false, 0, 0);

    gl.viewport(0, 0, canvas.width, canvas.height);
    gl.clearColor(0, 0, 0, 1);
    gl.clear(gl.COLOR_BUFFER_BIT);
    gl.drawArrays(gl.TRIANGLES, 0, 6);

    // 32×32 × 4 channels = 4096 bytes
    var pixels = new Uint8Array(canvas.width * canvas.height * 4);
    gl.readPixels(0, 0, canvas.width, canvas.height, gl.RGBA, gl.UNSIGNED_BYTE, pixels);
    return pixels;
  }

  function getStableParams(gl) {
    var names = [
      'MAX_TEXTURE_SIZE', 'MAX_VIEWPORT_DIMS', 'MAX_VERTEX_ATTRIBS',
      'ALIASED_LINE_WIDTH_RANGE', 'ALIASED_POINT_SIZE_RANGE',
      'SHADING_LANGUAGE_VERSION', 'VERSION',
    ];
    var out = {};
    names.forEach(function (n) {
      try {
        var v = gl.getParameter(gl[n]);
        out[n] = ArrayBuffer.isView(v) ? Array.from(v) : v;
      } catch (e) { out[n] = null; }
    });
    return out;
  }

  async function sha256Hex(bytes) {
    var digest = await crypto.subtle.digest('SHA-256', bytes);
    return Array.from(new Uint8Array(digest))
      .map(function (b) { return b.toString(16).padStart(2, '0'); })
      .join('');
  }

  function encode(str) { return new TextEncoder().encode(str); }

  function concat(a, b) {
    var out = new Uint8Array(a.length + b.length);
    out.set(a, 0);
    out.set(b, a.length);
    return out;
  }

  async function get() {
    var canvas = document.createElement('canvas');
    canvas.width = canvas.height = CANVAS_SIZE;

    var gl = getGL(canvas);
    if (!gl) return { supported: false, hash: null, rendererInfo: null };

    var rendererInfo = getRendererInfo(gl);
    var params       = getStableParams(gl);
    var pixels       = renderProbe(gl, canvas);

    var meta  = encode(JSON.stringify({ rendererInfo: rendererInfo, params: params }));
    var hash  = await sha256Hex(concat(meta, pixels));

    return { supported: true, hash: hash, rendererInfo: rendererInfo };
  }

  global.GPUFingerprint = global.GPUFingerprint || {};
  global.GPUFingerprint.get = get;

}(window));
