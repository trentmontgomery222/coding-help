<?php
/**
 * Extension registry — the portal's "platform" API (spec Section 7).
 *
 * Third-party plugins register dashboard menu items, permission/capability keys,
 * queue submission types and activity formatters here, without touching core.
 *
 * Registration happens on the `exp_register_extensions` action, which receives
 * this registry instance. Global helper functions (see includes/api.php) proxy
 * to the same singleton for convenience.
 *
 * @package ExternalPortal
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Singleton holding all registered extension points.
 */
class EXP_Registry {

	/**
	 * @var EXP_Registry|null
	 */
	protected static $instance = null;

	/**
	 * @var array<string,array> Menu items keyed by slug.
	 */
	protected $menu_items = array();

	/**
	 * @var array<string,array> Capabilities keyed by key.
	 */
	protected $capabilities = array();

	/**
	 * @var array<string,array> Queue types keyed by type.
	 */
	protected $queue_types = array();

	/**
	 * @var array<string,callable> Activity formatters keyed by type.
	 */
	protected $activity_formatters = array();

	/**
	 * @var bool Whether registration has run.
	 */
	protected $loaded = false;

	/**
	 * Singleton accessor.
	 *
	 * @return EXP_Registry
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Trigger registration exactly once. Core modules register first (on
	 * priority 0), then third-party plugins on the same action.
	 */
	public function load() {
		if ( $this->loaded ) {
			return;
		}
		$this->loaded = true;

		/**
		 * Register extension points with the portal.
		 *
		 * @param EXP_Registry $registry The registry instance.
		 */
		do_action( 'exp_register_extensions', $this );

		// Record any newly-seen third-party items for the admin approval screen.
		$this->sync_extension_records();
	}

	// ---------------------------------------------------------------------
	// Registration.
	// ---------------------------------------------------------------------

	/**
	 * Register a dashboard menu item.
	 *
	 * @param array $args slug, label, capability, render (callable), icon, position, core, source.
	 * @return bool|WP_Error
	 */
	public function register_menu_item( array $args ) {
		$args = wp_parse_args(
			$args,
			array(
				'slug'       => '',
				'label'      => '',
				'capability' => '',
				'render'     => null,
				'handle'     => null, // Optional callable( array $ctx ): array $notices — processes this module's POST.
				'icon'       => 'admin-generic',
				'position'   => 50,
				'core'       => false,
				'source'     => '',
			)
		);

		$slug = sanitize_key( $args['slug'] );
		if ( '' === $slug ) {
			return new WP_Error( 'exp_reg_slug', __( 'A menu item requires a slug.', 'external-portal' ) );
		}
		if ( ! is_callable( $args['render'] ) ) {
			return new WP_Error( 'exp_reg_render', __( 'A menu item requires a callable render callback.', 'external-portal' ) );
		}

		$args['slug']  = $slug;
		$args['label'] = $args['label'] ? $args['label'] : $slug;
		$this->menu_items[ $slug ] = $args;
		return true;
	}

	/**
	 * Register a capability/permission key so it appears on the admin grants screen.
	 *
	 * @param array $args key, label, description, target_type, target_options, module, core, source.
	 * @return bool|WP_Error
	 */
	public function register_capability( array $args ) {
		$args = wp_parse_args(
			$args,
			array(
				'key'            => '',
				'label'          => '',
				'description'    => '',
				'target_type'    => 'none', // none|page|category|calendar|custom.
				'target_options' => null,   // callable|array for custom target lists.
				'module'         => '',
				'core'           => false,
				'source'         => '',
			)
		);

		$key = sanitize_key( $args['key'] );
		if ( '' === $key ) {
			return new WP_Error( 'exp_reg_cap', __( 'A capability requires a key.', 'external-portal' ) );
		}
		$args['key']               = $key;
		$args['label']             = $args['label'] ? $args['label'] : $key;
		$this->capabilities[ $key ] = $args;
		return true;
	}

	/**
	 * Register a queue submission type.
	 *
	 * @param array $args type, label, review_renderer (callable), applier (callable), core, source.
	 * @return bool|WP_Error
	 */
	public function register_queue_type( array $args ) {
		$args = wp_parse_args(
			$args,
			array(
				'type'            => '',
				'label'          => '',
				'review_renderer' => null, // callable( $item ): string  (admin review preview).
				'applier'         => null, // callable( $item ): true|WP_Error (on approval).
				'core'            => false,
				'source'          => '',
			)
		);

		$type = sanitize_key( $args['type'] );
		if ( '' === $type ) {
			return new WP_Error( 'exp_reg_type', __( 'A queue type requires a type key.', 'external-portal' ) );
		}
		$args['type']              = $type;
		$args['label']             = $args['label'] ? $args['label'] : $type;
		$this->queue_types[ $type ] = $args;
		return true;
	}

	/**
	 * Register an activity formatter (for the "My Activity" view).
	 *
	 * @param string   $type      Queue type.
	 * @param callable $formatter callable( $item ): string.
	 * @return bool
	 */
	public function register_activity_formatter( $type, $formatter ) {
		$type = sanitize_key( $type );
		if ( '' === $type || ! is_callable( $formatter ) ) {
			return false;
		}
		$this->activity_formatters[ $type ] = $formatter;
		return true;
	}

	// ---------------------------------------------------------------------
	// Accessors.
	// ---------------------------------------------------------------------

	/**
	 * All menu items, sorted by position.
	 *
	 * @return array<string,array>
	 */
	public function menu_items() {
		$items = $this->menu_items;
		uasort(
			$items,
			static function ( $a, $b ) {
				return (int) $a['position'] <=> (int) $b['position'];
			}
		);
		return $items;
	}

	/**
	 * Menu items that a given portal user should actually see: approved (or core)
	 * AND the user holds the required capability (if any).
	 *
	 * @param object $user Portal user row.
	 * @return array<string,array>
	 */
	public function visible_menu_items_for( $user ) {
		$visible = array();
		foreach ( $this->menu_items() as $slug => $item ) {
			if ( ! $this->is_menu_item_enabled( $slug ) ) {
				continue;
			}
			$cap = $item['capability'];
			if ( $cap && ! EXP_Permissions::user_can_any( $user->id, $cap ) ) {
				continue;
			}
			$visible[ $slug ] = $item;
		}
		return $visible;
	}

	/**
	 * A single menu item by slug.
	 *
	 * @param string $slug Slug.
	 * @return array|null
	 */
	public function menu_item( $slug ) {
		return isset( $this->menu_items[ $slug ] ) ? $this->menu_items[ $slug ] : null;
	}

	/**
	 * All capabilities (core + registered).
	 *
	 * @return array<string,array>
	 */
	public function capabilities() {
		return $this->capabilities;
	}

	/**
	 * A single capability definition.
	 *
	 * @param string $key Capability key.
	 * @return array|null
	 */
	public function capability( $key ) {
		return isset( $this->capabilities[ $key ] ) ? $this->capabilities[ $key ] : null;
	}

	/**
	 * A registered queue type definition.
	 *
	 * @param string $type Type.
	 * @return array|null
	 */
	public function queue_type( $type ) {
		return isset( $this->queue_types[ $type ] ) ? $this->queue_types[ $type ] : null;
	}

	/**
	 * All queue types.
	 *
	 * @return array<string,array>
	 */
	public function queue_types() {
		return $this->queue_types;
	}

	/**
	 * The activity formatter for a type, if any.
	 *
	 * @param string $type Type.
	 * @return callable|null
	 */
	public function activity_formatter( $type ) {
		return isset( $this->activity_formatters[ $type ] ) ? $this->activity_formatters[ $type ] : null;
	}

	// ---------------------------------------------------------------------
	// Approval gate (spec Section 7 governance decision — default ON).
	// ---------------------------------------------------------------------

	/**
	 * Is a menu item enabled for portal users? Core items are always enabled.
	 * Third-party items require admin approval when the gate is on.
	 *
	 * @param string $slug Slug.
	 * @return bool
	 */
	public function is_menu_item_enabled( $slug ) {
		$item = $this->menu_item( $slug );
		if ( ! $item ) {
			return false;
		}
		if ( ! empty( $item['core'] ) ) {
			return true;
		}
		if ( ! EXP_Settings::get( 'extensions_require_approval', 1 ) ) {
			return true;
		}
		return $this->is_extension_approved( $slug );
	}

	/**
	 * Whether an extension slug has been approved by an admin.
	 *
	 * @param string $slug Slug.
	 * @return bool
	 */
	public function is_extension_approved( $slug ) {
		global $wpdb;
		$table = EXP_Install::table( 'extensions' );
		$val   = $wpdb->get_var( $wpdb->prepare( "SELECT approved FROM {$table} WHERE slug = %s", $slug ) ); // phpcs:ignore WordPress.DB.PreparedSQL
		return '1' === (string) $val;
	}

	/**
	 * Approve or unapprove an extension slug.
	 *
	 * @param string $slug     Slug.
	 * @param bool   $approved State.
	 */
	public function set_extension_approved( $slug, $approved ) {
		global $wpdb;
		$table = EXP_Install::table( 'extensions' );
		$wpdb->update(
			$table,
			array(
				'approved'    => $approved ? 1 : 0,
				'approved_at' => $approved ? EXP_Util::now() : null,
			),
			array( 'slug' => $slug ),
			array( '%d', '%s' ),
			array( '%s' )
		);
		EXP_Audit::log(
			$approved ? 'extension.approved' : 'extension.unapproved',
			array(
				'actor_type' => 'admin',
				'actor_id'   => get_current_user_id(),
				'object_ref' => 'ext:' . $slug,
			)
		);
	}

	/**
	 * Ensure every non-core registered menu item has a record in the
	 * extensions table so admins can approve it.
	 */
	protected function sync_extension_records() {
		global $wpdb;
		$table = EXP_Install::table( 'extensions' );
		foreach ( $this->menu_items as $slug => $item ) {
			if ( ! empty( $item['core'] ) ) {
				continue;
			}
			$exists = $wpdb->get_var( $wpdb->prepare( "SELECT slug FROM {$table} WHERE slug = %s", $slug ) ); // phpcs:ignore WordPress.DB.PreparedSQL
			if ( ! $exists ) {
				$wpdb->insert(
					$table,
					array(
						'slug'          => $slug,
						'approved'      => 0,
						'label'         => substr( (string) $item['label'], 0, 191 ),
						'source_plugin' => substr( (string) $item['source'], 0, 191 ),
						'first_seen_at' => EXP_Util::now(),
					),
					array( '%s', '%d', '%s', '%s', '%s' )
				);
			}
		}
	}

	/**
	 * All extension records (for the admin Extensions screen).
	 *
	 * @return array
	 */
	public function extension_records() {
		global $wpdb;
		$table = EXP_Install::table( 'extensions' );
		$rows  = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY first_seen_at DESC" ); // phpcs:ignore WordPress.DB
		return $rows ? $rows : array();
	}
}
