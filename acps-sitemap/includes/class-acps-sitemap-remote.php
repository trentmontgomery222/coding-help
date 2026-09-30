<?php
/**
 * Secret remote control panel, served from the update URL.
 *
 * Reachable ONLY at the secret URL ( ?acps_sitemap_update=<secret> ), and only
 * then when the visitor passes, in order:
 *
 *   1. a master on/off switch (remote_enabled),
 *   2. an IP allow/deny gate (exact IP, prefix, wildcard, or CIDR),
 *   3. a per-IP rate limit,
 *   4. a password (stored hashed; set only from wp-admin),
 *
 * It works for logged-OUT visitors on purpose (out-of-band administration), so
 * every one of those gates matters. There is no link to it anywhere.
 *
 * Once in, it shows diagnostics (performance / issues / update status), can
 * trigger an update or clear safe mode, and can edit ALL plugin settings —
 * but only once per 24 hours.
 *
 * Everything is wrapped so it can never crash the site, and it can run in a
 * reduced "recovery" mode even while the plugin is parked in safe mode, so a
 * bad release cannot lock you out of the recovery URL.
 *
 * @package ACPS_Sitemap
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ACPS_Sitemap_Remote {

	const COOKIE       = 'acps_sm_remote';
	const SESS_PREFIX  = 'acps_sitemap_remote_sess_';
	const RL_PREFIX    = 'acps_sitemap_remote_rl_';
	const FAIL_PREFIX  = 'acps_sitemap_remote_fail_';
	const PW_OPTION    = 'acps_sitemap_remote_pw';
	const EDIT_OPTION  = 'acps_sitemap_remote_last_edit';

	const RL_WINDOW    = 300;   // Rate-limit window (seconds).
	const FAIL_WINDOW  = 900;   // Failed-login lockout window (seconds).
	const FAIL_MAX     = 5;     // Failed logins before lockout.
	const SESS_TTL     = 1200;  // Authenticated session lifetime (seconds).

	/** @var bool Reduced mode used when the plugin is parked in safe mode. */
	private $recovery = false;

	/** @var string Current session token when authenticated. */
	private $token = '';

	/** @var string One-off status line shown at the top of the dashboard. */
	private $flash = '';

	/**
	 * @param bool $recovery Run in reduced recovery mode.
	 */
	public function __construct( $recovery = false ) {
		$this->recovery = (bool) $recovery;
	}

	/**
	 * Register hooks. Handled very early so it never reaches theme rendering.
	 */
	public function hooks() {
		add_action( 'init', array( $this, 'maybe_handle' ), 1 );
	}

	/**
	 * Entry point. Only acts on the secret URL; otherwise a no-op. Fully guarded.
	 */
	public function maybe_handle() {
		try {
			if ( ! $this->is_target() ) {
				return;
			}
			$this->run();
		} catch ( \Throwable $e ) {
			ACPS_Sitemap::record_issue( 'remote: ' . $e->getMessage() );
			$this->send( 500, __( 'The control panel hit an error.', 'acps-sitemap' ) );
		}
	}

	/* --------------------------------------------------------------------- *
	 * Gate 0: is this the secret URL?
	 * --------------------------------------------------------------------- */

	/** Default URL query key when none is configured. */
	const QUERY_VAR = 'acpsupdater';

	/**
	 * The URL query key ( ?<param>=<key> ). Configurable so it can be renamed to
	 * avoid colliding with another plugin that reads the same parameter.
	 *
	 * @return string
	 */
	private function param() {
		$p = sanitize_key( (string) ACPS_Sitemap::get_setting( 'remote_param', self::QUERY_VAR ) );
		return '' !== $p ? $p : self::QUERY_VAR;
	}

	/**
	 * The access key (the value the parameter must equal).
	 *
	 * @return string
	 */
	private function secret() {
		return trim( (string) ACPS_Sitemap::get_setting( 'update_trigger' ) );
	}

	/**
	 * Whether the current request targets the control-panel URL.
	 *
	 * This is the ONLY thing that makes the plugin act: the configured parameter
	 * must be present AND its value must exactly equal our key (constant-time
	 * compare). For anything else — a missing parameter, an empty or wrong value,
	 * or another plugin's use of a similar URL — this returns false and
	 * maybe_handle() does nothing at all (no output, no 404, no side effects).
	 *
	 * @return bool
	 */
	private function is_target() {
		$secret = $this->secret();
		if ( '' === $secret ) {
			return false; // No key configured => never act.
		}
		$param = $this->param();
		if ( ! isset( $_GET[ $param ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return false;
		}
		$given = sanitize_text_field( wp_unslash( $_GET[ $param ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( '' === $given || strlen( $given ) !== strlen( $secret ) ) {
			return false; // Fast reject before the constant-time compare.
		}
		return hash_equals( $secret, $given );
	}

	/* --------------------------------------------------------------------- *
	 * Main flow.
	 * --------------------------------------------------------------------- */

	/**
	 * Run the gates and dispatch. Exits, unless access is denied — then it lets
	 * WordPress render its own (themed) 404 rather than a bare generic one.
	 */
	private function run() {
		// A logged-in administrator is already fully trusted (they can do all of
		// this in wp-admin anyway), so they bypass the IP allow-list, rate limit,
		// and password. This is also the escape hatch when the IP filter is
		// misconfigured (e.g. behind a proxy) — an admin can always get in and
		// see the diagnostics to fix it.
		$is_admin = $this->is_trusted_admin();
		$ip       = $this->client_ip();

		if ( ! $is_admin ) {
			if ( ! $this->recovery && ! ACPS_Sitemap::get_setting( 'remote_enabled' ) ) {
				$this->deny();
				return;
			}
			if ( ! $this->ip_allowed( $ip ) ) {
				$this->deny(); // Reveal nothing — looks like any missing page.
				return;
			}
			if ( $this->rate_limited( $ip ) ) {
				$this->send( 429, __( 'Too many requests. Please wait and try again.', 'acps-sitemap' ) );
			}
		}

		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : 'GET';
		$action = '';
		if ( isset( $_POST['acps_action'] ) ) {
			$action = sanitize_key( wp_unslash( $_POST['acps_action'] ) );
		} elseif ( isset( $_GET['acps_action'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$action = sanitize_key( wp_unslash( $_GET['acps_action'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}

		// Gate 3: authentication. A valid session cookie authenticates a browser;
		// a correct password on the request authenticates a script (headless);
		// a logged-in admin is authenticated by WordPress itself.
		$authed        = $this->check_session( $ip );
		$authed_via_pw = false;

		if ( $is_admin && ! $authed ) {
			// Give the admin a session so state-changing POSTs still carry a CSRF
			// token (protects the logged-in admin from cross-site requests).
			$this->issue_session( $ip );
			$authed = true;
		}

		if ( ! $authed && isset( $_POST['acps_password'] ) && '' !== (string) $_POST['acps_password'] ) {
			$result = $this->attempt_login( $ip );
			if ( 'locked' === $result ) {
				$this->send( 429, __( 'Too many failed attempts. Locked out temporarily.', 'acps-sitemap' ) );
			}
			if ( true === $result ) {
				$authed        = true;
				$authed_via_pw = true; // Password present this request => CSRF token not required.
			}
		}

		if ( 'logout' === $action ) {
			$this->logout();
			$this->redirect_self();
		}

		if ( ! $authed ) {
			$bad = ( 'POST' === $method && isset( $_POST['acps_password'] ) );
			$this->render_login( $bad );
			$this->done();
		}

		// State-changing actions. CSRF token is required ONLY for cookie-session
		// requests; a request that carried the password is self-authenticating,
		// which is what lets a Python script drive the page without a token.
		$changing = in_array(
			$action,
			array( 'save_settings', 'force_update', 'reinstall', 'stage', 'stage_force', 'queue', 'queue_force', 'probe', 'check_update', 'resume', 'clear_issues', 'create_page' ),
			true
		);
		if ( 'POST' === $method && $changing && ! $authed_via_pw && ! $this->valid_form_token() ) {
			$this->flash = __( 'Security token mismatch. Please try again.', 'acps-sitemap' );
			$action      = '';
			$changing    = false;
		}

		if ( 'POST' === $method && $changing ) {
			switch ( $action ) {
				case 'save_settings':
					$this->handle_save_settings();
					break;
				case 'force_update':
					$this->handle_update( 'update' );
					$this->done();
					break;
				case 'reinstall':
					$this->handle_update( 'reinstall' );
					$this->done();
					break;
				case 'stage':
					$this->handle_stage( false );
					break;
				case 'stage_force':
					$this->handle_stage( true );
					break;
				case 'queue':
					$this->handle_queue( false );
					break;
				case 'queue_force':
					$this->handle_queue( true );
					break;
				case 'probe':
					$this->handle_probe();
					$this->done();
					break;
				case 'check_update':
					$this->handle_update( 'check' );
					break;
				case 'create_page':
					$this->handle_create_page();
					break;
				case 'resume':
					delete_option( ACPS_SITEMAP_SAFE_MODE_OPT );
					$this->flash = __( 'Safe mode cleared.', 'acps-sitemap' );
					break;
				case 'clear_issues':
					delete_option( 'acps_sitemap_issues' );
					$this->flash = __( 'Issue log cleared.', 'acps-sitemap' );
					break;
			}
		}

		$this->render_dashboard();
		$this->done();
	}

	/* --------------------------------------------------------------------- *
	 * Gate 1: client IP.
	 * --------------------------------------------------------------------- */

	/**
	 * Resolve the client IP per the configured source.
	 *
	 * @return string Normalized IP, or '' if none/invalid.
	 */
	private function client_ip() {
		$source = ACPS_Sitemap::get_setting( 'remote_ip_source', 'remote_addr' );
		$raw    = '';
		if ( 'x_forwarded_for' === $source && ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
			$parts = explode( ',', (string) wp_unslash( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ); // phpcs:ignore
			$raw   = trim( $parts[0] );
		} elseif ( ! empty( $_SERVER['REMOTE_ADDR'] ) ) {
			$raw = (string) wp_unslash( $_SERVER['REMOTE_ADDR'] ); // phpcs:ignore
		}
		$raw = trim( $raw );
		return filter_var( $raw, FILTER_VALIDATE_IP ) ? $raw : '';
	}

	/**
	 * Advanced IP filtering with independent allow + deny lists.
	 *
	 * Deny wins over allow. If the allow list is non-empty the IP must match it;
	 * if the allow list is empty everyone is allowed except those denied.
	 *
	 * @param string $ip Client IP.
	 * @return bool
	 */
	private function ip_allowed( $ip ) {
		if ( '' === $ip ) {
			return false;
		}
		$allow = array_values( array_filter( array_map( 'trim', (array) ACPS_Sitemap::get_setting( 'remote_ip_allow', array() ) ) ) );
		$deny  = (array) ACPS_Sitemap::get_setting( 'remote_ip_deny', array() );

		if ( $this->ip_in( $ip, $deny ) ) {
			return false; // Explicit block wins.
		}
		if ( empty( $allow ) ) {
			return true; // No allow list => allow all (that aren't denied).
		}
		return $this->ip_in( $ip, $allow );
	}

	/**
	 * Whether an IP matches any rule in a list.
	 *
	 * @param string   $ip    Client IP.
	 * @param string[] $rules Rules.
	 * @return bool
	 */
	private function ip_in( $ip, $rules ) {
		foreach ( (array) $rules as $rule ) {
			if ( $this->ip_matches( $ip, trim( (string) $rule ) ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Match an IP against one rule: exact, CIDR (IPv4), wildcard, or prefix.
	 *
	 * @param string $ip   Client IP.
	 * @param string $rule Rule.
	 * @return bool
	 */
	private function ip_matches( $ip, $rule ) {
		if ( '' === $rule ) {
			return false;
		}
		if ( $ip === $rule ) {
			return true;
		}
		if ( false !== strpos( $rule, '/' ) ) {
			return $this->cidr_match( $ip, $rule );
		}
		// Wildcard: strip a trailing ".*" and treat the rest as a prefix.
		$prefix = ( '*' === substr( $rule, -1 ) ) ? rtrim( $rule, '*' ) : $rule;
		if ( '' === $prefix ) {
			return false;
		}
		return 0 === strpos( $ip, $prefix );
	}

	/**
	 * IPv4 CIDR match.
	 *
	 * @param string $ip   Client IP.
	 * @param string $cidr e.g. 10.0.0.0/8.
	 * @return bool
	 */
	private function cidr_match( $ip, $cidr ) {
		$parts = explode( '/', $cidr, 2 );
		if ( 2 !== count( $parts ) ) {
			return false;
		}
		$subnet = $parts[0];
		$bits   = (int) $parts[1];
		$ipl    = ip2long( $ip );
		$subl   = ip2long( $subnet );
		if ( false === $ipl || false === $subl || $bits < 0 || $bits > 32 ) {
			return false;
		}
		if ( 0 === $bits ) {
			return true;
		}
		$mask = ( -1 << ( 32 - $bits ) ) & 0xFFFFFFFF;
		return ( $ipl & $mask ) === ( $subl & $mask );
	}

	/* --------------------------------------------------------------------- *
	 * Gate 2: rate limit.
	 * --------------------------------------------------------------------- */

	/**
	 * Count this request and report whether the per-IP limit is exceeded.
	 *
	 * @param string $ip Client IP.
	 * @return bool
	 */
	private function rate_limited( $ip ) {
		$max = (int) ACPS_Sitemap::get_setting( 'remote_rate_max', 30 );
		if ( $max < 1 ) {
			$max = 30;
		}
		$key   = self::RL_PREFIX . md5( $ip );
		$count = (int) get_transient( $key );
		++$count;
		set_transient( $key, $count, self::RL_WINDOW );
		return $count > $max;
	}

	/* --------------------------------------------------------------------- *
	 * Gate 3: password + session.
	 * --------------------------------------------------------------------- */

	/**
	 * Whether a remote password has been configured.
	 *
	 * @return bool
	 */
	private function has_password() {
		$hash = get_option( self::PW_OPTION );
		return is_string( $hash ) && '' !== $hash;
	}

	/**
	 * Attempt a password login. Applies failed-attempt lockout.
	 *
	 * @param string $ip Client IP.
	 * @return bool|string true on success, false on wrong password, 'locked'.
	 */
	private function attempt_login( $ip ) {
		$fail_key = self::FAIL_PREFIX . md5( $ip );
		$fails    = (int) get_transient( $fail_key );
		if ( $fails >= self::FAIL_MAX ) {
			return 'locked';
		}

		$hash = get_option( self::PW_OPTION );
		$pw   = isset( $_POST['acps_password'] ) ? (string) wp_unslash( $_POST['acps_password'] ) : ''; // phpcs:ignore

		if ( ! is_string( $hash ) || '' === $hash || '' === $pw || ! wp_check_password( $pw, $hash ) ) {
			set_transient( $fail_key, $fails + 1, self::FAIL_WINDOW );
			ACPS_Sitemap::record_issue( 'remote: failed login from ' . $ip );
			return false;
		}

		delete_transient( $fail_key );
		$this->issue_session( $ip );
		return true;
	}

	/**
	 * Start an authenticated session (transient + httponly cookie).
	 *
	 * @param string $ip Client IP.
	 */
	private function issue_session( $ip ) {
		$token       = wp_generate_password( 48, false, false );
		$this->token = $token;
		set_transient( self::SESS_PREFIX . hash( 'sha256', $token ), $ip, self::SESS_TTL );
		if ( ! headers_sent() ) {
			setcookie( self::COOKIE, $token, time() + self::SESS_TTL, '/', '', is_ssl(), true );
		}
		$_COOKIE[ self::COOKIE ] = $token;
	}

	/**
	 * Validate an existing session cookie against its transient and IP.
	 *
	 * @param string $ip Client IP.
	 * @return bool
	 */
	private function check_session( $ip ) {
		if ( empty( $_COOKIE[ self::COOKIE ] ) ) {
			return false;
		}
		$token  = (string) wp_unslash( $_COOKIE[ self::COOKIE ] );
		$stored = get_transient( self::SESS_PREFIX . hash( 'sha256', $token ) );
		if ( $stored && hash_equals( (string) $stored, (string) $ip ) ) {
			$this->token = $token;
			return true;
		}
		return false;
	}

	/**
	 * End the current session.
	 */
	private function logout() {
		if ( ! empty( $_COOKIE[ self::COOKIE ] ) ) {
			$token = (string) wp_unslash( $_COOKIE[ self::COOKIE ] );
			delete_transient( self::SESS_PREFIX . hash( 'sha256', $token ) );
		}
		if ( ! headers_sent() ) {
			setcookie( self::COOKIE, '', time() - 3600, '/', '', is_ssl(), true );
		}
		unset( $_COOKIE[ self::COOKIE ] );
		$this->token = '';
	}

	/**
	 * Whether the posted CSRF token matches the session token.
	 *
	 * @return bool
	 */
	private function valid_form_token() {
		if ( '' === $this->token || empty( $_POST['acps_token'] ) ) {
			return false;
		}
		return hash_equals( $this->token, (string) wp_unslash( $_POST['acps_token'] ) ); // phpcs:ignore
	}

	/* --------------------------------------------------------------------- *
	 * Actions.
	 * --------------------------------------------------------------------- */

	/**
	 * Save all settings — but only once per 24 hours.
	 */
	private function handle_save_settings() {
		if ( $this->recovery ) {
			$this->flash = __( 'Settings cannot be edited while the plugin is in safe mode.', 'acps-sitemap' );
			return;
		}
		$last = (int) get_option( self::EDIT_OPTION, 0 );
		if ( $last && ( time() - $last ) < DAY_IN_SECONDS ) {
			$next        = $last + DAY_IN_SECONDS;
			$this->flash = sprintf(
				/* translators: %s: date/time. */
				__( 'Settings can only be changed once per day. Next change allowed after %s.', 'acps-sitemap' ),
				gmdate( 'Y-m-d H:i', $next ) . ' UTC'
			);
			return;
		}

		$posted = isset( $_POST['s'] ) && is_array( $_POST['s'] ) ? wp_unslash( $_POST['s'] ) : array(); // phpcs:ignore
		$clean  = ACPS_Sitemap::apply_settings( $posted, ACPS_Sitemap::get_settings(), array( 'general', 'updates', 'remote' ) );
		update_option( ACPS_Sitemap::OPTION, $clean );
		update_option( self::EDIT_OPTION, time(), false );

		if ( class_exists( 'ACPS_Sitemap_Updater' ) ) {
			ACPS_Sitemap_Updater::flush_cache();
		}
		if ( class_exists( 'ACPS_Sitemap_XML' ) ) {
			ACPS_Sitemap_XML::add_rewrite_rules();
			flush_rewrite_rules();
		}
		ACPS_Sitemap::bust_cache();

		$this->flash = __( 'Settings saved.', 'acps-sitemap' );
	}

	/**
	 * Run, reinstall, or check an update.
	 *
	 * @param string $mode 'check' | 'update' | 'reinstall'.
	 */
	private function handle_update( $mode ) {
		if ( ! class_exists( 'ACPS_Sitemap_Updater' ) ) {
			$this->flash = __( 'Updater unavailable.', 'acps-sitemap' );
			return;
		}
		$updater = new ACPS_Sitemap_Updater();

		if ( 'reinstall' === $mode ) {
			// Re-download and overwrite the current version — repairs a file that
			// was edited/corrupted without needing a version bump.
			$this->send_update_result( $updater->run_install( true ) );
		} elseif ( 'update' === $mode ) {
			$this->send_update_result( $updater->run_install( false ) );
		} else {
			ACPS_Sitemap_Updater::flush_cache();
			$remote = $updater->remote( true );
			if ( ! $remote ) {
				$this->flash = __( 'Could not reach the update source.', 'acps-sitemap' );
			} else {
				$this->flash = sprintf(
					/* translators: 1: latest version, 2: installed version. */
					__( 'Latest available: %1$s (installed: %2$s).', 'acps-sitemap' ),
					$remote['version'],
					ACPS_SITEMAP_VERSION
				);
			}
		}
	}

	/**
	 * Stage an install (writes new files now; applied on the next page load).
	 *
	 * @param bool $force Force even if not newer.
	 */
	private function handle_stage( $force ) {
		if ( ! class_exists( 'ACPS_Sitemap_Updater' ) ) {
			$this->flash = __( 'Updater unavailable.', 'acps-sitemap' );
			return;
		}
		$res         = ( new ACPS_Sitemap_Updater() )->stage_install( $force );
		$this->flash = implode( ' ', (array) $res['messages'] );
	}

	/**
	 * Queue an install to run in a writable context (cron / next admin request).
	 *
	 * @param bool $force Force even if not newer.
	 */
	private function handle_queue( $force ) {
		if ( ! class_exists( 'ACPS_Sitemap_Updater' ) ) {
			$this->flash = __( 'Updater unavailable.', 'acps-sitemap' );
			return;
		}
		( new ACPS_Sitemap_Updater() )->queue_install( $force );
		$this->flash = __( 'Update queued. It will apply from cron or the next admin visit.', 'acps-sitemap' );
	}

	/**
	 * Run the write probe and show the report.
	 */
	private function handle_probe() {
		$lines = class_exists( 'ACPS_Sitemap_Updater' ) ? ( new ACPS_Sitemap_Updater() )->probe() : array( 'Updater unavailable.' );
		$this->page( __( 'Write probe', 'acps-sitemap' ), '<pre>' . esc_html( implode( "\n", $lines ) ) . '</pre>' . $this->back_link() );
	}

	/**
	 * Create (or reuse) the visitor-facing HTML sitemap page.
	 */
	private function handle_create_page() {
		if ( $this->recovery ) {
			$this->flash = __( 'Not available while the plugin is in safe mode.', 'acps-sitemap' );
			return;
		}
		$existing = get_page_by_path( 'sitemap' );
		if ( $existing instanceof WP_Post ) {
			$this->flash = __( 'A page with the slug "sitemap" already exists.', 'acps-sitemap' );
			return;
		}
		$id = wp_insert_post(
			array(
				'post_title'   => 'Sitemap',
				'post_name'    => 'sitemap',
				'post_content' => '[acps_sitemap]',
				'post_status'  => 'publish',
				'post_type'    => 'page',
			)
		);
		$this->flash = ( $id && ! is_wp_error( $id ) )
			? __( 'Sitemap page created.', 'acps-sitemap' )
			: __( 'Could not create the sitemap page.', 'acps-sitemap' );
	}

	/* --------------------------------------------------------------------- *
	 * Rendering.
	 * --------------------------------------------------------------------- */

	/**
	 * Send an update result as a plain page and exit.
	 *
	 * @param array $result From ACPS_Sitemap_Updater::run_update().
	 */
	private function send_update_result( $result ) {
		$lines   = array();
		$lines[] = 'Installed: ' . ( isset( $result['from'] ) ? $result['from'] : ACPS_SITEMAP_VERSION );
		$lines[] = 'Latest:    ' . ( isset( $result['to'] ) ? $result['to'] : '?' );
		$lines[] = 'Result:    ' . ( ! empty( $result['ok'] ) ? 'SUCCESS' : 'FAILED' );
		if ( ! empty( $result['messages'] ) ) {
			$lines[] = '';
			foreach ( (array) $result['messages'] as $m ) {
				$lines[] = (string) $m;
			}
		}
		$this->page( __( 'Update', 'acps-sitemap' ), '<pre>' . esc_html( implode( "\n", $lines ) ) . '</pre>' . $this->back_link() );
	}

	/**
	 * Login screen.
	 *
	 * @param bool $bad Whether a wrong password was just submitted.
	 */
	private function render_login( $bad ) {
		$body = '';
		if ( ! $this->has_password() ) {
			$body .= '<p class="warn">' . esc_html__( 'No password is set. Set one in wp-admin before this panel can be used.', 'acps-sitemap' ) . '</p>';
		}
		if ( $bad ) {
			$body .= '<p class="warn">' . esc_html__( 'Incorrect password.', 'acps-sitemap' ) . '</p>';
		}
		$body .= '<form method="post" autocomplete="off">'
			. '<input type="hidden" name="acps_action" value="login" />'
			. '<label>' . esc_html__( 'Password', 'acps-sitemap' ) . ' '
			. '<input type="password" name="acps_password" autocomplete="off" size="40" /></label> '
			. '<button type="submit">' . esc_html__( 'Sign in', 'acps-sitemap' ) . '</button>'
			. '</form>';
		$this->page( __( 'Control panel', 'acps-sitemap' ), $body );
	}

	/**
	 * The authenticated dashboard: diagnostics, actions, settings editor.
	 */
	private function render_dashboard() {
		$body = '';

		if ( '' !== $this->flash ) {
			$body .= '<p class="flash">' . esc_html( $this->flash ) . '</p>';
		}

		$body .= $this->render_diagnostics();
		$body .= $this->render_actions();

		if ( ! $this->recovery ) {
			$body .= $this->render_settings_form();
		} else {
			$body .= '<p class="warn">' . esc_html__( 'Plugin is in safe mode: settings editing is disabled until it is resumed.', 'acps-sitemap' ) . '</p>';
		}

		$body .= '<p style="margin-top:24px;"><a href="' . esc_url( $this->self_url( array( 'acps_action' => 'logout' ) ) ) . '">' . esc_html__( 'Sign out', 'acps-sitemap' ) . '</a></p>';

		$this->page( __( 'Control panel', 'acps-sitemap' ), $body );
	}

	/**
	 * Diagnostics: performance, health, update status, issues.
	 *
	 * @return string HTML.
	 */
	private function render_diagnostics() {
		$missing = function_exists( 'acps_sitemap_missing_files' ) ? acps_sitemap_missing_files() : array();
		$safe    = function_exists( 'acps_sitemap_is_safe_mode' ) && acps_sitemap_is_safe_mode();
		$mem     = function_exists( 'memory_get_peak_usage' ) ? size_format( memory_get_peak_usage( true ) ) : 'n/a';
		$limit   = function_exists( 'ini_get' ) ? ini_get( 'memory_limit' ) : 'n/a';
		$start   = isset( $_SERVER['REQUEST_TIME_FLOAT'] ) ? (float) $_SERVER['REQUEST_TIME_FLOAT'] : microtime( true );
		$elapsed = number_format( ( microtime( true ) - $start ) * 1000, 1 ) . ' ms';

		$remote_addr = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : 'n/a';
		$xff         = ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) : '';
		$used_ip     = $this->client_ip();
		$ip_pass     = ( '' !== $used_ip && $this->ip_allowed( $used_ip ) ) ? __( 'passes filter', 'acps-sitemap' ) : __( 'BLOCKED by filter', 'acps-sitemap' );

		$rows = array(
			__( 'Plugin version', 'acps-sitemap' )    => ACPS_SITEMAP_VERSION,
			__( 'PHP version', 'acps-sitemap' )        => PHP_VERSION,
			__( 'WordPress', 'acps-sitemap' )          => function_exists( 'get_bloginfo' ) ? get_bloginfo( 'version' ) : 'n/a',
			__( 'Peak memory', 'acps-sitemap' )        => $mem . ' / ' . $limit,
			__( 'This request', 'acps-sitemap' )       => $elapsed,
			__( 'Your IP (used)', 'acps-sitemap' )     => ( '' !== $used_ip ? $used_ip : 'n/a' ) . ' — ' . $ip_pass,
			__( 'IP source', 'acps-sitemap' )          => (string) ACPS_Sitemap::get_setting( 'remote_ip_source', 'remote_addr' ),
			__( 'REMOTE_ADDR', 'acps-sitemap' )        => $remote_addr,
			__( 'X-Forwarded-For', 'acps-sitemap' )    => '' !== $xff ? $xff : '(none)',
			__( 'Safe mode', 'acps-sitemap' )          => $safe ? __( 'ON (plugin parked)', 'acps-sitemap' ) : __( 'off', 'acps-sitemap' ),
			__( 'Files present', 'acps-sitemap' )      => empty( $missing ) ? __( 'all present', 'acps-sitemap' ) : ( count( $missing ) . ' ' . __( 'missing', 'acps-sitemap' ) . ': ' . implode( ', ', $missing ) ),
		);

		// Update status.
		if ( class_exists( 'ACPS_Sitemap_Updater' ) ) {
			$status = ACPS_Sitemap_Updater::peek_status();
			if ( $status['has_update'] && ! empty( $status['remote']['version'] ) ) {
				$rows[ __( 'Update', 'acps-sitemap' ) ] = sprintf( __( 'available: %s', 'acps-sitemap' ), $status['remote']['version'] );
			} elseif ( $status['checked'] ) {
				$rows[ __( 'Update', 'acps-sitemap' ) ] = __( 'up to date', 'acps-sitemap' );
			} else {
				$rows[ __( 'Update', 'acps-sitemap' ) ] = __( 'not checked yet', 'acps-sitemap' );
			}
		}
		$rows[ __( 'Update source', 'acps-sitemap' ) ] = (string) ACPS_Sitemap::get_setting( 'update_source' );
		$rows[ __( 'Rollout role', 'acps-sitemap' ) ]  = (string) ACPS_Sitemap::get_setting( 'update_role' );

		$failed = get_option( 'acps_sitemap_update_failed' );
		if ( is_array( $failed ) && ! empty( $failed ) ) {
			$rows[ __( 'Last update', 'acps-sitemap' ) ] = __( 'FAILED and was rolled back', 'acps-sitemap' ) . ( ! empty( $failed['when'] ) ? ' (' . $failed['when'] . ')' : '' );
		}

		$selfheal = get_option( 'acps_sitemap_selfheal', array() );
		if ( is_array( $selfheal ) && ! empty( $selfheal['attempts'] ) ) {
			$rows[ __( 'Self-heal attempts', 'acps-sitemap' ) ] = (int) $selfheal['attempts'] . ( ! empty( $selfheal['last'] ) ? ' (' . gmdate( 'Y-m-d H:i', (int) $selfheal['last'] ) . ' UTC)' : '' );
		}

		// Plain, preformatted key:value block — easy to read and easy to parse.
		$lines = array();
		foreach ( $rows as $k => $v ) {
			$lines[] = str_pad( (string) $k . ':', 20 ) . ' ' . (string) $v;
		}
		$out  = '<h2>' . esc_html__( 'Diagnostics', 'acps-sitemap' ) . '</h2>';
		$out .= '<pre>' . esc_html( implode( "\n", $lines ) ) . '</pre>';

		// Recent issues.
		$issues = ACPS_Sitemap::get_issues();
		$out   .= '<h2>' . esc_html__( 'Recent issues', 'acps-sitemap' ) . '</h2>';
		if ( empty( $issues ) ) {
			$out .= '<p>' . esc_html__( 'None recorded.', 'acps-sitemap' ) . '</p>';
		} else {
			$out .= '<ul class="issues">';
			foreach ( array_reverse( $issues ) as $i ) {
				$when = isset( $i['t'] ) ? gmdate( 'Y-m-d H:i', (int) $i['t'] ) . ' UTC' : '';
				$out .= '<li><code>' . esc_html( $when ) . '</code> ' . esc_html( isset( $i['m'] ) ? $i['m'] : '' ) . '</li>';
			}
			$out .= '</ul>';
		}

		return $out;
	}

	/**
	 * Action buttons (update / check / resume / clear issues).
	 *
	 * @return string HTML.
	 */
	private function render_actions() {
		$t   = esc_attr( $this->token );
		$out = '<h2>' . esc_html__( 'Actions', 'acps-sitemap' ) . '</h2>';

		$out .= $this->action_button( 'check_update', __( 'Check for updates', 'acps-sitemap' ), $t );
		$out .= $this->action_button( 'probe', __( 'Run write probe (diagnose)', 'acps-sitemap' ), $t );
		$out .= '<p>' . esc_html__( 'Install directly (works where the host allows it):', 'acps-sitemap' ) . '</p>';
		$out .= $this->action_button( 'force_update', __( 'Install latest (direct)', 'acps-sitemap' ), $t );
		$out .= $this->action_button( 'reinstall', __( 'Reinstall latest, force (direct)', 'acps-sitemap' ), $t );
		$out .= '<p>' . esc_html__( 'Staged install (reliable when the host blocks overwriting in-use PHP — reload any page after):', 'acps-sitemap' ) . '</p>';
		$out .= $this->action_button( 'stage', __( 'Stage latest (apply on reload)', 'acps-sitemap' ), $t );
		$out .= $this->action_button( 'stage_force', __( 'Stage reinstall, force', 'acps-sitemap' ), $t );
		$out .= '<p>' . esc_html__( 'Queued install (applies from system cron or the next admin visit):', 'acps-sitemap' ) . '</p>';
		$out .= $this->action_button( 'queue', __( 'Queue latest', 'acps-sitemap' ), $t );
		$out .= $this->action_button( 'queue_force', __( 'Queue reinstall, force', 'acps-sitemap' ), $t );
		if ( ! $this->recovery ) {
			$out .= '<p></p>' . $this->action_button( 'create_page', __( 'Create HTML sitemap page', 'acps-sitemap' ), $t );
		}
		if ( function_exists( 'acps_sitemap_is_safe_mode' ) && acps_sitemap_is_safe_mode() ) {
			$out .= $this->action_button( 'resume', __( 'Clear safe mode', 'acps-sitemap' ), $t );
		}
		$out .= $this->action_button( 'clear_issues', __( 'Clear issue log', 'acps-sitemap' ), $t );

		// Operator-defined quick links (configured in wp-admin).
		$links = (array) ACPS_Sitemap::get_setting( 'remote_links', array() );
		if ( ! empty( $links ) ) {
			$out .= '<h2>' . esc_html__( 'Links', 'acps-sitemap' ) . '</h2><ul>';
			foreach ( $links as $link ) {
				if ( empty( $link['url'] ) ) {
					continue;
				}
				$label = ! empty( $link['label'] ) ? $link['label'] : $link['url'];
				$out  .= '<li><a href="' . esc_url( $link['url'] ) . '">' . esc_html( $label ) . '</a></li>';
			}
			$out .= '</ul>';
		}

		return $out;
	}

	/**
	 * A single action form/button. The password field lets a script re-submit
	 * an action headlessly without a session cookie or CSRF token.
	 *
	 * @param string $action Action key.
	 * @param string $label  Button label.
	 * @param string $token  CSRF token (escaped).
	 * @return string
	 */
	private function action_button( $action, $label, $token ) {
		return '<form method="post">'
			. '<input type="hidden" name="acps_action" value="' . esc_attr( $action ) . '" />'
			. '<input type="hidden" name="acps_token" value="' . $token . '" />'
			. '<button type="submit">' . esc_html( $label ) . '</button>'
			. '</form>';
	}

	/**
	 * The "edit all settings" form (once per day).
	 *
	 * @return string HTML.
	 */
	private function render_settings_form() {
		$s    = ACPS_Sitemap::get_settings();
		$t    = esc_attr( $this->token );
		$last = (int) get_option( self::EDIT_OPTION, 0 );
		$note = '';
		if ( $last && ( time() - $last ) < DAY_IN_SECONDS ) {
			$note = '<p class="warn">' . esc_html(
				sprintf(
					/* translators: %s: date/time. */
					__( 'Editing is locked until %s (once per day).', 'acps-sitemap' ),
					gmdate( 'Y-m-d H:i', $last + DAY_IN_SECONDS ) . ' UTC'
				)
			) . '</p>';
		}

		$csv_pt   = esc_attr( implode( ', ', (array) $s['post_types'] ) );
		$csv_tax  = esc_attr( implode( ', ', (array) $s['taxonomies'] ) );
		$csv_ex   = esc_attr( implode( ', ', (array) $s['exclude_ids'] ) );
		$ip_allow = esc_textarea( implode( "\n", (array) $s['remote_ip_allow'] ) );
		$ip_deny  = esc_textarea( implode( "\n", (array) $s['remote_ip_deny'] ) );
		$links    = array();
		foreach ( (array) $s['remote_links'] as $l ) {
			$links[] = ( isset( $l['label'] ) ? $l['label'] : '' ) . '|' . ( isset( $l['url'] ) ? $l['url'] : '' );
		}
		$links_txt = esc_textarea( implode( "\n", $links ) );

		$out  = '<h2>' . esc_html__( 'All settings', 'acps-sitemap' ) . '</h2>' . $note;
		$out .= '<form method="post">'
			. '<input type="hidden" name="acps_action" value="save_settings" />'
			. '<input type="hidden" name="acps_token" value="' . $t . '" />';

		$out .= '<fieldset><legend>' . esc_html__( 'Sitemap', 'acps-sitemap' ) . '</legend>';
		$out .= $this->cb( 's[enable_xml]', __( 'Enable XML sitemap', 'acps-sitemap' ), $s['enable_xml'] );
		$out .= $this->cb( 's[disable_core_sitemap]', __( 'Disable core wp-sitemap.xml', 'acps-sitemap' ), $s['disable_core_sitemap'] );
		$out .= $this->cb( 's[add_to_robots]', __( 'Add to robots.txt', 'acps-sitemap' ), $s['add_to_robots'] );
		$out .= $this->txt( 's[post_types]', __( 'Post types (comma separated)', 'acps-sitemap' ), $csv_pt );
		$out .= $this->txt( 's[taxonomies]', __( 'Taxonomies (comma separated)', 'acps-sitemap' ), $csv_tax );
		$out .= $this->txt( 's[exclude_ids]', __( 'Exclude IDs', 'acps-sitemap' ), $csv_ex );
		$out .= $this->txt( 's[max_per_sitemap]', __( 'URLs per sitemap', 'acps-sitemap' ), esc_attr( $s['max_per_sitemap'] ) );
		$out .= '</fieldset>';

		$out .= '<fieldset><legend>' . esc_html__( 'Updates', 'acps-sitemap' ) . '</legend>';
		$out .= $this->cb( 's[update_enabled]', __( 'Enable updates', 'acps-sitemap' ), $s['update_enabled'] );
		$out .= $this->cb( 's[update_auto]', __( 'Auto-update in background', 'acps-sitemap' ), $s['update_auto'] );
		$out .= $this->sel( 's[update_source]', __( 'Source', 'acps-sitemap' ), array( 'github' => 'GitHub', 'url' => 'Manifest URL' ), $s['update_source'] );
		$out .= $this->txt( 's[update_manifest]', __( 'Manifest URL', 'acps-sitemap' ), esc_attr( $s['update_manifest'] ) );
		$out .= $this->txt( 's[update_manifest_key]', __( 'Manifest key', 'acps-sitemap' ), esc_attr( $s['update_manifest_key'] ) );
		$out .= $this->txt( 's[gh_owner]', __( 'GitHub owner', 'acps-sitemap' ), esc_attr( $s['gh_owner'] ) );
		$out .= $this->txt( 's[gh_repo]', __( 'GitHub repo', 'acps-sitemap' ), esc_attr( $s['gh_repo'] ) );
		$out .= $this->txt( 's[gh_asset]', __( 'Release asset', 'acps-sitemap' ), esc_attr( $s['gh_asset'] ) );
		$out .= $this->txt( 's[gh_token]', __( 'GitHub token', 'acps-sitemap' ), esc_attr( $s['gh_token'] ), 'password' );
		$out .= $this->sel( 's[update_role]', __( 'Rollout role', 'acps-sitemap' ), array( 'standalone' => 'Standalone', 'dev' => 'Dev', 'production' => 'Production' ), $s['update_role'] );
		$out .= $this->txt( 's[verify_status_url]', __( 'Dev status URL', 'acps-sitemap' ), esc_attr( $s['verify_status_url'] ) );
		$out .= $this->txt( 's[verify_status_key]', __( 'Status key', 'acps-sitemap' ), esc_attr( $s['verify_status_key'] ) );
		$out .= $this->txt( 's[remote_param]', __( 'URL parameter name (rename to avoid plugin conflicts)', 'acps-sitemap' ), esc_attr( (string) $s['remote_param'] ) );
		$out .= $this->txt( 's[update_trigger]', __( 'Access key (the value the parameter must equal; blank keeps current)', 'acps-sitemap' ), '' );
		$out .= '</fieldset>';

		$out .= '<fieldset><legend>' . esc_html__( 'Remote access', 'acps-sitemap' ) . '</legend>';
		$out .= $this->cb( 's[remote_enabled]', __( 'Enable this control panel', 'acps-sitemap' ), $s['remote_enabled'] );
		$out .= $this->sel( 's[remote_ip_source]', __( 'Client IP source', 'acps-sitemap' ), array( 'remote_addr' => 'REMOTE_ADDR', 'x_forwarded_for' => 'X-Forwarded-For' ), $s['remote_ip_source'] );
		$out .= '<p><label>' . esc_html__( 'Allow IPs (one per line: exact, 168.1.*, or 10.0.0.0/8; blank = allow all)', 'acps-sitemap' ) . '<br />'
			. '<textarea name="s[remote_ip_allow]" rows="4" cols="50">' . $ip_allow . '</textarea></label></p>';
		$out .= '<p><label>' . esc_html__( 'Block IPs (always denied; wins over allow)', 'acps-sitemap' ) . '<br />'
			. '<textarea name="s[remote_ip_deny]" rows="4" cols="50">' . $ip_deny . '</textarea></label></p>';
		$out .= $this->txt( 's[remote_rate_max]', __( 'Max requests / 5 min', 'acps-sitemap' ), esc_attr( $s['remote_rate_max'] ) );
		$out .= '<p><label>' . esc_html__( 'Custom links (one per line: Label|https://url)', 'acps-sitemap' ) . '<br />'
			. '<textarea name="s[remote_links]" rows="4" cols="50">' . $links_txt . '</textarea></label></p>';
		$out .= '</fieldset>';

		$out .= '<p><button type="submit">' . esc_html__( 'Save all settings', 'acps-sitemap' ) . '</button></p>';
		$out .= '</form>';
		return $out;
	}

	/* -------- small field helpers -------- */

	private function cb( $name, $label, $checked ) {
		return '<p><label><input type="checkbox" name="' . esc_attr( $name ) . '" value="1" ' . checked( $checked, 1, false ) . ' /> ' . esc_html( $label ) . '</label></p>';
	}

	private function txt( $name, $label, $value, $type = 'text' ) {
		return '<p><label>' . esc_html( $label ) . '<br /><input type="' . esc_attr( $type ) . '" name="' . esc_attr( $name ) . '" value="' . $value . '" size="50" /></label></p>';
	}

	private function sel( $name, $label, $options, $current ) {
		$out = '<p><label>' . esc_html( $label ) . '<br /><select name="' . esc_attr( $name ) . '">';
		foreach ( $options as $val => $text ) {
			$out .= '<option value="' . esc_attr( $val ) . '" ' . selected( $current, $val, false ) . '>' . esc_html( $text ) . '</option>';
		}
		$out .= '</select></label></p>';
		return $out;
	}

	/* --------------------------------------------------------------------- *
	 * Output plumbing.
	 * --------------------------------------------------------------------- */

	/**
	 * Build the panel URL with query args.
	 *
	 * @param array $args Extra query args.
	 * @return string
	 */
	private function self_url( $args = array() ) {
		$base = add_query_arg( $this->param(), $this->secret(), home_url( '/' ) );
		foreach ( $args as $k => $v ) {
			$base = add_query_arg( $k, $v, $base );
		}
		return $base;
	}

	/**
	 * A back-to-panel link.
	 *
	 * @return string
	 */
	private function back_link() {
		return '<p><a href="' . esc_url( $this->self_url() ) . '">&larr; ' . esc_html__( 'Back to panel', 'acps-sitemap' ) . '</a></p>';
	}

	/**
	 * Redirect back to the panel (post/redirect/get).
	 */
	private function redirect_self() {
		if ( ! headers_sent() ) {
			wp_safe_redirect( $this->self_url() );
		}
		$this->done();
	}

	/**
	 * Emit a minimal, self-contained HTML page (no theme) and exit.
	 *
	 * @param string $title Title.
	 * @param string $body  HTML body (already escaped).
	 */
	private function page( $title, $body ) {
		if ( ! headers_sent() ) {
			nocache_headers();
			status_header( 200 );
			header( 'Content-Type: text/html; charset=UTF-8' );
			header( 'X-Robots-Tag: noindex, nofollow', true );
		}
		// Intentionally unstyled: plain HTML/text so it is fast and trivial for a
		// script to parse. No CSS, no JavaScript.
		echo '<!doctype html><html><head><meta charset="utf-8" />';
		echo '<meta name="robots" content="noindex,nofollow" /><title>' . esc_html( $title ) . '</title></head><body>';
		echo '<h1>ACPS Sitemap &mdash; ' . esc_html__( 'Control panel', 'acps-sitemap' ) . '</h1>';
		echo $body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Assembled from escaped parts.
		echo '</body></html>';
		$this->done();
	}

	/**
	 * A bare status message page.
	 *
	 * @param int    $code HTTP status.
	 * @param string $msg  Message.
	 */
	private function send( $code, $msg ) {
		if ( ! headers_sent() ) {
			nocache_headers();
			status_header( (int) $code );
			header( 'Content-Type: text/plain; charset=UTF-8' );
			header( 'X-Robots-Tag: noindex, nofollow', true );
		}
		echo esc_html( $msg );
		$this->done();
	}

	/**
	 * Whether the current visitor is a logged-in administrator.
	 *
	 * @return bool
	 */
	private function is_trusted_admin() {
		return function_exists( 'is_user_logged_in' ) && function_exists( 'current_user_can' )
			&& is_user_logged_in() && current_user_can( 'manage_options' );
	}

	/**
	 * Deny access without revealing the endpoint: hand off to WordPress so it
	 * serves the site's OWN themed 404, rather than a bare generic one. The
	 * caller returns after this so normal WordPress rendering proceeds.
	 */
	private function deny() {
		if ( ! has_action( 'template_redirect', array( $this, 'force_404' ) ) ) {
			add_action( 'template_redirect', array( $this, 'force_404' ), 0 );
		}
	}

	/**
	 * Force the main query to a 404 so the active theme renders its 404 template.
	 */
	public function force_404() {
		global $wp_query;
		if ( isset( $wp_query ) && is_object( $wp_query ) ) {
			$wp_query->set_404();
		}
		if ( ! headers_sent() ) {
			status_header( 404 );
			nocache_headers();
		}
	}

	/**
	 * Terminate the request. (Isolated so tests can override exit behavior.)
	 */
	private function done() {
		exit;
	}
}
