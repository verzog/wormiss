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
 * Tests for the doctor directory sync (iMIS IQA -> lad_doctor pins).
 *
 * @package    wormiss
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Doctor directory sync.
 *
 * @covers Wormiss_Doctor_Sync
 */
class Test_Wormiss_Doctor_Sync extends Wormiss_Test_Case {

	/**
	 * The IQA this feature is pointed at.
	 */
	const QUERY = '$/SCCA/LocateADoctor';

	/**
	 * Register the lad_doctor post type (lad is not installed in the test site) and turn the
	 * feature on against a query.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		register_post_type(
			'lad_doctor',
			[
				'public'   => true,
				'label'    => 'Doctors',
				'supports' => [ 'title', 'editor', 'thumbnail' ],
			]
		);
		register_taxonomy( 'lad_accreditation', [ 'lad_doctor' ], [ 'public' => true ] );

		update_option(
			Wormiss_Doctor_Sync::OPTION,
			[
				'enabled' => true,
				'query'   => self::QUERY,
			],
			false
		);
	}

	/**
	 * A sample IQA row with sensible defaults, overridable per field.
	 *
	 * @param array<string, mixed> $overrides Field overrides.
	 * @return array<string, mixed>
	 */
	private function row( array $overrides = [] ): array {
		return array_merge(
			[
				'iMISID'    => '101',
				'FirstName' => 'Ada',
				'LastName'  => 'Skin',
				'Company'   => 'Ada Skin Clinic',
				'Suburb'    => 'Abbotsford',
				'State'     => 'VIC',
				'postcode'  => '3067',
				'Country'   => 'Australia',
				'Latitude'  => '-37.80',
				'Longitude' => '144.99',
				'Location'  => '',
				'OptOut'    => '',
			],
			$overrides
		);
	}

	/**
	 * Queue a token and one page of rows, then run a sync.
	 *
	 * @param array[] $rows Rows to serve.
	 * @return array<string, int|bool|string>
	 */
	private function sync( array $rows ): array {
		$this->imis->token()->reply( '/api/iqa', 200, Wormiss_Fake_Imis::page( $rows ) );
		return Wormiss_Doctor_Sync::run();
	}

	/**
	 * The published pins for an iMIS ID (any status), newest first.
	 *
	 * @param string $imis_id iMIS contact ID.
	 * @return int[]
	 */
	private function pins( string $imis_id ): array {
		return get_posts(
			[
				'post_type'      => 'lad_doctor',
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'meta_key'       => '_lad_imis_id', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Test lookup.
				'meta_value'     => $imis_id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Test lookup.
			]
		);
	}

	/**
	 * A row becomes one published pin with its fields mapped.
	 *
	 * @return void
	 */
	public function test_creates_pin(): void {
		$report = $this->sync( [ $this->row() ] );

		$this->assertSame( 1, $report['created'] );
		$this->assertTrue( $report['complete'] );

		$pins = $this->pins( '101' );
		$this->assertCount( 1, $pins );
		$pin = (int) $pins[0];
		$this->assertSame( 'publish', get_post_status( $pin ) );
		$this->assertSame( 'Ada Skin', get_the_title( $pin ) );
		$this->assertSame( 'Ada Skin Clinic', get_post_meta( $pin, '_lad_clinic', true ) );
		$this->assertSame( 'Abbotsford', get_post_meta( $pin, '_lad_suburb', true ) );
		$this->assertSame( '3067', get_post_meta( $pin, '_lad_postcode', true ) );
		$this->assertSame( 'AU', get_post_meta( $pin, '_lad_region', true ) );
		$this->assertSame( '-37.80', get_post_meta( $pin, '_lad_latitude', true ) );
		$this->assertSame( '101', get_post_meta( $pin, '_lad_imis_id', true ) );
	}

	/**
	 * Two locations for one doctor become two separate pins.
	 *
	 * @return void
	 */
	public function test_multiple_locations_make_separate_pins(): void {
		$report = $this->sync(
			[
				$this->row(
					[
						'Location' => 'A',
						'Suburb'   => 'Abbotsford',
					]
				),
				$this->row(
					[
						'Location' => 'B',
						'Suburb'   => 'Deniliquin',
						'State'    => 'NSW',
					]
				),
			]
		);

		$this->assertSame( 2, $report['created'] );
		$this->assertCount( 2, $this->pins( '101' ) );
	}

	/**
	 * A re-sync updates the same pin rather than duplicating it.
	 *
	 * @return void
	 */
	public function test_resync_updates_in_place(): void {
		$this->sync( [ $this->row() ] );
		$report = $this->sync( [ $this->row( [ 'Company' => 'Renamed Clinic' ] ) ] );

		$this->assertSame( 0, $report['created'] );
		$this->assertSame( 1, $report['updated'] );
		$pins = $this->pins( '101' );
		$this->assertCount( 1, $pins );
		$this->assertSame( 'Renamed Clinic', get_post_meta( (int) $pins[0], '_lad_clinic', true ) );
	}

	/**
	 * A single-location pin gains a location on a later sync instead of being duplicated.
	 *
	 * @return void
	 */
	public function test_located_row_adopts_unlocated_pin(): void {
		$this->sync( [ $this->row( [ 'Location' => '' ] ) ] );
		$report = $this->sync( [ $this->row( [ 'Location' => 'LOC1' ] ) ] );

		$this->assertSame( 0, $report['created'] );
		$this->assertSame( 1, $report['updated'] );
		$pins = $this->pins( '101' );
		$this->assertCount( 1, $pins );
		$this->assertSame( 'LOC1', get_post_meta( (int) $pins[0], '_lad_location_id', true ) );
	}

	/**
	 * An opted-out row is not published, and an existing pin is drafted.
	 *
	 * @return void
	 */
	public function test_optout_drafts_existing_pin(): void {
		$this->sync( [ $this->row() ] );
		$report = $this->sync( [ $this->row( [ 'OptOut' => '1' ] ) ] );

		$this->assertSame( 1, $report['skipped_optout'] );
		$this->assertSame( 1, $report['drafted'] );
		$pins = $this->pins( '101' );
		$this->assertSame( 'draft', get_post_status( (int) $pins[0] ) );
	}

	/**
	 * A doctor the feed no longer lists is drafted after a complete sync (reconciliation).
	 *
	 * @return void
	 */
	public function test_withdrawal_is_reconciled(): void {
		$this->sync( [ $this->row() ] );
		$report = $this->sync( [] );

		$this->assertSame( 0, $report['fetched'] );
		$this->assertTrue( $report['complete'] );
		$this->assertSame( 1, $report['drafted'] );
		$this->assertSame( 'draft', get_post_status( (int) $this->pins( '101' )[0] ) );
	}

	/**
	 * A failed or partial fetch never reconciles, so an outage cannot mass-unpublish the map.
	 *
	 * @return void
	 */
	public function test_incomplete_fetch_does_not_reconcile(): void {
		$this->sync( [ $this->row() ] );

		$this->imis->token()->fail( '/api/iqa' );
		$report = Wormiss_Doctor_Sync::run();

		$this->assertFalse( $report['complete'] );
		$this->assertSame( 0, $report['drafted'] );
		$this->assertSame( 'publish', get_post_status( (int) $this->pins( '101' )[0] ) );
	}

	/**
	 * A non-practice (home) address is never published.
	 *
	 * @return void
	 */
	public function test_non_practice_address_skipped(): void {
		$report = $this->sync( [ $this->row( [ 'address_purpose' => 'Home' ] ) ] );

		$this->assertSame( 1, $report['skipped_private'] );
		$this->assertSame( 0, $report['created'] );
		$this->assertCount( 0, $this->pins( '101' ) );
	}

	/**
	 * Reconciliation never touches a pin the sync did not create (no management stamp).
	 *
	 * @return void
	 */
	public function test_reconcile_ignores_unmanaged_posts(): void {
		$hand_made = (int) self::factory()->post->create(
			[
				'post_type'   => 'lad_doctor',
				'post_status' => 'publish',
			]
		);

		$report = $this->sync( [] );

		$this->assertSame( 0, $report['drafted'] );
		$this->assertSame( 'publish', get_post_status( $hand_made ) );
	}

	/**
	 * A row with no iMIS ID is skipped (it cannot be matched on a later sync).
	 *
	 * @return void
	 */
	public function test_row_without_imis_id_skipped(): void {
		$report = $this->sync( [ $this->row( [ 'iMISID' => '' ] ) ] );

		$this->assertSame( 0, $report['created'] );
		$this->assertSame( 1, $report['skipped'] );
	}
}
