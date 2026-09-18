<?php
/**
 * Renders the alert settings form.
 *
 * The same markup backs the dedicated "Edit alert" screen and the meta box on
 * the popup post editor, so the two can never drift apart.
 *
 * @package ACPS_Alert_Popups
 */

defined( 'ABSPATH' ) || exit;

/**
 * Alert settings form rendering.
 */
class ACPS_Alerts_Fields {

	const NONCE_ACTION = 'acps_alerts_save_alert';
	const NONCE_NAME   = 'acps_alerts_nonce';

	/**
	 * Outputs the full settings form body (no <form> wrapper, no submit button).
	 *
	 * @param ACPS_Alerts_Alert $alert Alert being edited.
	 * @return void
	 */
	public static function render( ACPS_Alerts_Alert $alert ) {
		// get_settings() always answers with a full, sanitized set — falling
		// back to defaults rather than throwing — so every key below is safe to
		// read without checking first.
		$s = $alert->get_settings();

		wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME );
		?>
		<div class="acps-alert-fields">

			<?php self::section_open( __( 'Status', 'acps-alert-popups' ), 'status' ); ?>
				<?php
				self::row(
					__( 'Alert is live', 'acps-alert-popups' ),
					self::checkbox( 'enabled', $s['enabled'], __( 'Show this popup as a site alert', 'acps-alert-popups' ) ),
					__( 'Design the popup in Beaver Builder; this switch controls whether visitors see it.', 'acps-alert-popups' )
				);

				?>
			<?php self::section_close(); ?>

			<?php self::section_open( __( 'Status board', 'acps-alert-popups' ), 'board' ); ?>
				<?php
				self::row(
					__( 'Status level', 'acps-alert-popups' ),
					self::select( 'status_level', $s['status_level'], ACPS_Alerts_Status::level_choices() ),
					__( 'Sets the wording and colour of the banner on the status page.', 'acps-alert-popups' )
				);

				self::row(
					__( 'Summary', 'acps-alert-popups' ),
					self::textarea( 'status_message', $s['status_message'] ),
					__( 'Shown on the status board and in the archive. Also used for the popup unless you design one in Beaver Builder.', 'acps-alert-popups' )
				);

				self::row(
					__( 'Where it appears', 'acps-alert-popups' ),
					self::checkbox( 'on_board', $s['on_board'], __( 'Show on the status board', 'acps-alert-popups' ) )
					. self::checkbox( 'as_popup', $s['as_popup'], __( 'Pop up across the site', 'acps-alert-popups' ) ),
					__( 'An update can do both, or just one.', 'acps-alert-popups' )
				);

				self::row(
					__( 'Take it down', 'acps-alert-popups' ),
					self::select(
						'expires_mode',
						$s['expires_mode'],
						array(
							'daily'  => sprintf(
								/* translators: %s: cut-off time, e.g. 17:50. */
								__( 'Automatically, at the daily cut-off (%s)', 'acps-alert-popups' ),
								ACPS_Alerts_Status::cutoff_time()
							),
							'keep'   => __( 'Keep it up until I archive it', 'acps-alert-popups' ),
							'custom' => __( 'Use the start and end dates below', 'acps-alert-popups' ),
						)
					),
					__( 'The cut-off is set in Settings. An update posted after it runs until the following day.', 'acps-alert-popups' )
				);

				self::row(
					__( 'Archived', 'acps-alert-popups' ),
					self::checkbox( 'archived', $s['archived'], __( 'This update is in the archive', 'acps-alert-popups' ) ),
					__( 'Archived updates drop off the banner and the popup, and appear in the list of past updates. Tick this when writing up something that already happened.', 'acps-alert-popups' )
				);

				self::row(
					__( 'Date it happened', 'acps-alert-popups' ),
					self::datetime( 'posted_at_local', self::stamp_to_local( $s['posted_at'] ) ),
					__( 'Decides where the update sits in the archive. Leave empty to use the moment it was created.', 'acps-alert-popups' )
				);
				?>
			<?php self::section_close(); ?>

			<?php self::section_open( __( 'Schedule', 'acps-alert-popups' ), 'schedule' ); ?>
				<?php
				self::row(
					__( 'Start', 'acps-alert-popups' ),
					self::datetime( 'start', $s['start'] ),
					__( 'Leave empty to start immediately. Times use the site timezone.', 'acps-alert-popups' )
				);

				self::row(
					__( 'End', 'acps-alert-popups' ),
					self::datetime( 'end', $s['end'] ),
					__( 'Leave empty to run until the alert is switched off.', 'acps-alert-popups' )
				);
				?>
			<?php self::section_close(); ?>

			<?php self::section_open( __( 'Where it shows', 'acps-alert-popups' ), 'where' ); ?>
				<?php
				self::row(
					__( 'Display on', 'acps-alert-popups' ),
					self::select(
						'display',
						$s['display'],
						array(
							'entire'   => __( 'Entire site', 'acps-alert-popups' ),
							'front'    => __( 'Front page only', 'acps-alert-popups' ),
							'selected' => __( 'Selected locations', 'acps-alert-popups' ),
						),
						array( 'class' => 'acps-display-mode' )
					)
				);
				?>
				<div class="acps-selected-only">
					<?php
					self::row(
						__( 'Post types', 'acps-alert-popups' ),
						self::post_type_checkboxes( (array) $s['post_types'] ),
						__( 'Matches single entries and archives for the chosen types.', 'acps-alert-popups' )
					);

					self::row(
						__( 'Specific page or post IDs', 'acps-alert-popups' ),
						self::text( 'post_ids', implode( ', ', (array) $s['post_ids'] ), array( 'placeholder' => '12, 34, 56' ) ),
						__( 'Comma separated IDs.', 'acps-alert-popups' )
					);

					self::row(
						__( 'URL paths', 'acps-alert-popups' ),
						self::textarea( 'include_urls', $s['include_urls'], array( 'placeholder' => "/enrollment\n/news/*" ) ),
						__( 'One path per line. Use * as a wildcard. Full URLs are accepted; only the path is compared.', 'acps-alert-popups' )
					);
					?>
				</div>
				<?php
				self::row(
					__( 'Never show on', 'acps-alert-popups' ),
					self::textarea( 'exclude_urls', $s['exclude_urls'], array( 'placeholder' => "/apply/thank-you\n/staff/*" ) ),
					__( 'Exclusions always win, whatever the targeting above says.', 'acps-alert-popups' )
				);
				?>
			<?php self::section_close(); ?>

			<?php self::section_open( __( 'Who sees it', 'acps-alert-popups' ), 'who' ); ?>
				<?php
				self::row(
					__( 'Visibility', 'acps-alert-popups' ),
					self::select(
						'visibility',
						$s['visibility'],
						array(
							'public'  => __( 'Live — everybody can see it', 'acps-alert-popups' ),
							'admins'  => __( 'Staff only — stage it before it goes out', 'acps-alert-popups' ),
							'preview' => __( 'Hidden — only through the preview link', 'acps-alert-popups' ),
						)
					),
					__( 'Staff only shows the update on the real site to people who can manage alerts, and to nobody else — on the board and in the popup. Use it to check an update before the public sees it.', 'acps-alert-popups' )
				);

				self::row(
					__( 'Audience', 'acps-alert-popups' ),
					self::select(
						'audience',
						$s['audience'],
						array(
							'all'        => __( 'Everyone', 'acps-alert-popups' ),
							'logged_out' => __( 'Logged out visitors', 'acps-alert-popups' ),
							'logged_in'  => __( 'Logged in users', 'acps-alert-popups' ),
							'roles'      => __( 'Specific roles', 'acps-alert-popups' ),
						),
						array( 'class' => 'acps-audience-mode' )
					)
				);
				?>
				<div class="acps-roles-only">
					<?php
					self::row(
						__( 'Roles', 'acps-alert-popups' ),
						self::role_checkboxes( (array) $s['roles'] )
					);
					?>
				</div>
			<?php self::section_close(); ?>

			<?php self::section_open( __( 'How it opens', 'acps-alert-popups' ), 'how' ); ?>
				<?php
				self::row(
					__( 'Trigger', 'acps-alert-popups' ),
					self::select(
						'trigger',
						$s['trigger'],
						array(
							'load'   => __( 'As soon as the page loads', 'acps-alert-popups' ),
							'delay'  => __( 'After a delay', 'acps-alert-popups' ),
							'scroll' => __( 'After scrolling down the page', 'acps-alert-popups' ),
							'exit'   => __( 'On exit intent', 'acps-alert-popups' ),
							'click'  => __( 'Only when something opens it', 'acps-alert-popups' ),
						),
						array( 'class' => 'acps-trigger-mode' )
					),
					__( 'Anything with the class acps-alert-open, or the Alert Trigger module, can open an alert on click.', 'acps-alert-popups' )
				);
				?>
				<div class="acps-trigger-delay-only">
					<?php
					self::row(
						__( 'Delay (seconds)', 'acps-alert-popups' ),
						self::number( 'trigger_delay', $s['trigger_delay'], 0, 600 )
					);
					?>
				</div>
				<div class="acps-trigger-scroll-only">
					<?php
					self::row(
						__( 'Scroll depth (%)', 'acps-alert-popups' ),
						self::number( 'trigger_scroll', $s['trigger_scroll'], 1, 100 )
					);
					?>
				</div>
				<?php
				self::row(
					__( 'Show again', 'acps-alert-popups' ),
					self::select(
						'frequency',
						$s['frequency'],
						array(
							'always'  => __( 'Every page view', 'acps-alert-popups' ),
							'session' => __( 'Once per browser session', 'acps-alert-popups' ),
							'days'    => __( 'Once every X days', 'acps-alert-popups' ),
							'once'    => __( 'Once, then never again', 'acps-alert-popups' ),
						),
						array( 'class' => 'acps-frequency-mode' )
					),
					__( 'Counted per browser, after the visitor closes the alert.', 'acps-alert-popups' )
				);
				?>
				<div class="acps-frequency-days-only">
					<?php
					self::row(
						__( 'Days between showings', 'acps-alert-popups' ),
						self::number( 'frequency_days', $s['frequency_days'], 1, 365 )
					);
					?>
				</div>
			<?php self::section_close(); ?>

			<?php self::section_open( __( 'Appearance and accessibility', 'acps-alert-popups' ), 'appearance' ); ?>
				<?php
				self::row(
					__( 'Position', 'acps-alert-popups' ),
					self::select(
						'position',
						$s['position'],
						array(
							'center'       => __( 'Centered', 'acps-alert-popups' ),
							'top'          => __( 'Top of the screen', 'acps-alert-popups' ),
							'bottom'       => __( 'Bottom of the screen', 'acps-alert-popups' ),
							'bottom-right' => __( 'Bottom right corner', 'acps-alert-popups' ),
							'bottom-left'  => __( 'Bottom left corner', 'acps-alert-popups' ),
						)
					),
					__( 'Ignored when the alert is rendered by Beaver Builder&rsquo;s own popup engine.', 'acps-alert-popups' )
				);

				self::row(
					__( 'Maximum width (px)', 'acps-alert-popups' ),
					self::number( 'width', $s['width'], 200, 1600 )
				);

				self::row(
					__( 'Overlay', 'acps-alert-popups' ),
					self::checkbox( 'show_overlay', $s['show_overlay'], __( 'Dim the page behind the alert', 'acps-alert-popups' ) )
				);

				self::row(
					__( 'Closing', 'acps-alert-popups' ),
					self::checkbox( 'dismissible', $s['dismissible'], __( 'Show a close button', 'acps-alert-popups' ) )
					. self::checkbox( 'overlay_close', $s['overlay_close'], __( 'Close when the overlay is clicked', 'acps-alert-popups' ) )
					. self::checkbox( 'esc_close', $s['esc_close'], __( 'Close on the Escape key', 'acps-alert-popups' ) ),
					__( 'An alert with no way to close it will trap keyboard users. Leave at least one option on.', 'acps-alert-popups' )
				);

				self::row(
					__( 'Screen reader label', 'acps-alert-popups' ),
					self::text( 'aria_label', $s['aria_label'], array( 'placeholder' => __( 'Site alert', 'acps-alert-popups' ) ) ),
					__( 'Announced when the alert opens. Defaults to the popup title.', 'acps-alert-popups' )
				);

				self::row(
					__( 'Internal notes', 'acps-alert-popups' ),
					self::textarea( 'notes', $s['notes'] ),
					__( 'Only ever shown here in the admin.', 'acps-alert-popups' )
				);
				?>
			<?php self::section_close(); ?>
		</div>
		<?php
	}

	/**
	 * Opens a titled section.
	 *
	 * @param string $title Section title.
	 * @param string $key   Stable key, used to anchor guided tour steps.
	 * @return void
	 */
	protected static function section_open( $title, $key = '' ) {
		?>
		<div class="acps-section" data-acps-section="<?php echo esc_attr( $key ); ?>">
			<h2 class="acps-section__title"><?php echo esc_html( $title ); ?></h2>
			<table class="form-table" role="presentation">
				<tbody>
		<?php
	}

	/**
	 * Closes a section.
	 *
	 * @return void
	 */
	protected static function section_close() {
		?>
				</tbody>
			</table>
		</div>
		<?php
	}

	/**
	 * Outputs one labelled form row.
	 *
	 * @param string $label       Field label.
	 * @param string $control     Control markup (already escaped).
	 * @param string $description Optional help text.
	 * @return void
	 */
	protected static function row( $label, $control, $description = '' ) {
		?>
		<tr>
			<th scope="row"><?php echo esc_html( $label ); ?></th>
			<td>
				<?php echo $control; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built from escaped helpers below. ?>
				<?php if ( '' !== $description ) : ?>
					<p class="description"><?php echo wp_kses_post( $description ); ?></p>
				<?php endif; ?>
			</td>
		</tr>
		<?php
	}

	/**
	 * Builds the name attribute for a setting.
	 *
	 * @param string $key Setting key.
	 * @return string
	 */
	protected static function name( $key ) {
		return 'acps_alert[' . $key . ']';
	}

	/**
	 * Checkbox control.
	 *
	 * @param string $key     Setting key.
	 * @param mixed  $value   Current value.
	 * @param string $label   Inline label.
	 * @return string
	 */
	protected static function checkbox( $key, $value, $label ) {
		return sprintf(
			'<label class="acps-check"><input type="hidden" name="%1$s" value="0" /><input type="checkbox" name="%1$s" value="1" %2$s /> %3$s</label>',
			esc_attr( self::name( $key ) ),
			checked( 1, (int) $value, false ),
			esc_html( $label )
		);
	}

	/**
	 * Select control.
	 *
	 * @param string $key     Setting key.
	 * @param mixed  $value   Current value.
	 * @param array  $choices value => label pairs.
	 * @param array  $attrs   Extra attributes.
	 * @return string
	 */
	protected static function select( $key, $value, array $choices, array $attrs = array() ) {
		$html = sprintf(
			'<select name="%s" class="%s">',
			esc_attr( self::name( $key ) ),
			esc_attr( isset( $attrs['class'] ) ? $attrs['class'] : '' )
		);

		foreach ( $choices as $choice => $label ) {
			$html .= sprintf(
				'<option value="%s" %s>%s</option>',
				esc_attr( $choice ),
				selected( $choice, $value, false ),
				esc_html( $label )
			);
		}

		return $html . '</select>';
	}

	/**
	 * Number control.
	 *
	 * @param string $key   Setting key.
	 * @param mixed  $value Current value.
	 * @param int    $min   Minimum.
	 * @param int    $max   Maximum.
	 * @return string
	 */
	protected static function number( $key, $value, $min, $max ) {
		return sprintf(
			'<input type="number" class="small-text" name="%s" value="%s" min="%d" max="%d" step="1" />',
			esc_attr( self::name( $key ) ),
			esc_attr( $value ),
			(int) $min,
			(int) $max
		);
	}

	/**
	 * Text control.
	 *
	 * @param string $key   Setting key.
	 * @param mixed  $value Current value.
	 * @param array  $attrs Extra attributes.
	 * @return string
	 */
	protected static function text( $key, $value, array $attrs = array() ) {
		return sprintf(
			'<input type="text" class="regular-text" name="%s" value="%s" placeholder="%s" />',
			esc_attr( self::name( $key ) ),
			esc_attr( $value ),
			esc_attr( isset( $attrs['placeholder'] ) ? $attrs['placeholder'] : '' )
		);
	}

	/**
	 * Textarea control.
	 *
	 * @param string $key   Setting key.
	 * @param mixed  $value Current value.
	 * @param array  $attrs Extra attributes.
	 * @return string
	 */
	protected static function textarea( $key, $value, array $attrs = array() ) {
		return sprintf(
			'<textarea name="%s" rows="4" class="large-text code" placeholder="%s">%s</textarea>',
			esc_attr( self::name( $key ) ),
			esc_attr( isset( $attrs['placeholder'] ) ? $attrs['placeholder'] : '' ),
			esc_textarea( $value )
		);
	}

	/**
	 * Turns a stored timestamp into the site-local value a datetime input wants.
	 *
	 * @param int $stamp Unix timestamp, or 0 when unset.
	 * @return string "Y-m-d H:i" in site time, or '' when unset.
	 */
	protected static function stamp_to_local( $stamp ) {
		$stamp = (int) $stamp;

		if ( $stamp <= 0 ) {
			return '';
		}

		return gmdate( 'Y-m-d H:i', $stamp + (int) ( get_option( 'gmt_offset', 0 ) * HOUR_IN_SECONDS ) );
	}

	/**
	 * Datetime-local control.
	 *
	 * @param string $key   Setting key.
	 * @param mixed  $value Current value, as Y-m-d H:i.
	 * @return string
	 */
	protected static function datetime( $key, $value ) {
		return sprintf(
			'<input type="datetime-local" name="%s" value="%s" />',
			esc_attr( self::name( $key ) ),
			esc_attr( '' !== $value ? str_replace( ' ', 'T', $value ) : '' )
		);
	}

	/**
	 * Checkbox list of public post types.
	 *
	 * @param array $selected Selected post type slugs.
	 * @return string
	 */
	protected static function post_type_checkboxes( array $selected ) {
		$post_types = get_post_types( array( 'public' => true ), 'objects' );
		$html       = '<fieldset class="acps-checkbox-list">';

		foreach ( $post_types as $post_type ) {
			if ( 'attachment' === $post_type->name || ACPS_Alerts_Source::post_type() === $post_type->name ) {
				continue;
			}

			$html .= sprintf(
				'<label><input type="checkbox" name="%s[]" value="%s" %s /> %s</label>',
				esc_attr( self::name( 'post_types' ) ),
				esc_attr( $post_type->name ),
				checked( in_array( $post_type->name, $selected, true ), true, false ),
				esc_html( $post_type->labels->name )
			);
		}

		return $html . '</fieldset>';
	}

	/**
	 * Checkbox list of roles.
	 *
	 * @param array $selected Selected role slugs.
	 * @return string
	 */
	protected static function role_checkboxes( array $selected ) {
		$html = '<fieldset class="acps-checkbox-list">';

		foreach ( wp_roles()->get_names() as $slug => $label ) {
			$html .= sprintf(
				'<label><input type="checkbox" name="%s[]" value="%s" %s /> %s</label>',
				esc_attr( self::name( 'roles' ) ),
				esc_attr( $slug ),
				checked( in_array( $slug, $selected, true ), true, false ),
				esc_html( translate_user_role( $label ) )
			);
		}

		return $html . '</fieldset>';
	}

	/**
	 * Reads and sanitizes a submitted alert form.
	 *
	 * @return array|null Sanitized settings, or null when the nonce is missing or invalid.
	 */
	public static function read_submission() {
		if ( ! isset( $_POST[ self::NONCE_NAME ] ) ) {
			return null;
		}

		$nonce = sanitize_text_field( wp_unslash( $_POST[ self::NONCE_NAME ] ) );

		if ( ! wp_verify_nonce( $nonce, self::NONCE_ACTION ) ) {
			return null;
		}

		$raw = isset( $_POST['acps_alert'] ) ? wp_unslash( $_POST['acps_alert'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized by the schema.

		return ACPS_Alerts_Alert::sanitize( (array) $raw );
	}
}
