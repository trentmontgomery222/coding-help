<?php
/**
 * Provider registry + auto-detection.
 *
 * Detects which page builders are active and, for any given page, picks the
 * provider that actually built it. This is what lets the plugin "just work"
 * with whatever builder the site uses.
 *
 * @package mcm
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MCM_Providers {

	/** @var MCM_Provider[]|null */
	private static $all = null;

	/**
	 * All provider instances, in priority order. Beaver Builder and Elementor
	 * come before Gutenberg because a page built with them is also technically
	 * "blocks or classic" underneath, and we want the specific builder to win.
	 *
	 * @return MCM_Provider[]
	 */
	public static function all() {
		if ( null === self::$all ) {
			self::$all = array(
				new MCM_Provider_Beaver(),
				new MCM_Provider_Elementor(),
				new MCM_Provider_Gutenberg(),
			);
			/**
			 * Filter the provider list so add-ons can register more builders.
			 *
			 * @param MCM_Provider[] $providers
			 */
			self::$all = apply_filters( 'mcm_providers', self::$all );
		}
		return self::$all;
	}

	/**
	 * Providers whose builder is currently active.
	 *
	 * @return MCM_Provider[]
	 */
	public static function active() {
		return array_values(
			array_filter(
				self::all(),
				static function ( $p ) {
					return $p->is_active();
				}
			)
		);
	}

	/**
	 * Is any builder we understand active?
	 *
	 * @return bool
	 */
	public static function any_active() {
		return ! empty( self::active() );
	}

	/**
	 * The provider that built a given post (first match by priority), or null.
	 *
	 * @param int $post_id
	 * @return MCM_Provider|null
	 */
	public static function for_post( $post_id ) {
		$post_id = absint( $post_id );
		foreach ( self::active() as $p ) {
			if ( $p->handles_post( $post_id ) ) {
				return $p;
			}
		}
		return null;
	}

	/**
	 * A provider by key (regardless of active state).
	 *
	 * @param string $key
	 * @return MCM_Provider|null
	 */
	public static function get( $key ) {
		foreach ( self::all() as $p ) {
			if ( $p->key() === $key ) {
				return $p;
			}
		}
		return null;
	}

	/**
	 * De-duplicated union of every active provider's pages (for the admin
	 * "editable pages" picker), each tagged with which builder owns it.
	 *
	 * @return array<int,array> { id, title, status, builder }
	 */
	public static function all_pages() {
		$seen = array();
		$out  = array();
		foreach ( self::active() as $p ) {
			foreach ( $p->get_pages() as $post ) {
				if ( isset( $seen[ $post->ID ] ) ) {
					continue;
				}
				$seen[ $post->ID ] = true;
				$out[]             = array(
					'id'      => (int) $post->ID,
					'title'   => $post->post_title ? $post->post_title : ( '#' . $post->ID ),
					'status'  => $post->post_status,
					'builder' => $p->name(),
				);
			}
		}
		usort(
			$out,
			static function ( $a, $b ) {
				return strcasecmp( $a['title'], $b['title'] );
			}
		);
		return $out;
	}

	/**
	 * Names of active builders, for admin messaging.
	 *
	 * @return string[]
	 */
	public static function active_names() {
		return array_map(
			static function ( $p ) {
				return $p->name();
			},
			self::active()
		);
	}
}
