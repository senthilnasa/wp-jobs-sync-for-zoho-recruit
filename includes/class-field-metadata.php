<?php
/**
 * Zoho field discovery.
 *
 * @package JobsSyncForZohoRecruit
 */

namespace JobsSyncForZohoRecruit;

defined( 'ABSPATH' ) || exit;

/**
 * Discovers the Job Opening fields available in the connected Zoho account so
 * the mapping UI can offer real choices instead of guesses.
 *
 * Falls back to a bundled list of standard fields when the site is not yet
 * connected or the metadata call fails.
 */
class Field_Metadata {

	/**
	 * Option caching the last successful discovery.
	 */
	const OPTION_CACHE = 'jszr_zoho_fields';

	/**
	 * Transient controlling how often discovery runs.
	 */
	const TRANSIENT_FRESH = 'jszr_zoho_fields_fresh';

	/**
	 * API client.
	 *
	 * @var Zoho_API
	 */
	private $api;

	/**
	 * Constructor.
	 *
	 * @param Zoho_API $api API client.
	 */
	public function __construct( Zoho_API $api ) {
		$this->api = $api;
	}

	/**
	 * Get the field list, refreshing from Zoho when the cache is stale.
	 *
	 * @param bool $force Force a refresh.
	 * @return array<string,array{api_name:string,label:string,type:string,options:array}>
	 */
	public function get_fields( $force = false ) {
		if ( ! $force && get_transient( self::TRANSIENT_FRESH ) ) {
			$cached = get_option( self::OPTION_CACHE, array() );

			if ( is_array( $cached ) && ! empty( $cached ) ) {
				return $cached;
			}
		}

		$fields = $this->refresh();

		if ( is_wp_error( $fields ) ) {
			$cached = get_option( self::OPTION_CACHE, array() );

			if ( is_array( $cached ) && ! empty( $cached ) ) {
				return $cached;
			}

			return self::default_fields();
		}

		return $fields;
	}

	/**
	 * Fetch and normalise the field list from Zoho.
	 *
	 * @return array|\WP_Error
	 */
	public function refresh() {
		$raw = $this->api->get_fields();

		if ( is_wp_error( $raw ) ) {
			Logger::warning( 'fields_discovery_failed', 'Could not load Zoho field metadata.', array( 'error' => $raw->get_error_message() ) );

			return $raw;
		}

		$fields = array();

		foreach ( $raw as $field ) {
			if ( ! is_array( $field ) || empty( $field['api_name'] ) ) {
				continue;
			}

			$api_name = Settings::sanitize_api_name( $field['api_name'] );

			if ( '' === $api_name ) {
				continue;
			}

			$options = array();

			if ( ! empty( $field['pick_list_values'] ) && is_array( $field['pick_list_values'] ) ) {
				foreach ( $field['pick_list_values'] as $option ) {
					if ( is_array( $option ) && isset( $option['display_value'] ) ) {
						$options[] = sanitize_text_field( (string) $option['display_value'] );
					}
				}
			}

			$fields[ $api_name ] = array(
				'api_name' => $api_name,
				'label'    => isset( $field['field_label'] ) ? sanitize_text_field( (string) $field['field_label'] ) : $api_name,
				'type'     => isset( $field['data_type'] ) ? sanitize_key( (string) $field['data_type'] ) : 'text',
				'options'  => $options,
			);
		}

		if ( empty( $fields ) ) {
			return new \WP_Error( 'jszr_no_fields', __( 'Zoho returned no field definitions for this module.', 'jobs-sync-for-zoho-recruit' ) );
		}

		ksort( $fields );

		update_option( self::OPTION_CACHE, $fields, false );
		set_transient( self::TRANSIENT_FRESH, 1, 12 * HOUR_IN_SECONDS );

		Logger::info( 'fields_discovered', 'Loaded Zoho field metadata.', array( 'count' => count( $fields ) ) );

		return $fields;
	}

	/**
	 * Whether a live field list has ever been stored.
	 *
	 * @return bool
	 */
	public function has_cached_fields() {
		$cached = get_option( self::OPTION_CACHE, array() );

		return is_array( $cached ) && ! empty( $cached );
	}

	/**
	 * Clear the cached field list.
	 *
	 * @return void
	 */
	public static function clear_cache() {
		delete_option( self::OPTION_CACHE );
		delete_transient( self::TRANSIENT_FRESH );
	}

	/**
	 * Standard Zoho Recruit job opening fields, used before the first
	 * successful discovery call.
	 *
	 * @return array
	 */
	public static function default_fields() {
		$fields = array(
			'Posting_Title'      => array( 'label' => 'Posting Title', 'type' => 'text' ),
			'Job_Opening_ID'     => array( 'label' => 'Job Opening ID', 'type' => 'text' ),
			'Job_Description'    => array( 'label' => 'Job Description', 'type' => 'textarea' ),
			'Job_Summary'        => array( 'label' => 'Job Summary', 'type' => 'textarea' ),
			'Requirements'       => array( 'label' => 'Requirements', 'type' => 'textarea' ),
			'Job_Opening_Status' => array( 'label' => 'Job Opening Status', 'type' => 'picklist' ),
			'Department_Name'    => array( 'label' => 'Department', 'type' => 'lookup' ),
			'Industry'           => array( 'label' => 'Industry', 'type' => 'picklist' ),
			'Job_Type'           => array( 'label' => 'Job Type', 'type' => 'picklist' ),
			'Work_Experience'    => array( 'label' => 'Work Experience', 'type' => 'picklist' ),
			'Salary'             => array( 'label' => 'Salary', 'type' => 'currency' ),
			'City'               => array( 'label' => 'City', 'type' => 'text' ),
			'State'              => array( 'label' => 'State', 'type' => 'text' ),
			'Country'            => array( 'label' => 'Country', 'type' => 'text' ),
			'Zip_Code'           => array( 'label' => 'Zip Code', 'type' => 'text' ),
			'Remote_Job'         => array( 'label' => 'Remote Job', 'type' => 'boolean' ),
			'Work_Mode'          => array( 'label' => 'Work Mode', 'type' => 'picklist' ),
			'Number_of_Positions' => array( 'label' => 'Number of Positions', 'type' => 'integer' ),
			'Date_Opened'        => array( 'label' => 'Date Opened', 'type' => 'date' ),
			'Target_Date'        => array( 'label' => 'Target Date', 'type' => 'date' ),
			'Expected_Closing_Date' => array( 'label' => 'Expected Closing Date', 'type' => 'date' ),
			'Created_Time'       => array( 'label' => 'Created Time', 'type' => 'datetime' ),
			'Modified_Time'      => array( 'label' => 'Modified Time', 'type' => 'datetime' ),
			'Client_Name'        => array( 'label' => 'Client Name', 'type' => 'lookup' ),
			'Account_Manager'    => array( 'label' => 'Account Manager', 'type' => 'ownerlookup' ),
			'Assigned_Recruiter' => array( 'label' => 'Assigned Recruiter', 'type' => 'multiselectlookup' ),
			'Publish_in_Career_Website' => array( 'label' => 'Publish in Career Website', 'type' => 'boolean' ),
			'Job_Opening_Name'   => array( 'label' => 'Job Opening Name', 'type' => 'text' ),
			'Skill_Set'          => array( 'label' => 'Skill Set', 'type' => 'textarea' ),
			'Website'            => array( 'label' => 'Website', 'type' => 'website' ),
		);

		$normalised = array();

		foreach ( $fields as $api_name => $config ) {
			$normalised[ $api_name ] = array(
				'api_name' => $api_name,
				'label'    => $config['label'],
				'type'     => $config['type'],
				'options'  => array(),
			);
		}

		/**
		 * Filter the fallback field list used before Zoho discovery succeeds.
		 *
		 * @param array $normalised Field definitions keyed by API name.
		 */
		return (array) apply_filters( 'jszr_default_zoho_fields', $normalised );
	}
}
