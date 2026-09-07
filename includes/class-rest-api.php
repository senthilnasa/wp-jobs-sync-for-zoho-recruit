<?php
/**
 * REST API endpoints.
 *
 * @package JobsSyncForZohoRecruit
 */

namespace JobsSyncForZohoRecruit;

defined( 'ABSPATH' ) || exit;

/**
 * Public job endpoints plus the protected sync control endpoints.
 *
 * Public responses are built from an administrator-controlled allow-list, so a
 * field never becomes public by accident.
 */
class REST_API {

	/**
	 * REST namespace.
	 */
	const NAMESPACE_V1 = 'jobs-sync-zoho-recruit/v1';

	/**
	 * Option holding the cache version, bumped to invalidate everything.
	 */
	const CACHE_VERSION_OPTION = 'jszr_cache_version';

	/**
	 * Sync engine.
	 *
	 * @var Sync
	 */
	private $sync;

	/**
	 * Run storage.
	 *
	 * @var Sync_Queue
	 */
	private $queue;

	/**
	 * Constructor.
	 *
	 * @param Sync       $sync  Sync engine.
	 * @param Sync_Queue $queue Run storage.
	 */
	public function __construct( Sync $sync, Sync_Queue $queue ) {
		$this->sync  = $sync;
		$this->queue = $queue;

		add_action( 'rest_api_init', array( $this, 'register_routes' ) );

		// Any change to a job, from any source, drops the cached listings.
		add_action( 'save_post_' . Post_Type::POST_TYPE, array( __CLASS__, 'flush_cache_for_post' ), 10, 2 );
		add_action( 'deleted_post', array( __CLASS__, 'flush_cache_for_post' ), 10, 2 );
		add_action( 'trashed_post', array( __CLASS__, 'flush_cache_for_post' ) );
		add_action( 'untrashed_post', array( __CLASS__, 'flush_cache_for_post' ) );
		add_action( 'update_option_' . Settings::OPTION, array( __CLASS__, 'flush_cache' ) );

		// Widen keyword search to cover the job code.
		add_filter( 'posts_join', array( __CLASS__, 'search_join' ), 10, 2 );
		add_filter( 'posts_search', array( __CLASS__, 'search_where' ), 10, 2 );
		add_filter( 'posts_groupby', array( __CLASS__, 'search_groupby' ), 10, 2 );
	}

	/**
	 * Register every route.
	 *
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			self::NAMESPACE_V1,
			'/jobs',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_jobs' ),
					'permission_callback' => array( $this, 'public_permission' ),
					'args'                => $this->collection_args(),
				),
				'schema' => array( $this, 'get_public_schema' ),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/jobs/filters',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_filters' ),
					'permission_callback' => array( $this, 'public_permission' ),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/jobs/(?P<id>[\d]+)',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_job' ),
					'permission_callback' => array( $this, 'public_permission' ),
					'args'                => self::validated(
						array(
							'id' => array(
								'type'        => 'integer',
								'required'    => true,
								'description' => __( 'WordPress post ID of the job.', 'jobs-sync-for-zoho-recruit' ),
							),
						)
					),
				),
				'schema' => array( $this, 'get_public_schema' ),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/sync',
			array(
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'start_sync' ),
					'permission_callback' => array( $this, 'manage_permission' ),
					'args'                => self::validated(
						array(
							'type'    => array(
								'type'    => 'string',
								'enum'    => array( 'full', 'incremental' ),
								'default' => 'incremental',
							),
							'dry_run' => array(
								'type'    => 'boolean',
								'default' => false,
							),
						)
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/sync/status',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_sync_status' ),
					'permission_callback' => array( $this, 'manage_permission' ),
					'args'                => self::validated(
						array(
							'run_id' => array(
								'type'     => 'integer',
								'required' => false,
							),
						)
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/sync/cancel',
			array(
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'cancel_sync' ),
					'permission_callback' => array( $this, 'manage_permission' ),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/jobs/(?P<id>[\d]+)/resync',
			array(
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'resync_job' ),
					'permission_callback' => array( $this, 'manage_permission' ),
					'args'                => self::validated(
						array(
							'id' => array(
								'type'     => 'integer',
								'required' => true,
							),
						)
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/zoho-fields',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_zoho_fields' ),
					'permission_callback' => array( $this, 'manage_permission' ),
					'args'                => self::validated(
						array(
							'refresh' => array(
								'type'    => 'boolean',
								'default' => false,
							),
						)
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/test-connection',
			array(
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'test_connection' ),
					'permission_callback' => array( $this, 'manage_permission' ),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/webhook',
			array(
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( plugin()->webhook(), 'handle' ),
					'permission_callback' => '__return_true',
				),
			)
		);
	}

	// ----------------------------------------------------------------------
	// Permissions
	// ----------------------------------------------------------------------

	/**
	 * Permission callback for the public endpoints.
	 *
	 * @return true|\WP_Error
	 */
	public function public_permission() {
		if ( ! Settings::get( 'rest_enabled', true ) ) {
			return new \WP_Error(
				'jszr_rest_disabled',
				__( 'The jobs API is disabled on this site.', 'jobs-sync-for-zoho-recruit' ),
				array( 'status' => 404 )
			);
		}

		return true;
	}

	/**
	 * Permission callback for administrative endpoints.
	 *
	 * @return true|\WP_Error
	 */
	public function manage_permission() {
		if ( ! current_user_can( Plugin::capability() ) ) {
			return new \WP_Error(
				'jszr_forbidden',
				__( 'You do not have permission to manage Zoho Recruit synchronization.', 'jobs-sync-for-zoho-recruit' ),
				array( 'status' => rest_authorization_required_code() )
			);
		}

		return true;
	}

	// ----------------------------------------------------------------------
	// Public endpoints
	// ----------------------------------------------------------------------

	/**
	 * Argument schema for the collection endpoint.
	 *
	 * @return array
	 */
	public function collection_args() {
		$max = (int) Settings::get( 'rest_max_per_page', 100 );

		$args = array(
			'page'     => array(
				'type'              => 'integer',
				'default'           => 1,
				'minimum'           => 1,
				'sanitize_callback' => 'absint',
			),
			'per_page' => array(
				'type'              => 'integer',
				'default'           => (int) Settings::get( 'rest_per_page', 20 ),
				'minimum'           => 1,
				'maximum'           => $max,
				'sanitize_callback' => 'absint',
			),
			'search'   => array(
				'type'              => 'string',
				'default'           => '',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'orderby'  => array(
				'type'    => 'string',
				'enum'    => array( 'date', 'title', 'closing_date', 'posted_date' ),
				'default' => 'date',
			),
			'order'    => array(
				'type'    => 'string',
				'enum'    => array( 'asc', 'desc', 'ASC', 'DESC' ),
				'default' => 'desc',
			),
			'status'   => array(
				'type'    => 'string',
				'enum'    => array( 'active', 'inactive', 'expired', 'closed', 'any' ),
				'default' => 'active',
			),
		);

		foreach ( self::filter_map() as $param => $taxonomy ) {
			$args[ $param ] = array(
				'type'              => 'string',
				'default'           => '',
				'sanitize_callback' => 'sanitize_text_field',
				'description'       => sprintf(
					/* translators: %s: taxonomy name. */
					__( 'Comma separated term slugs or names for %s.', 'jobs-sync-for-zoho-recruit' ),
					$taxonomy
				),
			);
		}

		return self::validated( $args );
	}

	/**
	 * Attach the schema validator to every argument.
	 *
	 * WordPress only runs an argument's schema — its type, enum, minimum and
	 * maximum — when the argument declares a validate_callback. Without this a
	 * route can carry a perfectly good schema that is never enforced, and
	 * per_page=5000 sails through with a 200.
	 *
	 * @param array $args Argument definitions.
	 * @return array
	 */
	private static function validated( array $args ) {
		foreach ( $args as $name => $definition ) {
			if ( ! is_array( $definition ) ) {
				continue;
			}

			if ( ! isset( $definition['validate_callback'] ) ) {
				$definition['validate_callback'] = 'rest_validate_request_arg';
			}

			if ( ! isset( $definition['sanitize_callback'] ) ) {
				$definition['sanitize_callback'] = 'rest_sanitize_request_arg';
			}

			$args[ $name ] = $definition;
		}

		return $args;
	}

	/**
	 * Map of query parameter => taxonomy.
	 *
	 * @return array<string,string>
	 */
	public static function filter_map() {
		$map = array(
			'department'      => Post_Type::TAX_DEPARTMENT,
			'location'        => Post_Type::TAX_LOCATION,
			'employment_type' => Post_Type::TAX_EMPLOYMENT_TYPE,
			'category'        => Post_Type::TAX_CATEGORY,
			'experience'      => Post_Type::TAX_EXPERIENCE,
		);

		/**
		 * Filter the query parameter to taxonomy map used by listings and REST.
		 *
		 * @param array $map Parameter => taxonomy.
		 */
		return (array) apply_filters( 'jszr_filter_map', $map );
	}

	/**
	 * GET /jobs
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_jobs( $request ) {
		$args = self::build_query_args(
			array(
				'page'            => (int) $request['page'],
				'per_page'        => (int) $request['per_page'],
				'search'          => (string) $request['search'],
				'orderby'         => (string) $request['orderby'],
				'order'           => (string) $request['order'],
				'status'          => (string) $request['status'],
				'department'      => (string) $request['department'],
				'location'        => (string) $request['location'],
				'employment_type' => (string) $request['employment_type'],
				'category'        => (string) $request['category'],
				'experience'      => (string) $request['experience'],
			)
		);

		/**
		 * Filter the WP_Query arguments used by the REST collection endpoint.
		 *
		 * @param array            $args    Query arguments.
		 * @param \WP_REST_Request $request Request.
		 */
		$args = (array) apply_filters( 'jszr_rest_query_args', $args, $request );

		$cache_key = self::cache_key( 'jobs', $args );
		$cached    = self::cache_get( $cache_key );

		if ( false !== $cached ) {
			return $this->respond( $cached['body'], $cached['total'], $args );
		}

		$query = new \WP_Query( $args );
		$items = array();

		foreach ( $query->posts as $post ) {
			$items[] = self::prepare_job( $post );
		}

		$payload = array(
			'body'  => $items,
			'total' => (int) $query->found_posts,
		);

		self::cache_set( $cache_key, $payload );

		return $this->respond( $items, (int) $query->found_posts, $args );
	}

	/**
	 * GET /jobs/{id}
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_job( $request ) {
		$post = get_post( (int) $request['id'] );

		if ( ! $post instanceof \WP_Post || Post_Type::POST_TYPE !== $post->post_type ) {
			return $this->not_found();
		}

		$viewable = in_array( $post->post_status, array( 'publish' ), true )
			|| ( 'private' === $post->post_status && current_user_can( 'read_post', $post->ID ) );

		if ( ! $viewable ) {
			return $this->not_found();
		}

		if ( ! Settings::get( 'rest_allow_inactive', false ) && ! Job::is_active( $post->ID ) ) {
			return $this->not_found();
		}

		$response = rest_ensure_response(
			array(
				'success' => true,
				'data'    => self::prepare_job( $post ),
			)
		);

		$this->add_cache_headers( $response );

		return $response;
	}

	/**
	 * GET /jobs/filters
	 *
	 * @return \WP_REST_Response
	 */
	public function get_filters() {
		$cache_key = self::cache_key( 'filters', array() );
		$cached    = self::cache_get( $cache_key );

		if ( false !== $cached ) {
			$response = rest_ensure_response(
				array(
					'success' => true,
					'data'    => $cached,
				)
			);

			$this->add_cache_headers( $response );

			return $response;
		}

		$data = array();

		foreach ( self::filter_map() as $param => $taxonomy ) {
			$data[ $param ] = self::active_terms( $taxonomy );
		}

		self::cache_set( $cache_key, $data );

		$response = rest_ensure_response(
			array(
				'success' => true,
				'data'    => $data,
			)
		);

		$this->add_cache_headers( $response );

		return $response;
	}

	// ----------------------------------------------------------------------
	// Protected endpoints
	// ----------------------------------------------------------------------

	/**
	 * POST /sync
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function start_sync( $request ) {
		$run_id = $this->sync->start(
			(string) $request['type'],
			array(
				'dry_run' => (bool) $request['dry_run'],
				'trigger' => 'rest',
			)
		);

		if ( is_wp_error( $run_id ) ) {
			$run_id->add_data( array( 'status' => 409 ) );

			return $run_id;
		}

		return rest_ensure_response(
			array(
				'success' => true,
				'data'    => Sync_Queue::stats( Sync_Queue::get( (int) $run_id ) ),
			)
		);
	}

	/**
	 * GET /sync/status
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function get_sync_status( $request ) {
		$run_id = isset( $request['run_id'] ) ? (int) $request['run_id'] : 0;

		$run = $run_id > 0 ? Sync_Queue::get( $run_id ) : Sync_Queue::get_active();

		if ( ! $run ) {
			$recent = Sync_Queue::get_recent( 1 );
			$run    = ! empty( $recent ) ? $recent[0] : null;
		}

		return rest_ensure_response(
			array(
				'success' => true,
				'data'    => array(
					'run'    => $run ? Sync_Queue::stats( $run ) : null,
					'state'  => $this->sync->get_state(),
					'counts' => Job::counts(),
				),
			)
		);
	}

	/**
	 * POST /sync/cancel
	 *
	 * @return \WP_REST_Response
	 */
	public function cancel_sync() {
		$cancelled = Sync_Queue::cancel_active();

		return rest_ensure_response(
			array(
				'success' => true,
				'data'    => array( 'cancelled' => $cancelled ),
			)
		);
	}

	/**
	 * POST /jobs/{id}/resync
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function resync_job( $request ) {
		$post_id = (int) $request['id'];
		$zoho_id = (string) get_post_meta( $post_id, Job::META_ZOHO_ID, true );

		if ( '' === $zoho_id ) {
			return new \WP_Error(
				'jszr_not_synced',
				__( 'This job was not imported from Zoho Recruit.', 'jobs-sync-for-zoho-recruit' ),
				array( 'status' => 400 )
			);
		}

		$result = $this->sync->sync_single( $zoho_id );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response(
			array(
				'success' => true,
				'data'    => $result,
			)
		);
	}

	/**
	 * GET /zoho-fields
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function get_zoho_fields( $request ) {
		$fields = plugin()->metadata()->get_fields( (bool) $request['refresh'] );

		return rest_ensure_response(
			array(
				'success' => true,
				'data'    => array_values( $fields ),
			)
		);
	}

	/**
	 * POST /test-connection
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function test_connection() {
		$result = plugin()->api()->test_connection();

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response(
			array(
				'success' => true,
				'data'    => $result,
			)
		);
	}

	// ----------------------------------------------------------------------
	// Query building
	// ----------------------------------------------------------------------

	/**
	 * Build WP_Query arguments from normalised listing parameters.
	 *
	 * Shared by the REST endpoint, the shortcode and the block so all three
	 * behave identically.
	 *
	 * @param array $params Listing parameters.
	 * @return array
	 */
	public static function build_query_args( array $params ) {
		$params = wp_parse_args(
			$params,
			array(
				'page'     => 1,
				'per_page' => (int) Settings::get( 'rest_per_page', 20 ),
				'search'   => '',
				'orderby'  => 'date',
				'order'    => 'desc',
				'status'   => 'active',
			)
		);

		$max      = (int) Settings::get( 'rest_max_per_page', 100 );
		$per_page = max( 1, min( $max, (int) $params['per_page'] ) );

		$args = array(
			'post_type'           => Post_Type::POST_TYPE,
			'post_status'         => 'publish',
			'posts_per_page'      => $per_page,
			'paged'               => max( 1, (int) $params['page'] ),
			'ignore_sticky_posts' => true,
			'no_found_rows'       => false,
		);

		$order         = strtoupper( (string) $params['order'] );
		$args['order'] = in_array( $order, array( 'ASC', 'DESC' ), true ) ? $order : 'DESC';

		switch ( (string) $params['orderby'] ) {
			case 'title':
				$args['orderby'] = 'title';
				break;

			case 'closing_date':
				$args['orderby']   = 'meta_value';
				$args['meta_key']  = '_zoho_recruit_closing_date'; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Sorting requires the key.
				$args['meta_type'] = 'DATE';
				break;

			case 'posted_date':
				$args['orderby']   = 'meta_value';
				$args['meta_key']  = '_zoho_recruit_posted_date'; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Sorting requires the key.
				$args['meta_type'] = 'DATE';
				break;

			case 'date':
			default:
				$args['orderby'] = 'date';
				break;
		}

		$meta_query = array();
		$status     = (string) $params['status'];

		if ( 'any' === $status ) {
			if ( ! Settings::get( 'rest_allow_inactive', false ) && ! current_user_can( Plugin::capability() ) ) {
				$status = 'active';
			}
		}

		if ( 'active' === $status ) {
			$today = ( new \DateTimeImmutable( 'now', wp_timezone() ) )->format( 'Y-m-d' );

			$meta_query[] = array(
				'key'     => Job::META_STATUS,
				'value'   => Job::active_statuses(),
				'compare' => 'IN',
			);

			$meta_query[] = array(
				'relation' => 'OR',
				array(
					'key'     => '_zoho_recruit_closing_date',
					'compare' => 'NOT EXISTS',
				),
				array(
					'key'     => '_zoho_recruit_closing_date',
					'value'   => '',
					'compare' => '=',
				),
				array(
					'key'     => '_zoho_recruit_closing_date',
					'value'   => $today,
					'compare' => '>=',
					'type'    => 'DATE',
				),
			);
		} elseif ( 'any' !== $status ) {
			$meta_query[] = array(
				'key'     => Job::META_STATUS,
				'value'   => sanitize_key( $status ),
				'compare' => '=',
			);
		}

		if ( ! empty( $meta_query ) ) {
			$meta_query['relation'] = 'AND';
			$args['meta_query']     = $meta_query; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Status filtering is the point of the endpoint.
		}

		$tax_query = array();

		foreach ( self::filter_map() as $param => $taxonomy ) {
			if ( empty( $params[ $param ] ) ) {
				continue;
			}

			$terms = array_filter( array_map( 'trim', explode( ',', (string) $params[ $param ] ) ), 'strlen' );

			if ( empty( $terms ) ) {
				continue;
			}

			$slugs = array();
			$names = array();

			foreach ( $terms as $term ) {
				$slugs[] = sanitize_title( $term );
				$names[] = sanitize_text_field( $term );
			}

			$resolved = get_terms(
				array(
					'taxonomy'   => $taxonomy,
					'hide_empty' => false,
					'slug'       => $slugs,
					'fields'     => 'ids',
				)
			);

			if ( is_wp_error( $resolved ) || empty( $resolved ) ) {
				$resolved = get_terms(
					array(
						'taxonomy'   => $taxonomy,
						'hide_empty' => false,
						'name'       => $names,
						'fields'     => 'ids',
					)
				);
			}

			if ( is_wp_error( $resolved ) || empty( $resolved ) ) {
				// An unmatched filter must return nothing rather than everything.
				$args['post__in'] = array( 0 );
				continue;
			}

			$tax_query[] = array(
				'taxonomy'         => $taxonomy,
				'field'            => 'term_id',
				'terms'            => array_map( 'intval', $resolved ),
				'include_children' => is_taxonomy_hierarchical( $taxonomy ),
			);
		}

		if ( ! empty( $tax_query ) ) {
			$tax_query['relation'] = 'AND';
			$args['tax_query']     = $tax_query; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- Filtering is the point of the endpoint.
		}

		$search = trim( (string) $params['search'] );

		if ( '' !== $search ) {
			$args['s']           = $search;
			$args['jszr_search'] = $search;
		}

		return $args;
	}

	/**
	 * Whether a query opted into the extended job search.
	 *
	 * @param \WP_Query $query Query object.
	 * @return string The search term, or an empty string.
	 */
	private static function search_term( $query ) {
		if ( ! $query instanceof \WP_Query ) {
			return '';
		}

		$term = $query->get( 'jszr_search' );

		return is_string( $term ) ? trim( $term ) : '';
	}

	/**
	 * Join the job code meta row for keyword search.
	 *
	 * @param string    $join  JOIN clause.
	 * @param \WP_Query $query Query object.
	 * @return string
	 */
	public static function search_join( $join, $query ) {
		global $wpdb;

		if ( '' === self::search_term( $query ) ) {
			return $join;
		}

		return $join . " LEFT JOIN {$wpdb->postmeta} AS jszr_code ON ( {$wpdb->posts}.ID = jszr_code.post_id AND jszr_code.meta_key = '_jszr_job_code' ) ";
	}

	/**
	 * Widen the core search clause with the job code.
	 *
	 * The core clause is reused verbatim rather than rebuilt, so title and
	 * content matching keeps working exactly as WordPress intends.
	 *
	 * @param string    $search Search clause, including its leading AND.
	 * @param \WP_Query $query  Query object.
	 * @return string
	 */
	public static function search_where( $search, $query ) {
		global $wpdb;

		$term = self::search_term( $query );

		if ( '' === $term || '' === trim( (string) $search ) ) {
			return $search;
		}

		$core = preg_replace( '/^\s*AND\s+/i', '', (string) $search );

		if ( ! is_string( $core ) || '' === trim( $core ) ) {
			return $search;
		}

		$like = '%' . $wpdb->esc_like( $term ) . '%';

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $core is core-generated SQL; the added condition is prepared.
		return ' AND ( ' . $core . ' OR ' . $wpdb->prepare( 'jszr_code.meta_value LIKE %s', $like ) . ' ) ';
	}

	/**
	 * Group by post ID so the meta join cannot duplicate rows.
	 *
	 * @param string    $groupby GROUP BY clause.
	 * @param \WP_Query $query   Query object.
	 * @return string
	 */
	public static function search_groupby( $groupby, $query ) {
		global $wpdb;

		if ( '' === self::search_term( $query ) ) {
			return $groupby;
		}

		return "{$wpdb->posts}.ID";
	}

	// ----------------------------------------------------------------------
	// Response shaping
	// ----------------------------------------------------------------------

	/**
	 * Build the collection response.
	 *
	 * @param array $items Prepared items.
	 * @param int   $total Total matching posts.
	 * @param array $args  Query arguments used.
	 * @return \WP_REST_Response
	 */
	private function respond( array $items, $total, array $args ) {
		$per_page = max( 1, (int) $args['posts_per_page'] );
		$page     = max( 1, (int) $args['paged'] );

		$response = rest_ensure_response(
			array(
				'success'    => true,
				'data'       => $items,
				'pagination' => array(
					'page'        => $page,
					'per_page'    => $per_page,
					'total'       => (int) $total,
					'total_pages' => (int) ceil( $total / $per_page ),
				),
			)
		);

		$response->header( 'X-WP-Total', (string) (int) $total );
		$response->header( 'X-WP-TotalPages', (string) (int) ceil( $total / $per_page ) );

		$this->add_cache_headers( $response );

		return $response;
	}

	/**
	 * Add cache headers to a public response.
	 *
	 * @param \WP_REST_Response $response Response.
	 * @return void
	 */
	private function add_cache_headers( $response ) {
		if ( is_user_logged_in() ) {
			return;
		}

		$ttl = (int) Settings::get( 'cache_ttl', 300 );

		if ( $ttl > 0 ) {
			$response->header( 'Cache-Control', 'public, max-age=' . $ttl );
		}
	}

	/**
	 * Standard 404 response.
	 *
	 * @return \WP_Error
	 */
	private function not_found() {
		return new \WP_Error(
			'jszr_job_not_found',
			__( 'Job not found.', 'jobs-sync-for-zoho-recruit' ),
			array( 'status' => 404 )
		);
	}

	/**
	 * Build the public representation of a job.
	 *
	 * @param \WP_Post $post Job post.
	 * @return array
	 */
	public static function prepare_job( $post ) {
		$allowed = (array) Settings::get( 'rest_fields', array() );
		$post_id = (int) $post->ID;
		$data    = array();

		$meta_map = Post_Type::rest_field_meta_map();

		foreach ( $allowed as $field ) {
			switch ( $field ) {
				case 'id':
					$data['id'] = $post_id;
					break;

				case 'title':
					$data['title'] = get_the_title( $post_id );
					break;

				case 'slug':
					$data['slug'] = $post->post_name;
					break;

				case 'link':
					$data['link'] = get_permalink( $post_id );
					break;

				case 'excerpt':
					$data['excerpt'] = wp_strip_all_tags( get_the_excerpt( $post_id ) );
					break;

				case 'content':
					$data['content'] = wp_kses_post( $post->post_content );
					break;

				case 'department':
				case 'location':
				case 'employment_type':
				case 'category':
				case 'experience':
					$map      = self::filter_map();
					$taxonomy = isset( $map[ $field ] ) ? $map[ $field ] : '';

					if ( '' === $taxonomy ) {
						break;
					}

					$terms = get_the_terms( $post_id, $taxonomy );

					$data[ $field ] = array();

					if ( is_array( $terms ) ) {
						foreach ( $terms as $term ) {
							$data[ $field ][] = array(
								'name' => $term->name,
								'slug' => $term->slug,
							);
						}
					}
					break;

				case 'apply_url':
					$data['apply_url'] = Job::get_apply_url( $post_id );
					break;

				case 'salary':
					$data['salary'] = (string) get_post_meta( $post_id, '_zoho_recruit_salary', true );
					break;

				default:
					if ( isset( $meta_map[ $field ] ) ) {
						$data[ $field ] = get_post_meta( $post_id, $meta_map[ $field ], true );
					}
					break;
			}
		}

		/**
		 * Filter the public representation of a job.
		 *
		 * @param array    $data Prepared data.
		 * @param \WP_Post $post Job post.
		 */
		return (array) apply_filters( 'jszr_rest_job_data', $data, $post );
	}

	/**
	 * Terms of a taxonomy that have at least one active job.
	 *
	 * @param string $taxonomy Taxonomy name.
	 * @return array<int,array{name:string,slug:string,count:int,parent:int}>
	 */
	public static function active_terms( $taxonomy ) {
		global $wpdb;

		if ( ! taxonomy_exists( $taxonomy ) ) {
			return array();
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Result cached by the caller.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT t.name, t.slug, tt.parent, COUNT(DISTINCT p.ID) AS total
				FROM {$wpdb->terms} t
				INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = t.term_id
				INNER JOIN {$wpdb->term_relationships} tr ON tr.term_taxonomy_id = tt.term_taxonomy_id
				INNER JOIN {$wpdb->posts} p ON p.ID = tr.object_id
				INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = %s
				WHERE tt.taxonomy = %s
					AND p.post_type = %s
					AND p.post_status = 'publish'
					AND pm.meta_value = 'active'
				GROUP BY t.term_id, t.name, t.slug, tt.parent
				ORDER BY t.name ASC",
				Job::META_STATUS,
				$taxonomy,
				Post_Type::POST_TYPE
			)
		);

		$terms = array();

		foreach ( (array) $rows as $row ) {
			$terms[] = array(
				'name'   => (string) $row->name,
				'slug'   => (string) $row->slug,
				'parent' => (int) $row->parent,
				'count'  => (int) $row->total,
			);
		}

		return $terms;
	}

	/**
	 * JSON schema for public job objects.
	 *
	 * @return array
	 */
	public function get_public_schema() {
		$properties = array();

		foreach ( (array) Settings::get( 'rest_fields', array() ) as $field ) {
			switch ( $field ) {
				case 'id':
				case 'positions':
					$properties[ $field ] = array(
						'type'    => 'integer',
						'context' => array( 'view' ),
					);
					break;

				case 'department':
				case 'location':
				case 'employment_type':
				case 'category':
				case 'experience':
					$properties[ $field ] = array(
						'type'    => 'array',
						'context' => array( 'view' ),
						'items'   => array(
							'type'       => 'object',
							'properties' => array(
								'name' => array( 'type' => 'string' ),
								'slug' => array( 'type' => 'string' ),
							),
						),
					);
					break;

				default:
					$properties[ $field ] = array(
						'type'    => 'string',
						'context' => array( 'view' ),
					);
					break;
			}
		}

		return array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'jszr_job',
			'type'       => 'object',
			'properties' => $properties,
		);
	}

	// ----------------------------------------------------------------------
	// Caching
	// ----------------------------------------------------------------------

	/**
	 * Build a cache key that includes the current cache version.
	 *
	 * @param string $scope Cache scope.
	 * @param array  $args  Arguments affecting the result.
	 * @return string
	 */
	public static function cache_key( $scope, array $args ) {
		$version = (int) get_option( self::CACHE_VERSION_OPTION, 1 );

		return 'jszr_' . $scope . '_' . $version . '_' . md5( (string) wp_json_encode( $args ) );
	}

	/**
	 * Read a cached value.
	 *
	 * @param string $key Cache key.
	 * @return mixed False when absent or caching is disabled.
	 */
	public static function cache_get( $key ) {
		if ( (int) Settings::get( 'cache_ttl', 300 ) <= 0 ) {
			return false;
		}

		return get_transient( $key );
	}

	/**
	 * Store a cached value.
	 *
	 * @param string $key   Cache key.
	 * @param mixed  $value Value.
	 * @return void
	 */
	public static function cache_set( $key, $value ) {
		$ttl = (int) Settings::get( 'cache_ttl', 300 );

		if ( $ttl <= 0 ) {
			return;
		}

		set_transient( $key, $value, $ttl );
	}

	/**
	 * Invalidate every cached listing by bumping the version.
	 *
	 * @return void
	 */
	public static function flush_cache() {
		$version = (int) get_option( self::CACHE_VERSION_OPTION, 1 );

		update_option( self::CACHE_VERSION_OPTION, $version + 1, false );

		wp_cache_delete( 'jszr_job_counts', 'jszr' );
	}

	/**
	 * Flush cached listings when a single job changes outside a sync.
	 *
	 * A sync flushes once at the end of a run, but a job edited, trashed or
	 * deleted in wp-admin would otherwise stay in the cached listings until the
	 * TTL expired.
	 *
	 * @param int           $post_id Post ID.
	 * @param \WP_Post|null $post    Post object, when the hook provides one.
	 * @return void
	 */
	public static function flush_cache_for_post( $post_id, $post = null ) {
		// A sync writes thousands of posts and flushes once when it finishes;
		// bumping the option per record would be a write per job.
		if ( Sync::is_syncing() ) {
			return;
		}

		$type = $post instanceof \WP_Post ? $post->post_type : get_post_type( (int) $post_id );

		if ( Post_Type::POST_TYPE !== $type ) {
			return;
		}

		self::flush_cache();
	}
}
