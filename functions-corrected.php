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
