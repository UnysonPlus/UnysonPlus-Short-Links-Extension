<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * Import / export.
 *
 * Export: CSV (spreadsheet-friendly) or JSON (lossless: categories + meta).
 *
 * Import is a JOB, so a big file never has to fit in one request:
 *   1. a source (an uploaded CSV/JSON file, or another link plugin's tables
 *      read directly) is normalised into rows and written to a private file;
 *   2. a dry run classifies every row — create / update / rename / skip /
 *      error — without writing anything;
 *   3. the browser then drives `step()` in chunks with a progress bar;
 *   4. a migration can bring the source's click history across too, as a
 *      second phase keyed by the source → new link id map.
 *
 * Rows are matched by SLUG only (case-insensitive). Ids are site-local, so an
 * id from another site means nothing here.
 */
class FW_Short_Links_Transfer {

	const COLUMNS   = array( 'slug', 'target_url', 'title', 'notes', 'redirect_type', 'status', 'fallback_url', 'nofollow', 'sponsored', 'new_window', 'param_forwarding', 'track', 'categories', 'clicks', 'uniques', 'created_at' );
	const BOOLS     = array( 'nofollow', 'sponsored', 'new_window', 'param_forwarding', 'track' );
	const POLICIES  = array( 'skip', 'overwrite', 'rename' );
	const CHUNK     = 200;
	const JOB_TTL   = DAY_IN_SECONDS;
	const SOURCE_ID = '_import_source_id';

	/** True while an import step runs: per-link webhooks are held back. */
	public static $importing = false;

	/** Header aliases accepted in an uploaded CSV. */
	const ALIASES = array(
		'url'         => 'target_url',
		'destination' => 'target_url',
		'target'      => 'target_url',
		'name'        => 'title',
		'description' => 'notes',
		'type'        => 'redirect_type',
		'redirect'    => 'redirect_type',
		'category'    => 'categories',
	);

	/* ---------------------------------------------------------------------- *
	 * Export
	 * ---------------------------------------------------------------------- */

	/**
	 * Stream every non-trashed link (optionally one category) and exit.
	 *
	 * @param string $format csv|json
	 * @param int    $owner_id 0 = everyone's
	 * @param int    $category 0 = all
	 */
	public static function export( $format, $owner_id = 0, $category = 0 ) {
		$name = 'short-links-' . gmdate( 'Y-m-d' ) . ( 'json' === $format ? '.json' : '.csv' );

		nocache_headers();
		header( 'Content-Type: ' . ( 'json' === $format ? 'application/json' : 'text/csv' ) . '; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $name . '"' );

		$out = fopen( 'php://output', 'w' );

		if ( 'json' === $format ) {
			fwrite( $out, '{"format":"fw-short-links","version":1,"exported_at":' . wp_json_encode( gmdate( 'c' ) ) . ',"site":' . wp_json_encode( home_url( '/' ) ) . ',"links":[' );
		} else {
			fwrite( $out, "\xEF\xBB\xBF" ); // BOM so Excel reads UTF-8
			fputcsv( $out, self::COLUMNS );
		}

		$first = true;

		self::each( $owner_id, $category, function ( $link, $cats ) use ( $out, $format, &$first ) {
			if ( 'json' === $format ) {
				$row = self::to_row( $link, $cats );
				$row['meta'] = self::meta_of( $link->id );
				fwrite( $out, ( $first ? '' : ',' ) . wp_json_encode( $row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
			} else {
				$row = self::to_row( $link, $cats );
				$row['categories'] = implode( '|', $row['categories'] );

				foreach ( self::BOOLS as $b ) {
					$row[ $b ] = $row[ $b ] ? 1 : 0;
				}

				fputcsv( $out, array_map( array( __CLASS__, 'csv_safe' ), array_values( $row ) ) );
			}

			$first = false;
		} );

		if ( 'json' === $format ) {
			fwrite( $out, ']}' );
		}

		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		exit;
	}

	/**
	 * Walk links in pages of 500 so an export never holds the table in memory.
	 *
	 * @param int      $owner_id
	 * @param int      $category
	 * @param callable $cb ( object $link, string[] $categories )
	 */
	private static function each( $owner_id, $category, callable $cb ) {
		$page = 1;

		do {
			$res = FW_Short_Links_Repository::query( array(
				'owner_id' => $owner_id,
				'category' => $category,
				'orderby'  => 'id',
				'order'    => 'ASC',
				'per_page' => 500,
				'page'     => $page,
			) );

			$cats = FW_Short_Links_Categories::names_for( wp_list_pluck( $res['rows'], 'id' ) );

			foreach ( $res['rows'] as $link ) {
				$cb( $link, $cats[ $link->id ] ?? array() );
			}

			$page++;
		} while ( count( $res['rows'] ) === 500 );
	}

	/**
	 * @param object   $link
	 * @param string[] $cats
	 *
	 * @return array
	 */
	private static function to_row( $link, array $cats ) {
		return array(
			'slug'             => $link->slug,
			'target_url'       => $link->target_url,
			'title'            => $link->title,
			'notes'            => $link->notes,
			'redirect_type'    => $link->redirect_type,
			'status'           => $link->status,
			'fallback_url'     => $link->fallback_url,
			'nofollow'         => $link->nofollow,
			'sponsored'        => $link->sponsored,
			'new_window'       => $link->new_window,
			'param_forwarding' => $link->param_forwarding,
			'track'            => $link->track,
			'categories'       => $cats,
			'clicks'           => $link->clicks,
			'uniques'          => $link->uniques,
			'created_at'       => mysql_to_rfc3339( $link->created_at ),
		);
	}

	/**
	 * @param int $link_id
	 *
	 * @return array
	 */
	private static function meta_of( $link_id ) {
		global $wpdb;

		$rows = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT meta_key, meta_value FROM ' . FW_Short_Links_Installer::table( 'meta' ) . ' WHERE link_id = %d', $link_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$out  = array();

		foreach ( $rows as $r ) {
			$out[ $r->meta_key ] = $r->meta_value;
		}

		return $out;
	}

	/**
	 * A cell beginning with = + - @ (or a tab / CR) runs as a formula in a
	 * spreadsheet. Link titles and notes are user text, so neutralise it.
	 *
	 * @param mixed $v
	 *
	 * @return string
	 */
	public static function csv_safe( $v ) {
		$v = (string) $v;

		return preg_match( '/^[=+\-@\t\r]/', $v ) ? "'" . $v : $v;
	}

	/* ---------------------------------------------------------------------- *
	 * Sources → normalised rows
	 * ---------------------------------------------------------------------- */

	/**
	 * @param string $path uploaded temp file
	 * @param string $name original filename (for the extension)
	 *
	 * @return array|WP_Error rows
	 */
	public static function read_file( $path, $name ) {
		$ext = strtolower( pathinfo( (string) $name, PATHINFO_EXTENSION ) );

		if ( 'json' === $ext ) {
			$data = json_decode( (string) file_get_contents( $path ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

			if ( ! is_array( $data ) ) {
				return new WP_Error( 'bad_json', __( 'That file is not valid JSON.', 'fw' ) );
			}

			$links = isset( $data['links'] ) && is_array( $data['links'] ) ? $data['links'] : ( array_keys( $data ) === range( 0, count( $data ) - 1 ) ? $data : null );

			if ( null === $links ) {
				return new WP_Error( 'bad_json', __( 'Expected a Short Links export, or a JSON array of links.', 'fw' ) );
			}

			return array_map( array( __CLASS__, 'normalise' ), array_filter( $links, 'is_array' ) );
		}

		if ( 'csv' !== $ext && 'txt' !== $ext ) {
			return new WP_Error( 'bad_type', __( 'Upload a .csv or .json file.', 'fw' ) );
		}

		// fgetcsv, not a split on newlines: a quoted cell may contain line breaks.
		$fh = fopen( $path, 'r' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen

		if ( ! $fh ) {
			return new WP_Error( 'unreadable', __( 'The file could not be read.', 'fw' ) );
		}

		$header = fgetcsv( $fh, 0, ',', '"', '\\' );

		if ( ! $header ) {
			fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			return new WP_Error( 'empty', __( 'The file is empty.', 'fw' ) );
		}

		$header[0] = preg_replace( '/^\xEF\xBB\xBF/', '', (string) $header[0] );
		$keys      = array();

		foreach ( $header as $h ) {
			$k      = strtolower( trim( preg_replace( '/[^A-Za-z_ ]/', '', (string) $h ) ) );
			$k      = str_replace( ' ', '_', $k );
			$keys[] = self::ALIASES[ $k ] ?? $k;
		}

		if ( ! in_array( 'target_url', $keys, true ) ) {
			fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			return new WP_Error( 'no_url_column', __( 'The CSV needs a target_url (or url) column. Download the sample to see the expected columns.', 'fw' ) );
		}

		$rows = array();

		while ( false !== ( $cells = fgetcsv( $fh, 0, ',', '"', '\\' ) ) ) {
			if ( array( null ) === $cells ) {
				continue; // blank line
			}

			$row = array();

			foreach ( $keys as $i => $k ) {
				$v = isset( $cells[ $i ] ) ? (string) $cells[ $i ] : '';
				// Undo our own formula guard on the way back in.
				$row[ $k ] = preg_match( "/^'[=+\-@\t\r]/", $v ) ? substr( $v, 1 ) : $v;
			}

			$rows[] = self::normalise( $row );
		}

		fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		return $rows;
	}

	/**
	 * @param array $in
	 *
	 * @return array
	 */
	public static function normalise( array $in ) {
		$row = array();

		foreach ( array( 'slug', 'target_url', 'title', 'notes', 'status', 'fallback_url' ) as $k ) {
			if ( isset( $in[ $k ] ) && '' !== trim( (string) $in[ $k ] ) ) {
				$row[ $k ] = trim( (string) $in[ $k ] );
			}
		}

		if ( isset( $in['redirect_type'] ) && '' !== (string) $in['redirect_type'] ) {
			$row['redirect_type'] = (int) $in['redirect_type'];
		}

		foreach ( self::BOOLS as $b ) {
			if ( isset( $in[ $b ] ) && '' !== (string) $in[ $b ] ) {
				$row[ $b ] = ! in_array( strtolower( trim( (string) $in[ $b ] ) ), array( '0', 'false', 'no', 'off' ), true ) && false !== $in[ $b ];
			}
		}

		$cats = $in['categories'] ?? array();
		$row['categories'] = array_values( array_filter( array_map( 'trim', is_array( $cats ) ? $cats : explode( '|', (string) $cats ) ) ) );

		foreach ( array( 'clicks', 'uniques' ) as $k ) {
			$row[ $k ] = isset( $in[ $k ] ) ? max( 0, (int) $in[ $k ] ) : 0;
		}

		foreach ( array( 'source_id', 'source_note', 'trashed' ) as $k ) {
			if ( isset( $in[ $k ] ) ) {
				$row[ $k ] = $in[ $k ];
			}
		}

		if ( isset( $in['meta'] ) && is_array( $in['meta'] ) ) {
			$row['meta'] = $in['meta'];
		}

		return $row;
	}

	/* ---------------------------------------------------------------------- *
	 * Another link plugin's tables
	 * ---------------------------------------------------------------------- */

	/**
	 * Whether a link-shortener plugin's tables (the widely used `prli_` schema)
	 * exist on this site, and how much they hold.
	 *
	 * @return array|null { links, clicks, has_terms }
	 */
	public static function detect_source() {
		global $wpdb;

		$links = $wpdb->prefix . 'prli_links';

		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $links ) ) ) !== $links ) {
			return null;
		}

		$has = function ( $t ) use ( $wpdb ) {
			return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $t ) ) ) === $t;
		};

		$cols = (array) $wpdb->get_col( "SHOW COLUMNS FROM {$links}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return array(
			'links'       => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$links}" . ( in_array( 'prettypay_link', $cols, true ) ? ' WHERE prettypay_link = 0' : '' ) ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			'clicks'      => $has( $wpdb->prefix . 'prli_clicks' ) ? (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}prli_clicks" ) : 0, // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			'has_terms'   => $has( $wpdb->prefix . 'prli_link_terms' ),
			'has_metas'   => $has( $wpdb->prefix . 'prli_link_metas' ),
			'has_deleted' => in_array( 'deleted_at', $cols, true ),
		);
	}

	/**
	 * Read the other plugin's links into normalised rows.
	 *
	 * Mapping: 301/302/307 kept; its other redirect kinds (framed, meta-refresh,
	 * JavaScript, pixel, bar) become 307 with the original noted in meta; payment
	 * links and links without a destination are left out; trashed links only
	 * on request. In its counts-only mode the totals live in metas.
	 *
	 * @param bool $include_trashed
	 *
	 * @return array rows (each with source_id)
	 */
	public static function read_source( $include_trashed = false ) {
		global $wpdb;

		$src = self::detect_source();

		if ( ! $src ) {
			return array();
		}

		$p     = $wpdb->prefix;
		$cols  = (array) $wpdb->get_col( "SHOW COLUMNS FROM {$p}prli_links" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$where = array( '1=1' );

		if ( in_array( 'prettypay_link', $cols, true ) ) {
			$where[] = 'prettypay_link = 0';
		}

		if ( $src['has_deleted'] && ! $include_trashed ) {
			$where[] = 'deleted_at IS NULL';
		}

		$links = (array) $wpdb->get_results( "SELECT * FROM {$p}prli_links WHERE " . implode( ' AND ', $where ) . ' ORDER BY id ASC', ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL

		// Counts-only mode keeps totals in metas.
		$static = array();

		if ( $src['has_metas'] ) {
			foreach ( (array) $wpdb->get_results( "SELECT link_id, meta_key, meta_value FROM {$p}prli_link_metas WHERE meta_key IN ('static-clicks','static-uniques')" ) as $m ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$static[ (int) $m->link_id ][ 'static-clicks' === $m->meta_key ? 'clicks' : 'uniques' ] = (int) $m->meta_value;
			}
		}

		$cats = array();

		if ( $src['has_terms'] ) {
			foreach ( (array) $wpdb->get_results( "SELECT lt.link_id, t.name FROM {$p}prli_link_terms lt JOIN {$wpdb->terms} t ON t.term_id = lt.term_id WHERE lt.taxonomy = 'category'" ) as $c ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$cats[ (int) $c->link_id ][] = $c->name;
			}
		}

		$rows = array();

		foreach ( $links as $l ) {
			$id   = (int) $l['id'];
			$url  = trim( (string) ( $l['url'] ?? '' ) );
			$type = (string) ( $l['redirect_type'] ?? '307' );

			if ( '' === $url ) {
				continue; // pixel / payment links have no destination
			}

			$row = array(
				'slug'             => (string) $l['slug'],
				'target_url'       => $url,
				'title'            => (string) ( $l['name'] ?? '' ),
				'notes'            => (string) ( $l['description'] ?? '' ),
				'redirect_type'    => in_array( $type, array( '301', '302', '307' ), true ) ? (int) $type : 307,
				'nofollow'         => ! empty( $l['nofollow'] ),
				'sponsored'        => ! empty( $l['sponsored'] ),
				'new_window'       => ! empty( $l['new_window'] ),
				'param_forwarding' => in_array( strtolower( (string) ( $l['param_forwarding'] ?? '' ) ), array( '1', 'on' ), true ),
				'track'            => ! isset( $l['track_me'] ) || ! empty( $l['track_me'] ),
				'categories'       => $cats[ $id ] ?? array(),
				'clicks'           => (int) ( $l['clicks'] ?? 0 ),
				'uniques'          => (int) ( $l['uniques'] ?? 0 ),
				'source_id'        => $id,
				'trashed'          => ! empty( $l['deleted_at'] ),
			);

			if ( isset( $static[ $id ] ) && ! $row['clicks'] ) {
				$row['clicks']  = (int) ( $static[ $id ]['clicks'] ?? 0 );
				$row['uniques'] = (int) ( $static[ $id ]['uniques'] ?? 0 );
			}

			if ( ! in_array( $type, array( '301', '302', '307' ), true ) ) {
				/* translators: %s: the original redirect kind */
				$row['source_note'] = sprintf( __( 'Was a "%s" redirect, imported as 307.', 'fw' ), $type );
				$row['meta']        = array( '_imported_redirect_type' => $type );
			}

			$rows[] = $row;
		}

		return $rows;
	}

	/* ---------------------------------------------------------------------- *
	 * Jobs
	 * ---------------------------------------------------------------------- */

	/**
	 * @return string directory for job files (created and locked down on first use)
	 */
	private static function dir() {
		$dir = fw_upw_uploads_dir( 'short-links' )['path'] . '/imports';

		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
			file_put_contents( $dir . '/index.php', "<?php // Silence.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			file_put_contents( $dir . '/.htaccess', "Require all denied\nDeny from all\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		}

		return $dir;
	}

	/**
	 * Store rows + options as a job and dry-run it.
	 *
	 * @param array  $rows
	 * @param array  $opts policy, import_counts, include_clicks, source (file|plugin), label
	 *
	 * @return array job state
	 */
	public static function create_job( array $rows, array $opts ) {
		self::cleanup();

		$id   = bin2hex( random_bytes( 12 ) );
		$opts = array_merge( array(
			'policy'         => 'skip',
			'import_counts'  => false,
			'include_clicks' => false,
			'source'         => 'file',
			'label'          => '',
		), $opts );

		if ( ! in_array( $opts['policy'], self::POLICIES, true ) ) {
			$opts['policy'] = 'skip';
		}

		file_put_contents( self::dir() . '/' . $id . '.json', wp_json_encode( $rows ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

		$job = array_merge( $opts, array(
			'id'          => $id,
			'user_id'     => get_current_user_id(),
			'total'       => count( $rows ),
			'done'        => 0,
			'phase'       => 'review',
			'plan'        => self::dry_run( $rows, $opts['policy'] ),
			'result'      => array( 'created' => 0, 'updated' => 0, 'renamed' => 0, 'skipped' => 0, 'failed' => 0, 'clicks' => 0 ),
			'errors'      => array(),
			'map'         => array(),
			'seen'        => array(),
			'click_from'  => 0,
		) );

		self::save( $job );

		return $job;
	}

	/**
	 * @param string $id
	 *
	 * @return array|null
	 */
	public static function job( $id ) {
		$job = get_transient( 'fw_sl_job_' . preg_replace( '/[^a-f0-9]/', '', (string) $id ) );

		return is_array( $job ) && (int) $job['user_id'] === get_current_user_id() ? $job : null;
	}

	/**
	 * @param array $job
	 */
	private static function save( array $job ) {
		set_transient( 'fw_sl_job_' . $job['id'], $job, self::JOB_TTL );
	}

	/**
	 * @param string $id
	 */
	public static function cancel( $id ) {
		$job = self::job( $id );

		if ( $job ) {
			delete_transient( 'fw_sl_job_' . $job['id'] );
			@unlink( self::dir() . '/' . $job['id'] . '.json' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}
	}

	/**
	 * Remove job files older than a day.
	 */
	private static function cleanup() {
		foreach ( (array) glob( self::dir() . '/*.json' ) as $f ) {
			if ( filemtime( $f ) < time() - self::JOB_TTL ) {
				@unlink( $f ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			}
		}
	}

	/**
	 * Classify every row without writing anything.
	 *
	 * @param array  $rows
	 * @param string $policy
	 *
	 * @return array { create, update, rename, skip, error, problems: [ {row, slug, message} ] (first 50) }
	 */
	public static function dry_run( array $rows, $policy ) {
		$plan  = array( 'create' => 0, 'update' => 0, 'rename' => 0, 'skip' => 0, 'error' => 0, 'problems' => array() );
		$seen  = array();

		foreach ( $rows as $i => $row ) {
			$outcome = self::classify( $row, $policy, $seen );

			$plan[ $outcome[0] ]++;

			if ( '' !== $outcome[1] && count( $plan['problems'] ) < 50 ) {
				$plan['problems'][] = array( 'row' => $i + 1, 'slug' => $row['slug'] ?? '', 'message' => $outcome[1] );
			}
		}

		return $plan;
	}

	/**
	 * @param array  $row
	 * @param string $policy
	 * @param array  $seen slugs earlier in the same file (by reference)
	 *
	 * @return array [ outcome, message ]
	 */
	private static function classify( array $row, $policy, array &$seen ) {
		$slug = FW_Short_Links_Service::sanitize_slug( $row['slug'] ?? '' );

		if ( '' !== $slug ) {
			$lower = strtolower( $slug );

			if ( isset( $seen[ $lower ] ) ) {
				return array( 'skip', __( 'Duplicate slug earlier in this file.', 'fw' ) );
			}

			$seen[ $lower ] = true;
		}

		$existing = '' !== $slug ? FW_Short_Links_Repository::find_by_slug( $slug ) : null;

		if ( $existing ) {
			if ( 'skip' === $policy ) {
				return array( 'skip', '' );
			}

			if ( 'overwrite' === $policy ) {
				$check = FW_Short_Links_Service::prepare( self::input( $row ), $existing );

				return is_wp_error( $check ) ? array( 'error', $check->get_error_message() ) : array( 'update', '' );
			}

			$row['slug'] = '';  // rename: validated as a fresh slug below
		}

		$check = FW_Short_Links_Service::prepare( self::input( $row ) );

		if ( is_wp_error( $check ) ) {
			return array( 'error', $check->get_error_message() );
		}

		return array( $existing ? 'rename' : 'create', $existing ? sprintf( __( 'Slug taken; will import as "%s".', 'fw' ), self::rename( $slug ) ) : ( $row['source_note'] ?? '' ) );
	}

	/**
	 * The service input for a row (counts, categories, meta handled separately).
	 *
	 * @param array $row
	 *
	 * @return array
	 */
	private static function input( array $row ) {
		return array_intersect_key( $row, array_flip( array( 'slug', 'target_url', 'title', 'notes', 'redirect_type', 'status', 'fallback_url', 'nofollow', 'sponsored', 'new_window', 'param_forwarding', 'track' ) ) );
	}

	/**
	 * First free "slug-2", "slug-3" … for the rename policy.
	 *
	 * @param string $slug
	 *
	 * @return string
	 */
	private static function rename( $slug ) {
		for ( $n = 2; $n < 1000; $n++ ) {
			$try = substr( $slug, 0, FW_Short_Links_Service::SLUG_MAX - 5 ) . '-' . $n;

			if ( ! FW_Short_Links_Repository::find_by_slug( $try ) ) {
				return $try;
			}
		}

		return $slug . '-' . wp_rand( 1000, 9999 );
	}

	/**
	 * Run the next chunk. Phase "links" walks the rows; then, for a migration
	 * that asked for it, phase "clicks" copies the click history.
	 *
	 * @param string $id
	 *
	 * @return array|WP_Error job state
	 */
	public static function step( $id ) {
		$job = self::job( $id );

		if ( ! $job ) {
			return new WP_Error( 'no_job', __( 'That import has expired. Start it again.', 'fw' ) );
		}

		if ( 'review' === $job['phase'] ) {
			$job['phase'] = 'links';
		}

		self::$importing = true;

		if ( 'links' === $job['phase'] ) {
			$rows = json_decode( (string) file_get_contents( self::dir() . '/' . $job['id'] . '.json' ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			$end = min( $job['total'], $job['done'] + self::CHUNK );

			for ( $i = $job['done']; $i < $end; $i++ ) {
				self::import_row( (array) $rows[ $i ], $i, $job );
			}

			$job['done'] = $end;

			if ( $job['done'] >= $job['total'] ) {
				$job['phase'] = $job['include_clicks'] && $job['map'] ? 'clicks' : 'finishing';
			}
		} elseif ( 'clicks' === $job['phase'] ) {
			self::import_clicks( $job );
		}

		if ( 'finishing' === $job['phase'] ) {
			if ( $job['include_clicks'] && $job['map'] ) {
				FW_Short_Links_Stats::rebuild_for( array_values( $job['map'] ) );
			}

			FW_Short_Links_Repository::rebuild_index();
			@unlink( self::dir() . '/' . $job['id'] . '.json' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			$job['phase'] = 'done';

			// One summary event instead of one per imported link.
			FW_Short_Links_Webhooks::emit( 'links.imported', array( 'result' => $job['result'], 'source' => $job['source'] ) );
		}

		self::$importing = false;
		self::save( $job );

		return $job;
	}

	/**
	 * @param array $row
	 * @param int   $index
	 * @param array $job by reference
	 */
	private static function import_row( array $row, $index, array &$job ) {
		$slug = FW_Short_Links_Service::sanitize_slug( $row['slug'] ?? '' );

		// A slug repeated later in the same file is skipped, exactly as the
		// review said — the first occurrence wins. `seen` lives on the job so
		// it holds across chunks.
		if ( '' !== $slug ) {
			$key = strtolower( $slug );

			if ( isset( $job['seen'][ $key ] ) ) {
				$job['result']['skipped']++;
				return;
			}

			$job['seen'][ $key ] = 1;
		}

		$existing = '' !== $slug ? FW_Short_Links_Repository::find_by_slug( $slug ) : null;
		$fail     = function ( $message ) use ( &$job, $index, $slug ) {
			$job['result']['failed']++;

			if ( count( $job['errors'] ) < 200 ) {
				$job['errors'][] = array( 'row' => $index + 1, 'slug' => $slug, 'message' => $message );
			}
		};

		if ( $existing && 'skip' === $job['policy'] ) {
			$job['result']['skipped']++;
			return;
		}

		$input = self::input( $row );

		if ( $existing && 'overwrite' === $job['policy'] ) {
			$link = FW_Short_Links_Service::update( $existing->id, $input );

			if ( is_wp_error( $link ) ) {
				$fail( $link->get_error_message() );
				return;
			}

			if ( null !== $existing->deleted_at ) {
				FW_Short_Links_Service::restore( array( $existing->id ) );
			}

			$job['result']['updated']++;
		} else {
			if ( $existing ) {
				$input['slug'] = self::rename( $slug );
			}

			$link = FW_Short_Links_Service::create( $input );

			if ( is_wp_error( $link ) ) {
				$fail( $link->get_error_message() );
				return;
			}

			$job['result'][ $existing ? 'renamed' : 'created' ]++;
		}

		if ( ! empty( $row['categories'] ) ) {
			FW_Short_Links_Categories::set( $link->id, (array) $row['categories'], true );
		}

		if ( $job['import_counts'] && ( $row['clicks'] || $row['uniques'] ) ) {
			FW_Short_Links_Repository::set_counts( $link->id, (int) $row['clicks'], (int) $row['uniques'] );
		}

		$meta = isset( $row['meta'] ) && is_array( $row['meta'] ) ? $row['meta'] : array();

		if ( isset( $row['source_id'] ) ) {
			$meta[ self::SOURCE_ID ] = (string) $row['source_id'];
			$job['map'][ (string) $row['source_id'] ] = $link->id;
		}

		foreach ( $meta as $k => $v ) {
			FW_Short_Links_Repository::set_meta( $link->id, (string) $k, is_scalar( $v ) ? (string) $v : wp_json_encode( $v ) );
		}

		if ( ! empty( $row['trashed'] ) ) {
			FW_Short_Links_Service::trash( array( $link->id ) );
		}
	}

	/**
	 * Copy up to 2,000 source clicks per step (only for imported links).
	 *
	 * @param array $job by reference
	 */
	private static function import_clicks( array &$job ) {
		global $wpdb;

		$src  = $wpdb->prefix . 'prli_clicks';
		$ids  = array_map( 'intval', array_keys( $job['map'] ) );
		$rows = (array) $wpdb->get_results( $wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL
			"SELECT * FROM {$src} WHERE id > %d AND link_id IN (" . implode( ',', $ids ) . ') ORDER BY id ASC LIMIT 2000',
			(int) $job['click_from']
		), ARRAY_A );

		if ( ! $rows ) {
			$job['phase'] = 'finishing';
			return;
		}

		$table  = FW_Short_Links_Installer::table( 'clicks' );
		$values = array();

		foreach ( $rows as $c ) {
			$ua     = (string) ( $c['browser'] ?? '' );
			$parsed = FW_Short_Links_Tracker::parse_ua( $ua );
			$ip     = FW_Short_Links_Tracker::pack_ip( (string) ( $c['ip'] ?? '' ) );

			$values[] = $wpdb->prepare(
				'(%d, %s, ' . ( null === $ip ? 'NULL' : "UNHEX('" . bin2hex( $ip ) . "')" ) . ', %s, %d, %s, %s, %s, %s, %s, %s, %d)',
				(int) $job['map'][ (string) $c['link_id'] ],
				(string) $c['created_at'],
				'm' . substr( md5( (string) ( $c['vuid'] ?? '' ) . (string) ( $c['ip'] ?? '' ) ), 0, 40 ),
				! empty( $c['first_click'] ) ? 1 : 0,
				substr( (string) ( $c['referer'] ?? '' ), 0, 255 ),
				strtoupper( substr( (string) ( $c['country'] ?? '' ), 0, 2 ) ),
				'' !== (string) ( $c['device_type'] ?? '' ) ? substr( (string) $c['device_type'], 0, 16 ) : $parsed['device'],
				$parsed['browser'],
				$parsed['os'],
				substr( (string) ( $c['uri'] ?? '' ), 0, 255 ),
				(int) ( $c['user_id'] ?? 0 )
			);
		}

		$wpdb->query( "INSERT INTO {$table} (link_id, created_at, ip, visitor, is_unique, referrer, country, device, browser, os, query, user_id) VALUES " . implode( ',', $values ) ); // phpcs:ignore WordPress.DB.PreparedSQL

		$job['result']['clicks'] += count( $rows );
		$job['click_from']        = (int) end( $rows )['id'];
	}

	/**
	 * A sample CSV with every column and two example rows.
	 */
	public static function sample() {
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="short-links-sample.csv"' );

		$out = fopen( 'php://output', 'w' );
		fputcsv( $out, self::COLUMNS );
		fputcsv( $out, array( 'spring-sale', 'https://example.com/spring?campaign=2026', 'Spring sale', 'Newsletter CTA', 307, 'enabled', '', 1, 0, 0, 1, 1, 'Campaigns|Seasonal', '', '', '' ) );
		fputcsv( $out, array( 'partner', 'https://partner.example.net/offer?ref=site', 'Partner offer', '', 302, 'enabled', '', 1, 1, 1, 0, 1, 'Affiliates', '', '', '' ) );
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		exit;
	}
}
