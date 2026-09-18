<?php
/**
 * The control panel served at the update URL.
 *
 * A front-end page, reachable without logging in, that reports on the
 * plugin and lets its settings be changed from outside wp-admin. That
 * is a lot of power on a public URL, so it is gated four ways and each
 * gate is checked before the next: the caller's IP, a rate limit, the
 * secret in the URL, and - for anything that writes - a password that
 * can only be set while logged into wp-admin.
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

	/** Query var that opens the panel - the same secret as the force update. */
	const QUERY_VAR = 'wpcodebbv_update';

	/** Rolling record of recent problems, for the panel to report. */
	const LOG_OPT = 'wpcodebbv_recent_issues';

	/** When settings were last changed from the panel. */
	const EDIT_OPT = 'wpcodebbv_panel_last_edit';

	/** Most recent issues kept. */
	const LOG_MAX = 25;

	public function register() {
		// Priority 0: before anything else gets a chance to fail, so the
		// panel still answers when the rest of the plugin is unhappy.
		add_action( 'init', array( $this, 'maybe_handle' ), 0 );
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
	 *   167.102.110.1   allow exactly this address
	 *   196.168.        allow anything starting with this
	 *   !203.0.113.7    never allow this address
	 *   !10.            never allow anything starting with this
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
	 * Exact address, or a prefix ("196.168." / "196.168.*").
	 *
	 * @param string $ip
	 * @param string $rule
	 * @return bool
	 */
	private static function ip_matches( $ip, $rule ) {
		$rule = rtrim( trim( $rule ), '*' );

		if ( '' === $rule ) {
			return false;
		}

		if ( '.' === substr( $rule, -1 ) || ':' === substr( $rule, -1 ) ) {
			return 0 === strpos( $ip, $rule );
		}

		return $ip === $rule;
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

		if ( '' === trim( (string) WPCodeBBV_Settings::get( 'update_trigger' ) ) ) {
			$missing[] = 'no secret';
		}

		if ( ! class_exists( 'WPCodeBBV_Updater' ) ) {
			$missing[] = 'updater not loaded';
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
			'Memory in use'  => size_format( memory_get_usage( true ) ),
			'Peak memory'    => size_format( memory_get_peak_usage( true ) ),
			'Memory limit'   => (string) ini_get( 'memory_limit' ),
			'This request'   => defined( 'WPCODEBBV_START' )
				? number_format( ( microtime( true ) - WPCODEBBV_START ) * 1000, 1 ) . ' ms'
				: 'n/a',
			'PHP'            => PHP_VERSION,
			'WordPress'      => get_bloginfo( 'version' ),
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

		$remote = class_exists( 'WPCodeBBV_Updater' ) ? WPCodeBBV_Updater::peek_status() : array( 'checked' => false, 'remote' => false );

		$out['Plugin'] = array(
			'Snippets read'   => (string) count( $snippets ),
			'Settings found'  => (string) $settings,
			'Update source'   => (string) WPCodeBBV_Settings::get( 'update_source' ),
			'Latest seen'     => ! empty( $remote['remote']['version'] ) ? (string) $remote['remote']['version'] : 'not checked yet',
		);

		return $out;
	}

	/* -----------------------------------------------------------------
	 * The request
	 * -------------------------------------------------------------- */

	public function maybe_handle() {
		try {
			if ( ! isset( $_GET[ self::QUERY_VAR ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				return;
			}

			$given  = sanitize_text_field( wp_unslash( $_GET[ self::QUERY_VAR ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$secret = trim( (string) WPCodeBBV_Settings::get( 'update_trigger' ) );
			$ip     = self::client_ip();

			// Gate 1. Anyone not on the list gets the same 404 an unknown
			// URL would give, so this address does not advertise itself.
			if ( ! self::ip_allowed( $ip, (string) WPCodeBBV_Settings::get( 'panel_ip_rules' ) ) ) {
				self::record_issue( 'panel refused: address ' . ( '' !== $ip ? $ip : 'unknown' ) . ' is not allowed' );
				$this->not_found();
			}

			// Gate 2.
			$rate = self::rate_check( $ip );

			if ( ! $rate['ok'] ) {
				self::record_issue( 'panel rate limit hit by ' . $ip );
				status_header( 429 );
				nocache_headers();
				header( 'Retry-After: ' . (int) $rate['window'] );
				header( 'Content-Type: text/plain; charset=utf-8' );
				echo "Too many requests.\n";
				exit;
			}

			// Gate 3. Timing-safe, and a wrong secret is a 404 as well.
			if ( '' === $secret || ! hash_equals( $secret, $given ) ) {
				self::record_issue( 'panel refused: wrong key from ' . $ip );
				$this->not_found();
			}

			$this->serve();
		} catch ( \Throwable $e ) {
			if ( function_exists( 'wpcodebbv_log' ) ) {
				wpcodebbv_log( 'panel failed: ' . $e->getMessage() );
			}

			// Never leave a half-rendered page behind.
			if ( ! headers_sent() ) {
				status_header( 500 );
				header( 'Content-Type: text/plain; charset=utf-8' );
			}

			echo "The control panel hit an error. The site is unaffected.\n";
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

	/**
	 * Past the gates: show the panel, and act on anything posted.
	 */
	private function serve() {
		nocache_headers();
		header( 'X-Robots-Tag: noindex, nofollow', true );

		$notices = array();
		$action  = isset( $_POST['wpcodebbv_action'] ) ? sanitize_key( wp_unslash( $_POST['wpcodebbv_action'] ) ) : '';

		if ( '' !== $action ) {
			$password = isset( $_POST['wpcodebbv_password'] ) ? (string) wp_unslash( $_POST['wpcodebbv_password'] ) : '';

			if ( ! self::password_ok( $password ) ) {
				self::record_issue( 'panel: wrong password from ' . self::client_ip() );
				$notices[] = array( 'bad', 'That password is not right. Nothing was changed.' );
			} elseif ( 'update' === $action ) {
				// Handing off to the updater, which prints its own output
				// and exits.
				if ( class_exists( 'WPCodeBBV_Updater' ) ) {
					$updater = new WPCodeBBV_Updater();
					$updater->force_update_now();
				}

				$notices[] = array( 'bad', 'The updater is not available on this install.' );
			} elseif ( 'save' === $action ) {
				$edit = self::edit_allowed();

				if ( ! $edit['ok'] ) {
					$notices[] = array(
						'bad',
						'Settings can only be changed once a day from here. Next change allowed at '
							. gmdate( 'Y-m-d H:i', $edit['next'] ) . ' UTC.',
					);
				} else {
					$posted = isset( $_POST['wpcodebbv_settings'] ) && is_array( $_POST['wpcodebbv_settings'] )
						? wp_unslash( $_POST['wpcodebbv_settings'] )
						: array();

					// The password and who may reach this page are set in
					// wp-admin only. Letting the panel change either would
					// make the panel able to hand itself away.
					unset( $posted['panel_password_hash'], $posted['panel_ip_rules'] );

					foreach ( array( 'update_enabled', 'update_auto' ) as $flag ) {
						if ( ! isset( $posted[ $flag ] ) ) {
							$posted[ $flag ] = 0;
						}
					}

					WPCodeBBV_Settings::save( $posted );
					update_option( self::EDIT_OPT, time(), false );

					$notices[] = array( 'good', 'Settings saved. The next change from here is allowed in a day.' );
				}
			}
		}

		$this->render( $notices );
		exit;
	}

	/**
	 * @param array $notices
	 */
	private function render( $notices ) {
		$settings = WPCodeBBV_Settings::all();
		$edit     = self::edit_allowed();
		$issues   = get_option( self::LOG_OPT, array() );
		$issues   = is_array( $issues ) ? array_reverse( $issues ) : array();

		header( 'Content-Type: text/html; charset=utf-8' );
		?>
<!doctype html>
<html lang="en"><head><meta charset="utf-8" /><meta name="robots" content="noindex,nofollow" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<title>Control panel</title>
<style>
body{font:14px/1.55 -apple-system,"Segoe UI",Roboto,Arial,sans-serif;margin:0;background:#f6f7f7;color:#2c3338}
.wrap{max-width:860px;margin:0 auto;padding:24px 16px 60px}
h1{font-size:20px;margin:0 0 4px}
h2{font-size:15px;margin:28px 0 8px;text-transform:uppercase;letter-spacing:.4px;color:#646970}
.card{background:#fff;border:1px solid #dcdcde;border-radius:6px;padding:14px 16px;margin-bottom:14px}
table{width:100%;border-collapse:collapse}
td{padding:5px 0;vertical-align:top;border-bottom:1px solid #f0f0f1}
td:first-child{width:200px;color:#646970}
tr:last-child td{border-bottom:0}
label{display:block;margin:10px 0 3px;color:#646970;font-size:13px}
input[type=text],input[type=url],input[type=password],select,textarea{width:100%;padding:7px 9px;border:1px solid #8c8f94;border-radius:4px;font:inherit;box-sizing:border-box}
button{background:#2271b1;color:#fff;border:0;border-radius:4px;padding:9px 16px;font:inherit;cursor:pointer;margin-top:12px}
button.secondary{background:#50575e}
.n{padding:10px 12px;border-radius:4px;margin-bottom:12px}
.n.good{background:#edfaef;border-left:4px solid #00a32a}
.n.bad{background:#fcf0f1;border-left:4px solid #d63638}
.muted{color:#787c82;font-size:12px}
code{background:#f0f0f1;padding:1px 4px;border-radius:3px;font-size:12px}
.issue{border-bottom:1px solid #f0f0f1;padding:6px 0;font-size:13px}
.issue:last-child{border-bottom:0}
</style></head><body><div class="wrap">
<h1>Control panel</h1>
<p class="muted">
	<?php echo esc_html( get_bloginfo( 'name' ) ); ?> &middot;
	<?php esc_html_e( 'plugin version', 'wpcode-bb-values' ); ?> <?php echo esc_html( WPCODEBBV_VERSION ); ?> &middot;
	<?php echo esc_html( self::client_ip() ); ?>
</p>

<?php foreach ( $notices as $notice ) : ?>
	<div class="n <?php echo esc_attr( $notice[0] ); ?>"><?php echo esc_html( $notice[1] ); ?></div>
<?php endforeach; ?>

<?php foreach ( self::diagnostics() as $section => $rows ) : ?>
	<h2><?php echo esc_html( $section ); ?></h2>
	<div class="card"><table>
		<?php foreach ( $rows as $label => $value ) : ?>
			<tr><td><?php echo esc_html( $label ); ?></td><td><?php echo esc_html( $value ); ?></td></tr>
		<?php endforeach; ?>
	</table></div>
<?php endforeach; ?>

<h2><?php esc_html_e( 'Recent problems', 'wpcode-bb-values' ); ?></h2>
<div class="card">
	<?php if ( empty( $issues ) ) : ?>
		<p class="muted"><?php esc_html_e( 'Nothing recorded.', 'wpcode-bb-values' ); ?></p>
	<?php else : ?>
		<?php foreach ( $issues as $issue ) : ?>
			<div class="issue">
				<span class="muted"><?php echo esc_html( gmdate( 'Y-m-d H:i', (int) $issue['time'] ) ); ?>Z</span>
				&nbsp;<?php echo esc_html( $issue['msg'] ); ?>
			</div>
		<?php endforeach; ?>
	<?php endif; ?>
</div>

<h2><?php esc_html_e( 'Settings', 'wpcode-bb-values' ); ?></h2>
<div class="card">
	<?php if ( ! $edit['ok'] ) : ?>
		<div class="n bad">
			<?php
			printf(
				/* translators: %s: a UTC timestamp */
				esc_html__( 'Settings were changed from here recently. The next change is allowed at %s UTC.', 'wpcode-bb-values' ),
				esc_html( gmdate( 'Y-m-d H:i', $edit['next'] ) )
			);
			?>
		</div>
	<?php endif; ?>

	<form method="post">
		<label><?php esc_html_e( 'Update source', 'wpcode-bb-values' ); ?></label>
		<select name="wpcodebbv_settings[update_source]">
			<option value="url" <?php selected( $settings['update_source'], 'url' ); ?>>Manifest URL</option>
			<option value="github" <?php selected( $settings['update_source'], 'github' ); ?>>GitHub releases</option>
		</select>

		<label><?php esc_html_e( 'Manifest URL', 'wpcode-bb-values' ); ?></label>
		<input type="url" name="wpcodebbv_settings[update_manifest]" value="<?php echo esc_attr( $settings['update_manifest'] ); ?>" />

		<label><?php esc_html_e( 'Manifest key', 'wpcode-bb-values' ); ?></label>
		<input type="text" name="wpcodebbv_settings[update_manifest_key]" value="<?php echo esc_attr( $settings['update_manifest_key'] ); ?>" />

		<label><?php esc_html_e( 'GitHub owner / repo / asset', 'wpcode-bb-values' ); ?></label>
		<input type="text" name="wpcodebbv_settings[gh_owner]" value="<?php echo esc_attr( $settings['gh_owner'] ); ?>" placeholder="owner" />
		<input type="text" name="wpcodebbv_settings[gh_repo]" value="<?php echo esc_attr( $settings['gh_repo'] ); ?>" placeholder="repo" />
		<input type="text" name="wpcodebbv_settings[gh_asset]" value="<?php echo esc_attr( $settings['gh_asset'] ); ?>" placeholder="asset.zip" />

		<label><?php esc_html_e( 'Rollout role', 'wpcode-bb-values' ); ?></label>
		<select name="wpcodebbv_settings[update_role]">
			<option value="standalone" <?php selected( $settings['update_role'], 'standalone' ); ?>>Standalone</option>
			<option value="dev" <?php selected( $settings['update_role'], 'dev' ); ?>>Dev / staging</option>
			<option value="production" <?php selected( $settings['update_role'], 'production' ); ?>>Production</option>
		</select>

		<label>
			<input type="checkbox" name="wpcodebbv_settings[update_enabled]" value="1" <?php checked( $settings['update_enabled'], 1 ); ?> />
			<?php esc_html_e( 'Check for updates', 'wpcode-bb-values' ); ?>
		</label>

		<label><?php esc_html_e( 'Password', 'wpcode-bb-values' ); ?></label>
		<input type="password" name="wpcodebbv_password" autocomplete="off" />
		<p class="muted"><?php esc_html_e( 'Set in wp-admin. Required for anything on this page that writes.', 'wpcode-bb-values' ); ?></p>

		<input type="hidden" name="wpcodebbv_action" value="save" />
		<button type="submit"><?php esc_html_e( 'Save settings', 'wpcode-bb-values' ); ?></button>
	</form>
</div>

<h2><?php esc_html_e( 'Update now', 'wpcode-bb-values' ); ?></h2>
<div class="card">
	<form method="post">
		<label><?php esc_html_e( 'Password', 'wpcode-bb-values' ); ?></label>
		<input type="password" name="wpcodebbv_password" autocomplete="off" />
		<input type="hidden" name="wpcodebbv_action" value="update" />
		<button type="submit" class="secondary"><?php esc_html_e( 'Check and install now', 'wpcode-bb-values' ); ?></button>
		<p class="muted"><?php esc_html_e( 'Not limited to once a day - only settings changes are.', 'wpcode-bb-values' ); ?></p>
	</form>
</div>

</div></body></html>
		<?php
	}
}
