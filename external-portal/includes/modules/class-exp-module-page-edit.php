<?php
/**
 * Module: Page Content Editing (spec Section 5.1).
 *
 * The portal user gets a simple form editor for a granted page's title/content —
 * NOT Beaver Builder. Submissions go to the Content Update Queue for review.
 *
 * Granularity decision (spec Q2): whole-page for v1. The grant target is the page
 * ID and the queue payload carries the full proposed content, structured so that
 * field/region scoping can be layered on later without a schema change.
 *
 * @package ExternalPortal
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Page-edit module.
 */
class EXP_Module_Page_Edit {

	const CAP  = 'edit_page';
	const SLUG = 'page_edit';
	const TYPE = 'page_edit';

	/**
	 * Register with the portal.
	 *
	 * @param EXP_Registry $r Registry.
	 */
	public static function register( $r ) {
		$r->register_capability(
			array(
				'key'            => self::CAP,
				'label'          => __( 'Edit page content', 'external-portal' ),
				'description'    => __( 'Submit content changes for specific pages (reviewed before publishing).', 'external-portal' ),
				'target_type'    => 'page',
				'target_options' => array( __CLASS__, 'page_options' ),
				'module'         => self::SLUG,
				'core'           => true,
			)
		);
		$r->register_menu_item(
			array(
				'slug'       => self::SLUG,
				'label'      => __( 'Edit Pages', 'external-portal' ),
				'icon'       => 'edit',
				'capability' => self::CAP,
				'render'     => array( __CLASS__, 'render' ),
				'handle'     => array( __CLASS__, 'handle' ),
				'position'   => 10,
				'core'       => true,
			)
		);
		$r->register_queue_type(
			array(
				'type'            => self::TYPE,
				'label'           => __( 'Page edit', 'external-portal' ),
				'review_renderer' => array( __CLASS__, 'review' ),
				'applier'         => array( __CLASS__, 'apply' ),
				'core'            => true,
			)
		);
		$r->register_activity_formatter( self::TYPE, array( __CLASS__, 'activity' ) );
	}

	/**
	 * Admin target option list: published pages.
	 *
	 * @return array<int,string> id => title.
	 */
	public static function page_options() {
		$pages = get_posts(
			array(
				'post_type'      => 'page',
				'post_status'    => array( 'publish', 'draft', 'private' ),
				'posts_per_page' => 200,
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		);
		$out = array();
		foreach ( $pages as $p ) {
			$out[ $p->ID ] = $p->post_title ? $p->post_title : sprintf( __( '(no title) #%d', 'external-portal' ), $p->ID );
		}
		return $out;
	}

	/**
	 * Render the module panel body.
	 *
	 * @param array $ctx Module context.
	 * @return string
	 */
	public static function render( array $ctx ) {
		$user     = $ctx['user'];
		$targets  = array_map( 'intval', EXP_Permissions::targets_for( $user->id, self::CAP ) );
		$page_id  = isset( $_GET['page_id'] ) ? (int) $_GET['page_id'] : 0; // phpcs:ignore WordPress.Security.NonceVerification

		if ( empty( $targets ) ) {
			return EXP_UI::notice( 'info', __( 'You have not been granted access to edit any pages yet.', 'external-portal' ) );
		}

		// Detail editor for a specific granted page.
		if ( $page_id && in_array( $page_id, $targets, true ) ) {
			return self::render_editor( $ctx, $page_id );
		}

		// Otherwise, list the pages the user may edit.
		$html  = '<p>' . esc_html__( 'Choose a page to submit a content update. Changes are reviewed before they go live.', 'external-portal' ) . '</p>';
		$html .= '<ul class="exp-list">';
		foreach ( $targets as $pid ) {
			$title = get_the_title( $pid );
			$url   = add_query_arg(
				array(
					'view'    => self::SLUG,
					'page_id' => $pid,
				),
				external_portal()->dashboard_url()
			);
			$html .= '<li class="exp-list__item"><a class="exp-link" href="' . esc_url( $url ) . '">' . esc_html( $title ? $title : '#' . $pid ) . '</a></li>';
		}
		$html .= '</ul>';
		return $html;
	}

	/**
	 * The simple editor form for one page.
	 *
	 * @param array $ctx     Context.
	 * @param int   $page_id Page id.
	 * @return string
	 */
	protected static function render_editor( array $ctx, $page_id ) {
		$post = get_post( $page_id );
		if ( ! $post ) {
			return EXP_UI::notice( 'error', __( 'That page could not be found.', 'external-portal' ) );
		}

		$back = add_query_arg( 'view', self::SLUG, external_portal()->dashboard_url() );

		$html  = '<p><a class="exp-link" href="' . esc_url( $back ) . '">&larr; ' . esc_html__( 'Back to page list', 'external-portal' ) . '</a></p>';
		$html .= '<form class="exp-form" method="post">';
		$html .= EXP_UI::module_hidden_fields( $ctx );
		$html .= '<input type="hidden" name="exp_page_id" value="' . esc_attr( $page_id ) . '" />';
		$html .= EXP_UI::field(
			array(
				'name'     => 'exp_title',
				'label'    => __( 'Page title', 'external-portal' ),
				'value'    => $post->post_title,
				'required' => true,
			)
		);

		// Simple content editor (plain, accessible textarea — not the page builder).
		$ta_id = 'exp-page-content';
		$html .= '<div class="exp-field">';
		$html .= '<label class="exp-field__label" for="' . esc_attr( $ta_id ) . '">' . esc_html__( 'Page content', 'external-portal' ) . '</label>';
		$html .= '<p class="exp-field__help" id="' . esc_attr( $ta_id ) . '-help">' . esc_html__( 'Basic HTML is allowed. Your change will be reviewed before it appears on the site.', 'external-portal' ) . '</p>';
		$html .= '<textarea class="exp-field__input exp-textarea" id="' . esc_attr( $ta_id ) . '" name="exp_content" rows="14" aria-describedby="' . esc_attr( $ta_id ) . '-help">' . esc_textarea( $post->post_content ) . '</textarea>';
		$html .= '</div>';

		$html .= EXP_UI::field(
			array(
				'name'  => 'exp_note',
				'label' => __( 'Note for the reviewer (optional)', 'external-portal' ),
			)
		);
		$html .= '<button type="submit" class="exp-button">' . esc_html__( 'Submit for review', 'external-portal' ) . '</button>';
		$html .= '</form>';
		return $html;
	}

	/**
	 * Handle a submission.
	 *
	 * @param array $ctx Context.
	 * @return array Notices.
	 */
	public static function handle( array $ctx ) {
		$user    = $ctx['user'];
		$page_id = isset( $_POST['exp_page_id'] ) ? (int) $_POST['exp_page_id'] : 0;

		if ( ! EXP_Permissions::user_can( $user->id, self::CAP, (string) $page_id ) ) {
			return array( array( 'type' => 'error', 'text' => __( 'You do not have permission to edit that page.', 'external-portal' ) ) );
		}
		$post = get_post( $page_id );
		if ( ! $post ) {
			return array( array( 'type' => 'error', 'text' => __( 'That page could not be found.', 'external-portal' ) ) );
		}

		$title   = isset( $_POST['exp_title'] ) ? sanitize_text_field( wp_unslash( $_POST['exp_title'] ) ) : '';
		$content = isset( $_POST['exp_content'] ) ? wp_kses_post( wp_unslash( $_POST['exp_content'] ) ) : '';
		$note    = isset( $_POST['exp_note'] ) ? sanitize_text_field( wp_unslash( $_POST['exp_note'] ) ) : '';

		if ( '' === $title ) {
			return array( array( 'type' => 'error', 'text' => __( 'A page title is required.', 'external-portal' ) ) );
		}

		$id = EXP_Queue::submit(
			array(
				'type'         => self::TYPE,
				'submitted_by' => $user->id,
				'content_ref'  => 'page:' . $page_id,
				'payload'      => array(
					'page_id'      => $page_id,
					'title'        => $title,
					'content'      => $content,
					'note'         => $note,
					'target_scope' => 'whole_page', // Forward-compatible with future field scoping.
				),
			)
		);
		if ( is_wp_error( $id ) ) {
			return array( array( 'type' => 'error', 'text' => $id->get_error_message() ) );
		}
		return array( array( 'type' => 'success', 'text' => __( 'Your page update was submitted for review.', 'external-portal' ) ) );
	}

	/**
	 * Apply on approval.
	 *
	 * @param object $item Queue item (payload_data populated).
	 * @return true|WP_Error
	 */
	public static function apply( $item ) {
		$data = $item->payload_data;
		if ( empty( $data['page_id'] ) ) {
			return new WP_Error( 'exp_apply_page', __( 'Missing page reference.', 'external-portal' ) );
		}
		$res = wp_update_post(
			array(
				'ID'           => (int) $data['page_id'],
				'post_title'   => isset( $data['title'] ) ? $data['title'] : '',
				'post_content' => isset( $data['content'] ) ? $data['content'] : '',
			),
			true
		);
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		return true;
	}

	/**
	 * Admin review preview.
	 *
	 * @param object $item Queue item.
	 * @return string
	 */
	public static function review( $item ) {
		$data  = $item->payload_data;
		$title = isset( $data['title'] ) ? $data['title'] : '';
		$html  = '<p><strong>' . esc_html__( 'Page:', 'external-portal' ) . '</strong> ' . esc_html( get_the_title( (int) ( $data['page_id'] ?? 0 ) ) ) . '</p>';
		$html .= '<p><strong>' . esc_html__( 'Proposed title:', 'external-portal' ) . '</strong> ' . esc_html( $title ) . '</p>';
		if ( ! empty( $data['note'] ) ) {
			$html .= '<p><strong>' . esc_html__( 'Submitter note:', 'external-portal' ) . '</strong> ' . esc_html( $data['note'] ) . '</p>';
		}
		$html .= '<details><summary>' . esc_html__( 'Proposed content', 'external-portal' ) . '</summary><pre class="exp-pre">' . esc_html( $data['content'] ?? '' ) . '</pre></details>';
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
			/* translators: %s: page title */
			esc_html__( 'Page edit: %s', 'external-portal' ),
			esc_html( get_the_title( (int) ( $data['page_id'] ?? 0 ) ) )
		);
	}
}
