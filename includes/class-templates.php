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

		$found = locate_template(
			array(
				self::THEME_DIR . '/' . $template,
				$template,
			)
		);

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
		if ( is_singular( Post_Type::POST_TYPE ) ) {
			if ( self::theme_has( array( 'single-' . Post_Type::POST_TYPE . '.php' ) ) ) {
				return $template;
			}

			self::enqueue_assets();

			return self::locate( 'single.php' );
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

			return self::locate( 'archive.php' );
		}

		return $template;
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
