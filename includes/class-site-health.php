<?php
/**
 * Site Health integration.
 *
 * @package JobsSyncForZohoRecruit
 */

namespace JobsSyncForZohoRecruit;

defined( 'ABSPATH' ) || exit;

/**
 * Surfaces the handful of environment problems that actually break syncing.
 */
class Site_Health {

	/**
	 * Hook registration.
	 *
	 * @return void
	 */
	public static function init() {
		add_filter( 'site_status_tests', array( __CLASS__, 'register_tests' ) );
		add_filter( 'debug_information', array( __CLASS__, 'debug_information' ) );
	}

	/**
	 * Register the plugin's tests.
	 *
	 * @param array $tests Registered tests.
	 * @return array
	 */
	public static function register_tests( $tests ) {
		$tests['direct']['jszr_connection'] = array(
			'label' => __( 'Zoho Recruit connection', 'jobs-sync-for-zoho-recruit' ),
			'test'  => array( __CLASS__, 'test_connection' ),
		);

		$tests['direct']['jszr_cron'] = array(
			'label' => __( 'Zoho Recruit scheduled sync', 'jobs-sync-for-zoho-recruit' ),
			'test'  => array( __CLASS__, 'test_cron' ),
		);

		$tests['direct']['jszr_last_sync'] = array(
			'label' => __( 'Zoho Recruit last sync', 'jobs-sync-for-zoho-recruit' ),
			'test'  => array( __CLASS__, 'test_last_sync' ),
		);

		$tests['direct']['jszr_environment'] = array(
			'label' => __( 'Zoho Recruit environment', 'jobs-sync-for-zoho-recruit' ),
			'test'  => array( __CLASS__, 'test_environment' ),
		);

		return $tests;
	}

	/**
	 * Base result array.
	 *
	 * @param string $label  Result label.
	 * @param string $status good|recommended|critical.
	 * @param string $body   HTML description.
	 * @return array
	 */
	private static function result( $label, $status, $body ) {
		return array(
			'label'       => $label,
			'status'      => $status,
			'badge'       => array(
				'label' => __( 'Zoho Recruit', 'jobs-sync-for-zoho-recruit' ),
				'color' => 'blue',
			),
			'description' => '<p>' . $body . '</p>',
			'actions'     => sprintf(
				'<p><a href="%1$s">%2$s</a></p>',
				esc_url( jszr_admin_url( 'jszr-settings' ) ),
				esc_html__( 'Open Zoho Recruit Jobs settings', 'jobs-sync-for-zoho-recruit' )
			),
			'test'        => 'jszr',
		);
	}

	/**
	 * Test: is the connection usable?
	 *
	 * @return array
	 */
	public static function test_connection() {
		$auth = plugin()->auth();

		if ( ! $auth->has_credentials() ) {
			return self::result(
				__( 'Zoho Recruit credentials have not been entered', 'jobs-sync-for-zoho-recruit' ),
				'recommended',
				esc_html__( 'Add your Zoho Client ID and Client Secret, then connect the site, before jobs can be synchronized.', 'jobs-sync-for-zoho-recruit' )
			);
		}

		if ( ! $auth->is_connected() ) {
			return self::result(
				__( 'This site is not connected to Zoho Recruit', 'jobs-sync-for-zoho-recruit' ),
				'recommended',
				esc_html__( 'Credentials are stored but the OAuth connection has not been completed.', 'jobs-sync-for-zoho-recruit' )
			);
		}

		if ( $auth->keys_changed() ) {
			return self::result(
				__( 'Stored Zoho credentials can no longer be decrypted', 'jobs-sync-for-zoho-recruit' ),
				'critical',
				esc_html__( 'The site security keys in wp-config.php changed, so the stored tokens cannot be read. Reconnect Zoho Recruit.', 'jobs-sync-for-zoho-recruit' )
			);
		}

		if ( $auth->is_circuit_open() ) {
			return self::result(
				__( 'Zoho Recruit syncing is paused after repeated failures', 'jobs-sync-for-zoho-recruit' ),
				'critical',
				esc_html__( 'Several authentication attempts failed in a row, so automatic syncing stopped. Reconnect Zoho Recruit to resume.', 'jobs-sync-for-zoho-recruit' )
			);
		}

		return self::result(
			__( 'Zoho Recruit is connected', 'jobs-sync-for-zoho-recruit' ),
			'good',
			esc_html__( 'The site holds a valid Zoho Recruit refresh token.', 'jobs-sync-for-zoho-recruit' )
		);
	}

	/**
	 * Test: is the sync scheduled and able to run?
	 *
	 * @return array
	 */
	public static function test_cron() {
		$frequency = (string) Settings::get( 'sync_frequency', 'jszr_six_hours' );

		if ( 'disabled' === $frequency ) {
			return self::result(
				__( 'Automatic Zoho Recruit syncing is disabled', 'jobs-sync-for-zoho-recruit' ),
				'recommended',
				esc_html__( 'Jobs will only change when you run a sync by hand.', 'jobs-sync-for-zoho-recruit' )
			);
		}

		$next = wp_next_scheduled( Cron::SYNC_HOOK );

		if ( ! $next ) {
			return self::result(
				__( 'The Zoho Recruit sync is not scheduled', 'jobs-sync-for-zoho-recruit' ),
				'critical',
				esc_html__( 'No scheduled event exists. Re-save the sync settings to reschedule it.', 'jobs-sync-for-zoho-recruit' )
			);
		}

		if ( Cron::is_wp_cron_disabled() ) {
			return self::result(
				__( 'WP-Cron is disabled on this site', 'jobs-sync-for-zoho-recruit' ),
				'recommended',
				esc_html__( 'DISABLE_WP_CRON is set, so scheduled syncing depends on a system cron job calling wp-cron.php. Confirm one is configured.', 'jobs-sync-for-zoho-recruit' )
			);
		}

		return self::result(
			__( 'The Zoho Recruit sync is scheduled', 'jobs-sync-for-zoho-recruit' ),
			'good',
			sprintf(
				/* translators: %s: formatted date and time. */
				esc_html__( 'The next sync is due %s.', 'jobs-sync-for-zoho-recruit' ),
				esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $next ) )
			)
		);
	}

	/**
	 * Test: has a sync succeeded recently?
	 *
	 * @return array
	 */
	public static function test_last_sync() {
		$state = get_option( Sync::OPTION_STATE, array() );
		$last  = is_array( $state ) && ! empty( $state['last_success'] ) ? (int) $state['last_success'] : 0;

		if ( ! $last ) {
			return self::result(
				__( 'No Zoho Recruit sync has completed yet', 'jobs-sync-for-zoho-recruit' ),
				'recommended',
				esc_html__( 'Run a full sync to import your job openings.', 'jobs-sync-for-zoho-recruit' )
			);
		}

		$age = time() - $last;

		if ( $age > ( 3 * DAY_IN_SECONDS ) ) {
			return self::result(
				__( 'The last successful Zoho Recruit sync is old', 'jobs-sync-for-zoho-recruit' ),
				'recommended',
				sprintf(
					/* translators: %s: human readable time difference. */
					esc_html__( 'The last successful sync finished %s ago. Check the sync log for errors.', 'jobs-sync-for-zoho-recruit' ),
					esc_html( human_time_diff( $last, time() ) )
				)
			);
		}

		return self::result(
			__( 'Zoho Recruit jobs are up to date', 'jobs-sync-for-zoho-recruit' ),
			'good',
			sprintf(
				/* translators: %s: human readable time difference. */
				esc_html__( 'The last successful sync finished %s ago.', 'jobs-sync-for-zoho-recruit' ),
				esc_html( human_time_diff( $last, time() ) )
			)
		);
	}

	/**
	 * Test: encryption, HTTPS and slug conflicts.
	 *
	 * @return array
	 */
	public static function test_environment() {
		$problems = array();

		if ( ! Encryption::is_available() ) {
			$problems[] = esc_html__( 'Secrets cannot be encrypted: this site has neither libsodium nor OpenSSL available, or its security salts are missing.', 'jobs-sync-for-zoho-recruit' );
		}

		if ( ! is_ssl() && 'https' !== wp_parse_url( admin_url(), PHP_URL_SCHEME ) ) {
			$problems[] = esc_html__( 'The admin area is not served over HTTPS. Zoho requires an HTTPS redirect URI for production credentials.', 'jobs-sync-for-zoho-recruit' );
		}

		$slug = (string) Settings::get( 'job_slug', 'jobs' );

		foreach ( get_post_types( array( '_builtin' => false ), 'objects' ) as $post_type ) {
			if ( Post_Type::POST_TYPE === $post_type->name ) {
				continue;
			}

			$other = is_array( $post_type->rewrite ) && isset( $post_type->rewrite['slug'] ) ? (string) $post_type->rewrite['slug'] : '';

			if ( '' !== $other && $other === $slug ) {
				$problems[] = sprintf(
					/* translators: 1: URL slug, 2: conflicting post type name. */
					esc_html__( 'The job URL slug "%1$s" is also used by the "%2$s" post type, which will break one of the two.', 'jobs-sync-for-zoho-recruit' ),
					esc_html( $slug ),
					esc_html( $post_type->name )
				);
			}
		}

		$page = get_page_by_path( $slug );

		if ( $page instanceof \WP_Post ) {
			$problems[] = sprintf(
				/* translators: %s: URL slug. */
				esc_html__( 'A page already exists at "/%s", which conflicts with the job URL slug.', 'jobs-sync-for-zoho-recruit' ),
				esc_html( $slug )
			);
		}

		if ( empty( $problems ) ) {
			return self::result(
				__( 'The Zoho Recruit environment looks correct', 'jobs-sync-for-zoho-recruit' ),
				'good',
				esc_html__( 'Encryption is available, the admin is served over HTTPS, and the job URL slug is free.', 'jobs-sync-for-zoho-recruit' )
			);
		}

		return self::result(
			__( 'The Zoho Recruit environment needs attention', 'jobs-sync-for-zoho-recruit' ),
			'recommended',
			implode( '</p><p>', $problems )
		);
	}

	/**
	 * Add plugin details to the Site Health info tab.
	 *
	 * @param array $info Debug information.
	 * @return array
	 */
	public static function debug_information( $info ) {
		$auth  = plugin()->auth();
		$state = plugin()->sync()->get_state();

		$info['jszr'] = array(
			'label'  => __( 'Jobs Sync for Zoho Recruit', 'jobs-sync-for-zoho-recruit' ),
			'fields' => array(
				'version'        => array(
					'label' => __( 'Plugin version', 'jobs-sync-for-zoho-recruit' ),
					'value' => VERSION,
				),
				'connected'      => array(
					'label' => __( 'Connected', 'jobs-sync-for-zoho-recruit' ),
					'value' => $auth->is_connected() ? __( 'Yes', 'jobs-sync-for-zoho-recruit' ) : __( 'No', 'jobs-sync-for-zoho-recruit' ),
				),
				'data_center'    => array(
					'label' => __( 'Data center', 'jobs-sync-for-zoho-recruit' ),
					'value' => Settings::data_center(),
				),
				'module'         => array(
					'label' => __( 'Zoho module', 'jobs-sync-for-zoho-recruit' ),
					'value' => plugin()->api()->module(),
				),
				'encryption'     => array(
					'label' => __( 'Secret encryption', 'jobs-sync-for-zoho-recruit' ),
					'value' => Encryption::is_available() ? __( 'Available', 'jobs-sync-for-zoho-recruit' ) : __( 'Unavailable', 'jobs-sync-for-zoho-recruit' ),
				),
				'last_sync'      => array(
					'label' => __( 'Last successful sync', 'jobs-sync-for-zoho-recruit' ),
					'value' => $state['last_success_time']
						? wp_date( 'c', $state['last_success_time'] )
						: __( 'Never', 'jobs-sync-for-zoho-recruit' ),
				),
				'job_counts'     => array(
					'label' => __( 'Job counts', 'jobs-sync-for-zoho-recruit' ),
					'value' => wp_json_encode( Job::counts() ),
				),
			),
		);

		return $info;
	}
}
