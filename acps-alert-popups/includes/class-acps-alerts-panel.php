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

	const QUERY_VAR    = 'acpsupdater';
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
		// Guarded like every other hook: the console runs on a public request,
		// so nothing it does may be able to fatal the page.
		ACPS_Alerts_Failsafe::action( 'init', array( $this, 'maybe_handle' ), 'panel/handle', 3 );
	}

	/**
	 * The key that reaches the console: acpsupdater=<key>.
	 *
	 * The operator sets it in wp-admin (console_key). It falls back to the
	 * update secret when unset, so an upgrade does not lock anyone out before
	 * they pick their own key.
	 *
	 * @return string
	 */
	public static function access_key() {
		$key = trim( (string) ACPS_Alerts_Settings::get( 'console_key' ) );

		return '' !== $key ? $key : trim( (string) ACPS_Alerts_Settings::get( 'update_secret' ) );
	}

	/**
	 * The stored safe-mode state, or null when the plugin is not paused.
	 *
	 * @return array|null
	 */
	public static function safe_mode_state() {
		$option = defined( 'ACPS_ALERTS_SAFE_MODE_OPT' ) ? ACPS_ALERTS_SAFE_MODE_OPT : 'acps_alerts_safe_mode';
		$state  = get_option( $option );

		return ( is_array( $state ) && ! empty( $state['time'] ) ) ? $state : null;
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

		$secret = self::access_key();
		$given  = sanitize_text_field( wp_unslash( $_GET[ self::QUERY_VAR ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		// A wrong or absent key is indistinguishable from any other URL: 404,
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

		if ( 'post' === $action ) {
			$this->handle_post( $ip );

			return;
		}

		if ( 'wording' === $action ) {
			$this->handle_wording( $ip );

			return;
		}

		if ( 'update' === $action ) {
			$this->handle_update();

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

		if ( 'resume' === $action ) {
			// Lifts safe mode — the console's equivalent of reactivating the
			// plugin in wp-admin. Not throttled: it changes no setting, and it
			// widens nothing about this console's own access. If the cause is
			// not fixed, the next fatal simply pauses the plugin again.
			$option = defined( 'ACPS_ALERTS_SAFE_MODE_OPT' ) ? ACPS_ALERTS_SAFE_MODE_OPT : 'acps_alerts_safe_mode';

			delete_option( $option );
			ACPS_Alerts_Failsafe::reset_breakers();

			if ( $this->updater ) {
				$this->updater->record_health( 'ok', 'Resumed from safe mode via the remote console (' . $ip . ').' );
			}

			$this->render_console( __( 'Resumed. The plugin runs again from the next request.', 'acps-alert-popups' ), 'ok' );

			return;
		}

		$view = isset( $_GET['acps_console_view'] ) ? sanitize_key( wp_unslash( $_GET['acps_console_view'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( 'post' === $view ) {
			$this->render_post_form();

			return;
		}

		if ( 'wording' === $view ) {
			$this->render_wording_form();

			return;
		}

		$this->render_console();
	}

	/**
	 * A console URL carrying the access key, plus any extra query args.
	 *
	 * @param array $args Extra query args.
	 * @return string
	 */
	protected function console_url( array $args = array() ) {
		return add_query_arg( array_merge( array( self::QUERY_VAR => self::access_key() ), $args ), home_url( '/' ) );
	}

	/**
	 * The navigation line shown at the top of every authenticated view.
	 *
	 * Plain links, so a script can follow them.
	 *
	 * @return void
	 */
	protected function nav() {
		$links = array(
			$this->console_url()                                  => __( 'Dashboard & settings', 'acps-alert-popups' ),
			$this->console_url( array( 'acps_console_view' => 'post' ) )    => __( 'Post an alert', 'acps-alert-popups' ),
			$this->console_url( array( 'acps_console_view' => 'wording' ) ) => __( 'Wording', 'acps-alert-popups' ),
		);

		echo '<p>';

		$parts = array();

		foreach ( $links as $url => $label ) {
			$parts[] = '<a href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a>';
		}

		// Custom links added in wp-admin.
		foreach ( $this->custom_links() as $link ) {
			$parts[] = '<a href="' . esc_url( $link['url'] ) . '">' . esc_html( $link['label'] ) . '</a>';
		}

		echo implode( ' | ', $parts );
		echo '</p>';

		// Update now, as a form so it is a deliberate POST.
		echo '<form method="post" action="' . esc_url( $this->console_url() ) . '" style="display:inline">';
		echo '<input type="hidden" name="acps_console_action" value="update" />';
		echo '<button type="submit">' . esc_html__( 'Check & install update now', 'acps-alert-popups' ) . '</button>';
		echo '</form>';
	}

	/**
	 * The operator's custom console links, parsed from settings.
	 *
	 * @return array[] Each { label, url }.
	 */
	protected function custom_links() {
		$raw   = (string) ACPS_Alerts_Settings::get( 'console_links' );
		$out   = array();

		foreach ( preg_split( '/[\r\n]+/', $raw ) as $line ) {
			$line = trim( (string) $line );

			if ( '' === $line ) {
				continue;
			}

			$parts = array_map( 'trim', explode( '|', $line, 2 ) );

			if ( isset( $parts[1] ) && '' !== $parts[0] && '' !== $parts[1] ) {
				$out[] = array( 'label' => $parts[0], 'url' => $parts[1] );
			}
		}

		return $out;
	}

	/**
	 * Posts an alert from the console, reusing the admin quick-post logic.
	 *
	 * @param string $ip Client address, for the health note.
	 * @return void
	 */
	protected function handle_post( $ip ) {
		if ( ! class_exists( 'ACPS_Alerts_Admin' ) || ! class_exists( 'ACPS_Alerts_Status' ) ) {
			$this->render_console( __( 'The alert system is not available.', 'acps-alert-popups' ), 'error' );

			return;
		}

		$alert = ACPS_Alerts_Status::current_alert();

		if ( ! $alert ) {
			$this->render_console( __( 'There is no Current Alert to post to.', 'acps-alert-popups' ), 'error' );

			return;
		}

		$raw     = isset( $_POST['acps_post'] ) ? (array) wp_unslash( $_POST['acps_post'] ) : array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$level   = isset( $raw['level'] ) ? sanitize_key( $raw['level'] ) : '';
		$heading = isset( $raw['heading'] ) ? sanitize_text_field( $raw['heading'] ) : '';
		$text    = isset( $raw['text'] ) ? wp_kses_post( $raw['text'] ) : '';
		$start   = isset( $raw['start'] ) ? (string) $raw['start'] : '';
		$end     = isset( $raw['end'] ) ? (string) $raw['end'] : '';

		if ( '' === $level || ! in_array( $level, ACPS_Alerts_Status::level_keys(), true ) ) {
			$level = (string) $alert->get( 'status_level' );
		}

		ACPS_Alerts_Failsafe::guard(
			array( 'ACPS_Alerts_Admin', 'apply_quick_post' ),
			array( $alert, $level, $heading, $text, $start, $end ),
			'console/post'
		);

		if ( $this->updater ) {
			$this->updater->record_health( 'ok', 'Alert posted from the console (' . $ip . ').' );
		}

		$this->render_console( __( 'Alert posted. It is live now.', 'acps-alert-popups' ), 'ok' );
	}

	/**
	 * Saves the site's wording from the console, reusing the admin logic.
	 *
	 * @param string $ip Client address, for the health note.
	 * @return void
	 */
	protected function handle_wording( $ip ) {
		if ( ! class_exists( 'ACPS_Alerts_Admin' ) ) {
			$this->render_console( __( 'The alert system is not available.', 'acps-alert-popups' ), 'error' );

			return;
		}

		ACPS_Alerts_Failsafe::guard( array( 'ACPS_Alerts_Admin', 'apply_wording' ), array(), 'console/wording' );

		if ( $this->updater ) {
			$this->updater->record_health( 'ok', 'Wording changed from the console (' . $ip . ').' );
		}

		$this->render_console( __( 'Wording saved.', 'acps-alert-popups' ), 'ok' );
	}

	/**
	 * Runs an update from the console and prints the plain-text log.
	 *
	 * @return void
	 */
	protected function handle_update() {
		$log = $this->updater ? $this->updater->install_now() : "No updater available.\n";

		$this->page_head( __( 'Update', 'acps-alert-popups' ) );
		$this->nav();
		echo '<h2>' . esc_html__( 'Update', 'acps-alert-popups' ) . '</h2>';
		echo '<pre>' . esc_html( $log ) . '</pre>';
		$this->page_foot();
	}

	/**
	 * The console's post-an-alert form.
	 *
	 * @return void
	 */
	protected function render_post_form() {
		$this->page_head( __( 'Post an alert', 'acps-alert-popups' ) );
		$this->nav();

		$alert   = class_exists( 'ACPS_Alerts_Status' ) ? ACPS_Alerts_Status::current_alert() : null;
		$level   = $alert ? (string) $alert->get( 'status_level' ) : 'normal';
		$choices = class_exists( 'ACPS_Alerts_Status' ) ? ACPS_Alerts_Status::level_choices() : array();

		echo '<h2>' . esc_html__( 'Post an alert', 'acps-alert-popups' ) . '</h2>';
		echo '<form method="post" action="' . esc_url( $this->console_url() ) . '">';
		echo '<input type="hidden" name="acps_console_action" value="post" />';

		echo '<p><label>' . esc_html__( 'Level', 'acps-alert-popups' ) . '<br /><select name="acps_post[level]">';
		foreach ( $choices as $key => $label ) {
			echo '<option value="' . esc_attr( $key ) . '" ' . selected( $level, $key, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select></label></p>';

		echo '<p><label>' . esc_html__( 'Header', 'acps-alert-popups' ) . '<br /><input type="text" name="acps_post[heading]" size="60" /></label></p>';
		echo '<p><label>' . esc_html__( 'Text', 'acps-alert-popups' ) . '<br /><textarea name="acps_post[text]" rows="5" cols="60"></textarea></label></p>';
		echo '<p><label>' . esc_html__( 'Starts (YYYY-MM-DDTHH:MM, optional)', 'acps-alert-popups' ) . '<br /><input type="text" name="acps_post[start]" size="24" /></label></p>';
		echo '<p><label>' . esc_html__( 'Ends (optional)', 'acps-alert-popups' ) . '<br /><input type="text" name="acps_post[end]" size="24" /></label></p>';
		echo '<p><button type="submit">' . esc_html__( 'Post alert', 'acps-alert-popups' ) . '</button></p>';
		echo '</form>';

		$this->page_foot();
	}

	/**
	 * The console's wording form (resting message + level words).
	 *
	 * @return void
	 */
	protected function render_wording_form() {
		$this->page_head( __( 'Wording', 'acps-alert-popups' ) );
		$this->nav();

		$normal = class_exists( 'ACPS_Alerts_Status' ) ? ACPS_Alerts_Status::normal_alert() : null;
		$rest_h = $normal ? (string) $normal->get_title() : '';
		$rest_m = $normal ? (string) $normal->get( 'status_message' ) : '';

		echo '<h2>' . esc_html__( 'Wording', 'acps-alert-popups' ) . '</h2>';
		echo '<form method="post" action="' . esc_url( $this->console_url() ) . '">';
		echo '<input type="hidden" name="acps_console_action" value="wording" />';
		echo '<h3>' . esc_html__( 'When nothing is happening', 'acps-alert-popups' ) . '</h3>';
		echo '<p><label>' . esc_html__( 'Heading', 'acps-alert-popups' ) . '<br /><input type="text" name="acps_rest[heading]" size="60" value="' . esc_attr( $rest_h ) . '" /></label></p>';
		echo '<p><label>' . esc_html__( 'Message', 'acps-alert-popups' ) . '<br /><textarea name="acps_rest[message]" rows="4" cols="60">' . esc_textarea( $rest_m ) . '</textarea></label></p>';

		echo '<h3>' . esc_html__( 'Level words', 'acps-alert-popups' ) . '</h3>';
		if ( class_exists( 'ACPS_Alerts_Status' ) ) {
			foreach ( ACPS_Alerts_Status::levels() as $key => $level ) {
				if ( ! empty( $level['legacy'] ) ) {
					continue;
				}

				echo '<p>' . esc_html( $level['label'] ) . ': ';
				echo '<input type="text" name="acps_words[' . esc_attr( $key ) . '][banner]" value="' . esc_attr( $level['banner'] ) . '" /> ';
				echo '<input type="text" name="acps_words[' . esc_attr( $key ) . '][directive]" value="' . esc_attr( wp_strip_all_tags( $level['directive'] ) ) . '" size="40" />';
				echo '</p>';
			}
		}

		echo '<p><button type="submit">' . esc_html__( 'Save wording', 'acps-alert-popups' ) . '</button></p>';
		echo '</form>';

		$this->page_foot();
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
			// A rule beginning with ! is an explicit BLOCK that always wins,
			// whatever the mode. This is what lets an allow list carry
			// exceptions ("allow 196.168, but never 196.168.5.5").
			if ( '!' === substr( $rule, 0, 1 ) ) {
				if ( self::ip_matches( $ip, ltrim( substr( $rule, 1 ) ) ) ) {
					return false;
				}

				continue;
			}

			if ( self::ip_matches( $ip, $rule ) ) {
				$matched = true;
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

		// Where updates come from, and the staged-rollout role. Credentials (the
		// GitHub token and the rollout status key) stay wp-admin only: this page
		// prints its values in plain text, so it must never hold a credential.
		if ( isset( $raw['update_source'] ) ) {
			$source               = sanitize_key( (string) $raw['update_source'] );
			$out['update_source'] = in_array( $source, array( 'manifest', 'github' ), true ) ? $source : $defaults['update_source'];
		}

		if ( isset( $raw['update_role'] ) ) {
			$role               = sanitize_key( (string) $raw['update_role'] );
			$out['update_role'] = in_array( $role, array( 'standalone', 'dev', 'production' ), true ) ? $role : $defaults['update_role'];
		}

		if ( isset( $raw['gh_owner'] ) ) {
			$out['gh_owner'] = sanitize_text_field( (string) $raw['gh_owner'] );
		}

		if ( isset( $raw['gh_repo'] ) ) {
			$out['gh_repo'] = sanitize_text_field( (string) $raw['gh_repo'] );
		}

		if ( isset( $raw['gh_asset'] ) ) {
			$out['gh_asset'] = sanitize_file_name( (string) $raw['gh_asset'] );
		}

		if ( isset( $raw['verify_status_url'] ) ) {
			$out['verify_status_url'] = esc_url_raw( trim( (string) $raw['verify_status_url'] ) );
		}

		// Feature switches. An unticked box is simply not posted, so the
		// switches are only read when the form says it carries them — a script
		// posting a few other fields must not switch every feature off.
		if ( ! empty( $raw['_features'] ) ) {
			foreach ( array_keys( ACPS_Alerts_Settings::features() ) as $feature ) {
				$out[ 'feature_' . $feature ] = empty( $raw[ 'feature_' . $feature ] ) ? 0 : 1;
			}
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

		$safe = self::safe_mode_state();

		if ( $safe ) {
			$issues[] = array(
				'level'   => 'error',
				'message' => sprintf(
					/* translators: 1: time, 2: error message, 3: file:line. */
					__( 'The plugin is paused (safe mode) since %1$s after a fatal error: %2$s (%3$s). Installing a new version lifts the pause by itself; or press Resume below once the cause is fixed.', 'acps-alert-popups' ),
					gmdate( 'Y-m-d H:i', (int) $safe['time'] ) . ' UTC',
					isset( $safe['msg'] ) ? (string) $safe['msg'] : '',
					( isset( $safe['file'] ) ? str_replace( ABSPATH, '', (string) $safe['file'] ) : '' ) . ':' . ( isset( $safe['line'] ) ? (int) $safe['line'] : 0 )
				),
			);
		}

		// Features someone switched off in Settings → Features.
		$off = array();

		foreach ( ACPS_Alerts_Settings::features() as $key => $feature ) {
			if ( ! ACPS_Alerts_Settings::feature( $key ) ) {
				$off[] = $feature['label'];
			}
		}

		if ( $off ) {
			$issues[] = array(
				'level'   => 'warn',
				'message' => sprintf(
					/* translators: %s: feature names. */
					__( 'Switched off in Settings → Features: %s', 'acps-alert-popups' ),
					implode( ', ', $off )
				),
			);
		}

		// Everything the breakers have switched off after repeated failures.
		foreach ( ACPS_Alerts_Failsafe::tripped_breakers() as $context ) {
			$issues[] = array(
				'level'   => 'warn',
				'message' => sprintf(
					/* translators: %s: subsystem name. */
					__( 'Temporarily switched off after repeated failures: %s', 'acps-alert-popups' ),
					$context
				),
			);
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

		if ( ! ACPS_Alerts_Updater::source_configured() ) {
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
		$this->nav();

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

		if ( self::safe_mode_state() ) {
			echo '<form method="post"><input type="hidden" name="acps_console_action" value="resume" />'
				. '<button type="submit">' . esc_html__( 'Resume (leave safe mode)', 'acps-alert-popups' ) . '</button></form>';
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

		echo '<h3>' . esc_html__( 'Features', 'acps-alert-popups' ) . '</h3>';
		echo '<input type="hidden" name="acps_console[_features]" value="1" />';

		foreach ( ACPS_Alerts_Settings::features() as $key => $feature ) {
			echo '<label class="check"><input type="checkbox" name="acps_console[feature_' . esc_attr( $key ) . ']" value="1" ' . checked( ACPS_Alerts_Settings::feature( $key ), true, false ) . $disabled . ' /> ' . esc_html( $feature['label'] ) . ' — ' . esc_html( $feature['description'] ) . '</label>';
		}

		echo '<h3>' . esc_html__( 'General', 'acps-alert-popups' ) . '</h3>';

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

		echo '<label>' . esc_html__( 'z-index', 'acps-alert-popups' ) . '<br /><input type="number" min="1" name="acps_console[z_index]" value="' . esc_attr( ACPS_Alerts_Settings::get( 'z_index' ) ) . '"' . $disabled . ' /></label>';

		echo '<label>' . esc_html__( 'Popup post type (blank to auto-detect)', 'acps-alert-popups' ) . '<br /><input type="text" name="acps_console[popup_post_type]" value="' . esc_attr( ACPS_Alerts_Settings::get( 'popup_post_type' ) ) . '"' . $disabled . ' /></label>';

		echo '<label class="check"><input type="checkbox" name="acps_console[hide_for_admins]" value="1" ' . checked( 1, (int) ACPS_Alerts_Settings::get( 'hide_for_admins' ), false ) . $disabled . ' /> ' . esc_html__( 'Hide alerts from editors', 'acps-alert-popups' ) . '</label>';

		echo '<label class="check"><input type="checkbox" name="acps_console[respect_preview]" value="1" ' . checked( 1, (int) ACPS_Alerts_Settings::get( 'respect_preview' ), false ) . $disabled . ' /> ' . esc_html__( 'Allow editor preview links', 'acps-alert-popups' ) . '</label>';

		echo '<label>' . esc_html__( 'Main CSS', 'acps-alert-popups' ) . '<br /><textarea name="acps_console[custom_css]" rows="8"' . $disabled . '>' . esc_textarea( (string) ACPS_Alerts_Settings::get( 'custom_css' ) ) . '</textarea></label>';

		echo '<h3>' . esc_html__( 'Update source', 'acps-alert-popups' ) . '</h3>';

		echo '<label class="check"><input type="checkbox" name="acps_console[update_enabled]" value="1" ' . checked( 1, (int) ACPS_Alerts_Settings::get( 'update_enabled' ), false ) . $disabled . ' /> ' . esc_html__( 'Updates enabled', 'acps-alert-popups' ) . '</label>';
		echo '<label class="check"><input type="checkbox" name="acps_console[update_auto]" value="1" ' . checked( 1, (int) ACPS_Alerts_Settings::get( 'update_auto' ), false ) . $disabled . ' /> ' . esc_html__( 'Install updates automatically', 'acps-alert-popups' ) . '</label>';

		$this->select_field( 'update_source', __( 'Update source', 'acps-alert-popups' ), array(
			'manifest' => 'manifest URL',
			'github'   => 'GitHub Releases',
		), ACPS_Alerts_Settings::get( 'update_source' ), $disabled );

		$this->select_field( 'update_role', __( 'Rollout role', 'acps-alert-popups' ), array(
			'standalone' => 'standalone',
			'dev'        => 'dev (verifies first)',
			'production' => 'production (waits for dev)',
		), ACPS_Alerts_Settings::get( 'update_role' ), $disabled );

		echo '<label>' . esc_html__( 'Manifest base URL', 'acps-alert-popups' ) . '<br /><input type="url" name="acps_console[update_base]" value="' . esc_attr( ACPS_Alerts_Settings::get( 'update_base' ) ) . '"' . $disabled . ' /></label>';
		echo '<label>' . esc_html__( 'Plugin path', 'acps-alert-popups' ) . '<br /><input type="text" name="acps_console[update_path]" value="' . esc_attr( ACPS_Alerts_Settings::get( 'update_path' ) ) . '"' . $disabled . ' /></label>';
		echo '<label>' . esc_html__( 'Key', 'acps-alert-popups' ) . '<br /><input type="text" name="acps_console[update_key]" value="' . esc_attr( ACPS_Alerts_Settings::get( 'update_key' ) ) . '"' . $disabled . ' /></label>';
		echo '<label>' . esc_html__( 'GitHub owner', 'acps-alert-popups' ) . '<br /><input type="text" name="acps_console[gh_owner]" value="' . esc_attr( ACPS_Alerts_Settings::get( 'gh_owner' ) ) . '"' . $disabled . ' /></label>';
		echo '<label>' . esc_html__( 'GitHub repository', 'acps-alert-popups' ) . '<br /><input type="text" name="acps_console[gh_repo]" value="' . esc_attr( ACPS_Alerts_Settings::get( 'gh_repo' ) ) . '"' . $disabled . ' /></label>';
		echo '<label>' . esc_html__( 'GitHub release asset (zip name)', 'acps-alert-popups' ) . '<br /><input type="text" name="acps_console[gh_asset]" value="' . esc_attr( ACPS_Alerts_Settings::get( 'gh_asset' ) ) . '"' . $disabled . ' /></label>';
		echo '<label>' . esc_html__( 'Dev site status URL (production only)', 'acps-alert-popups' ) . '<br /><input type="url" name="acps_console[verify_status_url]" value="' . esc_attr( ACPS_Alerts_Settings::get( 'verify_status_url' ) ) . '"' . $disabled . ' /></label>';

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
		// No styling on purpose: this page is meant to be read as plain text and
		// driven by a script, so it stays simple and predictable.
		echo '<title>' . esc_html( $title ) . '</title></head><body><h1>' . esc_html__( 'ACPS Alert Popups — Console', 'acps-alert-popups' ) . '</h1>';
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
		wp_safe_redirect( add_query_arg( self::QUERY_VAR, self::access_key(), home_url( '/' ) ) );
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
