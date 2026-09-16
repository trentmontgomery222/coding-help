<?php
/**
 * Taking over the site's own search.
 *
 * With no SearchWP installed, /?s=term goes to the theme's search template and
 * WordPress's own search. This replaces the results of that query with the
 * plugin's, so an ordinary theme search page gets ranked, cached, filtered
 * results without the theme being touched.
 *
 * Done through posts_pre_query, which hands WordPress a finished list and
 * skips its query entirely. The alternative — rewriting the query with
 * post__in — still runs the original search first and throws the work away.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WPSQR_Native {

	/** Set while serving, so nothing re-enters. */
	protected static $running = false;

	public function hooks() {
		add_filter( 'posts_pre_query', array( $this, 'serve' ), 10, 2 );
	}

	public function should_handle( $query ) {
		if ( self::$running || is_admin() ) {
			return false;
		}

		if ( ! $query instanceof WP_Query || ! $query->is_main_query() || ! $query->is_search() ) {
			return false;
		}

		// Feeds and REST get whatever WordPress would normally give them:
		// replacing results there changes an API's behaviour, which is not
		// what "make site search better" asked for.
		if ( $query->is_feed() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return false;
		}

		return WPSQR_Plugin::engine_is_builtin();
	}

	/**
	 * @param array|null $posts Null means "carry on"; an array short-circuits.
	 * @param WP_Query   $query
	 * @return array|null
	 */
	public function serve( $posts, $query ) {
		if ( ! $this->should_handle( $query ) ) {
			return $posts;
		}

		$term = (string) $query->get( 's' );

		if ( '' === trim( $term ) ) {
			return $posts;
		}

		self::$running = true;

		try {
			$per_page = (int) $query->get( 'posts_per_page' );

			if ( $per_page < 1 ) {
				$per_page = (int) get_option( 'posts_per_page', 10 );
			}

			$page = max( 1, (int) $query->get( 'paged' ) );

			$results = WPSQR_Engine::search(
				$term,
				array(
					'page'     => $page,
					'per_page' => $per_page,
				)
			);

			// A blocked search term returns an empty page rather than falling
			// through to WordPress, which would defeat the point of blocking.
			$found = (int) $results['total'];

			$query->found_posts   = $found;
			$query->max_num_pages = $per_page > 0 ? (int) ceil( $found / $per_page ) : 0;

			return WPSQR_Engine::hydrate( $results['post_ids'] );
		} catch ( \Throwable $e ) {
			// Search is not worth a white screen. Hand the query back and let
			// WordPress do what it always did.
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( 'WPSQR: native search failed, falling back to core: ' . $e->getMessage() ); // phpcs:ignore
			}

			return $posts;
		} finally {
			self::$running = false;
		}
	}
}
