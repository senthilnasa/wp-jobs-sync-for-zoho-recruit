<?php
/**
 * Block registration.
 *
 * @package JobsSyncForZohoRecruit
 */

namespace JobsSyncForZohoRecruit;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the editor block, which is server rendered so it shares exactly one
 * renderer with the shortcode.
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
	 * Register the block type.
	 *
	 * @return void
	 */
	public static function register() {
		if ( ! function_exists( 'register_block_type' ) ) {
			return;
		}

		$dir = PLUGIN_DIR . 'blocks/jobs';

		if ( ! is_readable( $dir . '/block.json' ) ) {
			return;
		}

		register_block_type(
			$dir,
			array(
				'render_callback' => array( __CLASS__, 'render' ),
			)
		);

		if ( function_exists( 'wp_set_script_translations' ) ) {
			wp_set_script_translations(
				'jobs-sync-for-zoho-recruit-jobs-editor-script',
				'jobs-sync-for-zoho-recruit',
				PLUGIN_DIR . 'languages'
			);
		}
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

		$wrapper_attributes = function_exists( 'get_block_wrapper_attributes' )
			? get_block_wrapper_attributes( array( 'class' => 'jszr-block' ) )
			: 'class="jszr-block"';

		return sprintf(
			'<div %1$s>%2$s</div>',
			$wrapper_attributes,
			Shortcode::render( $atts )
		);
	}
}
