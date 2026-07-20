<?php
/**
 * WP_List_Table for managing links.
 *
 * @package ACPS_Link_Shortener
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Accessible, core-styled table of short links.
 */
class ACPS_LS_List_Table extends WP_List_Table {

	/**
	 * Base admin page URL for building row-action links.
	 *
	 * @var string
	 */
	private $page_url;

	/**
	 * Constructor.
	 *
	 * @param string $page_url Admin page URL.
	 */
	public function __construct( $page_url ) {
		$this->page_url = $page_url;

		parent::__construct(
			array(
				'singular' => 'link',
				'plural'   => 'links',
				'ajax'     => false,
			)
		);
	}

	/**
	 * Column definitions.
	 *
	 * @return array
	 */
	public function get_columns() {
		return array(
			'title'       => __( 'Title', 'acps-link-shortener' ),
			'short_url'   => __( 'Short URL', 'acps-link-shortener' ),
			'destination' => __( 'Destination', 'acps-link-shortener' ),
			'clicks'      => __( 'Clicks', 'acps-link-shortener' ),
			'status'      => __( 'Status', 'acps-link-shortener' ),
			'created_at'  => __( 'Created', 'acps-link-shortener' ),
		);
	}

	/**
	 * Sortable columns.
	 *
	 * @return array
	 */
	protected function get_sortable_columns() {
		return array(
			'title'      => array( 'title', false ),
			'clicks'     => array( 'clicks', false ),
			'status'     => array( 'is_active', false ),
			'created_at' => array( 'created_at', true ),
		);
	}

	/**
	 * Build the short URL for a slug on the primary domain.
	 *
	 * @param string $slug Slug.
	 * @return string
	 */
	public static function short_url( $slug ) {
		return home_url( '/' . ACPS_LS_SLUG_PREFIX . '/' . $slug );
	}

	/**
	 * Query rows and set up pagination.
	 */
	public function prepare_items() {
		$per_page = 20;
		$paged    = $this->get_pagenum();

		// Nonce-verified search box value (read-only display use).
		$search  = isset( $_REQUEST['s'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['s'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$orderby = isset( $_REQUEST['orderby'] ) ? sanitize_key( $_REQUEST['orderby'] ) : 'created_at'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$order   = isset( $_REQUEST['order'] ) ? sanitize_key( $_REQUEST['order'] ) : 'desc'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		$result = ACPS_LS_DB::get_links(
			array(
				'search'   => $search,
				'per_page' => $per_page,
				'paged'    => $paged,
				'orderby'  => $orderby,
				'order'    => $order,
			)
		);

		$this->items = $result['items'];

		$this->set_pagination_args(
			array(
				'total_items' => $result['total'],
				'per_page'    => $per_page,
				'total_pages' => (int) ceil( $result['total'] / $per_page ),
			)
		);

		$this->_column_headers = array( $this->get_columns(), array(), $this->get_sortable_columns(), 'title' );
	}

	/**
	 * Title column, with row actions.
	 *
	 * @param object $item Row.
	 * @return string
	 */
	public function column_title( $item ) {
		$edit_url = add_query_arg(
			array(
				'action' => 'edit',
				'id'     => (int) $item->id,
			),
			$this->page_url
		);

		$toggle_action = $item->is_active ? 'deactivate' : 'activate';
		$toggle_label  = $item->is_active ? __( 'Deactivate', 'acps-link-shortener' ) : __( 'Activate', 'acps-link-shortener' );

		$toggle_url = wp_nonce_url(
			add_query_arg(
				array(
					'action' => $toggle_action,
					'id'     => (int) $item->id,
				),
				$this->page_url
			),
			'acps_ls_' . $toggle_action . '_' . $item->id
		);

		$delete_url = wp_nonce_url(
			add_query_arg(
				array(
					'action' => 'delete',
					'id'     => (int) $item->id,
				),
				$this->page_url
			),
			'acps_ls_delete_' . $item->id
		);

		$title = $item->title ? $item->title : $item->slug;

		$actions = array(
			'edit'         => sprintf( '<a href="%s">%s</a>', esc_url( $edit_url ), esc_html__( 'Edit', 'acps-link-shortener' ) ),
			$toggle_action => sprintf( '<a href="%s">%s</a>', esc_url( $toggle_url ), esc_html( $toggle_label ) ),
			'delete'       => sprintf(
				'<a href="%s" class="acps-ls-delete" onclick="return confirm(\'%s\');">%s</a>',
				esc_url( $delete_url ),
				esc_js( __( 'Delete this link permanently? This cannot be undone.', 'acps-link-shortener' ) ),
				esc_html__( 'Delete', 'acps-link-shortener' )
			),
		);

		return sprintf(
			'<strong><a class="row-title" href="%s">%s</a></strong>%s',
			esc_url( $edit_url ),
			esc_html( $title ),
			$this->row_actions( $actions )
		);
	}

	/**
	 * Short URL column with an accessible copy button.
	 *
	 * @param object $item Row.
	 * @return string
	 */
	public function column_short_url( $item ) {
		$url = self::short_url( $item->slug );

		// The copy button has a text label + aria-label including the slug so a
		// screen reader user knows exactly which link is being copied. Success
		// is announced via an aria-live region (see admin.js).
		return sprintf(
			'<code class="acps-ls-shorturl">%1$s</code>
			<button type="button" class="button button-small acps-ls-copy" data-clipboard-text="%2$s" aria-label="%3$s">%4$s</button>',
			esc_html( $url ),
			esc_attr( $url ),
			/* translators: %s: short URL. */
			esc_attr( sprintf( __( 'Copy short URL %s to clipboard', 'acps-link-shortener' ), $url ) ),
			esc_html__( 'Copy', 'acps-link-shortener' )
		);
	}

	/**
	 * Destination column.
	 *
	 * @param object $item Row.
	 * @return string
	 */
	public function column_destination( $item ) {
		return sprintf(
			'<a href="%1$s" target="_blank" rel="noopener noreferrer">%2$s</a>',
			esc_url( $item->destination ),
			esc_html( wp_trim_words( $item->destination, 12, '&hellip;' ) )
		);
	}

	/**
	 * Clicks column.
	 *
	 * @param object $item Row.
	 * @return string
	 */
	public function column_clicks( $item ) {
		$count = number_format_i18n( (int) $item->clicks );

		if ( $item->last_clicked_at && '1970-01-01 00:00:00' !== $item->last_clicked_at ) {
			return sprintf(
				'%1$s<br /><span class="description">%2$s %3$s</span>',
				esc_html( $count ),
				esc_html__( 'Last:', 'acps-link-shortener' ),
				esc_html( mysql2date( get_option( 'date_format' ), $item->last_clicked_at ) )
			);
		}

		return esc_html( $count );
	}

	/**
	 * Status column. Uses a text label + icon, never color alone (WCAG 1.4.1).
	 *
	 * @param object $item Row.
	 * @return string
	 */
	public function column_status( $item ) {
		if ( $item->is_active ) {
			return sprintf(
				'<span class="acps-ls-status acps-ls-status--active"><span aria-hidden="true">&#9679;</span> %s</span>',
				esc_html__( 'Active', 'acps-link-shortener' )
			);
		}

		return sprintf(
			'<span class="acps-ls-status acps-ls-status--inactive"><span aria-hidden="true">&#9675;</span> %s</span>',
			esc_html__( 'Inactive', 'acps-link-shortener' )
		);
	}

	/**
	 * Created column.
	 *
	 * @param object $item Row.
	 * @return string
	 */
	public function column_created_at( $item ) {
		$label = mysql2date( get_option( 'date_format' ), $item->created_at );
		if ( 'sheet' === $item->source ) {
			$label .= '<br /><span class="description">' . esc_html__( 'via Sheet sync', 'acps-link-shortener' ) . '</span>';
		}
		return wp_kses_post( $label );
	}

	/**
	 * Fallback for any column without a dedicated method.
	 *
	 * @param object $item        Row.
	 * @param string $column_name Column key.
	 * @return string
	 */
	public function column_default( $item, $column_name ) {
		return isset( $item->$column_name ) ? esc_html( $item->$column_name ) : '';
	}

	/**
	 * Message when there are no links yet.
	 */
	public function no_items() {
		esc_html_e( 'No short links yet. Use “Add New Link” to create one.', 'acps-link-shortener' );
	}
}
