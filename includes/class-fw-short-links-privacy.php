<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * GDPR: plugs click history into Tools → Export / Erase Personal Data.
 *
 * Clicks are anonymous unless a logged-in visitor followed a link, in which
 * case the row carries their user id — that is the only thing that ties a
 * click to an email address, so it is what export and erase work from.
 * Erasing ANONYMISES the rows (user id and IP removed) rather than deleting
 * them, so a link's totals stay true.
 */
class FW_Short_Links_Privacy {

	const GROUP = 'unysonplus-short-links';
	const PAGE  = 500;

	public function __construct() {
		add_filter( 'wp_privacy_personal_data_exporters', array( $this, '_register_exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( $this, '_register_eraser' ) );
		add_action( 'admin_init', array( $this, '_policy_text' ) );
	}

	/**
	 * @internal
	 */
	public function _register_exporter( $exporters ) {
		$exporters[ self::GROUP ] = array(
			'exporter_friendly_name' => __( 'Short link clicks', 'fw' ),
			'callback'               => array( $this, 'export' ),
		);

		return $exporters;
	}

	/**
	 * @internal
	 */
	public function _register_eraser( $erasers ) {
		$erasers[ self::GROUP ] = array(
			'eraser_friendly_name' => __( 'Short link clicks', 'fw' ),
			'callback'             => array( $this, 'erase' ),
		);

		return $erasers;
	}

	/**
	 * Suggested privacy-policy text (Settings → Privacy → Policy Guide).
	 *
	 * @internal
	 */
	public function _policy_text() {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}

		wp_add_privacy_policy_content( __( 'Short Links', 'fw' ), wp_kses_post( wpautop( __(
			'When you follow a short link on this site we record the time, the page you came from, your approximate country (when our hosting provides it), the type of device and browser you use and a shortened form of your IP address. To count unique visitors we may set first-party cookies named fwsl_v and fwsl_[number]; they contain a random identifier and last up to one year. This information is used only to measure how links perform.',
			'fw'
		) ) ) );
	}

	/**
	 * @param string $email
	 *
	 * @return int user id or 0
	 */
	private function user_id( $email ) {
		$user = get_user_by( 'email', $email );

		return $user ? (int) $user->ID : 0;
	}

	/**
	 * @param string $email
	 * @param int    $page
	 *
	 * @return array
	 */
	public function export( $email, $page = 1 ) {
		global $wpdb;

		$uid = $this->user_id( $email );

		if ( ! $uid ) {
			return array( 'data' => array(), 'done' => true );
		}

		$clicks = FW_Short_Links_Installer::table( 'clicks' );
		$links  = FW_Short_Links_Installer::table( 'links' );
		$rows   = (array) $wpdb->get_results( $wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			"SELECT c.*, l.slug FROM {$clicks} c LEFT JOIN {$links} l ON l.id = c.link_id WHERE c.user_id = %d ORDER BY c.id LIMIT %d OFFSET %d",
			$uid,
			self::PAGE,
			( max( 1, (int) $page ) - 1 ) * self::PAGE
		) );

		$data = array();

		foreach ( $rows as $r ) {
			$data[] = array(
				'group_id'    => self::GROUP,
				'group_label' => __( 'Short link clicks', 'fw' ),
				'item_id'     => 'short-link-click-' . $r->id,
				'data'        => array(
					array( 'name' => __( 'Link', 'fw' ), 'value' => '/' . $r->slug ),
					array( 'name' => __( 'Time (UTC)', 'fw' ), 'value' => $r->created_at ),
					array( 'name' => __( 'Referrer', 'fw' ), 'value' => $r->referrer ),
					array( 'name' => __( 'Country', 'fw' ), 'value' => $r->country ),
					array( 'name' => __( 'Device', 'fw' ), 'value' => trim( $r->device . ' ' . $r->browser . ' ' . $r->os ) ),
					array( 'name' => __( 'IP address', 'fw' ), 'value' => FW_Short_Links_Tracker::unpack_ip( $r->ip ) ),
				),
			);
		}

		return array( 'data' => $data, 'done' => count( $rows ) < self::PAGE );
	}

	/**
	 * @param string $email
	 * @param int    $page
	 *
	 * @return array
	 */
	public function erase( $email, $page = 1 ) {
		global $wpdb;

		$uid = $this->user_id( $email );

		if ( ! $uid ) {
			return array( 'items_removed' => false, 'items_retained' => false, 'messages' => array(), 'done' => true );
		}

		$n = (int) $wpdb->query( $wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			'UPDATE ' . FW_Short_Links_Installer::table( 'clicks' ) . " SET user_id = 0, ip = NULL, visitor = '' WHERE user_id = %d",
			$uid
		) );

		return array(
			'items_removed'  => $n > 0,
			'items_retained' => false,
			'messages'       => $n ? array( sprintf( _n( '%d short link click was anonymised.', '%d short link clicks were anonymised.', $n, 'fw' ), $n ) ) : array(),
			'done'           => true,
		);
	}
}
