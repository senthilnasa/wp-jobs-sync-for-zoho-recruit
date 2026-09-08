<?php
/**
 * Keyword search and page cache purging.
 *
 * @package JobsSyncForZohoRecruit
 */

use JobsSyncForZohoRecruit\Job;
use JobsSyncForZohoRecruit\Page_Cache;
use JobsSyncForZohoRecruit\Post_Type;
use JobsSyncForZohoRecruit\REST_API;

require_once __DIR__ . '/doubles/breeze.php';

/**
 * Covers the two faults that made a live site show an empty jobs archive: a
 * page cache nobody purged, and a search plugin that swallowed the query.
 */
class JSZR_Search_And_Cache_Test extends WP_UnitTestCase {

	/**
	 * Create a job.
	 *
	 * @param string $title Job title.
	 * @param string $code  Job code.
	 * @param string $body  Post content.
	 * @return int
	 */
	private function make_job( $title, $code, $body = '' ) {
		$post_id = self::factory()->post->create(
			array(
				'post_type'    => Post_Type::POST_TYPE,
				'post_status'  => 'publish',
				'post_title'   => $title,
				'post_content' => $body,
			)
		);

		update_post_meta( $post_id, Job::META_STATUS, 'active' );
		update_post_meta( $post_id, '_jszr_job_code', $code );

		return $post_id;
	}

	/**
	 * Run the listing query the way the shortcode and REST endpoint both do.
	 *
	 * @param array $params Listing parameters.
	 * @return int[] Matching post IDs.
	 */
	private function search( array $params ) {
		$query = new WP_Query( REST_API::build_query_args( $params ) );

		return wp_list_pluck( $query->posts, 'ID' );
	}

	/**
	 * The search term must not travel as `s`.
	 *
	 * This is the whole defence. Search plugins take over any query that sets
	 * `s`, and one that has not indexed this post type answers with nothing,
	 * which is exactly how a live site ended up with an empty jobs archive.
	 */
	public function test_search_does_not_set_the_s_query_var() {
		$args = REST_API::build_query_args( array( 'search' => 'writer' ) );

		$this->assertArrayNotHasKey( 's', $args );
		$this->assertSame( 'writer', $args['jszr_search'] );
	}

	/**
	 * A title match is the ordinary case.
	 */
	public function test_search_matches_the_title() {
		$writer = $this->make_job( 'Content Writer', 'CW-003' );
		$this->make_job( 'Groundskeeper', 'GK-001' );

		$this->assertSame( array( $writer ), $this->search( array( 'search' => 'writer' ) ) );
	}

	/**
	 * Candidates quote the job code, so it has to be searchable.
	 */
	public function test_search_matches_the_job_code() {
		$writer = $this->make_job( 'Content Writer', 'CW-003' );
		$this->make_job( 'Groundskeeper', 'GK-001' );

		$this->assertSame( array( $writer ), $this->search( array( 'search' => 'CW-003' ) ) );
	}

	/**
	 * The body counts too, so a skill named only in the description is findable.
	 */
	public function test_search_matches_the_body() {
		$job = $this->make_job( 'Content Writer', 'CW-003', 'Experience with InDesign preferred.' );

		$this->assertSame( array( $job ), $this->search( array( 'search' => 'indesign' ) ) );
	}

	/**
	 * Two words mean both words, which is what someone typing them expects.
	 */
	public function test_every_word_has_to_match() {
		$this->make_job( 'Content Writer', 'CW-003' );
		$this->make_job( 'Senior Engineer', 'SE-001' );

		$this->assertSame( array(), $this->search( array( 'search' => 'content engineer' ) ) );
	}

	/**
	 * A term matching nothing returns nothing rather than everything.
	 */
	public function test_an_unmatched_term_returns_nothing() {
		$this->make_job( 'Content Writer', 'CW-003' );

		$this->assertSame( array(), $this->search( array( 'search' => 'zzzznope' ) ) );
	}

	/**
	 * Search still works next to a plugin that hijacks core search.
	 *
	 * SearchWP, Search & Filter and Relevanssi all replace the search clause
	 * for queries that set `s`. This reproduces that behaviour: the listing has
	 * to keep returning its own results.
	 */
	public function test_search_survives_a_search_plugin_taking_over() {
		$writer = $this->make_job( 'Content Writer', 'CW-003' );

		$hijack = static function ( $search, $query ) {
			// A search plugin that has not indexed this post type: it claims
			// the query and answers with nothing.
			if ( '' !== (string) $query->get( 's' ) ) {
				return ' AND 1=0 ';
			}

			return $search;
		};

		add_filter( 'posts_search', $hijack, 10, 2 );

		$found = $this->search( array( 'search' => 'writer' ) );

		remove_filter( 'posts_search', $hijack, 10 );

		$this->assertSame( array( $writer ), $found );
	}

	/**
	 * Inactive jobs stay out of a search, the same as they stay out of a listing.
	 */
	public function test_search_still_respects_the_active_filter() {
		$job = $this->make_job( 'Content Writer', 'CW-003' );
		update_post_meta( $job, Job::META_STATUS, 'expired' );

		$this->assertSame( array(), $this->search( array( 'search' => 'writer' ) ) );
	}

	/**
	 * Breeze is what the live site runs, and it was the cache nobody purged.
	 */
	public function test_breeze_is_purged() {
		Breeze_PurgeCache::$flushed = 0;

		Page_Cache::purge();

		$this->assertSame( 1, Breeze_PurgeCache::$flushed );
	}

	/**
	 * A site can still opt out of the plugin touching its cache.
	 */
	public function test_purging_can_be_switched_off() {
		Breeze_PurgeCache::$flushed = 0;

		add_filter( 'jszr_purge_page_cache', '__return_false' );
		Page_Cache::purge();
		remove_filter( 'jszr_purge_page_cache', '__return_false' );

		$this->assertSame( 0, Breeze_PurgeCache::$flushed );
	}
}
