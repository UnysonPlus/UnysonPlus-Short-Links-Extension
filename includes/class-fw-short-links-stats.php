<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * Daily rollups and the report queries built on them.
 *
 * `fw_short_link_daily` holds one row per link per SITE-LOCAL day, written in
 * the same shutdown step as the click (so counts-only mode feeds it too).
 * Charts and the stats API read the rollup, never a COUNT over raw clicks, so
 * they stay fast at any volume and survive the retention trim.
 *
 * Breakdowns (referrer, country, device …) need the raw rows, so they cover
 * only what retention has kept — the UI and API say so.
 */
class FW_Short_Links_Stats {

	const BREAKDOWNS = array( 'referrer', 'country', 'device', 'browser', 'os' );

	/**
	 * @return string
	 */
	private static function t() {
		return FW_Short_Links_Installer::table( 'daily' );
	}

	/**
	 * Today in the site's timezone, as Y-m-d.
	 *
	 * @param int|null $ts
	 *
	 * @return string
	 */
	public static function local_day( $ts = null ) {
		return wp_date( 'Y-m-d', null === $ts ? time() : $ts );
	}

	/**
	 * @param int  $link_id
	 * @param bool $unique
	 */
	public static function bump( $link_id, $unique ) {
		global $wpdb;

		$wpdb->query( $wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			'INSERT INTO ' . self::t() . ' (link_id, day, clicks, uniques) VALUES (%d, %s, 1, %d)
			 ON DUPLICATE KEY UPDATE clicks = clicks + 1, uniques = uniques + VALUES(uniques)',
			(int) $link_id,
			self::local_day(),
			$unique ? 1 : 0
		) );
	}

	/**
	 * @param int[] $ids
	 */
	public static function forget( array $ids ) {
		global $wpdb;

		$ids = array_values( array_filter( array_map( 'intval', $ids ) ) );

		if ( $ids ) {
			$wpdb->query( 'DELETE FROM ' . self::t() . ' WHERE link_id IN (' . implode( ',', $ids ) . ')' ); // phpcs:ignore WordPress.DB.PreparedSQL
		}
	}

	/**
	 * One-off: rebuild the rollup from whatever raw clicks exist (used when the
	 * table is first created on a site that already has click history). Raw
	 * timestamps are UTC, so days at the edges can shift by the UTC offset.
	 */
	public static function backfill() {
		global $wpdb;

		$clicks = FW_Short_Links_Installer::table( 'clicks' );

		$wpdb->query( 'DELETE FROM ' . self::t() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			'INSERT INTO ' . self::t() . " (link_id, day, clicks, uniques)
			 SELECT link_id, DATE(created_at), COUNT(*), SUM(is_unique) FROM {$clicks} GROUP BY link_id, DATE(created_at)"
		);
	}

	/**
	 * Rebuild the rollup for some links from their raw clicks (after an import
	 * brought click history in).
	 *
	 * @param int[] $ids
	 */
	public static function rebuild_for( array $ids ) {
		global $wpdb;

		$ids = array_values( array_filter( array_map( 'intval', $ids ) ) );

		if ( ! $ids ) {
			return;
		}

		$in     = implode( ',', $ids );
		$clicks = FW_Short_Links_Installer::table( 'clicks' );

		self::forget( $ids );
		$wpdb->query( // phpcs:ignore WordPress.DB.PreparedSQL
			'INSERT INTO ' . self::t() . " (link_id, day, clicks, uniques)
			 SELECT link_id, DATE(created_at), COUNT(*), SUM(is_unique) FROM {$clicks} WHERE link_id IN ({$in}) GROUP BY link_id, DATE(created_at)"
		);
	}

	/**
	 * Normalise a from/to pair (site-local Y-m-d, inclusive). Defaults to the
	 * last 30 days; never longer than 5 years.
	 *
	 * @param string $from
	 * @param string $to
	 *
	 * @return array [ from, to ]
	 */
	public static function range( $from = '', $to = '' ) {
		$re = '/^\d{4}-\d{2}-\d{2}$/';
		$to = preg_match( $re, (string) $to ) ? $to : self::local_day();

		if ( ! preg_match( $re, (string) $from ) ) {
			$from = wp_date( 'Y-m-d', strtotime( $to . ' 12:00:00 UTC' ) - 29 * DAY_IN_SECONDS );
		}

		if ( $from > $to ) {
			list( $from, $to ) = array( $to, $from );
		}

		$min = wp_date( 'Y-m-d', strtotime( $to . ' 12:00:00 UTC' ) - 5 * YEAR_IN_SECONDS );

		return array( max( $from, $min ), $to );
	}

	/**
	 * Totals and a gap-free daily series for one link, a set of links, or all.
	 *
	 * @param int[]|null $link_ids null = every link
	 * @param string     $from
	 * @param string     $to
	 *
	 * @return array { from, to, clicks, uniques, links (distinct links clicked), series: [ {date, clicks, uniques} ] }
	 */
	public static function series( $link_ids, $from, $to ) {
		global $wpdb;

		list( $from, $to ) = self::range( $from, $to );

		$where = $wpdb->prepare( 'day BETWEEN %s AND %s', $from, $to );

		if ( is_array( $link_ids ) ) {
			$ids = array_values( array_filter( array_map( 'intval', $link_ids ) ) );

			if ( ! $ids ) {
				return array( 'from' => $from, 'to' => $to, 'clicks' => 0, 'uniques' => 0, 'links' => 0, 'series' => self::fill( array(), $from, $to ) );
			}

			$where .= ' AND link_id IN (' . implode( ',', $ids ) . ')';
		}

		$rows = $wpdb->get_results( 'SELECT day, SUM(clicks) AS c, SUM(uniques) AS u FROM ' . self::t() . " WHERE {$where} GROUP BY day" ); // phpcs:ignore WordPress.DB.PreparedSQL
		$links = (int) $wpdb->get_var( 'SELECT COUNT(DISTINCT link_id) FROM ' . self::t() . " WHERE {$where}" ); // phpcs:ignore WordPress.DB.PreparedSQL

		$by_day = array();
		$total  = array( 0, 0 );

		foreach ( (array) $rows as $r ) {
			$by_day[ $r->day ] = array( (int) $r->c, (int) $r->u );
			$total[0]         += (int) $r->c;
			$total[1]         += (int) $r->u;
		}

		return array(
			'from'    => $from,
			'to'      => $to,
			'clicks'  => $total[0],
			'uniques' => $total[1],
			'links'   => $links,
			'series'  => self::fill( $by_day, $from, $to ),
		);
	}

	/**
	 * @param array  $by_day day => [clicks, uniques]
	 * @param string $from
	 * @param string $to
	 *
	 * @return array
	 */
	private static function fill( array $by_day, $from, $to ) {
		$out = array();
		$day = strtotime( $from . ' 12:00:00 UTC' );
		$end = strtotime( $to . ' 12:00:00 UTC' );

		while ( $day <= $end ) {
			$d     = gmdate( 'Y-m-d', $day );
			$out[] = array(
				'date'    => $d,
				'clicks'  => isset( $by_day[ $d ] ) ? $by_day[ $d ][0] : 0,
				'uniques' => isset( $by_day[ $d ] ) ? $by_day[ $d ][1] : 0,
			);
			$day  += DAY_IN_SECONDS;
		}

		return $out;
	}

	/**
	 * Links ranked by clicks in a range.
	 *
	 * @param string   $from
	 * @param string   $to
	 * @param int      $limit
	 * @param int      $owner_id 0 = everyone's
	 *
	 * @return array [ { id, slug, title, clicks, uniques } ]
	 */
	public static function top_links( $from, $to, $limit = 10, $owner_id = 0 ) {
		global $wpdb;

		list( $from, $to ) = self::range( $from, $to );

		$links = FW_Short_Links_Installer::table( 'links' );
		$owner = $owner_id ? $wpdb->prepare( ' AND l.owner_id = %d', $owner_id ) : '';

		$rows = $wpdb->get_results( $wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL
			'SELECT l.id, l.slug, l.title, SUM(d.clicks) AS clicks, SUM(d.uniques) AS uniques FROM ' . self::t() . " d
			 JOIN {$links} l ON l.id = d.link_id
			 WHERE d.day BETWEEN %s AND %s{$owner}
			 GROUP BY l.id ORDER BY clicks DESC LIMIT %d",
			$from,
			$to,
			max( 1, min( 100, (int) $limit ) )
		) );

		return array_map( function ( $r ) {
			return array(
				'id'      => (int) $r->id,
				'slug'    => $r->slug,
				'title'   => $r->title,
				'clicks'  => (int) $r->clicks,
				'uniques' => (int) $r->uniques,
			);
		}, (array) $rows );
	}

	/**
	 * Top values of one click column in a range (raw rows: only what retention kept).
	 *
	 * @param string     $column one of BREAKDOWNS
	 * @param int[]|null $link_ids
	 * @param string     $from
	 * @param string     $to
	 * @param int        $limit
	 *
	 * @return array [ { value, clicks } ]
	 */
	public static function breakdown( $column, $link_ids, $from, $to, $limit = 10 ) {
		global $wpdb;

		if ( ! in_array( $column, self::BREAKDOWNS, true ) ) {
			return array();
		}

		list( $from, $to ) = self::range( $from, $to );

		// Local day bounds → UTC timestamps for the raw table.
		$start = get_gmt_from_date( $from . ' 00:00:00' );
		$end   = get_gmt_from_date( $to . ' 23:59:59' );

		$clicks = FW_Short_Links_Installer::table( 'clicks' );
		$where  = $wpdb->prepare( 'created_at BETWEEN %s AND %s', $start, $end );

		if ( is_array( $link_ids ) ) {
			$ids = array_values( array_filter( array_map( 'intval', $link_ids ) ) );

			if ( ! $ids ) {
				return array();
			}

			$where .= ' AND link_id IN (' . implode( ',', $ids ) . ')';
		}

		// Referrers group by host, which is what a person wants to read.
		$expr = 'referrer' === $column
			? "SUBSTRING_INDEX(SUBSTRING_INDEX(SUBSTRING_INDEX(referrer, '://', -1), '/', 1), '?', 1)"
			: $column;

		$rows = $wpdb->get_results( $wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL
			"SELECT {$expr} AS v, COUNT(*) AS n FROM {$clicks} WHERE {$where} GROUP BY v ORDER BY n DESC LIMIT %d",
			max( 1, min( 100, (int) $limit ) )
		) );

		return array_map( function ( $r ) {
			return array( 'value' => (string) $r->v, 'clicks' => (int) $r->n );
		}, (array) $rows );
	}
}
