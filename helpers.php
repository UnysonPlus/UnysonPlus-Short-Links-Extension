<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * Public helpers. These are a contract for themes and other extensions —
 * treat a rename as a breaking change.
 */

if ( ! function_exists( 'fw_ext_short_links_get' ) ) {
	/**
	 * A link by id or slug (trashed links included — check ->deleted_at).
	 *
	 * @param int|string $id_or_slug
	 *
	 * @return object|null
	 */
	function fw_ext_short_links_get( $id_or_slug ) {
		if ( is_int( $id_or_slug ) || ctype_digit( (string) $id_or_slug ) && ! FW_Short_Links_Repository::find_by_slug( (string) $id_or_slug ) ) {
			return FW_Short_Links_Repository::find( (int) $id_or_slug );
		}

		return FW_Short_Links_Repository::find_by_slug( FW_Short_Links_Service::sanitize_slug( (string) $id_or_slug ) );
	}
}

if ( ! function_exists( 'fw_ext_short_links_url' ) ) {
	/**
	 * The public short URL of a link (by id, slug or row), or '' when there is none.
	 *
	 * @param int|string|object $link
	 *
	 * @return string
	 */
	function fw_ext_short_links_url( $link ) {
		if ( ! is_object( $link ) ) {
			$link = fw_ext_short_links_get( $link );
		}

		return $link && null === $link->deleted_at ? FW_Short_Links_Service::short_url( $link->slug ) : '';
	}
}

if ( ! function_exists( 'fw_ext_short_links_create' ) ) {
	/**
	 * Create a link with the same rules as the admin screen. Unset keys take the
	 * site defaults; an empty slug is generated.
	 *
	 * @param array $data target_url (required), slug, title, notes, redirect_type, status,
	 *                    fallback_url, nofollow, sponsored, new_window, param_forwarding, track
	 *
	 * @return object|WP_Error
	 */
	function fw_ext_short_links_create( array $data ) {
		return FW_Short_Links_Service::create( $data );
	}
}
