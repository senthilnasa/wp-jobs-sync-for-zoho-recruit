<?php
/**
 * Block registration.
 *
 * @package JobsSyncForZohoRecruit
 */

namespace JobsSyncForZohoRecruit;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the editor blocks. All three are server rendered, so each shares
 * exactly one renderer with its shortcode equivalent.
 */
class Blocks {

	/**
	 * Hook registration.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'register' ), 20 );
	}

	/**
	 * The blocks this plugin registers.
	 *
	 * @return array<string,array{dir:string,handle:string,callback:string}>
	 */
	private static function definitions() {
		return array(
			'jobs'         => array(
				'dir'      => 'blocks/jobs',
				'handle'   => 'jobs-sync-for-zoho-recruit-jobs-editor-script',
				'callback' => 'render',
			),
			'job-meta'     => array(
				'dir'      => 'blocks/job-meta',
				'handle'   => 'jobs-sync-for-zoho-recruit-job-meta-editor-script',
				'callback' => 'render_job_meta',
			),
			'apply-button' => array(
				'dir'      => 'blocks/apply-button',
				'handle'   => 'jobs-sync-for-zoho-recruit-apply-button-editor-script',
				'callback' => 'render_apply_button',
			),
		);
	}

	/**
	 * Register every block type.
	 *
	 * @return void
	 */
	public static function register() {
		if ( ! function_exists( 'register_block_type' ) ) {
			return;
		}

		foreach ( self::definitions() as $definition ) {
			$dir = PLUGIN_DIR . $definition['dir'];

			if ( ! is_readable( $dir . '/block.json' ) ) {
				continue;
			}

			register_block_type(
				$dir,
				array(
					'render_callback' => array( __CLASS__, $definition['callback'] ),
				)
			);

			if ( function_exists( 'wp_set_script_translations' ) ) {
				wp_set_script_translations(
					$definition['handle'],
					'jobs-sync-for-zoho-recruit',
					PLUGIN_DIR . 'languages'
				);
			}
		}
	}

	/**
	 * Resolve the job a context-dependent block is rendering for.
	 *
	 * Inside a template or query loop the post ID arrives as block context. In
	 * the editor preview it arrives as a query argument on the ServerSideRender
	 * request instead, so both are accepted, and both are validated to be a job.
	 *
	 * @param \WP_Block|null $block Block instance, when one was passed.
	 * @return int Post ID, or 0 when this is not a job.
	 */
	private static function resolve_post_id( $block = null ) {
		$post_id = 0;

		if ( $block instanceof \WP_Block && ! empty( $block->context['postId'] ) ) {
			$post_id = (int) $block->context['postId'];
		}

		if ( ! $post_id ) {
			$post_id = (int) get_the_ID();
		}

		if ( ! $post_id && is_admin() ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only preview lookup; the value is validated below and only ever used to read a public post.
			$post_id = isset( $_GET['post_id'] ) ? (int) $_GET['post_id'] : 0;
		}

		if ( ! $post_id || Post_Type::POST_TYPE !== get_post_type( $post_id ) ) {
			return 0;
		}

		return $post_id;
	}

	/**
	 * Server-side renderer for the Job Details block.
	 *
	 * @param array          $attributes Block attributes.
	 * @param string         $content    Inner content, unused.
	 * @param \WP_Block|null $block      Block instance.
	 * @return string
	 */
	public static function render_job_meta( $attributes, $content = '', $block = null ) {
		$attributes = is_array( $attributes ) ? $attributes : array();
		$post_id    = self::resolve_post_id( $block );

		if ( ! $post_id ) {
			return '';
		}

		$atts   = array( 'id' => $post_id );
		$fields = isset( $attributes['fields'] ) ? trim( (string) $attributes['fields'] ) : '';

		// An empty attribute must fall through to the shortcode's own default
		// list rather than overriding it with nothing.
		if ( '' !== $fields ) {
			$atts['fields'] = $fields;
		}

		$markup = Shortcode::render_meta( $atts );

		if ( '' === $markup ) {
			return '';
		}

		return sprintf( '<div %1$s>%2$s</div>', self::wrapper(), $markup );
	}

	/**
	 * Server-side renderer for the Apply Button block.
	 *
	 * @param array          $attributes Block attributes.
	 * @param string         $content    Inner content, unused.
	 * @param \WP_Block|null $block      Block instance.
	 * @return string
	 */
	public static function render_apply_button( $attributes, $content = '', $block = null ) {
		$attributes = is_array( $attributes ) ? $attributes : array();
		$post_id    = self::resolve_post_id( $block );

		if ( ! $post_id ) {
			return '';
		}

		// A closed job has nothing to apply to, and the single template already
		// explains why. Rendering a dead button would be worse than nothing.
		if ( ! Job::is_active( $post_id ) ) {
			return '';
		}

		$markup = Shortcode::render_apply(
			array(
				'id'    => $post_id,
				'label' => isset( $attributes['label'] ) ? (string) $attributes['label'] : '',
			)
		);

		if ( '' === $markup ) {
			return '';
		}

		return sprintf( '<div %1$s>%2$s</div>', self::wrapper(), $markup );
	}

	/**
	 * Block wrapper attributes, with a graceful fallback.
	 *
	 * @param array $extra Extra attributes.
	 * @return string
	 */
	private static function wrapper( array $extra = array() ) {
		$extra = wp_parse_args( $extra, array( 'class' => 'jszr-block' ) );

		return function_exists( 'get_block_wrapper_attributes' )
			? get_block_wrapper_attributes( $extra )
			: 'class="' . esc_attr( $extra['class'] ) . '"';
	}

	/**
	 * Server-side renderer for the block.
	 *
	 * @param array $attributes Block attributes.
	 * @return string
	 */
	public static function render( $attributes ) {
		$attributes = is_array( $attributes ) ? $attributes : array();

		$atts = array(
			'per_page'        => isset( $attributes['perPage'] ) ? (int) $attributes['perPage'] : (int) Settings::get( 'rest_per_page', 20 ),
			'search'          => isset( $attributes['search'] ) ? sanitize_text_field( (string) $attributes['search'] ) : '',
			'department'      => isset( $attributes['department'] ) ? sanitize_text_field( (string) $attributes['department'] ) : '',
			'location'        => isset( $attributes['location'] ) ? sanitize_text_field( (string) $attributes['location'] ) : '',
			'employment_type' => isset( $attributes['employmentType'] ) ? sanitize_text_field( (string) $attributes['employmentType'] ) : '',
			'category'        => isset( $attributes['category'] ) ? sanitize_text_field( (string) $attributes['category'] ) : '',
			'experience'      => isset( $attributes['experience'] ) ? sanitize_text_field( (string) $attributes['experience'] ) : '',
			'orderby'         => isset( $attributes['orderby'] ) ? sanitize_key( (string) $attributes['orderby'] ) : 'date',
			'order'           => isset( $attributes['order'] ) ? sanitize_key( (string) $attributes['order'] ) : 'desc',
			'style'           => isset( $attributes['listingStyle'] ) ? sanitize_key( (string) $attributes['listingStyle'] ) : (string) Settings::get( 'default_style', 'list' ),
			'columns'         => isset( $attributes['columns'] ) ? (int) $attributes['columns'] : 3,
			'show_filters'    => ! empty( $attributes['showFilters'] ) ? 'true' : 'false',
			'show_search'     => ! empty( $attributes['showSearch'] ) ? 'true' : 'false',
			'show_pagination' => ! empty( $attributes['showPagination'] ) ? 'true' : 'false',
			'show_excerpt'    => ! empty( $attributes['showExcerpt'] ) ? 'true' : 'false',
			'status'          => 'active',
		);

		return sprintf(
			'<div %1$s>%2$s</div>',
			self::wrapper(),
			Shortcode::render( $atts )
		);
	}
}
