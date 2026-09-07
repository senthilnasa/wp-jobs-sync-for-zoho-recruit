<?php
/**
 * JobPosting structured data.
 *
 * @package JobsSyncForZohoRecruit
 */

namespace JobsSyncForZohoRecruit;

defined( 'ABSPATH' ) || exit;

/**
 * Emits schema.org JobPosting markup for single job pages.
 *
 * Nothing is guessed: a property is omitted whenever the underlying value is
 * missing or cannot be parsed with confidence, because invalid structured data
 * is worse than none.
 */
class Structured_Data {

	/**
	 * Hook registration.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'wp_head', array( __CLASS__, 'output' ), 20 );
	}

	/**
	 * Print the JSON-LD block.
	 *
	 * @return void
	 */
	public static function output() {
		if ( ! is_singular( Post_Type::POST_TYPE ) ) {
			return;
		}

		if ( ! Settings::get( 'schema_enabled', true ) ) {
			return;
		}

		if ( self::seo_plugin_handles_it() ) {
			return;
		}

		$post_id = get_queried_object_id();

		if ( ! $post_id || ! Job::is_active( $post_id ) ) {
			return;
		}

		$schema = self::build( $post_id );

		if ( empty( $schema ) ) {
			return;
		}

		$json = wp_json_encode( $schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );

		if ( ! is_string( $json ) ) {
			return;
		}

		printf(
			'<script type="application/ld+json">%s</script>' . "\n",
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON encoded above; wp_json_encode escapes for script context.
			$json
		);
	}

	/**
	 * Build the JobPosting graph for a job.
	 *
	 * @param int $post_id Post ID.
	 * @return array Empty when there is not enough information.
	 */
	public static function build( $post_id ) {
		$post = get_post( $post_id );

		if ( ! $post instanceof \WP_Post ) {
			return array();
		}

		$description = trim( wp_strip_all_tags( $post->post_content ) );

		if ( '' === $description ) {
			$description = trim( wp_strip_all_tags( get_the_excerpt( $post_id ) ) );
		}

		$posted = self::iso_date( (string) get_post_meta( $post_id, '_zoho_recruit_posted_date', true ) );

		if ( '' === $posted ) {
			$posted = get_post_time( 'c', true, $post );
		}

		$schema = array(
			'@context'    => 'https://schema.org/',
			'@type'       => 'JobPosting',
			'title'       => wp_strip_all_tags( get_the_title( $post_id ) ),
			'description' => wp_kses_post( $post->post_content ),
			'datePosted'  => $posted,
			'url'         => get_permalink( $post_id ),
		);

		if ( '' === $schema['description'] ) {
			$schema['description'] = $description;
		}

		if ( '' === trim( (string) $schema['description'] ) ) {
			// Google requires a description; without one the markup is invalid.
			return array();
		}

		$closing = self::iso_date( (string) get_post_meta( $post_id, '_zoho_recruit_closing_date', true ), true );

		if ( '' !== $closing ) {
			$schema['validThrough'] = $closing;
		}

		$employment = self::employment_types( $post_id );

		if ( ! empty( $employment ) ) {
			$schema['employmentType'] = 1 === count( $employment ) ? $employment[0] : $employment;
		}

		$organization = self::hiring_organization();

		if ( ! empty( $organization ) ) {
			$schema['hiringOrganization'] = $organization;
		}

		$identifier = self::identifier( $post_id, $organization );

		if ( ! empty( $identifier ) ) {
			$schema['identifier'] = $identifier;
		}

		$location = self::job_location( $post_id );

		if ( ! empty( $location ) ) {
			$schema['jobLocation'] = $location;
		}

		if ( self::is_remote( $post_id ) ) {
			$schema['jobLocationType'] = 'TELECOMMUTE';

			$country = trim( (string) get_post_meta( $post_id, '_zoho_recruit_country', true ) );

			if ( '' !== $country ) {
				$schema['applicantLocationRequirements'] = array(
					'@type' => 'Country',
					'name'  => $country,
				);
			}
		}

		$salary = self::base_salary( $post_id );

		if ( ! empty( $salary ) ) {
			$schema['baseSalary'] = $salary;
		}

		$apply_url = Job::get_apply_url( $post_id );

		if ( '' !== $apply_url ) {
			$schema['directApply'] = false;
		}

		$positions = (int) get_post_meta( $post_id, '_zoho_recruit_positions', true );

		if ( $positions > 0 ) {
			$schema['totalJobOpenings'] = $positions;
		}

		/**
		 * Filter the JobPosting structured data before output.
		 *
		 * Return an empty array to suppress the markup for a job.
		 *
		 * @param array    $schema  Schema graph.
		 * @param \WP_Post $post    Job post.
		 */
		return (array) apply_filters( 'jszr_structured_data', $schema, $post );
	}

	/**
	 * Map local employment type terms to schema.org enum values.
	 *
	 * @param int $post_id Post ID.
	 * @return string[]
	 */
	private static function employment_types( $post_id ) {
		$terms = get_the_terms( $post_id, Post_Type::TAX_EMPLOYMENT_TYPE );

		if ( ! is_array( $terms ) ) {
			return array();
		}

		$map = array(
			'full time'   => 'FULL_TIME',
			'full-time'   => 'FULL_TIME',
			'fulltime'    => 'FULL_TIME',
			'permanent'   => 'FULL_TIME',
			'part time'   => 'PART_TIME',
			'part-time'   => 'PART_TIME',
			'contract'    => 'CONTRACTOR',
			'contractor'  => 'CONTRACTOR',
			'contract to hire' => 'CONTRACTOR',
			'freelance'   => 'CONTRACTOR',
			'temporary'   => 'TEMPORARY',
			'temp'        => 'TEMPORARY',
			'intern'      => 'INTERN',
			'internship'  => 'INTERN',
			'volunteer'   => 'VOLUNTEER',
			'per diem'    => 'PER_DIEM',
			'other'       => 'OTHER',
		);

		/**
		 * Filter the employment type to schema.org enum map.
		 *
		 * @param array $map Lower-cased term name => schema enum.
		 */
		$map = (array) apply_filters( 'jszr_employment_type_map', $map );

		$types = array();

		foreach ( $terms as $term ) {
			$key = strtolower( trim( $term->name ) );

			if ( isset( $map[ $key ] ) ) {
				$types[] = $map[ $key ];
			}
		}

		return array_values( array_unique( $types ) );
	}

	/**
	 * The hiring organization block from settings.
	 *
	 * @return array
	 */
	private static function hiring_organization() {
		$name = trim( (string) Settings::get( 'org_name', '' ) );

		if ( '' === $name ) {
			$name = wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );
		}

		if ( '' === $name ) {
			return array();
		}

		$organization = array(
			'@type' => 'Organization',
			'name'  => $name,
		);

		$url = trim( (string) Settings::get( 'org_url', '' ) );

		$organization['sameAs'] = '' !== $url ? esc_url_raw( $url ) : home_url( '/' );

		$logo = trim( (string) Settings::get( 'org_logo', '' ) );

		if ( '' !== $logo ) {
			$organization['logo'] = esc_url_raw( $logo );
		}

		return $organization;
	}

	/**
	 * The identifier block.
	 *
	 * @param int   $post_id      Post ID.
	 * @param array $organization Hiring organization block.
	 * @return array
	 */
	private static function identifier( $post_id, array $organization ) {
		$code = trim( (string) get_post_meta( $post_id, '_jszr_job_code', true ) );

		if ( '' === $code ) {
			$code = trim( (string) get_post_meta( $post_id, Job::META_ZOHO_ID, true ) );
		}

		if ( '' === $code ) {
			return array();
		}

		return array(
			'@type' => 'PropertyValue',
			'name'  => isset( $organization['name'] ) ? $organization['name'] : get_bloginfo( 'name' ),
			'value' => $code,
		);
	}

	/**
	 * The jobLocation block.
	 *
	 * @param int $post_id Post ID.
	 * @return array
	 */
	private static function job_location( $post_id ) {
		$city    = trim( (string) get_post_meta( $post_id, '_zoho_recruit_city', true ) );
		$state   = trim( (string) get_post_meta( $post_id, '_zoho_recruit_state', true ) );
		$country = trim( (string) get_post_meta( $post_id, '_zoho_recruit_country', true ) );

		if ( '' === $city && '' === $state && '' === $country ) {
			return array();
		}

		$address = array( '@type' => 'PostalAddress' );

		if ( '' !== $city ) {
			$address['addressLocality'] = $city;
		}

		if ( '' !== $state ) {
			$address['addressRegion'] = $state;
		}

		if ( '' !== $country ) {
			$address['addressCountry'] = $country;
		}

		return array(
			'@type'   => 'Place',
			'address' => $address,
		);
	}

	/**
	 * Whether the job is remote.
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	private static function is_remote( $post_id ) {
		$value = (string) get_post_meta( $post_id, '_zoho_recruit_remote', true );

		if ( '' === $value ) {
			return false;
		}

		$normalised = strtolower( trim( $value ) );

		if ( in_array( $normalised, array( 'no', 'false', '0', 'onsite', 'on-site', 'office' ), true ) ) {
			return false;
		}

		return true;
	}

	/**
	 * The baseSalary block, only when the numbers parsed cleanly.
	 *
	 * @param int $post_id Post ID.
	 * @return array
	 */
	private static function base_salary( $post_id ) {
		$min      = (string) get_post_meta( $post_id, '_zoho_recruit_salary_min', true );
		$max      = (string) get_post_meta( $post_id, '_zoho_recruit_salary_max', true );
		$currency = (string) get_post_meta( $post_id, '_zoho_recruit_salary_currency', true );
		$unit     = (string) get_post_meta( $post_id, '_zoho_recruit_salary_unit', true );

		if ( '' === $min || '' === $currency || '' === $unit ) {
			// Without a currency and unit the value is ambiguous, so it is omitted.
			return array();
		}

		$value = array(
			'@type'    => 'QuantitativeValue',
			'unitText' => $unit,
		);

		if ( '' !== $max && (float) $max > (float) $min ) {
			$value['minValue'] = (float) $min;
			$value['maxValue'] = (float) $max;
		} else {
			$value['value'] = (float) $min;
		}

		return array(
			'@type'    => 'MonetaryAmount',
			'currency' => $currency,
			'value'    => $value,
		);
	}

	/**
	 * Convert a stored date to ISO-8601 in the site timezone.
	 *
	 * @param string $date       Stored date.
	 * @param bool   $end_of_day Whether to use the end of the day.
	 * @return string
	 */
	private static function iso_date( $date, $end_of_day = false ) {
		$date = trim( (string) $date );

		if ( '' === $date ) {
			return '';
		}

		try {
			if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
				$time = $end_of_day ? ' 23:59:59' : ' 00:00:00';
				$obj  = new \DateTimeImmutable( $date . $time, wp_timezone() );
			} else {
				$obj = new \DateTimeImmutable( $date );
			}
		} catch ( \Exception $e ) {
			return '';
		}

		return $obj->format( 'c' );
	}

	/**
	 * Whether an SEO plugin already outputs JobPosting markup.
	 *
	 * @return bool
	 */
	public static function seo_plugin_handles_it() {
		if ( ! Settings::get( 'schema_skip_if_seo', true ) ) {
			return false;
		}

		$detected = false;
		$post_id  = get_queried_object_id();

		// Rank Math stores per-post schema; a JobPosting entry means it will
		// render its own markup and ours would duplicate it.
		if ( $post_id && defined( 'RANK_MATH_VERSION' ) ) {
			$meta = get_post_meta( $post_id );

			if ( is_array( $meta ) ) {
				foreach ( array_keys( $meta ) as $key ) {
					if ( 0 === strpos( (string) $key, 'rank_math_schema_JobPosting' ) ) {
						$detected = true;
						break;
					}
				}
			}
		}

		/**
		 * Filter whether another plugin already outputs JobPosting markup.
		 *
		 * Installing an SEO plugin is not on its own enough to suppress this
		 * plugin's markup, because most do not emit JobPosting at all. Return
		 * true here when yours does.
		 *
		 * @param bool $detected Whether another plugin is known to handle it.
		 */
		return (bool) apply_filters( 'jszr_seo_plugin_handles_schema', $detected );
	}
}
