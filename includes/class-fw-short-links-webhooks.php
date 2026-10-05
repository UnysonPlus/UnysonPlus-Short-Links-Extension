<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * Outbound webhooks: tell another app when links change, and send it clicks.
 *
 * Events: link.created, link.updated, link.trashed, link.restored,
 * link.deleted, links.imported (one summary per import — per-link events are
 * held back while an import runs), and clicks (a batch of recorded clicks, sent at most every
 * five minutes — one request per click would let a busy link flood the
 * receiver and this site's cron).
 *
 * Delivery is never inline with the change that caused it: events go into a
 * queue table and a cron worker posts them, retrying with backoff (1 min,
 * 5 min, 30 min, 2 h, 12 h) before giving up. After 20 consecutive failures
 * the webhook is switched off so a dead endpoint stops costing requests.
 *
 * Every request is signed. The receiver recomputes
 *   HMAC-SHA256( secret, "{timestamp}.{raw body}" )
 * and compares it with the `v1` value in `X-Webhook-Signature: t=…,v1=…`,
 * rejecting stale timestamps to stop replays.
 */
class FW_Short_Links_Webhooks {

	const EVENTS       = array( 'link.created', 'link.updated', 'link.trashed', 'link.restored', 'link.deleted', 'links.imported', 'clicks' );
	const CRON_DELIVER = 'fw_ext_short_links_webhooks_deliver';
	const CRON_CLICKS  = 'fw_ext_short_links_webhooks_clicks';
	const CURSOR       = 'fw_ext_short_links_webhooks_click_cursor';
	const BACKOFF      = array( 60, 300, 1800, 7200, 43200 );
	const MAX_FAILURES = 20;

	public function __construct() {
		add_action( 'fw_ext_short_links_saved', array( $this, '_on_saved' ), 10, 2 );
		add_action( 'fw_ext_short_links_deleted', array( $this, '_on_deleted' ), 10, 2 );
		add_action( 'fw_ext_short_links_restored', array( $this, '_on_restored' ) );
		add_action( self::CRON_DELIVER, array( __CLASS__, 'deliver' ) );
		add_action( self::CRON_DELIVER . '_now', array( __CLASS__, 'deliver' ) );
		add_action( self::CRON_CLICKS, array( __CLASS__, 'queue_clicks' ) );
		add_filter( 'cron_schedules', array( $this, '_cron_schedules' ) );
	}

	/**
	 * @return string
	 */
	private static function t( $which = 'webhooks' ) {
		return FW_Short_Links_Installer::table( $which );
	}

	/**
	 * @internal
	 */
	public function _cron_schedules( $schedules ) {
		$schedules['fw_sl_five_minutes'] = array( 'interval' => 5 * MINUTE_IN_SECONDS, 'display' => __( 'Every five minutes', 'fw' ) );

		return $schedules;
	}

	/* ---------------------------------------------------------------------- *
	 * Subscriptions
	 * ---------------------------------------------------------------------- */

	/**
	 * @return object[]
	 */
	public static function all() {
		global $wpdb;

		$rows = (array) $wpdb->get_results( 'SELECT * FROM ' . self::t() . ' ORDER BY id DESC' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		foreach ( $rows as $r ) {
			$r->events = array_filter( explode( ',', $r->events ) );
		}

		return $rows;
	}

	/**
	 * @param string   $url
	 * @param string[] $events
	 *
	 * @return array|WP_Error { id, secret }
	 */
	public static function create( $url, array $events ) {
		global $wpdb;

		$url    = esc_url_raw( trim( (string) $url ), array( 'https', 'http' ) );
		$events = array_values( array_intersect( self::EVENTS, $events ) );

		if ( '' === $url || ! wp_http_validate_url( $url ) ) {
			return new WP_Error( 'bad_url', __( 'Enter a public http(s) URL that this site can reach.', 'fw' ) );
		}

		if ( ! $events ) {
			return new WP_Error( 'no_events', __( 'Pick at least one event.', 'fw' ) );
		}

		$secret = 'whsec_' . wp_generate_password( 32, false, false );

		$wpdb->insert( self::t(), array(
			'url'        => $url,
			'secret'     => $secret,
			'events'     => implode( ',', $events ),
			'active'     => 1,
			'created_at' => current_time( 'mysql', true ),
		) );

		self::schedule();

		return array( 'id' => (int) $wpdb->insert_id, 'secret' => $secret );
	}

	/**
	 * @param int  $id
	 * @param bool $active
	 */
	public static function set_active( $id, $active ) {
		global $wpdb;

		$wpdb->update( self::t(), array( 'active' => $active ? 1 : 0, 'failures' => 0 ), array( 'id' => (int) $id ) );
	}

	/**
	 * @param int $id
	 */
	public static function delete( $id ) {
		global $wpdb;

		$wpdb->delete( self::t(), array( 'id' => (int) $id ) );
		$wpdb->delete( self::t( 'queue' ), array( 'webhook_id' => (int) $id ) );
	}

	/**
	 * Crons exist only while there is something to deliver to.
	 */
	public static function schedule() {
		global $wpdb;

		$any    = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . self::t() . ' WHERE active = 1' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$clicks = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . self::t() . " WHERE active = 1 AND FIND_IN_SET('clicks', events)" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		if ( $any && ! wp_next_scheduled( self::CRON_DELIVER ) ) {
			wp_schedule_event( time() + MINUTE_IN_SECONDS, 'fw_sl_five_minutes', self::CRON_DELIVER );
		} elseif ( ! $any ) {
			wp_clear_scheduled_hook( self::CRON_DELIVER );
		}

		if ( $clicks && ! wp_next_scheduled( self::CRON_CLICKS ) ) {
			// Start the cursor at "now" so subscribing does not replay history.
			if ( false === get_option( self::CURSOR, false ) ) {
				update_option( self::CURSOR, (int) $wpdb->get_var( 'SELECT MAX(id) FROM ' . self::t( 'clicks' ) ), false ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			}
			wp_schedule_event( time() + 5 * MINUTE_IN_SECONDS, 'fw_sl_five_minutes', self::CRON_CLICKS );
		} elseif ( ! $clicks ) {
			wp_clear_scheduled_hook( self::CRON_CLICKS );
			delete_option( self::CURSOR );
		}
	}

	/* ---------------------------------------------------------------------- *
	 * Events → queue
	 * ---------------------------------------------------------------------- */

	/**
	 * @param string $event
	 * @param array  $data
	 */
	public static function emit( $event, array $data ) {
		global $wpdb;

		if ( FW_Short_Links_Transfer::$importing && 0 === strpos( $event, 'link.' ) ) {
			return;
		}

		$hooks = (array) $wpdb->get_results( $wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			'SELECT id FROM ' . self::t() . ' WHERE active = 1 AND FIND_IN_SET(%s, events)',
			$event
		) );

		if ( ! $hooks ) {
			return;
		}

		$payload = wp_json_encode( array(
			'id'         => wp_generate_uuid4(),
			'event'      => $event,
			'created_at' => gmdate( 'c' ),
			'site'       => home_url( '/' ),
			'data'       => $data,
		) );

		foreach ( $hooks as $h ) {
			$wpdb->insert( self::t( 'queue' ), array(
				'webhook_id'      => (int) $h->id,
				'event'           => $event,
				'payload'         => $payload,
				'attempts'        => 0,
				'next_attempt_at' => current_time( 'mysql', true ),
				'created_at'      => current_time( 'mysql', true ),
			) );
		}

		// Deliver soon rather than at the next five-minute tick.
		if ( ! wp_next_scheduled( self::CRON_DELIVER . '_now' ) ) {
			wp_schedule_single_event( time(), self::CRON_DELIVER . '_now' );
		}
	}

	/**
	 * @internal
	 */
	public function _on_saved( $link, $is_new ) {
		self::emit( $is_new ? 'link.created' : 'link.updated', FW_Short_Links_Service::to_array( $link ) );
	}

	/**
	 * @internal
	 *
	 * @param int[] $ids
	 * @param bool  $permanent
	 */
	public function _on_deleted( $ids, $permanent ) {
		foreach ( (array) $ids as $id ) {
			self::emit( $permanent ? 'link.deleted' : 'link.trashed', array( 'id' => (int) $id ) );
		}
	}

	/**
	 * @internal
	 *
	 * @param int[] $ids
	 */
	public function _on_restored( $ids ) {
		foreach ( (array) $ids as $id ) {
			$link = FW_Short_Links_Repository::find( $id );

			if ( $link ) {
				self::emit( 'link.restored', FW_Short_Links_Service::to_array( $link ) );
			}
		}
	}

	/**
	 * Cron: package clicks recorded since the last batch (up to 500 per run).
	 */
	public static function queue_clicks() {
		global $wpdb;

		$cursor = (int) get_option( self::CURSOR, 0 );
		$rows   = (array) $wpdb->get_results( $wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			'SELECT c.*, l.slug FROM ' . self::t( 'clicks' ) . ' c LEFT JOIN ' . self::t( 'links' ) . ' l ON l.id = c.link_id WHERE c.id > %d ORDER BY c.id ASC LIMIT 500',
			$cursor
		) );

		if ( ! $rows ) {
			return;
		}

		$clicks = array();

		foreach ( $rows as $r ) {
			$clicks[] = array(
				'id'         => (int) $r->id,
				'link_id'    => (int) $r->link_id,
				'slug'       => (string) $r->slug,
				'created_at' => mysql_to_rfc3339( $r->created_at ),
				'unique'     => (bool) $r->is_unique,
				'referrer'   => $r->referrer,
				'country'    => $r->country,
				'device'     => $r->device,
				'browser'    => $r->browser,
				'os'         => $r->os,
			);
		}

		update_option( self::CURSOR, (int) end( $rows )->id, false );

		self::emit( 'clicks', array( 'count' => count( $clicks ), 'clicks' => $clicks ) );
	}

	/* ---------------------------------------------------------------------- *
	 * Delivery
	 * ---------------------------------------------------------------------- */

	/**
	 * @param string $secret
	 * @param string $body
	 * @param int    $ts
	 *
	 * @return string
	 */
	public static function signature( $secret, $body, $ts ) {
		return 't=' . $ts . ',v1=' . hash_hmac( 'sha256', $ts . '.' . $body, $secret );
	}

	/**
	 * Cron worker: send due deliveries, oldest first, within a time budget.
	 */
	public static function deliver() {
		global $wpdb;

		$started = time();
		$due     = (array) $wpdb->get_results( $wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			'SELECT q.*, w.url, w.secret, w.active FROM ' . self::t( 'queue' ) . ' q JOIN ' . self::t() . ' w ON w.id = q.webhook_id
			 WHERE q.next_attempt_at <= %s ORDER BY q.id ASC LIMIT 50',
			current_time( 'mysql', true )
		) );

		foreach ( $due as $item ) {
			if ( time() - $started > 20 ) {
				break; // leave the rest for the next tick
			}

			if ( ! (int) $item->active ) {
				$wpdb->delete( self::t( 'queue' ), array( 'id' => (int) $item->id ) );
				continue;
			}

			$ok = self::send( $item );

			if ( $ok ) {
				$wpdb->delete( self::t( 'queue' ), array( 'id' => (int) $item->id ) );
				continue;
			}

			$attempts = (int) $item->attempts + 1;

			if ( $attempts > count( self::BACKOFF ) ) {
				$wpdb->delete( self::t( 'queue' ), array( 'id' => (int) $item->id ) ); // gave up
			} else {
				$wpdb->update( self::t( 'queue' ), array(
					'attempts'        => $attempts,
					'next_attempt_at' => gmdate( 'Y-m-d H:i:s', time() + self::BACKOFF[ $attempts - 1 ] ),
				), array( 'id' => (int) $item->id ) );
			}
		}
	}

	/**
	 * One POST. Records the outcome on the webhook; a 2xx is success.
	 *
	 * @param object $item queue row joined with url + secret
	 *
	 * @return bool
	 */
	public static function send( $item ) {
		global $wpdb;

		$ts  = time();
		$res = wp_safe_remote_post( $item->url, array(
			'timeout'     => 8,
			'redirection' => 0,
			'body'        => $item->payload,
			'headers'     => array(
				'Content-Type'        => 'application/json',
				'User-Agent'          => 'WordPress/' . get_bloginfo( 'version' ) . '; ' . home_url( '/' ),
				'X-Webhook-Event'     => $item->event,
				'X-Webhook-Id'        => (string) ( json_decode( $item->payload )->id ?? '' ),
				'X-Webhook-Signature' => self::signature( $item->secret, $item->payload, $ts ),
			),
		) );

		$code = is_wp_error( $res ) ? 0 : (int) wp_remote_retrieve_response_code( $res );
		$ok   = $code >= 200 && $code < 300;

		$wpdb->query( $wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			'UPDATE ' . self::t() . ' SET last_status = %s, last_sent_at = %s, failures = ' . ( $ok ? '0' : 'failures + 1' ) . ' WHERE id = %d',
			is_wp_error( $res ) ? substr( $res->get_error_message(), 0, 190 ) : (string) $code,
			current_time( 'mysql', true ),
			(int) $item->webhook_id
		) );

		if ( ! $ok ) {
			$wpdb->query( $wpdb->prepare( 'UPDATE ' . self::t() . ' SET active = 0 WHERE id = %d AND failures >= %d', (int) $item->webhook_id, self::MAX_FAILURES ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}

		return $ok;
	}

	/**
	 * Send a `ping` right now (the admin "Send test" button). Bypasses the queue.
	 *
	 * @param int $id
	 *
	 * @return string human result
	 */
	public static function ping( $id ) {
		global $wpdb;

		$hook = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::t() . ' WHERE id = %d', (int) $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		if ( ! $hook ) {
			return __( 'That webhook no longer exists.', 'fw' );
		}

		$payload = wp_json_encode( array(
			'id'         => wp_generate_uuid4(),
			'event'      => 'ping',
			'created_at' => gmdate( 'c' ),
			'site'       => home_url( '/' ),
			'data'       => array( 'message' => 'Test delivery from Short Links.' ),
		) );

		$ok = self::send( (object) array(
			'webhook_id' => $hook->id,
			'url'        => $hook->url,
			'secret'     => $hook->secret,
			'event'      => 'ping',
			'payload'    => $payload,
		) );

		$status = $wpdb->get_var( $wpdb->prepare( 'SELECT last_status FROM ' . self::t() . ' WHERE id = %d', (int) $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		/* translators: %s: HTTP status or error */
		return $ok ? sprintf( __( 'Delivered (HTTP %s).', 'fw' ), $status ) : sprintf( __( 'Failed: %s', 'fw' ), $status );
	}
}
