<?php
// This file is part of Wormiss, an iMIS bridge for WordPress.
//
// Wormiss is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Wormiss is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Wormiss.  If not, see <https://www.gnu.org/licenses/>.

/**
 * Tests for the "move protected images into the private store" tool.
 *
 * @package    wormiss
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Migrate existing protected images into the private store.
 *
 * @covers Wormiss_Media_Migrate
 */
class Test_Wormiss_Media_Migrate extends Wormiss_Test_Case {

	/**
	 * A temporary private store for the test.
	 *
	 * @var string
	 */
	private string $store = '';

	/**
	 * Register the taxonomy and protected meta; point the store at a temp directory.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();
		Wormiss_Media_Categories::register();
		Wormiss_Media_Protected::register_meta();
		$this->store = get_temp_dir() . 'wormiss-migrate-test-' . wp_rand() . '/';
		add_filter( 'wormiss_protected_dir', fn() => $this->store );
	}

	/**
	 * Remove the temp store.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		if ( '' !== $this->store && is_dir( $this->store ) ) {
			$files = glob( $this->store . '*/*' );
			foreach ( is_array( $files ) ? $files : [] as $file ) {
				wp_delete_file( $file );
			}
		}
		parent::tear_down();
	}

	/**
	 * A plain media category.
	 *
	 * @param string $name Name.
	 * @return int
	 */
	private function category( string $name ): int {
		return (int) self::factory()->term->create(
			[
				'taxonomy' => Wormiss_Media_Categories::TAXONOMY,
				'name'     => $name,
			]
		);
	}

	/**
	 * A protected media category.
	 *
	 * @return int
	 */
	private function protected_category(): int {
		$term = $this->category( 'Protected ' . wp_rand() );
		update_term_meta( $term, Wormiss_Media_Protected::META_PROTECTED, 1 );
		return $term;
	}

	/**
	 * An image attachment with a real file on disk, optionally in categories.
	 *
	 * @param int[] $term_ids Category term IDs.
	 * @return int
	 */
	private function attachment( array $term_ids = [] ): int {
		$upload = wp_upload_bits( 'wormiss-' . wp_rand() . '.jpg', null, 'test-bytes' );
		$id     = (int) self::factory()->attachment->create_object(
			$upload['file'],
			0,
			[ 'post_mime_type' => 'image/jpeg' ]
		);
		if ( $term_ids ) {
			wp_set_object_terms( $id, $term_ids, Wormiss_Media_Categories::TAXONOMY );
		}
		return $id;
	}

	/**
	 * Matching IDs are protected images whose original is still public: an unprotected image, an
	 * already-stored one, and a non-image are all excluded.
	 *
	 * @return void
	 */
	public function test_matching_ids(): void {
		$cat       = $this->protected_category();
		$protected = $this->attachment( [ $cat ] );
		$open      = $this->attachment( [ $this->category( 'Open' ) ] );
		$stored    = $this->attachment( [ $cat ] );
		Wormiss_Media_Delivery::store( $stored );
		$pdf = (int) self::factory()->attachment->create_object(
			get_temp_dir() . 'x.pdf',
			0,
			[ 'post_mime_type' => 'application/pdf' ]
		);
		wp_set_object_terms( $pdf, [ $cat ], Wormiss_Media_Categories::TAXONOMY );

		$ids = Wormiss_Media_Migrate::matching_ids();
		$this->assertContains( $protected, $ids, 'Protected, not yet stored.' );
		$this->assertNotContains( $open, $ids, 'Unprotected category.' );
		$this->assertNotContains( $stored, $ids, 'Already in the store.' );
		$this->assertNotContains( $pdf, $ids, 'Not an image.' );
		$this->assertSame( 1, Wormiss_Media_Migrate::count() );
	}

	/**
	 * A sub-category of a protected parent is covered too.
	 *
	 * @return void
	 */
	public function test_matching_ids_includes_subcategory(): void {
		$parent = $this->protected_category();
		$child  = (int) self::factory()->term->create(
			[
				'taxonomy' => Wormiss_Media_Categories::TAXONOMY,
				'parent'   => $parent,
			]
		);
		$img    = $this->attachment( [ $child ] );
		$this->assertContains( $img, Wormiss_Media_Migrate::matching_ids(), 'Image under a protected parent.' );
	}

	/**
	 * A job moves every matching original into the store, records a report and clears itself.
	 *
	 * @return void
	 */
	public function test_start_and_run(): void {
		$cat      = $this->protected_category();
		$img      = $this->attachment( [ $cat ] );
		$original = get_attached_file( $img );
		$this->assertFileExists( $original );

		$this->assertTrue( Wormiss_Media_Migrate::start() );
		$this->assertSame( 1, Wormiss_Media_Migrate::job()['total'] );
		Wormiss_Media_Migrate::run();

		$this->assertFalse( Wormiss_Media_Migrate::is_running(), 'Finished and cleared.' );
		$this->assertTrue( Wormiss_Media_Delivery::is_stored( $img ), 'Moved into the store.' );
		$this->assertFileDoesNotExist( $original, 'Public original removed.' );
		$report = get_option( Wormiss_Media_Migrate::REPORT_OPTION );
		$this->assertSame( 1, (int) $report['moved'] );
		$this->assertSame( 0, (int) $report['failed'] );
	}

	/**
	 * Starting is refused when off, already running, or nothing matches.
	 *
	 * @return void
	 */
	public function test_start_guards(): void {
		$this->assertFalse( Wormiss_Media_Migrate::start(), 'Nothing matches.' );

		$cat = $this->protected_category();
		$this->attachment( [ $cat ] );

		$off = static fn() => false;
		add_filter( 'wormiss_media_migrate_enabled', $off );
		$this->assertFalse( Wormiss_Media_Migrate::enabled() );
		$this->assertFalse( Wormiss_Media_Migrate::start(), 'Tool off.' );
		remove_filter( 'wormiss_media_migrate_enabled', $off );

		$this->assertTrue( Wormiss_Media_Migrate::start() );
		$this->assertFalse( Wormiss_Media_Migrate::start(), 'Already running.' );
		Wormiss_Media_Migrate::run();
		$this->assertFalse( Wormiss_Media_Migrate::start(), 'Nothing left to move.' );
	}

	/**
	 * Turning the tool off mid-job stops the worker and clears the job without moving anything.
	 *
	 * @return void
	 */
	public function test_kill_switch_stops_run(): void {
		$cat      = $this->protected_category();
		$img      = $this->attachment( [ $cat ] );
		$original = get_attached_file( $img );
		$this->assertTrue( Wormiss_Media_Migrate::start() );

		$off = static fn() => false;
		add_filter( 'wormiss_media_migrate_enabled', $off );
		Wormiss_Media_Migrate::run();
		remove_filter( 'wormiss_media_migrate_enabled', $off );

		$this->assertFalse( Wormiss_Media_Migrate::is_running(), 'Job cleared.' );
		$this->assertFileExists( $original, 'Nothing moved after the kill-switch.' );
		$this->assertFalse( Wormiss_Media_Delivery::is_stored( $img ) );
	}

	/**
	 * Cancelling clears the job; run() with no job is a no-op.
	 *
	 * @return void
	 */
	public function test_cancel_and_no_job(): void {
		$cat = $this->protected_category();
		$this->attachment( [ $cat ] );
		$this->assertTrue( Wormiss_Media_Migrate::start() );
		Wormiss_Media_Migrate::cancel();
		$this->assertFalse( Wormiss_Media_Migrate::is_running() );

		Wormiss_Media_Migrate::run();
		$this->assertFalse( Wormiss_Media_Migrate::is_running() );
	}

	/**
	 * A move that fails (unwritable store) records the attachment ID in the report, so the admin can
	 * find the images whose originals are still public.
	 *
	 * @return void
	 */
	public function test_failed_move_recorded(): void {
		$cat = $this->protected_category();
		$img = $this->attachment( [ $cat ] );
		// Point the store at a path whose parent is a file, so it cannot be created.
		$blocker = get_temp_dir() . 'wormiss-blocker-' . wp_rand();
		file_put_contents( $blocker, 'x' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture.
		$bad = static fn() => $blocker . '/store/';
		add_filter( 'wormiss_protected_dir', $bad, 99 );

		$this->assertTrue( Wormiss_Media_Migrate::start() );
		Wormiss_Media_Migrate::run();

		remove_filter( 'wormiss_protected_dir', $bad, 99 );
		wp_delete_file( $blocker );

		$report = get_option( Wormiss_Media_Migrate::REPORT_OPTION );
		$this->assertSame( 0, (int) $report['moved'] );
		$this->assertSame( 1, (int) $report['failed'] );
		$this->assertSame( [ $img ], array_map( 'intval', (array) $report['failed_ids'] ), 'Failed attachment recorded.' );
		$this->assertFalse( Wormiss_Media_Delivery::is_stored( $img ) );
	}

	/**
	 * A pending job whose cron continuation was lost is rescheduled by maybe_resume().
	 *
	 * @return void
	 */
	public function test_maybe_resume(): void {
		$cat = $this->protected_category();
		$this->attachment( [ $cat ] );
		$this->assertTrue( Wormiss_Media_Migrate::start() );
		wp_clear_scheduled_hook( Wormiss_Media_Migrate::HOOK );

		Wormiss_Media_Migrate::maybe_resume();
		$this->assertNotFalse( wp_next_scheduled( Wormiss_Media_Migrate::HOOK ), 'Pending job rescheduled.' );
		Wormiss_Media_Migrate::cancel();
	}
}
