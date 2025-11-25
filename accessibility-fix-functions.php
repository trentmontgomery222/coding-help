<?php
/**
 * Beaver Builder Accessibility Fix for Icon Module Links
 * Add this code to your child theme's functions.php file
 */

/**
 * Filter Beaver Builder icon module output to add aria-labels for accessibility
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


/**
 * Alternative method: Add screen reader-only text as visible alternative
 * Uncomment this function if the above doesn't work or you want visible SR text
 */
/*
add_filter( 'fl_builder_render_module_content', 'add_sr_only_text_to_icons', 10, 2 );

function add_sr_only_text_to_icons( $html, $module ) {

    if ( $module->settings->type !== 'icon' ) {
        return $html;
    }

    if ( ! empty( $module->settings->link ) && ! empty( $module->settings->sr_text ) ) {

        // Add screen reader only class to your theme's style.css first:
        // .sr-only { position: absolute; width: 1px; height: 1px; padding: 0;
        //            margin: -1px; overflow: hidden; clip: rect(0,0,0,0);
        //            white-space: nowrap; border-width: 0; }

        $sr_text = $module->settings->sr_text;

        // Add hidden span before closing </a> tag
        $html = preg_replace(
            '/<\/a>/i',
            '<span class="sr-only">' . esc_html( $sr_text ) . '</span></a>',
            $html
        );
    }

    return $html;
}
*/
