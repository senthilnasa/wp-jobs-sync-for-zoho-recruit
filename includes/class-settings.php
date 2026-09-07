<?php
/**
 * Settings storage, defaults and sanitization.
 *
 * @package JobsSyncForZohoRecruit
 */

namespace JobsSyncForZohoRecruit;

defined( 'ABSPATH' ) || exit;

/**
 * Single source of truth for plugin options.
 *
 * All settings live in one non-autoloaded option so a page load that never
 * touches the plugin pays nothing for it.
 */
class Settings {

	/**
	 * Option name holding the settings array.
	 */
	const OPTION = 'jszr_settings';

	/**
	 * Runtime cache of the merged settings array.
	 *
	 * @var array|null
	 */
	private static $cache = null;

	/**
	 * Supported Zoho data centers.
	 *
	 * @return array<string,array<string,string>>
	 */
	public static function data_centers() {
		$centers = array(
			'com' => array(
				'label'    => __( 'United States (zoho.com)', 'jobs-sync-for-zoho-recruit' ),
				'accounts' => 'https://accounts.zoho.com',
				'api'      => 'https://recruit.zoho.com',
			),
			'eu'  => array(
				'label'    => __( 'Europe (zoho.eu)', 'jobs-sync-for-zoho-recruit' ),
				'accounts' => 'https://accounts.zoho.eu',
				'api'      => 'https://recruit.zoho.eu',
			),
			'in'  => array(
				'label'    => __( 'India (zoho.in)', 'jobs-sync-for-zoho-recruit' ),
				'accounts' => 'https://accounts.zoho.in',
				'api'      => 'https://recruit.zoho.in',
			),
			'au'  => array(
				'label'    => __( 'Australia (zoho.com.au)', 'jobs-sync-for-zoho-recruit' ),
				'accounts' => 'https://accounts.zoho.com.au',
				'api'      => 'https://recruit.zoho.com.au',
			),
			'jp'  => array(
				'label'    => __( 'Japan (zoho.jp)', 'jobs-sync-for-zoho-recruit' ),
				'accounts' => 'https://accounts.zoho.jp',
				'api'      => 'https://recruit.zoho.jp',
			),
			'ca'  => array(
				'label'    => __( 'Canada (zohocloud.ca)', 'jobs-sync-for-zoho-recruit' ),
				'accounts' => 'https://accounts.zohocloud.ca',
				'api'      => 'https://recruit.zohocloud.ca',
			),
			'uk'  => array(
				'label'    => __( 'United Kingdom (zoho.uk)', 'jobs-sync-for-zoho-recruit' ),
				'accounts' => 'https://accounts.zoho.uk',
				'api'      => 'https://recruit.zoho.uk',
			),
			'sa'  => array(
				'label'    => __( 'Saudi Arabia (zoho.sa)', 'jobs-sync-for-zoho-recruit' ),
				'accounts' => 'https://accounts.zoho.sa',
				'api'      => 'https://recruit.zoho.sa',
			),
		);

		/**
		 * Filter the supported Zoho data centers.
		 *
		 * @param array $centers Map of key => array{label,accounts,api}.
		 */
		return (array) apply_filters( 'jszr_data_centers', $centers );
	}

	/**
	 * Default settings.
	 *
	 * @return array
	 */
	public static function defaults() {
		$defaults = array(
			// Connection.
			'data_center'             => 'com',

			// Sync.
			'sync_frequency'          => 'jszr_six_hours',
			'cron_sync_type'          => 'incremental',
			'full_sync_interval_days' => 7,
			'per_request'             => 200,
			'batch_size'              => 50,
			'request_timeout'         => 30,
			'only_published'          => true,
			'published_field'         => 'Publish_in_Career_Website',
			'status_map'              => array(
				'In-progress'          => 'active',
				'Active'               => 'active',
				'Submitted'            => 'active',
				'Approved'             => 'active',
				'On-Hold'              => 'inactive',
				'Inactive'             => 'inactive',
				'Waiting for approval' => 'draft',
				'Cancelled'            => 'closed',
				'Closed'               => 'closed',
				'Filled'               => 'closed',
				'Closed-Won'           => 'closed',
			),
			'default_status'          => 'active',
			'expire_action'           => 'inactive',
			'orphan_action'           => 'draft',
			'deactivation_threshold'  => 50,
			'conflict_mode'           => 'mapped_only',
			'store_raw'               => true,
			'notify_on_failure'       => true,
			'notify_email'            => '',

			// Public REST API.
			'rest_enabled'            => true,
			'rest_per_page'           => 20,
			'rest_max_per_page'       => 100,
			'rest_allow_inactive'     => false,
			'rest_fields'             => array(
				'id',
				'title',
				'slug',
				'link',
				'excerpt',
				'content',
				'job_code',
				'status',
				'department',
				'location',
				'city',
				'state',
				'country',
				'remote',
				'employment_type',
				'experience',
				'salary',
				'posted_date',
				'closing_date',
				'apply_url',
			),
			'cache_ttl'               => 300,

			// Frontend.
			'job_slug'                => 'jobs',
			'archive_slug'            => 'jobs',
			'public_jobs'             => true,
			'default_style'           => 'list',
			'expired_behavior'        => 'notice',
			'apply_label'             => '',
			'apply_url_template'      => '',
			'apply_utm'               => '',

			// Structured data.
			'schema_enabled'          => true,
			'schema_skip_if_seo'      => true,
			'org_name'                => '',
			'org_logo'                => '',
			'org_url'                 => '',

			// SEO.
			'sitemap_enabled'         => true,

			// Webhook.
			'webhook_enabled'         => false,

			// Advanced.
			'debug_logging'           => false,
			'log_retention_days'      => 30,
			'log_retention_max'       => 200,
			'uninstall_delete_jobs'   => false,
			'uninstall_delete_data'   => true,
		);

		/**
		 * Filter the plugin's default settings.
		 *
		 * @param array $defaults Default settings.
		 */
		return (array) apply_filters( 'jszr_default_settings', $defaults );
	}

	/**
	 * Get all settings merged over defaults.
	 *
	 * @return array
	 */
	public static function all() {
		if ( null === self::$cache ) {
			$stored = get_option( self::OPTION, array() );

			if ( ! is_array( $stored ) ) {
				$stored = array();
			}

			self::$cache = array_merge( self::defaults(), $stored );
		}

		return self::$cache;
	}

	/**
	 * Get a single setting.
	 *
	 * @param string $key      Setting key.
	 * @param mixed  $fallback Value returned when the key is unknown.
	 * @return mixed
	 */
	public static function get( $key, $fallback = null ) {
		$all = self::all();

		if ( array_key_exists( $key, $all ) ) {
			return $all[ $key ];
		}

		return $fallback;
	}

	/**
	 * Update one or more settings.
	 *
	 * @param array $values Key => value pairs (already sanitized).
	 * @return bool
	 */
	public static function update( array $values ) {
		$current = self::all();
		$merged  = array_merge( $current, $values );

		self::$cache = $merged;

		return update_option( self::OPTION, $merged, false );
	}

	/**
	 * Replace the whole settings array.
	 *
	 * @param array $values Sanitized settings.
	 * @return bool
	 */
	public static function replace( array $values ) {
		self::$cache = array_merge( self::defaults(), $values );

		return update_option( self::OPTION, self::$cache, false );
	}

	/**
	 * Clear the runtime cache (used by tests and after external writes).
	 *
	 * @return void
	 */
	public static function flush_cache() {
		self::$cache = null;
	}

	/**
	 * Accounts (OAuth) base URL for the configured data center.
	 *
	 * @return string
	 */
	public static function accounts_url() {
		$centers = self::data_centers();
		$key     = self::data_center();

		return isset( $centers[ $key ]['accounts'] ) ? $centers[ $key ]['accounts'] : $centers['com']['accounts'];
	}

	/**
	 * Recruit API base URL for the configured data center.
	 *
	 * @return string
	 */
	public static function api_url() {
		$centers = self::data_centers();
		$key     = self::data_center();

		return isset( $centers[ $key ]['api'] ) ? $centers[ $key ]['api'] : $centers['com']['api'];
	}

	/**
	 * Effective data center key, honouring the wp-config constant.
	 *
	 * @return string
	 */
	public static function data_center() {
		if ( defined( 'JSZR_DATA_CENTER' ) && '' !== (string) JSZR_DATA_CENTER ) {
			$key = sanitize_key( (string) JSZR_DATA_CENTER );
		} else {
			$key = sanitize_key( (string) self::get( 'data_center', 'com' ) );
		}

		$centers = self::data_centers();

		return isset( $centers[ $key ] ) ? $key : 'com';
	}

	/**
	 * Sanitize an incoming settings array.
	 *
	 * Unknown keys are dropped. Every known key is coerced to its expected type.
	 *
	 * @param array $input Raw input.
	 * @return array Sanitized settings, merged over the current values.
	 */
	public static function sanitize( $input ) {
		$input    = is_array( $input ) ? $input : array();
		$current  = self::all();
		$defaults = self::defaults();
		$out      = $current;

		$booleans = array(
			'only_published',
			'store_raw',
			'notify_on_failure',
			'rest_enabled',
			'rest_allow_inactive',
			'public_jobs',
			'schema_enabled',
			'schema_skip_if_seo',
			'sitemap_enabled',
			'webhook_enabled',
			'debug_logging',
			'uninstall_delete_jobs',
			'uninstall_delete_data',
		);

		foreach ( $booleans as $key ) {
			if ( array_key_exists( $key, $input ) ) {
				$out[ $key ] = (bool) $input[ $key ];
			}
		}

		$integers = array(
			'full_sync_interval_days' => array( 0, 365 ),
			'per_request'             => array( 1, 200 ),
			'batch_size'              => array( 1, 200 ),
			'request_timeout'         => array( 5, 120 ),
			'deactivation_threshold'  => array( 1, 100 ),
			'rest_per_page'           => array( 1, 100 ),
			'rest_max_per_page'       => array( 1, 200 ),
			'cache_ttl'               => array( 0, 86400 ),
			'log_retention_days'      => array( 0, 365 ),
			'log_retention_max'       => array( 0, 10000 ),
		);

		foreach ( $integers as $key => $range ) {
			if ( array_key_exists( $key, $input ) ) {
				$value       = (int) $input[ $key ];
				$out[ $key ] = max( $range[0], min( $range[1], $value ) );
			}
		}

		if ( isset( $input['data_center'] ) ) {
			$centers            = self::data_centers();
			$dc                 = sanitize_key( (string) $input['data_center'] );
			$out['data_center'] = isset( $centers[ $dc ] ) ? $dc : $defaults['data_center'];
		}

		$enums = array(
			'sync_frequency'   => array( 'disabled', 'hourly', 'jszr_six_hours', 'twicedaily', 'daily' ),
			'cron_sync_type'   => array( 'incremental', 'full' ),
			'expire_action'    => array( 'inactive', 'draft', 'private', 'trash' ),
			'orphan_action'    => array( 'none', 'draft', 'private', 'trash', 'delete' ),
			'conflict_mode'    => array( 'overwrite_all', 'mapped_only', 'preserve_manual' ),
			'default_style'    => array( 'list', 'grid' ),
			'expired_behavior' => array( 'notice', 'gone', 'redirect' ),
			'default_status'   => array( 'active', 'inactive', 'draft', 'closed', 'expired' ),
		);

		foreach ( $enums as $key => $allowed ) {
			if ( isset( $input[ $key ] ) ) {
				$value       = sanitize_key( (string) $input[ $key ] );
				$out[ $key ] = in_array( $value, $allowed, true ) ? $value : $defaults[ $key ];
			}
		}

		$slugs = array( 'job_slug', 'archive_slug' );

		foreach ( $slugs as $key ) {
			if ( isset( $input[ $key ] ) ) {
				$value       = sanitize_title( (string) $input[ $key ] );
				$out[ $key ] = '' === $value ? $defaults[ $key ] : $value;
			}
		}

		if ( isset( $input['published_field'] ) ) {
			$out['published_field'] = self::sanitize_api_name( $input['published_field'] );
		}

		if ( isset( $input['notify_email'] ) ) {
			$email               = sanitize_email( (string) $input['notify_email'] );
			$out['notify_email'] = is_email( $email ) ? $email : '';
		}

		$texts = array( 'apply_label', 'apply_url_template', 'apply_utm', 'org_name' );

		foreach ( $texts as $key ) {
			if ( isset( $input[ $key ] ) ) {
				$out[ $key ] = sanitize_text_field( (string) $input[ $key ] );
			}
		}

		foreach ( array( 'org_logo', 'org_url' ) as $key ) {
			if ( isset( $input[ $key ] ) ) {
				$out[ $key ] = esc_url_raw( (string) $input[ $key ] );
			}
		}

		$allowed_statuses = array( 'active', 'inactive', 'draft', 'closed', 'expired', 'skip' );

		if ( isset( $input['status_map'] ) && is_array( $input['status_map'] ) ) {
			$map = array();

			foreach ( $input['status_map'] as $zoho_status => $local ) {
				$zoho_status = sanitize_text_field( (string) $zoho_status );
				$local       = sanitize_key( (string) $local );

				if ( '' === $zoho_status || ! in_array( $local, $allowed_statuses, true ) ) {
					continue;
				}

				$map[ $zoho_status ] = $local;
			}

			$out['status_map'] = $map;
		}

		// A single "add another status" row appended by the settings screen.
		if ( isset( $input['new_status_key'] ) && '' !== trim( (string) $input['new_status_key'] ) ) {
			$new_key   = sanitize_text_field( (string) $input['new_status_key'] );
			$new_value = isset( $input['new_status_value'] ) ? sanitize_key( (string) $input['new_status_value'] ) : 'active';

			if ( in_array( $new_value, $allowed_statuses, true ) ) {
				$out['status_map'][ $new_key ] = $new_value;
			}
		}

		if ( isset( $input['rest_fields'] ) ) {
			$allowed = self::available_rest_fields();
			$fields  = is_array( $input['rest_fields'] ) ? $input['rest_fields'] : array();
			$fields  = array_map( 'sanitize_key', $fields );
			$fields  = array_values( array_intersect( $fields, array_keys( $allowed ) ) );

			// "id" and "title" are always required for a usable response.
			$fields = array_values( array_unique( array_merge( array( 'id', 'title' ), $fields ) ) );

			$out['rest_fields'] = $fields;
		}

		if ( $out['rest_per_page'] > $out['rest_max_per_page'] ) {
			$out['rest_per_page'] = $out['rest_max_per_page'];
		}

		/**
		 * Filter sanitized settings just before they are stored.
		 *
		 * @param array $out   Sanitized settings.
		 * @param array $input Raw input.
		 */
		return (array) apply_filters( 'jszr_sanitize_settings', $out, $input );
	}

	/**
	 * Sanitize a Zoho API field name.
	 *
	 * Zoho API names are alphanumeric with underscores; anything else is dropped.
	 *
	 * @param string $name Raw name.
	 * @return string
	 */
	public static function sanitize_api_name( $name ) {
		$name = (string) $name;
		$name = preg_replace( '/[^A-Za-z0-9_]/', '', $name );

		return is_string( $name ) ? substr( $name, 0, 100 ) : '';
	}

	/**
	 * Fields that may be exposed through the public REST API.
	 *
	 * @return array<string,string> Key => human label.
	 */
	public static function available_rest_fields() {
		$fields = array(
			'id'              => __( 'Job ID (WordPress)', 'jobs-sync-for-zoho-recruit' ),
			'title'           => __( 'Job title', 'jobs-sync-for-zoho-recruit' ),
			'slug'            => __( 'Slug', 'jobs-sync-for-zoho-recruit' ),
			'link'            => __( 'Permalink', 'jobs-sync-for-zoho-recruit' ),
			'excerpt'         => __( 'Excerpt', 'jobs-sync-for-zoho-recruit' ),
			'content'         => __( 'Description', 'jobs-sync-for-zoho-recruit' ),
			'job_code'        => __( 'Job code', 'jobs-sync-for-zoho-recruit' ),
			'status'          => __( 'Status', 'jobs-sync-for-zoho-recruit' ),
			'department'      => __( 'Department', 'jobs-sync-for-zoho-recruit' ),
			'location'        => __( 'Location', 'jobs-sync-for-zoho-recruit' ),
			'city'            => __( 'City', 'jobs-sync-for-zoho-recruit' ),
			'state'           => __( 'State', 'jobs-sync-for-zoho-recruit' ),
			'country'         => __( 'Country', 'jobs-sync-for-zoho-recruit' ),
			'remote'          => __( 'Remote / work mode', 'jobs-sync-for-zoho-recruit' ),
			'employment_type' => __( 'Employment type', 'jobs-sync-for-zoho-recruit' ),
			'experience'      => __( 'Experience', 'jobs-sync-for-zoho-recruit' ),
			'category'        => __( 'Category', 'jobs-sync-for-zoho-recruit' ),
			'industry'        => __( 'Industry', 'jobs-sync-for-zoho-recruit' ),
			'client'          => __( 'Client', 'jobs-sync-for-zoho-recruit' ),
			'positions'       => __( 'Number of positions', 'jobs-sync-for-zoho-recruit' ),
			'salary'          => __( 'Salary', 'jobs-sync-for-zoho-recruit' ),
			'posted_date'     => __( 'Posted date', 'jobs-sync-for-zoho-recruit' ),
			'closing_date'    => __( 'Closing date', 'jobs-sync-for-zoho-recruit' ),
			'modified_time'   => __( 'Zoho modified time', 'jobs-sync-for-zoho-recruit' ),
			'apply_url'       => __( 'Application URL', 'jobs-sync-for-zoho-recruit' ),
			'zoho_id'         => __( 'Zoho record ID', 'jobs-sync-for-zoho-recruit' ),
		);

		/**
		 * Filter the fields that can be exposed publicly.
		 *
		 * @param array $fields Key => label.
		 */
		return (array) apply_filters( 'jszr_available_rest_fields', $fields );
	}
}
