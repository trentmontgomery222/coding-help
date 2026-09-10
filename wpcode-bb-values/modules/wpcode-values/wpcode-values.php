<?php
/**
 * The Beaver Builder module class.
 *
 * The field schema lives in wpcodebbv_form() in the main plugin file;
 * this class only turns the saved settings into a shortcode.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/*
 * Only the parent check is meaningful here: PHP early-binds this class
 * at compile time whenever FLBuilderModule is already loaded, so a
 * "have I declared myself already?" test would be true on the first
 * load and useless. The require_once in wpcodebbv_register_module() is
 * what prevents a double load. When Beaver Builder is not loaded the
 * declaration cannot be early-bound, and this return stops it running,
 * so we never try to extend a class that is not there.
 */
if ( ! class_exists( 'FLBuilderModule' ) ) {
	return;
}

class WPCodeBBV_Module extends FLBuilderModule {

	public function __construct() {
		parent::__construct(
			array(
				'name'            => __( 'WPCode Values', 'wpcode-bb-values' ),
				'description'     => __( 'Runs a WPCode snippet with values you type here.', 'wpcode-bb-values' ),
				'category'        => __( 'WPCode', 'wpcode-bb-values' ),
				'dir'             => WPCODEBBV_DIR . 'modules/wpcode-values/',
				'url'             => WPCODEBBV_URL . 'modules/wpcode-values/',
				'partial_refresh' => true,
			)
		);
	}

	/**
	 * Called by Beaver Builder when this module's settings are saved.
	 *
	 * This is where a siteWide setting becomes site-wide: whatever was
	 * typed into its box is written to the shared store, so every module
	 * running this snippet renders the new value. Everything else stays
	 * on this module and is not touched here.
	 *
	 * @param object $settings The settings being saved.
	 * @return object
	 */
	public function update( $settings ) {
		try {
			$id = 0;

			foreach ( array( 'wpcode_id_manual', 'wpcode_id' ) as $key ) {
				if ( isset( $settings->{$key} ) && preg_match( '/(\\d+)/', (string) $settings->{$key}, $match ) ) {
					$id = (int) $match[1];
					break;
				}
			}

			if ( $id < 1 || ! function_exists( 'wpcodebbv_snippets' ) ) {
				return $settings;
			}

			$snippets = wpcodebbv_snippets();

			if ( ! isset( $snippets[ $id ]['settings'] ) || ! is_array( $snippets[ $id ]['settings'] ) ) {
				return $settings;
			}

			$reset = isset( $settings->reset_action ) ? (string) $settings->reset_action : '';

			// Asked for on the Setup tab, applied here, then forgotten -
			// so it reads as a one-off action rather than a mode the
			// module is stuck in.
			if ( 'wide' === $reset || 'both' === $reset ) {
				wpcodebbv_reset_globals( $id );
			}

			$settings->reset_action = '';
			$shared                 = wpcodebbv_globals_for( $id );

			foreach ( $snippets[ $id ]['settings'] as $path => $leaf ) {
				$key      = wpcodebbv_field_key( $id, $path );
				$wide_key = $key . '__wide';
				$snippet  = (string) $leaf['value'];
				$is_wide  = ! empty( $leaf['global'] ) || ! empty( $leaf['php'] );

				// The value in force everywhere before this save.
				$current = isset( $shared[ $path ] ) && '' !== $shared[ $path ]
					? (string) $shared[ $path ]
					: $snippet;

				if ( $is_wide && isset( $settings->{$wide_key} ) ) {
					$typed = trim( (string) $settings->{$wide_key} );

					// The box opened showing what is in force, so anything
					// else means somebody typed over it. Saving a module
					// nobody touched therefore leaves the shared value
					// alone rather than reverting it to what this page
					// last saw.
					if ( 'wide' !== $reset && 'both' !== $reset && '' !== $typed && $typed !== $current ) {
						wpcodebbv_set_global( $id, $path, $typed === $snippet ? '' : $typed );
					}

					// Never kept on the module: the shared store is the
					// only copy, so the box always opens showing what is
					// actually in force.
					unset( $settings->{$wide_key} );
				}

				if ( empty( $leaf['php'] ) && isset( $settings->{$key} ) ) {
					$typed = trim( (string) $settings->{$key} );

					// Re-read, since a site-wide edit above may have just
					// changed what this page is inheriting.
					$live = wpcodebbv_globals_for( $id );
					$now  = isset( $live[ $path ] ) && '' !== $live[ $path ] ? (string) $live[ $path ] : $snippet;

					if ( 'page' === $reset || 'both' === $reset || '' === $typed || $typed === $now ) {
						// Nothing to override: drop the copy so this page
						// follows the site-wide value or the snippet, and
						// keeps following it as those change.
						unset( $settings->{$key} );
					}
				}
			}
		} catch ( \Throwable $e ) {
			if ( function_exists( 'wpcodebbv_log' ) ) {
				wpcodebbv_log( 'could not store values: ' . $e->getMessage() );
			}
		}

		return $settings;
	}

	/**
	 * The WPCode snippet ID this module renders.
	 *
	 * @return int Zero when nothing is set.
	 */
	public function get_snippet_id() {
		$settings = is_object( $this->settings ) ? $this->settings : new stdClass();

		// The typed-in box wins when it is filled, since it is only ever
		// filled for a snippet the picker could not list.
		foreach ( array( 'wpcode_id_manual', 'wpcode_id' ) as $key ) {
			$raw = isset( $settings->{$key} ) ? trim( (string) $settings->{$key} ) : '';

			// Tolerate a pasted [wpcode id="123"] or a bare number.
			if ( '' !== $raw && preg_match( '/(\d+)/', $raw, $match ) ) {
				return (int) $match[1];
			}
		}

		return 0;
	}

	/**
	 * The shortcode this module runs.
	 *
	 * @return string Empty when no snippet ID is set.
	 */
	public function get_shortcode() {
		$id = $this->get_snippet_id();

		return $id > 0 ? '[wpcode id="' . $id . '"]' : '';
	}

	/**
	 * The overrides to write into the snippet's configurations array, as
	 * path => value.
	 *
	 * Every setting of the chosen snippet has a field, pre-filled with
	 * the value the snippet itself uses, so normally all of them are
	 * sent and the ones nobody touched simply write back what was
	 * already there. Clearing a box removes that override, which lets
	 * the snippet's own value through again.
	 *
	 * @return array<string, string>
	 */
	public function get_overrides() {
		$overrides = array();
		$settings  = is_object( $this->settings ) ? $this->settings : new stdClass();
		$id        = $this->get_snippet_id();

		if ( $id > 0 && function_exists( 'wpcodebbv_snippets' ) ) {
			$snippets = array();
			$globals  = array();

			try {
				$snippets = wpcodebbv_snippets();
				$globals  = wpcodebbv_globals_for( $id );
			} catch ( \Throwable $e ) {
				$snippets = array();
				$globals  = array();
			}

			if ( isset( $snippets[ $id ]['settings'] ) && is_array( $snippets[ $id ]['settings'] ) ) {
				foreach ( $snippets[ $id ]['settings'] as $path => $leaf ) {
					$snippet = (string) $leaf['value'];
					$shared  = isset( $globals[ $path ] ) ? (string) $globals[ $path ] : '';

					// What this setting is worth before this page speaks.
					$value = '' !== $shared ? $shared : $snippet;

					// A PHP value is read at runtime wherever the snippet
					// runs, including where no module is involved, so it
					// has no per-page layer to consult.
					if ( empty( $leaf['php'] ) ) {
						$key  = wpcodebbv_field_key( $id, $path );
						$page = isset( $settings->{$key} ) ? trim( (string) $settings->{$key} ) : '';

						if ( '' !== $page ) {
							$value = $page;
						}
					}

					if ( $value !== $snippet ) {
						$overrides[ $path ] = $value;
					}
				}
			}
		}

		if ( isset( $settings->custom_settings ) && function_exists( 'wpcodebbv_parse_lines' ) ) {
			foreach ( wpcodebbv_parse_lines( (string) $settings->custom_settings ) as $path => $value ) {
				$overrides[ $path ] = $value;
			}
		}

		return $overrides;
	}
}
