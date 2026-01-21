<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
<?php wp_head(); ?>
</head>

<body <?php body_class(); ?>>

<?php
/**
 * OPTIONAL header.php for Beaver Builder Child Theme
 *
 * PURPOSE: Adds lang="en" attribute to <html> tag using pure semantic HTML
 * instead of using the language_attributes filter.
 *
 * INSTRUCTIONS:
 * 1. Save this file as "header.php" in your Beaver Builder child theme directory
 * 2. This file will override the parent theme's header
 * 3. The lang="en" attribute is hardcoded on line 2: <html lang="en">
 *
 * PROS:
 * - Pure semantic HTML (no filters, no JavaScript)
 * - One less filter to worry about
 * - Direct control over HTML tag
 *
 * CONS:
 * - Must maintain this file if parent theme updates header structure
 * - Beaver Builder theme might have complex header logic
 * - Could break with major theme updates
 *
 * RECOMMENDATION:
 * Only use this if you want to completely eliminate the language_attributes filter.
 * Otherwise, the filter approach in the main code is safer and more maintainable.
 *
 * NOTE:
 * This is a minimal header that extends the parent theme. The parent theme's
 * header hooks will still run via wp_head(). You're only overriding the
 * initial HTML structure to add the lang attribute.
 */

// Let WordPress and theme know the body tag has been opened
do_action( 'wp_body_open' );

// Load the parent theme's header content if it uses a hook
do_action( 'fl_before_header' );
?>

<?php
// NOTE: Everything else (navigation, logo, etc.) is handled by the parent theme
// via hooks and filters. This minimal header just ensures lang="en" is set.
?>
