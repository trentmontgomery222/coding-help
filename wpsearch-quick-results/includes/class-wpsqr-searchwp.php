<?php
/**
 * SearchWP behaviour, from WPCode snippet #0.
 *
 * Folded in so there is one place to look instead of a WPCode snippet that
 * can be deactivated independently of the plugin that depends on it.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WPSQR_SearchWP {

	public function hooks() {
		add_action( 'template_redirect', array( $this, 'redirect_default_search' ) );

		foreach ( array( 'the_permalink', 'attachment_link', 'post_type_link' ) as $filter ) {
			add_filter( $filter, array( $this, 'media_direct_link' ), 99, 2 );
		}

		add_filter( 'searchwp\query\mods', array( $this, 'sink_attachments' ) );
	}

	/** Send /?s=term to the real results page. */
	public function redirect_default_search() {
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() || is_feed() ) {
			return;
		}

		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return;
		}

		if ( ! is_search() || ! isset( $_GET['s'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}

		$settings = WPSQR_Plugin::settings();
		$param    = $settings['query_param'];

		if ( isset( $_GET['swp_form']['form_id'] ) || isset( $_GET[ $param ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}

		$target  = untrailingslashit( $settings['results_path'] );
		$current = untrailingslashit( (string) wp_parse_url( add_query_arg( array() ), PHP_URL_PATH ) );

		if ( $current === $target ) {
			return;
		}

		$term = get_search_query();
		if ( '' === trim( $term ) ) {
			return;
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'swp_form[form_id]' => (int) $settings['form_id'],
					$param              => rawurlencode( $term ),
				),
				home_url( trailingslashit( $settings['results_path'] ) )
			),
			302
		);
		exit;
	}

	/**
	 * Point attachment links at the file rather than the attachment page.
	 *
	 * Front end only, unconditionally: detecting a "search context" was what
	 * made the original snippet silently do nothing on a page builder results
	 * page and in AJAX requests.
	 */
	public function media_direct_link( $permalink, $post = null ) {
		if ( is_admin() && ! wp_doing_ajax() ) {
			return $permalink;
		}

		if ( 'attachment' !== get_post_type( $post ) ) {
			return $permalink;
		}

		$attachment = get_post( $post );
		if ( ! $attachment ) {
			return $permalink;
		}

		$file_url = wp_get_attachment_url( $attachment->ID );

		return $file_url ? esc_url( $file_url ) : $permalink;
	}

	/** Weight every other source above attachments so PDFs rank last. */
	public function sink_attachments( $mods ) {
		if ( ! class_exists( '\SearchWP\Mod' ) || ! class_exists( '\SearchWP\Utils' ) ) {
			return $mods;
		}

		$source = \SearchWP\Utils::get_post_type_source_name( 'attachment' );
		if ( empty( $source ) ) {
			return $mods;
		}

		$mod = new \SearchWP\Mod( $source );

		$mod->relevance(
			function ( $runtime ) use ( $source ) {
				global $wpdb;

				return $wpdb->prepare(
					"IF( {$runtime->get_foreign_alias()}.source != %s, '9999999', '0' )",
					$source
				);
			}
		);

		$mods[] = $mod;

		return $mods;
	}
}
