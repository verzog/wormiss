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
 * Tests for joining and leaving an event's waitlist.
 *
 * @package    wormiss
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * "Join the waitlist" on full free events, "You're on the waitlist", and leaving it.
 *
 * @covers Wormiss_Event_Registration
 * @covers Wormiss_Rest_Client
 */
class Test_Wormiss_Event_Waitlist extends Wormiss_Test_Case {

	/** The places IQA used by the tests. */
	const PLACES_QUERY = '$/Events/PlacesTaken';

	/**
	 * Turn registering on (live) with a places IQA, and sign in a member.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();
		$this->settings( false );
		$user_id = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		update_user_meta( $user_id, 'imis_id', '12345' );
		wp_set_current_user( $user_id );
	}

	/**
	 * Registration settings.
	 *
	 * @param bool $dry_run Dry run.
	 * @return void
	 */
	private function settings( bool $dry_run ): void {
		update_option(
			Wormiss_Settings::OPTION,
			array_merge(
				Wormiss_Settings::all(),
				[
					'event_registration_enabled' => true,
					'event_registration_dry_run' => $dry_run,
					'event_places_query'         => self::PLACES_QUERY,
				]
			)
		);
	}

	/**
	 * A full free iMIS event as the Events API sends it.
	 *
	 * @param string $waitlist WaitlistPermissions.
	 * @return array
	 */
	private static function imis_event( string $waitlist = 'StaffAndPublicUsers' ): array {
		return [
			'EventId'             => 'FREE1',
			'EventCode'           => 'FREE1',
			'Name'                => 'Free webinar',
			'StartDateTime'       => '2027-03-05T18:00:00',
			'EndDateTime'         => '2027-03-05T19:00:00',
			'Status'              => 'A',
			'TemplateState'       => 'None',
			'RegistrationType'    => 'RegistrationRequired',
			'Capacity'            => 30,
			'WaitlistPermissions' => $waitlist,
			'RegistrationOptions' => [ '$values' => [ [ 'EventFunctionId' => 'FREE1/MAIN' ] ] ],
		];
	}

	/**
	 * The event as the plugin keeps it.
	 *
	 * @param string $waitlist WaitlistPermissions.
	 * @return array
	 */
	private static function event( string $waitlist = 'StaffAndPublicUsers' ): array {
		return Wormiss_Events::normalise( json_decode( (string) wp_json_encode( self::imis_event( $waitlist ) ) ) );
	}

	/**
	 * A registration as GET EventRegistration returns it.
	 *
	 * @param bool $waitlisted Only on the waitlist.
	 * @return array
	 */
	private static function registration( bool $waitlisted ): array {
		return [
			'EventRegistrationId' => 'R1',
			'Status'              => 'Registered',
			'Functions'           => [
				'$values' => [
					[
						'Quantity'           => [ 'Amount' => $waitlisted ? 0 : 1 ],
						'QuantityWaitlisted' => [ 'Amount' => $waitlisted ? 1 : 0 ],
					],
				],
			],
		];
	}

	/**
	 * A reply from the places IQA, with the sign-in its client needs first.
	 *
	 * @param int $taken Places taken.
	 * @return void
	 */
	private function places_reply( int $taken ): void {
		$this->imis->token()->reply( '/api/iqa', 200, Wormiss_Fake_Imis::page( array_fill( 0, $taken, [ 'ID' => '1' ] ) ) );
	}

	/**
	 * The JSON body of request $n.
	 *
	 * @param int $n Request index.
	 * @return array
	 */
	private function body( int $n ): array {
		return json_decode( (string) $this->imis->requests[ $n ]['body'], true );
	}

	/**
	 * Waitlisted means something waitlisted and nothing booked; the request asks for the waitlist.
	 *
	 * @return void
	 */
	public function test_waitlisted_and_body(): void {
		$decode = static fn( array $data ) => json_decode( (string) wp_json_encode( $data ) );
		$this->assertTrue( Wormiss_Rest_Client::is_waitlisted( $decode( self::registration( true ) ) ) );
		$this->assertFalse( Wormiss_Rest_Client::is_waitlisted( $decode( self::registration( false ) ) ) );
		$this->assertFalse( Wormiss_Rest_Client::is_waitlisted( (object) [ 'Status' => 'Registered' ] ) );

		$this->assertTrue( Wormiss_Rest_Client::register_body( 'FREE1', '12345', 'FREE1/MAIN', true )['Waitlist'] );
		$this->assertFalse( Wormiss_Rest_Client::register_body( 'FREE1', '12345', 'FREE1/MAIN' )['Waitlist'] );
		$this->assertTrue( Wormiss_Event_Registration::waitlist_open( self::event() ) );
		$this->assertFalse( Wormiss_Event_Registration::waitlist_open( self::event( 'OnlyStaff' ) ) );
	}

	/**
	 * A full event offers the waitlist only when members may join it; a waitlisted member sees
	 * "You're on the waitlist" with Leave the waitlist.
	 *
	 * @return void
	 */
	public function test_button(): void {
		$this->imis->token()->reply( '/api/EventRegistration?', 404, '{}' );
		$this->places_reply( 30 );
		$html = Wormiss_Event_Registration::button( self::event(), true );
		$this->assertStringContainsString( 'This event is full.', $html );
		$this->assertStringContainsString( 'value="wormiss_join_waitlist"', $html );
		$this->assertStringContainsString( '>Join the waitlist</button>', $html );

		delete_transient( 'wormiss_reg_' . md5( Wormiss_Settings::base_url() . "\0FREE1\0" . '12345' ) );
		$this->imis->token()->reply( '/api/EventRegistration?', 404, '{}' );
		$staff = Wormiss_Event_Registration::button( self::event( 'OnlyStaff' ), true );
		$this->assertStringContainsString( 'This event is full.', $staff );
		$this->assertStringNotContainsString( 'wormiss_join_waitlist', $staff );

		delete_transient( 'wormiss_reg_' . md5( Wormiss_Settings::base_url() . "\0FREE1\0" . '12345' ) );
		$this->imis->token()->reply( '/api/EventRegistration?', 200, self::registration( true ) );
		$waiting = Wormiss_Event_Registration::button( self::event(), true );
		$this->assertStringContainsString( 'You&#039;re on the waitlist.', $waiting );
		$this->assertStringContainsString( '>Leave the waitlist</button>', $waiting );
		$this->assertStringNotContainsString( 'registered.', $waiting );
	}

	/**
	 * Joining checks the event, the member and that it is still full, then registers them on
	 * the waitlist, for themselves.
	 *
	 * @return void
	 */
	public function test_join(): void {
		$this->imis->token()
			->reply( '/api/Event/FREE1', 200, self::imis_event() )
			->reply( '/api/EventRegistration?', 404, '{}' )
			->reply( '/api/iqa', 200, Wormiss_Fake_Imis::page( array_fill( 0, 30, [ 'ID' => '1' ] ) ) )
			->reply( '/api/EventRegistration/_execute', 200, '{}' );
		$this->assertSame( 'waitlisted', Wormiss_Event_Registration::join_waitlist( 'FREE1' ) );
		$body = $this->body( 4 );
		$this->assertSame( 'RegisterEvent', $body['OperationName'] );
		$this->assertTrue( $body['Waitlist'] );
		$this->assertSame( '12345', $body['RegistrantId'] );
		$this->assertSame( 'FREE1/MAIN', $body['RegistrationOptionFunctionId'] );
	}

	/**
	 * No waitlist for members, a place free again, already on it, registered, or a dry run:
	 * nothing is sent.
	 *
	 * @return void
	 */
	public function test_join_refusals(): void {
		$this->imis->token()->reply( '/api/Event/FREE1', 200, self::imis_event( 'OnlyStaff' ) );
		$this->assertSame( 'no_waitlist', Wormiss_Event_Registration::join_waitlist( 'FREE1' ) );

		$this->imis->token()
			->reply( '/api/Event/FREE1', 200, self::imis_event() )
			->reply( '/api/EventRegistration?', 404, '{}' )
			->reply( '/api/iqa', 200, Wormiss_Fake_Imis::page( array_fill( 0, 10, [ 'ID' => '1' ] ) ) );
		$this->assertSame( 'not_full', Wormiss_Event_Registration::join_waitlist( 'FREE1' ) );

		$this->imis->token()
			->reply( '/api/Event/FREE1', 200, self::imis_event() )
			->reply( '/api/EventRegistration?', 200, self::registration( true ) );
		$this->assertSame( 'already_waitlisted', Wormiss_Event_Registration::join_waitlist( 'FREE1' ) );

		$this->imis->token()
			->reply( '/api/Event/FREE1', 200, self::imis_event() )
			->reply( '/api/EventRegistration?', 200, self::registration( false ) );
		$this->assertSame( 'already', Wormiss_Event_Registration::join_waitlist( 'FREE1' ) );

		$this->settings( true );
		$this->imis->token()
			->reply( '/api/Event/FREE1', 200, self::imis_event() )
			->reply( '/api/EventRegistration?', 404, '{}' )
			->reply( '/api/iqa', 200, Wormiss_Fake_Imis::page( array_fill( 0, 30, [ 'ID' => '1' ] ) ) );
		$this->assertSame( 'dry_run', Wormiss_Event_Registration::join_waitlist( 'FREE1' ) );
		$this->assertSame( 0, $this->imis->count( '/api/EventRegistration/_execute' ) );

		// Registering while on the waitlist says so rather than registering twice.
		$this->settings( false );
		$this->imis->token()
			->reply( '/api/Event/FREE1', 200, self::imis_event() )
			->reply( '/api/EventRegistration?', 200, self::registration( true ) );
		$this->assertSame( 'already_waitlisted', Wormiss_Event_Registration::register( 'FREE1' ) );
	}

	/**
	 * Leaving the waitlist cancels the registration and says the member has left.
	 *
	 * @return void
	 */
	public function test_leave(): void {
		$this->imis->token()
			->reply( '/api/Event/FREE1', 200, self::imis_event() )
			->reply( '/api/EventRegistration?', 200, self::registration( true ) )
			->reply( '/api/EventRegistration/_execute', 200, '{}' );
		$this->assertSame( 'left_waitlist', Wormiss_Event_Registration::cancel( 'FREE1' ) );
		$this->assertSame( 'CancelEventRegistration', $this->body( 3 )['OperationName'] );
		$this->assertArrayHasKey( 'left_waitlist', Wormiss_Event_Registration::messages() );
	}
}
