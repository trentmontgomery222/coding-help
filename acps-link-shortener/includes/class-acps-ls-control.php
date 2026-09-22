<?php
/**
 * Public "remote control" endpoint — a login-free, IP-restricted, password-
 * protected page for emergencies and remote administration.
 *
 * Reached at:  {home}/?acps_ul_status=<url-key>
 *
 * Guards, in order:
 *   1. Feature enabled + a URL key is configured and matches (else: homepage).
 *   2. Per-IP rate limit (else: 429).
 *   3. IP allow/deny rules, with prefix ranges like "196.168." (else: homepage).
 *   4. Control password (set only in wp-admin) via a short signed cookie.
 *
 * Once authenticated you can: trigger an update, view diagnostics (performance /
 * issues / problems), edit plugin settings (once per 24h), change the control
 * password, and resume the plugin from safe mode.
 *
 * This runs even while the rest of the plugin is dormant in safe mode, so it is
 * a genuine recovery path if an update ever breaks the site.
 *
 * @package ACPS_Link_Shortener
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Public control endpoint.
 */
class ACPS_LS_Control {

	const COOKIE   = 'acps_ls_ctrl_auth';
	const EDIT_OPT = 'acps_ls_ctrl_last_edit';

	/**
	 * Resolved config.
	 *
	 * @var array
	 */
	private $cfg;

	/**
	 * Build with resolved config.
	 */
	public function __construct() {
		$this->cfg = self::config();
	}

	/**
	 * Read control config from settings (with defaults).
	 *
	 * @return array
	 */
	public static function config() {
		$s = get_option( ACPS_LS_OPT_SETTINGS, array() );
		$s = is_array( $s ) ? $s : array();

		return array(
			'enabled'      => ! empty( $s['ctrl_enabled'] ),
			'key'          => isset( $s['ctrl_key'] ) ? (string) $s['ctrl_key'] : '',
			'ip_mode'      => ( isset( $s['ctrl_ip_mode'] ) && 'deny' === $s['ctrl_ip_mode'] ) ? 'deny' : 'allow',
			'ips'          => ( isset( $s['ctrl_ips'] ) && is_array( $s['ctrl_ips'] ) ) ? $s['ctrl_ips'] : array( '167.102.110.1' ),
			'rate'         => isset( $s['ctrl_rate'] ) ? max( 1, (int) $s['ctrl_rate'] ) : 20,
			'pw_hash'      => isset( $s['ctrl_password'] ) ? (string) $s['ctrl_password'] : '',
			'has_password' => ! empty( $s['ctrl_password'] ),
		);
	}

	/**
	 * Hook the endpoint (very early, and independent of safe mode).
	 */
	public function register() {
		try {
			add_action( 'init', array( $this, 'maybe_handle' ), 1 );
		} catch ( Throwable $e ) {
			acps_ls_log_error( 'control register', $e );
		}
	}

	/**
	 * Detect and serve the control endpoint.
	 */
	public function maybe_handle() {
		try {
			if ( ! isset( $_GET['acps_ul_status'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				return;
			}
			// Disabled or unconfigured -> behave as if the URL is nothing.
			if ( empty( $this->cfg['enabled'] ) || '' === $this->cfg['key'] ) {
				return;
			}
			$given = (string) wp_unslash( $_GET['acps_ul_status'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			if ( ! hash_equals( $this->cfg['key'], $given ) ) {
				return; // Wrong key -> let WordPress render normally (no signal).
			}

			$ip = $this->client_ip();

			// Rate limit first (protects everything downstream).
			if ( ! $this->rate_ok( 'ctrl_' . md5( $ip ), 60, $this->cfg['rate'] ) ) {
				status_header( 429 );
				nocache_headers();
				header( 'Retry-After: 60' );
				exit( 'Too many requests. Try again in a minute.' );
			}

			// IP gate. Not allowed -> silently show the homepage.
			if ( ! $this->ip_allowed( $ip ) ) {
				wp_safe_redirect( home_url( '/' ), 302 );
				exit;
			}

			$this->serve();
			exit;
		} catch ( Throwable $e ) {
			acps_ls_log_error( 'control handle', $e );
			// Fail closed to the homepage; never expose an error here.
			if ( ! headers_sent() ) {
				wp_safe_redirect( home_url( '/' ), 302 );
			}
			exit;
		}
	}

	/* --------------------------------------------------------------------- */
	/* IP + rate limiting                                                     */
	/* --------------------------------------------------------------------- */

	/**
	 * Best-effort client IP.
	 *
	 * @return string
	 */
	private function client_ip() {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		return $ip ? $ip : '0.0.0.0';
	}

	/**
	 * Does an IP match a rule? A rule is either an exact address or a prefix
	 * ("196.168." or "196.168.*") that matches anything starting with it.
	 *
	 * @param string $ip   Client IP.
	 * @param string $rule Rule.
	 * @return bool
	 */
	private function ip_matches( $ip, $rule ) {
		$rule = trim( $rule );
		if ( '' === $rule ) {
			return false;
		}
		if ( '*' === substr( $rule, -1 ) ) {
			$prefix = rtrim( substr( $rule, 0, -1 ), '' );
			return 0 === strpos( $ip, $prefix );
		}
		if ( '.' === substr( $rule, -1 ) ) {
			return 0 === strpos( $ip, $rule );
		}
		return hash_equals( $rule, $ip );
	}

	/**
	 * Apply the allow/deny rules to an IP.
	 *
	 * @param string $ip Client IP.
	 * @return bool
	 */
	private function ip_allowed( $ip ) {
		$rules = $this->cfg['ips'];
		$hit   = false;
		foreach ( (array) $rules as $rule ) {
			if ( $this->ip_matches( $ip, $rule ) ) {
				$hit = true;
				break;
			}
		}
		return ( 'deny' === $this->cfg['ip_mode'] ) ? ! $hit : $hit;
	}

	/**
	 * Fixed-window rate check.
	 *
	 * @param string $bucket Bucket id.
	 * @param int    $window Seconds.
	 * @param int    $limit  Max per window.
	 * @return bool
	 */
	private function rate_ok( $bucket, $window, $limit ) {
		if ( $limit <= 0 ) {
			return true;
		}
		$slot = (int) floor( time() / $window );
		$tk   = 'acps_ls_crl_' . md5( $bucket . '|' . $slot );
		$n    = (int) get_transient( $tk );
		if ( $n >= $limit ) {
			return false;
		}
		set_transient( $tk, $n + 1, $window + 5 );
		return true;
	}

	/* --------------------------------------------------------------------- */
	/* Auth (signed cookie, keyed to the password hash + WP salt)             */
	/* --------------------------------------------------------------------- */

	/**
	 * Secret for signing the auth cookie. Changing the password invalidates
	 * every existing session automatically.
	 *
	 * @return string
	 */
	private function auth_secret() {
		return wp_salt( 'auth' ) . '|acps-ls-ctrl|' . $this->cfg['pw_hash'];
	}

	/**
	 * Is the current request carrying a valid auth cookie?
	 *
	 * @return bool
	 */
	private function is_authed() {
		if ( empty( $this->cfg['has_password'] ) ) {
			return false;
		}
		if ( empty( $_COOKIE[ self::COOKIE ] ) ) {
			return false;
		}
		$val = (string) wp_unslash( $_COOKIE[ self::COOKIE ] );
		$parts = explode( '.', $val, 2 );
		if ( count( $parts ) !== 2 ) {
			return false;
		}
		$exp = (int) $parts[0];
		if ( $exp < time() ) {
			return false;
		}
		$expected = hash_hmac( 'sha256', (string) $exp, $this->auth_secret() );
		return hash_equals( $expected, $parts[1] );
	}

	/**
	 * Issue an auth cookie valid for 30 minutes.
	 */
	private function set_auth_cookie() {
		$exp = time() + 1800;
		$val = $exp . '.' . hash_hmac( 'sha256', (string) $exp, $this->auth_secret() );
		$secure = is_ssl();
		setcookie( self::COOKIE, $val, $exp, '/', '', $secure, true );
		$_COOKIE[ self::COOKIE ] = $val; // Available this request too.
	}

	/**
	 * CSRF token tied to the current session secret.
	 *
	 * @return string
	 */
	private function csrf() {
		return substr( hash_hmac( 'sha256', 'acps_ls_ctrl_csrf', $this->auth_secret() ), 0, 32 );
	}

	/**
	 * Verify a posted CSRF token.
	 *
	 * @return bool
	 */
	private function csrf_ok() {
		$t = isset( $_POST['acps_ls_csrf'] ) ? (string) wp_unslash( $_POST['acps_ls_csrf'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		return hash_equals( $this->csrf(), $t );
	}

	/* --------------------------------------------------------------------- */
	/* Serving                                                                */
	/* --------------------------------------------------------------------- */

	/**
	 * Serve the control page (login form or panel).
	 */
	private function serve() {
		nocache_headers();
		$self   = home_url( '/?acps_ul_status=' . rawurlencode( $this->cfg['key'] ) );
		$notice = '';
		$log    = '';

		// No password configured yet -> lock the endpoint (set it in wp-admin).
		if ( empty( $this->cfg['has_password'] ) ) {
			$this->page( __( 'Control endpoint', 'acps-link-shortener' ),
				'<p>' . esc_html__( 'This endpoint is not ready: no control password has been set. Set one in wp-admin (Settings → Link Shortener, add &updates=1 to the address).', 'acps-link-shortener' ) . '</p>' );
			return;
		}

		// Handle a login attempt.
		if ( isset( $_POST['acps_ls_ctrl_login'] ) ) {
			// Throttle password attempts hard, separate from the general limit.
			if ( ! $this->rate_ok( 'ctrl_pw_' . md5( $this->client_ip() ), 600, 5 ) ) {
				$this->login_form( $self, __( 'Too many attempts. Wait a few minutes and try again.', 'acps-link-shortener' ) );
				return;
			}
			$pw = isset( $_POST['acps_ls_ctrl_pw'] ) ? (string) $_POST['acps_ls_ctrl_pw'] : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			if ( '' !== $pw && wp_check_password( $pw, $this->cfg['pw_hash'] ) ) {
				$this->set_auth_cookie();
				wp_safe_redirect( $self );
				exit;
			}
			$this->login_form( $self, __( 'Wrong password.', 'acps-link-shortener' ) );
			return;
		}

		if ( ! $this->is_authed() ) {
			$this->login_form( $self, '' );
			return;
		}

		// ---- Authenticated actions ----
		if ( isset( $_POST['acps_ls_ctrl_action'] ) && $this->csrf_ok() ) {
			$action = sanitize_key( wp_unslash( $_POST['acps_ls_ctrl_action'] ) );
			if ( 'logout' === $action ) {
				$this->clear_cookie();
				wp_safe_redirect( home_url( '/' ) );
				exit;
			} elseif ( 'update' === $action ) {
				$log = $this->do_update();
			} elseif ( 'resume' === $action ) {
				$notice = $this->do_resume();
			} elseif ( 'password' === $action ) {
				$notice = $this->do_change_password();
			} elseif ( 'save' === $action ) {
				$notice = $this->do_save_settings();
			}
		}

		$this->panel( $self, $notice, $log );
	}

	/**
	 * Clear the auth cookie.
	 */
	private function clear_cookie() {
		setcookie( self::COOKIE, '', time() - 3600, '/', '', is_ssl(), true );
		unset( $_COOKIE[ self::COOKIE ] );
	}

	/* --------------------------------------------------------------------- */
	/* Actions                                                                */
	/* --------------------------------------------------------------------- */

	/**
	 * Trigger an update, returning the log text.
	 *
	 * @return string
	 */
	private function do_update() {
		if ( ! class_exists( 'ACPS_LS_Updater' ) ) {
			return __( 'Updater unavailable.', 'acps-link-shortener' );
		}
		return ( new ACPS_LS_Updater() )->perform_update();
	}

	/**
	 * Clear safe mode so the plugin loads normally again next request.
	 *
	 * @return string
	 */
	private function do_resume() {
		if ( defined( 'ACPS_LS_SAFE_MODE_OPT' ) ) {
			delete_option( ACPS_LS_SAFE_MODE_OPT );
		}
		delete_option( 'acps_ls_update_failed' );
		return __( 'Safe mode cleared. The plugin will load normally on the next request.', 'acps-link-shortener' );
	}

	/**
	 * Change the control password from the endpoint (allowed once authenticated).
	 *
	 * @return string
	 */
	private function do_change_password() {
		$new = isset( $_POST['new_password'] ) ? (string) $_POST['new_password'] : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$new = trim( $new );
		if ( strlen( $new ) < 6 ) {
			return __( 'Password not changed: choose at least 6 characters.', 'acps-link-shortener' );
		}
		$s = get_option( ACPS_LS_OPT_SETTINGS, array() );
		$s = is_array( $s ) ? $s : array();
		$s['ctrl_password'] = wp_hash_password( $new );
		update_option( ACPS_LS_OPT_SETTINGS, $s );
		// Re-read config + re-issue the cookie under the new secret.
		$this->cfg = self::config();
		$this->set_auth_cookie();
		return __( 'Control password changed.', 'acps-link-shortener' );
	}

	/**
	 * Save edited settings — but only once per 24 hours.
	 *
	 * @return string
	 */
	private function do_save_settings() {
		$last = (int) get_option( self::EDIT_OPT, 0 );
		if ( $last && ( time() - $last ) < DAY_IN_SECONDS ) {
			$next = $last + DAY_IN_SECONDS;
			/* translators: %s: date/time. */
			return sprintf( __( 'Settings can only be edited once per day. Next edit allowed after %s.', 'acps-link-shortener' ), gmdate( 'Y-m-d H:i', $next ) . ' UTC' );
		}

		$s = get_option( ACPS_LS_OPT_SETTINGS, array() );
		$s = is_array( $s ) ? $s : array();

		foreach ( $this->schema() as $field ) {
			$key = $field['key'];
			switch ( $field['type'] ) {
				case 'bool':
					$s[ $key ] = isset( $_POST[ 'set_' . $key ] ) ? 1 : 0;
					break;
				case 'int':
					if ( isset( $_POST[ 'set_' . $key ] ) ) {
						$s[ $key ] = max( 0, absint( wp_unslash( $_POST[ 'set_' . $key ] ) ) );
					}
					break;
				case 'url':
					if ( isset( $_POST[ 'set_' . $key ] ) ) {
						$s[ $key ] = esc_url_raw( wp_unslash( $_POST[ 'set_' . $key ] ), array( 'https', 'http' ) );
					}
					break;
				case 'list':
					if ( isset( $_POST[ 'set_' . $key ] ) ) {
						$lines = preg_split( '/[\r\n]+/', sanitize_textarea_field( wp_unslash( $_POST[ 'set_' . $key ] ) ) );
						$clean = array();
						foreach ( (array) $lines as $line ) {
							$line = trim( $line );
							if ( '' !== $line ) {
								$clean[] = $line;
							}
						}
						$s[ $key ] = $clean;
					}
					break;
				case 'text':
				default:
					if ( isset( $_POST[ 'set_' . $key ] ) ) {
						$s[ $key ] = sanitize_text_field( wp_unslash( $_POST[ 'set_' . $key ] ) );
					}
					break;
			}
		}

		update_option( ACPS_LS_OPT_SETTINGS, $s );
		update_option( self::EDIT_OPT, time(), false );
		delete_transient( 'acps_ls_update_remote' );
		$this->cfg = self::config();
		return __( 'Settings saved. (Editing locks again for 24 hours.)', 'acps-link-shortener' );
	}

	/**
	 * The whitelist of settings editable from the endpoint. Sensitive items
	 * (staff passwords, API keys, the control password itself) are deliberately
	 * excluded — the control password has its own change action.
	 *
	 * @return array[]
	 */
	private function schema() {
		return array(
			array( 'key' => 'link_domain', 'type' => 'url', 'label' => 'Custom short domain' ),
			array( 'key' => 'shortcode_page', 'type' => 'url', 'label' => 'Shortcode page URL' ),
			array( 'key' => 'check_enabled', 'type' => 'bool', 'label' => 'Link checker enabled' ),
			array( 'key' => 'scan_content', 'type' => 'bool', 'label' => 'Scan post/page content' ),
			array( 'key' => 'scan_comments', 'type' => 'bool', 'label' => 'Scan comments' ),
			array( 'key' => 'recheck_hours', 'type' => 'int', 'label' => 'Recheck interval (hours)' ),
			array( 'key' => 'timeout', 'type' => 'int', 'label' => 'Request timeout (seconds)' ),
			array( 'key' => 'check_night_only', 'type' => 'bool', 'label' => 'Check only at night' ),
			array( 'key' => 'scan_idle_minutes', 'type' => 'int', 'label' => 'Idle scan interval (minutes)' ),
			array( 'key' => 'sync_enabled', 'type' => 'bool', 'label' => 'Google Sheet sync enabled' ),
			array( 'key' => 'api_enabled', 'type' => 'bool', 'label' => 'REST API enabled' ),
			array( 'key' => 'api_allow_manage', 'type' => 'bool', 'label' => 'API allow update/delete' ),
			array( 'key' => 'api_rate_limit', 'type' => 'int', 'label' => 'API rate limit (req/min/key)' ),
			array( 'key' => 'api_hourly_max', 'type' => 'int', 'label' => 'API create cap (per hour)' ),
			array( 'key' => 'update_enabled', 'type' => 'bool', 'label' => 'Updates enabled' ),
			array( 'key' => 'update_auto', 'type' => 'bool', 'label' => 'Auto-install updates' ),
			array( 'key' => 'update_source', 'type' => 'text', 'label' => 'Update source (url or github)' ),
			array( 'key' => 'update_manifest', 'type' => 'url', 'label' => 'Update manifest URL' ),
			array( 'key' => 'gh_owner', 'type' => 'text', 'label' => 'GitHub owner' ),
			array( 'key' => 'gh_repo', 'type' => 'text', 'label' => 'GitHub repo' ),
			array( 'key' => 'ctrl_enabled', 'type' => 'bool', 'label' => 'Control endpoint enabled' ),
			array( 'key' => 'ctrl_ip_mode', 'type' => 'text', 'label' => 'Control IP mode (allow/deny)' ),
			array( 'key' => 'ctrl_rate', 'type' => 'int', 'label' => 'Control rate limit (req/min/IP)' ),
			array( 'key' => 'ctrl_ips', 'type' => 'list', 'label' => 'Control IP list (one per line)' ),
		);
	}

	/* --------------------------------------------------------------------- */
	/* Diagnostics                                                            */
	/* --------------------------------------------------------------------- */

	/**
	 * Gather performance / health / problems.
	 *
	 * @return array[] Rows of [label, value, level] where level is ok|warn|bad|info.
	 */
	private function diagnostics() {
		$rows = array();
		$rows[] = array( 'Plugin version', ACPS_LS_VERSION, 'info' );
		$rows[] = array( 'WordPress', get_bloginfo( 'version' ), 'info' );
		$rows[] = array( 'PHP', PHP_VERSION, 'info' );
		$rows[] = array( 'Memory in use', size_format( memory_get_usage( true ) ), 'info' );
		$rows[] = array( 'Peak memory', size_format( memory_get_peak_usage( true ) ), 'info' );
		$rows[] = array( 'Memory limit', (string) ini_get( 'memory_limit' ), 'info' );

		// Safe mode / crash state.
		$safe = get_option( defined( 'ACPS_LS_SAFE_MODE_OPT' ) ? ACPS_LS_SAFE_MODE_OPT : 'acps_ls_safe_mode' );
		if ( is_array( $safe ) && ! empty( $safe['time'] ) ) {
			$rows[] = array( 'Safe mode', 'ON — ' . ( isset( $safe['msg'] ) ? $safe['msg'] : '' ), 'bad' );
		} else {
			$rows[] = array( 'Safe mode', 'off (healthy)', 'ok' );
		}
		$failed = get_option( 'acps_ls_update_failed' );
		if ( is_array( $failed ) ) {
			$rows[] = array( 'Last update', 'FAILED load test ' . ( isset( $failed['when'] ) ? $failed['when'] : '' ), 'bad' );
		}

		// Update availability.
		if ( class_exists( 'ACPS_LS_Updater' ) ) {
			try {
				$vs = ( new ACPS_LS_Updater() )->version_status();
				$rows[] = array( 'Latest available', $vs['latest'] ? $vs['latest'] : 'unknown', $vs['update_available'] ? 'warn' : 'ok' );
			} catch ( Throwable $e ) {
				$rows[] = array( 'Latest available', 'lookup error', 'warn' );
			}
		}

		// Link counts.
		if ( class_exists( 'ACPS_LS_Checker' ) ) {
			try {
				$info   = ACPS_LS_Checker::status_info();
				$rows[] = array( 'Broken links', (string) (int) $info['broken'], ( (int) $info['broken'] > 0 ) ? 'warn' : 'ok' );
				$rows[] = array( 'Check queue', (string) (int) $info['queue'], 'info' );
				$rows[] = array( 'Unique URLs tracked', (string) (int) $info['unique'], 'info' );
			} catch ( Throwable $e ) { /* ignore */ }
		}

		// Cron health.
		$sync_next  = wp_next_scheduled( 'acps_ls_sheet_sync' );
		$check_next = wp_next_scheduled( 'acps_ls_link_check' );
		$rows[] = array( 'Next sync run', $sync_next ? gmdate( 'Y-m-d H:i', $sync_next ) . ' UTC' : 'not scheduled', 'info' );
		$rows[] = array( 'Next checker run', $check_next ? gmdate( 'Y-m-d H:i', $check_next ) . ' UTC' : 'not scheduled', 'info' );

		$rows[] = array( 'WP_DEBUG', ( defined( 'WP_DEBUG' ) && WP_DEBUG ) ? 'on' : 'off', 'info' );

		return $rows;
	}

	/* --------------------------------------------------------------------- */
	/* HTML                                                                   */
	/* --------------------------------------------------------------------- */

	/**
	 * Shared page chrome.
	 *
	 * @param string $title Heading.
	 * @param string $body  HTML body (already escaped by caller).
	 */
	private function page( $title, $body ) {
		header( 'Content-Type: text/html; charset=utf-8' );
		echo '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>' . esc_html( $title ) . '</title>';
		echo '<style>'
			. 'body{font:15px/1.5 -apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;background:#0f1216;color:#e6e6e6;margin:0;padding:24px}'
			. '.wrap{max-width:820px;margin:0 auto}'
			. 'h1{font-size:20px;margin:0 0 4px}h2{font-size:16px;margin:24px 0 8px;border-bottom:1px solid #2a2f37;padding-bottom:6px}'
			. '.card{background:#171b21;border:1px solid #2a2f37;border-radius:10px;padding:16px 18px;margin:14px 0}'
			. 'input[type=text],input[type=password],input[type=url],input[type=number],textarea{width:100%;box-sizing:border-box;background:#0f1216;border:1px solid #2a2f37;color:#e6e6e6;border-radius:6px;padding:8px 10px;font:inherit}'
			. 'label{display:block;margin:8px 0 3px;color:#b9c0c9;font-size:13px}'
			. 'button{background:#2271b1;color:#fff;border:0;border-radius:6px;padding:9px 14px;font:inherit;cursor:pointer}button.secondary{background:#3a3f47}button.danger{background:#b32d2e}'
			. 'table{width:100%;border-collapse:collapse}td{padding:6px 8px;border-bottom:1px solid #21262d;vertical-align:top}td:first-child{color:#b9c0c9;width:40%}'
			. '.ok{color:#4ec27f}.warn{color:#e3b341}.bad{color:#f2726e}.info{color:#9aa4b2}'
			. 'pre{white-space:pre-wrap;background:#0b0e12;border:1px solid #2a2f37;border-radius:8px;padding:12px;overflow:auto}'
			. '.note{background:#12331f;border:1px solid #1f6b3b;border-radius:8px;padding:10px 12px;margin:12px 0}'
			. '.grid{display:grid;grid-template-columns:1fr 1fr;gap:8px 16px}@media(max-width:640px){.grid{grid-template-columns:1fr}}'
			. '.row{display:flex;gap:10px;align-items:center;flex-wrap:wrap}'
			. '</style></head><body><div class="wrap">';
		echo '<h1>' . esc_html( $title ) . '</h1>';
		echo $body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- body assembled with esc_* by callers.
		echo '</div></body></html>';
	}

	/**
	 * Login form.
	 *
	 * @param string $self URL.
	 * @param string $err  Error message.
	 */
	private function login_form( $self, $err ) {
		$body  = '<div class="card"><form method="post" action="' . esc_url( $self ) . '">';
		if ( $err ) {
			$body .= '<p class="bad">' . esc_html( $err ) . '</p>';
		}
		$body .= '<label for="pw">' . esc_html__( 'Control password', 'acps-link-shortener' ) . '</label>';
		$body .= '<input type="password" id="pw" name="acps_ls_ctrl_pw" autocomplete="current-password" autofocus />';
		$body .= '<p><button type="submit" name="acps_ls_ctrl_login" value="1">' . esc_html__( 'Sign in', 'acps-link-shortener' ) . '</button></p>';
		$body .= '</form></div>';
		$this->page( __( 'Control', 'acps-link-shortener' ), $body );
	}

	/**
	 * The authenticated control panel.
	 *
	 * @param string $self   URL.
	 * @param string $notice Notice text.
	 * @param string $log    Update log text.
	 */
	private function panel( $self, $notice, $log ) {
		$csrf = $this->csrf();
		$hidden = '<input type="hidden" name="acps_ls_csrf" value="' . esc_attr( $csrf ) . '" />';

		$body = '';
		if ( $notice ) {
			$body .= '<div class="note">' . esc_html( $notice ) . '</div>';
		}

		// Actions row.
		$body .= '<div class="card"><div class="row">';
		$body .= '<form method="post" action="' . esc_url( $self ) . '">' . $hidden . '<input type="hidden" name="acps_ls_ctrl_action" value="update"><button type="submit">' . esc_html__( '⬆ Update now', 'acps-link-shortener' ) . '</button></form>';
		$body .= '<form method="post" action="' . esc_url( $self ) . '">' . $hidden . '<input type="hidden" name="acps_ls_ctrl_action" value="resume"><button type="submit" class="secondary">' . esc_html__( '⟳ Resume from safe mode', 'acps-link-shortener' ) . '</button></form>';
		$body .= '<form method="post" action="' . esc_url( $self ) . '">' . $hidden . '<input type="hidden" name="acps_ls_ctrl_action" value="logout"><button type="submit" class="secondary">' . esc_html__( 'Sign out', 'acps-link-shortener' ) . '</button></form>';
		$body .= '</div>';
		if ( $log ) {
			$body .= '<pre>' . esc_html( $log ) . '</pre>';
		}
		$body .= '</div>';

		// Diagnostics.
		$body .= '<h2>' . esc_html__( 'Diagnostics', 'acps-link-shortener' ) . '</h2><div class="card"><table>';
		foreach ( $this->diagnostics() as $r ) {
			$lvl = isset( $r[2] ) ? $r[2] : 'info';
			$body .= '<tr><td>' . esc_html( $r[0] ) . '</td><td class="' . esc_attr( $lvl ) . '">' . esc_html( $r[1] ) . '</td></tr>';
		}
		$body .= '</table></div>';

		// Settings editor (once/day).
		$last = (int) get_option( self::EDIT_OPT, 0 );
		$locked = ( $last && ( time() - $last ) < DAY_IN_SECONDS );
		$body .= '<h2>' . esc_html__( 'Edit settings', 'acps-link-shortener' ) . '</h2><div class="card">';
		if ( $locked ) {
			$next = gmdate( 'Y-m-d H:i', $last + DAY_IN_SECONDS ) . ' UTC';
			$body .= '<p class="warn">' . esc_html( sprintf( /* translators: %s: datetime */ __( 'Editing is locked until %s (once per day).', 'acps-link-shortener' ), $next ) ) . '</p>';
		}
		$s = get_option( ACPS_LS_OPT_SETTINGS, array() );
		$s = is_array( $s ) ? $s : array();
		$body .= '<form method="post" action="' . esc_url( $self ) . '">' . $hidden . '<input type="hidden" name="acps_ls_ctrl_action" value="save"><div class="grid">';
		foreach ( $this->schema() as $f ) {
			$key = $f['key'];
			$val = isset( $s[ $key ] ) ? $s[ $key ] : '';
			$dis = $locked ? ' disabled' : '';
			$id  = 'set_' . $key;
			if ( 'bool' === $f['type'] ) {
				$body .= '<div><label>' . esc_html( $f['label'] ) . '</label><input type="checkbox" name="' . esc_attr( $id ) . '" value="1"' . checked( ! empty( $val ), true, false ) . $dis . '></div>';
			} elseif ( 'list' === $f['type'] ) {
				$txt = is_array( $val ) ? implode( "\n", $val ) : '';
				$body .= '<div style="grid-column:1/-1"><label>' . esc_html( $f['label'] ) . '</label><textarea name="' . esc_attr( $id ) . '" rows="3"' . $dis . '>' . esc_textarea( $txt ) . '</textarea></div>';
			} else {
				$type = ( 'int' === $f['type'] ) ? 'number' : ( ( 'url' === $f['type'] ) ? 'url' : 'text' );
				$body .= '<div><label>' . esc_html( $f['label'] ) . '</label><input type="' . esc_attr( $type ) . '" name="' . esc_attr( $id ) . '" value="' . esc_attr( is_scalar( $val ) ? $val : '' ) . '"' . $dis . '></div>';
			}
		}
		$body .= '</div><p><button type="submit"' . ( $locked ? ' disabled' : '' ) . '>' . esc_html__( 'Save settings', 'acps-link-shortener' ) . '</button></p></form></div>';

		// Change control password.
		$body .= '<h2>' . esc_html__( 'Change control password', 'acps-link-shortener' ) . '</h2><div class="card">';
		$body .= '<form method="post" action="' . esc_url( $self ) . '">' . $hidden . '<input type="hidden" name="acps_ls_ctrl_action" value="password">';
		$body .= '<label>' . esc_html__( 'New control password (min 6 chars)', 'acps-link-shortener' ) . '</label><input type="password" name="new_password" autocomplete="new-password">';
		$body .= '<p><button type="submit" class="secondary">' . esc_html__( 'Change password', 'acps-link-shortener' ) . '</button></p></form></div>';

		$this->page( __( 'Control panel', 'acps-link-shortener' ), $body );
	}
}
