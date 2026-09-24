<?php
/**
 * Beaver Builder integration: the Alert Trigger module.
 *
 * Lets an editor drop a button into any Beaver Builder row, page or popup that
 * opens one of the managed alerts.
 *
 * @package ACPS_Alert_Popups
 */

defined( 'ABSPATH' ) || exit;

/**
 * Registers the plugin's Beaver Builder module.
 */
class ACPS_Alerts_Builder {

	/**
	 * Hooks the module registration up.
	 *
	 * @return void
	 */
	public function init() {
		ACPS_Alerts_Failsafe::action( 'init', array( $this, 'register_module' ), 'builder/register', 20 );
		ACPS_Alerts_Failsafe::action( 'wp_enqueue_scripts', array( $this, 'enqueue_board_styles' ), 'builder/board-css' );
	}

	/**
	 * Loads the status board styling.
	 *
	 * Registered always and enqueued only where a board is on the page, so a
	 * status page gets the styling and every other page carries nothing.
	 *
	 * @return void
	 */
	public function enqueue_board_styles() {
		if ( ! ACPS_Alerts_Failsafe::has_file( 'assets/css/board.css' ) ) {
			return;
		}

		wp_register_style( 'acps-alerts-board', ACPS_ALERTS_URL . 'assets/css/board.css', array(), ACPS_Alerts_Failsafe::asset_version( 'assets/css/board.css' ) );

		// The module asks for this by name when it renders; registering here and
		// enqueuing on demand keeps it off pages with no board on them.
		if ( self::board_on_page() ) {
			wp_enqueue_style( 'acps-alerts-board' );
		}
	}

	/**
	 * Whether the current post's Beaver Builder layout carries the status page.
	 *
	 * True for either of the two modules that make up the status page: the
	 * board, and the Current Alert popup that is edited beside it. The popup
	 * must never open on the page it is edited on, so this is what the front
	 * end asks before deciding to render.
	 *
	 * @return bool
	 */
	public static function board_on_page() {
		if ( ! is_singular() ) {
			return false;
		}

		$post_id = get_queried_object_id();

		if ( ! $post_id ) {
			return false;
		}

		// Beaver Builder stores its layout as post meta; a plain string search is
		// far cheaper than parsing the layout, and a false positive only costs
		// one small stylesheet.
		$data = get_post_meta( $post_id, '_fl_builder_data', true );

		if ( empty( $data ) ) {
			return false;
		}

		$json = (string) wp_json_encode( $data );

		$needles = array(
			'status-board',
			'ACPS_Status_Board_Module',
			'alert-popup',
			'ACPS_Alert_Popup_Module',
		);

		foreach ( $needles as $needle ) {
			if ( false !== strpos( $json, $needle ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Loads the module when Beaver Builder is available.
	 *
	 * Registering a module depends on another plugin's class staying the shape
	 * we expect across versions, so every precondition is checked and the whole
	 * thing is skipped rather than risked if anything is missing.
	 *
	 * @return void
	 */
	public function register_module() {
		if ( ! class_exists( 'FLBuilderModule' ) || ! class_exists( 'FLBuilder' ) ) {
			return;
		}

		if ( ! method_exists( 'FLBuilder', 'register_module' ) ) {
			return;
		}

		// Optional files: a missing module costs that module, not the site.
		$modules = array(
			'ACPS_Alert_Trigger_Module' => 'modules/alert-trigger/alert-trigger.php',
			'ACPS_Status_Board_Module'  => 'modules/status-board/status-board.php',
			'ACPS_Alert_Popup_Module'   => 'modules/alert-popup/alert-popup.php',
			'ACPS_Status_Dot_Module'    => 'modules/status-dot/status-dot.php',
		);

		foreach ( $modules as $class => $rel ) {
			if ( class_exists( $class ) ) {
				continue; // Already loaded.
			}

			$file = ACPS_ALERTS_DIR . $rel;

			if ( is_readable( $file ) ) {
				require_once $file;
			}
		}
	}

	/**
	 * Alert choices for module and field dropdowns.
	 *
	 * @return array Popup ID => title.
	 */
	public static function get_alert_choices() {
		$choices = array( '' => __( 'Choose an alert&hellip;', 'acps-alert-popups' ) );

		foreach ( ACPS_Alerts_Source::get_popups() as $post ) {
			$alert                        = new ACPS_Alerts_Alert( $post );
			$choices[ (string) $post->ID ] = $alert->get_title();
		}

		return $choices;
	}
}
