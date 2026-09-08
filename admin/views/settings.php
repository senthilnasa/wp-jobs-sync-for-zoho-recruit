<?php
/**
 * Settings screen.
 *
 * @package JobsSyncForZohoRecruit
 *
 * @var array                             $tabs       Tab key => label.
 * @var string                            $active     Active tab key.
 * @var array                             $settings   Current settings.
 * @var \JobsSyncForZohoRecruit\Zoho_Auth $auth       OAuth handler.
 * @var array                             $connection Connection info.
 */

namespace JobsSyncForZohoRecruit;

defined( 'ABSPATH' ) || exit;

$jszr_option = Settings::OPTION;

/**
 * Render a checkbox with a paired hidden field so unchecking persists.
 *
 * @param string $key     Setting key.
 * @param array  $values  Current settings.
 * @param string $label   Field label.
 * @param string $help    Optional description.
 * @return void
 */
$jszr_checkbox = static function ( $key, $values, $label, $help = '' ) use ( $jszr_option ) {
	printf(
		'<input type="hidden" name="%1$s[%2$s]" value="0" />',
		esc_attr( $jszr_option ),
		esc_attr( $key )
	);

	printf(
		'<label for="jszr-%1$s"><input type="checkbox" id="jszr-%1$s" name="%2$s[%1$s]" value="1"%3$s /> %4$s</label>',
		esc_attr( $key ),
		esc_attr( $jszr_option ),
		checked( ! empty( $values[ $key ] ), true, false ),
		esc_html( $label )
	);

	if ( '' !== $help ) {
		printf( '<p class="description">%s</p>', esc_html( $help ) );
	}
};

/**
 * Render a select field.
 *
 * @param string $key     Setting key.
 * @param array  $values  Current settings.
 * @param array  $options Value => label.
 * @param string $help    Optional description.
 * @return void
 */
$jszr_select = static function ( $key, $values, $options, $help = '' ) use ( $jszr_option ) {
	printf(
		'<select id="jszr-%1$s" name="%2$s[%1$s]">',
		esc_attr( $key ),
		esc_attr( $jszr_option )
	);

	foreach ( $options as $jszr_value => $jszr_label ) {
		printf(
			'<option value="%1$s"%2$s>%3$s</option>',
			esc_attr( (string) $jszr_value ),
			selected( (string) ( $values[ $key ] ?? '' ), (string) $jszr_value, false ),
			esc_html( (string) $jszr_label )
		);
	}

	echo '</select>';

	if ( '' !== $help ) {
		printf( '<p class="description">%s</p>', esc_html( $help ) );
	}
};

/**
 * Render a text or number field.
 *
 * @param string $key    Setting key.
 * @param array  $values Current settings.
 * @param string $type   Input type.
 * @param string $help   Optional description.
 * @param array  $attrs  Extra attributes.
 * @return void
 */
$jszr_input = static function ( $key, $values, $type = 'text', $help = '', $attrs = array() ) use ( $jszr_option ) {
	$jszr_extra = '';

	foreach ( $attrs as $jszr_attr => $jszr_attr_value ) {
		$jszr_extra .= sprintf( ' %s="%s"', esc_attr( $jszr_attr ), esc_attr( (string) $jszr_attr_value ) );
	}

	printf(
		'<input type="%1$s" id="jszr-%2$s" name="%3$s[%2$s]" value="%4$s" class="%5$s"%6$s />',
		esc_attr( $type ),
		esc_attr( $key ),
		esc_attr( $jszr_option ),
		esc_attr( (string) ( $values[ $key ] ?? '' ) ),
		esc_attr( 'number' === $type ? 'small-text' : 'regular-text' ),
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Each attribute escaped above.
		$jszr_extra
	);

	if ( '' !== $help ) {
		printf( '<p class="description">%s</p>', esc_html( $help ) );
	}
};
?>
<div class="wrap jszr-wrap">
	<h1><?php esc_html_e( 'Zoho Recruit Jobs Settings', 'jobs-sync-for-zoho-recruit' ); ?></h1>

	<nav class="nav-tab-wrapper" aria-label="<?php esc_attr_e( 'Settings sections', 'jobs-sync-for-zoho-recruit' ); ?>">
		<?php foreach ( $tabs as $jszr_tab => $jszr_label ) : ?>
			<a class="nav-tab<?php echo $active === $jszr_tab ? ' nav-tab-active' : ''; ?>"
				href="<?php echo esc_url( jszr_admin_url( Admin::SETTINGS_SLUG, array( 'tab' => $jszr_tab ) ) ); ?>">
				<?php echo esc_html( $jszr_label ); ?>
			</a>
		<?php endforeach; ?>
	</nav>

	<?php settings_errors(); ?>

	<?php if ( 'connection' === $active ) : ?>

		<h2><?php esc_html_e( 'Zoho application credentials', 'jobs-sync-for-zoho-recruit' ); ?></h2>

		<p>
			<?php esc_html_e( 'Create a Server-based Application in the Zoho API console, then paste its credentials here. The redirect URI below must be registered on that application exactly as shown.', 'jobs-sync-for-zoho-recruit' ); ?>
		</p>

		<table class="form-table" role="presentation">
			<tbody>
				<tr>
					<th scope="row"><label for="jszr-redirect-uri"><?php esc_html_e( 'Redirect URI', 'jobs-sync-for-zoho-recruit' ); ?></label></th>
					<td>
						<input type="text" id="jszr-redirect-uri" class="large-text code" readonly
							value="<?php echo esc_attr( $auth->redirect_uri() ); ?>"
							onfocus="this.select();" />
						<p class="description"><?php esc_html_e( 'Copy this into the "Authorized Redirect URIs" field in the Zoho API console.', 'jobs-sync-for-zoho-recruit' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Permissions requested', 'jobs-sync-for-zoho-recruit' ); ?></th>
					<td>
						<code><?php echo esc_html( implode( ', ', $auth->scopes() ) ); ?></code>
						<p class="description">
							<?php
							printf(
								/* translators: %s: the required OAuth scope name. */
								esc_html__( 'Only %s is required. The second scope fills the Field Mapping dropdowns from your account; without it the plugin falls back to the standard Zoho Recruit field names and everything else still works.', 'jobs-sync-for-zoho-recruit' ),
								'<code>' . esc_html( Zoho_Auth::required_scope() ) . '</code>'
							);
							?>
						</p>
						<p class="description">
							<?php esc_html_e( 'If Zoho answers "Invalid OAuth Scope / Scope does not exist", it has rejected one of these without saying which. Remove the second one below and try again.', 'jobs-sync-for-zoho-recruit' ); ?>
						</p>
					</td>
				</tr>
			</tbody>
		</table>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'jszr_save_credentials' ); ?>
			<input type="hidden" name="action" value="jszr_save_credentials" />

			<table class="form-table" role="presentation">
				<tbody>
					<tr>
						<th scope="row"><label for="jszr-client-id"><?php esc_html_e( 'Client ID', 'jobs-sync-for-zoho-recruit' ); ?></label></th>
						<td>
							<?php if ( Zoho_Auth::client_id_is_constant() ) : ?>
								<code><?php echo esc_html( $auth->masked_client_id() ); ?></code>
								<p class="description"><?php esc_html_e( 'Defined by the JSZR_CLIENT_ID constant in wp-config.php.', 'jobs-sync-for-zoho-recruit' ); ?></p>
							<?php else : ?>
								<input type="text" id="jszr-client-id" name="jszr_client_id" class="regular-text" autocomplete="off"
									placeholder="<?php echo esc_attr( $auth->masked_client_id() ); ?>" />
								<p class="description"><?php esc_html_e( 'Leave blank to keep the stored value.', 'jobs-sync-for-zoho-recruit' ); ?></p>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="jszr-client-secret"><?php esc_html_e( 'Client Secret', 'jobs-sync-for-zoho-recruit' ); ?></label></th>
						<td>
							<?php if ( Zoho_Auth::client_secret_is_constant() ) : ?>
								<code><?php echo esc_html( $auth->masked_client_secret() ); ?></code>
								<p class="description"><?php esc_html_e( 'Defined by the JSZR_CLIENT_SECRET constant in wp-config.php.', 'jobs-sync-for-zoho-recruit' ); ?></p>
							<?php else : ?>
								<input type="password" id="jszr-client-secret" name="jszr_client_secret" class="regular-text" autocomplete="new-password"
									placeholder="<?php echo esc_attr( $auth->masked_client_secret() ); ?>" />
								<p class="description"><?php esc_html_e( 'Leave blank to keep the stored value. The secret is encrypted before it is stored.', 'jobs-sync-for-zoho-recruit' ); ?></p>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="jszr-data-center"><?php esc_html_e( 'Data center', 'jobs-sync-for-zoho-recruit' ); ?></label></th>
						<td>
							<?php if ( Zoho_Auth::data_center_is_constant() ) : ?>
								<code><?php echo esc_html( Settings::data_center() ); ?></code>
								<p class="description"><?php esc_html_e( 'Defined by the JSZR_DATA_CENTER constant in wp-config.php.', 'jobs-sync-for-zoho-recruit' ); ?></p>
							<?php else : ?>
								<select id="jszr-data-center" name="jszr_data_center">
									<?php foreach ( Settings::data_centers() as $jszr_key => $jszr_center ) : ?>
										<option value="<?php echo esc_attr( $jszr_key ); ?>" <?php selected( Settings::data_center(), $jszr_key ); ?>>
											<?php echo esc_html( $jszr_center['label'] ); ?>
										</option>
									<?php endforeach; ?>
								</select>
								<p class="description"><?php esc_html_e( 'Choose the region your Zoho Recruit account is hosted in. The wrong region will reject the connection.', 'jobs-sync-for-zoho-recruit' ); ?></p>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="jszr-oauth-scopes"><?php esc_html_e( 'Permissions', 'jobs-sync-for-zoho-recruit' ); ?></label></th>
						<td>
							<input type="text" id="jszr-oauth-scopes" name="jszr_oauth_scopes" class="large-text code"
								value="<?php echo esc_attr( implode( ',', $auth->scopes() ) ); ?>" />
							<p class="description">
								<?php esc_html_e( 'The permissions to ask Zoho for, comma separated. Leave as they are unless Zoho refuses them.', 'jobs-sync-for-zoho-recruit' ); ?>
							</p>
							<p class="description">
								<?php
								printf(
									/* translators: %s: the minimum working scope list. */
									esc_html__( 'To connect with the minimum, use just %s.', 'jobs-sync-for-zoho-recruit' ),
									'<code>' . esc_html( Zoho_Auth::required_scope() ) . '</code>'
								);
								?>
							</p>
						</td>
					</tr>
				</tbody>
			</table>

			<?php submit_button( __( 'Save credentials', 'jobs-sync-for-zoho-recruit' ) ); ?>
		</form>

		<h2><?php esc_html_e( 'Connection', 'jobs-sync-for-zoho-recruit' ); ?></h2>

		<p class="jszr-actions">
			<?php if ( $connection['connected'] ) : ?>
				<span class="jszr-badge jszr-badge-active"><?php esc_html_e( 'Connected', 'jobs-sync-for-zoho-recruit' ); ?></span>
				<a class="button button-link-delete" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=jszr_oauth_disconnect' ), 'jszr_oauth_disconnect' ) ); ?>">
					<?php esc_html_e( 'Disconnect Zoho', 'jobs-sync-for-zoho-recruit' ); ?>
				</a>
			<?php elseif ( $auth->has_credentials() ) : ?>
				<a class="button button-primary" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=jszr_oauth_start' ), 'jszr_oauth_start' ) ); ?>">
					<?php esc_html_e( 'Connect to Zoho Recruit', 'jobs-sync-for-zoho-recruit' ); ?>
				</a>
			<?php else : ?>
				<em><?php esc_html_e( 'Save your credentials to enable the connect button.', 'jobs-sync-for-zoho-recruit' ); ?></em>
			<?php endif; ?>
		</p>

	<?php else : ?>

		<form method="post" action="<?php echo esc_url( admin_url( 'options.php' ) ); ?>">
			<?php settings_fields( 'jszr_settings_group' ); ?>

			<?php if ( 'sync' === $active ) : ?>
				<table class="form-table" role="presentation">
					<tbody>
						<tr>
							<th scope="row"><label for="jszr-sync_frequency"><?php esc_html_e( 'Sync frequency', 'jobs-sync-for-zoho-recruit' ); ?></label></th>
							<td><?php $jszr_select( 'sync_frequency', $settings, Cron::frequencies() ); ?></td>
						</tr>
						<tr>
							<th scope="row"><label for="jszr-cron_sync_type"><?php esc_html_e( 'Scheduled sync type', 'jobs-sync-for-zoho-recruit' ); ?></label></th>
							<td>
								<?php
								$jszr_select(
									'cron_sync_type',
									$settings,
									array(
										'incremental' => __( 'Incremental (with periodic full syncs)', 'jobs-sync-for-zoho-recruit' ),
										'full'        => __( 'Always full', 'jobs-sync-for-zoho-recruit' ),
									)
								);
								?>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="jszr-full_sync_interval_days"><?php esc_html_e( 'Days between full syncs', 'jobs-sync-for-zoho-recruit' ); ?></label></th>
							<td>
								<?php
								$jszr_input(
									'full_sync_interval_days',
									$settings,
									'number',
									__( 'A full sync is the only thing that can deactivate jobs missing from Zoho. Set to 0 to never force one.', 'jobs-sync-for-zoho-recruit' ),
									array(
										'min' => 0,
										'max' => 365,
									)
								);
								?>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="jszr-per_request"><?php esc_html_e( 'Records per API request', 'jobs-sync-for-zoho-recruit' ); ?></label></th>
							<td>
								<?php
								$jszr_input(
									'per_request',
									$settings,
									'number',
									__( 'Zoho allows up to 200.', 'jobs-sync-for-zoho-recruit' ),
									array(
										'min' => 1,
										'max' => 200,
									)
								);
								?>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="jszr-batch_size"><?php esc_html_e( 'Records per background batch', 'jobs-sync-for-zoho-recruit' ); ?></label></th>
							<td>
								<?php
								$jszr_input(
									'batch_size',
									$settings,
									'number',
									__( 'Keep this equal to the records per API request above: a batch reads one page and writes this many records from it, so a smaller batch re-reads the same page and spends extra Zoho API credits. Lower it only if background requests are hitting your host time limit.', 'jobs-sync-for-zoho-recruit' ),
									array(
										'min' => 1,
										'max' => 200,
									)
								);
								?>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="jszr-request_timeout"><?php esc_html_e( 'Request timeout (seconds)', 'jobs-sync-for-zoho-recruit' ); ?></label></th>
							<td>
								<?php
								$jszr_input(
									'request_timeout',
									$settings,
									'number',
									'',
									array(
										'min' => 5,
										'max' => 120,
									)
								);
								?>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Publish filter', 'jobs-sync-for-zoho-recruit' ); ?></th>
							<td>
								<?php
								$jszr_checkbox(
									'only_published',
									$settings,
									__( 'Only publish jobs that Zoho marks for the careers website', 'jobs-sync-for-zoho-recruit' ),
									__( 'Jobs that lose the flag are deactivated locally, never deleted.', 'jobs-sync-for-zoho-recruit' )
								);
								?>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="jszr-published_field"><?php esc_html_e( 'Publish flag field', 'jobs-sync-for-zoho-recruit' ); ?></label></th>
							<td>
								<?php
								$jszr_input(
									'published_field',
									$settings,
									'text',
									__( 'The Zoho API name of the checkbox that controls website publishing. The Field Mapping screen lists the API names available in your account.', 'jobs-sync-for-zoho-recruit' )
								);
								?>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="jszr-expire_action"><?php esc_html_e( 'When a job is closed or expires', 'jobs-sync-for-zoho-recruit' ); ?></label></th>
							<td>
								<?php
								$jszr_select(
									'expire_action',
									$settings,
									array(
										'inactive' => __( 'Keep the page published and mark it closed', 'jobs-sync-for-zoho-recruit' ),
										'draft'    => __( 'Move to draft', 'jobs-sync-for-zoho-recruit' ),
										'private'  => __( 'Make private', 'jobs-sync-for-zoho-recruit' ),
										'trash'    => __( 'Move to trash', 'jobs-sync-for-zoho-recruit' ),
									),
									__( 'Closed jobs never appear in listings or the public API regardless of this setting.', 'jobs-sync-for-zoho-recruit' )
								);
								?>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="jszr-orphan_action"><?php esc_html_e( 'When a job is deleted in Zoho', 'jobs-sync-for-zoho-recruit' ); ?></label></th>
							<td>
								<?php
								$jszr_select(
									'orphan_action',
									$settings,
									array(
										'draft'   => __( 'Move to draft', 'jobs-sync-for-zoho-recruit' ),
										'private' => __( 'Make private', 'jobs-sync-for-zoho-recruit' ),
										'trash'   => __( 'Move to trash', 'jobs-sync-for-zoho-recruit' ),
										'delete'  => __( 'Delete permanently', 'jobs-sync-for-zoho-recruit' ),
										'none'    => __( 'Do nothing', 'jobs-sync-for-zoho-recruit' ),
									),
									__( 'Jobs created by hand in WordPress are never touched.', 'jobs-sync-for-zoho-recruit' )
								);
								?>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="jszr-deactivation_threshold"><?php esc_html_e( 'Deactivation safety threshold (%)', 'jobs-sync-for-zoho-recruit' ); ?></label></th>
							<td>
								<?php
								$jszr_input(
									'deactivation_threshold',
									$settings,
									'number',
									__( 'If a full sync would deactivate more than this share of jobs, nothing is deactivated and the run is marked partial.', 'jobs-sync-for-zoho-recruit' ),
									array(
										'min' => 1,
										'max' => 100,
									)
								);
								?>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="jszr-conflict_mode"><?php esc_html_e( 'Conflict handling', 'jobs-sync-for-zoho-recruit' ); ?></label></th>
							<td>
								<?php
								$jszr_select(
									'conflict_mode',
									$settings,
									array(
										'mapped_only'     => __( 'Zoho updates mapped fields only (recommended)', 'jobs-sync-for-zoho-recruit' ),
										'overwrite_all'   => __( 'Zoho always overwrites', 'jobs-sync-for-zoho-recruit' ),
										'preserve_manual' => __( 'Preserve fields edited in WordPress', 'jobs-sync-for-zoho-recruit' ),
									)
								);
								?>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Raw data', 'jobs-sync-for-zoho-recruit' ); ?></th>
							<td>
								<?php
								$jszr_checkbox(
									'store_raw',
									$settings,
									__( 'Store the raw Zoho record with each job', 'jobs-sync-for-zoho-recruit' ),
									__( 'Useful for troubleshooting. Turn it off to save database space; it is never exposed publicly.', 'jobs-sync-for-zoho-recruit' )
								);
								?>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Failure notifications', 'jobs-sync-for-zoho-recruit' ); ?></th>
							<td>
								<?php
								$jszr_checkbox(
									'notify_on_failure',
									$settings,
									__( 'Email an administrator when a sync fails or the connection breaks', 'jobs-sync-for-zoho-recruit' )
								);
								?>
								<br /><br />
								<?php
								$jszr_input(
									'notify_email',
									$settings,
									'email',
									__( 'Leave blank to use the site administration email address.', 'jobs-sync-for-zoho-recruit' )
								);
								?>
							</td>
						</tr>
					</tbody>
				</table>

				<h2><?php esc_html_e( 'Status mapping', 'jobs-sync-for-zoho-recruit' ); ?></h2>
				<p><?php esc_html_e( 'Map each Zoho job opening status to how the job should behave on this site.', 'jobs-sync-for-zoho-recruit' ); ?></p>

				<table class="widefat striped jszr-status-map">
					<thead>
						<tr>
							<th scope="col"><?php esc_html_e( 'Zoho status', 'jobs-sync-for-zoho-recruit' ); ?></th>
							<th scope="col"><?php esc_html_e( 'On this site', 'jobs-sync-for-zoho-recruit' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php
						$jszr_local_statuses = array(
							'active'   => __( 'Active', 'jobs-sync-for-zoho-recruit' ),
							'inactive' => __( 'Inactive', 'jobs-sync-for-zoho-recruit' ),
							'closed'   => __( 'Closed', 'jobs-sync-for-zoho-recruit' ),
							'expired'  => __( 'Expired', 'jobs-sync-for-zoho-recruit' ),
							'draft'    => __( 'Draft', 'jobs-sync-for-zoho-recruit' ),
							'skip'     => __( 'Do not import', 'jobs-sync-for-zoho-recruit' ),
						);

						foreach ( (array) $settings['status_map'] as $jszr_zoho_status => $jszr_local ) :
							?>
							<tr>
								<td>
									<input type="text" readonly class="regular-text"
										value="<?php echo esc_attr( (string) $jszr_zoho_status ); ?>" />
								</td>
								<td>
									<select name="<?php echo esc_attr( $jszr_option ); ?>[status_map][<?php echo esc_attr( (string) $jszr_zoho_status ); ?>]">
										<?php foreach ( $jszr_local_statuses as $jszr_value => $jszr_label ) : ?>
											<option value="<?php echo esc_attr( $jszr_value ); ?>" <?php selected( (string) $jszr_local, $jszr_value ); ?>>
												<?php echo esc_html( $jszr_label ); ?>
											</option>
										<?php endforeach; ?>
									</select>
								</td>
							</tr>
						<?php endforeach; ?>
						<tr>
							<td>
								<input type="text" class="regular-text"
									name="<?php echo esc_attr( $jszr_option ); ?>[new_status_key]"
									placeholder="<?php esc_attr_e( 'Add another Zoho status', 'jobs-sync-for-zoho-recruit' ); ?>" />
							</td>
							<td>
								<select name="<?php echo esc_attr( $jszr_option ); ?>[new_status_value]">
									<?php foreach ( $jszr_local_statuses as $jszr_value => $jszr_label ) : ?>
										<option value="<?php echo esc_attr( $jszr_value ); ?>"><?php echo esc_html( $jszr_label ); ?></option>
									<?php endforeach; ?>
								</select>
							</td>
						</tr>
					</tbody>
				</table>

				<p>
					<label for="jszr-default_status"><?php esc_html_e( 'Status for unmapped Zoho values', 'jobs-sync-for-zoho-recruit' ); ?></label><br />
					<?php
					$jszr_select(
						'default_status',
						$settings,
						array(
							'active'   => __( 'Active', 'jobs-sync-for-zoho-recruit' ),
							'inactive' => __( 'Inactive', 'jobs-sync-for-zoho-recruit' ),
							'draft'    => __( 'Draft', 'jobs-sync-for-zoho-recruit' ),
						)
					);
					?>
				</p>

			<?php elseif ( 'api' === $active ) : ?>

				<table class="form-table" role="presentation">
					<tbody>
						<tr>
							<th scope="row"><?php esc_html_e( 'Public API', 'jobs-sync-for-zoho-recruit' ); ?></th>
							<td>
								<?php
								$jszr_checkbox(
									'rest_enabled',
									$settings,
									__( 'Enable the public jobs REST API', 'jobs-sync-for-zoho-recruit' ),
									sprintf(
										/* translators: %s: REST endpoint URL. */
										__( 'Endpoint: %s', 'jobs-sync-for-zoho-recruit' ),
										esc_url_raw( rest_url( REST_API::NAMESPACE_V1 . '/jobs' ) )
									)
								);
								?>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="jszr-rest_per_page"><?php esc_html_e( 'Default jobs per page', 'jobs-sync-for-zoho-recruit' ); ?></label></th>
							<td>
								<?php
								$jszr_input(
									'rest_per_page',
									$settings,
									'number',
									'',
									array(
										'min' => 1,
										'max' => 100,
									)
								);
								?>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="jszr-rest_max_per_page"><?php esc_html_e( 'Maximum jobs per page', 'jobs-sync-for-zoho-recruit' ); ?></label></th>
							<td>
								<?php
								$jszr_input(
									'rest_max_per_page',
									$settings,
									'number',
									__( 'Requests above this limit are rejected.', 'jobs-sync-for-zoho-recruit' ),
									array(
										'min' => 1,
										'max' => 200,
									)
								);
								?>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Inactive jobs', 'jobs-sync-for-zoho-recruit' ); ?></th>
							<td>
								<?php
								$jszr_checkbox(
									'rest_allow_inactive',
									$settings,
									__( 'Allow inactive and expired jobs to be requested through the API', 'jobs-sync-for-zoho-recruit' ),
									__( 'Off by default so closed jobs cannot be enumerated.', 'jobs-sync-for-zoho-recruit' )
								);
								?>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="jszr-cache_ttl"><?php esc_html_e( 'Cache lifetime (seconds)', 'jobs-sync-for-zoho-recruit' ); ?></label></th>
							<td>
								<?php
								$jszr_input(
									'cache_ttl',
									$settings,
									'number',
									__( 'Set to 0 to disable caching. Caches are cleared automatically when a sync completes.', 'jobs-sync-for-zoho-recruit' ),
									array(
										'min' => 0,
										'max' => 86400,
									)
								);
								?>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Fields exposed publicly', 'jobs-sync-for-zoho-recruit' ); ?></th>
							<td>
								<fieldset>
									<legend class="screen-reader-text"><?php esc_html_e( 'Fields exposed publicly', 'jobs-sync-for-zoho-recruit' ); ?></legend>
									<input type="hidden" name="<?php echo esc_attr( $jszr_option ); ?>[rest_fields][]" value="id" />
									<?php foreach ( Settings::available_rest_fields() as $jszr_field => $jszr_label ) : ?>
										<?php if ( 'id' === $jszr_field ) : ?>
											<?php continue; ?>
										<?php endif; ?>
										<label class="jszr-checkbox-row">
											<input type="checkbox"
												name="<?php echo esc_attr( $jszr_option ); ?>[rest_fields][]"
												value="<?php echo esc_attr( $jszr_field ); ?>"
												<?php checked( in_array( $jszr_field, (array) $settings['rest_fields'], true ) ); ?> />
											<?php echo esc_html( $jszr_label ); ?>
										</label>
									<?php endforeach; ?>
								</fieldset>
								<p class="description"><?php esc_html_e( 'This list also controls which job meta fields appear in the core wp/v2 REST endpoint.', 'jobs-sync-for-zoho-recruit' ); ?></p>
							</td>
						</tr>
					</tbody>
				</table>

			<?php elseif ( 'frontend' === $active ) : ?>

				<table class="form-table" role="presentation">
					<tbody>
						<tr>
							<th scope="row"><?php esc_html_e( 'Public job pages', 'jobs-sync-for-zoho-recruit' ); ?></th>
							<td>
								<?php
								$jszr_checkbox(
									'public_jobs',
									$settings,
									__( 'Give synchronized jobs their own public pages and archive', 'jobs-sync-for-zoho-recruit' ),
									__( 'Turn this off to keep jobs available only through the API, shortcode or block.', 'jobs-sync-for-zoho-recruit' )
								);
								?>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="jszr-job_slug"><?php esc_html_e( 'Job URL slug', 'jobs-sync-for-zoho-recruit' ); ?></label></th>
							<td>
								<?php
								$jszr_input(
									'job_slug',
									$settings,
									'text',
									sprintf(
										/* translators: %s: example job URL. */
										__( 'Example: %s', 'jobs-sync-for-zoho-recruit' ),
										home_url( '/' . (string) $settings['job_slug'] . '/software-developer/' )
									)
								);
								?>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="jszr-archive_slug"><?php esc_html_e( 'Archive slug', 'jobs-sync-for-zoho-recruit' ); ?></label></th>
							<td><?php $jszr_input( 'archive_slug', $settings, 'text' ); ?></td>
						</tr>
						<tr>
							<th scope="row"><label for="jszr-default_style"><?php esc_html_e( 'Default listing style', 'jobs-sync-for-zoho-recruit' ); ?></label></th>
							<td>
								<?php
								$jszr_select(
									'default_style',
									$settings,
									array(
										'list' => __( 'List', 'jobs-sync-for-zoho-recruit' ),
										'grid' => __( 'Grid', 'jobs-sync-for-zoho-recruit' ),
									)
								);
								?>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="jszr-expired_behavior"><?php esc_html_e( 'Expired job pages', 'jobs-sync-for-zoho-recruit' ); ?></label></th>
							<td>
								<?php
								$jszr_select(
									'expired_behavior',
									$settings,
									array(
										'notice'   => __( 'Show a "position closed" notice and mark the page noindex', 'jobs-sync-for-zoho-recruit' ),
										'gone'     => __( 'Return HTTP 410 Gone', 'jobs-sync-for-zoho-recruit' ),
										'redirect' => __( 'Redirect to the jobs archive', 'jobs-sync-for-zoho-recruit' ),
									)
								);
								?>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="jszr-apply_label"><?php esc_html_e( 'Apply button label', 'jobs-sync-for-zoho-recruit' ); ?></label></th>
							<td>
								<?php
								$jszr_input(
									'apply_label',
									$settings,
									'text',
									__( 'Leave blank to use "Apply Now".', 'jobs-sync-for-zoho-recruit' )
								);
								?>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="jszr-apply_target"><?php esc_html_e( 'Apply link opens', 'jobs-sync-for-zoho-recruit' ); ?></label></th>
							<td>
								<?php
								$jszr_select(
									'apply_target',
									$settings,
									array(
										'new_tab'  => __( 'In a new tab', 'jobs-sync-for-zoho-recruit' ),
										'same_tab' => __( 'In the same tab', 'jobs-sync-for-zoho-recruit' ),
									),
									__( 'Applications are completed on Zoho Recruit, not on this site. A new tab keeps your listing open behind it.', 'jobs-sync-for-zoho-recruit' )
								);
								?>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="jszr-apply_url_template"><?php esc_html_e( 'Fallback application URL', 'jobs-sync-for-zoho-recruit' ); ?></label></th>
							<td>
								<?php
								$jszr_input(
									'apply_url_template',
									$settings,
									'text',
									__( 'Used when a Zoho record has no application URL. Placeholders: {zoho_id}, {job_code}, {slug}, {id}.', 'jobs-sync-for-zoho-recruit' )
								);
								?>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="jszr-apply_utm"><?php esc_html_e( 'Extra apply link parameters', 'jobs-sync-for-zoho-recruit' ); ?></label></th>
							<td>
								<?php
								$jszr_input(
									'apply_utm',
									$settings,
									'text',
									__( 'Appended to every apply link, for example: utm_source=website&utm_medium=careers', 'jobs-sync-for-zoho-recruit' )
								);
								?>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Sitemap', 'jobs-sync-for-zoho-recruit' ); ?></th>
							<td>
								<?php
								$jszr_checkbox(
									'sitemap_enabled',
									$settings,
									__( 'Include jobs in the WordPress sitemap', 'jobs-sync-for-zoho-recruit' )
								);
								?>
							</td>
						</tr>
					</tbody>
				</table>

			<?php elseif ( 'display' === $active ) : ?>

				<h2><?php esc_html_e( 'Job listing', 'jobs-sync-for-zoho-recruit' ); ?></h2>

				<table class="form-table" role="presentation">
					<tbody>
						<tr>
							<th scope="row"><label for="jszr-listing_layout"><?php esc_html_e( 'Listing layout', 'jobs-sync-for-zoho-recruit' ); ?></label></th>
							<td>
								<?php
								$jszr_select(
									'listing_layout',
									$settings,
									Layouts::listing_layouts(),
									__( 'Applies to the shortcode, the block and the job archive. A copy of card.php in your theme still wins over all of these.', 'jobs-sync-for-zoho-recruit' )
								);
								?>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="jszr-card_template"><?php esc_html_e( 'Card HTML', 'jobs-sync-for-zoho-recruit' ); ?></label></th>
							<td>
								<textarea id="jszr-card_template" class="large-text code" rows="14"
									name="<?php echo esc_attr( $jszr_option ); ?>[card_template]"
									spellcheck="false"><?php echo esc_textarea( (string) $settings['card_template'] ); ?></textarea>
								<p class="description">
									<?php esc_html_e( 'Used when the layout is set to Custom. Leave it empty to fall back to the default card.', 'jobs-sync-for-zoho-recruit' ); ?>
								</p>
								<p>
									<button type="button" class="button jszr-copy-preset"
										data-jszr-target="jszr-card_template"
										data-jszr-preset="<?php echo esc_attr( Layouts::listing_preset( 'card' ) ); ?>">
										<?php esc_html_e( 'Start from the Card layout', 'jobs-sync-for-zoho-recruit' ); ?>
									</button>
									<button type="button" class="button jszr-copy-preset"
										data-jszr-target="jszr-card_template"
										data-jszr-preset="<?php echo esc_attr( Layouts::listing_preset( 'compact' ) ); ?>">
										<?php esc_html_e( 'Start from the Compact layout', 'jobs-sync-for-zoho-recruit' ); ?>
									</button>
								</p>
							</td>
						</tr>
					</tbody>
				</table>

				<h2><?php esc_html_e( 'Preview', 'jobs-sync-for-zoho-recruit' ); ?></h2>

				<?php
				$jszr_preview_template = Layouts::listing_template();
				$jszr_preview_info     = Layouts::job_info_template();
				?>

				<p class="description">
					<?php esc_html_e( 'The saved layout, drawn with a real job where one exists. Save the page to refresh it.', 'jobs-sync-for-zoho-recruit' ); ?>
				</p>

				<div class="jszr-preview jszr-jobs">
					<?php if ( '' !== $jszr_preview_template ) : ?>
						<?php
						echo wp_kses(
							Layouts::render_preview( $jszr_preview_template ),
							Layouts::allowed_html()
						);
						?>
					<?php else : ?>
						<p class="description"><?php esc_html_e( 'The default card is rendered by a PHP template, so there is nothing to preview here. Choose another layout to see it.', 'jobs-sync-for-zoho-recruit' ); ?></p>
					<?php endif; ?>

					<?php if ( '' !== $jszr_preview_info ) : ?>
						<?php
						echo wp_kses(
							Layouts::render_preview( $jszr_preview_info ),
							Layouts::allowed_html()
						);
						?>
					<?php endif; ?>
				</div>

				<h2><?php esc_html_e( 'Job details', 'jobs-sync-for-zoho-recruit' ); ?></h2>

				<table class="form-table" role="presentation">
					<tbody>
						<tr>
							<th scope="row"><label for="jszr-job_info_layout"><?php esc_html_e( 'Job details layout', 'jobs-sync-for-zoho-recruit' ); ?></label></th>
							<td>
								<?php
								$jszr_select(
									'job_info_layout',
									$settings,
									Layouts::job_info_layouts(),
									__( 'The facts shown on a single job page, by the Job Details block and by the [zoho_job_meta] shortcode.', 'jobs-sync-for-zoho-recruit' )
								);
								?>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Fields to show', 'jobs-sync-for-zoho-recruit' ); ?></th>
							<td>
								<fieldset>
									<legend class="screen-reader-text"><?php esc_html_e( 'Fields to show', 'jobs-sync-for-zoho-recruit' ); ?></legend>
									<?php foreach ( Settings::available_job_info_fields() as $jszr_field => $jszr_label ) : ?>
										<label class="jszr-checkbox-row">
											<input type="checkbox"
												name="<?php echo esc_attr( $jszr_option ); ?>[job_info_fields][]"
												value="<?php echo esc_attr( $jszr_field ); ?>"
												<?php checked( in_array( $jszr_field, (array) $settings['job_info_fields'], true ) ); ?> />
											<?php echo esc_html( $jszr_label ); ?>
										</label>
									<?php endforeach; ?>
								</fieldset>
								<p class="description"><?php esc_html_e( 'A field with no value on a job is skipped rather than shown empty. The Custom layout ignores this list and decides for itself.', 'jobs-sync-for-zoho-recruit' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="jszr-job_info_template"><?php esc_html_e( 'Job details HTML', 'jobs-sync-for-zoho-recruit' ); ?></label></th>
							<td>
								<textarea id="jszr-job_info_template" class="large-text code" rows="10"
									name="<?php echo esc_attr( $jszr_option ); ?>[job_info_template]"
									spellcheck="false"><?php echo esc_textarea( (string) $settings['job_info_template'] ); ?></textarea>
								<p>
									<button type="button" class="button jszr-copy-preset"
										data-jszr-target="jszr-job_info_template"
										data-jszr-preset="<?php echo esc_attr( Layouts::job_info_preset( 'inline' ) ); ?>">
										<?php esc_html_e( 'Start from the Inline layout', 'jobs-sync-for-zoho-recruit' ); ?>
									</button>
								</p>
							</td>
						</tr>
					</tbody>
				</table>

				<h2><?php esc_html_e( 'Available tags', 'jobs-sync-for-zoho-recruit' ); ?></h2>

				<p>
					<?php esc_html_e( 'Use these in either HTML box. A tag with no value on a job becomes nothing at all.', 'jobs-sync-for-zoho-recruit' ); ?>
					<?php
					printf(
						/* translators: 1: an example conditional opening tag, 2: the matching closing tag. */
						esc_html__( 'Wrap a part in %1$s and %2$s to drop it entirely when that value is missing.', 'jobs-sync-for-zoho-recruit' ),
						'<code>{if:salary}</code>',
						'<code>{/if:salary}</code>'
					);
					?>
				</p>

				<table class="widefat striped jszr-tag-reference">
					<thead>
						<tr>
							<th scope="col"><?php esc_html_e( 'Tag', 'jobs-sync-for-zoho-recruit' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Value', 'jobs-sync-for-zoho-recruit' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( Template_Tags::documented_tags() as $jszr_tag => $jszr_description ) : ?>
							<tr>
								<td><code>{<?php echo esc_html( $jszr_tag ); ?>}</code></td>
								<td><?php echo esc_html( $jszr_description ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>

				<h2><?php esc_html_e( 'Search and filters', 'jobs-sync-for-zoho-recruit' ); ?></h2>

				<table class="form-table" role="presentation">
					<tbody>
						<tr>
							<th scope="row"><?php esc_html_e( 'Show by default', 'jobs-sync-for-zoho-recruit' ); ?></th>
							<td>
								<?php
								$jszr_checkbox( 'show_search_default', $settings, __( 'Search box', 'jobs-sync-for-zoho-recruit' ) );
								echo '<br />';
								$jszr_checkbox( 'show_filters_default', $settings, __( 'Filter dropdowns', 'jobs-sync-for-zoho-recruit' ) );
								echo '<br />';
								$jszr_checkbox( 'show_sort', $settings, __( 'Sort control', 'jobs-sync-for-zoho-recruit' ) );
								?>
								<p class="description"><?php esc_html_e( 'An individual shortcode or block can still switch each one on or off.', 'jobs-sync-for-zoho-recruit' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Filters to offer', 'jobs-sync-for-zoho-recruit' ); ?></th>
							<td>
								<fieldset>
									<legend class="screen-reader-text"><?php esc_html_e( 'Filters to offer', 'jobs-sync-for-zoho-recruit' ); ?></legend>
									<?php foreach ( REST_API::filter_map() as $jszr_param => $jszr_taxonomy ) : ?>
										<?php $jszr_tax_object = get_taxonomy( $jszr_taxonomy ); ?>
										<label class="jszr-checkbox-row">
											<input type="checkbox"
												name="<?php echo esc_attr( $jszr_option ); ?>[filter_fields][]"
												value="<?php echo esc_attr( $jszr_param ); ?>"
												<?php checked( in_array( $jszr_param, (array) $settings['filter_fields'], true ) ); ?> />
											<?php echo esc_html( $jszr_tax_object ? $jszr_tax_object->labels->singular_name : $jszr_param ); ?>
										</label>
									<?php endforeach; ?>
								</fieldset>
								<p class="description"><?php esc_html_e( 'A filter with no terms yet is left out automatically.', 'jobs-sync-for-zoho-recruit' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="jszr-filters_layout"><?php esc_html_e( 'Filter bar layout', 'jobs-sync-for-zoho-recruit' ); ?></label></th>
							<td>
								<?php
								$jszr_select(
									'filters_layout',
									$settings,
									array(
										'inline'  => __( 'Inline — side by side', 'jobs-sync-for-zoho-recruit' ),
										'stacked' => __( 'Stacked — one per line, for narrow columns', 'jobs-sync-for-zoho-recruit' ),
									)
								);
								?>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="jszr-search_placeholder"><?php esc_html_e( 'Search placeholder', 'jobs-sync-for-zoho-recruit' ); ?></label></th>
							<td><?php $jszr_input( 'search_placeholder', $settings, 'text', __( 'Leave blank to use "Job title or code".', 'jobs-sync-for-zoho-recruit' ) ); ?></td>
						</tr>
						<tr>
							<th scope="row"><label for="jszr-filters_button_label"><?php esc_html_e( 'Filter button label', 'jobs-sync-for-zoho-recruit' ); ?></label></th>
							<td><?php $jszr_input( 'filters_button_label', $settings, 'text', __( 'Leave blank to use "Filter".', 'jobs-sync-for-zoho-recruit' ) ); ?></td>
						</tr>
					</tbody>
				</table>

				<h2><?php esc_html_e( 'Custom CSS', 'jobs-sync-for-zoho-recruit' ); ?></h2>

				<table class="form-table" role="presentation">
					<tbody>
						<tr>
							<th scope="row"><label for="jszr-custom_css"><?php esc_html_e( 'CSS', 'jobs-sync-for-zoho-recruit' ); ?></label></th>
							<td>
								<textarea id="jszr-custom_css" class="large-text code" rows="12"
									name="<?php echo esc_attr( $jszr_option ); ?>[custom_css]"
									spellcheck="false"><?php echo esc_textarea( (string) $settings['custom_css'] ); ?></textarea>
								<p class="description">
									<?php esc_html_e( 'Loaded only on pages that show jobs, and after the plugin stylesheet so it wins. Every class the plugin prints starts with jszr-.', 'jobs-sync-for-zoho-recruit' ); ?>
								</p>
								<p class="description">
									<code>.jszr-job-card { border-color: #0b5cff; }</code>
								</p>
							</td>
						</tr>
					</tbody>
				</table>

			<?php elseif ( 'schema' === $active ) : ?>

				<table class="form-table" role="presentation">
					<tbody>
						<tr>
							<th scope="row"><?php esc_html_e( 'JobPosting markup', 'jobs-sync-for-zoho-recruit' ); ?></th>
							<td>
								<?php
								$jszr_checkbox(
									'schema_enabled',
									$settings,
									__( 'Output JobPosting structured data on single job pages', 'jobs-sync-for-zoho-recruit' ),
									__( 'Markup is only emitted for active jobs, and only when the required information is present.', 'jobs-sync-for-zoho-recruit' )
								);
								?>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'SEO plugins', 'jobs-sync-for-zoho-recruit' ); ?></th>
							<td>
								<?php
								$jszr_checkbox(
									'schema_skip_if_seo',
									$settings,
									__( 'Skip output when another plugin already emits JobPosting markup for the job', 'jobs-sync-for-zoho-recruit' )
								);
								?>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="jszr-org_name"><?php esc_html_e( 'Hiring organization name', 'jobs-sync-for-zoho-recruit' ); ?></label></th>
							<td>
								<?php
								$jszr_input(
									'org_name',
									$settings,
									'text',
									__( 'Leave blank to use the site title.', 'jobs-sync-for-zoho-recruit' )
								);
								?>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="jszr-org_url"><?php esc_html_e( 'Organization URL', 'jobs-sync-for-zoho-recruit' ); ?></label></th>
							<td><?php $jszr_input( 'org_url', $settings, 'url' ); ?></td>
						</tr>
						<tr>
							<th scope="row"><label for="jszr-org_logo"><?php esc_html_e( 'Organization logo URL', 'jobs-sync-for-zoho-recruit' ); ?></label></th>
							<td><?php $jszr_input( 'org_logo', $settings, 'url' ); ?></td>
						</tr>
					</tbody>
				</table>

			<?php elseif ( 'advanced' === $active ) : ?>

				<table class="form-table" role="presentation">
					<tbody>
						<tr>
							<th scope="row"><?php esc_html_e( 'Debug logging', 'jobs-sync-for-zoho-recruit' ); ?></th>
							<td>
								<?php
								$jszr_checkbox(
									'debug_logging',
									$settings,
									__( 'Record detailed debug entries', 'jobs-sync-for-zoho-recruit' ),
									__( 'Tokens and secrets are always removed before anything is written to the log.', 'jobs-sync-for-zoho-recruit' )
								);
								?>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="jszr-log_retention_days"><?php esc_html_e( 'Keep logs for (days)', 'jobs-sync-for-zoho-recruit' ); ?></label></th>
							<td>
								<?php
								$jszr_input(
									'log_retention_days',
									$settings,
									'number',
									__( '0 keeps entries until the maximum count is reached.', 'jobs-sync-for-zoho-recruit' ),
									array(
										'min' => 0,
										'max' => 365,
									)
								);
								?>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="jszr-log_retention_max"><?php esc_html_e( 'Maximum log entries', 'jobs-sync-for-zoho-recruit' ); ?></label></th>
							<td>
								<?php
								$jszr_input(
									'log_retention_max',
									$settings,
									'number',
									'',
									array(
										'min' => 0,
										'max' => 10000,
									)
								);
								?>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Webhook', 'jobs-sync-for-zoho-recruit' ); ?></th>
							<td>
								<?php
								$jszr_checkbox(
									'webhook_enabled',
									$settings,
									__( 'Accept webhook triggers from Zoho Recruit', 'jobs-sync-for-zoho-recruit' ),
									__( 'The payload is only used as a trigger; the record is always re-read from the Zoho API.', 'jobs-sync-for-zoho-recruit' )
								);
								?>
								<p>
									<label for="jszr-webhook-url"><?php esc_html_e( 'Webhook URL', 'jobs-sync-for-zoho-recruit' ); ?></label><br />
									<input type="text" id="jszr-webhook-url" class="large-text code" readonly
										value="<?php echo esc_attr( Webhook::url() ); ?>" onfocus="this.select();" />
								</p>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Uninstall', 'jobs-sync-for-zoho-recruit' ); ?></th>
							<td>
								<?php
								$jszr_checkbox(
									'uninstall_delete_data',
									$settings,
									__( 'Delete plugin settings, tokens and logs when the plugin is deleted', 'jobs-sync-for-zoho-recruit' )
								);
								?>
								<br />
								<?php
								$jszr_checkbox(
									'uninstall_delete_jobs',
									$settings,
									__( 'Also delete synchronized job posts and their taxonomy terms', 'jobs-sync-for-zoho-recruit' ),
									__( 'Off by default. Jobs created by hand are never deleted.', 'jobs-sync-for-zoho-recruit' )
								);
								?>
							</td>
						</tr>
					</tbody>
				</table>

			<?php endif; ?>

			<?php submit_button(); ?>
		</form>

		<?php if ( 'advanced' === $active ) : ?>
			<hr />
			<h2><?php esc_html_e( 'Import and export', 'jobs-sync-for-zoho-recruit' ); ?></h2>
			<p><?php esc_html_e( 'Move configuration between staging and production. Credentials and tokens are never included.', 'jobs-sync-for-zoho-recruit' ); ?></p>

			<p class="jszr-actions">
				<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=jszr_export_settings' ), 'jszr_export_settings' ) ); ?>">
					<?php esc_html_e( 'Export settings', 'jobs-sync-for-zoho-recruit' ); ?>
				</a>
			</p>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data" class="jszr-inline-form">
				<?php wp_nonce_field( 'jszr_import_settings' ); ?>
				<input type="hidden" name="action" value="jszr_import_settings" />
				<label for="jszr-settings-file" class="screen-reader-text"><?php esc_html_e( 'Settings JSON file', 'jobs-sync-for-zoho-recruit' ); ?></label>
				<input type="file" id="jszr-settings-file" name="jszr_settings_file" accept="application/json,.json" required />
				<button type="submit" class="button"><?php esc_html_e( 'Import settings', 'jobs-sync-for-zoho-recruit' ); ?></button>
			</form>

			<hr />
			<h2><?php esc_html_e( 'Webhook secret', 'jobs-sync-for-zoho-recruit' ); ?></h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'jszr_regenerate_webhook' ); ?>
				<input type="hidden" name="action" value="jszr_regenerate_webhook" />
				<button type="submit" class="button"><?php esc_html_e( 'Generate a new webhook URL', 'jobs-sync-for-zoho-recruit' ); ?></button>
			</form>
		<?php endif; ?>

	<?php endif; ?>
</div>
