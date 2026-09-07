<?php
/**
 * Token substitution for administrator-authored layouts.
 *
 * @package JobsSyncForZohoRecruit
 */

namespace JobsSyncForZohoRecruit;

defined( 'ABSPATH' ) || exit;

/**
 * Turns a token template into markup for one job.
 *
 * Layouts are token templates rather than PHP on purpose. An option that holds
 * executable code is a remote code execution hole waiting for one weak admin
 * password, so the plugin never evaluates what an administrator types. The
 * template's HTML is filtered through an allow-list when it is saved, every
 * token value is escaped for its own nature when it is substituted, and an
 * unrecognised token resolves to nothing rather than being printed back.
 *
 * Supported syntax:
 *
 *     {title}                        a value
 *     {if:salary}...{/if:salary}     kept only when the value is non-empty
 *     {ifnot:salary}...{/ifnot:salary}
 */
class Template_Tags {

	/**
	 * Render a template for one job.
	 *
	 * @param string $template Token template.
	 * @param int    $post_id  Job post ID.
	 * @return string
	 */
	public static function render( $template, $post_id ) {
		$template = (string) $template;
		$post_id  = (int) $post_id;

		if ( '' === trim( $template ) || ! $post_id ) {
			return '';
		}

		$markup = self::render_values( $template, self::values( $post_id ) );

		/**
		 * Filter the markup produced by a token template.
		 *
		 * @param string $markup   Rendered markup.
		 * @param string $template The template it came from.
		 * @param int    $post_id  Job post ID.
		 */
		return (string) apply_filters( 'jszr_rendered_template', $markup, $template, $post_id );
	}

	/**
	 * Render a template against a value set that is already resolved.
	 *
	 * Used by the settings preview, which has no real job to read.
	 *
	 * @param string $template Token template.
	 * @param array  $values   Ready-to-print token values.
	 * @return string
	 */
	public static function render_values( $template, array $values ) {
		$markup = self::apply_conditionals( (string) $template, $values );

		return self::replace_tokens( $markup, $values );
	}

	/**
	 * A plausible job, for previewing a layout with no jobs synced yet.
	 *
	 * @return array<string,string>
	 */
	public static function sample_values() {
		$values = array(
			'id'           => '0',
			'title'        => esc_html__( 'Senior Software Developer', 'jobs-sync-for-zoho-recruit' ),
			'permalink'    => '#',
			'excerpt'      => esc_html__( 'Work with our platform team on the services behind everything we ship.', 'jobs-sync-for-zoho-recruit' ),
			'content'      => '<p>' . esc_html__( 'Work with our platform team on the services behind everything we ship.', 'jobs-sync-for-zoho-recruit' ) . '</p>',
			'job_code'     => 'JOB-104',
			'status'       => 'active',
			'city'         => esc_html__( 'Chennai', 'jobs-sync-for-zoho-recruit' ),
			'state'        => esc_html__( 'Tamil Nadu', 'jobs-sync-for-zoho-recruit' ),
			'country'      => esc_html__( 'India', 'jobs-sync-for-zoho-recruit' ),
			'remote'       => esc_html__( 'Remote', 'jobs-sync-for-zoho-recruit' ),
			'salary'       => '1,200,000 - 1,800,000 INR',
			'industry'     => esc_html__( 'Software', 'jobs-sync-for-zoho-recruit' ),
			'client'       => '',
			'positions'    => '2',
			'posted_date'  => wp_date( (string) get_option( 'date_format' ), time() - ( 9 * DAY_IN_SECONDS ) ),
			'closing_date' => wp_date( (string) get_option( 'date_format' ), time() + ( 21 * DAY_IN_SECONDS ) ),
			'apply_url'    => '#',
			'thumbnail'    => '',
			'view_link'    => '<a class="jszr-job-card__link" href="#">' . esc_html__( 'View Job', 'jobs-sync-for-zoho-recruit' ) . '</a>',
			'apply_button' => '<a class="jszr-apply-button" href="#">' . esc_html__( 'Apply Now', 'jobs-sync-for-zoho-recruit' ) . '</a>',
		);

		$samples = array(
			'department'      => esc_html__( 'Engineering', 'jobs-sync-for-zoho-recruit' ),
			'location'        => esc_html__( 'Chennai', 'jobs-sync-for-zoho-recruit' ),
			'employment_type' => esc_html__( 'Full time', 'jobs-sync-for-zoho-recruit' ),
			'experience'      => esc_html__( '2 - 5 years', 'jobs-sync-for-zoho-recruit' ),
			'category'        => esc_html__( 'Product', 'jobs-sync-for-zoho-recruit' ),
		);

		foreach ( array_keys( REST_API::filter_map() ) as $param ) {
			$values[ $param ] = isset( $samples[ $param ] ) ? $samples[ $param ] : ucfirst( str_replace( '_', ' ', $param ) );
		}

		return $values;
	}

	/**
	 * Resolve every token for a job.
	 *
	 * Values are returned ready to print: text is escaped, URLs are passed
	 * through esc_url(), and the few markup tokens are assembled from escaped
	 * parts here.
	 *
	 * @param int $post_id Job post ID.
	 * @return array<string,string>
	 */
	public static function values( $post_id ) {
		$post_id = (int) $post_id;
		$post    = get_post( $post_id );

		if ( ! $post instanceof \WP_Post ) {
			return array();
		}

		$date_format = (string) get_option( 'date_format' );
		$permalink   = (string) get_permalink( $post_id );
		$title       = get_the_title( $post_id );
		$apply_url   = Job::get_apply_url( $post_id );

		$values = array(
			'id'        => (string) $post_id,
			'title'     => esc_html( $title ),
			'permalink' => esc_url( $permalink ),
			'excerpt'   => esc_html( wp_trim_words( wp_strip_all_tags( get_the_excerpt( $post_id ) ), 28, '&hellip;' ) ),
			'content'   => wp_kses_post( $post->post_content ),
			'status'    => esc_html( Job::get_status( $post_id ) ),
			'apply_url' => esc_url( $apply_url ),
		);

		// Taxonomies, including any registered through jszr_taxonomies.
		foreach ( REST_API::filter_map() as $param => $taxonomy ) {
			$terms = get_the_terms( $post_id, $taxonomy );

			$values[ $param ] = is_array( $terms )
				? esc_html( implode( ', ', wp_list_pluck( $terms, 'name' ) ) )
				: '';
		}

		// Plain meta values.
		$meta_tokens = array(
			'job_code'        => '_jszr_job_code',
			'city'            => '_zoho_recruit_city',
			'state'           => '_zoho_recruit_state',
			'country'         => '_zoho_recruit_country',
			'industry'        => '_zoho_recruit_industry',
			'client'          => '_zoho_recruit_client',
			'positions'       => '_zoho_recruit_positions',
			'salary'          => '_zoho_recruit_salary',
			'salary_min'      => '_zoho_recruit_salary_min',
			'salary_max'      => '_zoho_recruit_salary_max',
			'salary_currency' => '_zoho_recruit_salary_currency',
		);

		foreach ( $meta_tokens as $token => $meta_key ) {
			$values[ $token ] = esc_html( (string) get_post_meta( $post_id, $meta_key, true ) );
		}

		// Dates, formatted the way the site formats dates.
		foreach ( array(
			'posted_date'  => '_zoho_recruit_posted_date',
			'closing_date' => '_zoho_recruit_closing_date',
		) as $token => $meta_key ) {
			$raw       = (string) get_post_meta( $post_id, $meta_key, true );
			$timestamp = '' !== $raw ? strtotime( $raw ) : false;

			$values[ $token ] = $timestamp ? esc_html( wp_date( $date_format, $timestamp ) ) : '';
		}

		// A flag, shown as a word that means something on its own.
		$remote = (string) get_post_meta( $post_id, '_zoho_recruit_remote', true );

		$values['remote'] = ( '' !== $remote && ! in_array( strtolower( trim( $remote ) ), array( 'no', 'false', '0' ), true ) )
			? esc_html__( 'Remote', 'jobs-sync-for-zoho-recruit' )
			: '';

		// Markup tokens, assembled from already-escaped parts.
		$values['thumbnail'] = has_post_thumbnail( $post_id )
			? get_the_post_thumbnail(
				$post_id,
				'medium',
				array(
					'class'   => 'jszr-job-card__image',
					'loading' => 'lazy',
				)
			)
			: '';

		$values['view_link'] = sprintf(
			'<a class="jszr-job-card__link" href="%1$s">%2$s<span class="screen-reader-text"> %3$s</span></a>',
			esc_url( $permalink ),
			esc_html__( 'View Job', 'jobs-sync-for-zoho-recruit' ),
			esc_html( $title )
		);

		$values['apply_button'] = '';

		if ( '' !== $apply_url && Job::is_active( $post_id ) ) {
			$label = (string) Settings::get( 'apply_label', '' );

			$values['apply_button'] = sprintf(
				'<a class="jszr-apply-button" href="%1$s" target="_blank" rel="noopener nofollow">%2$s<span class="screen-reader-text"> %3$s</span></a>',
				esc_url( $apply_url ),
				esc_html( '' !== $label ? $label : __( 'Apply Now', 'jobs-sync-for-zoho-recruit' ) ),
				esc_html__( '(opens in a new tab)', 'jobs-sync-for-zoho-recruit' )
			);
		}

		/**
		 * Filter the token values available to a layout template.
		 *
		 * Every value must already be escaped for output; nothing here is
		 * escaped again at substitution time.
		 *
		 * @param array $values  Token name => ready-to-print value.
		 * @param int   $post_id Job post ID.
		 */
		return (array) apply_filters( 'jszr_template_tag_values', $values, $post_id );
	}

	/**
	 * Every token an administrator can use, with a short description.
	 *
	 * Used by the settings screen to document the templates.
	 *
	 * @return array<string,string>
	 */
	public static function documented_tags() {
		$tags = array(
			'title'        => __( 'Job title', 'jobs-sync-for-zoho-recruit' ),
			'permalink'    => __( 'Link to the job page', 'jobs-sync-for-zoho-recruit' ),
			'excerpt'      => __( 'Short summary', 'jobs-sync-for-zoho-recruit' ),
			'content'      => __( 'Full description', 'jobs-sync-for-zoho-recruit' ),
			'job_code'     => __( 'Job code', 'jobs-sync-for-zoho-recruit' ),
			'city'         => __( 'City', 'jobs-sync-for-zoho-recruit' ),
			'state'        => __( 'State', 'jobs-sync-for-zoho-recruit' ),
			'country'      => __( 'Country', 'jobs-sync-for-zoho-recruit' ),
			'remote'       => __( 'The word "Remote" when the job is remote, nothing otherwise', 'jobs-sync-for-zoho-recruit' ),
			'salary'       => __( 'Salary as Zoho stores it', 'jobs-sync-for-zoho-recruit' ),
			'industry'     => __( 'Industry', 'jobs-sync-for-zoho-recruit' ),
			'client'       => __( 'Client', 'jobs-sync-for-zoho-recruit' ),
			'positions'    => __( 'Number of openings', 'jobs-sync-for-zoho-recruit' ),
			'posted_date'  => __( 'Posted date', 'jobs-sync-for-zoho-recruit' ),
			'closing_date' => __( 'Closing date', 'jobs-sync-for-zoho-recruit' ),
			'apply_url'    => __( 'Application URL', 'jobs-sync-for-zoho-recruit' ),
			'apply_button' => __( 'A ready-made Apply button, or nothing if the job is closed', 'jobs-sync-for-zoho-recruit' ),
			'view_link'    => __( 'A ready-made "View Job" link', 'jobs-sync-for-zoho-recruit' ),
			'thumbnail'    => __( 'Featured image, if the job has one', 'jobs-sync-for-zoho-recruit' ),
			'id'           => __( 'WordPress post ID', 'jobs-sync-for-zoho-recruit' ),
		);

		foreach ( REST_API::filter_map() as $param => $taxonomy ) {
			$object = get_taxonomy( $taxonomy );

			$tags[ $param ] = $object
				? sprintf(
					/* translators: %s: taxonomy label. */
					__( '%s terms, comma separated', 'jobs-sync-for-zoho-recruit' ),
					$object->labels->singular_name
				)
				: $param;
		}

		ksort( $tags );

		return $tags;
	}

	/**
	 * Resolve {if:token} and {ifnot:token} blocks.
	 *
	 * @param string $template Template.
	 * @param array  $values   Token values.
	 * @return string
	 */
	private static function apply_conditionals( $template, array $values ) {
		// Nested conditionals are resolved by repeating until nothing changes.
		$guard = 0;

		do {
			$before = $template;

			$template = (string) preg_replace_callback(
				'/\{(if|ifnot):([a-z0-9_]+)\}(.*?)\{\/\1:\2\}/is',
				static function ( $matches ) use ( $values ) {
					$has = isset( $values[ $matches[2] ] ) && '' !== trim( (string) $values[ $matches[2] ] );

					if ( 'ifnot' === $matches[1] ) {
						$has = ! $has;
					}

					return $has ? $matches[3] : '';
				},
				$template
			);

			++$guard;
		} while ( $template !== $before && $guard < 10 );

		return $template;
	}

	/**
	 * Replace every {token}, dropping the ones we do not recognise.
	 *
	 * @param string $template Template.
	 * @param array  $values   Token values.
	 * @return string
	 */
	private static function replace_tokens( $template, array $values ) {
		return (string) preg_replace_callback(
			'/\{([a-z0-9_]+)\}/i',
			static function ( $matches ) use ( $values ) {
				$key = strtolower( $matches[1] );

				return isset( $values[ $key ] ) ? (string) $values[ $key ] : '';
			},
			$template
		);
	}
}
