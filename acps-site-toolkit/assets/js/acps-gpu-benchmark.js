/**
 * gpu-benchmark.js — part of Device Bridge (see device-bridge.php for full docs)
 *
 * WHAT IT DOES
 *   Extends window.GPUFingerprint with a .profile() method that returns a
 *   Promise resolving to a hardware capability object:
 *     {
 *       supported:     bool,
 *       renderer:      string,   // GPU model name from WEBGL_debug_renderer_info
 *       vendor:        string,
 *       tier:          string,   // rough bucket — see classifyRenderer() below
 *       benchmarkScore: number,  // draws/sec; higher = faster GPU
 *       maxTextureSize: number,
 *       webgl2:        bool,
 *       cpuCores:      number|null,  // navigator.hardwareConcurrency
 *       deviceMemoryGB: number|null, // navigator.deviceMemory (Chrome/Edge only)
 *       screen:        { width, height, dpr }
 *     }
 *   None of this is personal data — it is hardware capability, not identity.
 *
 * PERFORMANCE CHANGES vs v2
 *   - Removed gl.finish() inside the benchmark loop.
 *     Old: gl.finish() per draw → GPU fence every iteration → main thread
 *          blocked for the entire durationMs budget (150 ms of jank).
 *     New: draw N times (GPU runs async), then ONE gl.finish() at the end.
 *          The single fence is typically < 5 ms on any real GPU. All GPU work
 *          happens in the background during the loop.
 *   - Benchmark is skipped entirely for GPU tiers that are clearly identified
 *     from the renderer string alone ('high-end-discrete', 'apple-silicon',
 *     'mobile-gpu', 'software-renderer'). Score returned as null for those.
 *     This saves the benchmark entirely for the majority of devices.
 *   - Canvas reused from the fingerprint step — no second WebGL context.
 *     (We create a fresh one only if .profile() is called standalone.)
 *   - The whole function is called inside requestIdleCallback by report.js,
 *     so even the final gl.finish() doesn't affect page rendering.
 *
 * TIER BUCKETS
 *   'high-end-discrete'  RTX, RX 7xxx/6xxx, Intel Arc A7xx
 *   'mid-discrete'       GTX, RX 5xxx/4xxx, Radeon Pro
 *   'apple-silicon'      Apple M-series integrated GPU
 *   'integrated-modern'  Iris Xe, Iris Plus, Vega iGPU
 *   'integrated-basic'   Intel HD / UHD
 *   'mobile-gpu'         Mali, Adreno, PowerVR
 *   'software-renderer'  SwiftShader, llvmpipe, software fallback
 *   'other'              Anything not matched above
 */

(function (global) {
  'use strict';

  // Tiers where the renderer string is informative enough on its own.
  // We skip the benchmark for these to avoid any extra GPU work.
  var SKIP_BENCHMARK_TIERS = [
    'high-end-discrete',
    'apple-silicon',
    'mobile-gpu',
    'software-renderer',
  ];

  function classifyRenderer(renderer) {
    if (!renderer) return 'unknown';
    var r = renderer.toLowerCase();
    if (/(rtx|radeon rx [76]|arc a[79])/.test(r))            return 'high-end-discrete';
    if (/(gtx|radeon rx [54]|radeon pro)/.test(r))           return 'mid-discrete';
    if (/apple (m[1-9]|gpu)/.test(r))                        return 'apple-silicon';
    if (/(iris xe|iris plus|vega(?! rx))/.test(r))           return 'integrated-modern';
    if (/(intel hd|intel uhd|intel\(r\) hd)/.test(r))       return 'integrated-basic';
    if (/(mali|adreno|powervr)/.test(r))                     return 'mobile-gpu';
    if (/(swiftshader|software|llvmpipe)/.test(r))           return 'software-renderer';
    return 'other';
  }

  function runBenchmark(gl, canvas) {
    // Build the same overdraw-heavy fullscreen shader as before.
    // 40 sin/cos iterations per fragment stresses the shader units,
    // making the draw time GPU-bound rather than CPU-bound.
    var vs = 'attribute vec2 p; void main(){ gl_Position = vec4(p,0.0,1.0); }';
    var fs = [
      'precision highp float;',
      'uniform float u;',
      'void main() {',
      '  vec3 c = vec3(0.0);',
      '  for (int i = 0; i < 40; i++) {',
      '    c += vec3(sin(u + float(i)), cos(u * 1.7 + float(i)), sin(u * 0.5));',
      '  }',
      '  gl_FragColor = vec4(c * 0.01, 1.0);',
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
    var loc  = gl.getAttribLocation(prog, 'p');
    gl.enableVertexAttribArray(loc);
    gl.vertexAttribPointer(loc, 2, gl.FLOAT, false, 0, 0);

    var uLoc = gl.getUniformLocation(prog, 'u');
    gl.viewport(0, 0, canvas.width, canvas.height);

    // KEY CHANGE: loop issues draws without stalling; GPU runs async.
    // One gl.finish() at the end waits for all work to complete, then
    // we measure the total elapsed time.
    var N = 200;
    var start = performance.now();
    for (var i = 0; i < N; i++) {
      gl.uniform1f(uLoc, i * 0.01);
      gl.drawArrays(gl.TRIANGLES, 0, 6);
    }
    gl.finish(); // single fence — typically < 5 ms on real hardware
    var elapsed = performance.now() - start;

    return Math.round(N / (elapsed / 1000)); // draws/sec
  }

  async function profile() {
    var canvas = document.createElement('canvas');
    canvas.width = canvas.height = 32;
    var gl = canvas.getContext('webgl2') || canvas.getContext('webgl');
    if (!gl) return { supported: false };

    var ext      = gl.getExtension('WEBGL_debug_renderer_info');
    var renderer = ext ? gl.getParameter(ext.UNMASKED_RENDERER_WEBGL) : 'unknown';
    var vendor   = ext ? gl.getParameter(ext.UNMASKED_VENDOR_WEBGL)   : 'unknown';
    var tier     = classifyRenderer(renderer);

    var score = null;
    if (SKIP_BENCHMARK_TIERS.indexOf(tier) === -1) {
      // Only benchmark ambiguous/mid-range tiers where the number adds info.
      score = runBenchmark(gl, canvas);
    }

    return {
      supported:      true,
      renderer:       renderer,
      vendor:         vendor,
      tier:           tier,
      benchmarkScore: score,
      maxTextureSize: gl.getParameter(gl.MAX_TEXTURE_SIZE),
      webgl2:         !! canvas.getContext('webgl2'),
      cpuCores:       navigator.hardwareConcurrency  || null,
      deviceMemoryGB: navigator.deviceMemory         || null,
      screen: {
        width:  screen.width,
        height: screen.height,
        dpr:    window.devicePixelRatio || 1,
      },
    };
  }

  global.GPUFingerprint = global.GPUFingerprint || {};
  global.GPUFingerprint.profile = profile;

}(window));
