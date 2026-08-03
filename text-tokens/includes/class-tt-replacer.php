<?php
/**
 * Hooks token replacement into everywhere text is rendered.
 *
 * @package TextTokens
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class TT_Replacer
 *
 * Registers the WordPress (and Beaver Builder) filters that run the token
 * swap on visible output.
 */
class TT_Replacer {

	/**
	 * Attach replacement to the relevant render filters.
	 *
	 * @return void
	 */
	public function register_hooks() {
		// Standard post/page content and excerpts.
		add_filter( 'the_content', array( $this, 'filter' ), 20 );
		add_filter( 'the_excerpt', array( $this, 'filter' ), 20 );

		// Titles (posts, pages, and the document <title>).
		add_filter( 'the_title', array( $this, 'filter' ), 20 );
		add_filter( 'single_post_title', array( $this, 'filter' ), 20 );
		add_filter( 'document_title_parts', array( $this, 'filter_title_parts' ), 20 );

		// Text widgets and legacy widget titles.
		add_filter( 'widget_text', array( $this, 'filter' ), 20 );
		add_filter( 'widget_text_content', array( $this, 'filter' ), 20 );
		add_filter( 'widget_title', array( $this, 'filter' ), 20 );
		add_filter( 'widget_block_content', array( $this, 'filter' ), 20 );

		// Navigation menu item labels.
		add_filter( 'nav_menu_item_title', array( $this, 'filter' ), 20 );

		// Beaver Builder renders its layouts outside of the_content, so hook
		// its own output filters explicitly. These run only when BB is active.
		add_filter( 'fl_builder_render_content', array( $this, 'filter' ), 20 );
		add_filter( 'fl_builder_before_render_module', array( $this, 'noop_passthrough' ) );

		/**
		 * Allow other integrations to register the replacer against additional
		 * filters without editing the plugin. Callback signature: ( TT_Replacer $replacer ).
		 */
		do_action( 'tt_register_replacer', $this );
	}

	/**
	 * Generic string filter callback.
	 *
	 * @param mixed $content Filtered value; only strings are processed.
	 * @return mixed
	 */
	public function filter( $content ) {
		if ( ! is_string( $content ) ) {
			return $content;
		}
		return TT_Resolver::replace( $content );
	}

	/**
	 * Filter the document title parts array.
	 *
	 * @param array $parts Title parts.
	 * @return array
	 */
	public function filter_title_parts( $parts ) {
		if ( ! is_array( $parts ) ) {
			return $parts;
		}
		foreach ( $parts as $key => $value ) {
			if ( is_string( $value ) ) {
				$parts[ $key ] = TT_Resolver::replace( $value );
			}
		}
		return $parts;
	}

	/**
	 * Pass-through used purely to guarantee BB module hooks resolve without
	 * altering behavior; kept explicit for clarity of the BB integration point.
	 *
	 * @param mixed $value Value.
	 * @return mixed
	 */
	public function noop_passthrough( $value ) {
		return $value;
	}
}
