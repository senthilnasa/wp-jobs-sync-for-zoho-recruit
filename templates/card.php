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

$jszr_department = get_the_terms( $post_id, 'zoho_job_department' );
$jszr_location   = get_the_terms( $post_id, 'zoho_job_location' );
$jszr_type       = get_the_terms( $post_id, 'zoho_job_employment_type' );
$jszr_experience = get_the_terms( $post_id, 'zoho_job_experience' );
$jszr_closing    = (string) jszr_get_job_meta( $post_id, '_zoho_recruit_closing_date' );
$jszr_remote     = (string) jszr_get_job_meta( $post_id, '_zoho_recruit_remote' );

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

$jszr_facts = array_filter(
	array(
		$jszr_term_names( $jszr_department ),
		$jszr_term_names( $jszr_location ),
		$jszr_term_names( $jszr_type ),
		$jszr_term_names( $jszr_experience ),
	),
	'strlen'
);
?>
<article class="jszr-job-card" id="jszr-job-<?php echo esc_attr( (string) $post_id ); ?>">
	<h3 class="jszr-job-card__title">
		<a href="<?php echo esc_url( get_permalink( $post_id ) ); ?>"><?php echo esc_html( get_the_title( $post_id ) ); ?></a>
	</h3>

	<?php if ( ! empty( $jszr_facts ) ) : ?>
		<p class="jszr-job-card__meta">
			<?php echo esc_html( implode( ' · ', $jszr_facts ) ); ?>
		</p>
	<?php endif; ?>

	<?php
	/*
	 * The stored value is a flag, not a label: printing it raw put a bare
	 * "Yes" on the card. Show a word that means something on its own.
	 */
	if ( '' !== $jszr_remote && ! in_array( strtolower( $jszr_remote ), array( 'no', 'false', '0' ), true ) ) :
		?>
		<p class="jszr-job-card__remote"><?php esc_html_e( 'Remote', 'jobs-sync-for-zoho-recruit' ); ?></p>
	<?php endif; ?>

	<?php if ( ! empty( $show_excerpt ) ) : ?>
		<?php $jszr_excerpt = wp_strip_all_tags( get_the_excerpt( $post_id ) ); ?>
		<?php if ( '' !== $jszr_excerpt ) : ?>
			<p class="jszr-job-card__excerpt"><?php echo esc_html( wp_trim_words( $jszr_excerpt, 28, '&hellip;' ) ); ?></p>
		<?php endif; ?>
	<?php endif; ?>

	<p class="jszr-job-card__footer">
		<a class="jszr-job-card__link" href="<?php echo esc_url( get_permalink( $post_id ) ); ?>">
			<?php esc_html_e( 'View Job', 'jobs-sync-for-zoho-recruit' ); ?>
			<span class="screen-reader-text"><?php echo esc_html( get_the_title( $post_id ) ); ?></span>
		</a>

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
