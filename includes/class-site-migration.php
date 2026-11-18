<?php
/**
 * Moving the plugin's configuration to another site.
 *
 * @package JobsSyncForZohoRecruit
 */

namespace JobsSyncForZohoRecruit;

defined( 'ABSPATH' ) || exit;

/**
 * Builds and applies a migration file: everything that makes a careers page
 * look and behave the way it does, so a new site can be set up in one step.
 *
 * Included: every setting (layouts, card and job-details HTML templates, named
 * card designs, custom CSS, filter and search options, slugs, sync and REST
 * options), the field mapping and the Zoho field list. Never included: Zoho
 * credentials and tokens (invariant 5), the webhook secret, the notification
 * address, sync history, logs and the jobs themselves -- the new site connects
 * to Zoho on its own and the first sync brings the jobs back.
 *
 * Links in the settings that point at the old site (a template's "Back to
 * jobs" link, a CSS background, the organisation URL) can be rewritten to the
 * new site's address on import.
 */
class Site_Migration {

	/**
	 * File format. 1 was the settings-and-mapping export; 2 adds the site
	 * address and the Zoho field list. Both import.
	 */
	const FORMAT = 2;

	/**
	 * The `plugin` value every export carries.
	 */
	const PLUGIN = 'jobs-sync-for-zoho-recruit';

	/**
	 * Settings that are personal to one site and never travel.
	 */
	const SITE_ONLY_SETTINGS = array( 'notify_email' );

	/**
	 * Most Zoho fields an import will store. A real module has a few hundred.
	 */
	const MAX_FIELDS = 2000;

	/**
	 * Build the migration payload.
	 *
	 * @return array
	 */
	public static function export() {
		$settings = Settings::all();

		foreach ( self::SITE_ONLY_SETTINGS as $key ) {
			unset( $settings[ $key ] );
		}

		$fields = get_option( Field_Metadata::OPTION_CACHE, array() );

		$payload = array(
			'plugin'      => self::PLUGIN,
			'format'      => self::FORMAT,
			'version'     => VERSION,
			'exported'    => gmdate( 'c' ),
			'site_url'    => untrailingslashit( home_url() ),
			'settings'    => $settings,
			'mapping'     => Field_Mapper::get_mapping(),
			'zoho_fields' => is_array( $fields ) ? $fields : array(),
		);

		/**
		 * Filter the migration file before it is downloaded.
		 *
		 * Anything secret must stay out: the file is meant to be emailed,
		 * attached to tickets and committed to staging repositories.
		 *
		 * @param array $payload Migration payload.
		 */
		return (array) apply_filters( 'jszr_migration_export', $payload );
	}

	/**
	 * The export as pretty-printed JSON.
	 *
	 * @return string
	 */
	public static function export_json() {
		return (string) wp_json_encode( self::export(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
	}

	/**
	 * A download file name naming the site and the day, so two exports
	 * never get mixed up.
	 *
	 * @return string
	 */
	public static function filename() {
		$host = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		$host = sanitize_file_name( '' === $host ? 'site' : $host );

		return sprintf( 'jobs-sync-for-zoho-recruit-%s-%s.json', $host, gmdate( 'Y-m-d' ) );
	}

	/**
	 * Decode and import a migration file.
	 *
	 * @param string $json Raw file contents.
	 * @param array  $args See import().
	 * @return array|\WP_Error Report, or an error when nothing was imported.
	 */
	public static function import_json( $json, array $args = array() ) {
		$data = json_decode( (string) $json, true );

		if ( ! is_array( $data ) ) {
			return new \WP_Error( 'jszr_migration_json', __( 'The uploaded file is not valid JSON.', 'jobs-sync-for-zoho-recruit' ) );
		}

		return self::import( $data, $args );
	}

	/**
	 * Apply a migration payload to this site.
	 *
	 * @param array $data Decoded migration file.
	 * @param array $args {
	 *     Optional.
	 *
	 *     @type bool $replace_urls Rewrite links to the exported site's address
	 *                              into this site's address. Default true.
	 * }
	 * @return array|\WP_Error Report, or an error when nothing was imported.
	 */
	public static function import( array $data, array $args = array() ) {
		$args = wp_parse_args( $args, array( 'replace_urls' => true ) );

		if ( isset( $data['plugin'] ) && self::PLUGIN !== $data['plugin'] ) {
			return new \WP_Error( 'jszr_migration_plugin', __( 'This file was not exported by Jobs Sync for Zoho Recruit.', 'jobs-sync-for-zoho-recruit' ) );
		}

		$has_settings = isset( $data['settings'] ) && is_array( $data['settings'] );
		$has_mapping  = isset( $data['mapping'] ) && is_array( $data['mapping'] );
		$has_fields   = isset( $data['zoho_fields'] ) && is_array( $data['zoho_fields'] ) && ! empty( $data['zoho_fields'] );

		if ( ! $has_settings && ! $has_mapping && ! $has_fields ) {
			return new \WP_Error( 'jszr_migration_empty', __( 'The file holds no settings, mapping or field list to import.', 'jobs-sync-for-zoho-recruit' ) );
		}

		$report = array(
			'from'             => '',
			'to'               => untrailingslashit( home_url() ),
			'settings'         => 0,
			'templates'        => 0,
			'custom_css'       => false,
			'mapping_rows'     => 0,
			'fields'           => 0,
			'fields_kept'      => false,
			'urls_replaced'    => 0,
			'newer_export'     => false,
			'exported_version' => isset( $data['version'] ) ? sanitize_text_field( (string) $data['version'] ) : '',
		);

		if ( '' !== $report['exported_version'] && version_compare( $report['exported_version'], VERSION, '>' ) ) {
			$report['newer_export'] = true;
		}

		if ( $has_settings ) {
			$settings = $data['settings'];

			foreach ( self::SITE_ONLY_SETTINGS as $key ) {
				unset( $settings[ $key ] );
			}

			$from           = isset( $data['site_url'] ) ? untrailingslashit( esc_url_raw( (string) $data['site_url'] ) ) : '';
			$report['from'] = $from;

			if ( $args['replace_urls'] && '' !== $from ) {
				$replaced                = 0;
				$settings                = self::replace_site_url( $settings, $from, $report['to'], $replaced );
				$report['urls_replaced'] = $replaced;
			}

			$before = Settings::all();
			$clean  = Settings::sanitize( $settings );

			Settings::replace( $clean );
			Settings::flush_cache();

			$report['settings'] = count( array_intersect_key( $clean, $settings ) );

			$report['templates'] = count( (array) ( $clean['card_templates'] ?? array() ) )
				+ ( '' !== (string) ( $clean['card_template'] ?? '' ) ? 1 : 0 )
				+ ( '' !== (string) ( $clean['job_info_template'] ?? '' ) ? 1 : 0 );

			$report['custom_css'] = '' !== trim( (string) ( $clean['custom_css'] ?? '' ) );

			// On a site that never saved settings the option is added, not
			// updated, so the hooks that refresh permalinks and the cron
			// schedule never fire. Run them here either way.
			Post_Type::maybe_flush_rewrites( $before, Settings::all() );
			Cron::schedule();
		}

		if ( $has_mapping ) {
			Field_Mapper::save_mapping( $data['mapping'] );

			$report['mapping_rows'] = count( Field_Mapper::get_mapping() );
		}

		if ( $has_fields ) {
			$existing = get_option( Field_Metadata::OPTION_CACHE, array() );

			// A site that already loaded its own field list from Zoho keeps it:
			// that list is current, the file's is a copy from another site.
			if ( is_array( $existing ) && ! empty( $existing ) ) {
				$report['fields_kept'] = true;
			} else {
				$fields = self::sanitize_fields( $data['zoho_fields'] );

				if ( ! empty( $fields ) ) {
					update_option( Field_Metadata::OPTION_CACHE, $fields, false );
				}

				$report['fields'] = count( $fields );
			}
		}

		plugin()->sync()->invalidate_caches();

		/**
		 * Fires after a migration file has been imported.
		 *
		 * @param array $report What was imported.
		 * @param array $data   The decoded file.
		 */
		do_action( 'jszr_migration_imported', $report, $data );

		return $report;
	}

	/**
	 * Rewrite links to one site into links to another, in every string.
	 *
	 * Matches the old address with or without its scheme, and only up to a
	 * boundary, so `https://example.com` never rewrites part of
	 * `https://example.com.au` or `https://example.community`.
	 *
	 * @param mixed  $value    A setting value: string, array or scalar.
	 * @param string $from     Old site address, no trailing slash.
	 * @param string $to       New site address, no trailing slash.
	 * @param int    $replaced Incremented by the number of links rewritten.
	 * @return mixed
	 */
	public static function replace_site_url( $value, $from, $to, &$replaced = 0 ) {
		$from_rest = preg_replace( '#^[a-z][a-z0-9+.-]*:#i', '', untrailingslashit( (string) $from ) );
		$to        = untrailingslashit( (string) $to );
		$to_rest   = preg_replace( '#^[a-z][a-z0-9+.-]*:#i', '', $to );

		if ( '' === (string) $from_rest || 0 !== strpos( (string) $from_rest, '//' ) || $from_rest === $to_rest ) {
			return $value;
		}

		if ( is_array( $value ) ) {
			foreach ( $value as $key => $item ) {
				$value[ $key ] = self::replace_site_url( $item, $from, $to, $replaced );
			}

			return $value;
		}

		if ( ! is_string( $value ) || false === stripos( $value, substr( (string) $from_rest, 2 ) ) ) {
			return $value;
		}

		$pattern = '#(https?:)?' . preg_quote( (string) $from_rest, '#' ) . '(?=[/?\#"\'\s)<>,;]|$)#i';

		$result = preg_replace_callback(
			$pattern,
			static function ( $matches ) use ( $to, $to_rest ) {
				// A scheme-less link stays scheme-less.
				return '' === ( $matches[1] ?? '' ) ? $to_rest : $to;
			},
			$value,
			-1,
			$count
		);

		if ( ! is_string( $result ) ) {
			return $value;
		}

		$replaced += (int) $count;

		return $result;
	}

	/**
	 * Clean a Zoho field list from a file before it is stored.
	 *
	 * @param array $fields Field list keyed by API name.
	 * @return array
	 */
	public static function sanitize_fields( array $fields ) {
		$clean = array();

		foreach ( $fields as $key => $field ) {
			if ( count( $clean ) >= self::MAX_FIELDS ) {
				break;
			}

			if ( ! is_array( $field ) ) {
				continue;
			}

			$api_name = Settings::sanitize_api_name( $field['api_name'] ?? $key );

			if ( '' === $api_name ) {
				continue;
			}

			$options = array();

			foreach ( (array) ( $field['options'] ?? array() ) as $option ) {
				if ( is_scalar( $option ) ) {
					$options[] = sanitize_text_field( (string) $option );
				}
			}

			$clean[ $api_name ] = array(
				'api_name' => $api_name,
				'label'    => isset( $field['label'] ) && is_scalar( $field['label'] ) ? sanitize_text_field( (string) $field['label'] ) : $api_name,
				'type'     => isset( $field['type'] ) && is_scalar( $field['type'] ) ? sanitize_key( (string) $field['type'] ) : 'text',
				'options'  => $options,
			);
		}

		ksort( $clean );

		return $clean;
	}

	/**
	 * A one-paragraph summary of an import for a notice or the terminal.
	 *
	 * @param array $report Report from import().
	 * @return string
	 */
	public static function summarize( array $report ) {
		$parts = array();

		$parts[] = sprintf(
			/* translators: 1: number of settings, 2: number of HTML templates, 3: number of mapping rows. */
			__( 'Imported %1$d settings, %2$d HTML templates and %3$d field mapping rows.', 'jobs-sync-for-zoho-recruit' ),
			(int) $report['settings'],
			(int) $report['templates'],
			(int) $report['mapping_rows']
		);

		if ( ! empty( $report['custom_css'] ) ) {
			$parts[] = __( 'Custom CSS imported.', 'jobs-sync-for-zoho-recruit' );
		}

		if ( ! empty( $report['fields'] ) ) {
			$parts[] = sprintf(
				/* translators: %d: number of Zoho fields. */
				__( 'Zoho field list imported (%d fields).', 'jobs-sync-for-zoho-recruit' ),
				(int) $report['fields']
			);
		} elseif ( ! empty( $report['fields_kept'] ) ) {
			$parts[] = __( 'This site\'s own Zoho field list was kept.', 'jobs-sync-for-zoho-recruit' );
		}

		if ( ! empty( $report['urls_replaced'] ) ) {
			$parts[] = sprintf(
				/* translators: 1: number of links, 2: old site address, 3: new site address. */
				_n( '%1$d link to %2$s now points to %3$s.', '%1$d links to %2$s now point to %3$s.', (int) $report['urls_replaced'], 'jobs-sync-for-zoho-recruit' ),
				(int) $report['urls_replaced'],
				$report['from'],
				$report['to']
			);
		}

		if ( ! empty( $report['newer_export'] ) ) {
			$parts[] = sprintf(
				/* translators: %s: plugin version the file came from. */
				__( 'The file came from version %s, which is newer than this one; settings this version does not know were skipped.', 'jobs-sync-for-zoho-recruit' ),
				$report['exported_version']
			);
		}

		$parts[] = __( 'Zoho credentials are never included: connect this site to Zoho Recruit and run a sync to bring the jobs in.', 'jobs-sync-for-zoho-recruit' );

		return implode( ' ', $parts );
	}
}
