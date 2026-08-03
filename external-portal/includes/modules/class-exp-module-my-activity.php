<?php
/**
 * Module: My Activity (spec Section 5.5).
 *
 * Shows the logged-in portal user their own submission history from the shared
 * queue: what they submitted, its status, and any admin notes. Status is a clear
 * TEXT label (WCAG 1.4.1 — never colour alone). Third-party queue types are
 * summarised via their registered activity formatter.
 *
 * @package ExternalPortal
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * My Activity module.
 */
class EXP_Module_My_Activity {

	const SLUG = 'my_activity';

	/**
	 * Register.
	 *
	 * @param EXP_Registry $r Registry.
	 */
	public static function register( $r ) {
		$r->register_menu_item(
			array(
				'slug'       => self::SLUG,
				'label'      => __( 'My Activity', 'external-portal' ),
				'icon'       => 'list-view',
				'capability' => '', // Always available to any signed-in portal user.
				'render'     => array( __CLASS__, 'render' ),
				'position'   => 80,
				'core'       => true,
			)
		);
	}

	/**
	 * Human, translated status label. Text conveys meaning, not colour.
	 *
	 * @param string $status Status.
	 * @return string
	 */
	public static function status_label( $status ) {
		switch ( $status ) {
			case EXP_Queue::STATUS_APPROVED:
				return __( 'Approved', 'external-portal' );
			case EXP_Queue::STATUS_REJECTED:
				return __( 'Rejected — see note', 'external-portal' );
			case EXP_Queue::STATUS_PENDING:
			default:
				return __( 'Pending review', 'external-portal' );
		}
	}

	/**
	 * Render.
	 *
	 * @param array $ctx Context.
	 * @return string
	 */
	public static function render( array $ctx ) {
		$user = $ctx['user'];
		$page = isset( $_GET['ap'] ) ? max( 1, (int) $_GET['ap'] ) : 1; // phpcs:ignore WordPress.Security.NonceVerification

		$result = EXP_Queue::query(
			array(
				'submitted_by' => $user->id,
				'per_page'     => 20,
				'page'         => $page,
			)
		);

		if ( empty( $result['rows'] ) ) {
			return EXP_UI::notice( 'info', __( 'You have not submitted anything yet.', 'external-portal' ) );
		}

		$registry = EXP_Registry::instance();

		$html  = '<p>' . esc_html__( 'Your submissions and their current status.', 'external-portal' ) . '</p>';
		$html .= '<table class="exp-table"><caption class="screen-reader-text">' . esc_html__( 'Your submission history', 'external-portal' ) . '</caption>';
		$html .= '<thead><tr>';
		$html .= '<th scope="col">' . esc_html__( 'Submitted', 'external-portal' ) . '</th>';
		$html .= '<th scope="col">' . esc_html__( 'Item', 'external-portal' ) . '</th>';
		$html .= '<th scope="col">' . esc_html__( 'Type', 'external-portal' ) . '</th>';
		$html .= '<th scope="col">' . esc_html__( 'Status', 'external-portal' ) . '</th>';
		$html .= '<th scope="col">' . esc_html__( 'Reviewer note', 'external-portal' ) . '</th>';
		$html .= '</tr></thead><tbody>';

		foreach ( $result['rows'] as $row ) {
			$summary   = self::summarize( $registry, $row );
			$status    = self::status_label( $row->status );
			$submitted = self::local_date( $row->created_at );

			$html .= '<tr>';
			$html .= '<td>' . esc_html( $submitted ) . '</td>';
			$html .= '<td>' . esc_html( $summary ) . '</td>';
			$html .= '<td>' . esc_html( EXP_Queue::type_label( $row->type ) ) . '</td>';
			$html .= '<td><span class="exp-status exp-status--' . esc_attr( $row->status ) . '">' . esc_html( $status ) . '</span></td>';
			$html .= '<td>' . ( $row->admin_notes ? esc_html( $row->admin_notes ) : '<span aria-hidden="true">&mdash;</span><span class="screen-reader-text">' . esc_html__( 'none', 'external-portal' ) . '</span>' ) . '</td>';
			$html .= '</tr>';
		}
		$html .= '</tbody></table>';

		$html .= self::pager( $page, (int) $result['total'], 20 );
		return $html;
	}

	/**
	 * Summarise a row via its type's activity formatter, with a safe fallback.
	 *
	 * @param EXP_Registry $registry Registry.
	 * @param object       $row      Queue row (payload_data populated).
	 * @return string
	 */
	protected static function summarize( $registry, $row ) {
		$formatter = $registry->activity_formatter( $row->type );
		if ( is_callable( $formatter ) ) {
			$label = (string) call_user_func( $formatter, $row );
			// Formatter may return escaped HTML; strip to plain text for the cell.
			return wp_strip_all_tags( $label );
		}
		return EXP_Queue::type_label( $row->type );
	}

	/**
	 * Format a stored UTC datetime in the site's timezone.
	 *
	 * @param string $utc UTC MySQL datetime.
	 * @return string
	 */
	protected static function local_date( $utc ) {
		$ts = strtotime( $utc . ' UTC' );
		return $ts ? wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $ts ) : $utc;
	}

	/**
	 * Simple accessible pager.
	 *
	 * @param int $page     Current page.
	 * @param int $total    Total rows.
	 * @param int $per_page Rows per page.
	 * @return string
	 */
	protected static function pager( $page, $total, $per_page ) {
		$pages = (int) ceil( $total / max( 1, $per_page ) );
		if ( $pages <= 1 ) {
			return '';
		}
		$base = add_query_arg( 'view', self::SLUG, external_portal()->dashboard_url() );
		$html = '<nav class="exp-pager" aria-label="' . esc_attr__( 'Activity pages', 'external-portal' ) . '"><ul class="exp-pager__list">';
		for ( $i = 1; $i <= $pages; $i++ ) {
			$url  = add_query_arg( 'ap', $i, $base );
			$cur  = ( $i === $page );
			$html .= '<li>' . ( $cur
				? '<span class="exp-pager__current" aria-current="page">' . esc_html( $i ) . '</span>'
				: '<a class="exp-link" href="' . esc_url( $url ) . '">' . esc_html( $i ) . '</a>' ) . '</li>';
		}
		return $html . '</ul></nav>';
	}
}
