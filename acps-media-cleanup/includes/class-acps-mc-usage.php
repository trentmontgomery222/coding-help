<?php
/**
 * On-demand "where is this file used?" lookup for a single attachment.
 *
 * Runs targeted queries for ONE file so the media manager and the media modal
 * can show usage instantly, without needing a full library scan. Same accuracy
 * rules as the scanner: attachment-owned data is never counted as self-usage.
 *
 * @package ACPS_Media_Cleanup
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ACPS_MC_Usage {

	/**
	 * Find where an attachment is used.
	 *
	 * @param int $id Attachment ID.
	 * @return array List of array( 'label' => string, 'url' => string ).
	 */
	public static function for_attachment( $id ) {
		global $wpdb;
		$id = (int) $id;
		if ( $id <= 0 || 'attachment' !== get_post_type( $id ) ) {
			return array();
		}

		$locations = array();
		$seen      = array();
		$add       = function ( $label, $url = '' ) use ( &$locations, &$seen ) {
			$label = trim( (string) $label );
			if ( '' === $label || isset( $seen[ $label ] ) ) {
				return;
			}
			$seen[ $label ] = true;
			$locations[]    = array( 'label' => $label, 'url' => (string) $url );
		};

		// Filename stem (matches original + all sizes + builder photo_src URLs).
		$scanner  = new ACPS_MC_Scanner();
		$file     = get_post_meta( $id, '_wp_attached_file', true );
		$basename = $file ? wp_basename( $file ) : wp_basename( (string) get_attached_file( $id ) );
		$stem     = $scanner->stem( $basename );
		$stems    = array_values( array_unique( array_filter( array( $stem ) ) ) );

		$img_class = '%wp-image-' . $id . '%';

		// ---- Posts / pages content ----
		if ( $stems ) {
			$clauses = array();
			$args    = array();
			foreach ( $stems as $s ) {
				$like      = '%' . $wpdb->esc_like( $s ) . '%';
				$clauses[] = 'post_content LIKE %s';
				$args[]    = $like;
				$clauses[] = 'post_excerpt LIKE %s';
				$args[]    = $like;
			}
			$clauses[] = 'post_content LIKE %s';
			$args[]    = $img_class;

			$sql  = "SELECT ID, post_title, post_type FROM {$wpdb->posts}
				WHERE post_status NOT IN ('trash','auto-draft')
				  AND post_type NOT IN ('revision','attachment')
				  AND (" . implode( ' OR ', $clauses ) . ')
				LIMIT 40';
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$rows = $wpdb->get_results( $wpdb->prepare( $sql, $args ) );
			foreach ( (array) $rows as $r ) {
				$add( self::post_label( $r->post_type, $r->post_title, $r->ID ), get_edit_post_link( $r->ID, 'raw' ) );
			}
		}

		// ---- Post meta (page builders, custom fields), non-attachment ----
		if ( $stems ) {
			$self    = "'" . implode( "','", array_map( 'esc_sql', ACPS_MC_Scanner::SELF_META_KEYS ) ) . "'";
			$clauses = array();
			$args    = array();
			foreach ( $stems as $s ) {
				$clauses[] = 'pm.meta_value LIKE %s';
				$args[]    = '%' . $wpdb->esc_like( $s ) . '%';
			}
			$clauses[] = 'pm.meta_value LIKE %s';
			$args[]    = $img_class;

			$sql = "SELECT p.ID, p.post_title, p.post_type
				FROM {$wpdb->postmeta} pm
				INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
				WHERE p.post_type <> 'attachment'
				  AND p.post_status NOT IN ('trash','auto-draft')
				  AND pm.meta_key NOT IN ($self)
				  AND (" . implode( ' OR ', $clauses ) . ')
				GROUP BY p.ID
				LIMIT 40';
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$rows = $wpdb->get_results( $wpdb->prepare( $sql, $args ) );
			foreach ( (array) $rows as $r ) {
				$add( self::post_label( $r->post_type, $r->post_title, $r->ID ), get_edit_post_link( $r->ID, 'raw' ) );
			}
		}

		// ---- Featured image ----
		$feat = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT p.ID, p.post_title, p.post_type
				 FROM {$wpdb->postmeta} pm
				 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
				 WHERE pm.meta_key = '_thumbnail_id' AND pm.meta_value = %d
				   AND p.post_status <> 'trash'
				 LIMIT 25",
				$id
			)
		);
		foreach ( (array) $feat as $r ) {
			$add(
				sprintf( __( 'Featured image of %s', 'acps-media-cleanup' ), self::post_label( $r->post_type, $r->post_title, $r->ID ) ),
				get_edit_post_link( $r->ID, 'raw' )
			);
		}

		// ---- Options (widgets, theme mods, plugin settings) ----
		if ( $stems ) {
			// Never count the plugin's OWN storage as a "use": the results option
			// contains every scanned attachment ID (as array keys), the settings
			// option holds excluded/target IDs, and scan-meta holds counts — a file
			// appearing there is not a real reference and would mark everything used.
			$skip    = array( ACPS_MC_OPT_RESULTS, ACPS_MC_OPT_SCANMETA, ACPS_MC_OPT_SETTINGS );
			$clauses = array();
			$args    = $skip;
			foreach ( $stems as $s ) {
				$clauses[] = 'option_value LIKE %s';
				$args[]    = '%' . $wpdb->esc_like( $s ) . '%';
			}
			$sql = "SELECT option_name FROM {$wpdb->options}
				WHERE option_name NOT LIKE '\_transient\_%'
				  AND option_name NOT LIKE '\_site\_transient\_%'
				  AND option_name NOT IN ( " . implode( ', ', array_fill( 0, count( $skip ), '%s' ) ) . " )
				  AND (" . implode( ' OR ', $clauses ) . ')
				LIMIT 25';
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$rows = $wpdb->get_col( $wpdb->prepare( $sql, $args ) );
			foreach ( (array) $rows as $name ) {
				list( $label, $url ) = self::option_label( $name );
				$add( $label, $url );
			}
		}

		// ---- Site icon / logo (by id) ----
		if ( (int) get_option( 'site_icon' ) === $id ) {
			$add( __( 'Site icon', 'acps-media-cleanup' ), admin_url( 'options-general.php' ) );
		}
		if ( (int) get_theme_mod( 'custom_logo' ) === $id ) {
			$add( __( 'Site logo', 'acps-media-cleanup' ), admin_url( 'customize.php' ) );
		}

		// ---- Attached to a post ----
		$post = get_post( $id );
		if ( $post && $post->post_parent > 0 ) {
			$parent = get_post( $post->post_parent );
			if ( $parent && 'trash' !== $parent->post_status ) {
				$add( sprintf( __( 'Attached to: %s', 'acps-media-cleanup' ), get_the_title( $parent ) ), get_edit_post_link( $parent->ID, 'raw' ) );
			}
		}

		return $locations;
	}

	protected static function post_label( $post_type, $title, $id ) {
		$obj  = get_post_type_object( $post_type );
		$name = ( $obj && ! empty( $obj->labels->singular_name ) ) ? $obj->labels->singular_name : ucfirst( (string) $post_type );
		$t    = trim( (string) $title );
		if ( '' === $t ) {
			$t = sprintf( __( '(no title) #%d', 'acps-media-cleanup' ), (int) $id );
		}
		return $name . ': ' . $t;
	}

	protected static function option_label( $name ) {
		$url = '';
		if ( 0 === strpos( $name, 'widget_' ) || 'sidebars_widgets' === $name ) {
			$label = __( 'Widget', 'acps-media-cleanup' );
			$url   = admin_url( 'widgets.php' );
		} elseif ( 0 === strpos( $name, 'theme_mods_' ) || in_array( $name, array( 'site_logo', 'custom_logo' ), true ) ) {
			$label = __( 'Theme / customizer setting', 'acps-media-cleanup' );
			$url   = admin_url( 'customize.php' );
		} else {
			$label = sprintf( __( 'Site option: %s', 'acps-media-cleanup' ), $name );
		}
		return array( $label, $url );
	}
}
