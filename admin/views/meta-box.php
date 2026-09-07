<?php
/**
 * Zoho details metabox on the job edit screen.
 *
 * @package JobsSyncForZohoRecruit
 *
 * @var \WP_Post $post    Post being edited.
 * @var string   $zoho_id Zoho record ID.
 * @var array    $mapped  Flattened mapped values.
 */

namespace JobsSyncForZohoRecruit;

defined( 'ABSPATH' ) || exit;

$jszr_format = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
?>
<div class="jszr-meta-box">
	<?php if ( '' === $zoho_id ) : ?>
		<p><?php esc_html_e( 'This job was created in WordPress. It is not linked to a Zoho Recruit record and will never be changed or deactivated by a sync.', 'jobs-sync-for-zoho-recruit' ); ?></p>
	<?php else : ?>
		<p class="jszr-synced-badge">
			<span class="dashicons dashicons-cloud"></span>
			<?php esc_html_e( 'Synced from Zoho Recruit', 'jobs-sync-for-zoho-recruit' ); ?>
		</p>

		<table class="jszr-kv">
			<tbody>
				<tr>
					<th scope="row"><?php esc_html_e( 'Zoho ID', 'jobs-sync-for-zoho-recruit' ); ?></th>
					<td><code><?php echo esc_html( $zoho_id ); ?></code></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Job status', 'jobs-sync-for-zoho-recruit' ); ?></th>
					<td>
						<span class="jszr-badge jszr-badge-<?php echo esc_attr( Job::get_status( $post->ID ) ); ?>">
							<?php echo esc_html( Admin::status_label( Job::get_status( $post->ID ) ) ); ?>
						</span>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Zoho status', 'jobs-sync-for-zoho-recruit' ); ?></th>
					<td><?php echo esc_html( (string) get_post_meta( $post->ID, '_zoho_recruit_status', true ) ); ?></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Modified in Zoho', 'jobs-sync-for-zoho-recruit' ); ?></th>
					<td>
						<?php
						$jszr_modified = (string) get_post_meta( $post->ID, '_zoho_recruit_modified_time', true );
						$jszr_stamp    = '' !== $jszr_modified ? strtotime( $jszr_modified ) : false;

						echo esc_html( $jszr_stamp ? wp_date( $jszr_format, $jszr_stamp ) : '—' );
						?>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Last synced', 'jobs-sync-for-zoho-recruit' ); ?></th>
					<td>
						<?php
						$jszr_synced = (string) get_post_meta( $post->ID, Job::META_LAST_SYNCED, true );
						$jszr_stamp  = '' !== $jszr_synced ? strtotime( $jszr_synced . ' UTC' ) : false;

						echo esc_html( $jszr_stamp ? wp_date( $jszr_format, $jszr_stamp ) : '—' );
						?>
					</td>
				</tr>
			</tbody>
		</table>

		<?php if ( current_user_can( Plugin::capability() ) ) : ?>
			<p>
				<a class="button button-secondary"
					href="<?php echo esc_url( wp_nonce_url( add_query_arg( array( 'action' => 'jszr_resync_job', 'post' => (int) $post->ID ), admin_url( 'admin-post.php' ) ), 'jszr_resync_job' ) ); ?>">
					<?php esc_html_e( 'Resync from Zoho', 'jobs-sync-for-zoho-recruit' ); ?>
				</a>
			</p>
		<?php endif; ?>

		<?php $jszr_zoho_link = Job::get_zoho_link( $post->ID ); ?>

		<?php if ( '' !== $jszr_zoho_link ) : ?>
			<p>
				<a href="<?php echo esc_url( $jszr_zoho_link ); ?>" target="_blank" rel="noopener">
					<?php esc_html_e( 'View in Zoho Recruit', 'jobs-sync-for-zoho-recruit' ); ?>
					<span class="screen-reader-text"><?php esc_html_e( '(opens in a new tab)', 'jobs-sync-for-zoho-recruit' ); ?></span>
				</a>
			</p>
		<?php endif; ?>

		<?php if ( ! empty( $mapped ) ) : ?>
			<details class="jszr-mapped-values">
				<summary><?php esc_html_e( 'Mapped Zoho values', 'jobs-sync-for-zoho-recruit' ); ?></summary>
				<table class="jszr-kv">
					<tbody>
						<?php foreach ( $mapped as $jszr_target => $jszr_value ) : ?>
							<tr>
								<th scope="row"><code><?php echo esc_html( (string) $jszr_target ); ?></code></th>
								<td>
									<?php
									$jszr_display = is_array( $jszr_value )
										? implode( ', ', array_map( 'strval', $jszr_value ) )
										: (string) $jszr_value;

									echo esc_html( wp_html_excerpt( wp_strip_all_tags( $jszr_display ), 200, '…' ) );
									?>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</details>
		<?php endif; ?>

		<p class="description">
			<?php esc_html_e( 'Mapped fields are refreshed on every sync. The Zoho ID is not editable.', 'jobs-sync-for-zoho-recruit' ); ?>
		</p>
	<?php endif; ?>
</div>
