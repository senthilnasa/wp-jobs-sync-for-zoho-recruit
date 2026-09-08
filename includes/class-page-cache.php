<?php
/**
 * Best-effort page cache invalidation.
 *
 * @package JobsSyncForZohoRecruit
 */

namespace JobsSyncForZohoRecruit;

defined( 'ABSPATH' ) || exit;

/**
 * Asks any page caching plugin that happens to be installed to drop its copy of
 * the job pages after a sync.
 *
 * Every call is guarded by a `function_exists()` or `class_exists()` check and
 * wrapped so a plugin that changes its API cannot take the sync down with it.
 * Nothing here is a dependency: with no caching plugin installed this class
 * does nothing at all.
 */
class Page_Cache {

	/**
	 * Hook registration.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'jszr_caches_invalidated', array( __CLASS__, 'purge' ) );
	}

	/**
	 * Purge whatever page cache is present.
	 *
	 * @return void
	 */
	public static function purge() {
		/**
		 * Filter whether the plugin asks page caching plugins to purge.
		 *
		 * Return false on sites that would rather manage cache invalidation
		 * themselves, or where a full purge is too expensive to do per sync.
		 *
		 * @param bool $purge Whether to purge.
		 */
		if ( ! apply_filters( 'jszr_purge_page_cache', true ) ) {
			return;
		}

		$purged = array();

		foreach ( self::purgers() as $name => $purger ) {
			try {
				if ( $purger() ) {
					$purged[] = $name;
				}
			} catch ( \Throwable $error ) {
				// A caching plugin that throws is the caching plugin's problem;
				// it must never break a sync.
				Logger::debug(
					'page_cache_purge_failed',
					'A page cache purge threw an error.',
					array(
						'plugin' => $name,
						'error'  => $error->getMessage(),
					)
				);
			}
		}

		if ( ! empty( $purged ) ) {
			Logger::debug(
				'page_cache_purged',
				'Asked page caching plugins to purge.',
				array( 'plugins' => $purged )
			);
		}
	}

	/**
	 * The purge callbacks for the caching plugins we know about.
	 *
	 * Each returns true when it actually did something.
	 *
	 * @return array<string,callable>
	 */
	private static function purgers() {
		$purgers = array(
			'wp-rocket'      => static function () {
				if ( ! function_exists( 'rocket_clean_domain' ) ) {
					return false;
				}

				rocket_clean_domain();

				return true;
			},
			'w3-total-cache' => static function () {
				if ( ! function_exists( 'w3tc_flush_posts' ) ) {
					return false;
				}

				w3tc_flush_posts();

				return true;
			},
			'wp-super-cache' => static function () {
				if ( ! function_exists( 'wp_cache_clear_cache' ) ) {
					return false;
				}

				wp_cache_clear_cache( get_current_blog_id() );

				return true;
			},
			'litespeed'      => static function () {
				if ( ! defined( 'LSCWP_V' ) && ! class_exists( '\LiteSpeed\Purge' ) ) {
					return false;
				}

				// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- LiteSpeed Cache's own documented purge hook; the point is to call theirs, not ours.
				do_action( 'litespeed_purge_post_tag', Post_Type::POST_TYPE );

				return true;
			},
			'cache-enabler'  => static function () {
				if ( ! class_exists( '\Cache_Enabler' ) || ! method_exists( '\Cache_Enabler', 'clear_complete_cache' ) ) {
					return false;
				}

				\Cache_Enabler::clear_complete_cache();

				return true;
			},
			'sg-optimizer'   => static function () {
				if ( ! function_exists( 'sg_cachepress_purge_cache' ) ) {
					return false;
				}

				sg_cachepress_purge_cache();

				return true;
			},
			'nginx-helper'   => static function () {
				if ( ! has_action( 'rt_nginx_helper_purge_all' ) ) {
					return false;
				}

				// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Nginx Helper's own documented purge hook.
				do_action( 'rt_nginx_helper_purge_all' );

				return true;
			},
			'kinsta'         => static function () {
				if ( ! has_action( 'kinsta_cache_purge_all' ) ) {
					return false;
				}

				// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Kinsta's own documented purge hook.
				do_action( 'kinsta_cache_purge_all' );

				return true;
			},
			'autoptimize'    => static function () {
				if ( ! class_exists( '\autoptimizeCache' ) || ! method_exists( '\autoptimizeCache', 'clearall' ) ) {
					return false;
				}

				\autoptimizeCache::clearall();

				return true;
			},
			'breeze'         => static function () {
				// Breeze ships with Cloudways, where it also fronts Varnish. Its
				// own flush clears both, so prefer it over poking Varnish here.
				if ( class_exists( '\Breeze_PurgeCache' ) && method_exists( '\Breeze_PurgeCache', 'breeze_cache_flush' ) ) {
					\Breeze_PurgeCache::breeze_cache_flush();

					return true;
				}

				if ( has_action( 'breeze_clear_all_cache' ) ) {
					// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Breeze's own documented purge hook.
					do_action( 'breeze_clear_all_cache' );

					return true;
				}

				return false;
			},
			'wp-fastest'     => static function () {
				if ( ! function_exists( 'wpfc_clear_all_cache' ) ) {
					return false;
				}

				wpfc_clear_all_cache( true );

				return true;
			},
			'hummingbird'    => static function () {
				if ( ! has_action( 'wphb_clear_page_cache' ) ) {
					return false;
				}

				// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Hummingbird's own documented purge hook.
				do_action( 'wphb_clear_page_cache' );

				return true;
			},
			'comet-cache'    => static function () {
				if ( ! class_exists( '\comet_cache' ) || ! method_exists( '\comet_cache', 'clear' ) ) {
					return false;
				}

				\comet_cache::clear();

				return true;
			},
			'wp-engine'      => static function () {
				if ( ! class_exists( '\WpeCommon' ) || ! method_exists( '\WpeCommon', 'purge_varnish_cache' ) ) {
					return false;
				}

				\WpeCommon::purge_varnish_cache();

				return true;
			},
			'pantheon'       => static function () {
				if ( ! function_exists( 'pantheon_clear_edge_all' ) ) {
					return false;
				}

				pantheon_clear_edge_all();

				return true;
			},
		);

		/**
		 * Filter the page cache purge callbacks.
		 *
		 * Add an entry for a caching plugin the list does not cover. Each
		 * callback takes no arguments and returns true when it purged.
		 *
		 * @param array $purgers Name => callable.
		 */
		return (array) apply_filters( 'jszr_page_cache_purgers', $purgers );
	}
}
