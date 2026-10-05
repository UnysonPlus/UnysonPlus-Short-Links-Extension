<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * The redirect. Runs on `init` priority 0 — after the extension has loaded,
 * before WordPress parses the request — and answers GET and HEAD.
 *
 * Cost for a request that is NOT a short link: a path parse and an isset()
 * against the in-memory slug index; no query. Cost for a short link: one
 * cached lookup, the redirect, then the click write after the response.
 *
 * Only stored links redirect, and only to their stored target. Nothing in the
 * request can choose the destination, so this is not an open redirect.
 */
class FW_Short_Links_Redirect {

	public function __construct() {
		add_action( 'init', array( $this, '_maybe_redirect' ), 0 );
	}

	/**
	 * @internal
	 */
	public function _maybe_redirect() {
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() || ( defined( 'REST_REQUEST' ) && REST_REQUEST )
			|| ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST ) || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			return;
		}

		$method = strtoupper( (string) ( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) );

		if ( 'GET' !== $method && 'HEAD' !== $method ) {
			return;
		}

		$slug = self::slug_from_request();

		if ( '' === $slug || FW_Short_Links_Repository::definitely_not_a_link( $slug ) ) {
			return;
		}

		$link = FW_Short_Links_Repository::resolve( $slug );

		/** Filters the link resolved for a request (null = not a short link). */
		$link = apply_filters( 'fw_ext_short_links_resolve', $link, $slug );

		if ( ! $link ) {
			return;
		}

		if ( 'enabled' !== $link->status ) {
			// A disabled link with no fallback simply is not there: WordPress goes
			// on to route the request and shows its normal 404.
			if ( '' === $link->fallback_url ) {
				return;
			}

			$this->send( $link->fallback_url, 302, $link );
		}

		$target = self::target_for( $link );

		/** Filters the final destination URL. Return '' to let WordPress handle the request instead. */
		$target = (string) apply_filters( 'fw_ext_short_links_target_url', $target, $link );

		if ( '' === $target ) {
			return;
		}

		FW_Short_Links_Tracker::track( $link );

		/** Fires right before the redirect is sent. */
		do_action( 'fw_ext_short_links_before_redirect', $link, $target );

		$this->send( $target, $link->redirect_type, $link );
	}

	/**
	 * The slug this request asks for, or '' when it cannot be one.
	 *
	 * @return string
	 */
	public static function slug_from_request() {
		$uri  = isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : '';
		$path = (string) wp_parse_url( $uri, PHP_URL_PATH );
		$path = rawurldecode( $path );

		// Subdirectory installs: drop the home path once.
		$home = trim( (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH ), '/' );
		$path = trim( $path, '/' );

		if ( '' !== $home ) {
			if ( 0 !== strpos( $path . '/', $home . '/' ) ) {
				return '';
			}

			$path = trim( substr( $path, strlen( $home ) ), '/' );
		}

		// "Almost pretty" permalinks.
		if ( 0 === strpos( $path, 'index.php/' ) ) {
			$path = substr( $path, 10 );
		}

		$prefix = FW_Short_Links_Service::settings()['link_prefix'];

		if ( '' !== $prefix ) {
			if ( 0 !== stripos( $path, $prefix . '/' ) ) {
				return '';
			}

			$path = substr( $path, strlen( $prefix ) + 1 );
		}

		if ( '' === $path || strlen( $path ) > FW_Short_Links_Service::SLUG_MAX || preg_match( '#[^A-Za-z0-9\-_.~/]#', $path ) ) {
			return '';
		}

		return $path;
	}

	/**
	 * The stored target plus the incoming query string when forwarding is on.
	 * The RAW query string is appended, so percent-encoding survives (a%20b
	 * stays a%20b), and it goes before any #fragment.
	 *
	 * @param object $link
	 *
	 * @return string
	 */
	public static function target_for( $link ) {
		$target = $link->target_url;
		$query  = isset( $_SERVER['QUERY_STRING'] ) ? (string) $_SERVER['QUERY_STRING'] : '';
		$query  = str_replace( array( "\r", "\n" ), '', $query );

		if ( ! $link->param_forwarding || '' === $query || ! preg_match( '#^https?://#i', $target ) ) {
			return $target;
		}

		$fragment = '';
		$hash     = strpos( $target, '#' );

		if ( false !== $hash ) {
			$fragment = substr( $target, $hash );
			$target   = substr( $target, 0, $hash );
		}

		return $target . ( false === strpos( $target, '?' ) ? '?' : '&' ) . $query . $fragment;
	}

	/**
	 * @param string $url
	 * @param int    $status
	 * @param object $link
	 */
	private function send( $url, $status, $link ) {
		$url = str_replace( array( "\r", "\n" ), '', $url );

		nocache_headers();
		// No store at all, including for 301/308: a browser that caches the
		// redirect never asks again, so later edits and click counts are lost.
		header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0, private' );
		header( 'X-Robots-Tag: ' . ( $link->nofollow ? 'noindex, nofollow' : 'noindex' ) );

		if ( preg_match( '#^https?://#i', $url ) ) {
			// Not wp_safe_redirect(): an off-site destination is the whole point,
			// and the destination is never taken from the request.
			wp_redirect( $url, (int) $status, false ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect
		} else {
			// mailto: / tel: / sms: — wp_redirect() would strip the scheme.
			status_header( 302 );
			header( 'Location: ' . $url );
		}

		exit;
	}
}
