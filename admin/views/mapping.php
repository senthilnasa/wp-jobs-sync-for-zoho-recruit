<?php
/**
 * Field mapping screen.
 *
 * @package JobsSyncForZohoRecruit
 *
 * @var array $mapping    Current mapping rows.
 * @var array $fields     Discovered Zoho fields.
 * @var bool  $has_live   Whether a live field list is cached.
 * @var array $targets    Available targets.
 * @var array $transforms Available transforms.
 * @var bool  $connected  Whether the site is connected.
 */

namespace JobsSyncForZohoRecruit;

defined( 'ABSPATH' ) || exit;

/**
 * Render one mapping row.
 *
 * @param int   $index      Row index, or -1 for the JS template row.
 * @param array $row        Row data.
 * @param array $fields     Zoho fields.
 * @param array $targets    Targets.
 * @param array $transforms Transforms.
 * @return void
 */
$jszr_row = static function ( $index, $row, $fields, $targets, $transforms ) {
	$jszr_name  = -1 === $index ? '__INDEX__' : (string) $index;
	$jszr_field = isset( $row['zoho_field'] ) ? (string) $row['zoho_field'] : '';
	$jszr_known = '' === $jszr_field || isset( $fields[ $jszr_field ] );
	?>
	<tr class="jszr-mapping-row">
		<td>
			<label class="screen-reader-text" for="jszr-field-<?php echo esc_attr( $jszr_name ); ?>">
				<?php esc_html_e( 'Zoho field', 'jobs-sync-for-zoho-recruit' ); ?>
			</label>
			<select id="jszr-field-<?php echo esc_attr( $jszr_name ); ?>"
				name="jszr_mapping[<?php echo esc_attr( $jszr_name ); ?>][zoho_field]" class="jszr-field-select">
				<option value=""><?php esc_html_e( '— Select a Zoho field —', 'jobs-sync-for-zoho-recruit' ); ?></option>
				<?php if ( ! $jszr_known ) : ?>
					<option value="<?php echo esc_attr( $jszr_field ); ?>" selected>
						<?php
						printf(
							/* translators: %s: Zoho field API name. */
							esc_html__( '%s (not found in this account)', 'jobs-sync-for-zoho-recruit' ),
							esc_html( $jszr_field )
						);
						?>
					</option>
				<?php endif; ?>
				<?php foreach ( $fields as $jszr_api_name => $jszr_definition ) : ?>
					<option value="<?php echo esc_attr( $jszr_api_name ); ?>" <?php selected( $jszr_field, $jszr_api_name ); ?>>
						<?php
						printf(
							'%1$s (%2$s)',
							esc_html( $jszr_definition['label'] ),
							esc_html( $jszr_api_name )
						);
						?>
					</option>
				<?php endforeach; ?>
			</select>
		</td>
		<td>
			<label class="screen-reader-text" for="jszr-target-<?php echo esc_attr( $jszr_name ); ?>">
				<?php esc_html_e( 'WordPress target', 'jobs-sync-for-zoho-recruit' ); ?>
			</label>
			<select id="jszr-target-<?php echo esc_attr( $jszr_name ); ?>"
				name="jszr_mapping[<?php echo esc_attr( $jszr_name ); ?>][target]">
				<?php foreach ( $targets as $jszr_target => $jszr_label ) : ?>
					<option value="<?php echo esc_attr( $jszr_target ); ?>" <?php selected( (string) ( $row['target'] ?? '' ), $jszr_target ); ?>>
						<?php echo esc_html( $jszr_label ); ?>
					</option>
				<?php endforeach; ?>
			</select>
		</td>
		<td>
			<label class="screen-reader-text" for="jszr-transform-<?php echo esc_attr( $jszr_name ); ?>">
				<?php esc_html_e( 'Transform', 'jobs-sync-for-zoho-recruit' ); ?>
			</label>
			<select id="jszr-transform-<?php echo esc_attr( $jszr_name ); ?>"
				name="jszr_mapping[<?php echo esc_attr( $jszr_name ); ?>][transform]">
				<?php foreach ( $transforms as $jszr_transform => $jszr_label ) : ?>
					<option value="<?php echo esc_attr( $jszr_transform ); ?>" <?php selected( (string) ( $row['transform'] ?? 'text' ), $jszr_transform ); ?>>
						<?php echo esc_html( $jszr_label ); ?>
					</option>
				<?php endforeach; ?>
			</select>
		</td>
		<td>
			<button type="button" class="button-link jszr-remove-row">
				<?php esc_html_e( 'Remove', 'jobs-sync-for-zoho-recruit' ); ?>
			</button>
		</td>
	</tr>
	<?php
};
?>
<div class="wrap jszr-wrap">
	<h1><?php esc_html_e( 'Field Mapping', 'jobs-sync-for-zoho-recruit' ); ?></h1>

	<p>
		<?php esc_html_e( 'Decide where each Zoho Recruit field is stored in WordPress. Fields you do not map are left alone, and unmapped WordPress content is never overwritten.', 'jobs-sync-for-zoho-recruit' ); ?>
	</p>

	<?php if ( ! $has_live ) : ?>
		<div class="notice notice-info inline">
			<p>
				<?php if ( $connected ) : ?>
					<?php esc_html_e( 'Showing the standard Zoho Recruit fields. Load the real field list from your account to include custom fields.', 'jobs-sync-for-zoho-recruit' ); ?>
				<?php else : ?>
					<?php esc_html_e( 'Showing the standard Zoho Recruit fields. Connect the site to load the real field list, including custom fields, from your account.', 'jobs-sync-for-zoho-recruit' ); ?>
				<?php endif; ?>
			</p>
		</div>
	<?php endif; ?>

	<p class="jszr-actions">
		<?php if ( $connected ) : ?>
			<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=jszr_refresh_fields' ), 'jszr_refresh_fields' ) ); ?>">
				<?php esc_html_e( 'Reload fields from Zoho', 'jobs-sync-for-zoho-recruit' ); ?>
			</a>
		<?php endif; ?>
		<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=jszr_export_mapping' ), 'jszr_export_mapping' ) ); ?>">
			<?php esc_html_e( 'Export mapping', 'jobs-sync-for-zoho-recruit' ); ?>
		</a>
	</p>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="jszr-mapping-form">
		<?php wp_nonce_field( 'jszr_save_mapping' ); ?>
		<input type="hidden" name="action" value="jszr_save_mapping" />

		<table class="widefat striped jszr-mapping-table">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Zoho field', 'jobs-sync-for-zoho-recruit' ); ?></th>
					<th scope="col"><?php esc_html_e( 'WordPress target', 'jobs-sync-for-zoho-recruit' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Transform', 'jobs-sync-for-zoho-recruit' ); ?></th>
					<th scope="col"><span class="screen-reader-text"><?php esc_html_e( 'Actions', 'jobs-sync-for-zoho-recruit' ); ?></span></th>
				</tr>
			</thead>
			<tbody id="jszr-mapping-rows">
				<?php foreach ( $mapping as $jszr_index => $jszr_mapping_row ) : ?>
					<?php $jszr_row( (int) $jszr_index, $jszr_mapping_row, $fields, $targets, $transforms ); ?>
				<?php endforeach; ?>
			</tbody>
		</table>

		<p>
			<button type="button" class="button" id="jszr-add-row"><?php esc_html_e( 'Add mapping row', 'jobs-sync-for-zoho-recruit' ); ?></button>
		</p>

		<?php submit_button( __( 'Save mapping', 'jobs-sync-for-zoho-recruit' ) ); ?>
	</form>

	<template id="jszr-mapping-template">
		<?php $jszr_row( -1, array( 'transform' => 'text' ), $fields, $targets, $transforms ); ?>
	</template>

	<hr />

	<h2><?php esc_html_e( 'Import mapping', 'jobs-sync-for-zoho-recruit' ); ?></h2>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data" class="jszr-inline-form">
		<?php wp_nonce_field( 'jszr_import_mapping' ); ?>
		<input type="hidden" name="action" value="jszr_import_mapping" />
		<label for="jszr-mapping-file" class="screen-reader-text"><?php esc_html_e( 'Mapping JSON file', 'jobs-sync-for-zoho-recruit' ); ?></label>
		<input type="file" id="jszr-mapping-file" name="jszr_mapping_file" accept="application/json,.json" required />
		<button type="submit" class="button"><?php esc_html_e( 'Import mapping', 'jobs-sync-for-zoho-recruit' ); ?></button>
	</form>

	<h2><?php esc_html_e( 'Reset', 'jobs-sync-for-zoho-recruit' ); ?></h2>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<?php wp_nonce_field( 'jszr_reset_mapping' ); ?>
		<input type="hidden" name="action" value="jszr_reset_mapping" />
		<button type="submit" class="button button-link-delete">
			<?php esc_html_e( 'Reset to the default mapping', 'jobs-sync-for-zoho-recruit' ); ?>
		</button>
	</form>
</div>
