<?php
/**
 * Deleting synchronized data so a sync can rebuild it.
 *
 * @package JobsSyncForZohoRecruit
 */

use JobsSyncForZohoRecruit\Job;
use JobsSyncForZohoRecruit\Post_Type;

/**
 * The purge exists because un-mapping a field does not remove the meta it
 * already wrote. It must take every synced job and nothing else.
 */
class JSZR_Purge_Test extends WP_UnitTestCase {

	/**
	 * Create a job.
	 *
	 * @param string $zoho_id Zoho record ID, or empty for a manual job.
	 * @param string $title   Post title.
	 * @return int
	 */
	private function make_job( $zoho_id, $title ) {
		$post_id = self::factory()->post->create(
			array(
				'post_type'   => Post_Type::POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => $title,
			)
		);

		if ( '' !== $zoho_id ) {
			update_post_meta( $post_id, Job::META_ZOHO_ID, $zoho_id );
		}

		update_post_meta( $post_id, Job::META_STATUS, 'active' );

		return $post_id;
	}

	/**
	 * Synced jobs go; manual ones and other post types stay.
	 */
	public function test_only_synced_jobs_are_deleted() {
		$synced = array(
			$this->make_job( '801080000001099005', 'Assistant Manager HR' ),
			$this->make_job( '801080000001099006', 'Content Writer' ),
		);

		$manual = $this->make_job( '', 'Added By Hand' );
		$page   = self::factory()->post->create( array( 'post_type' => 'page' ) );

		$this->assertSame( 2, Job::purge_synced( true ), 'dry run counts the synced jobs' );

		$deleted = Job::purge_synced();

		$this->assertSame( 2, $deleted );

		foreach ( $synced as $id ) {
			$this->assertNull( get_post( $id ) );
		}

		$this->assertNotNull( get_post( $manual ), 'a manual job must survive' );
		$this->assertNotNull( get_post( $page ), 'other post types must survive' );
	}

	/**
	 * An empty Zoho ID counts as manual, not as synced.
	 */
	public function test_an_empty_zoho_id_is_treated_as_manual() {
		$post_id = $this->make_job( '', 'No Record' );
		update_post_meta( $post_id, Job::META_ZOHO_ID, '' );

		$this->assertSame( 0, Job::purge_synced() );
		$this->assertNotNull( get_post( $post_id ) );
	}

	/**
	 * Purging is safe to run when there is nothing to purge.
	 */
	public function test_purging_nothing_is_harmless() {
		$this->assertSame( 0, Job::purge_synced() );
		$this->assertSame( 0, Job::purge_orphan_terms() );
	}

	/**
	 * Terms left with no jobs are cleaned up; terms still in use are not.
	 */
	public function test_orphan_terms_are_removed_but_used_ones_are_kept() {
		$keeper = $this->make_job( '801080000001099007', 'Kept' );
		$going  = $this->make_job( '801080000001099008', 'Going' );

		wp_set_object_terms( $keeper, 'Engineering', Post_Type::TAX_DEPARTMENT );
		wp_set_object_terms( $going, 'Facilities', Post_Type::TAX_DEPARTMENT );

		// Only delete one of them, so one department is orphaned and one is not.
		wp_delete_post( $going, true );

		$this->assertGreaterThanOrEqual( 1, Job::purge_orphan_terms() );

		$this->assertFalse( get_term_by( 'name', 'Facilities', Post_Type::TAX_DEPARTMENT ) );
		$this->assertNotFalse( get_term_by( 'name', 'Engineering', Post_Type::TAX_DEPARTMENT ) );
	}
}
