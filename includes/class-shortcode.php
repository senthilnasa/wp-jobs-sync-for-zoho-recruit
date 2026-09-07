<?php
/**
 * Shortcodes and the shared listing renderer.
 *
 * @package JobsSyncForZohoRecruit
 */

namespace JobsSyncForZohoRecruit;

defined( 'ABSPATH' ) || exit;

/**
 * One renderer serves the shortcode, the block and the archive template, so
 * markup and behaviour never drift between them.
 */
class Shortcode {

	/**
	 * Hook registration.
	 *
	 * @return void
	 */
	public static function init() {
		add_shortcode( 'zoho_jobs', array( __CLASS__, 'render_shortcode' ) );
		add_shortcode( 'zoho_job_apply', array( __CLASS__, 'render_apply' ) );
		add_shortcode( 'zoho_job_meta', array( __CLASS__, 'render_meta' ) );
	}

	/**
	 * Default listing attributes.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'per_page'        => (int) Settings::get( 'rest_per_page', 20 ),
			'search'          => '',
			'department'      => '',
			'location'        => '',
			'employment_type' => '',
			'category'        => '',
			'experience'      => '',
			'orderby'         => 'date',
			'order'           => 'desc',
			'status'          => 'active',
			'style'           => (string) Settings::get( 'default_style', 'list' ),
			'columns'         => 3,
			'show_filters'    => 'false',
			'show_search'     => 'false',
			'show_pagination' => 'true',
			'show_excerpt'    => 'true',
		);
	}

	/**
	 * Render the [zoho_jobs] shortcode.
	 *
	 * @param array|string $atts Shortcode attributes.
	 * @return string
	 */
	public static function render_shortcode( $atts ) {
		$atts = shortcode_atts( self::defaults(), (array) $atts, 'zoho_jobs' );

		return self::render( $atts );
	}

	/**
	 * Render a job listing.
	 *
	 * @param array $atts Listing attributes.
	 * @return string
	 */
	public static function render( array $atts ) {
		$atts = wp_parse_args( $atts, self::defaults() );

		Templates::enqueue_assets();

		$show_filters = self::truthy( $atts['show_filters'] );
		$show_search  = self::truthy( $atts['show_search'] );

		// Visitor-supplied values from the no-JS filter form take precedence.
		$request = self::request_values( $show_filters, $show_search );

		$params = array(
			'page'            => $request['page'],
			'per_page'        => max( 1, (int) $atts['per_page'] ),
			'search'          => '' !== $request['search'] ? $request['search'] : (string) $atts['search'],
			'orderby'         => (string) $atts['orderby'],
			'order'           => (string) $atts['order'],
			'status'          => (string) $atts['status'],
		);

		foreach ( array_keys( REST_API::filter_map() ) as $key ) {
			$params[ $key ] = '' !== $request[ $key ] ? $request[ $key ] : (string) $atts[ $key ];
		}

		$args = REST_API::build_query_args( $params );

		/**
		 * Filter the query arguments used by the listing renderer.
		 *
		 * @param array $args  WP_Query arguments.
		 * @param array $atts  Listing attributes.
		 */
		$args = (array) apply_filters( 'jszr_listing_query_args', $args, $atts );

		$query = new \WP_Query( $args );

		$style   = 'grid' === $atts['style'] ? 'grid' : 'list';
		$columns = max( 1, min( 4, (int) $atts['columns'] ) );

		return Templates::get(
			'listing.php',
			array(
				'query'           => $query,
				'atts'            => $atts,
				'params'          => $params,
				'style'           => $style,
				'columns'         => $columns,
				'show_filters'    => $show_filters,
				'show_search'     => $show_search,
				'show_pagination' => self::truthy( $atts['show_pagination'] ),
				'show_excerpt'    => self::truthy( $atts['show_excerpt'] ),
			)
		);
	}

	/**
	 * Read filter values from the request.
	 *
	 * Filters are plain GET parameters so they work without JavaScript.
	 *
	 * @param bool $allow_filters Whether taxonomy filters are accepted.
	 * @param bool $allow_search  Whether the keyword box is accepted.
	 * @return array<string,string|int>
	 */
	private static function request_values( $allow_filters, $allow_search ) {
		$values = array(
			'page'   => 1,
			'search' => '',
		);

		foreach ( array_keys( REST_API::filter_map() ) as $key ) {
			$values[ $key ] = '';
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Public read-only filtering.
		if ( isset( $_GET['jszr_page'] ) ) {
			$values['page'] = max( 1, (int) $_GET['jszr_page'] );
		} elseif ( get_query_var( 'paged' ) ) {
			$values['page'] = max( 1, (int) get_query_var( 'paged' ) );
		}

		if ( $allow_search && isset( $_GET['jszr_search'] ) ) {
			$values['search'] = sanitize_text_field( wp_unslash( $_GET['jszr_search'] ) );
		}

		if ( $allow_filters ) {
			foreach ( array_keys( REST_API::filter_map() ) as $key ) {
				$param = 'jszr_' . $key;

				if ( isset( $_GET[ $param ] ) ) {
					$values[ $key ] = sanitize_text_field( wp_unslash( $_GET[ $param ] ) );
				}
			}
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		return $values;
	}

	/**
	 * Render the [zoho_job_apply] shortcode.
	 *
	 * @param array|string $atts Attributes.
	 * @return string
	 */
	public static function render_apply( $atts ) {
		$atts = shortcode_atts(
			array(
				'id'    => 0,
				'label' => '',
				'class' => '',
			),
			(array) $atts,
			'zoho_job_apply'
		);

		$post_id = (int) $atts['id'] > 0 ? (int) $atts['id'] : get_the_ID();

		if ( ! $post_id || Post_Type::POST_TYPE !== get_post_type( $post_id ) ) {
			return '';
		}

		$url = Job::get_apply_url( $post_id );

		if ( '' === $url ) {
			return '';
		}

		Templates::enqueue_assets();

		$label = '' !== $atts['label']
			? $atts['label']
			: (string) Settings::get( 'apply_label', '' );

		if ( '' === $label ) {
			$label = __( 'Apply Now', 'jobs-sync-for-zoho-recruit' );
		}

		$classes = trim( 'jszr-apply-button ' . sanitize_html_class( (string) $atts['class'] ) );

		return sprintf(
			'<a class="%1$s" href="%2$s" target="_blank" rel="noopener nofollow">%3$s</a>',
			esc_attr( $classes ),
			esc_url( $url ),
			esc_html( $label )
		);
	}

	/**
	 * Render the [zoho_job_meta] shortcode.
	 *
	 * @param array|string $atts Attributes.
	 * @return string
	 */
	public static function render_meta( $atts ) {
		$atts = shortcode_atts(
			array(
				'id'     => 0,
				'fields' => 'department,location,employment_type,experience,closing_date',
			),
			(array) $atts,
			'zoho_job_meta'
		);

		$post_id = (int) $atts['id'] > 0 ? (int) $atts['id'] : get_the_ID();

		if ( ! $post_id || Post_Type::POST_TYPE !== get_post_type( $post_id ) ) {
			return '';
		}

		Templates::enqueue_assets();

		$fields = array_filter( array_map( 'trim', explode( ',', (string) $atts['fields'] ) ), 'strlen' );

		return Templates::get(
			'job-meta.php',
			array(
				'post_id' => $post_id,
				'fields'  => $fields,
			)
		);
	}

	/**
	 * Interpret a shortcode boolean attribute.
	 *
	 * @param mixed $value Raw value.
	 * @return bool
	 */
	public static function truthy( $value ) {
		if ( is_bool( $value ) ) {
			return $value;
		}

		return in_array( strtolower( trim( (string) $value ) ), array( '1', 'true', 'yes', 'on' ), true );
	}
}
