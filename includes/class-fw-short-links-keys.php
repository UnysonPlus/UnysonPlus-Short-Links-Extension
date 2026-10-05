<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * API keys for other apps: `Authorization: Bearer fwsl_…` on `fw-short-links/v1`.
 *
 *  - A key ACTS AS the user who created it (their capabilities, their links),
 *    narrowed by its scopes: read, write, stats.
 *  - Only a hash is stored (HMAC with the site's auth salt) plus a short
 *    prefix for lookup and display. The full key is shown once, at creation.
 *  - It authenticates ONLY requests to this extension's namespace — a leaked
 *    key cannot drive the rest of the WordPress REST API.
 *  - Rate limited per key (default 120 requests a minute).
 *
 * Application Passwords keep working alongside, for full-account access.
 */
class FW_Short_Links_Keys {

	const PREFIX = 'fwsl_';
	const SCOPES = array( 'read', 'write', 'stats' );

	/** @var object|null the key that authenticated this request */
	private static $current = null;

	public function __construct() {
		// AFTER core's cookie (10) and application-password (20) checks. Running
		// earlier does not work: wp_validate_auth_cookie() treats whatever value
		// it is handed as a cookie string, so it would discard our user id.
		add_filter( 'determine_current_user', array( $this, '_authenticate' ), 30 );
		add_filter( 'rest_pre_dispatch', array( $this, '_rate_limit' ), 10, 3 );
		add_filter( 'rest_authentication_errors', array( $this, '_auth_errors' ), 90 );
	}

	/**
	 * @return string
	 */
	private static function t() {
		return FW_Short_Links_Installer::table( 'keys' );
	}

	/**
	 * @param string $key
	 *
	 * @return string
	 */
	private static function hash( $key ) {
		return hash_hmac( 'sha256', $key, wp_salt( 'auth' ) );
	}

	/**
	 * @return object|null the authenticating key, when the request used one
	 */
	public static function current() {
		return self::$current;
	}

	/**
	 * True when the request is cookie / app-password auth (full user rights),
	 * or a key that carries $scope.
	 *
	 * @param string $scope
	 *
	 * @return bool
	 */
	public static function allows( $scope ) {
		return ! self::$current || in_array( $scope, self::$current->scopes, true );
	}

	/* ---------------------------------------------------------------------- *
	 * Auth
	 * ---------------------------------------------------------------------- */

	/**
	 * @return string
	 */
	private static function bearer() {
		$h = '';

		if ( isset( $_SERVER['HTTP_AUTHORIZATION'] ) ) {
			$h = (string) $_SERVER['HTTP_AUTHORIZATION'];
		} elseif ( isset( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ) ) {
			$h = (string) $_SERVER['REDIRECT_HTTP_AUTHORIZATION']; // Apache + CGI
		} elseif ( function_exists( 'getallheaders' ) ) {
			// mod_php does not copy a Bearer header into $_SERVER.
			foreach ( (array) getallheaders() as $name => $value ) {
				if ( 'authorization' === strtolower( $name ) ) {
					$h = (string) $value;
					break;
				}
			}
		}

		return preg_match( '/^Bearer\s+(' . self::PREFIX . '[A-Za-z0-9]{40})$/', trim( $h ), $m ) ? $m[1] : '';
	}

	/**
	 * Only this extension's routes, whether reached by pretty URL or ?rest_route=.
	 *
	 * @return bool
	 */
	private static function is_our_route() {
		$ns  = '/' . FW_Short_Links_REST::NAMESPACE_ . '/';
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? rawurldecode( (string) $_SERVER['REQUEST_URI'] ) : '';

		if ( false !== strpos( $uri, '/' . rest_get_url_prefix() . $ns ) ) {
			return true;
		}

		return isset( $_GET['rest_route'] ) && 0 === strpos( '/' . ltrim( (string) $_GET['rest_route'], '/' ), $ns ); // phpcs:ignore WordPress.Security.NonceVerification
	}

	/**
	 * @internal
	 *
	 * @param int|false $user_id
	 *
	 * @return int|false
	 */
	public function _authenticate( $user_id ) {
		if ( $user_id ) {
			return $user_id;
		}

		$key = self::bearer();

		if ( '' === $key || ! self::is_our_route() ) {
			return $user_id;
		}

		global $wpdb;

		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::t() . ' WHERE prefix = %s AND revoked_at IS NULL LIMIT 1', substr( $key, 0, 12 ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		if ( ! $row || ! hash_equals( $row->key_hash, self::hash( $key ) ) || ! get_userdata( (int) $row->user_id ) ) {
			self::$current = (object) array( 'invalid' => true );

			return $user_id;
		}

		$row->scopes   = array_values( array_intersect( self::SCOPES, explode( ',', $row->scopes ) ) );
		$row->id       = (int) $row->id;
		self::$current = $row;

		// Recording use is a write; once a minute per key is plenty.
		if ( ! $row->last_used_at || strtotime( $row->last_used_at . ' UTC' ) < time() - MINUTE_IN_SECONDS ) {
			$wpdb->update( self::t(), array( 'last_used_at' => current_time( 'mysql', true ) ), array( 'id' => $row->id ) );
		}

		return (int) $row->user_id;
	}

	/**
	 * A malformed or revoked key is an error, not a silent fall-through to
	 * anonymous — the caller should know their key does not work.
	 *
	 * @internal
	 */
	public function _auth_errors( $result ) {
		if ( self::$current && ! empty( self::$current->invalid ) ) {
			return new WP_Error( 'fw_short_links_invalid_key', __( 'Invalid or revoked API key.', 'fw' ), array( 'status' => 401 ) );
		}

		return $result;
	}

	/**
	 * @internal
	 *
	 * @param mixed           $result
	 * @param WP_REST_Server  $server
	 * @param WP_REST_Request $request
	 *
	 * @return mixed
	 */
	public function _rate_limit( $result, $server, $request ) {
		if ( null !== $result || ! self::$current || ! empty( self::$current->invalid ) ) {
			return $result;
		}

		/** Filters the per-key request limit per minute. */
		$limit  = (int) apply_filters( 'fw_ext_short_links_api_rate_limit', 120, self::$current );
		$bucket = 'fw_sl_rl_' . self::$current->id . '_' . floor( time() / 60 );
		$count  = (int) get_transient( $bucket ) + 1;

		set_transient( $bucket, $count, 2 * MINUTE_IN_SECONDS );

		if ( $limit > 0 && $count > $limit ) {
			return new WP_Error( 'fw_short_links_rate_limited', __( 'Too many requests for this API key. Slow down and retry after a minute.', 'fw' ), array( 'status' => 429 ) );
		}

		return $result;
	}

	/* ---------------------------------------------------------------------- *
	 * Management
	 * ---------------------------------------------------------------------- */

	/**
	 * @param string   $name
	 * @param string[] $scopes
	 * @param int      $user_id the user the key acts as
	 *
	 * @return array|WP_Error { id, key } — the only time the full key exists
	 */
	public static function create( $name, array $scopes, $user_id ) {
		global $wpdb;

		$scopes = array_values( array_intersect( self::SCOPES, $scopes ) );

		if ( ! $scopes ) {
			return new WP_Error( 'no_scope', __( 'Give the key at least one permission.', 'fw' ) );
		}

		$key = self::PREFIX . wp_generate_password( 40, false, false );

		$wpdb->insert( self::t(), array(
			'name'       => substr( sanitize_text_field( $name ), 0, 100 ) ?: __( 'Unnamed key', 'fw' ),
			'prefix'     => substr( $key, 0, 12 ),
			'key_hash'   => self::hash( $key ),
			'scopes'     => implode( ',', $scopes ),
			'user_id'    => (int) $user_id,
			'created_at' => current_time( 'mysql', true ),
		) );

		return array( 'id' => (int) $wpdb->insert_id, 'key' => $key );
	}

	/**
	 * @param int $id
	 */
	public static function revoke( $id ) {
		global $wpdb;

		$wpdb->update( self::t(), array( 'revoked_at' => current_time( 'mysql', true ) ), array( 'id' => (int) $id ) );
	}

	/**
	 * @return object[] live keys, newest first
	 */
	public static function all() {
		global $wpdb;

		return (array) $wpdb->get_results( 'SELECT id, name, prefix, scopes, user_id, last_used_at, created_at FROM ' . self::t() . ' WHERE revoked_at IS NULL ORDER BY id DESC' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}
}
