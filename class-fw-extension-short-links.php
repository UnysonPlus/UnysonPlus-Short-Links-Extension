<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * Short Links — branded short URLs on the site's own domain, with click
 * tracking.
 *
 * Layering (same discipline as the Newsletter CRM):
 *
 *   Installer   — the only DDL. Tables, schema version, capabilities.
 *   Repository  — the only SQL for links, plus the slug index and lookup cache.
 *   Service     — the only business rules, and the only thing outsiders call.
 *   Redirect    — the request hook. Tracker — visitors, clicks, retention.
 *   Admin / REST / shortcode — presentation and transport over the service.
 */
class FW_Extension_Short_Links extends FW_Extension {

	/**
	 * @internal
	 */
	public function _init() {
		$dir = dirname( __FILE__ ) . '/includes/';

		require_once $dir . 'class-fw-short-links-installer.php';
		require_once $dir . 'class-fw-short-links-repository.php';
		require_once $dir . 'class-fw-short-links-service.php';
		require_once $dir . 'class-fw-short-links-tracker.php';
		require_once $dir . 'class-fw-short-links-redirect.php';
		require_once $dir . 'class-fw-short-links-rest.php';
		require_once $dir . 'class-fw-short-links-stats.php';
		require_once $dir . 'class-fw-short-links-categories.php';
		require_once $dir . 'class-fw-short-links-keys.php';
		require_once $dir . 'class-fw-short-links-webhooks.php';
		require_once $dir . 'class-fw-short-links-transfer.php';
		require_once $dir . 'class-fw-short-links-privacy.php';
		require_once dirname( __FILE__ ) . '/helpers.php';

		// One autoloaded get_option() when up to date — cheap enough for every
		// load, and it makes activation and updates self-healing.
		FW_Short_Links_Installer::maybe_install();

		new FW_Short_Links_Redirect();
		new FW_Short_Links_REST();
		new FW_Short_Links_Categories();
		new FW_Short_Links_Keys();
		new FW_Short_Links_Webhooks();
		new FW_Short_Links_Privacy();

		add_action( FW_Short_Links_Tracker::CRON_TRIM, array( 'FW_Short_Links_Tracker', 'trim' ) );
		add_shortcode( 'short_link', array( $this, '_shortcode' ) );
		add_action( 'admin_bar_menu', array( $this, '_action_admin_bar' ), 80 );

		if ( is_admin() ) {
			require_once $dir . 'class-fw-short-links-list-table.php';
			require_once $dir . 'class-fw-short-links-admin-page.php';
			require_once $dir . 'class-fw-short-links-admin-reports.php';
			require_once $dir . 'class-fw-short-links-admin-transfer.php';
			require_once $dir . 'class-fw-short-links-admin-api.php';

			new FW_Short_Links_Admin_Page( $this );
			new FW_Short_Links_Admin_Transfer();

			// Keep the retention job in step with the setting (cheap: one cron read).
			add_action( 'admin_init', array( 'FW_Short_Links_Tracker', 'schedule_trim' ) );
		}
	}

	/**
	 * [short_link id="12"] or [short_link slug="deal" text="Get the deal" class="btn" target="_blank"]
	 *
	 * @internal
	 *
	 * @param array|string $atts
	 *
	 * @return string
	 */
	public function _shortcode( $atts ) {
		$atts = shortcode_atts( array(
			'id'     => '',
			'slug'   => '',
			'text'   => '',
			'class'  => '',
			'target' => '',
		), $atts, 'short_link' );

		$link = fw_ext_short_links_get( '' !== $atts['id'] ? (int) $atts['id'] : $atts['slug'] );

		if ( ! $link || null !== $link->deleted_at ) {
			return '';
		}

		$url    = FW_Short_Links_Service::short_url( $link->slug );
		$text   = '' !== $atts['text'] ? $atts['text'] : ( '' !== $link->title ? $link->title : $url );
		$target = '' !== $atts['target'] ? $atts['target'] : ( $link->new_window ? '_blank' : '' );

		$rel = array();

		if ( $link->nofollow ) {
			$rel[] = 'nofollow';
		}
		if ( $link->sponsored ) {
			$rel[] = 'sponsored';
		}
		if ( '_blank' === $target ) {
			$rel[] = 'noopener';
		}

		return sprintf(
			'<a href="%s" class="%s"%s%s>%s</a>',
			esc_url( $url ),
			esc_attr( trim( 'short-link ' . $atts['class'] ) ),
			'' !== $target ? ' target="' . esc_attr( $target ) . '"' : '',
			$rel ? ' rel="' . esc_attr( implode( ' ', $rel ) ) . '"' : '',
			esc_html( $text )
		);
	}

	/**
	 * "+ New → Short Link" in the admin bar.
	 *
	 * @internal
	 *
	 * @param WP_Admin_Bar $bar
	 */
	public function _action_admin_bar( $bar ) {
		if ( ! is_admin_bar_showing() || ! FW_Short_Links_Service::can_edit() ) {
			return;
		}

		$bar->add_node( array(
			'parent' => 'new-content',
			'id'     => 'fw-new-short-link',
			'title'  => __( 'Short Link', 'fw' ),
			'href'   => admin_url( 'admin.php?page=fw-short-links&tab=new' ),
		) );
	}
}
