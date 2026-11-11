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
 * Tests for recording activity in iMIS: the queue, reads, downloads, Mark as done and forms.
 *
 * @package    wormiss
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Activity records in iMIS.
 *
 * @covers Wormiss_Activity
 * @covers Wormiss_Completion
 */
class Test_Wormiss_Activity extends Wormiss_Test_Case {

	/**
	 * Start each test with nothing queued and no cached membership.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();
		wp_unschedule_hook( Wormiss_Activity::SEND_HOOK );
		Wormiss_Access::reset();
	}

	/**
	 * Turn recording on.
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
					'activity_enabled' => true,
					'activity_dry_run' => $dry_run,
					'activity_type'    => 'WEBACTIVITY',
				],
				$more
			)
		);
	}

	/**
	 * Sign in a member with an iMIS ID.
	 *
	 * @param string $imis_id iMIS ID ('' for none).
	 * @return int User ID.
	 */
	private function sign_in( string $imis_id = '12345' ): int {
		$user_id = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		if ( '' !== $imis_id ) {
			update_user_meta( $user_id, 'imis_id', $imis_id );
		}
		wp_set_current_user( $user_id );
		return $user_id;
	}

	/**
	 * Records waiting to be sent.
	 *
	 * @return array[] Each: time, args (record, attempt).
	 */
	private function queued(): array {
		$out = [];
		foreach ( (array) _get_cron_array() as $time => $hooks ) {
			foreach ( $hooks[ Wormiss_Activity::SEND_HOOK ] ?? [] as $event ) {
				$out[] = [
					'time' => (int) $time,
					'args' => $event['args'],
				];
			}
		}
		return $out;
	}

	/**
	 * A property value from an activity body.
	 *
	 * @param array  $body Activity body.
	 * @param string $name Property name.
	 * @return mixed
	 */
	private static function prop( array $body, string $name ) {
		foreach ( $body['Properties']['$values'] as $prop ) {
			if ( $prop['Name'] === $name ) {
				return $prop['Value'] ?? null;
			}
		}
		return 'missing';
	}

	/**
	 * Nothing is recorded while off, without an activity type, or for unlinked users.
	 *
	 * @return void
	 */
	public function test_off_by_default_and_guards(): void {
		$this->sign_in();
		$this->assertFalse( Wormiss_Activity::record( 'read', 'A post' ) );

		$this->turn_on( false, [ 'activity_type' => '' ] );
		$this->assertFalse( Wormiss_Activity::record( 'read', 'A post' ) );

		$this->turn_on();
		$this->sign_in( '' );
		$this->assertFalse( Wormiss_Activity::record( 'read', 'A post' ) );
		$this->assertSame( [], $this->queued() );
	}

	/**
	 * A dry run logs what would be sent, and sends nothing.
	 *
	 * @return void
	 */
	public function test_dry_run_logs_only(): void {
		$this->turn_on( true, [ 'log_capture' => true ] );
		$this->sign_in();
		$this->assertTrue( Wormiss_Activity::record( 'read', 'Members news' ) );
		$this->assertSame( [], $this->queued() );
		$this->assertSame( [], $this->imis->requests );

		$log = Wormiss_Logger::recent( 1 )[0];
		$this->assertSame( 'DRY RUN POST /api/Activity (read: Members news)', $log['endpoint'] );
		$this->assertSame( '12345', $log['imis_id'] );
		$this->assertStringContainsString( 'WEBACTIVITY', (string) $log['request'] );
	}

	/**
	 * A record is queued, then sent as a full iMIS Activity with typed values.
	 *
	 * @return void
	 */
	public function test_queue_then_send_full_record(): void {
		$this->turn_on();
		$this->sign_in( '12345' );
		$this->assertTrue(
			Wormiss_Activity::record(
				'completion',
				'Dermoscopy quiz',
				[
					'note'    => 'Completed: Dermoscopy quiz',
					'points'  => 2.5,
					'post_id' => 42,
				]
			)
		);
		$queued = $this->queued();
		$this->assertCount( 1, $queued );
		[ $record, $attempt ] = $queued[0]['args'];
		$this->assertSame( 1, $attempt );

		$this->imis->token()->reply( '/api/Activity', 201, [ 'Id' => 'x' ] );
		$this->assertTrue( Wormiss_Activity::send( $record, 1 ) );

		$request = end( $this->imis->requests );
		$this->assertSame( 'POST', $request['method'] );
		$this->assertStringEndsWith( '/api/Activity', $request['url'] );
		$body = json_decode( $request['body'], true );
		$this->assertSame( 'Activity', $body['EntityTypeName'] );
		$this->assertSame( [ '12345' ], $body['PrimaryParentIdentity']['IdentityElements']['$values'] );
		$this->assertSame( 'WEBACTIVITY', self::prop( $body, 'ACTIVITY_TYPE' ) );
		$this->assertSame( '12345', self::prop( $body, 'PartyId' ) );
		$this->assertSame( 'Dermoscopy quiz', self::prop( $body, 'DESCRIPTION' ) );
		$this->assertSame( 'completion', self::prop( $body, 'NOTE_2' ) );
		$this->assertSame(
			[
				'$type'  => 'System.Decimal',
				'$value' => 2.5,
			],
			self::prop( $body, 'UF_4' )
		);
		$this->assertNull( self::prop( $body, 'UF_1' ) );
		$this->assertCount( 44, $body['Properties']['$values'] );
	}

	/**
	 * A failed send is retried after 5 and then 30 minutes, and then given up.
	 *
	 * @return void
	 */
	public function test_retries_then_gives_up(): void {
		$this->turn_on();
		$this->sign_in();
		$record = [
			'kind'        => 'read',
			'imis_id'     => '12345',
			'user_id'     => get_current_user_id(),
			'description' => 'X',
			'note'        => '',
			'points'      => 0,
			'post_id'     => 0,
			'date'        => '2026-11-12T09:00:00',
		];

		$this->imis->token()->reply( '/api/Activity', 500, 'Server Error' );
		$this->assertFalse( Wormiss_Activity::send( $record, 1 ) );
		$queued = $this->queued();
		$this->assertCount( 1, $queued );
		$this->assertSame( 2, $queued[0]['args'][1] );
		$this->assertEqualsWithDelta( time() + 300, $queued[0]['time'], 5 );

		wp_unschedule_hook( Wormiss_Activity::SEND_HOOK );
		$this->imis->token()->reply( '/api/Activity', 500, 'Server Error' );
		$this->assertFalse( Wormiss_Activity::send( $record, 3 ) );
		$this->assertSame( [], $this->queued() );
	}

	/**
	 * Reads of members-only posts are recorded once a day; public posts and the teaser are not.
	 *
	 * @return void
	 */
	public function test_reads(): void {
		$this->turn_on();
		$this->sign_in();
		$public = self::factory()->post->create();
		$this->go_to( get_permalink( $public ) );
		Wormiss_Activity::maybe_record_read();
		$this->assertSame( [], $this->queued() );

		$members = self::factory()->post->create( [ 'post_title' => 'Members news' ] );
		update_post_meta( $members, Wormiss_Access::META_ENABLED, true );
		$this->go_to( get_permalink( $members ) );
		Wormiss_Activity::maybe_record_read();
		Wormiss_Activity::maybe_record_read();
		$queued = $this->queued();
		$this->assertCount( 1, $queued );
		$this->assertSame( 'read', $queued[0]['args'][0]['kind'] );
		$this->assertSame( $members, $queued[0]['args'][0]['post_id'] );

		// A member who cannot read it (wrong type) is not recorded.
		update_post_meta( $members, Wormiss_Access::META_TYPES, 'Fellow' );
		Wormiss_Access::reset();
		$this->sign_in( '67890' );
		$this->go_to( get_permalink( $members ) );
		Wormiss_Activity::maybe_record_read();
		$this->assertCount( 1, $this->queued() );
	}

	/**
	 * Downloads are recorded only through a genuine (nonced) link, and always lead to the file.
	 *
	 * @return void
	 */
	public function test_downloads(): void {
		$this->turn_on();
		$this->sign_in();
		$file = self::factory()->attachment->create_object(
			'slides.pdf',
			0,
			[
				'post_mime_type' => 'application/pdf',
				'post_title'     => 'Course slides',
			]
		);

		$link = do_shortcode( '[wormiss_download id="' . $file . '"]Slides[/wormiss_download]' );
		$this->assertStringContainsString( 'wormiss_download=' . $file, $link );
		$this->assertStringContainsString( '_wpnonce=', $link );
		$this->assertStringContainsString( '>Slides</a>', $link );

		$this->assertNotSame( '', Wormiss_Activity::handle_download( $file, 'forged' ) );
		$this->assertSame( [], $this->queued() );

		$url = Wormiss_Activity::handle_download( $file, wp_create_nonce( 'wormiss_download_' . $file ) );
		$this->assertStringEndsWith( 'slides.pdf', $url );
		$this->assertCount( 1, $this->queued() );
		$this->assertSame( 'Course slides', $this->queued()[0]['args'][0]['description'] );

		$this->assertSame( '', Wormiss_Activity::handle_download( self::factory()->post->create(), 'x' ) );
	}

	/**
	 * Mark as done: sign-in link, button, one completion with the block's points, then "Done".
	 *
	 * @return void
	 */
	public function test_mark_as_done(): void {
		$this->turn_on();
		$post_id = self::factory()->post->create(
			[
				'post_title'   => 'Quiz',
				'post_content' => '<!-- wp:group --><div class="wp-block-group"><!-- wp:wormiss/complete '
					. '{"completionId":"abc-1","points":2.5,"activity":"Dermoscopy quiz"} /--></div><!-- /wp:group -->',
			]
		);
		$this->go_to( get_permalink( $post_id ) );
		$GLOBALS['post'] = get_post( $post_id ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Set up the loop.
		setup_postdata( $GLOBALS['post'] );

		$block = '<!-- wp:wormiss/complete {"completionId":"abc-1","points":2.5} /-->';
		$this->assertStringContainsString( 'Sign in to mark this as done.', do_blocks( $block ) );

		$this->sign_in();
		$form = do_blocks( $block );
		$this->assertStringContainsString( 'name="completion_id" value="abc-1"', $form );
		$this->assertStringContainsString( 'name="_wpnonce"', $form );
		$this->assertStringNotContainsString( '2.5', $form );

		$this->assertFalse( Wormiss_Completion::complete( $post_id, 'not-on-page' ) );
		$this->assertTrue( Wormiss_Completion::complete( $post_id, 'abc-1' ) );
		$this->assertFalse( Wormiss_Completion::complete( $post_id, 'abc-1' ) );

		$queued = $this->queued();
		$this->assertCount( 1, $queued );
		$this->assertSame( 'Dermoscopy quiz', $queued[0]['args'][0]['description'] );
		$this->assertSame( 2.5, $queued[0]['args'][0]['points'] );
		$this->assertStringContainsString( '✓ Done on ', do_blocks( $block ) );
		wp_reset_postdata();
	}

	/**
	 * A completion on a post the member cannot read is refused.
	 *
	 * @return void
	 */
	public function test_mark_as_done_needs_access(): void {
		$this->turn_on();
		$this->sign_in();
		$post_id = self::factory()->post->create(
			[ 'post_content' => '<!-- wp:wormiss/complete {"completionId":"abc-2"} /-->' ]
		);
		update_post_meta( $post_id, Wormiss_Access::META_ENABLED, true );
		update_post_meta( $post_id, Wormiss_Access::META_TYPES, 'Fellow' );
		$this->assertFalse( Wormiss_Completion::complete( $post_id, 'abc-2' ) );
		$this->assertSame( [], $this->queued() );
	}

	/**
	 * Form submissions are recorded when switched on; developers can record anything.
	 *
	 * @return void
	 */
	public function test_forms_and_developer_action(): void {
		$this->turn_on();
		$this->sign_in();
		do_action( 'gform_after_submission', [], [ 'title' => 'Feedback' ] );
		$this->assertSame( [], $this->queued() );

		$this->turn_on( false, [ 'record_forms' => true ] );
		do_action( 'gform_after_submission', [], [ 'title' => 'Feedback' ] );
		do_action( 'wpforms_process_complete', [], [], [ 'settings' => [ 'form_title' => 'Enquiry' ] ], 7 );
		do_action( 'wormiss_record_activity', 'webinar', 'Watched the webinar', [ 'points' => 1 ] );
		$kinds = array_map( static fn( $q ) => $q['args'][0]['kind'] . ':' . $q['args'][0]['description'], $this->queued() );
		sort( $kinds );
		$this->assertSame( [ 'form:Enquiry', 'form:Feedback', 'webinar:Watched the webinar' ], $kinds );
	}

	/**
	 * Erasing personal data removes a member's completions.
	 *
	 * @return void
	 */
	public function test_privacy_erase_removes_completions(): void {
		$user_id = $this->sign_in();
		update_user_meta( $user_id, Wormiss_Completion::meta_key( 5, 'abc-1' ), time() );
		$result = Wormiss_Plugin::erase_personal_data( get_userdata( $user_id )->user_email );
		$this->assertTrue( $result['items_removed'] );
		$this->assertSame( '', get_user_meta( $user_id, Wormiss_Completion::meta_key( 5, 'abc-1' ), true ) );
	}

	/**
	 * The activity switches survive WordPress's double first save, and unticked boxes are saved as off.
	 *
	 * @return void
	 */
	public function test_activity_settings_save(): void {
		$form  = [
			'activity_section' => '1',
			'activity_enabled' => '1',
			'activity_type'    => 'WEBACTIVITY',
		];
		$twice = Wormiss_Settings::sanitize( Wormiss_Settings::sanitize( $form ) );
		$this->assertTrue( $twice['activity_enabled'] );
		$this->assertFalse( $twice['activity_dry_run'] );
		$this->assertFalse( $twice['record_reads'] );
		$this->assertSame( 'WEBACTIVITY', $twice['activity_type'] );

		$bad = Wormiss_Settings::sanitize( [ 'activity_type' => 'WEB ACTIVITY;' ] );
		$this->assertSame( '', $bad['activity_type'] );
	}
}
