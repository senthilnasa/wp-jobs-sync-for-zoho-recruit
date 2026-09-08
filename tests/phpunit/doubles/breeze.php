<?php
/**
 * Test double for Breeze, the page cache that ships with Cloudways.
 *
 * The name has to match Breeze's own class for the purger to find it, so the
 * plugin prefix rule cannot apply here.
 *
 * @package JobsSyncForZohoRecruit
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound

if ( ! class_exists( 'Breeze_PurgeCache' ) ) {

	/**
	 * Records how often the plugin asked Breeze to flush.
	 */
	class Breeze_PurgeCache {

		/**
		 * How many times the flush was called.
		 *
		 * @var int
		 */
		public static $flushed = 0;

		/**
		 * Breeze's own flush entry point.
		 *
		 * @return void
		 */
		public static function breeze_cache_flush() {
			++self::$flushed;
		}
	}
}
