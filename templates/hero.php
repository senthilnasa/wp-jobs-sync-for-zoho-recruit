<?php
/**
 * Careers hero shown above the job archive.
 *
 * Every string is filterable so a site can say something of its own without
 * copying the template:
 *
 *     add_filter( 'jszr_hero_title', fn() => 'Build the future with us' );
 *
 * Return an empty string from jszr_hero_title to drop the hero entirely.
 *
 * Override by copying to yourtheme/jobs-sync-for-zoho-recruit/hero.php
 *
 * @package JobsSyncForZohoRecruit
 *
 * @var array $counts Job counts, as returned by Job::counts().
 */

defined( 'ABSPATH' ) || exit;

/**
 * Filter the hero heading. Return an empty string to hide the hero.
 *
 * @param string $title Heading text.
 */
$jszr_title = (string) apply_filters( 'jszr_hero_title', __( 'Build the future with us', 'jobs-sync-for-zoho-recruit' ) );

if ( '' === trim( $jszr_title ) ) {
	return;
}

/**
 * Filter the small label above the hero heading.
 *
 * @param string $eyebrow Eyebrow text.
 */
$jszr_eyebrow = (string) apply_filters( 'jszr_hero_eyebrow', __( 'Careers', 'jobs-sync-for-zoho-recruit' ) );

/**
 * Filter the supporting paragraph under the hero heading.
 *
 * @param string $lead Lead text.
 */
$jszr_lead = (string) apply_filters(
	'jszr_hero_lead',
	__( 'Join a community of people who take their work seriously and each other kindly. Explore the roles we are hiring for and find the one that fits.', 'jobs-sync-for-zoho-recruit' )
);

$jszr_open = isset( $counts['active'] ) ? (int) $counts['active'] : 0;

$jszr_locations = get_terms(
	array(
		'taxonomy'   => 'zoho_job_location',
		'hide_empty' => true,
		'fields'     => 'ids',
	)
);

$jszr_location_count = is_wp_error( $jszr_locations ) ? 0 : count( $jszr_locations );
?>
<section class="jszr-hero">
	<div class="jszr-hero__inner">
		<?php if ( '' !== trim( $jszr_eyebrow ) ) : ?>
			<p class="jszr-hero__eyebrow"><?php echo esc_html( $jszr_eyebrow ); ?></p>
		<?php endif; ?>

		<h1 class="jszr-hero__title"><?php echo esc_html( $jszr_title ); ?></h1>

		<?php if ( '' !== trim( $jszr_lead ) ) : ?>
			<p class="jszr-hero__lead"><?php echo esc_html( $jszr_lead ); ?></p>
		<?php endif; ?>

		<?php if ( $jszr_open > 0 ) : ?>
			<ul class="jszr-hero__stats">
				<li class="jszr-hero__stat">
					<span class="jszr-hero__stat-value"><?php echo esc_html( number_format_i18n( $jszr_open ) ); ?></span>
					<span class="jszr-hero__stat-label">
						<?php
						echo esc_html(
							_n( 'Open position', 'Open positions', $jszr_open, 'jobs-sync-for-zoho-recruit' )
						);
						?>
					</span>
				</li>

				<?php if ( $jszr_location_count > 0 ) : ?>
					<li class="jszr-hero__stat">
						<span class="jszr-hero__stat-value"><?php echo esc_html( number_format_i18n( $jszr_location_count ) ); ?></span>
						<span class="jszr-hero__stat-label">
							<?php
							echo esc_html(
								_n( 'Location', 'Locations', $jszr_location_count, 'jobs-sync-for-zoho-recruit' )
							);
							?>
						</span>
					</li>
				<?php endif; ?>
			</ul>
		<?php endif; ?>
	</div>
</section>
