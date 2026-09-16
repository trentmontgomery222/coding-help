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
 *   - filter  wpsqr_people_search         → matched, public-only people
 *   - filter  wpsqr_people_matches_hidden → bool: does the term match a HIDDEN
 *                                           person? (server-side only; no names)
 *   - filter  wpsqr_people_more_url       → our directory page for the "more" link
 *   - filter  wpsqr_directory_pages       → name our directory page so the search
 *                                           plugin can drop it from name searches
 *   - filter  wpsqr_people_providers      → identify ourselves on their dashboard
 *   - action  wpsqr_people_changed        → we FIRE this when our data changes
 *
 * FAILSAFE by design:
 *   - If the search plugin is not installed, our filters simply never run —
 *     nothing here depends on it, no notices, no disabled features.
 *   - Every callback is wrapped in try/catch(\Throwable) and can only ever
 *     return the value it was given, so a bug here can never break search or
 *     white-screen the site.
 *   - Visibility is applied per-row against the same flag the public listing
 *     uses; the visible search and the hidden-match check share ONE matching
 *     function, so they can never drift apart.
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
 * Register the hooks. All are cheap to add and harmless when the search
 * plugin is absent (the filters just never fire).
 * ---------------------------------------------------------------------- */
add_filter( 'wpsqr_people_search',         'CAYDENDIR_sd_wpsqr_people', 10, 2 );
add_filter( 'wpsqr_people_matches_hidden', 'CAYDENDIR_sd_wpsqr_matches_hidden', 10, 2 );
add_filter( 'wpsqr_people_more_url',       'CAYDENDIR_sd_wpsqr_more_url', 10, 2 );
add_filter( 'wpsqr_directory_pages',       'CAYDENDIR_sd_wpsqr_directory_pages', 10, 1 );
add_filter( 'wpsqr_people_providers',      'CAYDENDIR_sd_wpsqr_provider', 10, 1 );

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

/* =========================================================================
 * Name matching — shared by the visible search and the hidden-match check.
 * ====================================================================== */

/**
 * Normalize a string for name matching: lowercase, strip accents, collapse
 * whitespace. Lowercasing first means a lowercase accent map collapses both
 * "Á" and "á" to "a", so case never leaves an accent behind.
 */
function CAYDENDIR_sd_search_norm( $s ) {
	$s = (string) $s;
	$s = function_exists( 'mb_strtolower' ) ? mb_strtolower( $s, 'UTF-8' ) : strtolower( $s );
	if ( function_exists( 'remove_accents' ) ) {
		$s = remove_accents( $s );
	}
	$s = preg_replace( '/\s+/u', ' ', $s );
	return trim( (string) $s );
}

/**
 * Prepare a raw query for matching. Returns array( $q, $qt, $qr ) or null when
 * the query is too short or empty — the search plugin already drops terms under
 * 3 chars, but we never trust that (a short query would match by prefix).
 */
function CAYDENDIR_sd_search_prepare( $query ) {
	$query = trim( (string) $query );
	$len   = function_exists( 'mb_strlen' ) ? mb_strlen( $query, 'UTF-8' ) : strlen( $query );
	if ( $len < 3 ) {
		return null;
	}
	$q = CAYDENDIR_sd_search_norm( $query );
	if ( '' === $q ) {
		return null;
	}
	$qt = array_values( array_filter( explode( ' ', $q ), 'strlen' ) );
	$qr = ( 2 === count( $qt ) ) ? ( $qt[1] . ' ' . $qt[0] ) : '';
	return array( $q, $qt, $qr );
}

/**
 * Rank a person against a prepared query. Lower is a better match; -1 means no
 * match. NAME FIELDS ONLY — first, last, full — never job/location/tags, so a
 * job-title search like "warehouse driver" matches nobody.
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

/** A person's display name (falls back to first + last). '' if none. */
function CAYDENDIR_sd_person_name( $row ) {
	$name = isset( $row['name'] ) ? trim( (string) $row['name'] ) : '';
	if ( '' === $name ) {
		$name = trim( ( isset( $row['firstname'] ) ? $row['firstname'] : '' ) . ' ' . ( isset( $row['lastname'] ) ? $row['lastname'] : '' ) );
	}
	return $name;
}

/** True when a row is hidden from the public (same flag the listing uses). */
function CAYDENDIR_sd_row_is_hidden( $row ) {
	return isset( $row['hidden'] ) && '1' === (string) $row['hidden'];
}

/**
 * Rank one row against a prepared query. Returns the rank (>=0) or -1 for no
 * match / no name. Shared by every matcher so semantics can never diverge.
 */
function CAYDENDIR_sd_row_rank( $row, $q, $qt, $qr ) {
	$name = CAYDENDIR_sd_person_name( $row );
	if ( '' === $name ) {
		return -1;
	}
	$first = CAYDENDIR_sd_search_norm( isset( $row['firstname'] ) ? $row['firstname'] : '' );
	$last  = CAYDENDIR_sd_search_norm( isset( $row['lastname'] ) ? $row['lastname'] : '' );
	$full  = CAYDENDIR_sd_search_norm( $name );
	return CAYDENDIR_sd_search_rank( $q, $qt, $qr, $first, $last, $full );
}

/* =========================================================================
 * Filters
 * ====================================================================== */

/**
 * The people-search provider. Returns public-only people whose name matches.
 *
 * @param array $people Starts empty; we append matches (and keep whatever was
 *                      passed).
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
		$prep  = CAYDENDIR_sd_search_prepare( isset( $args['query'] ) ? $args['query'] : '' );
		if ( null === $prep ) {
			return $people;
		}
		list( $q, $qt, $qr ) = $prep;

		$limit = isset( $args['limit'] ) ? (int) $args['limit'] : 20;
		$limit = $limit > 0 ? $limit : 20;

		// VISIBILITY: we ignore $viewer and always return the visitor-safe set —
		// showing an editor slightly less is cosmetic; the reverse is a
		// disclosure. Hidden rows are skipped before matching.
		$all    = CAYDENDIR_sd_get_merged_data();
		$all    = is_array( $all ) ? $all : array();
		$scored = array();

		foreach ( $all as $row ) {
			if ( ! is_array( $row ) || CAYDENDIR_sd_row_is_hidden( $row ) ) {
				continue;
			}
			$rank = CAYDENDIR_sd_row_rank( $row, $q, $qt, $qr );
			if ( $rank < 0 ) {
				continue;
			}
			$scored[] = array( 'rank' => $rank, 'name' => CAYDENDIR_sd_person_name( $row ), 'row' => $row );
		}

		if ( empty( $scored ) ) {
			return $people;
		}

		// Best matches first; ties broken alphabetically by name.
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
			// merge key the directory already computed for this person. Opaque
			// string — the search side never parses it.
			$id = isset( $row['id'] ) ? trim( (string) $row['id'] ) : '';
			if ( '' === $id ) {
				$id = isset( $row['_key'] ) ? (string) $row['_key'] : ( 'nm:' . md5( $name ) );
			}

			$person = array(
				'id'   => $id,
				'name' => $name,
			);

			// A deep link that lands right on this person: the directory page plus
			// a #firstnamelastname fragment matching the id the directory renders
			// on their row/card. Falls back to the ?sd_name= search pre-fill when
			// we can't build a name slug.
			if ( '' !== $dir_url ) {
				$slug = function_exists( 'CAYDENDIR_sd_name_slug' )
					? CAYDENDIR_sd_name_slug( isset( $row['firstname'] ) ? $row['firstname'] : '', isset( $row['lastname'] ) ? $row['lastname'] : '', $name )
					: '';
				if ( '' !== $slug ) {
					$person['url'] = $dir_url . '#' . $slug;
				} else {
					$person['url'] = add_query_arg( 'sd_name', rawurlencode( $name ), $dir_url );
				}
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
 * Does the query match a HIDDEN person's name? Bare boolean, server-side only —
 * no names or records ever leave this function. It lets the search plugin drop
 * the directory page for a hidden-name search (whose mere appearance would
 * confirm the person exists) while still showing it for a topical search like
 * "staff directory".
 *
 * @param bool  $matches Running result (OR across providers).
 * @param array $args    query, limit, fields, viewer.
 * @return bool
 */
function CAYDENDIR_sd_wpsqr_matches_hidden( $matches, $args = array() ) {
	try {
		if ( $matches ) {
			return true; // another provider already matched — no work needed.
		}
		if ( ! function_exists( 'CAYDENDIR_sd_get_merged_data' ) ) {
			return $matches;
		}
		$args = is_array( $args ) ? $args : array();
		$prep = CAYDENDIR_sd_search_prepare( isset( $args['query'] ) ? $args['query'] : '' );
		if ( null === $prep ) {
			return $matches;
		}
		list( $q, $qt, $qr ) = $prep;

		$all = CAYDENDIR_sd_get_merged_data();
		$all = is_array( $all ) ? $all : array();
		foreach ( $all as $row ) {
			// HIDDEN people only — same name matching as the visible search.
			if ( ! is_array( $row ) || ! CAYDENDIR_sd_row_is_hidden( $row ) ) {
				continue;
			}
			if ( CAYDENDIR_sd_row_rank( $row, $q, $qt, $qr ) >= 0 ) {
				return true; // found one — stop; caller only needs the boolean.
			}
		}
	} catch ( \Throwable $e ) {
		CAYDENDIR_sd_log( 'wpsqr matches hidden', $e );
	}
	return $matches;
}

/**
 * Supply our directory page for the "Search the full staff directory" link,
 * pre-filled with the search term. Returns the configured $url unchanged when
 * we can't find our own page, leaving the setting in charge.
 *
 * @param string $url  The URL the setting would use.
 * @param string $term The search term.
 * @return string
 */
function CAYDENDIR_sd_wpsqr_more_url( $url, $term = '' ) {
	try {
		$page = CAYDENDIR_sd_directory_url();
		if ( '' !== $page ) {
			$term = (string) $term;
			return ( '' !== $term ) ? add_query_arg( 'sd_name', rawurlencode( $term ), $page ) : $page;
		}
	} catch ( \Throwable $e ) {
		CAYDENDIR_sd_log( 'wpsqr more url', $e );
	}
	return $url;
}

/**
 * Name our directory page so the search plugin can drop it from name searches
 * that match nobody visible (closing the "hidden person confirmed by the page
 * appearing" leak — see rule 7). We add a page ID, or an override URL if set.
 */
function CAYDENDIR_sd_wpsqr_directory_pages( $pages ) {
	try {
		if ( ! is_array( $pages ) ) {
			$pages = array();
		}
		$override = CAYDENDIR_sd_directory_url_override();
		if ( '' !== $override ) {
			$pages[] = $override;
			return $pages;
		}
		$pid = CAYDENDIR_sd_directory_page_id();
		if ( $pid > 0 ) {
			$pages[] = (string) $pid;
		}
	} catch ( \Throwable $e ) {
		CAYDENDIR_sd_log( 'wpsqr directory pages', $e );
	}
	return $pages;
}

/* =========================================================================
 * Directory-page discovery (cached, failsafe)
 * ====================================================================== */

/**
 * An explicit directory-page URL supplied by the site, or '' if none.
 * Set CAYDENDIR_SD_DIRECTORY_URL (constant) or filter 'CAYDENDIR_sd_directory_url'.
 */
function CAYDENDIR_sd_directory_url_override() {
	$override = '';
	if ( defined( 'CAYDENDIR_SD_DIRECTORY_URL' ) && is_string( CAYDENDIR_SD_DIRECTORY_URL ) ) {
		$override = (string) CAYDENDIR_SD_DIRECTORY_URL;
	}
	if ( function_exists( 'apply_filters' ) ) {
		$override = (string) apply_filters( 'CAYDENDIR_sd_directory_url', $override );
	}
	return ( '' !== $override && function_exists( 'esc_url_raw' ) ) ? esc_url_raw( $override ) : $override;
}

/**
 * The ID of the published page/post that hosts the directory (its content
 * carries our shortcode or block). Cached for a day. 0 when none is found.
 */
function CAYDENDIR_sd_directory_page_id() {
	try {
		$cache_key = 'CAYDENDIR_sd_dir_pid';
		if ( function_exists( 'get_transient' ) ) {
			$cached = get_transient( $cache_key );
			if ( false !== $cached && '' !== $cached ) {
				return (int) $cached;
			}
		}

		$pid = 0;
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
			$pid = $id ? (int) $id : 0;
		}

		if ( function_exists( 'set_transient' ) ) {
			$ttl = defined( 'DAY_IN_SECONDS' ) ? DAY_IN_SECONDS : 86400;
			// Store 0 as a sentinel string so a "known none" is cached too.
			set_transient( $cache_key, $pid > 0 ? (string) $pid : '0', $ttl );
		}
		return $pid;
	} catch ( \Throwable $e ) {
		CAYDENDIR_sd_log( 'wpsqr directory page id', $e );
		return 0;
	}
}

/**
 * Best-effort URL of the directory page, for deep links. An explicit override
 * wins; otherwise the discovered page's permalink. '' when none is found (a bad
 * or missing lookup must never break search — the name just renders as text).
 */
function CAYDENDIR_sd_directory_url() {
	try {
		$override = CAYDENDIR_sd_directory_url_override();
		if ( '' !== $override ) {
			return $override;
		}
		$pid = CAYDENDIR_sd_directory_page_id();
		if ( $pid > 0 && function_exists( 'get_permalink' ) ) {
			$perma = get_permalink( $pid );
			if ( is_string( $perma ) ) {
				return $perma;
			}
		}
		return '';
	} catch ( \Throwable $e ) {
		CAYDENDIR_sd_log( 'wpsqr directory url', $e );
		return '';
	}
}
