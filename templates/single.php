<?php
/**
 * Single job.
 *
 * Used only when the active theme provides no single-zoho_job.php.
 *
 * Override by copying to yourtheme/jobs-sync-for-zoho-recruit/single.php
 *
 * @package JobsSyncForZohoRecruit
 */

defined( 'ABSPATH' ) || exit;

get_header();

while ( have_posts() ) :
	the_post();

	$jszr_post_id = get_the_ID();
	$jszr_active  = jszr_is_job_active( $jszr_post_id );
	$jszr_apply   = jszr_get_apply_url( $jszr_post_id );
	?>
	<main id="primary" class="jszr-single site-main">
		<article <?php post_class( 'jszr-job' ); ?>>
			<header class="jszr-job__header">
				<h1 class="jszr-job__title"><?php the_title(); ?></h1>

				<?php
				jszr_get_template(
					'job-meta.php',
					array(
						'post_id' => $jszr_post_id,
						'fields'  => (array) jszr_get_setting( 'job_info_fields', array() ),
					)
				);
				?>
			</header>

			<?php if ( ! $jszr_active ) : ?>
				<div class="jszr-job__closed" role="status">
					<p><?php esc_html_e( 'This position is closed and is no longer accepting applications.', 'jobs-sync-for-zoho-recruit' ); ?></p>
					<?php $jszr_archive = get_post_type_archive_link( 'zoho_job' ); ?>
					<?php if ( $jszr_archive ) : ?>
						<p>
							<a href="<?php echo esc_url( $jszr_archive ); ?>">
								<?php esc_html_e( 'See all open positions', 'jobs-sync-for-zoho-recruit' ); ?>
							</a>
						</p>
					<?php endif; ?>
				</div>
			<?php endif; ?>

			<div class="jszr-job__content">
				<?php the_content(); ?>
			</div>

			<?php if ( $jszr_active && '' !== $jszr_apply ) : ?>
				<p class="jszr-job__apply">
					<a class="jszr-apply-button" href="<?php echo esc_url( $jszr_apply ); ?>" target="_blank" rel="noopener nofollow">
						<?php
						$jszr_label = (string) jszr_get_setting( 'apply_label', '' );

						echo esc_html( '' !== $jszr_label ? $jszr_label : __( 'Apply Now', 'jobs-sync-for-zoho-recruit' ) );
						?>
						<span class="screen-reader-text"><?php esc_html_e( '(opens in a new tab)', 'jobs-sync-for-zoho-recruit' ); ?></span>
					</a>
				</p>
			<?php endif; ?>
		</article>
	</main>
	<?php
endwhile;

get_footer();
