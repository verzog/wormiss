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
 * Tests for importing iMIS events as linked Event pages.
 *
 * @package    wormiss
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Upcoming iMIS events become linked draft Event pages; titles follow iMIS; nothing is removed.
 *
 * @covers Wormiss_Event_Import
 * @covers Wormiss_Event_Sync
 */
class Test_Wormiss_Event_Import extends Wormiss_Test_Case {

	/**
	 * Register the post type, sign in an administrator and turn importing on.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();
		Wormiss_Event_Types::register();
		Wormiss_Event_Sync::register();
		wp_unschedule_hook( Wormiss_Event_Sync::SYNC_HOOK );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$this->turn_on( true );
	}

	/**
	 * Turn importing (and sending events) on or off.
	 *
	 * @param bool $on Import on.
	 * @return void
	 */
	private function turn_on( bool $on ): void {
		update_option(
			Wormiss_Settings::OPTION,
			array_merge(
				Wormiss_Settings::all(),
				[
					'event_import_enabled' => $on,
					'event_sync_enabled'   => true,
					'event_sync_dry_run'   => false,
					'event_template_id'    => 'TEMPLATE',
				]
			)
		);
	}

	/**
	 * An iMIS event as the Events API lists it.
	 *
	 * @param string $id   EventId.
	 * @param array  $more Fields to add or replace.
	 * @return array
	 */
	private static function imis_event( string $id, array $more = [] ): array {
		return array_merge(
			[
				'EventId'       => $id,
				'EventCode'     => $id,
				'Name'          => 'Event ' . $id,
				'Description'   => '<p>About ' . $id . '.</p>',
				'StartDateTime' => gmdate( 'Y-m-d', time() + 30 * DAY_IN_SECONDS ) . 'T09:00:00',
				'EndDateTime'   => gmdate( 'Y-m-d', time() + 30 * DAY_IN_SECONDS ) . 'T17:00:00',
				'Status'        => 'A',
				'TemplateState' => 'None',
			],
			$more
		);
	}

	/**
	 * Serve a list of events.
	 *
	 * @param array[] $events Events.
	 * @return void
	 */
	private function serve( array $events ): void {
		$this->imis->token()->reply( '/api/Event?', 200, [ 'Items' => [ '$values' => $events ] ] );
	}

	/**
	 * The Event pages linked to an iMIS event.
	 *
	 * @param string $id iMIS event ID.
	 * @return int[]
	 */
	private static function pages( string $id ): array {
		return get_posts(
			[
				'post_type'   => Wormiss_Event_Sync::POST_TYPE,
				'post_status' => [ 'publish', 'draft', 'trash' ],
				'meta_key'    => Wormiss_Event_Sync::META_IMIS_ID, // phpcs:ignore WordPress.DB.SlowDBQuery
				'meta_value'  => $id, // phpcs:ignore WordPress.DB.SlowDBQuery
				'fields'      => 'ids',
			]
		);
	}

	/**
	 * Off by default: nothing is read.
	 *
	 * @return void
	 */
	public function test_off(): void {
		$this->assertFalse( Wormiss_Settings::defaults()['event_import_enabled'] );
		$this->turn_on( false );
		$this->assertSame( 'off', Wormiss_Event_Import::run()['status'] );
		$this->assertSame( 0, count( $this->imis->requests ) );
	}

	/**
	 * Upcoming real events become linked drafts with iMIS's name and description; templates and
	 * finished events do not; nothing is sent to iMIS.
	 *
	 * @return void
	 */
	public function test_imports_drafts(): void {
		$this->serve(
			[
				self::imis_event( 'CONGRESS26' ),
				self::imis_event( 'DERM_FEB26' ),
				self::imis_event( 'TPL', [ 'TemplateState' => 'StandardTemplate' ] ),
				self::imis_event(
					'OLD25',
					[
						'StartDateTime' => '2020-01-01T09:00:00',
						'EndDateTime'   => '2020-01-01T17:00:00',
					]
				),
			]
		);
		$report = Wormiss_Event_Import::run();
		$this->assertSame( 2, $report['read'] );
		$this->assertSame( 2, $report['created'] );

		$pages = self::pages( 'CONGRESS26' );
		$this->assertCount( 1, $pages );
		$post = get_post( $pages[0] );
		$this->assertSame( 'draft', $post->post_status );
		$this->assertSame( 'Event CONGRESS26', $post->post_title );
		$this->assertStringContainsString( 'About CONGRESS26.', $post->post_content );
		$this->assertTrue( Wormiss_Event_Sync::is_linked( $post->ID ) );
		$this->assertSame( 'CONGRESS26', get_post_meta( $post->ID, Wormiss_Event_Sync::META_LINKED_DETAILS, true )['id'] );
		$this->assertFalse( wp_next_scheduled( Wormiss_Event_Sync::SYNC_HOOK, [ $post->ID ] ) );
		$this->assertCount( 0, self::pages( 'TPL' ) );
		$this->assertCount( 0, self::pages( 'OLD25' ) );
		$this->assertSame( 0, $this->imis->count( '/_execute' ) );
		$this->assertStringContainsString( '2 draft pages made', Wormiss_Event_Import::summary() );
	}

	/**
	 * Later imports keep titles in step and make no second page; trashed pages and pages
	 * WordPress sent to iMIS are left alone.
	 *
	 * @return void
	 */
	public function test_updates_and_leaves_alone(): void {
		$this->serve( [ self::imis_event( 'CONGRESS26' ), self::imis_event( 'CONF27' ), self::imis_event( 'GONE26' ) ] );
		Wormiss_Event_Import::run();
		wp_trash_post( self::pages( 'GONE26' )[0] );
		// CONF27's page was made in WordPress and sent to iMIS: it is not a linked page.
		$conf = self::pages( 'CONF27' )[0];
		delete_post_meta( $conf, Wormiss_Event_Sync::META_LINKED );
		delete_post_meta( $conf, Wormiss_Event_Sync::META_EXISTING );
		wp_update_post(
			[
				'ID'         => $conf,
				'post_title' => 'Our conference',
			]
		);

		$this->serve(
			[
				self::imis_event( 'CONGRESS26', [ 'Name' => 'Congress 2026' ] ),
				self::imis_event( 'CONF27', [ 'Name' => 'Renamed in iMIS' ] ),
				self::imis_event( 'GONE26' ),
			]
		);
		$report = Wormiss_Event_Import::run();
		$this->assertSame( 0, $report['created'] );
		$this->assertSame( 1, $report['updated'] );
		$this->assertCount( 1, self::pages( 'CONGRESS26' ) );
		$this->assertSame( 'Congress 2026', get_the_title( self::pages( 'CONGRESS26' )[0] ) );
		$this->assertSame( 'Our conference', get_the_title( $conf ) );
		$this->assertSame( 'trash', get_post_status( self::pages( 'GONE26' )[0] ) );
	}

	/**
	 * When iMIS cannot be read, nothing changes and the reason is shown.
	 *
	 * @return void
	 */
	public function test_failure(): void {
		$this->imis->token()->reply( '/api/Event?', 500, '{"Message":"An error occurred."}' );
		$report = Wormiss_Event_Import::run();
		$this->assertSame( 'error', $report['status'] );
		$this->assertCount(
			0,
			get_posts(
				[
					'post_type'   => Wormiss_Event_Sync::POST_TYPE,
					'post_status' => 'any',
				]
			)
		);
		$this->assertStringContainsString( 'could not be read', Wormiss_Event_Import::summary() );
	}

	/**
	 * Linking by hand falls back to the events list when iMIS cannot read the event on its own.
	 *
	 * @return void
	 */
	public function test_link_falls_back_to_list(): void {
		$post_id = self::factory()->post->create(
			[
				'post_type'   => Wormiss_Event_Sync::POST_TYPE,
				'post_status' => 'publish',
			]
		);
		update_post_meta( $post_id, Wormiss_Event_Sync::META_EXISTING, 'CONGRESS26' );
		$this->imis->token()->reply( '/api/Event/CONGRESS26', 500, '{"Message":"An error occurred."}' );
		$this->serve( [ self::imis_event( 'CONGRESS26' ) ] );
		$this->assertSame( 'linked', Wormiss_Event_Sync::maybe_link( $post_id, get_post( $post_id ) ) );
		$this->assertSame( 'CONGRESS26', get_post_meta( $post_id, Wormiss_Event_Sync::META_IMIS_ID, true ) );
	}

	/**
	 * The setting is saved with its section.
	 *
	 * @return void
	 */
	public function test_setting(): void {
		$this->assertTrue(
			Wormiss_Settings::sanitize(
				[
					'event_import_section' => '1',
					'event_import_enabled' => '1',
				]
			)['event_import_enabled']
		);
		$this->assertFalse( Wormiss_Settings::sanitize( [ 'event_import_section' => '1' ] )['event_import_enabled'] );
	}

	/**
	 * With iMIS down, an earlier copy of the events list is never imported.
	 *
	 * @return void
	 */
	public function test_never_imports_an_old_copy(): void {
		$old = Wormiss_Events::normalise( json_decode( (string) wp_json_encode( self::imis_event( 'OLDCOPY' ) ) ) );
		set_transient( Wormiss_Events::cache_key() . '_stale', [ $old ], HOUR_IN_SECONDS );
		$this->imis->token()->reply( '/api/Event?', 500, '{"Message":"An error occurred."}' );
		$this->assertSame( 'error', Wormiss_Event_Import::run()['status'] );
		$this->assertCount( 0, self::pages( 'OLDCOPY' ) );
	}

	/**
	 * Only one import runs at a time; a lock left by a run that died is taken over.
	 *
	 * @return void
	 */
	public function test_one_import_at_a_time(): void {
		add_option( Wormiss_Event_Import::LOCK_OPTION, (string) time(), '', false );
		$this->assertSame( 'busy', Wormiss_Event_Import::run()['status'] );
		$this->assertSame( 0, count( $this->imis->requests ) );

		update_option( Wormiss_Event_Import::LOCK_OPTION, (string) ( time() - Wormiss_Event_Import::LOCK_SECONDS - 5 ), false );
		$this->serve( [ self::imis_event( 'CONGRESS26' ) ] );
		$this->assertSame( 'ok', Wormiss_Event_Import::run()['status'] );
		$this->assertFalse( get_option( Wormiss_Event_Import::LOCK_OPTION ) );
	}

	/**
	 * Backslashes in iMIS names and descriptions are kept, and an unchanged name is not seen as a change.
	 *
	 * @return void
	 */
	public function test_backslashes_kept(): void {
		$event = self::imis_event(
			'SLASH26',
			[
				'Name'        => 'C:\Training',
				'Description' => 'Path C:\Files',
			]
		);
		$this->serve( [ $event ] );
		Wormiss_Event_Import::run();
		$post = get_post( self::pages( 'SLASH26' )[0] );
		$this->assertSame( 'C:\Training', $post->post_title );
		$this->assertStringContainsString( 'C:\Files', $post->post_content );
		$this->assertSame( 'C:\Training', get_post_meta( $post->ID, Wormiss_Event_Sync::META_LINKED_DETAILS, true )['name'] );

		$this->serve( [ $event ] );
		$this->assertSame( 0, Wormiss_Event_Import::run()['updated'] );
	}

	/**
	 * With a past-events date, finished events since then come in too (expired ones included);
	 * earlier ones, templates and unlisted statuses do not.
	 *
	 * @return void
	 */
	public function test_past_events(): void {
		update_option( Wormiss_Settings::OPTION, array_merge( Wormiss_Settings::all(), [ 'event_import_since' => '2024-01-01' ] ) );
		$past   = static fn( string $id, string $date, array $more = [] ) => self::imis_event(
			$id,
			array_merge(
				[
					'StartDateTime' => $date . 'T09:00:00',
					'EndDateTime'   => $date . 'T17:00:00',
				],
				$more
			)
		);
		$this->serve(
			[
				self::imis_event( 'NEXT26' ),
				$past( 'PAST25', '2025-03-01', [ 'PublishingInformation' => [ 'PublishingState' => 'Expired' ] ] ),
				$past( 'OLD23', '2023-06-01' ),
				$past( 'TPL25', '2025-03-01', [ 'TemplateState' => 'StandardTemplate' ] ),
				$past( 'PEND25', '2025-03-01', [ 'Status' => 'P' ] ),
			]
		);
		$report = Wormiss_Event_Import::run();
		$this->assertSame( 2, $report['created'] );
		$this->assertStringContainsString( 'StartDateTime=ge%3A2024-01-01', $this->imis->requests[1]['url'] );
		$this->assertCount( 1, self::pages( 'NEXT26' ) );
		$this->assertCount( 1, self::pages( 'PAST25' ) );
		$this->assertCount( 0, self::pages( 'OLD23' ) );
		$this->assertCount( 0, self::pages( 'TPL25' ) );
		$this->assertCount( 0, self::pages( 'PEND25' ) );
	}

	/**
	 * The past-events date must be a real date, not in the future.
	 *
	 * @return void
	 */
	public function test_since_setting(): void {
		$this->assertSame( '2024-01-01', Wormiss_Settings::sanitize( [ 'event_import_since' => '2024-01-01' ] )['event_import_since'] );
		$this->assertSame( '', Wormiss_Settings::sanitize( [ 'event_import_since' => '' ] )['event_import_since'] );
		$this->assertSame( '', Wormiss_Settings::sanitize( [ 'event_import_since' => '2024-02-30' ] )['event_import_since'] );
		$this->assertSame( '', Wormiss_Settings::sanitize( [ 'event_import_since' => '2999-01-01' ] )['event_import_since'] );
	}

	/**
	 * A recent past-events date still imports events already under way.
	 *
	 * @return void
	 */
	public function test_recent_date_keeps_ongoing_events(): void {
		update_option( Wormiss_Settings::OPTION, array_merge( Wormiss_Settings::all(), [ 'event_import_since' => gmdate( 'Y-m-d' ) ] ) );
		$this->serve(
			[
				self::imis_event(
					'ONGOING',
					[
						'StartDateTime' => gmdate( 'Y-m-d', time() - 2 * DAY_IN_SECONDS ) . 'T09:00:00',
						'EndDateTime'   => gmdate( 'Y-m-d', time() + 2 * DAY_IN_SECONDS ) . 'T17:00:00',
					]
				),
			]
		);
		$this->assertSame( 1, Wormiss_Event_Import::run()['created'] );
		$lookback = wp_date( 'Y-m-d', time() - Wormiss_Events::LOOKBACK_DAYS * DAY_IN_SECONDS );
		$this->assertStringContainsString( rawurlencode( 'ge:' . $lookback ), $this->imis->requests[1]['url'] );
	}

	/**
	 * A past-events read goes past the usual 1,000-event cap.
	 *
	 * @return void
	 */
	public function test_history_reads_past_the_cap(): void {
		$this->assertGreaterThan( Wormiss_Rest_Client::MAX_EVENTS, Wormiss_Event_Import::MAX_READ );
		update_option( Wormiss_Settings::OPTION, array_merge( Wormiss_Settings::all(), [ 'event_import_since' => '2024-01-01' ] ) );
		$this->imis->token()
			->reply(
				'/api/Event?',
				200,
				[
					'Items'      => [ '$values' => [ self::imis_event( 'PAGE1' ) ] ],
					'HasNext'    => true,
					'NextOffset' => 100,
				]
			)
			->reply( '/api/Event?', 200, [ 'Items' => [ '$values' => [ self::imis_event( 'PAGE2' ) ] ] ] );
		$this->assertSame( 2, Wormiss_Event_Import::run()['created'] );
		$this->assertStringContainsString( 'limit=100&offset=100', $this->imis->requests[2]['url'] );
	}

	/**
	 * With publishing on, new pages go live and the import's earlier drafts are published; a page
	 * an editor unpublished afterwards stays a draft.
	 *
	 * @return void
	 */
	public function test_publishing(): void {
		$this->serve( [ self::imis_event( 'EARLY26' ) ] );
		Wormiss_Event_Import::run();
		$early = self::pages( 'EARLY26' )[0];
		$this->assertSame( 'draft', get_post_status( $early ) );

		update_option( Wormiss_Settings::OPTION, array_merge( Wormiss_Settings::all(), [ 'event_import_publish' => true ] ) );
		$this->serve( [ self::imis_event( 'EARLY26' ), self::imis_event( 'NEW26' ) ] );
		Wormiss_Event_Import::run();
		$this->assertSame( 'publish', get_post_status( $early ) );
		$this->assertSame( 'publish', get_post_status( self::pages( 'NEW26' )[0] ) );
		$this->assertFalse( wp_next_scheduled( Wormiss_Event_Sync::SYNC_HOOK, [ $early ] ) );

		wp_update_post(
			[
				'ID'          => $early,
				'post_status' => 'draft',
			]
		);
		$this->serve( [ self::imis_event( 'EARLY26' ), self::imis_event( 'NEW26' ) ] );
		Wormiss_Event_Import::run();
		$this->assertSame( 'draft', get_post_status( $early ) );
	}

	/**
	 * The full iMIS description (formatting kept, scripts removed) becomes the page text and
	 * follows iMIS when it changes there; an editor's change stands until iMIS changes.
	 *
	 * @return void
	 */
	public function test_description_follows_imis(): void {
		$html = '<p>Two days of <strong>talks</strong>.</p><script>alert(1)</script>';
		$this->serve( [ self::imis_event( 'DESC26', [ 'Description' => $html ] ) ] );
		Wormiss_Event_Import::run();
		$post_id = self::pages( 'DESC26' )[0];
		$content = get_post_field( 'post_content', $post_id );
		$this->assertStringContainsString( '<strong>talks</strong>', $content );
		$this->assertStringNotContainsString( '<script>', $content );

		wp_update_post(
			[
				'ID'           => $post_id,
				'post_content' => 'Edited here.',
			]
		);
		$this->serve( [ self::imis_event( 'DESC26', [ 'Description' => $html ] ) ] );
		Wormiss_Event_Import::run();
		$this->assertSame( 'Edited here.', get_post_field( 'post_content', $post_id ) );

		$this->serve( [ self::imis_event( 'DESC26', [ 'Description' => '<p>New programme.</p>' ] ) ] );
		Wormiss_Event_Import::run();
		$this->assertSame( '<p>New programme.</p>', get_post_field( 'post_content', $post_id ) );
	}

	/**
	 * The iMIS category becomes the page's event type, made once with the category's ID.
	 *
	 * @return void
	 */
	public function test_event_type_from_category(): void {
		$category = [
			'Category' => [
				'EventCategoryId' => 'M/L',
				'Name'            => 'Masterclass',
			],
		];
		$this->serve( [ self::imis_event( 'ML1', $category ), self::imis_event( 'ML2', $category ) ] );
		Wormiss_Event_Import::run();
		$term = get_term_by( 'name', 'Masterclass', Wormiss_Event_Types::TAXONOMY );
		$this->assertInstanceOf( WP_Term::class, $term );
		$this->assertSame( 'M/L', get_term_meta( $term->term_id, Wormiss_Event_Types::META_CATEGORY, true ) );
		foreach ( [ 'ML1', 'ML2' ] as $id ) {
			$this->assertSame( [ $term->term_id ], wp_get_object_terms( self::pages( $id )[0], Wormiss_Event_Types::TAXONOMY, [ 'fields' => 'ids' ] ) );
		}
		$this->assertCount(
			1,
			get_terms(
				[
					'taxonomy'   => Wormiss_Event_Types::TAXONOMY,
					'hide_empty' => false,
				]
			)
		);
	}

	/**
	 * The iMIS event image becomes the featured image, downloaded once.
	 *
	 * @return void
	 */
	public function test_featured_image(): void {
		$png       = base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- A test image.
		$downloads = 0;
		$image     = static function ( $pre, $args, $url ) use ( $png, &$downloads ) {
			if ( false === strpos( $url, '/images/ev1.png' ) ) {
				return $pre;
			}
			++$downloads;
			if ( ! empty( $args['filename'] ) ) {
				file_put_contents( $args['filename'], $png ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- The download's temporary file.
			}
			return [
				'headers'  => [ 'content-type' => 'image/png' ],
				'body'     => '',
				'response' => [
					'code'    => 200,
					'message' => 'OK',
				],
				'cookies'  => [],
				'filename' => $args['filename'] ?? null,
			];
		};
		// After the fake iMIS, which answers every request it was not told about with an error.
		add_filter( 'pre_http_request', $image, 20, 3 );
		$this->serve( [ self::imis_event( 'IMG26', [ 'ImageUrl' => '~/images/ev1.png' ] ) ] );
		$report  = Wormiss_Event_Import::run();
		$post_id = self::pages( 'IMG26' )[0];
		$this->assertTrue( has_post_thumbnail( $post_id ), implode( ' ', $report['notes'] ) );

		$this->serve( [ self::imis_event( 'IMG26', [ 'ImageUrl' => '~/images/ev1.png' ] ) ] );
		Wormiss_Event_Import::run();
		$this->assertSame( 1, $downloads );
		remove_filter( 'pre_http_request', $image, 20 );

		// Taken off the event in iMIS: taken off the page.
		$this->serve( [ self::imis_event( 'IMG26' ) ] );
		Wormiss_Event_Import::run();
		$this->assertFalse( has_post_thumbnail( $post_id ) );
	}

	/**
	 * An image an editor chose instead is kept when iMIS drops the event's image.
	 *
	 * @return void
	 */
	public function test_editors_image_kept(): void {
		$this->serve( [ self::imis_event( 'OWN26' ) ] );
		Wormiss_Event_Import::run();
		$post_id = self::pages( 'OWN26' )[0];
		$own     = self::factory()->attachment->create_object( 'own.jpg', $post_id, [ 'post_mime_type' => 'image/jpeg' ] );
		set_post_thumbnail( $post_id, $own );
		update_post_meta( $post_id, Wormiss_Event_Import::META_IMAGE, 'https://imis.example.org/images/old.png' );
		update_post_meta( $post_id, Wormiss_Event_Import::META_IMAGE_ID, $own + 1000 );
		$this->serve( [ self::imis_event( 'OWN26' ) ] );
		Wormiss_Event_Import::run();
		$this->assertSame( $own, get_post_thumbnail_id( $post_id ) );
	}

	/**
	 * A page an editor published by hand and later took down is not published again.
	 *
	 * @return void
	 */
	public function test_hand_published_page_stays_down(): void {
		$this->serve( [ self::imis_event( 'HAND26' ) ] );
		Wormiss_Event_Import::run();
		$post_id = self::pages( 'HAND26' )[0];
		wp_publish_post( $post_id );
		wp_update_post(
			[
				'ID'          => $post_id,
				'post_status' => 'draft',
			]
		);
		update_option( Wormiss_Settings::OPTION, array_merge( Wormiss_Settings::all(), [ 'event_import_publish' => true ] ) );
		$this->serve( [ self::imis_event( 'HAND26' ) ] );
		Wormiss_Event_Import::run();
		$this->assertSame( 'draft', get_post_status( $post_id ) );
	}

	/**
	 * A linked page shows when registration closes, while it is still to come.
	 *
	 * @return void
	 */
	public function test_registration_closes_shown(): void {
		$closes = gmdate( 'Y-m-d', time() + 20 * DAY_IN_SECONDS ) . 'T17:00:00';
		update_option( Wormiss_Settings::OPTION, array_merge( Wormiss_Settings::all(), [ 'event_import_publish' => true ] ) );
		$this->serve( [ self::imis_event( 'REG26', [ 'RegistrationEndDate' => $closes ] ) ] );
		Wormiss_Event_Import::run();
		$post_id = self::pages( 'REG26' )[0];
		$this->serve( [ self::imis_event( 'REG26', [ 'RegistrationEndDate' => $closes ] ) ] );

		$this->go_to( get_permalink( $post_id ) );
		$GLOBALS['wp_query']->the_post();
		$html = apply_filters( 'the_content', get_the_content() );
		wp_reset_postdata();
		$this->assertStringContainsString( '<dt>Registration closes</dt>', $html );
	}
}
