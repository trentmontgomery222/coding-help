<?php
/**
 * Self-checks for the dashboard.
 *
 * Every question someone asks when the plugin "isn't doing anything",
 * answered on screen instead of guessed at.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WPSQR_Status {

	/**
	 * @return array[] Each: label, value, state (ok|warn|bad|info), note.
	 */
	public static function checks() {
		global $wpdb;

		$checks   = array();
		$settings = WPSQR_Plugin::settings();
		$summary  = WPSQR_Stats::summary();

		// --- tables -----------------------------------------------------
		$tables_ok = true;
		foreach ( array( WPSQR_Schema::cache_table(), WPSQR_Schema::terms_table() ) as $table ) {
			$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ); // phpcs:ignore WordPress.DB
			if ( $found !== $table ) {
				$tables_ok = false;
			}
		}

		$checks[] = array(
			'label' => __( 'Database tables', 'wpsqr' ),
			'value' => $tables_ok ? __( 'Created', 'wpsqr' ) : __( 'MISSING', 'wpsqr' ),
			'state' => $tables_ok ? 'ok' : 'bad',
			'note'  => $tables_ok
				? ''
				: __( 'Deactivate and reactivate the plugin. If they still do not appear, the database user may not have CREATE permission.', 'wpsqr' ),
		);

		// --- is anything being recorded at all? --------------------------
		$checks[] = array(
			'label' => __( 'Searches recorded', 'wpsqr' ),
			'value' => number_format_i18n( $summary['searches'] ),
			'state' => $summary['searches'] > 0 ? 'ok' : 'warn',
			'note'  => $summary['searches'] > 0
				? ''
				: __( 'No searches logged yet. If you have been searching, check the results-page path below matches the page you are actually landing on.', 'wpsqr' ),
		);

		// --- shortcode vs observer --------------------------------------
		$rendered = (int) $summary['rendered'];
		$observed = (int) $summary['observed'];

		if ( $rendered > 0 ) {
			$state = 'ok';
			$value = sprintf( /* translators: %d: count */ __( 'Yes — %d searches served', 'wpsqr' ), $rendered );
			$note  = '';
		} elseif ( $observed > 0 ) {
			$state = 'warn';
			$value = __( 'Not in use', 'wpsqr' );
			$note  = __( 'Searches are being watched and counted, but SearchWP is still rendering them, so nothing is cached yet. Add [wpsqr_results] to the results page to turn the caching on.', 'wpsqr' );
		} else {
			$state = 'info';
			$value = __( 'Not in use', 'wpsqr' );
			$note  = __( 'Add [wpsqr_results] to the results page once you have enough traffic in the table below to judge whether caching is worth it.', 'wpsqr' );
		}

		$checks[] = array(
			'label' => __( '[wpsqr_results] shortcode', 'wpsqr' ),
			'value' => $value,
			'state' => $state,
			'note'  => $note,
		);

		// --- what the plugin thinks the results page is ------------------
		$checks[] = array(
			'label' => __( 'Results page', 'wpsqr' ),
			'value' => esc_html( $settings['results_path'] ) . '  ?' . esc_html( $settings['query_param'] ) . '=',
			'state' => 'info',
			'note'  => __( 'A search is recognised by this path, or by any of these parameters: ', 'wpsqr' ) . implode( ', ', WPSQR_Plugin::query_params() ),
		);

		// --- search engine ----------------------------------------------
		$has_swp  = class_exists( '\SearchWP\Query' );
		$checks[] = array(
			'label' => __( 'Search engine', 'wpsqr' ),
			'value' => $has_swp ? __( 'SearchWP', 'wpsqr' ) : __( 'Core WordPress search', 'wpsqr' ),
			'state' => $has_swp ? 'ok' : 'warn',
			'note'  => $has_swp ? '' : __( 'SearchWP was not detected, so cache misses fall back to core search, which finds less.', 'wpsqr' ),
		);

		// --- cron --------------------------------------------------------
		$next = wp_next_scheduled( WPSQR_Warmer::HOOK );
		$cron_disabled = defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON;

		$checks[] = array(
			'label' => __( 'Cache warming', 'wpsqr' ),
			'value' => $next ? sprintf( /* translators: %s: human time diff */ __( 'Next run in %s', 'wpsqr' ), human_time_diff( time(), $next ) ) : __( 'Not scheduled', 'wpsqr' ),
			'state' => ( $next && ! $cron_disabled ) ? 'ok' : 'warn',
			'note'  => $cron_disabled
				? __( 'DISABLE_WP_CRON is set. Warming will not run unless a real cron job calls wp-cron.php. Everything else still works; the first visitor after each save pays full price.', 'wpsqr' )
				: '',
		);

		// --- object cache -------------------------------------------------
		$ext = wp_using_ext_object_cache();
		$checks[] = array(
			'label' => __( 'Persistent object cache', 'wpsqr' ),
			'value' => $ext ? __( 'Yes', 'wpsqr' ) : __( 'No', 'wpsqr' ),
			'state' => $ext ? 'ok' : 'info',
			'note'  => $ext ? '' : __( 'Without one, a cached search still costs a single indexed database lookup — fast, but not free. Redis or Memcached would make warm searches cost nothing.', 'wpsqr' ),
		);

		// --- staff directory ---------------------------------------------
		$providers = WPSQR_People::providers();
		$has_filter = has_filter( 'wpsqr_people_search' );

		if ( $providers ) {
			$names = array();
			foreach ( $providers as $provider ) {
				$names[] = trim( $provider['name'] . ' ' . $provider['version'] );
			}

			$state = 'ok';
			$value = implode( ', ', $names );
			$note  = '';
		} elseif ( $has_filter ) {
			$state = 'warn';
			$value = __( 'Answering, but unidentified', 'wpsqr' );
			$note  = __( 'Something is responding to wpsqr_people_search but has not registered itself through wpsqr_people_providers, so there is no way to tell which plugin or version it is.', 'wpsqr' );
		} else {
			$state = 'info';
			$value = __( 'No directory plugin connected', 'wpsqr' );
			$note  = __( 'People results are off until a staff directory plugin implements the wpsqr_people_search filter. See INTEGRATION.md in the plugin folder.', 'wpsqr' );
		}

		$checks[] = array(
			'label' => __( 'Staff directory', 'wpsqr' ),
			'value' => $value,
			'state' => $state,
			'note'  => $note,
		);

		// --- hidden content -----------------------------------------------
		$map      = WPSQR_Hidden::map();
		$meta_key = $settings['hide_meta_key'];

		$checks[] = array(
			'label' => __( 'Hidden content', 'wpsqr' ),
			'value' => '' === $meta_key
				? __( 'No meta key set', 'wpsqr' )
				: sprintf( /* translators: 1: count, 2: meta key */ __( '%1$d posts hidden via %2$s', 'wpsqr' ), count( $map['ids'] ), $meta_key ),
			'state' => ( '' !== $meta_key && $map['ids'] ) ? 'ok' : 'warn',
			'note'  => ( '' !== $meta_key && ! $map['ids'] )
				? __( 'The meta key is set but nothing matches it. Check it is exactly what your hide-plugin writes — this is the most common reason hidden content still appears.', 'wpsqr' )
				: '',
		);

		return $checks;
	}
}
