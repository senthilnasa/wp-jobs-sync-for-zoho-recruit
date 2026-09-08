<?php
/**
 * Shown when a job listing has no matches.
 *
 * This is the "we looked and there is genuinely nothing" state. A failure to
 * reach the data is a different template, error.php, so a visitor is never
 * told there are no jobs when the truth is that something broke.
 *
 * Override by copying to yourtheme/jobs-sync-for-zoho-recruit/no-results.php
 *
 * @package JobsSyncForZohoRecruit
 *
 * @var array $params Listing parameters.
 */

defined( 'ABSPATH' ) || exit;

$jszr_searched = ! empty( $params['search'] );
$jszr_filtered = false;

foreach ( $params as $jszr_key => $jszr_value ) {
	if ( ! in_array( $jszr_key, array( 'page', 'per_page', 'search', 'orderby', 'order', 'status' ), true ) && '' !== $jszr_value ) {
		$jszr_filtered = true;
		break;
	}
}
?>
<div class="jszr-state jszr-state--empty">
	<?php jszr_icon( 'inbox', 'jszr-state__icon' ); ?>

	<h3 class="jszr-state__title">
		<?php if ( $jszr_searched || $jszr_filtered ) : ?>
			<?php esc_html_e( 'No matching positions', 'jobs-sync-for-zoho-recruit' ); ?>
		<?php else : ?>
			<?php esc_html_e( 'No open positions right now', 'jobs-sync-for-zoho-recruit' ); ?>
		<?php endif; ?>
	</h3>

	<p class="jszr-state__body">
		<?php if ( $jszr_searched ) : ?>
			<?php
			printf(
				/* translators: %s: search term. */
				esc_html__( 'Nothing matches “%s”. Try a different keyword, or clear the filters to see everything that is open.', 'jobs-sync-for-zoho-recruit' ),
				esc_html( (string) $params['search'] )
			);
			?>
		<?php elseif ( $jszr_filtered ) : ?>
			<?php esc_html_e( 'No positions match the filters you have chosen. Try widening them to see more.', 'jobs-sync-for-zoho-recruit' ); ?>
		<?php else : ?>
			<?php esc_html_e( 'We do not have any open positions at the moment. Please check back soon for new opportunities.', 'jobs-sync-for-zoho-recruit' ); ?>
		<?php endif; ?>
	</p>

	<?php if ( $jszr_searched || $jszr_filtered ) : ?>
		<?php $jszr_archive = get_post_type_archive_link( \JobsSyncForZohoRecruit\Post_Type::POST_TYPE ); ?>
		<?php if ( $jszr_archive ) : ?>
			<a class="jszr-button jszr-button--secondary" href="<?php echo esc_url( $jszr_archive ); ?>">
				<?php esc_html_e( 'View all positions', 'jobs-sync-for-zoho-recruit' ); ?>
			</a>
		<?php endif; ?>
	<?php endif; ?>
</div>
