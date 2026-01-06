/**
 * DEBUGGING VERSION - Specific Accessibility Fixes
 * This version has extensive console logging to help diagnose issues
 */

// ============================================================================
// FIX #1: Search Bar Icon
// ============================================================================

if (!function_exists('fix_search_widget_accessibility_debug')) {
    function fix_search_widget_accessibility_debug() {
        ?>
        <script>
        (function() {
            function fixSearchWidget() {
                console.log('=== SEARCH WIDGET FIX STARTING ===');
                try {
                    // Find all search buttons with icons
                    var searchButtons = document.querySelectorAll('.fl-button-icon.fa-search');
                    console.log('Found search button icons:', searchButtons.length);

                    searchButtons.forEach(function(icon) {
                        var button = icon.closest('button, a, .fl-button');
                        if (button) {
                            console.log('Found search button element:', button);
                            if (!button.getAttribute('aria-label')) {
                                button.setAttribute('aria-label', 'Search');
                                console.log('✓ Added aria-label to search button');
                            } else {
                                console.log('Search button already has aria-label:', button.getAttribute('aria-label'));
                            }
                        }
                    });

                    // Find all search inputs
                    var searchInputs = document.querySelectorAll('input[type="search"], .search-field');
                    console.log('Found search inputs:', searchInputs.length);

                    searchInputs.forEach(function(input) {
                        console.log('Search input:', input);
                        if (!input.getAttribute('aria-label') && !input.id) {
                            input.setAttribute('aria-label', 'Search');
                            console.log('✓ Added aria-label to search input');
                        }
                    });

                    console.log('=== SEARCH WIDGET FIX COMPLETE ===');
                } catch (e) {
                    console.error('❌ Search widget fix error:', e);
                }
            }

            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', fixSearchWidget);
            } else {
                fixSearchWidget();
            }
        })();
        </script>
        <?php
    }
    add_action('wp_footer', 'fix_search_widget_accessibility_debug');
}

// ============================================================================
// FIX #2: Silent Videos
// ============================================================================

if (!function_exists('fix_silent_video_accessibility_debug')) {
    function fix_silent_video_accessibility_debug() {
        ?>
        <script>
        (function() {
            function fixSilentVideos() {
                console.log('=== SILENT VIDEO FIX STARTING ===');
                try {
                    var videos = document.querySelectorAll('video[muted]');
                    console.log('Found muted videos:', videos.length);

                    videos.forEach(function(video, index) {
                        console.log('Video #' + (index + 1) + ':', video);

                        if (!video.getAttribute('aria-label')) {
                            var description = video.getAttribute('data-title') ||
                                            video.getAttribute('data-description') ||
                                            'Background video (no audio)';

                            video.setAttribute('aria-label', description);
                            video.setAttribute('title', description);
                            console.log('✓ Added aria-label to video:', description);
                        } else {
                            console.log('Video already has aria-label:', video.getAttribute('aria-label'));
                        }
                    });

                    console.log('=== SILENT VIDEO FIX COMPLETE ===');
                } catch (e) {
                    console.error('❌ Silent video fix error:', e);
                }
            }

            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', fixSilentVideos);
            } else {
                fixSilentVideos();
            }
        })();
        </script>
        <?php
    }
    add_action('wp_footer', 'fix_silent_video_accessibility_debug');
}

// ============================================================================
// FIX #3: Smart Slider
// ============================================================================

if (!function_exists('fix_smart_slider_accessibility_debug')) {
    function fix_smart_slider_accessibility_debug() {
        ?>
        <script>
        (function() {
            function fixSmartSlider() {
                console.log('=== SMART SLIDER FIX STARTING ===');
                try {
                    var slides = document.querySelectorAll('.n2-ss-slide-background');
                    console.log('Found Smart Slider slides:', slides.length);

                    slides.forEach(function(slide, index) {
                        console.log('Slide #' + (index + 1) + ':', slide);

                        var img = slide.querySelector('img, picture img');
                        if (img) {
                            console.log('Found image in slide:', img);
                            var alt = img.getAttribute('data-alt') ||
                                     img.getAttribute('data-title') ||
                                     slide.getAttribute('data-title') || '';

                            if (alt && !img.getAttribute('alt')) {
                                img.setAttribute('alt', alt);
                                console.log('✓ Added alt text:', alt);
                            } else if (img.getAttribute('alt')) {
                                console.log('Image already has alt:', img.getAttribute('alt'));
                            } else {
                                console.log('⚠ No alt text found for this image');
                            }
                        } else {
                            console.log('No image found in this slide');
                        }

                        if (slide.getAttribute('aria-hidden') === 'true') {
                            var sliderContainer = slide.closest('.n2-ss-slider, .smartslider');
                            if (sliderContainer && !sliderContainer.getAttribute('aria-label')) {
                                sliderContainer.setAttribute('aria-label', 'Image slider');
                                console.log('✓ Added aria-label to slider container');
                            }
                        }
                    });

                    console.log('=== SMART SLIDER FIX COMPLETE ===');
                } catch (e) {
                    console.error('❌ Smart Slider fix error:', e);
                }
            }

            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', fixSmartSlider);
            } else {
                fixSmartSlider();
            }
        })();
        </script>
        <?php
    }
    add_action('wp_footer', 'fix_smart_slider_accessibility_debug');
}

// ============================================================================
// FIX #4: Callout Buttons
// ============================================================================

if (!function_exists('fix_callout_button_accessibility_debug')) {
    function fix_callout_button_accessibility_debug() {
        ?>
        <script>
        (function() {
            function fixCalloutButtons() {
                console.log('=== CALLOUT BUTTONS FIX STARTING ===');
                try {
                    var callouts = document.querySelectorAll('.fl-module-callout');
                    console.log('Found callout modules:', callouts.length);

                    callouts.forEach(function(callout, index) {
                        console.log('Callout #' + (index + 1) + ':', callout);

                        var iconLink = callout.querySelector('.fl-callout-photo a');
                        var textLink = callout.querySelector('.fl-callout-title-link');

                        console.log('  Icon link:', iconLink);
                        console.log('  Text link:', textLink);

                        if (iconLink && textLink) {
                            console.log('  Icon href:', iconLink.href);
                            console.log('  Text href:', textLink.href);

                            if (iconLink.href === textLink.href) {
                                iconLink.setAttribute('aria-hidden', 'true');
                                iconLink.setAttribute('tabindex', '-1');

                                var linkText = textLink.textContent.trim();
                                if (!textLink.getAttribute('aria-label') && linkText) {
                                    textLink.setAttribute('aria-label', linkText);
                                }

                                console.log('✓ Fixed duplicate links for:', linkText);
                            } else {
                                console.log('⚠ Links go to different URLs, not hiding icon');
                            }
                        } else {
                            console.log('⚠ Could not find both icon and text links');
                        }
                    });

                    console.log('=== CALLOUT BUTTONS FIX COMPLETE ===');
                } catch (e) {
                    console.error('❌ Callout button fix error:', e);
                }
            }

            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', fixCalloutButtons);
            } else {
                fixCalloutButtons();
            }
        })();
        </script>
        <?php
    }
    add_action('wp_footer', 'fix_callout_button_accessibility_debug');
}

// ============================================================================
// FIX #5: UserFeedback Forms
// ============================================================================

if (!function_exists('fix_userfeedback_accessibility_debug')) {
    function fix_userfeedback_accessibility_debug() {
        ?>
        <script>
        (function() {
            function fixUserFeedbackForms() {
                console.log('=== USERFEEDBACK FORMS FIX STARTING ===');
                try {
                    var formContainers = document.querySelectorAll('[aria-hidden="true"]');
                    console.log('Found aria-hidden elements:', formContainers.length);

                    var fixedCount = 0;
                    formContainers.forEach(function(container) {
                        var hasFormElements = container.querySelector('input, textarea, select, button');

                        if (hasFormElements) {
                            container.removeAttribute('aria-hidden');
                            fixedCount++;
                            console.log('✓ Removed aria-hidden from form container');
                        }
                    });

                    console.log('Removed aria-hidden from', fixedCount, 'form containers');

                    var textareas = document.querySelectorAll('.userFeedback textarea, textarea.textarea');
                    console.log('Found textareas:', textareas.length);

                    textareas.forEach(function(textarea) {
                        if (!textarea.getAttribute('aria-label') && !textarea.id) {
                            var placeholder = textarea.getAttribute('placeholder');
                            if (placeholder) {
                                textarea.setAttribute('aria-label', placeholder);
                                console.log('✓ Added aria-label to textarea:', placeholder);
                            }
                        }
                    });

                    console.log('=== USERFEEDBACK FORMS FIX COMPLETE ===');
                } catch (e) {
                    console.error('❌ UserFeedback fix error:', e);
                }
            }

            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', function() {
                    setTimeout(fixUserFeedbackForms, 1000);
                });
            } else {
                setTimeout(fixUserFeedbackForms, 1000);
            }
        })();
        </script>
        <?php
    }
    add_action('wp_footer', 'fix_userfeedback_accessibility_debug');
}

/**
 * HOW TO USE THIS DEBUGGING VERSION:
 *
 * 1. REPLACE your current specific fixes code with this version
 * 2. Visit your site and open Console (F12 → Console tab)
 * 3. You will see detailed messages showing:
 *    - How many elements were found
 *    - What was fixed
 *    - What couldn't be found
 * 4. Send me the console output and I can tell you exactly what's wrong
 */
