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
 * Tests for the bulk-categorise tool.
 *
 * @package    wormiss
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Bulk categorise (media spec phase 3).
 *
 * @covers Wormiss_Media_Bulk
 */
class Test_Wormiss_Media_Bulk extends Wormiss_Test_Case {

	/**
	 * Register the taxonomy.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();
		Wormiss_Media_Categories::register();
	}

	/**
	 * A media category.
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
	 * An attachment, optionally in categories.
	 *
	 * @param int[] $term_ids Category term IDs.
	 * @return int
	 */
	private function attachment( array $term_ids = [] ): int {
		$id = (int) self::factory()->post->create(
			[
				'post_type'      => 'attachment',
				'post_mime_type' => 'image/jpeg',
				'post_status'    => 'inherit',
			]
		);
		if ( $term_ids ) {
			wp_set_object_terms( $id, $term_ids, Wormiss_Media_Categories::TAXONOMY );
		}
		return $id;
	}

	/**
	 * The category term IDs on an attachment.
	 *
	 * @param int $id Attachment ID.
	 * @return int[]
	 */
	private function terms_on( int $id ): array {
		$terms = wp_get_object_terms( $id, Wormiss_Media_Categories::TAXONOMY, [ 'fields' => 'ids' ] );
		return is_wp_error( $terms ) ? [] : array_map( 'intval', $terms );
	}

	/**
	 * "Uncategorised" matches only images with no category; "all" matches every attachment.
	 *
	 * @return void
	 */
	public function test_matching_ids(): void {
		$logos = $this->category( 'Logos' );
		$a     = $this->attachment();
		$b     = $this->attachment();
		$c     = $this->attachment( [ $logos ] );

		$uncategorised = Wormiss_Media_Bulk::matching_ids( 'uncategorised' );
		sort( $uncategorised );
		$expected = [ $a, $b ];
		sort( $expected );
		$this->assertSame( $expected, $uncategorised );
		$this->assertSame( 2, Wormiss_Media_Bulk::count( 'uncategorised' ) );
		$this->assertContains( $c, Wormiss_Media_Bulk::matching_ids( 'all' ) );
		$this->assertSame( 3, Wormiss_Media_Bulk::count( 'all' ) );
	}

	/**
	 * A job adds the category to every uncategorised image, records a report and clears itself.
	 *
	 * @return void
	 */
	public function test_start_and_run(): void {
		$logos = $this->category( 'Logos' );
		$a     = $this->attachment();
		$b     = $this->attachment();
		$kept  = $this->attachment( [ $logos ] );

		$this->assertTrue( Wormiss_Media_Bulk::start( $logos, 'uncategorised' ) );
		$this->assertTrue( Wormiss_Media_Bulk::is_running() );
		$this->assertSame( 2, Wormiss_Media_Bulk::job()['total'] );

		Wormiss_Media_Bulk::run();

		$this->assertFalse( Wormiss_Media_Bulk::is_running(), 'Finished and cleared.' );
		$this->assertSame( [ $logos ], $this->terms_on( $a ) );
		$this->assertSame( [ $logos ], $this->terms_on( $b ) );
		$this->assertSame( [ $logos ], $this->terms_on( $kept ), 'Already categorised, unchanged.' );
		$report = get_option( Wormiss_Media_Bulk::REPORT_OPTION );
		$this->assertSame( 2, (int) $report['done'] );
		$this->assertSame( $logos, (int) $report['category'] );
	}

	/**
	 * "All" scope adds the category to every attachment, keeping existing categories.
	 *
	 * @return void
	 */
	public function test_all_scope(): void {
		$logos    = $this->category( 'Logos' );
		$headshot = $this->category( 'Headshots' );
		$plain    = $this->attachment();
		$already  = $this->attachment( [ $headshot ] );

		$this->assertTrue( Wormiss_Media_Bulk::start( $logos, 'all' ) );
		Wormiss_Media_Bulk::run();

		$this->assertSame( [ $logos ], $this->terms_on( $plain ) );
		$got = $this->terms_on( $already );
		sort( $got );
		$expected = [ $logos, $headshot ];
		sort( $expected );
		$this->assertSame( $expected, $got, 'Added alongside the existing category.' );
	}

	/**
	 * Starting is refused when off, already running, the category is unknown, or nothing matches.
	 *
	 * @return void
	 */
	public function test_start_guards(): void {
		$logos = $this->category( 'Logos' );
		$this->attachment();

		$this->assertFalse( Wormiss_Media_Bulk::start( 999999, 'uncategorised' ), 'Unknown category.' );

		$off = static fn() => false;
		add_filter( 'wormiss_media_bulk_enabled', $off );
		$this->assertFalse( Wormiss_Media_Bulk::enabled() );
		$this->assertFalse( Wormiss_Media_Bulk::start( $logos, 'uncategorised' ), 'Tool off.' );
		remove_filter( 'wormiss_media_bulk_enabled', $off );

		$this->assertTrue( Wormiss_Media_Bulk::start( $logos, 'uncategorised' ) );
		$this->assertFalse( Wormiss_Media_Bulk::start( $logos, 'uncategorised' ), 'Already running.' );

		// Finish it; then nothing is uncategorised and a new uncategorised job is refused.
		Wormiss_Media_Bulk::run();
		$this->assertFalse( Wormiss_Media_Bulk::is_running() );
		$this->assertSame( 0, Wormiss_Media_Bulk::count( 'uncategorised' ) );
		$this->assertFalse( Wormiss_Media_Bulk::start( $logos, 'uncategorised' ), 'Nothing matches.' );
	}

	/**
	 * Cancelling clears the job; run() with no job does nothing.
	 *
	 * @return void
	 */
	public function test_cancel_and_no_job(): void {
		$logos = $this->category( 'Logos' );
		$this->attachment();
		$this->assertTrue( Wormiss_Media_Bulk::start( $logos, 'uncategorised' ) );
		Wormiss_Media_Bulk::cancel();
		$this->assertFalse( Wormiss_Media_Bulk::is_running() );

		// No job: a run is a no-op and does not error.
		Wormiss_Media_Bulk::run();
		$this->assertFalse( Wormiss_Media_Bulk::is_running() );
	}
}
