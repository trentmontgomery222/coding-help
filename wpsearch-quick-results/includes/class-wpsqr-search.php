<?php
/**
 * The built-in search engine.
 *
 * Queries the index table and ranks by MySQL's own relevance scoring, with
 * the title weighted far above everything else. Where full-text is not
 * available it falls back to LIKE, which cannot rank as well but still beats
 * core search on ordering and partial matching.
 *
 * The query-building here is deliberately pure string work with no database
 * access, so it can be tested on its own — see tests/search-query-test.php.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WPSQR_Search {

	/**
	 * How much more a title match is worth than a body match.
	 *
	 * Five is high on purpose. Someone searching "transportation" wants the
	 * Transportation page, not the twenty pages that mention buses.
	 */
	const TITLE_WEIGHT = 5;

	/**
	 * InnoDB ignores tokens shorter than this by default
	 * (innodb_ft_min_token_size). Anything shorter has to go through LIKE.
	 */
	const MIN_TOKEN = 3;

	/**
	 * Split a search into tokens worth querying.
	 *
	 * @return array{tokens:string[],short:string[]}
	 */
	public static function tokenize( $term ) {
		$term = WPSQR_Normalizer::normalize( $term );

		$tokens = array_values( array_filter( preg_split( '/\s+/u', $term ) ) );

		$long  = array();
		$short = array();

		foreach ( $tokens as $token ) {
			// Boolean-mode operators would otherwise be read as syntax: a
			// search for "c++" or "-19" must not become a query directive.
			$token = preg_replace( '/[+\-><()~*"@]+/u', '', $token );

			if ( '' === $token ) {
				continue;
			}

			if ( mb_strlen( $token ) >= self::MIN_TOKEN ) {
				$long[] = $token;
			} else {
				$short[] = $token;
			}
		}

		return array(
			'tokens' => array_values( array_unique( $long ) ),
			'short'  => array_values( array_unique( $short ) ),
		);
	}

	/**
	 * A boolean-mode expression.
	 *
	 * Every token is required and allowed to match as a prefix, so "aust"
	 * finds "Austin" and "bus route" does not return everything about buses.
	 * Requiring all tokens matters more than it sounds: a two-word search
	 * that ORs its terms returns a great deal of nothing-in-particular.
	 */
	public static function boolean_expression( $tokens ) {
		$parts = array();

		foreach ( $tokens as $token ) {
			$parts[] = '+' . $token . '*';
		}

		return implode( ' ', $parts );
	}

	/**
	 * Run a search.
	 *
	 * @return array{post_ids:int[],total:int}
	 */
	public static function query( $term, $args = array() ) {
		global $wpdb;

		$args = wp_parse_args(
			$args,
			array(
				'page'     => 1,
				'per_page' => 20,
			)
		);

		$parsed = self::tokenize( $term );

		if ( ! $parsed['tokens'] && ! $parsed['short'] ) {
			return array( 'post_ids' => array(), 'total' => 0 );
		}

		// A search made only of short tokens cannot use the full-text index,
		// since MySQL never indexed them.
		$use_fulltext = WPSQR_Index::has_fulltext() && $parsed['tokens'];

		return $use_fulltext
			? self::query_fulltext( $parsed['tokens'], $args )
			: self::query_like( array_merge( $parsed['tokens'], $parsed['short'] ), $args );
	}

	protected static function query_fulltext( $tokens, $args ) {
		global $wpdb;

		$table      = WPSQR_Index::table();
		$expression = self::boolean_expression( $tokens );

		$offset = max( 0, ( (int) $args['page'] - 1 ) * (int) $args['per_page'] );
		$limit  = max( 1, (int) $args['per_page'] );

		// SQL_CALC_FOUND_ROWS is deprecated but still the cheapest way to get
		// a total alongside a page of a scored result set; the alternative is
		// running the whole scoring query twice.
		$sql = $wpdb->prepare(
			"SELECT SQL_CALC_FOUND_ROWS post_id,
				( MATCH(title) AGAINST (%s IN BOOLEAN MODE) * %d )
				+ MATCH(search_text) AGAINST (%s IN BOOLEAN MODE) AS score
			 FROM {$table}
			 WHERE MATCH(search_text) AGAINST (%s IN BOOLEAN MODE)
			 ORDER BY score DESC, post_date DESC
			 LIMIT %d OFFSET %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$expression,
			self::TITLE_WEIGHT,
			$expression,
			$expression,
			$limit,
			$offset
		);

		$ids = $wpdb->get_col( $sql ); // phpcs:ignore WordPress.DB

		$total = (int) $wpdb->get_var( 'SELECT FOUND_ROWS()' ); // phpcs:ignore WordPress.DB

		return array(
			'post_ids' => array_map( 'intval', (array) $ids ),
			'total'    => $total,
		);
	}

	/**
	 * The fallback.
	 *
	 * Scored by hand rather than by MySQL: an exact title, then a title
	 * starting with the term, then a title containing it, then the body.
	 * Crude, but a great deal better than core search's "by date".
	 */
	protected static function query_like( $tokens, $args ) {
		global $wpdb;

		if ( ! $tokens ) {
			return array( 'post_ids' => array(), 'total' => 0 );
		}

		$table  = WPSQR_Index::table();
		$phrase = implode( ' ', $tokens );

		$where  = array();
		$params = array();

		// Every token must appear somewhere, matching the full-text behaviour.
		foreach ( $tokens as $token ) {
			$where[]  = 'search_text LIKE %s';
			$params[] = '%' . $wpdb->esc_like( $token ) . '%';
		}

		$score = 'CASE
			WHEN title = %s THEN 1000
			WHEN title LIKE %s THEN 500
			WHEN title LIKE %s THEN 250
			ELSE 0 END';

		$score_params = array(
			$phrase,
			$wpdb->esc_like( $phrase ) . '%',
			'%' . $wpdb->esc_like( $phrase ) . '%',
		);

		$offset = max( 0, ( (int) $args['page'] - 1 ) * (int) $args['per_page'] );
		$limit  = max( 1, (int) $args['per_page'] );

		$sql = $wpdb->prepare(
			"SELECT SQL_CALC_FOUND_ROWS post_id, ({$score}) AS score
			 FROM {$table}
			 WHERE " . implode( ' AND ', $where ) . "
			 ORDER BY score DESC, post_date DESC
			 LIMIT %d OFFSET %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			array_merge( $score_params, $params, array( $limit, $offset ) )
		);

		$ids   = $wpdb->get_col( $sql ); // phpcs:ignore WordPress.DB
		$total = (int) $wpdb->get_var( 'SELECT FOUND_ROWS()' ); // phpcs:ignore WordPress.DB

		return array(
			'post_ids' => array_map( 'intval', (array) $ids ),
			'total'    => $total,
		);
	}
}
