<?php
/**
 * Beaver Builder Child Theme
 * For additional information on potential customization options,
 * read the developers' documentation:
 *
 * https://docs.wpbeaverbuilder.com/
 * @version 1.0
 */
if ( ! defined( 'ABSPATH' ) ) {
  exit; // Exit if accessed directly.
}

/**
 * Enqueue child theme style.css file
 * Do not delete this, you will need it
 */
add_action( 'wp_enqueue_scripts', function() {
  wp_enqueue_style(
    'child-style',
    get_stylesheet_uri(),
    array( 'fl-automator-skin' ),
    wp_get_theme()->get( 'Version' )
  );
});

/**
 * Add your custom theme functions below!
 */
/**
 * Beaver Builder Accessibility Fix for Icon Module Links
 */
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
            $phone_number = preg_replace( '/[^0-9]/', '', $phone_number ); // Clean number
            $phone_number = preg_replace( '/(\d{3})(\d{3})(\d{4})/', '$1-$2-$3', $phone_number ); // Format
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
// PASTE THE NEW ACCESSIBILITY CODE BELOW THIS LINE
// ============================================================================

/**
 * WordPress Accessibility Fixes - SAFE VERSION
 * Adds ARIA landmarks and accessibility features for screen readers
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
                    if (contentArea && !contentArea.closest('main') && contentArea.getAttribute('role') !== 'main') {
                        var mainWrapper = document.createElement('main');
                        mainWrapper.id = 'main-content';
                        mainWrapper.setAttribute('role', 'main');
                        contentArea.parentNode.insertBefore(mainWrapper, contentArea);
                        mainWrapper.appendChild(contentArea);
                    }

                    // Add banner landmark to header
                    var headerDiv = document.querySelector('.fl-page-header');
                    if (headerDiv && headerDiv.tagName !== 'HEADER' && !headerDiv.getAttribute('role')) {
                        headerDiv.setAttribute('role', 'banner');
                    }

                    // Add navigation landmark to menu
                    var navDiv = document.querySelector('.fl-page-nav');
                    if (navDiv && navDiv.tagName !== 'NAV' && !navDiv.getAttribute('role')) {
                        navDiv.setAttribute('role', 'navigation');
                        if (!navDiv.getAttribute('aria-label')) {
                            navDiv.setAttribute('aria-label', 'Main Navigation');
                        }
                    }

                    // Add contentinfo landmark to footer
                    var footerDiv = document.querySelector('.fl-page-footer');
                    if (footerDiv && footerDiv.tagName !== 'FOOTER' && !footerDiv.getAttribute('role')) {
                        footerDiv.setAttribute('role', 'contentinfo');
                    }
                } catch (e) {
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
