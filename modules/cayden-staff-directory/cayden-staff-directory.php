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
				// Stays in Beaver Builder's default (Standard) module group, but
				// sits under a shared "Caydens Plugins" category so every Cayden
				// plugin's module groups together there. The category is a plain,
				// untranslated string on purpose — it is the grouping key, and
				// translating it would split the category.
				'category'        => defined( 'CAYDENDIR_BB_CATEGORY' ) ? CAYDENDIR_BB_CATEGORY : 'Caydens Plugins',
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
