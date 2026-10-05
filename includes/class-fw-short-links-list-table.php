<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * The links list. Native WP_List_Table, so sorting, pagination, bulk actions
 * and screen options are core's. Authors see only their own links.
 */
class FW_Short_Links_List_Table extends WP_List_Table {

	/** @var array */
	private $counts = array();

	/** @var array link id => category names */
	private $cats = array();

	public function __construct() {
		parent::__construct( array(
			'singular' => 'short-link',
			'plural'   => 'short-links',
			'ajax'     => false,
		) );
	}

	/**
	 * @return array
	 */
	public static function filters() {
		$view    = isset( $_GET['view'] ) ? sanitize_key( wp_unslash( $_GET['view'] ) ) : 'all'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$orderby = isset( $_GET['orderby'] ) ? sanitize_key( wp_unslash( $_GET['orderby'] ) ) : 'created_at'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		return array(
			'view'     => in_array( $view, array( 'all', 'enabled', 'disabled', 'trash' ), true ) ? $view : 'all',
			'search'   => isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			'category' => isset( $_GET['category'] ) ? (int) $_GET['category'] : 0, // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			'orderby'  => in_array( $orderby, FW_Short_Links_Repository::SORTABLE, true ) ? $orderby : 'created_at',
			'order'    => isset( $_GET['order'] ) && 'asc' === strtolower( sanitize_key( wp_unslash( $_GET['order'] ) ) ) ? 'ASC' : 'DESC', // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			'owner_id' => FW_Short_Links_Service::can_manage() ? 0 : get_current_user_id(),
		);
	}

	public function prepare_items() {
		$per_page = $this->get_items_per_page( 'fw_short_links_per_page', 20 );
		$filters  = self::filters();

		$this->counts = FW_Short_Links_Repository::counts( $filters['owner_id'] );

		$result = FW_Short_Links_Repository::query( array_merge( $filters, array(
			'per_page' => $per_page,
			'page'     => $this->get_pagenum(),
		) ) );

		$this->items = $result['rows'];
		$this->cats  = FW_Short_Links_Categories::names_for( wp_list_pluck( $this->items, 'id' ) );

		$this->set_pagination_args( array(
			'total_items' => $result['total'],
			'per_page'    => $per_page,
		) );

		$this->_column_headers = array( $this->get_columns(), array(), $this->get_sortable_columns(), 'title' );
	}

	/* ---------------------------------------------------------------------- *
	 * Views + bulk
	 * ---------------------------------------------------------------------- */

	protected function get_views() {
		$current = self::filters()['view'];
		$labels  = array(
			'all'      => __( 'All', 'fw' ),
			'enabled'  => __( 'Enabled', 'fw' ),
			'disabled' => __( 'Disabled', 'fw' ),
			'trash'    => __( 'Trash', 'fw' ),
		);
		$views   = array();

		foreach ( $labels as $view => $label ) {
			if ( 'trash' === $view && ! $this->counts['trash'] ) {
				continue;
			}

			$url = 'all' === $view ? FW_Short_Links_Admin_Page::page_url() : add_query_arg( 'view', $view, FW_Short_Links_Admin_Page::page_url() );

			$views[ $view ] = sprintf(
				'<a href="%s"%s>%s <span class="count">(%d)</span></a>',
				esc_url( $url ),
				$view === $current ? ' class="current" aria-current="page"' : '',
				esc_html( $label ),
				(int) $this->counts[ $view ]
			);
		}

		return $views;
	}

	protected function get_bulk_actions() {
		if ( 'trash' === self::filters()['view'] ) {
			return array(
				'restore' => __( 'Restore', 'fw' ),
				'delete'  => __( 'Delete permanently', 'fw' ),
			);
		}

		return array(
			'enable'       => __( 'Enable', 'fw' ),
			'disable'      => __( 'Disable', 'fw' ),
			'nofollow_on'  => __( 'Nofollow: on', 'fw' ),
			'nofollow_off' => __( 'Nofollow: off', 'fw' ),
			'track_on'     => __( 'Tracking: on', 'fw' ),
			'track_off'    => __( 'Tracking: off', 'fw' ),
			'reset'        => __( 'Reset clicks', 'fw' ),
			'trash'        => __( 'Move to Trash', 'fw' ),
		);
	}

	/* ---------------------------------------------------------------------- *
	 * Columns
	 * ---------------------------------------------------------------------- */

	public function get_columns() {
		$columns = array(
			'cb'            => '<input type="checkbox" />',
			'title'         => __( 'Link', 'fw' ),
			'target_url'    => __( 'Destination', 'fw' ),
			'categories'    => __( 'Categories', 'fw' ),
			'redirect_type' => __( 'Redirect', 'fw' ),
			'clicks'        => __( 'Clicks', 'fw' ),
			'uniques'       => __( 'Unique', 'fw' ),
		);

		if ( FW_Short_Links_Service::can_manage() ) {
			$columns['owner_id'] = __( 'Owner', 'fw' );
		}

		$columns['created_at'] = __( 'Created', 'fw' );

		return $columns;
	}

	protected function get_sortable_columns() {
		return array(
			'title'      => array( 'title', false ),
			'clicks'     => array( 'clicks', true ),
			'uniques'    => array( 'uniques', true ),
			'created_at' => array( 'created_at', true ),
		);
	}

	public function no_items() {
		if ( 'trash' === self::filters()['view'] ) {
			esc_html_e( 'The trash is empty.', 'fw' );

			return;
		}

		printf(
			/* translators: %s: Add New link */
			esc_html__( 'No short links yet. %s', 'fw' ),
			'<a href="' . esc_url( FW_Short_Links_Admin_Page::page_url( 'new' ) ) . '">' . esc_html__( 'Create your first one', 'fw' ) . '</a>'
		);
	}

	protected function column_cb( $item ) {
		return sprintf( '<input type="checkbox" name="ids[]" value="%d" />', (int) $item->id );
	}

	protected function column_title( $item ) {
		$short = FW_Short_Links_Service::short_url( $item->slug );
		$edit  = FW_Short_Links_Admin_Page::edit_url( $item->id );
		$label = '' !== $item->title ? $item->title : '/' . $item->slug;
		$trash = null !== $item->deleted_at;

		$out = $trash
			? '<strong>' . esc_html( $label ) . '</strong>'
			: '<strong><a class="row-title" href="' . esc_url( $edit ) . '">' . esc_html( $label ) . '</a></strong>';

		if ( 'disabled' === $item->status ) {
			$out .= ' <span class="fw-sl-pill fw-sl-pill--off">' . esc_html__( 'Disabled', 'fw' ) . '</span>';
		}

		$out .= '<div class="fw-sl-short"><code>' . esc_html( $short ) . '</code> '
			. '<button type="button" class="button-link fw-sl-copy" data-copy="' . esc_attr( $short ) . '">' . esc_html__( 'Copy', 'fw' ) . '</button></div>';

		if ( $trash ) {
			$actions = array(
				'restore' => '<a href="' . esc_url( FW_Short_Links_Admin_Page::action_url( 'restore', $item->id ) ) . '">' . esc_html__( 'Restore', 'fw' ) . '</a>',
				'delete'  => '<a class="submitdelete fw-sl-confirm" data-confirm="' . esc_attr__( 'Delete this link and its click history permanently?', 'fw' ) . '" href="' . esc_url( FW_Short_Links_Admin_Page::action_url( 'delete', $item->id ) ) . '">' . esc_html__( 'Delete permanently', 'fw' ) . '</a>',
			);
		} else {
			$actions = array(
				'edit'      => '<a href="' . esc_url( $edit ) . '">' . esc_html__( 'Edit & stats', 'fw' ) . '</a>',
				'visit'     => '<a href="' . esc_url( $short ) . '" target="_blank" rel="noopener">' . esc_html__( 'Test', 'fw' ) . '</a>',
				'duplicate' => '<a href="' . esc_url( FW_Short_Links_Admin_Page::action_url( 'duplicate', $item->id ) ) . '">' . esc_html__( 'Duplicate', 'fw' ) . '</a>',
				'reset'     => '<a class="fw-sl-confirm" data-confirm="' . esc_attr__( 'Reset this link\'s clicks to zero and delete its click history?', 'fw' ) . '" href="' . esc_url( FW_Short_Links_Admin_Page::action_url( 'reset', $item->id ) ) . '">' . esc_html__( 'Reset clicks', 'fw' ) . '</a>',
				'trash'     => '<a class="submitdelete" href="' . esc_url( FW_Short_Links_Admin_Page::action_url( 'trash', $item->id ) ) . '">' . esc_html__( 'Trash', 'fw' ) . '</a>',
			);
		}

		return $out . $this->row_actions( $actions );
	}

	protected function column_target_url( $item ) {
		$flags = array();

		if ( $item->nofollow ) {
			$flags[] = 'nofollow';
		}
		if ( $item->sponsored ) {
			$flags[] = 'sponsored';
		}
		if ( $item->param_forwarding ) {
			$flags[] = __( 'forwards query', 'fw' );
		}
		if ( ! $item->track ) {
			$flags[] = __( 'not tracked', 'fw' );
		}

		return '<a class="fw-sl-target" href="' . esc_url( $item->target_url ) . '" target="_blank" rel="noopener noreferrer" title="' . esc_attr( $item->target_url ) . '">' . esc_html( $item->target_url ) . '</a>'
			. ( $flags ? '<div class="fw-sl-flags">' . esc_html( implode( ' · ', $flags ) ) . '</div>' : '' );
	}

	protected function column_categories( $item ) {
		$names = $this->cats[ $item->id ] ?? array();

		return $names ? esc_html( implode( ', ', $names ) ) : '<span aria-hidden="true">&mdash;</span>';
	}

	/**
	 * Category filter beside the bulk actions.
	 *
	 * @param string $which
	 */
	protected function extra_tablenav( $which ) {
		$terms = FW_Short_Links_Categories::all();

		if ( 'top' !== $which || ! $terms ) {
			return;
		}

		$current = self::filters()['category'];

		echo '<div class="alignleft actions">';
		echo '<label class="screen-reader-text" for="fw-sl-cat-filter">' . esc_html__( 'Filter by category', 'fw' ) . '</label>';
		echo '<select name="category" id="fw-sl-cat-filter"><option value="0">' . esc_html__( 'All categories', 'fw' ) . '</option>';

		foreach ( $terms as $t ) {
			echo '<option value="' . (int) $t->term_id . '"' . selected( $current, (int) $t->term_id, false ) . '>' . esc_html( $t->name ) . '</option>';
		}

		echo '</select>';
		submit_button( __( 'Filter', 'fw' ), '', 'filter_action', false );
		echo '</div>';
	}

	protected function column_redirect_type( $item ) {
		return '<span class="fw-sl-pill">' . (int) $item->redirect_type . '</span>';
	}

	protected function column_clicks( $item ) {
		return number_format_i18n( $item->clicks );
	}

	protected function column_uniques( $item ) {
		return number_format_i18n( $item->uniques );
	}

	protected function column_owner_id( $item ) {
		$user = $item->owner_id ? get_userdata( $item->owner_id ) : null;

		return $user ? esc_html( $user->display_name ) : '&mdash;';
	}

	protected function column_created_at( $item ) {
		$ts = strtotime( $item->created_at . ' UTC' );

		return '<time datetime="' . esc_attr( gmdate( 'c', $ts ) ) . '">' . esc_html( date_i18n( get_option( 'date_format' ), $ts ) ) . '</time>';
	}
}
