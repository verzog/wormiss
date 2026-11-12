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
 * Tests for the "Event details" box.
 *
 * @package    wormiss
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * The box shows an Event's details in tabs and saves them, cleaned, before the sync is queued.
 *
 * @covers Wormiss_Event_Editor
 */
class Test_Wormiss_Event_Editor extends Wormiss_Test_Case {

	/**
	 * Register the post type and sign in an editor.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();
		Wormiss_Event_Types::register();
		Wormiss_Event_Sync::register();
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );
	}

	/**
	 * Clear the posted form.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		$_POST = [];
		parent::tear_down();
	}

	/**
	 * An event.
	 *
	 * @param array $meta Meta.
	 * @return int Post ID.
	 */
	private function event( array $meta = [] ): int {
		$post_id = self::factory()->post->create(
			[
				'post_type'   => Wormiss_Event_Sync::POST_TYPE,
				'post_title'  => 'Dermoscopy course',
				'post_status' => 'draft',
			]
		);
		foreach ( $meta as $key => $value ) {
			update_post_meta( $post_id, $key, $value );
		}
		return $post_id;
	}

	/**
	 * The box as HTML.
	 *
	 * @param int $post_id Post ID.
	 * @return string
	 */
	private function box( int $post_id ): string {
		ob_start();
		Wormiss_Event_Editor::render( get_post( $post_id ) );
		return (string) ob_get_clean();
	}

	/**
	 * Every tab and field shows, with the saved values; the code is locked once in iMIS.
	 *
	 * @return void
	 */
	public function test_render(): void {
		$post_id = $this->event(
			[
				Wormiss_Event_Sync::META_LOCATION => 'Brisbane <Centre>',
				Wormiss_Event_Sync::META_CAPACITY => 40,
				Wormiss_Event_Sync::META_COUNTRY  => 'New Zealand',
				Wormiss_Event_Sync::META_CODE     => 'DERM27',
			]
		);
		$html    = $this->box( $post_id );
		foreach ( [ 'When', 'Where', 'Places', 'Waitlist', 'iMIS' ] as $tab ) {
			$this->assertStringContainsString( '>' . $tab . '</button>', $html );
		}
		$this->assertStringContainsString( 'name="' . Wormiss_Event_Sync::META_START . '"', $html );
		$this->assertStringContainsString( 'value="Brisbane"', $html ); // Cleaned when saved.
		$this->assertStringContainsString( 'value="40"', $html );
		$this->assertStringContainsString( '<option value="New Zealand" selected=\'selected\'>', $html );
		$this->assertStringNotContainsString( 'readonly', $html );

		update_post_meta( $post_id, Wormiss_Event_Sync::META_IMIS_ID, 'DERM27' );
		$html = $this->box( $post_id );
		$this->assertStringContainsString( 'In iMIS as DERM27', $html );
		$this->assertStringContainsString( 'readonly', $html );
	}

	/**
	 * A linked event shows only its link.
	 *
	 * @return void
	 */
	public function test_render_linked(): void {
		$html = $this->box( $this->event( [ Wormiss_Event_Sync::META_EXISTING => 'CONGRESS26' ] ) );
		$this->assertStringContainsString( 'value="CONGRESS26"', $html );
		$this->assertStringContainsString( 'Linked to an iMIS event', $html );
		$this->assertStringNotContainsString( 'name="' . Wormiss_Event_Sync::META_START . '"', $html );
	}

	/**
	 * Saving keeps what was sent, cleaned by each field's own rules; nothing without the nonce,
	 * and a code already in iMIS stays.
	 *
	 * @return void
	 */
	public function test_save(): void {
		$post_id = $this->event( [ Wormiss_Event_Sync::META_LOCATION => 'Old place' ] );
		$_POST   = [
			Wormiss_Event_Editor::FIELD_SENT  => '1',
			Wormiss_Event_Sync::META_START    => '2027-05-01T09:00',
			Wormiss_Event_Sync::META_CAPACITY => '30',
			Wormiss_Event_Sync::META_STATE    => 'qld',
			Wormiss_Event_Sync::META_COUNTRY  => 'Narnia',
			Wormiss_Event_Sync::META_CODE     => 'derm27',
			Wormiss_Event_Sync::META_LOCATION => 'New place',
		];
		Wormiss_Event_Editor::save( $post_id );
		$this->assertSame( 'Old place', get_post_meta( $post_id, Wormiss_Event_Sync::META_LOCATION, true ), 'No nonce, nothing saved.' );

		$_POST[ Wormiss_Event_Editor::NONCE ] = wp_create_nonce( Wormiss_Event_Editor::NONCE );
		Wormiss_Event_Editor::save( $post_id );
		$this->assertSame( 'New place', get_post_meta( $post_id, Wormiss_Event_Sync::META_LOCATION, true ) );
		$this->assertSame( '2027-05-01T09:00', get_post_meta( $post_id, Wormiss_Event_Sync::META_START, true ) );
		$this->assertSame( 30, (int) get_post_meta( $post_id, Wormiss_Event_Sync::META_CAPACITY, true ) );
		$this->assertSame( 'QLD', get_post_meta( $post_id, Wormiss_Event_Sync::META_STATE, true ) );
		$this->assertSame( '', get_post_meta( $post_id, Wormiss_Event_Sync::META_COUNTRY, true ) );
		$this->assertSame( 'DERM27', get_post_meta( $post_id, Wormiss_Event_Sync::META_CODE, true ) );

		update_post_meta( $post_id, Wormiss_Event_Sync::META_IMIS_ID, 'DERM27' );
		$_POST[ Wormiss_Event_Sync::META_CODE ] = 'OTHER27';
		Wormiss_Event_Editor::save( $post_id );
		$this->assertSame( 'DERM27', get_post_meta( $post_id, Wormiss_Event_Sync::META_CODE, true ) );

		// Someone who cannot edit the event saves nothing.
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );
		$_POST[ Wormiss_Event_Editor::NONCE ]       = wp_create_nonce( Wormiss_Event_Editor::NONCE );
		$_POST[ Wormiss_Event_Sync::META_LOCATION ] = 'Hijacked';
		Wormiss_Event_Editor::save( $post_id );
		$this->assertSame( 'New place', get_post_meta( $post_id, Wormiss_Event_Sync::META_LOCATION, true ) );
	}

	/**
	 * The box saves before the sync is queued, so the sync sends what was just saved.
	 *
	 * @return void
	 */
	public function test_saves_before_queue(): void {
		$hook = 'save_post_' . Wormiss_Event_Sync::POST_TYPE;
		$this->assertSame( 5, has_action( $hook, [ 'Wormiss_Event_Editor', 'save' ] ) );
		$this->assertSame( 20, has_action( $hook, [ 'Wormiss_Event_Sync', 'maybe_queue' ] ) );
	}

	/**
	 * An event WordPress created in iMIS cannot become a link: no field, and nothing saved.
	 *
	 * @return void
	 */
	public function test_no_link_once_created(): void {
		$post_id = $this->event( [ Wormiss_Event_Sync::META_IMIS_ID => 'DERM27' ] );
		$this->assertStringNotContainsString( 'name="' . Wormiss_Event_Sync::META_EXISTING . '"', $this->box( $post_id ) );
		$_POST = [
			Wormiss_Event_Editor::FIELD_SENT  => '1',
			Wormiss_Event_Editor::NONCE       => wp_create_nonce( Wormiss_Event_Editor::NONCE ),
			Wormiss_Event_Sync::META_EXISTING => 'CONGRESS26',
		];
		Wormiss_Event_Editor::save( $post_id );
		$this->assertSame( '', get_post_meta( $post_id, Wormiss_Event_Sync::META_EXISTING, true ) );
		$this->assertFalse( Wormiss_Event_Sync::is_linked( $post_id ) );
	}

	/**
	 * Values reach their own sanitizers untouched: an online link keeps its %2F.
	 *
	 * @return void
	 */
	public function test_online_link_kept(): void {
		$post_id = $this->event();
		$_POST   = [
			Wormiss_Event_Editor::FIELD_SENT => '1',
			Wormiss_Event_Editor::NONCE      => wp_create_nonce( Wormiss_Event_Editor::NONCE ),
			Wormiss_Event_Sync::META_ONLINE  => 'https://meet.example.org/join?token=abc%2Fdef',
		];
		Wormiss_Event_Editor::save( $post_id );
		$this->assertSame( 'https://meet.example.org/join?token=abc%2Fdef', get_post_meta( $post_id, Wormiss_Event_Sync::META_ONLINE, true ) );
	}

	/**
	 * A block editor (REST) save waits a minute for the box, whose save then sends straight away.
	 *
	 * @return void
	 */
	public function test_rest_save_waits_for_box(): void {
		update_option( Wormiss_Settings::OPTION, array_merge( Wormiss_Settings::all(), [ 'event_sync_enabled' => true ] ) );
		add_filter( 'wormiss_event_sync_in_rest', '__return_true' );
		$post_id = self::factory()->post->create(
			[
				'post_type'   => Wormiss_Event_Sync::POST_TYPE,
				'post_title'  => 'Course',
				'post_status' => 'publish',
			]
		);
		remove_filter( 'wormiss_event_sync_in_rest', '__return_true' );
		$when = wp_next_scheduled( Wormiss_Event_Sync::SYNC_HOOK, [ $post_id ] );
		$this->assertNotFalse( $when );
		$this->assertGreaterThan( time() + 30, $when );

		$_POST = [
			Wormiss_Event_Editor::FIELD_SENT => '1',
			Wormiss_Event_Editor::NONCE      => wp_create_nonce( Wormiss_Event_Editor::NONCE ),
			Wormiss_Event_Sync::META_CODE    => 'COURSE27',
		];
		wp_update_post(
			[
				'ID'         => $post_id,
				'post_title' => 'Course 2027',
			]
		);
		$when = wp_next_scheduled( Wormiss_Event_Sync::SYNC_HOOK, [ $post_id ] );
		$this->assertNotFalse( $when );
		$this->assertLessThanOrEqual( time() + 5, $when );
		$this->assertSame( 'COURSE27', get_post_meta( $post_id, Wormiss_Event_Sync::META_CODE, true ) );
		wp_clear_scheduled_hook( Wormiss_Event_Sync::SYNC_HOOK, [ $post_id ] );
	}
}
