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

	/** The query var that marks a request as aimed at this endpoint. */
	const VAR = 'wpsqr_rc';

	const RATE_OPTION   = 'wpsqr_rc_rate';
	const EDIT_OPTION   = 'wpsqr_rc_last_edit';
	const RATE_WINDOW   = 300;  // seconds
	const RATE_MAX      = 20;   // requests per window per IP
	const EDIT_COOLDOWN = 86400; // one edit per day

	public function hooks() {
		// parse_request runs before the theme, so this can answer and exit
		// without WordPress rendering a page around it.
		add_action( 'parse_request', array( $this, 'maybe_handle' ) );
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

	/** Regenerate the key. Any URL already handed out stops working. */
	public static function rotate_key() {
		$key = wp_generate_password( 40, false, false );
		update_option( 'wpsqr_rc_key', $key, false );
		return $key;
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

	public function maybe_handle( $wp ) {
		$key = self::key();

		// No key configured, or the URL does not carry it: this is not our
		// request. Return silently — the endpoint gives nothing away, not even
		// its own existence, to a request without the key.
		if ( '' === $key ) {
			return;
		}

		$given = isset( $_GET[ self::VAR ] ) ? (string) wp_unslash( $_GET[ self::VAR ] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification

		if ( '' === $given || ! hash_equals( $key, $given ) ) {
			return;
		}

		// From here the request has proven it knows the key. Everything below
		// still has to pass, but the endpoint will now answer rather than 404.
		nocache_headers();

		$ip = WPSQR_NetGate::client_ip( (bool) WPSQR_Plugin::settings()['rc_trust_proxy'] );

		if ( ! WPSQR_NetGate::allows( $ip, self::rules() ) ) {
			$this->deny( 403, 'Address not permitted.' );
		}

		if ( ! $this->rate_ok( $ip ) ) {
			$this->deny( 429, 'Too many requests. Wait a few minutes.' );
		}

		$action = isset( $_REQUEST['do'] ) ? sanitize_key( $_REQUEST['do'] ) : 'status'; // phpcs:ignore WordPress.Security.NonceVerification

		if ( 'save' === $action ) {
			$this->handle_save();
		}

		$this->render_status();
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

		// A deliberately small, safe subset — the levers you would want from
		// outside if the site were misbehaving, and nothing that could be
		// turned into a foothold.
		$settings = WPSQR_Plugin::settings();
		$changed  = array();

		$editable = array(
			'engine_mode'    => array( 'auto', 'builtin', 'searchwp', 'core' ),
			'native_search'  => 'bool',
			'age_mode'       => array( 'off', 'demote', 'hide' ),
			'directory_mode' => array( 'smart', 'strict', 'always', 'never' ),
			'warm_enabled'   => 'bool',
		);

		foreach ( $editable as $field => $allowed ) {
			if ( ! isset( $_POST[ $field ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
				continue;
			}

			$value = sanitize_text_field( wp_unslash( $_POST[ $field ] ) ); // phpcs:ignore WordPress.Security.NonceVerification

			if ( 'bool' === $allowed ) {
				$value = ( '1' === $value || 'on' === $value || 'true' === $value ) ? 1 : 0;
			} elseif ( ! in_array( $value, $allowed, true ) ) {
				continue;
			}

			if ( $settings[ $field ] !== $value ) {
				$settings[ $field ] = $value;
				$changed[]          = $field;
			}
		}

		// One special action, gated the same way: clear safe mode remotely,
		// which is the whole reason someone would reach for this in a crisis.
		if ( ! empty( $_POST['resume'] ) && class_exists( 'WPSQR_Guard' ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			WPSQR_Guard::leave_safe_mode();
			$changed[] = 'resumed';
		}

		if ( $changed ) {
			WPSQR_Plugin::update( $settings );
			update_option( self::EDIT_OPTION, time(), false );
		}

		$this->flash = $changed
			? 'Saved: ' . implode( ', ', $changed )
			: 'Nothing changed.';
	}

	protected $flash = '';

	/* ---- Output: health, performance, problems -------------------------- */

	protected function render_status() {
		status_header( 200 );
		header( 'Content-Type: text/html; charset=utf-8' );
		header( 'X-Robots-Tag: noindex, nofollow' );

		$report = $this->report();

		?><!doctype html>
<meta name="robots" content="noindex,nofollow">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Status</title>
<style>
	body { font: 14px/1.5 system-ui, sans-serif; max-width: 46rem; margin: 2rem auto; padding: 0 1rem; color: #1d2327; }
	h1 { font-size: 1.2rem; } h2 { font-size: 1rem; margin-top: 1.5rem; }
	table { border-collapse: collapse; width: 100%; } td, th { text-align: left; padding: .3rem .5rem; border-bottom: 1px solid #eee; }
	.ok { color: #007017; } .bad { color: #b32d2e; font-weight: 600; } .warn { color: #8a6d00; }
	code { background: #f0f0f1; padding: .1em .4em; border-radius: 3px; }
	form { margin-top: 2rem; padding-top: 1rem; border-top: 1px solid #ddd; }
	label { display: block; margin: .5rem 0; }
	.flash { background: #e5f5e9; border: 1px solid #a3d9b1; padding: .5rem .75rem; border-radius: 4px; }
</style>
<h1>WPSearch Quick Results — status</h1>

		<?php if ( $this->flash ) : ?>
			<p class="flash"><?php echo esc_html( $this->flash ); ?></p>
		<?php endif; ?>

		<?php foreach ( $report as $section => $rows ) : ?>
			<h2><?php echo esc_html( $section ); ?></h2>
			<table>
				<?php foreach ( $rows as $label => $cell ) : ?>
					<tr>
						<th><?php echo esc_html( $label ); ?></th>
						<td class="<?php echo esc_attr( $cell['state'] ); ?>"><?php echo esc_html( $cell['value'] ); ?></td>
					</tr>
				<?php endforeach; ?>
			</table>
		<?php endforeach; ?>

		<?php $this->render_edit_form(); ?>
		<?php
		exit;
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
		);

		return $out;
	}

	protected function row( $value, $state = '' ) {
		return array( 'value' => (string) $value, 'state' => $state );
	}

	protected function render_edit_form() {
		$last     = (int) get_option( self::EDIT_OPTION, 0 );
		$cooling  = $last && ( time() - $last ) < self::EDIT_COOLDOWN;
		$settings = WPSQR_Plugin::settings();
		?>
		<form method="post">
			<h2>Change a setting</h2>

			<?php if ( ! self::has_password() ) : ?>
				<p class="warn">Editing is off until a password is set in wp-admin (Settings &rarr; the hidden updates panel).</p>
			<?php elseif ( $cooling ) : ?>
				<p class="warn">Already changed today. Next change available in <?php echo esc_html( human_time_diff( time(), $last + self::EDIT_COOLDOWN ) ); ?>.</p>
			<?php else : ?>
				<input type="hidden" name="<?php echo esc_attr( self::VAR ); ?>" value="<?php echo esc_attr( self::key() ); ?>">
				<input type="hidden" name="do" value="save">

				<label>Password<br><input type="password" name="pw" autocomplete="off" required></label>

				<label>Engine
					<select name="engine_mode">
						<?php foreach ( array( 'auto', 'builtin', 'searchwp', 'core' ) as $m ) : ?>
							<option value="<?php echo esc_attr( $m ); ?>" <?php selected( $settings['engine_mode'], $m ); ?>><?php echo esc_html( $m ); ?></option>
						<?php endforeach; ?>
					</select>
				</label>

				<label><input type="checkbox" name="native_search" value="1" <?php checked( $settings['native_search'], 1 ); ?>> Take over site search</label>
				<label><input type="checkbox" name="warm_enabled" value="1" <?php checked( $settings['warm_enabled'], 1 ); ?>> Cache warming</label>
				<label><input type="checkbox" name="resume" value="1"> Clear safe mode (resume the plugin)</label>

				<p><button type="submit">Save (once per day)</button></p>
			<?php endif; ?>
		</form>
		<?php
	}
}
