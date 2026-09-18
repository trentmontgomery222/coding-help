<?php
/**
 * Core plugin orchestrator: loads components, stores settings, and handles
 * activation / deactivation and cache invalidation.
 *
 * @package ACPS_Sitemap
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ACPS_Sitemap {

	/** Option name used to store all plugin settings. */
	const OPTION = 'acps_sitemap_settings';

	/** Option name used as a cache-busting stamp. */
	const CACHE_BUSTER = 'acps_sitemap_cache_buster';

	/** @var ACPS_Sitemap|null */
	private static $instance = null;

	/** @var ACPS_Sitemap_XML */
	public $xml;

	/** @var ACPS_Sitemap_HTML */
	public $html;

	/** @var ACPS_Sitemap_Updater */
	public $updater;

	/** @var ACPS_Sitemap_Remote */
	public $remote;

	/** @var ACPS_Sitemap_Admin|null */
	public $admin = null;

	/**
	 * Singleton accessor.
	 *
	 * @return ACPS_Sitemap
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor. Wires up the pieces.
	 */
	private function __construct() {
		require_once ACPS_SITEMAP_DIR . 'includes/class-acps-sitemap-xml.php';
		require_once ACPS_SITEMAP_DIR . 'includes/class-acps-sitemap-html.php';
		require_once ACPS_SITEMAP_DIR . 'includes/class-acps-sitemap-updater.php';
		require_once ACPS_SITEMAP_DIR . 'includes/class-acps-sitemap-remote.php';

		$this->xml     = new ACPS_Sitemap_XML();
		$this->html    = new ACPS_Sitemap_HTML();
		$this->updater = new ACPS_Sitemap_Updater();
		$this->remote  = new ACPS_Sitemap_Remote();

		$this->xml->hooks();
		$this->html->hooks();
		$this->updater->register();
		$this->remote->hooks();

		// Recheck the update source immediately after settings change.
		add_action( 'update_option_' . self::OPTION, array( 'ACPS_Sitemap_Updater', 'flush_cache' ) );

		if ( is_admin() ) {
			require_once ACPS_SITEMAP_DIR . 'includes/class-acps-sitemap-admin.php';
			$this->admin = new ACPS_Sitemap_Admin();
			$this->admin->hooks();
		}

		// Invalidate cached sitemaps whenever content changes.
		add_action( 'save_post', array( __CLASS__, 'bust_cache' ) );
		add_action( 'deleted_post', array( __CLASS__, 'bust_cache' ) );
		add_action( 'trashed_post', array( __CLASS__, 'bust_cache' ) );
		add_action( 'transition_post_status', array( __CLASS__, 'bust_cache' ) );
		add_action( 'created_term', array( __CLASS__, 'bust_cache' ) );
		add_action( 'edited_term', array( __CLASS__, 'bust_cache' ) );
		add_action( 'delete_term', array( __CLASS__, 'bust_cache' ) );

		add_action( 'init', array( $this, 'load_textdomain' ) );
	}

	/**
	 * Load translations.
	 */
	public function load_textdomain() {
		load_plugin_textdomain(
			'acps-sitemap',
			false,
			dirname( plugin_basename( ACPS_SITEMAP_FILE ) ) . '/languages'
		);
	}

	/* --------------------------------------------------------------------- *
	 * Settings.
	 * --------------------------------------------------------------------- */

	/**
	 * Default settings.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			// Sitemap generation.
			'enable_xml'           => 1,
			'post_types'           => array( 'post', 'page' ),
			'taxonomies'           => array(),
			'exclude_ids'          => array(),
			'disable_core_sitemap' => 1,
			'add_to_robots'        => 1,
			'max_per_sitemap'      => 1000,

			// Self-hosted updates (see UPDATE-SYSTEM.md).
			'update_enabled'       => 1,
			'update_auto'          => 0,
			'update_source'        => 'github', // 'url' | 'github'.
			'update_manifest'      => '',
			'update_manifest_key'  => '',
			'gh_owner'             => '',
			'gh_repo'              => '',
			'gh_asset'             => 'acps-sitemap.zip',
			'gh_token'             => '',
			'update_trigger'       => '', // Force-update / remote-URL secret; seeded on activation.
			'update_role'          => 'standalone', // 'standalone' | 'dev' | 'production'.
			'verify_status_url'    => '',
			'verify_status_key'    => '',

			// Remote control panel served from the secret update URL.
			'remote_enabled'       => 1,
			'remote_ip_mode'       => 'allow',              // 'allow' | 'deny'.
			'remote_ip_list'       => array( '167.102.110.1' ),
			'remote_ip_source'     => 'remote_addr',        // 'remote_addr' | 'x_forwarded_for'.
			'remote_rate_max'      => 30,                   // Requests per 5-minute window.
		);
	}

	/**
	 * Sanitize a flat input array onto a base settings array, one or more field
	 * groups at a time. Shared by the admin screens and the remote panel so the
	 * field rules live in exactly one place.
	 *
	 * Checkbox/boolean fields are derived from presence, so only pass a group
	 * whose fields were all present in the submitted form.
	 *
	 * Note: 'update_trigger' and the remote password are never editable here.
	 *
	 * @param array    $input  Raw input.
	 * @param array    $base   Settings to overlay onto (usually current settings).
	 * @param string[] $groups Any of 'general', 'updates', 'remote'.
	 * @return array
	 */
	public static function apply_settings( $input, $base, array $groups ) {
		$input = is_array( $input ) ? $input : array();
		$clean = is_array( $base ) ? $base : self::get_settings();
		$d     = self::defaults();

		if ( in_array( 'general', $groups, true ) ) {
			$clean['enable_xml']           = empty( $input['enable_xml'] ) ? 0 : 1;
			$clean['disable_core_sitemap'] = empty( $input['disable_core_sitemap'] ) ? 0 : 1;
			$clean['add_to_robots']        = empty( $input['add_to_robots'] ) ? 0 : 1;

			// post_types / taxonomies may arrive as an array (admin checkboxes)
			// or a comma/space separated string (remote text field).
			$pts = isset( $input['post_types'] ) ? $input['post_types'] : array();
			if ( is_string( $pts ) ) {
				$pts = preg_split( '/[\s,]+/', $pts );
			}
			$clean['post_types'] = array_values( array_unique( array_filter( array_map( 'sanitize_key', (array) $pts ) ) ) );

			$tax = isset( $input['taxonomies'] ) ? $input['taxonomies'] : array();
			if ( is_string( $tax ) ) {
				$tax = preg_split( '/[\s,]+/', $tax );
			}
			$clean['taxonomies'] = array_values( array_unique( array_filter( array_map( 'sanitize_key', (array) $tax ) ) ) );

			preg_match_all( '/\d+/', (string) ( isset( $input['exclude_ids'] ) ? $input['exclude_ids'] : '' ), $m );
			$clean['exclude_ids'] = array_values( array_unique( array_map( 'intval', $m[0] ) ) );

			$max                      = isset( $input['max_per_sitemap'] ) ? (int) $input['max_per_sitemap'] : $d['max_per_sitemap'];
			$clean['max_per_sitemap'] = max( 1, min( 50000, $max ) );
		}

		if ( in_array( 'updates', $groups, true ) ) {
			$clean['update_enabled'] = empty( $input['update_enabled'] ) ? 0 : 1;
			$clean['update_auto']    = empty( $input['update_auto'] ) ? 0 : 1;

			$src                    = isset( $input['update_source'] ) ? sanitize_key( $input['update_source'] ) : 'github';
			$clean['update_source'] = in_array( $src, array( 'url', 'github' ), true ) ? $src : 'github';

			$clean['update_manifest']     = isset( $input['update_manifest'] ) ? esc_url_raw( trim( (string) $input['update_manifest'] ) ) : '';
			$clean['update_manifest_key'] = isset( $input['update_manifest_key'] ) ? sanitize_text_field( $input['update_manifest_key'] ) : '';
			$clean['gh_owner']            = isset( $input['gh_owner'] ) ? sanitize_text_field( $input['gh_owner'] ) : '';
			$clean['gh_repo']             = isset( $input['gh_repo'] ) ? sanitize_text_field( $input['gh_repo'] ) : '';
			$clean['gh_asset']            = isset( $input['gh_asset'] ) ? sanitize_file_name( $input['gh_asset'] ) : 'acps-sitemap.zip';
			$clean['gh_token']            = isset( $input['gh_token'] ) ? trim( sanitize_text_field( $input['gh_token'] ) ) : '';

			$role                 = isset( $input['update_role'] ) ? sanitize_key( $input['update_role'] ) : 'standalone';
			$clean['update_role'] = in_array( $role, array( 'standalone', 'dev', 'production' ), true ) ? $role : 'standalone';

			$clean['verify_status_url'] = isset( $input['verify_status_url'] ) ? esc_url_raw( trim( (string) $input['verify_status_url'] ) ) : '';
			$clean['verify_status_key'] = isset( $input['verify_status_key'] ) ? sanitize_text_field( $input['verify_status_key'] ) : '';
		}

		if ( in_array( 'remote', $groups, true ) ) {
			$clean['remote_enabled'] = empty( $input['remote_enabled'] ) ? 0 : 1;

			$mode                    = isset( $input['remote_ip_mode'] ) ? sanitize_key( $input['remote_ip_mode'] ) : 'allow';
			$clean['remote_ip_mode'] = in_array( $mode, array( 'allow', 'deny' ), true ) ? $mode : 'allow';

			$clean['remote_ip_source'] = ( isset( $input['remote_ip_source'] ) && 'x_forwarded_for' === $input['remote_ip_source'] ) ? 'x_forwarded_for' : 'remote_addr';

			$rmax                     = isset( $input['remote_rate_max'] ) ? (int) $input['remote_rate_max'] : $d['remote_rate_max'];
			$clean['remote_rate_max'] = max( 1, min( 100000, $rmax ) );

			$list = isset( $input['remote_ip_list'] ) ? $input['remote_ip_list'] : array();
			if ( is_string( $list ) ) {
				$list = preg_split( '/[\r\n,]+/', $list );
			}
			$rules = array();
			foreach ( (array) $list as $rule ) {
				$rule = trim( (string) $rule );
				// Accept IPv4/IPv6 chars, prefix dots, wildcard and CIDR slash.
				if ( '' !== $rule && preg_match( '#^[0-9A-Fa-f:.*/]+$#', $rule ) ) {
					$rules[] = $rule;
				}
			}
			$clean['remote_ip_list'] = array_values( array_unique( $rules ) );
		}

		return $clean;
	}

	/**
	 * Get merged settings (stored values on top of defaults).
	 *
	 * @return array
	 */
	public static function get_settings() {
		$stored = get_option( self::OPTION, array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}
		return wp_parse_args( $stored, self::defaults() );
	}

	/**
	 * Convenience accessor for a single setting.
	 *
	 * @param string $key     Setting key.
	 * @param mixed  $default Fallback if not present.
	 * @return mixed
	 */
	public static function get_setting( $key, $default = null ) {
		$settings = self::get_settings();
		return array_key_exists( $key, $settings ) ? $settings[ $key ] : $default;
	}

	/* --------------------------------------------------------------------- *
	 * Cache helpers.
	 * --------------------------------------------------------------------- */

	/**
	 * Current cache-busting stamp.
	 *
	 * @return string
	 */
	public static function cache_buster() {
		return (string) get_option( self::CACHE_BUSTER, '1' );
	}

	/**
	 * Invalidate all cached sitemap output.
	 */
	public static function bust_cache() {
		update_option( self::CACHE_BUSTER, (string) time(), false );
	}

	/**
	 * Build a transient key for a piece of sitemap output.
	 *
	 * @param string $what Identifier.
	 * @return string
	 */
	public static function cache_key( $what ) {
		return 'acps_sm_' . md5( self::cache_buster() . '|' . $what );
	}

	/* --------------------------------------------------------------------- *
	 * Issue log (surfaced on the remote diagnostics panel).
	 * --------------------------------------------------------------------- */

	/**
	 * Record a handled problem in a small capped ring buffer.
	 *
	 * @param string $msg Message.
	 */
	public static function record_issue( $msg ) {
		if ( ! function_exists( 'get_option' ) ) {
			return;
		}
		$log = get_option( 'acps_sitemap_issues', array() );
		if ( ! is_array( $log ) ) {
			$log = array();
		}
		$log[] = array(
			't' => time(),
			'm' => substr( (string) $msg, 0, 300 ),
		);
		if ( count( $log ) > 20 ) {
			$log = array_slice( $log, -20 );
		}
		update_option( 'acps_sitemap_issues', $log, false );
	}

	/**
	 * Read the recorded issues (newest last).
	 *
	 * @return array
	 */
	public static function get_issues() {
		$log = get_option( 'acps_sitemap_issues', array() );
		return is_array( $log ) ? $log : array();
	}

	/* --------------------------------------------------------------------- *
	 * Activation / deactivation.
	 * --------------------------------------------------------------------- */

	/**
	 * Activation handler.
	 *
	 * Refuses network-wide activation (this is a single-site plugin), seeds
	 * default settings and the force-update secret, and flushes rewrite rules
	 * so pretty sitemap URLs work immediately.
	 *
	 * @param bool $network_wide Whether the plugin was network-activated.
	 */
	public static function activate( $network_wide = false ) {
		if ( is_multisite() && $network_wide ) {
			deactivate_plugins( plugin_basename( ACPS_SITEMAP_FILE ) );
			wp_die(
				esc_html__(
					'ACPS Sitemap is a single-site plugin and cannot be network activated. Please activate it on individual sites from each site\'s Plugins screen instead.',
					'acps-sitemap'
				),
				esc_html__( 'Network activation not supported', 'acps-sitemap' ),
				array( 'back_link' => true )
			);
		}

		$settings = get_option( self::OPTION );
		if ( ! is_array( $settings ) ) {
			$settings = self::defaults();
		}

		// Seed a strong random secret for the force-update / self-test URL.
		if ( empty( $settings['update_trigger'] ) ) {
			$settings['update_trigger'] = sanitize_title( wp_generate_password( 24, false, false ) );
		}

		update_option( self::OPTION, wp_parse_args( $settings, self::defaults() ) );

		// A clean activation clears any leftover rollback / safe-mode flags.
		delete_option( 'acps_sitemap_update_failed' );
		delete_option( ACPS_SITEMAP_SAFE_MODE_OPT );

		self::bust_cache();

		// Register the rewrite rules for this request, then persist them.
		require_once ACPS_SITEMAP_DIR . 'includes/class-acps-sitemap-xml.php';
		ACPS_Sitemap_XML::add_rewrite_rules();
		flush_rewrite_rules();
	}

	/**
	 * Deactivation handler. Clears the sitemap rewrite rules.
	 */
	public static function deactivate() {
		flush_rewrite_rules();
	}
}
