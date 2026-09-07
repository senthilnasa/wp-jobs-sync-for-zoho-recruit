<?php
/**
 * Privacy policy content.
 *
 * @package JobsSyncForZohoRecruit
 */

namespace JobsSyncForZohoRecruit;

defined( 'ABSPATH' ) || exit;

/**
 * Adds suggested privacy policy text.
 *
 * The plugin stores no personal data of its own: it reads job openings, never
 * candidates, and it sends nothing about site visitors to Zoho.
 */
class Privacy {

	/**
	 * Hook registration.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_init', array( __CLASS__, 'add_policy_content' ) );
	}

	/**
	 * Register suggested policy text.
	 *
	 * @return void
	 */
	public static function add_policy_content() {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}

		$content = '<h2>' . esc_html__( 'Job listings from Zoho Recruit', 'jobs-sync-for-zoho-recruit' ) . '</h2>';

		$content .= '<p>' . esc_html__( 'This site publishes job openings that are copied from a Zoho Recruit account. The copying is one way: job opening records are read from Zoho Recruit and stored in this site\'s database. No information about visitors to this site is sent to Zoho Recruit by this plugin.', 'jobs-sync-for-zoho-recruit' ) . '</p>';

		$content .= '<p>' . esc_html__( 'The plugin reads job opening records only. It does not read, store or transmit candidate records, applications or any other personal data held in Zoho Recruit.', 'jobs-sync-for-zoho-recruit' ) . '</p>';

		$content .= '<p>' . esc_html__( 'Stored data consists of the job fields an administrator has chosen to map (such as title, description, location, employment type and closing date), together with the Zoho record identifier and, optionally, the raw record for troubleshooting. This data is retained until the job is deleted in this site or the plugin is uninstalled with the "delete data" option enabled.', 'jobs-sync-for-zoho-recruit' ) . '</p>';

		$content .= '<p>' . esc_html__( 'If an "Apply" link is shown, following it takes the visitor to an external application form. That destination is operated by the employer or by Zoho, and its own privacy policy applies from that point onwards.', 'jobs-sync-for-zoho-recruit' ) . '</p>';

		$content .= '<p>' . esc_html__( 'An administrator can end the connection at any time from the plugin settings screen, which revokes the stored access at Zoho and deletes the tokens from this site.', 'jobs-sync-for-zoho-recruit' ) . '</p>';

		wp_add_privacy_policy_content(
			__( 'Jobs Sync for Zoho Recruit', 'jobs-sync-for-zoho-recruit' ),
			wp_kses_post( wpautop( $content, false ) )
		);
	}
}
