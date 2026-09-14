<?php
/**
 * REST API for creating and managing short links remotely.
 *
 * Endpoints (namespace acps-ls/v1), all authenticated with an API key:
 *
 *   POST   /links                 Create a link (auto or custom slug; permanent or not).
 *   GET    /links                 List links (page, per_page, search).
 *   GET    /links/<slug>          Fetch one link.
 *   PATCH  /links/<slug>          Update is_active / redirect_type (destination is locked).
 *   DELETE /links/<slug>          Delete a link.
 *   GET    /ping                  Auth + health check.
 *
 * Authentication: send the key in a header —
 *   X-Api-Key: <key>            (preferred), or
 *   Authorization: Bearer <key>
 * Keys are created on the hidden API screen and stored only as SHA-256 hashes.
 *
 * Protection:
 *   - Master on/off switch (disabled = every route 404s to auth).
 *   - Per-key request rate limit (requests/minute) -> HTTP 429.
 *   - Per-key hourly create cap (anti-spam) -> HTTP 429.
 *   - Per-IP pre-auth rate limit so keys can't be brute-forced cheaply.
 *   - Destination URLs validated (http/https only) with an optional host blocklist.
 *   - Every callback wrapped so a failure returns a clean 4xx/5xx, never a fatal.
 *
 * @package ACPS_Link_Shortener
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * REST API controller.
 */
class ACPS_LS_API {

	/**
	 * Resolved config for this request.
	 *
	 * @var array
	 */
	private $cfg;

	/**
	 * The key id that authorized the current request (for rate limiting + stamping).
	 *
	 * @var string
	 */
	private $active_key_id = '';

	/**
	 * Build with resolved config.
	 */
	public function __construct() {
		$this->cfg = self::config();
	}

	/**
	 * Merge stored settings with defaults.
	 *
	 * @return array
	 */
	public static function config() {
		$s = get_option( ACPS_LS_OPT_SETTINGS, array() );
		$s = is_array( $s ) ? $s : array();

		return array(
			'enabled'     => ! empty( $s['api_enabled'] ),
			'keys'        => ( isset( $s['api_keys'] ) && is_array( $s['api_keys'] ) ) ? $s['api_keys'] : array(),
			'rate_limit'  => isset( $s['api_rate_limit'] ) ? max( 1, (int) $s['api_rate_limit'] ) : 60,   // requests / minute / key
			'hourly_max'  => isset( $s['api_hourly_max'] ) ? max( 0, (int) $s['api_hourly_max'] ) : 200,  // link creates / hour / key (0 = unlimited)
			'ip_limit'    => isset( $s['api_ip_limit'] ) ? max( 1, (int) $s['api_ip_limit'] ) : 120,      // requests / minute / IP (pre-auth)
			'blocklist'   => ( isset( $s['api_blocklist'] ) && is_array( $s['api_blocklist'] ) ) ? $s['api_blocklist'] : array(),
			'allow_manage' => isset( $s['api_allow_manage'] ) ? (bool) $s['api_allow_manage'] : true,     // allow PATCH/DELETE
		);
	}

	/**
	 * Register the REST routes. Never throws.
	 */
	public function register() {
		try {
			add_action( 'rest_api_init', array( $this, 'register_routes' ) );
		} catch ( Throwable $e ) {
			acps_ls_log_error( 'api register', $e );
		}
	}

	/**
	 * Define the routes.
	 */
	public function register_routes() {
		$ns   = defined( 'ACPS_LS_REST_NAMESPACE' ) ? ACPS_LS_REST_NAMESPACE : 'acps-ls/v1';
		$auth = array( $this, 'authorize' );

		register_rest_route(
			$ns,
			'/ping',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'ep_ping' ),
				'permission_callback' => $auth,
			)
		);

		register_rest_route(
			$ns,
			'/links',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'ep_list' ),
					'permission_callback' => $auth,
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'ep_create' ),
					'permission_callback' => $auth,
				),
			)
		);

		// Slug may contain slashes (namespaced links), so allow them in the match.
		register_rest_route(
			$ns,
			'/links/(?P<slug>[A-Za-z0-9/_-]+)',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'ep_get' ),
					'permission_callback' => $auth,
				),
				array(
					'methods'             => 'PATCH',
					'callback'            => array( $this, 'ep_update' ),
					'permission_callback' => $auth,
				),
				array(
					'methods'             => 'DELETE',
					'callback'            => array( $this, 'ep_delete' ),
					'permission_callback' => $auth,
				),
			)
		);
	}

	/* --------------------------------------------------------------------- */
	/* Authentication + rate limiting                                         */
	/* --------------------------------------------------------------------- */

	/**
	 * Permission callback for every route: master switch, per-IP pre-auth limit,
	 * API-key check, then per-key request rate limit.
	 *
	 * @param WP_REST_Request $req Request.
	 * @return true|WP_Error
	 */
	public function authorize( $req ) {
		try {
			if ( empty( $this->cfg['enabled'] ) ) {
				return new WP_Error( 'acps_ls_api_disabled', __( 'The API is disabled.', 'acps-link-shortener' ), array( 'status' => 404 ) );
			}

			// Per-IP pre-auth throttle so keys can't be brute-forced cheaply.
			$ip = $this->client_ip();
			if ( ! $this->rate_ok( 'ip_' . md5( $ip ), 60, $this->cfg['ip_limit'] ) ) {
				return $this->too_many();
			}

			$key = $this->presented_key( $req );
			if ( '' === $key ) {
				return new WP_Error( 'acps_ls_api_no_key', __( 'Missing API key.', 'acps-link-shortener' ), array( 'status' => 401 ) );
			}

			$id = $this->match_key( $key );
			if ( '' === $id ) {
				return new WP_Error( 'acps_ls_api_bad_key', __( 'Invalid API key.', 'acps-link-shortener' ), array( 'status' => 403 ) );
			}
			$this->active_key_id = $id;

			// Per-key request rate limit.
			if ( ! $this->rate_ok( 'key_' . $id, 60, $this->cfg['rate_limit'] ) ) {
				return $this->too_many();
			}

			$this->stamp_key_use( $id );
			return true;
		} catch ( Throwable $e ) {
			acps_ls_log_error( 'api authorize', $e );
			return new WP_Error( 'acps_ls_api_error', __( 'Authorization error.', 'acps-link-shortener' ), array( 'status' => 500 ) );
		}
	}

	/**
	 * The key presented on the request (header only; never the query string).
	 *
	 * @param WP_REST_Request $req Request.
	 * @return string
	 */
	private function presented_key( $req ) {
		$key = (string) $req->get_header( 'x_api_key' );
		if ( '' === $key ) {
			$auth = (string) $req->get_header( 'authorization' );
			if ( 0 === stripos( $auth, 'bearer ' ) ) {
				$key = trim( substr( $auth, 7 ) );
			}
		}
		return trim( $key );
	}

	/**
	 * Return the id of the stored key matching $key, or '' if none. Constant-time
	 * comparison against each stored SHA-256 hash.
	 *
	 * @param string $key Presented key.
	 * @return string
	 */
	private function match_key( $key ) {
		$hash = hash( 'sha256', $key );
		foreach ( $this->cfg['keys'] as $k ) {
			if ( empty( $k['hash'] ) || empty( $k['id'] ) ) {
				continue;
			}
			if ( ! empty( $k['revoked'] ) ) {
				continue;
			}
			if ( hash_equals( (string) $k['hash'], $hash ) ) {
				return (string) $k['id'];
			}
		}
		return '';
	}

	/**
	 * Stamp last_used on a key (best-effort; ignores races).
	 *
	 * @param string $id Key id.
	 */
	private function stamp_key_use( $id ) {
		$s = get_option( ACPS_LS_OPT_SETTINGS, array() );
		if ( ! is_array( $s ) || empty( $s['api_keys'] ) || ! is_array( $s['api_keys'] ) ) {
			return;
		}
		foreach ( $s['api_keys'] as &$k ) {
			if ( isset( $k['id'] ) && $k['id'] === $id ) {
				$k['last_used'] = time();
				break;
			}
		}
		unset( $k );
		update_option( ACPS_LS_OPT_SETTINGS, $s );
	}

	/**
	 * Fixed-window rate check. Returns true if still within the limit (and counts
	 * this hit), false if the limit is exceeded.
	 *
	 * @param string $bucket Identifier (per key / per IP / per action).
	 * @param int    $window Window length in seconds.
	 * @param int    $limit  Max hits per window.
	 * @return bool
	 */
	private function rate_ok( $bucket, $window, $limit ) {
		if ( $limit <= 0 ) {
			return true; // Unlimited.
		}
		$slot = (int) floor( time() / $window );
		$tk   = 'acps_ls_rl_' . md5( $bucket . '|' . $slot );
		$n    = (int) get_transient( $tk );
		if ( $n >= $limit ) {
			return false;
		}
		set_transient( $tk, $n + 1, $window + 5 );
		return true;
	}

	/**
	 * Standard 429 response.
	 *
	 * @return WP_Error
	 */
	private function too_many() {
		return new WP_Error(
			'acps_ls_api_rate',
			__( 'Rate limit exceeded. Slow down and try again shortly.', 'acps-link-shortener' ),
			array( 'status' => 429 )
		);
	}

	/**
	 * Best-effort client IP.
	 *
	 * @return string
	 */
	private function client_ip() {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '0.0.0.0';
		return $ip ? $ip : '0.0.0.0';
	}

	/* --------------------------------------------------------------------- */
	/* Endpoints                                                              */
	/* --------------------------------------------------------------------- */

	/**
	 * GET /ping — confirms the key works.
	 *
	 * @return WP_REST_Response
	 */
	public function ep_ping() {
		return new WP_REST_Response( array( 'ok' => true, 'plugin' => 'acps-link-shortener', 'version' => ACPS_LS_VERSION ), 200 );
	}

	/**
	 * POST /links — create a short link.
	 *
	 * Body (JSON or form):
	 *   destination  (required) http/https URL.
	 *   slug         (optional) custom slug/path; auto-generated if omitted.
	 *   permanent    (optional) bool; true = 301 (permanent), false = 302 (default).
	 *   active       (optional) bool; default true.
	 *   title        (optional) label.
	 *
	 * @param WP_REST_Request $req Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function ep_create( $req ) {
		try {
			// Anti-spam: per-key hourly create cap.
			if ( '' !== $this->active_key_id && ! $this->rate_ok( 'create_' . $this->active_key_id, HOUR_IN_SECONDS, $this->cfg['hourly_max'] ) ) {
				return $this->too_many();
			}

			$dest = (string) $req->get_param( 'destination' );
			$clean_dest = ACPS_LS_DB::validate_destination( $dest );
			if ( is_wp_error( $clean_dest ) ) {
				return $this->err( $clean_dest, 400 );
			}

			// Optional destination host blocklist (anti-abuse).
			$host = strtolower( (string) wp_parse_url( $clean_dest, PHP_URL_HOST ) );
			foreach ( $this->cfg['blocklist'] as $blocked ) {
				$blocked = strtolower( trim( (string) $blocked ) );
				if ( '' !== $blocked && ( $host === $blocked || $this->host_in( $host, $blocked ) ) ) {
					return new WP_Error( 'acps_ls_api_blocked', __( 'That destination host is not allowed.', 'acps-link-shortener' ), array( 'status' => 422 ) );
				}
			}

			// Slug: custom or auto.
			$raw_slug = trim( (string) $req->get_param( 'slug' ) );
			if ( '' !== $raw_slug ) {
				$slug  = ACPS_LS_DB::sanitize_slug_path( $raw_slug );
				$valid = ACPS_LS_DB::validate_slug( $slug );
				if ( is_wp_error( $valid ) ) {
					return $this->err( $valid, 409 );
				}
			} else {
				$slug = ACPS_LS_DB::generate_unique_slug();
			}

			$permanent = $this->boolish( $req->get_param( 'permanent' ) );
			$active    = null === $req->get_param( 'active' ) ? true : $this->boolish( $req->get_param( 'active' ) );
			$title     = sanitize_text_field( (string) $req->get_param( 'title' ) );

			$id = ACPS_LS_DB::create(
				array(
					'slug'          => $slug,
					'destination'   => $clean_dest,
					'title'         => $title,
					'redirect_type' => $permanent ? 301 : 302,
					'is_active'     => $active ? 1 : 0,
					'source'        => 'api',
					'creator_label' => 'api',
				)
			);
			if ( is_wp_error( $id ) ) {
				return $this->err( $id, 500 );
			}

			$link = ACPS_LS_DB::get( (int) $id );
			return new WP_REST_Response( $this->shape( $link ), 201 );
		} catch ( Throwable $e ) {
			acps_ls_log_error( 'api create', $e );
			return $this->fatal();
		}
	}

	/**
	 * GET /links — list links.
	 *
	 * @param WP_REST_Request $req Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function ep_list( $req ) {
		try {
			$per_page = min( 100, max( 1, (int) $req->get_param( 'per_page' ) ?: 20 ) );
			$paged    = max( 1, (int) $req->get_param( 'page' ) ?: 1 );
			$search   = sanitize_text_field( (string) $req->get_param( 'search' ) );

			$res   = ACPS_LS_DB::get_links( array( 'per_page' => $per_page, 'paged' => $paged, 'search' => $search ) );
			$items = array();
			foreach ( (array) $res['items'] as $row ) {
				$items[] = $this->shape( $row );
			}

			$resp = new WP_REST_Response( $items, 200 );
			$resp->header( 'X-Total', (string) (int) $res['total'] );
			$resp->header( 'X-Total-Pages', (string) (int) ceil( $res['total'] / $per_page ) );
			return $resp;
		} catch ( Throwable $e ) {
			acps_ls_log_error( 'api list', $e );
			return $this->fatal();
		}
	}

	/**
	 * GET /links/<slug>.
	 *
	 * @param WP_REST_Request $req Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function ep_get( $req ) {
		try {
			$link = $this->find( $req );
			if ( ! $link ) {
				return $this->not_found();
			}
			return new WP_REST_Response( $this->shape( $link ), 200 );
		} catch ( Throwable $e ) {
			acps_ls_log_error( 'api get', $e );
			return $this->fatal();
		}
	}

	/**
	 * PATCH /links/<slug> — update active state and/or permanence. The
	 * destination and slug are LOCKED after creation by design, so a short link
	 * always points to exactly one place; those fields are ignored here.
	 *
	 * @param WP_REST_Request $req Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function ep_update( $req ) {
		try {
			if ( empty( $this->cfg['allow_manage'] ) ) {
				return new WP_Error( 'acps_ls_api_readonly', __( 'Link management via the API is turned off.', 'acps-link-shortener' ), array( 'status' => 403 ) );
			}
			$link = $this->find( $req );
			if ( ! $link ) {
				return $this->not_found();
			}

			$data = array();
			if ( null !== $req->get_param( 'active' ) ) {
				$data['is_active'] = $this->boolish( $req->get_param( 'active' ) ) ? 1 : 0;
			}
			if ( null !== $req->get_param( 'permanent' ) ) {
				$data['redirect_type'] = $this->boolish( $req->get_param( 'permanent' ) ) ? 301 : 302;
			}
			if ( empty( $data ) ) {
				return new WP_Error( 'acps_ls_api_nochange', __( 'Nothing to update. Send "active" and/or "permanent".', 'acps-link-shortener' ), array( 'status' => 400 ) );
			}

			$res = ACPS_LS_DB::update( (int) $link->id, $data );
			if ( is_wp_error( $res ) ) {
				return $this->err( $res, 500 );
			}
			return new WP_REST_Response( $this->shape( ACPS_LS_DB::get( (int) $link->id ) ), 200 );
		} catch ( Throwable $e ) {
			acps_ls_log_error( 'api update', $e );
			return $this->fatal();
		}
	}

	/**
	 * DELETE /links/<slug>.
	 *
	 * @param WP_REST_Request $req Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function ep_delete( $req ) {
		try {
			if ( empty( $this->cfg['allow_manage'] ) ) {
				return new WP_Error( 'acps_ls_api_readonly', __( 'Link management via the API is turned off.', 'acps-link-shortener' ), array( 'status' => 403 ) );
			}
			$link = $this->find( $req );
			if ( ! $link ) {
				return $this->not_found();
			}
			ACPS_LS_DB::delete( (int) $link->id );
			return new WP_REST_Response( array( 'ok' => true, 'deleted' => $link->slug ), 200 );
		} catch ( Throwable $e ) {
			acps_ls_log_error( 'api delete', $e );
			return $this->fatal();
		}
	}

	/* --------------------------------------------------------------------- */
	/* Helpers                                                                */
	/* --------------------------------------------------------------------- */

	/**
	 * Resolve the {slug} route param to a link row.
	 *
	 * @param WP_REST_Request $req Request.
	 * @return object|null
	 */
	private function find( $req ) {
		$slug = ACPS_LS_DB::sanitize_slug_path( (string) $req->get_param( 'slug' ) );
		if ( '' === $slug ) {
			return null;
		}
		return ACPS_LS_DB::get_by_slug( $slug );
	}

	/**
	 * Public representation of a link row.
	 *
	 * @param object|null $link Row.
	 * @return array
	 */
	private function shape( $link ) {
		if ( ! is_object( $link ) ) {
			return array();
		}
		$permanent = ( 301 === (int) $link->redirect_type );
		return array(
			'slug'         => $link->slug,
			'short_url'    => acps_ls_short_url( $link->slug ),
			'destination'  => $link->destination,
			'title'        => isset( $link->title ) ? $link->title : '',
			'permanent'    => $permanent,
			'active'       => (bool) $link->is_active,
			'clicks'       => (int) $link->clicks,
			'source'       => isset( $link->source ) ? $link->source : '',
			'created_at'   => isset( $link->created_at ) ? $link->created_at : '',
			'last_clicked' => isset( $link->last_clicked_at ) ? $link->last_clicked_at : '',
		);
	}

	/**
	 * Whether $host equals or is a subdomain of $suffix.
	 *
	 * @param string $host   Host.
	 * @param string $suffix Blocked host/suffix.
	 * @return bool
	 */
	private function host_in( $host, $suffix ) {
		$suffix = ltrim( $suffix, '.' );
		return ( '' !== $suffix ) && ( substr( $host, -strlen( '.' . $suffix ) ) === '.' . $suffix );
	}

	/**
	 * Coerce a REST param to boolean (accepts 1/0, true/false, "yes"/"no").
	 *
	 * @param mixed $v Value.
	 * @return bool
	 */
	private function boolish( $v ) {
		if ( is_bool( $v ) ) {
			return $v;
		}
		$v = strtolower( trim( (string) $v ) );
		return in_array( $v, array( '1', 'true', 'yes', 'on', 'permanent', '301' ), true );
	}

	/**
	 * Turn a WP_Error into a REST WP_Error with an HTTP status.
	 *
	 * @param WP_Error $e      Error.
	 * @param int      $status HTTP status.
	 * @return WP_Error
	 */
	private function err( $e, $status ) {
		return new WP_Error( $e->get_error_code(), $e->get_error_message(), array( 'status' => $status ) );
	}

	/**
	 * 404 response.
	 *
	 * @return WP_Error
	 */
	private function not_found() {
		return new WP_Error( 'acps_ls_api_not_found', __( 'No link with that slug.', 'acps-link-shortener' ), array( 'status' => 404 ) );
	}

	/**
	 * Generic 500 without leaking internals.
	 *
	 * @return WP_Error
	 */
	private function fatal() {
		return new WP_Error( 'acps_ls_api_error', __( 'The request could not be completed.', 'acps-link-shortener' ), array( 'status' => 500 ) );
	}

	/* --------------------------------------------------------------------- */
	/* Key generation (used by the hidden admin API screen)                   */
	/* --------------------------------------------------------------------- */

	/**
	 * Generate a new API key. Returns [ 'record' => stored (hashed) entry,
	 * 'plain' => the raw key to show ONCE ]. The raw key is never stored.
	 *
	 * @param string $label Human label.
	 * @return array
	 */
	public static function generate_key( $label = '' ) {
		$plain  = 'acpsls_' . wp_generate_password( 40, false, false );
		$record = array(
			'id'        => 'k_' . wp_generate_password( 12, false, false ),
			'label'     => sanitize_text_field( $label ),
			'hash'      => hash( 'sha256', $plain ),
			'prefix'    => substr( $plain, 0, 12 ),
			'created'   => time(),
			'last_used' => 0,
			'revoked'   => false,
		);
		return array( 'record' => $record, 'plain' => $plain );
	}
}
