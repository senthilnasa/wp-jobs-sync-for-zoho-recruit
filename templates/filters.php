<?php
/**
 * Job listing filters.
 *
 * The form submits with GET so filtering works with JavaScript disabled.
 *
 * Override by copying to yourtheme/jobs-sync-for-zoho-recruit/filters.php
 *
 * @package JobsSyncForZohoRecruit
 *
 * @var array $params       Current listing parameters.
 * @var bool  $show_filters Whether to render taxonomy filters.
 * @var bool  $show_search  Whether to render the search box.
 */

defined( 'ABSPATH' ) || exit;

$jszr_labels = array(
	'department'      => __( 'Department', 'jobs-sync-for-zoho-recruit' ),
	'location'        => __( 'Location', 'jobs-sync-for-zoho-recruit' ),
	'employment_type' => __( 'Employment type', 'jobs-sync-for-zoho-recruit' ),
	'category'        => __( 'Category', 'jobs-sync-for-zoho-recruit' ),
	'experience'      => __( 'Experience', 'jobs-sync-for-zoho-recruit' ),
);

$jszr_action = remove_query_arg(
	array_merge(
		array( 'jszr_page', 'jszr_search' ),
		array_map(
			static function ( $key ) {
				return 'jszr_' . $key;
			},
			array_keys( $jszr_labels )
		)
	)
);
?>
<form class="jszr-filters" method="get" action="<?php echo esc_url( $jszr_action ); ?>" role="search">
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
		<p class="jszr-filters__field jszr-filters__field--search">
			<label for="jszr-search"><?php esc_html_e( 'Search jobs', 'jobs-sync-for-zoho-recruit' ); ?></label>
			<input type="search" id="jszr-search" name="jszr_search"
				value="<?php echo esc_attr( (string) ( $params['search'] ?? '' ) ); ?>"
				placeholder="<?php esc_attr_e( 'Job title or code', 'jobs-sync-for-zoho-recruit' ); ?>" />
		</p>
	<?php endif; ?>

	<?php if ( ! empty( $show_filters ) ) : ?>
		<?php foreach ( \JobsSyncForZohoRecruit\REST_API::filter_map() as $jszr_param => $jszr_taxonomy ) : ?>
			<?php
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

	<p class="jszr-filters__actions">
		<button type="submit" class="jszr-filters__submit"><?php esc_html_e( 'Filter', 'jobs-sync-for-zoho-recruit' ); ?></button>
		<a class="jszr-filters__reset" href="<?php echo esc_url( $jszr_action ); ?>"><?php esc_html_e( 'Reset', 'jobs-sync-for-zoho-recruit' ); ?></a>
	</p>
</form>
