<?php
/**
 * Gutenberg / block-editor provider (covers core blocks, GeneratePress +
 * GenerateBlocks, and any block theme).
 *
 * Block content lives in post_content as block markup. There is no reliable
 * per-block DOM id to click, so this provider is list-based: the editor picks a
 * block from the drawer. Editing rewrites the block's inner HTML (for text) or
 * image, then re-serializes post_content.
 *
 * Nodes are addressed by their position in a depth-first list of editable
 * blocks.
 *
 * @package mcm
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MCM_Provider_Gutenberg extends MCM_Provider {

	public function key() {
		return 'gutenberg';
	}

	public function name() {
		return __( 'Block editor', 'mcm' );
	}

	public function is_active() {
		// The block editor is core; treat as available on any modern WP.
		return function_exists( 'parse_blocks' ) && function_exists( 'serialize_blocks' );
	}

	public function handles_post( $post_id ) {
		$post = get_post( $post_id );
		return $post && function_exists( 'has_blocks' ) && has_blocks( $post->post_content );
	}

	public function get_pages() {
		$q = new WP_Query(
			array(
				'post_type'      => array( 'page', 'post' ),
				'post_status'    => array( 'publish', 'draft', 'private', 'pending' ),
				'posts_per_page' => 200,
				'orderby'        => 'title',
				'order'          => 'ASC',
				'no_found_rows'  => true,
			)
		);
		$out = array();
		foreach ( $q->posts as $p ) {
			if ( has_blocks( $p->post_content ) ) {
				$out[] = $p;
			}
		}
		return $out;
	}

	public function supports_inplace() {
		return false; // List-based: no reliable per-block DOM hook.
	}

	/**
	 * Editable-block config. Each returns field descriptors with a 'source'.
	 *
	 * @param string $name block name
	 * @return array|null
	 */
	private function config( $name ) {
		$text = array(
			array( 'key' => 'content', 'label' => __( 'Text', 'mcm' ), 'widget' => 'richtext', 'source' => 'innerhtml' ),
		);
		$map = array(
			'core/paragraph'          => $text,
			'core/heading'            => $text,
			'core/list'               => $text,
			'core/list-item'          => $text,
			'core/quote'              => $text,
			'core/verse'              => $text,
			'core/button'             => $text,
			'core/preformatted'       => $text,
			'generateblocks/headline' => $text,
			'generateblocks/button'   => $text,
			'generateblocks/paragraph' => $text,
			'core/image'              => array(
				array( 'key' => 'image', 'label' => __( 'Image', 'mcm' ), 'widget' => 'image', 'source' => 'image' ),
			),
		);
		return isset( $map[ $name ] ) ? $map[ $name ] : null;
	}

	public function list_nodes( $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return array();
		}
		$blocks = parse_blocks( $post->post_content );
		$out    = array();
		$index  = 0;
		$this->walk(
			$blocks,
			$index,
			function ( $block, $i ) use ( &$out ) {
				$out[] = array(
					'node_id' => (string) $i,
					'label'   => $this->block_label( $block['blockName'] ),
					'preview' => $this->preview( $block ),
				);
			}
		);
		return $out;
	}

	public function describe_node( $post_id, $node_id ) {
		$block = $this->block_at( $post_id, (int) $node_id );
		if ( null === $block ) {
			return new WP_Error( 'mcm_gb_node', __( 'That block could not be found.', 'mcm' ) );
		}
		$config  = $this->config( $block['blockName'] );
		$primary = array();
		foreach ( (array) $config as $spec ) {
			$value = ( 'image' === $spec['source'] )
				? $this->image_src_from_block( $block )
				: trim( (string) $block['innerHTML'] );
			$primary[] = array(
				'key'     => $spec['key'],
				'label'   => $spec['label'],
				'widget'  => $spec['widget'],
				'options' => array(),
				'on'      => 'yes',
				'off'     => 'no',
				'value'   => $value,
			);
		}
		return array(
			'slug'     => $block['blockName'],
			'label'    => $this->block_label( $block['blockName'] ),
			'primary'  => $primary,
			'advanced' => array(),
		);
	}

	public function update_node( $post_id, $node_id, $assoc ) {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return new WP_Error( 'mcm_gb_post', __( 'Page not found.', 'mcm' ) );
		}
		$blocks = parse_blocks( $post->post_content );
		$target = (int) $node_id;
		$index  = 0;
		$done   = false;

		$this->walk_update(
			$blocks,
			$index,
			function ( &$block ) use ( $assoc, &$done ) {
				$config = $this->config( $block['blockName'] );
				if ( empty( $config ) ) {
					return;
				}
				foreach ( $config as $spec ) {
					$key = $spec['key'];
					if ( ! array_key_exists( $key, $assoc ) ) {
						continue;
					}
					if ( 'image' === $spec['source'] && is_array( $assoc[ $key ] ) && isset( $assoc[ $key ]['url'] ) ) {
						$this->apply_image( $block, (int) ( $assoc[ $key ]['id'] ?? 0 ), $assoc[ $key ]['url'] );
					} elseif ( 'innerhtml' === $spec['source'] && empty( $block['innerBlocks'] ) ) {
						$html                    = (string) $assoc[ $key ];
						$block['innerHTML']      = $html;
						$block['innerContent']   = array( $html );
					}
				}
				$done = true;
			},
			$target
		);

		if ( ! $done ) {
			return new WP_Error( 'mcm_gb_node', __( 'That block could not be found. The page may have changed.', 'mcm' ) );
		}

		$content = serialize_blocks( $blocks );
		$res     = wp_update_post(
			array(
				'ID'           => $post_id,
				'post_content' => wp_slash( $content ),
			),
			true
		);
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		clean_post_cache( $post_id );
		return true;
	}

	public function node_image_src( $post_id, $node_id ) {
		$block = $this->block_at( $post_id, (int) $node_id );
		return $block ? $this->image_src_from_block( $block ) : '';
	}

	// -----------------------------------------------------------------------
	// Block helpers
	// -----------------------------------------------------------------------

	/**
	 * Depth-first walk over editable blocks (read-only), numbering them.
	 *
	 * @param array    $blocks
	 * @param int      $index by reference
	 * @param callable $cb ( $block, $i )
	 */
	private function walk( $blocks, &$index, $cb ) {
		foreach ( $blocks as $block ) {
			if ( ! empty( $block['blockName'] ) && null !== $this->config( $block['blockName'] ) ) {
				$cb( $block, $index );
				++$index;
			}
			if ( ! empty( $block['innerBlocks'] ) ) {
				$this->walk( $block['innerBlocks'], $index, $cb );
			}
		}
	}

	/**
	 * Depth-first walk that mutates the block at $target in place.
	 *
	 * @param array    $blocks by reference
	 * @param int      $index  by reference
	 * @param callable $mutate ( &$block )
	 * @param int      $target index to mutate
	 */
	private function walk_update( &$blocks, &$index, $mutate, $target ) {
		foreach ( $blocks as &$block ) {
			if ( ! empty( $block['blockName'] ) && null !== $this->config( $block['blockName'] ) ) {
				if ( $index === $target ) {
					$mutate( $block );
				}
				++$index;
			}
			if ( ! empty( $block['innerBlocks'] ) ) {
				$this->walk_update( $block['innerBlocks'], $index, $mutate, $target );
			}
		}
		unset( $block );
	}

	/**
	 * @param int $post_id
	 * @param int $target
	 * @return array|null block (copy)
	 */
	private function block_at( $post_id, $target ) {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return null;
		}
		$blocks = parse_blocks( $post->post_content );
		$index  = 0;
		$found  = null;
		$this->walk(
			$blocks,
			$index,
			function ( $block, $i ) use ( &$found, $target ) {
				if ( $i === $target ) {
					$found = $block;
				}
			}
		);
		return $found;
	}

	private function apply_image( &$block, $id, $url ) {
		if ( $id ) {
			$block['attrs']['id'] = $id;
		}
		$html = (string) $block['innerHTML'];
		$html = preg_replace( '/src="[^"]*"/', 'src="' . esc_url( $url ) . '"', $html, 1 );
		if ( $id ) {
			$html = preg_replace( '/wp-image-\d+/', 'wp-image-' . (int) $id, $html );
		}
		$block['innerHTML']    = $html;
		$block['innerContent'] = array( $html );
	}

	private function image_src_from_block( $block ) {
		if ( ! empty( $block['attrs']['url'] ) ) {
			return (string) $block['attrs']['url'];
		}
		if ( preg_match( '/src="([^"]+)"/', (string) $block['innerHTML'], $m ) ) {
			return $m[1];
		}
		return '';
	}

	private function block_label( $name ) {
		$name = preg_replace( '#^[a-z0-9-]+/#', '', (string) $name );
		return ucwords( str_replace( array( '-', '_' ), ' ', $name ) );
	}

	private function preview( $block ) {
		$p = trim( wp_strip_all_tags( (string) $block['innerHTML'] ) );
		if ( '' !== $p ) {
			return mb_strlen( $p ) > 60 ? mb_substr( $p, 0, 60 ) . '…' : $p;
		}
		if ( 'core/image' === $block['blockName'] ) {
			return __( '(image)', 'mcm' );
		}
		return '';
	}
}
