/**
 * ACCESSIBILITY FIXES DIAGNOSTIC SCRIPT
 *
 * HOW TO USE:
 * 1. Open your website in a browser
 * 2. Press F12 to open Developer Tools
 * 3. Click the "Console" tab
 * 4. Copy and paste this ENTIRE script into the console
 * 5. Press Enter
 * 6. Read the diagnostic results
 */

(function() {
    console.log('%c========================================', 'color: #00ff00; font-weight: bold;');
    console.log('%cACCESSIBILITY FIXES DIAGNOSTIC TOOL', 'color: #00ff00; font-weight: bold;');
    console.log('%c========================================', 'color: #00ff00; font-weight: bold;');
    console.log('');

    var results = {
        passed: [],
        failed: [],
        warnings: []
    };

    // TEST 1: Check for main landmarks
    console.log('%c[TEST 1] Checking Main Landmarks...', 'color: #00aaff; font-weight: bold;');
    var mainLandmarks = document.querySelectorAll('main, [role="main"]');
    console.log('  Found', mainLandmarks.length, 'main landmark(s)');

    if (mainLandmarks.length === 0) {
        results.failed.push('No main landmark found - FIX #1 not working');
        console.log('  ❌ FAILED: No main landmark found');
    } else if (mainLandmarks.length === 1) {
        results.passed.push('Exactly 1 main landmark (PERFECT!)');
        console.log('  ✅ PASSED: Exactly 1 main landmark');
        console.log('  Main element:', mainLandmarks[0].tagName, 'ID:', mainLandmarks[0].id || '(none)');
    } else {
        results.failed.push('Multiple main landmarks found - Old code may still be present');
        console.log('  ❌ FAILED: Multiple main landmarks detected');
        mainLandmarks.forEach(function(main, i) {
            console.log('    Main #' + (i+1) + ':', main.tagName, 'ID:', main.id || '(none)', 'Class:', main.className || '(none)');
        });
    }
    console.log('');

    // TEST 2: Check for skip navigation
    console.log('%c[TEST 2] Checking Skip Navigation...', 'color: #00aaff; font-weight: bold;');
    var skipLinks = document.querySelectorAll('.skip-link, .skip-link-enhanced, a[href="#main-content"]');
    console.log('  Found', skipLinks.length, 'skip link(s)');

    if (skipLinks.length > 0) {
        results.passed.push('Skip navigation links present');
        console.log('  ✅ PASSED: Skip links found');
    } else {
        results.failed.push('No skip navigation links - FIX #2 not working');
        console.log('  ❌ FAILED: No skip links found');
    }
    console.log('');

    // TEST 3: Check background videos
    console.log('%c[TEST 3] Checking Background Videos...', 'color: #00aaff; font-weight: bold;');
    var videos = document.querySelectorAll('video');
    console.log('  Found', videos.length, 'video element(s)');

    videos.forEach(function(video, i) {
        var isBackground = video.classList.contains('background-video') ||
                          video.classList.contains('n2-ss-slide-background-video');

        if (isBackground) {
            var ariaHidden = video.getAttribute('aria-hidden');
            var role = video.getAttribute('role');
            var ariaLabel = video.getAttribute('aria-label');

            console.log('  Video #' + (i+1) + ' (background):', {
                'aria-hidden': ariaHidden,
                'role': role,
                'aria-label': ariaLabel
            });

            if (ariaHidden === 'true' && role === 'presentation' && !ariaLabel) {
                results.passed.push('Background video properly marked as decorative');
                console.log('  ✅ PASSED: Video properly marked as decorative');
            } else {
                results.failed.push('Background video not properly configured - FIX #5 not working');
                console.log('  ❌ FAILED: Video should have aria-hidden="true", role="presentation", and NO aria-label');
            }
        }
    });
    console.log('');

    // TEST 4: Check Google Maps
    console.log('%c[TEST 4] Checking Google Maps...', 'color: #00aaff; font-weight: bold;');
    var mapIframes = document.querySelectorAll('iframe[src*="google.com/maps"], iframe[src*="maps.google.com"]');
    console.log('  Found', mapIframes.length, 'Google Map iframe(s)');

    if (mapIframes.length > 0) {
        mapIframes.forEach(function(iframe, i) {
            var ariaHidden = iframe.getAttribute('aria-hidden');
            var title = iframe.getAttribute('title');
            var ariaLabel = iframe.getAttribute('aria-label');
            var tabindex = iframe.getAttribute('tabindex');

            console.log('  Map #' + (i+1) + ':', {
                'aria-hidden': ariaHidden,
                'title': title,
                'aria-label': ariaLabel,
                'tabindex': tabindex
            });

            if (ariaHidden === 'true') {
                results.failed.push('Google Map has aria-hidden="true" (conflicts with focusable iframe) - FIX #18 NOT WORKING');
                console.log('  ❌ FAILED: Map has aria-hidden="true" - should be removed');
            } else if (title || ariaLabel) {
                results.passed.push('Google Map properly labeled and accessible');
                console.log('  ✅ PASSED: Map is accessible');
            } else {
                results.warnings.push('Google Map missing title/aria-label');
                console.log('  ⚠️ WARNING: Map accessible but could use better label');
            }
        });
    } else {
        console.log('  ℹ️ INFO: No Google Maps found on this page');
    }
    console.log('');

    // TEST 5: Check for FIX console messages
    console.log('%c[TEST 5] Checking Console for FIX Messages...', 'color: #00aaff; font-weight: bold;');
    console.log('  Scroll up in the console and look for messages like:');
    console.log('    - "FIX #1: Main landmark already exists..."');
    console.log('    - "FIX #2: Added skip navigation link"');
    console.log('    - "FIX #5: Marked background video as decorative"');
    console.log('    - "FIX #18: Removed aria-hidden from focusable Google Map"');
    console.log('');
    console.log('  If you DON\'T see these messages, the fixes are NOT running!');
    console.log('');

    // TEST 6: Check for keyboard navigation CSS
    console.log('%c[TEST 6] Checking Dropdown Keyboard Navigation...', 'color: #00aaff; font-weight: bold;');
    var hasKeyboardCSS = false;
    var styles = document.querySelectorAll('style');
    styles.forEach(function(style) {
        if (style.textContent.includes('keyboard-focus')) {
            hasKeyboardCSS = true;
        }
    });

    if (hasKeyboardCSS) {
        results.passed.push('Keyboard navigation CSS loaded');
        console.log('  ✅ PASSED: Keyboard navigation CSS found');
    } else {
        results.failed.push('Keyboard navigation CSS missing - FIX #21 not working');
        console.log('  ❌ FAILED: Keyboard navigation CSS not found');
    }
    console.log('');

    // FINAL RESULTS
    console.log('%c========================================', 'color: #00ff00; font-weight: bold;');
    console.log('%cDIAGNOSTIC RESULTS', 'color: #00ff00; font-weight: bold;');
    console.log('%c========================================', 'color: #00ff00; font-weight: bold;');
    console.log('');

    console.log('%c✅ PASSED (' + results.passed.length + '):', 'color: #00ff00; font-weight: bold;');
    results.passed.forEach(function(msg) {
        console.log('  ✅ ' + msg);
    });
    console.log('');

    if (results.warnings.length > 0) {
        console.log('%c⚠️ WARNINGS (' + results.warnings.length + '):', 'color: #ffaa00; font-weight: bold;');
        results.warnings.forEach(function(msg) {
            console.log('  ⚠️ ' + msg);
        });
        console.log('');
    }

    if (results.failed.length > 0) {
        console.log('%c❌ FAILED (' + results.failed.length + '):', 'color: #ff0000; font-weight: bold;');
        results.failed.forEach(function(msg) {
            console.log('  ❌ ' + msg);
        });
        console.log('');
    }

    // VERDICT
    console.log('%c========================================', 'color: #00ff00; font-weight: bold;');
    console.log('%cVERDICT', 'color: #00ff00; font-weight: bold;');
    console.log('%c========================================', 'color: #00ff00; font-weight: bold;');

    if (results.failed.length === 0) {
        console.log('%c✅ ALL FIXES ARE WORKING!', 'color: #00ff00; font-size: 16px; font-weight: bold;');
        console.log('');
        console.log('Your accessibility code is loaded and running correctly.');
        console.log('Re-test with ARC Toolkit and axe DevTools to see error reduction.');
    } else if (results.failed.length <= 2) {
        console.log('%c⚠️ MOSTLY WORKING - Minor Issues', 'color: #ffaa00; font-size: 16px; font-weight: bold;');
        console.log('');
        console.log('Most fixes are working, but there are a few issues to address.');
        console.log('See failed tests above for details.');
    } else {
        console.log('%c❌ FIXES NOT WORKING', 'color: #ff0000; font-size: 16px; font-weight: bold;');
        console.log('');
        console.log('%cPOSSIBLE CAUSES:', 'color: #ff0000; font-weight: bold;');
        console.log('  1. Code not saved to WordPress functions.php');
        console.log('  2. WP Engine cache not cleared (ALL caches)');
        console.log('  3. Browser cache not cleared (hard refresh: Ctrl+Shift+R)');
        console.log('  4. Old accessibility code still present (lines 27-218)');
        console.log('  5. JavaScript syntax error preventing code execution');
        console.log('');
        console.log('%cNEXT STEPS:', 'color: #ff0000; font-weight: bold;');
        console.log('  1. Check for JavaScript errors (look for red text in console)');
        console.log('  2. Verify code is in WordPress: Appearance → Theme Editor → functions.php');
        console.log('  3. Clear ALL caches in WP Engine dashboard');
        console.log('  4. Hard refresh browser: Ctrl+Shift+R (Windows) or Cmd+Shift+R (Mac)');
        console.log('  5. Try private/incognito browser window');
    }

    console.log('');
    console.log('%cSee TROUBLESHOOTING-GUIDE.md for detailed instructions.', 'color: #00aaff;');
    console.log('%c========================================', 'color: #00ff00; font-weight: bold;');

})();
