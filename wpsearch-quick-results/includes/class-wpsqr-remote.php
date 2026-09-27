<?php
/**
 * The hidden remote endpoint.
 *
 * A page reachable without a WordPress login, at a secret URL, that reports
 * the plugin's health and — for someone who also has the password — lets a
 * few settings be changed from outside wp-admin. It exists for the case where
 * wp-admin itself is the problem: a bad update, a locked-out login, a site you
 * need to check on from a script.
 *
 * Because there is no login in front of it, it is gated four ways, every one
 * of which must pass:
 *
 *   1. The secret key in the URL. Wrong or missing → the request is not even
 *      acknowledged as this endpoint; it 404s like any other bad URL.
 *   2. The visitor's IP, against the allow/deny rules. Default: one address.
 *   3. A rate limit, so the key and password cannot be brute-forced.
 *   4. The password, for anything that changes a setting (reading is allowed
 *      to any request that clears the first three).
 *
 * And editing is capped at once per day, so even a fully authenticated
 * mistake cannot be made in a loop.
 *
 * Nothing anywhere else in the plugin links to or mentions this. It is reached
 * only by someone who already knows the URL.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WPSQR_Remote {

	/**
	 * The query var that marks a request as aimed at this endpoint. Its value
	 * is the secret key, so the URL reads ?acpsupdater=<key>.
	 */
	const VAR = 'acpsupdater';

	/** The name used before this was renamed — still accepted, so old bookmarks and scripts keep working. */
	const LEGACY_VAR = 'wpsqr_rc';

	const RATE_OPTION   = 'wpsqr_rc_rate';
	const EDIT_OPTION   = 'wpsqr_rc_last_edit';
	const RATE_WINDOW   = 300;  // seconds
	const RATE_MAX      = 20;   // requests per window per IP
	const EDIT_COOLDOWN   = 86400; // one settings edit per day
	const UPDATE_COOLDOWN = 120;   // seconds between update attempts

	public function hooks() {
		// Handled on init at the lowest possible priority — the earliest point
		// where the pluggable functions this needs (redirect, password) are
		// defined. Serving and exiting here means the request never reaches
		// `wp`, `template_redirect`, `send_headers`, `wp_footer` or the theme,
		// so a page-view tracker hooked to any of those never sees it. The
		// stealth step below covers the shutdown hook as well, and because the
		// page renders none of the theme's scripts, JS-pixel trackers never
		// fire either.
		add_action( 'init', array( $this, 'maybe_handle' ), -PHP_INT_MAX );
	}

	/* ---- Configuration that lives only in the DB ----------------------- */

	/**
	 * Make sure the endpoint has a key and, if never set, a random one.
	 *
	 * Called on activation and re-checked after every update, so an update
	 * that somehow cleared the key cannot leave the endpoint unreachable —
	 * a new key is generated rather than the route silently dying.
	 *
	 * @return bool True if it had to repair something.
	 */
	public static function ensure_configured() {
		$repaired = false;

		if ( '' === (string) get_option( 'wpsqr_rc_key', '' ) ) {
			update_option( 'wpsqr_rc_key', wp_generate_password( 40, false, false ), false );
			$repaired = true;
		}

		return $repaired;
	}

	public static function key() {
		return (string) get_option( 'wpsqr_rc_key', '' );
	}

	/** Regenerate a random key. Any URL already handed out stops working. */
	public static function rotate_key() {
		$key = wp_generate_password( 40, false, false );
		update_option( 'wpsqr_rc_key', $key, false );
		return $key;
	}

	/**
	 * Set the key to one you chose.
	 *
	 * Kept to URL-safe characters and a length that is not trivially
	 * guessable — the whole URL is the secret, so a two-letter key would be a
	 * two-letter password on an unauthenticated page.
	 *
	 * @return true|string True on success, an error message otherwise.
	 */
	public static function set_key( $key ) {
		$key = trim( (string) $key );

		if ( strlen( $key ) < 12 ) {
			return __( 'The key must be at least 12 characters — it is the only thing guarding the page.', 'wpsqr' );
		}

		if ( ! preg_match( '/^[A-Za-z0-9._~-]+$/', $key ) ) {
			return __( 'The key may use only letters, numbers, and . _ ~ - (so it survives being put in a URL).', 'wpsqr' );
		}

		update_option( 'wpsqr_rc_key', $key, false );

		return true;
	}

	public static function set_password( $plain ) {
		$plain = (string) $plain;

		if ( '' === $plain ) {
			delete_option( 'wpsqr_rc_pwhash' );
			return;
		}

		update_option( 'wpsqr_rc_pwhash', wp_hash_password( $plain ), false );
	}

	public static function has_password() {
		return '' !== (string) get_option( 'wpsqr_rc_pwhash', '' );
	}

	protected static function check_password( $plain ) {
		$hash = (string) get_option( 'wpsqr_rc_pwhash', '' );

		if ( '' === $hash ) {
			return false; // no password set → editing is closed, not open
		}

		return wp_check_password( (string) $plain, $hash );
	}

	/** The full endpoint URL, for display in the hidden admin panel only. */
	public static function url() {
		return add_query_arg( self::VAR, self::key(), home_url( '/' ) );
	}

	protected static function rules() {
		$text = (string) WPSQR_Plugin::settings()['rc_ip_rules'];

		return WPSQR_NetGate::parse_rules( $text );
	}

	/* ---- The request --------------------------------------------------- */

	public function maybe_handle( $unused = null ) {
		$key = self::key();

		// No key configured, or the URL does not carry it: this is not our
		// request. Return silently — the endpoint gives nothing away, not even
		// its own existence, to a request without the key.
		if ( '' === $key ) {
			return;
		}

		$given = self::given_key();

		if ( '' === $given || ! hash_equals( $key, $given ) ) {
			return;
		}

		// From here the request has proven it knows the key. Everything below
		// still has to pass, but the endpoint will now answer rather than 404.
		// First, make it invisible to anything that logs page views.
		$this->go_stealth();

		$ip = WPSQR_NetGate::client_ip( (bool) WPSQR_Plugin::settings()['rc_trust_proxy'] );

		// A blocked address is sent quietly to the homepage rather than shown
		// a refusal — no signal that anything is here at this URL, which is
		// the point of a hidden endpoint.
		if ( ! WPSQR_NetGate::allows( $ip, self::rules() ) ) {
			wp_safe_redirect( home_url( '/' ) );
			exit;
		}

		if ( ! $this->rate_ok( $ip ) ) {
			$this->deny( 429, 'Too many requests. Wait a few minutes.' );
		}

		// The whole handler runs inside a guard: this page is meant to be the
		// tool of last resort, so if its own code throws — a missing class after
		// a bad edit, say — it does not white-screen. It tries a failsafe
		// reinstall of the latest release from the source and then reports, in
		// plain text, rather than dying. A deny()/redirect throws no exception
		// (they exit), so those still behave normally.
		try {
			$action = isset( $_REQUEST['do'] ) ? sanitize_key( $_REQUEST['do'] ) : 'status'; // phpcs:ignore WordPress.Security.NonceVerification

			if ( 'save' === $action ) {
				$this->handle_save();
			} elseif ( 'check' === $action ) {
				$this->handle_check();
			} elseif ( 'update' === $action ) {
				$this->handle_update();
			} elseif ( 'reinstall' === $action ) {
				$this->handle_reinstall();
			}

			$this->render_status();
		} catch ( \Throwable $e ) {
			$this->failsafe( $e );
		}
	}

	/** The key the request presented, under the current or legacy var name. */
	protected static function given_key() {
		foreach ( array( self::VAR, self::LEGACY_VAR ) as $var ) {
			if ( isset( $_GET[ $var ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
				return (string) wp_unslash( $_GET[ $var ] );
			}
		}

		return '';
	}

	/**
	 * The handler threw. This page has to survive that, so: attempt a failsafe
	 * reinstall of the latest release (the updater rate-limits itself), then
	 * print a plain-text report. No styling, no theme, nothing that could
	 * itself fail — just text a person or a script can read.
	 */
	protected function failsafe( $e ) {
		if ( ! headers_sent() ) {
			status_header( 200 );
			header( 'Content-Type: text/plain; charset=utf-8' );
			header( 'X-Robots-Tag: noindex, nofollow' );
		}

		$repair = 'not attempted';

		if ( class_exists( 'WPSQR_Updater' ) ) {
			try {
				$repair = ( new WPSQR_Updater() )->failsafe_repair();
			} catch ( \Throwable $inner ) {
				$repair = 'failsafe error: ' . $inner->getMessage();
			}
		}

		echo "WPSearch Quick Results — status page recovered from an error.\n";
		echo 'error: ' . $e->getMessage() . "\n";
		echo 'failsafe: ' . $repair . "\n";
		echo "reload this URL to try the status page again.\n";
		exit;
	}

	/**
	 * Make this request invisible to page-view logging.
	 *
	 * Other plugins were counting a visit to the status URL because the old
	 * handler ran late, on parse_request. This one runs on early init and
	 * exits before the hooks most trackers use, but shutdown fires even on
	 * exit — so any logger hooked there is unhooked. The request is
	 * terminating with its own output, so WordPress's own shutdown work does
	 * not matter. The crash guard is registered with
	 * register_shutdown_function, not the shutdown action, so it is untouched.
	 *
	 * Filter wpsqr_remote_stealth to false to turn this off.
	 */
	protected function go_stealth() {
		nocache_headers();
		header( 'X-Robots-Tag: noindex, nofollow', true );

		if ( ! apply_filters( 'wpsqr_remote_stealth', true ) ) {
			return;
		}

		remove_all_actions( 'shutdown' );
	}

	/**
	 * Rate limit per IP, sliding-ish window.
	 *
	 * Counts requests in the current window and refuses past the cap. Cheap
	 * and good enough: the key and IP gate already make this a narrow door,
	 * and the limit is only there so that door cannot be pounded on.
	 */
	protected function rate_ok( $ip ) {
		$all = get_option( self::RATE_OPTION, array() );

		if ( ! is_array( $all ) ) {
			$all = array();
		}

		$now    = time();
		$bucket = isset( $all[ $ip ] ) && is_array( $all[ $ip ] ) ? $all[ $ip ] : array( 'start' => $now, 'count' => 0 );

		if ( $now - (int) $bucket['start'] > self::RATE_WINDOW ) {
			$bucket = array( 'start' => $now, 'count' => 0 );
		}

		$bucket['count']++;

		// Forget stale IPs so this option cannot grow without bound.
		foreach ( $all as $addr => $data ) {
			if ( ! is_array( $data ) || $now - (int) $data['start'] > self::RATE_WINDOW ) {
				unset( $all[ $addr ] );
			}
		}

		$all[ $ip ] = $bucket;
		update_option( self::RATE_OPTION, $all, false );

		return $bucket['count'] <= self::RATE_MAX;
	}

	protected function deny( $code, $message ) {
		status_header( $code );
		header( 'Content-Type: text/plain; charset=utf-8' );
		echo esc_html( $message ) . "\n";
		exit;
	}

	/* ---- Editing settings, once a day, with the password ---------------- */

	protected function handle_save() {
		$password = isset( $_POST['pw'] ) ? (string) wp_unslash( $_POST['pw'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification

		if ( ! self::has_password() ) {
			$this->deny( 403, 'Editing is disabled until a password is set in wp-admin.' );
		}

		if ( ! self::check_password( $password ) ) {
			$this->deny( 403, 'Wrong password.' );
		}

		$last = (int) get_option( self::EDIT_OPTION, 0 );

		if ( $last && ( time() - $last ) < self::EDIT_COOLDOWN ) {
			$wait = human_time_diff( time(), $last + self::EDIT_COOLDOWN );
			$this->deny( 429, "Settings can be changed once a day here. Try again in {$wait}." );
		}

		$in      = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification
		$changed = array();

		// Every plugin setting, by the shared metadata. The form sends the
		// list of keys it carried so unchecked boxes register as off.
		$present = isset( $in['_fields'] ) ? array_filter( array_map( 'sanitize_key', explode( ',', (string) $in['_fields'] ) ) ) : array();

		if ( $present ) {
			$changed = WPSQR_Plugin::apply_input( $in, $present );
		}

		// The remote access rules themselves — editable here, as asked, but
		// only once the password is in. An empty box would lock everyone out,
		// so it is ignored rather than saved.
		if ( isset( $in['rc_ip_rules'] ) ) {
			$rules = trim( (string) $in['rc_ip_rules'] );

			if ( '' !== $rules ) {
				$settings = WPSQR_Plugin::settings();

				if ( $settings['rc_ip_rules'] !== $in['rc_ip_rules'] ) {
					$settings['rc_ip_rules'] = sanitize_textarea_field( $in['rc_ip_rules'] );
					WPSQR_Plugin::update( $settings );
					$changed[] = 'rc_ip_rules';
				}
			}
		}

		// The endpoint key. Changing it here changes the URL you are on, so
		// the response says so and links to the new one.
		if ( ! empty( $in['new_key'] ) ) {
			$result = self::set_key( (string) $in['new_key'] );

			if ( true === $result ) {
				$changed[]        = 'endpoint key';
				$this->key_changed = self::key();
			} else {
				$this->flash_error = $result;
			}
		}

		// Clear safe mode, the reason this page exists in a crisis.
		if ( ! empty( $in['resume'] ) && class_exists( 'WPSQR_Guard' ) ) {
			WPSQR_Guard::leave_safe_mode();
			$changed[] = 'resumed';
		}

		if ( $changed ) {
			update_option( self::EDIT_OPTION, time(), false );
		}

		$this->flash = $changed
			? 'Saved: ' . implode( ', ', $changed )
			: ( $this->flash_error ? '' : 'Nothing changed.' );
	}

	protected $flash_error = '';
	protected $key_changed = '';

	protected $flash = '';

	/* ---- Update, from the status page ---------------------------------- */

	protected $update_info = null;

	/** Re-check the source. No password: looking is not changing anything. */
	protected function handle_check() {
		if ( ! class_exists( 'WPSQR_Updater' ) ) {
			return;
		}

		$this->update_info = ( new WPSQR_Updater() )->check();

		$this->flash = $this->update_info['newer']
			? 'Update available: ' . $this->update_info['available'] . ' (you have ' . $this->update_info['current'] . ').'
			: 'Up to date (' . $this->update_info['current'] . ').';
	}

	/**
	 * Install the update. Password required — this writes files.
	 *
	 * Its own short cooldown, separate from the once-a-day settings limit:
	 * updating and changing a setting are different acts, and you might
	 * legitimately retry an update that failed on a filesystem hiccup.
	 */
	protected function handle_update() {
		$password = isset( $_POST['pw'] ) ? (string) wp_unslash( $_POST['pw'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification

		if ( ! self::has_password() ) {
			$this->deny( 403, 'Updating from here is disabled until a password is set in wp-admin.' );
		}

		if ( ! self::check_password( $password ) ) {
			$this->deny( 403, 'Wrong password.' );
		}

		$last = (int) get_option( 'wpsqr_rc_last_update', 0 );

		if ( $last && ( time() - $last ) < self::UPDATE_COOLDOWN ) {
			$this->deny( 429, 'An update was just attempted. Wait a minute or two before trying again.' );
		}

		update_option( 'wpsqr_rc_last_update', time(), false );

		if ( ! class_exists( 'WPSQR_Updater' ) ) {
			$this->flash_error = 'The updater did not load.';
			return;
		}

		$result = ( new WPSQR_Updater() )->install_now();

		if ( $result['ok'] ) {
			$this->flash = $result['message'];
		} else {
			$this->flash_error = $result['message'];
		}
	}

	/**
	 * Re-download and overwrite with a clean copy of the current release, even
	 * if the version has not changed. Password-gated and on the update
	 * cooldown, like handle_update — this writes files too.
	 */
	protected function handle_reinstall() {
		$password = isset( $_POST['pw'] ) ? (string) wp_unslash( $_POST['pw'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification

		if ( ! self::has_password() ) {
			$this->deny( 403, 'Reinstalling from here is disabled until a password is set in wp-admin.' );
		}

		if ( ! self::check_password( $password ) ) {
			$this->deny( 403, 'Wrong password.' );
		}

		$last = (int) get_option( 'wpsqr_rc_last_update', 0 );

		if ( $last && ( time() - $last ) < self::UPDATE_COOLDOWN ) {
			$this->deny( 429, 'An update was just attempted. Wait a minute or two before trying again.' );
		}

		update_option( 'wpsqr_rc_last_update', time(), false );

		if ( ! class_exists( 'WPSQR_Updater' ) ) {
			$this->flash_error = 'The updater did not load.';
			return;
		}

		$result = ( new WPSQR_Updater() )->reinstall_now();

		if ( $result['ok'] ) {
			$this->flash = $result['message'];
		} else {
			$this->flash_error = $result['message'];
		}
	}

	/* ---- Output: health, performance, problems -------------------------- */

	/**
	 * The page itself. Deliberately plain: no CSS, no scripts, no theme — just
	 * headings, tables and forms with stable field names, so a person reads it
	 * as text and a Python script (requests, mechanicalsoup) can parse and post
	 * to it without fighting a layout. Every form field name here is part of
	 * the contract and should not change casually.
	 */
	protected function render_status() {
		status_header( 200 );
		header( 'Content-Type: text/html; charset=utf-8' );
		header( 'X-Robots-Tag: noindex, nofollow' );

		$report = $this->report();

		?><!doctype html>
<html lang="en">
<meta charset="utf-8">
<meta name="robots" content="noindex,nofollow">
<title>WPSearch Quick Results status</title>
<h1>WPSearch Quick Results &mdash; status</h1>

		<?php if ( $this->flash ) : ?>
			<p id="wpsqr-flash">OK: <?php echo esc_html( $this->flash ); ?></p>
		<?php endif; ?>
		<?php if ( $this->flash_error ) : ?>
			<p id="wpsqr-error">ERROR: <?php echo esc_html( $this->flash_error ); ?></p>
		<?php endif; ?>
		<?php if ( $this->key_changed ) : ?>
			<p id="wpsqr-newurl">New URL: <?php echo esc_html( add_query_arg( self::VAR, $this->key_changed, home_url( '/' ) ) ); ?></p>
		<?php endif; ?>

		<?php foreach ( $report as $section => $rows ) : ?>
			<h2><?php echo esc_html( $section ); ?></h2>
			<table border="1" cellpadding="4">
				<?php foreach ( $rows as $label => $cell ) : ?>
					<tr>
						<th align="left"><?php echo esc_html( $label ); ?></th>
						<td data-state="<?php echo esc_attr( $cell['state'] ); ?>"><?php echo esc_html( $cell['value'] ); ?></td>
					</tr>
				<?php endforeach; ?>
			</table>
		<?php endforeach; ?>

		<?php $this->render_links(); ?>
		<?php $this->render_update_controls(); ?>
		<?php $this->render_edit_form(); ?>
</html>
		<?php
		exit;
	}

	/**
	 * Extra links configured in the hidden updates tab (rc_links). One per
	 * line, "Label | https://…" or a bare URL. Only http(s) links are shown,
	 * so a bad line cannot inject a javascript: URL onto the page.
	 */
	protected function render_links() {
		$lines = (array) WPSQR_Plugin::settings()['rc_links'];
		$links = array();

		foreach ( $lines as $line ) {
			$line = trim( (string) $line );

			if ( '' === $line ) {
				continue;
			}

			if ( false !== strpos( $line, '|' ) ) {
				list( $label, $url ) = array_map( 'trim', explode( '|', $line, 2 ) );
			} else {
				$label = $line;
				$url   = $line;
			}

			$url = esc_url_raw( $url, array( 'http', 'https' ) );

			if ( '' === $url ) {
				continue;
			}

			$links[] = array( 'label' => '' !== $label ? $label : $url, 'url' => $url );
		}

		if ( ! $links ) {
			return;
		}

		echo '<h2>Links</h2><ul>';
		foreach ( $links as $link ) {
			printf(
				'<li><a href="%s">%s</a></li>',
				esc_url( $link['url'] ),
				esc_html( $link['label'] )
			);
		}
		echo '</ul>';
	}

	protected function report() {
		$out = array();

		// --- health ---
		$safe    = class_exists( 'WPSQR_Guard' ) && WPSQR_Guard::is_safe_mode();
		$health  = get_option( 'wpsqr_last_health', array() );
		$missing = is_array( $health ) && ! empty( $health['missing'] ) ? $health['missing'] : array();
		$upcheck = get_option( 'wpsqr_last_update_check', array() );

		$out['Health'] = array(
			'Plugin version' => $this->row( WPSQR_VERSION ),
			'Safe mode'      => $safe ? $this->row( 'ON — plugin paused after a crash', 'bad' ) : $this->row( 'off', 'ok' ),
			'Missing files'  => $missing ? $this->row( implode( ', ', $missing ), 'bad' ) : $this->row( 'none', 'ok' ),
			'Last update'    => $this->row(
				empty( $upcheck['version'] ) ? 'no record' : $upcheck['version'] . ' — ' . ( empty( $upcheck['problems'] ) ? 'clean' : implode( '; ', $upcheck['problems'] ) ),
				empty( $upcheck['problems'] ) ? 'ok' : 'warn'
			),
		);

		// --- engine & index ---
		if ( class_exists( 'WPSQR_Plugin' ) && class_exists( 'WPSQR_Index' ) ) {
			$index = WPSQR_Index::stats();

			$out['Search'] = array(
				'Engine'     => $this->row( WPSQR_Plugin::engine() ),
				'Index rows' => $this->row( $index['rows'] . ' of ' . $index['expected'], $index['rows'] >= $index['expected'] ? 'ok' : 'warn' ),
				'Full-text'  => $index['fulltext'] ? $this->row( 'yes', 'ok' ) : $this->row( 'no (using LIKE)', 'warn' ),
			);
		}

		// --- performance / usage ---
		if ( class_exists( 'WPSQR_Stats' ) && class_exists( 'WPSQR_Cache' ) ) {
			$summary = WPSQR_Stats::summary();
			$cache   = WPSQR_Cache::stats();

			$out['Performance'] = array(
				'Searches recorded'  => $this->row( $summary['searches'] ),
				'Cache hit rate'     => $this->row( $summary['rendered'] > 0 ? $summary['hit_rate'] . '%' : 'n/a' ),
				'Avg uncached'       => $this->row( $summary['avg_uncached'] . 'ms', $summary['avg_uncached'] > 1500 ? 'warn' : 'ok' ),
				'Cached entries'     => $this->row( $cache['entries'] ),
			);
		}

		// --- cron, the thing most likely to be quietly broken ---
		$next_warm = wp_next_scheduled( 'wpsqr_warm_cache' );
		$cron_off  = defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON;

		$out['Background'] = array(
			'WP-Cron'    => $cron_off ? $this->row( 'DISABLE_WP_CRON is set', 'warn' ) : $this->row( 'enabled', 'ok' ),
			'Next warm'  => $this->row( $next_warm ? human_time_diff( time(), $next_warm ) : 'not scheduled', $next_warm ? 'ok' : 'warn' ),
			'This request' => $this->row( apply_filters( 'wpsqr_remote_stealth', true ) ? 'hidden from page-view logging' : 'visible to trackers (stealth off)', 'ok' ),
		);

		// --- updates (cached manifest unless a check was just run) ---
		if ( class_exists( 'WPSQR_Updater' ) ) {
			$info = $this->update_info;

			if ( null === $info ) {
				$remote = ( new WPSQR_Updater() )->remote();
				$info   = array(
					'current'   => WPSQR_VERSION,
					'available' => $remote ? (string) $remote['version'] : '',
					'newer'     => $remote ? version_compare( $remote['version'], WPSQR_VERSION, '>' ) : false,
				);
			}

			$out['Updates'] = array(
				'Installed'  => $this->row( $info['current'] ),
				'Available'  => $this->row( '' === $info['available'] ? 'unknown (no source, or not checked)' : $info['available'], $info['newer'] ? 'warn' : 'ok' ),
			);

			$rollback = get_option( 'wpsqr_last_rollback', array() );
			if ( ! empty( $rollback['reverted_to'] ) ) {
				$out['Updates']['Last rollback'] = $this->row(
					'reverted to ' . $rollback['reverted_to'] . ' — a newer version crashed and was undone',
					'warn'
				);
			}
		}

		return $out;
	}

	protected function row( $value, $state = '' ) {
		return array( 'value' => (string) $value, 'state' => $state );
	}

	protected function render_update_controls() {
		if ( ! class_exists( 'WPSQR_Updater' ) ) {
			return;
		}

		$key = esc_attr( self::key() );
		?>
		<h2>Update</h2>
		<form method="get">
			<input type="hidden" name="<?php echo esc_attr( self::VAR ); ?>" value="<?php echo $key; ?>">
			<input type="hidden" name="do" value="check">
			<button type="submit" name="submit_check" value="1">Check the source now</button>
		</form>

		<?php if ( self::has_password() ) : ?>
			<form method="post">
				<input type="hidden" name="<?php echo esc_attr( self::VAR ); ?>" value="<?php echo $key; ?>">
				<input type="hidden" name="do" value="update">
				<p><label>Password: <input type="password" name="pw" autocomplete="off" required></label></p>
				<button type="submit" name="submit_update" value="1">Install update now</button>
			</form>

			<h2>Reinstall</h2>
			<p>Re-download and overwrite with a clean copy of the current release, even if the version is unchanged &mdash; use this if a file was edited wrongly on the server.</p>
			<form method="post">
				<input type="hidden" name="<?php echo esc_attr( self::VAR ); ?>" value="<?php echo $key; ?>">
				<input type="hidden" name="do" value="reinstall">
				<p><label>Password: <input type="password" name="pw" autocomplete="off" required></label></p>
				<button type="submit" name="submit_reinstall" value="1">Reinstall current version</button>
			</form>
		<?php else : ?>
			<p>Set a password in wp-admin to allow installing from here.</p>
		<?php endif; ?>
		<?php
	}

	protected function render_edit_form() {
		$last     = (int) get_option( self::EDIT_OPTION, 0 );
		$cooling  = $last && ( time() - $last ) < self::EDIT_COOLDOWN;
		$settings = WPSQR_Plugin::settings();
		$meta     = WPSQR_Plugin::meta();

		echo '<h2>Change settings</h2>';

		if ( ! self::has_password() ) {
			echo '<p>Editing is off until a password is set in wp-admin (the settings page with ?updates=1).</p>';
			return;
		}

		if ( $cooling ) {
			echo '<p>Already changed today. Next change available in ' . esc_html( human_time_diff( time(), $last + self::EDIT_COOLDOWN ) ) . '.</p>';
			return;
		}

		echo '<form method="post">';
		echo '<input type="hidden" name="' . esc_attr( self::VAR ) . '" value="' . esc_attr( self::key() ) . '">';
		echo '<input type="hidden" name="do" value="save">';
		echo '<input type="hidden" name="_fields" value="' . esc_attr( implode( ',', array_keys( $meta ) ) ) . '">';

		echo '<p><label>Password (required): <input type="password" name="pw" autocomplete="off" required></label></p>';

		// Group the fields the way the metadata groups them, so a long form is
		// still navigable.
		$groups = array();
		foreach ( $meta as $key => $spec ) {
			$groups[ $spec['group'] ][ $key ] = $spec;
		}

		foreach ( $groups as $group => $fields ) {
			echo '<h3>' . esc_html( $group ) . '</h3>';

			foreach ( $fields as $key => $spec ) {
				$this->render_field( $key, $spec, $settings[ $key ] );
			}
		}

		// Access rules and the key sit with the settings, gated by the same
		// password.
		echo '<h3>Remote access</h3>';
		echo '<p><label>Allowed addresses (one rule per line)<br><textarea name="rc_ip_rules" rows="4" cols="60">' . esc_textarea( $settings['rc_ip_rules'] ) . '</textarea></label></p>';
		echo '<p><label>Change this page\'s key (12+ chars; letters, numbers, . _ ~ -)<br><input type="text" name="new_key" autocomplete="off" placeholder="leave blank to keep" size="50"></label></p>';

		echo '<p><label><input type="checkbox" name="resume" value="1"> Clear safe mode (resume the plugin)</label></p>';

		echo '<p><button type="submit" name="submit_save" value="1">Save (once per day)</button></p></form>';
	}

	protected function render_field( $key, $spec, $value ) {
		$name  = esc_attr( $key );
		$label = esc_html( $key );

		switch ( $spec['type'] ) {
			case 'bool':
				printf(
					'<p><label><input type="checkbox" name="%s" value="1" %s> %s</label></p>',
					$name,
					checked( (int) $value, 1, false ),
					$label
				);
				break;

			case 'enum':
				echo '<p><label>' . $label . ' <select name="' . $name . '">';
				foreach ( $spec['values'] as $option ) {
					printf( '<option value="%1$s" %2$s>%1$s</option>', esc_attr( $option ), selected( (string) $value, $option, false ) );
				}
				echo '</select></label></p>';
				break;

			case 'int':
				printf( '<p><label>%s <input type="number" name="%s" value="%s"></label></p>', $label, $name, esc_attr( (string) (int) $value ) );
				break;

			case 'lines':
				printf( '<p><label>%s (one per line)<br><textarea name="%s" rows="3" cols="60">%s</textarea></label></p>', $label, $name, esc_textarea( implode( "\n", (array) $value ) ) );
				break;

			case 'json':
				printf( '<p><label>%s (JSON)<br><textarea name="%s" rows="3" cols="60">%s</textarea></label></p>', $label, $name, esc_textarea( wp_json_encode( $value ) ) );
				break;

			case 'url':
			default:
				printf( '<p><label>%s<br><input type="text" name="%s" value="%s" size="50"></label></p>', $label, $name, esc_attr( (string) $value ) );
				break;
		}
	}
}
