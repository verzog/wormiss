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
 * Tests for event numbers and waitlist details.
 *
 * @package    wormiss
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Event codes, places left, minimum and expected attendance, waitlist settings, category IDs
 * with / and iMIS publish dates.
 *
 * @covers Wormiss_Event_Sync
 * @covers Wormiss_Event_Types
 * @covers Wormiss_Events
 * @covers Wormiss_Event_Registration
 * @covers Wormiss_Rest_Client
 */
class Test_Wormiss_Event_Numbers extends Wormiss_Test_Case {

	/** The places IQA used by the tests. */
	const PLACES_QUERY = '$/Events/PlacesTaken';

	/**
	 * Register the post type and taxonomy (the test framework resets them) and sign in an editor.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();
		Wormiss_Event_Types::register();
		Wormiss_Event_Sync::register();
		wp_unschedule_hook( Wormiss_Event_Sync::SYNC_HOOK );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );
	}

	/**
	 * Change settings.
	 *
	 * @param array $more Settings to add or replace.
	 * @return void
	 */
	private function settings( array $more ): void {
		update_option( Wormiss_Settings::OPTION, array_merge( Wormiss_Settings::all(), $more ) );
	}

	/**
	 * An iMIS event as the Events API sends it, kept as the plugin keeps it.
	 *
	 * @param array $more Fields to add or replace.
	 * @return array
	 */
	private static function listed( array $more = [] ): array {
		return Wormiss_Events::normalise( json_decode( (string) wp_json_encode( self::imis_event( $more ) ) ) );
	}

	/**
	 * An iMIS event as the Events API sends it.
	 *
	 * @param array $more Fields to add or replace.
	 * @return array
	 */
	private static function imis_event( array $more = [] ): array {
		return array_merge(
			[
				'$type'               => 'Asi.Soa.Events.DataContracts.EventData, Asi.Contracts',
				'EventId'             => 'FREE1',
				'EventCode'           => 'FREE1',
				'Name'                => 'Free webinar',
				'StartDateTime'       => '2027-03-05T18:00:00',
				'EndDateTime'         => '2027-03-05T19:00:00',
				'Status'              => 'A',
				'TemplateState'       => 'None',
				'RegistrationType'    => 'RegistrationRequired',
				'Capacity'            => 30,
				'RegistrationOptions' => [ '$values' => [ [ 'EventFunctionId' => 'FREE1/MAIN' ] ] ],
			],
			$more
		);
	}

	/**
	 * A reply from the places IQA, with the sign-in its new client needs first.
	 *
	 * @param int $taken Rows (places taken).
	 * @return self
	 */
	private function places_reply( int $taken ): self {
		$this->imis->token()->reply( '/api/iqa', 200, Wormiss_Fake_Imis::page( array_fill( 0, $taken, [ 'ID' => '1' ] ) ) );
		return $this;
	}

	/**
	 * Codes are up to 10 characters; category IDs may have / between parts; the waitlist and
	 * release choices are only iMIS's values.
	 *
	 * @return void
	 */
	public function test_cleaning(): void {
		$this->assertSame( 'CONF2027AB', Wormiss_Event_Sync::clean_code( 'conf2027ab' ) );
		$this->assertSame( '', Wormiss_Event_Sync::clean_code( 'CONF2027ABC' ) );

		$this->assertSame( 'M/L', Wormiss_Event_Types::clean_category( ' m/l ' ) );
		$this->assertSame( 'WORKSHOP', Wormiss_Event_Types::clean_category( 'workshop' ) );
		$this->assertSame( '', Wormiss_Event_Types::clean_category( '/ML' ) );
		$this->assertSame( '', Wormiss_Event_Types::clean_category( 'M//L' ) );
		$this->assertSame( '', Wormiss_Event_Types::clean_category( '../x' ) );
		$this->assertSame( '', Wormiss_Event_Types::clean_id( 'M/L' ) );

		$this->assertSame( 'StaffAndPublicUsers', Wormiss_Event_Types::clean_waitlist( 'StaffAndPublicUsers' ) );
		$this->assertSame( '', Wormiss_Event_Types::clean_waitlist( 'Everyone' ) );
		$this->assertSame( 'yes', Wormiss_Event_Types::clean_yes_no( ' YES ' ) );
		$this->assertSame( '', Wormiss_Event_Types::clean_yes_no( 'maybe' ) );
		$this->assertSame( 12, Wormiss_Event_Types::clean_number( '12' ) );
		$this->assertSame( 0, Wormiss_Event_Types::clean_number( '-3' ) );
	}

	/**
	 * Capacity, waitlist settings and publish dates are read from iMIS events.
	 *
	 * @return void
	 */
	public function test_normalise(): void {
		$event = self::listed(
			[
				'WaitlistPermissions'              => 'StaffAndPublicUsers',
				'AutomaticallyReleaseFromWaitlist' => true,
				'PublishingInformation'            => [
					'PublishingState' => 'Active',
					'StartDate'       => '2027-01-01T00:00:00',
					'ExpirationDate'  => '2027-02-01T00:00:00',
				],
			]
		);
		$this->assertSame( 30, $event['capacity'] );
		$this->assertSame( 'StaffAndPublicUsers', $event['waitlist'] );
		$this->assertTrue( $event['auto_release'] );
		$this->assertSame( '2027-01-01T00:00:00', $event['publish_from'] );

		$before = strtotime( '2026-12-15 00:00:00' );
		$during = strtotime( '2027-01-15 00:00:00' );
		$after  = strtotime( '2027-02-15 00:00:00' );
		// Publish dates are ignored unless Settings says to follow them.
		$this->assertTrue( Wormiss_Events::is_published( $event, $before ) );
		$this->settings( [ 'event_publish_dates' => true ] );
		$this->assertFalse( Wormiss_Events::is_published( $event, $before ) );
		$this->assertTrue( Wormiss_Events::is_published( $event, $during ) );
		$this->assertFalse( Wormiss_Events::is_published( $event, $after ) );
		$this->assertCount( 1, Wormiss_Events::upcoming( [ $event ], '', $during ) );
		$this->assertCount( 0, Wormiss_Events::upcoming( [ $event ], '', $after ) );
		$this->assertTrue( Wormiss_Events::is_published( self::listed(), $before ) );
	}

	/**
	 * Places taken: rows counted, or a Registered column added up.
	 *
	 * @return void
	 */
	public function test_places_taken(): void {
		$this->assertSame( 3, Wormiss_Rest_Client::places_taken( [ [ 'ID' => '1' ], [ 'ID' => '2' ], [ 'ID' => '3' ] ] ) );
		$this->assertSame( 0, Wormiss_Rest_Client::places_taken( [] ) );
		$this->assertSame( 7, Wormiss_Rest_Client::places_taken( [ [ 'Registered' => 7 ] ] ) );
		$this->assertSame( 5, Wormiss_Rest_Client::places_taken( [ [ 'quantity' => '2' ], [ 'Quantity' => '3' ] ] ) );
	}

	/**
	 * Places come from the IQA (given the event ID), are cached, and read as words; no IQA,
	 * no capacity or a failed read shows nothing; a capacity of 1000 or more is not counted.
	 *
	 * @return void
	 */
	public function test_places(): void {
		$this->assertNull( Wormiss_Events::places( self::listed() ) );

		$this->settings( [ 'event_places_query' => self::PLACES_QUERY ] );
		$this->places_reply( 18 );
		$places = Wormiss_Events::places( self::listed() );
		$this->assertSame( 12, $places['left'] );
		$this->assertSame( '12 of 30 places left', Wormiss_Events::places_text( $places ) );
		$this->assertStringContainsString( 'QueryName=%24%2FEvents%2FPlacesTaken&Parameter=FREE1', $this->imis->requests[1]['url'] );

		// Cached: no second read.
		$this->assertSame( 12, Wormiss_Events::places( self::listed() )['left'] );
		$this->assertSame( 1, $this->imis->count( '/api/iqa' ) );

		Wormiss_Events::forget_places( 'FREE1' );
		$this->places_reply( 31 );
		$full = Wormiss_Events::places( self::listed() );
		$this->assertSame( 0, $full['left'] );
		$this->assertSame( 'Full', Wormiss_Events::places_text( $full ) );

		$open = Wormiss_Events::places( self::listed( [ 'Capacity' => 1000 ] ) );
		$this->assertTrue( $open['unlimited'] );
		$this->assertSame( 'Places available', Wormiss_Events::places_text( $open ) );
		$this->assertSame( 2, $this->imis->count( '/api/iqa' ) );

		$this->assertNull( Wormiss_Events::places( self::listed( [ 'Capacity' => 0 ] ) ) );

		// A different places IQA is counted afresh, not read from the old one's cache.
		$this->settings( [ 'event_places_query' => '$/Events/OtherCount' ] );
		$this->places_reply( 5 );
		$this->assertSame( 25, Wormiss_Events::places( self::listed() )['left'] );
		$this->assertSame( 3, $this->imis->count( '/api/iqa' ) );

		$this->imis->token()->fail( '/api/iqa' );
		$this->assertNull( Wormiss_Events::places( self::listed( [ 'EventId' => 'OTHER' ] ) ) );
	}

	/**
	 * Numbers and waitlist settings come from the event, else its type, and are written over
	 * the iMIS event (attendance on the main registration option); blank keeps iMIS's.
	 *
	 * @return void
	 */
	public function test_apply_numbers_and_waitlist(): void {
		$type = self::factory()->term->create(
			[
				'taxonomy' => Wormiss_Event_Types::TAXONOMY,
				'name'     => 'Workshop',
			]
		);
		update_term_meta( $type, Wormiss_Event_Types::META_TEMPLATE, 'WKSHPTPL' );
		update_term_meta( $type, Wormiss_Event_Types::META_MINIMUM, 8 );
		update_term_meta( $type, Wormiss_Event_Types::META_EXPECTED, 20 );
		update_term_meta( $type, Wormiss_Event_Types::META_WAITLIST, 'OnlyStaff' );
		update_term_meta( $type, Wormiss_Event_Types::META_AUTO_RELEASE, 'no' );
		update_term_meta( $type, Wormiss_Event_Types::META_CATEGORY, 'm/l' );
		$this->assertSame( 'M/L', get_term_meta( $type, Wormiss_Event_Types::META_CATEGORY, true ) );

		$post_id = self::factory()->post->create(
			[
				'post_type'   => Wormiss_Event_Sync::POST_TYPE,
				'post_title'  => 'Dermoscopy workshop',
				'post_status' => 'publish',
			]
		);
		wp_set_object_terms( $post_id, [ $type ], Wormiss_Event_Types::TAXONOMY );
		update_post_meta( $post_id, Wormiss_Event_Sync::META_CODE, 'DERM27' );
		update_post_meta( $post_id, Wormiss_Event_Sync::META_START, '2027-05-01T09:00' );
		update_post_meta( $post_id, Wormiss_Event_Sync::META_END, '2027-05-01T17:00' );
		update_post_meta( $post_id, Wormiss_Event_Sync::META_EXPECTED, 25 );
		update_post_meta( $post_id, Wormiss_Event_Sync::META_WAITLIST, 'StaffAndPublicUsers' );

		$details = Wormiss_Event_Sync::details( get_post( $post_id ) );
		$this->assertSame( 8, $details['minimum'] );
		$this->assertSame( 25, $details['expected'] );
		$this->assertSame( 'StaffAndPublicUsers', $details['waitlist'] );
		$this->assertSame( 'no', $details['release'] );
		$this->assertSame( '', Wormiss_Event_Sync::problem( $details ) );

		$event = json_decode( (string) wp_json_encode( self::imis_event( [ 'AutomaticallyReleaseFromWaitlist' => true ] ) ) );
		$saved = json_decode( (string) wp_json_encode( Wormiss_Event_Sync::apply( $event, $details ) ), true );
		$this->assertSame( 'StaffAndPublicUsers', $saved['WaitlistPermissions'] );
		$this->assertFalse( $saved['AutomaticallyReleaseFromWaitlist'] );
		$this->assertSame( 8, $saved['RegistrationOptions']['$values'][0]['MinimumAttendance'] );
		$this->assertSame( 25, $saved['RegistrationOptions']['$values'][0]['ExpectedAttendance'] );
		$this->assertSame( 'M/L', $saved['Category']['EventCategoryId'] );

		// A wrapped value keeps its wrapping; blank settings leave iMIS's alone.
		$wrapped = (object) [
			'WaitlistPermissions'              => (object) [
				'$type'  => 'Asi.Soa.Events.DataContracts.WaitlistPermissionsData, Asi.Contracts',
				'$value' => 'None',
			],
			'AutomaticallyReleaseFromWaitlist' => true,
		];
		$blank   = array_merge(
			$details,
			[
				'waitlist' => '',
				'release'  => '',
			]
		);
		$kept    = Wormiss_Event_Sync::apply( clone $wrapped, $blank );
		// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- iMIS fields.
		$this->assertSame( 'None', $kept->WaitlistPermissions->{'$value'} );
		$this->assertTrue( $kept->AutomaticallyReleaseFromWaitlist );
		$changed = Wormiss_Event_Sync::apply( $wrapped, $details );
		$this->assertSame( 'StaffAndPublicUsers', $changed->WaitlistPermissions->{'$value'} );
		// phpcs:enable
	}

	/**
	 * Attendance that cannot fit is a problem to fix before sending.
	 *
	 * @return void
	 */
	public function test_attendance_problems(): void {
		$details = [
			'type_problem' => '',
			'template'     => 'TPL',
			'imis_id'      => '',
			'code'         => 'X1',
			'name'         => 'X',
			'start'        => '2027-05-01T09:00',
			'end'          => '2027-05-01T17:00',
			'capacity'     => 20,
			'minimum'      => 5,
			'expected'     => 10,
		];
		$this->assertSame( '', Wormiss_Event_Sync::problem( $details ) );
		$this->assertNotSame( '', Wormiss_Event_Sync::problem( array_merge( $details, [ 'expected' => 25 ] ) ) );
		$this->assertNotSame( '', Wormiss_Event_Sync::problem( array_merge( $details, [ 'minimum' => 12 ] ) ) );
		$no_limit = array_merge(
			$details,
			[
				'capacity' => 0,
				'expected' => 50,
			]
		);
		$this->assertSame( '', Wormiss_Event_Sync::problem( $no_limit ) );
	}

	/**
	 * A category ID with / is looked up in the category list, not in the address.
	 *
	 * @return void
	 */
	public function test_category_with_slash(): void {
		$list = [
			'Items'   => [
				'$values' => [
					[ 'EventCategoryId' => 'CONF' ],
					[ 'EventCategoryId' => 'M/L' ],
				],
			],
			'HasNext' => false,
		];
		$this->imis->token()->reply( '/api/EventCategory?', 200, $list )->reply( '/api/EventCategory?', 200, $list );
		$client = new Wormiss_Rest_Client();
		$this->assertTrue( $client->event_category_exists( 'M/L' ) );
		$this->assertFalse( $client->event_category_exists( 'A/B' ) );
		$this->assertSame( 0, $this->imis->count( '/api/EventCategory/' ) );
	}

	/**
	 * A full event says so instead of offering Register, and registering checks again.
	 *
	 * @return void
	 */
	public function test_full_event(): void {
		$this->settings(
			[
				'event_registration_enabled' => true,
				'event_registration_dry_run' => false,
				'event_places_query'         => self::PLACES_QUERY,
			]
		);
		$user_id = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		update_user_meta( $user_id, 'imis_id', '12345' );
		wp_set_current_user( $user_id );

		$this->imis->token()
			->reply( '/api/EventRegistration?', 404, '{}' );
		$this->places_reply( 30 );
		$html = Wormiss_Event_Registration::button( self::listed(), true );
		$this->assertStringContainsString( 'This event is full.', $html );
		$this->assertStringNotContainsString( 'value="wormiss_register"', $html );

		$this->imis->reply( '/api/Event/FREE1', 200, self::imis_event() )
			->reply( '/api/EventRegistration?', 404, '{}' );
		$this->places_reply( 30 );
		$this->assertSame( 'full', Wormiss_Event_Registration::register( 'FREE1' ) );
		$this->assertSame( 0, $this->imis->count( '/api/EventRegistration/_execute' ) );
	}

	/**
	 * The event page shows the event code and places left; the Events list has code and iMIS columns.
	 *
	 * @return void
	 */
	public function test_event_page_and_columns(): void {
		$this->settings( [ 'event_places_query' => self::PLACES_QUERY ] );
		$post_id = self::factory()->post->create(
			[
				'post_type'   => Wormiss_Event_Sync::POST_TYPE,
				'post_status' => 'publish',
			]
		);
		update_post_meta( $post_id, Wormiss_Event_Sync::META_CODE, 'FREE1' );
		update_post_meta( $post_id, Wormiss_Event_Sync::META_IMIS_ID, 'FREE1' );
		update_post_meta( $post_id, Wormiss_Event_Sync::META_STATUS, 'synced' );
		$this->imis->token()->reply( '/api/Event?', 200, [ 'Items' => [ '$values' => [ self::imis_event() ] ] ] );
		$this->places_reply( 10 );

		$this->go_to( get_permalink( $post_id ) );
		$GLOBALS['wp_query']->the_post();
		$html = apply_filters( 'the_content', get_the_content() );
		wp_reset_postdata();
		$this->assertStringContainsString( '<dt>Event code</dt><dd>FREE1</dd>', $html );
		$this->assertStringContainsString( '<dt>Places</dt><dd>20 of 30 places left</dd>', $html );

		$columns = Wormiss_Event_Sync::columns(
			[
				'cb'    => '',
				'title' => 'Title',
				'date'  => 'Date',
			]
		);
		$this->assertSame( [ 'cb', 'title', 'wormiss_code', 'wormiss_imis', 'date' ], array_keys( $columns ) );
		ob_start();
		Wormiss_Event_Sync::column( 'wormiss_imis', $post_id );
		$this->assertSame( 'Saved (FREE1)', ob_get_clean() );
	}

	/**
	 * The places IQA and publish dates setting are checked when saved.
	 *
	 * @return void
	 */
	public function test_settings(): void {
		$clean = Wormiss_Settings::sanitize(
			[
				'events_section'      => '1',
				'event_places_query'  => ' $/Events/PlacesTaken ',
				'event_publish_dates' => '1',
			]
		);
		$this->assertSame( '$/Events/PlacesTaken', $clean['event_places_query'] );
		$this->assertTrue( $clean['event_publish_dates'] );
		$this->assertFalse( Wormiss_Settings::defaults()['event_publish_dates'] );
		$this->assertSame( '', Wormiss_Settings::defaults()['event_places_query'] );
	}
}
