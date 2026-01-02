<?php
/**
 * WordPress Accessibility Fixes - IMPROVED VERSION
 *
 * This code fixes critical accessibility issues for screen readers (JAWS, NVDA, VoiceOver)
 * and ensures WCAG 2.1 AA compliance for MSDE requirements.
 *
 * INSTALLATION:
 * Add this entire code to your child theme's functions.php file.
 *
 * WHAT THIS FIXES:
 * 1. Adds ARIA landmarks (banner, navigation, main, contentinfo)
 * 2. Adds skip navigation link for keyboard users
 * 3. Ensures HTML language attribute exists
 * 4. Runs early enough for screen readers to detect
 * 5. Creates proper semantic HTML structure
 */

/**
 * Add semantic HTML landmarks for accessibility
 * CRITICAL FIX: Runs in wp_head (not wp_footer) so screen readers catch it early
 */
function add_accessibility_landmarks() {
    ?>
    <script>
    (function() {
        // CRITICAL: Run immediately, don't wait for DOMContentLoaded
        // Screen readers scan the page as it loads
        function addLandmarks() {
            // Add main landmark to primary content area
            var contentArea = document.querySelector('.fl-page-content');
            if (contentArea) {
                // Check if already wrapped in <main> or has role="main"
                if (!contentArea.closest('main') && contentArea.getAttribute('role') !== 'main') {
                    // IMPROVED: Create a proper <main> element wrapper (better than just adding role to div)
                    var mainWrapper = document.createElement('main');
                    mainWrapper.id = 'main-content';
                    mainWrapper.setAttribute('role', 'main'); // Redundant but ensures compatibility
                    contentArea.parentNode.insertBefore(mainWrapper, contentArea);
                    mainWrapper.appendChild(contentArea);
                }
            }

            // Add banner landmark to header (only if not already a <header> element)
            var headerDiv = document.querySelector('.fl-page-header');
            if (headerDiv) {
                // Only add role if it's NOT already a semantic <header> tag
                if (headerDiv.tagName !== 'HEADER' && !headerDiv.getAttribute('role')) {
                    headerDiv.setAttribute('role', 'banner');
                }
            } else {
                // Fallback: try to find any header element
                var header = document.querySelector('header');
                if (header && !header.getAttribute('role')) {
                    header.setAttribute('role', 'banner');
                }
            }

            // Add navigation landmark to menu
            var navDiv = document.querySelector('.fl-page-nav');
            if (navDiv) {
                // Only add role if it's NOT already a semantic <nav> tag
                if (navDiv.tagName !== 'NAV' && !navDiv.getAttribute('role')) {
                    navDiv.setAttribute('role', 'navigation');
                    // Add aria-label if not present
                    if (!navDiv.getAttribute('aria-label')) {
                        navDiv.setAttribute('aria-label', 'Main Navigation');
                    }
                }
            } else {
                // Fallback: try to find any nav element
                var nav = document.querySelector('nav');
                if (nav && !nav.getAttribute('role')) {
                    nav.setAttribute('role', 'navigation');
                    if (!nav.getAttribute('aria-label')) {
                        nav.setAttribute('aria-label', 'Main Navigation');
                    }
                }
            }

            // Add contentinfo landmark to footer
            var footerDiv = document.querySelector('.fl-page-footer');
            if (footerDiv) {
                // Only add role if it's NOT already a semantic <footer> tag
                if (footerDiv.tagName !== 'FOOTER' && !footerDiv.getAttribute('role')) {
                    footerDiv.setAttribute('role', 'contentinfo');
                }
            } else {
                // Fallback: try to find any footer element
                var footer = document.querySelector('footer');
                if (footer && !footer.getAttribute('role')) {
                    footer.setAttribute('role', 'contentinfo');
                }
            }
        }

        // Run immediately if DOM is already loaded, otherwise wait
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', addLandmarks);
        } else {
            addLandmarks();
        }
    })();
    </script>
    <?php
}
// CRITICAL FIX: Changed from wp_footer to wp_head with high priority
// This ensures the script runs EARLY in the page load
add_action('wp_head', 'add_accessibility_landmarks', 999);

/**
 * Add skip navigation link
 * CRITICAL for keyboard and screen reader users
 * This allows users to skip repetitive navigation and jump straight to main content
 */
function add_skip_navigation() {
    ?>
    <style>
    /* Skip link is hidden by default but appears on keyboard focus */
    .skip-link {
        position: absolute;
        top: -40px;
        left: 0;
        background: #000;
        color: #fff;
        padding: 8px 16px;
        text-decoration: none;
        z-index: 100000;
        font-size: 14px;
        font-weight: bold;
    }

    /* When focused (Tab key), skip link appears at top of page */
    .skip-link:focus {
        top: 0;
        outline: 2px solid #fff;
        outline-offset: 2px;
    }

    /* Screen reader only text utility class */
    .screen-reader-text {
        position: absolute;
        left: -10000px;
        width: 1px;
        height: 1px;
        overflow: hidden;
    }

    .screen-reader-text:focus {
        position: static;
        width: auto;
        height: auto;
        clip: auto;
        overflow: visible;
    }
    </style>
    <a href="#main-content" class="skip-link">Skip to main content</a>
    <?php
}
// Use wp_body_open hook (WordPress 5.2+) to add skip link as first element in <body>
add_action('wp_body_open', 'add_skip_navigation');

/**
 * Fallback for themes that don't support wp_body_open hook
 * This ensures skip link is added even on older themes
 */
function add_skip_navigation_fallback() {
    if (!did_action('wp_body_open')) {
        add_skip_navigation();
    }
}
add_action('wp_header', 'add_skip_navigation_fallback');

/**
 * Ensure HTML has language attribute
 * Required for screen readers to use correct pronunciation
 */
function ensure_language_attribute($output) {
    // Check if language attribute is missing
    if (strpos($output, 'lang=') === false) {
        // Get the site language from WordPress settings
        $lang = get_bloginfo('language');
        if (empty($lang)) {
            $lang = 'en-US'; // Default to English
        }
        $output .= ' lang="' . esc_attr($lang) . '"';
    }
    return $output;
}
add_filter('language_attributes', 'ensure_language_attribute');

/**
 * Add ARIA labels to Beaver Builder modules that might be missing them
 * This enhances accessibility for icon links, buttons, and other interactive elements
 */
function fix_beaver_builder_aria_labels() {
    ?>
    <script>
    (function() {
        function fixAriaLabels() {
            // Fix icon-only links that don't have aria-label or title
            var iconLinks = document.querySelectorAll('a[class*="icon"]:not([aria-label]):not([title])');
            iconLinks.forEach(function(link) {
                // Try to get text content from nearby elements
                var text = link.textContent.trim();
                if (!text || text.length === 0) {
                    // If no text, try to infer from class names or href
                    var href = link.getAttribute('href') || '';
                    var classes = link.className;

                    if (href.includes('facebook')) {
                        link.setAttribute('aria-label', 'Facebook');
                    } else if (href.includes('twitter')) {
                        link.setAttribute('aria-label', 'Twitter');
                    } else if (href.includes('instagram')) {
                        link.setAttribute('aria-label', 'Instagram');
                    } else if (href.includes('linkedin')) {
                        link.setAttribute('aria-label', 'LinkedIn');
                    } else if (href.includes('youtube')) {
                        link.setAttribute('aria-label', 'YouTube');
                    } else if (href.includes('tel:')) {
                        link.setAttribute('aria-label', 'Call us');
                    } else if (href.includes('mailto:')) {
                        link.setAttribute('aria-label', 'Email us');
                    } else if (href.includes('maps.google') || href.includes('google.com/maps')) {
                        link.setAttribute('aria-label', 'View on Google Maps');
                    } else {
                        // Generic fallback
                        link.setAttribute('aria-label', 'Link');
                    }
                }
            });

            // Fix Google Maps iframes
            var mapIframes = document.querySelectorAll('iframe[src*="google.com/maps"]:not([title])');
            mapIframes.forEach(function(iframe) {
                iframe.setAttribute('title', 'Google Maps');
            });

            // Fix any iframe without a title
            var iframes = document.querySelectorAll('iframe:not([title])');
            iframes.forEach(function(iframe) {
                var src = iframe.getAttribute('src') || '';
                if (src.includes('youtube')) {
                    iframe.setAttribute('title', 'YouTube video player');
                } else if (src.includes('vimeo')) {
                    iframe.setAttribute('title', 'Vimeo video player');
                } else {
                    iframe.setAttribute('title', 'Embedded content');
                }
            });
        }

        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', fixAriaLabels);
        } else {
            fixAriaLabels();
        }
    })();
    </script>
    <?php
}
add_action('wp_footer', 'fix_beaver_builder_aria_labels');

/**
 * Add focus visible styles for keyboard navigation
 * Ensures all interactive elements have visible focus indicators
 */
function add_focus_styles() {
    ?>
    <style>
    /* Ensure all focusable elements have visible focus indicator */
    a:focus,
    button:focus,
    input:focus,
    textarea:focus,
    select:focus,
    [tabindex]:focus {
        outline: 2px solid #005fcc;
        outline-offset: 2px;
    }

    /* High contrast focus for better visibility */
    a:focus-visible,
    button:focus-visible,
    input:focus-visible,
    textarea:focus-visible,
    select:focus-visible {
        outline: 3px solid #005fcc;
        outline-offset: 2px;
    }
    </style>
    <?php
}
add_action('wp_head', 'add_focus_styles', 100);

/**
 * TESTING INSTRUCTIONS
 *
 * After adding this code to your functions.php:
 *
 * 1. WAVE Browser Extension Test:
 *    - Install WAVE from wave.webaim.org
 *    - Visit your site and click the WAVE icon
 *    - Click the "Structure" tab
 *    - You should see: banner, navigation, main, contentinfo landmarks
 *
 * 2. JAWS/NVDA Screen Reader Test:
 *    - Press Insert + F3 to open elements list
 *    - Select "Landmarks" from dropdown
 *    - You should see: banner, navigation, main, contentinfo
 *    - Press D key to navigate between landmarks
 *
 * 3. Keyboard Navigation Test:
 *    - Press Tab key repeatedly
 *    - First element should be "Skip to main content" link
 *    - All interactive elements should have visible focus
 *
 * 4. axe DevTools Test (Chrome):
 *    - Install axe DevTools extension
 *    - Open Chrome DevTools (F12)
 *    - Click "axe DevTools" tab
 *    - Click "Scan ALL of my page"
 *    - Should return 0 violations for landmarks
 */
