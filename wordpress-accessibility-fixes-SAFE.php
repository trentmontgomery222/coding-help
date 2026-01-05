/**
 * WordPress Accessibility Fixes - SAFE VERSION
 *
 * IMPORTANT INSTALLATION NOTES:
 * 1. DO NOT include the opening <?php tag if your functions.php already has one
 * 2. Copy from line 1 below (the comment block) to the end
 * 3. Paste at the VERY END of your functions.php file
 */

/**
 * Add semantic HTML landmarks for accessibility
 * CRITICAL FIX: Runs in wp_head (not wp_footer) so screen readers catch it early
 */
if (!function_exists('add_accessibility_landmarks')) {
    function add_accessibility_landmarks() {
        ?>
        <script>
        (function() {
            function addLandmarks() {
                try {
                    // Add main landmark to primary content area
                    var contentArea = document.querySelector('.fl-page-content');
                    if (contentArea) {
                        if (!contentArea.closest('main') && contentArea.getAttribute('role') !== 'main') {
                            var mainWrapper = document.createElement('main');
                            mainWrapper.id = 'main-content';
                            mainWrapper.setAttribute('role', 'main');
                            contentArea.parentNode.insertBefore(mainWrapper, contentArea);
                            mainWrapper.appendChild(contentArea);
                        }
                    }

                    // Add banner landmark to header
                    var headerDiv = document.querySelector('.fl-page-header');
                    if (headerDiv) {
                        if (headerDiv.tagName !== 'HEADER' && !headerDiv.getAttribute('role')) {
                            headerDiv.setAttribute('role', 'banner');
                        }
                    } else {
                        var header = document.querySelector('header');
                        if (header && !header.getAttribute('role')) {
                            header.setAttribute('role', 'banner');
                        }
                    }

                    // Add navigation landmark to menu
                    var navDiv = document.querySelector('.fl-page-nav');
                    if (navDiv) {
                        if (navDiv.tagName !== 'NAV' && !navDiv.getAttribute('role')) {
                            navDiv.setAttribute('role', 'navigation');
                            if (!navDiv.getAttribute('aria-label')) {
                                navDiv.setAttribute('aria-label', 'Main Navigation');
                            }
                        }
                    } else {
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
                        if (footerDiv.tagName !== 'FOOTER' && !footerDiv.getAttribute('role')) {
                            footerDiv.setAttribute('role', 'contentinfo');
                        }
                    } else {
                        var footer = document.querySelector('footer');
                        if (footer && !footer.getAttribute('role')) {
                            footer.setAttribute('role', 'contentinfo');
                        }
                    }
                } catch (e) {
                    // Silently fail if there's an error
                    console.error('Accessibility landmarks error:', e);
                }
            }

            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', addLandmarks);
            } else {
                addLandmarks();
            }
        })();
        </script>
        <?php
    }
    add_action('wp_head', 'add_accessibility_landmarks', 999);
}

/**
 * Add skip navigation link
 */
if (!function_exists('add_skip_navigation')) {
    function add_skip_navigation() {
        ?>
        <style>
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
        .skip-link:focus {
            top: 0;
            outline: 2px solid #fff;
            outline-offset: 2px;
        }
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
    add_action('wp_body_open', 'add_skip_navigation');
}

/**
 * Fallback for themes that don't support wp_body_open hook
 */
if (!function_exists('add_skip_navigation_fallback')) {
    function add_skip_navigation_fallback() {
        if (!did_action('wp_body_open')) {
            add_skip_navigation();
        }
    }
    add_action('wp_header', 'add_skip_navigation_fallback');
}

/**
 * Ensure HTML has language attribute
 */
if (!function_exists('ensure_language_attribute')) {
    function ensure_language_attribute($output) {
        if (strpos($output, 'lang=') === false) {
            $lang = get_bloginfo('language');
            if (empty($lang)) {
                $lang = 'en-US';
            }
            $output .= ' lang="' . esc_attr($lang) . '"';
        }
        return $output;
    }
    add_filter('language_attributes', 'ensure_language_attribute');
}

/**
 * Add ARIA labels to Beaver Builder modules
 */
if (!function_exists('fix_beaver_builder_aria_labels')) {
    function fix_beaver_builder_aria_labels() {
        ?>
        <script>
        (function() {
            function fixAriaLabels() {
                try {
                    // Fix icon-only links
                    var iconLinks = document.querySelectorAll('a[class*="icon"]:not([aria-label]):not([title])');
                    iconLinks.forEach(function(link) {
                        var text = link.textContent.trim();
                        if (!text || text.length === 0) {
                            var href = link.getAttribute('href') || '';

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
                } catch (e) {
                    console.error('ARIA labels error:', e);
                }
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
}

/**
 * Add focus visible styles for keyboard navigation
 */
if (!function_exists('add_focus_styles')) {
    function add_focus_styles() {
        ?>
        <style>
        a:focus,
        button:focus,
        input:focus,
        textarea:focus,
        select:focus,
        [tabindex]:focus {
            outline: 2px solid #005fcc;
            outline-offset: 2px;
        }
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
}

/**
 * INSTALLATION COMPLETE
 *
 * Test your site:
 * 1. Use WAVE browser extension - should show 4 landmarks
 * 2. Press Tab key - skip link should appear
 * 3. Use JAWS/NVDA - should detect all landmarks
 */
