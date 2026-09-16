<?php
/**
 * Front end: works out which alerts run, then renders them.
 *
 * @package ACPS_Alert_Popups
 */

defined( 'ABSPATH' ) || exit;

/**
 * Front end output.
 */
class ACPS_Alerts_Frontend {

	/**
	 * Alerts queued for this request.
	 *
	 * @var ACPS_Alerts_Alert[]
	 */
	protected $queue = array();

	/**
	 * Whether this request is an editor preview of one alert.
	 *
	 * @var bool
	 */
	protected $is_preview = false;

	/**
	 * Hooks the front end up.
	 *
	 * @return void
	 */
	public function init() {
		add_action( 'wp', array( $this, 'collect_alerts' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'wp_footer', array( $this, 'render_alerts' ), 100 );
		add_shortcode( 'acps_alert_trigger', array( $this, 'trigger_shortcode' ) );
	}

	/**
	 * Builds the queue of alerts for the current request.
	 *
	 * @return void
	 */
	public function collect_alerts() {
		if ( is_admin() || is_feed() || is_embed() || ! ACPS_Alerts_Source::is_ready() ) {
			return;
		}

		if ( is_singular() && ACPS_Alerts_Source::is_popup( get_queried_object_id() ) ) {
			return; // Never stack an alert on top of a popup being previewed.
		}

		if ( $this->maybe_collect_preview() ) {
			return;
		}

		if ( ACPS_Alerts_Settings::get( 'hide_for_admins' ) && current_user_can( ACPS_Alerts_Admin::capability() ) ) {
			return;
		}

		$max     = (int) ACPS_Alerts_Settings::get( 'max_concurrent' );
		$queue   = array();

		foreach ( ACPS_Alerts_Source::get_enabled_alerts() as $alert ) {
			if ( ! ACPS_Alerts_Conditions::passes( $alert ) ) {
				continue;
			}

			$queue[] = $alert;

			if ( count( $queue ) >= $max ) {
				break;
			}
		}

		$this->queue = $queue;
	}

	/**
	 * Queues a single alert when an editor asks for a preview.
	 *
	 * @return bool Whether this request is a preview.
	 */
	protected function maybe_collect_preview() {
		if ( ! ACPS_Alerts_Settings::get( 'respect_preview' ) ) {
			return false;
		}

		$preview_id = isset( $_GET['acps_alert_preview'] ) ? absint( $_GET['acps_alert_preview'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Editor-only preview, no state change.

		if ( ! $preview_id || ! current_user_can( ACPS_Alerts_Admin::capability() ) || ! ACPS_Alerts_Source::is_popup( $preview_id ) ) {
			return false;
		}

		$this->queue      = array( new ACPS_Alerts_Alert( $preview_id ) );
		$this->is_preview = true;

		return true;
	}

	/**
	 * Loads styles and scripts when there is something to show.
	 *
	 * @return void
	 */
	public function enqueue_assets() {
		if ( empty( $this->queue ) ) {
			return;
		}

		wp_enqueue_style( 'acps-alerts', ACPS_ALERTS_URL . 'assets/css/alerts.css', array(), ACPS_ALERTS_VERSION );

		$custom_css = (string) ACPS_Alerts_Settings::get( 'custom_css' );
		$z_index    = (int) ACPS_Alerts_Settings::get( 'z_index' );

		wp_add_inline_style( 'acps-alerts', ':root{--acps-alert-z-index:' . $z_index . ';}' . $custom_css );

		wp_enqueue_script( 'acps-alerts', ACPS_ALERTS_URL . 'assets/js/alerts.js', array(), ACPS_ALERTS_VERSION, true );

		wp_localize_script(
			'acps-alerts',
			'ACPSAlertsData',
			array(
				'storage'    => ACPS_Alerts_Settings::get( 'storage' ),
				'isPreview'  => $this->is_preview ? 1 : 0,
				'nativeOpen' => $this->use_native_rendering() ? $this->native_open_callback() : '',
				'alerts'     => $this->get_js_config(),
				'i18n'       => array(
					'close' => __( 'Close alert', 'acps-alert-popups' ),
				),
			)
		);

		// Let Beaver Builder load the CSS and JS each popup layout needs.
		foreach ( $this->queue as $alert ) {
			if ( method_exists( 'FLBuilder', 'enqueue_layout_styles_scripts_by_id' ) ) {
				FLBuilder::enqueue_layout_styles_scripts_by_id( $alert->get_id() );
			}
		}
	}

	/**
	 * The queue, reduced to what the JavaScript needs.
	 *
	 * @return array
	 */
	protected function get_js_config() {
		$config = array();

		foreach ( $this->queue as $alert ) {
			$config[] = array(
				'id'            => $alert->get_id(),
				'trigger'       => $this->is_preview ? 'load' : $alert->get( 'trigger' ),
				'triggerDelay'  => (int) $alert->get( 'trigger_delay' ),
				'triggerScroll' => (int) $alert->get( 'trigger_scroll' ),
				'frequency'     => $this->is_preview ? 'always' : $alert->get( 'frequency' ),
				'frequencyDays' => (int) $alert->get( 'frequency_days' ),
				'dismissible'   => (bool) $alert->get( 'dismissible' ),
				'overlayClose'  => (bool) $alert->get( 'overlay_close' ),
				'escClose'      => (bool) $alert->get( 'esc_close' ),
				'native'        => $this->use_native_rendering(),
				'version'       => (string) get_post_modified_time( 'U', true, $alert->get_id() ),
			);
		}

		return $config;
	}

	/**
	 * Whether Beaver Builder's own popup engine should open the popups.
	 *
	 * @return bool
	 */
	protected function use_native_rendering() {
		$mode = ACPS_Alerts_Settings::get( 'render_mode' );

		if ( 'modal' === $mode ) {
			return false;
		}

		$has_native = $this->native_open_callback() !== '';

		if ( 'native' === $mode ) {
			return $has_native;
		}

		return $has_native; // 'auto'.
	}

	/**
	 * The JavaScript callback Beaver Builder exposes for opening a popup, if any.
	 *
	 * @return string Empty string when Beaver Builder has no popup API here.
	 */
	protected function native_open_callback() {
		/**
		 * Filters the JS function used to open a Beaver Builder popup natively.
		 *
		 * Return something like 'FLBuilderPopup.open' to hand opening over to
		 * Beaver Builder. An empty string keeps the plugin's own modal.
		 *
		 * @param string $callback Dotted path to a global JS function.
		 */
		return (string) apply_filters( 'acps_alerts_native_open_callback', '' );
	}

	/**
	 * Prints the alert markup in the footer.
	 *
	 * @return void
	 */
	public function render_alerts() {
		if ( empty( $this->queue ) ) {
			return;
		}

		foreach ( $this->queue as $alert ) {
			$this->render_alert( $alert );
		}
	}

	/**
	 * Prints one alert.
	 *
	 * @param ACPS_Alerts_Alert $alert Alert to render.
	 * @return void
	 */
	protected function render_alert( ACPS_Alerts_Alert $alert ) {
		$id       = $alert->get_id();
		$label    = $alert->get( 'aria_label' );
		$label    = '' !== $label ? $label : $alert->get_title();
		$position = $alert->get( 'position' );
		$severity = $alert->get( 'severity' );
		$width    = (int) $alert->get( 'width' );

		$classes = array(
			'acps-alert',
			'acps-alert--' . $severity,
			'acps-alert--' . $position,
		);

		if ( ! $alert->get( 'show_overlay' ) ) {
			$classes[] = 'acps-alert--no-overlay';
		}
		?>
		<div
			id="acps-alert-<?php echo esc_attr( $id ); ?>"
			class="<?php echo esc_attr( implode( ' ', $classes ) ); ?>"
			data-alert="<?php echo esc_attr( $id ); ?>"
			role="dialog"
			aria-modal="true"
			aria-label="<?php echo esc_attr( $label ); ?>"
			hidden
		>
			<div class="acps-alert__overlay" data-acps-overlay></div>
			<div class="acps-alert__dialog" style="max-width:<?php echo esc_attr( $width ); ?>px">
				<?php if ( $alert->get( 'dismissible' ) ) : ?>
					<button type="button" class="acps-alert__close" data-acps-close aria-label="<?php esc_attr_e( 'Close alert', 'acps-alert-popups' ); ?>">
						<span aria-hidden="true">&times;</span>
					</button>
				<?php endif; ?>
				<div class="acps-alert__content">
					<?php echo $this->get_popup_content( $id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Rendered page-builder layout. ?>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * The rendered Beaver Builder layout for a popup.
	 *
	 * @param int $post_id Popup post ID.
	 * @return string
	 */
	protected function get_popup_content( $post_id ) {
		if ( method_exists( 'FLBuilder', 'render_content_by_id' ) ) {
			return (string) FLBuilder::render_content_by_id( $post_id );
		}

		$post = get_post( $post_id );

		if ( ! $post ) {
			return '';
		}

		/** This filter is documented in wp-includes/post-template.php */
		return (string) apply_filters( 'the_content', $post->post_content );
	}

	/**
	 * Shortcode that renders a link or button which opens an alert.
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string
	 */
	public function trigger_shortcode( $atts ) {
		$atts = shortcode_atts(
			array(
				'id'    => 0,
				'text'  => __( 'Open alert', 'acps-alert-popups' ),
				'class' => '',
				'tag'   => 'button',
			),
			$atts,
			'acps_alert_trigger'
		);

		$id = absint( $atts['id'] );

		if ( ! $id || ! ACPS_Alerts_Source::is_popup( $id ) ) {
			return '';
		}

		$classes = trim( 'acps-alert-open ' . sanitize_text_field( $atts['class'] ) );

		if ( 'a' === $atts['tag'] ) {
			return sprintf(
				'<a href="#acps-alert-%1$d" class="%2$s" data-alert="%1$d">%3$s</a>',
				$id,
				esc_attr( $classes ),
				esc_html( $atts['text'] )
			);
		}

		return sprintf(
			'<button type="button" class="%2$s" data-alert="%1$d">%3$s</button>',
			$id,
			esc_attr( $classes ),
			esc_html( $atts['text'] )
		);
	}

	/**
	 * The alerts queued for this request.
	 *
	 * @return ACPS_Alerts_Alert[]
	 */
	public function get_queue() {
		return $this->queue;
	}
}
