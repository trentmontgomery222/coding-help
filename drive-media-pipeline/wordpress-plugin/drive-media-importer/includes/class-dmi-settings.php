<?php
/**
 * Network-admin settings for Drive Media Importer.
 *
 * Settings are network-scoped (site options). The shared token can be
 * defined as a constant in wp-config.php — DMI_SHARED_TOKEN — which takes
 * precedence over the stored option and keeps the secret out of the
 * database and the repo.
 */

defined( 'ABSPATH' ) || exit;

class DMI_Settings {

	const OPTION = 'dmi_settings';

	public static function init() {
		add_action( 'network_admin_menu', array( __CLASS__, 'add_menu' ) );
		add_action( 'network_admin_edit_dmi_save_settings', array( __CLASS__, 'save' ) );
		add_action( 'network_admin_edit_dmi_run_now', array( __CLASS__, 'run_now' ) );
	}

	public static function defaults() {
		return array(
			'webapp_url'     => '',
			'token'          => '',
			'batch_size'     => 10,
			'enabled'        => 0,
			'hours_enabled'  => 0,
			'hours_start'    => '07:00',
			'hours_end'      => '17:00',
			'hours_days'     => array( 1, 2, 3, 4, 5 ), // Mon–Fri (ISO-8601 N)
			'max_file_bytes' => 20 * MB_IN_BYTES,
		);
	}

	public static function get() {
		$settings = wp_parse_args( (array) get_site_option( self::OPTION, array() ), self::defaults() );
		if ( defined( 'DMI_SHARED_TOKEN' ) && DMI_SHARED_TOKEN ) {
			$settings['token'] = DMI_SHARED_TOKEN;
		}
		return $settings;
	}

	public static function add_menu() {
		add_submenu_page(
			'settings.php',
			__( 'Drive Media Importer', 'drive-media-importer' ),
			__( 'Drive Media Importer', 'drive-media-importer' ),
			'manage_network_options',
			'dmi-settings',
			array( __CLASS__, 'render' )
		);
	}

	public static function save() {
		check_admin_referer( 'dmi_save_settings' );
		if ( ! current_user_can( 'manage_network_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'drive-media-importer' ) );
		}

		$in       = wp_unslash( $_POST );
		$existing = self::get();
		$stored   = (array) get_site_option( self::OPTION, array() );

		$days = array();
		if ( ! empty( $in['hours_days'] ) && is_array( $in['hours_days'] ) ) {
			foreach ( $in['hours_days'] as $d ) {
				$d = (int) $d;
				if ( $d >= 1 && $d <= 7 ) {
					$days[] = $d;
				}
			}
		}

		$settings = array(
			'webapp_url'     => esc_url_raw( trim( $in['webapp_url'] ?? '' ) ),
			// Blank token field means "keep the existing token".
			'token'          => ( '' !== trim( $in['token'] ?? '' ) )
				? trim( $in['token'] )
				: (string) ( $stored['token'] ?? '' ),
			'batch_size'     => max( 1, min( 50, (int) ( $in['batch_size'] ?? 10 ) ) ),
			'enabled'        => empty( $in['enabled'] ) ? 0 : 1,
			'hours_enabled'  => empty( $in['hours_enabled'] ) ? 0 : 1,
			'hours_start'    => self::sanitize_time( $in['hours_start'] ?? '07:00', '07:00' ),
			'hours_end'      => self::sanitize_time( $in['hours_end'] ?? '17:00', '17:00' ),
			'hours_days'     => $days ? $days : $existing['hours_days'],
			'max_file_bytes' => max( MB_IN_BYTES, (int) ( $in['max_file_bytes'] ?? 20 * MB_IN_BYTES ) ),
		);

		update_site_option( self::OPTION, $settings );

		wp_safe_redirect( add_query_arg(
			array( 'page' => 'dmi-settings', 'updated' => 'true' ),
			network_admin_url( 'settings.php' )
		) );
		exit;
	}

	/** Manual "Run now" trigger (build-order step 6). */
	public static function run_now() {
		check_admin_referer( 'dmi_run_now' );
		if ( ! current_user_can( 'manage_network_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'drive-media-importer' ) );
		}

		$summary = DMI_Poller::run( true );

		wp_safe_redirect( add_query_arg(
			array( 'page' => 'dmi-settings', 'dmi_ran' => rawurlencode( $summary ) ),
			network_admin_url( 'settings.php' )
		) );
		exit;
	}

	private static function sanitize_time( $value, $fallback ) {
		return preg_match( '/^([01]\d|2[0-3]):[0-5]\d$/', (string) $value ) ? (string) $value : $fallback;
	}

	public static function render() {
		$s          = self::get();
		$token_const = defined( 'DMI_SHARED_TOKEN' ) && DMI_SHARED_TOKEN;
		$day_labels  = array(
			1 => __( 'Mon', 'drive-media-importer' ),
			2 => __( 'Tue', 'drive-media-importer' ),
			3 => __( 'Wed', 'drive-media-importer' ),
			4 => __( 'Thu', 'drive-media-importer' ),
			5 => __( 'Fri', 'drive-media-importer' ),
			6 => __( 'Sat', 'drive-media-importer' ),
			7 => __( 'Sun', 'drive-media-importer' ),
		);
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Drive Media Importer', 'drive-media-importer' ); ?></h1>

			<?php if ( isset( $_GET['updated'] ) ) : ?>
				<div class="notice notice-success" role="status"><p><?php esc_html_e( 'Settings saved.', 'drive-media-importer' ); ?></p></div>
			<?php endif; ?>
			<?php if ( isset( $_GET['dmi_ran'] ) ) : ?>
				<div class="notice notice-info" role="status"><p><?php echo esc_html( wp_unslash( $_GET['dmi_ran'] ) ); ?></p></div>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( network_admin_url( 'edit.php?action=dmi_save_settings' ) ); ?>">
				<?php wp_nonce_field( 'dmi_save_settings' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="dmi_webapp_url"><?php esc_html_e( 'Apps Script Web App URL', 'drive-media-importer' ); ?></label></th>
						<td>
							<input name="webapp_url" id="dmi_webapp_url" type="url" class="large-text" value="<?php echo esc_attr( $s['webapp_url'] ); ?>" required>
							<p class="description"><?php esc_html_e( 'The /exec URL of the deployed Web App. Redeploying as a new deployment changes this URL — edit the existing deployment instead.', 'drive-media-importer' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="dmi_token"><?php esc_html_e( 'Shared secret token', 'drive-media-importer' ); ?></label></th>
						<td>
							<?php if ( $token_const ) : ?>
								<p><strong><?php esc_html_e( 'Defined as DMI_SHARED_TOKEN in wp-config.php (recommended). The field below is ignored.', 'drive-media-importer' ); ?></strong></p>
							<?php endif; ?>
							<input name="token" id="dmi_token" type="password" class="large-text" value="" autocomplete="new-password" <?php disabled( $token_const ); ?> aria-describedby="dmi_token_desc">
							<p class="description" id="dmi_token_desc"><?php esc_html_e( 'Leave blank to keep the current token. Must match the SHARED_TOKEN script property in Apps Script.', 'drive-media-importer' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="dmi_batch_size"><?php esc_html_e( 'Batch size', 'drive-media-importer' ); ?></label></th>
						<td><input name="batch_size" id="dmi_batch_size" type="number" min="1" max="50" value="<?php echo esc_attr( $s['batch_size'] ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><label for="dmi_max_file_bytes"><?php esc_html_e( 'Max accepted file size (bytes)', 'drive-media-importer' ); ?></label></th>
						<td><input name="max_file_bytes" id="dmi_max_file_bytes" type="number" min="<?php echo esc_attr( MB_IN_BYTES ); ?>" value="<?php echo esc_attr( $s['max_file_bytes'] ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Enable polling', 'drive-media-importer' ); ?></th>
						<td>
							<label for="dmi_enabled">
								<input name="enabled" id="dmi_enabled" type="checkbox" value="1" <?php checked( $s['enabled'] ); ?>>
								<?php esc_html_e( 'Master enable switch. When off, the poller does nothing.', 'drive-media-importer' ); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Working-hours window', 'drive-media-importer' ); ?></th>
						<td>
							<fieldset>
								<legend class="screen-reader-text"><?php esc_html_e( 'Working-hours window', 'drive-media-importer' ); ?></legend>
								<label for="dmi_hours_enabled">
									<input name="hours_enabled" id="dmi_hours_enabled" type="checkbox" value="1" <?php checked( $s['hours_enabled'] ); ?>>
									<?php esc_html_e( 'Only poll during the window below (site timezone). Leave off to poll 24/7.', 'drive-media-importer' ); ?>
								</label>
								<br><br>
								<label for="dmi_hours_start"><?php esc_html_e( 'Start', 'drive-media-importer' ); ?></label>
								<input name="hours_start" id="dmi_hours_start" type="time" value="<?php echo esc_attr( $s['hours_start'] ); ?>">
								<label for="dmi_hours_end"><?php esc_html_e( 'End', 'drive-media-importer' ); ?></label>
								<input name="hours_end" id="dmi_hours_end" type="time" value="<?php echo esc_attr( $s['hours_end'] ); ?>">
								<br><br>
								<?php foreach ( $day_labels as $n => $label ) : ?>
									<label style="margin-right:1em;">
										<input type="checkbox" name="hours_days[]" value="<?php echo esc_attr( $n ); ?>" <?php checked( in_array( $n, (array) $s['hours_days'], true ) ); ?>>
										<?php echo esc_html( $label ); ?>
									</label>
								<?php endforeach; ?>
							</fieldset>
						</td>
					</tr>
				</table>
				<?php submit_button( __( 'Save Settings', 'drive-media-importer' ) ); ?>
			</form>

			<h2><?php esc_html_e( 'Manual run', 'drive-media-importer' ); ?></h2>
			<form method="post" action="<?php echo esc_url( network_admin_url( 'edit.php?action=dmi_run_now' ) ); ?>">
				<?php wp_nonce_field( 'dmi_run_now' ); ?>
				<?php submit_button( __( 'Run one poll cycle now', 'drive-media-importer' ), 'secondary' ); ?>
			</form>
		</div>
		<?php
	}
}
