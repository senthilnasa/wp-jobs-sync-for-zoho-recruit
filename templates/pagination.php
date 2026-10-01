<?php
/**
 * Job listing pagination.
 *
 * Override by copying to yourtheme/jobs-sync-for-zoho-recruit/pagination.php
 *
 * @package JobsSyncForZohoRecruit
 *
 * @var \WP_Query $query  Job query.
 * @var array     $params Listing parameters.
 */

defined( 'ABSPATH' ) || exit;

$jszr_total = (int) $query->max_num_pages;

if ( $jszr_total < 2 ) {
	return;
}

$jszr_current = max( 1, (int) ( $params['page'] ?? 1 ) );

/*
 * "Load more" is one link to the next page. Without JavaScript it simply opens
 * that page; with it, the script appends the next page's cards below the
 * current ones and swaps this control for the one that comes back.
 */
if ( 'load_more' === jszr_get_setting( 'pagination_style', 'numbers' ) ) {
	if ( $jszr_current >= $jszr_total ) {
		return;
	}

	$jszr_more_label = (string) jszr_get_setting( 'load_more_label', '' );

	if ( '' === $jszr_more_label ) {
		$jszr_more_label = __( 'Load more listings', 'jobs-sync-for-zoho-recruit' );
	}
	?>
	<nav class="jszr-pagination jszr-pagination--more" aria-label="<?php esc_attr_e( 'Job listing pages', 'jobs-sync-for-zoho-recruit' ); ?>">
		<a class="jszr-button jszr-button--secondary jszr-pagination__more"
			href="<?php echo esc_url( add_query_arg( 'jszr_page', $jszr_current + 1 ) ); ?>"
			data-jszr-append="true"
			data-jszr-page="<?php echo esc_attr( (string) ( $jszr_current + 1 ) ); ?>"
			data-jszr-pages="<?php echo esc_attr( (string) $jszr_total ); ?>">
			<?php echo esc_html( $jszr_more_label ); ?>
		</a>
	</nav>
	<?php
	return;
}

$jszr_links = paginate_links(
	array(
		'base'      => add_query_arg( 'jszr_page', '%#%' ),
		'format'    => '',
		'current'   => $jszr_current,
		'total'     => $jszr_total,
		'type'      => 'array',
		'prev_text' => __( '&laquo; Previous', 'jobs-sync-for-zoho-recruit' ),
		'next_text' => __( 'Next &raquo;', 'jobs-sync-for-zoho-recruit' ),
	)
);

if ( empty( $jszr_links ) ) {
	return;
}
?>
<nav class="jszr-pagination" aria-label="<?php esc_attr_e( 'Job listing pages', 'jobs-sync-for-zoho-recruit' ); ?>">
	<ul class="jszr-pagination__list">
		<?php foreach ( $jszr_links as $jszr_link ) : ?>
			<li class="jszr-pagination__item"><?php echo wp_kses_post( $jszr_link ); ?></li>
		<?php endforeach; ?>
	</ul>
</nav>
