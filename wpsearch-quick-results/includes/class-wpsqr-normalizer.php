<?php
/**
 * Turns what someone typed into a stable cache key.
 *
 * This is where most of the cache hit rate comes from. "Staff", "staff ",
 * "STAFF" and "the staff" are one search as far as a visitor is concerned,
 * and treating them as four different cache entries would waste most of the
 * benefit. Everything here is pure string work with no database access, so it
 * can be tested on its own — see tests/normalizer-test.php.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WPSQR_Normalizer {

	/**
	 * Words dropped before hashing. Deliberately short: an aggressive
	 * stopword list merges searches that genuinely differ.
	 */
	const STOPWORDS = array( 'a', 'an', 'the', 'of', 'for', 'to', 'in', 'on', 'at', 'and', 'or' );

	/**
	 * Normalize a raw search term.
	 *
	 * @param string $term Raw user input.
	 * @return string Normalized form, '' if nothing usable remains.
	 */
	public static function normalize( $term ) {
		$term = (string) $term;

		// Decode entities first, so "&amp;" and "&" are the same search.
		$term = html_entity_decode( $term, ENT_QUOTES, 'UTF-8' );
		$term = wp_strip_all_tags( $term );

		// Curly quotes and dashes that a phone keyboard inserts.
		$term = strtr(
			$term,
			array(
				'“' => '"', '”' => '"', '‘' => "'", '’' => "'",
				'–' => '-', '—' => '-', ' ' => ' ',
			)
		);

		$term = function_exists( 'mb_strtolower' ) ? mb_strtolower( $term, 'UTF-8' ) : strtolower( $term );

		// Punctuation to spaces, but keep digits, letters, hyphens between
		// word characters (title-ix, 2026-2027) and apostrophes inside words.
		$term = preg_replace( '/[^\p{L}\p{N}\s\'\-]+/u', ' ', $term );
		$term = preg_replace( '/(?<![\p{L}\p{N}])[\'\-]+|[\'\-]+(?![\p{L}\p{N}])/u', ' ', $term );

		$term = preg_replace( '/\s+/u', ' ', $term );
		$term = trim( $term );

		if ( '' === $term ) {
			return '';
		}

		$words = explode( ' ', $term );

		// Strip stopwords, but never strip the whole thing away: a search for
		// "the" should still be a search for "the".
		$kept = array_values( array_diff( $words, self::STOPWORDS ) );
		if ( $kept ) {
			$words = $kept;
		}

		$words = self::apply_synonyms( $words );

		/**
		 * Filter the normalized words before they are joined and hashed.
		 *
		 * @param string[] $words
		 * @param string   $original
		 */
		$words = apply_filters( 'wpsqr_normalized_words', $words, $term );

		return implode( ' ', $words );
	}

	/**
	 * Fold known equivalents together so they share one cache entry.
	 *
	 * Kept small and explicit rather than clever. Stemming would merge
	 * "policy"/"policies" automatically but would also merge things that
	 * should stay apart, and it is not worth the surprise.
	 */
	public static function apply_synonyms( $words ) {
		$map = apply_filters(
			'wpsqr_synonyms',
			array(
				'staffs'     => 'staff',
				'employees'  => 'staff',
				'employee'   => 'staff',
				'calendars'  => 'calendar',
				'menus'      => 'menu',
				'lunches'    => 'lunch',
				'policies'   => 'policy',
				'schools'    => 'school',
				'jobs'       => 'job',
				'careers'    => 'job',
				'enrol'      => 'enroll',
				'enrollment' => 'enroll',
				'enrolment'  => 'enroll',
			)
		);

		foreach ( $words as $i => $word ) {
			if ( isset( $map[ $word ] ) ) {
				$words[ $i ] = $map[ $word ];
			}
		}

		return $words;
	}

	/**
	 * The cache key for a term, engine, page and viewer bucket.
	 *
	 * The viewer bucket matters: an editor sees results a visitor must not,
	 * so the two can never share a cache entry.
	 */
	public static function key( $term, $args = array() ) {
		$args = wp_parse_args(
			$args,
			array(
				'engine'   => 'default',
				'page'     => 1,
				'per_page' => 10,
				'viewer'   => 'visitor',
			)
		);

		$parts = array(
			self::normalize( $term ),
			(string) $args['engine'],
			(int) $args['page'],
			(int) $args['per_page'],
			(string) $args['viewer'],
			(string) WPSQR_DB_VERSION,
		);

		return hash( 'sha256', implode( '|', $parts ) );
	}

	/**
	 * Which cache bucket the current user belongs to.
	 *
	 * Two buckets only. Per-user caching would be nearly useless — each user
	 * would get their own entry and almost never hit it.
	 */
	public static function viewer_bucket() {
		return current_user_can( 'edit_posts' ) ? 'editor' : 'visitor';
	}
}
