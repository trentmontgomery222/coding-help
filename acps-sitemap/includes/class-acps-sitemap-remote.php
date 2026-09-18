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

	/**
	 * The shared secret (same value used to build the URL).
	 *
	 * @return string
	 */
	private function secret() {
		return trim( (string) ACPS_Sitemap::get_setting( 'update_trigger' ) );
	}

	/**
	 * Whether the current request targets the secret URL.
	 *
	 * @return bool
	 */
	private function is_target() {
		$secret = $this->secret();
		if ( '' === $secret ) {
			return false;
		}
		if ( isset( $_GET['acps_sitemap_update'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return hash_equals( $secret, sanitize_text_field( wp_unslash( $_GET['acps_sitemap_update'] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}
		if ( isset( $_SERVER['REQUEST_URI'] ) ) {
			$path = trim( (string) wp_parse_url( wp_unslash( $_SERVER['REQUEST_URI'] ), PHP_URL_PATH ), '/' ); // phpcs:ignore
			if ( '' !== $path && hash_equals( $secret, $path ) ) {
				return true;
			}
		}
		return false;
	}

	/* --------------------------------------------------------------------- *
	 * Main flow.
	 * --------------------------------------------------------------------- */

	/**
	 * Run the gates and dispatch. Always exits.
	 */
	private function run() {
		if ( ! $this->recovery && ! ACPS_Sitemap::get_setting( 'remote_enabled' ) ) {
			$this->not_found();
		}

		$ip = $this->client_ip();

		// Gate 1: IP.
		if ( ! $this->ip_allowed( $ip ) ) {
			$this->not_found(); // Reveal nothing.
		}

		// Gate 2: rate limit.
		if ( $this->rate_limited( $ip ) ) {
			$this->send( 429, __( 'Too many requests. Please wait and try again.', 'acps-sitemap' ) );
		}

		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : 'GET';
		$action = '';
		if ( isset( $_POST['acps_action'] ) ) {
			$action = sanitize_key( wp_unslash( $_POST['acps_action'] ) );
		} elseif ( isset( $_GET['acps_action'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$action = sanitize_key( wp_unslash( $_GET['acps_action'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}

		// Gate 3: authentication.
		$authed = $this->check_session( $ip );

		if ( ! $authed && 'POST' === $method && isset( $_POST['acps_password'] ) ) {
			$result = $this->attempt_login( $ip );
			if ( 'locked' === $result ) {
				$this->send( 429, __( 'Too many failed attempts. Locked out temporarily.', 'acps-sitemap' ) );
			}
			$authed = ( true === $result );
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

		// Authenticated. Guard state-changing actions with a CSRF form token.
		$changing = in_array( $action, array( 'save_settings', 'force_update', 'check_update', 'resume', 'clear_issues' ), true );
		if ( 'POST' === $method && $changing && ! $this->valid_form_token() ) {
			$this->flash = __( 'Security token mismatch. Please try again.', 'acps-sitemap' );
			$action      = '';
		}

		if ( 'POST' === $method && $changing ) {
			switch ( $action ) {
				case 'save_settings':
					$this->handle_save_settings();
					break;
				case 'force_update':
					$this->handle_update( true );
					$this->done();
					break;
				case 'check_update':
					$this->handle_update( false );
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
	 * Whether an IP passes the allow/deny list.
	 *
	 * @param string $ip Client IP.
	 * @return bool
	 */
	private function ip_allowed( $ip ) {
		if ( '' === $ip ) {
			return false;
		}
		$mode  = ACPS_Sitemap::get_setting( 'remote_ip_mode', 'allow' );
		$rules = (array) ACPS_Sitemap::get_setting( 'remote_ip_list', array() );

		$matched = false;
		foreach ( $rules as $rule ) {
			if ( $this->ip_matches( $ip, trim( (string) $rule ) ) ) {
				$matched = true;
				break;
			}
		}
		return ( 'deny' === $mode ) ? ! $matched : $matched;
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
	 * Run or check an update.
	 *
	 * @param bool $install Whether to install (true) or only check (false).
	 */
	private function handle_update( $install ) {
		if ( ! class_exists( 'ACPS_Sitemap_Updater' ) ) {
			$this->flash = __( 'Updater unavailable.', 'acps-sitemap' );
			return;
		}
		$updater = new ACPS_Sitemap_Updater();
		if ( $install ) {
			$result = $updater->run_update();
			$this->send_update_result( $result );
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
			. '<label>' . esc_html__( 'Password', 'acps-sitemap' ) . '<br />'
			. '<input type="password" name="acps_password" autocomplete="off" style="width:100%;padding:8px;" /></label>'
			. '<p><button type="submit" style="padding:8px 16px;">' . esc_html__( 'Sign in', 'acps-sitemap' ) . '</button></p>'
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

		$rows = array(
			__( 'Plugin version', 'acps-sitemap' )   => ACPS_SITEMAP_VERSION,
			__( 'PHP version', 'acps-sitemap' )       => PHP_VERSION,
			__( 'WordPress', 'acps-sitemap' )         => function_exists( 'get_bloginfo' ) ? get_bloginfo( 'version' ) : 'n/a',
			__( 'Peak memory', 'acps-sitemap' )       => $mem . ' / ' . $limit,
			__( 'This request', 'acps-sitemap' )      => $elapsed,
			__( 'Safe mode', 'acps-sitemap' )         => $safe ? __( 'ON (plugin parked)', 'acps-sitemap' ) : __( 'off', 'acps-sitemap' ),
			__( 'Files present', 'acps-sitemap' )     => empty( $missing ) ? __( 'all present', 'acps-sitemap' ) : ( count( $missing ) . ' ' . __( 'missing', 'acps-sitemap' ) . ': ' . implode( ', ', $missing ) ),
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

		$out = '<h2>' . esc_html__( 'Diagnostics', 'acps-sitemap' ) . '</h2><table class="diag">';
		foreach ( $rows as $k => $v ) {
			$out .= '<tr><th>' . esc_html( $k ) . '</th><td>' . esc_html( $v ) . '</td></tr>';
		}
		$out .= '</table>';

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
		$out = '<h2>' . esc_html__( 'Actions', 'acps-sitemap' ) . '</h2><div class="actions">';

		$out .= $this->action_button( 'check_update', __( 'Check for updates', 'acps-sitemap' ), $t );
		$out .= $this->action_button( 'force_update', __( 'Install update now', 'acps-sitemap' ), $t );
		if ( function_exists( 'acps_sitemap_is_safe_mode' ) && acps_sitemap_is_safe_mode() ) {
			$out .= $this->action_button( 'resume', __( 'Clear safe mode', 'acps-sitemap' ), $t );
		}
		$out .= $this->action_button( 'clear_issues', __( 'Clear issue log', 'acps-sitemap' ), $t );

		$out .= '</div>';
		return $out;
	}

	/**
	 * A single action form/button.
	 *
	 * @param string $action Action key.
	 * @param string $label  Button label.
	 * @param string $token  CSRF token (escaped).
	 * @return string
	 */
	private function action_button( $action, $label, $token ) {
		return '<form method="post" style="display:inline-block;margin:0 8px 8px 0;">'
			. '<input type="hidden" name="acps_action" value="' . esc_attr( $action ) . '" />'
			. '<input type="hidden" name="acps_token" value="' . $token . '" />'
			. '<button type="submit" style="padding:6px 12px;">' . esc_html( $label ) . '</button>'
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

		$csv_pt  = esc_attr( implode( ', ', (array) $s['post_types'] ) );
		$csv_tax = esc_attr( implode( ', ', (array) $s['taxonomies'] ) );
		$csv_ex  = esc_attr( implode( ', ', (array) $s['exclude_ids'] ) );
		$ips     = esc_textarea( implode( "\n", (array) $s['remote_ip_list'] ) );

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
		$out .= '</fieldset>';

		$out .= '<fieldset><legend>' . esc_html__( 'Remote access', 'acps-sitemap' ) . '</legend>';
		$out .= $this->cb( 's[remote_enabled]', __( 'Enable this control panel', 'acps-sitemap' ), $s['remote_enabled'] );
		$out .= $this->sel( 's[remote_ip_mode]', __( 'IP list mode', 'acps-sitemap' ), array( 'allow' => __( 'Allow only listed', 'acps-sitemap' ), 'deny' => __( 'Block listed', 'acps-sitemap' ) ), $s['remote_ip_mode'] );
		$out .= $this->sel( 's[remote_ip_source]', __( 'Client IP source', 'acps-sitemap' ), array( 'remote_addr' => 'REMOTE_ADDR', 'x_forwarded_for' => 'X-Forwarded-For' ), $s['remote_ip_source'] );
		$out .= '<p><label>' . esc_html__( 'IP rules (one per line: exact, 196.168.*, or 10.0.0.0/8)', 'acps-sitemap' ) . '<br />'
			. '<textarea name="s[remote_ip_list]" rows="4" style="width:100%;">' . $ips . '</textarea></label></p>';
		$out .= $this->txt( 's[remote_rate_max]', __( 'Max requests / 5 min', 'acps-sitemap' ), esc_attr( $s['remote_rate_max'] ) );
		$out .= '</fieldset>';

		$out .= '<p><button type="submit" style="padding:8px 16px;">' . esc_html__( 'Save all settings', 'acps-sitemap' ) . '</button></p>';
		$out .= '</form>';
		return $out;
	}

	/* -------- small field helpers -------- */

	private function cb( $name, $label, $checked ) {
		return '<p><label><input type="checkbox" name="' . esc_attr( $name ) . '" value="1" ' . checked( $checked, 1, false ) . ' /> ' . esc_html( $label ) . '</label></p>';
	}

	private function txt( $name, $label, $value, $type = 'text' ) {
		return '<p><label>' . esc_html( $label ) . '<br /><input type="' . esc_attr( $type ) . '" name="' . esc_attr( $name ) . '" value="' . $value . '" style="width:100%;padding:6px;" /></label></p>';
	}

	private function sel( $name, $label, $options, $current ) {
		$out = '<p><label>' . esc_html( $label ) . '<br /><select name="' . esc_attr( $name ) . '" style="padding:6px;">';
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
		$base = add_query_arg( 'acps_sitemap_update', $this->secret(), home_url( '/' ) );
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
		echo '<!doctype html><html><head><meta charset="utf-8" /><meta name="viewport" content="width=device-width, initial-scale=1" />';
		echo '<meta name="robots" content="noindex,nofollow" /><title>' . esc_html( $title ) . '</title>';
		echo '<style>'
			. 'body{font:14px/1.5 -apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;max-width:820px;margin:32px auto;padding:0 16px;color:#1d2327;background:#f6f7f7;}'
			. 'h1{font-size:20px;} h2{font-size:15px;margin-top:24px;border-bottom:1px solid #dcdcde;padding-bottom:4px;}'
			. 'table.diag{border-collapse:collapse;width:100%;} table.diag th{ text-align:left;width:180px;color:#50575e;font-weight:600;padding:3px 8px;vertical-align:top;} table.diag td{padding:3px 8px;}'
			. 'fieldset{border:1px solid #dcdcde;margin:0 0 16px;padding:8px 16px;background:#fff;} legend{font-weight:600;padding:0 6px;}'
			. 'input,select,textarea{font:inherit;box-sizing:border-box;} label{display:block;}'
			. '.flash{background:#e6f4ea;border:1px solid #46b450;padding:8px 12px;} .warn{background:#fcf0f1;border:1px solid #dc3232;padding:8px 12px;}'
			. 'ul.issues{list-style:none;padding:0;} ul.issues li{padding:2px 0;border-bottom:1px solid #eee;}'
			. 'pre{background:#fff;border:1px solid #dcdcde;padding:12px;overflow:auto;}'
			. '</style></head><body>';
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
	 * Behave like the URL does not exist.
	 */
	private function not_found() {
		if ( ! headers_sent() ) {
			status_header( 404 );
			header( 'Content-Type: text/plain; charset=UTF-8' );
		}
		echo 'Not found.';
		$this->done();
	}

	/**
	 * Terminate the request. (Isolated so tests can override exit behavior.)
	 */
	private function done() {
		exit;
	}
}
