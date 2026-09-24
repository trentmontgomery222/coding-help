<?php
/**
 * The unlisted remote maintenance console.
 *
 * Reached only by typing the secret URL — there is no link to it anywhere. It
 * lets an operator, from outside wp-admin, read the plugin's health and edit a
 * subset of its settings, behind several independent gates:
 *
 *   - address gate: only requests from an allowed IP (or, in deny mode, any
 *     address not on the block list) get past the front door;
 *   - rate limit: a per-address request cap over a short window;
 *   - password: a shared password whose hash is set only from wp-admin;
 *   - lockout: repeated wrong passwords lock an address out for a while;
 *   - edit throttle: settings may be written at most once per configured window
 *     (a day by default).
 *
 * The password and the address rules are deliberately NOT editable here — they
 * can only be changed by a logged-in administrator, so this console can never
 * be used to widen its own access.
 *
 * @package ACPS_Alert_Popups
 */

defined( 'ABSPATH' ) || exit;

/**
 * Remote console.
 */
class ACPS_Alerts_Panel {

	const QUERY_VAR    = 'acps_ap_console';
	const COOKIE       = 'acps_ap_console_sess';
	const SESSION_TTL  = 1800; // 30 minutes.
	const LAST_EDIT    = 'acps_alerts_panel_last_edit';

	/**
	 * The updater, for status output and manifest checks.
	 *
	 * @var ACPS_Alerts_Updater|null
	 */
	protected $updater;

	/**
	 * Constructor.
	 *
	 * @param ACPS_Alerts_Updater|null $updater Updater instance.
	 */
	public function __construct( $updater = null ) {
		$this->updater = $updater;
	}

	/**
	 * Registers the request handler.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'init', array( $this, 'maybe_handle' ), 3 );
	}

	/**
	 * Detects a console request and dispatches it.
	 *
	 * @return void
	 */
	public function maybe_handle() {
		if ( ! isset( $_GET[ self::QUERY_VAR ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}

		$secret = trim( (string) ACPS_Alerts_Settings::get( 'update_secret' ) );
		$given  = sanitize_text_field( wp_unslash( $_GET[ self::QUERY_VAR ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		// A wrong or absent secret is indistinguishable from any other URL: 404,
		// so the console leaks nothing about its own existence.
		if ( '' === $secret || ! hash_equals( $secret, $given ) ) {
			return;
		}

		if ( ! ACPS_Alerts_Settings::get( 'panel_enabled' ) ) {
			$this->deny_404();
		}

		try {
			$this->dispatch();
		} catch ( \Throwable $e ) {
			ACPS_Alerts_Failsafe::log( $e->getMessage(), 'panel' );
			$this->send( 500, __( 'The console hit an error.', 'acps-alert-popups' ) );
		}
	}

	/**
	 * Runs the gates in order, then routes the request.
	 *
	 * @return void
	 */
	protected function dispatch() {
		nocache_headers();

		$ip = $this->client_ip();

		if ( ! $this->ip_allowed( $ip ) ) {
			$this->deny_404();
		}

		if ( $this->is_locked_out( $ip ) ) {
			$this->send( 429, __( 'Too many failed attempts. Try again later.', 'acps-alert-popups' ) );
		}

		if ( ! $this->rate_ok( $ip ) ) {
			$this->send( 429, __( 'Rate limit reached. Slow down and try again shortly.', 'acps-alert-popups' ) );
		}

		$action = isset( $_POST['acps_console_action'] ) ? sanitize_key( wp_unslash( $_POST['acps_console_action'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing

		if ( 'login' === $action ) {
			$this->handle_login( $ip );

			return;
		}

		if ( 'logout' === $action ) {
			$this->clear_session();
			$this->redirect_self();
		}

		if ( ! $this->authenticated( $ip ) ) {
			$this->render_login();

			return;
		}

		if ( 'save' === $action ) {
			$this->handle_save( $ip );

			return;
		}

		if ( 'clear' === $action ) {
			// Not throttled like a settings write: clearing the log and closing
			// the breakers changes no configuration, and is exactly what an
			// operator needs to do after fixing something.
			ACPS_Alerts_Failsafe::clear_problems();
			ACPS_Alerts_Failsafe::reset_breakers();

			$this->render_console( __( 'Problem log cleared and every switched-off part re-enabled.', 'acps-alert-popups' ), 'ok' );

			return;
		}

		$this->render_console();
	}

	/* ------------------------------------------------------------------ *
	 * Address gate.
	 * ------------------------------------------------------------------ */

	/**
	 * The client IP, honoring forwarded-for headers when the proxy option is on.
	 *
	 * @return string
	 */
	public function client_ip() {
		$remote = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : '';

		if ( ACPS_Alerts_Settings::get( 'panel_proxy' ) ) {
			$headers = array( 'HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP' );

			foreach ( $headers as $header ) {
				if ( empty( $_SERVER[ $header ] ) ) {
					continue;
				}

				$parts = explode( ',', (string) $_SERVER[ $header ] );
				$candidate = trim( $parts[0] );

				if ( '' !== $candidate ) {
					$remote = $candidate;
					break;
				}
			}
		}

		return preg_replace( '/[^0-9a-f:.]/i', '', $remote );
	}

	/**
	 * Whether an address may reach the console, per the allow/deny rules.
	 *
	 * @param string $ip Client address.
	 * @return bool
	 */
	public function ip_allowed( $ip ) {
		$mode  = ACPS_Alerts_Settings::get( 'panel_ip_mode' );
		$rules = preg_split( '/[\r\n]+/', (string) ACPS_Alerts_Settings::get( 'panel_ips' ) );
		$rules = array_filter( array_map( 'trim', (array) $rules ) );

		$matched = false;

		foreach ( $rules as $rule ) {
			if ( self::ip_matches( $ip, $rule ) ) {
				$matched = true;
				break;
			}
		}

		// allow mode: only listed addresses pass (empty list = allow none).
		// deny mode: everyone passes except listed addresses.
		return ( 'deny' === $mode ) ? ! $matched : $matched;
	}

	/**
	 * Whether an address matches one rule: exact, prefix (192.168. or
	 * 192.168.*), or CIDR (10.0.0.0/8).
	 *
	 * @param string $ip   Address.
	 * @param string $rule Rule.
	 * @return bool
	 */
	public static function ip_matches( $ip, $rule ) {
		$ip   = trim( (string) $ip );
		$rule = trim( (string) $rule );

		if ( '' === $ip || '' === $rule ) {
			return false;
		}

		if ( $ip === $rule ) {
			return true;
		}

		// CIDR.
		if ( false !== strpos( $rule, '/' ) ) {
			return self::cidr_match( $ip, $rule );
		}

		// Wildcard / prefix written with a trailing dot or star: 192.168. or
		// 192.168.*  — both mean "starts with 192.168.".
		if ( '*' === substr( $rule, -1 ) || '.' === substr( $rule, -1 ) ) {
			$prefix = rtrim( $rule, '*' );

			return 0 === strpos( $ip, $prefix );
		}

		// A bare partial IPv4 — one to three octets, no trailing dot or star,
		// e.g. 196.168 or 10 — is treated as an octet-boundary prefix, so
		// 196.168 matches 196.168.x.x but not 196.1689.x. The boundary is a
		// real dot, which is what keeps 10 from matching 100.x.x.x.
		if ( preg_match( '/^\d{1,3}(\.\d{1,3}){0,2}$/', $rule ) ) {
			return 0 === strpos( $ip, $rule . '.' );
		}

		return false;
	}

	/**
	 * IPv4/IPv6 CIDR match.
	 *
	 * @param string $ip   Address.
	 * @param string $cidr CIDR rule.
	 * @return bool
	 */
	protected static function cidr_match( $ip, $cidr ) {
		list( $subnet, $bits ) = array_pad( explode( '/', $cidr, 2 ), 2, null );

		$ip_bin     = @inet_pton( $ip );
		$subnet_bin = @inet_pton( $subnet );

		if ( false === $ip_bin || false === $subnet_bin || strlen( $ip_bin ) !== strlen( $subnet_bin ) ) {
			return false;
		}

		$bits = (int) $bits;
		$len  = strlen( $ip_bin );

		if ( $bits < 0 || $bits > $len * 8 ) {
			return false;
		}

		$bytes = intdiv( $bits, 8 );
		$rem   = $bits % 8;

		if ( $bytes > 0 && 0 !== substr_compare( $ip_bin, $subnet_bin, 0, $bytes ) ) {
			return false;
		}

		if ( 0 === $rem ) {
			return true;
		}

		$mask = chr( 0xFF << ( 8 - $rem ) & 0xFF );

		return ( $ip_bin[ $bytes ] & $mask ) === ( $subnet_bin[ $bytes ] & $mask );
	}

	/* ------------------------------------------------------------------ *
	 * Rate limiting and lockout.
	 * ------------------------------------------------------------------ */

	/**
	 * Whether the address is under its request cap for the window.
	 *
	 * @param string $ip Address.
	 * @return bool
	 */
	protected function rate_ok( $ip ) {
		$max    = (int) ACPS_Alerts_Settings::get( 'panel_rate_max' );
		$window = (int) ACPS_Alerts_Settings::get( 'panel_rate_win' );
		$key    = 'acps_ap_rate_' . md5( $ip );

		$hits = (int) get_transient( $key );
		$hits++;

		set_transient( $key, $hits, $window );

		return $hits <= $max;
	}

	/**
	 * Whether the address is currently locked out for failed passwords.
	 *
	 * @param string $ip Address.
	 * @return bool
	 */
	protected function is_locked_out( $ip ) {
		return (bool) get_transient( 'acps_ap_lock_' . md5( $ip ) );
	}

	/**
	 * Records a failed password and locks the address out past the threshold.
	 *
	 * @param string $ip Address.
	 * @return void
	 */
	protected function note_failure( $ip ) {
		$max   = (int) ACPS_Alerts_Settings::get( 'panel_max_fails' );
		$mins  = (int) ACPS_Alerts_Settings::get( 'panel_lock_mins' );
		$key   = 'acps_ap_fails_' . md5( $ip );
		$fails = (int) get_transient( $key ) + 1;

		set_transient( $key, $fails, $mins * MINUTE_IN_SECONDS );

		if ( $fails >= $max ) {
			set_transient( 'acps_ap_lock_' . md5( $ip ), 1, $mins * MINUTE_IN_SECONDS );
			delete_transient( $key );
		}
	}

	/* ------------------------------------------------------------------ *
	 * Session / password.
	 * ------------------------------------------------------------------ */

	/**
	 * Handles a login POST.
	 *
	 * @param string $ip Address.
	 * @return void
	 */
	protected function handle_login( $ip ) {
		$hash = (string) ACPS_Alerts_Settings::get( 'panel_password' );

		if ( '' === $hash ) {
			$this->render_login( __( 'No console password is set. An administrator must set one in wp-admin first.', 'acps-alert-popups' ) );

			return;
		}

		$password = isset( $_POST['acps_console_password'] ) ? (string) wp_unslash( $_POST['acps_console_password'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing

		if ( '' === $password || ! wp_check_password( $password, $hash ) ) {
			$this->note_failure( $ip );
			$this->render_login( __( 'Wrong password.', 'acps-alert-popups' ) );

			return;
		}

		$this->start_session( $ip );
		$this->redirect_self();
	}

	/**
	 * Whether the current request carries a valid session for this address.
	 *
	 * @param string $ip Address.
	 * @return bool
	 */
	protected function authenticated( $ip ) {
		if ( empty( $_COOKIE[ self::COOKIE ] ) ) {
			return false;
		}

		$token   = sanitize_text_field( wp_unslash( $_COOKIE[ self::COOKIE ] ) );
		$stored  = get_transient( 'acps_ap_sess_' . hash( 'sha256', $token ) );

		return is_array( $stored ) && isset( $stored['ip'] ) && hash_equals( (string) $stored['ip'], $ip );
	}

	/**
	 * Starts a session bound to the address.
	 *
	 * @param string $ip Address.
	 * @return void
	 */
	protected function start_session( $ip ) {
		$token = wp_generate_password( 48, false, false );

		set_transient(
			'acps_ap_sess_' . hash( 'sha256', $token ),
			array(
				'ip'    => $ip,
				'start' => time(),
			),
			self::SESSION_TTL
		);

		$this->set_cookie( $token, time() + self::SESSION_TTL );
	}

	/**
	 * Clears the session.
	 *
	 * @return void
	 */
	protected function clear_session() {
		if ( ! empty( $_COOKIE[ self::COOKIE ] ) ) {
			$token = sanitize_text_field( wp_unslash( $_COOKIE[ self::COOKIE ] ) );
			delete_transient( 'acps_ap_sess_' . hash( 'sha256', $token ) );
		}

		$this->set_cookie( '', time() - 3600 );
	}

	/**
	 * Sets the session cookie, scoped to the console URL.
	 *
	 * @param string $value   Cookie value.
	 * @param int    $expires Expiry timestamp.
	 * @return void
	 */
	protected function set_cookie( $value, $expires ) {
		if ( headers_sent() ) {
			return;
		}

		$secure = is_ssl();

		setcookie(
			self::COOKIE,
			$value,
			array(
				'expires'  => $expires,
				'path'     => '/',
				'secure'   => $secure,
				'httponly' => true,
				'samesite' => 'Strict',
			)
		);
	}

	/* ------------------------------------------------------------------ *
	 * Saving settings (throttled to once per window).
	 * ------------------------------------------------------------------ */

	/**
	 * Handles a settings save from the console.
	 *
	 * @param string $ip Address.
	 * @return void
	 */
	protected function handle_save( $ip ) {
		$hours = (int) ACPS_Alerts_Settings::get( 'panel_edit_hours' );
		$last  = (int) get_option( self::LAST_EDIT, 0 );

		if ( $hours > 0 && $last > 0 && ( time() - $last ) < $hours * HOUR_IN_SECONDS ) {
			$remaining = $hours * HOUR_IN_SECONDS - ( time() - $last );

			$this->render_console(
				sprintf(
					/* translators: %s: human readable time. */
					__( 'Settings can be changed once every %1$s here. Try again in %2$s.', 'acps-alert-popups' ),
					human_time_diff( 0, $hours * HOUR_IN_SECONDS ),
					human_time_diff( time(), time() + $remaining )
				),
				'warn'
			);

			return;
		}

		$raw = isset( $_POST['acps_console'] ) ? wp_unslash( $_POST['acps_console'] ) : array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

		$changes = $this->sanitize_console_input( (array) $raw );

		ACPS_Alerts_Settings::patch( $changes );
		ACPS_Alerts_Updater::flush_cache();
		update_option( self::LAST_EDIT, time(), false );

		if ( $this->updater ) {
			$this->updater->record_health( 'ok', 'Settings changed from the remote console (' . $ip . ').' );
		}

		$this->render_console( __( 'Saved.', 'acps-alert-popups' ), 'ok' );
	}

	/**
	 * Sanitizes the settings the console is allowed to change.
	 *
	 * Everything operational is here. What is deliberately NOT here is anything
	 * governing access to the console itself: the password, the address rules,
	 * the proxy switch, the rate limit, the lockout, the edit throttle, the
	 * secret and the console's own on/off. Those change only from wp-admin, so
	 * this page can never be used to widen its own reach — a console that can
	 * raise its own rate limit and unlock its own address list is not gated at
	 * all.
	 *
	 * @param array $raw Raw POST data.
	 * @return array
	 */
	protected function sanitize_console_input( array $raw ) {
		$defaults = ACPS_Alerts_Settings::defaults();
		$out      = array();

		// Display behaviour.
		if ( isset( $raw['render_mode'] ) ) {
			$mode               = sanitize_key( $raw['render_mode'] );
			$out['render_mode'] = in_array( $mode, array( 'auto', 'native', 'modal' ), true ) ? $mode : $defaults['render_mode'];
		}

		if ( isset( $raw['storage'] ) ) {
			$storage        = sanitize_key( $raw['storage'] );
			$out['storage'] = in_array( $storage, array( 'local', 'session', 'cookie' ), true ) ? $storage : $defaults['storage'];
		}

		if ( isset( $raw['max_concurrent'] ) ) {
			$out['max_concurrent'] = max( 1, min( 5, absint( $raw['max_concurrent'] ) ) );
		}

		if ( isset( $raw['z_index'] ) ) {
			$out['z_index'] = max( 1, absint( $raw['z_index'] ) );
		}

		// The daily cut-off: the most likely thing to need changing in a hurry,
		// which is the whole reason this console exists.
		if ( isset( $raw['archive_time'] ) ) {
			$time                 = trim( (string) $raw['archive_time'] );
			$out['archive_time']  = preg_match( '/^([01]?\d|2[0-3]):([0-5]\d)$/', $time ) ? $time : $defaults['archive_time'];
		}

		if ( isset( $raw['popup_post_type'] ) ) {
			$type                   = sanitize_key( $raw['popup_post_type'] );
			$out['popup_post_type'] = ( '' === $type || post_type_exists( $type ) ) ? $type : $defaults['popup_post_type'];
		}

		if ( isset( $raw['custom_css'] ) ) {
			$out['custom_css'] = wp_strip_all_tags( (string) $raw['custom_css'] );
		}

		$out['hide_for_admins'] = empty( $raw['hide_for_admins'] ) ? 0 : 1;
		$out['respect_preview'] = empty( $raw['respect_preview'] ) ? 0 : 1;

		// Update channel (but not the secret or the password).
		$out['update_enabled'] = empty( $raw['update_enabled'] ) ? 0 : 1;
		$out['update_auto']    = empty( $raw['update_auto'] ) ? 0 : 1;

		if ( isset( $raw['update_base'] ) ) {
			$out['update_base'] = esc_url_raw( trim( (string) $raw['update_base'] ) );
		}

		if ( isset( $raw['update_path'] ) ) {
			$out['update_path'] = trim( sanitize_text_field( (string) $raw['update_path'] ), " \t\n\r/" );
		}

		if ( isset( $raw['update_key'] ) ) {
			$out['update_key'] = sanitize_text_field( (string) $raw['update_key'] );
		}

		return $out;
	}

	/* ------------------------------------------------------------------ *
	 * Status gathering.
	 * ------------------------------------------------------------------ */

	/**
	 * Performance snapshot.
	 *
	 * @return array
	 */
	protected function performance() {
		return array(
			__( 'PHP version', 'acps-alert-popups' )        => PHP_VERSION,
			__( 'WordPress version', 'acps-alert-popups' )  => get_bloginfo( 'version' ),
			__( 'Plugin version', 'acps-alert-popups' )     => ACPS_ALERTS_VERSION,
			__( 'Memory in use', 'acps-alert-popups' )      => size_format( memory_get_usage( true ) ),
			__( 'Peak memory', 'acps-alert-popups' )        => size_format( memory_get_peak_usage( true ) ),
			__( 'Memory limit', 'acps-alert-popups' )       => (string) ini_get( 'memory_limit' ),
			__( 'Popups on site', 'acps-alert-popups' )     => (string) count( ACPS_Alerts_Source::get_popups() ),
			__( 'Live alerts', 'acps-alert-popups' )        => (string) count( ACPS_Alerts_Source::get_enabled_alerts() ),
		);
	}

	/**
	 * Detected issues, worst first.
	 *
	 * @return array[] Each { level, message }.
	 */
	protected function issues() {
		$issues = array();

		$missing = ACPS_Alerts_Failsafe::missing_files();

		if ( ! empty( $missing ) ) {
			$issues[] = array(
				'level'   => 'error',
				'message' => sprintf(
					/* translators: %s: file list. */
					__( 'Missing plugin files: %s', 'acps-alert-popups' ),
					implode( ', ', $missing )
				),
			);
		}

		$missing_optional = ACPS_Alerts_Failsafe::missing_optional_files();

		if ( ! empty( $missing_optional ) ) {
			$issues[] = array(
				'level'   => 'warn',
				'message' => sprintf(
					/* translators: %s: file list. */
					__( 'Missing optional files (the plugin still runs): %s', 'acps-alert-popups' ),
					implode( ', ', $missing_optional )
				),
			);
		}

		if ( function_exists( 'acps_alerts_is_safe_mode' ) && acps_alerts_is_safe_mode() ) {
			$issues[] = array(
				'level'   => 'error',
				'message' => __( 'The plugin is in safe mode: it caught a fatal error and is dormant. Resume it from wp-admin once fixed.', 'acps-alert-popups' ),
			);
		}

		// Anything the breakers have switched off after repeated failures.
		foreach ( array( 'frontend', 'frontend/render', 'frontend/render-one', 'frontend/collect', 'frontend/bb-render', 'builder/register' ) as $context ) {
			if ( ACPS_Alerts_Failsafe::breaker_tripped( $context ) ) {
				$issues[] = array(
					'level'   => 'warn',
					'message' => sprintf(
						/* translators: %s: subsystem name. */
						__( 'Temporarily switched off after repeated failures: %s', 'acps-alert-popups' ),
						$context
					),
				);
			}
		}

		if ( is_array( get_option( ACPS_Alerts_Updater::FAILED_OPTION ) ) ) {
			$issues[] = array(
				'level'   => 'error',
				'message' => __( 'The last update failed its load test and was rolled back.', 'acps-alert-popups' ),
			);
		}

		if ( ! ACPS_Alerts_Source::is_ready() ) {
			$issues[] = array(
				'level'   => 'warn',
				'message' => __( 'Beaver Builder popups are not available, so alerts cannot run.', 'acps-alert-popups' ),
			);
		}

		if ( '' === trim( (string) ACPS_Alerts_Settings::get( 'update_base' ) ) ) {
			$issues[] = array(
				'level'   => 'warn',
				'message' => __( 'No update source is configured.', 'acps-alert-popups' ),
			);
		} elseif ( $this->updater ) {
			$status = $this->updater->peek_status();

			if ( $status['checked'] && ! $status['remote'] ) {
				$issues[] = array(
					'level'   => 'warn',
					'message' => __( 'The update source could not be read on the last check.', 'acps-alert-popups' ),
				);
			}
		}

		if ( '' === (string) ACPS_Alerts_Settings::get( 'panel_password' ) ) {
			$issues[] = array(
				'level'   => 'warn',
				'message' => __( 'No console password is set.', 'acps-alert-popups' ),
			);
		}

		// A degraded update channel: the plugin runs, but the last update left
		// its own updater or this console not re-initialising cleanly. Surfaced
		// prominently so a broken update path is noticed before the next update
		// silently never arrives.
		$health = get_option( ACPS_Alerts_Updater::HEALTH_OPTION, array() );

		if ( is_array( $health ) && ! empty( $health ) ) {
			$latest = end( $health );

			if ( is_array( $latest ) && isset( $latest['status'] ) && 'degraded' === $latest['status'] ) {
				$issues[] = array(
					'level'   => 'error',
					'message' => __( 'The update channel is degraded: the last update did not re-initialise the updater cleanly. Check the update settings.', 'acps-alert-popups' ),
				);
			}
		}

		return $issues;
	}

	/* ------------------------------------------------------------------ *
	 * Rendering.
	 * ------------------------------------------------------------------ */

	/**
	 * Renders the login form and exits.
	 *
	 * @param string $error Optional error message.
	 * @return void
	 */
	protected function render_login( $error = '' ) {
		$this->page_head( __( 'Console', 'acps-alert-popups' ) );

		if ( '' !== $error ) {
			echo '<p class="msg msg-error">' . esc_html( $error ) . '</p>';
		}

		echo '<form method="post"><input type="hidden" name="acps_console_action" value="login" />';
		echo '<label>' . esc_html__( 'Password', 'acps-alert-popups' ) . '<br /><input type="password" name="acps_console_password" autocomplete="current-password" autofocus /></label>';
		echo '<p><button type="submit">' . esc_html__( 'Enter', 'acps-alert-popups' ) . '</button></p>';
		echo '</form>';

		$this->page_foot();
	}

	/**
	 * Renders the authenticated console and exits.
	 *
	 * @param string $message Optional flash message.
	 * @param string $level   ok | warn | error.
	 * @return void
	 */
	protected function render_console( $message = '', $level = 'ok' ) {
		$this->page_head( __( 'Console', 'acps-alert-popups' ) );

		if ( '' !== $message ) {
			echo '<p class="msg msg-' . esc_attr( $level ) . '">' . esc_html( $message ) . '</p>';
		}

		// Issues.
		$issues = $this->issues();
		echo '<h2>' . esc_html__( 'Issues', 'acps-alert-popups' ) . '</h2>';

		if ( empty( $issues ) ) {
			echo '<p class="msg msg-ok">' . esc_html__( 'No problems detected.', 'acps-alert-popups' ) . '</p>';
		} else {
			echo '<ul class="issues">';

			foreach ( $issues as $issue ) {
				echo '<li class="lvl-' . esc_attr( $issue['level'] ) . '">' . esc_html( $issue['message'] ) . '</li>';
			}

			echo '</ul>';
		}

		// Performance.
		echo '<h2>' . esc_html__( 'Performance', 'acps-alert-popups' ) . '</h2><table>';

		foreach ( $this->performance() as $label => $value ) {
			echo '<tr><th>' . esc_html( $label ) . '</th><td>' . esc_html( $value ) . '</td></tr>';
		}

		echo '</table>';

		// Update status.
		echo '<h2>' . esc_html__( 'Updates', 'acps-alert-popups' ) . '</h2><table>';

		$status = $this->updater ? $this->updater->peek_status() : array( 'checked' => false, 'remote' => false, 'has_update' => false );
		$remote = $status['remote'];

		echo '<tr><th>' . esc_html__( 'Latest known version', 'acps-alert-popups' ) . '</th><td>' . esc_html( $remote && ! empty( $remote['version'] ) ? $remote['version'] : __( 'unknown', 'acps-alert-popups' ) ) . '</td></tr>';
		echo '<tr><th>' . esc_html__( 'Update available', 'acps-alert-popups' ) . '</th><td>' . esc_html( $status['has_update'] ? __( 'yes', 'acps-alert-popups' ) : __( 'no', 'acps-alert-popups' ) ) . '</td></tr>';
		echo '</table>';

		// Recent health.
		$health = get_option( ACPS_Alerts_Updater::HEALTH_OPTION, array() );

		if ( is_array( $health ) && ! empty( $health ) ) {
			echo '<h2>' . esc_html__( 'Recent activity', 'acps-alert-popups' ) . '</h2><ul class="issues">';

			foreach ( array_reverse( array_slice( $health, -10 ) ) as $entry ) {
				echo '<li class="lvl-' . esc_attr( isset( $entry['status'] ) ? $entry['status'] : 'ok' ) . '">'
					. esc_html( isset( $entry['time'] ) ? gmdate( 'Y-m-d H:i', (int) $entry['time'] ) . ' UTC — ' : '' )
					. esc_html( isset( $entry['note'] ) ? $entry['note'] : '' )
					. '</li>';
			}

			echo '</ul>';
		}

		// Problems: what the failsafe actually caught, newest first.
		$problems = ACPS_Alerts_Failsafe::problems( 10 );

		echo '<h2>' . esc_html__( 'Problems', 'acps-alert-popups' ) . '</h2>';

		if ( empty( $problems ) ) {
			echo '<p class="msg msg-ok">' . esc_html__( 'Nothing caught recently.', 'acps-alert-popups' ) . '</p>';
		} else {
			echo '<ul class="issues">';

			foreach ( $problems as $problem ) {
				$where = '';

				if ( ! empty( $problem['file'] ) ) {
					$where = ' (' . $problem['file'] . ':' . (int) $problem['line'] . ')';
				}

				echo '<li class="lvl-warn"><strong>' . esc_html( $problem['context'] ) . '</strong> — '
					. esc_html( gmdate( 'Y-m-d H:i', (int) $problem['time'] ) ) . ' UTC<br />'
					. esc_html( $problem['message'] . $where )
					. '</li>';
			}

			echo '</ul>';

			echo '<form method="post"><input type="hidden" name="acps_console_action" value="clear" />'
				. '<button type="submit">' . esc_html__( 'Clear problem log and re-enable anything switched off', 'acps-alert-popups' ) . '</button></form>';
		}

		$this->render_settings_form();

		echo '<form method="post" class="logout"><input type="hidden" name="acps_console_action" value="logout" /><button type="submit">' . esc_html__( 'Log out', 'acps-alert-popups' ) . '</button></form>';

		$this->page_foot();
	}

	/**
	 * Renders the editable-settings form.
	 *
	 * @return void
	 */
	protected function render_settings_form() {
		$hours = (int) ACPS_Alerts_Settings::get( 'panel_edit_hours' );
		$last  = (int) get_option( self::LAST_EDIT, 0 );
		$locked = ( $hours > 0 && $last > 0 && ( time() - $last ) < $hours * HOUR_IN_SECONDS );

		echo '<h2>' . esc_html__( 'Settings', 'acps-alert-popups' ) . '</h2>';

		if ( $locked ) {
			$remaining = $hours * HOUR_IN_SECONDS - ( time() - $last );
			echo '<p class="msg msg-warn">' . esc_html(
				sprintf(
					/* translators: %s: time remaining. */
					__( 'Settings were changed recently. Editing unlocks again in %s.', 'acps-alert-popups' ),
					human_time_diff( time(), time() + $remaining )
				)
			) . '</p>';
		}

		$disabled = $locked ? ' disabled' : '';

		echo '<form method="post"><input type="hidden" name="acps_console_action" value="save" />';

		$this->select_field( 'render_mode', __( 'Rendering', 'acps-alert-popups' ), array(
			'auto'   => 'auto',
			'native' => 'native (Beaver Builder)',
			'modal'  => 'modal (this plugin)',
		), ACPS_Alerts_Settings::get( 'render_mode' ), $disabled );

		$this->select_field( 'storage', __( 'Remember dismissals in', 'acps-alert-popups' ), array(
			'local'   => 'local storage',
			'session' => 'session storage',
			'cookie'  => 'cookie',
		), ACPS_Alerts_Settings::get( 'storage' ), $disabled );

		echo '<label>' . esc_html__( 'Daily cut-off', 'acps-alert-popups' ) . '<br /><input type="time" name="acps_console[archive_time]" value="' . esc_attr( ACPS_Alerts_Settings::get( 'archive_time' ) ) . '"' . $disabled . ' /></label>';

		echo '<label>' . esc_html__( 'Alerts per page view', 'acps-alert-popups' ) . '<br /><input type="number" min="1" max="5" name="acps_console[max_concurrent]" value="' . esc_attr( ACPS_Alerts_Settings::get( 'max_concurrent' ) ) . '"' . $disabled . ' /></label>';

		echo '<label>' . esc_html__( 'z-index', 'acps-alert-popups' ) . '<br /><input type="number" min="1" name="acps_console[z_index]" value="' . esc_attr( ACPS_Alerts_Settings::get( 'z_index' ) ) . '"' . $disabled . ' /></label>';

		echo '<label>' . esc_html__( 'Popup post type (blank to auto-detect)', 'acps-alert-popups' ) . '<br /><input type="text" name="acps_console[popup_post_type]" value="' . esc_attr( ACPS_Alerts_Settings::get( 'popup_post_type' ) ) . '"' . $disabled . ' /></label>';

		echo '<label class="check"><input type="checkbox" name="acps_console[hide_for_admins]" value="1" ' . checked( 1, (int) ACPS_Alerts_Settings::get( 'hide_for_admins' ), false ) . $disabled . ' /> ' . esc_html__( 'Hide alerts from editors', 'acps-alert-popups' ) . '</label>';

		echo '<label class="check"><input type="checkbox" name="acps_console[respect_preview]" value="1" ' . checked( 1, (int) ACPS_Alerts_Settings::get( 'respect_preview' ), false ) . $disabled . ' /> ' . esc_html__( 'Allow editor preview links', 'acps-alert-popups' ) . '</label>';

		echo '<label>' . esc_html__( 'Extra CSS', 'acps-alert-popups' ) . '<br /><textarea name="acps_console[custom_css]" rows="4"' . $disabled . '>' . esc_textarea( (string) ACPS_Alerts_Settings::get( 'custom_css' ) ) . '</textarea></label>';

		echo '<h3>' . esc_html__( 'Update source', 'acps-alert-popups' ) . '</h3>';

		echo '<label class="check"><input type="checkbox" name="acps_console[update_enabled]" value="1" ' . checked( 1, (int) ACPS_Alerts_Settings::get( 'update_enabled' ), false ) . $disabled . ' /> ' . esc_html__( 'Updates enabled', 'acps-alert-popups' ) . '</label>';
		echo '<label class="check"><input type="checkbox" name="acps_console[update_auto]" value="1" ' . checked( 1, (int) ACPS_Alerts_Settings::get( 'update_auto' ), false ) . $disabled . ' /> ' . esc_html__( 'Install updates automatically', 'acps-alert-popups' ) . '</label>';

		echo '<label>' . esc_html__( 'Manifest base URL', 'acps-alert-popups' ) . '<br /><input type="url" name="acps_console[update_base]" value="' . esc_attr( ACPS_Alerts_Settings::get( 'update_base' ) ) . '"' . $disabled . ' /></label>';
		echo '<label>' . esc_html__( 'Plugin path', 'acps-alert-popups' ) . '<br /><input type="text" name="acps_console[update_path]" value="' . esc_attr( ACPS_Alerts_Settings::get( 'update_path' ) ) . '"' . $disabled . ' /></label>';
		echo '<label>' . esc_html__( 'Key', 'acps-alert-popups' ) . '<br /><input type="text" name="acps_console[update_key]" value="' . esc_attr( ACPS_Alerts_Settings::get( 'update_key' ) ) . '"' . $disabled . ' /></label>';

		if ( ! $locked ) {
			echo '<p><button type="submit">' . esc_html__( 'Save', 'acps-alert-popups' ) . '</button></p>';
		}

		echo '</form>';
	}

	/**
	 * A labelled select control.
	 *
	 * @param string $key      Field key under acps_console[].
	 * @param string $label    Label.
	 * @param array  $choices  value => label.
	 * @param string $current  Current value.
	 * @param string $disabled Disabled attribute.
	 * @return void
	 */
	protected function select_field( $key, $label, array $choices, $current, $disabled ) {
		echo '<label>' . esc_html( $label ) . '<br /><select name="acps_console[' . esc_attr( $key ) . ']"' . $disabled . '>';

		foreach ( $choices as $value => $text ) {
			echo '<option value="' . esc_attr( $value ) . '" ' . selected( $value, $current, false ) . '>' . esc_html( $text ) . '</option>';
		}

		echo '</select></label>';
	}

	/* ------------------------------------------------------------------ *
	 * Output helpers.
	 * ------------------------------------------------------------------ */

	/**
	 * Prints the page head.
	 *
	 * @param string $title Title.
	 * @return void
	 */
	protected function page_head( $title ) {
		if ( ! headers_sent() ) {
			header( 'Content-Type: text/html; charset=utf-8' );
			header( 'X-Robots-Tag: noindex, nofollow', true );
		}

		echo '<!doctype html><html><head><meta charset="utf-8" /><meta name="robots" content="noindex,nofollow" />';
		echo '<meta name="viewport" content="width=device-width, initial-scale=1" />';
		echo '<title>' . esc_html( $title ) . '</title><style>';
		echo 'body{font:15px/1.5 -apple-system,Segoe UI,Roboto,sans-serif;max-width:720px;margin:40px auto;padding:0 16px;color:#1d2327;background:#f6f7f7}';
		echo 'h1{font-size:20px}h2{font-size:16px;margin-top:28px;border-bottom:1px solid #dcdcde;padding-bottom:4px}h3{font-size:14px;margin:18px 0 6px}';
		echo 'table{border-collapse:collapse;width:100%}th,td{text-align:left;padding:4px 8px;border-bottom:1px solid #eee;vertical-align:top}th{width:45%}';
		echo 'label{display:block;margin:10px 0}label.check{display:block}input,select,textarea{font:inherit;padding:6px;max-width:100%;box-sizing:border-box}input[type=url],input[type=text],input[type=password],textarea{width:100%}textarea{font-family:ui-monospace,Menlo,Consolas,monospace;font-size:13px}';
		echo 'button{font:inherit;padding:8px 16px;background:#2271b1;color:#fff;border:0;border-radius:3px;cursor:pointer}';
		echo '.msg{padding:8px 12px;border-radius:3px}.msg-ok{background:#d5f5dd}.msg-warn{background:#fcf3d4}.msg-error{background:#f7d7d7}';
		echo 'ul.issues{list-style:none;padding:0}ul.issues li{padding:6px 10px;border-left:4px solid #ccc;margin:4px 0;background:#fff}';
		echo '.lvl-error{border-left-color:#d63638}.lvl-warn{border-left-color:#dba617}.lvl-ok{border-left-color:#00a32a}';
		echo 'form.logout{margin-top:24px}form.logout button{background:#50575e}';
		echo '</style></head><body><h1>' . esc_html__( 'ACPS Alert Popups — Console', 'acps-alert-popups' ) . '</h1>';
	}

	/**
	 * Prints the page foot and exits.
	 *
	 * @return void
	 */
	protected function page_foot() {
		echo '</body></html>';
		exit;
	}

	/**
	 * Redirects back to the console root (post/redirect/get).
	 *
	 * @return void
	 */
	protected function redirect_self() {
		$secret = trim( (string) ACPS_Alerts_Settings::get( 'update_secret' ) );
		wp_safe_redirect( add_query_arg( self::QUERY_VAR, $secret, home_url( '/' ) ) );
		exit;
	}

	/**
	 * Sends a bare status message and exits.
	 *
	 * @param int    $code    HTTP status.
	 * @param string $message Message.
	 * @return void
	 */
	protected function send( $code, $message ) {
		if ( ! headers_sent() ) {
			status_header( $code );
			header( 'Content-Type: text/plain; charset=utf-8' );
			header( 'X-Robots-Tag: noindex, nofollow', true );
		}

		echo esc_html( $message );
		exit;
	}

	/**
	 * Serves a plain 404 and exits, so the console is indistinguishable from a
	 * missing page to anyone who can't reach it.
	 *
	 * @return void
	 */
	protected function deny_404() {
		status_header( 404 );
		nocache_headers();

		if ( function_exists( 'wp_die' ) ) {
			wp_die( esc_html__( 'Not found.', 'acps-alert-popups' ), '', array( 'response' => 404 ) );
		}

		exit;
	}
}
