<?php
/**
 * Zoho record to WordPress payload translation.
 *
 * @package JobsSyncForZohoRecruit
 */

namespace JobsSyncForZohoRecruit;

defined( 'ABSPATH' ) || exit;

/**
 * Turns a raw Zoho job opening into the post fields, meta and terms that the
 * Job writer consumes.
 *
 * Nothing here touches the database; the mapper is pure translation, which
 * makes it straightforward to unit test.
 */
class Field_Mapper {

	/**
	 * Option holding the administrator's mapping.
	 */
	const OPTION = 'jszr_field_mapping';

	/**
	 * Runtime cache for the resolved mapping.
	 *
	 * @var array|null
	 */
	private static $cache = null;

	/**
	 * Available transforms.
	 *
	 * @return array<string,string> Key => label.
	 */
	public static function transforms() {
		return array(
			'text'        => __( 'Plain text', 'jobs-sync-for-zoho-recruit' ),
			'html'        => __( 'HTML (sanitized)', 'jobs-sync-for-zoho-recruit' ),
			'autop'       => __( 'Plain text with paragraphs', 'jobs-sync-for-zoho-recruit' ),
			'date'        => __( 'Date (Y-m-d)', 'jobs-sync-for-zoho-recruit' ),
			'datetime'    => __( 'Date and time', 'jobs-sync-for-zoho-recruit' ),
			'number'      => __( 'Number', 'jobs-sync-for-zoho-recruit' ),
			'integer'     => __( 'Whole number', 'jobs-sync-for-zoho-recruit' ),
			'boolean'     => __( 'Yes / No', 'jobs-sync-for-zoho-recruit' ),
			'join'        => __( 'Join list with commas', 'jobs-sync-for-zoho-recruit' ),
			'lookup_name' => __( 'Use lookup name', 'jobs-sync-for-zoho-recruit' ),
			'url'         => __( 'URL', 'jobs-sync-for-zoho-recruit' ),
		);
	}

	/**
	 * Targets a Zoho field can be mapped to.
	 *
	 * @return array<string,string> Target key => label.
	 */
	public static function targets() {
		$targets = array(
			'post_title'   => __( 'Post: Title', 'jobs-sync-for-zoho-recruit' ),
			'post_content' => __( 'Post: Description', 'jobs-sync-for-zoho-recruit' ),
			'post_excerpt' => __( 'Post: Excerpt', 'jobs-sync-for-zoho-recruit' ),
		);

		foreach ( Post_Type::taxonomies() as $taxonomy => $config ) {
			$targets[ 'tax:' . $taxonomy ] = sprintf(
				/* translators: %s: taxonomy label. */
				__( 'Taxonomy: %s', 'jobs-sync-for-zoho-recruit' ),
				$config['label']
			);
		}

		$meta_labels = array(
			'_jszr_job_code'                => __( 'Job code', 'jobs-sync-for-zoho-recruit' ),
			'_zoho_recruit_status'          => __( 'Zoho status', 'jobs-sync-for-zoho-recruit' ),
			'_zoho_recruit_city'            => __( 'City', 'jobs-sync-for-zoho-recruit' ),
			'_zoho_recruit_state'           => __( 'State', 'jobs-sync-for-zoho-recruit' ),
			'_zoho_recruit_country'         => __( 'Country', 'jobs-sync-for-zoho-recruit' ),
			'_zoho_recruit_remote'          => __( 'Remote / work mode', 'jobs-sync-for-zoho-recruit' ),
			'_zoho_recruit_industry'        => __( 'Industry', 'jobs-sync-for-zoho-recruit' ),
			'_zoho_recruit_client'          => __( 'Client', 'jobs-sync-for-zoho-recruit' ),
			'_zoho_recruit_positions'       => __( 'Number of positions', 'jobs-sync-for-zoho-recruit' ),
			'_zoho_recruit_salary'          => __( 'Salary', 'jobs-sync-for-zoho-recruit' ),
			'_zoho_recruit_posted_date'     => __( 'Posted date', 'jobs-sync-for-zoho-recruit' ),
			'_zoho_recruit_closing_date'    => __( 'Closing date', 'jobs-sync-for-zoho-recruit' ),
			'_zoho_recruit_created_time'    => __( 'Created time', 'jobs-sync-for-zoho-recruit' ),
			'_zoho_recruit_modified_time'   => __( 'Modified time', 'jobs-sync-for-zoho-recruit' ),
			'_zoho_recruit_application_url' => __( 'Application URL', 'jobs-sync-for-zoho-recruit' ),
			'_zoho_recruit_source_url'      => __( 'Source URL', 'jobs-sync-for-zoho-recruit' ),
		);

		foreach ( $meta_labels as $key => $label ) {
			$targets[ 'meta:' . $key ] = sprintf(
				/* translators: %s: meta field label. */
				__( 'Meta: %s', 'jobs-sync-for-zoho-recruit' ),
				$label
			);
		}

		/**
		 * Filter the available mapping targets.
		 *
		 * @param array $targets Target key => label.
		 */
		return (array) apply_filters( 'jszr_mapping_targets', $targets );
	}

	/**
	 * The default mapping shipped with the plugin.
	 *
	 * @return array<int,array{zoho_field:string,target:string,transform:string}>
	 */
	public static function default_mapping() {
		$mapping = array(
			array(
				'zoho_field' => 'Posting_Title',
				'target'     => 'post_title',
				'transform'  => 'text',
			),
			array(
				'zoho_field' => 'Job_Description',
				'target'     => 'post_content',
				'transform'  => 'html',
			),
			array(
				'zoho_field' => 'Job_Summary',
				'target'     => 'post_excerpt',
				'transform'  => 'text',
			),
			array(
				'zoho_field' => 'Job_Opening_ID',
				'target'     => 'meta:_jszr_job_code',
				'transform'  => 'text',
			),
			array(
				'zoho_field' => 'Job_Opening_Status',
				'target'     => 'meta:_zoho_recruit_status',
				'transform'  => 'text',
			),
			array(
				'zoho_field' => 'Department_Name',
				'target'     => 'tax:' . Post_Type::TAX_DEPARTMENT,
				'transform'  => 'lookup_name',
			),
			array(
				'zoho_field' => 'Job_Type',
				'target'     => 'tax:' . Post_Type::TAX_EMPLOYMENT_TYPE,
				'transform'  => 'text',
			),
			array(
				'zoho_field' => 'Work_Experience',
				'target'     => 'tax:' . Post_Type::TAX_EXPERIENCE,
				'transform'  => 'text',
			),
			array(
				'zoho_field' => 'Industry',
				'target'     => 'meta:_zoho_recruit_industry',
				'transform'  => 'text',
			),
			array(
				'zoho_field' => 'City',
				'target'     => 'meta:_zoho_recruit_city',
				'transform'  => 'text',
			),
			array(
				'zoho_field' => 'State',
				'target'     => 'meta:_zoho_recruit_state',
				'transform'  => 'text',
			),
			array(
				'zoho_field' => 'Country',
				'target'     => 'meta:_zoho_recruit_country',
				'transform'  => 'text',
			),
			array(
				'zoho_field' => 'Remote_Job',
				'target'     => 'meta:_zoho_recruit_remote',
				'transform'  => 'boolean',
			),
			array(
				'zoho_field' => 'Salary',
				'target'     => 'meta:_zoho_recruit_salary',
				'transform'  => 'text',
			),
			array(
				'zoho_field' => 'Number_of_Positions',
				'target'     => 'meta:_zoho_recruit_positions',
				'transform'  => 'integer',
			),
			array(
				'zoho_field' => 'Date_Opened',
				'target'     => 'meta:_zoho_recruit_posted_date',
				'transform'  => 'date',
			),
			array(
				'zoho_field' => 'Expected_Closing_Date',
				'target'     => 'meta:_zoho_recruit_closing_date',
				'transform'  => 'date',
			),
			array(
				'zoho_field' => 'Created_Time',
				'target'     => 'meta:_zoho_recruit_created_time',
				'transform'  => 'datetime',
			),
			array(
				'zoho_field' => 'Modified_Time',
				'target'     => 'meta:_zoho_recruit_modified_time',
				'transform'  => 'datetime',
			),
			array(
				'zoho_field' => 'Client_Name',
				'target'     => 'meta:_zoho_recruit_client',
				'transform'  => 'lookup_name',
			),
			array(
				'zoho_field' => 'Website',
				'target'     => 'meta:_zoho_recruit_application_url',
				'transform'  => 'url',
			),
		);

		/**
		 * Filter the default field mapping.
		 *
		 * @param array $mapping Mapping rows.
		 */
		return (array) apply_filters( 'jszr_default_field_mapping', $mapping );
	}

	/**
	 * Get the effective mapping.
	 *
	 * @return array<int,array{zoho_field:string,target:string,transform:string}>
	 */
	public static function get_mapping() {
		if ( null === self::$cache ) {
			$stored = get_option( self::OPTION, null );

			self::$cache = is_array( $stored ) && ! empty( $stored )
				? self::sanitize_mapping( $stored )
				: self::default_mapping();
		}

		/**
		 * Filter the active field mapping.
		 *
		 * @param array $mapping Mapping rows.
		 */
		return (array) apply_filters( 'jszr_field_mapping', self::$cache );
	}

	/**
	 * Persist a mapping.
	 *
	 * @param array $mapping Mapping rows.
	 * @return bool
	 */
	public static function save_mapping( array $mapping ) {
		$clean       = self::sanitize_mapping( $mapping );
		self::$cache = $clean;

		return update_option( self::OPTION, $clean, false );
	}

	/**
	 * Restore the shipped default mapping.
	 *
	 * @return void
	 */
	public static function reset_mapping() {
		delete_option( self::OPTION );
		self::$cache = null;
	}

	/**
	 * Clear the runtime cache.
	 *
	 * @return void
	 */
	public static function flush_cache() {
		self::$cache = null;
	}

	/**
	 * Validate and normalise mapping rows.
	 *
	 * @param array $mapping Raw rows.
	 * @return array
	 */
	public static function sanitize_mapping( $mapping ) {
		$mapping    = is_array( $mapping ) ? $mapping : array();
		$targets    = self::targets();
		$transforms = self::transforms();
		$clean      = array();
		$seen       = array();

		foreach ( $mapping as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$field  = Settings::sanitize_api_name( $row['zoho_field'] ?? '' );
			$target = isset( $row['target'] ) ? (string) $row['target'] : '';
			$target = preg_replace( '/[^a-z0-9_:]/i', '', $target );

			if ( '' === $field || ! isset( $targets[ $target ] ) ) {
				continue;
			}

			$transform = isset( $row['transform'] ) ? sanitize_key( (string) $row['transform'] ) : 'text';

			if ( ! isset( $transforms[ $transform ] ) ) {
				$transform = 'text';
			}

			$signature = $field . '|' . $target;

			if ( isset( $seen[ $signature ] ) ) {
				continue;
			}

			$seen[ $signature ] = true;

			$clean[] = array(
				'zoho_field' => $field,
				'target'     => $target,
				'transform'  => $transform,
			);
		}

		return $clean;
	}

	/**
	 * Translate a Zoho record into a WordPress payload.
	 *
	 * @param array $record Raw Zoho record.
	 * @return array {
	 *     @type array $post  Post fields (post_title, post_content, post_excerpt).
	 *     @type array $meta  Meta key => value.
	 *     @type array $terms Taxonomy => term names (or hierarchical paths).
	 *     @type array $mapped Flat map of target => value, used for change detection.
	 * }
	 */
	public function map( array $record ) {
		$data = array(
			'post'   => array(),
			'meta'   => array(),
			'terms'  => array(),
			'mapped' => array(),
		);

		foreach ( self::get_mapping() as $row ) {
			$field = $row['zoho_field'];

			if ( ! array_key_exists( $field, $record ) ) {
				continue;
			}

			$value = self::transform( $record[ $field ], $row['transform'] );

			if ( null === $value ) {
				continue;
			}

			$target = $row['target'];

			if ( 0 === strpos( $target, 'meta:' ) ) {
				$key                  = substr( $target, 5 );
				$data['meta'][ $key ] = $value;
			} elseif ( 0 === strpos( $target, 'tax:' ) ) {
				$taxonomy = substr( $target, 4 );
				$terms    = is_array( $value ) ? $value : array( $value );
				$terms    = array_values( array_filter( array_map( 'trim', array_map( 'strval', $terms ) ), 'strlen' ) );

				if ( ! empty( $terms ) ) {
					$existing                   = isset( $data['terms'][ $taxonomy ] ) ? $data['terms'][ $taxonomy ] : array();
					$data['terms'][ $taxonomy ] = array_merge( $existing, $terms );
				}
			} elseif ( in_array( $target, array( 'post_title', 'post_content', 'post_excerpt' ), true ) ) {
				$data['post'][ $target ] = is_array( $value ) ? implode( ', ', $value ) : (string) $value;
			}

			$data['mapped'][ $target ] = $value;
		}

		$data = self::apply_derived_values( $data, $record );

		/**
		 * Filter the mapped job payload before it is written.
		 *
		 * @param array $data   Mapped payload.
		 * @param array $record Raw Zoho record.
		 */
		return (array) apply_filters( 'jszr_job_data', $data, $record );
	}

	/**
	 * Fill in values that are derived rather than mapped one to one.
	 *
	 * @param array $data   Mapped payload.
	 * @param array $record Raw Zoho record.
	 * @return array
	 */
	private static function apply_derived_values( array $data, array $record ) {
		// Title fallback so a job is never created with an empty title.
		if ( empty( $data['post']['post_title'] ) ) {
			foreach ( array( 'Posting_Title', 'Job_Opening_Name', 'Job_Opening_ID' ) as $candidate ) {
				if ( ! empty( $record[ $candidate ] ) && is_scalar( $record[ $candidate ] ) ) {
					$data['post']['post_title'] = sanitize_text_field( (string) $record[ $candidate ] );
					break;
				}
			}
		}

		if ( empty( $data['post']['post_title'] ) ) {
			$data['post']['post_title'] = __( 'Untitled job', 'jobs-sync-for-zoho-recruit' );
		}

		// Excerpt fallback derived from the description.
		if ( empty( $data['post']['post_excerpt'] ) && ! empty( $data['post']['post_content'] ) ) {
			$data['post']['post_excerpt'] = wp_trim_words( wp_strip_all_tags( $data['post']['post_content'] ), 40, '&hellip;' );
		}

		// Salary breakdown.
		if ( ! empty( $data['meta']['_zoho_recruit_salary'] ) ) {
			$salary = self::parse_salary( (string) $data['meta']['_zoho_recruit_salary'] );

			foreach ( $salary as $key => $value ) {
				if ( '' !== $value && null !== $value ) {
					$data['meta'][ '_zoho_recruit_salary_' . $key ] = $value;
				}
			}
		}

		// Hierarchical location terms built from country > state > city.
		$location_taxonomy = Post_Type::TAX_LOCATION;

		if ( empty( $data['terms'][ $location_taxonomy ] ) ) {
			$path = array();

			foreach ( array( '_zoho_recruit_country', '_zoho_recruit_state', '_zoho_recruit_city' ) as $meta_key ) {
				if ( ! empty( $data['meta'][ $meta_key ] ) && is_scalar( $data['meta'][ $meta_key ] ) ) {
					$path[] = trim( (string) $data['meta'][ $meta_key ] );
				}
			}

			$path = array_values( array_filter( $path, 'strlen' ) );

			if ( ! empty( $path ) ) {
				$data['terms'][ $location_taxonomy ] = array( $path );
			}
		}

		return $data;
	}

	/**
	 * Apply a transform to a raw Zoho value.
	 *
	 * @param mixed  $value     Raw value.
	 * @param string $transform Transform key.
	 * @return mixed Null when the value should be skipped.
	 */
	public static function transform( $value, $transform ) {
		if ( null === $value ) {
			return null;
		}

		switch ( $transform ) {
			case 'html':
				$html = wp_kses_post( self::stringify( $value ) );

				return self::tidy_html( $html );

			case 'autop':
				$text = wp_strip_all_tags( self::stringify( $value ) );

				return wpautop( esc_html( $text ) );

			case 'date':
				return self::to_date( $value );

			case 'datetime':
				return self::to_datetime( $value );

			case 'number':
				return self::to_number( $value );

			case 'integer':
				return (int) self::to_number( $value );

			case 'boolean':
				return self::to_boolean_label( $value );

			case 'join':
				$items = is_array( $value ) ? $value : array( $value );
				$items = array_map( array( __CLASS__, 'stringify' ), $items );
				$items = array_values( array_filter( array_map( 'trim', $items ), 'strlen' ) );

				return implode( ', ', array_map( 'sanitize_text_field', $items ) );

			case 'lookup_name':
				return sanitize_text_field( self::lookup_name( $value ) );

			case 'url':
				$url = esc_url_raw( trim( self::stringify( $value ) ) );

				return '' === $url ? null : $url;

			case 'text':
			default:
				if ( is_array( $value ) && ! self::is_lookup( $value ) ) {
					$items = array_map( array( __CLASS__, 'stringify' ), $value );

					return array_values( array_filter( array_map( 'sanitize_text_field', $items ), 'strlen' ) );
				}

				return sanitize_text_field( self::stringify( $value ) );
		}
	}

	/**
	 * Flatten any Zoho value to a string.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	public static function stringify( $value ) {
		if ( is_bool( $value ) ) {
			return $value ? '1' : '0';
		}

		if ( is_scalar( $value ) ) {
			return (string) $value;
		}

		if ( is_array( $value ) ) {
			if ( self::is_lookup( $value ) ) {
				return (string) ( $value['name'] ?? $value['id'] ?? '' );
			}

			$parts = array();

			foreach ( $value as $item ) {
				$parts[] = self::stringify( $item );
			}

			return implode( ', ', array_filter( $parts, 'strlen' ) );
		}

		return '';
	}

	/**
	 * Whether an array looks like a Zoho lookup/owner object.
	 *
	 * @param array $value Value.
	 * @return bool
	 */
	public static function is_lookup( array $value ) {
		return isset( $value['name'] ) || ( isset( $value['id'] ) && count( $value ) <= 3 );
	}

	/**
	 * Extract the display name from a lookup object.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	public static function lookup_name( $value ) {
		if ( is_array( $value ) ) {
			if ( isset( $value['name'] ) ) {
				return (string) $value['name'];
			}

			// Multi-select lookup: a list of objects.
			$names = array();

			foreach ( $value as $item ) {
				if ( is_array( $item ) && isset( $item['name'] ) ) {
					$names[] = (string) $item['name'];
				}
			}

			if ( ! empty( $names ) ) {
				return implode( ', ', $names );
			}
		}

		return self::stringify( $value );
	}

	/**
	 * Normalise a Zoho date value to Y-m-d.
	 *
	 * @param mixed $value Raw value.
	 * @return string Empty string when unparseable.
	 */
	public static function to_date( $value ) {
		$timestamp = self::to_timestamp( $value );

		return null === $timestamp ? '' : gmdate( 'Y-m-d', $timestamp );
	}

	/**
	 * Normalise a Zoho datetime value to an ISO-8601 UTC string.
	 *
	 * @param mixed $value Raw value.
	 * @return string Empty string when unparseable.
	 */
	public static function to_datetime( $value ) {
		$timestamp = self::to_timestamp( $value );

		return null === $timestamp ? '' : gmdate( 'Y-m-d\TH:i:s\Z', $timestamp );
	}

	/**
	 * Parse a Zoho date/datetime into a UTC timestamp.
	 *
	 * Zoho sends ISO-8601 strings carrying the account's offset, e.g.
	 * 2026-09-30T18:30:00+05:30. Those must be respected rather than assumed
	 * to be UTC or site time.
	 *
	 * @param mixed $value Raw value.
	 * @return int|null
	 */
	public static function to_timestamp( $value ) {
		$string = trim( self::stringify( $value ) );

		if ( '' === $string ) {
			return null;
		}

		try {
			// A bare date has no time or offset: treat it as site-local midnight.
			if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $string ) ) {
				$date = new \DateTimeImmutable( $string . ' 00:00:00', wp_timezone() );
			} else {
				$date = new \DateTimeImmutable( $string );
			}
		} catch ( \Exception $e ) {
			return null;
		}

		return $date->getTimestamp();
	}

	/**
	 * Coerce a value to a float, tolerating currency formatting.
	 *
	 * @param mixed $value Raw value.
	 * @return float
	 */
	public static function to_number( $value ) {
		$string = self::stringify( $value );
		$string = str_replace( array( ',', ' ' ), '', $string );
		$string = preg_replace( '/[^0-9.\-]/', '', $string );

		return is_numeric( $string ) ? (float) $string : 0.0;
	}

	/**
	 * Convert a boolean-ish value to a translated label.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	public static function to_boolean_label( $value ) {
		return self::to_boolean( $value )
			? __( 'Yes', 'jobs-sync-for-zoho-recruit' )
			: __( 'No', 'jobs-sync-for-zoho-recruit' );
	}

	/**
	 * Interpret a Zoho value as a boolean.
	 *
	 * @param mixed $value Raw value.
	 * @return bool
	 */
	public static function to_boolean( $value ) {
		if ( is_bool( $value ) ) {
			return $value;
		}

		if ( is_numeric( $value ) ) {
			return (float) $value > 0;
		}

		$string = strtolower( trim( self::stringify( $value ) ) );

		return in_array( $string, array( 'true', 'yes', 'y', '1', 'on', 'enabled' ), true );
	}

	/**
	 * Remove empty tags and inline styles from sanitized HTML.
	 *
	 * @param string $html Sanitized HTML.
	 * @return string
	 */
	public static function tidy_html( $html ) {
		$html = (string) $html;

		/**
		 * Filter whether inline style attributes are stripped from descriptions.
		 *
		 * @param bool $strip Whether to strip inline styles.
		 */
		if ( apply_filters( 'jszr_strip_inline_styles', true ) ) {
			$html = preg_replace( '/\s+style\s*=\s*(("[^"]*")|(\'[^\']*\'))/i', '', $html );
		}

		$html = preg_replace( '#<(p|div|span)[^>]*>(\s|&nbsp;|<br\s*/?>)*</\1>#i', '', $html );

		return is_string( $html ) ? trim( $html ) : '';
	}

	/**
	 * Best-effort parse of a free-text salary value.
	 *
	 * Only returns numbers it is confident about; structured data will not be
	 * emitted from a guess.
	 *
	 * @param string $salary Raw salary text.
	 * @return array{min:string,max:string,currency:string,unit:string}
	 */
	public static function parse_salary( $salary ) {
		$result = array(
			'min'      => '',
			'max'      => '',
			'currency' => '',
			'unit'     => '',
		);

		$salary = trim( (string) $salary );

		if ( '' === $salary ) {
			return $result;
		}

		$currencies = array(
			'USD' => array( 'usd', '$' ),
			'EUR' => array( 'eur', '€' ),
			'GBP' => array( 'gbp', '£' ),
			'INR' => array( 'inr', '₹', 'rs.', 'rs ' ),
			'AUD' => array( 'aud', 'a$' ),
			'CAD' => array( 'cad', 'c$' ),
			'JPY' => array( 'jpy', '¥' ),
			'SGD' => array( 'sgd' ),
			'AED' => array( 'aed' ),
			'SAR' => array( 'sar' ),
		);

		$haystack = strtolower( $salary );

		foreach ( $currencies as $code => $needles ) {
			foreach ( $needles as $needle ) {
				if ( false !== strpos( $haystack, $needle ) ) {
					$result['currency'] = $code;
					break 2;
				}
			}
		}

		$units = array(
			'HOUR'  => array( 'per hour', '/hour', 'hourly', '/hr', 'per hr' ),
			'DAY'   => array( 'per day', '/day', 'daily' ),
			'WEEK'  => array( 'per week', '/week', 'weekly' ),
			'MONTH' => array( 'per month', '/month', 'monthly', 'p.m.' ),
			'YEAR'  => array( 'per year', '/year', 'annually', 'per annum', 'p.a.', 'yearly', 'lpa' ),
		);

		foreach ( $units as $unit => $needles ) {
			foreach ( $needles as $needle ) {
				if ( false !== strpos( $haystack, $needle ) ) {
					$result['unit'] = $unit;
					break 2;
				}
			}
		}

		$multiplier = 1.0;

		if ( preg_match( '/\b(lpa|lakh|lac)\b/i', $salary ) ) {
			$multiplier = 100000.0;
		} elseif ( preg_match( '/\bcr(ore)?\b/i', $salary ) ) {
			$multiplier = 10000000.0;
		} elseif ( preg_match( '/\d\s*k\b/i', $salary ) ) {
			$multiplier = 1000.0;
		}

		if ( preg_match_all( '/\d[\d,]*(?:\.\d+)?/', $salary, $matches ) && ! empty( $matches[0] ) ) {
			$numbers = array();

			foreach ( $matches[0] as $match ) {
				$numbers[] = (float) str_replace( ',', '', $match ) * $multiplier;
			}

			$numbers = array_values(
				array_filter(
					$numbers,
					static function ( $number ) {
						return $number > 0;
					}
				)
			);

			if ( 1 === count( $numbers ) ) {
				$result['min'] = (string) $numbers[0];
				$result['max'] = (string) $numbers[0];
			} elseif ( count( $numbers ) >= 2 ) {
				sort( $numbers );
				$result['min'] = (string) $numbers[0];
				$result['max'] = (string) $numbers[ count( $numbers ) - 1 ];
			}
		}

		return $result;
	}

	/**
	 * Export the current mapping as a JSON string.
	 *
	 * @return string
	 */
	public static function export() {
		return (string) wp_json_encode(
			array(
				'version' => VERSION,
				'mapping' => self::get_mapping(),
			),
			JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
		);
	}

	/**
	 * Import a mapping from JSON.
	 *
	 * @param string $json JSON payload.
	 * @return true|\WP_Error
	 */
	public static function import( $json ) {
		$data = json_decode( (string) $json, true );

		if ( ! is_array( $data ) ) {
			return new \WP_Error( 'jszr_invalid_json', __( 'The uploaded file is not valid JSON.', 'jobs-sync-for-zoho-recruit' ) );
		}

		$mapping = isset( $data['mapping'] ) && is_array( $data['mapping'] ) ? $data['mapping'] : $data;
		$clean   = self::sanitize_mapping( $mapping );

		if ( empty( $clean ) ) {
			return new \WP_Error( 'jszr_empty_mapping', __( 'The file did not contain any valid mapping rows.', 'jobs-sync-for-zoho-recruit' ) );
		}

		self::save_mapping( $clean );

		return true;
	}
}
