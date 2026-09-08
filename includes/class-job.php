<?php
/**
 * Job post reading and writing.
 *
 * @package JobsSyncForZohoRecruit
 */

namespace JobsSyncForZohoRecruit;

defined( 'ABSPATH' ) || exit;

/**
 * Everything that reads or writes a job post goes through this class, so the
 * "one Zoho record = one WordPress post" rule has exactly one enforcement point.
 */
class Job {

	/**
	 * Meta key holding the Zoho record ID.
	 */
	const META_ZOHO_ID = '_zoho_recruit_id';

	/**
	 * Meta key holding the plugin's normalised status.
	 */
	const META_STATUS = '_jszr_status';

	/**
	 * Meta key holding per-field hashes of the last synced values.
	 */
	const META_HASH = '_jszr_sync_hash';

	/**
	 * Meta key holding the raw Zoho payload.
	 */
	const META_RAW = '_zoho_recruit_raw_data';

	/**
	 * Meta key recording the last sync time.
	 */
	const META_LAST_SYNCED = '_zoho_recruit_last_synced';

	/**
	 * Meta key holding the flattened mapped values for the admin metabox.
	 */
	const META_MAPPED = '_jszr_mapped_values';

	/**
	 * Statuses considered "open for applications".
	 *
	 * @return string[]
	 */
	public static function active_statuses() {
		return array( 'active' );
	}

	/**
	 * Find the post ID for a Zoho record.
	 *
	 * @param string $zoho_id Zoho record ID.
	 * @return int 0 when not found.
	 */
	public static function find_by_zoho_id( $zoho_id ) {
		global $wpdb;

		$zoho_id = self::sanitize_zoho_id( $zoho_id );

		if ( '' === $zoho_id ) {
			return 0;
		}

		$cache_key = 'jszr_job_' . $zoho_id;
		$cached    = wp_cache_get( $cache_key, 'jszr' );

		if ( false !== $cached ) {
			$cached = (int) $cached;

			if ( $cached > 0 && get_post_type( $cached ) === Post_Type::POST_TYPE ) {
				return $cached;
			}

			wp_cache_delete( $cache_key, 'jszr' );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Result is cached below; meta_key is indexed.
		$post_id = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT pm.post_id FROM {$wpdb->postmeta} pm
				INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
				WHERE pm.meta_key = %s AND pm.meta_value = %s AND p.post_type = %s
				ORDER BY pm.post_id ASC LIMIT 1",
				self::META_ZOHO_ID,
				$zoho_id,
				Post_Type::POST_TYPE
			)
		);

		if ( $post_id > 0 ) {
			wp_cache_set( $cache_key, $post_id, 'jszr', HOUR_IN_SECONDS );
		}

		return $post_id;
	}

	/**
	 * Normalise a Zoho record ID.
	 *
	 * @param mixed $zoho_id Raw ID.
	 * @return string
	 */
	public static function sanitize_zoho_id( $zoho_id ) {
		$zoho_id = is_scalar( $zoho_id ) ? (string) $zoho_id : '';
		$zoho_id = preg_replace( '/[^0-9A-Za-z]/', '', $zoho_id );

		return is_string( $zoho_id ) ? substr( $zoho_id, 0, 64 ) : '';
	}

	/**
	 * Create or update the job for a Zoho record.
	 *
	 * The `$context` array carries `record` (the raw Zoho record), `status` (the
	 * normalised job status) and `dry_run` (when true nothing is written).
	 *
	 * @param string $zoho_id Zoho record ID.
	 * @param array  $payload Mapped payload from Field_Mapper::map().
	 * @param array  $context Sync context, described above.
	 * @return array|\WP_Error Array with `post_id` and `action`
	 *                         (created|updated|unchanged), or an error.
	 */
	public static function upsert( $zoho_id, array $payload, array $context = array() ) {
		$zoho_id = self::sanitize_zoho_id( $zoho_id );

		if ( '' === $zoho_id ) {
			return new \WP_Error( 'jszr_missing_zoho_id', __( 'The Zoho record has no usable ID.', 'jobs-sync-for-zoho-recruit' ) );
		}

		$record  = isset( $context['record'] ) && is_array( $context['record'] ) ? $context['record'] : array();
		$status  = isset( $context['status'] ) ? (string) $context['status'] : 'active';
		$dry_run = ! empty( $context['dry_run'] );
		$force   = ! empty( $context['force'] );

		$post_id = self::find_by_zoho_id( $zoho_id );

		if ( $dry_run ) {
			return array(
				'post_id' => $post_id,
				'action'  => $post_id ? 'updated' : 'created',
			);
		}

		// A named lock keeps two concurrent workers from creating the same job.
		$lock_key = 'jszr_upsert_' . $zoho_id;

		if ( ! $post_id ) {
			if ( ! self::acquire_lock( $lock_key ) ) {
				// Another worker is creating it; re-read after a short pause.
				usleep( 250000 );
				$post_id = self::find_by_zoho_id( $zoho_id );

				if ( ! $post_id ) {
					return new \WP_Error( 'jszr_locked', __( 'Another sync process is already importing this job.', 'jobs-sync-for-zoho-recruit' ) );
				}
			}
		}

		try {
			$mode = (string) Settings::get( 'conflict_mode', 'mapped_only' );

			// A forced reset is the administrator saying "discard my edits and
			// take Zoho's values". It overrides preserve mode for this write
			// only, and stops short of overwrite_all so unmapped meta the site
			// added for its own purposes still survives.
			if ( $force && 'preserve_manual' === $mode ) {
				$mode = 'mapped_only';
			}

			$hashes    = $post_id ? (array) get_post_meta( $post_id, self::META_HASH, true ) : array();
			$is_create = ! $post_id;

			$post_args = array(
				'post_type'   => Post_Type::POST_TYPE,
				'post_status' => self::post_status_for( $status ),
			);

			foreach ( array( 'post_title', 'post_content', 'post_excerpt' ) as $field ) {
				if ( ! array_key_exists( $field, $payload['post'] ) ) {
					continue;
				}

				$value = (string) $payload['post'][ $field ];

				if ( ! $is_create && 'preserve_manual' === $mode && self::was_edited_manually( $post_id, $field, $hashes ) ) {
					continue;
				}

				$post_args[ $field ] = $value;
			}

			if ( $is_create ) {
				$post_args['post_author'] = self::author_id();

				$post_id = wp_insert_post( wp_slash( $post_args ), true );

				if ( is_wp_error( $post_id ) ) {
					return $post_id;
				}

				$post_id = (int) $post_id;

				update_post_meta( $post_id, self::META_ZOHO_ID, $zoho_id );
				wp_cache_set( 'jszr_job_' . $zoho_id, $post_id, 'jszr', HOUR_IN_SECONDS );
			} else {
				$post_args['ID'] = $post_id;

				$current_status = get_post_status( $post_id );

				// Never resurrect a trashed job silently; leave the admin in control.
				if ( 'trash' === $current_status && 'trash' !== $post_args['post_status'] ) {
					unset( $post_args['post_status'] );
				}

				$result = wp_update_post( wp_slash( $post_args ), true );

				if ( is_wp_error( $result ) ) {
					return $result;
				}
			}

			self::write_meta( $post_id, $payload, $mode, $hashes, $is_create );
			self::write_terms( $post_id, $payload, $mode, $hashes, $is_create );

			update_post_meta( $post_id, self::META_STATUS, sanitize_key( $status ) );
			update_post_meta( $post_id, self::META_LAST_SYNCED, current_time( 'mysql', true ) );

			if ( Settings::get( 'store_raw', true ) && ! empty( $record ) ) {
				update_post_meta( $post_id, self::META_RAW, wp_slash( (string) wp_json_encode( $record ) ) );
			} elseif ( ! Settings::get( 'store_raw', true ) ) {
				delete_post_meta( $post_id, self::META_RAW );
			}

			update_post_meta( $post_id, self::META_MAPPED, $payload['mapped'] );
			update_post_meta( $post_id, self::META_HASH, self::build_hashes( $payload ) );

			return array(
				'post_id' => $post_id,
				'action'  => $is_create ? 'created' : 'updated',
			);
		} finally {
			self::release_lock( $lock_key );
		}
	}

	/**
	 * Write mapped meta values.
	 *
	 * @param int    $post_id   Post ID.
	 * @param array  $payload   Mapped payload.
	 * @param string $mode     Conflict mode.
	 * @param array  $hashes    Stored hashes.
	 * @param bool   $is_create Whether the post was just created.
	 * @return void
	 */
	private static function write_meta( $post_id, array $payload, $mode, array $hashes, $is_create ) {
		$meta = isset( $payload['meta'] ) && is_array( $payload['meta'] ) ? $payload['meta'] : array();

		foreach ( $meta as $key => $value ) {
			$key = (string) $key;

			if ( '' === $key || 0 !== strpos( $key, '_' ) ) {
				continue;
			}

			if ( ! $is_create && 'preserve_manual' === $mode && self::was_edited_manually( $post_id, 'meta:' . $key, $hashes ) ) {
				continue;
			}

			if ( is_array( $value ) ) {
				$value = implode( ', ', array_map( 'strval', $value ) );
			}

			update_post_meta( $post_id, $key, is_string( $value ) ? wp_slash( $value ) : $value );
		}

		if ( 'overwrite_all' === $mode ) {
			// Clear plugin meta that is no longer produced by the mapping.
			foreach ( array_keys( Post_Type::meta_keys() ) as $known_key ) {
				if ( self::META_ZOHO_ID === $known_key || self::META_STATUS === $known_key || self::META_LAST_SYNCED === $known_key ) {
					continue;
				}

				if ( ! array_key_exists( $known_key, $meta ) ) {
					delete_post_meta( $post_id, $known_key );
				}
			}
		}
	}

	/**
	 * Assign taxonomy terms, creating hierarchical paths where needed.
	 *
	 * @param int    $post_id   Post ID.
	 * @param array  $payload   Mapped payload.
	 * @param string $mode      Conflict mode.
	 * @param array  $hashes    Stored hashes.
	 * @param bool   $is_create Whether the post was just created.
	 * @return void
	 */
	private static function write_terms( $post_id, array $payload, $mode, array $hashes, $is_create ) {
		$terms = isset( $payload['terms'] ) && is_array( $payload['terms'] ) ? $payload['terms'] : array();

		foreach ( $terms as $taxonomy => $values ) {
			if ( ! taxonomy_exists( $taxonomy ) ) {
				continue;
			}

			if ( ! $is_create && 'preserve_manual' === $mode && self::was_edited_manually( $post_id, 'tax:' . $taxonomy, $hashes ) ) {
				continue;
			}

			$term_ids = array();

			foreach ( (array) $values as $value ) {
				$term_id = is_array( $value )
					? self::ensure_term_path( $value, $taxonomy )
					: self::ensure_term( (string) $value, $taxonomy );

				if ( $term_id > 0 ) {
					$term_ids[] = $term_id;
				}
			}

			if ( ! empty( $term_ids ) ) {
				wp_set_object_terms( $post_id, $term_ids, $taxonomy, false );
			}
		}
	}

	/**
	 * Find or create a single term.
	 *
	 * @param string $name      Term name.
	 * @param string $taxonomy  Taxonomy.
	 * @param int    $parent_id Parent term ID.
	 * @return int Term ID, or 0 on failure.
	 */
	public static function ensure_term( $name, $taxonomy, $parent_id = 0 ) {
		$name      = trim( wp_strip_all_tags( (string) $name ) );
		$parent_id = (int) $parent_id;

		if ( '' === $name || ! taxonomy_exists( $taxonomy ) ) {
			return 0;
		}

		$existing = get_term_by( 'name', $name, $taxonomy );

		if ( $existing instanceof \WP_Term ) {
			if ( 0 === $parent_id || (int) $existing->parent === $parent_id ) {
				return (int) $existing->term_id;
			}
		}

		$args = array();

		if ( $parent_id > 0 && is_taxonomy_hierarchical( $taxonomy ) ) {
			$args['parent'] = $parent_id;
		}

		$result = wp_insert_term( $name, $taxonomy, $args );

		if ( is_wp_error( $result ) ) {
			$existing_id = $result->get_error_data( 'term_exists' );

			return is_numeric( $existing_id ) ? (int) $existing_id : 0;
		}

		return isset( $result['term_id'] ) ? (int) $result['term_id'] : 0;
	}

	/**
	 * Find or create a hierarchical path of terms, returning the deepest one.
	 *
	 * @param array  $path     Ordered term names, outermost first.
	 * @param string $taxonomy Taxonomy.
	 * @return int
	 */
	public static function ensure_term_path( array $path, $taxonomy ) {
		$parent = 0;
		$leaf   = 0;

		foreach ( $path as $name ) {
			$term_id = self::ensure_term( (string) $name, $taxonomy, $parent );

			if ( 0 === $term_id ) {
				continue;
			}

			$parent = $term_id;
			$leaf   = $term_id;
		}

		return $leaf;
	}

	/**
	 * Whether a field was changed in WordPress since the last sync.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $target  Mapping target key.
	 * @param array  $hashes  Stored hashes.
	 * @return bool
	 */
	private static function was_edited_manually( $post_id, $target, array $hashes ) {
		if ( ! isset( $hashes[ $target ] ) ) {
			return false;
		}

		$current = self::current_value( $post_id, $target );

		return self::hash( $current ) !== (string) $hashes[ $target ];
	}

	/**
	 * Read the current WordPress value for a mapping target.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $target  Mapping target key.
	 * @return mixed
	 */
	private static function current_value( $post_id, $target ) {
		if ( 0 === strpos( $target, 'meta:' ) ) {
			return get_post_meta( $post_id, substr( $target, 5 ), true );
		}

		if ( 0 === strpos( $target, 'tax:' ) ) {
			$terms = wp_get_object_terms( $post_id, substr( $target, 4 ), array( 'fields' => 'names' ) );

			return is_wp_error( $terms ) ? array() : $terms;
		}

		$post = get_post( $post_id );

		if ( ! $post instanceof \WP_Post ) {
			return '';
		}

		switch ( $target ) {
			case 'post_title':
				return $post->post_title;
			case 'post_content':
				return $post->post_content;
			case 'post_excerpt':
				return $post->post_excerpt;
		}

		return '';
	}

	/**
	 * Build the per-target hash map for a payload.
	 *
	 * @param array $payload Mapped payload.
	 * @return array<string,string>
	 */
	private static function build_hashes( array $payload ) {
		$hashes = array();

		foreach ( array( 'post_title', 'post_content', 'post_excerpt' ) as $field ) {
			if ( isset( $payload['post'][ $field ] ) ) {
				$hashes[ $field ] = self::hash( $payload['post'][ $field ] );
			}
		}

		foreach ( (array) ( $payload['meta'] ?? array() ) as $key => $value ) {
			$hashes[ 'meta:' . $key ] = self::hash( $value );
		}

		foreach ( (array) ( $payload['terms'] ?? array() ) as $taxonomy => $values ) {
			$names = array();

			foreach ( (array) $values as $value ) {
				if ( is_array( $value ) ) {
					$names[] = (string) end( $value );
				} else {
					$names[] = (string) $value;
				}
			}

			$hashes[ 'tax:' . $taxonomy ] = self::hash( $names );
		}

		return $hashes;
	}

	/**
	 * Stable hash for a mapped value.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	private static function hash( $value ) {
		if ( is_array( $value ) ) {
			$value = array_map( 'strval', $value );
			sort( $value );
			$value = implode( '|', $value );
		}

		return md5( trim( (string) $value ) );
	}

	/**
	 * The WordPress post status for a normalised job status.
	 *
	 * @param string $status Normalised status.
	 * @return string
	 */
	public static function post_status_for( $status ) {
		if ( in_array( $status, self::active_statuses(), true ) ) {
			return 'publish';
		}

		if ( 'draft' === $status ) {
			return 'draft';
		}

		$action = (string) Settings::get( 'expire_action', 'inactive' );

		$map = array(
			'inactive' => 'publish',
			'draft'    => 'draft',
			'private'  => 'private',
			'trash'    => 'trash',
		);

		$post_status = isset( $map[ $action ] ) ? $map[ $action ] : 'publish';

		/**
		 * Filter the WordPress post status used for a normalised job status.
		 *
		 * @param string $post_status WordPress post status.
		 * @param string $status      Normalised job status.
		 */
		return (string) apply_filters( 'jszr_post_status_for_job_status', $post_status, $status );
	}

	/**
	 * Author assigned to imported jobs.
	 *
	 * @return int
	 */
	private static function author_id() {
		$user_id = get_current_user_id();

		if ( $user_id > 0 ) {
			return $user_id;
		}

		$admins = get_users(
			array(
				'role'    => 'administrator',
				'number'  => 1,
				'fields'  => 'ID',
				'orderby' => 'ID',
			)
		);

		return ! empty( $admins ) ? (int) $admins[0] : 0;
	}

	// ----------------------------------------------------------------------
	// Status transitions
	// ----------------------------------------------------------------------

	/**
	 * Mark a job inactive.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $reason  Machine-readable reason.
	 * @return bool Whether anything changed.
	 */
	public static function deactivate( $post_id, $reason = 'missing' ) {
		$post_id = (int) $post_id;

		if ( self::is_manual( $post_id ) ) {
			return false;
		}

		$current = (string) get_post_meta( $post_id, self::META_STATUS, true );

		if ( 'inactive' === $current ) {
			return false;
		}

		update_post_meta( $post_id, self::META_STATUS, 'inactive' );
		self::apply_post_status( $post_id, 'inactive' );

		/**
		 * Fires after a job has been deactivated.
		 *
		 * @param int    $post_id Post ID.
		 * @param string $reason  Reason code.
		 */
		do_action( 'jszr_job_deactivated', $post_id, $reason );

		return true;
	}

	/**
	 * Mark a job expired.
	 *
	 * @param int $post_id Post ID.
	 * @return bool Whether anything changed.
	 */
	public static function expire( $post_id ) {
		$post_id = (int) $post_id;

		if ( self::is_manual( $post_id ) ) {
			return false;
		}

		$current = (string) get_post_meta( $post_id, self::META_STATUS, true );

		if ( 'expired' === $current ) {
			return false;
		}

		update_post_meta( $post_id, self::META_STATUS, 'expired' );
		self::apply_post_status( $post_id, 'expired' );

		/**
		 * Fires after a job has been marked expired.
		 *
		 * @param int $post_id Post ID.
		 */
		do_action( 'jszr_job_expired', $post_id );

		return true;
	}

	/**
	 * Apply the configured orphan action to a job deleted in Zoho.
	 *
	 * @param int $post_id Post ID.
	 * @return string Action taken.
	 */
	public static function orphan( $post_id ) {
		$post_id = (int) $post_id;

		if ( self::is_manual( $post_id ) ) {
			return 'skipped';
		}

		$action = (string) Settings::get( 'orphan_action', 'draft' );

		switch ( $action ) {
			case 'none':
				return 'none';

			case 'trash':
				wp_trash_post( $post_id );
				break;

			case 'delete':
				wp_delete_post( $post_id, true );
				break;

			case 'private':
				wp_update_post(
					array(
						'ID'          => $post_id,
						'post_status' => 'private',
					)
				);
				update_post_meta( $post_id, self::META_STATUS, 'inactive' );
				break;

			case 'draft':
			default:
				wp_update_post(
					array(
						'ID'          => $post_id,
						'post_status' => 'draft',
					)
				);
				update_post_meta( $post_id, self::META_STATUS, 'inactive' );
				$action = 'draft';
				break;
		}

		/**
		 * Fires after a job removed from Zoho has been handled locally.
		 *
		 * @param int    $post_id Post ID.
		 * @param string $action  Action applied.
		 */
		do_action( 'jszr_job_orphaned', $post_id, $action );

		return $action;
	}

	/**
	 * Set the WordPress post status to match a job status.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $status  Normalised job status.
	 * @return void
	 */
	private static function apply_post_status( $post_id, $status ) {
		$target  = self::post_status_for( $status );
		$current = get_post_status( $post_id );

		if ( $current === $target || 'trash' === $current ) {
			return;
		}

		if ( 'trash' === $target ) {
			wp_trash_post( $post_id );

			return;
		}

		wp_update_post(
			array(
				'ID'          => $post_id,
				'post_status' => $target,
			)
		);
	}

	// ----------------------------------------------------------------------
	// Readers
	// ----------------------------------------------------------------------

	/**
	 * Whether a job was created by hand rather than imported.
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	public static function is_manual( $post_id ) {
		return '' === (string) get_post_meta( (int) $post_id, self::META_ZOHO_ID, true );
	}

	/**
	 * Read one of the plugin's meta values.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $key     Meta key, with or without the plugin prefix.
	 * @return mixed
	 */
	public static function get_meta( $post_id, $key ) {
		$key = (string) $key;

		if ( 0 !== strpos( $key, '_' ) ) {
			$candidates = array( '_zoho_recruit_' . $key, '_jszr_' . $key );

			foreach ( $candidates as $candidate ) {
				$value = get_post_meta( (int) $post_id, $candidate, true );

				if ( '' !== $value && null !== $value ) {
					return $value;
				}
			}

			return '';
		}

		return get_post_meta( (int) $post_id, $key, true );
	}

	/**
	 * Normalised status of a job.
	 *
	 * @param int $post_id Post ID.
	 * @return string
	 */
	public static function get_status( $post_id ) {
		$status = (string) get_post_meta( (int) $post_id, self::META_STATUS, true );

		return '' === $status ? 'active' : $status;
	}

	/**
	 * Whether a job is open for applications.
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	public static function is_active( $post_id ) {
		$post_id = (int) $post_id;

		if ( ! in_array( self::get_status( $post_id ), self::active_statuses(), true ) ) {
			return false;
		}

		$closing = self::closing_timestamp( $post_id );

		if ( null !== $closing && $closing < time() ) {
			return false;
		}

		return true;
	}

	/**
	 * The closing date of a job as a UTC timestamp.
	 *
	 * The stored value is a date in the site timezone; the job stays open for
	 * the whole of its closing day.
	 *
	 * @param int $post_id Post ID.
	 * @return int|null
	 */
	public static function closing_timestamp( $post_id ) {
		$closing = (string) get_post_meta( (int) $post_id, '_zoho_recruit_closing_date', true );

		if ( '' === $closing ) {
			return null;
		}

		try {
			if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $closing ) ) {
				$date = new \DateTimeImmutable( $closing . ' 23:59:59', wp_timezone() );
			} else {
				$date = new \DateTimeImmutable( $closing );
			}
		} catch ( \Exception $e ) {
			return null;
		}

		return $date->getTimestamp();
	}

	/**
	 * Resolve the application URL for a job.
	 *
	 * @param int $post_id Post ID.
	 * @return string
	 */
	public static function get_apply_url( $post_id ) {
		$post_id = (int) $post_id;
		$url     = (string) get_post_meta( $post_id, '_zoho_recruit_application_url', true );

		if ( '' === $url ) {
			$template = (string) Settings::get( 'apply_url_template', '' );

			if ( '' !== $template ) {
				$post = get_post( $post_id );

				$url = strtr(
					$template,
					array(
						'{zoho_id}'  => rawurlencode( (string) get_post_meta( $post_id, self::META_ZOHO_ID, true ) ),
						'{job_code}' => rawurlencode( (string) get_post_meta( $post_id, '_jszr_job_code', true ) ),
						'{slug}'     => $post instanceof \WP_Post ? rawurlencode( $post->post_name ) : '',
						'{id}'       => (string) $post_id,
					)
				);
			}
		}

		if ( '' === $url ) {
			$url = self::career_site_url( $post_id );
		}

		$url = esc_url_raw( $url );

		if ( '' !== $url ) {
			$utm = trim( (string) Settings::get( 'apply_utm', '' ) );

			if ( '' !== $utm ) {
				$params = array();
				wp_parse_str( ltrim( $utm, '?&' ), $params );

				if ( ! empty( $params ) ) {
					$url = add_query_arg( array_map( 'rawurlencode', array_map( 'strval', $params ) ), $url );
				}
			}
		}

		/**
		 * Filter the application URL for a job.
		 *
		 * @param string $url     Application URL, possibly empty.
		 * @param int    $post_id Post ID.
		 */
		return (string) apply_filters( 'jszr_apply_url', $url, $post_id );
	}

	/**
	 * Build the career-site application URL for a job.
	 *
	 * The Job Openings API does not return a link to the public posting. There
	 * is no field for it: `Website` on a job opening is the client's own site,
	 * and is usually empty, so a site that maps it gets no apply button at all.
	 * The career site does have a stable address, though, and it is built from
	 * the record ID the sync already stores:
	 *
	 *     https://<your-org>.zohorecruit.com/jobs/Careers/<record id>/<title>
	 *
	 * The title segment is decoration -- Zoho serves the same posting with the
	 * wrong one or with none at all -- so it is included only to keep the link
	 * readable when a candidate shares it, and never relied on.
	 *
	 * @param int $post_id Post ID.
	 * @return string Empty when no career site is configured or the job is not from Zoho.
	 */
	public static function career_site_url( $post_id ) {
		$base = trim( (string) Settings::get( 'career_site_url', '' ) );

		if ( '' === $base ) {
			return '';
		}

		$zoho_id = (string) get_post_meta( (int) $post_id, self::META_ZOHO_ID, true );

		// A job added by hand has no Zoho record to link to.
		if ( '' === $zoho_id ) {
			return '';
		}

		$url = rtrim( $base, '/' ) . '/jobs/Careers/' . rawurlencode( $zoho_id );

		$title = sanitize_title( get_the_title( (int) $post_id ) );

		if ( '' !== $title ) {
			$url .= '/' . $title;
		}

		return $url;
	}

	/**
	 * Link to the record in the Zoho Recruit UI.
	 *
	 * @param int $post_id Post ID.
	 * @return string
	 */
	public static function get_zoho_link( $post_id ) {
		$zoho_id = (string) get_post_meta( (int) $post_id, self::META_ZOHO_ID, true );

		if ( '' === $zoho_id ) {
			return '';
		}

		return sprintf(
			'%s/recruit/EntityInfo.do?module=Job%%20Openings&id=%s',
			untrailingslashit( Settings::api_url() ),
			rawurlencode( $zoho_id )
		);
	}

	/**
	 * Count jobs by normalised status.
	 *
	 * @return array<string,int>
	 */
	public static function counts() {
		global $wpdb;

		$cached = wp_cache_get( 'jszr_job_counts', 'jszr' );

		if ( is_array( $cached ) ) {
			return $cached;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Aggregate cached below.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT pm.meta_value AS status, COUNT(*) AS total
				FROM {$wpdb->postmeta} pm
				INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
				WHERE pm.meta_key = %s AND p.post_type = %s AND p.post_status != 'trash'
				GROUP BY pm.meta_value",
				self::META_STATUS,
				Post_Type::POST_TYPE
			)
		);

		$counts = array(
			'total'    => 0,
			'active'   => 0,
			'inactive' => 0,
			'expired'  => 0,
			'closed'   => 0,
			'draft'    => 0,
		);

		foreach ( (array) $rows as $row ) {
			$status = sanitize_key( (string) $row->status );
			$total  = (int) $row->total;

			if ( ! isset( $counts[ $status ] ) ) {
				$counts[ $status ] = 0;
			}

			$counts[ $status ] += $total;
			$counts['total']   += $total;
		}

		wp_cache_set( 'jszr_job_counts', $counts, 'jszr', 5 * MINUTE_IN_SECONDS );

		return $counts;
	}

	// ----------------------------------------------------------------------
	// Locking
	// ----------------------------------------------------------------------

	/**
	 * Acquire a short-lived named lock.
	 *
	 * @param string $key Lock key.
	 * @return bool
	 */
	private static function acquire_lock( $key ) {
		if ( get_transient( $key ) ) {
			return false;
		}

		set_transient( $key, 1, 30 );

		return true;
	}

	/**
	 * Release a named lock.
	 *
	 * @param string $key Lock key.
	 * @return void
	 */
	private static function release_lock( $key ) {
		delete_transient( $key );
	}
}
