<?php
/**
 * The rule engine, server side.
 *
 * Rules are stored in exactly the shape the browser engine uses, so one rule
 * is understood in both places and there is no translation layer to drift.
 *
 * The split of labour:
 *
 *   Server  hide, keep — removing a result is a correctness and privacy
 *           matter, so it happens before the markup is built and the hidden
 *           item never reaches the browser.
 *   Browser dim, rewrite, badge, top, bottom — presentation, and things that
 *           have to react to markup the server never produced (live search
 *           re-renders, SearchWP's own template).
 *
 * A `hide` rule on `excerpt` or `text` is the one case the server cannot
 * always judge, because the excerpt it would build may not be the excerpt the
 * template shows. Those are left to the browser and flagged in the UI.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WPSQR_Rules {

	const FIELDS = array( 'title', 'url', 'id', 'type', 'excerpt', 'text' );
	const OPS    = array( 'contains', 'equals', 'starts', 'ends', 'regex', 'in' );
	const ACTIONS = array( 'hide', 'dim', 'rewrite', 'setDesc', 'badge', 'top', 'bottom', 'keep' );

	/** Fields the server can evaluate reliably. */
	const SERVER_FIELDS = array( 'title', 'url', 'id', 'type' );

	/** Actions the server carries out; the rest are the browser's. */
	const SERVER_ACTIONS = array( 'hide', 'keep' );

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

		// Editors keep seeing hidden items, marked, so they can find and fix
		// them. Everyone else never receives them.
		$show_hidden = $settings['admin_preview'] && current_user_can( 'edit_posts' );

		$rules = self::server_rules( $settings );
		$kept  = array();

		foreach ( $post_ids as $post_id ) {
			$post_id = (int) $post_id;

			if ( $show_hidden ) {
				$kept[] = $post_id;
				continue;
			}

			if ( isset( $hidden[ $post_id ] ) ) {
				continue;
			}

			if ( 'hide' === self::verdict( $post_id, $rules ) ) {
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

	/** Only the rules this side can act on. */
	protected static function server_rules( $settings ) {
		$rules = array();

		foreach ( (array) $settings['result_rules'] as $rule ) {
			$when = isset( $rule['when'] ) ? $rule['when'] : 'title';
			$then = isset( $rule['then'] ) ? $rule['then'] : 'hide';

			if ( in_array( $when, self::SERVER_FIELDS, true ) && in_array( $then, self::SERVER_ACTIONS, true ) ) {
				$rules[] = $rule;
			}
		}

		return $rules;
	}

	/**
	 * Run the rules against one post.
	 *
	 * Same precedence as the browser: rules run in order and `keep` or `hide`
	 * is final, so an early `keep` protects a result from everything below it.
	 *
	 * @return string 'hide', 'keep' or ''.
	 */
	protected static function verdict( $post_id, $rules ) {
		if ( ! $rules ) {
			return '';
		}

		$fields = self::fields( $post_id );

		foreach ( $rules as $rule ) {
			$when    = isset( $rule['when'] ) ? $rule['when'] : 'title';
			$subject = isset( $fields[ $when ] ) ? $fields[ $when ] : null;

			if ( null === $subject ) {
				continue;
			}

			if ( ! self::matches( $subject, $rule ) ) {
				continue;
			}

			$then = isset( $rule['then'] ) ? $rule['then'] : 'hide';

			if ( 'keep' === $then || 'hide' === $then ) {
				return $then;
			}
		}

		return '';
	}

	/** The comparable values for a post, matching the browser's field names. */
	protected static function fields( $post_id ) {
		$path = (string) wp_parse_url( get_permalink( $post_id ), PHP_URL_PATH );
		$path = strtolower( $path );

		// Pages carry a trailing slash so '/enrollment/' matches a top-level
		// page; file links keep their extension so '.pdf$' still works. The
		// browser engine does exactly the same thing.
		$last = substr( $path, strrpos( $path, '/' ) + 1 );
		if ( '' !== $path && '/' !== substr( $path, -1 ) && false === strpos( $last, '.' ) ) {
			$path .= '/';
		}

		return array(
			'title' => strtolower( (string) get_the_title( $post_id ) ),
			'url'   => $path,
			'id'    => (string) (int) $post_id,
			'type'  => strtolower( (string) get_post_type( $post_id ) ),
		);
	}

	/**
	 * One comparison routine, mirroring the browser's.
	 *
	 * @param string $subject Already lower-cased.
	 */
	public static function matches( $subject, $rule ) {
		$op    = isset( $rule['op'] ) ? $rule['op'] : 'contains';
		$value = isset( $rule['value'] ) ? $rule['value'] : '';

		if ( 'in' === $op ) {
			$list = is_array( $value ) ? $value : explode( ',', (string) $value );

			foreach ( $list as $item ) {
				if ( self::matches( $subject, array( 'op' => 'equals', 'value' => trim( (string) $item ) ) ) ) {
					return true;
				}
			}

			return false;
		}

		if ( 'regex' === $op ) {
			$pattern = '/' . str_replace( '/', '\/', (string) $value ) . '/iu';

			// A bad pattern from the settings screen must not warn on a public
			// page, so errors are suppressed and treated as "no match".
			$result = @preg_match( $pattern, $subject ); // phpcs:ignore WordPress.PHP.NoSilencedErrors

			return 1 === $result;
		}

		$needle = strtolower( trim( (string) $value ) );

		if ( '' === $needle ) {
			return false;
		}

		switch ( $op ) {
			case 'equals':
				return $subject === $needle;
			case 'starts':
				return 0 === strpos( $subject, $needle );
			case 'ends':
				return substr( $subject, -strlen( $needle ) ) === $needle;
			case 'contains':
			default:
				return false !== strpos( $subject, $needle );
		}
	}

	/**
	 * Does a query rule act on this search?
	 *
	 * @return array|null The matching rule, or null. 'allow' returns null —
	 *                    an allow rule exists precisely to mean "do nothing".
	 */
	public static function query_verdict( $term ) {
		$settings = WPSQR_Plugin::settings();
		$term     = strtolower( trim( (string) $term ) );

		if ( '' === $term ) {
			return null;
		}

		foreach ( (array) $settings['query_rules'] as $rule ) {
			if ( ! self::matches( $term, $rule ) ) {
				continue;
			}

			$then = isset( $rule['then'] ) ? $rule['then'] : 'noResults';

			// First match wins, including an 'allow' that shields a term from
			// a broader rule below it.
			return 'allow' === $then ? null : $rule;
		}

		return null;
	}

	/** Rules handed to the browser: everything, since it re-checks its own. */
	public static function for_browser() {
		$settings = WPSQR_Plugin::settings();

		$result = array();

		foreach ( (array) $settings['result_rules'] as $rule ) {
			// A server-side hide already removed the post, so re-sending the
			// rule would only make the browser look for something that is not
			// there. Keep rules it alone can act on.
			$when = isset( $rule['when'] ) ? $rule['when'] : 'title';
			$then = isset( $rule['then'] ) ? $rule['then'] : 'hide';

			$handled_server_side = in_array( $when, self::SERVER_FIELDS, true )
				&& in_array( $then, self::SERVER_ACTIONS, true );

			if ( ! $handled_server_side ) {
				$result[] = $rule;
			}
		}

		return array(
			'result' => array_values( $result ),
			'query'  => array_values( (array) $settings['query_rules'] ),
		);
	}
}
