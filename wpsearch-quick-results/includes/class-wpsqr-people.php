<?php
/**
 * People results.
 *
 * When someone searches a person's name, the useful answer is that person —
 * not the directory page they happen to appear on. This asks whatever staff
 * directory plugin is installed for matching people and shows them above the
 * ordinary results.
 *
 * This plugin owns none of that data. It defines a contract and renders
 * whatever comes back; the directory plugin decides who is visible and who
 * matches. That split matters: visibility rules belong with the data, and a
 * search plugin guessing at them is how people who asked to be unlisted end
 * up listed.
 *
 * See INTEGRATION.md for the contract a directory plugin implements.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WPSQR_People {

	const CONTRACT_VERSION = 1;

	/**
	 * Find people matching a search.
	 *
	 * @param string $term  Raw search term.
	 * @param int    $limit Maximum people to return.
	 * @return array[] Normalized person rows, possibly empty.
	 */
	public static function search( $term, $limit = null ) {
		$settings = WPSQR_Plugin::settings();

		if ( empty( $settings['people_enabled'] ) ) {
			return array();
		}

		$term = trim( (string) $term );

		// Very short terms match half the directory and help nobody.
		if ( mb_strlen( $term ) < max( 2, (int) $settings['people_min_chars'] ) ) {
			return array();
		}

		$limit = null === $limit ? (int) $settings['people_limit'] : (int) $limit;
		$limit = max( 1, min( 50, $limit ) );

		$cache_key = 'wpsqr_people_' . md5( WPSQR_Normalizer::normalize( $term ) . '|' . $limit . '|' . WPSQR_Normalizer::viewer_bucket() );

		$cached = get_transient( $cache_key );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		/**
		 * Ask the directory plugin for matching people.
		 *
		 * Implementers: return only people who are visible to the public, and
		 * match on name only for now. See INTEGRATION.md.
		 *
		 * @param array[] $people Start empty; append your matches.
		 * @param array   $args   query, limit, fields, viewer.
		 */
		$people = apply_filters(
			'wpsqr_people_search',
			array(),
			array(
				'query'  => $term,
				'limit'  => $limit,
				'fields' => array( 'name' ),
				'viewer' => WPSQR_Normalizer::viewer_bucket(),
			)
		);

		$people = self::normalize_all( $people, $limit );

		set_transient( $cache_key, $people, 10 * MINUTE_IN_SECONDS );

		return $people;
	}

	/**
	 * Clean whatever the provider returned.
	 *
	 * Everything here is defensive on purpose. This data is rendered on a
	 * public page, and a provider written by someone else — or by an AI
	 * session, as here — should not be able to inject markup or leak a field
	 * the site has not opted into showing.
	 */
	public static function normalize_all( $people, $limit ) {
		if ( ! is_array( $people ) ) {
			return array();
		}

		$clean = array();
		$seen  = array();

		foreach ( $people as $person ) {
			$person = self::normalize( $person );

			if ( null === $person ) {
				continue;
			}

			// A person listed twice under two records is still one person.
			// The provider's own id is the most reliable thing to key on,
			// since two records for the same person may differ in everything
			// else; URL and name are fallbacks for providers that send none.
			if ( '' !== $person['id'] ) {
				$fingerprint = 'id:' . $person['id'];
			} elseif ( '' !== $person['url'] ) {
				$fingerprint = 'url:' . $person['url'];
			} else {
				$fingerprint = 'name:' . strtolower( $person['name'] );
			}
			if ( isset( $seen[ $fingerprint ] ) ) {
				continue;
			}
			$seen[ $fingerprint ] = true;

			$clean[] = $person;

			if ( count( $clean ) >= $limit ) {
				break;
			}
		}

		return $clean;
	}

	/**
	 * @return array|null Null when the row is unusable.
	 */
	public static function normalize( $person ) {
		if ( ! is_array( $person ) ) {
			return null;
		}

		$name = isset( $person['name'] ) ? trim( wp_strip_all_tags( (string) $person['name'] ) ) : '';

		// A person without a name is not a result anyone can use.
		if ( '' === $name ) {
			return null;
		}

		$settings = WPSQR_Plugin::settings();

		$clean = array(
			// An opaque string, never cast to int. A directory that stores
			// people outside the posts table has no numeric post ID to give —
			// identifiers like "WP-1-SD-1-E" are normal, and casting those to
			// int turns every one of them into 0, which silently collapses
			// every result into one during de-duplication.
			'id'         => isset( $person['id'] ) ? sanitize_text_field( (string) $person['id'] ) : '',
			'name'       => $name,
			'url'        => isset( $person['url'] ) ? esc_url_raw( (string) $person['url'] ) : '',
			'job_title'  => self::text( $person, 'job_title' ),
			'department' => self::text( $person, 'department' ),
			'location'   => self::text( $person, 'location' ),
			'photo'      => isset( $person['photo'] ) ? esc_url_raw( (string) $person['photo'] ) : '',
			'email'      => '',
			'phone'      => '',
		);

		// Contact details are opt-in. A directory that publishes them on its
		// own page has made that choice for that page; it is not automatically
		// a choice to scatter them across search results.
		if ( ! empty( $settings['people_show_email'] ) && ! empty( $person['email'] ) && is_email( $person['email'] ) ) {
			$clean['email'] = sanitize_email( $person['email'] );
		}

		if ( ! empty( $settings['people_show_phone'] ) ) {
			$clean['phone'] = self::text( $person, 'phone' );
		}

		return $clean;
	}

	protected static function text( $person, $key ) {
		if ( ! isset( $person[ $key ] ) ) {
			return '';
		}

		return trim( wp_strip_all_tags( (string) $person[ $key ] ) );
	}

	/**
	 * Which directory plugin, if any, is answering.
	 *
	 * @return array[] Each: name, version.
	 */
	public static function providers() {
		/**
		 * Identify yourself so the admin screen can confirm the integration
		 * is live rather than leaving someone to guess from an empty result.
		 *
		 * @param array[] $providers
		 */
		$providers = apply_filters( 'wpsqr_people_providers', array() );

		$clean = array();

		foreach ( (array) $providers as $provider ) {
			if ( empty( $provider['name'] ) ) {
				continue;
			}

			$clean[] = array(
				'name'     => sanitize_text_field( $provider['name'] ),
				'version'  => isset( $provider['version'] ) ? sanitize_text_field( $provider['version'] ) : '',
				'contract' => isset( $provider['contract'] ) ? (int) $provider['contract'] : 0,
			);
		}

		return $clean;
	}

	public static function has_provider() {
		return (bool) self::providers() || has_filter( 'wpsqr_people_search' );
	}

	/**
	 * Where "search the full directory" should point.
	 *
	 * A directory plugin usually knows its own page better than the setting
	 * does — it can find the page carrying its shortcode — so it gets first
	 * refusal, and the setting is the fallback.
	 *
	 * @param string $term The current search.
	 * @return string URL, or '' to show no link.
	 */
	public static function more_url( $term ) {
		$configured = (string) WPSQR_Plugin::settings()['people_more_url'];

		/**
		 * @param string $url  The configured URL, possibly empty.
		 * @param string $term The current search term, unescaped.
		 */
		$url = apply_filters( 'wpsqr_people_more_url', $configured, $term );

		return esc_url_raw( (string) $url );
	}

	/** Called by the directory plugin when its data changes. */
	public static function flush() {
		global $wpdb;

		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_wpsqr_people_%'
			 OR option_name LIKE '_transient_timeout_wpsqr_people_%'"
		);
	}
}

// A directory plugin calls do_action( 'wpsqr_people_changed' ) after an edit.
add_action( 'wpsqr_people_changed', array( 'WPSQR_People', 'flush' ) );
