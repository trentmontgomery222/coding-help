<?php
/**
 * Beaver Builder provider. Delegates to MCM_Beaver (the original integration).
 *
 * @package mcm
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MCM_Provider_Beaver extends MCM_Provider {

	public function key() {
		return 'beaver';
	}

	public function name() {
		return __( 'Beaver Builder', 'mcm' );
	}

	public function is_active() {
		return MCM_Beaver::is_active();
	}

	public function handles_post( $post_id ) {
		if ( '1' === (string) get_post_meta( $post_id, '_fl_builder_enabled', true ) ) {
			return true;
		}
		$data = get_post_meta( $post_id, '_fl_builder_data', true );
		return ! empty( $data );
	}

	public function get_pages() {
		return MCM_Beaver::get_bb_posts();
	}

	public function supports_inplace() {
		return true;
	}

	public function dom_selector() {
		return '.fl-module[data-node]';
	}

	public function dom_id_attr() {
		return 'data-node';
	}

	public function list_nodes( $post_id ) {
		return MCM_Beaver::scan_modules( $post_id );
	}

	public function describe_node( $post_id, $node_id ) {
		return MCM_Beaver::describe_module( $post_id, $node_id );
	}

	public function update_node( $post_id, $node_id, $assoc ) {
		return MCM_Beaver::update_module_settings( $post_id, $node_id, $assoc );
	}

	public function node_image_src( $post_id, $node_id ) {
		return MCM_Beaver::module_image_src( $post_id, $node_id );
	}
}
