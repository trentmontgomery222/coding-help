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

		$this->xml  = new ACPS_Sitemap_XML();
		$this->html = new ACPS_Sitemap_HTML();

		$this->xml->hooks();
		$this->html->hooks();

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
			'enable_xml'           => 1,
			'public_only'          => 1,
			'post_types'           => array( 'post', 'page' ),
			'taxonomies'           => array(),
			'exclude_ids'          => array(),
			'disable_core_sitemap' => 1,
			'add_to_robots'        => 1,
			'max_per_sitemap'      => 1000,
		);
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
	 * Public-content filtering.
	 * --------------------------------------------------------------------- */

	/**
	 * IDs of posts of a given type that should be left out of the sitemap
	 * because they are not public: password-protected, or flagged "noindex"
	 * by a common SEO plugin (Yoast SEO, Rank Math, All in One SEO,
	 * SEOPress). Returns an empty array when the "public only" setting is
	 * off, so nothing extra is excluded.
	 *
	 * @param string $post_type Post type.
	 * @return int[]
	 */
	public static function non_public_post_ids( $post_type ) {
		if ( ! self::get_setting( 'public_only', 1 ) ) {
			return array();
		}

		$password_protected = get_posts(
			array(
				'post_type'        => $post_type,
				'post_status'      => 'publish',
				'has_password'     => true,
				'posts_per_page'   => -1,
				'fields'           => 'ids',
				'suppress_filters' => false,
			)
		);

		$noindexed = get_posts(
			array(
				'post_type'        => $post_type,
				'post_status'      => 'publish',
				'posts_per_page'   => -1,
				'fields'           => 'ids',
				'suppress_filters' => false,
				'meta_query'       => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					'relation' => 'OR',
					array(
						'key'   => '_yoast_wpseo_meta-robots-noindex',
						'value' => '1',
					),
					array(
						'key'   => '_aioseo_noindex',
						'value' => '1',
					),
					array(
						'key'   => '_seopress_robots_noindex',
						'value' => 'yes',
					),
					array(
						'key'     => 'rank_math_robots',
						'value'   => 'noindex',
						'compare' => 'LIKE',
					),
				),
			)
		);

		return array_values( array_unique( array_map( 'intval', array_merge( $password_protected, $noindexed ) ) ) );
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
	 * Activation / deactivation.
	 * --------------------------------------------------------------------- */

	/**
	 * Activation handler.
	 *
	 * Refuses network-wide activation (this is a single-site plugin), seeds
	 * default settings, and flushes rewrite rules so pretty sitemap URLs work
	 * immediately.
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

		if ( false === get_option( self::OPTION, false ) ) {
			add_option( self::OPTION, self::defaults() );
		}

		self::bust_cache();

		// Register the rewrite rules for this request, then persist them.
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
