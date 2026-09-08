<?php
/**
 * Single job.
 *
 * Used only when the active theme provides no single-zoho_job.php.
 *
 * Two columns on a wide screen: the description reads at a comfortable measure
 * on the left, and the facts and the apply button sit in a panel on the right
 * that stays in view while the description scrolls. One column below that, with
 * the apply panel moved above the description so the call to action is not
 * buried under a long list of responsibilities on a phone.
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
	$jszr_code    = (string) jszr_get_job_meta( $jszr_post_id, '_jszr_job_code' );
	$jszr_archive = get_post_type_archive_link( \JobsSyncForZohoRecruit\Post_Type::POST_TYPE );
	?>
	<main id="primary" class="jszr-single jszr-archive jszr-scope site-main">
		<?php
		/**
		 * Fires on a single job, before the job.
		 *
		 * @param int $post_id Job post ID.
		 */
		do_action( 'jszr_before_job', $jszr_post_id );
		?>

		<article <?php post_class( 'jszr-job' ); ?>>
			<?php if ( $jszr_archive ) : ?>
				<a class="jszr-job__back" href="<?php echo esc_url( $jszr_archive ); ?>">
					<?php jszr_icon( 'back', 'jszr-facts__icon' ); ?>
					<?php esc_html_e( 'Back to Jobs', 'jobs-sync-for-zoho-recruit' ); ?>
				</a>
			<?php endif; ?>

			<header class="jszr-job__header">
				<h1 class="jszr-job__title"><?php the_title(); ?></h1>

				<?php if ( '' !== $jszr_code ) : ?>
					<p class="jszr-job__code">
						<?php
						printf(
							/* translators: %s: job reference code. */
							esc_html__( 'Job Code: %s', 'jobs-sync-for-zoho-recruit' ),
							esc_html( $jszr_code )
						);
						?>
					</p>
				<?php endif; ?>

				<?php if ( ! $jszr_active ) : ?>
					<div class="jszr-job__closed" role="status">
						<div>
							<p><?php esc_html_e( 'This position is closed and is no longer accepting applications.', 'jobs-sync-for-zoho-recruit' ); ?></p>
							<?php if ( $jszr_archive ) : ?>
								<p>
									<a href="<?php echo esc_url( $jszr_archive ); ?>">
										<?php esc_html_e( 'See all open positions', 'jobs-sync-for-zoho-recruit' ); ?>
									</a>
								</p>
							<?php endif; ?>
						</div>
					</div>
				<?php endif; ?>
			</header>

			<div class="jszr-job__layout">
				<div class="jszr-job__body jszr-job__content">
					<?php the_content(); ?>
				</div>

				<aside class="jszr-job__aside">
					<div class="jszr-job__panel">
						<?php if ( $jszr_active && '' !== $jszr_apply ) : ?>
							<h2 class="jszr-job__panel-title"><?php esc_html_e( 'Apply', 'jobs-sync-for-zoho-recruit' ); ?></h2>

							<?php
							// Built by Templates::apply_link(), which is also what
							// the shortcode, the block and {apply_button} use.
							echo wp_kses(
								jszr_apply_link( $jszr_post_id ),
								\JobsSyncForZohoRecruit\Layouts::allowed_html()
							);
							?>

							<p class="jszr-apply-note">
								<?php esc_html_e( 'Applications are handled on Zoho Recruit. You will be taken there to complete yours.', 'jobs-sync-for-zoho-recruit' ); ?>
							</p>
						<?php endif; ?>

						<h2 class="jszr-job__panel-title">
							<?php esc_html_e( 'Position details', 'jobs-sync-for-zoho-recruit' ); ?>
						</h2>

						<?php
						jszr_get_template(
							'job-meta.php',
							array(
								'post_id' => $jszr_post_id,
								'fields'  => (array) jszr_get_setting( 'job_info_fields', array() ),
							)
						);
						?>
					</div>
				</aside>
			</div>
		</article>

		<?php
		/**
		 * Fires on a single job, after the job.
		 *
		 * @param int $post_id Job post ID.
		 */
		do_action( 'jszr_after_job', $jszr_post_id );
		?>
	</main>
	<?php
endwhile;

get_footer();
