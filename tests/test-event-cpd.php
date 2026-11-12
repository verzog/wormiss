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
 * Tests for showing an event's CPD hours from iMIS.
 *
 * @package    wormiss
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * CPD rows are read from the iMIS EventCPD panel, added up per category and shown.
 *
 * @covers Wormiss_Event_Cpd
 * @covers Wormiss_Rest_Client::get_entities
 */
class Test_Wormiss_Event_Cpd extends Wormiss_Test_Case {

	/**
	 * Register the post type.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();
		Wormiss_Event_Types::register();
		Wormiss_Event_Sync::register();
	}

	/**
	 * Turn showing CPD on or off.
	 *
	 * @param bool $on On.
	 * @return void
	 */
	private function turn( bool $on ): void {
		update_option( Wormiss_Settings::OPTION, array_merge( Wormiss_Settings::all(), [ 'event_cpd' => $on ] ) );
	}

	/**
	 * One EventCPD row as iMIS sends it.
	 *
	 * @param mixed  $education   EducationPoints.
	 * @param mixed  $reviewing   ReviewingPerformancePoints.
	 * @param string $requirement ProgramLevelRequirements.
	 * @return array
	 */
	private static function cpd_row( $education, $reviewing, string $requirement ): array {
		$property = static fn( string $name, $value ) => [
			'$type' => 'Asi.Soa.Core.DataContracts.GenericPropertyData, Asi.Contracts',
			'Name'  => $name,
			'Value' => $value,
		];
		return [
			'$type'          => 'Asi.Soa.Core.DataContracts.GenericEntityData, Asi.Contracts',
			'EntityTypeName' => 'EventCPD',
			'Properties'     => [
				'$values' => [
					$property( 'EventKey', 'CONGRESS26' ),
					$property(
						'EducationPoints',
						[
							'$type'  => 'System.Decimal',
							'$value' => $education,
						]
					),
					$property( 'ReviewingPerformancePoints', $reviewing ),
					$property( 'MeasuringOutcomesPoints', '0.00' ),
					$property( 'ProgramLevelRequirements', $requirement ),
				],
			],
		];
	}

	/**
	 * A page of rows.
	 *
	 * @param array $rows Rows.
	 * @return array
	 */
	private static function page( array $rows ): array {
		return [
			'$type'   => 'Asi.Soa.Core.DataContracts.PagedResult, Asi.Contracts',
			'Items'   => [ '$values' => $rows ],
			'HasNext' => false,
		];
	}

	/**
	 * Reply with CONGRESS26's two rows.
	 *
	 * @return void
	 */
	private function serve(): void {
		$this->imis->token()->reply(
			'/api/EventCPD?EventKey=CONGRESS26',
			200,
			self::page(
				[
					self::cpd_row( 1.5, '2', 'Addressing health inequities' ),
					self::cpd_row( 0.25, 0, '(None)' ),
				]
			)
		);
	}

	/**
	 * The rows are read for the event, unwrapped and added up.
	 *
	 * @return void
	 */
	public function test_rows_and_totals(): void {
		$this->serve();
		$rows = Wormiss_Event_Cpd::rows( 'CONGRESS26' );
		$this->assertStringContainsString( '/api/EventCPD?EventKey=CONGRESS26&limit=', $this->imis->requests[1]['url'] );
		$this->assertCount( 2, $rows );
		$this->assertSame( 1.5, $rows[0]['education'] );
		$this->assertSame( 2.0, $rows[0]['reviewing'] );
		$this->assertSame( '', $rows[1]['requirement'] );
		$this->assertSame(
			[
				'education' => 1.75,
				'reviewing' => 2.0,
				'outcomes'  => 0.0,
			],
			Wormiss_Event_Cpd::totals( $rows )
		);
		$this->assertSame( [ 'Addressing health inequities' ], Wormiss_Event_Cpd::requirements( $rows ) );
		$this->assertSame( '1.75', Wormiss_Event_Cpd::hours( 1.75 ) );
		$this->assertSame( '2', Wormiss_Event_Cpd::hours( 2.0 ) );
		$this->assertSame( '0', Wormiss_Event_Cpd::hours( 0.0 ) );

		// Cached: no second request.
		$count = count( $this->imis->requests );
		Wormiss_Event_Cpd::rows( 'congress26' );
		$this->assertSame( $count, count( $this->imis->requests ) );
		$this->assertNull( Wormiss_Event_Cpd::rows( '../x' ) );

		// Another iMIS connection does not get these rows from the cache.
		update_option( Wormiss_Settings::OPTION, array_merge( Wormiss_Settings::all(), [ 'base_url' => 'https://other.example.org' ] ) );
		$this->imis->token()->reply( '/api/EventCPD?EventKey=CONGRESS26', 200, self::page( [] ) );
		$this->assertSame( [], Wormiss_Event_Cpd::rows( 'CONGRESS26' ) );
	}

	/**
	 * A failed read shows nothing (and is not asked again straight away).
	 *
	 * @return void
	 */
	public function test_failure(): void {
		$this->imis->token()->reply( '/api/EventCPD', 500, 'An error occurred.' );
		$this->assertNull( Wormiss_Event_Cpd::rows( 'BROKEN1' ) );
		$count = count( $this->imis->requests );
		$this->assertNull( Wormiss_Event_Cpd::rows( 'BROKEN1' ) );
		$this->assertSame( $count, count( $this->imis->requests ) );
	}

	/**
	 * The event page gets the CPD table after its text when CPD is on; nothing when it is off.
	 *
	 * @return void
	 */
	public function test_event_page(): void {
		$post_id = self::factory()->post->create(
			[
				'post_type'    => Wormiss_Event_Sync::POST_TYPE,
				'post_title'   => 'Congress 2026',
				'post_content' => 'Two days of talks.',
				'post_status'  => 'publish',
			]
		);
		update_post_meta( $post_id, Wormiss_Event_Sync::META_IMIS_ID, 'CONGRESS26' );
		$page = function () use ( $post_id ): string {
			$this->go_to( get_permalink( $post_id ) );
			$GLOBALS['wp_query']->the_post();
			$html = apply_filters( 'the_content', get_the_content() );
			wp_reset_postdata();
			return $html;
		};

		$this->turn( false );
		$this->assertStringNotContainsString( 'wormiss-cpd', $page() );
		$this->assertSame( [], $this->imis->requests );

		$this->turn( true );
		$this->serve();
		$html = $page();
		$this->assertStringContainsString( '<h2 class="wormiss-cpd__title">CPD information</h2>', $html );
		$this->assertStringContainsString( '<td>Educational Activities</td><td>Medical Education</td><td class="wormiss-cpd__hours">1.75</td>', $html );
		$this->assertStringContainsString( 'Addressing health inequities', $html );
		$this->assertLessThan( strpos( $html, 'wormiss-cpd' ), strpos( $html, 'Two days of talks.' ) );
	}

	/**
	 * The Event details box gets a CPD hours tab showing iMIS's rows.
	 *
	 * @return void
	 */
	public function test_editor_tab(): void {
		$this->turn( true );
		$post_id = self::factory()->post->create(
			[
				'post_type'   => Wormiss_Event_Sync::POST_TYPE,
				'post_title'  => 'Congress 2026',
				'post_status' => 'draft',
			]
		);
		$box     = function () use ( $post_id ): string {
			ob_start();
			Wormiss_Event_Editor::render( get_post( $post_id ) );
			return (string) ob_get_clean();
		};
		$this->assertStringContainsString( 'CPD hours show here once the event is in iMIS.', $box() );

		update_post_meta( $post_id, Wormiss_Event_Sync::META_IMIS_ID, 'CONGRESS26' );
		$this->serve();
		$html = $box();
		$this->assertStringContainsString( '>CPD hours</button>', $html );
		$this->assertStringContainsString( '<td>1.5</td><td>2</td><td>0</td><td>Addressing health inequities</td>', $html );
		$this->assertStringContainsString( '<td>(None)</td>', $html );
	}
}
