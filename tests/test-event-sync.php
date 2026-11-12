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
 * Tests for sending WordPress events to iMIS.
 *
 * @package    wormiss
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Events created and updated in iMIS from WordPress.
 *
 * @covers Wormiss_Event_Sync
 * @covers Wormiss_Rest_Client
 */
class Test_Wormiss_Event_Sync extends Wormiss_Test_Case {

	/**
	 * Register the post type (the test framework resets post types) and sign in an editor.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();
		Wormiss_Event_Sync::register();
		wp_unschedule_hook( Wormiss_Event_Sync::SYNC_HOOK );
		wp_unschedule_hook( Wormiss_Event_Sync::CANCEL_HOOK );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );
	}

	/**
	 * Turn sending on.
	 *
	 * @param bool  $dry_run Dry run.
	 * @param array $more    More settings.
	 * @return void
	 */
	private function turn_on( bool $dry_run = false, array $more = [] ): void {
		update_option(
			Wormiss_Settings::OPTION,
			array_merge(
				Wormiss_Settings::all(),
				[
					'event_sync_enabled' => true,
					'event_sync_dry_run' => $dry_run,
					'event_template_id'  => 'TEMPLATE',
				],
				$more
			)
		);
	}

	/**
	 * A published event with its details.
	 *
	 * @param array $meta Details to add or replace.
	 * @return int Post ID.
	 */
	private function event( array $meta = [] ): int {
		$post_id = self::factory()->post->create(
			[
				'post_type'    => Wormiss_Event_Sync::POST_TYPE,
				'post_title'   => 'Annual Conference',
				'post_content' => '<!-- wp:paragraph --><p>Two days of <strong>talks</strong>.</p><!-- /wp:paragraph -->',
				'post_status'  => 'publish',
			]
		);
		$meta   += [
			Wormiss_Event_Sync::META_CODE     => 'conf27',
			Wormiss_Event_Sync::META_START    => '2027-03-05T09:00',
			Wormiss_Event_Sync::META_END      => '2027-03-06T17:00',
			Wormiss_Event_Sync::META_LOCATION => 'Brisbane Convention Centre',
			Wormiss_Event_Sync::META_ONLINE   => 'https://meet.example.org/conf',
			Wormiss_Event_Sync::META_CAPACITY => 250,
		];
		foreach ( $meta as $key => $value ) {
			update_post_meta( $post_id, $key, $value );
		}
		return $post_id;
	}

	/**
	 * An iMIS event, as GET /api/Event/{id} returns it.
	 *
	 * @param string $id EventId.
	 * @return array
	 */
	private static function imis_event( string $id ): array {
		return [
			'$type'         => 'Asi.Soa.Events.DataContracts.EventData, Asi.Contracts',
			'EventId'       => $id,
			'EventCode'     => $id,
			'Name'          => 'Template',
			'TemplateState' => 'TEMPLATE' === $id ? 'StandardTemplate' : 'None',
			'Location'      => [
				'$type'          => 'Asi.Soa.Membership.DataContracts.LocationPartyAddressData, Asi.Contracts',
				'PartyAddressId' => 'PA1',
				'Name'           => 'Old venue',
			],
			'Functions'     => [
				'$type'   => 'Asi.Soa.Events.DataContracts.EventFunctionDataCollection, Asi.Contracts',
				'$values' => [ [ 'EventFunctionCode' => 'REG' ] ],
			],
			'ProgramGroups' => (object) [],
		];
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
	 * The post type, its details and their cleaning.
	 *
	 * @return void
	 */
	public function test_registered(): void {
		$this->assertTrue( post_type_exists( Wormiss_Event_Sync::POST_TYPE ) );
		$this->assertTrue( registered_meta_key_exists( 'post', Wormiss_Event_Sync::META_START, Wormiss_Event_Sync::POST_TYPE ) );
		$this->assertSame( 'CONF-27', Wormiss_Event_Sync::clean_code( ' conf-27 ' ) );
		$this->assertSame( '', Wormiss_Event_Sync::clean_code( 'CONF 27' ) );
		$this->assertSame( '2027-03-05T09:00', Wormiss_Event_Sync::clean_datetime( '2027-03-05T09:00' ) );
		$this->assertSame( '', Wormiss_Event_Sync::clean_datetime( '2027-02-30T09:00' ) );

		$post_id = $this->event();
		$this->assertSame( 'CONF27', get_post_meta( $post_id, Wormiss_Event_Sync::META_CODE, true ) );
	}

	/**
	 * Events are queued only when sending is on, when published, and by an editor.
	 *
	 * @return void
	 */
	public function test_queue_rules(): void {
		$off = $this->event();
		$this->assertFalse( wp_next_scheduled( Wormiss_Event_Sync::SYNC_HOOK, [ $off ] ) );

		$this->turn_on();
		$on = $this->event();
		$this->assertNotFalse( wp_next_scheduled( Wormiss_Event_Sync::SYNC_HOOK, [ $on ] ) );
		$this->assertSame( 'queued', get_post_meta( $on, Wormiss_Event_Sync::META_STATUS, true ) );

		$draft = self::factory()->post->create(
			[
				'post_type'   => Wormiss_Event_Sync::POST_TYPE,
				'post_status' => 'draft',
			]
		);
		$this->assertFalse( wp_next_scheduled( Wormiss_Event_Sync::SYNC_HOOK, [ $draft ] ) );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'author' ] ) );
		$by_author = $this->event();
		$this->assertFalse( wp_next_scheduled( Wormiss_Event_Sync::SYNC_HOOK, [ $by_author ] ) );
	}

	/**
	 * A dry run asks iMIS to check the new event (the template with the WordPress details)
	 * and creates nothing.
	 *
	 * @return void
	 */
	public function test_dry_run_new_event(): void {
		$this->turn_on( true );
		$post_id = $this->event();
		$this->imis->token()
			->reply( '/api/Event/TEMPLATE', 200, self::imis_event( 'TEMPLATE' ) )
			->reply( '/api/Event/_validate', 200, '{}' );

		$this->assertSame( 'dry_run', Wormiss_Event_Sync::sync( $post_id ) );
		$checked = $this->body( 2 );
		$this->assertSame( 'POST', $this->imis->requests[2]['method'] );
		$this->assertSame( '', $checked['EventId'] );
		$this->assertSame( 'CONF27', $checked['EventCode'] );
		$this->assertSame( 'None', $checked['TemplateState'] );
		$this->assertSame( 'Annual Conference', $checked['Name'] );
		$this->assertSame( 0, $this->imis->count( '/_execute' ) );
		$this->assertSame( '', get_post_meta( $post_id, Wormiss_Event_Sync::META_IMIS_ID, true ) );
		$this->assertStringContainsString( 'Nothing was created', get_post_meta( $post_id, Wormiss_Event_Sync::META_MESSAGE, true ) );
	}

	/**
	 * Problems iMIS finds in a dry run are shown on the event.
	 *
	 * @return void
	 */
	public function test_dry_run_reports_problems(): void {
		$this->turn_on( true );
		$post_id = $this->event();
		$this->imis->token()
			->reply( '/api/Event/TEMPLATE', 200, self::imis_event( 'TEMPLATE' ) )
			->reply(
				'/api/Event/_validate',
				200,
				[
					'IsValid'           => false,
					'ValidationResults' => [ 'Errors' => [ '$values' => [ [ 'Message' => 'Event code already in use.' ] ] ] ],
				]
			);
		$this->assertSame( 'error', Wormiss_Event_Sync::sync( $post_id ) );
		$message = get_post_meta( $post_id, Wormiss_Event_Sync::META_MESSAGE, true );
		$this->assertStringContainsString( 'Event code already in use.', $message );
	}

	/**
	 * Live: the first send copies the template and saves the details over the copy; later
	 * sends update that event without copying again.
	 *
	 * @return void
	 */
	public function test_create_then_update(): void {
		$this->turn_on();
		$post_id = $this->event();
		$this->imis->token()
			->reply( '/api/Event/_execute', 200, [ 'EventId' => 'CONF27' ] )
			->reply( '/api/Event/CONF27', 200, self::imis_event( 'CONF27' ) )
			->reply( '/api/Event/CONF27', 200, '{}' );

		$this->assertSame( 'synced', Wormiss_Event_Sync::sync( $post_id ) );
		$clone = $this->body( 1 );
		$this->assertSame( 'CloneEvent', $clone['OperationName'] );
		$this->assertSame( 'TEMPLATE', $clone['EventId'] );
		$this->assertSame( 'CONF27', $clone['EventCode'] );
		$this->assertSame( 'Annual Conference', $clone['EventName'] );

		$this->assertSame( 'PUT', $this->imis->requests[3]['method'] );
		$saved = $this->body( 3 );
		$this->assertSame( '2027-03-05T09:00:00', $saved['StartDateTime'] );
		$this->assertSame( '2027-03-06T17:00:00', $saved['EndDateTime'] );
		$this->assertSame( 'Brisbane Convention Centre', $saved['Location']['Name'] );
		$this->assertSame( 'PA1', $saved['Location']['PartyAddressId'] );
		$this->assertSame( 250, $saved['Capacity'] );
		$this->assertSame( 'https://meet.example.org/conf', $saved['VirtualMeetingUrl'] );
		$this->assertStringContainsString( '<strong>talks</strong>', $saved['Description'] );
		$this->assertSame( 'REG', $saved['Functions']['$values'][0]['EventFunctionCode'] );
		$this->assertStringContainsString( '"ProgramGroups":{}', (string) $this->imis->requests[3]['body'] );
		$this->assertSame( 'CONF27', get_post_meta( $post_id, Wormiss_Event_Sync::META_IMIS_ID, true ) );

		$this->imis->requests = [];
		$this->imis->token()
			->reply( '/api/Event/CONF27', 200, self::imis_event( 'CONF27' ) )
			->reply( '/api/Event/CONF27', 200, '{}' );
		$this->assertSame( 'synced', Wormiss_Event_Sync::sync( $post_id ) );
		$this->assertSame( 0, $this->imis->count( '/_execute' ) );
	}

	/**
	 * If saving the details fails after the copy was made, the copy is remembered, so the
	 * next send does not copy the template again.
	 *
	 * @return void
	 */
	public function test_failure_after_copy_keeps_the_copy(): void {
		$this->turn_on();
		$post_id = $this->event();
		$this->imis->token()
			->reply( '/api/Event/_execute', 200, '"CONF27"' )
			->reply( '/api/Event/CONF27', 200, self::imis_event( 'CONF27' ) )
			->reply( '/api/Event/CONF27', 500, '{"Message":"Server error"}' );
		$this->assertSame( 'error', Wormiss_Event_Sync::sync( $post_id ) );
		$this->assertSame( 'CONF27', get_post_meta( $post_id, Wormiss_Event_Sync::META_IMIS_ID, true ) );
		$this->assertStringContainsString( 'HTTP 500', get_post_meta( $post_id, Wormiss_Event_Sync::META_MESSAGE, true ) );
	}

	/**
	 * Missing or impossible details stop the send before iMIS is contacted.
	 *
	 * @return void
	 */
	public function test_details_checked_first(): void {
		$this->turn_on( false, [ 'event_template_id' => '' ] );
		$post_id = $this->event();
		$this->assertSame( 'error', Wormiss_Event_Sync::sync( $post_id ) );
		$this->assertStringContainsString( 'template event', get_post_meta( $post_id, Wormiss_Event_Sync::META_MESSAGE, true ) );

		$this->turn_on();
		$backwards = $this->event( [ Wormiss_Event_Sync::META_END => '2027-03-04T09:00' ] );
		$this->assertSame( 'error', Wormiss_Event_Sync::sync( $backwards ) );
		$this->assertStringContainsString( 'before the start', get_post_meta( $backwards, Wormiss_Event_Sync::META_MESSAGE, true ) );
		$this->assertSame( [], $this->imis->requests );
	}

	/**
	 * The sync results are a read-only REST field, not editable meta, so an editor's save that
	 * sends all of the event's meta back cannot be refused because the sync changed them.
	 *
	 * @return void
	 */
	public function test_sync_results_are_read_only_field(): void {
		$this->turn_on();
		$post_id = $this->event( [ Wormiss_Event_Sync::META_IMIS_ID => 'CONF27' ] );
		$request = new WP_REST_Request( 'POST', '/wp/v2/' . Wormiss_Event_Sync::POST_TYPE . '/' . $post_id );
		$request->set_body_params(
			[
				'title' => 'Annual Conference 2027',
				'meta'  => [ Wormiss_Event_Sync::META_LOCATION => 'Sydney' ],
			]
		);
		// A fresh REST server, so its routes include the Events post type registered in set_up().
		$GLOBALS['wp_rest_server'] = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Test set-up.
		$response                  = rest_get_server()->dispatch( $request );
		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertArrayNotHasKey( Wormiss_Event_Sync::META_STATUS, $data['meta'] );
		$this->assertSame( 'CONF27', $data['wormiss_sync']['imis_id'] );
		$this->assertSame( 'queued', $data['wormiss_sync']['status'] );
	}

	/**
	 * An event an editor scheduled is sent when WordPress publishes it later in the background;
	 * one last saved by an author is not.
	 *
	 * @return void
	 */
	public function test_scheduled_publication(): void {
		$this->turn_on();
		$post_id = self::factory()->post->create(
			[
				'post_type'   => Wormiss_Event_Sync::POST_TYPE,
				'post_status' => 'future',
				'post_date'   => gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS ),
			]
		);
		$this->assertFalse( wp_next_scheduled( Wormiss_Event_Sync::SYNC_HOOK, [ $post_id ] ) );

		wp_set_current_user( 0 );
		wp_publish_post( $post_id );
		$this->assertNotFalse( wp_next_scheduled( Wormiss_Event_Sync::SYNC_HOOK, [ $post_id ] ) );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'author' ] ) );
		$by_author = self::factory()->post->create(
			[
				'post_type'   => Wormiss_Event_Sync::POST_TYPE,
				'post_status' => 'future',
				'post_date'   => gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS ),
			]
		);
		wp_set_current_user( 0 );
		wp_publish_post( $by_author );
		$this->assertFalse( wp_next_scheduled( Wormiss_Event_Sync::SYNC_HOOK, [ $by_author ] ) );
	}

	/**
	 * An event trashed while iMIS was copying the template is cancelled there afterwards.
	 *
	 * @return void
	 */
	public function test_trashed_during_copy(): void {
		$this->turn_on( false, [ 'event_cancel_on_trash' => true ] );
		$post_id = $this->event();
		// Trash the event at the moment iMIS answers the copy request.
		$trash = static function ( $pre, $args, $url ) use ( $post_id ) {
			if ( false !== strpos( $url, '/_execute' ) ) {
				wp_trash_post( $post_id );
			}
			return $pre;
		};
		add_filter( 'pre_http_request', $trash, 5, 3 );
		$this->imis->token()->reply( '/api/Event/_execute', 200, [ 'EventId' => 'CONF27' ] );
		$this->assertSame( 'error', Wormiss_Event_Sync::sync( $post_id ) );
		remove_filter( 'pre_http_request', $trash, 5 );

		$this->assertSame( 0, $this->imis->count( '/api/Event/CONF27' ) );
		$this->assertSame( 'CONF27', get_post_meta( $post_id, Wormiss_Event_Sync::META_IMIS_ID, true ) );
		$this->assertNotFalse( wp_next_scheduled( Wormiss_Event_Sync::CANCEL_HOOK, [ $post_id, 'CONF27' ] ) );
	}

	/**
	 * Trashing a sent event cancels it in iMIS only when that is switched on; restoring it
	 * first leaves iMIS alone.
	 *
	 * @return void
	 */
	public function test_cancel_on_trash(): void {
		$this->turn_on();
		$kept = $this->event( [ Wormiss_Event_Sync::META_IMIS_ID => 'KEEP' ] );
		wp_trash_post( $kept );
		$this->assertFalse( wp_next_scheduled( Wormiss_Event_Sync::CANCEL_HOOK, [ $kept, 'KEEP' ] ) );

		$this->turn_on( false, [ 'event_cancel_on_trash' => true ] );
		$post_id = $this->event( [ Wormiss_Event_Sync::META_IMIS_ID => 'CONF27' ] );
		wp_trash_post( $post_id );
		$this->assertNotFalse( wp_next_scheduled( Wormiss_Event_Sync::CANCEL_HOOK, [ $post_id, 'CONF27' ] ) );

		$this->imis->token()->reply( '/api/Event/_execute', 200, '{}' );
		$this->assertSame( 'cancelled', Wormiss_Event_Sync::cancel( $post_id, 'CONF27' ) );
		$cancel = $this->body( 1 );
		$this->assertSame( 'CancelEvent', $cancel['OperationName'] );
		$this->assertSame( 'CONF27', $cancel['Parameters']['$values'][0]['$value'] );

		// Switched off after it was queued: the queued cancel does nothing.
		$this->turn_on( false, [ 'event_cancel_on_trash' => false ] );
		$this->assertSame( '', Wormiss_Event_Sync::cancel( $post_id, 'CONF27' ) );
		$this->assertSame( 1, $this->imis->count( '/_execute' ) );

		$this->turn_on( false, [ 'event_cancel_on_trash' => true ] );
		wp_untrash_post( $post_id );
		$this->assertSame( '', Wormiss_Event_Sync::cancel( $post_id, 'CONF27' ) );
	}

	/**
	 * The settings: the template ID is checked, and the switches survive a double first save.
	 *
	 * @return void
	 */
	public function test_settings(): void {
		$form  = [
			'event_sync_section' => '1',
			'event_sync_enabled' => '1',
			'event_template_id'  => ' template ',
		];
		$twice = Wormiss_Settings::sanitize( Wormiss_Settings::sanitize( $form ) );
		$this->assertTrue( $twice['event_sync_enabled'] );
		$this->assertFalse( $twice['event_sync_dry_run'] );
		$this->assertFalse( $twice['event_cancel_on_trash'] );
		$this->assertSame( 'TEMPLATE', $twice['event_template_id'] );

		$bad = Wormiss_Settings::sanitize( [ 'event_template_id' => 'NOT VALID' ] );
		$this->assertSame( '', $bad['event_template_id'] );
	}

	/**
	 * The event's page shows when and where, and a Register button once it is in iMIS.
	 *
	 * @return void
	 */
	public function test_details_box(): void {
		$post_id = $this->event( [ Wormiss_Event_Sync::META_IMIS_ID => 'CONF27' ] );
		$this->go_to( get_permalink( $post_id ) );
		$GLOBALS['wp_query']->the_post();
		$html = apply_filters( 'the_content', get_the_content() );
		wp_reset_postdata();
		$this->assertStringContainsString( '05/03/2027 9:00 am – 06/03/2027 5:00 pm', $html );
		$this->assertStringContainsString( 'Brisbane Convention Centre', $html );
		$this->assertStringContainsString(
			'href="https://imis.example.org/iCore/Events/Event_display.aspx?EventKey=CONF27"',
			$html
		);
		$this->assertStringContainsString( 'Two days of', $html );
	}

	/**
	 * The new event's ID is found in each shape of CloneEvent response.
	 *
	 * @return void
	 */
	public function test_response_event_id(): void {
		$this->assertSame( 'A1', Wormiss_Rest_Client::response_event_id( 'A1' ) );
		$this->assertSame( 'A1', Wormiss_Rest_Client::response_event_id( (object) [ 'EventId' => 'A1' ] ) );
		$this->assertSame( 'A1', Wormiss_Rest_Client::response_event_id( (object) [ 'Result' => (object) [ 'EventId' => 'A1' ] ] ) );
		$this->assertSame( '', Wormiss_Rest_Client::response_event_id( (object) [ 'EventId' => '../x' ] ) );
	}
}
