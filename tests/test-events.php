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
 * Tests for showing iMIS events from the Events API.
 *
 * @package    wormiss
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Upcoming iMIS events.
 *
 * @covers Wormiss_Events
 * @covers Wormiss_Rest_Client
 */
class Test_Wormiss_Events extends Wormiss_Test_Case {

	/**
	 * An iMIS EventData object as the Events API sends it.
	 *
	 * @param string $id   EventId.
	 * @param array  $more Fields to add or replace.
	 * @return array
	 */
	private static function event( string $id, array $more = [] ): array {
		return array_merge(
			[
				'$type'                 => 'Asi.Soa.Events.DataContracts.EventData, Asi.Contracts',
				'EventId'               => $id,
				'EventCode'             => $id,
				'Name'                  => 'Event ' . $id,
				'Description'           => '<p>About <strong>' . $id . '</strong>.</p>',
				'StartDateTime'         => '2027-03-05T09:00:00',
				'EndDateTime'           => '2027-03-05T17:00:00',
				'Status'                => 'A',
				'TemplateState'         => 'None',
				'Location'              => [
					'$type' => 'Asi.Soa.Membership.DataContracts.LocationPartyAddressData, Asi.Contracts',
					'Name'  => 'Brisbane Convention Centre',
				],
				'PublishingInformation' => [ 'PublishingState' => 'Active' ],
			],
			$more
		);
	}

	/**
	 * A page of events.
	 *
	 * @param array[] $events   Events.
	 * @param bool    $has_next Whether another page follows.
	 * @param int     $next     Next offset.
	 * @return array
	 */
	private static function page( array $events, bool $has_next = false, int $next = 0 ): array {
		return [
			'$type'      => 'Asi.Soa.Core.DataContracts.PagedResult, Asi.Contracts',
			'Items'      => [ '$values' => $events ],
			'Offset'     => 0,
			'Limit'      => 100,
			'HasNext'    => $has_next,
			'NextOffset' => $next,
		];
	}

	/**
	 * Real, public, open, future events are listed soonest first; templates, past, closed and
	 * unpublished events are not. Text is escaped and links go to the event's iMIS page.
	 *
	 * @return void
	 */
	public function test_lists_upcoming_events(): void {
		$this->imis->token()->reply(
			'/api/Event',
			200,
			self::page(
				[
					self::event(
						'LATER',
						[
							'StartDateTime' => '2027-06-01T00:00:00',
							'EndDateTime'   => '2027-06-02T00:00:00',
						]
					),
					self::event( 'SOON', [ 'Name' => 'Skin <b>Basics</b>' ] ),
					self::event( 'TEMPLATE', [ 'TemplateState' => 'StandardTemplate' ] ),
					self::event(
						'PAST',
						[
							'StartDateTime' => '2020-01-01T09:00:00',
							'EndDateTime'   => '2020-01-01T10:00:00',
						]
					),
					self::event( 'CLOSED', [ 'Status' => 'C' ] ),
					self::event( 'DRAFT', [ 'PublishingInformation' => [ 'PublishingState' => 'Working' ] ] ),
				]
			)
		);
		$html = Wormiss_Events::render( [] );

		$this->assertStringContainsString( '/api/Event?StartDateTime=ge%3A', $this->imis->requests[1]['url'] );
		$this->assertStringContainsString( '&limit=100&offset=0', $this->imis->requests[1]['url'] );
		$this->assertStringContainsString( 'Skin &lt;b&gt;Basics&lt;/b&gt;', $html );
		$this->assertLessThan( strpos( $html, 'Event LATER' ), strpos( $html, 'Skin &lt;b&gt;' ) );
		foreach ( [ 'TEMPLATE', 'PAST', 'CLOSED', 'DRAFT' ] as $hidden ) {
			$this->assertStringNotContainsString( 'Event ' . $hidden, $html );
		}
		$this->assertStringContainsString( '05/03/2027, 9:00 am – 5:00 pm', $html );
		$this->assertStringContainsString( '01/06/2027 – 02/06/2027', $html );
		$this->assertStringContainsString( 'Brisbane Convention Centre', $html );
		$this->assertStringContainsString( 'About SOON.', $html );
		$this->assertStringContainsString(
			'href="https://imis.example.org/iCore/Events/Event_display.aspx?EventKey=SOON"',
			$html
		);
		$this->assertStringContainsString( 'aria-label="Find out more: Skin &lt;b&gt;Basics&lt;/b&gt;"', $html );
	}

	/**
	 * If iMIS refuses the date filter, the events are read without it.
	 *
	 * @return void
	 */
	public function test_reads_without_date_filter_if_refused(): void {
		$this->imis->token()
			->reply( '/api/Event', 400, '{"Message":"Unknown property"}' )
			->reply( '/api/Event', 200, self::page( [ self::event( 'EV1' ) ] ) );
		$html = Wormiss_Events::render( [] );
		$this->assertStringContainsString( 'Event EV1', $html );
		$this->assertStringNotContainsString( 'StartDateTime', $this->imis->requests[2]['url'] );
	}

	/**
	 * Every page is read until iMIS says there are no more.
	 *
	 * @return void
	 */
	public function test_reads_every_page(): void {
		$this->imis->token()
			->reply( '/api/Event', 200, self::page( [ self::event( 'P1' ) ], true, 100 ) )
			->reply( '/api/Event', 200, self::page( [ self::event( 'P2' ) ] ) );
		$events = Wormiss_Events::get_events();
		$this->assertSame( [ 'P1', 'P2' ], array_column( $events, 'id' ) );
		$this->assertStringContainsString( 'offset=100', $this->imis->requests[2]['url'] );
	}

	/**
	 * Results are cached; if iMIS fails later, the last good copy is shown; with no copy,
	 * visitors see a short message.
	 *
	 * @return void
	 */
	public function test_cache_and_fallbacks(): void {
		$this->imis->token()->reply( '/api/Event', 200, self::page( [ self::event( 'EV1' ) ] ) );
		Wormiss_Events::render( [] );
		$this->assertStringContainsString( 'Event EV1', Wormiss_Events::render( [] ) );
		$this->assertSame( 1, $this->imis->count( '/api/Event' ) );

		delete_transient( Wormiss_Events::cache_key() );
		$this->imis->token()->reply( '/api/Event', 500, 'Server Error' );
		$this->assertStringContainsString( 'Event EV1', Wormiss_Events::render( [] ) );

		delete_transient( Wormiss_Events::cache_key() );
		delete_transient( Wormiss_Events::cache_key() . '_stale' );
		$this->imis->token()->reply( '/api/Event', 500, 'Server Error' );
		$this->assertStringContainsString( 'isn&#039;t available right now', Wormiss_Events::render( [] ) );
	}

	/**
	 * Category filter, limit, list layout, button text and the empty message.
	 *
	 * @return void
	 */
	public function test_display_options(): void {
		$this->imis->token()->reply(
			'/api/Event',
			200,
			self::page(
				[
					self::event( 'W1', [ 'Category' => [ 'Name' => 'Workshop' ] ] ),
					self::event(
						'W2',
						[
							'Category'      => [ 'Name' => 'Workshop' ],
							'StartDateTime' => '2027-04-01T09:00:00',
						]
					),
					self::event( 'C1', [ 'Category' => [ 'Name' => 'Conference' ] ] ),
				]
			)
		);
		$html = Wormiss_Events::render(
			[
				'category'         => 'workshop',
				'limit'            => 1,
				'layout'           => 'list',
				'show_description' => false,
				'button_text'      => 'Register',
			]
		);
		$this->assertStringContainsString( 'wormiss-events--list', $html );
		$this->assertStringContainsString( 'Event W1', $html );
		$this->assertStringNotContainsString( 'Event W2', $html );
		$this->assertStringNotContainsString( 'Event C1', $html );
		$this->assertStringNotContainsString( 'About W1', $html );
		$this->assertStringContainsString( '>Register</a>', $html );

		$empty = Wormiss_Events::render(
			[
				'category'   => 'Gala',
				'empty_text' => 'Nothing planned yet.',
			]
		);
		$this->assertStringContainsString( 'Nothing planned yet.', $empty );
	}

	/**
	 * Event page and image addresses: iMIS paths join the iMIS site; only http(s) is allowed;
	 * a blank event page address means no link.
	 *
	 * @return void
	 */
	public function test_addresses(): void {
		$this->assertSame( 'https://imis.example.org/images/a.jpg', Wormiss_Events::absolute_url( '~/images/a.jpg' ) );
		$this->assertSame( 'https://cdn.example.net/a.jpg', Wormiss_Events::absolute_url( 'https://cdn.example.net/a.jpg' ) );
		$this->assertSame( '', Wormiss_Events::absolute_url( 'javascript:alert(1)' ) );

		$event = Wormiss_Events::normalise(
			(object) [
				'EventId'   => 'E 1',
				'EventCode' => 'CONF/27',
				'Name'      => 'Conf',
			]
		);
		update_option(
			Wormiss_Settings::OPTION,
			array_merge( Wormiss_Settings::all(), [ 'event_url' => 'https://events.example.org/e/{EventCode}' ] )
		);
		$this->assertSame( 'https://events.example.org/e/CONF%2F27', Wormiss_Events::event_url( $event ) );

		update_option( Wormiss_Settings::OPTION, array_merge( Wormiss_Settings::all(), [ 'event_url' => '' ] ) );
		$this->assertSame( '', Wormiss_Events::event_url( $event ) );
	}

	/**
	 * The events settings are checked when saved; a bad address keeps the old one.
	 *
	 * @return void
	 */
	public function test_settings(): void {
		$clean = Wormiss_Settings::sanitize(
			[
				'event_statuses' => ' A, p, a ',
				'event_url'      => 'javascript:alert(1)',
			]
		);
		$this->assertSame( [ 'A', 'p' ], $clean['event_statuses'] );
		$this->assertSame( Wormiss_Settings::DEFAULT_EVENT_URL, $clean['event_url'] );

		$this->assertTrue( Wormiss_Settings::is_event_url( '/Events/Detail?id={EventId}' ) );
		$this->assertTrue( Wormiss_Settings::is_event_url( 'https://imis.example.org/e?k={EventId}' ) );
		$this->assertFalse( Wormiss_Settings::is_event_url( '//evil.example/e' ) );
		$this->assertFalse( Wormiss_Settings::is_event_url( 'http://imis.example.org/e' ) );
		$this->assertFalse( Wormiss_Settings::is_event_url( '/e?id="x"' ) );
	}

	/**
	 * One event can be read by its ID; an implausible ID is refused before contacting iMIS.
	 *
	 * @return void
	 */
	public function test_get_event(): void {
		$this->imis->token()->reply( '/api/Event/CONF2027', 200, self::event( 'CONF2027' ) );
		$event = ( new Wormiss_Rest_Client() )->get_event( 'CONF2027' );
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- iMIS field.
		$this->assertSame( 'CONF2027', $event->EventId );

		$this->expectException( Wormiss_Exception::class );
		( new Wormiss_Rest_Client() )->get_event( '../Party' );
	}

	/**
	 * The shortcode takes the same options; "no" turns a switch off.
	 *
	 * @return void
	 */
	public function test_shortcode(): void {
		$this->imis->token()->reply(
			'/api/Event',
			200,
			self::page( [ self::event( 'EV1', [ 'ImageUrl' => '~/images/ev1.jpg' ] ) ] )
		);
		$html = do_shortcode( '[wormiss_events show_image="no" button_text="Book now"]' );
		$this->assertStringContainsString( 'Event EV1', $html );
		$this->assertStringNotContainsString( 'ev1.jpg', $html );
		$this->assertStringContainsString( '>Book now</a>', $html );

		$image = 'src="https://imis.example.org/images/ev1.jpg"';
		$this->assertStringContainsString( $image, do_shortcode( '[wormiss_events]' ) );
	}
}
