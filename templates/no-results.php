<?php
/**
 * Shown when a job listing has no matches.
 *
 * Override by copying to yourtheme/jobs-sync-for-zoho-recruit/no-results.php
 *
 * @package JobsSyncForZohoRecruit
 *
 * @var array $params Listing parameters.
 */

defined( 'ABSPATH' ) || exit;
?>
<p class="jszr-no-results">
	<?php if ( ! empty( $params['search'] ) ) : ?>
		<?php
		printf(
			/* translators: %s: search term. */
			esc_html__( 'No open positions match “%s”.', 'jobs-sync-for-zoho-recruit' ),
			esc_html( (string) $params['search'] )
		);
		?>
	<?php else : ?>
		<?php esc_html_e( 'There are no open positions at the moment. Please check back soon.', 'jobs-sync-for-zoho-recruit' ); ?>
	<?php endif; ?>
</p>
