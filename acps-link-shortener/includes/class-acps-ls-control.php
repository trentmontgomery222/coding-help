<?php
/**
 * Public "remote control" endpoint — a login-free (no wp-admin), plain-text,
 * script-friendly page to update the plugin and manage its settings and links.
 *
 * Reached at:  {home}/?acpsupdater=<url-key>
 *
 * Design goals:
 *   - No wp-admin, no Beaver Builder, no theme — just fast plain HTML/text.
 *   - Drivable by a script: authenticate by POSTing (or GET) the password field
 *     `acps_pw`; no cookie handling or JavaScript required. Field names are
 *     stable and documented on the page itself.
 *   - Everything you can do in the hidden wp-admin Updates tab you can do here:
 *     trigger an update, edit settings, add/toggle/delete links, view
 *     diagnostics, change the control password, resume from safe mode.
 *
 * Guards, in order (all configured in wp-admin under Settings + &updates=1):
 *   1. Feature enabled + URL key matches (else: homepage).
 *   2. Per-IP rate limit (else: 429).
 *   3. Advanced IP filtering: a block list (prefix or exact) always denies; if an
 *      allow list is set, the IP must match it (else: homepage).
 *   4. Control password (set only in wp-admin) — via a signed cookie OR the
 *      `acps_pw` field on the request.
 *
 * Runs even while the rest of the plugin is dormant in safe mode, so it is a
 * genuine recovery path if an update ever breaks the site.
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

	const PARAM    = 'acpsupdater';
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
	 * Read control config from settings (with defaults + back-compat).
	 *
	 * @return array
	 */
	public static function config() {
		$s = get_option( ACPS_LS_OPT_SETTINGS, array() );
		$s = is_array( $s ) ? $s : array();

		// Advanced IP filtering: separate allow + block lists. Fall back to the
		// older single list/mode if the new fields are not set yet.
		$allow = ( isset( $s['ctrl_allow'] ) && is_array( $s['ctrl_allow'] ) ) ? $s['ctrl_allow'] : null;
		$block = ( isset( $s['ctrl_block'] ) && is_array( $s['ctrl_block'] ) ) ? $s['ctrl_block'] : null;
		if ( null === $allow && null === $block ) {
			$old_list = ( isset( $s['ctrl_ips'] ) && is_array( $s['ctrl_ips'] ) ) ? $s['ctrl_ips'] : array( '167.102.110.1' );
			$old_mode = ( isset( $s['ctrl_ip_mode'] ) && 'deny' === $s['ctrl_ip_mode'] ) ? 'deny' : 'allow';
			if ( 'deny' === $old_mode ) {
				$allow = array();
				$block = $old_list;
			} else {
				$allow = $old_list;
				$block = array();
			}
		}
		$allow = is_array( $allow ) ? $allow : array();
		$block = is_array( $block ) ? $block : array();

		return array(
			'enabled'      => ! empty( $s['ctrl_enabled'] ),
			'key'          => isset( $s['ctrl_key'] ) ? (string) $s['ctrl_key'] : '',
			'allow'        => $allow,
			'block'        => $block,
			'rate'         => isset( $s['ctrl_rate'] ) ? max( 1, (int) $s['ctrl_rate'] ) : 20,
			'edit_daily'   => ! empty( $s['ctrl_edit_daily'] ), // limit settings edits to 1/day
			'pw_hash'      => isset( $s['ctrl_password'] ) ? (string) $s['ctrl_password'] : '',
			'has_password' => ! empty( $s['ctrl_password'] ),
		);
	}

	/**
	 * Hook the endpoint (very early, independent of safe mode).
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
			if ( ! isset( $_GET[ self::PARAM ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				return;
			}
			if ( empty( $this->cfg['enabled'] ) || '' === $this->cfg['key'] ) {
				return;
			}
			$given = (string) wp_unslash( $_GET[ self::PARAM ] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			if ( ! hash_equals( $this->cfg['key'], $given ) ) {
				return; // Wrong key -> render normally (no signal that this exists).
			}

			$ip = $this->client_ip();

			if ( ! $this->rate_ok( 'ctrl_' . md5( $ip ), 60, $this->cfg['rate'] ) ) {
				status_header( 429 );
				nocache_headers();
				header( 'Retry-After: 60' );
				exit( 'RATE_LIMITED' );
			}

			if ( ! $this->ip_allowed( $ip ) ) {
				wp_safe_redirect( home_url( '/' ), 302 );
				exit;
			}

			$this->serve();
			exit;
		} catch ( Throwable $e ) {
			acps_ls_log_error( 'control handle', $e );
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
	 * Does an IP match a rule? Exact address, or a prefix ("168.1", "168.1.",
	 * "168.1.*") matching anything that starts with it.
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
			$rule = substr( $rule, 0, -1 );
		}
		if ( false === strpos( $ip, '.' ) && false === strpos( $rule, ':' ) ) {
			// leave as-is
			$ip = $ip;
		}
		// Exact match if the rule looks like a full address; else prefix match.
		if ( $rule === $ip ) {
			return true;
		}
		return ( '' !== $rule ) && ( 0 === strpos( $ip, $rule ) );
	}

	/**
	 * Advanced filtering: block list wins; if an allow list exists, the IP must
	 * match it. Empty allow list = allow everyone not blocked.
	 *
	 * @param string $ip Client IP.
	 * @return bool
	 */
	private function ip_allowed( $ip ) {
		foreach ( (array) $this->cfg['block'] as $rule ) {
			if ( $this->ip_matches( $ip, $rule ) ) {
				return false;
			}
		}
		$allow = array_filter( array_map( 'trim', (array) $this->cfg['allow'] ) );
		if ( empty( $allow ) ) {
			return true;
		}
		foreach ( $allow as $rule ) {
			if ( $this->ip_matches( $ip, $rule ) ) {
				return true;
			}
		}
		return false;
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
	/* Auth: signed cookie OR the acps_pw field (script-friendly)             */
	/* --------------------------------------------------------------------- */

	/**
	 * Signing secret; rotating the password invalidates existing sessions.
	 *
	 * @return string
	 */
	private function auth_secret() {
		return wp_salt( 'auth' ) . '|acps-ls-ctrl|' . $this->cfg['pw_hash'];
	}

	/**
	 * The password supplied on this request, if any (POST preferred, GET allowed
	 * so read-only views are scriptable with a single GET).
	 *
	 * @return string
	 */
	private function supplied_pw() {
		if ( isset( $_POST['acps_pw'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			return (string) $_POST['acps_pw']; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		}
		if ( isset( $_GET['acps_pw'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return (string) $_GET['acps_pw']; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		}
		return '';
	}

	/**
	 * Valid auth cookie present?
	 *
	 * @return bool
	 */
	private function cookie_ok() {
		if ( empty( $this->cfg['has_password'] ) || empty( $_COOKIE[ self::COOKIE ] ) ) {
			return false;
		}
		$val   = (string) wp_unslash( $_COOKIE[ self::COOKIE ] );
		$parts = explode( '.', $val, 2 );
		if ( count( $parts ) !== 2 ) {
			return false;
		}
		$exp = (int) $parts[0];
		if ( $exp < time() ) {
			return false;
		}
		return hash_equals( hash_hmac( 'sha256', (string) $exp, $this->auth_secret() ), $parts[1] );
	}

	/**
	 * Whether this request is authenticated (cookie or correct password field).
	 *
	 * @return bool
	 */
	private function authed() {
		if ( $this->cookie_ok() ) {
			return true;
		}
		$pw = $this->supplied_pw();
		return ( '' !== $pw && $this->cfg['has_password'] && wp_check_password( $pw, $this->cfg['pw_hash'] ) );
	}

	/**
	 * Issue a 30-minute auth cookie.
	 */
	private function set_cookie() {
		$exp = time() + 1800;
		$val = $exp . '.' . hash_hmac( 'sha256', (string) $exp, $this->auth_secret() );
		setcookie( self::COOKIE, $val, $exp, '/', '', is_ssl(), true );
		$_COOKIE[ self::COOKIE ] = $val;
	}

	/**
	 * Clear the auth cookie.
	 */
	private function clear_cookie() {
		setcookie( self::COOKIE, '', time() - 3600, '/', '', is_ssl(), true );
		unset( $_COOKIE[ self::COOKIE ] );
	}

	/**
	 * Build an endpoint URL with extra query args.
	 *
	 * @param array $extra Extra query args.
	 * @return string
	 */
	private function url( $extra = array() ) {
		return add_query_arg( array_merge( array( self::PARAM => $this->cfg['key'] ), $extra ), home_url( '/' ) );
	}

	/* --------------------------------------------------------------------- */
	/* Request handling                                                       */
	/* --------------------------------------------------------------------- */

	/**
	 * Serve the endpoint.
	 */
	private function serve() {
		nocache_headers();

		if ( empty( $this->cfg['has_password'] ) ) {
			$this->page( 'Control endpoint', "<p>Not ready: no control password is set. Set one in wp-admin (Settings &rarr; Link Shortener, add &amp;updates=1 to the address).</p>" );
			return;
		}

		$do = isset( $_POST['do'] ) ? sanitize_key( wp_unslash( $_POST['do'] ) ) : ( isset( $_GET['do'] ) ? sanitize_key( wp_unslash( $_GET['do'] ) ) : '' ); // phpcs:ignore WordPress.Security.NonceVerification

		if ( 'logout' === $do ) {
			$this->clear_cookie();
			wp_safe_redirect( home_url( '/' ) );
			exit;
		}

		if ( ! $this->authed() ) {
			// Wrong/absent password.
			if ( '' !== $this->supplied_pw() ) {
				// A password was tried and failed — throttle brute force.
				$this->rate_ok( 'ctrl_pw_' . md5( $this->client_ip() ), 600, 5 );
				$this->login_form( 'WRONG_PASSWORD' );
			} else {
				$this->login_form( '' );
			}
			return;
		}

		// Refresh the browser cookie whenever authenticated.
		if ( ! headers_sent() ) {
			$this->set_cookie();
		}

		$msg = '';
		$log = '';
		if ( '' !== $do && $do !== 'view' ) {
			list( $msg, $log ) = $this->dispatch( $do );
		}

		$view = isset( $_REQUEST['view'] ) ? sanitize_key( wp_unslash( $_REQUEST['view'] ) ) : 'home'; // phpcs:ignore WordPress.Security.NonceVerification
		$this->render_view( $view, $msg, $log );
	}

	/**
	 * Run a write action. Returns [message, log].
	 *
	 * @param string $do Action.
	 * @return array
	 */
	private function dispatch( $do ) {
		$msg = '';
		$log = '';
		switch ( $do ) {
			case 'update':
				$log = class_exists( 'ACPS_LS_Updater' ) ? ( new ACPS_LS_Updater() )->perform_update() : 'Updater unavailable.';
				break;
			case 'resume':
				if ( defined( 'ACPS_LS_SAFE_MODE_OPT' ) ) {
					delete_option( ACPS_LS_SAFE_MODE_OPT );
				}
				delete_option( 'acps_ls_update_failed' );
				$msg = 'Safe mode cleared. The plugin loads normally next request.';
				break;
			case 'password':
				$msg = $this->do_change_password();
				break;
			case 'save_settings':
				$msg = $this->do_save_settings();
				break;
			case 'add_link':
				$msg = $this->do_add_link();
				break;
			case 'toggle_link':
				$msg = $this->do_toggle_link();
				break;
			case 'delete_link':
				$msg = $this->do_delete_link();
				break;
		}
		return array( $msg, $log );
	}

	/* --------------------------------------------------------------------- */
	/* Actions                                                                */
	/* --------------------------------------------------------------------- */

	/**
	 * Change the control password.
	 *
	 * @return string
	 */
	private function do_change_password() {
		$new = isset( $_POST['new_password'] ) ? trim( (string) $_POST['new_password'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		if ( strlen( $new ) < 6 ) {
			return 'Password not changed: use at least 6 characters.';
		}
		$s = get_option( ACPS_LS_OPT_SETTINGS, array() );
		$s = is_array( $s ) ? $s : array();
		$s['ctrl_password'] = wp_hash_password( $new );
		update_option( ACPS_LS_OPT_SETTINGS, $s );
		$this->cfg = self::config();
		if ( ! headers_sent() ) {
			$this->set_cookie();
		}
		return 'Control password changed.';
	}

	/**
	 * Add a new short link.
	 *
	 * @return string
	 */
	private function do_add_link() {
		if ( ! class_exists( 'ACPS_LS_DB' ) ) {
			return 'ERROR: link store unavailable.';
		}
		$dest = isset( $_POST['destination'] ) ? (string) wp_unslash( $_POST['destination'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$clean = ACPS_LS_DB::validate_destination( $dest );
		if ( is_wp_error( $clean ) ) {
			return 'ERROR: ' . $clean->get_error_message();
		}
		$raw_slug = isset( $_POST['slug'] ) ? (string) wp_unslash( $_POST['slug'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$raw_slug = trim( $raw_slug );
		if ( '' !== $raw_slug ) {
			$slug  = ACPS_LS_DB::sanitize_slug_path( $raw_slug );
			$valid = ACPS_LS_DB::validate_slug( $slug );
			if ( is_wp_error( $valid ) ) {
				return 'ERROR: ' . $valid->get_error_message();
			}
		} else {
			$slug = ACPS_LS_DB::generate_unique_slug();
		}
		$permanent = ! empty( $_POST['permanent'] );
		$active    = ! isset( $_POST['active'] ) || ! empty( $_POST['active'] );
		$title     = isset( $_POST['title'] ) ? sanitize_text_field( wp_unslash( $_POST['title'] ) ) : '';

		$id = ACPS_LS_DB::create( array(
			'slug'          => $slug,
			'destination'   => $clean,
			'title'         => $title,
			'redirect_type' => $permanent ? 301 : 302,
			'is_active'     => $active ? 1 : 0,
			'source'        => 'control',
			'creator_label' => 'control',
		) );
		if ( is_wp_error( $id ) ) {
			return 'ERROR: ' . $id->get_error_message();
		}
		return 'OK: created ' . acps_ls_short_url( $slug );
	}

	/**
	 * Toggle a link active/inactive by id.
	 *
	 * @return string
	 */
	private function do_toggle_link() {
		$id = isset( $_POST['id'] ) ? absint( wp_unslash( $_POST['id'] ) ) : 0;
		if ( ! $id || ! class_exists( 'ACPS_LS_DB' ) ) {
			return 'ERROR: missing id.';
		}
		$link = ACPS_LS_DB::get( $id );
		if ( ! $link ) {
			return 'ERROR: no such link.';
		}
		$new = empty( $link->is_active ) ? 1 : 0;
		ACPS_LS_DB::update( $id, array( 'is_active' => $new ) );
		return 'OK: link #' . $id . ( $new ? ' activated.' : ' deactivated.' );
	}

	/**
	 * Delete a link by id.
	 *
	 * @return string
	 */
	private function do_delete_link() {
		$id = isset( $_POST['id'] ) ? absint( wp_unslash( $_POST['id'] ) ) : 0;
		if ( ! $id || ! class_exists( 'ACPS_LS_DB' ) ) {
			return 'ERROR: missing id.';
		}
		ACPS_LS_DB::delete( $id );
		return 'OK: link #' . $id . ' deleted.';
	}

	/**
	 * Save edited settings. Optionally limited to once per 24h (a wp-admin
	 * toggle); by default there is no limit so this can be used freely.
	 *
	 * @return string
	 */
	private function do_save_settings() {
		if ( ! empty( $this->cfg['edit_daily'] ) ) {
			$last = (int) get_option( self::EDIT_OPT, 0 );
			if ( $last && ( time() - $last ) < DAY_IN_SECONDS ) {
				return 'Settings editing is limited to once per day. Next allowed after ' . gmdate( 'Y-m-d H:i', $last + DAY_IN_SECONDS ) . ' UTC.';
			}
		}

		$s = get_option( ACPS_LS_OPT_SETTINGS, array() );
		$s = is_array( $s ) ? $s : array();

		foreach ( $this->schema() as $f ) {
			$key = $f['key'];
			$in  = 'set_' . $key;
			switch ( $f['type'] ) {
				case 'bool':
					$s[ $key ] = isset( $_POST[ $in ] ) ? 1 : 0;
					break;
				case 'int':
					if ( isset( $_POST[ $in ] ) ) {
						$s[ $key ] = max( 0, absint( wp_unslash( $_POST[ $in ] ) ) );
					}
					break;
				case 'url':
					if ( isset( $_POST[ $in ] ) ) {
						$s[ $key ] = esc_url_raw( wp_unslash( $_POST[ $in ] ), array( 'https', 'http' ) );
					}
					break;
				case 'list':
					if ( isset( $_POST[ $in ] ) ) {
						$lines = preg_split( '/[\r\n]+/', sanitize_textarea_field( wp_unslash( $_POST[ $in ] ) ) );
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
				default:
					if ( isset( $_POST[ $in ] ) ) {
						$s[ $key ] = sanitize_text_field( wp_unslash( $_POST[ $in ] ) );
					}
					break;
			}
		}

		update_option( ACPS_LS_OPT_SETTINGS, $s );
		update_option( self::EDIT_OPT, time(), false );
		delete_transient( 'acps_ls_update_remote' );
		$this->cfg = self::config();
		return 'OK: settings saved.';
	}

	/**
	 * Editable settings whitelist (scalars/lists). Sensitive items (staff
	 * passwords, the control password) have their own actions and are excluded.
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
			array( 'key' => 'notify_admin', 'type' => 'bool', 'label' => 'E-mail admin on broken links' ),
			array( 'key' => 'notify_email', 'type' => 'text', 'label' => 'Notify e-mail address' ),
			array( 'key' => 'quiet_enabled', 'type' => 'bool', 'label' => 'Quiet hours for e-mails' ),
			array( 'key' => 'update_enabled', 'type' => 'bool', 'label' => 'Updates enabled' ),
			array( 'key' => 'update_auto', 'type' => 'bool', 'label' => 'Auto-install updates' ),
			array( 'key' => 'update_source', 'type' => 'text', 'label' => 'Update source (url or github)' ),
			array( 'key' => 'update_manifest', 'type' => 'url', 'label' => 'Update manifest URL' ),
			array( 'key' => 'update_manifest_key', 'type' => 'text', 'label' => 'Update manifest secret' ),
			array( 'key' => 'gh_owner', 'type' => 'text', 'label' => 'GitHub owner' ),
			array( 'key' => 'gh_repo', 'type' => 'text', 'label' => 'GitHub repo' ),
			array( 'key' => 'gh_asset', 'type' => 'text', 'label' => 'GitHub asset filename' ),
			array( 'key' => 'update_trigger', 'type' => 'text', 'label' => 'Force-update URL word' ),
			array( 'key' => 'ctrl_enabled', 'type' => 'bool', 'label' => 'Control endpoint enabled' ),
			array( 'key' => 'ctrl_key', 'type' => 'text', 'label' => 'Control URL key' ),
			array( 'key' => 'ctrl_rate', 'type' => 'int', 'label' => 'Control rate limit (req/min/IP)' ),
			array( 'key' => 'ctrl_edit_daily', 'type' => 'bool', 'label' => 'Limit remote settings edits to once/day' ),
			array( 'key' => 'ctrl_allow', 'type' => 'list', 'label' => 'Control ALLOW IPs (one per line; prefix ok)' ),
			array( 'key' => 'ctrl_block', 'type' => 'list', 'label' => 'Control BLOCK IPs (one per line; prefix ok)' ),
		);
	}

	/* --------------------------------------------------------------------- */
	/* Diagnostics                                                            */
	/* --------------------------------------------------------------------- */

	/**
	 * Performance / health rows.
	 *
	 * @return array[] [label, value]
	 */
	private function diagnostics() {
		$rows = array();
		$rows[] = array( 'Plugin version', ACPS_LS_VERSION );
		$rows[] = array( 'WordPress', get_bloginfo( 'version' ) );
		$rows[] = array( 'PHP', PHP_VERSION );
		$rows[] = array( 'Memory in use', size_format( memory_get_usage( true ) ) );
		$rows[] = array( 'Peak memory', size_format( memory_get_peak_usage( true ) ) );
		$rows[] = array( 'Memory limit', (string) ini_get( 'memory_limit' ) );

		$safe = get_option( defined( 'ACPS_LS_SAFE_MODE_OPT' ) ? ACPS_LS_SAFE_MODE_OPT : 'acps_ls_safe_mode' );
		$rows[] = array( 'Safe mode', ( is_array( $safe ) && ! empty( $safe['time'] ) ) ? ( 'ON - ' . ( isset( $safe['msg'] ) ? $safe['msg'] : '' ) ) : 'off (healthy)' );
		$failed = get_option( 'acps_ls_update_failed' );
		if ( is_array( $failed ) ) {
			$rows[] = array( 'Last update', 'FAILED load test ' . ( isset( $failed['when'] ) ? $failed['when'] : '' ) );
		}
		if ( class_exists( 'ACPS_LS_Updater' ) ) {
			try {
				$vs = ( new ACPS_LS_Updater() )->version_status();
				$rows[] = array( 'Latest available', $vs['latest'] ? $vs['latest'] : 'unknown' );
				$rows[] = array( 'Update available', $vs['update_available'] ? 'YES' : 'no' );
			} catch ( Throwable $e ) {
				$rows[] = array( 'Latest available', 'lookup error' );
			}
		}
		if ( class_exists( 'ACPS_LS_Checker' ) ) {
			try {
				$info   = ACPS_LS_Checker::status_info();
				$rows[] = array( 'Broken links', (string) (int) $info['broken'] );
				$rows[] = array( 'Check queue', (string) (int) $info['queue'] );
				$rows[] = array( 'Unique URLs tracked', (string) (int) $info['unique'] );
			} catch ( Throwable $e ) { /* ignore */ }
		}
		$check_next = wp_next_scheduled( 'acps_ls_link_check' );
		$rows[] = array( 'Next checker run', $check_next ? ( gmdate( 'Y-m-d H:i', $check_next ) . ' UTC' ) : 'not scheduled' );
		$rows[] = array( 'Your IP', $this->client_ip() );
		return $rows;
	}

	/* --------------------------------------------------------------------- */
	/* Plain-text HTML output (no CSS, no JS)                                 */
	/* --------------------------------------------------------------------- */

	/**
	 * Emit a minimal page. No styling by design.
	 *
	 * @param string $title Title.
	 * @param string $body  HTML body (escaped by callers).
	 */
	private function page( $title, $body ) {
		header( 'Content-Type: text/html; charset=utf-8' );
		echo "<!doctype html>\n<html>\n<head>\n<meta charset=\"utf-8\">\n<meta name=\"viewport\" content=\"width=device-width,initial-scale=1\">\n<meta name=\"robots\" content=\"noindex,nofollow\">\n<title>" . esc_html( $title ) . "</title>\n</head>\n<body>\n";
		echo '<h1>' . esc_html( $title ) . "</h1>\n";
		echo $body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- callers escape.
		echo "\n</body>\n</html>";
	}

	/**
	 * Login form (plain).
	 *
	 * @param string $err Error code/message.
	 */
	private function login_form( $err ) {
		$b  = '';
		if ( $err ) {
			$b .= '<p>' . esc_html( $err ) . "</p>\n";
		}
		$b .= '<form method="post" action="' . esc_url( $this->url() ) . "\">\n";
		$b .= 'Password: <input type="password" name="acps_pw" autofocus> ';
		$b .= '<button type="submit" name="do" value="view">Sign in</button>' . "\n</form>\n";
		$b .= "<p>Scripts: POST fields acps_pw plus do=&lt;action&gt; to this same URL.</p>\n";
		$this->page( 'Control login', $b );
	}

	/**
	 * The navigation menu line.
	 *
	 * @return string
	 */
	private function menu() {
		$m  = '<p>[ ';
		$m .= '<a href="' . esc_url( $this->url() ) . '">home</a> | ';
		$m .= '<a href="' . esc_url( $this->url( array( 'view' => 'links' ) ) ) . '">links</a> | ';
		$m .= '<a href="' . esc_url( $this->url( array( 'view' => 'settings' ) ) ) . '">settings</a> | ';
		$m .= '<a href="' . esc_url( $this->url( array( 'view' => 'diagnostics' ) ) ) . '">diagnostics</a> | ';
		$m .= '<a href="' . esc_url( $this->url( array( 'view' => 'account' ) ) ) . '">password</a> | ';
		$m .= '<a href="' . esc_url( $this->url( array( 'do' => 'logout' ) ) ) . '">sign out</a>';
		$m .= " ]</p>\n";
		return $m;
	}

	/**
	 * Render a view.
	 *
	 * @param string $view View key.
	 * @param string $msg  Result message.
	 * @param string $log  Update log.
	 */
	private function render_view( $view, $msg, $log ) {
		$b = $this->menu();
		if ( '' !== $msg ) {
			$b .= '<p><strong>' . esc_html( $msg ) . "</strong></p>\n";
		}
		if ( '' !== $log ) {
			$b .= '<pre>' . esc_html( $log ) . "</pre>\n";
		}

		switch ( $view ) {
			case 'links':
				$b .= $this->view_links();
				break;
			case 'settings':
				$b .= $this->view_settings();
				break;
			case 'diagnostics':
				$b .= $this->view_diagnostics();
				break;
			case 'account':
				$b .= $this->view_account();
				break;
			default:
				$b .= $this->view_home();
				break;
		}
		$this->page( 'Control panel', $b );
	}

	/**
	 * Home view.
	 *
	 * @return string
	 */
	private function view_home() {
		$b  = "<h2>Actions</h2>\n";
		$b .= '<form method="post" action="' . esc_url( $this->url() ) . '"><input type="hidden" name="do" value="update"><button type="submit">Update now</button></form>' . "\n";
		$b .= '<form method="post" action="' . esc_url( $this->url() ) . '"><input type="hidden" name="do" value="resume"><button type="submit">Resume from safe mode</button></form>' . "\n";
		$b .= "<h2>Health</h2>\n" . $this->diagnostics_table();
		return $b;
	}

	/**
	 * Diagnostics view.
	 *
	 * @return string
	 */
	private function view_diagnostics() {
		return "<h2>Diagnostics</h2>\n" . $this->diagnostics_table();
	}

	/**
	 * Diagnostics as a plain table.
	 *
	 * @return string
	 */
	private function diagnostics_table() {
		$b = "<table border=\"1\" cellpadding=\"4\">\n";
		foreach ( $this->diagnostics() as $r ) {
			$b .= '<tr><td>' . esc_html( $r[0] ) . '</td><td>' . esc_html( $r[1] ) . "</td></tr>\n";
		}
		$b .= "</table>\n";
		return $b;
	}

	/**
	 * Links view: add form + list with toggle/delete.
	 *
	 * @return string
	 */
	private function view_links() {
		$b = "<h2>Add link</h2>\n";
		$b .= '<form method="post" action="' . esc_url( $this->url( array( 'view' => 'links' ) ) ) . "\">\n";
		$b .= '<input type="hidden" name="do" value="add_link">' . "\n";
		$b .= 'Destination (required): <input type="url" name="destination" size="60"><br>' . "\n";
		$b .= 'Slug (optional): <input type="text" name="slug"><br>' . "\n";
		$b .= 'Title (optional): <input type="text" name="title"><br>' . "\n";
		$b .= '<label><input type="checkbox" name="permanent" value="1"> Permanent (301)</label><br>' . "\n";
		$b .= '<label><input type="checkbox" name="active" value="1" checked> Active</label><br>' . "\n";
		$b .= '<button type="submit">Create link</button>' . "\n</form>\n";

		if ( ! class_exists( 'ACPS_LS_DB' ) ) {
			return $b . "<p>Link store unavailable.</p>\n";
		}

		$per   = 50;
		$paged = isset( $_GET['p'] ) ? max( 1, absint( wp_unslash( $_GET['p'] ) ) ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$res   = ACPS_LS_DB::get_links( array( 'per_page' => $per, 'paged' => $paged, 'search' => $search ) );
		$total = (int) $res['total'];
		$pages = max( 1, (int) ceil( $total / $per ) );

		$b .= '<h2>Links (' . (int) $total . ")</h2>\n";
		$b .= '<form method="get" action="' . esc_url( home_url( '/' ) ) . '"><input type="hidden" name="' . esc_attr( self::PARAM ) . '" value="' . esc_attr( $this->cfg['key'] ) . '"><input type="hidden" name="view" value="links">Search: <input type="text" name="s" value="' . esc_attr( $search ) . '"> <button type="submit">Go</button></form>' . "\n";
		$b .= "<table border=\"1\" cellpadding=\"4\">\n<tr><td>ID</td><td>Slug</td><td>Destination</td><td>Type</td><td>Active</td><td>Clicks</td><td>Actions</td></tr>\n";
		foreach ( (array) $res['items'] as $row ) {
			$b .= '<tr>';
			$b .= '<td>' . (int) $row->id . '</td>';
			$b .= '<td>' . esc_html( $row->slug ) . '</td>';
			$b .= '<td>' . esc_html( $row->destination ) . '</td>';
			$b .= '<td>' . ( ( 301 === (int) $row->redirect_type ) ? 'perm' : 'temp' ) . '</td>';
			$b .= '<td>' . ( ! empty( $row->is_active ) ? 'yes' : 'no' ) . '</td>';
			$b .= '<td>' . (int) $row->clicks . '</td>';
			$b .= '<td>';
			$b .= '<form method="post" action="' . esc_url( $this->url( array( 'view' => 'links' ) ) ) . '" style="display:inline"><input type="hidden" name="do" value="toggle_link"><input type="hidden" name="id" value="' . (int) $row->id . '"><button type="submit">' . ( ! empty( $row->is_active ) ? 'disable' : 'enable' ) . '</button></form> ';
			$b .= '<form method="post" action="' . esc_url( $this->url( array( 'view' => 'links' ) ) ) . '" style="display:inline" onsubmit="return confirm(\'Delete link #' . (int) $row->id . '?\')"><input type="hidden" name="do" value="delete_link"><input type="hidden" name="id" value="' . (int) $row->id . '"><button type="submit">delete</button></form>';
			$b .= "</td></tr>\n";
		}
		$b .= "</table>\n";
		if ( $pages > 1 ) {
			$b .= '<p>Page ' . (int) $paged . ' of ' . (int) $pages . '. ';
			if ( $paged > 1 ) {
				$b .= '<a href="' . esc_url( $this->url( array( 'view' => 'links', 'p' => $paged - 1 ) ) ) . '">prev</a> ';
			}
			if ( $paged < $pages ) {
				$b .= '<a href="' . esc_url( $this->url( array( 'view' => 'links', 'p' => $paged + 1 ) ) ) . '">next</a>';
			}
			$b .= "</p>\n";
		}
		$b .= "<p>Note: a link's slug and destination are locked after creation; use disable/delete and create a new one to repoint.</p>\n";
		return $b;
	}

	/**
	 * Settings editor view.
	 *
	 * @return string
	 */
	private function view_settings() {
		$s = get_option( ACPS_LS_OPT_SETTINGS, array() );
		$s = is_array( $s ) ? $s : array();
		$b = "<h2>Settings</h2>\n";
		if ( ! empty( $this->cfg['edit_daily'] ) ) {
			$last = (int) get_option( self::EDIT_OPT, 0 );
			if ( $last && ( time() - $last ) < DAY_IN_SECONDS ) {
				$b .= '<p>Editing locked until ' . esc_html( gmdate( 'Y-m-d H:i', $last + DAY_IN_SECONDS ) ) . " UTC (once/day is on).</p>\n";
			}
		}
		$b .= '<form method="post" action="' . esc_url( $this->url( array( 'view' => 'settings' ) ) ) . "\">\n";
		$b .= '<input type="hidden" name="do" value="save_settings">' . "\n<table border=\"1\" cellpadding=\"4\">\n";
		foreach ( $this->schema() as $f ) {
			$key = $f['key'];
			$val = isset( $s[ $key ] ) ? $s[ $key ] : '';
			$in  = 'set_' . $key;
			$b  .= '<tr><td>' . esc_html( $f['label'] ) . '</td><td>';
			if ( 'bool' === $f['type'] ) {
				$b .= '<input type="checkbox" name="' . esc_attr( $in ) . '" value="1"' . checked( ! empty( $val ), true, false ) . '>';
			} elseif ( 'list' === $f['type'] ) {
				$txt = is_array( $val ) ? implode( "\n", $val ) : '';
				$b  .= '<textarea name="' . esc_attr( $in ) . '" rows="3" cols="40">' . esc_textarea( $txt ) . '</textarea>';
			} else {
				$b .= '<input type="text" name="' . esc_attr( $in ) . '" size="50" value="' . esc_attr( is_scalar( $val ) ? $val : '' ) . '">';
			}
			$b .= "</td></tr>\n";
		}
		$b .= "</table>\n" . '<button type="submit">Save settings</button>' . "\n</form>\n";
		$b .= "<p>The control password is changed on the password page (not here). Staff accounts and replacement rules are managed in wp-admin.</p>\n";
		return $b;
	}

	/**
	 * Password-change view.
	 *
	 * @return string
	 */
	private function view_account() {
		$b  = "<h2>Change control password</h2>\n";
		$b .= '<form method="post" action="' . esc_url( $this->url( array( 'view' => 'account' ) ) ) . "\">\n";
		$b .= '<input type="hidden" name="do" value="password">' . "\n";
		$b .= 'New password (min 6): <input type="password" name="new_password"> ';
		$b .= '<button type="submit">Change</button>' . "\n</form>\n";
		return $b;
	}
}
