<?php
/**
 * Global helper functions.
 *
 * These are intentionally thin wrappers so themes and other plugins have a
 * stable, prefixed API without needing to know the internal class names.
 *
 * @package JobsSyncForZohoRecruit
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'jszr_get_setting' ) ) {
	/**
	 * Read a single plugin setting.
	 *
	 * @param string $key      Setting key.
	 * @param mixed  $fallback Value returned when the setting is not stored.
	 * @return mixed
	 */
	function jszr_get_setting( $key, $fallback = null ) {
		return \JobsSyncForZohoRecruit\Settings::get( $key, $fallback );
	}
}

if ( ! function_exists( 'jszr_capability' ) ) {
	/**
	 * Capability required to administer the plugin.
	 *
	 * @return string
	 */
	function jszr_capability() {
		return \JobsSyncForZohoRecruit\Plugin::capability();
	}
}

if ( ! function_exists( 'jszr_verify_admin_request' ) ) {
	/**
	 * Guard an admin-post request handler.
	 *
	 * Every one of the plugin's `admin_post_jszr_*` handlers calls this before it
	 * reads a single request value: it checks the plugin capability and then the
	 * nonce, and stops the request outright when either fails. It is a global
	 * function rather than a class method so static analysis can see the nonce
	 * check at the call site.
	 *
	 * @param string $action Nonce action name.
	 * @return void
	 */
	function jszr_verify_admin_request( $action ) {
		if ( ! current_user_can( jszr_capability() ) ) {
			wp_die(
				esc_html__( 'You do not have permission to perform this action.', 'jobs-sync-for-zoho-recruit' ),
				403
			);
		}

		check_admin_referer( $action );
	}
}

if ( ! function_exists( 'jszr_admin_url' ) ) {
	/**
	 * Build the URL of one of the plugin's admin screens.
	 *
	 * The plugin's screens live under the job post type menu, so every site has
	 * a single place for everything Zoho Recruit related and editors keep access
	 * to the job list even without the plugin capability.
	 *
	 * @param string $page Page slug, e.g. "jszr-settings". Empty for the job list.
	 * @param array  $args Extra query arguments.
	 * @return string
	 */
	function jszr_admin_url( $page = '', array $args = array() ) {
		if ( '' !== $page ) {
			$args = array_merge( array( 'page' => $page ), $args );
		}

		return add_query_arg(
			$args,
			admin_url( 'edit.php?post_type=' . \JobsSyncForZohoRecruit\Post_Type::POST_TYPE )
		);
	}
}

if ( ! function_exists( 'jszr_is_connected' ) ) {
	/**
	 * Whether a usable Zoho refresh token is stored.
	 *
	 * @return bool
	 */
	function jszr_is_connected() {
		return \JobsSyncForZohoRecruit\plugin()->auth()->is_connected();
	}
}

if ( ! function_exists( 'jszr_get_job_meta' ) ) {
	/**
	 * Read one of the plugin's job meta values.
	 *
	 * @param int    $post_id Job post ID.
	 * @param string $key     Meta key with or without the plugin prefix.
	 * @return mixed
	 */
	function jszr_get_job_meta( $post_id, $key ) {
		return \JobsSyncForZohoRecruit\Job::get_meta( (int) $post_id, $key );
	}
}

if ( ! function_exists( 'jszr_get_apply_url' ) ) {
	/**
	 * Resolve the application URL for a job.
	 *
	 * @param int $post_id Job post ID.
	 * @return string Empty string when no URL can be resolved.
	 */
	function jszr_get_apply_url( $post_id ) {
		return \JobsSyncForZohoRecruit\Job::get_apply_url( (int) $post_id );
	}
}

if ( ! function_exists( 'jszr_apply_link' ) ) {
	/**
	 * Build the Apply link markup for a job.
	 *
	 * @param int   $post_id Job post ID.
	 * @param array $args    Optional: label, class.
	 * @return string Empty string when the job has no application URL.
	 */
	function jszr_apply_link( $post_id, array $args = array() ) {
		return \JobsSyncForZohoRecruit\Templates::apply_link( (int) $post_id, $args );
	}
}

if ( ! function_exists( 'jszr_is_job_active' ) ) {
	/**
	 * Whether a job is currently open for applications.
	 *
	 * @param int $post_id Job post ID.
	 * @return bool
	 */
	function jszr_is_job_active( $post_id ) {
		return \JobsSyncForZohoRecruit\Job::is_active( (int) $post_id );
	}
}

if ( ! function_exists( 'jszr_locate_template' ) ) {
	/**
	 * Locate a plugin template, honouring theme overrides.
	 *
	 * @param string $template Template file name, e.g. "card.php".
	 * @return string Absolute path.
	 */
	function jszr_locate_template( $template ) {
		return \JobsSyncForZohoRecruit\Templates::locate( $template );
	}
}

if ( ! function_exists( 'jszr_get_template' ) ) {
	/**
	 * Render a plugin template with the given variables.
	 *
	 * @param string $template Template file name.
	 * @param array  $args     Variables extracted into template scope.
	 * @return void
	 */
	function jszr_get_template( $template, array $args = array() ) {
		\JobsSyncForZohoRecruit\Templates::render( $template, $args );
	}
}

if ( ! function_exists( 'jszr_svg_allowed_html' ) ) {
	/**
	 * The SVG subset the icon helper is allowed to print.
	 *
	 * @return array
	 */
	function jszr_svg_allowed_html() {
		$attrs = array(
			'xmlns'           => true,
			'viewbox'         => true,
			'width'           => true,
			'height'          => true,
			'fill'            => true,
			'stroke'          => true,
			'stroke-width'    => true,
			'stroke-linecap'  => true,
			'stroke-linejoin' => true,
			'class'           => true,
			'aria-hidden'     => true,
			'focusable'       => true,
			'd'               => true,
			'cx'              => true,
			'cy'              => true,
			'r'               => true,
			'x'               => true,
			'y'               => true,
			'rx'              => true,
			'x1'              => true,
			'x2'              => true,
			'y1'              => true,
			'y2'              => true,
			'points'          => true,
		);

		return array(
			'svg'      => $attrs,
			'path'     => $attrs,
			'circle'   => $attrs,
			'rect'     => $attrs,
			'line'     => $attrs,
			'polyline' => $attrs,
		);
	}
}

if ( ! function_exists( 'jszr_icon' ) ) {
	/**
	 * Print one of the plugin's inline icons.
	 *
	 * Inline rather than an icon font or sprite: a handful of small paths cost
	 * less than another request, and they inherit currentcolor so they match
	 * whatever the theme is doing.
	 *
	 * @param string $name      Icon name.
	 * @param string $css_class Extra class for the svg element.
	 * @return void
	 */
	function jszr_icon( $name, $css_class = '' ) {
		$paths = array(
			'search'    => '<circle cx="11" cy="11" r="7"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line>',
			'location'  => '<path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"></path><circle cx="12" cy="10" r="3"></circle>',
			'briefcase' => '<rect x="2" y="7" width="20" height="14" rx="2"></rect><path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"></path>',
			'building'  => '<rect x="4" y="2" width="16" height="20" rx="2"></rect><line x1="9" y1="7" x2="9" y2="7"></line><line x1="15" y1="7" x2="15" y2="7"></line><line x1="9" y1="12" x2="9" y2="12"></line><line x1="15" y1="12" x2="15" y2="12"></line><line x1="10" y1="22" x2="14" y2="22"></line>',
			'clock'     => '<circle cx="12" cy="12" r="9"></circle><polyline points="12 7 12 12 15 14"></polyline>',
			'calendar'  => '<rect x="3" y="5" width="18" height="16" rx="2"></rect><line x1="16" y1="3" x2="16" y2="7"></line><line x1="8" y1="3" x2="8" y2="7"></line><line x1="3" y1="11" x2="21" y2="11"></line>',
			'arrow'     => '<line x1="5" y1="12" x2="19" y2="12"></line><polyline points="12 5 19 12 12 19"></polyline>',
			'back'      => '<line x1="19" y1="12" x2="5" y2="12"></line><polyline points="12 19 5 12 12 5"></polyline>',
			'inbox'     => '<path d="M22 12h-6l-2 3h-4l-2-3H2"></path><path d="M5.45 5.11 2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.45-6.89A2 2 0 0 0 16.76 4H7.24a2 2 0 0 0-1.79 1.11z"></path>',
			'alert'     => '<circle cx="12" cy="12" r="9"></circle><line x1="12" y1="8" x2="12" y2="12"></line><line x1="12" y1="16" x2="12" y2="16"></line>',
		);

		if ( ! isset( $paths[ $name ] ) ) {
			return;
		}

		$svg = sprintf(
			'<svg class="%s" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">%s</svg>',
			esc_attr( $css_class ),
			$paths[ $name ]
		);

		echo wp_kses( $svg, jszr_svg_allowed_html() );
	}
}

if ( ! function_exists( 'jszr_jobs_frontend_enabled' ) ) {
	/**
	 * Whether the plugin renders its own frontend for the job pages.
	 *
	 * False when "Disable default jobs frontend" is on, or when something has
	 * filtered jszr_frontend_enabled. Everything else -- the URLs, the post
	 * type, the REST API, the admin, the sync -- is unaffected either way.
	 *
	 * @return bool
	 */
	function jszr_jobs_frontend_enabled() {
		return \JobsSyncForZohoRecruit\Templates::frontend_enabled();
	}
}

if ( ! function_exists( 'jszr_get_jobs' ) ) {
	/**
	 * Query jobs for a custom frontend.
	 *
	 * The same query builder the REST endpoint, the shortcode and the block all
	 * use, so a hand-written template gets identical results -- including the
	 * active-only rule and the closing-date cutoff -- without repeating any of
	 * it. Returns plain arrays shaped exactly like the REST response, so markup
	 * can be written against one documented structure.
	 *
	 * @param array $args {
	 *     Optional. Query arguments.
	 *
	 *     @type int    $page            Page number. Default 1.
	 *     @type int    $per_page        Jobs per page.
	 *     @type string $search          Keyword, matched against title, body and job code.
	 *     @type string $orderby         date|title|closing_date|posted_date.
	 *     @type string $order           asc|desc.
	 *     @type string $status          active|any, or one stored status.
	 *     @type string $department      Term slug.
	 *     @type string $location        Term slug.
	 *     @type string $employment_type Term slug.
	 *     @type string $category        Term slug.
	 *     @type string $experience      Term slug.
	 * }
	 * @return array {
	 *     @type array[] $jobs  Prepared jobs.
	 *     @type int     $total Total matching jobs.
	 *     @type int     $pages Total pages.
	 *     @type int     $page  Current page.
	 * }
	 */
	function jszr_get_jobs( array $args = array() ) {
		$query_args = \JobsSyncForZohoRecruit\REST_API::build_query_args( $args );

		/**
		 * Filter the query arguments used by jszr_get_jobs().
		 *
		 * @param array $query_args WP_Query arguments.
		 * @param array $args       Arguments passed in.
		 */
		$query_args = (array) apply_filters( 'jszr_jobs_query_args', $query_args, $args );

		$query = new \WP_Query( $query_args );
		$jobs  = array();

		foreach ( $query->posts as $post ) {
			/**
			 * Filter one prepared job before it reaches a custom frontend.
			 *
			 * @param array    $job  Prepared job data.
			 * @param \WP_Post $post Job post.
			 */
			$jobs[] = (array) apply_filters(
				'jszr_job_data',
				\JobsSyncForZohoRecruit\REST_API::prepare_job( $post ),
				$post
			);
		}

		wp_reset_postdata();

		$per_page = max( 1, (int) $query_args['posts_per_page'] );

		return array(
			'jobs'  => $jobs,
			'total' => (int) $query->found_posts,
			'pages' => (int) ceil( $query->found_posts / $per_page ),
			'page'  => max( 1, (int) $query_args['paged'] ),
		);
	}
}

if ( ! function_exists( 'jszr_get_job' ) ) {
	/**
	 * One job, shaped the same way jszr_get_jobs() shapes them.
	 *
	 * @param int|\WP_Post|null $post Job post or ID. Defaults to the current post.
	 * @return array Empty array when the post is not a job.
	 */
	function jszr_get_job( $post = null ) {
		$post = get_post( $post );

		if ( ! $post instanceof \WP_Post || \JobsSyncForZohoRecruit\Post_Type::POST_TYPE !== $post->post_type ) {
			return array();
		}

		/** This filter is documented in includes/functions.php */
		return (array) apply_filters(
			'jszr_job_data',
			\JobsSyncForZohoRecruit\REST_API::prepare_job( $post ),
			$post
		);
	}
}
