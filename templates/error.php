<?php
/**
 * Shown when the listing could not be loaded.
 *
 * Kept separate from no-results.php on purpose. "There are no jobs" and "we
 * could not find out whether there are any jobs" are different things, and
 * telling a candidate the first when the second is true costs applications.
 *
 * Override by copying to yourtheme/jobs-sync-for-zoho-recruit/error.php
 *
 * @package JobsSyncForZohoRecruit
 *
 * @var string $message Optional detail to show under the heading.
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="jszr-state jszr-state--error" role="alert">
	<?php jszr_icon( 'alert', 'jszr-state__icon' ); ?>

	<h3 class="jszr-state__title"><?php esc_html_e( 'Unable to load jobs', 'jobs-sync-for-zoho-recruit' ); ?></h3>

	<p class="jszr-state__body">
		<?php
		if ( ! empty( $message ) ) {
			echo esc_html( (string) $message );
		} else {
			esc_html_e( 'We could not load the current job openings. Please try again.', 'jobs-sync-for-zoho-recruit' );
		}
		?>
	</p>

	<button type="button" class="jszr-button jszr-jobs__retry">
		<?php esc_html_e( 'Retry', 'jobs-sync-for-zoho-recruit' ); ?>
	</button>
</div>
