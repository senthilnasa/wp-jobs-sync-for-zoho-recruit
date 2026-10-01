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
		// One empty attribute per registered taxonomy, so a taxonomy added
		// through jszr_taxonomies is filterable from the shortcode with no
		// further wiring.
		$taxonomy_atts = array_fill_keys( array_keys( REST_API::filter_map() ), '' );

		return array_merge(
			$taxonomy_atts,
			array(
				'per_page'        => (int) Settings::get( 'rest_per_page', 20 ),
				'search'          => '',
				'orderby'         => 'date',
				'order'           => 'desc',
				'status'          => 'active',
				'style'           => (string) Settings::get( 'default_style', 'list' ),
				'layout'          => (string) Settings::get( 'listing_layout', 'default' ),
				'columns'         => 3,
				'show_filters'    => Settings::get( 'show_filters_default', true ) ? 'true' : 'false',
				'show_search'     => Settings::get( 'show_search_default', true ) ? 'true' : 'false',
				'show_pagination' => 'true',
				'show_excerpt'    => 'true',
				'show_sort'       => Settings::get( 'show_sort', false ) ? 'true' : 'false',

				// A named card template from Settings → Display, so two pages
				// can show two different cards.
				'template'        => '',

				// Post IDs to leave out, and a "related" shortcut: the name of a
				// filter (department, category...) whose terms are copied from
				// the job currently being viewed, with that job excluded.
				'exclude'         => '', // phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_exclude -- A handful of IDs, not a bulk exclusion.
				'related'         => '',
				'location_search' => '',
			)
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

		$atts = self::apply_related( $atts );

		$params = array(
			'page'            => $request['page'],
			'per_page'        => max( 1, (int) $atts['per_page'] ),
			'search'          => '' !== $request['search'] ? $request['search'] : (string) $atts['search'],
			'location_search' => '' !== $request['location_search'] ? $request['location_search'] : (string) $atts['location_search'],
			'orderby'         => '' !== $request['orderby'] ? $request['orderby'] : (string) $atts['orderby'],
			'order'           => '' !== $request['order'] ? $request['order'] : (string) $atts['order'],
			'status'          => '' !== $request['status'] ? $request['status'] : (string) $atts['status'],
			'exclude'         => (string) $atts['exclude'], // phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_exclude -- A handful of IDs, not a bulk exclusion.
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

		$layouts = array_keys( Layouts::listing_layouts() );
		$layout  = in_array( (string) $atts['layout'], $layouts, true )
			? (string) $atts['layout']
			: (string) Settings::get( 'listing_layout', 'default' );

		return Templates::get(
			'listing.php',
			array(
				'query'           => $query,
				'atts'            => $atts,
				'params'          => $params,
				'style'           => $style,
				'layout'          => $layout,
				// Not "template": Templates::render() already has a variable of
				// that name (the file being included), and EXTR_SKIP keeps it.
				'card_template'   => sanitize_key( (string) $atts['template'] ),
				'columns'         => $columns,
				'show_filters'    => $show_filters,
				'show_search'     => $show_search,
				'show_pagination' => self::truthy( $atts['show_pagination'] ),
				'show_excerpt'    => self::truthy( $atts['show_excerpt'] ),
				'show_sort'       => self::truthy( $atts['show_sort'] ),
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
			'page'            => 1,
			'search'          => '',
			'location_search' => '',
			'orderby'         => '',
			'order'           => '',
			'status'          => '',
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

		if ( $allow_search && isset( $_GET['jszr_location_q'] ) && Settings::get( 'show_location_search', false ) ) {
			$values['location_search'] = sanitize_text_field( wp_unslash( $_GET['jszr_location_q'] ) );
		}

		// The status filter is only honoured when the administrator offers it,
		// so a crafted URL cannot list closed jobs on a site that hides them.
		if ( $allow_filters && isset( $_GET['jszr_status'] ) && Settings::get( 'show_status_filter', false ) ) {
			$status = sanitize_key( wp_unslash( $_GET['jszr_status'] ) );

			if ( in_array( $status, array( 'active', 'closed', 'expired', 'inactive' ), true ) ) {
				$values['status'] = $status;
			}
		}

		// The sort control posts one value, "orderby:order", so the visitor gets
		// a single dropdown rather than two. Both halves are validated against
		// the same allow-lists the REST endpoint uses.
		if ( isset( $_GET['jszr_sort'] ) ) {
			$sort  = sanitize_text_field( wp_unslash( $_GET['jszr_sort'] ) );
			$parts = array_pad( explode( ':', $sort, 2 ), 2, '' );

			$orderby = sanitize_key( $parts[0] );
			$order   = strtolower( sanitize_key( $parts[1] ) );

			if ( in_array( $orderby, array( 'date', 'title', 'closing_date', 'posted_date' ), true ) ) {
				$values['orderby'] = $orderby;
			}

			if ( in_array( $order, array( 'asc', 'desc' ), true ) ) {
				$values['order'] = $order;
			}
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
	 * Turn `related="department"` into concrete filter values.
	 *
	 * On a single job page, `[zoho_jobs related="department" per_page="3"]`
	 * lists other jobs sharing the current job's department. The current job
	 * is always excluded. Outside a job page, or when the job has no terms in
	 * that taxonomy, the attribute is ignored and the listing is unfiltered.
	 *
	 * @param array $atts Listing attributes.
	 * @return array
	 */
	private static function apply_related( array $atts ) {
		$related = sanitize_key( (string) ( $atts['related'] ?? '' ) );

		if ( '' === $related ) {
			return $atts;
		}

		$post_id = get_the_ID();

		if ( ! $post_id || Post_Type::POST_TYPE !== get_post_type( $post_id ) ) {
			return $atts;
		}

		$exclude         = array_filter( array_map( 'absint', explode( ',', (string) $atts['exclude'] ) ) );
		$exclude[]       = (int) $post_id;
		$atts['exclude'] = implode( ',', array_unique( $exclude ) ); // phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_exclude -- Only the job being viewed.

		$map = REST_API::filter_map();

		if ( ! isset( $map[ $related ] ) || '' !== (string) $atts[ $related ] ) {
			return $atts;
		}

		$terms = get_the_terms( $post_id, $map[ $related ] );

		if ( is_array( $terms ) && ! empty( $terms ) ) {
			$atts[ $related ] = implode( ',', wp_list_pluck( $terms, 'slug' ) );
		}

		return $atts;
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

		$markup = Templates::apply_link(
			$post_id,
			array(
				'label' => (string) $atts['label'],
				'class' => (string) $atts['class'],
			)
		);

		if ( '' === $markup ) {
			return '';
		}

		Templates::enqueue_assets();

		return $markup;
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
				'fields' => implode( ',', (array) Settings::get( 'job_info_fields', array() ) ),
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
