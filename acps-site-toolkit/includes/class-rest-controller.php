<?php
/**
 * REST API controllers — the cache-safe surface (spec §4.2, §7.5).
 *
 * Every route here is dynamic and must never be edge-cached. WP Engine's page
 * cache does not cache /wp-json/ by default; we also send no-cache headers as
 * belt-and-braces.
 *
 * Routes:
 *   POST /beacon         — record a page visit (journey tracking)
 *   POST /unload         — record time-on-page on page exit
 *   GET  /token          — fetch a fresh nonce + time-trap timestamp (never cached)
 *   GET  /recent-pages   — recent visited pages for the feedback pre-fill
 *   POST /submit         — submit any form (including feedback)
 *
 * @package ACPS\SiteToolkit
 */

namespace ACPS\SiteToolkit;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * REST_Controller.
 */
class REST_Controller {

	/**
	 * Register routes.
	 */
	public function register_routes() {
		$ns = ACPS_ST_REST_NAMESPACE;

		register_rest_route(
			$ns,
			'/beacon',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'beacon' ),
				'permission_callback' => '__return_true', // public tracking endpoint.
			)
		);

		register_rest_route(
			$ns,
			'/unload',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'unload' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			$ns,
			'/ping',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'ping' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			$ns,
			'/token',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'token' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			$ns,
			'/recent-pages',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'recent_pages' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'session' => array( 'required' => true ),
				),
			)
		);

		register_rest_route(
			$ns,
			'/submit',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'submit' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			$ns,
			'/unlock',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'unlock' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * POST /unlock — verify a password-protected form's password and, on
	 * success, return the rendered form HTML (never printed into cached markup).
	 *
	 * @param \WP_REST_Request $req Request.
	 * @return \WP_REST_Response
	 */
	public function unlock( $req ) {
		$this->no_cache();
		$params  = $req->get_json_params();
		$params  = is_array( $params ) ? $params : $req->get_params();

		$form_id  = isset( $params['form_id'] ) ? absint( $params['form_id'] ) : 0;
		$password = isset( $params['password'] ) ? (string) $params['password'] : '';
		$form     = Form::find( $form_id );

		if ( ! $form ) {
			return new \WP_REST_Response( array( 'success' => false ), 200 );
		}

		if ( Access::verify_password( $form, $password ) ) {
			$html = Form_Renderer::render( $form, array( 'post_id' => isset( $params['post_id'] ) ? absint( $params['post_id'] ) : 0 ) );
			return new \WP_REST_Response( array( 'success' => true, 'html' => $html ), 200 );
		}

		return new \WP_REST_Response(
			array( 'success' => false, 'message' => __( 'Incorrect password.', 'acps-site-toolkit' ) ),
			200
		);
	}

	/**
	 * Send no-store headers so nothing here is ever cached at the edge.
	 */
	private function no_cache() {
		nocache_headers();
		if ( ! headers_sent() ) {
			header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0' );
		}
	}

	/**
	 * POST /beacon — record a page visit.
	 *
	 * @param \WP_REST_Request $req Request.
	 * @return \WP_REST_Response
	 */
	public function beacon( $req ) {
		$this->no_cache();

		// Honour the tracking master switch and consent mode (spec §4.4).
		if ( ! Settings::get( 'tracking_enabled' ) ) {
			return new \WP_REST_Response( array( 'ok' => false, 'reason' => 'disabled' ), 200 );
		}

		$params  = $req->get_json_params();
		$params  = is_array( $params ) ? $params : $req->get_params();
		$consent = ! empty( $params['consent'] );

		if ( Settings::get( 'consent_mode' ) && ! $consent ) {
			// Consent required but not given: do not track. Forms still work.
			return new \WP_REST_Response( array( 'ok' => false, 'reason' => 'no_consent' ), 200 );
		}

		$token = isset( $params['session'] ) ? $params['session'] : '';
		$session_id = Session::resolve(
			$token,
			array(
				'post_id'  => isset( $params['post_id'] ) ? absint( $params['post_id'] ) : 0,
				'url'      => isset( $params['url'] ) ? $params['url'] : '',
				'referrer' => isset( $params['referrer'] ) ? $params['referrer'] : '',
				'viewport' => isset( $params['viewport'] ) ? $params['viewport'] : '',
				'consent'  => $consent,
			)
		);

		if ( ! $session_id ) {
			return new \WP_REST_Response( array( 'ok' => false, 'reason' => 'bad_session' ), 200 );
		}

		$visit_id = Tracking::record_visit(
			$session_id,
			array(
				'post_id'  => isset( $params['post_id'] ) ? absint( $params['post_id'] ) : 0,
				'url'      => isset( $params['url'] ) ? $params['url'] : '',
				'title'    => isset( $params['title'] ) ? $params['title'] : '',
				'viewport' => isset( $params['viewport'] ) ? $params['viewport'] : '',
			)
		);

		return new \WP_REST_Response( array( 'ok' => (bool) $visit_id ), 200 );
	}

	/**
	 * POST /unload — write time-on-page for the last visit.
	 *
	 * @param \WP_REST_Request $req Request.
	 * @return \WP_REST_Response
	 */
	public function unload( $req ) {
		$this->no_cache();
		if ( ! Settings::get( 'tracking_enabled' ) ) {
			return new \WP_REST_Response( array( 'ok' => false ), 200 );
		}
		$params  = $req->get_json_params();
		$params  = is_array( $params ) ? $params : $req->get_params();
		$session = Session::lookup( isset( $params['session'] ) ? $params['session'] : '' );
		if ( $session ) {
			Tracking::record_unload( $session, isset( $params['seconds'] ) ? absint( $params['seconds'] ) : 0 );
		}
		return new \WP_REST_Response( array( 'ok' => true ), 200 );
	}

	/**
	 * POST /ping — heartbeat that keeps a session marked "active" while the tab
	 * is open, so the live "who's on the site now" view is accurate even when a
	 * visitor stays on one page. Lookup-only: never creates a session.
	 *
	 * @param \WP_REST_Request $req Request.
	 * @return \WP_REST_Response
	 */
	public function ping( $req ) {
		$this->no_cache();
		if ( ! Settings::get( 'tracking_enabled' ) ) {
			return new \WP_REST_Response( array( 'ok' => false ), 200 );
		}
		$params  = $req->get_json_params();
		$params  = is_array( $params ) ? $params : $req->get_params();
		$session = Session::lookup( isset( $params['session'] ) ? $params['session'] : '' );
		if ( $session ) {
			Session::touch( $session );
		}
		return new \WP_REST_Response( array( 'ok' => (bool) $session ), 200 );
	}

	/**
	 * GET /token — a fresh nonce + server timestamp for a form.
	 *
	 * This is THE fix for stale cached nonces (spec §7.5). The form HTML is
	 * cached with empty token slots; this uncached call fills them after load.
	 *
	 * @param \WP_REST_Request $req Request.
	 * @return \WP_REST_Response
	 */
	public function token( $req ) {
		$this->no_cache();
		return new \WP_REST_Response(
			array(
				'nonce'   => wp_create_nonce( Spam::NONCE_ACTION ),
				'ts'      => time(),
				// Randomized honeypot field name so bots can't target a fixed name.
				'hp_name' => 'website_' . wp_generate_password( 6, false, false ),
			),
			200
		);
	}

	/**
	 * GET /recent-pages — the last N visited pages for a session, by title.
	 *
	 * @param \WP_REST_Request $req Request.
	 * @return \WP_REST_Response
	 */
	public function recent_pages( $req ) {
		$this->no_cache();
		$session = Session::lookup( $req->get_param( 'session' ) );
		$limit   = (int) Settings::get( 'recent_pages_count', 3 );
		if ( ! $session ) {
			return new \WP_REST_Response( array( 'pages' => array() ), 200 );
		}
		$pages = Tracking::recent_pages( $session, $limit );
		// Only expose title + post id + url.
		$out = array();
		foreach ( $pages as $p ) {
			$out[] = array(
				'post_id' => (int) $p['post_id'],
				'title'   => $p['title'] ? $p['title'] : $p['url'],
				'url'     => $p['url'],
			);
		}
		return new \WP_REST_Response( array( 'pages' => $out ), 200 );
	}

	/**
	 * POST /submit — process any form submission.
	 *
	 * @param \WP_REST_Request $req Request.
	 * @return \WP_REST_Response
	 */
	public function submit( $req ) {
		$this->no_cache();

		$body  = $req->get_params(); // multipart or urlencoded body.
		$nonce = isset( $body['acps_nonce'] ) ? sanitize_text_field( $body['acps_nonce'] ) : '';

		// Nonce = CSRF + spam layer 3 (spec §7.4).
		if ( ! Spam::verify_nonce( $nonce ) ) {
			return new \WP_REST_Response(
				array(
					'success' => false,
					'summary' => array( array( 'field' => '', 'message' => __( 'Your session expired. Please reload the page and try again.', 'acps-site-toolkit' ) ) ),
				),
				200
			);
		}

		$form_id = isset( $body['acps_form_id'] ) ? absint( $body['acps_form_id'] ) : 0;
		$form    = Form::find( $form_id );
		if ( ! $form || 'published' !== $form->status ) {
			// Feedback form may be draft-hidden but still submittable.
			if ( ! $form || ! $form->is_feedback ) {
				return new \WP_REST_Response(
					array(
						'success' => false,
						'summary' => array( array( 'field' => '', 'message' => __( 'This form is not available.', 'acps-site-toolkit' ) ) ),
					),
					200
				);
			}
		}

		$result = Submission::process( $form, $body, $req->get_file_params() );

		return new \WP_REST_Response( $result, 200 );
	}
}
