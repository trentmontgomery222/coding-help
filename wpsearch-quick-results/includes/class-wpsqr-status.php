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
		$engine = WPSQR_Plugin::engine();
		$index  = WPSQR_Index::stats();

		$engine_labels = array(
			'searchwp' => array( 'ok', __( 'SearchWP', 'wpsqr' ), '' ),
			'builtin'  => array(
				'ok',
				__( 'This plugin\'s own index', 'wpsqr' ),
				$index['fulltext'] ? '' : __( 'Full-text indexing is unavailable on this database, so matching and ranking use a simpler method.', 'wpsqr' ),
			),
			'core'     => array(
				'warn',
				__( 'WordPress core search', 'wpsqr' ),
				0 === $index['rows']
					? __( 'The built-in index is empty, so core search is being used instead of returning nothing. Rebuild the index on this page to switch over.', 'wpsqr' )
					: __( 'Core search ranks by date, not relevance.', 'wpsqr' ),
			),
		);

		$row      = isset( $engine_labels[ $engine ] ) ? $engine_labels[ $engine ] : array( 'info', $engine, '' );
		$checks[] = array(
			'label' => __( 'Search engine', 'wpsqr' ),
			'value' => $row[1],
			'state' => $row[0],
			'note'  => $row[2],
		);

		// --- index --------------------------------------------------------
		$complete = $index['expected'] > 0 && $index['rows'] >= $index['expected'];

		$checks[] = array(
			'label' => __( 'Search index', 'wpsqr' ),
			'value' => sprintf(
				/* translators: 1: indexed, 2: expected */
				__( '%1$s of %2$s posts', 'wpsqr' ),
				number_format_i18n( $index['rows'] ),
				number_format_i18n( $index['expected'] )
			),
			'state' => $complete ? 'ok' : ( $index['rows'] > 0 ? 'warn' : 'bad' ),
			'note'  => $complete ? '' : __( 'Posts are indexed as they are saved, so an existing site needs one rebuild to catch up. Until then some content simply will not be found.', 'wpsqr' ),
		);

		// --- site search takeover -----------------------------------------
		if ( WPSQR_Plugin::engine_is_builtin() ) {
			$checks[] = array(
				'label' => __( 'Site search', 'wpsqr' ),
				'value' => __( 'Handled by this plugin', 'wpsqr' ),
				'state' => 'ok',
				'note'  => __( 'The theme\'s own search page is being answered with these results.', 'wpsqr' ),
			);
		}

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

		// --- the directory page as a result ------------------------------
		$mode = $settings['directory_mode'];

		if ( ! WPSQR_Directory::is_configured() ) {
			$dir_state = 'warn';
			$dir_value = __( 'Not identified', 'wpsqr' );
			$dir_note  = __( 'Until the directory page is named, it is treated as an ordinary result — so searching a hidden person\'s name will return it, which confirms that person exists.', 'wpsqr' );
		} else {
			$labels = array(
				'smart'             => array( 'ok', __( 'Hidden only when the search names a hidden person', 'wpsqr' ), '' ),
				'smart_no_signal'   => array(
					'warn',
					__( 'Always shown (cannot detect hidden names)', 'wpsqr' ),
					__( 'Set to hide only for a hidden person\'s name, but the directory plugin cannot answer whether a term matches one. Nothing is hidden; the description is still replaced. The wpsqr_people_matches_hidden filter is what it needs.', 'wpsqr' ),
				),
				'smart_no_provider' => array(
					'warn',
					__( 'Always shown (no directory plugin)', 'wpsqr' ),
					__( 'No directory plugin is connected, so a hidden name cannot be recognised.', 'wpsqr' ),
				),
				'strict'            => array(
					'ok',
					__( 'Hidden whenever nobody visible matched', 'wpsqr' ),
					__( 'Topical searches such as "staff directory" lose the page too, since they match nobody\'s name either.', 'wpsqr' ),
				),
				'always'            => array( 'info', __( 'Always shown', 'wpsqr' ), __( 'A hidden person\'s name will return the page. Its description is still replaced.', 'wpsqr' ) ),
				'never'             => array( 'info', __( 'Never shown', 'wpsqr' ), '' ),
			);

			$state = WPSQR_Directory::mode_state();
			$row   = isset( $labels[ $state ] ) ? $labels[ $state ] : array( 'info', $state, '' );

			$dir_state = $row[0];
			$dir_value = $row[1];
			$dir_note  = $row[2];
		}

		$checks[] = array(
			'label' => __( 'Directory page in results', 'wpsqr' ),
			'value' => $dir_value,
			'state' => $dir_state,
			'note'  => $dir_note,
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
