<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * REST — `fw-short-links/v1`: the admin's own surface and the public API for
 * other apps. Every write goes through the service, so an app gets exactly the
 * rules the screen enforces.
 *
 * Auth: cookie + nonce (the admin), an Application Password (full account), or
 * an API key — `Authorization: Bearer fwsl_…` — which acts as the user who made
 * it, narrowed to its scopes (read / write / stats). Authors see and change only
 * their own links.
 *
 * Sync: every link carries `version`. Send it back on PATCH (or as `If-Match`)
 * and a stale write gets 409 instead of overwriting someone else's edit. List
 * with `view=any&modified_since=…` to pick up changes, trashings included;
 * permanent deletions arrive by webhook (`link.deleted`).
 *
 * `GET /openapi.json` describes all of it, machine-readably.
 */
class FW_Short_Links_REST {

	const NAMESPACE_  = 'fw-short-links/v1';
	const BATCH_LIMIT = 100;

	public function __construct() {
		add_action( 'rest_api_init', array( $this, '_register_routes' ) );
	}

	/**
	 * @return array
	 */
	private function link_args() {
		return array(
			'target_url'       => array( 'type' => 'string', 'description' => 'Destination URL (http, https, mailto, tel, sms).' ),
			'slug'             => array( 'type' => 'string', 'description' => 'The path after the domain. Empty on create = generated.' ),
			'title'            => array( 'type' => 'string' ),
			'notes'            => array( 'type' => 'string' ),
			'redirect_type'    => array( 'type' => 'integer', 'enum' => FW_Short_Links_Repository::REDIRECT_TYPES ),
			'status'           => array( 'type' => 'string', 'enum' => FW_Short_Links_Repository::STATUSES ),
			'fallback_url'     => array( 'type' => 'string', 'description' => 'Where a disabled link sends visitors; empty = 404.' ),
			'nofollow'         => array( 'type' => 'boolean' ),
			'sponsored'        => array( 'type' => 'boolean' ),
			'new_window'       => array( 'type' => 'boolean' ),
			'param_forwarding' => array( 'type' => 'boolean' ),
			'track'            => array( 'type' => 'boolean' ),
			'categories'       => array( 'type' => 'array', 'items' => array( 'type' => array( 'integer', 'string' ) ), 'description' => 'Category ids or names; replaces the link\'s categories.' ),
			'fetch_title'      => array( 'type' => 'boolean', 'default' => false, 'description' => 'Fill an empty title from the destination page.' ),
		);
	}

	/**
	 * @return array
	 */
	private function range_args() {
		return array(
			'from' => array( 'type' => 'string', 'format' => 'date', 'description' => 'Site-local Y-m-d, inclusive. Default: 29 days before `to`.' ),
			'to'   => array( 'type' => 'string', 'format' => 'date', 'description' => 'Site-local Y-m-d, inclusive. Default: today.' ),
		);
	}

	/**
	 * @internal
	 */
	public function _register_routes() {
		register_rest_route( self::NAMESPACE_, '/links', array(
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, '_list' ),
				'permission_callback' => $this->guard( 'read' ),
				'args'                => array(
					'search'         => array( 'type' => 'string', 'default' => '' ),
					'view'           => array( 'type' => 'string', 'enum' => array( 'all', 'enabled', 'disabled', 'trash', 'any' ), 'default' => 'all' ),
					'category'       => array( 'type' => 'integer', 'default' => 0 ),
					'modified_since' => array( 'type' => 'string', 'format' => 'date-time', 'description' => 'Only links changed at or after this time (ISO 8601).' ),
					'orderby'        => array( 'type' => 'string', 'enum' => FW_Short_Links_Repository::SORTABLE, 'default' => 'created_at' ),
					'order'          => array( 'type' => 'string', 'enum' => array( 'asc', 'desc' ), 'default' => 'desc' ),
					'per_page'       => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 20 ),
					'page'           => array( 'type' => 'integer', 'minimum' => 1, 'default' => 1 ),
				),
			),
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, '_create' ),
				'permission_callback' => $this->guard( 'write' ),
				'args'                => array_merge( $this->link_args(), array(
					'target_url' => array( 'type' => 'string', 'required' => true ),
				) ),
			),
		) );

		register_rest_route( self::NAMESPACE_, '/links/batch', array(
			'methods'             => 'POST',
			'callback'            => array( $this, '_batch' ),
			'permission_callback' => $this->guard( 'write' ),
			'args'                => array(
				'operations' => array(
					'type'        => 'array',
					'required'    => true,
					'maxItems'    => self::BATCH_LIMIT,
					'description' => 'Up to 100 of { op: create|update|delete|restore, id?, version?, force?, data? }.',
					'items'       => array( 'type' => 'object' ),
				),
			),
		) );

		register_rest_route( self::NAMESPACE_, '/links/(?P<id>\d+)', array(
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, '_get' ),
				'permission_callback' => $this->guard( 'read', true ),
			),
			array(
				'methods'             => 'PUT, PATCH',
				'callback'            => array( $this, '_update' ),
				'permission_callback' => $this->guard( 'write', true ),
				'args'                => array_merge( $this->link_args(), array(
					'version' => array( 'type' => 'integer', 'minimum' => 0 ),
				) ),
			),
			array(
				'methods'             => 'DELETE',
				'callback'            => array( $this, '_delete' ),
				'permission_callback' => $this->guard( 'write', true ),
				'args'                => array(
					'force' => array( 'type' => 'boolean', 'default' => false ),
				),
			),
		) );

		register_rest_route( self::NAMESPACE_, '/links/(?P<id>\d+)/restore', array(
			'methods'             => 'POST',
			'callback'            => array( $this, '_restore' ),
			'permission_callback' => $this->guard( 'write', true ),
		) );

		register_rest_route( self::NAMESPACE_, '/links/(?P<id>\d+)/stats', array(
			'methods'             => 'GET',
			'callback'            => array( $this, '_link_stats' ),
			'permission_callback' => $this->guard( 'stats', true ),
			'args'                => array_merge( $this->range_args(), array(
				'breakdown' => array( 'type' => 'array', 'items' => array( 'type' => 'string', 'enum' => FW_Short_Links_Stats::BREAKDOWNS ), 'default' => array() ),
			) ),
		) );

		register_rest_route( self::NAMESPACE_, '/stats', array(
			'methods'             => 'GET',
			'callback'            => array( $this, '_stats' ),
			'permission_callback' => $this->guard( 'stats' ),
			'args'                => array_merge( $this->range_args(), array(
				'top'       => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 10 ),
				'breakdown' => array( 'type' => 'array', 'items' => array( 'type' => 'string', 'enum' => FW_Short_Links_Stats::BREAKDOWNS ), 'default' => array() ),
			) ),
		) );

		register_rest_route( self::NAMESPACE_, '/categories', array(
			'methods'             => 'GET',
			'callback'            => array( $this, '_categories' ),
			'permission_callback' => $this->guard( 'read' ),
		) );

		register_rest_route( self::NAMESPACE_, '/slug', array(
			'methods'             => 'GET',
			'callback'            => array( $this, '_slug' ),
			'permission_callback' => $this->guard( 'read' ),
			'args'                => array(
				'check' => array( 'type' => 'string', 'default' => '' ),
			),
		) );

		register_rest_route( self::NAMESPACE_, '/openapi.json', array(
			'methods'             => 'GET',
			'callback'            => array( $this, '_openapi' ),
			'permission_callback' => '__return_true', // a description of the API is not a secret
		) );
	}

	/* ---------------------------------------------------------------------- *
	 * Permissions
	 * ---------------------------------------------------------------------- */

	/**
	 * A permission callback: the user may use links at all, the API key (if one
	 * was used) carries $scope, and — for a single-link route — this user may
	 * touch this link. Stats on all links needs manage_short_links.
	 *
	 * @param string $scope
	 * @param bool   $single
	 *
	 * @return Closure
	 */
	private function guard( $scope, $single = false ) {
		return function ( WP_REST_Request $request ) use ( $scope, $single ) {
			if ( ! FW_Short_Links_Service::can_edit() ) {
				return false;
			}

			if ( ! FW_Short_Links_Keys::allows( $scope ) ) {
				/* translators: %s: read / write / stats */
				return new WP_Error( 'fw_short_links_scope', sprintf( __( 'This API key does not have the "%s" permission.', 'fw' ), $scope ), array( 'status' => 403 ) );
			}

			if ( 'stats' === $scope && ! $single && ! FW_Short_Links_Service::can_manage() ) {
				return false;
			}

			if ( ! $single ) {
				return true;
			}

			$link = FW_Short_Links_Repository::find( (int) $request['id'] );

			if ( ! $link ) {
				return new WP_Error( 'not_found', __( 'Link not found.', 'fw' ), array( 'status' => 404 ) );
			}

			return FW_Short_Links_Service::can_edit_link( $link );
		};
	}

	/* ---------------------------------------------------------------------- *
	 * Links
	 * ---------------------------------------------------------------------- */

	/**
	 * @param object|WP_Error $result
	 * @param int             $status
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	private function respond( $result, $status = 200 ) {
		if ( is_wp_error( $result ) ) {
			$data = $result->get_error_data();

			if ( ! is_array( $data ) || empty( $data['status'] ) ) {
				$result->add_data( array_merge( is_array( $data ) ? $data : array(), array( 'status' => 400 ) ) );
			}

			return $result;
		}

		$response = new WP_REST_Response( FW_Short_Links_Service::to_array( $result ), $status );
		$response->header( 'ETag', '"' . $result->version . '"' );

		return $response;
	}

	/**
	 * @internal
	 */
	public function _list( WP_REST_Request $request ) {
		$since = '';

		if ( $request['modified_since'] ) {
			$ts = strtotime( (string) $request['modified_since'] );

			if ( false === $ts ) {
				return new WP_Error( 'rest_invalid_param', __( 'modified_since must be an ISO 8601 date-time.', 'fw' ), array( 'status' => 400 ) );
			}

			$since = gmdate( 'Y-m-d H:i:s', $ts );
		}

		$result = FW_Short_Links_Repository::query( array(
			'view'           => $request['view'],
			'search'         => $request['search'],
			'category'       => $request['category'],
			'modified_since' => $since,
			'orderby'        => $request['orderby'],
			'order'          => $request['order'],
			'per_page'       => $request['per_page'],
			'page'           => $request['page'],
			'owner_id'       => FW_Short_Links_Service::can_manage() ? 0 : get_current_user_id(),
		) );

		$response = new WP_REST_Response( array_map( array( 'FW_Short_Links_Service', 'to_array' ), $result['rows'] ) );
		$response->header( 'X-WP-Total', (string) $result['total'] );
		$response->header( 'X-WP-TotalPages', (string) (int) ceil( $result['total'] / $request['per_page'] ) );

		return $response;
	}

	/**
	 * @internal
	 */
	public function _get( WP_REST_Request $request ) {
		return $this->respond( FW_Short_Links_Repository::find( (int) $request['id'] ) );
	}

	/**
	 * Only fields the client actually sent are passed on, so a PATCH of one
	 * field leaves the rest alone.
	 *
	 * @param array $sent raw params
	 * @param array $clean schema-sanitised params
	 *
	 * @return array
	 */
	private function input( array $sent, $clean ) {
		$input = array();

		foreach ( array_keys( $this->link_args() ) as $key ) {
			if ( array_key_exists( $key, $sent ) ) {
				$input[ $key ] = $clean[ $key ];
			}
		}

		return $input;
	}

	/**
	 * @param WP_REST_Request $request
	 *
	 * @return array
	 */
	private function sent( WP_REST_Request $request ) {
		return array_merge( (array) $request->get_query_params(), (array) $request->get_body_params(), (array) $request->get_json_params() );
	}

	/**
	 * @internal
	 */
	public function _create( WP_REST_Request $request ) {
		return $this->respond( FW_Short_Links_Service::create( $this->input( $this->sent( $request ), $request ) ), 201 );
	}

	/**
	 * @internal
	 */
	public function _update( WP_REST_Request $request ) {
		$version = (int) $request['version'];

		if ( ! $version && $request->get_header( 'if_match' ) ) {
			$version = (int) trim( (string) $request->get_header( 'if_match' ), '"W/ ' );
		}

		return $this->respond( FW_Short_Links_Service::update( (int) $request['id'], $this->input( $this->sent( $request ), $request ), $version ) );
	}

	/**
	 * @internal
	 */
	public function _delete( WP_REST_Request $request ) {
		$id   = (int) $request['id'];
		$link = FW_Short_Links_Repository::find( $id );

		if ( $request['force'] ) {
			FW_Short_Links_Service::delete( array( $id ) );

			return new WP_REST_Response( array( 'deleted' => true, 'previous' => FW_Short_Links_Service::to_array( $link ) ) );
		}

		FW_Short_Links_Service::trash( array( $id ) );

		return $this->respond( FW_Short_Links_Repository::find( $id ) );
	}

	/**
	 * @internal
	 */
	public function _restore( WP_REST_Request $request ) {
		$id = (int) $request['id'];

		FW_Short_Links_Service::restore( array( $id ) );

		return $this->respond( FW_Short_Links_Repository::find( $id ) );
	}

	/**
	 * Many operations in one request; each succeeds or fails on its own and the
	 * response lists a result per operation, in order. Not a transaction.
	 *
	 * @internal
	 */
	public function _batch( WP_REST_Request $request ) {
		$results = array();
		$schema  = $this->link_args();

		foreach ( (array) $request['operations'] as $i => $op ) {
			$op   = (array) $op;
			$kind = isset( $op['op'] ) ? (string) $op['op'] : '';
			$id   = isset( $op['id'] ) ? (int) $op['id'] : 0;
			$data = isset( $op['data'] ) && is_array( $op['data'] ) ? array_intersect_key( $op['data'], $schema ) : array();
			$link = $id ? FW_Short_Links_Repository::find( $id ) : null;

			if ( 'create' !== $kind && ( ! $link || ! FW_Short_Links_Service::can_edit_link( $link ) ) ) {
				$results[] = array( 'index' => $i, 'ok' => false, 'error' => array( 'code' => 'not_found', 'message' => __( 'Link not found.', 'fw' ) ) );
				continue;
			}

			switch ( $kind ) {
				case 'create':
					$r = FW_Short_Links_Service::create( $data );
					break;
				case 'update':
					$r = FW_Short_Links_Service::update( $id, $data, isset( $op['version'] ) ? (int) $op['version'] : 0 );
					break;
				case 'delete':
					if ( ! empty( $op['force'] ) ) {
						FW_Short_Links_Service::delete( array( $id ) );
						$results[] = array( 'index' => $i, 'ok' => true, 'deleted' => true, 'id' => $id );
						continue 2;
					}
					FW_Short_Links_Service::trash( array( $id ) );
					$r = FW_Short_Links_Repository::find( $id );
					break;
				case 'restore':
					FW_Short_Links_Service::restore( array( $id ) );
					$r = FW_Short_Links_Repository::find( $id );
					break;
				default:
					$r = new WP_Error( 'bad_op', __( 'op must be create, update, delete or restore.', 'fw' ) );
			}

			$results[] = is_wp_error( $r )
				? array( 'index' => $i, 'ok' => false, 'error' => array( 'code' => $r->get_error_code(), 'message' => $r->get_error_message() ) )
				: array( 'index' => $i, 'ok' => true, 'link' => FW_Short_Links_Service::to_array( $r ) );
		}

		return new WP_REST_Response( array(
			'results'   => $results,
			'succeeded' => count( wp_list_filter( $results, array( 'ok' => true ) ) ),
			'failed'    => count( wp_list_filter( $results, array( 'ok' => false ) ) ),
		) );
	}

	/* ---------------------------------------------------------------------- *
	 * Stats, categories, slugs
	 * ---------------------------------------------------------------------- */

	/**
	 * @internal
	 */
	public function _link_stats( WP_REST_Request $request ) {
		$id   = (int) $request['id'];
		$data = FW_Short_Links_Stats::series( array( $id ), (string) $request['from'], (string) $request['to'] );
		$link = FW_Short_Links_Repository::find( $id );

		$data['link']     = array( 'id' => $id, 'slug' => $link->slug, 'lifetime_clicks' => $link->clicks, 'lifetime_uniques' => $link->uniques );
		$data['breakdown'] = array();

		foreach ( (array) $request['breakdown'] as $col ) {
			$data['breakdown'][ $col ] = FW_Short_Links_Stats::breakdown( $col, array( $id ), $data['from'], $data['to'] );
		}

		return new WP_REST_Response( $data );
	}

	/**
	 * @internal
	 */
	public function _stats( WP_REST_Request $request ) {
		$data = FW_Short_Links_Stats::series( null, (string) $request['from'], (string) $request['to'] );

		$data['top_links'] = FW_Short_Links_Stats::top_links( $data['from'], $data['to'], (int) $request['top'] );
		$data['breakdown'] = array();

		foreach ( (array) $request['breakdown'] as $col ) {
			$data['breakdown'][ $col ] = FW_Short_Links_Stats::breakdown( $col, null, $data['from'], $data['to'] );
		}

		return new WP_REST_Response( $data );
	}

	/**
	 * @internal
	 */
	public function _categories() {
		return new WP_REST_Response( array_map( function ( $t ) {
			return array( 'id' => (int) $t->term_id, 'name' => $t->name, 'slug' => $t->slug, 'parent' => (int) $t->parent, 'count' => (int) $t->count );
		}, FW_Short_Links_Categories::all() ) );
	}

	/**
	 * GET /slug → a fresh free slug; GET /slug?check=x → whether x is usable.
	 *
	 * @internal
	 */
	public function _slug( WP_REST_Request $request ) {
		$check = FW_Short_Links_Service::sanitize_slug( (string) $request['check'] );

		if ( '' === $check ) {
			$slug = FW_Short_Links_Service::generate_slug();

			return new WP_REST_Response( array( 'slug' => $slug, 'short_url' => FW_Short_Links_Service::short_url( $slug ), 'available' => true ) );
		}

		$problem = '';
		$taken   = FW_Short_Links_Repository::find_by_slug( $check );

		if ( $taken ) {
			$problem = __( 'Already used by another link.', 'fw' );
		} elseif ( FW_Short_Links_Service::is_reserved( $check ) ) {
			$problem = __( 'Reserved for WordPress.', 'fw' );
		} else {
			$conflict = FW_Short_Links_Service::site_conflict( $check );
			$problem  = '' !== $conflict ? sprintf( __( 'Already %s.', 'fw' ), $conflict ) : '';
		}

		return new WP_REST_Response( array(
			'slug'      => $check,
			'short_url' => FW_Short_Links_Service::short_url( $check ),
			'available' => '' === $problem,
			'problem'   => $problem,
			'link_id'   => $taken ? $taken->id : 0,
		) );
	}

	/* ---------------------------------------------------------------------- *
	 * OpenAPI
	 * ---------------------------------------------------------------------- */

	/**
	 * An OpenAPI 3.1 document generated from the registered routes, so it can
	 * never drift from what the API actually accepts.
	 *
	 * @internal
	 */
	public function _openapi() {
		$server = rest_get_server();
		$paths  = array();
		$link   = array(
			'type'       => 'object',
			'properties' => array(
				'id'         => array( 'type' => 'integer' ),
				'short_url'  => array( 'type' => 'string', 'format' => 'uri' ),
				'version'    => array( 'type' => 'integer', 'description' => 'Bumped on every change; send it back on PATCH.' ),
				'clicks'     => array( 'type' => 'integer' ),
				'uniques'    => array( 'type' => 'integer' ),
				'trashed'    => array( 'type' => 'boolean' ),
				'created_at' => array( 'type' => 'string', 'format' => 'date-time' ),
				'updated_at' => array( 'type' => 'string', 'format' => 'date-time' ),
			),
		);

		foreach ( $this->link_args() as $k => $a ) {
			if ( 'fetch_title' !== $k ) {
				$link['properties'][ $k ] = array_intersect_key( $a, array_flip( array( 'type', 'enum', 'items', 'description' ) ) );
			}
		}

		foreach ( $server->get_routes( self::NAMESPACE_ ) as $route => $handlers ) {
			if ( '/' . self::NAMESPACE_ === $route ) {
				continue;
			}

			$path = preg_replace( '#\(\?P<(\w+)>[^)]+\)#', '{$1}', substr( $route, strlen( '/' . self::NAMESPACE_ ) ) );

			foreach ( $handlers as $h ) {
				foreach ( array_keys( array_filter( (array) $h['methods'] ) ) as $method ) {
					$m      = strtolower( $method );
					$params = array();
					$body   = array();

					if ( preg_match_all( '/\{(\w+)\}/', $path, $mm ) ) {
						foreach ( $mm[1] as $p ) {
							$params[] = array( 'name' => $p, 'in' => 'path', 'required' => true, 'schema' => array( 'type' => 'integer' ) );
						}
					}

					foreach ( (array) ( $h['args'] ?? array() ) as $name => $arg ) {
						$schema = array_intersect_key( $arg, array_flip( array( 'type', 'enum', 'items', 'format', 'minimum', 'maximum', 'maxItems', 'default' ) ) );

						if ( in_array( $m, array( 'get', 'delete' ), true ) ) {
							$params[] = array_filter( array(
								'name'        => $name,
								'in'          => 'query',
								'required'    => ! empty( $arg['required'] ),
								'description' => $arg['description'] ?? null,
								'schema'      => $schema,
							), function ( $v ) {
								return null !== $v;
							} );
						} else {
							$body[ $name ] = $schema + ( isset( $arg['description'] ) ? array( 'description' => $arg['description'] ) : array() );
						}
					}

					$op = array(
						'operationId' => $m . preg_replace( '/[^A-Za-z]/', '', ucwords( str_replace( array( '/', '{', '}', '.', '-' ), ' ', $path ) ) ),
						'parameters'  => $params,
						'responses'   => array(
							'200' => array( 'description' => 'OK' ),
							'400' => array( 'description' => 'Invalid input' ),
							'401' => array( 'description' => 'Not authenticated' ),
							'403' => array( 'description' => 'Not allowed, or the key lacks the scope' ),
							'409' => array( 'description' => 'Version conflict' ),
							'429' => array( 'description' => 'Rate limited' ),
						),
					);

					if ( $body ) {
						$required = array_keys( array_filter( (array) $h['args'], function ( $a ) {
							return ! empty( $a['required'] );
						} ) );

						$op['requestBody'] = array( 'content' => array( 'application/json' => array( 'schema' => array_filter( array(
							'type'       => 'object',
							'properties' => $body,
							'required'   => $required ? $required : null,
						) ) ) ) );
					}

					if ( '/openapi.json' === $path ) {
						$op['security'] = array();
					}

					$paths[ $path ][ $m ] = $op;
				}
			}
		}

		return new WP_REST_Response( array(
			'openapi'    => '3.1.0',
			'info'       => array(
				'title'       => 'Short Links API',
				'version'     => fw_ext( 'short-links' ) ? fw_ext( 'short-links' )->manifest->get_version() : '1',
				'description' => 'Create, change and measure short links. Authenticate with an API key (Bearer) or a WordPress Application Password (Basic).',
			),
			'servers'    => array( array( 'url' => rest_url( self::NAMESPACE_ ) ) ),
			'security'   => array( array( 'apiKey' => array() ), array( 'appPassword' => array() ) ),
			'components' => array(
				'securitySchemes' => array(
					'apiKey'      => array( 'type' => 'http', 'scheme' => 'bearer', 'description' => 'An API key from Short Links → API & Webhooks (fwsl_…).' ),
					'appPassword' => array( 'type' => 'http', 'scheme' => 'basic', 'description' => 'username + WordPress Application Password.' ),
				),
				'schemas'         => array( 'Link' => $link ),
			),
			'paths'      => $paths,
		) );
	}
}
