<?php
/**
 * Zoho details metabox on the job edit screen.
 *
 * Grouped into labelled sections rather than one long table: where the job came
 * from, what it is, and what you can do to it are three different questions,
 * and an editor scanning this box is usually asking only one of them.
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

/**
 * Format a stored timestamp for display.
 *
 * @param string $value Stored value.
 * @param bool   $utc   Whether the stored value is UTC.
 * @return string
 */
$jszr_when = static function ( $value, $utc = false ) use ( $jszr_format ) {
	$value = (string) $value;

	if ( '' === $value ) {
		return '—';
	}

	$stamp = strtotime( $utc ? $value . ' UTC' : $value );

	return $stamp ? wp_date( $jszr_format, $stamp ) : $value;
};

$jszr_code   = (string) get_post_meta( $post->ID, '_jszr_job_code', true );
$jszr_synced = (string) get_post_meta( $post->ID, Job::META_LAST_SYNCED, true );
$jszr_status = Job::get_status( $post->ID );
?>
<div class="jszr-meta-box">
	<?php if ( '' === $zoho_id ) : ?>
		<div class="jszr-section">
			<p class="jszr-section__title"><?php esc_html_e( 'Source', 'jobs-sync-for-zoho-recruit' ); ?></p>
			<p>
				<span class="jszr-badge jszr-badge-manual"><?php esc_html_e( 'Added here', 'jobs-sync-for-zoho-recruit' ); ?></span>
			</p>
			<p class="description">
				<?php esc_html_e( 'This job was created in WordPress. It is not linked to a Zoho Recruit record and will never be changed or deactivated by a sync.', 'jobs-sync-for-zoho-recruit' ); ?>
			</p>
		</div>
	<?php else : ?>
		<div class="jszr-section">
			<p class="jszr-section__title"><?php esc_html_e( 'Synchronization', 'jobs-sync-for-zoho-recruit' ); ?></p>

			<p class="jszr-synced-badge">
				<span class="dashicons dashicons-cloud"></span>
				<?php if ( '' !== $jszr_synced ) : ?>
					<?php esc_html_e( 'Synced from Zoho Recruit', 'jobs-sync-for-zoho-recruit' ); ?>
				<?php else : ?>
					<?php esc_html_e( 'Linked to Zoho Recruit, not yet synced', 'jobs-sync-for-zoho-recruit' ); ?>
				<?php endif; ?>
			</p>

			<table class="jszr-kv">
				<tbody>
					<tr>
						<th scope="row"><?php esc_html_e( 'Source', 'jobs-sync-for-zoho-recruit' ); ?></th>
						<td><?php esc_html_e( 'Zoho Recruit', 'jobs-sync-for-zoho-recruit' ); ?></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Zoho job ID', 'jobs-sync-for-zoho-recruit' ); ?></th>
						<td><code><?php echo esc_html( $zoho_id ); ?></code></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Last synced', 'jobs-sync-for-zoho-recruit' ); ?></th>
						<td><?php echo esc_html( $jszr_when( $jszr_synced, true ) ); ?></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Modified in Zoho', 'jobs-sync-for-zoho-recruit' ); ?></th>
						<td><?php echo esc_html( $jszr_when( get_post_meta( $post->ID, '_zoho_recruit_modified_time', true ) ) ); ?></td>
					</tr>
				</tbody>
			</table>
		</div>

		<div class="jszr-section">
			<p class="jszr-section__title"><?php esc_html_e( 'Basic information', 'jobs-sync-for-zoho-recruit' ); ?></p>

			<table class="jszr-kv">
				<tbody>
					<?php if ( '' !== $jszr_code ) : ?>
						<tr>
							<th scope="row"><?php esc_html_e( 'Job code', 'jobs-sync-for-zoho-recruit' ); ?></th>
							<td><code><?php echo esc_html( $jszr_code ); ?></code></td>
						</tr>
					<?php endif; ?>
					<tr>
						<th scope="row"><?php esc_html_e( 'Job status', 'jobs-sync-for-zoho-recruit' ); ?></th>
						<td>
							<span class="jszr-badge jszr-badge-<?php echo esc_attr( $jszr_status ); ?>">
								<?php echo esc_html( Admin::status_label( $jszr_status ) ); ?>
							</span>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Zoho status', 'jobs-sync-for-zoho-recruit' ); ?></th>
						<td>
							<?php
							$jszr_zoho_status = (string) get_post_meta( $post->ID, '_zoho_recruit_status', true );

							echo '' !== $jszr_zoho_status ? esc_html( $jszr_zoho_status ) : '&mdash;';
							?>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Closing date', 'jobs-sync-for-zoho-recruit' ); ?></th>
						<td><?php echo esc_html( $jszr_when( get_post_meta( $post->ID, '_zoho_recruit_closing_date', true ) ) ); ?></td>
					</tr>
				</tbody>
			</table>
		</div>

		<?php $jszr_zoho_link = Job::get_zoho_link( $post->ID ); ?>

		<?php if ( current_user_can( Plugin::capability() ) || '' !== $jszr_zoho_link ) : ?>
			<div class="jszr-section">
				<p class="jszr-section__title"><?php esc_html_e( 'Actions', 'jobs-sync-for-zoho-recruit' ); ?></p>

				<?php if ( current_user_can( Plugin::capability() ) ) : ?>
					<p>
						<a class="button button-secondary"
							href="<?php echo esc_url( Admin::job_action_url( 'jszr_resync_job', (int) $post->ID ) ); ?>">
							<?php esc_html_e( 'Resync from Zoho', 'jobs-sync-for-zoho-recruit' ); ?>
						</a>
					</p>

					<?php if ( 'preserve_manual' === Settings::get( 'conflict_mode', 'mapped_only' ) ) : ?>
						<p>
							<a class="button button-link-delete jszr-confirm"
								href="<?php echo esc_url( Admin::job_action_url( 'jszr_reset_job', (int) $post->ID ) ); ?>"
								data-jszr-confirm="<?php esc_attr_e( 'Discard the changes made to this job in WordPress and replace them with the values from Zoho Recruit?', 'jobs-sync-for-zoho-recruit' ); ?>">
								<?php esc_html_e( 'Reset to Zoho values', 'jobs-sync-for-zoho-recruit' ); ?>
							</a>
						</p>
						<p class="description">
							<?php esc_html_e( 'A resync keeps fields you edited here. A reset replaces them.', 'jobs-sync-for-zoho-recruit' ); ?>
						</p>
					<?php endif; ?>
				<?php endif; ?>

				<?php if ( '' !== $jszr_zoho_link ) : ?>
					<p>
						<a href="<?php echo esc_url( $jszr_zoho_link ); ?>" target="_blank" rel="noopener">
							<?php esc_html_e( 'View in Zoho Recruit', 'jobs-sync-for-zoho-recruit' ); ?>
							<span class="screen-reader-text"><?php esc_html_e( '(opens in a new tab)', 'jobs-sync-for-zoho-recruit' ); ?></span>
						</a>
					</p>
				<?php endif; ?>
			</div>
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
