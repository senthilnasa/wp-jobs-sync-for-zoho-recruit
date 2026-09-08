<?php
/**
 * Uninstall routine.
 *
 * Runs when the plugin is deleted from the Plugins screen. It is deliberately
 * self-contained: no plugin classes are loaded, so nothing here can depend on
 * the plugin still being bootable.
 *
 * Nothing is removed unless the administrator asked for it in the settings, and
 * jobs created by hand in WordPress are never deleted.
 *
 * @package JobsSyncForZohoRecruit
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/*
 * The two routines below are guarded so the file can be included more than once
 * in a single process without a fatal redeclaration. WordPress includes it once,
 * but the lifecycle test exercises both uninstall settings back to back, and a
 * guard is cheaper than a crash if anything else ever claims the name.
 */
if ( ! function_exists( 'jszr_uninstall_site' ) ) {
	/**
	 * Remove every trace of the plugin from the current site.
	 *
	 * @return void
	 */
	function jszr_uninstall_site() {
		global $wpdb;

		$settings = get_option( 'jszr_settings', array() );
		$settings = is_array( $settings ) ? $settings : array();

		$delete_data = ! array_key_exists( 'uninstall_delete_data', $settings ) || ! empty( $settings['uninstall_delete_data'] );
		$delete_jobs = ! empty( $settings['uninstall_delete_jobs'] );

		// Scheduled events always go; leaving them behind would fire dead hooks.
		foreach ( array( 'jszr_scheduled_sync', 'jszr_check_expired', 'jszr_prune_logs' ) as $hook ) {
			wp_clear_scheduled_hook( $hook );
		}

		$crons = _get_cron_array();

		if ( is_array( $crons ) ) {
			foreach ( $crons as $timestamp => $hooks ) {
				foreach ( array_keys( (array) $hooks ) as $hook ) {
					if ( 0 === strpos( (string) $hook, 'jszr_' ) ) {
						wp_clear_scheduled_hook( $hook );
					}
				}
			}
		}

		if ( $delete_jobs ) {
			jszr_uninstall_delete_jobs();
		}

		if ( ! $delete_data ) {
			return;
		}

		$options = array(
			'jszr_settings',
			'jszr_credentials',
			'jszr_tokens',
			'jszr_auth_failures',
			'jszr_auth_state',
			'jszr_field_mapping',
			'jszr_zoho_fields',
			'jszr_sync_state',
			'jszr_db_version',
			'jszr_webhook_secret',
			'jszr_cache_version',
		);

		foreach ( $options as $option ) {
			delete_option( $option );
		}

		// Plugin transients, including the site-wide variants on multisite.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One-off cleanup.
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
				$wpdb->esc_like( '_transient_jszr_' ) . '%',
				$wpdb->esc_like( '_transient_timeout_jszr_' ) . '%'
			)
		);

		if ( is_multisite() ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One-off cleanup.
			$wpdb->query(
				$wpdb->prepare(
					"DELETE FROM {$wpdb->sitemeta} WHERE meta_key LIKE %s OR meta_key LIKE %s",
					$wpdb->esc_like( '_site_transient_jszr_' ) . '%',
					$wpdb->esc_like( '_site_transient_timeout_jszr_' ) . '%'
				)
			);
		}

		foreach ( array( 'jszr_sync_logs', 'jszr_sync_runs' ) as $table ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Removing the plugin's own tables.
			$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wpdb->prefix . $table ) );
		}

		// Remove the custom capability from every role.
		$roles = wp_roles();

		if ( $roles instanceof WP_Roles ) {
			foreach ( array_keys( $roles->roles ) as $role_name ) {
				$role = get_role( $role_name );

				if ( $role instanceof WP_Role ) {
					$role->remove_cap( 'manage_zoho_recruit' );
				}
			}
		}
	}
}

if ( ! function_exists( 'jszr_uninstall_delete_jobs' ) ) {
	/**
	 * Delete synchronized jobs and the taxonomy terms the plugin created.
	 *
	 * Jobs that were created by hand (no Zoho record ID) are left alone.
	 *
	 * @return void
	 */
	function jszr_uninstall_delete_jobs() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One-off cleanup.
		$post_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT p.ID FROM {$wpdb->posts} p
				INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID
				WHERE p.post_type = %s AND pm.meta_key = %s AND pm.meta_value != ''",
				'zoho_job',
				'_zoho_recruit_id'
			)
		);

		foreach ( (array) $post_ids as $post_id ) {
			wp_delete_post( (int) $post_id, true );
		}

		$taxonomies = array(
			'zoho_job_department',
			'zoho_job_location',
			'zoho_job_employment_type',
			'zoho_job_category',
			'zoho_job_experience',
		);

		// The plugin is no longer loaded, so its taxonomies are not registered.
		// wp_delete_term() refuses to touch an unregistered taxonomy, so register
		// bare versions of them purely for this cleanup pass.
		foreach ( $taxonomies as $taxonomy ) {
			if ( ! taxonomy_exists( $taxonomy ) ) {
				register_taxonomy(
					$taxonomy,
					'zoho_job',
					array(
						'public'  => false,
						'show_ui' => false,
					)
				);
			}
		}

		foreach ( $taxonomies as $taxonomy ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Taxonomies are not registered during uninstall.
			$term_ids = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT term_id FROM {$wpdb->term_taxonomy} WHERE taxonomy = %s",
					$taxonomy
				)
			);

			foreach ( (array) $term_ids as $term_id ) {
				wp_delete_term( (int) $term_id, $taxonomy );
			}
		}
	}
}

if ( is_multisite() ) {
	$jszr_site_ids = get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	);

	foreach ( $jszr_site_ids as $jszr_site_id ) {
		switch_to_blog( (int) $jszr_site_id );
		jszr_uninstall_site();
		restore_current_blog();
	}
} else {
	jszr_uninstall_site();
}
