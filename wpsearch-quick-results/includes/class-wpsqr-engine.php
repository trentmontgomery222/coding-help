<?php
/**
 * Run a search, or avoid running one.
 *
 * The whole point of the plugin lives in search(): on a cache hit nothing is
 * scored, nothing is ranked, and the only work left is fetching the posts by
 * primary key — which WordPress will itself serve from the object cache if
 * one is installed.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WPSQR_Engine {

	/**
	 * @param string $term Raw search term.
	 * @param array  $args page, per_page, engine.
	 * @return array {
	 *     @type int[] post_ids   Ordered, already filtered.
	 *     @type int   total      Total before paging.
	 *     @type bool  cached     Whether this came from the cache.
	 *     @type int   ms         Milliseconds spent.
	 *     @type array blocked    A query rule that suppressed the search, if any.
	 * }
	 */
	public static function search( $term, $args = array() ) {
		$started = microtime( true );

		$args = wp_parse_args(
			$args,
			array(
				'page'     => 1,
				'per_page' => (int) WPSQR_Plugin::settings()['per_page'],
				'engine'   => 'default',
			)
		);

		$normalized = WPSQR_Normalizer::normalize( $term );

		// A blocked term never reaches the search engine at all — the fastest
		// search is the one that doesn't run.
		$blocked = WPSQR_Rules::query_verdict( $normalized );
		if ( $blocked ) {
			return array(
				'post_ids' => array(),
				'total'    => 0,
				'cached'   => true,
				'ms'       => self::elapsed( $started ),
				'blocked'  => $blocked,
			);
		}

		if ( '' === $normalized ) {
			return array(
				'post_ids' => array(),
				'total'    => 0,
				'cached'   => false,
				'ms'       => self::elapsed( $started ),
				'blocked'  => null,
			);
		}

		$viewer = WPSQR_Normalizer::viewer_bucket();
		$key    = WPSQR_Normalizer::key(
			$term,
			array(
				'engine'   => $args['engine'],
				'page'     => $args['page'],
				'per_page' => $args['per_page'],
				'viewer'   => $viewer,
			)
		);

		$hit = WPSQR_Cache::get( $key );

		if ( null !== $hit ) {
			WPSQR_Cache::record_hit( $key );
			WPSQR_Stats::record( $normalized, $term, $hit['total'], 0, true );

			return array(
				'post_ids' => $hit['post_ids'],
				'total'    => $hit['total'],
				'cached'   => true,
				'ms'       => self::elapsed( $started ),
				'blocked'  => null,
			);
		}

		$raw = self::run_uncached( $normalized, $args );

		// Filtering happens before caching, so the expensive part is done once
		// and every later hit serves an already-clean list.
		$filtered = WPSQR_Rules::apply( $raw['post_ids'] );

		$ms = self::elapsed( $started );

		WPSQR_Cache::set(
			$key,
			array(
				'term'     => $normalized,
				'viewer'   => $viewer,
				'page'     => $args['page'],
				'post_ids' => $filtered,
				'total'    => max( 0, $raw['total'] - ( count( $raw['post_ids'] ) - count( $filtered ) ) ),
				'build_ms' => $ms,
			)
		);

		WPSQR_Stats::record( $normalized, $term, count( $filtered ), $ms, false );

		return array(
			'post_ids' => $filtered,
			'total'    => max( 0, $raw['total'] - ( count( $raw['post_ids'] ) - count( $filtered ) ) ),
			'cached'   => false,
			'ms'       => $ms,
			'blocked'  => null,
		);
	}

	/**
	 * The slow path: ask the actual search engine.
	 *
	 * Uses SearchWP when it's available and falls back to core WP search when
	 * it isn't, so the plugin degrades to something that still works rather
	 * than a blank page if SearchWP is deactivated.
	 */
	protected static function run_uncached( $term, $args ) {
		if ( class_exists( '\SearchWP\Query' ) ) {
			return self::run_searchwp( $term, $args );
		}

		return self::run_core( $term, $args );
	}

	protected static function run_searchwp( $term, $args ) {
		try {
			$query = new \SearchWP\Query(
				$term,
				array(
					'engine'  => $args['engine'],
					'fields'  => 'ids',
					'page'    => (int) $args['page'],
					'per_page' => (int) $args['per_page'],
				)
			);

			$ids = $query->get_results();

			return array(
				'post_ids' => is_array( $ids ) ? array_map( 'intval', $ids ) : array(),
				'total'    => (int) $query->found_results,
			);
		} catch ( \Throwable $e ) {
			// A SearchWP upgrade mid-request, a bad engine name, an index
			// rebuild — fall through to core search rather than fatal on a
			// public page.
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( 'WPSQR: SearchWP query failed, falling back to core search: ' . $e->getMessage() ); // phpcs:ignore
			}

			return self::run_core( $term, $args );
		}
	}

	protected static function run_core( $term, $args ) {
		$query = new WP_Query(
			array(
				's'                      => $term,
				'post_type'              => 'any',
				'post_status'            => 'publish',
				'fields'                 => 'ids',
				'posts_per_page'         => (int) $args['per_page'],
				'paged'                  => (int) $args['page'],
				'update_post_term_cache' => false,
				'ignore_sticky_posts'    => true,
			)
		);

		return array(
			'post_ids' => array_map( 'intval', $query->posts ),
			'total'    => (int) $query->found_posts,
		);
	}

	/**
	 * Load the posts for a result set in one query, with caches primed.
	 *
	 * post__in with orderby post__in keeps the relevance order the engine
	 * decided, and a single WP_Query means one round trip however many
	 * results there are.
	 */
	public static function hydrate( $post_ids ) {
		$post_ids = array_values( array_filter( array_map( 'intval', (array) $post_ids ) ) );

		if ( ! $post_ids ) {
			return array();
		}

		$query = new WP_Query(
			array(
				'post__in'               => $post_ids,
				'orderby'                => 'post__in',
				'post_type'              => 'any',
				'post_status'            => array( 'publish', 'private' ),
				'posts_per_page'         => count( $post_ids ),
				'ignore_sticky_posts'    => true,
				'no_found_rows'          => true,
				'update_post_term_cache' => false,
			)
		);

		return $query->posts;
	}

	protected static function elapsed( $started ) {
		return (int) round( ( microtime( true ) - $started ) * 1000 );
	}
}
