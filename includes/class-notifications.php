<?php
/**
 * Administrator notifications.
 *
 * @package JobsSyncForZohoRecruit
 */

namespace JobsSyncForZohoRecruit;

defined( 'ABSPATH' ) || exit;

/**
 * Sends the small number of emails the plugin is allowed to send, and throttles
 * them so a broken connection cannot flood an inbox.
 */
class Notifications {

	/**
	 * Minimum interval between two notifications of the same kind.
	 */
	const THROTTLE = 6 * HOUR_IN_SECONDS;

	/**
	 * Where notifications go.
	 *
	 * @return string
	 */
	public static function recipient() {
		$email = (string) Settings::get( 'notify_email', '' );

		if ( '' === $email || ! is_email( $email ) ) {
			$email = (string) get_option( 'admin_email' );
		}

		/**
		 * Filter the notification recipient.
		 *
		 * @param string $email Recipient address.
		 */
		return (string) apply_filters( 'jszr_notification_recipient', $email );
	}

	/**
	 * Whether notifications are switched on.
	 *
	 * @return bool
	 */
	public static function enabled() {
		return (bool) Settings::get( 'notify_on_failure', true ) && is_email( self::recipient() );
	}

	/**
	 * Notify that a sync failed.
	 *
	 * @param string $message Failure message.
	 * @param array  $stats   Run statistics.
	 * @return void
	 */
	public static function send_failure_alert( $message, array $stats = array() ) {
		if ( ! self::enabled() || ! self::should_send( 'sync_failure' ) ) {
			return;
		}

		$lines = array(
			sprintf(
				/* translators: %s: site name. */
				__( 'A Zoho Recruit job sync failed on %s.', 'jobs-sync-for-zoho-recruit' ),
				wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES )
			),
			'',
			Logger::scrub_string( (string) $message ),
			'',
		);

		if ( ! empty( $stats ) ) {
			$lines[] = sprintf(
				/* translators: 1: processed count, 2: created count, 3: updated count, 4: error count. */
				__( 'Processed: %1$d, created: %2$d, updated: %3$d, errors: %4$d', 'jobs-sync-for-zoho-recruit' ),
				(int) ( $stats['processed'] ?? 0 ),
				(int) ( $stats['created'] ?? 0 ),
				(int) ( $stats['updated'] ?? 0 ),
				(int) ( $stats['errors'] ?? 0 )
			);
			$lines[] = '';
		}

		$lines[] = __( 'Sync logs:', 'jobs-sync-for-zoho-recruit' );
		$lines[] = jszr_admin_url( 'jszr-logs' );

		self::send(
			__( 'Zoho Recruit job sync failed', 'jobs-sync-for-zoho-recruit' ),
			implode( "\n", $lines ),
			'sync_failure'
		);
	}

	/**
	 * Notify that the Zoho connection is failing.
	 *
	 * @param int $failures Consecutive failure count.
	 * @return void
	 */
	public static function send_connection_alert( $failures ) {
		if ( ! self::enabled() || ! self::should_send( 'connection' ) ) {
			return;
		}

		$body = implode(
			"\n",
			array(
				sprintf(
					/* translators: %d: number of consecutive failures. */
					__( 'The connection to Zoho Recruit has failed %d times in a row, so automatic syncing has been paused.', 'jobs-sync-for-zoho-recruit' ),
					(int) $failures
				),
				'',
				__( 'Reconnect Zoho Recruit to resume syncing:', 'jobs-sync-for-zoho-recruit' ),
				jszr_admin_url( 'jszr-settings', array( 'tab' => 'connection' ) ),
			)
		);

		self::send(
			__( 'Zoho Recruit connection needs attention', 'jobs-sync-for-zoho-recruit' ),
			$body,
			'connection'
		);
	}

	/**
	 * Notify that the deactivation safety threshold blocked a sync.
	 *
	 * @param string $message Explanation.
	 * @return void
	 */
	public static function send_threshold_alert( $message ) {
		if ( ! self::enabled() || ! self::should_send( 'threshold' ) ) {
			return;
		}

		$body = implode(
			"\n",
			array(
				Logger::scrub_string( (string) $message ),
				'',
				__( 'No jobs were deactivated. Review the sync log before running another full sync:', 'jobs-sync-for-zoho-recruit' ),
				jszr_admin_url( 'jszr-logs' ),
			)
		);

		self::send(
			__( 'Zoho Recruit sync stopped before deactivating jobs', 'jobs-sync-for-zoho-recruit' ),
			$body,
			'threshold'
		);
	}

	/**
	 * Send a mail and record the time.
	 *
	 * @param string $subject Subject line.
	 * @param string $body    Message body.
	 * @param string $kind    Throttle bucket.
	 * @return void
	 */
	private static function send( $subject, $body, $kind ) {
		$recipient = self::recipient();

		if ( ! is_email( $recipient ) ) {
			return;
		}

		$subject = sprintf(
			'[%1$s] %2$s',
			wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES ),
			$subject
		);

		wp_mail( $recipient, $subject, $body );

		set_transient( 'jszr_notified_' . $kind, time(), self::THROTTLE );
	}

	/**
	 * Whether this kind of notification is outside its throttle window.
	 *
	 * @param string $kind Throttle bucket.
	 * @return bool
	 */
	private static function should_send( $kind ) {
		return ! get_transient( 'jszr_notified_' . $kind );
	}
}
