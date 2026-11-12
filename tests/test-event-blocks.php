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
 * Tests for the event blocks (facts, CPD information, iMIS settings) and editing in the page.
 *
 * @package    wormiss
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * The event blocks show the event's details where the template places them, and the block
 * editor saves the details (CPD only by editors, and never by accident).
 *
 * @covers Wormiss_Event_Sync::facts_html
 * @covers Wormiss_Event_Sync::template_has
 * @covers Wormiss_Event_Sync::send_after_rest_save
 * @covers Wormiss_Event_Cpd::register_meta
 * @covers Wormiss_Event_Cpd::guard
 * @covers Wormiss_Event_Cpd::section_html
 */
class Test_Wormiss_Event_Blocks extends Wormiss_Test_Case {

	/**
	 * Register the post type and the CPD field.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();
		Wormiss_Event_Types::register();
		Wormiss_Event_Sync::register();
		Wormiss_Event_Cpd::register_meta();
	}

	/**
	 * Forget the template.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		unset( $GLOBALS['_wp_current_template_content'] );
		parent::tear_down();
	}

	/**
	 * A WordPress event in iMIS.
	 *
	 * @return int Post ID.
	 */
	private function event(): int {
		$post_id = self::factory()->post->create(
			[
				'post_type'    => Wormiss_Event_Sync::POST_TYPE,
				'post_title'   => 'Certificate',
				'post_content' => 'About the course.',
				'post_status'  => 'publish',
			]
		);
		update_post_meta( $post_id, Wormiss_Event_Sync::META_START, '2027-05-01T09:00' );
		update_post_meta( $post_id, Wormiss_Event_Sync::META_LOCATION, 'Brisbane Convention Centre' );
		update_post_meta( $post_id, Wormiss_Event_Sync::META_IMIS_ID, 'CERT27' );
		return $post_id;
	}

	/**
	 * Save through the REST API, as the block editor does.
	 *
	 * @param int   $post_id Post ID.
	 * @param array $meta    Meta sent.
	 * @return WP_REST_Response
	 */
	private function rest_save( int $post_id, array $meta ): WP_REST_Response {
		$request = new WP_REST_Request( 'POST', '/wp/v2/' . Wormiss_Event_Sync::POST_TYPE . '/' . $post_id );
		$request->set_body_params( [ 'meta' => $meta ] );
		$GLOBALS['wp_rest_server'] = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Test set-up.
		return rest_get_server()->dispatch( $request );
	}

	/**
	 * The blocks are registered; the facts block shows the facts, the iMIS settings block
	 * nothing on the website.
	 *
	 * @return void
	 */
	public function test_blocks(): void {
		foreach ( [ 'wormiss/event-facts', 'wormiss/event-cpd', 'wormiss/event-imis' ] as $name ) {
			$this->assertTrue( WP_Block_Type_Registry::get_instance()->is_registered( $name ), $name );
		}
		$post_id = $this->event();
		$facts   = ( new WP_Block( [ 'blockName' => 'wormiss/event-facts' ], [ 'postId' => $post_id ] ) )->render();
		$this->assertStringContainsString( 'Brisbane Convention Centre', $facts );
		$this->assertStringContainsString( 'wp-block-wormiss-event-facts', $facts );
		$imis = ( new WP_Block( [ 'blockName' => 'wormiss/event-imis' ], [ 'postId' => $post_id ] ) )->render();
		$this->assertSame( '', trim( $imis ) );
	}

	/**
	 * When the template places the facts and CPD blocks, they are not added to the content too.
	 *
	 * @return void
	 */
	public function test_template_places_them(): void {
		update_option( Wormiss_Settings::OPTION, array_merge( Wormiss_Settings::all(), [ 'event_cpd' => true ] ) );
		$post_id = $this->event();
		update_post_meta(
			$post_id,
			Wormiss_Event_Cpd::META,
			[
				[
					'education'   => 1.5,
					'reviewing'   => 0,
					'outcomes'    => 0,
					'requirement' => '',
					'ordinal'     => 0,
					'uid'         => 'a1',
				],
			]
		);
		$page = function () use ( $post_id ): string {
			$this->go_to( get_permalink( $post_id ) );
			$GLOBALS['wp_query']->the_post();
			$html = apply_filters( 'the_content', get_the_content() );
			wp_reset_postdata();
			return $html;
		};
		$html = $page();
		$this->assertStringContainsString( 'wormiss-event-details', $html );
		$this->assertStringContainsString( '<td class="wormiss-cpd__hours">1.5</td>', $html, 'CPD set here shows as saved.' );

		$GLOBALS['_wp_current_template_content'] = '<!-- wp:wormiss/event-facts /--><!-- wp:post-content /--><!-- wp:wormiss/event-cpd /-->';
		$html                                    = $page();
		$this->assertStringNotContainsString( 'wormiss-event-details', $html );
		$this->assertStringNotContainsString( 'wormiss-cpd', $html );
		$this->assertStringContainsString( 'About the course.', $html );
	}

	/**
	 * The block editor sends every field back: an untouched CPD field never sets CPD (which
	 * would remove iMIS's lines); an editor's lines are saved; an author's are ignored without
	 * failing the save.
	 *
	 * @return void
	 */
	public function test_cpd_through_rest(): void {
		$post_id = $this->event();
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );
		$response = $this->rest_save(
			$post_id,
			[
				Wormiss_Event_Sync::META_LOCATION => 'Sydney',
				Wormiss_Event_Cpd::META           => null,
			]
		);
		$this->assertSame( 200, $response->get_status() );
		$this->assertFalse( Wormiss_Event_Cpd::owned( $post_id ) );
		$this->assertSame( 'Sydney', get_post_meta( $post_id, Wormiss_Event_Sync::META_LOCATION, true ) );
		$this->assertArrayHasKey( 'cpd', $response->get_data()['wormiss_sync'] );

		$line     = [
			'education'   => 2.5,
			'reviewing'   => 0,
			'outcomes'    => 1,
			'requirement' => 'Culturally safe practice',
			'ordinal'     => 0,
			'uid'         => 'abc123',
		];
		$response = $this->rest_save( $post_id, [ Wormiss_Event_Cpd::META => [ $line ] ] );
		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( Wormiss_Event_Cpd::owned( $post_id ) );
		$this->assertSame( 2.5, Wormiss_Event_Cpd::saved_rows( $post_id )[0]['education'] );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'author' ] ) );
		wp_update_post(
			[
				'ID'          => $post_id,
				'post_author' => get_current_user_id(),
			]
		);
		$response = $this->rest_save(
			$post_id,
			[
				Wormiss_Event_Sync::META_LOCATION => 'Perth',
				Wormiss_Event_Cpd::META           => [ array_merge( $line, [ 'education' => 99 ] ) ],
			]
		);
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'Perth', get_post_meta( $post_id, Wormiss_Event_Sync::META_LOCATION, true ) );
		$this->assertSame( 2.5, Wormiss_Event_Cpd::saved_rows( $post_id )[0]['education'], 'An author cannot change CPD.' );
	}

	/**
	 * A block editor save waits a minute (its fields are saved after the post), then is sent as
	 * soon as the REST save has finished.
	 *
	 * @return void
	 */
	public function test_sent_after_rest_save(): void {
		$post_id = $this->event();
		wp_schedule_single_event( time() + MINUTE_IN_SECONDS, Wormiss_Event_Sync::SYNC_HOOK, [ $post_id ] );
		Wormiss_Event_Sync::send_after_rest_save( get_post( $post_id ) );
		$this->assertLessThanOrEqual( time() + 5, wp_next_scheduled( Wormiss_Event_Sync::SYNC_HOOK, [ $post_id ] ) );
		wp_clear_scheduled_hook( Wormiss_Event_Sync::SYNC_HOOK, [ $post_id ] );

		Wormiss_Event_Sync::send_after_rest_save( get_post( $post_id ) );
		$this->assertFalse( wp_next_scheduled( Wormiss_Event_Sync::SYNC_HOOK, [ $post_id ] ), 'Nothing queued, nothing sent.' );
	}
}
