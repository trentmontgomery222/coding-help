<?php
/**
 * WCAG 2.1 AA Accessibility Enhancements
 * Optimized Semantic HTML Version - Minimal JavaScript
 *
 * This version uses PHP hooks to output semantic HTML directly,
 * reducing JavaScript load and improving performance.
 *
 * Add this code to your Beaver Builder child theme's functions.php
 */

// ============================================================================
// FIX #1: Beaver Builder Icon Accessibility (PHP Filter)
// ============================================================================

add_filter( 'fl_builder_render_module_content', 'fix_bb_icon_accessibility', 10, 2 );

function fix_bb_icon_accessibility( $html, $module ) {
    // Only target icon modules
    if ( $module->settings->type !== 'icon' ) {
        return $html;
    }

    // Check if the module has a link and sr_text (screen reader text)
    if ( ! empty( $module->settings->link ) && ! empty( $module->settings->sr_text ) ) {
        $link = $module->settings->link;
        $sr_text = $module->settings->sr_text;

        // Create a more descriptive aria-label based on the link type
        $aria_label = $sr_text;

        // Enhance aria-label for common patterns
        if ( strpos( $link, 'tel:' ) === 0 ) {
            // Phone number link
            $phone_number = str_replace( 'tel:', '', $link );
            $phone_number = preg_replace( '/[^0-9]/', '', $phone_number );
            $phone_number = preg_replace( '/(\d{3})(\d{3})(\d{4})/', '$1-$2-$3', $phone_number );
            $aria_label = 'Call ' . $phone_number;
        } elseif ( strpos( $link, 'mailto:' ) === 0 ) {
            // Email link
            $email = str_replace( 'mailto:', '', $link );
            $aria_label = 'Email ' . $email;
        }

        // Find <a> tags and add/update aria-label
        $html = preg_replace_callback(
            '/<a([^>]*)>/i',
            function( $matches ) use ( $aria_label ) {
                $a_tag = $matches[0];
                // Remove existing aria-label if present
                $a_tag = preg_replace( '/aria-label=["\'][^"\']*["\']/i', '', $a_tag );
                // Add new aria-label before the closing >
                $a_tag = str_replace( '>', ' aria-label="' . esc_attr( $aria_label ) . '">', $a_tag );
                return $a_tag;
            },
            $html
        );

        // Make sure Font Awesome icons have aria-hidden="true"
        $html = preg_replace(
            '/<i([^>]*class=["\'][^"\']*fa[^"\']*["\'][^>]*)>/i',
            '<i$1 aria-hidden="true">',
            $html
        );
    }

    return $html;
}

// ============================================================================
// FIX #3: Lang Attribute (PHP Filter - No JavaScript)
// ============================================================================

function add_lang_attribute_filter( $output ) {
    // Ensure lang="en" is always set
    if ( strpos( $output, 'lang=' ) === false ) {
        $output .= ' lang="en"';
    }
    return $output;
}
add_filter( 'language_attributes', 'add_lang_attribute_filter' );

// ============================================================================
// FIX #17: Skip Navigation Links (Semantic HTML via Hook)
// ============================================================================

function add_skip_navigation_html() {
    ?>
    <!-- Skip Navigation Links -->
    <div class="skip-links-container">
        <a href="#main-content" class="skip-link">Skip to main content</a>
        <a href="#main-navigation" class="skip-link">Skip to navigation</a>
    </div>
    <?php
}
add_action( 'wp_body_open', 'add_skip_navigation_html', 1 );

// Skip link CSS
function add_skip_link_styles() {
    ?>
    <style>
    .skip-links-container {
        position: absolute;
        top: 0;
        left: 0;
        z-index: 100001;
    }
    .skip-link {
        position: absolute;
        top: -100px;
        left: 0;
        background: #000;
        color: #fff;
        padding: 10px 20px;
        text-decoration: none;
        font-size: 16px;
        font-weight: bold;
        border: 2px solid #fff;
        z-index: 100002;
    }
    .skip-link:focus {
        top: 0 !important;
        outline: 3px solid #ffcc00;
        outline-offset: 2px;
    }
    </style>
    <?php
}
add_action( 'wp_head', 'add_skip_link_styles', 5 );

// Ensure main content and navigation have IDs
function add_landmark_ids() {
    ?>
    <script>
    // Ensure IDs exist for skip link targets (minimal JavaScript - only runs once)
    (function() {
        var main = document.querySelector('main, .fl-page-content, [role="main"]');
        if (main && !main.id) {
            main.id = 'main-content';
        }

        var nav = document.querySelector('nav, .fl-page-nav, [role="navigation"]');
        if (nav && !nav.id) {
            nav.id = 'main-navigation';
        }
    })();
    </script>
    <?php
}
add_action( 'wp_footer', 'add_landmark_ids', 1 );

// ============================================================================
// FIX #18: Google Maps Accessibility (PHP Content Filter)
// ============================================================================

function fix_google_maps_accessibility( $content ) {
    // Add aria-hidden and tabindex to Google Maps iframes
    $content = preg_replace_callback(
        '/<iframe([^>]*)(src=["\'][^"\']*google\.com\/maps[^"\']*["\'])([^>]*)>/i',
        function( $matches ) {
            $iframe = $matches[0];

            // Remove closing > temporarily
            $iframe = rtrim( $iframe, '>' );

            // Add aria-hidden if not present
            if ( strpos( $iframe, 'aria-hidden' ) === false ) {
                $iframe .= ' aria-hidden="true"';
            }

            // Add tabindex if not present
            if ( strpos( $iframe, 'tabindex' ) === false ) {
                $iframe .= ' tabindex="-1"';
            }

            // Add title if not present
            if ( strpos( $iframe, 'title' ) === false ) {
                $iframe .= ' title="Google Maps - Decorative"';
            }

            return $iframe . '>';
        },
        $content
    );

    return $content;
}
add_filter( 'the_content', 'fix_google_maps_accessibility', 20 );
add_filter( 'widget_text', 'fix_google_maps_accessibility', 20 );
add_filter( 'fl_builder_render_module_content', 'fix_google_maps_accessibility', 20 );

// ============================================================================
// FIX #16: District Calendar H1 (Minimal JavaScript - Tribe Events Edge Case)
// ============================================================================
// Note: This requires JavaScript because Tribe Events generates HTML dynamically
// and doesn't provide PHP hooks for this specific element

if (!function_exists('fix_district_calendar_h1')) {
    function fix_district_calendar_h1() {
        // Only load on pages that might have Tribe Events
        if ( ! function_exists( 'tribe_is_event' ) ) {
            return;
        }
        ?>
        <script>
        (function() {
            'use strict';

            function fixDistrictCalendarH1() {
                // Look for "District Calendar" H1 specifically
                var headings = document.querySelectorAll('h1');

                headings.forEach(function(h1) {
                    if (h1.textContent.trim() === 'District Calendar') {
                        // Check if it's inside tribe events container
                        var tribeContainer = h1.closest('.tribe-events');
                        if (tribeContainer) {
                            // Change to H2
                            var h2 = document.createElement('h2');
                            h2.className = h1.className;
                            h2.textContent = h1.textContent;
                            h1.parentNode.replaceChild(h2, h1);
                        }
                    }
                });
            }

            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', fixDistrictCalendarH1);
            } else {
                fixDistrictCalendarH1();
            }
        })();
        </script>
        <?php
    }
    add_action('wp_footer', 'fix_district_calendar_h1', 10);
}

// ============================================================================
// FIX #23: Remove Duplicate Main Landmarks (Minimal JavaScript)
// ============================================================================
// Note: Requires JavaScript because Beaver Builder generates landmarks dynamically

if (!function_exists('remove_duplicate_main_landmarks')) {
    function remove_duplicate_main_landmarks() {
        ?>
        <script>
        (function() {
            'use strict';

            function removeDuplicateMainLandmarks() {
                // Find all elements with role="main" or <main> tags
                var mainElements = document.querySelectorAll('main, [role="main"]');

                if (mainElements.length > 1) {
                    // Keep the first one that has actual content
                    var kept = false;

                    mainElements.forEach(function(element) {
                        if (!kept && element.textContent.trim().length > 0) {
                            // Keep this one
                            kept = true;
                        } else {
                            // Remove role="main" from duplicates
                            if (element.getAttribute('role') === 'main') {
                                element.removeAttribute('role');
                            }
                        }
                    });
                }
            }

            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', removeDuplicateMainLandmarks);
            } else {
                removeDuplicateMainLandmarks();
            }
        })();
        </script>
        <?php
    }
    add_action('wp_footer', 'remove_duplicate_main_landmarks', 5);
}

// ============================================================================
// FIX #24: Accessible GTranslate Enhancement (JavaScript Required)
// ============================================================================
// Note: Third-party plugin - requires JavaScript for enhancement

if (!function_exists('add_accessible_gtranslate_enhancement')) {
    function add_accessible_gtranslate_enhancement() {
        ?>
        <script>
        (function() {
            'use strict';

            function enhanceGTranslateAccessibility() {
                try {
                    var checkInterval = setInterval(function() {
                        var gtranslateSelectors = [
                            '.gtranslate_wrapper',
                            '#google_translate_element',
                            '.gt_selector',
                            '.gt-current-lang',
                            '[class*="gtranslate"]'
                        ];

                        var gtElement = null;
                        for (var i = 0; i < gtranslateSelectors.length; i++) {
                            gtElement = document.querySelector(gtranslateSelectors[i]);
                            if (gtElement) break;
                        }

                        if (gtElement) {
                            clearInterval(checkInterval);
                            applyAccessibilityEnhancements(gtElement);
                        }
                    }, 500);

                    setTimeout(function() {
                        clearInterval(checkInterval);
                    }, 10000);

                } catch (e) {
                    console.error('GTranslate accessibility enhancement error:', e);
                }
            }

            function applyAccessibilityEnhancements(gtElement) {
                try {
                    if (!gtElement.getAttribute('aria-label')) {
                        gtElement.setAttribute('aria-label', 'Language selector');
                        gtElement.setAttribute('role', 'navigation');
                    }

                    var trigger = gtElement.querySelector('a, button, .gt-current-lang');
                    if (trigger) {
                        if (!trigger.getAttribute('aria-label')) {
                            trigger.setAttribute('aria-label', 'Select language');
                        }
                        if (!trigger.getAttribute('aria-haspopup')) {
                            trigger.setAttribute('aria-haspopup', 'true');
                            trigger.setAttribute('aria-expanded', 'false');
                        }

                        if (!trigger.hasAttribute('tabindex')) {
                            trigger.setAttribute('tabindex', '0');
                        }

                        trigger.addEventListener('keydown', function(e) {
                            if (e.key === ' ' || e.key === 'Enter') {
                                e.preventDefault();
                                this.click();
                            }
                        });

                        trigger.addEventListener('click', function() {
                            var expanded = this.getAttribute('aria-expanded') === 'true';
                            this.setAttribute('aria-expanded', !expanded);
                        });
                    }

                    var languageLinks = gtElement.querySelectorAll('a[data-gt-lang], a[onclick*="doGTranslate"], .gt-option a');
                    languageLinks.forEach(function(link) {
                        var langCode = link.getAttribute('data-gt-lang') ||
                                      (link.getAttribute('onclick') || '').match(/\|([a-z]{2})/);

                        if (langCode && !link.getAttribute('lang')) {
                            var code = typeof langCode === 'string' ? langCode : langCode[1];
                            link.setAttribute('lang', code);
                        }

                        if (!link.getAttribute('aria-label') && link.textContent.trim()) {
                            link.setAttribute('aria-label', 'Switch to ' + link.textContent.trim());
                        }

                        if (!link.getAttribute('role')) {
                            link.setAttribute('role', 'menuitem');
                        }
                    });

                    var dropdown = gtElement.querySelector('.gt-selected, .gt_options, [class*="dropdown"]');
                    if (dropdown) {
                        if (!dropdown.getAttribute('role')) {
                            dropdown.setAttribute('role', 'menu');
                        }
                        if (!dropdown.getAttribute('aria-label')) {
                            dropdown.setAttribute('aria-label', 'Available languages');
                        }
                    }

                    var style = document.createElement('style');
                    style.textContent = `
                        .gtranslate_wrapper a:focus,
                        .gt-current-lang:focus,
                        .gt_selector a:focus,
                        #google_translate_element a:focus {
                            outline: 3px solid #005fcc !important;
                            outline-offset: 2px !important;
                        }

                        .gtranslate_wrapper a:focus-visible,
                        .gt-current-lang:focus-visible,
                        .gt_selector a:focus-visible,
                        #google_translate_element a:focus-visible {
                            outline: 3px solid #005fcc !important;
                            outline-offset: 2px !important;
                        }
                    `;
                    document.head.appendChild(style);

                } catch (e) {
                    console.error('Error applying GTranslate enhancements:', e);
                }
            }

            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', enhanceGTranslateAccessibility);
            } else {
                enhanceGTranslateAccessibility();
            }

            if (typeof MutationObserver !== 'undefined') {
                var observer = new MutationObserver(function(mutations) {
                    mutations.forEach(function(mutation) {
                        if (mutation.addedNodes.length > 0) {
                            mutation.addedNodes.forEach(function(node) {
                                if (node.nodeType === 1) {
                                    var gtElement = node.querySelector ?
                                        node.querySelector('[class*="gtranslate"]') : null;
                                    if (gtElement || (node.className && node.className.indexOf('gtranslate') > -1)) {
                                        enhanceGTranslateAccessibility();
                                    }
                                }
                            });
                        }
                    });
                });

                observer.observe(document.body, {
                    childList: true,
                    subtree: true
                });
            }

        })();
        </script>
        <?php
    }
    add_action('wp_footer', 'add_accessible_gtranslate_enhancement', 20);
}

// ============================================================================
// FIX #25: GTranslate Menu Bar Styling (CSS Only - No JavaScript)
// ============================================================================

if (!function_exists('add_gtranslate_menu_styling')) {
    function add_gtranslate_menu_styling() {
        ?>
        <style>
        /* Make GTranslate compact dropdown for menu bar */
        #mega-menu-wrap .gtranslate_wrapper,
        .mega-menu .gtranslate_wrapper,
        nav .gtranslate_wrapper,
        .gtranslate_wrapper {
            display: inline-block !important;
            margin: 0 10px !important;
            vertical-align: middle !important;
            position: relative !important;
        }

        /* Hide the inline flags list - force dropdown mode */
        .gtranslate_wrapper a[href*="google.com/translate"]:not(.gt-current-lang) {
            display: none !important;
        }

        /* Show only when dropdown is opened */
        .gtranslate_wrapper.gt-open a[href*="google.com/translate"],
        .gtranslate_wrapper:hover .gt_options a,
        .gtranslate_wrapper:focus-within .gt_options a {
            display: block !important;
        }

        /* Style the dropdown trigger to match blue menu bar */
        .gtranslate_wrapper select,
        .gtranslate_wrapper a.gt-current-lang,
        .gtranslate_wrapper .gt_switcher {
            padding: 8px 15px !important;
            background: rgba(255, 255, 255, 0.1) !important;
            border: 1px solid rgba(255, 255, 255, 0.2) !important;
            color: #ffffff !important;
            font-size: 14px !important;
            font-family: inherit !important;
            border-radius: 3px !important;
            cursor: pointer !important;
            display: inline-block !important;
            text-decoration: none !important;
            transition: all 0.3s ease !important;
            white-space: nowrap !important;
        }

        /* Hover effect to match menu items */
        .gtranslate_wrapper select:hover,
        .gtranslate_wrapper a.gt-current-lang:hover,
        .gtranslate_wrapper .gt_switcher:hover {
            background: rgba(255, 255, 255, 0.2) !important;
            border-color: rgba(255, 255, 255, 0.4) !important;
        }

        /* Dropdown menu container */
        .gtranslate_wrapper .gt_options,
        .gtranslate_wrapper .gt-selected,
        .gtranslate_wrapper select {
            position: absolute !important;
            top: 100% !important;
            left: 0 !important;
            background: #ffffff !important;
            border: 1px solid #ddd !important;
            border-radius: 4px !important;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15) !important;
            max-height: 400px !important;
            overflow-y: auto !important;
            z-index: 99999 !important;
            margin-top: 5px !important;
            min-width: 200px !important;
        }

        /* Individual language options in dropdown */
        .gtranslate_wrapper .gt_options a,
        .gtranslate_wrapper option {
            display: block !important;
            padding: 10px 15px !important;
            color: #333 !important;
            text-decoration: none !important;
            border-bottom: 1px solid #f0f0f0 !important;
            transition: background 0.2s ease !important;
        }

        .gtranslate_wrapper .gt_options a:hover,
        .gtranslate_wrapper option:hover {
            background: #f5f5f5 !important;
        }

        /* Flag sizing */
        .gtranslate_wrapper img {
            max-width: 24px !important;
            height: auto !important;
            vertical-align: middle !important;
            margin-right: 8px !important;
        }

        /* Fix for Max Mega Menu integration */
        #mega-menu-wrap .gtranslate_wrapper {
            line-height: normal !important;
        }

        /* Ensure dropdown doesn't cause horizontal scroll */
        .gtranslate_wrapper,
        .gtranslate_wrapper * {
            max-width: 100% !important;
            box-sizing: border-box !important;
        }

        /* Focus styles for accessibility */
        .gtranslate_wrapper a:focus,
        .gtranslate_wrapper select:focus {
            outline: 3px solid #ffcc00 !important;
            outline-offset: 2px !important;
        }
        </style>
        <?php
    }
    add_action('wp_head', 'add_gtranslate_menu_styling', 100);
}
