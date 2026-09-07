<?php
/**
 * Sync logs screen.
 *
 * @package JobsSyncForZohoRecruit
 *
 * @var array $runs     Sync runs for this page.
 * @var int   $total    Total run count.
 * @var int   $paged    Current page.
 * @var int   $per_page Rows per page.
 * @var int   $run_id   Selected run ID, or 0.
 * @var array $entries  Log entries to show.
 */

namespace JobsSyncForZohoRecruit;

defined( 'ABSPATH' ) || exit;

$jszr_format = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
$jszr_pages  = (int) ceil( $total / max( 1, $per_page ) );
?>
<div class="wrap jszr-wrap">
	<h1><?php esc_html_e( 'Sync Logs', 'jobs-sync-for-zoho-recruit' ); ?></h1>

	<table class="widefat striped">
		<thead>
			<tr>
				<th scope="col"><?php esc_html_e( 'Started', 'jobs-sync-for-zoho-recruit' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Type', 'jobs-sync-for-zoho-recruit' ); ?></th>
				<th scope="col"><?php esc_html_e( 'State', 'jobs-sync-for-zoho-recruit' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Processed', 'jobs-sync-for-zoho-recruit' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Added', 'jobs-sync-for-zoho-recruit' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Updated', 'jobs-sync-for-zoho-recruit' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Expired', 'jobs-sync-for-zoho-recruit' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Deactivated', 'jobs-sync-for-zoho-recruit' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Errors', 'jobs-sync-for-zoho-recruit' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Details', 'jobs-sync-for-zoho-recruit' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php if ( empty( $runs ) ) : ?>
				<tr>
					<td colspan="10"><?php esc_html_e( 'No syncs have run yet.', 'jobs-sync-for-zoho-recruit' ); ?></td>
				</tr>
			<?php else : ?>
				<?php foreach ( $runs as $jszr_run ) : ?>
					<?php $jszr_started = strtotime( $jszr_run->started_at . ' UTC' ); ?>
					<tr>
						<td><?php echo esc_html( $jszr_started ? wp_date( $jszr_format, $jszr_started ) : $jszr_run->started_at ); ?></td>
						<td><?php echo esc_html( $jszr_run->type ); ?><?php echo $jszr_run->dry_run ? ' <em>(' . esc_html__( 'dry run', 'jobs-sync-for-zoho-recruit' ) . ')</em>' : ''; ?></td>
						<td>
							<span class="jszr-badge jszr-badge-<?php echo esc_attr( $jszr_run->state ); ?>"><?php echo esc_html( $jszr_run->state ); ?></span>
							<?php if ( '' !== (string) $jszr_run->message ) : ?>
								<p class="description"><?php echo esc_html( $jszr_run->message ); ?></p>
							<?php endif; ?>
						</td>
						<td><?php echo esc_html( number_format_i18n( (int) $jszr_run->processed ) ); ?></td>
						<td><?php echo esc_html( number_format_i18n( (int) $jszr_run->created_count ) ); ?></td>
						<td><?php echo esc_html( number_format_i18n( (int) $jszr_run->updated_count ) ); ?></td>
						<td><?php echo esc_html( number_format_i18n( (int) $jszr_run->expired_count ) ); ?></td>
						<td><?php echo esc_html( number_format_i18n( (int) $jszr_run->deactivated_count ) ); ?></td>
						<td><?php echo esc_html( number_format_i18n( (int) $jszr_run->error_count ) ); ?></td>
						<td>
							<a href="<?php echo esc_url( jszr_admin_url( Admin::LOGS_SLUG, array( 'run_id' => (int) $jszr_run->id ) ) ); ?>">
								<?php esc_html_e( 'View entries', 'jobs-sync-for-zoho-recruit' ); ?>
							</a>
						</td>
					</tr>
				<?php endforeach; ?>
			<?php endif; ?>
		</tbody>
	</table>

	<?php if ( $jszr_pages > 1 ) : ?>
		<div class="tablenav">
			<div class="tablenav-pages">
				<?php
				echo wp_kses_post(
					paginate_links(
						array(
							'base'      => add_query_arg( 'paged', '%#%' ),
							'format'    => '',
							'current'   => $paged,
							'total'     => $jszr_pages,
							'prev_text' => '&laquo;',
							'next_text' => '&raquo;',
						)
					)
				);
				?>
			</div>
		</div>
	<?php endif; ?>

	<h2>
		<?php if ( $run_id > 0 ) : ?>
			<?php
			printf(
				/* translators: %d: sync run ID. */
				esc_html__( 'Entries for sync #%d', 'jobs-sync-for-zoho-recruit' ),
				(int) $run_id
			);
			?>
		<?php else : ?>
			<?php esc_html_e( 'Recent log entries', 'jobs-sync-for-zoho-recruit' ); ?>
		<?php endif; ?>
	</h2>

	<?php if ( $run_id > 0 ) : ?>
		<p><a href="<?php echo esc_url( jszr_admin_url( Admin::LOGS_SLUG ) ); ?>"><?php esc_html_e( '&larr; All entries', 'jobs-sync-for-zoho-recruit' ); ?></a></p>
	<?php endif; ?>

	<table class="widefat striped">
		<thead>
			<tr>
				<th scope="col"><?php esc_html_e( 'Time', 'jobs-sync-for-zoho-recruit' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Level', 'jobs-sync-for-zoho-recruit' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Event', 'jobs-sync-for-zoho-recruit' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Message', 'jobs-sync-for-zoho-recruit' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Context', 'jobs-sync-for-zoho-recruit' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php if ( empty( $entries ) ) : ?>
				<tr>
					<td colspan="5"><?php esc_html_e( 'Nothing logged yet.', 'jobs-sync-for-zoho-recruit' ); ?></td>
				</tr>
			<?php else : ?>
				<?php foreach ( $entries as $jszr_entry ) : ?>
					<?php $jszr_time = strtotime( $jszr_entry->created_at . ' UTC' ); ?>
					<tr>
						<td><?php echo esc_html( $jszr_time ? wp_date( $jszr_format, $jszr_time ) : $jszr_entry->created_at ); ?></td>
						<td><span class="jszr-badge jszr-badge-<?php echo esc_attr( $jszr_entry->level ); ?>"><?php echo esc_html( $jszr_entry->level ); ?></span></td>
						<td><code><?php echo esc_html( $jszr_entry->event ); ?></code></td>
						<td><?php echo esc_html( $jszr_entry->message ); ?></td>
						<td>
							<?php if ( ! empty( $jszr_entry->context ) ) : ?>
								<details>
									<summary><?php esc_html_e( 'Show', 'jobs-sync-for-zoho-recruit' ); ?></summary>
									<pre class="jszr-context"><?php echo esc_html( (string) $jszr_entry->context ); ?></pre>
								</details>
							<?php else : ?>
								&mdash;
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			<?php endif; ?>
		</tbody>
	</table>

	<h2><?php esc_html_e( 'Maintenance', 'jobs-sync-for-zoho-recruit' ); ?></h2>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<?php wp_nonce_field( 'jszr_clear_logs' ); ?>
		<input type="hidden" name="action" value="jszr_clear_logs" />
		<button type="submit" class="button button-link-delete">
			<?php esc_html_e( 'Clear all sync logs', 'jobs-sync-for-zoho-recruit' ); ?>
		</button>
	</form>
</div>
