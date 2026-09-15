<?php
/**
 * Server-side result rules — the PHP half of the rule engine that WPCode
 * snippet #2 runs in the browser.
 *
 * The same rule shape is used in both places, so a rule written once is
 * understood by both. This half runs first and removes; the browser half
 * handles presentation (rewrites, badges, reordering) and anything that has
 * to react to markup the server never saw.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WPSQR_Rules {

	/**
	 * Filter a list of post IDs down to what should be shown.
	 *
	 * @param int[] $post_ids
	 * @return int[]
	 */
	public static function apply( $post_ids ) {
		$settings = WPSQR_Plugin::settings();
		$map      = WPSQR_Hidden::map();
		$hidden   = array_flip( $map['ids'] );

		// Editors see hidden items (marked in the markup) so they can find and
		// fix them. Everyone else never receives them.
		$show_hidden = $settings['admin_preview'] && current_user_can( 'edit_posts' );

		$kept = array();

		foreach ( $post_ids as $post_id ) {
			$post_id = (int) $post_id;

			if ( isset( $hidden[ $post_id ] ) && ! $show_hidden ) {
				continue;
			}

			if ( self::blocked_by_rule( $post_id, $settings ) && ! $show_hidden ) {
				continue;
			}

			$kept[] = $post_id;
		}

		/**
		 * Last word on which results survive.
		 *
		 * @param int[] $kept
		 * @param int[] $post_ids
		 */
		return apply_filters( 'wpsqr_filter_results', $kept, $post_ids );
	}

	/** Does any configured rule remove this post? */
	protected static function blocked_by_rule( $post_id, $settings ) {
		$post_type = get_post_type( $post_id );

		if ( in_array( $post_type, (array) $settings['block_types'], true ) ) {
			return true;
		}

		$path  = strtolower( (string) wp_parse_url( get_permalink( $post_id ), PHP_URL_PATH ) );
		$title = strtolower( (string) get_the_title( $post_id ) );

		foreach ( (array) $settings['block_urls'] as $needle ) {
			$needle = strtolower( trim( $needle ) );
			if ( '' !== $needle && false !== strpos( $path, $needle ) ) {
				return true;
			}
		}

		foreach ( (array) $settings['block_titles'] as $needle ) {
			$needle = strtolower( trim( $needle ) );
			if ( '' !== $needle && false !== strpos( $title, $needle ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Does a query rule block this search outright?
	 *
	 * @return array|null The matching rule, or null.
	 */
	public static function query_verdict( $term ) {
		$settings = WPSQR_Plugin::settings();
		$term     = strtolower( trim( (string) $term ) );

		if ( '' === $term ) {
			return null;
		}

		foreach ( (array) $settings['blocked_queries'] as $rule ) {
			$value = strtolower( trim( (string) $rule['value'] ) );
			if ( '' === $value ) {
				continue;
			}

			$matched = ( 'equals' === $rule['op'] )
				? ( $term === $value )
				: ( false !== strpos( $term, $value ) );

			if ( $matched ) {
				return $rule;
			}
		}

		return null;
	}
}
