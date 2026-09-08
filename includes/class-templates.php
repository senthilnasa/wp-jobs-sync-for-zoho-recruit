<?php
/**
 * Frontend templating.
 *
 * @package JobsSyncForZohoRecruit
 */

namespace JobsSyncForZohoRecruit;

defined( 'ABSPATH' ) || exit;

/**
 * Loads the plugin's templates, lets themes override them, and applies the
 * configured behaviour for expired jobs.
 */
class Templates {

	/**
	 * Directory name themes use to override plugin templates.
	 */
	const THEME_DIR = 'jobs-sync-for-zoho-recruit';

	/**
	 * Whether frontend assets have been enqueued this request.
	 *
	 * @var bool
	 */
	private static $assets_enqueued = false;

	/**
	 * Hook registration.
	 *
	 * @return void
	 */
	public static function init() {
		add_filter( 'template_include', array( __CLASS__, 'template_include' ), 20 );
		add_action( 'template_redirect', array( __CLASS__, 'handle_expired_job' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'register_assets' ) );

		// block.json names jszr-jobs as the block's style, so the handle has to
		// exist in the editor too or the preview renders unstyled.
		add_action( 'enqueue_block_editor_assets', array( __CLASS__, 'register_assets' ) );
		add_action( 'pre_get_posts', array( __CLASS__, 'filter_archive_query' ) );

		if ( function_exists( 'register_block_template' ) ) {
			add_action( 'init', array( __CLASS__, 'register_block_templates' ), 20 );
		}
	}

	/**
	 * Find a template, preferring the active theme's override.
	 *
	 * @param string $template Template file name, e.g. "card.php".
	 * @return string Absolute path.
	 */
	public static function locate( $template ) {
		$template = ltrim( (string) $template, '/' );
		$template = str_replace( array( '..', "\0" ), '', $template );

		// Only the namespaced directory counts as an override. Looking for a
		// bare "archive.php" or "single.php" would match the theme's own blog
		// templates and quietly render those instead of the job listing.
		$found = locate_template( array( self::THEME_DIR . '/' . $template ) );

		if ( '' === $found ) {
			$found = PLUGIN_DIR . 'templates/' . $template;
		}

		/**
		 * Filter the resolved path of a plugin template.
		 *
		 * @param string $found    Absolute path.
		 * @param string $template Template file name.
		 */
		return (string) apply_filters( 'jszr_template_path', $found, $template );
	}

	/**
	 * Render a template.
	 *
	 * @param string $template Template file name.
	 * @param array  $args     Variables made available to the template.
	 * @return void
	 */
	public static function render( $template, array $args = array() ) {
		$path = self::locate( $template );

		if ( ! is_readable( $path ) ) {
			return;
		}

		// phpcs:ignore WordPress.PHP.DontExtract.extract_extract -- Deliberate template scope.
		extract( $args, EXTR_SKIP );

		include $path;
	}

	/**
	 * Capture a rendered template as a string.
	 *
	 * @param string $template Template file name.
	 * @param array  $args     Variables.
	 * @return string
	 */
	public static function get( $template, array $args = array() ) {
		ob_start();
		self::render( $template, $args );

		return (string) ob_get_clean();
	}

	/**
	 * Fall back to the plugin's templates when the theme has none.
	 *
	 * @param string $template Resolved template path.
	 * @return string
	 */
	public static function template_include( $template ) {
		// Stand down entirely when the site has taken the frontend over. The
		// URL, the query and the post are all still there -- WordPress simply
		// resolves the template through its own hierarchy, so a theme file, a
		// page builder or a custom controller gets the request untouched.
		if ( ! self::frontend_enabled() ) {
			return $template;
		}

		// A block theme composes its own header and footer through templates and
		// template parts. Loading the plugin's classic PHP templates there would
		// call get_header()/get_footer() on a theme that has neither, which both
		// raises a deprecation notice and drops the site's real chrome. Block
		// themes get the block templates registered in register_block_templates()
		// instead, or the theme's own fallback.
		if ( function_exists( 'wp_is_block_theme' ) && wp_is_block_theme() ) {
			return $template;
		}

		if ( is_singular( Post_Type::POST_TYPE ) ) {
			if ( self::theme_has( array( 'single-' . Post_Type::POST_TYPE . '.php' ) ) ) {
				return $template;
			}

			self::enqueue_assets();

			/**
			 * Filter the template file used for a single job.
			 *
			 * Return an absolute path to render something else entirely.
			 *
			 * @param string $path     Absolute path to the template.
			 * @param string $template The theme's resolved template.
			 */
			return (string) apply_filters( 'jszr_job_template', self::locate( 'single.php' ), $template );
		}

		if ( is_post_type_archive( Post_Type::POST_TYPE ) || self::is_job_taxonomy() ) {
			$candidates = array( 'archive-' . Post_Type::POST_TYPE . '.php' );

			foreach ( array_keys( Post_Type::taxonomies() ) as $taxonomy ) {
				$candidates[] = 'taxonomy-' . $taxonomy . '.php';
			}

			if ( self::theme_has( $candidates ) ) {
				return $template;
			}

			self::enqueue_assets();

			/**
			 * Filter the template file used for the job archive.
			 *
			 * @param string $path     Absolute path to the template.
			 * @param string $template The theme's resolved template.
			 */
			return (string) apply_filters( 'jszr_jobs_template', self::locate( 'archive.php' ), $template );
		}

		return $template;
	}

	/**
	 * Whether the plugin should render its own frontend for job pages.
	 *
	 * False means the site has taken the job pages over: nothing else changes.
	 * The post type, the URLs, the REST API, the admin screens, the sync and
	 * the structured data all carry on exactly as before -- only the plugin's
	 * own templates and their assets stand down.
	 *
	 * @return bool
	 */
	public static function frontend_enabled() {
		$enabled = ! Settings::get( 'disable_default_jobs_frontend', false );

		/**
		 * Filter whether the plugin renders its default job frontend.
		 *
		 * Lets a site take over the job pages conditionally -- for one template,
		 * one language, or one section of the site -- without touching the
		 * setting.
		 *
		 * @param bool $enabled Whether the plugin renders its own frontend.
		 */
		return (bool) apply_filters( 'jszr_frontend_enabled', $enabled );
	}

	/**
	 * Whether the current request is one of the job taxonomy archives.
	 *
	 * @return bool
	 */
	public static function is_job_taxonomy() {
		foreach ( array_keys( Post_Type::taxonomies() ) as $taxonomy ) {
			if ( is_tax( $taxonomy ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether the theme provides one of the given templates.
	 *
	 * @param string[] $files Candidate file names.
	 * @return bool
	 */
	private static function theme_has( array $files ) {
		return '' !== locate_template( $files );
	}

	/**
	 * Keep inactive jobs out of the public archive and feed.
	 *
	 * @param \WP_Query $query Query object.
	 * @return void
	 */
	public static function filter_archive_query( $query ) {
		if ( is_admin() || ! $query->is_main_query() ) {
			return;
		}

		$is_job_archive = $query->is_post_type_archive( Post_Type::POST_TYPE );

		if ( ! $is_job_archive ) {
			foreach ( array_keys( Post_Type::taxonomies() ) as $taxonomy ) {
				if ( $query->is_tax( $taxonomy ) ) {
					$is_job_archive = true;
					break;
				}
			}
		}

		if ( ! $is_job_archive ) {
			return;
		}

		$meta_query = (array) $query->get( 'meta_query' );

		$meta_query[] = array(
			'key'     => Job::META_STATUS,
			'value'   => Job::active_statuses(),
			'compare' => 'IN',
		);

		$query->set( 'meta_query', $meta_query );
	}

	/**
	 * Apply the configured behaviour when a visitor opens an expired job.
	 *
	 * @return void
	 */
	public static function handle_expired_job() {
		if ( ! is_singular( Post_Type::POST_TYPE ) ) {
			return;
		}

		// This can redirect or send a 410, and either would fight a custom
		// implementation that has taken the job pages over. The job data and
		// jszr_is_job_active() are still there for a template that wants to
		// make the same decision itself.
		if ( ! self::frontend_enabled() ) {
			return;
		}

		$post_id = get_queried_object_id();

		if ( ! $post_id || Job::is_active( $post_id ) ) {
			return;
		}

		$behavior = (string) Settings::get( 'expired_behavior', 'notice' );

		if ( 'redirect' === $behavior ) {
			$archive = get_post_type_archive_link( Post_Type::POST_TYPE );

			if ( $archive ) {
				wp_safe_redirect( $archive, 302 );
				exit;
			}
		}

		if ( 'gone' === $behavior ) {
			status_header( 410 );
			nocache_headers();
		}

		add_action( 'wp_head', array( __CLASS__, 'noindex_expired' ), 1 );
	}

	/**
	 * Keep expired jobs out of search engines.
	 *
	 * @return void
	 */
	public static function noindex_expired() {
		echo '<meta name="robots" content="noindex, follow" />' . "\n";
	}

	// ----------------------------------------------------------------------
	// Assets
	// ----------------------------------------------------------------------

	/**
	 * Build the Apply link for a job.
	 *
	 * The single place this markup is produced. The shortcode, the block, the
	 * {apply_button} template token and the single job template all come here,
	 * so the target, the rel attributes and the accessible note cannot drift
	 * apart between them.
	 *
	 * @param int   $post_id Job post ID.
	 * @param array $args    Optional: label, class.
	 * @return string Empty when the job has no application URL.
	 */
	public static function apply_link( $post_id, array $args = array() ) {
		$post_id = (int) $post_id;
		$url     = Job::get_apply_url( $post_id );

		if ( '' === $url ) {
			return '';
		}

		$label = isset( $args['label'] ) ? trim( (string) $args['label'] ) : '';

		if ( '' === $label ) {
			$label = (string) Settings::get( 'apply_label', '' );
		}

		if ( '' === $label ) {
			$label = __( 'Apply Now', 'jobs-sync-for-zoho-recruit' );
		}

		$classes = 'jszr-apply-button';

		if ( ! empty( $args['class'] ) ) {
			$classes .= ' ' . sanitize_html_class( (string) $args['class'] );
		}

		// A new tab keeps the visitor's place in the listing, which is why it is
		// the default. Some sites would rather not spawn tabs, so it is a choice.
		$new_tab = 'same_tab' !== Settings::get( 'apply_target', 'new_tab' );

		$attributes = sprintf(
			'class="%1$s" href="%2$s" rel="%3$s"',
			esc_attr( $classes ),
			esc_url( $url ),
			esc_attr( $new_tab ? 'noopener nofollow' : 'nofollow' )
		);

		if ( $new_tab ) {
			$attributes .= ' target="_blank"';
		}

		// Announced only when it is true, because telling a screen reader about
		// a new tab that does not open is worse than saying nothing.
		$note = $new_tab
			? '<span class="screen-reader-text"> ' . esc_html__( '(opens in a new tab)', 'jobs-sync-for-zoho-recruit' ) . '</span>'
			: '';

		return sprintf(
			'<a %1$s>%2$s%3$s</a>',
			$attributes,
			esc_html( $label ),
			$note
		);
	}

	/**
	 * Register (but do not enqueue) frontend assets.
	 *
	 * @return void
	 */
	public static function register_assets() {
		wp_register_style(
			'jszr-jobs',
			PLUGIN_URL . 'public/css/jobs.css',
			array(),
			VERSION
		);

		wp_register_script(
			'jszr-jobs',
			PLUGIN_URL . 'public/js/jobs.js',
			array(),
			VERSION,
			true
		);

		// Only load the plugin's frontend assets on job pages it actually
		// renders. A site that has taken the pages over should not be shipped
		// stylesheet and script it does not use; shortcodes and blocks still
		// enqueue on demand through Shortcode::render().
		if ( ! self::frontend_enabled() ) {
			return;
		}

		if ( is_singular( Post_Type::POST_TYPE ) || is_post_type_archive( Post_Type::POST_TYPE ) || self::is_job_taxonomy() ) {
			self::enqueue_assets();
		}
	}

	/**
	 * Enqueue frontend assets exactly once.
	 *
	 * @return void
	 */
	public static function enqueue_assets() {
		if ( self::$assets_enqueued ) {
			return;
		}

		self::$assets_enqueued = true;

		if ( ! wp_style_is( 'jszr-jobs', 'registered' ) ) {
			self::register_assets();
		}

		wp_enqueue_style( 'jszr-jobs' );
		wp_enqueue_script( 'jszr-jobs' );

		// Strings for the states the script has to render itself, because a
		// failed request cannot come back through a PHP template.
		wp_localize_script(
			'jszr-jobs',
			'jszrJobsL10n',
			array(
				'errorTitle' => __( 'Unable to load jobs', 'jobs-sync-for-zoho-recruit' ),
				'errorBody'  => __( 'We could not load the current job openings. Please try again.', 'jobs-sync-for-zoho-recruit' ),
				'retry'      => __( 'Retry', 'jobs-sync-for-zoho-recruit' ),
				'loading'    => __( 'Loading jobs', 'jobs-sync-for-zoho-recruit' ),
			)
		);

		// Administrator CSS rides along with the plugin stylesheet, so it loads
		// only on pages that actually show jobs and always after the defaults.
		$custom_css = Layouts::custom_css();

		if ( '' !== $custom_css ) {
			wp_add_inline_style( 'jszr-jobs', $custom_css );
		}
	}

	// ----------------------------------------------------------------------
	// Block themes
	// ----------------------------------------------------------------------

	/**
	 * Register the bundled block templates on WordPress versions that support
	 * plugin-registered templates.
	 *
	 * @return void
	 */
	public static function register_block_templates() {
		if ( ! function_exists( 'register_block_template' ) || ! wp_is_block_theme() ) {
			return;
		}

		// These are the plugin's default frontend on a block theme, so they
		// stand down with the rest of it and the theme's own templates apply.
		if ( ! self::frontend_enabled() ) {
			return;
		}

		$templates = array(
			'single-' . Post_Type::POST_TYPE  => array(
				'title'       => __( 'Single Job', 'jobs-sync-for-zoho-recruit' ),
				'description' => __( 'Layout for a single job opening.', 'jobs-sync-for-zoho-recruit' ),
				'file'        => 'single-zoho_job.html',
			),
			'archive-' . Post_Type::POST_TYPE => array(
				'title'       => __( 'Jobs Archive', 'jobs-sync-for-zoho-recruit' ),
				'description' => __( 'Layout for the job listing archive.', 'jobs-sync-for-zoho-recruit' ),
				'file'        => 'archive-zoho_job.html',
			),
		);

		foreach ( $templates as $slug => $config ) {
			$path = PLUGIN_DIR . 'templates/block-templates/' . $config['file'];

			if ( ! is_readable( $path ) ) {
				continue;
			}

			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading a bundled plugin file.
			$content = file_get_contents( $path );

			if ( false === $content ) {
				continue;
			}

			register_block_template(
				'jobs-sync-for-zoho-recruit//' . $slug,
				array(
					'title'       => $config['title'],
					'description' => $config['description'],
					'content'     => $content,
					'post_types'  => array( Post_Type::POST_TYPE ),
				)
			);
		}
	}
}
