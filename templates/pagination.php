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
