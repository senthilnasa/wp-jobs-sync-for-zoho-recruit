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
 * @var \WP_Post[]                              $recent     Recently changed jobs.
 */

namespace JobsSyncForZohoRecruit;

defined( 'ABSPATH' ) || exit;

$jszr_date_format = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
$jszr_active_run  = isset( $state['active'] ) ? $state['active'] : null;
?>
<div class="wrap jszr-wrap">
	<h1><?php esc_html_e( 'Zoho Recruit Jobs', 'jobs-sync-for-zoho-recruit' ); ?></h1>

	<?php
	/*
	 * The four numbers someone opening this screen actually wants: how many
	 * roles are live, how many are waiting, how many have lapsed, and how big
	 * the whole set is. Each tile links into the job list already filtered.
	 */
	$jszr_tiles = array(
		array(
			'label' => __( 'Active jobs', 'jobs-sync-for-zoho-recruit' ),
			'value' => isset( $counts['active'] ) ? (int) $counts['active'] : 0,
			'tone'  => 'active',
			'args'  => array( 'jszr_status' => 'active' ),
		),
		array(
			'label' => __( 'Draft jobs', 'jobs-sync-for-zoho-recruit' ),
			'value' => isset( $counts['draft'] ) ? (int) $counts['draft'] : 0,
			'tone'  => 'draft',
			'args'  => array( 'post_status' => 'draft' ),
		),
		array(
			'label' => __( 'Expired jobs', 'jobs-sync-for-zoho-recruit' ),
			'value' => isset( $counts['expired'] ) ? (int) $counts['expired'] : 0,
			'tone'  => 'expired',
			'args'  => array( 'jszr_status' => 'expired' ),
		),
		array(
			'label' => __( 'Total jobs', 'jobs-sync-for-zoho-recruit' ),
			'value' => isset( $counts['total'] ) ? (int) $counts['total'] : array_sum( array_map( 'intval', (array) $counts ) ),
			'tone'  => 'total',
			'args'  => array(),
		),
	);
	?>

	<ul class="jszr-stats">
		<?php foreach ( $jszr_tiles as $jszr_tile ) : ?>
			<li class="jszr-stat jszr-stat--<?php echo esc_attr( $jszr_tile['tone'] ); ?>">
				<a href="<?php echo esc_url( add_query_arg( $jszr_tile['args'], admin_url( 'edit.php?post_type=' . Post_Type::POST_TYPE ) ) ); ?>">
					<span class="jszr-stat__value"><?php echo esc_html( number_format_i18n( $jszr_tile['value'] ) ); ?></span>
					<span class="jszr-stat__label"><?php echo esc_html( $jszr_tile['label'] ); ?></span>
				</a>
			</li>
		<?php endforeach; ?>
	</ul>

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
					<button type="submit" name="sync_type" value="force"
						class="button button-primary jszr-confirm"
						data-jszr-confirm="<?php esc_attr_e( 'Rewrite every job from Zoho Recruit? Any field edited here will be replaced.', 'jobs-sync-for-zoho-recruit' ); ?>"
						<?php disabled( ! $connection['connected'] ); ?>>
						<?php esc_html_e( 'Sync Now', 'jobs-sync-for-zoho-recruit' ); ?>
					</button>
					<button type="submit" name="sync_type" value="full" class="button" <?php disabled( ! $connection['connected'] ); ?>>
						<?php esc_html_e( 'Full Sync', 'jobs-sync-for-zoho-recruit' ); ?>
					</button>
					<button type="submit" name="sync_type" value="incremental" class="button" <?php disabled( ! $connection['connected'] ); ?>>
						<?php esc_html_e( 'Incremental Sync', 'jobs-sync-for-zoho-recruit' ); ?>
					</button>
				</p>

				<p class="description">
					<?php esc_html_e( 'Sync Now reads every job from Zoho and rewrites all of them, including fields edited in WordPress. Full Sync does the same but leaves those edits alone when the conflict setting says to. Incremental Sync only fetches what changed since the last successful run.', 'jobs-sync-for-zoho-recruit' ); ?>
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

	<h2><?php esc_html_e( 'Recent jobs', 'jobs-sync-for-zoho-recruit' ); ?></h2>

	<?php if ( empty( $recent ) ) : ?>
		<div class="jszr-empty">
			<p><strong><?php esc_html_e( 'No jobs yet', 'jobs-sync-for-zoho-recruit' ); ?></strong></p>
			<p>
				<?php esc_html_e( 'Once a sync has run, the job openings from Zoho Recruit appear here.', 'jobs-sync-for-zoho-recruit' ); ?>
			</p>
		</div>
	<?php else : ?>
		<div class="jszr-table-scroll">
			<table class="widefat striped jszr-recent-jobs">
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'Job title', 'jobs-sync-for-zoho-recruit' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Status', 'jobs-sync-for-zoho-recruit' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Location', 'jobs-sync-for-zoho-recruit' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Updated', 'jobs-sync-for-zoho-recruit' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $recent as $jszr_job ) : ?>
						<?php
						$jszr_status = Job::is_manual( $jszr_job->ID ) ? 'manual' : Job::get_status( $jszr_job->ID );
						$jszr_terms  = get_the_terms( $jszr_job->ID, 'zoho_job_location' );
						$jszr_where  = is_array( $jszr_terms ) ? implode( ', ', wp_list_pluck( $jszr_terms, 'name' ) ) : '';
						$jszr_edited = get_post_modified_time( 'U', true, $jszr_job );
						?>
						<tr>
							<td data-label="<?php esc_attr_e( 'Job title', 'jobs-sync-for-zoho-recruit' ); ?>">
								<a href="<?php echo esc_url( (string) get_edit_post_link( $jszr_job->ID ) ); ?>">
									<?php echo esc_html( get_the_title( $jszr_job->ID ) ); ?>
								</a>
							</td>
							<td data-label="<?php esc_attr_e( 'Status', 'jobs-sync-for-zoho-recruit' ); ?>">
								<span class="jszr-badge jszr-badge-<?php echo esc_attr( $jszr_status ); ?>">
									<?php
									echo esc_html(
										'manual' === $jszr_status
											? __( 'Manual', 'jobs-sync-for-zoho-recruit' )
											: Admin::status_label( $jszr_status )
									);
									?>
								</span>
							</td>
							<td data-label="<?php esc_attr_e( 'Location', 'jobs-sync-for-zoho-recruit' ); ?>">
								<?php echo '' !== $jszr_where ? esc_html( $jszr_where ) : '&mdash;'; ?>
							</td>
							<td data-label="<?php esc_attr_e( 'Updated', 'jobs-sync-for-zoho-recruit' ); ?>">
								<?php
								printf(
									/* translators: %s: human readable time difference. */
									esc_html__( '%s ago', 'jobs-sync-for-zoho-recruit' ),
									esc_html( human_time_diff( $jszr_edited, time() ) )
								);
								?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>

		<p>
			<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=' . Post_Type::POST_TYPE ) ); ?>">
				<?php esc_html_e( 'View all jobs', 'jobs-sync-for-zoho-recruit' ); ?>
			</a>
		</p>
	<?php endif; ?>
</div>
