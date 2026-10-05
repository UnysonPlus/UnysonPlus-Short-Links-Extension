<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * The only SQL for links. No hooks and no business rules — those live in the
 * service. Rows come back as objects with typed fields (see hydrate()).
 *
 * Also owns the SLUG INDEX: a compact, autoloaded list of 8-character hashes
 * of every live slug. The redirect checks it before querying, so a page view
 * that is not a short link costs no query at all, even without a persistent
 * object cache. Past INDEX_MAX links it is not kept, and the redirect falls
 * back to one indexed lookup per request (cached in the object cache).
 */
class FW_Short_Links_Repository {

	const INDEX_OPTION = 'fw_ext_short_links_index';
	const INDEX_MAX    = 5000;
	const CACHE_GROUP  = 'fw_short_links';

	const STATUSES       = array( 'enabled', 'disabled' );
	const REDIRECT_TYPES = array( 301, 302, 307, 308 );

	/** Columns a caller may write; everything else is managed here. */
	const WRITABLE = array(
		'slug', 'target_url', 'title', 'notes', 'redirect_type', 'status', 'fallback_url',
		'nofollow', 'sponsored', 'new_window', 'param_forwarding', 'track', 'owner_id',
	);

	const SORTABLE = array( 'id', 'slug', 'title', 'clicks', 'uniques', 'created_at', 'updated_at' );

	/**
	 * @return string
	 */
	private static function t() {
		return FW_Short_Links_Installer::table( 'links' );
	}

	/**
	 * @param object|null $row
	 *
	 * @return object|null
	 */
	public static function hydrate( $row ) {
		if ( ! $row ) {
			return null;
		}

		foreach ( array( 'id', 'redirect_type', 'owner_id', 'clicks', 'uniques', 'version' ) as $k ) {
			$row->$k = (int) $row->$k;
		}

		foreach ( array( 'nofollow', 'sponsored', 'new_window', 'param_forwarding', 'track' ) as $k ) {
			$row->$k = (bool) (int) $row->$k;
		}

		$row->notes        = (string) $row->notes;
		$row->fallback_url = (string) $row->fallback_url;

		return $row;
	}

	/* ---------------------------------------------------------------------- *
	 * Reads
	 * ---------------------------------------------------------------------- */

	/**
	 * @param int $id
	 *
	 * @return object|null
	 */
	public static function find( $id ) {
		global $wpdb;

		return self::hydrate( $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::t() . ' WHERE id = %d', (int) $id ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Includes trashed links: a trashed link still owns its slug.
	 *
	 * @param string $slug
	 *
	 * @return object|null
	 */
	public static function find_by_slug( $slug ) {
		global $wpdb;

		return self::hydrate( $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::t() . ' WHERE slug = %s LIMIT 1', (string) $slug ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * The redirect's lookup: live links only, through the object cache, with a
	 * negative entry so a miss is not re-queried within the cache lifetime.
	 *
	 * @param string $slug
	 *
	 * @return object|null
	 */
	public static function resolve( $slug ) {
		$key    = 'slug:' . md5( strtolower( $slug ) );
		$cached = wp_cache_get( $key, self::CACHE_GROUP );

		if ( 'none' === $cached ) {
			return null;
		}

		if ( is_object( $cached ) ) {
			return $cached;
		}

		global $wpdb;

		$row = self::hydrate( $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::t() . ' WHERE slug = %s AND deleted_at IS NULL LIMIT 1', $slug ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		wp_cache_set( $key, $row ? $row : 'none', self::CACHE_GROUP, HOUR_IN_SECONDS );

		return $row;
	}

	/**
	 * @param array $args view (all|enabled|disabled|trash|any), search, owner_id,
	 *                    category (term id), modified_since (Y-m-d H:i:s UTC),
	 *                    orderby, order, per_page, page
	 *
	 * @return array { rows: object[], total: int }
	 */
	public static function query( array $args ) {
		global $wpdb;

		$args = array_merge( array(
			'view'           => 'all',
			'search'         => '',
			'owner_id'       => 0,
			'category'       => 0,
			'modified_since' => '',
			'orderby'  => 'created_at',
			'order'    => 'DESC',
			'per_page' => 20,
			'page'     => 1,
		), $args );

		list( $where, $params ) = self::where( $args );

		$orderby  = in_array( $args['orderby'], self::SORTABLE, true ) ? $args['orderby'] : 'created_at';
		$order    = 'ASC' === strtoupper( $args['order'] ) ? 'ASC' : 'DESC';
		$per_page = max( 1, min( 500, (int) $args['per_page'] ) );
		$offset   = ( max( 1, (int) $args['page'] ) - 1 ) * $per_page;

		$total = (int) $wpdb->get_var( $params ? $wpdb->prepare( 'SELECT COUNT(*) FROM ' . self::t() . " WHERE {$where}", $params ) : 'SELECT COUNT(*) FROM ' . self::t() . " WHERE {$where}" ); // phpcs:ignore WordPress.DB.PreparedSQL

		$sql  = 'SELECT * FROM ' . self::t() . " WHERE {$where} ORDER BY {$orderby} {$order}, id {$order} LIMIT %d OFFSET %d";
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, array_merge( $params, array( $per_page, $offset ) ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL

		return array(
			'rows'  => array_map( array( __CLASS__, 'hydrate' ), (array) $rows ),
			'total' => $total,
		);
	}

	/**
	 * @param array $args
	 *
	 * @return array [ string $where, array $params ]
	 */
	private static function where( array $args ) {
		global $wpdb;

		$where  = array();
		$params = array();

		switch ( $args['view'] ) {
			case 'trash':
				$where[] = 'deleted_at IS NOT NULL';
				break;
			case 'enabled':
			case 'disabled':
				$where[]  = 'deleted_at IS NULL AND status = %s';
				$params[] = $args['view'];
				break;
			case 'any':
				// Trashed included — what a sync client needs to see removals.
				$where[] = '1=1';
				break;
			default:
				$where[] = 'deleted_at IS NULL';
		}

		if ( '' !== $args['search'] ) {
			$like     = '%' . $wpdb->esc_like( $args['search'] ) . '%';
			$where[]  = '(slug LIKE %s OR title LIKE %s OR target_url LIKE %s)';
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
		}

		if ( ! empty( $args['owner_id'] ) ) {
			$where[]  = 'owner_id = %d';
			$params[] = (int) $args['owner_id'];
		}

		if ( ! empty( $args['category'] ) ) {
			$where[] = FW_Short_Links_Categories::sql_filter( (int) $args['category'] ); // ints only
		}

		if ( '' !== (string) $args['modified_since'] ) {
			$where[]  = 'updated_at >= %s';
			$params[] = (string) $args['modified_since'];
		}

		return array( implode( ' AND ', $where ), $params );
	}

	/**
	 * @param int $owner_id 0 = everyone's
	 *
	 * @return array view => count
	 */
	public static function counts( $owner_id = 0 ) {
		global $wpdb;

		$owner = $owner_id ? $wpdb->prepare( ' WHERE owner_id = %d', (int) $owner_id ) : '';

		$row = $wpdb->get_row( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			'SELECT SUM(deleted_at IS NULL) AS all_, SUM(deleted_at IS NULL AND status = \'enabled\') AS enabled, '
			. 'SUM(deleted_at IS NULL AND status = \'disabled\') AS disabled, SUM(deleted_at IS NOT NULL) AS trash FROM ' . self::t() . $owner
		);

		return array(
			'all'      => (int) ( $row ? $row->all_ : 0 ),
			'enabled'  => (int) ( $row ? $row->enabled : 0 ),
			'disabled' => (int) ( $row ? $row->disabled : 0 ),
			'trash'    => (int) ( $row ? $row->trash : 0 ),
		);
	}

	/* ---------------------------------------------------------------------- *
	 * Writes
	 * ---------------------------------------------------------------------- */

	/**
	 * @param array $data already validated by the service
	 *
	 * @return int|false new id
	 */
	public static function insert( array $data ) {
		global $wpdb;

		$now  = current_time( 'mysql', true );
		$data = array_intersect_key( $data, array_flip( self::WRITABLE ) );

		$data['created_at'] = $now;
		$data['updated_at'] = $now;
		$data['version']    = 1;

		if ( ! $wpdb->insert( self::t(), $data ) ) {
			return false;
		}

		$id = (int) $wpdb->insert_id;
		self::changed( $data['slug'] );

		return $id;
	}

	/**
	 * @param int   $id
	 * @param array $data
	 *
	 * @return bool
	 */
	public static function update( $id, array $data ) {
		global $wpdb;

		$before = self::find( $id );

		if ( ! $before ) {
			return false;
		}

		$data = array_intersect_key( $data, array_flip( self::WRITABLE ) );

		if ( ! $data ) {
			return true;
		}

		$data['updated_at'] = current_time( 'mysql', true );
		$data['version']    = $before->version + 1;

		$ok = false !== $wpdb->update( self::t(), $data, array( 'id' => (int) $id ) );

		self::changed( $before->slug );

		if ( isset( $data['slug'] ) ) {
			self::changed( $data['slug'] );
		}

		return $ok;
	}

	/**
	 * Sets one column on many rows — used by bulk toggles and trash/restore.
	 *
	 * @param int[]  $ids
	 * @param string $sql_set   e.g. "status = %s" (column names are ours, never input)
	 * @param array  $params
	 *
	 * @return int rows affected
	 */
	public static function bulk_set( array $ids, $sql_set, array $params = array() ) {
		global $wpdb;

		$ids = array_values( array_filter( array_map( 'intval', $ids ) ) );

		if ( ! $ids ) {
			return 0;
		}

		$in  = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$sql = 'UPDATE ' . self::t() . " SET {$sql_set}, version = version + 1, updated_at = %s WHERE id IN ({$in})";
		$n   = (int) $wpdb->query( $wpdb->prepare( $sql, array_merge( $params, array( current_time( 'mysql', true ) ), $ids ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL

		self::changed_many( $ids );

		return $n;
	}

	/**
	 * Permanent delete of links, their meta and their clicks.
	 *
	 * @param int[] $ids
	 *
	 * @return int
	 */
	public static function delete( array $ids ) {
		global $wpdb;

		$ids = array_values( array_filter( array_map( 'intval', $ids ) ) );

		if ( ! $ids ) {
			return 0;
		}

		self::changed_many( $ids ); // before the rows are gone, so their slugs are known

		$in = implode( ',', $ids ); // ints only

		$wpdb->query( 'DELETE FROM ' . FW_Short_Links_Installer::table( 'clicks' ) . " WHERE link_id IN ({$in})" ); // phpcs:ignore WordPress.DB.PreparedSQL
		$wpdb->query( 'DELETE FROM ' . FW_Short_Links_Installer::table( 'meta' ) . " WHERE link_id IN ({$in})" ); // phpcs:ignore WordPress.DB.PreparedSQL
		FW_Short_Links_Stats::forget( $ids );
		FW_Short_Links_Categories::forget( $ids );

		$n = (int) $wpdb->query( 'DELETE FROM ' . self::t() . " WHERE id IN ({$in})" ); // phpcs:ignore WordPress.DB.PreparedSQL

		self::rebuild_index();

		return $n;
	}

	/**
	 * Atomic counters — never read-modify-write.
	 *
	 * @param int  $id
	 * @param bool $unique
	 */
	public static function increment( $id, $unique ) {
		global $wpdb;

		$wpdb->query( $wpdb->prepare( 'UPDATE ' . self::t() . ' SET clicks = clicks + 1, uniques = uniques + %d WHERE id = %d', $unique ? 1 : 0, (int) $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * @param int[] $ids
	 *
	 * @return int
	 */
	public static function reset_counts( array $ids ) {
		global $wpdb;

		$ids = array_values( array_filter( array_map( 'intval', $ids ) ) );

		if ( ! $ids ) {
			return 0;
		}

		$in = implode( ',', $ids );

		$wpdb->query( 'DELETE FROM ' . FW_Short_Links_Installer::table( 'clicks' ) . " WHERE link_id IN ({$in})" ); // phpcs:ignore WordPress.DB.PreparedSQL
		FW_Short_Links_Stats::forget( $ids );

		return (int) $wpdb->query( 'UPDATE ' . self::t() . " SET clicks = 0, uniques = 0 WHERE id IN ({$in})" ); // phpcs:ignore WordPress.DB.PreparedSQL
	}

	/**
	 * Set lifetime totals outright (imports carry them over).
	 *
	 * @param int $id
	 * @param int $clicks
	 * @param int $uniques
	 */
	public static function set_counts( $id, $clicks, $uniques ) {
		global $wpdb;

		$wpdb->update( self::t(), array( 'clicks' => max( 0, (int) $clicks ), 'uniques' => max( 0, (int) $uniques ) ), array( 'id' => (int) $id ) );
	}

	/**
	 * @param int    $id
	 * @param string $key
	 * @param string $value
	 */
	public static function set_meta( $id, $key, $value ) {
		global $wpdb;

		$table = FW_Short_Links_Installer::table( 'meta' );
		$found = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE link_id = %d AND meta_key = %s LIMIT 1", (int) $id, $key ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( $found ) {
			$wpdb->update( $table, array( 'meta_value' => $value ), array( 'id' => (int) $found ) );
		} else {
			$wpdb->insert( $table, array( 'link_id' => (int) $id, 'meta_key' => substr( $key, 0, 191 ), 'meta_value' => $value ) );
		}
	}

	/**
	 * @param int    $id
	 * @param string $key
	 *
	 * @return string|null
	 */
	public static function get_meta( $id, $key ) {
		global $wpdb;

		return $wpdb->get_var( $wpdb->prepare( 'SELECT meta_value FROM ' . FW_Short_Links_Installer::table( 'meta' ) . ' WHERE link_id = %d AND meta_key = %s LIMIT 1', (int) $id, $key ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/* ---------------------------------------------------------------------- *
	 * Cache + slug index
	 * ---------------------------------------------------------------------- */

	/**
	 * @param string $slug
	 */
	private static function changed( $slug ) {
		wp_cache_delete( 'slug:' . md5( strtolower( (string) $slug ) ), self::CACHE_GROUP );
		self::rebuild_index();
	}

	/**
	 * @param int[] $ids
	 */
	private static function changed_many( array $ids ) {
		global $wpdb;

		$in    = implode( ',', array_map( 'intval', $ids ) );
		$slugs = (array) $wpdb->get_col( 'SELECT slug FROM ' . self::t() . " WHERE id IN ({$in})" ); // phpcs:ignore WordPress.DB.PreparedSQL

		foreach ( $slugs as $slug ) {
			wp_cache_delete( 'slug:' . md5( strtolower( $slug ) ), self::CACHE_GROUP );
		}

		self::rebuild_index();
	}

	/**
	 * @param string $slug
	 *
	 * @return string
	 */
	public static function index_hash( $slug ) {
		return substr( md5( strtolower( $slug ) ), 0, 8 );
	}

	/**
	 * Every live (non-trashed) slug, enabled or disabled — a disabled link still
	 * answers with its fallback.
	 */
	public static function rebuild_index() {
		global $wpdb;

		$count = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . self::t() . ' WHERE deleted_at IS NULL' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		if ( $count > self::INDEX_MAX ) {
			update_option( self::INDEX_OPTION, 'off', true );

			return;
		}

		$slugs  = (array) $wpdb->get_col( 'SELECT slug FROM ' . self::t() . ' WHERE deleted_at IS NULL' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$hashes = array_map( array( __CLASS__, 'index_hash' ), $slugs );

		update_option( self::INDEX_OPTION, implode( ' ', $hashes ), true );
	}

	/**
	 * true = definitely not a link (skip the query); false = maybe a link.
	 *
	 * @param string $slug
	 *
	 * @return bool
	 */
	public static function definitely_not_a_link( $slug ) {
		static $set = null;

		if ( null === $set ) {
			$raw = get_option( self::INDEX_OPTION, null );

			if ( null === $raw ) {
				// Never built (first load after install): build it now, once.
				self::rebuild_index();
				$raw = get_option( self::INDEX_OPTION, '' );
			}

			$set = 'off' === $raw ? false : array_flip( '' === $raw ? array() : explode( ' ', $raw ) );
		}

		if ( false === $set ) {
			return false;
		}

		return ! isset( $set[ self::index_hash( $slug ) ] );
	}
}
