<?php
/**
 * Job archive.
 *
 * Used only when the active theme provides no archive-zoho_job.php.
 *
 * The listing is rendered by the shared renderer whether or not a filter is
 * set. It used to loop the main query when nothing was filtered and hand over
 * to the renderer only once a filter appeared, which meant the plain archive
 * and the filtered archive were two different code paths with two different
 * sets of bugs -- and the plain one had no result count, no sort control and a
 * thinner empty state. One path now, so what a visitor sees on /jobs is the
 * same thing the shortcode and the block produce.
 *
 * Override by copying to yourtheme/jobs-sync-for-zoho-recruit/archive.php
 *
 * @package JobsSyncForZohoRecruit
 */

defined( 'ABSPATH' ) || exit;

get_header();
?>
<main id="primary" class="jszr-archive jszr-scope site-main">
	<?php
	if ( ! is_tax() ) {
		jszr_get_template( 'hero.php', array( 'counts' => \JobsSyncForZohoRecruit\Job::counts() ) );
	}
	?>

	<header class="jszr-archive__header">
		<?php if ( is_tax() ) : ?>
			<h1 class="jszr-archive__title"><?php single_term_title(); ?></h1>
		<?php endif; ?>

		<?php
		$jszr_description = is_tax() ? term_description() : get_the_post_type_description();

		if ( $jszr_description ) {
			echo '<div class="jszr-archive__description">' . wp_kses_post( $jszr_description ) . '</div>';
		}
		?>
	</header>

	<?php
	$jszr_params = array(
		'search'          => '',
		'department'      => '',
		'location'        => '',
		'employment_type' => '',
		'category'        => '',
		'experience'      => '',
	);

	// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only public filtering.
	foreach ( array_keys( $jszr_params ) as $jszr_key ) {
		if ( isset( $_GET[ 'jszr_' . $jszr_key ] ) ) {
			$jszr_params[ $jszr_key ] = sanitize_text_field( wp_unslash( $_GET[ 'jszr_' . $jszr_key ] ) );
		}
	}
	// phpcs:enable WordPress.Security.NonceVerification.Recommended

	/*
	 * On a taxonomy archive the term is the filter, so it is passed to the
	 * renderer rather than left to the main query.
	 */
	if ( is_tax() ) {
		$jszr_term = get_queried_object();

		if ( $jszr_term instanceof WP_Term ) {
			foreach ( \JobsSyncForZohoRecruit\REST_API::filter_map() as $jszr_param => $jszr_taxonomy ) {
				if ( $jszr_taxonomy === $jszr_term->taxonomy ) {
					$jszr_params[ $jszr_param ] = $jszr_term->slug;
					break;
				}
			}
		}
	}

	/**
	 * Fires on the job archive, before the listing.
	 *
	 * @param array $params Filter parameters read from the request.
	 */
	do_action( 'jszr_before_jobs', $jszr_params );

	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Renderer escapes its own output.
	echo \JobsSyncForZohoRecruit\Shortcode::render(
		array_merge(
			$jszr_params,
			array(
				'show_filters'    => 'true',
				'show_search'     => 'true',
				'show_sort'       => 'true',
				'show_pagination' => 'true',
				'show_excerpt'    => 'true',
				'style'           => 'grid',
				'per_page'        => (int) get_option( 'posts_per_page' ),
			)
		)
	);

	/**
	 * Fires on the job archive, after the listing.
	 *
	 * @param array $params Filter parameters read from the request.
	 */
	do_action( 'jszr_after_jobs', $jszr_params );
	?>
</main>
<?php
get_footer();
