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
 * Tests for members registering for free iMIS events.
 *
 * @package    wormiss
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Free event registration.
 *
 * @covers Wormiss_Event_Registration
 * @covers Wormiss_Rest_Client
 */
class Test_Wormiss_Event_Registration extends Wormiss_Test_Case {

	/**
	 * Turn registering on (live) and sign in a member.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();
		$this->turn_on( false );
		$user_id = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		update_user_meta( $user_id, 'imis_id', '12345' );
		wp_set_current_user( $user_id );
	}

	/**
	 * Turn registering on.
	 *
	 * @param bool $dry_run Dry run.
	 * @param bool $enabled Enabled.
	 * @return void
	 */
	private function turn_on( bool $dry_run, bool $enabled = true ): void {
		update_option(
			Wormiss_Settings::OPTION,
			array_merge(
				Wormiss_Settings::all(),
				[
					'event_registration_enabled' => $enabled,
					'event_registration_dry_run' => $dry_run,
				]
			)
		);
	}

	/**
	 * An iMIS event as the Events API sends it.
	 *
	 * @param string $type Registration type.
	 * @param array  $more Fields to add or replace.
	 * @return array
	 */
	private static function imis_event( string $type = 'RegistrationRequired', array $more = [] ): array {
		return array_merge(
			[
				'EventId'             => 'FREE1',
				'EventCode'           => 'FREE1',
				'Name'                => 'Free webinar',
				'StartDateTime'       => '2027-03-05T18:00:00',
				'EndDateTime'         => '2027-03-05T19:00:00',
				'Status'              => 'A',
				'TemplateState'       => 'None',
				'RegistrationType'    => $type,
				'RegistrationOptions' => [ '$values' => [ [ 'EventFunctionId' => 'FREE1/MAIN' ] ] ],
			],
			$more
		);
	}

	/**
	 * The event as the plugin keeps it.
	 *
	 * @param string $type Registration type.
	 * @param array  $more Fields to add or replace.
	 * @return array
	 */
	private static function event( string $type = 'RegistrationRequired', array $more = [] ): array {
		return Wormiss_Events::normalise( json_decode( (string) wp_json_encode( self::imis_event( $type, $more ) ) ) );
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
	 * Only free, open, real, unfinished events can be registered for.
	 *
	 * @return void
	 */
	public function test_is_open(): void {
		$now = strtotime( '2027-01-01 00:00:00' );
		$this->assertTrue( Wormiss_Event_Registration::is_open( self::event(), $now ) );
		$this->assertSame( 'FREE1/MAIN', self::event()['reg_option'] );
		$this->assertFalse( Wormiss_Event_Registration::is_open( self::event( 'FeeRegistrationRequired' ), $now ) );
		$this->assertFalse( Wormiss_Event_Registration::is_open( self::event( 'RegistrationNotRequired' ), $now ) );
		$closed = self::event( 'RegistrationRequired', [ 'RegistrationEndDate' => '2026-12-01T00:00:00' ] );
		$this->assertFalse( Wormiss_Event_Registration::is_open( $closed, $now ) );
		$template = self::event( 'RegistrationRequired', [ 'TemplateState' => 'StandardTemplate' ] );
		$this->assertFalse( Wormiss_Event_Registration::is_open( $template, $now ) );
	}

	/**
	 * The button: nothing when off or for non-members, a sign-in link when signed out, a
	 * Register form for members, and "You're registered" with Cancel when they are.
	 *
	 * @return void
	 */
	public function test_button(): void {
		$form = Wormiss_Event_Registration::button( self::event() );
		$this->assertStringContainsString( 'name="action" value="wormiss_register"', $form );
		$this->assertStringContainsString( 'name="event_id" value="FREE1"', $form );
		$this->assertStringContainsString( 'name="_wpnonce"', $form );
		$this->assertSame( '', Wormiss_Event_Registration::button( self::event( 'FeeRegistrationRequired' ) ) );

		$this->imis->token()->reply(
			'/api/EventRegistration?',
			200,
			[
				'EventRegistrationId' => 'R1',
				'Status'              => 'Registered',
			]
		);
		$done = Wormiss_Event_Registration::button( self::event(), true );
		$this->assertStringContainsString( 'You&#039;re registered.', $done );
		$this->assertStringContainsString( 'value="wormiss_cancel_registration"', $done );
		// Cached: a second look does not ask iMIS again.
		Wormiss_Event_Registration::button( self::event(), true );
		$this->assertSame( 1, $this->imis->count( '/api/EventRegistration?' ) );

		wp_set_current_user( self::factory()->user->create() );
		$this->assertSame( '', Wormiss_Event_Registration::button( self::event() ) );

		wp_set_current_user( 0 );
		$this->assertStringContainsString( 'Sign in to register', Wormiss_Event_Registration::button( self::event() ) );

		$this->turn_on( false, false );
		$this->assertSame( '', Wormiss_Event_Registration::button( self::event() ) );
	}

	/**
	 * Registering checks the event in iMIS, checks the member is not already registered, then
	 * registers them without payment, for themselves.
	 *
	 * @return void
	 */
	public function test_register(): void {
		$this->imis->token()
			->reply( '/api/Event/FREE1', 200, self::imis_event() )
			->reply( '/api/EventRegistration?', 404, '{"Message":"Not found"}' )
			->reply( '/api/EventRegistration/_execute', 200, '{}' );
		$this->assertSame( 'registered', Wormiss_Event_Registration::register( 'FREE1' ) );

		$this->assertStringContainsString( 'EventId=FREE1&PartyId=12345', $this->imis->requests[2]['url'] );
		$body = $this->body( 3 );
		$this->assertSame( 'RegisterEvent', $body['OperationName'] );
		$this->assertSame( 3, $body['RegistrationType']['$value'] );
		$this->assertSame( 'FREE1', $body['EventId'] );
		$this->assertSame( 'FREE1/MAIN', $body['RegistrationOptionFunctionId'] );
		$this->assertSame( '12345', $body['RegistrantId'] );
		$this->assertSame( '12345', $body['RegisteredBy'] );
		$this->assertSame( '12345', $body['BillTo'] );
		$this->assertFalse( $body['Waitlist'] );
	}

	/**
	 * Already registered, paid or closed events, and iMIS errors, register nothing.
	 *
	 * @return void
	 */
	public function test_register_refusals(): void {
		$this->imis->token()
			->reply( '/api/Event/FREE1', 200, self::imis_event() )
			->reply(
				'/api/EventRegistration?',
				200,
				[
					'EventRegistrationId' => 'R1',
					'Status'              => 'Registered',
				]
			);
		$this->assertSame( 'already', Wormiss_Event_Registration::register( 'FREE1' ) );

		$this->imis->token()->reply( '/api/Event/FREE1', 200, self::imis_event( 'FeeRegistrationRequired' ) );
		$this->assertSame( 'closed', Wormiss_Event_Registration::register( 'FREE1' ) );

		$this->imis->token()->reply( '/api/Event/FREE1', 500, 'Server Error' );
		$this->assertSame( 'error', Wormiss_Event_Registration::register( 'FREE1' ) );
		$this->assertSame( 0, $this->imis->count( '/_execute' ) );

		$this->assertSame( 'off', Wormiss_Event_Registration::register( '../x' ) );
		wp_set_current_user( self::factory()->user->create() );
		$this->assertSame( 'not_member', Wormiss_Event_Registration::register( 'FREE1' ) );
	}

	/**
	 * A dry run checks everything but sends no registration.
	 *
	 * @return void
	 */
	public function test_dry_run(): void {
		$this->turn_on( true );
		$this->imis->token()
			->reply( '/api/Event/FREE1', 200, self::imis_event() )
			->reply( '/api/EventRegistration?', 404, '{}' );
		$this->assertSame( 'dry_run', Wormiss_Event_Registration::register( 'FREE1' ) );
		$this->assertSame( 0, $this->imis->count( '/_execute' ) );
	}

	/**
	 * Cancelling finds the member's registration and cancels it with no fee.
	 *
	 * @return void
	 */
	public function test_cancel(): void {
		$this->imis->token()
			->reply( '/api/Event/FREE1', 200, self::imis_event() )
			->reply(
				'/api/EventRegistration?',
				200,
				[
					'EventRegistrationId' => 'R1',
					'Status'              => 'Registered',
				]
			)
			->reply( '/api/EventRegistration/_execute', 200, '{}' );
		$this->assertSame( 'cancelled', Wormiss_Event_Registration::cancel( 'FREE1' ) );
		$body = $this->body( 3 );
		$this->assertSame( 'CancelEventRegistration', $body['OperationName'] );
		$this->assertSame( 'R1', $body['Parameters']['$values'][0]['$value'] );

		$this->imis->token()
			->reply( '/api/Event/FREE1', 200, self::imis_event() )
			->reply( '/api/EventRegistration?', 404, '{}' );
		$this->assertSame( 'not_registered', Wormiss_Event_Registration::cancel( 'FREE1' ) );

		// An event that has become paid is not cancelled here, whatever an old page shows.
		$this->imis->requests = [];
		$this->imis->token()->reply( '/api/Event/FREE1', 200, self::imis_event( 'FeeRegistrationRequired' ) );
		$this->assertSame( 'cannot_cancel', Wormiss_Event_Registration::cancel( 'FREE1' ) );
		$this->assertSame( 0, $this->imis->count( '/_execute' ) );
	}

	/**
	 * A WordPress event page for a free iMIS event shows Register instead of the iMIS link.
	 *
	 * @return void
	 */
	public function test_event_page(): void {
		Wormiss_Event_Types::register();
		Wormiss_Event_Sync::register();
		$post_id = self::factory()->post->create(
			[
				'post_type'   => Wormiss_Event_Sync::POST_TYPE,
				'post_status' => 'publish',
			]
		);
		update_post_meta( $post_id, Wormiss_Event_Sync::META_IMIS_ID, 'FREE1' );
		update_post_meta( $post_id, Wormiss_Event_Sync::META_START, '2027-03-05T18:00' );
		$this->imis->token()
			->reply( '/api/Event?', 200, [ 'Items' => [ '$values' => [ self::imis_event() ] ] ] )
			->reply( '/api/EventRegistration?', 404, '{}' );

		$this->go_to( get_permalink( $post_id ) );
		$GLOBALS['wp_query']->the_post();
		$html = apply_filters( 'the_content', get_the_content() );
		wp_reset_postdata();
		$this->assertStringContainsString( 'value="wormiss_register"', $html );
		$this->assertStringNotContainsString( 'Event_display.aspx', $html );
	}

	/**
	 * The result message shows once, next to its event's button.
	 *
	 * @return void
	 */
	public function test_notice(): void {
		Wormiss_Event_Registration::set_notice( 'FREE1', 'registered' );
		$first = Wormiss_Event_Registration::button( self::event() );
		$this->assertStringContainsString( 'You&#039;re registered. A confirmation', $first );
		$this->assertStringNotContainsString( 'A confirmation', Wormiss_Event_Registration::button( self::event() ) );

		// Shown even when the event can no longer be registered for (it closed meanwhile).
		Wormiss_Event_Registration::set_notice( 'FREE1', 'closed' );
		$closed = self::event( 'RegistrationRequired', [ 'RegistrationEndDate' => '2020-01-01T00:00:00' ] );
		$this->assertStringContainsString( 'Registration for this event is closed.', Wormiss_Event_Registration::button( $closed ) );
	}

	/**
	 * The sign-in return address keeps a site folder once, not twice.
	 *
	 * @return void
	 */
	public function test_return_url_in_a_site_folder(): void {
		update_option( 'home', 'http://example.org/community' );
		$_SERVER['REQUEST_URI'] = '/community/events/webinar/?x=1';
		$this->assertSame( 'http://example.org/community/events/webinar/?x=1', Wormiss_Event_Registration::return_url() );

		update_option( 'home', 'http://example.org' );
		$_SERVER['REQUEST_URI'] = '/events/webinar/';
		$this->assertSame( 'http://example.org/events/webinar/', Wormiss_Event_Registration::return_url() );
	}
}
