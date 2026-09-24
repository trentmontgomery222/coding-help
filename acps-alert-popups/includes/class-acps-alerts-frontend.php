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
	 * Alert IDs already printed this request, so none is printed twice.
	 *
	 * @var array
	 */
	protected $rendered = array();

	/**
	 * Hooks the front end up.
	 *
	 * @return void
	 */
	public function init() {
		// Every front-end hook is registered through the failsafe, so a throw in
		// any of them is caught, recorded and turned into "no alert" rather than
		// a broken page. Repeated failures trip a breaker and stand the whole
		// front end down for a while.
		ACPS_Alerts_Failsafe::action( 'wp', array( $this, 'collect_alerts' ), 'frontend/collect' );
		ACPS_Alerts_Failsafe::action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ), 'frontend/enqueue' );
		ACPS_Alerts_Failsafe::action( 'wp_footer', array( $this, 'render_alerts' ), 'frontend/render', 100 );

		add_shortcode(
			'acps_alert_trigger',
			ACPS_Alerts_Failsafe::wrap( array( $this, 'trigger_shortcode' ), 'frontend/shortcode' )
		);
	}

	/**
	 * Whether the front end should do anything at all on this request.
	 *
	 * Everything that would make alert output pointless or risky is checked in
	 * one place: a dormant plugin, a tripped breaker, a self-test loopback, an
	 * exhausted memory budget, or a request that is not a normal page view.
	 *
	 * @return bool
	 */
	protected function should_run() {
		if ( is_admin() || is_feed() || is_embed() ) {
			return false;
		}

		// A REST, AJAX, cron or XML-RPC request never shows an alert.
		if ( wp_doing_ajax() || wp_doing_cron() ) {
			return false;
		}

		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return false;
		}

		if ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST ) {
			return false;
		}

		// The update self-test loads the home page to prove the plugin boots.
		// Rendering alerts into it wastes work and risks muddying the result.
		if ( isset( $_GET[ ACPS_Alerts_Updater::SELFTEST_VAR ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return false;
		}

		// Inside the Beaver Builder editor the layout is the thing being edited;
		// an alert on top of it only gets in the way.
		if ( class_exists( 'FLBuilderModel' ) && method_exists( 'FLBuilderModel', 'is_builder_active' ) && FLBuilderModel::is_builder_active() ) {
			return false;
		}

		// The status page already shows the update as a banner. Popping the same
		// thing up on top of it just covers the page someone came to read.
		if ( self::is_status_page() ) {
			return false;
		}

		if ( ACPS_Alerts_Failsafe::breaker_tripped( 'frontend' ) ) {
			return false;
		}

		return ACPS_Alerts_Source::is_ready();
	}

	/**
	 * Whether the page being viewed carries a status board.
	 *
	 * @return bool
	 */
	public static function is_status_page() {
		if ( ! is_singular() ) {
			return false;
		}

		if ( class_exists( 'ACPS_Alerts_Builder' ) && ACPS_Alerts_Builder::board_on_page() ) {
			return true;
		}

		// Fall back to the page the board was last saved on, so this still holds
		// if the layout cannot be inspected.
		$board_page = (int) get_option( 'acps_alerts_board_page', 0 );

		return $board_page > 0 && $board_page === (int) get_queried_object_id();
	}

	/**
	 * Builds the queue of alerts for the current request.
	 *
	 * @return void
	 */
	public function collect_alerts() {
		if ( ! $this->should_run() ) {
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

		// There is exactly one alert that can pop up: the Current Alert. The
		// Normal Alert is the board's resting state and never interrupts anyone.
		$alert = ACPS_Alerts_Status::current_alert();

		if ( ! $alert ) {
			return;
		}

		$passes = ACPS_Alerts_Failsafe::guard(
			array( 'ACPS_Alerts_Conditions', 'passes' ),
			array( $alert ),
			'frontend/conditions',
			false
		);

		$queue = $passes ? array( $alert ) : array();

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

		// A missing asset file must not produce a 404-ing <script> tag, and must
		// never stop the rest of the request. If the runtime is gone there is
		// nothing to drive the alert, so stand down for this request.
		if ( ! ACPS_Alerts_Failsafe::has_file( 'assets/js/alerts.js' ) ) {
			ACPS_Alerts_Failsafe::record( 'frontend/enqueue', 'alerts.js is missing; alerts suppressed for this request' );
			$this->queue = array();

			return;
		}

		if ( ACPS_Alerts_Failsafe::has_file( 'assets/css/alerts.css' ) ) {
			wp_enqueue_style( 'acps-alerts', ACPS_ALERTS_URL . 'assets/css/alerts.css', array(), ACPS_Alerts_Failsafe::asset_version( 'assets/css/alerts.css' ) );

			$custom_css = (string) ACPS_Alerts_Settings::get( 'custom_css' );
			$z_index    = (int) ACPS_Alerts_Settings::get( 'z_index' );

			wp_add_inline_style( 'acps-alerts', ':root{--acps-alert-z-index:' . $z_index . ';}' . $custom_css );
		}

		wp_enqueue_script( 'acps-alerts', ACPS_ALERTS_URL . 'assets/js/alerts.js', array(), ACPS_Alerts_Failsafe::asset_version( 'assets/js/alerts.js' ), true );

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

		// Let Beaver Builder load the CSS and JS each popup needs, here rather
		// than at render time: a stylesheet asked for in the footer arrives
		// after the browser has already painted the popup unstyled.
		//
		// The Current Alert's styling belongs to the STATUS PAGE, because that
		// is where its popup is built. Every other alert brings its own.
		if ( class_exists( 'ACPS_Alerts_Popup_Source' ) && ACPS_Alerts_Popup_Source::available() ) {
			ACPS_Alerts_Failsafe::guard(
				array( 'ACPS_Alerts_Popup_Source', 'enqueue_assets' ),
				array(),
				'frontend/bb-popup-assets'
			);
		}

		// This reaches into another plugin's internals, so each call is guarded
		// separately: if Beaver Builder throws, the alert still renders with the
		// theme's own styling rather than taking the page down.
		foreach ( $this->queue as $alert ) {
			if ( ! method_exists( 'FLBuilder', 'enqueue_layout_styles_scripts_by_id' ) ) {
				continue;
			}

			ACPS_Alerts_Failsafe::guard(
				array( 'FLBuilder', 'enqueue_layout_styles_scripts_by_id' ),
				array( $alert->get_id() ),
				'frontend/bb-assets'
			);
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
				// Two halves, because either can move on its own: the revision
				// counter catches a settings-only edit, and the modified time
				// catches content edited outside the plugin's own screens.
				'version'       => $alert->revision() . '-' . (string) get_post_modified_time( 'U', true, $alert->get_id() ),
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

		// Rendering a page-builder layout is the most memory-hungry thing this
		// plugin does. If the request is already close to the limit, drop the
		// alert rather than risk exhausting memory in the footer.
		if ( ! ACPS_Alerts_Failsafe::memory_ok() ) {
			ACPS_Alerts_Failsafe::record( 'frontend/render', 'skipped: not enough memory headroom' );

			return;
		}

		foreach ( $this->queue as $alert ) {
			$id = $alert->get_id();

			// Some themes call wp_footer more than once, and a page can hold the
			// same alert twice over. Print each one at most once per request:
			// two copies of the same popup is worse than none.
			if ( isset( $this->rendered[ $id ] ) ) {
				continue;
			}

			$this->rendered[ $id ] = true;

			// Captured, not echoed directly: if an alert throws halfway through
			// its own markup the partial fragment is discarded instead of
			// landing in the page with unclosed tags.
			$html = ACPS_Alerts_Failsafe::capture(
				array( $this, 'render_alert' ),
				array( $alert ),
				'frontend/render-one'
			);

			echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped inside render_alert().
		}
	}

	/**
	 * Prints one alert.
	 *
	 * Public because the failsafe calls it through call_user_func_array from
	 * outside this class, to capture its output.
	 *
	 * @param ACPS_Alerts_Alert $alert Alert to render.
	 * @return void
	 */
	public function render_alert( ACPS_Alerts_Alert $alert ) {
		$id       = $alert->get_id();
		$label    = $alert->get( 'aria_label' );
		$label    = '' !== $label ? $label : $alert->get_title();
		$position = $alert->get( 'position' );
		$width    = (int) $alert->get( 'width' );

		// How urgent the popup looks follows the status level, so the popup and
		// the status board can never disagree about how serious this is.
		$level    = class_exists( 'ACPS_Alerts_Status' )
			? ACPS_Alerts_Status::level( $alert->get( 'status_level' ) )
			: array();
		$severity = isset( $level['severity'] ) ? (string) $level['severity'] : 'info';

		// Whether the body of this alert is a popup somebody designed in Beaver
		// Builder, rather than plain text this plugin is laying out. Worked out
		// before the classes, because it decides one of them.
		$built = self::has_builder_layout( $id )
			|| ( class_exists( 'ACPS_Alerts_Popup_Source' ) && ACPS_Alerts_Popup_Source::available() );

		// Resolved before anything is printed, because whether the alert needs a
		// close button of its own — and how the dialog is sized — both depend on
		// whether the body brought one.
		$body = $this->get_popup_content( $id );

		$own_close = class_exists( 'ACPS_Alerts_Popup_Source' )
			&& ACPS_Alerts_Popup_Source::has_close_button( $body );

		$classes = array(
			'acps-alert',
			'acps-alert--' . $severity,
			'acps-alert--' . $position,
		);

		// A designed popup is the box. Ours would otherwise sit around it as a
		// second white panel with its own corners, shadow and width, and the
		// alert would not look like the thing that was built.
		if ( $built ) {
			$classes[] = 'acps-alert--built';
		}

		// Said out loud, because the stylesheet has to treat the two cases
		// differently: a dialog that spans the page gives the popup's
		// percentage width a basis, but puts OUR close button in the corner of
		// the window rather than the corner of the popup.
		if ( $own_close ) {
			$classes[] = 'acps-alert--own-close';
		}

		if ( ! $alert->get( 'show_overlay' ) ) {
			$classes[] = 'acps-alert--no-overlay';
		}

		// Colour the popup's stripe to match the status level, so the popup and
		// The popup chrome no longer changes colour by status. Status colour is
		// shown by the [statusdot] and [schoolstatus] shortcodes now, placed in
		// the content, so the box itself stays the site's own neutral look.
		$stripe = '';

		// The badge, the heading and the link below it are only drawn for an
		// alert whose body is plain content. A popup built in Beaver Builder —
		// whether that is a layout on this post or the Popup module on the
		// status page — already has its own heading and buttons, and adding
		// ours on top would give it two of each.
		$furniture = ! $built;

		$cta_text = trim( (string) $alert->get( 'cta_text' ) );
		$cta_url  = trim( (string) $alert->get( 'cta_url' ) );

		// An empty link box means "the status page", which is where a visitor
		// wants to go from an alert nine times in ten.
		if ( '' !== $cta_text && '' === $cta_url && class_exists( 'ACPS_Alerts_Status' ) ) {
			$board = ACPS_Alerts_Status::board_page();

			if ( $board ) {
				$cta_url = (string) get_permalink( $board );
			}
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
			<div class="acps-alert__dialog"<?php echo $built ? '' : ' style="max-width:' . esc_attr( $width ) . 'px' . ( $stripe ? ';border-top-color:' . esc_attr( $stripe ) : '' ) . '"'; ?>>
				<?php if ( $alert->get( 'dismissible' ) && ! $own_close ) : ?>
					<button type="button" class="acps-alert__close" data-acps-close aria-label="<?php esc_attr_e( 'Close alert', 'acps-alert-popups' ); ?>">
						<span aria-hidden="true">&times;</span>
					</button>
				<?php endif; ?>
				<div class="acps-alert__content">
					<?php if ( $furniture ) : ?>
						<?php if ( $alert->get( 'show_icon' ) ) : ?>
							<?php echo ACPS_Alerts_Status::level_icon( $alert->get( 'status_level' ), $alert->get( 'icon_size' ), '#1b2f5e' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped at source. ?>
						<?php endif; ?>

						<?php if ( $alert->get( 'show_word' ) && '' !== (string) $level['banner'] ) : ?>
							<p class="acps-alert__level"<?php echo $stripe ? ' style="color:' . esc_attr( $stripe ) . '"' : ''; ?>>
								<?php echo esc_html( $level['banner'] ); ?>
								<?php if ( '' !== (string) $level['directive'] ) : ?>
									<span class="acps-alert__directive"><?php echo esc_html( wp_strip_all_tags( $level['directive'] ) ); ?></span>
								<?php endif; ?>
							</p>
						<?php endif; ?>

						<h2 class="acps-alert__heading"><?php echo esc_html( $alert->get_title() ); ?></h2>
					<?php endif; ?>

					<?php echo $body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Rendered page-builder layout. ?>

					<?php if ( $furniture && '' !== $cta_text && '' !== $cta_url ) : ?>
						<p class="acps-alert__cta">
							<a href="<?php echo esc_url( $cta_url ); ?>"<?php echo $stripe ? ' style="color:' . esc_attr( $stripe ) . '"' : ''; ?>>
								<?php echo esc_html( $cta_text ); ?>
								<span class="acps-alert__cta-arrow" aria-hidden="true">&rarr;</span>
							</a>
						</p>
					<?php endif; ?>
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
		$post_id = (int) $post_id;

		// Re-entry guard. Rendering runs the_content, and anything hooked there
		// could reach back into this method; rendering the same popup inside
		// itself would duplicate it and could loop.
		static $rendering = array();

		if ( isset( $rendering[ $post_id ] ) ) {
			return '';
		}

		$rendering[ $post_id ] = true;

		try {
			$html = $this->resolve_popup_content( $post_id );
		} finally {
			unset( $rendering[ $post_id ] );
		}

		return $html;
	}

	/**
	 * Picks exactly one source for a popup's body.
	 *
	 * This must never combine the two. Beaver Builder hooks its layout renderer
	 * onto `the_content`, so asking it to render a post that also has editor
	 * content returns the layout *and* that content — which shows up as the
	 * whole popup appearing twice, once builder-styled and once theme-styled.
	 *
	 * So: if the post has a builder layout, render only that, through Beaver
	 * Builder's own embed shortcode (the supported way to put one layout inside
	 * another page). Otherwise render only the editor content.
	 *
	 * @param int $post_id Popup post ID.
	 * @return string
	 */
	protected function resolve_popup_content( $post_id ) {
		$is_current = class_exists( 'ACPS_Alerts_Post_Type' )
			&& ACPS_Alerts_Post_Type::ROLE_CURRENT === ACPS_Alerts_Post_Type::role_of( $post_id );

		// The Current Alert IS the Beaver Builder popup sitting on the status
		// page. Take that, rather than drawing anything of our own.
		if ( $is_current && class_exists( 'ACPS_Alerts_Popup_Source' ) ) {
			$popup = ACPS_Alerts_Failsafe::guard(
				array( 'ACPS_Alerts_Popup_Source', 'render' ),
				array(),
				'frontend/bb-popup',
				''
			);

			if ( '' !== trim( (string) $popup ) ) {
				return (string) $popup;
			}
		}

		// Falling through here means there is no popup module on the status page
		// yet, or Beaver Builder could not render it. The module's own heading
		// and text stand in so an alert still reaches people.
		if ( ! $is_current && self::has_builder_layout( $post_id ) && shortcode_exists( 'fl_builder_insert_layout' ) ) {
			$html = ACPS_Alerts_Failsafe::guard(
				'do_shortcode',
				array( '[fl_builder_insert_layout id="' . $post_id . '"]' ),
				'frontend/bb-render',
				null
			);

			if ( null !== $html && '' !== trim( (string) $html ) ) {
				return (string) $html;
			}
		}

		$post = get_post( $post_id );

		if ( ! $post ) {
			return '';
		}

		// No builder layout: the editor content is the whole popup. the_content
		// runs every other plugin's filters, so a throw in one of those is
		// caught here rather than in the middle of the footer.
		$filtered = ACPS_Alerts_Failsafe::guard(
			'apply_filters',
			array( 'the_content', $post->post_content ),
			'frontend/the-content',
			null
		);

		return (string) ( null !== $filtered ? $filtered : wp_kses_post( $post->post_content ) );
	}

	/**
	 * Whether a post has a Beaver Builder layout that should be rendered.
	 *
	 * Checks that the builder is switched on for the post *and* that it has
	 * actual layout data, because an empty enabled layout would otherwise
	 * render nothing and hide the editor content behind it.
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	public static function has_builder_layout( $post_id ) {
		if ( ! class_exists( 'FLBuilder' ) ) {
			return false;
		}

		$post_id = (int) $post_id;

		// Read the stored per-post flags rather than asking Beaver Builder.
		// FLBuilderModel::is_builder_enabled() inspects the *current* post in
		// some versions, and in the footer that is the page being viewed, not
		// the popup — which would wrongly veto a layout that really exists.
		$enabled = get_post_meta( $post_id, '_fl_builder_enabled', true );

		if ( '' !== $enabled && ! $enabled ) {
			return false;
		}

		return ! empty( get_post_meta( $post_id, '_fl_builder_data', true ) );
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
