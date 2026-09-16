<?php
/**
 * Integration: WPSearch Quick Results — "people" provider.
 *
 * Feeds the site search plugin (WPSearch Quick Results) a list of staff whose
 * NAME matches the query, so a search for a person returns THAT PERSON instead
 * of the directory page's leaking admin text. We only supply data; the search
 * plugin owns caching, rendering, escaping and layout.
 *
 * Contract version 1 — hooks:
 *   - filter  wpsqr_people_search      → return matched, public-only people
 *   - filter  wpsqr_people_providers   → identify ourselves on their dashboard
 *   - action  wpsqr_people_changed     → we FIRE this when our data changes
 *
 * FAILSAFE by design:
 *   - If the search plugin is not installed, our filters simply never run —
 *     nothing here depends on it, no notices, no disabled features.
 *   - Every callback is wrapped in try/catch(\Throwable) and can only ever
 *     return the array it was given, so a bug here can never break search or
 *     white-screen the site.
 *   - Visibility is applied FIRST, to a public-only list, and we never search
 *     anything but that list — a mistake later cannot reveal a hidden person.
 *
 * This file is required from the main plugin (guarded by is_readable). It uses
 * the directory's own data helpers, which are always loaded by then.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'CAYDENDIR_SD_SEARCH_CONTRACT' ) ) {
	define( 'CAYDENDIR_SD_SEARCH_CONTRACT', 1 );
}

/* -------------------------------------------------------------------------
 * Register the hooks. All three are cheap to add and harmless when the
 * search plugin is absent (the filters just never fire).
 * ---------------------------------------------------------------------- */
add_filter( 'wpsqr_people_search', 'CAYDENDIR_sd_wpsqr_people', 10, 2 );
add_filter( 'wpsqr_people_providers', 'CAYDENDIR_sd_wpsqr_provider', 10, 1 );

// Fire wpsqr_people_changed whenever our directory data changes (add / edit /
// delete / hide / unhide all end as a write to one of these two options), so
// the search plugin's 10-minute people cache is invalidated promptly. Hooking
// the option writes centrally means we never have to touch each save site.
add_action( 'added_option',   'CAYDENDIR_sd_wpsqr_option_changed', 10, 1 );
add_action( 'updated_option', 'CAYDENDIR_sd_wpsqr_option_changed', 10, 1 );
add_action( 'deleted_option', 'CAYDENDIR_sd_wpsqr_option_changed', 10, 1 );

/** Announce ourselves so their dashboard shows the integration is live. */
function CAYDENDIR_sd_wpsqr_provider( $providers ) {
	try {
		if ( ! is_array( $providers ) ) {
			$providers = array();
		}
		$providers[] = array(
			'name'     => 'Cayden Staff Directory',
			'version'  => defined( 'CAYDENDIR_SD_VERSION' ) ? CAYDENDIR_SD_VERSION : '',
			'contract' => CAYDENDIR_SD_SEARCH_CONTRACT,
		);
	} catch ( \Throwable $e ) {
		CAYDENDIR_sd_log( 'wpsqr provider', $e );
	}
	return $providers;
}

/** Fire the "data changed" action when one of our two data options is written. */
function CAYDENDIR_sd_wpsqr_option_changed( $option ) {
	try {
		$ours = array();
		if ( defined( 'CAYDENDIR_SD_DATA_OPTION' ) ) {
			$ours[] = CAYDENDIR_SD_DATA_OPTION;
		}
		if ( defined( 'CAYDENDIR_SD_MANUAL_OPTION' ) ) {
			$ours[] = CAYDENDIR_SD_MANUAL_OPTION;
		}
		if ( in_array( (string) $option, $ours, true ) && function_exists( 'do_action' ) ) {
			do_action( 'wpsqr_people_changed' );
		}
	} catch ( \Throwable $e ) {
		CAYDENDIR_sd_log( 'wpsqr option changed', $e );
	}
}

/**
 * Normalize a string for name matching: strip accents, lowercase, collapse
 * whitespace. Uses WordPress' remove_accents() when available.
 */
function CAYDENDIR_sd_search_norm( $s ) {
	$s = (string) $s;
	// Lowercase first, then strip accents: a lowercase accent map collapses both
	// "Á" and "á" to "a", so case never leaves an accent behind.
	$s = function_exists( 'mb_strtolower' ) ? mb_strtolower( $s, 'UTF-8' ) : strtolower( $s );
	if ( function_exists( 'remove_accents' ) ) {
		$s = remove_accents( $s );
	}
	$s = preg_replace( '/\s+/u', ' ', $s );
	return trim( (string) $s );
}

/**
 * Rank a person against a normalized query. Lower is a better match; -1 means
 * no match. NAME FIELDS ONLY — first, last, full — never job/location/tags, so
 * a job-title search like "warehouse driver" matches nobody.
 *
 * @param string   $q     Normalized query.
 * @param string[] $qt    Normalized query tokens.
 * @param string   $qr    Normalized reversed query ("last first" → "first last").
 * @param string   $first Normalized first name.
 * @param string   $last  Normalized last name.
 * @param string   $full  Normalized full name.
 */
function CAYDENDIR_sd_search_rank( $q, $qt, $qr, $first, $last, $full ) {
	if ( '' === $q ) {
		return -1;
	}
	// Exact full name (either word order).
	if ( $full === $q || ( '' !== $qr && $full === $qr ) ) {
		return 0;
	}
	// Exact single-part matches.
	if ( '' !== $last && $last === $q ) {
		return 1;
	}
	if ( '' !== $first && $first === $q ) {
		return 2;
	}
	// Prefix on either name part.
	if ( ( '' !== $first && 0 === strpos( $first, $q ) ) || ( '' !== $last && 0 === strpos( $last, $q ) ) ) {
		return 3;
	}
	// Two-part query: each token is a prefix of a name part, either order.
	if ( 2 === count( $qt ) && '' !== $first && '' !== $last ) {
		$a = $qt[0];
		$b = $qt[1];
		if ( ( 0 === strpos( $first, $a ) && 0 === strpos( $last, $b ) ) ||
		     ( 0 === strpos( $first, $b ) && 0 === strpos( $last, $a ) ) ) {
			return 3;
		}
	}
	// Substring anywhere in the full name (either word order).
	if ( false !== strpos( $full, $q ) || ( '' !== $qr && false !== strpos( $full, $qr ) ) ) {
		return 4;
	}
	// Substring within a single name part.
	if ( ( '' !== $first && false !== strpos( $first, $q ) ) || ( '' !== $last && false !== strpos( $last, $q ) ) ) {
		return 4;
	}
	return -1;
}

/**
 * The people-search provider.
 *
 * @param array $people Starts empty; we append matches (and defensively keep
 *                      whatever was passed).
 * @param array $args   query, limit, fields, viewer.
 * @return array
 */
function CAYDENDIR_sd_wpsqr_people( $people, $args = array() ) {
	try {
		if ( ! is_array( $people ) ) {
			$people = array();
		}
		if ( ! function_exists( 'CAYDENDIR_sd_get_merged_data' ) ) {
			return $people; // directory core not loaded — nothing to add.
		}

		$args  = is_array( $args ) ? $args : array();
		$query = isset( $args['query'] ) ? trim( (string) $args['query'] ) : '';
		$limit = isset( $args['limit'] ) ? (int) $args['limit'] : 20;
		$limit = $limit > 0 ? $limit : 20;

		// The search plugin already filters terms under 3 chars, but never trust
		// that — a short query would match half the directory by prefix.
		$qlen = function_exists( 'mb_strlen' ) ? mb_strlen( $query, 'UTF-8' ) : strlen( $query );
		if ( $qlen < 3 ) {
			return $people;
		}

		$q  = CAYDENDIR_sd_search_norm( $query );
		if ( '' === $q ) {
			return $people;
		}
		$qt = array_values( array_filter( explode( ' ', $q ), 'strlen' ) );
		$qr = ( 2 === count( $qt ) ) ? ( $qt[1] . ' ' . $qt[0] ) : '';

		// VISIBILITY FIRST: build a public-only list and only ever search that.
		// We ignore $viewer and always return the visitor-safe set — showing an
		// editor slightly less is cosmetic; the reverse is a disclosure.
		$all    = CAYDENDIR_sd_get_merged_data();
		$all    = is_array( $all ) ? $all : array();
		$scored = array();

		foreach ( $all as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			if ( isset( $row['hidden'] ) && '1' === (string) $row['hidden'] ) {
				continue; // hidden from the public — never searchable here.
			}
			$name = isset( $row['name'] ) ? trim( (string) $row['name'] ) : '';
			if ( '' === $name ) {
				$name = trim( ( isset( $row['firstname'] ) ? $row['firstname'] : '' ) . ' ' . ( isset( $row['lastname'] ) ? $row['lastname'] : '' ) );
			}
			if ( '' === $name ) {
				continue; // no name → not a result.
			}

			$first = CAYDENDIR_sd_search_norm( isset( $row['firstname'] ) ? $row['firstname'] : '' );
			$last  = CAYDENDIR_sd_search_norm( isset( $row['lastname'] ) ? $row['lastname'] : '' );
			$full  = CAYDENDIR_sd_search_norm( $name );

			$rank = CAYDENDIR_sd_search_rank( $q, $qt, $qr, $first, $last, $full );
			if ( $rank < 0 ) {
				continue;
			}
			$scored[] = array( 'rank' => $rank, 'name' => $name, 'row' => $row );
		}

		if ( empty( $scored ) ) {
			return $people;
		}

		// Best matches first; ties broken alphabetically by name. A stable sort
		// keeps the directory's own ordering within an equal rank+name.
		usort( $scored, function ( $a, $b ) {
			if ( $a['rank'] !== $b['rank'] ) {
				return $a['rank'] < $b['rank'] ? -1 : 1;
			}
			return strcasecmp( $a['name'], $b['name'] );
		} );

		$dir_url = CAYDENDIR_sd_directory_url();
		$count   = 0;

		foreach ( $scored as $hit ) {
			if ( $count >= $limit ) {
				break;
			}
			$row  = $hit['row'];
			$name = $hit['name'];

			// A stable, non-empty record id: the visible ID if present, else the
			// merge key the directory already computed for this person.
			$id = isset( $row['id'] ) ? trim( (string) $row['id'] ) : '';
			if ( '' === $id ) {
				$id = isset( $row['_key'] ) ? (string) $row['_key'] : ( 'nm:' . md5( $name ) );
			}

			$person = array(
				'id'   => $id,
				'name' => $name,
			);

			// A deep link that lands on this person: the directory page with the
			// name pre-filled into its search box (the JS reads ?sd_name=). Only
			// added when we could find a directory page — otherwise the name
			// renders as plain text, which is still a correct result.
			if ( '' !== $dir_url ) {
				$person['url'] = add_query_arg( 'sd_name', rawurlencode( $name ), $dir_url );
			}

			$job = isset( $row['publictitle'] ) ? trim( (string) $row['publictitle'] ) : '';
			if ( '' === $job && isset( $row['job'] ) ) {
				$job = trim( (string) $row['job'] );
			}
			if ( '' !== $job ) {
				$person['job_title'] = $job;
			}
			if ( isset( $row['job'] ) && '' !== trim( (string) $row['job'] ) ) {
				$person['department'] = trim( (string) $row['job'] );
			}
			if ( isset( $row['location'] ) && '' !== trim( (string) $row['location'] ) ) {
				$person['location'] = trim( (string) $row['location'] );
			}
			if ( isset( $row['photo'] ) && '' !== trim( (string) $row['photo'] ) ) {
				$person['photo'] = trim( (string) $row['photo'] );
			}
			// Email is opt-in on the search side (defaults off there); we send it
			// if we have it and let the site decide whether to display it.
			if ( isset( $row['email'] ) && '' !== trim( (string) $row['email'] ) ) {
				$person['email'] = trim( (string) $row['email'] );
			}

			$people[] = $person;
			$count++;
		}
	} catch ( \Throwable $e ) {
		CAYDENDIR_sd_log( 'wpsqr people search', $e );
	}

	return $people;
}

/**
 * Best-effort URL of the page that hosts the directory, so search results can
 * deep-link to it. Cached for a day. Returns '' when none is found (a bad or
 * missing lookup must never break the search — the name just renders as text).
 *
 * Order of preference:
 *   1. A URL supplied via the 'CAYDENDIR_sd_directory_url' filter or the
 *      CAYDENDIR_SD_DIRECTORY_URL constant (explicit admin control).
 *   2. Auto-discovery: the first published page/post whose content contains the
 *      directory shortcode or block.
 */
function CAYDENDIR_sd_directory_url() {
	try {
		// Explicit override wins and is not cached (cheap, and always current).
		$override = '';
		if ( defined( 'CAYDENDIR_SD_DIRECTORY_URL' ) && is_string( CAYDENDIR_SD_DIRECTORY_URL ) ) {
			$override = (string) CAYDENDIR_SD_DIRECTORY_URL;
		}
		if ( function_exists( 'apply_filters' ) ) {
			$override = (string) apply_filters( 'CAYDENDIR_sd_directory_url', $override );
		}
		if ( '' !== $override ) {
			return esc_url_raw( $override );
		}

		$cache_key = 'CAYDENDIR_sd_dir_url';
		if ( function_exists( 'get_transient' ) ) {
			$cached = get_transient( $cache_key );
			if ( is_string( $cached ) ) {
				return $cached; // '' is a valid cached "known none".
			}
		}

		$url = '';
		global $wpdb;
		if ( isset( $wpdb ) && is_object( $wpdb ) && method_exists( $wpdb, 'get_var' ) ) {
			$like_sc = '%' . $wpdb->esc_like( 'CAYDENDIR_staff_directory' ) . '%';
			$like_bl = '%' . $wpdb->esc_like( 'caydendir/staff-directory' ) . '%';
			$id = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT ID FROM {$wpdb->posts}
					  WHERE post_status = 'publish'
					    AND post_type IN ('page','post')
					    AND ( post_content LIKE %s OR post_content LIKE %s )
					  ORDER BY ( post_type = 'page' ) DESC, ID ASC
					  LIMIT 1",
					$like_sc,
					$like_bl
				)
			);
			if ( $id && function_exists( 'get_permalink' ) ) {
				$perma = get_permalink( (int) $id );
				if ( is_string( $perma ) ) {
					$url = $perma;
				}
			}
		}

		if ( function_exists( 'set_transient' ) ) {
			$ttl = defined( 'DAY_IN_SECONDS' ) ? DAY_IN_SECONDS : 86400;
			set_transient( $cache_key, (string) $url, $ttl );
		}
		return (string) $url;
	} catch ( \Throwable $e ) {
		CAYDENDIR_sd_log( 'wpsqr directory url', $e );
		return '';
	}
}
