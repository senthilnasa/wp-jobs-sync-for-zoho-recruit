<?php
/**
 * Dashboard screen.
 *
 * @package JobsSyncForZohoRecruit
 *
 * @var array                                  $connection Connection info.
 * @var array                                  $state      Sync state summary.
 * @var array                                  $counts     Job counts by status.
 * @var \JobsSyncForZohoRecruit\Zoho_Auth      $auth       OAuth handler.
 * @var array                                  $runs       Recent sync runs.
 */

namespace JobsSyncForZohoRecruit;

defined( 'ABSPATH' ) || exit;

$jszr_date_format = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
$jszr_active_run  = isset( $state['active'] ) ? $state['active'] : null;
?>
<div class="wrap jszr-wrap">
	<h1><?php esc_html_e( 'Zoho Recruit Jobs', 'jobs-sync-for-zoho-recruit' ); ?></h1>

	<div class="jszr-cards">
		<div class="jszr-card">
			<h2><?php esc_html_e( 'Connection', 'jobs-sync-for-zoho-recruit' ); ?></h2>
			<table class="jszr-kv">
				<tbody>
					<tr>
						<th scope="row"><?php esc_html_e( 'Status', 'jobs-sync-for-zoho-recruit' ); ?></th>
						<td>
							<?php if ( $connection['connected'] ) : ?>
								<span class="jszr-badge jszr-badge-active"><?php esc_html_e( 'Connected', 'jobs-sync-for-zoho-recruit' ); ?></span>
							<?php else : ?>
								<span class="jszr-badge jszr-badge-inactive"><?php esc_html_e( 'Not connected', 'jobs-sync-for-zoho-recruit' ); ?></span>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Data center', 'jobs-sync-for-zoho-recruit' ); ?></th>
						<td><?php echo esc_html( $connection['data_center_label'] ); ?></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'API endpoint', 'jobs-sync-for-zoho-recruit' ); ?></th>
						<td><code><?php echo esc_html( $connection['api_base'] ); ?></code></td>
					</tr>
					<?php if ( $connection['connected'] ) : ?>
						<tr>
							<th scope="row"><?php esc_html_e( 'Access token expires', 'jobs-sync-for-zoho-recruit' ); ?></th>
							<td>
								<?php
								echo $connection['expires_at']
									? esc_html( wp_date( $jszr_date_format, $connection['expires_at'] ) )
									: esc_html__( 'Unknown', 'jobs-sync-for-zoho-recruit' );
								?>
							</td>
						</tr>
					<?php endif; ?>
				</tbody>
			</table>

			<p class="jszr-actions">
				<?php if ( ! $connection['connected'] ) : ?>
					<?php if ( $auth->has_credentials() ) : ?>
						<a class="button button-primary" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=jszr_oauth_start' ), 'jszr_oauth_start' ) ); ?>">
							<?php esc_html_e( 'Connect to Zoho Recruit', 'jobs-sync-for-zoho-recruit' ); ?>
						</a>
					<?php else : ?>
						<a class="button button-primary" href="<?php echo esc_url( jszr_admin_url( Admin::SETTINGS_SLUG, array( 'tab' => 'connection' ) ) ); ?>">
							<?php esc_html_e( 'Add Zoho credentials', 'jobs-sync-for-zoho-recruit' ); ?>
						</a>
					<?php endif; ?>
				<?php else : ?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="jszr-inline-form">
						<?php wp_nonce_field( 'jszr_test_connection' ); ?>
						<input type="hidden" name="action" value="jszr_test_connection" />
						<button type="submit" class="button"><?php esc_html_e( 'Test Connection', 'jobs-sync-for-zoho-recruit' ); ?></button>
					</form>
					<a class="button button-link-delete" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=jszr_oauth_disconnect' ), 'jszr_oauth_disconnect' ) ); ?>">
						<?php esc_html_e( 'Disconnect Zoho', 'jobs-sync-for-zoho-recruit' ); ?>
					</a>
				<?php endif; ?>
			</p>
		</div>

		<div class="jszr-card">
			<h2><?php esc_html_e( 'Jobs', 'jobs-sync-for-zoho-recruit' ); ?></h2>
			<table class="jszr-kv">
				<tbody>
					<?php
					$jszr_count_labels = array(
						'total'    => __( 'Total', 'jobs-sync-for-zoho-recruit' ),
						'active'   => __( 'Active', 'jobs-sync-for-zoho-recruit' ),
						'expired'  => __( 'Expired', 'jobs-sync-for-zoho-recruit' ),
						'inactive' => __( 'Inactive', 'jobs-sync-for-zoho-recruit' ),
						'closed'   => __( 'Closed', 'jobs-sync-for-zoho-recruit' ),
						'draft'    => __( 'Draft', 'jobs-sync-for-zoho-recruit' ),
					);

					foreach ( $jszr_count_labels as $jszr_key => $jszr_label ) :
						?>
						<tr>
							<th scope="row"><?php echo esc_html( $jszr_label ); ?></th>
							<td><?php echo esc_html( number_format_i18n( (int) ( $counts[ $jszr_key ] ?? 0 ) ) ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<p class="jszr-actions">
				<a class="button" href="<?php echo esc_url( admin_url( 'edit.php?post_type=' . Post_Type::POST_TYPE ) ); ?>">
					<?php esc_html_e( 'View all jobs', 'jobs-sync-for-zoho-recruit' ); ?>
				</a>
			</p>
		</div>

		<div class="jszr-card">
			<h2><?php esc_html_e( 'Synchronization', 'jobs-sync-for-zoho-recruit' ); ?></h2>
			<table class="jszr-kv">
				<tbody>
					<tr>
						<th scope="row"><?php esc_html_e( 'Last sync', 'jobs-sync-for-zoho-recruit' ); ?></th>
						<td>
							<?php
							if ( ! empty( $state['last_run'] ) ) {
								$jszr_started = strtotime( $state['last_run']['started_at'] . ' UTC' );

								printf(
									'%1$s &mdash; %2$s',
									esc_html( $jszr_started ? wp_date( $jszr_date_format, $jszr_started ) : $state['last_run']['started_at'] ),
									esc_html( $state['last_run']['state'] )
								);
							} else {
								esc_html_e( 'Never', 'jobs-sync-for-zoho-recruit' );
							}
							?>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Last successful sync', 'jobs-sync-for-zoho-recruit' ); ?></th>
						<td>
							<?php
							echo $state['last_success_time']
								? esc_html( wp_date( $jszr_date_format, (int) $state['last_success_time'] ) )
								: esc_html__( 'Never', 'jobs-sync-for-zoho-recruit' );
							?>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Next scheduled sync', 'jobs-sync-for-zoho-recruit' ); ?></th>
						<td>
							<?php
							echo $state['next_scheduled']
								? esc_html( wp_date( $jszr_date_format, (int) $state['next_scheduled'] ) )
								: esc_html__( 'Not scheduled', 'jobs-sync-for-zoho-recruit' );
							?>
						</td>
					</tr>
				</tbody>
			</table>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="jszr-sync-form">
				<?php wp_nonce_field( 'jszr_start_sync' ); ?>
				<input type="hidden" name="action" value="jszr_start_sync" />

				<p class="jszr-actions">
					<button type="submit" name="sync_type" value="full" class="button button-primary" <?php disabled( ! $connection['connected'] ); ?>>
						<?php esc_html_e( 'Full Sync', 'jobs-sync-for-zoho-recruit' ); ?>
					</button>
					<button type="submit" name="sync_type" value="incremental" class="button" <?php disabled( ! $connection['connected'] ); ?>>
						<?php esc_html_e( 'Incremental Sync', 'jobs-sync-for-zoho-recruit' ); ?>
					</button>
				</p>

				<p>
					<label>
						<input type="checkbox" name="dry_run" value="1" />
						<?php esc_html_e( 'Dry run (report changes without writing anything)', 'jobs-sync-for-zoho-recruit' ); ?>
					</label>
				</p>
			</form>

			<?php if ( $jszr_active_run ) : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<?php wp_nonce_field( 'jszr_cancel_sync' ); ?>
					<input type="hidden" name="action" value="jszr_cancel_sync" />
					<button type="submit" class="button button-link-delete"><?php esc_html_e( 'Cancel running sync', 'jobs-sync-for-zoho-recruit' ); ?></button>
				</form>
			<?php endif; ?>
		</div>
	</div>

	<div class="jszr-card jszr-progress-card"
		id="jszr-sync-progress"
		data-run-id="<?php echo esc_attr( (string) ( $jszr_active_run['run_id'] ?? 0 ) ); ?>"
		<?php echo $jszr_active_run ? '' : 'hidden'; ?>>
		<h2><?php esc_html_e( 'Sync progress', 'jobs-sync-for-zoho-recruit' ); ?></h2>
		<div class="jszr-progress-bar" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0">
			<span class="jszr-progress-fill" style="width:0%"></span>
		</div>
		<p class="jszr-progress-text" aria-live="polite"></p>
	</div>

	<h2><?php esc_html_e( 'Recent syncs', 'jobs-sync-for-zoho-recruit' ); ?></h2>

	<table class="widefat striped">
		<thead>
			<tr>
				<th scope="col"><?php esc_html_e( 'Started', 'jobs-sync-for-zoho-recruit' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Type', 'jobs-sync-for-zoho-recruit' ); ?></th>
				<th scope="col"><?php esc_html_e( 'State', 'jobs-sync-for-zoho-recruit' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Added', 'jobs-sync-for-zoho-recruit' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Updated', 'jobs-sync-for-zoho-recruit' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Expired', 'jobs-sync-for-zoho-recruit' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Deactivated', 'jobs-sync-for-zoho-recruit' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Errors', 'jobs-sync-for-zoho-recruit' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php if ( empty( $runs ) ) : ?>
				<tr>
					<td colspan="8"><?php esc_html_e( 'No syncs have run yet.', 'jobs-sync-for-zoho-recruit' ); ?></td>
				</tr>
			<?php else : ?>
				<?php foreach ( $runs as $jszr_run ) : ?>
					<?php $jszr_started = strtotime( $jszr_run->started_at . ' UTC' ); ?>
					<tr>
						<td><?php echo esc_html( $jszr_started ? wp_date( $jszr_date_format, $jszr_started ) : $jszr_run->started_at ); ?></td>
						<td><?php echo esc_html( $jszr_run->type ); ?></td>
						<td><span class="jszr-badge jszr-badge-<?php echo esc_attr( $jszr_run->state ); ?>"><?php echo esc_html( $jszr_run->state ); ?></span></td>
						<td><?php echo esc_html( number_format_i18n( (int) $jszr_run->created_count ) ); ?></td>
						<td><?php echo esc_html( number_format_i18n( (int) $jszr_run->updated_count ) ); ?></td>
						<td><?php echo esc_html( number_format_i18n( (int) $jszr_run->expired_count ) ); ?></td>
						<td><?php echo esc_html( number_format_i18n( (int) $jszr_run->deactivated_count ) ); ?></td>
						<td><?php echo esc_html( number_format_i18n( (int) $jszr_run->error_count ) ); ?></td>
					</tr>
				<?php endforeach; ?>
			<?php endif; ?>
		</tbody>
	</table>

	<p>
		<a href="<?php echo esc_url( jszr_admin_url( Admin::LOGS_SLUG ) ); ?>">
			<?php esc_html_e( 'View full sync log', 'jobs-sync-for-zoho-recruit' ); ?>
		</a>
	</p>
</div>
