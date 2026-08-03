<?php
/**
 * Module: Category-Scoped Post Creation (spec Section 5.2).
 *
 * A minimal add/edit post form scoped to one granted category (e.g. the category
 * the site's carousel pulls from). No wp-admin, no full editor. Submissions route
 * to the Content Update Queue; on approval the post is created/updated live.
 *
 * @package ExternalPortal
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Category post module.
 */
class EXP_Module_Category_Post {

	const CAP  = 'create_post_in_category';
	const SLUG = 'category_post';
	const TYPE = 'category_post';

	/**
	 * Register.
	 *
	 * @param EXP_Registry $r Registry.
	 */
	public static function register( $r ) {
		$r->register_capability(
			array(
				'key'            => self::CAP,
				'label'          => __( 'Add/edit posts in a category', 'external-portal' ),
				'description'    => __( 'Submit posts within specific categories (reviewed before publishing).', 'external-portal' ),
				'target_type'    => 'category',
				'target_options' => array( __CLASS__, 'category_options' ),
				'module'         => self::SLUG,
				'core'           => true,
			)
		);
		$r->register_menu_item(
			array(
				'slug'       => self::SLUG,
				'label'      => __( 'Posts', 'external-portal' ),
				'icon'       => 'admin-post',
				'capability' => self::CAP,
				'render'     => array( __CLASS__, 'render' ),
				'handle'     => array( __CLASS__, 'handle' ),
				'position'   => 20,
				'core'       => true,
			)
		);
		$r->register_queue_type(
			array(
				'type'            => self::TYPE,
				'label'           => __( 'Category post', 'external-portal' ),
				'review_renderer' => array( __CLASS__, 'review' ),
				'applier'         => array( __CLASS__, 'apply' ),
				'core'            => true,
			)
		);
		$r->register_activity_formatter( self::TYPE, array( __CLASS__, 'activity' ) );
	}

	/**
	 * Admin target options: categories.
	 *
	 * @return array<int,string>
	 */
	public static function category_options() {
		$terms = get_terms(
			array(
				'taxonomy'   => 'category',
				'hide_empty' => false,
			)
		);
		$out = array();
		if ( ! is_wp_error( $terms ) ) {
			foreach ( $terms as $t ) {
				$out[ $t->term_id ] = $t->name;
			}
		}
		return $out;
	}

	/**
	 * Render.
	 *
	 * @param array $ctx Context.
	 * @return string
	 */
	public static function render( array $ctx ) {
		$user    = $ctx['user'];
		$targets = array_map( 'intval', EXP_Permissions::targets_for( $user->id, self::CAP ) );
		if ( empty( $targets ) ) {
			return EXP_UI::notice( 'info', __( 'You have not been granted posting access to any category yet.', 'external-portal' ) );
		}

		$cat_id  = isset( $_GET['cat_id'] ) ? (int) $_GET['cat_id'] : 0; // phpcs:ignore WordPress.Security.NonceVerification
		$post_id = isset( $_GET['post_id'] ) ? (int) $_GET['post_id'] : 0; // phpcs:ignore WordPress.Security.NonceVerification

		// Category chooser when the user has more than one.
		if ( ! $cat_id || ! in_array( $cat_id, $targets, true ) ) {
			if ( 1 === count( $targets ) ) {
				$cat_id = $targets[0];
			} else {
				return self::render_category_chooser( $targets );
			}
		}

		return self::render_category_workspace( $ctx, $cat_id, $post_id );
	}

	/**
	 * Category chooser list.
	 *
	 * @param int[] $targets Category ids.
	 * @return string
	 */
	protected static function render_category_chooser( $targets ) {
		$html = '<p>' . esc_html__( 'Choose a category to work in.', 'external-portal' ) . '</p><ul class="exp-list">';
		foreach ( $targets as $cid ) {
			$term = get_term( $cid, 'category' );
			$name = ( $term && ! is_wp_error( $term ) ) ? $term->name : '#' . $cid;
			$url  = add_query_arg(
				array(
					'view'   => self::SLUG,
					'cat_id' => $cid,
				),
				external_portal()->dashboard_url()
			);
			$html .= '<li class="exp-list__item"><a class="exp-link" href="' . esc_url( $url ) . '">' . esc_html( $name ) . '</a></li>';
		}
		return $html . '</ul>';
	}

	/**
	 * Workspace: new-post form + list of the user's own submitted posts.
	 *
	 * @param array $ctx     Context.
	 * @param int   $cat_id  Category id.
	 * @param int   $post_id Post being edited (0 = new).
	 * @return string
	 */
	protected static function render_category_workspace( array $ctx, $cat_id, $post_id ) {
		$term      = get_term( $cat_id, 'category' );
		$cat_name  = ( $term && ! is_wp_error( $term ) ) ? $term->name : '#' . $cat_id;
		$editing   = null;

		if ( $post_id ) {
			$editing = get_post( $post_id );
			// Only allow editing posts this portal user originally submitted.
			if ( ! $editing || (int) get_post_meta( $post_id, '_exp_submitted_by', true ) !== (int) $ctx['user']->id ) {
				$editing = null;
			}
		}

		$html  = '<p>' . sprintf(
			/* translators: %s: category name */
			esc_html__( 'Category: %s', 'external-portal' ),
			'<strong>' . esc_html( $cat_name ) . '</strong>'
		) . '</p>';

		$html .= '<form class="exp-form" method="post">';
		$html .= EXP_UI::module_hidden_fields( $ctx );
		$html .= '<input type="hidden" name="exp_cat_id" value="' . esc_attr( $cat_id ) . '" />';
		$html .= '<input type="hidden" name="exp_post_id" value="' . esc_attr( $editing ? $editing->ID : 0 ) . '" />';
		$html .= '<h3 class="exp-subhead">' . ( $editing ? esc_html__( 'Edit post', 'external-portal' ) : esc_html__( 'New post', 'external-portal' ) ) . '</h3>';
		$html .= EXP_UI::field(
			array(
				'name'     => 'exp_title',
				'label'    => __( 'Title', 'external-portal' ),
				'value'    => $editing ? $editing->post_title : '',
				'required' => true,
			)
		);
		$html .= EXP_UI::field(
			array(
				'name'  => 'exp_excerpt',
				'label' => __( 'Excerpt', 'external-portal' ),
				'value' => $editing ? $editing->post_excerpt : '',
				'help'  => __( 'A short summary shown in listings.', 'external-portal' ),
			)
		);

		$ta_id = 'exp-post-content';
		$html .= '<div class="exp-field"><label class="exp-field__label" for="' . esc_attr( $ta_id ) . '">' . esc_html__( 'Content', 'external-portal' ) . '</label>';
		$html .= '<textarea class="exp-field__input exp-textarea" id="' . esc_attr( $ta_id ) . '" name="exp_content" rows="10">' . esc_textarea( $editing ? $editing->post_content : '' ) . '</textarea></div>';

		$current_img = $editing ? get_the_post_thumbnail_url( $editing->ID, 'medium' ) : '';
		$html       .= EXP_UI::field(
			array(
				'name'  => 'exp_featured_url',
				'label' => __( 'Featured image URL', 'external-portal' ),
				'type'  => 'url',
				'value' => $current_img ? $current_img : '',
				'help'  => __( 'Paste an image URL. It will be attached when the post is approved.', 'external-portal' ),
			)
		);

		$html .= '<button type="submit" class="exp-button">' . esc_html__( 'Submit for review', 'external-portal' ) . '</button>';
		$html .= '</form>';

		$html .= self::render_own_posts( $ctx, $cat_id );
		return $html;
	}

	/**
	 * List the user's own submitted posts in a category, with edit links.
	 *
	 * @param array $ctx    Context.
	 * @param int   $cat_id Category id.
	 * @return string
	 */
	protected static function render_own_posts( array $ctx, $cat_id ) {
		$posts = get_posts(
			array(
				'post_type'      => 'post',
				'post_status'    => array( 'publish', 'draft', 'pending' ),
				'posts_per_page' => 25,
				'cat'            => $cat_id,
				'meta_key'       => '_exp_submitted_by', // phpcs:ignore WordPress.DB.SlowDBQuery
				'meta_value'     => (int) $ctx['user']->id, // phpcs:ignore WordPress.DB.SlowDBQuery
			)
		);
		if ( empty( $posts ) ) {
			return '';
		}
		$html = '<h3 class="exp-subhead">' . esc_html__( 'Your posts in this category', 'external-portal' ) . '</h3><ul class="exp-list">';
		foreach ( $posts as $p ) {
			$url   = add_query_arg(
				array(
					'view'    => self::SLUG,
					'cat_id'  => $cat_id,
					'post_id' => $p->ID,
				),
				external_portal()->dashboard_url()
			);
			$html .= '<li class="exp-list__item"><a class="exp-link" href="' . esc_url( $url ) . '">' . esc_html( $p->post_title ? $p->post_title : '#' . $p->ID ) . '</a></li>';
		}
		return $html . '</ul>';
	}

	/**
	 * Handle submission.
	 *
	 * @param array $ctx Context.
	 * @return array Notices.
	 */
	public static function handle( array $ctx ) {
		$user   = $ctx['user'];
		$cat_id = isset( $_POST['exp_cat_id'] ) ? (int) $_POST['exp_cat_id'] : 0;

		if ( ! EXP_Permissions::user_can( $user->id, self::CAP, (string) $cat_id ) ) {
			return array( array( 'type' => 'error', 'text' => __( 'You do not have posting access to that category.', 'external-portal' ) ) );
		}

		$post_id = isset( $_POST['exp_post_id'] ) ? (int) $_POST['exp_post_id'] : 0;
		if ( $post_id && (int) get_post_meta( $post_id, '_exp_submitted_by', true ) !== (int) $user->id ) {
			return array( array( 'type' => 'error', 'text' => __( 'You can only edit posts you submitted.', 'external-portal' ) ) );
		}

		$title = isset( $_POST['exp_title'] ) ? sanitize_text_field( wp_unslash( $_POST['exp_title'] ) ) : '';
		if ( '' === $title ) {
			return array( array( 'type' => 'error', 'text' => __( 'A title is required.', 'external-portal' ) ) );
		}

		$payload = array(
			'post_id'       => $post_id,
			'category_id'   => $cat_id,
			'title'         => $title,
			'excerpt'       => isset( $_POST['exp_excerpt'] ) ? sanitize_text_field( wp_unslash( $_POST['exp_excerpt'] ) ) : '',
			'content'       => isset( $_POST['exp_content'] ) ? wp_kses_post( wp_unslash( $_POST['exp_content'] ) ) : '',
			'featured_url'  => isset( $_POST['exp_featured_url'] ) ? esc_url_raw( wp_unslash( $_POST['exp_featured_url'] ) ) : '',
			'submitted_by'  => (int) $user->id,
		);

		$id = EXP_Queue::submit(
			array(
				'type'         => self::TYPE,
				'submitted_by' => $user->id,
				'content_ref'  => $post_id ? 'post:' . $post_id : 'category:' . $cat_id,
				'payload'      => $payload,
			)
		);
		if ( is_wp_error( $id ) ) {
			return array( array( 'type' => 'error', 'text' => $id->get_error_message() ) );
		}
		return array( array( 'type' => 'success', 'text' => __( 'Your post was submitted for review.', 'external-portal' ) ) );
	}

	/**
	 * Apply on approval.
	 *
	 * @param object $item Queue item.
	 * @return true|WP_Error
	 */
	public static function apply( $item ) {
		$data = $item->payload_data;

		$postarr = array(
			'post_type'    => 'post',
			'post_status'  => 'publish',
			'post_title'   => isset( $data['title'] ) ? $data['title'] : '',
			'post_excerpt' => isset( $data['excerpt'] ) ? $data['excerpt'] : '',
			'post_content' => isset( $data['content'] ) ? $data['content'] : '',
			'post_category' => array( (int) ( $data['category_id'] ?? 0 ) ),
		);

		if ( ! empty( $data['post_id'] ) ) {
			$postarr['ID'] = (int) $data['post_id'];
			$post_id       = wp_update_post( $postarr, true );
		} else {
			$post_id = wp_insert_post( $postarr, true );
		}
		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		update_post_meta( $post_id, '_exp_submitted_by', (int) ( $data['submitted_by'] ?? 0 ) );

		// Sideload featured image if provided.
		if ( ! empty( $data['featured_url'] ) ) {
			require_once ABSPATH . 'wp-admin/includes/media.php';
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/image.php';
			$attach_id = media_sideload_image( $data['featured_url'], $post_id, null, 'id' );
			if ( ! is_wp_error( $attach_id ) ) {
				set_post_thumbnail( $post_id, $attach_id );
			}
		}
		return true;
	}

	/**
	 * Review preview.
	 *
	 * @param object $item Queue item.
	 * @return string
	 */
	public static function review( $item ) {
		$data = $item->payload_data;
		$term = get_term( (int) ( $data['category_id'] ?? 0 ), 'category' );
		$html = '<p><strong>' . esc_html__( 'Category:', 'external-portal' ) . '</strong> ' . esc_html( ( $term && ! is_wp_error( $term ) ) ? $term->name : '' ) . '</p>';
		$html .= '<p><strong>' . esc_html__( 'Title:', 'external-portal' ) . '</strong> ' . esc_html( $data['title'] ?? '' ) . '</p>';
		if ( ! empty( $data['excerpt'] ) ) {
			$html .= '<p><strong>' . esc_html__( 'Excerpt:', 'external-portal' ) . '</strong> ' . esc_html( $data['excerpt'] ) . '</p>';
		}
		$html .= '<details><summary>' . esc_html__( 'Content', 'external-portal' ) . '</summary><pre class="exp-pre">' . esc_html( $data['content'] ?? '' ) . '</pre></details>';
		return $html;
	}

	/**
	 * My Activity line.
	 *
	 * @param object $item Queue item.
	 * @return string
	 */
	public static function activity( $item ) {
		$data = $item->payload_data;
		return sprintf(
			/* translators: %s: post title */
			esc_html__( 'Post: %s', 'external-portal' ),
			esc_html( $data['title'] ?? '' )
		);
	}
}
