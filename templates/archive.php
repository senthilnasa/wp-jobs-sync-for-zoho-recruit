<?php
/**
 * Job archive.
 *
 * Used only when the active theme provides no archive-zoho_job.php.
 *
 * Override by copying to yourtheme/jobs-sync-for-zoho-recruit/archive.php
 *
 * @package JobsSyncForZohoRecruit
 */

defined( 'ABSPATH' ) || exit;

get_header();
?>
<main id="primary" class="jszr-archive site-main">
	<header class="jszr-archive__header">
		<h1 class="jszr-archive__title">
			<?php
			if ( is_tax() ) {
				single_term_title();
			} else {
				post_type_archive_title();
			}
			?>
		</h1>

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
		'page'            => max( 1, (int) get_query_var( 'paged' ) ),
	);

	// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only public filtering.
	foreach ( array_keys( $jszr_params ) as $jszr_key ) {
		if ( 'page' === $jszr_key ) {
			continue;
		}

		if ( isset( $_GET[ 'jszr_' . $jszr_key ] ) ) {
			$jszr_params[ $jszr_key ] = sanitize_text_field( wp_unslash( $_GET[ 'jszr_' . $jszr_key ] ) );
		}
	}
	// phpcs:enable WordPress.Security.NonceVerification.Recommended

	$jszr_filtering = false;

	foreach ( $jszr_params as $jszr_key => $jszr_value ) {
		if ( 'page' !== $jszr_key && '' !== $jszr_value ) {
			$jszr_filtering = true;
			break;
		}
	}

	if ( $jszr_filtering ) {
		// A filtered archive is rendered by the shared listing renderer.
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Renderer escapes its own output.
		echo \JobsSyncForZohoRecruit\Shortcode::render(
			array_merge(
				$jszr_params,
				array(
					'show_filters'    => 'true',
					'show_search'     => 'true',
					'show_pagination' => 'true',
					'per_page'        => (int) get_option( 'posts_per_page' ),
				)
			)
		);
	} else {
		jszr_get_template(
			'filters.php',
			array(
				'params'       => $jszr_params,
				'show_filters' => true,
				'show_search'  => true,
			)
		);

		if ( have_posts() ) {
			echo '<ul class="jszr-jobs__list">';

			while ( have_posts() ) {
				the_post();

				echo '<li class="jszr-jobs__item">';

				jszr_get_template(
					'card.php',
					array(
						'post_id'      => get_the_ID(),
						'show_excerpt' => true,
					)
				);

				echo '</li>';
			}

			echo '</ul>';

			the_posts_pagination(
				array(
					'mid_size'  => 2,
					'prev_text' => __( '&laquo; Previous', 'jobs-sync-for-zoho-recruit' ),
					'next_text' => __( 'Next &raquo;', 'jobs-sync-for-zoho-recruit' ),
				)
			);
		} else {
			jszr_get_template( 'no-results.php', array( 'params' => $jszr_params ) );
		}
	}
	?>
</main>
<?php
get_footer();
