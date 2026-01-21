/**
 * UNIVERSAL WordPress Accessibility Fixes
 * Works with ANY theme - not just Beaver Builder
 */

if (!function_exists('universal_accessibility_landmarks')) {
    function universal_accessibility_landmarks() {
        ?>
        <script>
        (function() {
            function addLandmarks() {
                try {
                    console.log('Adding accessibility landmarks...');

                    // Find the main content area - try multiple selectors
                    var contentArea = document.querySelector('.fl-page-content') ||
                                     document.querySelector('.site-content') ||
                                     document.querySelector('#content') ||
                                     document.querySelector('.content') ||
                                     document.querySelector('article') ||
                                     document.querySelector('.entry-content');

                    if (contentArea && !document.querySelector('main')) {
                        console.log('Found content area:', contentArea);
                        var mainWrapper = document.createElement('main');
                        mainWrapper.id = 'main-content';
                        mainWrapper.setAttribute('role', 'main');

                        // Find the best parent to wrap
                        var parent = contentArea.closest('.fl-page') || contentArea.parentNode;
                        parent.insertBefore(mainWrapper, parent.firstChild);

                        // Move all children into main
                        while (parent.firstChild && parent.firstChild !== mainWrapper) {
                            mainWrapper.appendChild(parent.firstChild);
                        }

                        console.log('Main landmark added!');
                    }

                    // Add banner to header - try multiple selectors
                    var header = document.querySelector('.fl-page-header') ||
                                document.querySelector('.site-header') ||
                                document.querySelector('#masthead') ||
                                document.querySelector('header') ||
                                document.querySelector('.header');

                    if (header && !header.getAttribute('role') && header.tagName !== 'HEADER') {
                        header.setAttribute('role', 'banner');
                        console.log('Banner landmark added!');
                    }

                    // Add navigation - try multiple selectors
                    var nav = document.querySelector('.fl-page-nav') ||
                             document.querySelector('.site-navigation') ||
                             document.querySelector('#site-navigation') ||
                             document.querySelector('nav') ||
                             document.querySelector('.nav');

                    if (nav && !nav.getAttribute('role') && nav.tagName !== 'NAV') {
                        nav.setAttribute('role', 'navigation');
                        nav.setAttribute('aria-label', 'Main Navigation');
                        console.log('Navigation landmark added!');
                    }

                    // Add contentinfo to footer - try multiple selectors
                    var footer = document.querySelector('.fl-page-footer') ||
                                document.querySelector('.site-footer') ||
                                document.querySelector('#colophon') ||
                                document.querySelector('footer') ||
                                document.querySelector('.footer');

                    if (footer && !footer.getAttribute('role') && footer.tagName !== 'FOOTER') {
                        footer.setAttribute('role', 'contentinfo');
                        console.log('Footer landmark added!');
                    }

                    console.log('Accessibility landmarks complete!');

                } catch (e) {
                    console.error('Accessibility error:', e);
                }
            }

            // Run as early as possible
            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', addLandmarks);
            } else {
                addLandmarks();
            }
        })();
        </script>
        <?php
    }
    add_action('wp_head', 'universal_accessibility_landmarks', 1);
}

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
        }
        .skip-link:focus {
            top: 0;
            outline: 2px solid #fff;
            outline-offset: 2px;
        }
        </style>
        <a href="#main-content" class="skip-link">Skip to main content</a>
        <?php
    }
    add_action('wp_body_open', 'add_skip_navigation');
}

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
        </style>
        <?php
    }
    add_action('wp_head', 'add_focus_styles', 100);
}
