<?php
/**
 * Global helper functions.
 *
 * These are intentionally thin wrappers so themes and other plugins have a
 * stable, prefixed API without needing to know the internal class names.
 *
 * @package JobsSyncForZohoRecruit
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'jszr_get_setting' ) ) {
	/**
	 * Read a single plugin setting.
	 *
	 * @param string $key      Setting key.
	 * @param mixed  $fallback Value returned when the setting is not stored.
	 * @return mixed
	 */
	function jszr_get_setting( $key, $fallback = null ) {
		return \JobsSyncForZohoRecruit\Settings::get( $key, $fallback );
	}
}

if ( ! function_exists( 'jszr_capability' ) ) {
	/**
	 * Capability required to administer the plugin.
	 *
	 * @return string
	 */
	function jszr_capability() {
		return \JobsSyncForZohoRecruit\Plugin::capability();
	}
}

if ( ! function_exists( 'jszr_verify_admin_request' ) ) {
	/**
	 * Guard an admin-post request handler.
	 *
	 * Every one of the plugin's `admin_post_jszr_*` handlers calls this before it
	 * reads a single request value: it checks the plugin capability and then the
	 * nonce, and stops the request outright when either fails. It is a global
	 * function rather than a class method so static analysis can see the nonce
	 * check at the call site.
	 *
	 * @param string $action Nonce action name.
	 * @return void
	 */
	function jszr_verify_admin_request( $action ) {
		if ( ! current_user_can( jszr_capability() ) ) {
			wp_die(
				esc_html__( 'You do not have permission to perform this action.', 'jobs-sync-for-zoho-recruit' ),
				403
			);
		}

		check_admin_referer( $action );
	}
}

if ( ! function_exists( 'jszr_admin_url' ) ) {
	/**
	 * Build the URL of one of the plugin's admin screens.
	 *
	 * The plugin's screens live under the job post type menu, so every site has
	 * a single place for everything Zoho Recruit related and editors keep access
	 * to the job list even without the plugin capability.
	 *
	 * @param string $page Page slug, e.g. "jszr-settings". Empty for the job list.
	 * @param array  $args Extra query arguments.
	 * @return string
	 */
	function jszr_admin_url( $page = '', array $args = array() ) {
		if ( '' !== $page ) {
			$args = array_merge( array( 'page' => $page ), $args );
		}

		return add_query_arg(
			$args,
			admin_url( 'edit.php?post_type=' . \JobsSyncForZohoRecruit\Post_Type::POST_TYPE )
		);
	}
}

if ( ! function_exists( 'jszr_is_connected' ) ) {
	/**
	 * Whether a usable Zoho refresh token is stored.
	 *
	 * @return bool
	 */
	function jszr_is_connected() {
		return \JobsSyncForZohoRecruit\plugin()->auth()->is_connected();
	}
}

if ( ! function_exists( 'jszr_get_job_meta' ) ) {
	/**
	 * Read one of the plugin's job meta values.
	 *
	 * @param int    $post_id Job post ID.
	 * @param string $key     Meta key with or without the plugin prefix.
	 * @return mixed
	 */
	function jszr_get_job_meta( $post_id, $key ) {
		return \JobsSyncForZohoRecruit\Job::get_meta( (int) $post_id, $key );
	}
}

if ( ! function_exists( 'jszr_get_apply_url' ) ) {
	/**
	 * Resolve the application URL for a job.
	 *
	 * @param int $post_id Job post ID.
	 * @return string Empty string when no URL can be resolved.
	 */
	function jszr_get_apply_url( $post_id ) {
		return \JobsSyncForZohoRecruit\Job::get_apply_url( (int) $post_id );
	}
}

if ( ! function_exists( 'jszr_is_job_active' ) ) {
	/**
	 * Whether a job is currently open for applications.
	 *
	 * @param int $post_id Job post ID.
	 * @return bool
	 */
	function jszr_is_job_active( $post_id ) {
		return \JobsSyncForZohoRecruit\Job::is_active( (int) $post_id );
	}
}

if ( ! function_exists( 'jszr_locate_template' ) ) {
	/**
	 * Locate a plugin template, honouring theme overrides.
	 *
	 * @param string $template Template file name, e.g. "card.php".
	 * @return string Absolute path.
	 */
	function jszr_locate_template( $template ) {
		return \JobsSyncForZohoRecruit\Templates::locate( $template );
	}
}

if ( ! function_exists( 'jszr_get_template' ) ) {
	/**
	 * Render a plugin template with the given variables.
	 *
	 * @param string $template Template file name.
	 * @param array  $args     Variables extracted into template scope.
	 * @return void
	 */
	function jszr_get_template( $template, array $args = array() ) {
		\JobsSyncForZohoRecruit\Templates::render( $template, $args );
	}
}
