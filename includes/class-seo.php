<?php
/**
 * Title and meta description integration.
 *
 * @package JobsSyncForZohoRecruit
 */

namespace JobsSyncForZohoRecruit;

defined( 'ABSPATH' ) || exit;

/**
 * Gives SEO plugins and themes a documented way to read and change the title
 * and description the plugin would use for a job.
 *
 * The plugin does not compete with an SEO plugin: when one is active it exposes
 * the values through filters and outputs nothing of its own.
 */
class SEO {

	/**
	 * Hook registration.
	 *
	 * @return void
	 */
	public static function init() {
		add_filter( 'document_title_parts', array( __CLASS__, 'filter_title_parts' ), 20 );
		add_action( 'wp_head', array( __CLASS__, 'output_meta_description' ), 2 );
	}

	/**
	 * The title a job should use.
	 *
	 * @param int $post_id Job post ID.
	 * @return string
	 */
	public static function job_title( $post_id ) {
		$post_id = (int) $post_id;
		$title   = wp_strip_all_tags( get_the_title( $post_id ) );

		$parts = array( $title );

		$location = self::first_term_name( $post_id, Post_Type::TAX_LOCATION );

		if ( '' !== $location ) {
			$parts[] = $location;
		}

		/**
		 * Filter the document title used for a single job.
		 *
		 * SEO plugins can call this to read the plugin's preferred title, and
		 * sites can return their own.
		 *
		 * @param string $title    Suggested title.
		 * @param int    $post_id  Job post ID.
		 * @param array  $parts    Title components the suggestion was built from.
		 */
		return (string) apply_filters(
			'jszr_job_title',
			implode( ' - ', array_filter( $parts, 'strlen' ) ),
			$post_id,
			$parts
		);
	}

	/**
	 * The meta description a job should use.
	 *
	 * @param int $post_id Job post ID.
	 * @return string
	 */
	public static function meta_description( $post_id ) {
		$post_id = (int) $post_id;
		$post    = get_post( $post_id );

		$description = '';

		if ( $post instanceof \WP_Post ) {
			$description = trim( wp_strip_all_tags( $post->post_excerpt ) );

			if ( '' === $description ) {
				$description = trim( wp_strip_all_tags( strip_shortcodes( $post->post_content ) ) );
			}
		}

		$description = wp_trim_words( $description, 30, '' );

		/**
		 * Filter the meta description used for a single job.
		 *
		 * @param string $description Suggested description.
		 * @param int    $post_id     Job post ID.
		 */
		return (string) apply_filters( 'jszr_meta_description', $description, $post_id );
	}

	/**
	 * Add the location to the browser title on single job pages.
	 *
	 * @param array $parts Title parts.
	 * @return array
	 */
	public static function filter_title_parts( $parts ) {
		if ( ! is_singular( Post_Type::POST_TYPE ) || self::seo_plugin_active() ) {
			return $parts;
		}

		$post_id = get_queried_object_id();

		if ( ! $post_id ) {
			return $parts;
		}

		$title = self::job_title( $post_id );

		if ( '' !== $title ) {
			$parts['title'] = $title;
		}

		return $parts;
	}

	/**
	 * Print a meta description on single job pages.
	 *
	 * @return void
	 */
	public static function output_meta_description() {
		if ( ! is_singular( Post_Type::POST_TYPE ) || self::seo_plugin_active() ) {
			return;
		}

		/**
		 * Filter whether the plugin prints its own meta description tag.
		 *
		 * The filters above stay available either way, so an SEO plugin can
		 * read the values without the plugin printing anything.
		 *
		 * @param bool $output Whether to print the tag.
		 */
		if ( ! apply_filters( 'jszr_output_meta_description', true ) ) {
			return;
		}

		$description = self::meta_description( get_queried_object_id() );

		if ( '' === trim( $description ) ) {
			return;
		}

		printf(
			'<meta name="description" content="%s" />' . "\n",
			esc_attr( $description )
		);
	}

	/**
	 * Whether an SEO plugin is managing titles and descriptions.
	 *
	 * @return bool
	 */
	public static function seo_plugin_active() {
		$active = defined( 'WPSEO_VERSION' )
			|| defined( 'RANK_MATH_VERSION' )
			|| defined( 'SEOPRESS_VERSION' )
			|| defined( 'AIOSEO_VERSION' )
			|| class_exists( '\The_SEO_Framework\Load' )
			|| function_exists( 'the_seo_framework' );

		/**
		 * Filter whether an SEO plugin owns the title and description.
		 *
		 * @param bool $active Whether one was detected.
		 */
		return (bool) apply_filters( 'jszr_seo_plugin_active', $active );
	}

	/**
	 * The name of a job's first term in a taxonomy.
	 *
	 * @param int    $post_id  Job post ID.
	 * @param string $taxonomy Taxonomy name.
	 * @return string
	 */
	private static function first_term_name( $post_id, $taxonomy ) {
		$terms = get_the_terms( $post_id, $taxonomy );

		if ( ! is_array( $terms ) || empty( $terms ) ) {
			return '';
		}

		// For the hierarchical location taxonomy the deepest term is the most
		// specific, and the most useful thing to put in a title.
		$deepest = $terms[0];

		foreach ( $terms as $term ) {
			if ( (int) $term->parent > (int) $deepest->parent ) {
				$deepest = $term;
			}
		}

		return (string) $deepest->name;
	}
}
