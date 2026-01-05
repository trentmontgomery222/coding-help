/**
 * SPECIFIC ACCESSIBILITY FIXES
 * Add these to your functions.php after the existing accessibility code
 */

// ============================================================================
// FIX #1: Search Bar Icon - Add aria-label to search button/form
// ============================================================================

if (!function_exists('fix_search_widget_accessibility')) {
    function fix_search_widget_accessibility() {
        ?>
        <script>
        (function() {
            function fixSearchWidget() {
                try {
                    // Find all search buttons with icons
                    var searchButtons = document.querySelectorAll('.fl-button-icon.fa-search');
                    searchButtons.forEach(function(icon) {
                        var button = icon.closest('button, a, .fl-button');
                        if (button && !button.getAttribute('aria-label')) {
                            button.setAttribute('aria-label', 'Search');
                        }
                    });

                    // Find all search inputs without labels
                    var searchInputs = document.querySelectorAll('input[type="search"], .search-field');
                    searchInputs.forEach(function(input) {
                        if (!input.getAttribute('aria-label') && !input.id) {
                            input.setAttribute('aria-label', 'Search');
                        }
                    });

                    console.log('Search widget accessibility fixed!');
                } catch (e) {
                    console.error('Search widget fix error:', e);
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
    add_action('wp_footer', 'fix_search_widget_accessibility');
}

// ============================================================================
// FIX #2: Videos with No Audio - Add aria-label indicating no audio
// ============================================================================

if (!function_exists('fix_silent_video_accessibility')) {
    function fix_silent_video_accessibility() {
        ?>
        <script>
        (function() {
            function fixSilentVideos() {
                try {
                    // Find all muted videos (background videos)
                    var videos = document.querySelectorAll('video[muted]');
                    videos.forEach(function(video) {
                        // Add aria-label describing it's a background video with no audio
                        if (!video.getAttribute('aria-label')) {
                            // Try to get description from data attributes or nearby text
                            var description = video.getAttribute('data-title') ||
                                            video.getAttribute('data-description') ||
                                            'Background video (no audio)';

                            video.setAttribute('aria-label', description);
                            video.setAttribute('title', description);
                        }
                    });

                    console.log('Silent videos accessibility fixed!');
                } catch (e) {
                    console.error('Silent video fix error:', e);
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
    add_action('wp_footer', 'fix_silent_video_accessibility');
}

// ============================================================================
// FIX #3: Smart Slider - Ensure slides have proper ARIA and alt text
// ============================================================================

if (!function_exists('fix_smart_slider_accessibility')) {
    function fix_smart_slider_accessibility() {
        ?>
        <script>
        (function() {
            function fixSmartSlider() {
                try {
                    // Find all Smart Slider backgrounds
                    var slides = document.querySelectorAll('.n2-ss-slide-background');
                    slides.forEach(function(slide) {
                        // Find the image inside
                        var img = slide.querySelector('img, picture img');
                        if (img) {
                            var alt = img.getAttribute('data-alt') ||
                                     img.getAttribute('data-title') ||
                                     slide.getAttribute('data-title') || '';

                            if (alt && !img.getAttribute('alt')) {
                                img.setAttribute('alt', alt);
                            }
                        }

                        // If slide is aria-hidden, make sure there's alternative text elsewhere
                        if (slide.getAttribute('aria-hidden') === 'true') {
                            // Find the slider container and ensure it's labeled
                            var sliderContainer = slide.closest('.n2-ss-slider, .smartslider');
                            if (sliderContainer && !sliderContainer.getAttribute('aria-label')) {
                                sliderContainer.setAttribute('aria-label', 'Image slider');
                            }
                        }
                    });

                    console.log('Smart Slider accessibility fixed!');
                } catch (e) {
                    console.error('Smart Slider fix error:', e);
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
    add_action('wp_footer', 'fix_smart_slider_accessibility');
}

// ============================================================================
// FIX #4: Callout Module - Combine separate icon and text links
// ============================================================================

if (!function_exists('fix_callout_button_accessibility')) {
    function fix_callout_button_accessibility() {
        ?>
        <script>
        (function() {
            function fixCalloutButtons() {
                try {
                    // Find all callout modules
                    var callouts = document.querySelectorAll('.fl-module-callout');
                    callouts.forEach(function(callout) {
                        // Find icon link and text link
                        var iconLink = callout.querySelector('.fl-callout-photo a');
                        var textLink = callout.querySelector('.fl-callout-title-link');

                        if (iconLink && textLink) {
                            // Check if they go to the same place
                            if (iconLink.href === textLink.href) {
                                // Hide icon link from screen readers
                                iconLink.setAttribute('aria-hidden', 'true');
                                iconLink.setAttribute('tabindex', '-1');

                                // Make sure text link has proper label
                                var linkText = textLink.textContent.trim();
                                if (!textLink.getAttribute('aria-label') && linkText) {
                                    textLink.setAttribute('aria-label', linkText);
                                }

                                console.log('Fixed callout button:', linkText);
                            }
                        }
                    });

                    console.log('Callout buttons accessibility fixed!');
                } catch (e) {
                    console.error('Callout button fix error:', e);
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
    add_action('wp_footer', 'fix_callout_button_accessibility');
}

// ============================================================================
// FIX #5: UserFeedback Form - Remove aria-hidden from form fields
// ============================================================================

if (!function_exists('fix_userfeedback_accessibility')) {
    function fix_userfeedback_accessibility() {
        ?>
        <script>
        (function() {
            function fixUserFeedbackForms() {
                try {
                    // Find all UserFeedback form containers
                    var formContainers = document.querySelectorAll('[aria-hidden="true"]');
                    formContainers.forEach(function(container) {
                        // Check if it contains form elements
                        var hasFormElements = container.querySelector('input, textarea, select, button');

                        if (hasFormElements) {
                            // Remove aria-hidden from form containers
                            container.removeAttribute('aria-hidden');
                            console.log('Removed aria-hidden from form container');
                        }
                    });

                    // Ensure all form fields have proper labels
                    var textareas = document.querySelectorAll('.userFeedback textarea, textarea.textarea');
                    textareas.forEach(function(textarea) {
                        if (!textarea.getAttribute('aria-label') && !textarea.id) {
                            var placeholder = textarea.getAttribute('placeholder');
                            if (placeholder) {
                                textarea.setAttribute('aria-label', placeholder);
                            }
                        }
                    });

                    console.log('UserFeedback forms accessibility fixed!');
                } catch (e) {
                    console.error('UserFeedback fix error:', e);
                }
            }

            // Run after a delay to ensure UserFeedback plugin has loaded
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
    add_action('wp_footer', 'fix_userfeedback_accessibility');
}

/**
 * MANUAL FIXES NEEDED (Can't be automated)
 */

// ============================================================================
// FIX #2 MANUAL: Video Description
// ============================================================================
// For your silent video, you should add a text description near it.
// Add this HTML near your video slider:
//
// <div class="screen-reader-text">
//     This video shows [describe what's in the video - e.g., "students
//     learning in a classroom, teachers instructing, and school activities"]
// </div>
//
// OR add a caption/description below the video that visible users can see too.

// ============================================================================
// FIX #4 ALTERNATIVE: Callout Module Settings
// ============================================================================
// BETTER SOLUTION: Fix in Beaver Builder settings
// 1. Edit the Callout module
// 2. Remove the icon link (set to "None")
// 3. Keep only the text link
// 4. Or combine them into a single Button module instead
