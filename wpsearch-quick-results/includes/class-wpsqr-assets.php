<?php
/**
 * Front-end assets: the browser rule engine and its styles.
 *
 * Same files as the WPCode snippets, now versioned with the plugin and loaded
 * only where they are needed instead of site-wide.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WPSQR_Assets {

	public function hooks() {
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	public function enqueue() {
		if ( ! $this->is_results_page() ) {
			return;
		}

		wp_enqueue_style(
			'wpsqr-search',
			WPSQR_URL . 'assets/css/search-filter.css',
			array(),
			WPSQR_VERSION
		);

		wp_enqueue_script(
			'wpsqr-search',
			WPSQR_URL . 'assets/js/search-filter.js',
			array(),
			WPSQR_VERSION,
			true
		);

		$map      = WPSQR_Hidden::map();
		$settings = WPSQR_Plugin::settings();

		// Editors get the hidden list so their results can be marked. Visitors
		// never receive hidden items in the first place — the server already
		// removed them — so they get an empty list rather than a roster of
		// everything that's hidden, which would be its own disclosure.
		$is_editor = current_user_can( 'edit_posts' );

		wp_add_inline_script(
			'wpsqr-search',
			'window.ACPS_SEARCH=' . wp_json_encode(
				array(
					'hiddenIds'   => $is_editor ? $map['ids'] : array(),
					'hiddenPaths' => $is_editor ? $map['paths'] : array(),
					'isAdmin'     => $is_editor,
					'query'       => WPSQR_Plugin::current_term(),
					'queryParams' => WPSQR_Plugin::query_params(),
					'homePath'    => untrailingslashit( (string) wp_parse_url( home_url(), PHP_URL_PATH ) ),
					'rules'       => array_merge(
						WPSQR_Rules::for_browser(),
						array(
							'hideMode'        => (string) $settings['hide_mode'],
							'adminSeesHidden' => (bool) $settings['admin_preview'],
							'updateCount'     => (bool) $settings['update_count'],
							'emptyMessage'    => (string) $settings['empty_message'],
						)
					),
				)
			) . ';',
			'before'
		);
	}

	protected function is_results_page() {
		if ( is_search() ) {
			return true;
		}

		foreach ( WPSQR_Plugin::query_params() as $param ) {
			if ( isset( $_GET[ $param ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
				return true;
			}
		}

		$settings = WPSQR_Plugin::settings();
		$current  = untrailingslashit( (string) wp_parse_url( add_query_arg( array() ), PHP_URL_PATH ) );

		return $current === untrailingslashit( $settings['results_path'] );
	}
}
