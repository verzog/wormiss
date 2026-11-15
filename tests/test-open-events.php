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
 * Tests for the Open events block.
 *
 * @package    wormiss
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Events of a type stay listed until enrolment closes, soonest first.
 *
 * @covers Wormiss_Open_Events
 */
class Test_Wormiss_Open_Events extends Wormiss_Test_Case {

	/**
	 * Register the taxonomy and post type.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();
		Wormiss_Event_Types::register();
		Wormiss_Event_Sync::register();
		wp_unschedule_hook( Wormiss_Event_Sync::SYNC_HOOK );
	}

	/**
	 * A published event of a type.
	 *
	 * @param string $title Title.
	 * @param int    $type  Event type term ID.
	 * @param array  $meta  Post meta.
	 * @return int
	 */
	private function event( string $title, int $type, array $meta ): int {
		$post_id = self::factory()->post->create(
			[
				'post_type'   => 'wormiss_event',
				'post_status' => 'publish',
				'post_title'  => $title,
			]
		);
		wp_set_object_terms( $post_id, [ $type ], Wormiss_Event_Types::TAXONOMY );
		foreach ( $meta as $key => $value ) {
			update_post_meta( $post_id, $key, $value );
		}
		return $post_id;
	}

	/**
	 * A date some days from now, as iMIS gives it.
	 *
	 * @param int $days Days (negative: in the past).
	 * @return string
	 */
	private static function day( int $days ): string {
		return wp_date( 'Y-m-d\TH:i:s', time() + $days * DAY_IN_SECONDS );
	}

	/**
	 * Closed events drop off (iMIS registration close first, else the start); sub-types count;
	 * other types do not; soonest first; nothing open shows the message.
	 *
	 * @return void
	 */
	public function test_find_and_render(): void {
		$courses   = (int) self::factory()->term->create(
			[
				'taxonomy' => Wormiss_Event_Types::TAXONOMY,
				'name'     => 'Courses',
			]
		);
		$essential = (int) self::factory()->term->create(
			[
				'taxonomy' => Wormiss_Event_Types::TAXONOMY,
				'name'     => 'Essential',
				'parent'   => $courses,
			]
		);
		$webinar   = (int) self::factory()->term->create(
			[
				'taxonomy' => Wormiss_Event_Types::TAXONOMY,
				'name'     => 'Webinar',
			]
		);
		$this->event( 'Later course', $essential, [ Wormiss_Event_Sync::META_START => self::day( 40 ) ] );
		$this->event( 'Sooner course', $essential, [ Wormiss_Event_Sync::META_START => self::day( 10 ) ] );
		$this->event( 'Started course', $essential, [ Wormiss_Event_Sync::META_START => self::day( -1 ) ] );
		$this->event(
			'Enrolment closed',
			$essential,
			[
				Wormiss_Event_Sync::META_LINKED         => 1,
				Wormiss_Event_Sync::META_LINKED_DETAILS => [
					'start'   => self::day( 20 ),
					'reg_end' => self::day( -2 ),
				],
			]
		);
		$this->event(
			'Still enrolling',
			$courses,
			[
				Wormiss_Event_Sync::META_LINKED         => 1,
				Wormiss_Event_Sync::META_LINKED_DETAILS => [
					'start'    => self::day( 30 ),
					'reg_end'  => self::day( 5 ),
					'location' => '',
					'online'   => 'https://example.org/meet',
				],
			]
		);
		$this->event( 'A webinar', $webinar, [ Wormiss_Event_Sync::META_START => self::day( 3 ) ] );

		$titles = static fn( array $found ): array => array_map( static fn( array $e ): string => $e['post']->post_title, $found );
		$this->assertSame( [ 'Sooner course', 'Still enrolling', 'Later course' ], $titles( Wormiss_Open_Events::find( 'courses', true, 12, time() ) ) );
		$this->assertSame( [ 'Still enrolling' ], $titles( Wormiss_Open_Events::find( 'courses', false, 12, time() ) ), 'Without its sub-types.' );
		$this->assertSame( [ 'Sooner course' ], $titles( Wormiss_Open_Events::find( 'essential', true, 1, time() ) ), 'At most the limit.' );

		$html = Wormiss_Open_Events::render(
			[
				'type'             => 'courses',
				'children'         => true,
				'limit'            => 12,
				'show_description' => true,
				'button_text'      => '',
				'empty_text'       => '',
			]
		);
		$this->assertStringContainsString( 'Sooner course', $html );
		$this->assertStringContainsString( 'Enrolments close ' . wp_date( 'd/m/Y', time() + 5 * DAY_IN_SECONDS ), $html );
		$this->assertStringContainsString( '>Online<', $html );
		$this->assertStringContainsString( '>Learn more<', $html );
		$this->assertStringNotContainsString( 'Enrolment closed', $html );

		$none = Wormiss_Open_Events::render(
			[
				'type'             => 'nothing-here',
				'children'         => true,
				'limit'            => 12,
				'show_description' => true,
				'button_text'      => '',
				'empty_text'       => 'No courses open right now.',
			]
		);
		$this->assertStringContainsString( 'No courses open right now.', $none );
	}

	/**
	 * A linked event follows iMIS's current closing date, not the one kept when it was linked.
	 *
	 * @return void
	 */
	public function test_linked_event_uses_current_imis_dates(): void {
		$type = (int) self::factory()->term->create(
			[
				'taxonomy' => Wormiss_Event_Types::TAXONOMY,
				'name'     => 'Courses',
			]
		);
		$this->event(
			'Extended course',
			$type,
			[
				Wormiss_Event_Sync::META_IMIS_ID        => 'EXT1',
				Wormiss_Event_Sync::META_LINKED         => 1,
				Wormiss_Event_Sync::META_LINKED_DETAILS => [
					'start'   => self::day( 30 ),
					'reg_end' => self::day( -1 ),
				],
			]
		);
		$this->assertSame( [], Wormiss_Open_Events::find( 'courses', true, 12, time() ), 'Closed by the kept date while iMIS cannot be read.' );

		$this->imis->token()->reply(
			'/api/Event?',
			200,
			[
				'Items' => [
					'$values' => [
						[
							'EventId'             => 'EXT1',
							'EventCode'           => 'EXT1',
							'Name'                => 'Extended course',
							'StartDateTime'       => self::day( 30 ),
							'EndDateTime'         => self::day( 30 ),
							'RegistrationEndDate' => self::day( 10 ),
							'Status'              => 'A',
							'TemplateState'       => 'None',
						],
					],
				],
			]
		);
		$found = Wormiss_Open_Events::find( 'courses', true, 12, time() );
		$this->assertCount( 1, $found, 'iMIS extended the closing date.' );
		$this->assertSame( self::day( 10 ), $found[0]['facts']['reg_end'] );
	}
}
