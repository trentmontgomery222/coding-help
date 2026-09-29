<?php
/**
 * The control panel served at the update URL.
 *
 * A front-end page, reachable without logging in, that reports on the
 * plugin and lets everything wp-admin can do be done from outside it.
 * That is a lot of power on a public URL, so it is gated four ways and
 * each gate is checked before the next: the caller's IP, a rate limit,
 * the secret in the URL, and - for anything that writes - a password
 * that can only be set while logged into wp-admin.
 *
 * The page is deliberately plain: no CSS, no JavaScript, no images.
 * Everything is text, the forms are ordinary HTML forms, and every
 * field name is stable, so a script can post to it as easily as a
 * person can click it. `&view=raw` drops the forms entirely and
 * returns text/plain.
 *
 * @package WPCodeBBV
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'WPCODEBBV_VERSION' ) ) {
	return;
}

class WPCodeBBV_Panel {

	/** Query var that opens the panel. */
	const QUERY_VAR = 'acpsupdater';

	/** Rolling record of recent problems, for the panel to report. */
	const LOG_OPT = 'wpcodebbv_recent_issues';

	/** When settings were last changed from the panel. */
	const EDIT_OPT = 'wpcodebbv_panel_last_edit';

	/** Most recent issues kept. */
	const LOG_MAX = 25;

	/**
	 * Settings the panel may never write, whatever is posted.
	 *
	 * The password is the gate this page stands behind; letting the page
	 * change it would let anyone who got through once lock the owner out
	 * for good. It is set in wp-admin and nowhere else.
	 *
	 * @var string[]
	 */
	private static $read_only = array( 'panel_password_hash' );

	public function register() {
		// Priority 0: before anything else gets a chance to fail, so the
		// panel still answers when the rest of the plugin is unhappy.
		add_action( 'init', array( $this, 'maybe_handle' ), 0 );
	}

	/**
	 * The key that opens the panel: panel_key if one is set, otherwise
	 * the update secret, so an install that predates panel_key keeps
	 * working without anyone having to go and set it.
	 *
	 * It is stored slugified, so what comes back here is lower-case and
	 * URL-safe whatever was typed into the settings box.
	 *
	 * @return string
	 */
	public static function key() {
		$key = trim( (string) WPCodeBBV_Settings::get( 'panel_key' ) );

		if ( '' !== $key ) {
			return $key;
		}

		return trim( (string) WPCodeBBV_Settings::get( 'update_trigger' ) );
	}

	/**
	 * The panel's own address, for printing on the admin screen.
	 *
	 * @return string Empty when there is no key to use.
	 */
	public static function url() {
		$key = self::key();

		if ( '' === $key ) {
			return '';
		}

		return add_query_arg( self::QUERY_VAR, rawurlencode( $key ), home_url( '/' ) );
	}

	/* -----------------------------------------------------------------
	 * Gate 1 - who is asking
	 * -------------------------------------------------------------- */

	/**
	 * The caller's IP.
	 *
	 * Proxy headers are deliberately NOT trusted: anyone can send
	 * X-Forwarded-For, and trusting it would turn the IP gate into a
	 * formality. REMOTE_ADDR is the only address the web server itself
	 * vouches for.
	 *
	 * @return string
	 */
	public static function client_ip() {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : '';

		return filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '';
	}

	/**
	 * Whether an IP passes the rules.
	 *
	 * One rule per line:
	 *
	 *   167.102.110.1     allow exactly this address
	 *   168.1.            allow anything starting with this
	 *   168.1.*           the same thing, written the other way
	 *   10.0.0.0/8        allow anything in this range
	 *   !203.0.113.7      never allow this address
	 *   !10.              never allow anything starting with this
	 *   !192.168.0.0/16   never allow anything in this range
	 *   *                 allow everything (only ever what you meant)
	 *
	 * A deny always wins. If any allow rules are present the address has
	 * to match one of them, so the default of a single address means
	 * only that address gets in. With no rules at all nothing is allowed
	 * - an empty box must not mean "open to the world".
	 *
	 * @param string $ip
	 * @param string $rules
	 * @return bool
	 */
	public static function ip_allowed( $ip, $rules ) {
		if ( '' === $ip ) {
			return false;
		}

		$allows = array();
		$denies = array();

		foreach ( preg_split( '/[\r\n,]+/', (string) $rules ) as $rule ) {
			$rule = trim( $rule );

			if ( '' === $rule || '#' === substr( $rule, 0, 1 ) ) {
				continue;
			}

			if ( '!' === substr( $rule, 0, 1 ) ) {
				$denies[] = trim( substr( $rule, 1 ) );
			} else {
				$allows[] = $rule;
			}
		}

		foreach ( $denies as $rule ) {
			if ( '' !== $rule && self::ip_matches( $ip, $rule ) ) {
				return false;
			}
		}

		if ( empty( $allows ) ) {
			return false;
		}

		foreach ( $allows as $rule ) {
			if ( self::ip_matches( $ip, $rule ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Exact address, a prefix ("168.1." / "168.1*"), a CIDR range, or
	 * "*" for everything.
	 *
	 * @param string $ip
	 * @param string $rule
	 * @return bool
	 */
	private static function ip_matches( $ip, $rule ) {
		$rule = trim( $rule );

		if ( '' === $rule ) {
			return false;
		}

		if ( '*' === $rule ) {
			return true;
		}

		if ( false !== strpos( $rule, '/' ) ) {
			return self::ip_in_cidr( $ip, $rule );
		}

		$rule = rtrim( $rule, '*' );

		if ( '' === $rule ) {
			return false;
		}

		// Anything that is not a complete address is read as a prefix,
		// so "168.1" works as well as "168.1." - people write both and
		// only ever mean the same thing by them.
		if ( '.' === substr( $rule, -1 ) || ':' === substr( $rule, -1 ) || ! filter_var( $rule, FILTER_VALIDATE_IP ) ) {
			return 0 === strpos( $ip, $rule );
		}

		return $ip === $rule;
	}

	/**
	 * Whether an address falls inside a CIDR range. IPv4 and IPv6 both.
	 *
	 * @param string $ip
	 * @param string $cidr
	 * @return bool
	 */
	private static function ip_in_cidr( $ip, $cidr ) {
		$parts = explode( '/', $cidr, 2 );
		$net    = trim( $parts[0] );
		$bits   = isset( $parts[1] ) ? (int) trim( $parts[1] ) : -1;

		$a = @inet_pton( $ip );
		$b = @inet_pton( $net );

		if ( false === $a || false === $b || strlen( $a ) !== strlen( $b ) ) {
			return false;
		}

		$max = strlen( $a ) * 8;

		if ( $bits < 0 || $bits > $max ) {
			return false;
		}

		$whole = intdiv( $bits, 8 );
		$rest  = $bits % 8;

		if ( $whole > 0 && substr( $a, 0, $whole ) !== substr( $b, 0, $whole ) ) {
			return false;
		}

		if ( 0 === $rest ) {
			return true;
		}

		$mask = chr( 0xff << ( 8 - $rest ) & 0xff );

		return ( $a[ $whole ] & $mask ) === ( $b[ $whole ] & $mask );
	}

	/* -----------------------------------------------------------------
	 * Gate 2 - how often
	 * -------------------------------------------------------------- */

	/**
	 * Counts this hit and says whether the caller is within the limit.
	 * Kept in a transient per address, so it costs nothing once the
	 * window passes.
	 *
	 * @param string $ip
	 * @return array{ok:bool, hits:int, limit:int, window:int}
	 */
	public static function rate_check( $ip ) {
		$limit  = max( 1, (int) WPCodeBBV_Settings::get( 'panel_rate_limit', 20 ) );
		$window = max( 10, (int) WPCodeBBV_Settings::get( 'panel_rate_window', 300 ) );
		$key    = 'wpcodebbv_rate_' . md5( $ip );
		$hits   = (int) get_transient( $key );
		$hits++;

		set_transient( $key, $hits, $window );

		return array(
			'ok'     => $hits <= $limit,
			'hits'   => $hits,
			'limit'  => $limit,
			'window' => $window,
		);
	}

	/* -----------------------------------------------------------------
	 * Gate 4 - the password (gate 3 is the secret, checked by the caller)
	 * -------------------------------------------------------------- */

	/**
	 * @param string $given
	 * @return bool
	 */
	public static function password_ok( $given ) {
		$hash = (string) WPCodeBBV_Settings::get( 'panel_password_hash' );

		if ( '' === $hash || '' === (string) $given ) {
			return false; // No password set means no writing from out here.
		}

		return wp_check_password( (string) $given, $hash );
	}

	/**
	 * Whether a settings change is allowed yet - once a day.
	 *
	 * @return array{ok:bool, next:int}
	 */
	public static function edit_allowed() {
		$interval = max( 60, (int) WPCodeBBV_Settings::get( 'panel_edit_interval', DAY_IN_SECONDS ) );
		$last     = (int) get_option( self::EDIT_OPT, 0 );
		$next     = $last + $interval;

		return array(
			'ok'   => time() >= $next,
			'next' => $next,
		);
	}

	/* -----------------------------------------------------------------
	 * What the panel reports
	 * -------------------------------------------------------------- */

	/**
	 * Adds a problem to the rolling record the panel shows. Called from
	 * wpcodebbv_log(), so anything the plugin logs is visible here too.
	 *
	 * @param string $message
	 */
	public static function record_issue( $message ) {
		$log = get_option( self::LOG_OPT, array() );

		if ( ! is_array( $log ) ) {
			$log = array();
		}

		$log[] = array(
			'time' => time(),
			'msg'  => substr( (string) $message, 0, 500 ),
		);

		if ( count( $log ) > self::LOG_MAX ) {
			$log = array_slice( $log, -self::LOG_MAX );
		}

		update_option( self::LOG_OPT, $log, false );
	}

	/**
	 * Whether the last update left the update system able to update
	 * again - reported here because this page is the only thing that can
	 * still be reached if it did not.
	 *
	 * @return string
	 */
	private static function update_system_state() {
		$problems = get_option( 'wpcodebbv_update_system_problems' );

		if ( is_array( $problems ) && ! empty( $problems['problems'] ) ) {
			return 'PROBLEMS after the update on ' . $problems['when'] . ': ' . implode( '; ', (array) $problems['problems'] );
		}

		$missing = array();

		if ( '' === self::key() ) {
			return 'no key set - this page is unreachable once that is true';
		}

		if ( ! class_exists( 'WPCodeBBV_Updater' ) ) {
			$missing[] = 'updater not loaded';
		}

		if ( '' === trim( (string) WPCodeBBV_Settings::get( 'update_manifest' ) )
			&& 'github' !== WPCodeBBV_Settings::get( 'update_source' ) ) {
			$missing[] = 'no manifest URL';
		}

		return $missing ? implode( ', ', $missing ) : 'healthy';
	}

	/**
	 * Everything worth knowing about how this install is doing.
	 *
	 * @return array<string, array<string, string>>
	 */
	public static function diagnostics() {
		$out = array();

		$out['Performance'] = array(
			'Memory in use' => size_format( memory_get_usage( true ) ),
			'Peak memory'   => size_format( memory_get_peak_usage( true ) ),
			'Memory limit'  => (string) ini_get( 'memory_limit' ),
			'This request'  => defined( 'WPCODEBBV_START' )
				? number_format( ( microtime( true ) - WPCODEBBV_START ) * 1000, 1 ) . ' ms'
				: 'n/a',
			'PHP'           => PHP_VERSION,
			'WordPress'     => get_bloginfo( 'version' ),
		);

		$missing = function_exists( 'wpcodebbv_missing_files' ) ? wpcodebbv_missing_files() : array();
		$safe    = function_exists( 'wpcodebbv_is_safe_mode' ) && wpcodebbv_is_safe_mode();
		$failed  = get_option( 'wpcodebbv_update_failed' );

		$out['Health'] = array(
			'Plugin version' => WPCODEBBV_VERSION,
			'Safe mode'      => $safe ? 'ON - the plugin is dormant after a caught fatal' : 'off',
			'Missing files'  => $missing ? implode( ', ', array_keys( $missing ) ) : 'none',
			'Load errors'    => ! empty( $GLOBALS['wpcodebbv_load_errors'] ) ? implode( '; ', $GLOBALS['wpcodebbv_load_errors'] ) : 'none',
			'Last update'    => is_array( $failed ) && ! empty( $failed['when'] )
				? 'FAILED its load test at ' . $failed['when']
				: 'no failure recorded',
			'Update system'  => self::update_system_state(),
		);

		$snippets = function_exists( 'wpcodebbv_snippets' ) ? wpcodebbv_snippets() : array();
		$settings = 0;

		foreach ( $snippets as $snippet ) {
			$settings += isset( $snippet['settings'] ) ? count( $snippet['settings'] ) : 0;
		}

		$remote = class_exists( 'WPCodeBBV_Updater' )
			? WPCodeBBV_Updater::peek_status()
			: array( 'checked' => false, 'remote' => false );

		$out['Plugin'] = array(
			'Snippets read'  => (string) count( $snippets ),
			'Settings found' => (string) $settings,
			'Update source'  => (string) WPCodeBBV_Settings::get( 'update_source' ),
			'Latest seen'    => ! empty( $remote['remote']['version'] ) ? (string) $remote['remote']['version'] : 'not checked yet',
		);

		return $out;
	}

	/* -----------------------------------------------------------------
	 * The request
	 * -------------------------------------------------------------- */

	public function maybe_handle() {
		// Only flipped once the key has proved this request is for this
		// plugin. Until then a failure must stay silent - see the catch.
		$ours = false;

		try {
			if ( ! isset( $_GET[ self::QUERY_VAR ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				return;
			}

			$given = sanitize_text_field( wp_unslash( $_GET[ self::QUERY_VAR ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$key   = self::key();

			/*
			 * Gate 0: is this request ours at all?
			 *
			 * This parameter is a shared convention - other plugins on
			 * the same site answer on it too, each with its own key. So
			 * the key is compared before anything else happens, and a
			 * key that is not ours means this plugin does absolutely
			 * nothing: it returns, and the request carries on to
			 * whichever plugin the key does belong to.
			 *
			 * Nothing above this line may refuse, redirect, exit, count
			 * a rate-limit hit or write a log entry, because none of
			 * that would be about this plugin. Every gate below is
			 * reached only once the key has matched, so the address
			 * rules and the rate limit apply to our own traffic alone.
			 *
			 * hash_equals() is used rather than === so the comparison
			 * takes the same time whatever the key is; it is safe with
			 * strings of different lengths, returning false.
			 */
			if ( '' === $key || ! hash_equals( $key, $given ) ) {
				return;
			}

			$ours = true;
			$ip   = self::client_ip();

			// Gate 1. Past here the key was ours, so this really is a
			// caller for this plugin. One not on the list gets the same
			// 404 an unknown URL would give, so the address does not
			// advertise itself.
			if ( ! self::ip_allowed( $ip, (string) WPCodeBBV_Settings::get( 'panel_ip_rules' ) ) ) {
				self::record_issue( 'panel refused: address ' . ( '' !== $ip ? $ip : 'unknown' ) . ' is not allowed' );
				$this->not_found();
			}

			// Gate 2. Only our own callers are counted.
			$rate = self::rate_check( $ip );

			if ( ! $rate['ok'] ) {
				self::record_issue( 'panel rate limit hit by ' . $ip );
				status_header( 429 );
				nocache_headers();
				header( 'Retry-After: ' . (int) $rate['window'] );
				header( 'X-WPCodeBBV-Result: RATE_LIMITED' );
				header( 'Content-Type: text/plain; charset=utf-8' );
				echo "RESULT: RATE_LIMITED\nToo many requests.\n";
				exit;
			}

			$this->serve();
		} catch ( \Throwable $e ) {
			if ( function_exists( 'wpcodebbv_log' ) ) {
				wpcodebbv_log( 'panel failed: ' . $e->getMessage() );
			}

			/*
			 * If it broke before the key matched, this request was
			 * never ours - it may well belong to another plugin
			 * answering on the same parameter. Answering it with a 500
			 * would take that plugin's request away from it, so step
			 * aside instead and let the page load as it would have.
			 */
			if ( ! $ours ) {
				return;
			}

			// Never leave a half-rendered page behind.
			if ( ! headers_sent() ) {
				status_header( 500 );
				header( 'X-WPCodeBBV-Result: ERROR' );
				header( 'Content-Type: text/plain; charset=utf-8' );
			}

			// text/plain: escaping here would turn the one message
			// somebody needs to read into HTML entities.
			echo "RESULT: ERROR\n" . $e->getMessage() . "\nThe site is unaffected.\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			exit;
		}
	}

	private function not_found() {
		status_header( 404 );
		nocache_headers();
		header( 'Content-Type: text/plain; charset=utf-8' );
		echo "Not found.\n";
		exit;
	}

	/* -----------------------------------------------------------------
	 * Actions
	 * -------------------------------------------------------------- */

	/**
	 * Every action the panel offers: the name a form posts, and the
	 * sentence next to its button.
	 *
	 * @return array<string, string>
	 */
	public static function actions() {
		return array(
			'save'           => 'Save the settings above',
			'update'         => 'Check for a newer version and install it',
			'reinstall'      => 'Re-download and re-install the current latest version over this one',
			'flush'          => 'Forget the cached update check',
			'rescan'         => 'Re-read every snippet',
			'reset_sitewide' => 'Clear every stored site-wide value',
			'resume'         => 'Leave safe mode',
			'clear_log'      => 'Empty the problem log',
		);
	}

	/**
	 * Runs one action. Everything here is already past all four gates.
	 *
	 * @param string $action
	 * @return array{0:bool, 1:string} Whether it worked, and what to say.
	 */
	private function run_action( $action ) {
		switch ( $action ) {
			case 'update':
				if ( ! class_exists( 'WPCodeBBV_Updater' ) ) {
					return array( false, 'The updater is not available on this install.' );
				}

				if ( ! headers_sent() ) {
					header( 'Content-Type: text/plain; charset=utf-8' );
				}

				/*
				 * The updater prints its own report and then exits, so
				 * there is no return value to turn into a RESULT line -
				 * and printing one first would have to guess, which is
				 * worse than not printing it at all.
				 *
				 * An output-buffer callback reads what the updater
				 * actually said and puts the right line in front of it.
				 * PHP runs the callback when the buffer is flushed,
				 * which happens at shutdown, so the updater's exit()
				 * does not skip it.
				 */
				ob_start(
					function ( $printed ) {
						$ok = false !== strpos( $printed, 'SUCCESS' )
							|| false !== strpos( $printed, 'Already up to date' );

						$verdict = $ok ? 'OK' : 'FAIL';

						if ( ! headers_sent() ) {
							header( 'X-WPCodeBBV-Result: ' . $verdict );
						}

						return 'RESULT: ' . $verdict . "\n" . $printed;
					}
				);

				/*
				 * Anything thrown in here is caught on the spot rather
				 * than left to the handler in maybe_handle(). That
				 * handler would print a verdict of its own, and the
				 * buffer above is already going to print one - a
				 * response carrying two RESULT lines is worse than
				 * either of them alone.
				 */
				try {
					$updater = new WPCodeBBV_Updater();
					$updater->force_update_now();
				} catch ( \Throwable $e ) {
					if ( function_exists( 'wpcodebbv_log' ) ) {
						wpcodebbv_log( 'update from the panel failed: ' . $e->getMessage() );
					}

					echo "\nThe update could not be finished: " . $e->getMessage() . "\n";
					echo "FAILED\n";
				}

				// Only reachable if force_update_now() stops exiting;
				// the buffer is ours to close in that case.
				ob_end_flush();
				exit;

			case 'reinstall':
				if ( ! function_exists( 'wpcodebbv_emergency_reinstall' ) ) {
					return array( false, 'Reinstalling is not available on this install.' );
				}

				$said = wpcodebbv_emergency_reinstall( WPCodeBBV_Settings::all() );

				return array( 0 === strpos( $said, 'reinstalled' ), $said );

			case 'flush':
				if ( class_exists( 'WPCodeBBV_Updater' ) ) {
					WPCodeBBV_Updater::flush_cache();
				}

				return array( true, 'The cached update check was cleared.' );

			case 'rescan':
				if ( defined( 'WPCODEBBV_CACHE' ) ) {
					delete_transient( WPCODEBBV_CACHE );
				}

				$count = function_exists( 'wpcodebbv_snippets' ) ? count( wpcodebbv_snippets( true ) ) : 0;

				return array( true, 'Re-read ' . $count . ' snippet(s).' );

			case 'reset_sitewide':
				if ( ! defined( 'WPCODEBBV_OPTION' ) ) {
					return array( false, 'Nothing to reset on this install.' );
				}

				delete_option( WPCODEBBV_OPTION );

				return array( true, 'Every stored site-wide value was cleared.' );

			case 'resume':
				if ( defined( 'WPCODEBBV_SAFE_MODE_OPT' ) ) {
					delete_option( WPCODEBBV_SAFE_MODE_OPT );
				}

				return array( true, 'Safe mode was cleared. Reload to see the plugin running again.' );

			case 'clear_log':
				delete_option( self::LOG_OPT );

				return array( true, 'The problem log was emptied.' );
		}

		return array( false, 'Unknown action.' );
	}

	/**
	 * Past the gates: show the panel, and act on anything posted.
	 */
	private function serve() {
		nocache_headers();
		header( 'X-Robots-Tag: noindex, nofollow', true );

		$notices = array();
		$ok      = true;
		$action  = isset( $_POST['wpcodebbv_action'] ) ? sanitize_key( wp_unslash( $_POST['wpcodebbv_action'] ) ) : '';

		if ( '' !== $action ) {
			$password = isset( $_POST['wpcodebbv_password'] ) ? (string) wp_unslash( $_POST['wpcodebbv_password'] ) : '';

			if ( ! self::password_ok( $password ) ) {
				self::record_issue( 'panel: wrong password from ' . self::client_ip() );
				$ok        = false;
				$notices[] = 'That password is not right. Nothing was changed.';
			} elseif ( 'save' === $action ) {
				list( $ok, $said ) = $this->save_settings();
				$notices[]         = $said;
			} else {
				list( $ok, $said ) = $this->run_action( $action );
				$notices[]         = $said;
			}
		}

		$this->render( $notices, $action, $ok );
		exit;
	}

	/**
	 * The save action, kept apart because it is the only one that is
	 * rate limited to once a day and the only one that reads a whole
	 * array of input.
	 *
	 * @return array{0:bool, 1:string}
	 */
	private function save_settings() {
		$edit = self::edit_allowed();

		if ( ! $edit['ok'] ) {
			return array(
				false,
				'Settings can only be changed once a day from here. Next change allowed at '
					. gmdate( 'Y-m-d H:i', $edit['next'] ) . ' UTC.',
			);
		}

		$posted = isset( $_POST['wpcodebbv_settings'] ) && is_array( $_POST['wpcodebbv_settings'] ) // phpcs:ignore WordPress.Security.NonceVerification.Missing
			? wp_unslash( $_POST['wpcodebbv_settings'] ) // phpcs:ignore WordPress.Security.NonceVerification.Missing
			: array();

		foreach ( self::$read_only as $key ) {
			unset( $posted[ $key ] );
		}

		// A checkbox that is off sends nothing at all, so an absent flag
		// has to be read as zero - but only when the form was the one
		// carrying flags. A script posting a single key should not have
		// the others silently turned off, so this only fires when the
		// form's own marker came with it.
		if ( isset( $_POST['wpcodebbv_full_form'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			foreach ( array( 'update_enabled', 'update_auto' ) as $flag ) {
				if ( ! isset( $posted[ $flag ] ) ) {
					$posted[ $flag ] = 0;
				}
			}
		}

		if ( empty( $posted ) ) {
			return array( false, 'Nothing was posted to save.' );
		}

		WPCodeBBV_Settings::save( $posted );
		update_option( self::EDIT_OPT, time(), false );

		return array( true, 'Saved ' . count( $posted ) . ' setting(s). The next change from here is allowed in a day.' );
	}

	/* -----------------------------------------------------------------
	 * Output
	 * -------------------------------------------------------------- */

	/**
	 * The extra links configured in wp-admin, as label => url.
	 *
	 * @return array<string, string>
	 */
	public static function links() {
		$out = array();

		foreach ( preg_split( '/[\r\n]+/', (string) WPCodeBBV_Settings::get( 'panel_links' ) ) as $line ) {
			$line = trim( $line );

			if ( '' === $line ) {
				continue;
			}

			if ( false !== strpos( $line, '|' ) ) {
				list( $label, $url ) = array_map( 'trim', explode( '|', $line, 2 ) );
			} else {
				$label = $line;
				$url   = $line;
			}

			$url = esc_url_raw( $url );

			if ( '' === $url || '' === $label ) {
				continue;
			}

			$out[ $label ] = $url;
		}

		return $out;
	}

	/**
	 * How a given setting should be shown: 'flag', 'choice', 'lines' or
	 * 'line'. Derived from the key rather than a hand-kept list, so a
	 * new setting turns up here on its own.
	 *
	 * @param string $key
	 * @return array{type:string, choices:array}
	 */
	private static function field_shape( $key ) {
		$choices = array(
			'update_source' => array( 'url', 'github' ),
			'update_role'   => array( 'standalone', 'dev', 'production' ),
		);

		if ( isset( $choices[ $key ] ) ) {
			return array( 'type' => 'choice', 'choices' => $choices[ $key ] );
		}

		if ( in_array( $key, array( 'update_enabled', 'update_auto' ), true ) ) {
			return array( 'type' => 'flag', 'choices' => array() );
		}

		if ( in_array( $key, array( 'panel_ip_rules', 'panel_links' ), true ) ) {
			return array( 'type' => 'lines', 'choices' => array() );
		}

		return array( 'type' => 'line', 'choices' => array() );
	}

	/**
	 * The whole report as plain text. Used for `&view=raw`, and printed
	 * at the top of the page so a scraper can read one block instead of
	 * walking the markup.
	 *
	 * @return string
	 */
	public static function report() {
		$lines = array();

		$lines[] = 'WPCODEBBV PANEL';
		$lines[] = 'site: ' . get_bloginfo( 'name' );
		$lines[] = 'home: ' . home_url( '/' );
		$lines[] = 'you: ' . self::client_ip();
		$lines[] = 'time: ' . gmdate( 'Y-m-d H:i:s' ) . ' UTC';
		$lines[] = '';

		foreach ( self::diagnostics() as $section => $rows ) {
			$lines[] = '[' . strtoupper( $section ) . ']';

			foreach ( $rows as $label => $value ) {
				$lines[] = $label . ': ' . $value;
			}

			$lines[] = '';
		}

		$lines[] = '[SETTINGS]';

		foreach ( WPCodeBBV_Settings::all() as $key => $value ) {
			if ( 'panel_password_hash' === $key ) {
				$lines[] = $key . ': ' . ( '' !== (string) $value ? '(set)' : '(not set)' );
				continue;
			}

			if ( 'gh_token' === $key ) {
				$lines[] = $key . ': ' . ( '' !== (string) $value ? '(set)' : '(not set)' );
				continue;
			}

			$lines[] = $key . ': ' . str_replace( array( "\r\n", "\n" ), ' | ', (string) $value );
		}

		$lines[] = '';
		$lines[] = '[PROBLEMS]';

		$issues = get_option( self::LOG_OPT, array() );
		$issues = is_array( $issues ) ? array_reverse( $issues ) : array();

		if ( empty( $issues ) ) {
			$lines[] = 'none';
		} else {
			foreach ( $issues as $issue ) {
				$lines[] = gmdate( 'Y-m-d H:i', (int) $issue['time'] ) . 'Z ' . $issue['msg'];
			}
		}

		$links = self::links();

		if ( $links ) {
			$lines[] = '';
			$lines[] = '[LINKS]';

			foreach ( $links as $label => $url ) {
				$lines[] = $label . ': ' . $url;
			}
		}

		return implode( "\n", $lines ) . "\n";
	}

	/**
	 * @param string[] $notices
	 * @param string   $action  What was just run, if anything.
	 * @param bool     $ok      Whether it worked.
	 */
	private function render( $notices, $action = '', $ok = true ) {
		$raw = isset( $_GET['view'] ) && 'raw' === $_GET['view']; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		$result = '' === $action ? 'READ' : ( $ok ? 'OK' : 'FAIL' );

		/*
		 * The verdict goes in a header as well as in the body. The body
		 * carries it as a first line only in the raw view; in the HTML
		 * view it is a paragraph like everything else, and a script that
		 * had been told to read "the first line" would find markup and
		 * read every success as a failure. A header is true of both.
		 */
		if ( ! headers_sent() ) {
			header( 'X-WPCodeBBV-Result: ' . $result );
		}

		if ( $raw ) {
			header( 'Content-Type: text/plain; charset=utf-8' );

			// Deliberately not escaped: this is text/plain, where HTML
			// entities would be shown literally and a scraper would have
			// to undo them. Nothing here can be interpreted as markup.
			echo 'RESULT: ' . $result . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

			foreach ( $notices as $notice ) {
				echo 'NOTICE: ' . str_replace( array( "\r", "\n" ), ' ', $notice ) . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}

			echo "\n" . self::report(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

			return;
		}

		$settings = WPCodeBBV_Settings::all();
		$edit     = self::edit_allowed();
		$here     = esc_url( add_query_arg( self::QUERY_VAR, rawurlencode( self::key() ), home_url( '/' ) ) );

		header( 'Content-Type: text/html; charset=utf-8' );

		// No stylesheet, no script, no images. Everything below is text
		// and plain form controls on purpose: this page has to stay
		// readable and postable by a script that knows nothing about it.
		?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="robots" content="noindex,nofollow">
<title>WPCODEBBV PANEL</title>
</head>
<body>
<p>RESULT: <?php echo esc_html( $result ); ?></p>
<?php foreach ( $notices as $notice ) : ?>
<p>NOTICE: <?php echo esc_html( $notice ); ?></p>
<?php endforeach; ?>

<hr>
<pre><?php echo esc_html( self::report() ); ?></pre>
<hr>

<h2>Settings</h2>
<?php if ( ! $edit['ok'] ) : ?>
<p>Settings were changed from here recently. The next change is allowed at
	<?php echo esc_html( gmdate( 'Y-m-d H:i', $edit['next'] ) ); ?> UTC.</p>
<?php endif; ?>
<form method="post" action="<?php echo $here; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>">
<input type="hidden" name="wpcodebbv_action" value="save">
<input type="hidden" name="wpcodebbv_full_form" value="1">
<table>
<?php foreach ( $settings as $key => $value ) : ?>
	<?php
	if ( in_array( $key, self::$read_only, true ) ) {
		continue;
	}

	$shape = self::field_shape( $key );
	$name  = 'wpcodebbv_settings[' . $key . ']';
	?>
<tr>
	<td><label for="f_<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $key ); ?></label></td>
	<td>
	<?php if ( 'choice' === $shape['type'] ) : ?>
		<select id="f_<?php echo esc_attr( $key ); ?>" name="<?php echo esc_attr( $name ); ?>">
		<?php foreach ( $shape['choices'] as $choice ) : ?>
			<option value="<?php echo esc_attr( $choice ); ?>" <?php selected( (string) $value, $choice ); ?>><?php echo esc_html( $choice ); ?></option>
		<?php endforeach; ?>
		</select>
	<?php elseif ( 'flag' === $shape['type'] ) : ?>
		<select id="f_<?php echo esc_attr( $key ); ?>" name="<?php echo esc_attr( $name ); ?>">
			<option value="1" <?php selected( (int) $value, 1 ); ?>>1</option>
			<option value="0" <?php selected( (int) $value, 0 ); ?>>0</option>
		</select>
	<?php elseif ( 'lines' === $shape['type'] ) : ?>
		<textarea id="f_<?php echo esc_attr( $key ); ?>" name="<?php echo esc_attr( $name ); ?>" rows="6" cols="60"><?php echo esc_textarea( (string) $value ); ?></textarea>
	<?php else : ?>
		<input type="text" size="60" id="f_<?php echo esc_attr( $key ); ?>" name="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( (string) $value ); ?>">
	<?php endif; ?>
	</td>
</tr>
<?php endforeach; ?>
<tr>
	<td><label for="f_pw_save">password</label></td>
	<td><input type="password" id="f_pw_save" name="wpcodebbv_password" size="40" autocomplete="off"></td>
</tr>
</table>
<p><button type="submit" name="submit" value="save">Save settings</button></p>
</form>

<hr>
<h2>Actions</h2>
<?php foreach ( self::actions() as $name => $label ) : ?>
	<?php if ( 'save' === $name ) { continue; } ?>
<form method="post" action="<?php echo $here; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>">
<input type="hidden" name="wpcodebbv_action" value="<?php echo esc_attr( $name ); ?>">
<p>
	<label for="f_pw_<?php echo esc_attr( $name ); ?>"><?php echo esc_html( $label ); ?> - password</label>
	<input type="password" id="f_pw_<?php echo esc_attr( $name ); ?>" name="wpcodebbv_password" size="40" autocomplete="off">
	<button type="submit" name="submit" value="<?php echo esc_attr( $name ); ?>"><?php echo esc_html( $name ); ?></button>
</p>
</form>
<?php endforeach; ?>

<?php $links = self::links(); ?>
<?php if ( $links ) : ?>
<hr>
<h2>Links</h2>
<ul>
<?php foreach ( $links as $label => $url ) : ?>
	<li><a href="<?php echo esc_url( $url ); ?>"><?php echo esc_html( $label ); ?></a></li>
<?php endforeach; ?>
</ul>
<?php endif; ?>

<hr>
<h2>For scripts</h2>
<pre>GET  <?php echo esc_html( $here ); ?>&amp;view=raw     text/plain, no forms
POST <?php echo esc_html( $here ); ?>
     wpcodebbv_action     one of: <?php echo esc_html( implode( ', ', array_keys( self::actions() ) ) ); ?>

     wpcodebbv_password   the password set in wp-admin
     wpcodebbv_settings[KEY]=VALUE  with wpcodebbv_action=save
     wpcodebbv_full_form=1          also zeroes any flag you leave out

The verdict is one of OK, FAIL, READ, RATE_LIMITED or ERROR. Every
response carries it in the X-WPCodeBBV-Result header; a &amp;view=raw
response also carries it as its first line, "RESULT: &lt;verdict&gt;".
Post to the &amp;view=raw address to get both.</pre>
</body>
</html>
		<?php
	}
}
