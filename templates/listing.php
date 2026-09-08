<?php
/**
 * Job listing rendered by the shortcode, the block and the archive template.
 *
 * Override by copying to yourtheme/jobs-sync-for-zoho-recruit/listing.php
 *
 * @package JobsSyncForZohoRecruit
 *
 * @var \WP_Query $query           Job query.
 * @var array     $atts            Listing attributes.
 * @var array     $params          Normalised listing parameters.
 * @var string    $style           list|grid.
 * @var string    $layout          Card layout key.
 * @var int       $columns         Grid column count.
 * @var bool      $show_filters    Whether to render taxonomy filters.
 * @var bool      $show_search     Whether to render the search box.
 * @var bool      $show_pagination Whether to render pagination.
 * @var bool      $show_excerpt    Whether cards show an excerpt.
 * @var bool      $show_sort       Whether to offer a sort control.
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="jszr-jobs jszr-scope jszr-jobs--<?php echo esc_attr( $style ); ?> jszr-jobs--layout-<?php echo esc_attr( isset( $layout ) ? $layout : 'default' ); ?>"
	<?php echo 'grid' === $style ? 'style="--jszr-columns:' . esc_attr( (string) $columns ) . '"' : ''; ?>>

	<?php if ( $show_filters || $show_search || ! empty( $show_sort ) ) : ?>
		<?php
		jszr_get_template(
			'filters.php',
			array(
				'params'       => $params,
				'show_filters' => $show_filters,
				'show_search'  => $show_search,
				'show_sort'    => ! empty( $show_sort ),
			)
		);
		?>
	<?php endif; ?>

	<div class="jszr-jobs__results" role="region" aria-live="polite"
		aria-label="<?php esc_attr_e( 'Job results', 'jobs-sync-for-zoho-recruit' ); ?>">

		<div class="jszr-jobs__toolbar">
			<p class="jszr-jobs__count">
				<?php
				printf(
					esc_html(
						/* translators: %s: number of jobs. */
						_n( '%s job found', '%s jobs found', (int) $query->found_posts, 'jobs-sync-for-zoho-recruit' )
					),
					esc_html( number_format_i18n( (int) $query->found_posts ) )
				);
				?>
			</p>
		</div>

		<?php
		/*
		 * Skeletons for the enhanced path. Hidden until the script swaps them
		 * in, so a visitor without JavaScript never sees an empty placeholder.
		 */
		?>
		<div class="jszr-skeleton" hidden aria-hidden="true">
			<ul class="jszr-jobs__list">
				<?php for ( $jszr_i = 0; $jszr_i < 6; $jszr_i++ ) : ?>
					<li class="jszr-jobs__item">
						<div class="jszr-skeleton__card">
							<div class="jszr-skeleton__line jszr-skeleton__line--title"></div>
							<div class="jszr-skeleton__line jszr-skeleton__line--short"></div>
							<div class="jszr-skeleton__line jszr-skeleton__line--medium"></div>
							<div class="jszr-skeleton__line jszr-skeleton__line--short"></div>
						</div>
					</li>
				<?php endfor; ?>
			</ul>
		</div>

		<?php if ( $query->have_posts() ) : ?>
			<ul class="jszr-jobs__list">
				<?php
				while ( $query->have_posts() ) :
					$query->the_post();
					?>
					<li class="jszr-jobs__item">
						<?php
						jszr_get_template(
							'card.php',
							array(
								'post_id'      => get_the_ID(),
								'show_excerpt' => $show_excerpt,
								'layout'       => isset( $layout ) ? $layout : '',
							)
						);
						?>
					</li>
					<?php
				endwhile;

				wp_reset_postdata();
				?>
			</ul>

			<?php if ( $show_pagination ) : ?>
				<?php
				jszr_get_template(
					'pagination.php',
					array(
						'query'  => $query,
						'params' => $params,
					)
				);
				?>
			<?php endif; ?>
		<?php else : ?>
			<?php jszr_get_template( 'no-results.php', array( 'params' => $params ) ); ?>
		<?php endif; ?>
	</div>
</div>
