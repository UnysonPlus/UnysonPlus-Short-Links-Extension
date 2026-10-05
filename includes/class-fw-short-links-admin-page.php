<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * Unyson+ → Short Links.
 *
 * House conventions (as the Newsletter CRM / Forms entries screens):
 *  - add_submenu_page() under `fw-extensions`, placed via
 *    `fw_unysonplus_admin_submenu_order`;
 *  - EVERY action runs on `load-{$hook_suffix}` before output, then PRG-redirects,
 *    so a refresh never repeats it; notices cross the redirect in a transient;
 *  - native `nav-tab-wrapper` tabs; the list is a WP_List_Table; settings are an
 *    options array rendered by fw()->backend->render_options().
 *
 * Also owns the two Quick Add surfaces: the admin-bar "+ New → Short Link" and
 * the dashboard widget, which posts to this page's `quick_add` action.
 */
class FW_Short_Links_Admin_Page {

	const PARENT_SLUG      = 'fw-extensions';
	const PAGE_SLUG        = 'fw-short-links';
	const NONCE            = 'fw_short_links_action';
	const TRANSIENT_NOTICE = 'fw_ext_short_links_notice_';
	const TRANSIENT_FORM   = 'fw_ext_short_links_form_';

	/** @var FW_Extension_Short_Links */
	private $ext;

	/** @var string|null */
	private $hook_suffix = null;

	/** @var FW_Short_Links_List_Table|null */
	private $table = null;

	/**
	 * @param FW_Extension_Short_Links $ext
	 */
	public function __construct( $ext ) {
		$this->ext = $ext;

		add_action( 'admin_menu', array( $this, '_action_admin_menu' ), 30 );
		add_filter( 'fw_unysonplus_admin_submenu_order', array( $this, '_filter_submenu_order' ) );
		add_filter( 'set-screen-option', array( $this, '_filter_screen_option' ), 10, 3 );
		add_filter( 'set_screen_option_fw_short_links_per_page', array( $this, '_filter_screen_option' ), 10, 3 );
		add_action( 'wp_dashboard_setup', array( $this, '_action_dashboard_widget' ) );

		// ONE settings screen: the Extensions card links to our Settings tab, and
		// the manager's generic form for this extension redirects there.
		add_filter( 'fw_ext_manager_settings_url', array( $this, '_filter_manager_settings_url' ), 10, 2 );
		add_action( 'admin_init', array( $this, '_redirect_manager_settings' ) );
	}

	/**
	 * @internal
	 *
	 * @param string $url
	 * @param string $name
	 *
	 * @return string
	 */
	public function _filter_manager_settings_url( $url, $name ) {
		return 'short-links' === $name ? self::page_url( 'settings' ) : $url;
	}

	/**
	 * @internal
	 */
	public function _redirect_manager_settings() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- a read-only redirect
		if ( isset( $_GET['page'], $_GET['sub-page'], $_GET['extension'] )
			&& 'fw-extensions' === $_GET['page'] && 'extension' === $_GET['sub-page'] && 'short-links' === $_GET['extension']
			&& 'GET' === strtoupper( (string) ( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) ) ) {
			wp_safe_redirect( self::page_url( 'settings' ) );
			exit;
		}
		// phpcs:enable
	}

	/* ---------------------------------------------------------------------- *
	 * Menu, URLs, assets
	 * ---------------------------------------------------------------------- */

	/**
	 * @internal
	 */
	public function _action_admin_menu() {
		if ( ! FW_Short_Links_Service::can_edit() ) {
			return;
		}

		$this->hook_suffix = add_submenu_page(
			self::PARENT_SLUG,
			__( 'Short Links', 'fw' ),
			__( 'Short Links', 'fw' ),
			current_user_can( 'manage_short_links' ) ? 'manage_short_links' : 'edit_short_links',
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);

		if ( $this->hook_suffix ) {
			add_action( 'load-' . $this->hook_suffix, array( $this, '_action_load' ) );
			add_action( 'admin_enqueue_scripts', array( $this, '_action_enqueue' ) );
		}
	}

	/**
	 * @internal
	 */
	public function _filter_submenu_order( $order ) {
		if ( ! in_array( self::PAGE_SLUG, (array) $order, true ) ) {
			$order[] = self::PAGE_SLUG;
		}

		return $order;
	}

	/**
	 * @internal
	 */
	public function _filter_screen_option( $status, $option, $value ) {
		return 'fw_short_links_per_page' === $option ? max( 1, min( 500, (int) $value ) ) : $status;
	}

	/**
	 * @param string $tab links|new|edit|settings
	 *
	 * @return string
	 */
	public static function page_url( $tab = '' ) {
		$url = admin_url( 'admin.php?page=' . self::PAGE_SLUG );

		return '' === $tab || 'links' === $tab ? $url : add_query_arg( 'tab', $tab, $url );
	}

	/**
	 * @param int $id
	 *
	 * @return string
	 */
	public static function edit_url( $id ) {
		return add_query_arg( 'link', (int) $id, self::page_url( 'edit' ) );
	}

	/**
	 * @param string $action
	 * @param int    $id
	 *
	 * @return string
	 */
	public static function action_url( $action, $id ) {
		return wp_nonce_url( add_query_arg( array( 'fw_sl_action' => $action, 'id' => (int) $id ), self::page_url() ), self::NONCE );
	}

	/**
	 * @return string
	 */
	private function current_tab() {
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'links'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( in_array( $tab, array( 'settings', 'api' ), true ) && ! current_user_can( 'manage_options' ) ) {
			return 'links';
		}

		if ( in_array( $tab, array( 'reports', 'transfer' ), true ) && ! FW_Short_Links_Service::can_manage() ) {
			return 'links';
		}

		return in_array( $tab, array( 'links', 'new', 'edit', 'reports', 'transfer', 'api', 'settings' ), true ) ? $tab : 'links';
	}

	/**
	 * @internal
	 */
	public function _action_enqueue( $hook ) {
		if ( $hook !== $this->hook_suffix ) {
			return;
		}

		$ver = $this->ext->manifest->get_version();

		if ( 'settings' === $this->current_tab() ) {
			fw()->backend->enqueue_options_static( $this->ext->get_settings_options() );
		}

		// `fw` in both arrays: fw.confirm() takes its look from the fw stylesheet.
		wp_enqueue_style( 'fw-ext-short-links', $this->ext->get_uri( '/static/css/admin.css' ), array( 'fw' ), $ver );
		wp_enqueue_script( 'fw-ext-short-links', $this->ext->get_uri( '/static/js/admin.js' ), array( 'jquery', 'fw' ), $ver, true );
		wp_localize_script( 'fw-ext-short-links', 'fwShortLinks', array(
			'restUrl'    => esc_url_raw( rest_url( FW_Short_Links_REST::NAMESPACE_ . '/' ) ),
			'nonce'      => wp_create_nonce( 'wp_rest' ),
			'ajaxUrl'    => admin_url( 'admin-ajax.php' ),
			'stepNonce'  => wp_create_nonce( self::NONCE ),
			'stepAction' => FW_Short_Links_Admin_Transfer::AJAX_STEP,
			'l10n'    => array(
				'copied'    => __( 'Copied', 'fw' ),
				'checking'  => __( 'Checking…', 'fw' ),
				'available' => __( 'Available', 'fw' ),
				'failed'    => __( 'Could not reach the server.', 'fw' ),
				'importing' => __( 'Importing links… %1$s of %2$s', 'fw' ),
				'clicks'    => __( 'Copying click history… %s rows so far', 'fw' ),
				'finishing' => __( 'Finishing…', 'fw' ),
				'leave'     => __( 'An import is running. Leave anyway? You can start it again from the Import / Export tab.', 'fw' ),
			),
		) );
	}

	/* ---------------------------------------------------------------------- *
	 * Actions — all on load-{hook}, all PRG
	 * ---------------------------------------------------------------------- */

	/**
	 * @internal
	 */
	public function _action_load() {
		if ( ! FW_Short_Links_Service::can_edit() ) {
			return;
		}

		$this->table = new FW_Short_Links_List_Table();

		add_screen_option( 'per_page', array(
			'label'   => __( 'Links per page', 'fw' ),
			'default' => 20,
			'option'  => 'fw_short_links_per_page',
		) );

		// Our own actions carry `fw_sl_action` (POST wins); a bulk action arrives
		// through the list table and is nonced by core as `bulk-short-links`.
		// Resolve which it is first, then verify THAT nonce.
		$action = '';
		$bulk   = $this->table->current_action();

		if ( isset( $_POST['fw_sl_action'] ) ) {
			$action = sanitize_key( wp_unslash( $_POST['fw_sl_action'] ) );
		} elseif ( isset( $_GET['fw_sl_action'] ) ) {
			$action = sanitize_key( wp_unslash( $_GET['fw_sl_action'] ) );
		}

		if ( '' !== $action ) {
			check_admin_referer( self::NONCE );
		} elseif ( $bulk && ! empty( $_REQUEST['ids'] ) ) {
			check_admin_referer( 'bulk-short-links' );
			$this->apply( $bulk, array_map( 'intval', (array) $_REQUEST['ids'] ) );
			$this->redirect( self::page_url( 'links' ) );
		} else {
			return;
		}

		switch ( $action ) {
			case 'save':
				$this->handle_save();
				break;

			case 'quick_add':
				$this->handle_quick_add();
				break;

			case 'save_settings':
				$this->handle_save_settings();
				break;

			case 'remove_data':
				$this->handle_remove_data();
				break;

			default:
				// Import / export and API & webhooks actions live with their tabs.
				$sub = FW_Short_Links_Service::can_manage() ? FW_Short_Links_Admin_Transfer::handle( $action ) : null;

				if ( null === $sub ) {
					$sub = FW_Short_Links_Admin_API::handle( $action );
				}

				if ( null !== $sub ) {
					if ( '' !== $sub[0] ) {
						$this->notice( $sub[0], $sub[1] );
					}

					$this->redirect( $sub[2] );
				}

				$id = isset( $_GET['id'] ) ? (int) $_GET['id'] : 0;
				$this->apply( $action, array( $id ) );

				$back = 'duplicate' === $action && $this->last_duplicate ? self::edit_url( $this->last_duplicate ) : wp_get_referer();
				$this->redirect( $back ? remove_query_arg( array( 'fw_sl_action', 'id', '_wpnonce' ), $back ) : self::page_url() );
		}
	}

	/** @var int */
	private $last_duplicate = 0;

	/**
	 * Single and bulk actions. Ids the user may not touch are dropped first.
	 *
	 * @param string $action
	 * @param int[]  $ids
	 */
	private function apply( $action, array $ids ) {
		$ids = array_values( array_filter( $ids, function ( $id ) {
			return FW_Short_Links_Service::can_edit_link( FW_Short_Links_Repository::find( $id ) );
		} ) );

		if ( ! $ids ) {
			$this->notice( 'error', __( 'Nothing to do — the link is gone, or you are not allowed to change it.', 'fw' ) );

			return;
		}

		$n = count( $ids );

		switch ( $action ) {
			case 'trash':
				FW_Short_Links_Service::trash( $ids );
				/* translators: %d: number of links */
				$this->notice( 'success', sprintf( _n( '%d link moved to the Trash.', '%d links moved to the Trash.', $n, 'fw' ), $n ) );
				break;

			case 'restore':
				FW_Short_Links_Service::restore( $ids );
				$this->notice( 'success', sprintf( _n( '%d link restored.', '%d links restored.', $n, 'fw' ), $n ) );
				break;

			case 'delete':
				FW_Short_Links_Service::delete( $ids );
				$this->notice( 'success', sprintf( _n( '%d link deleted permanently.', '%d links deleted permanently.', $n, 'fw' ), $n ) );
				break;

			case 'enable':
			case 'disable':
				FW_Short_Links_Repository::bulk_set( $ids, 'status = %s', array( 'enable' === $action ? 'enabled' : 'disabled' ) );
				$this->notice( 'success', sprintf( _n( '%d link updated.', '%d links updated.', $n, 'fw' ), $n ) );
				break;

			case 'nofollow_on':
			case 'nofollow_off':
				FW_Short_Links_Repository::bulk_set( $ids, 'nofollow = %d', array( 'nofollow_on' === $action ? 1 : 0 ) );
				$this->notice( 'success', sprintf( _n( '%d link updated.', '%d links updated.', $n, 'fw' ), $n ) );
				break;

			case 'track_on':
			case 'track_off':
				FW_Short_Links_Repository::bulk_set( $ids, 'track = %d', array( 'track_on' === $action ? 1 : 0 ) );
				$this->notice( 'success', sprintf( _n( '%d link updated.', '%d links updated.', $n, 'fw' ), $n ) );
				break;

			case 'reset':
				FW_Short_Links_Repository::reset_counts( $ids );
				$this->notice( 'success', sprintf( _n( 'Clicks reset on %d link.', 'Clicks reset on %d links.', $n, 'fw' ), $n ) );
				break;

			case 'duplicate':
				$copy = FW_Short_Links_Service::duplicate( $ids[0] );

				if ( is_wp_error( $copy ) ) {
					$this->notice( 'error', $copy->get_error_message() );
				} else {
					$this->last_duplicate = $copy->id;
					$this->notice( 'success', __( 'Link duplicated with a new slug. You are editing the copy.', 'fw' ) );
				}
				break;
		}
	}

	/**
	 * The add / edit form.
	 */
	private function handle_save() {
		$id    = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;
		$input = array(
			'target_url'       => isset( $_POST['target_url'] ) ? wp_unslash( $_POST['target_url'] ) : '', // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- validated by the service
			'slug'             => isset( $_POST['slug'] ) ? sanitize_text_field( wp_unslash( $_POST['slug'] ) ) : '',
			'title'            => isset( $_POST['title'] ) ? sanitize_text_field( wp_unslash( $_POST['title'] ) ) : '',
			'notes'            => isset( $_POST['notes'] ) ? sanitize_textarea_field( wp_unslash( $_POST['notes'] ) ) : '',
			'redirect_type'    => isset( $_POST['redirect_type'] ) ? (int) $_POST['redirect_type'] : 307,
			'status'           => isset( $_POST['status'] ) ? sanitize_key( wp_unslash( $_POST['status'] ) ) : 'enabled',
			'fallback_url'     => isset( $_POST['fallback_url'] ) ? wp_unslash( $_POST['fallback_url'] ) : '', // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- validated by the service
			'nofollow'         => ! empty( $_POST['nofollow'] ),
			'sponsored'        => ! empty( $_POST['sponsored'] ),
			'new_window'       => ! empty( $_POST['new_window'] ),
			'param_forwarding' => ! empty( $_POST['param_forwarding'] ),
			'track'            => ! empty( $_POST['track'] ),
			'fetch_title'      => true,
			'categories'       => array_merge(
				isset( $_POST['categories'] ) ? array_map( 'intval', (array) $_POST['categories'] ) : array(),
				isset( $_POST['new_categories'] ) ? array_filter( array_map( 'trim', explode( ',', sanitize_text_field( wp_unslash( $_POST['new_categories'] ) ) ) ) ) : array()
			),
		);

		if ( $id ) {
			if ( ! FW_Short_Links_Service::can_edit_link( FW_Short_Links_Repository::find( $id ) ) ) {
				$this->notice( 'error', __( 'You are not allowed to edit that link.', 'fw' ) );
				$this->redirect( self::page_url() );
			}

			$result = FW_Short_Links_Service::update( $id, $input, isset( $_POST['version'] ) ? (int) $_POST['version'] : 0 );
		} else {
			$result = FW_Short_Links_Service::create( $input );
		}

		if ( is_wp_error( $result ) ) {
			// Keep what they typed, show every problem at once.
			set_transient( self::TRANSIENT_FORM . get_current_user_id(), $input, 5 * MINUTE_IN_SECONDS );
			$this->notice( 'error', implode( '<br>', array_map( 'esc_html', $result->get_error_messages() ) ) );
			$this->redirect( $id ? self::edit_url( $id ) : self::page_url( 'new' ) );
		}

		$this->notice( 'success', $id
			? __( 'Link updated.', 'fw' )
			/* translators: %s: the short URL */
			: sprintf( __( 'Link created: %s', 'fw' ), '<code>' . esc_html( FW_Short_Links_Service::short_url( $result->slug ) ) . '</code>' ) );

		$this->redirect( self::edit_url( $result->id ) );
	}

	/**
	 * The dashboard widget's form: a destination and an optional slug.
	 */
	private function handle_quick_add() {
		$input = array(
			'target_url'  => isset( $_POST['target_url'] ) ? wp_unslash( $_POST['target_url'] ) : '', // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- validated by the service
			'slug'        => isset( $_POST['slug'] ) ? sanitize_text_field( wp_unslash( $_POST['slug'] ) ) : '',
			'fetch_title' => true,
		);
		$result = FW_Short_Links_Service::create( $input );

		if ( is_wp_error( $result ) ) {
			// Continue on the full form, with what they typed, where the error shows.
			set_transient( self::TRANSIENT_FORM . get_current_user_id(), $input, 5 * MINUTE_IN_SECONDS );
			$this->notice( 'error', implode( '<br>', array_map( 'esc_html', $result->get_error_messages() ) ) );
			$this->redirect( self::page_url( 'new' ) );
		}

		$this->notice( 'success', sprintf( __( 'Link created: %s', 'fw' ), '<code>' . esc_html( FW_Short_Links_Service::short_url( $result->slug ) ) . '</code>' ) );
		$this->redirect( self::edit_url( $result->id ) );
	}

	private function handle_save_settings() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$before = (array) fw_get_db_ext_settings_option( $this->ext->get_name() );
		$values = array_merge( $before, fw_get_options_values_from_input( $this->ext->get_settings_options() ) );

		fw_set_db_ext_settings_option( $this->ext->get_name(), null, $values );
		FW_Short_Links_Service::flush_settings();

		$this->notice( 'success', __( 'Settings saved.', 'fw' ) );
		$this->redirect( self::page_url( 'settings' ) );
	}

	private function handle_remove_data() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$confirm = isset( $_POST['confirm'] ) ? sanitize_text_field( wp_unslash( $_POST['confirm'] ) ) : '';

		if ( 'DELETE' !== strtoupper( $confirm ) ) {
			$this->notice( 'error', __( 'Type DELETE to confirm removing every short link and its click history.', 'fw' ) );
			$this->redirect( self::page_url( 'settings' ) );
		}

		FW_Short_Links_Installer::uninstall();

		$this->notice( 'success', __( 'All short links and click data were removed. Deactivate the extension now if you are uninstalling it; otherwise the tables are recreated empty on the next page load.', 'fw' ) );
		$this->redirect( self::page_url( 'settings' ) );
	}

	/**
	 * @param string $url
	 */
	private function redirect( $url ) {
		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * @param string $type    success|error|warning
	 * @param string $message may contain markup the caller escaped
	 */
	private function notice( $type, $message ) {
		set_transient( self::TRANSIENT_NOTICE . get_current_user_id(), array( 'type' => $type, 'message' => $message ), MINUTE_IN_SECONDS );
	}

	/* ---------------------------------------------------------------------- *
	 * Render
	 * ---------------------------------------------------------------------- */

	public function render_page() {
		if ( ! FW_Short_Links_Service::can_edit() ) {
			wp_die( esc_html__( 'You are not allowed to manage short links.', 'fw' ) );
		}

		$tab  = $this->current_tab();
		$tabs = array(
			'links' => __( 'Links', 'fw' ),
			'new'   => __( 'Add New', 'fw' ),
		);

		if ( 'edit' === $tab ) {
			$tabs['edit'] = __( 'Edit Link', 'fw' );
		}

		if ( FW_Short_Links_Service::can_manage() ) {
			$tabs['reports']  = __( 'Reports', 'fw' );
			$tabs['transfer'] = __( 'Import / Export', 'fw' );
		}

		if ( current_user_can( 'manage_options' ) ) {
			$tabs['api']      = __( 'API & Webhooks', 'fw' );
			$tabs['settings'] = __( 'Settings', 'fw' );
		}
		?>
		<div class="wrap fw-ext-short-links">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Short Links', 'fw' ); ?></h1>
			<?php if ( 'links' === $tab ) : ?>
				<a href="<?php echo esc_url( self::page_url( 'new' ) ); ?>" class="page-title-action"><?php esc_html_e( 'Add New Link', 'fw' ); ?></a>
			<?php endif; ?>
			<hr class="wp-header-end">

			<?php $this->render_notice(); ?>
			<?php $this->render_permalink_warning(); ?>

			<h2 class="nav-tab-wrapper" style="margin:.6em 0 1.4em">
				<?php foreach ( $tabs as $slug => $label ) : ?>
					<a href="<?php echo esc_url( 'edit' === $slug ? self::edit_url( isset( $_GET['link'] ) ? (int) $_GET['link'] : 0 ) : self::page_url( $slug ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>"
					   class="nav-tab<?php echo $slug === $tab ? ' nav-tab-active' : ''; ?>"><?php echo esc_html( $label ); ?></a>
				<?php endforeach; ?>
			</h2>

			<?php
			switch ( $tab ) {
				case 'new':
					$this->render_form( null );
					break;
				case 'edit':
					$this->render_edit();
					break;
				case 'reports':
					FW_Short_Links_Admin_Reports::render();
					break;
				case 'transfer':
					FW_Short_Links_Admin_Transfer::render();
					break;
				case 'api':
					FW_Short_Links_Admin_API::render();
					break;
				case 'settings':
					$this->render_settings();
					break;
				default:
					$this->render_list();
			}
			?>
		</div>
		<?php
	}

	private function render_notice() {
		$key    = self::TRANSIENT_NOTICE . get_current_user_id();
		$notice = get_transient( $key );

		if ( ! $notice ) {
			return;
		}

		delete_transient( $key );

		printf( '<div class="notice notice-%s is-dismissible"><p>%s</p></div>', esc_attr( $notice['type'] ), wp_kses_post( $notice['message'] ) );
	}

	/**
	 * Short links need pretty permalinks only for the home-path detection to be
	 * meaningful; with plain permalinks /slug still reaches index.php on most
	 * servers, but not on all. Say so rather than fail silently.
	 */
	private function render_permalink_warning() {
		if ( '' !== (string) get_option( 'permalink_structure' ) ) {
			return;
		}

		printf(
			'<div class="notice notice-warning"><p>%s</p></div>',
			sprintf(
				/* translators: %s: link to Settings → Permalinks */
				esc_html__( 'Your site uses plain permalinks. Short links work only when the web server sends unknown paths to WordPress, which pretty permalinks guarantee. Choose any structure under %s.', 'fw' ),
				'<a href="' . esc_url( admin_url( 'options-permalink.php' ) ) . '">' . esc_html__( 'Settings → Permalinks', 'fw' ) . '</a>'
			)
		);
	}

	private function render_list() {
		$this->table->prepare_items();
		$filters = FW_Short_Links_List_Table::filters();
		?>
		<form method="get">
			<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE_SLUG ); ?>" />
			<?php if ( 'all' !== $filters['view'] ) : ?>
				<input type="hidden" name="view" value="<?php echo esc_attr( $filters['view'] ); ?>" />
			<?php endif; ?>
			<?php
			$this->table->views();
			$this->table->search_box( __( 'Search links', 'fw' ), 'fw-short-links' );
			$this->table->display();
			?>
		</form>
		<?php
	}

	private function render_edit() {
		$id   = isset( $_GET['link'] ) ? (int) $_GET['link'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$link = FW_Short_Links_Repository::find( $id );

		if ( ! $link || ! FW_Short_Links_Service::can_edit_link( $link ) ) {
			echo '<p>' . esc_html__( 'That link does not exist, or you are not allowed to edit it.', 'fw' ) . '</p>';

			return;
		}

		if ( null !== $link->deleted_at ) {
			printf(
				'<div class="notice notice-warning inline"><p>%s <a href="%s">%s</a></p></div>',
				esc_html__( 'This link is in the Trash and does not redirect.', 'fw' ),
				esc_url( self::action_url( 'restore', $link->id ) ),
				esc_html__( 'Restore it', 'fw' )
			);
		}

		$this->render_form( $link );
		$this->render_stats( $link );
	}

	/**
	 * @param object|null $link null = new
	 */
	private function render_form( $link ) {
		$s      = FW_Short_Links_Service::settings();
		$failed = get_transient( self::TRANSIENT_FORM . get_current_user_id() );

		delete_transient( self::TRANSIENT_FORM . get_current_user_id() );

		$v = $link ? (array) $link : array(
			'id'               => 0,
			'slug'             => '',
			'target_url'       => '',
			'title'            => '',
			'notes'            => '',
			'redirect_type'    => (int) $s['default_redirect'],
			'status'           => 'enabled',
			'fallback_url'     => '',
			'nofollow'         => (bool) $s['default_nofollow'],
			'sponsored'        => (bool) $s['default_sponsored'],
			'new_window'       => false,
			'param_forwarding' => false,
			'track'            => (bool) $s['default_track'],
			'version'          => 0,
		);

		if ( is_array( $failed ) ) {
			$v = array_merge( $v, $failed );
		}

		$base   = trailingslashit( home_url( '/' . ( '' !== $s['link_prefix'] ? $s['link_prefix'] . '/' : '' ) ) );
		$labels = array(
			307 => __( '307 Temporary (recommended)', 'fw' ),
			302 => __( '302 Found (temporary)', 'fw' ),
			301 => __( '301 Moved Permanently', 'fw' ),
			308 => __( '308 Permanent Redirect', 'fw' ),
		);
		?>
		<form method="post" action="<?php echo esc_url( self::page_url() ); ?>" class="fw-sl-form">
			<?php wp_nonce_field( self::NONCE ); ?>
			<input type="hidden" name="fw_sl_action" value="save" />
			<input type="hidden" name="id" value="<?php echo (int) $v['id']; ?>" />
			<input type="hidden" name="version" value="<?php echo (int) $v['version']; ?>" />

			<?php if ( $link ) : ?>
				<div class="fw-sl-hero">
					<code class="fw-sl-hero__url"><?php echo esc_html( FW_Short_Links_Service::short_url( $link->slug ) ); ?></code>
					<button type="button" class="button fw-sl-copy" data-copy="<?php echo esc_attr( FW_Short_Links_Service::short_url( $link->slug ) ); ?>"><?php esc_html_e( 'Copy', 'fw' ); ?></button>
					<a class="button" href="<?php echo esc_url( FW_Short_Links_Service::short_url( $link->slug ) ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Test', 'fw' ); ?></a>
				</div>
			<?php endif; ?>

			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="fw-sl-target"><?php esc_html_e( 'Destination URL', 'fw' ); ?></label></th>
					<td>
						<input type="url" id="fw-sl-target" name="target_url" class="large-text code" required
							   placeholder="https://" value="<?php echo esc_attr( $v['target_url'] ); ?>" />
						<p class="description"><?php esc_html_e( 'Where the short link sends people. mailto:, tel: and sms: links also work.', 'fw' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="fw-sl-slug"><?php esc_html_e( 'Short link', 'fw' ); ?></label></th>
					<td>
						<div class="fw-sl-slug">
							<span class="fw-sl-slug__base"><?php echo esc_html( $base ); ?></span>
							<input type="text" id="fw-sl-slug" name="slug" class="regular-text code" maxlength="<?php echo (int) FW_Short_Links_Service::SLUG_MAX; ?>"
								   value="<?php echo esc_attr( $v['slug'] ); ?>" data-original="<?php echo esc_attr( $link ? $link->slug : '' ); ?>"
								   placeholder="<?php esc_attr_e( 'leave empty to generate', 'fw' ); ?>" autocomplete="off" />
							<button type="button" class="button fw-sl-generate"><?php esc_html_e( 'Generate', 'fw' ); ?></button>
						</div>
						<p class="description fw-sl-slug__status" aria-live="polite"><?php esc_html_e( 'Letters, numbers, - _ . ~ and / (for paths like deals/summer). Not case-sensitive.', 'fw' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="fw-sl-title"><?php esc_html_e( 'Title', 'fw' ); ?></label></th>
					<td>
						<input type="text" id="fw-sl-title" name="title" class="regular-text" value="<?php echo esc_attr( $v['title'] ); ?>" />
						<p class="description"><?php esc_html_e( 'For your list only. Leave empty to use the destination page\'s own title.', 'fw' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="fw-sl-redirect"><?php esc_html_e( 'Redirect type', 'fw' ); ?></label></th>
					<td>
						<select id="fw-sl-redirect" name="redirect_type">
							<?php foreach ( $labels as $code => $label ) : ?>
								<option value="<?php echo (int) $code; ?>" <?php selected( (int) $v['redirect_type'], $code ); ?>><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
						</select>
						<p class="description"><?php esc_html_e( 'Use a temporary type (307 / 302) for links you may repoint later; permanent types (301 / 308) tell search engines the destination is final.', 'fw' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Status', 'fw' ); ?></th>
					<td>
						<fieldset>
							<label><input type="radio" name="status" value="enabled" <?php checked( 'enabled', $v['status'] ); ?> /> <?php esc_html_e( 'Enabled', 'fw' ); ?></label>
							&nbsp;&nbsp;
							<label><input type="radio" name="status" value="disabled" <?php checked( 'disabled', $v['status'] ); ?> /> <?php esc_html_e( 'Disabled', 'fw' ); ?></label>
						</fieldset>
						<div class="fw-sl-fallback">
							<label for="fw-sl-fallback"><?php esc_html_e( 'When disabled, send visitors to:', 'fw' ); ?></label><br>
							<input type="url" id="fw-sl-fallback" name="fallback_url" class="large-text code" placeholder="<?php esc_attr_e( 'empty = show your site\'s 404 page', 'fw' ); ?>" value="<?php echo esc_attr( $v['fallback_url'] ); ?>" />
						</div>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Options', 'fw' ); ?></th>
					<td>
						<fieldset class="fw-sl-checks">
							<div class="fw-sl-check"><label><input type="checkbox" name="nofollow" value="1" <?php checked( (bool) $v['nofollow'] ); ?> /> <?php esc_html_e( 'Nofollow — ask search engines not to follow or index the link', 'fw' ); ?></label></div>
							<div class="fw-sl-check"><label><input type="checkbox" name="sponsored" value="1" <?php checked( (bool) $v['sponsored'] ); ?> /> <?php esc_html_e( 'Sponsored — mark it as a paid / affiliate link (rel="sponsored")', 'fw' ); ?></label></div>
							<div class="fw-sl-check"><label><input type="checkbox" name="param_forwarding" value="1" <?php checked( (bool) $v['param_forwarding'] ); ?> /> <?php esc_html_e( 'Forward query parameters — /slug?utm_source=x passes ?utm_source=x on to the destination', 'fw' ); ?></label></div>
							<div class="fw-sl-check"><label><input type="checkbox" name="track" value="1" <?php checked( (bool) $v['track'] ); ?> /> <?php esc_html_e( 'Track clicks', 'fw' ); ?></label></div>
							<div class="fw-sl-check"><label><input type="checkbox" name="new_window" value="1" <?php checked( (bool) $v['new_window'] ); ?> /> <?php esc_html_e( 'Open in a new tab when inserted with the [short_link] shortcode', 'fw' ); ?></label></div>
						</fieldset>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Categories', 'fw' ); ?></th>
					<td>
						<?php
						$terms   = FW_Short_Links_Categories::all();
						$current = isset( $v['categories'] ) && is_array( $v['categories'] )
							? array_map( 'intval', array_filter( $v['categories'], 'is_numeric' ) )
							: ( $link ? array_map( 'intval', wp_list_pluck( FW_Short_Links_Categories::of( $link->id ), 'term_id' ) ) : array() );
						?>
						<?php if ( $terms ) : ?>
							<fieldset class="fw-sl-cats">
								<?php foreach ( $terms as $t ) : ?>
									<label><input type="checkbox" name="categories[]" value="<?php echo (int) $t->term_id; ?>" <?php checked( in_array( (int) $t->term_id, $current, true ) ); ?> /> <?php echo esc_html( $t->name ); ?></label>
								<?php endforeach; ?>
							</fieldset>
						<?php endif; ?>
						<?php if ( current_user_can( 'manage_short_links' ) ) : ?>
							<input type="text" name="new_categories" class="regular-text" placeholder="<?php esc_attr_e( 'Add new categories, comma-separated', 'fw' ); ?>" />
							<p class="description"><a href="<?php echo esc_url( FW_Short_Links_Categories::manage_url() ); ?>"><?php esc_html_e( 'Manage categories', 'fw' ); ?></a></p>
						<?php elseif ( ! $terms ) : ?>
							<p class="description"><?php esc_html_e( 'No categories yet.', 'fw' ); ?></p>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="fw-sl-notes"><?php esc_html_e( 'Notes', 'fw' ); ?></label></th>
					<td><textarea id="fw-sl-notes" name="notes" class="large-text" rows="3"><?php echo esc_textarea( $v['notes'] ); ?></textarea></td>
				</tr>
			</table>

			<p class="submit">
				<button type="submit" class="button button-primary"><?php echo $link ? esc_html__( 'Update Link', 'fw' ) : esc_html__( 'Create Link', 'fw' ); ?></button>
				<?php if ( $link && null === $link->deleted_at ) : ?>
					<a class="button-link fw-sl-trash" href="<?php echo esc_url( self::action_url( 'trash', $link->id ) ); ?>"><?php esc_html_e( 'Move to Trash', 'fw' ); ?></a>
				<?php endif; ?>
			</p>
		</form>
		<?php
	}

	/**
	 * Totals, top referrers / countries / devices / browsers, then the click log.
	 *
	 * @param object $link
	 */
	private function render_stats( $link ) {
		$mode   = FW_Short_Links_Service::settings()['tracking_mode'];
		$page   = isset( $_GET['cpage'] ) ? max( 1, (int) $_GET['cpage'] ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$clicks = FW_Short_Links_Tracker::clicks_for( $link->id, 50, $page );
		?>
		<div class="fw-sl-stats">
			<h2><?php esc_html_e( 'Clicks', 'fw' ); ?></h2>

			<div class="fw-sl-kpis">
				<div class="fw-sl-kpi"><span class="fw-sl-kpi__n"><?php echo esc_html( number_format_i18n( $link->clicks ) ); ?></span><span class="fw-sl-kpi__l"><?php esc_html_e( 'Total clicks', 'fw' ); ?></span></div>
				<div class="fw-sl-kpi"><span class="fw-sl-kpi__n"><?php echo esc_html( number_format_i18n( $link->uniques ) ); ?></span><span class="fw-sl-kpi__l"><?php esc_html_e( 'Unique visitors', 'fw' ); ?></span></div>
				<div class="fw-sl-kpi"><span class="fw-sl-kpi__n"><?php echo esc_html( date_i18n( get_option( 'date_format' ), strtotime( $link->created_at . ' UTC' ) ) ); ?></span><span class="fw-sl-kpi__l"><?php esc_html_e( 'Created', 'fw' ); ?></span></div>
			</div>

			<?php if ( 'full' !== $mode ) : ?>
				<p class="description"><?php esc_html_e( 'Only totals are kept in the current tracking mode, so there is no click history to show. Switch to full tracking in Settings to record each click.', 'fw' ); ?></p>
				<?php
				return;
			endif;

			if ( ! $clicks['total'] ) :
				?>
				<p class="description"><?php esc_html_e( 'No clicks recorded yet. Clicks from bots, excluded IPs and logged-in link editors are not counted.', 'fw' ); ?></p>
				<?php
				return;
			endif;

			$groups = array(
				'referrer' => __( 'Top referrers', 'fw' ),
				'country'  => __( 'Countries', 'fw' ),
				'device'   => __( 'Devices', 'fw' ),
				'browser'  => __( 'Browsers', 'fw' ),
			);
			?>
			<div class="fw-sl-breakdowns">
				<?php foreach ( $groups as $col => $label ) : ?>
					<div class="fw-sl-breakdown">
						<h3><?php echo esc_html( $label ); ?></h3>
						<ul>
							<?php foreach ( FW_Short_Links_Tracker::breakdown( $link->id, $col ) as $value => $n ) : ?>
								<li><span><?php echo esc_html( '' !== $value ? ( 'referrer' === $col ? (string) wp_parse_url( $value, PHP_URL_HOST ) : $value ) : ( 'referrer' === $col ? __( '(direct)', 'fw' ) : __( '(unknown)', 'fw' ) ) ); ?></span><b><?php echo esc_html( number_format_i18n( $n ) ); ?></b></li>
							<?php endforeach; ?>
						</ul>
					</div>
				<?php endforeach; ?>
			</div>

			<table class="widefat striped fw-sl-clicks">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Time', 'fw' ); ?></th>
						<th><?php esc_html_e( 'Referrer', 'fw' ); ?></th>
						<th><?php esc_html_e( 'Country', 'fw' ); ?></th>
						<th><?php esc_html_e( 'Device', 'fw' ); ?></th>
						<th><?php esc_html_e( 'Browser / OS', 'fw' ); ?></th>
						<th><?php esc_html_e( 'IP', 'fw' ); ?></th>
						<th><?php esc_html_e( 'Unique', 'fw' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $clicks['rows'] as $c ) : ?>
						<tr>
							<td><?php echo esc_html( date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $c->created_at . ' UTC' ) ) ); ?></td>
							<td class="fw-sl-clicks__ref"><?php echo '' !== $c->referrer ? esc_html( $c->referrer ) : '&mdash;'; ?></td>
							<td><?php echo '' !== $c->country ? esc_html( $c->country ) : '&mdash;'; ?></td>
							<td><?php echo esc_html( $c->device ); ?></td>
							<td><?php echo esc_html( $c->browser . ' / ' . $c->os ); ?></td>
							<td><code><?php echo esc_html( FW_Short_Links_Tracker::unpack_ip( $c->ip ) ); ?></code></td>
							<td><?php echo $c->is_unique ? '&#10003;' : ''; ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<?php
			$pages = (int) ceil( $clicks['total'] / 50 );

			if ( $pages > 1 ) {
				echo '<div class="tablenav"><div class="tablenav-pages">' . paginate_links( array( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					'base'    => add_query_arg( 'cpage', '%#%', self::edit_url( $link->id ) ),
					'format'  => '',
					'current' => $page,
					'total'   => $pages,
				) ) . '</div></div>';
			}
			?>
		</div>
		<?php
	}

	private function render_settings() {
		$schema = $this->ext->get_settings_options();
		$values = (array) fw_get_db_ext_settings_option( $this->ext->get_name() );
		?>
		<form method="post" action="<?php echo esc_url( self::page_url( 'settings' ) ); ?>">
			<?php wp_nonce_field( self::NONCE ); ?>
			<input type="hidden" name="fw_sl_action" value="save_settings" />
			<?php echo fw()->backend->render_options( $schema, $values ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
			<p class="submit">
				<button type="submit" class="button button-primary"><?php esc_html_e( 'Save Changes', 'fw' ); ?></button>
			</p>
		</form>

		<div class="fw-sl-danger">
			<h2><?php esc_html_e( 'Remove all data', 'fw' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Deletes every short link, its settings and its click history. Deactivating the extension never does this — your links come back when you reactivate it. This cannot be undone.', 'fw' ); ?></p>
			<form method="post" action="<?php echo esc_url( self::page_url( 'settings' ) ); ?>">
				<?php wp_nonce_field( self::NONCE ); ?>
				<input type="hidden" name="fw_sl_action" value="remove_data" />
				<label><?php esc_html_e( 'Type DELETE to confirm:', 'fw' ); ?> <input type="text" name="confirm" class="regular-text" autocomplete="off" /></label>
				<button type="submit" class="button button-secondary"><?php esc_html_e( 'Remove all short link data', 'fw' ); ?></button>
			</form>
		</div>
		<?php
	}

	/* ---------------------------------------------------------------------- *
	 * Dashboard widget
	 * ---------------------------------------------------------------------- */

	/**
	 * @internal
	 */
	public function _action_dashboard_widget() {
		if ( ! FW_Short_Links_Service::can_edit() ) {
			return;
		}

		wp_add_dashboard_widget( 'fw_short_links_quick_add', __( 'Quick Add Short Link', 'fw' ), array( $this, 'render_dashboard_widget' ) );
	}

	public function render_dashboard_widget() {
		$s    = FW_Short_Links_Service::settings();
		$base = trailingslashit( home_url( '/' . ( '' !== $s['link_prefix'] ? $s['link_prefix'] . '/' : '' ) ) );
		?>
		<form method="post" action="<?php echo esc_url( self::page_url() ); ?>">
			<?php wp_nonce_field( self::NONCE ); ?>
			<input type="hidden" name="fw_sl_action" value="quick_add" />
			<p>
				<label for="fw-sl-qa-target"><?php esc_html_e( 'Destination URL', 'fw' ); ?></label><br>
				<input type="url" id="fw-sl-qa-target" name="target_url" class="widefat" required placeholder="https://" />
			</p>
			<p>
				<label for="fw-sl-qa-slug"><?php esc_html_e( 'Short link', 'fw' ); ?></label><br>
				<code style="font-size:12px"><?php echo esc_html( $base ); ?></code><input type="text" id="fw-sl-qa-slug" name="slug" placeholder="<?php esc_attr_e( 'auto', 'fw' ); ?>" style="width:9em" />
			</p>
			<p>
				<button type="submit" class="button button-primary"><?php esc_html_e( 'Create', 'fw' ); ?></button>
				<a href="<?php echo esc_url( self::page_url() ); ?>" style="margin-left:.6em"><?php esc_html_e( 'All short links', 'fw' ); ?></a>
			</p>
		</form>
		<?php
	}
}
