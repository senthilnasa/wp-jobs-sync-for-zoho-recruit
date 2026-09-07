<?php
/**
 * Custom post type, taxonomies and meta registration.
 *
 * @package JobsSyncForZohoRecruit
 */

namespace JobsSyncForZohoRecruit;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the zoho_job post type and its taxonomies.
 */
class Post_Type {

	/**
	 * Post type name.
	 */
	const POST_TYPE = 'zoho_job';

	/**
	 * Taxonomy: department.
	 */
	const TAX_DEPARTMENT = 'zoho_job_department';

	/**
	 * Taxonomy: location (hierarchical: country > state > city).
	 */
	const TAX_LOCATION = 'zoho_job_location';

	/**
	 * Taxonomy: employment type.
	 */
	const TAX_EMPLOYMENT_TYPE = 'zoho_job_employment_type';

	/**
	 * Taxonomy: category.
	 */
	const TAX_CATEGORY = 'zoho_job_category';

	/**
	 * Taxonomy: experience.
	 */
	const TAX_EXPERIENCE = 'zoho_job_experience';

	/**
	 * Hook registration.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'register' ), 5 );
		add_action( 'init', array( __CLASS__, 'register_meta' ), 6 );
		add_action( 'update_option_' . Settings::OPTION, array( __CLASS__, 'maybe_flush_rewrites' ), 10, 2 );
		add_filter( 'wp_sitemaps_post_types', array( __CLASS__, 'filter_sitemap_post_types' ) );
		add_filter( 'wp_unique_post_slug', array( __CLASS__, 'preserve_slug' ), 10, 6 );
	}

	/**
	 * Register the post type and taxonomies.
	 *
	 * @return void
	 */
	public static function register() {
		$slug   = (string) Settings::get( 'job_slug', 'jobs' );
		$public = (bool) Settings::get( 'public_jobs', true );

		$labels = array(
			'name'                  => _x( 'Jobs', 'post type general name', 'jobs-sync-for-zoho-recruit' ),
			'singular_name'         => _x( 'Job', 'post type singular name', 'jobs-sync-for-zoho-recruit' ),
			'menu_name'             => _x( 'Zoho Recruit Jobs', 'admin menu', 'jobs-sync-for-zoho-recruit' ),
			'name_admin_bar'        => _x( 'Job', 'add new on admin bar', 'jobs-sync-for-zoho-recruit' ),
			'add_new'               => __( 'Add New', 'jobs-sync-for-zoho-recruit' ),
			'add_new_item'          => __( 'Add New Job', 'jobs-sync-for-zoho-recruit' ),
			'new_item'              => __( 'New Job', 'jobs-sync-for-zoho-recruit' ),
			'edit_item'             => __( 'Edit Job', 'jobs-sync-for-zoho-recruit' ),
			'view_item'             => __( 'View Job', 'jobs-sync-for-zoho-recruit' ),
			'view_items'            => __( 'View Jobs', 'jobs-sync-for-zoho-recruit' ),
			'all_items'             => __( 'All Jobs', 'jobs-sync-for-zoho-recruit' ),
			'search_items'          => __( 'Search Jobs', 'jobs-sync-for-zoho-recruit' ),
			'not_found'             => __( 'No jobs found.', 'jobs-sync-for-zoho-recruit' ),
			'not_found_in_trash'    => __( 'No jobs found in Trash.', 'jobs-sync-for-zoho-recruit' ),
			'archives'              => __( 'Job Archives', 'jobs-sync-for-zoho-recruit' ),
			'item_published'        => __( 'Job published.', 'jobs-sync-for-zoho-recruit' ),
			'item_updated'          => __( 'Job updated.', 'jobs-sync-for-zoho-recruit' ),
			'featured_image'        => __( 'Job image', 'jobs-sync-for-zoho-recruit' ),
			'set_featured_image'    => __( 'Set job image', 'jobs-sync-for-zoho-recruit' ),
			'remove_featured_image' => __( 'Remove job image', 'jobs-sync-for-zoho-recruit' ),
		);

		$args = array(
			'labels'              => $labels,
			'description'         => __( 'Job openings synchronized from Zoho Recruit.', 'jobs-sync-for-zoho-recruit' ),
			'public'              => $public,
			'publicly_queryable'  => $public,
			'exclude_from_search' => ! $public,
			'show_ui'             => true,
			'show_in_menu'        => true,
			'show_in_nav_menus'   => $public,
			'show_in_admin_bar'   => true,
			'show_in_rest'        => true,
			'rest_base'           => 'zoho_job',
			'menu_position'       => 26,
			'menu_icon'           => 'dashicons-businessperson',
			'capability_type'     => 'post',
			'map_meta_cap'        => true,
			'hierarchical'        => false,
			'has_archive'         => $public ? (string) Settings::get( 'archive_slug', 'jobs' ) : false,
			'rewrite'             => $public ? array(
				'slug'       => $slug,
				'with_front' => false,
				'feeds'      => true,
				'pages'      => true,
			) : false,
			'supports'            => array( 'title', 'editor', 'excerpt', 'custom-fields', 'thumbnail', 'revisions' ),
			'delete_with_user'    => false,
		);

		/**
		 * Filter the job post type registration arguments.
		 *
		 * @param array $args Arguments passed to register_post_type().
		 */
		$args = (array) apply_filters( 'jszr_post_type_args', $args );

		register_post_type( self::POST_TYPE, $args );

		foreach ( self::taxonomies() as $taxonomy => $config ) {
			register_taxonomy( $taxonomy, array( self::POST_TYPE ), $config['args'] );
		}
	}

	/**
	 * Taxonomy definitions.
	 *
	 * @return array<string,array{label:string,args:array}>
	 */
	public static function taxonomies() {
		$public = (bool) Settings::get( 'public_jobs', true );

		$build = static function ( $singular, $plural, $slug, $hierarchical ) use ( $public ) {
			return array(
				'labels'             => array(
					'name'          => $plural,
					'singular_name' => $singular,
					'search_items'  => $plural,
					'all_items'     => $plural,
					'edit_item'     => $singular,
					'update_item'   => $singular,
					'add_new_item'  => $singular,
					'new_item_name' => $singular,
					'menu_name'     => $plural,
				),
				'hierarchical'       => $hierarchical,
				'public'             => $public,
				'publicly_queryable' => $public,
				'show_ui'            => true,
				'show_admin_column'  => true,
				'show_in_rest'       => true,
				'show_in_nav_menus'  => $public,
				'query_var'          => true,
				'rewrite'            => $public ? array(
					'slug'       => $slug,
					'with_front' => false,
				) : false,
			);
		};

		$taxonomies = array(
			self::TAX_DEPARTMENT      => array(
				'label' => __( 'Department', 'jobs-sync-for-zoho-recruit' ),
				'args'  => $build(
					__( 'Department', 'jobs-sync-for-zoho-recruit' ),
					__( 'Departments', 'jobs-sync-for-zoho-recruit' ),
					'job-department',
					false
				),
			),
			self::TAX_LOCATION        => array(
				'label' => __( 'Location', 'jobs-sync-for-zoho-recruit' ),
				'args'  => $build(
					__( 'Location', 'jobs-sync-for-zoho-recruit' ),
					__( 'Locations', 'jobs-sync-for-zoho-recruit' ),
					'job-location',
					true
				),
			),
			self::TAX_EMPLOYMENT_TYPE => array(
				'label' => __( 'Employment Type', 'jobs-sync-for-zoho-recruit' ),
				'args'  => $build(
					__( 'Employment Type', 'jobs-sync-for-zoho-recruit' ),
					__( 'Employment Types', 'jobs-sync-for-zoho-recruit' ),
					'job-type',
					false
				),
			),
			self::TAX_CATEGORY        => array(
				'label' => __( 'Category', 'jobs-sync-for-zoho-recruit' ),
				'args'  => $build(
					__( 'Job Category', 'jobs-sync-for-zoho-recruit' ),
					__( 'Job Categories', 'jobs-sync-for-zoho-recruit' ),
					'job-category',
					true
				),
			),
			self::TAX_EXPERIENCE      => array(
				'label' => __( 'Experience', 'jobs-sync-for-zoho-recruit' ),
				'args'  => $build(
					__( 'Experience', 'jobs-sync-for-zoho-recruit' ),
					__( 'Experience Levels', 'jobs-sync-for-zoho-recruit' ),
					'job-experience',
					false
				),
			),
		);

		/**
		 * Filter the taxonomies registered for jobs.
		 *
		 * Developers may add taxonomies here; they automatically become
		 * available as field-mapping targets.
		 *
		 * @param array $taxonomies Map of taxonomy name => array{label, args}.
		 */
		return (array) apply_filters( 'jszr_taxonomies', $taxonomies );
	}

	/**
	 * Meta keys that hold synchronized values.
	 *
	 * @return array<string,string> Meta key => type (string|integer|number|boolean).
	 */
	public static function meta_keys() {
		return array(
			'_zoho_recruit_id'              => 'string',
			'_jszr_job_code'                => 'string',
			'_jszr_status'                  => 'string',
			'_zoho_recruit_status'          => 'string',
			'_zoho_recruit_created_time'    => 'string',
			'_zoho_recruit_modified_time'   => 'string',
			'_zoho_recruit_posted_date'     => 'string',
			'_zoho_recruit_closing_date'    => 'string',
			'_zoho_recruit_city'            => 'string',
			'_zoho_recruit_state'           => 'string',
			'_zoho_recruit_country'         => 'string',
			'_zoho_recruit_remote'          => 'string',
			'_zoho_recruit_industry'        => 'string',
			'_zoho_recruit_client'          => 'string',
			'_zoho_recruit_positions'       => 'integer',
			'_zoho_recruit_salary'          => 'string',
			'_zoho_recruit_salary_min'      => 'number',
			'_zoho_recruit_salary_max'      => 'number',
			'_zoho_recruit_salary_currency' => 'string',
			'_zoho_recruit_salary_unit'     => 'string',
			'_zoho_recruit_application_url' => 'string',
			'_zoho_recruit_source_url'      => 'string',
			'_zoho_recruit_last_synced'     => 'string',
		);
	}

	/**
	 * Map a public REST field name to its meta key, where one exists.
	 *
	 * @return array<string,string>
	 */
	public static function rest_field_meta_map() {
		return array(
			'job_code'      => '_jszr_job_code',
			'status'        => '_jszr_status',
			'city'          => '_zoho_recruit_city',
			'state'         => '_zoho_recruit_state',
			'country'       => '_zoho_recruit_country',
			'remote'        => '_zoho_recruit_remote',
			'industry'      => '_zoho_recruit_industry',
			'client'        => '_zoho_recruit_client',
			'positions'     => '_zoho_recruit_positions',
			'salary'        => '_zoho_recruit_salary',
			'posted_date'   => '_zoho_recruit_posted_date',
			'closing_date'  => '_zoho_recruit_closing_date',
			'modified_time' => '_zoho_recruit_modified_time',
			'zoho_id'       => '_zoho_recruit_id',
		);
	}

	/**
	 * Register post meta.
	 *
	 * Only meta whose corresponding public field is enabled in settings is
	 * exposed through the core wp/v2 endpoint; everything else stays private.
	 *
	 * @return void
	 */
	public static function register_meta() {
		$allowed_fields = (array) Settings::get( 'rest_fields', array() );
		$field_map      = self::rest_field_meta_map();
		$public_meta    = array();

		foreach ( $field_map as $field => $meta_key ) {
			if ( in_array( $field, $allowed_fields, true ) ) {
				$public_meta[] = $meta_key;
			}
		}

		foreach ( self::meta_keys() as $meta_key => $type ) {
			$show_in_rest = in_array( $meta_key, $public_meta, true );

			register_post_meta(
				self::POST_TYPE,
				$meta_key,
				array(
					'type'              => $type,
					'single'            => true,
					'show_in_rest'      => $show_in_rest,
					'sanitize_callback' => 'number' === $type
						? static function ( $value ) {
							return (float) $value;
						}
						: ( 'integer' === $type
							? static function ( $value ) {
								return (int) $value;
							}
							: 'sanitize_text_field' ),
					'auth_callback'     => static function () {
						return current_user_can( Plugin::capability() );
					},
				)
			);
		}

		// Never exposed through REST, regardless of settings.
		foreach ( array( '_zoho_recruit_raw_data', '_jszr_sync_hash', '_jszr_manual', '_jszr_mapped_values' ) as $private_key ) {
			register_post_meta(
				self::POST_TYPE,
				$private_key,
				array(
					'single'        => true,
					'show_in_rest'  => false,
					'auth_callback' => static function () {
						return current_user_can( Plugin::capability() );
					},
				)
			);
		}
	}

	/**
	 * Flush rewrite rules when a slug or visibility setting changes.
	 *
	 * @param mixed $old_value Previous option value.
	 * @param mixed $value     New option value.
	 * @return void
	 */
	public static function maybe_flush_rewrites( $old_value, $value ) {
		$keys = array( 'job_slug', 'archive_slug', 'public_jobs' );
		$old  = is_array( $old_value ) ? $old_value : array();
		$new  = is_array( $value ) ? $value : array();

		foreach ( $keys as $key ) {
			$before = isset( $old[ $key ] ) ? $old[ $key ] : null;
			$after  = isset( $new[ $key ] ) ? $new[ $key ] : null;

			if ( $before !== $after ) {
				Settings::flush_cache();
				self::register();
				flush_rewrite_rules( false );

				return;
			}
		}
	}

	/**
	 * Keep job slugs stable once assigned.
	 *
	 * Zoho titles change frequently (typo fixes, re-postings). Regenerating the
	 * slug would break every published link, so an existing job keeps the slug
	 * it already has unless an editor changes it by hand.
	 *
	 * @param string $slug          Proposed slug.
	 * @param int    $post_id       Post ID.
	 * @param string $post_status   Post status.
	 * @param string $post_type     Post type.
	 * @param int    $post_parent   Parent ID.
	 * @param string $original_slug Requested slug.
	 * @return string
	 */
	public static function preserve_slug( $slug, $post_id, $post_status, $post_type, $post_parent, $original_slug ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- Signature is fixed by the wp_unique_post_slug filter.
		if ( self::POST_TYPE !== $post_type ) {
			return $slug;
		}

		if ( ! Sync::is_syncing() ) {
			return $slug;
		}

		$existing = get_post_field( 'post_name', $post_id );

		return '' !== (string) $existing ? (string) $existing : $slug;
	}

	/**
	 * Include or exclude jobs from core sitemaps per settings.
	 *
	 * @param array $post_types Sitemap post type objects keyed by name.
	 * @return array
	 */
	public static function filter_sitemap_post_types( $post_types ) {
		if ( ! Settings::get( 'sitemap_enabled', true ) || ! Settings::get( 'public_jobs', true ) ) {
			unset( $post_types[ self::POST_TYPE ] );
		}

		return $post_types;
	}
}
