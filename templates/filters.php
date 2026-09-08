<?php
/**
 * Job listing filters.
 *
 * The form submits with GET so filtering works with JavaScript disabled.
 *
 * Which filters appear, how they are laid out, and the search and button labels
 * all come from Settings → Display. Override by copying this file to
 * yourtheme/jobs-sync-for-zoho-recruit/filters.php
 *
 * @package JobsSyncForZohoRecruit
 *
 * @var array $params       Current listing parameters.
 * @var bool  $show_filters Whether to render taxonomy filters.
 * @var bool  $show_search  Whether to render the search box.
 * @var bool  $show_sort    Whether to render the sort control.
 */

defined( 'ABSPATH' ) || exit;

$jszr_map       = \JobsSyncForZohoRecruit\REST_API::filter_map();
$jszr_show_sort = ! empty( $show_sort );

// Labels come from the registered taxonomies, so a taxonomy added through
// jszr_taxonomies gets a correctly labelled filter with no template change.
$jszr_taxonomies = \JobsSyncForZohoRecruit\Post_Type::taxonomies();
$jszr_labels     = array();

foreach ( $jszr_map as $jszr_param => $jszr_taxonomy ) {
	if ( isset( $jszr_taxonomies[ $jszr_taxonomy ]['label'] ) ) {
		$jszr_labels[ $jszr_param ] = $jszr_taxonomies[ $jszr_taxonomy ]['label'];
		continue;
	}

	$jszr_object = get_taxonomy( $jszr_taxonomy );

	$jszr_labels[ $jszr_param ] = $jszr_object ? $jszr_object->labels->singular_name : $jszr_param;
}

// Only the filters the administrator chose, in the map's order.
$jszr_chosen = (array) jszr_get_setting( 'filter_fields', array_keys( $jszr_map ) );
$jszr_chosen = array_values( array_intersect( array_keys( $jszr_map ), $jszr_chosen ) );

/**
 * Filter which taxonomy filters the listing form offers.
 *
 * @param string[] $jszr_chosen Parameter names, in display order.
 */
$jszr_chosen = (array) apply_filters( 'jszr_visible_filters', $jszr_chosen );

$jszr_layout = 'stacked' === jszr_get_setting( 'filters_layout', 'inline' ) ? 'stacked' : 'inline';

$jszr_placeholder = (string) jszr_get_setting( 'search_placeholder', '' );

if ( '' === $jszr_placeholder ) {
	$jszr_placeholder = __( 'Job title or code', 'jobs-sync-for-zoho-recruit' );
}

$jszr_button = (string) jszr_get_setting( 'filters_button_label', '' );

if ( '' === $jszr_button ) {
	$jszr_button = __( 'Filter', 'jobs-sync-for-zoho-recruit' );
}

// Every parameter this form owns is dropped from the action URL, so submitting
// replaces them rather than stacking a second copy on the query string.
$jszr_owned = array( 'jszr_page', 'jszr_search', 'jszr_sort' );

foreach ( array_keys( $jszr_map ) as $jszr_param ) {
	$jszr_owned[] = 'jszr_' . $jszr_param;
}

$jszr_action = remove_query_arg( $jszr_owned );

$jszr_sort_options = array(
	'date:desc'        => __( 'Newest first', 'jobs-sync-for-zoho-recruit' ),
	'date:asc'         => __( 'Oldest first', 'jobs-sync-for-zoho-recruit' ),
	'title:asc'        => __( 'Title A–Z', 'jobs-sync-for-zoho-recruit' ),
	'title:desc'       => __( 'Title Z–A', 'jobs-sync-for-zoho-recruit' ),
	'closing_date:asc' => __( 'Closing soonest', 'jobs-sync-for-zoho-recruit' ),
);

$jszr_current_sort = sprintf(
	'%s:%s',
	isset( $params['orderby'] ) ? (string) $params['orderby'] : 'date',
	strtolower( isset( $params['order'] ) ? (string) $params['order'] : 'desc' )
);
?>
<form class="jszr-filters jszr-filters--<?php echo esc_attr( $jszr_layout ); ?>"
	method="get" action="<?php echo esc_url( $jszr_action ); ?>" role="search">
	<?php
	// Preserve any query arguments the theme or another plugin relies on.
	foreach ( $_GET as $jszr_key => $jszr_value ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only pass-through.
		$jszr_key = sanitize_key( (string) $jszr_key );

		if ( 0 === strpos( $jszr_key, 'jszr_' ) || ! is_scalar( $jszr_value ) ) {
			continue;
		}
		?>
		<input type="hidden" name="<?php echo esc_attr( $jszr_key ); ?>"
			value="<?php echo esc_attr( sanitize_text_field( wp_unslash( (string) $jszr_value ) ) ); ?>" />
		<?php
	endforeach;
	?>

	<?php if ( ! empty( $show_search ) ) : ?>
		<p class="jszr-filters__search">
			<label for="jszr-search"><?php esc_html_e( 'Search jobs', 'jobs-sync-for-zoho-recruit' ); ?></label>
			<?php jszr_icon( 'search', 'jszr-filters__search-icon' ); ?>
			<input type="search" id="jszr-search" name="jszr_search"
				value="<?php echo esc_attr( (string) ( $params['search'] ?? '' ) ); ?>"
				placeholder="<?php echo esc_attr( $jszr_placeholder ); ?>" />
		</p>
	<?php endif; ?>

	<div class="jszr-filters__row">
	<?php if ( ! empty( $show_filters ) ) : ?>
		<?php foreach ( $jszr_chosen as $jszr_param ) : ?>
			<?php
			$jszr_taxonomy = $jszr_map[ $jszr_param ];

			$jszr_terms = get_terms(
				array(
					'taxonomy'   => $jszr_taxonomy,
					'hide_empty' => true,
				)
			);

			if ( is_wp_error( $jszr_terms ) || empty( $jszr_terms ) ) {
				continue;
			}
			?>
			<p class="jszr-filters__field">
				<label for="jszr-filter-<?php echo esc_attr( $jszr_param ); ?>">
					<?php echo esc_html( $jszr_labels[ $jszr_param ] ?? $jszr_param ); ?>
				</label>
				<select id="jszr-filter-<?php echo esc_attr( $jszr_param ); ?>" name="jszr_<?php echo esc_attr( $jszr_param ); ?>">
					<option value=""><?php esc_html_e( 'All', 'jobs-sync-for-zoho-recruit' ); ?></option>
					<?php foreach ( $jszr_terms as $jszr_term ) : ?>
						<option value="<?php echo esc_attr( $jszr_term->slug ); ?>"
							<?php selected( (string) ( $params[ $jszr_param ] ?? '' ), $jszr_term->slug ); ?>>
							<?php echo esc_html( $jszr_term->name ); ?>
						</option>
					<?php endforeach; ?>
				</select>
			</p>
		<?php endforeach; ?>
	<?php endif; ?>

	<?php if ( $jszr_show_sort ) : ?>
		<p class="jszr-filters__field jszr-filters__field--sort">
			<label for="jszr-sort"><?php esc_html_e( 'Sort by', 'jobs-sync-for-zoho-recruit' ); ?></label>
			<select id="jszr-sort" name="jszr_sort">
				<?php foreach ( $jszr_sort_options as $jszr_value => $jszr_label ) : ?>
					<option value="<?php echo esc_attr( $jszr_value ); ?>" <?php selected( $jszr_current_sort, $jszr_value ); ?>>
						<?php echo esc_html( $jszr_label ); ?>
					</option>
				<?php endforeach; ?>
			</select>
		</p>
	<?php endif; ?>

	<p class="jszr-filters__actions">
		<button type="submit" class="jszr-button jszr-filters__submit"><?php echo esc_html( $jszr_button ); ?></button>
		<a class="jszr-button jszr-button--ghost jszr-filters__reset" href="<?php echo esc_url( $jszr_action ); ?>"><?php esc_html_e( 'Reset', 'jobs-sync-for-zoho-recruit' ); ?></a>
	</p>
	</div>
</form>
