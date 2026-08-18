<?php
/**
 * Beaver Builder module: Staff Directory.
 *
 * A thin wrapper around CAYDENDIR_sd_render() so the directory can be dropped
 * onto a page from the Beaver Builder content panel instead of only via the
 * [CAYDENDIR_staff_directory] shortcode. Registered from the main plugin file
 * on `init`, only when Beaver Builder is active.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'FLBuilderModule' ) ) {
	return;
}

if ( ! class_exists( 'CaydenStaffDirectoryModule' ) ) {

	class CaydenStaffDirectoryModule extends FLBuilderModule {

		public function __construct() {
			parent::__construct( array(
				'name'            => __( 'Staff Directory', 'cayden-staff-directory' ),
				'description'     => __( 'Searchable staff directory with the plugin\'s data, layout and styling.', 'cayden-staff-directory' ),
				// Top-level group ("tab") in the Beaver Builder content panel.
				// Shared across every Cayden plugin via the CAYDENDIR_BB_GROUP
				// constant, so they all appear together under "Caydens Plugins".
				// It is a plain, untranslated string on purpose — the value is a
				// grouping key, and translating it would split the group.
				'group'           => defined( 'CAYDENDIR_BB_GROUP' ) ? CAYDENDIR_BB_GROUP : 'Caydens Plugins',
				'category'        => __( 'Staff Directory', 'cayden-staff-directory' ),
				'dir'             => CAYDENDIR_SD_DIR . 'modules/cayden-staff-directory/',
				'url'             => CAYDENDIR_SD_URL . 'modules/cayden-staff-directory/',
				'editor_export'   => true,
				'enabled'         => true,
				'partial_refresh' => true,
			) );
		}

		/**
		 * Make sure the directory's assets are available in the builder and on
		 * the front end. The stylesheet is inline-only (added at render time),
		 * so enqueuing the handles here is enough.
		 */
		public function enqueue_scripts() {
			if ( wp_style_is( 'CAYDENDIR-sd', 'registered' ) ) {
				wp_enqueue_style( 'CAYDENDIR-sd' );
			}
			if ( wp_script_is( 'CAYDENDIR-sd', 'registered' ) ) {
				wp_enqueue_script( 'CAYDENDIR-sd' );
			}
		}
	}

	FLBuilder::register_module( 'CaydenStaffDirectoryModule', array(
		'general' => array(
			'title'    => __( 'Directory', 'cayden-staff-directory' ),
			'sections' => array(
				'general' => array(
					'title'  => '',
					'fields' => array(
						'heading' => array(
							'type'    => 'text',
							'label'   => __( 'Heading', 'cayden-staff-directory' ),
							'default' => 'Staff Directory',
						),
						'layout'  => array(
							'type'    => 'select',
							'label'   => __( 'Layout', 'cayden-staff-directory' ),
							'default' => '',
							'options' => array(
								''      => __( 'Use plugin setting', 'cayden-staff-directory' ),
								'table' => __( 'Table (rows)', 'cayden-staff-directory' ),
								'cards' => __( 'Cards (grid)', 'cayden-staff-directory' ),
							),
						),
						'match'   => array(
							'type'    => 'select',
							'label'   => __( 'Tag match', 'cayden-staff-directory' ),
							'default' => 'any',
							'options' => array(
								'any' => __( 'Any selected tag', 'cayden-staff-directory' ),
								'all' => __( 'All selected tags', 'cayden-staff-directory' ),
							),
						),
					),
				),
			),
		),
	) );
}
