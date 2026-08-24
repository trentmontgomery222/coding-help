<?php
/**
 * Page-builder provider contract.
 *
 * A "provider" teaches the plugin how one page builder stores and renders a
 * page's content, so the in-place editor can work with Beaver Builder,
 * Elementor, or the block editor (Gutenberg / GenerateBlocks) without the rest
 * of the plugin knowing which is in use.
 *
 * A "node" is one editable unit — a Beaver Builder module, an Elementor widget,
 * or a Gutenberg block — addressed by a provider-specific string id.
 *
 * @package mcm
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

abstract class MCM_Provider {

	/** Stable key, e.g. 'beaver'. @return string */
	abstract public function key();

	/** Human name, e.g. 'Beaver Builder'. @return string */
	abstract public function name();

	/** Is the builder plugin active in this request? @return bool */
	abstract public function is_active();

	/**
	 * Was this specific post built with this builder?
	 *
	 * @param int $post_id
	 * @return bool
	 */
	abstract public function handles_post( $post_id );

	/**
	 * Pages/posts built with this builder (for the admin picker).
	 *
	 * @return WP_Post[]
	 */
	abstract public function get_pages();

	/**
	 * True → editors click the element on the page (the builder marks each unit
	 * in the DOM). False → editors pick from a list in the drawer.
	 *
	 * @return bool
	 */
	public function supports_inplace() {
		return false;
	}

	/** CSS selector for editable units in the rendered DOM (in-place only). @return string */
	public function dom_selector() {
		return '';
	}

	/** DOM attribute holding the node id (in-place only). @return string */
	public function dom_id_attr() {
		return 'data-node';
	}

	/**
	 * List the editable nodes on a post (for the drawer list + admin preview).
	 *
	 * @param int $post_id
	 * @return array<int,array> each { node_id, label, preview }
	 */
	abstract public function list_nodes( $post_id );

	/**
	 * Describe one node's editable fields.
	 *
	 * @param int    $post_id
	 * @param string $node_id
	 * @return array|WP_Error { label, primary[], advanced[] } (field descriptors)
	 */
	abstract public function describe_node( $post_id, $node_id );

	/**
	 * Write edited field values back to one node.
	 *
	 * @param int    $post_id
	 * @param string $node_id
	 * @param array  $assoc key => sanitized value (keys are descriptor keys)
	 * @return true|WP_Error
	 */
	abstract public function update_node( $post_id, $node_id, $assoc );

	/**
	 * Current image URL for a node's image field, for the upload preview.
	 *
	 * @param int    $post_id
	 * @param string $node_id
	 * @return string
	 */
	public function node_image_src( $post_id, $node_id ) {
		return '';
	}
}
