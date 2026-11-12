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
 * Tests for linking a WordPress event to an existing iMIS event.
 *
 * @package    wormiss
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * An event page linked to an event already in iMIS: read from iMIS, never sent or cancelled.
 *
 * @covers Wormiss_Event_Sync
 */
class Test_Wormiss_Event_Link extends Wormiss_Test_Case {

	/**
	 * Register the post type, sign in an editor and turn sending (and cancelling) on.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();
		Wormiss_Event_Types::register();
		Wormiss_Event_Sync::register();
		wp_unschedule_hook( Wormiss_Event_Sync::SYNC_HOOK );
		wp_unschedule_hook( Wormiss_Event_Sync::CANCEL_HOOK );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );
		update_option(
			Wormiss_Settings::OPTION,
			array_merge(
				Wormiss_Settings::all(),
				[
					'event_sync_enabled'    => true,
					'event_sync_dry_run'    => false,
					'event_template_id'     => 'TEMPLATE',
					'event_cancel_on_trash' => true,
				]
			)
		);
	}

	/**
	 * An iMIS event.
	 *
	 * @param string $id EventId.
	 * @return array
	 */
	private static function imis_event( string $id = 'CONGRESS26' ): array {
		return [
			'EventId'       => $id,
			'EventCode'     => $id,
			'Name'          => 'Congress 2026',
			'StartDateTime' => '2027-05-01T09:00:00',
			'EndDateTime'   => '2027-05-03T17:00:00',
			'Status'        => 'A',
			'TemplateState' => 'None',
			'Location'      => [ 'Name' => 'Gold Coast Convention Centre' ],
		];
	}

	/**
	 * A published event with an existing iMIS event ID entered.
	 *
	 * @param string $existing Existing iMIS event ID.
	 * @return int Post ID.
	 */
	private function event( string $existing = 'congress26' ): int {
		$post_id = self::factory()->post->create(
			[
				'post_type'   => Wormiss_Event_Sync::POST_TYPE,
				'post_title'  => 'Congress',
				'post_status' => 'publish',
			]
		);
		update_post_meta( $post_id, Wormiss_Event_Sync::META_EXISTING, $existing );
		return $post_id;
	}

	/**
	 * Saving links the page to the iMIS event after checking it is there; nothing is sent.
	 *
	 * @return void
	 */
	public function test_links_existing_event(): void {
		$post_id = $this->event();
		$this->assertSame( 'CONGRESS26', get_post_meta( $post_id, Wormiss_Event_Sync::META_EXISTING, true ) );
		$this->imis->token()->reply( '/api/Event/CONGRESS26', 200, self::imis_event() );

		$this->assertSame( 'linked', Wormiss_Event_Sync::maybe_link( $post_id, get_post( $post_id ) ) );
		$this->assertSame( 'CONGRESS26', get_post_meta( $post_id, Wormiss_Event_Sync::META_IMIS_ID, true ) );
		$this->assertSame( 'linked', get_post_meta( $post_id, Wormiss_Event_Sync::META_STATUS, true ) );
		$this->assertStringContainsString( 'Congress 2026', get_post_meta( $post_id, Wormiss_Event_Sync::META_MESSAGE, true ) );
		$this->assertSame( 0, $this->imis->count( '/_execute' ) );
		$this->assertTrue( Wormiss_Event_Sync::rest_sync_field( [ 'id' => $post_id ] )['linked'] );

		// Later saves neither send it nor read iMIS again.
		Wormiss_Event_Sync::maybe_queue( $post_id, get_post( $post_id ) );
		$this->assertFalse( wp_next_scheduled( Wormiss_Event_Sync::SYNC_HOOK, [ $post_id ] ) );
		$this->assertSame( '', Wormiss_Event_Sync::sync( $post_id ) );
		$this->assertSame( '', Wormiss_Event_Sync::maybe_link( $post_id, get_post( $post_id ) ) );
		$this->assertSame( 2, count( $this->imis->requests ) );
	}

	/**
	 * An event iMIS does not have is not linked, and is never copied from the template instead.
	 *
	 * @return void
	 */
	public function test_unknown_event_is_not_linked(): void {
		$post_id = $this->event( 'NOPE26' );
		wp_schedule_single_event( time(), Wormiss_Event_Sync::SYNC_HOOK, [ $post_id ] );
		$this->imis->token()->reply( '/api/Event/NOPE26', 404, '{"Message":"Not found"}' );

		$this->assertSame( 'error', Wormiss_Event_Sync::maybe_link( $post_id, get_post( $post_id ) ) );
		$this->assertSame( '', get_post_meta( $post_id, Wormiss_Event_Sync::META_IMIS_ID, true ) );
		$this->assertStringContainsString( 'NOPE26', get_post_meta( $post_id, Wormiss_Event_Sync::META_MESSAGE, true ) );
		$this->assertFalse( wp_next_scheduled( Wormiss_Event_Sync::SYNC_HOOK, [ $post_id ] ) );
		$this->assertSame( '', Wormiss_Event_Sync::sync( $post_id ) );
		$this->assertSame( 0, $this->imis->count( '/_execute' ) );
	}

	/**
	 * Clearing the ID unlinks the page; an event WordPress created cannot be linked elsewhere.
	 *
	 * @return void
	 */
	public function test_unlink_and_created_events(): void {
		$post_id = $this->event();
		update_post_meta( $post_id, Wormiss_Event_Sync::META_IMIS_ID, 'CONGRESS26' );
		update_post_meta( $post_id, Wormiss_Event_Sync::META_LINKED, 1 );
		update_post_meta( $post_id, Wormiss_Event_Sync::META_EXISTING, '' );
		$this->assertSame( 'unlinked', Wormiss_Event_Sync::maybe_link( $post_id, get_post( $post_id ) ) );
		$this->assertSame( '', get_post_meta( $post_id, Wormiss_Event_Sync::META_IMIS_ID, true ) );
		$this->assertFalse( Wormiss_Event_Sync::is_linked( $post_id ) );

		$created = $this->event( 'OTHER26' );
		update_post_meta( $created, Wormiss_Event_Sync::META_IMIS_ID, 'CONF27' );
		$this->assertSame( 'error', Wormiss_Event_Sync::maybe_link( $created, get_post( $created ) ) );
		$this->assertSame( 'CONF27', get_post_meta( $created, Wormiss_Event_Sync::META_IMIS_ID, true ) );
		$this->assertSame( 0, count( $this->imis->requests ) );
	}

	/**
	 * Only editors can link; trashing a linked event never cancels it in iMIS.
	 *
	 * @return void
	 */
	public function test_authors_and_trash(): void {
		$post_id = $this->event();
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'author' ] ) );
		$this->assertSame( '', Wormiss_Event_Sync::maybe_link( $post_id, get_post( $post_id ) ) );
		$this->assertSame( 0, count( $this->imis->requests ) );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );
		update_post_meta( $post_id, Wormiss_Event_Sync::META_IMIS_ID, 'CONGRESS26' );
		update_post_meta( $post_id, Wormiss_Event_Sync::META_LINKED, 1 );
		Wormiss_Event_Sync::maybe_queue_cancel( $post_id );
		$this->assertFalse( wp_next_scheduled( Wormiss_Event_Sync::CANCEL_HOOK, [ $post_id, 'CONGRESS26' ] ) );
	}

	/**
	 * A linked event's page shows when, where and its code from iMIS.
	 *
	 * @return void
	 */
	public function test_page_details_come_from_imis(): void {
		$post_id = $this->event();
		update_post_meta( $post_id, Wormiss_Event_Sync::META_IMIS_ID, 'CONGRESS26' );
		update_post_meta( $post_id, Wormiss_Event_Sync::META_LINKED, 1 );
		$this->imis->token()->reply( '/api/Event?', 200, [ 'Items' => [ '$values' => [ self::imis_event() ] ] ] );

		$this->go_to( get_permalink( $post_id ) );
		$GLOBALS['wp_query']->the_post();
		$html = apply_filters( 'the_content', get_the_content() );
		wp_reset_postdata();
		$this->assertStringContainsString( '01/05/2027 9:00 am – 03/05/2027 5:00 pm', $html );
		$this->assertStringContainsString( 'Gold Coast Convention Centre', $html );
		$this->assertStringContainsString( '<dt>Event code</dt><dd>CONGRESS26</dd>', $html );
		$this->assertStringContainsString( 'EventKey=CONGRESS26', $html );
	}
}
