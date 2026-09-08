<?php
/**
 * A single job card in a listing.
 *
 * Override by copying to yourtheme/jobs-sync-for-zoho-recruit/card.php
 *
 * @package JobsSyncForZohoRecruit
 *
 * @var int  $post_id      Job post ID.
 * @var bool $show_excerpt Whether to show the excerpt.
 */

defined( 'ABSPATH' ) || exit;

/*
 * An administrator can choose a different layout, or write their own, on
 * Settings → Display. Those are token templates rather than PHP, so they are
 * rendered here and this file's markup is used only for the default layout.
 * A theme override of this file still wins over both.
 */
$jszr_layout_template = \JobsSyncForZohoRecruit\Layouts::listing_template( isset( $layout ) ? (string) $layout : '' );

if ( '' !== $jszr_layout_template ) {
	echo wp_kses(
		\JobsSyncForZohoRecruit\Template_Tags::render( $jszr_layout_template, $post_id ),
		\JobsSyncForZohoRecruit\Layouts::allowed_html()
	);

	return;
}

$jszr_code    = (string) jszr_get_job_meta( $post_id, '_jszr_job_code' );
$jszr_closing = (string) jszr_get_job_meta( $post_id, '_zoho_recruit_closing_date' );
$jszr_remote  = (string) jszr_get_job_meta( $post_id, '_zoho_recruit_remote' );
$jszr_title   = get_the_title( $post_id );
$jszr_link    = get_permalink( $post_id );

/**
 * Build a comma separated list of term names.
 *
 * @param mixed $terms Terms or WP_Error.
 * @return string
 */
$jszr_term_names = static function ( $terms ) {
	if ( ! is_array( $terms ) ) {
		return '';
	}

	return implode( ', ', wp_list_pluck( $terms, 'name' ) );
};

/*
 * Each fact carries its own icon, so the row reads at a glance instead of as
 * one run-on line of separated text.
 */
$jszr_facts = array_filter(
	array(
		array( 'location', $jszr_term_names( get_the_terms( $post_id, 'zoho_job_location' ) ) ),
		array( 'briefcase', $jszr_term_names( get_the_terms( $post_id, 'zoho_job_employment_type' ) ) ),
		array( 'building', $jszr_term_names( get_the_terms( $post_id, 'zoho_job_department' ) ) ),
		array( 'clock', $jszr_term_names( get_the_terms( $post_id, 'zoho_job_experience' ) ) ),
	),
	static function ( $fact ) {
		return '' !== $fact[1];
	}
);

$jszr_is_remote = '' !== $jszr_remote && ! in_array( strtolower( $jszr_remote ), array( 'no', 'false', '0' ), true );
?>
<article class="jszr-job-card" id="jszr-job-<?php echo esc_attr( (string) $post_id ); ?>">
	<div class="jszr-job-card__head">
		<h3 class="jszr-job-card__title">
			<a href="<?php echo esc_url( $jszr_link ); ?>"><?php echo esc_html( $jszr_title ); ?></a>
		</h3>

		<?php if ( '' !== $jszr_code ) : ?>
			<p class="jszr-job-card__code">
				<?php
				printf(
					/* translators: %s: job reference code. */
					esc_html__( 'Job Code: %s', 'jobs-sync-for-zoho-recruit' ),
					esc_html( $jszr_code )
				);
				?>
			</p>
		<?php endif; ?>
	</div>

	<?php if ( ! empty( $jszr_facts ) || $jszr_is_remote ) : ?>
		<ul class="jszr-facts">
			<?php foreach ( $jszr_facts as $jszr_fact ) : ?>
				<li class="jszr-facts__item">
					<?php jszr_icon( $jszr_fact[0], 'jszr-facts__icon' ); ?>
					<span class="jszr-facts__text"><?php echo esc_html( $jszr_fact[1] ); ?></span>
				</li>
			<?php endforeach; ?>

			<?php if ( $jszr_is_remote ) : ?>
				<li class="jszr-facts__item">
					<span class="jszr-badge jszr-badge--remote">
						<?php esc_html_e( 'Remote', 'jobs-sync-for-zoho-recruit' ); ?>
					</span>
				</li>
			<?php endif; ?>
		</ul>
	<?php endif; ?>

	<?php if ( ! empty( $show_excerpt ) ) : ?>
		<?php $jszr_excerpt = wp_strip_all_tags( get_the_excerpt( $post_id ) ); ?>
		<?php if ( '' !== $jszr_excerpt ) : ?>
			<p class="jszr-job-card__excerpt"><?php echo esc_html( wp_trim_words( $jszr_excerpt, 26, '&hellip;' ) ); ?></p>
		<?php endif; ?>
	<?php endif; ?>

	<p class="jszr-job-card__footer">
		<span class="jszr-job-card__link">
			<?php esc_html_e( 'View Job', 'jobs-sync-for-zoho-recruit' ); ?>
			<?php jszr_icon( 'arrow', 'jszr-facts__icon' ); ?>
		</span>

		<?php if ( '' !== $jszr_closing ) : ?>
			<?php $jszr_closing_stamp = strtotime( $jszr_closing ); ?>
			<span class="jszr-job-card__closing">
				<?php
				printf(
					/* translators: %s: closing date. */
					esc_html__( 'Closes %s', 'jobs-sync-for-zoho-recruit' ),
					esc_html( $jszr_closing_stamp ? wp_date( (string) get_option( 'date_format' ), $jszr_closing_stamp ) : $jszr_closing )
				);
				?>
			</span>
		<?php endif; ?>
	</p>
</article>
