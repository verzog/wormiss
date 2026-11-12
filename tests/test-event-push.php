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
 * Tests for sending WordPress events to iMIS in batches.
 *
 * @package    wormiss
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * "Send events to iMIS now": events iMIS does not have up to date are sent, a batch at a time.
 *
 * @covers Wormiss_Event_Push
 * @covers Wormiss_Event_Sync
 */
class Test_Wormiss_Event_Push extends Wormiss_Test_Case {

	/**
	 * Register the post type and sign in an editor.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();
		Wormiss_Event_Types::register();
		Wormiss_Event_Sync::register();
		wp_unschedule_hook( Wormiss_Event_Sync::SYNC_HOOK );
		wp_clear_scheduled_hook( Wormiss_Event_Push::MORE_HOOK );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );
	}

	/**
	 * Turn sending on.
	 *
	 * @param bool $dry_run Dry run.
	 * @return void
	 */
	private function turn_on( bool $dry_run ): void {
		update_option(
			Wormiss_Settings::OPTION,
			array_merge(
				Wormiss_Settings::all(),
				[
					'event_sync_enabled' => true,
					'event_sync_dry_run' => $dry_run,
					'event_template_id'  => 'TEMPLATE',
				]
			)
		);
	}

	/**
	 * A published event ready to send.
	 *
	 * @param string $code Event code.
	 * @param array  $meta More meta.
	 * @return int Post ID.
	 */
	private function event( string $code, array $meta = [] ): int {
		$post_id = self::factory()->post->create(
			[
				'post_type'   => Wormiss_Event_Sync::POST_TYPE,
				'post_title'  => 'Event ' . $code,
				'post_status' => 'publish',
			]
		);
		$meta   += [
			Wormiss_Event_Sync::META_CODE     => $code,
			Wormiss_Event_Sync::META_START    => '2027-03-05T09:00',
			Wormiss_Event_Sync::META_END      => '2027-03-05T17:00',
			Wormiss_Event_Sync::META_APPROVED => get_current_user_id(),
		];
		foreach ( $meta as $key => $value ) {
			update_post_meta( $post_id, $key, $value );
		}
		wp_unschedule_hook( Wormiss_Event_Sync::SYNC_HOOK );
		return $post_id;
	}

	/**
	 * An iMIS event.
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
		];
	}

	/**
	 * Off when sending events is off.
	 *
	 * @return void
	 */
	public function test_off(): void {
		$this->event( 'OFF1' );
		$this->assertSame( 'off', Wormiss_Event_Push::run()['status'] );
		$this->assertSame( 0, count( $this->imis->requests ) );
	}

	/**
	 * Which events are due: never sent, last sent with a problem, or edited since; not those
	 * up to date, and never linked ones.
	 *
	 * @return void
	 */
	public function test_due(): void {
		$this->turn_on( false );
		$new     = $this->event( 'NEW1' );
		$failed  = $this->event(
			'FAIL1',
			[
				Wormiss_Event_Sync::META_IMIS_ID => 'FAIL1',
				Wormiss_Event_Sync::META_STATUS  => 'error',
			]
		);
		$current = $this->event(
			'OK1',
			[
				Wormiss_Event_Sync::META_IMIS_ID   => 'OK1',
				Wormiss_Event_Sync::META_STATUS    => 'synced',
				Wormiss_Event_Sync::META_SYNCED_AT => time() + 60,
			]
		);
		$edited  = $this->event(
			'EDIT1',
			[
				Wormiss_Event_Sync::META_IMIS_ID   => 'EDIT1',
				Wormiss_Event_Sync::META_STATUS    => 'synced',
				Wormiss_Event_Sync::META_SYNCED_AT => time() - DAY_IN_SECONDS,
			]
		);
		$linked  = $this->event(
			'LINK1',
			[
				Wormiss_Event_Sync::META_IMIS_ID => 'LINK1',
				Wormiss_Event_Sync::META_LINKED  => 1,
			]
		);
		$author  = $this->event( 'AUTH1', [ Wormiss_Event_Sync::META_APPROVED => '' ] );
		$due     = Wormiss_Event_Push::due();
		$this->assertNotContains( $author, $due, 'An event no editor approved is not sent.' );
		$this->assertContains( $new, $due );
		$this->assertContains( $failed, $due );
		$this->assertContains( $edited, $due );
		$this->assertNotContains( $current, $due );
		$this->assertNotContains( $linked, $due );
	}

	/**
	 * Live: a never-sent event is created in iMIS and kept as up to date.
	 *
	 * @return void
	 */
	public function test_sends(): void {
		$this->turn_on( false );
		$post_id = $this->event( 'SEND1' );
		$this->imis->token()
			->reply( '/api/Event/_execute', 200, [ 'EventId' => 'SEND1' ] )
			->reply( '/api/Event/SEND1', 200, self::imis_event( 'SEND1' ) )
			->reply( '/api/Event/SEND1', 200, '{}' );
		$report = Wormiss_Event_Push::run();
		$this->assertSame( 1, $report['sent'] );
		$this->assertSame( 'SEND1', get_post_meta( $post_id, Wormiss_Event_Sync::META_IMIS_ID, true ) );
		$this->assertGreaterThan( 0, (int) get_post_meta( $post_id, Wormiss_Event_Sync::META_SYNCED_AT, true ) );
		$this->assertNotContains( $post_id, Wormiss_Event_Push::due() );
		$this->assertStringContainsString( '1 saved in iMIS', Wormiss_Event_Push::summary() );
	}

	/**
	 * Many events go a batch at a time, the rest in the background.
	 *
	 * @return void
	 */
	public function test_batches(): void {
		$this->turn_on( true );
		for ( $i = 1; $i <= Wormiss_Event_Push::BATCH + 2; $i++ ) {
			$this->event( 'DRY' . $i );
			$this->imis->token()
				->reply( '/api/Event/TEMPLATE', 200, self::imis_event( 'TEMPLATE' ) )
				->reply( '/api/Event/_validate', 200, '{}' );
		}
		$report = Wormiss_Event_Push::run();
		$this->assertSame( Wormiss_Event_Push::BATCH, $report['checked'] );
		$this->assertSame( 2, $report['remaining'] );
		$this->assertNotFalse( wp_next_scheduled( Wormiss_Event_Push::MORE_HOOK ) );

		$report = Wormiss_Event_Push::run_more();
		$this->assertSame( 2, $report['checked'] );
		$this->assertSame( 0, $report['remaining'] );
		$this->assertFalse( get_option( Wormiss_Event_Push::DONE_OPTION ) );
		$this->assertSame( 0, $this->imis->count( '/_execute' ) );
	}

	/**
	 * A push already running stops a second one starting.
	 *
	 * @return void
	 */
	public function test_busy(): void {
		$this->turn_on( true );
		$this->event( 'BUSY1' );
		add_option( Wormiss_Event_Push::LOCK_OPTION, (string) time(), '', false );
		$this->assertSame( 'busy', Wormiss_Event_Push::run()['status'] );
		$this->assertSame( 0, count( $this->imis->requests ) );
		delete_option( Wormiss_Event_Push::LOCK_OPTION );
	}
}
