<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * The business rules, and the only thing outsiders (admin, REST, shortcode,
 * helpers) call. Validation, slug rules, ownership, and the public hooks all
 * live here so every entry point behaves the same.
 */
class FW_Short_Links_Service {

	const SLUG_MAX = 191;

	/** @var array|null per-request settings cache; reset by flush_settings() */
	private static $settings = null;

	/** WordPress paths a short link may never claim (fnmatch, case-insensitive). */
	const RESERVED = array(
		'wp-admin', 'wp-admin/*', 'wp-login.php', 'wp-json', 'wp-json/*', 'wp-content/*', 'wp-includes/*',
		'wp-cron.php', 'xmlrpc.php', '*.php', 'feed', 'feed/*', '*/feed', 'comments/*', 'sitemap*', 'robots.txt',
		'favicon.ico', '.well-known/*', 'page/*', 'category/*', 'tag/*', 'author/*', 'search/*', 'embed',
		'*/embed', 'trackback', '*/trackback', 'wp-sitemap*',
	);

	/* ---------------------------------------------------------------------- *
	 * Settings
	 * ---------------------------------------------------------------------- */

	/**
	 * Saved settings over the defaults. One keyless read per request.
	 *
	 * @return array
	 */
	public static function settings() {
		if ( null !== self::$settings ) {
			return self::$settings;
		}

		$saved = (array) fw_get_db_ext_settings_option( 'short-links' );

		$settings = array_merge( array(
			'default_redirect'  => '307',
			'slug_length'       => 5,
			'link_prefix'       => '',
			'reserved_patterns' => '',
			'default_nofollow'  => true,
			'default_sponsored' => false,
			'default_track'     => true,
			'tracking_mode'     => 'full',
			'use_cookies'       => true,
			'track_editors'     => false,
			'anonymize_ip'      => true,
			'bot_filter'        => true,
			'bot_patterns'      => '',
			'excluded_ips'      => '',
			'trusted_proxies'   => '',
			'retention_days'    => '0',
		), array_filter( $saved, function ( $v ) {
			return null !== $v;
		} ) );

		$settings['link_prefix'] = trim( self::sanitize_slug( (string) $settings['link_prefix'] ), '/' );

		self::$settings = $settings;

		return $settings;
	}

	/**
	 * Called after the settings are saved: drop the cached copy (admin_init has
	 * already read the old values this request) and reschedule the trim job for
	 * the new retention window.
	 */
	public static function flush_settings() {
		self::$settings = null;
		FW_Short_Links_Tracker::schedule_trim( true );
	}

	/**
	 * Split a settings textarea into trimmed, non-empty lines (commas also split).
	 *
	 * @param string $text
	 *
	 * @return string[]
	 */
	public static function lines( $text ) {
		return array_values( array_filter( array_map( 'trim', preg_split( '/[\r\n,]+/', (string) $text ) ) ) );
	}

	/* ---------------------------------------------------------------------- *
	 * Permissions
	 * ---------------------------------------------------------------------- */

	/**
	 * @return bool
	 */
	public static function can_edit() {
		return current_user_can( 'edit_short_links' ) || current_user_can( 'manage_short_links' );
	}

	/**
	 * @return bool
	 */
	public static function can_manage() {
		return current_user_can( 'manage_short_links' );
	}

	/**
	 * @param object|null $link
	 *
	 * @return bool
	 */
	public static function can_edit_link( $link ) {
		if ( ! $link ) {
			return false;
		}

		return self::can_manage() || ( self::can_edit() && (int) $link->owner_id === get_current_user_id() );
	}

	/* ---------------------------------------------------------------------- *
	 * URLs
	 * ---------------------------------------------------------------------- */

	/**
	 * The public short URL for a slug (the prefix is part of the address, not
	 * of the stored slug, so changing it moves every link at once).
	 *
	 * @param string $slug
	 *
	 * @return string
	 */
	public static function short_url( $slug ) {
		$prefix = self::settings()['link_prefix'];
		$path   = ( '' !== $prefix ? $prefix . '/' : '' ) . $slug;

		// Encode each segment but keep the slashes.
		$path = implode( '/', array_map( 'rawurlencode', explode( '/', $path ) ) );

		return home_url( '/' . $path );
	}

	/* ---------------------------------------------------------------------- *
	 * Slugs
	 * ---------------------------------------------------------------------- */

	/**
	 * Letters, digits and - _ . ~ / only. Whitespace becomes a dash; repeated
	 * slashes and dashes collapse; leading/trailing slashes go.
	 *
	 * @param string $slug
	 *
	 * @return string
	 */
	public static function sanitize_slug( $slug ) {
		$slug = trim( (string) $slug );
		$slug = preg_replace( '/\s+/', '-', $slug );
		$slug = preg_replace( '#[^A-Za-z0-9\-_.~/]#', '', $slug );
		$slug = preg_replace( '#/{2,}#', '/', $slug );
		$slug = preg_replace( '/-{2,}/', '-', $slug );
		$slug = trim( $slug, '/' );

		return substr( $slug, 0, self::SLUG_MAX );
	}

	/**
	 * @param string $slug
	 *
	 * @return bool
	 */
	public static function is_reserved( $slug ) {
		$patterns = array_merge( self::RESERVED, self::lines( self::settings()['reserved_patterns'] ) );

		/** Filters the slug patterns (fnmatch wildcards) a short link may not use. */
		$patterns = (array) apply_filters( 'fw_ext_short_links_reserved_slugs', $patterns );

		$prefix = self::settings()['link_prefix'];
		$full   = strtolower( ( '' !== $prefix ? $prefix . '/' : '' ) . $slug );

		foreach ( $patterns as $p ) {
			if ( fnmatch( strtolower( $p ), $full ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * What already answers at this address on the site, if anything (a post,
	 * page, or term archive). A short link there would silently hide it.
	 *
	 * @param string $slug
	 *
	 * @return string '' when free, otherwise a human description
	 */
	public static function site_conflict( $slug ) {
		$prefix = self::settings()['link_prefix'];

		if ( '' !== $prefix ) {
			// A prefixed link lives under its own path segment; only a page at
			// exactly that path could collide.
			$page = get_page_by_path( $prefix . '/' . $slug, OBJECT, get_post_types( array( 'public' => true ) ) );

			return $page ? sprintf( __( 'the %1$s "%2$s"', 'fw' ), get_post_type_object( $page->post_type )->labels->singular_name, $page->post_title ) : '';
		}

		$post_id = url_to_postid( home_url( '/' . $slug . '/' ) );

		if ( ! $post_id ) {
			$page = get_page_by_path( $slug, OBJECT, get_post_types( array( 'public' => true ) ) );
			$post_id = $page ? $page->ID : 0;
		}

		if ( $post_id ) {
			$post = get_post( $post_id );
			$type = $post ? get_post_type_object( $post->post_type ) : null;

			return sprintf( __( 'the %1$s "%2$s"', 'fw' ), $type ? $type->labels->singular_name : __( 'post', 'fw' ), $post ? $post->post_title : '' );
		}

		return '';
	}

	/**
	 * A random slug that is free: not taken, not reserved, not shadowing content.
	 *
	 * @return string
	 */
	public static function generate_slug() {
		$length   = max( 3, min( 20, (int) self::settings()['slug_length'] ) );
		$alphabet = 'abcdefghijkmnpqrstuvwxyz23456789'; // no 0/o/1/l: readable aloud and in print

		for ( $attempt = 0; $attempt < 30; $attempt++ ) {
			// Grow the length after repeated collisions instead of looping forever.
			$len  = $length + (int) floor( $attempt / 10 );
			$slug = '';

			for ( $i = 0; $i < $len; $i++ ) {
				$slug .= $alphabet[ wp_rand( 0, strlen( $alphabet ) - 1 ) ];
			}

			if ( ! FW_Short_Links_Repository::find_by_slug( $slug ) && ! self::is_reserved( $slug ) && '' === self::site_conflict( $slug ) ) {
				return $slug;
			}
		}

		return strtolower( wp_generate_password( $length + 4, false ) );
	}

	/* ---------------------------------------------------------------------- *
	 * Validation
	 * ---------------------------------------------------------------------- */

	/**
	 * @param string $url
	 *
	 * @return bool
	 */
	public static function is_valid_target( $url ) {
		$url = trim( (string) $url );

		if ( '' === $url || preg_match( '/[\r\n]/', $url ) ) {
			return false;
		}

		$scheme = strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) );

		if ( in_array( $scheme, array( 'mailto', 'tel', 'sms' ), true ) ) {
			return true;
		}

		return in_array( $scheme, array( 'http', 'https' ), true ) && (bool) wp_parse_url( $url, PHP_URL_HOST );
	}

	/**
	 * Normalise + validate input for a create (no $existing) or an update.
	 *
	 * @param array       $input
	 * @param object|null $existing
	 *
	 * @return array|WP_Error clean column => value
	 */
	public static function prepare( array $input, $existing = null ) {
		$s      = self::settings();
		$is_new = ! $existing;
		$data   = array();
		$errors = new WP_Error();

		// Target.
		if ( $is_new || array_key_exists( 'target_url', $input ) ) {
			$url = trim( (string) ( $input['target_url'] ?? '' ) );

			if ( ! self::is_valid_target( $url ) ) {
				$errors->add( 'invalid_target', __( 'Enter a full destination URL starting with http:// or https:// (mailto:, tel: and sms: also work).', 'fw' ) );
			} else {
				$data['target_url'] = esc_url_raw( $url, array( 'http', 'https', 'mailto', 'tel', 'sms' ) );
			}
		}

		// Slug.
		if ( $is_new || array_key_exists( 'slug', $input ) ) {
			$slug = self::sanitize_slug( (string) ( $input['slug'] ?? '' ) );

			if ( '' === $slug ) {
				$slug = $is_new ? self::generate_slug() : $existing->slug;
			}

			$taken = FW_Short_Links_Repository::find_by_slug( $slug );

			if ( $taken && ( $is_new || (int) $taken->id !== (int) $existing->id ) ) {
				$errors->add( 'slug_taken', $taken->deleted_at
					? sprintf( __( 'The slug "%s" belongs to a link in the trash. Restore it, or delete it permanently first.', 'fw' ), $slug )
					: sprintf( __( 'The slug "%s" is already used by another link.', 'fw' ), $slug ) );
			} elseif ( ( $is_new || 0 !== strcasecmp( $slug, $existing->slug ) ) && self::is_reserved( $slug ) ) {
				$errors->add( 'slug_reserved', sprintf( __( 'The slug "%s" is reserved for WordPress (or by your reserved patterns).', 'fw' ), $slug ) );
			} elseif ( $is_new || 0 !== strcasecmp( $slug, $existing->slug ) ) {
				$conflict = self::site_conflict( $slug );

				if ( '' !== $conflict ) {
					$errors->add( 'slug_conflict', sprintf( __( 'The address "%1$s" is already %2$s. Pick another slug so the link does not hide it.', 'fw' ), $slug, $conflict ) );
				}
			}

			$data['slug'] = $slug;

			// Loop guard: a link must not point at itself.
			if ( isset( $data['target_url'] ) || ! $is_new ) {
				$target = $data['target_url'] ?? $existing->target_url;

				if ( untrailingslashit( strtok( $target, '?#' ) ) === untrailingslashit( self::short_url( $slug ) ) ) {
					$errors->add( 'loop', __( 'A short link cannot point at itself.', 'fw' ) );
				}
			}
		}

		if ( array_key_exists( 'title', $input ) ) {
			$data['title'] = substr( sanitize_text_field( (string) $input['title'] ), 0, 255 );
		} elseif ( $is_new ) {
			$data['title'] = '';
		}

		if ( array_key_exists( 'notes', $input ) ) {
			$data['notes'] = sanitize_textarea_field( (string) $input['notes'] );
		}

		if ( $is_new || array_key_exists( 'redirect_type', $input ) ) {
			$type = (int) ( $input['redirect_type'] ?? $s['default_redirect'] );

			if ( ! in_array( $type, FW_Short_Links_Repository::REDIRECT_TYPES, true ) ) {
				$errors->add( 'invalid_redirect', __( 'Redirect type must be 301, 302, 307 or 308.', 'fw' ) );
			}

			$data['redirect_type'] = $type;
		}

		if ( $is_new || array_key_exists( 'status', $input ) ) {
			$status = (string) ( $input['status'] ?? 'enabled' );

			if ( ! in_array( $status, FW_Short_Links_Repository::STATUSES, true ) ) {
				$errors->add( 'invalid_status', __( 'Status must be enabled or disabled.', 'fw' ) );
			}

			$data['status'] = $status;
		}

		if ( array_key_exists( 'fallback_url', $input ) ) {
			$fallback = trim( (string) $input['fallback_url'] );

			if ( '' !== $fallback && ! self::is_valid_target( $fallback ) ) {
				$errors->add( 'invalid_fallback', __( 'The fallback URL must be a full http(s) URL, or empty.', 'fw' ) );
			}

			$data['fallback_url'] = '' === $fallback ? '' : esc_url_raw( $fallback );
		} elseif ( $is_new ) {
			$data['fallback_url'] = '';
		}

		$flag_defaults = array(
			'nofollow'         => (bool) $s['default_nofollow'],
			'sponsored'        => (bool) $s['default_sponsored'],
			'new_window'       => false,
			'param_forwarding' => false,
			'track'            => (bool) $s['default_track'],
		);

		foreach ( $flag_defaults as $flag => $default ) {
			if ( array_key_exists( $flag, $input ) ) {
				$data[ $flag ] = rest_sanitize_boolean( $input[ $flag ] ) ? 1 : 0;
			} elseif ( $is_new ) {
				$data[ $flag ] = $default ? 1 : 0;
			}
		}

		if ( $is_new ) {
			$data['owner_id'] = get_current_user_id();
		}

		if ( $errors->has_errors() ) {
			return $errors;
		}

		/** Filters a link's validated data before it is written. Return a WP_Error to reject it. */
		return apply_filters( 'fw_ext_short_links_prepare', $data, $input, $existing );
	}

	/* ---------------------------------------------------------------------- *
	 * Lifecycle
	 * ---------------------------------------------------------------------- */

	/**
	 * @param array $input
	 *
	 * @return object|WP_Error the saved link
	 */
	public static function create( array $input ) {
		$data = self::prepare( $input );

		if ( is_wp_error( $data ) ) {
			return $data;
		}

		if ( '' === $data['title'] && ! empty( $input['fetch_title'] ) ) {
			$data['title'] = self::fetch_title( $data['target_url'] );
		}

		$id = FW_Short_Links_Repository::insert( $data );

		if ( ! $id ) {
			return new WP_Error( 'insert_failed', __( 'The link could not be saved.', 'fw' ) );
		}

		if ( isset( $input['categories'] ) && is_array( $input['categories'] ) ) {
			FW_Short_Links_Categories::set( $id, $input['categories'] );
		}

		$link = FW_Short_Links_Repository::find( $id );

		/** Fires after a short link is created or updated. */
		do_action( 'fw_ext_short_links_saved', $link, true );

		return $link;
	}

	/**
	 * @param int   $id
	 * @param array $input
	 * @param int   $expected_version 0 = do not check
	 *
	 * @return object|WP_Error
	 */
	public static function update( $id, array $input, $expected_version = 0 ) {
		$existing = FW_Short_Links_Repository::find( $id );

		if ( ! $existing ) {
			return new WP_Error( 'not_found', __( 'That link no longer exists.', 'fw' ), array( 'status' => 404 ) );
		}

		if ( $expected_version && $expected_version !== $existing->version ) {
			return new WP_Error( 'version_conflict', __( 'This link was changed by someone else since you loaded it. Reload and try again.', 'fw' ), array(
				'status'  => 409,
				'current' => $existing->version,
			) );
		}

		$data = self::prepare( $input, $existing );

		if ( is_wp_error( $data ) ) {
			return $data;
		}

		if ( isset( $data['title'] ) && '' === $data['title'] && ! empty( $input['fetch_title'] ) ) {
			$data['title'] = self::fetch_title( $data['target_url'] ?? $existing->target_url );
		}

		FW_Short_Links_Repository::update( $id, $data );

		if ( isset( $input['categories'] ) && is_array( $input['categories'] ) ) {
			FW_Short_Links_Categories::set( $id, $input['categories'] );
		}

		$link = FW_Short_Links_Repository::find( $id );

		do_action( 'fw_ext_short_links_saved', $link, false );

		return $link;
	}

	/**
	 * @param int[] $ids
	 *
	 * @return int
	 */
	public static function trash( array $ids ) {
		$n = FW_Short_Links_Repository::bulk_set( $ids, 'deleted_at = %s', array( current_time( 'mysql', true ) ) );

		/** Fires after links are trashed (false) or permanently deleted (true). */
		do_action( 'fw_ext_short_links_deleted', $ids, false );

		return $n;
	}

	/**
	 * @param int[] $ids
	 *
	 * @return int
	 */
	public static function restore( array $ids ) {
		$n = FW_Short_Links_Repository::bulk_set( $ids, 'deleted_at = NULL' );

		/** Fires after links are restored from the trash. */
		do_action( 'fw_ext_short_links_restored', $ids );

		return $n;
	}

	/**
	 * @param int[] $ids
	 *
	 * @return int
	 */
	public static function delete( array $ids ) {
		$n = FW_Short_Links_Repository::delete( $ids );

		do_action( 'fw_ext_short_links_deleted', $ids, true );

		return $n;
	}

	/**
	 * @param int $id
	 *
	 * @return object|WP_Error
	 */
	public static function duplicate( $id ) {
		$link = FW_Short_Links_Repository::find( $id );

		if ( ! $link ) {
			return new WP_Error( 'not_found', __( 'That link no longer exists.', 'fw' ) );
		}

		$copy = array_intersect_key( (array) $link, array_flip( FW_Short_Links_Repository::WRITABLE ) );

		$copy['slug']       = self::generate_slug();
		$copy['categories'] = wp_list_pluck( FW_Short_Links_Categories::of( $link->id ), 'term_id' );
		$copy['title'] = '' !== $link->title ? sprintf( __( '%s (copy)', 'fw' ), $link->title ) : '';

		return self::create( $copy );
	}

	/**
	 * The destination page's <title>, for links saved without one. Bounded:
	 * safe-remote (no internal hosts), 5 s, first 64 KB.
	 *
	 * @param string $url
	 *
	 * @return string
	 */
	public static function fetch_title( $url ) {
		if ( ! preg_match( '#^https?://#i', $url ) ) {
			return '';
		}

		$res = wp_safe_remote_get( $url, array(
			'timeout'             => 5,
			'redirection'         => 3,
			'limit_response_size' => 65536,
			'user-agent'          => 'WordPress/' . get_bloginfo( 'version' ) . '; ' . home_url( '/' ),
		) );

		if ( is_wp_error( $res ) || 200 !== (int) wp_remote_retrieve_response_code( $res ) ) {
			return '';
		}

		if ( ! preg_match( '#<title[^>]*>(.*?)</title>#is', wp_remote_retrieve_body( $res ), $m ) ) {
			return '';
		}

		$title = trim( preg_replace( '/\s+/', ' ', html_entity_decode( wp_strip_all_tags( $m[1] ), ENT_QUOTES, 'UTF-8' ) ) );

		return function_exists( 'mb_substr' ) ? mb_substr( $title, 0, 150 ) : substr( $title, 0, 150 );
	}

	/**
	 * The shape every outside caller (REST, helpers) sees.
	 *
	 * @param object $link
	 *
	 * @return array
	 */
	public static function to_array( $link ) {
		return array(
			'id'               => $link->id,
			'slug'             => $link->slug,
			'short_url'        => self::short_url( $link->slug ),
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
			'owner_id'         => $link->owner_id,
			'clicks'           => $link->clicks,
			'uniques'          => $link->uniques,
			'version'          => $link->version,
			'created_at'       => mysql_to_rfc3339( $link->created_at ),
			'updated_at'       => mysql_to_rfc3339( $link->updated_at ),
			'trashed'          => null !== $link->deleted_at,
			'categories'       => wp_list_pluck( FW_Short_Links_Categories::of( $link->id ), 'name' ),
		);
	}
}
