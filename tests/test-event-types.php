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
 * Tests for event types and their iMIS templates.
 *
 * @package    wormiss
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Event types: one iMIS template (and optional category) per type.
 *
 * @covers Wormiss_Event_Types
 * @covers Wormiss_Event_Sync
 */
class Test_Wormiss_Event_Types extends Wormiss_Test_Case {

	/**
	 * Register the taxonomy and post type (the test framework resets them), sign in an editor
	 * and turn sending on with a default template.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();
		Wormiss_Event_Types::register();
		Wormiss_Event_Sync::register();
		wp_unschedule_hook( Wormiss_Event_Sync::SYNC_HOOK );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );
		$this->turn_on( false );
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
					'event_template_id'  => 'DEFAULTTPL',
				]
			)
		);
	}

	/**
	 * An event type.
	 *
	 * @param string $name     Name.
	 * @param string $template Template event ID ('' for none).
	 * @param string $category Category ID ('' for none).
	 * @return int Term ID.
	 */
	private function type( string $name, string $template = '', string $category = '' ): int {
		$term_id = self::factory()->term->create(
			[
				'taxonomy' => Wormiss_Event_Types::TAXONOMY,
				'name'     => $name,
			]
		);
		update_term_meta( $term_id, Wormiss_Event_Types::META_TEMPLATE, $template );
		update_term_meta( $term_id, Wormiss_Event_Types::META_CATEGORY, $category );
		return $term_id;
	}

	/**
	 * A published event with its details and types.
	 *
	 * @param int[] $types Term IDs.
	 * @return int Post ID.
	 */
	private function event( array $types = [] ): int {
		$post_id = self::factory()->post->create(
			[
				'post_type'   => Wormiss_Event_Sync::POST_TYPE,
				'post_title'  => 'Dermoscopy workshop',
				'post_status' => 'publish',
			]
		);
		wp_set_object_terms( $post_id, $types, Wormiss_Event_Types::TAXONOMY );
		$meta = [
			Wormiss_Event_Sync::META_CODE  => 'DERM27',
			Wormiss_Event_Sync::META_START => '2027-05-01T09:00',
			Wormiss_Event_Sync::META_END   => '2027-05-01T17:00',
		];
		foreach ( $meta as $key => $value ) {
			update_post_meta( $post_id, $key, $value );
		}
		return $post_id;
	}

	/**
	 * The taxonomy, its settings, and cleaning of the IDs.
	 *
	 * @return void
	 */
	public function test_registered(): void {
		$this->assertTrue( taxonomy_exists( Wormiss_Event_Types::TAXONOMY ) );
		$this->assertContains( Wormiss_Event_Sync::POST_TYPE, get_taxonomy( Wormiss_Event_Types::TAXONOMY )->object_type );
		$this->assertTrue( registered_meta_key_exists( 'term', Wormiss_Event_Types::META_TEMPLATE, Wormiss_Event_Types::TAXONOMY ) );
		$this->assertSame( 'WKSHP-TPL', Wormiss_Event_Types::clean_id( ' wkshp-tpl ' ) );
		$this->assertSame( '', Wormiss_Event_Types::clean_id( 'not valid' ) );
	}

	/**
	 * The template comes from the type, else the default; two types is a problem to fix.
	 *
	 * @return void
	 */
	public function test_template_for_event(): void {
		$workshop = $this->type( 'Workshop', 'WKSHPTPL', 'WKSHP' );
		$plain    = $this->type( 'Meeting' );

		$this->assertSame( 'DEFAULTTPL', Wormiss_Event_Types::for_event( $this->event() )['template'] );

		$typed = Wormiss_Event_Types::for_event( $this->event( [ $workshop ] ) );
		$this->assertSame( 'WKSHPTPL', $typed['template'] );
		$this->assertSame( 'WKSHP', $typed['category'] );
		$this->assertSame( 'Workshop', $typed['type'] );

		$this->assertSame( 'DEFAULTTPL', Wormiss_Event_Types::for_event( $this->event( [ $plain ] ) )['template'] );

		$both = $this->event( [ $workshop, $plain ] );
		$this->assertNotSame( '', Wormiss_Event_Types::for_event( $both )['problem'] );
		$this->assertSame( 'error', Wormiss_Event_Sync::sync( $both ) );
		$this->assertStringContainsString( 'only one event type', get_post_meta( $both, Wormiss_Event_Sync::META_MESSAGE, true ) );
		$this->assertSame( [], $this->imis->requests );
	}

	/**
	 * A new event is copied from its type's template and given the type's category.
	 *
	 * @return void
	 */
	public function test_new_event_uses_type_template_and_category(): void {
		$post_id = $this->event( [ $this->type( 'Workshop', 'WKSHPTPL', 'WKSHP' ) ] );
		$this->imis->token()
			->reply( '/api/EventCategory/WKSHP', 200, [ 'EventCategoryId' => 'WKSHP' ] )
			->reply( '/api/Event/_execute', 200, [ 'EventId' => 'DERM27' ] )
			->reply(
				'/api/Event/DERM27',
				200,
				[
					'EventId'  => 'DERM27',
					'Category' => [
						'EventCategoryId' => 'OTHER',
						'Name'            => 'Other',
					],
				]
			)
			->reply( '/api/Event/DERM27', 200, '{}' );

		$this->assertSame( 'synced', Wormiss_Event_Sync::sync( $post_id ) );
		$clone = json_decode( (string) $this->imis->requests[2]['body'], true );
		$this->assertSame( 'WKSHPTPL', $clone['EventId'] );
		$saved = json_decode( (string) $this->imis->requests[4]['body'], true );
		$this->assertSame( 'WKSHP', $saved['Category']['EventCategoryId'] );
		$this->assertArrayNotHasKey( 'Name', $saved['Category'] );
	}

	/**
	 * A dry run checks the type's template, with the type's category.
	 *
	 * @return void
	 */
	public function test_dry_run_uses_type_template(): void {
		$this->turn_on( true );
		$post_id = $this->event( [ $this->type( 'Webinar', 'WEBTPL', 'ONLN' ) ] );
		$this->imis->token()
			->reply( '/api/EventCategory/ONLN', 200, [ 'EventCategoryId' => 'ONLN' ] )
			->reply( '/api/Event/WEBTPL', 200, [ 'EventId' => 'WEBTPL' ] )
			->reply( '/api/Event/_validate', 200, '{}' );
		$this->assertSame( 'dry_run', Wormiss_Event_Sync::sync( $post_id ) );
		$checked = json_decode( (string) $this->imis->requests[3]['body'], true );
		$this->assertSame( 'ONLN', $checked['Category']['EventCategoryId'] );
		$this->assertSame( 'DERM27', $checked['EventCode'] );
	}

	/**
	 * An event already in iMIS is still updated when it no longer has a template (no type, a
	 * type without one, and no default), with the new type's category.
	 *
	 * @return void
	 */
	public function test_update_needs_no_template(): void {
		update_option( Wormiss_Settings::OPTION, array_merge( Wormiss_Settings::all(), [ 'event_template_id' => '' ] ) );
		$post_id = $this->event( [ $this->type( 'Category only', '', 'SEMNR' ) ] );
		$this->assertSame( 'error', Wormiss_Event_Sync::sync( $post_id ) );
		$this->assertSame( [], $this->imis->requests );

		update_post_meta( $post_id, Wormiss_Event_Sync::META_IMIS_ID, 'DERM27' );
		$this->imis->token()
			->reply( '/api/EventCategory/SEMNR', 200, [ 'EventCategoryId' => 'SEMNR' ] )
			->reply( '/api/Event/DERM27', 200, [ 'EventId' => 'DERM27' ] )
			->reply( '/api/Event/DERM27', 200, '{}' );
		$this->assertSame( 'synced', Wormiss_Event_Sync::sync( $post_id ) );
		$saved = json_decode( (string) $this->imis->requests[3]['body'], true );
		$this->assertSame( 'SEMNR', $saved['Category']['EventCategoryId'] );
		$this->assertSame( 0, $this->imis->count( '/_execute' ) );
	}

	/**
	 * An event with no capacity of its own takes its type's default capacity.
	 *
	 * @return void
	 */
	public function test_default_capacity(): void {
		$term_id = $this->type( 'Workshop', 'WKSHPTPL' );
		update_term_meta( $term_id, Wormiss_Event_Types::META_CAPACITY, 40 );
		$post_id = $this->event( [ $term_id ] );
		$this->assertSame( 40, Wormiss_Event_Sync::details( get_post( $post_id ) )['capacity'] );

		update_post_meta( $post_id, Wormiss_Event_Sync::META_CAPACITY, 25 );
		$this->assertSame( 25, Wormiss_Event_Sync::details( get_post( $post_id ) )['capacity'] );

		$this->assertSame( 0, Wormiss_Event_Sync::details( get_post( $this->event() ) )['capacity'] );
	}

	/**
	 * Saving a type can create its event category in iMIS, with an ID made from its name when
	 * none is given; one that exists already is left alone.
	 *
	 * @return void
	 */
	public function test_create_category(): void {
		$this->assertSame( 'SKINC', Wormiss_Event_Types::category_id_from_name( 'Skin cancer workshops' ) );
		$term_id = $this->type( 'Skin cancer workshops' );
		$this->imis->token()
			->reply( '/api/EventCategory/SKINC', 404, '{"Message":"Not found"}' )
			->reply( '/api/EventCategory', 200, '{}' );
		$this->assertSame( 'created', Wormiss_Event_Types::create_category( $term_id ) );
		$this->assertSame( 'SKINC', get_term_meta( $term_id, Wormiss_Event_Types::META_CATEGORY, true ) );
		$body = json_decode( (string) $this->imis->requests[2]['body'], true );
		$this->assertSame( 'POST', $this->imis->requests[2]['method'] );
		$this->assertSame( 'SKINC', $body['EventCategoryId'] );
		$this->assertSame( 'Skin cancer workshops', $body['Name'] );
		$this->assertStringContainsString( 'was created', get_term_meta( $term_id, Wormiss_Event_Types::META_CATEGORY_STATUS, true ) );

		$this->imis->requests = [];
		$this->imis->token()->reply( '/api/EventCategory/SKINC', 200, '{"EventCategoryId":"SKINC"}' );
		$this->assertSame( 'exists', Wormiss_Event_Types::create_category( $term_id ) );
		$this->assertSame( 2, count( $this->imis->requests ) );
	}

	/**
	 * A dry run only checks and logs; with sending off nothing is contacted.
	 *
	 * @return void
	 */
	public function test_create_category_dry_run_and_off(): void {
		$this->turn_on( true );
		$term_id = $this->type( 'Webinar', '', 'WEBNR' );
		$this->imis->token()->reply( '/api/EventCategory/WEBNR', 404, '{}' );
		$this->assertSame( 'dry_run', Wormiss_Event_Types::create_category( $term_id ) );
		$this->assertSame( 2, count( $this->imis->requests ) );

		update_option( Wormiss_Settings::OPTION, array_merge( Wormiss_Settings::all(), [ 'event_sync_enabled' => false ] ) );
		$this->imis->requests = [];
		$this->assertSame( 'off', Wormiss_Event_Types::create_category( $term_id ) );
		$this->assertSame( [], $this->imis->requests );
	}

	/**
	 * The type form saves the capacity and, when ticked, creates the category.
	 *
	 * @return void
	 */
	public function test_form_capacity_and_create(): void {
		$term_id = $this->type( 'Seminar', '', 'SEMNR' );
		$this->imis->token()
			->reply( '/api/EventCategory/SEMNR', 404, '{}' )
			->reply( '/api/EventCategory', 200, '{}' );
		$_POST = [
			'wormiss_event_type_nonce'         => wp_create_nonce( Wormiss_Event_Types::NONCE ),
			Wormiss_Event_Types::META_CAPACITY => '60',
			Wormiss_Event_Types::FIELD_CREATE  => '1',
			Wormiss_Event_Types::META_CATEGORY => 'semnr',
		];
		Wormiss_Event_Types::save_fields( $term_id );
		$_POST = [];
		$this->assertSame( 60, (int) get_term_meta( $term_id, Wormiss_Event_Types::META_CAPACITY, true ) );
		$this->assertSame( 1, $this->imis->count( '/api/EventCategory/SEMNR' ) );
		$this->assertStringContainsString( 'was created', get_term_meta( $term_id, Wormiss_Event_Types::META_CATEGORY_STATUS, true ) );
	}

	/**
	 * The type form saves cleaned IDs with a valid nonce, and nothing without one.
	 *
	 * @return void
	 */
	public function test_save_fields(): void {
		$term_id = $this->type( 'Conference' );
		$_POST   = [
			Wormiss_Event_Types::META_TEMPLATE => ' conftpl ',
			Wormiss_Event_Types::META_CATEGORY => 'bad value',
		];
		Wormiss_Event_Types::save_fields( $term_id );
		$this->assertSame( '', get_term_meta( $term_id, Wormiss_Event_Types::META_TEMPLATE, true ) );

		$_POST['wormiss_event_type_nonce'] = wp_create_nonce( Wormiss_Event_Types::NONCE );
		Wormiss_Event_Types::save_fields( $term_id );
		$this->assertSame( 'CONFTPL', get_term_meta( $term_id, Wormiss_Event_Types::META_TEMPLATE, true ) );
		$this->assertSame( '', get_term_meta( $term_id, Wormiss_Event_Types::META_CATEGORY, true ) );
		$this->assertStringContainsString( 'was not kept', get_term_meta( $term_id, Wormiss_Event_Types::META_CATEGORY_STATUS, true ) );
		$_POST = [];

		$this->assertSame( 'CONFTPL', Wormiss_Event_Types::column( '', Wormiss_Event_Types::META_TEMPLATE, $term_id ) );
		$this->assertSame( 'Default', Wormiss_Event_Types::column( '', Wormiss_Event_Types::META_TEMPLATE, $this->type( 'Other' ) ) );
	}

	/**
	 * The iMIS category IDs are at most 5 characters: a longer one (iMIS fails the whole save with
	 * "HTTP 500") is not kept, and one saved before the limit stops the send with a clear reason.
	 *
	 * @return void
	 */
	public function test_category_limit(): void {
		$this->assertSame( 'COUR', Wormiss_Event_Types::clean_category( 'cour' ) );
		$this->assertSame( '', Wormiss_Event_Types::clean_category( 'COURSE' ) );
		$this->assertSame( 'SKINC', Wormiss_Event_Types::category_id_from_name( 'Skin cancer' ) );

		$term_id = $this->type( 'Course', 'CERTTPL' );
		$_POST   = [
			'wormiss_event_type_nonce'         => wp_create_nonce( Wormiss_Event_Types::NONCE ),
			Wormiss_Event_Types::META_CATEGORY => 'COURSE',
		];
		Wormiss_Event_Types::save_fields( $term_id );
		$_POST = [];
		$this->assertSame( '', get_term_meta( $term_id, Wormiss_Event_Types::META_CATEGORY, true ) );
		$this->assertStringContainsString( 'up to 5', get_term_meta( $term_id, Wormiss_Event_Types::META_CATEGORY_STATUS, true ) );

		// Ticking "create" with a rejected ID creates nothing and keeps the reason.
		$_POST = [
			'wormiss_event_type_nonce'         => wp_create_nonce( Wormiss_Event_Types::NONCE ),
			Wormiss_Event_Types::META_CATEGORY => 'COURSE',
			Wormiss_Event_Types::FIELD_CREATE  => '1',
		];
		Wormiss_Event_Types::save_fields( $term_id );
		$_POST = [];
		$this->assertSame( '', get_term_meta( $term_id, Wormiss_Event_Types::META_CATEGORY, true ) );
		$this->assertStringContainsString( 'was not kept', get_term_meta( $term_id, Wormiss_Event_Types::META_CATEGORY_STATUS, true ) );
		$this->assertSame( [], $this->imis->requests );

		// Saved before the limit was checked: stopped before anything is sent.
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Store an old value past the sanitizer.
		$wpdb->update(
			$wpdb->termmeta,
			[ 'meta_value' => 'COURSE' ], // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Test.
			[
				'term_id'  => $term_id,
				'meta_key' => Wormiss_Event_Types::META_CATEGORY, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Test.
			]
		);
		wp_cache_delete( $term_id, 'term_meta' );
		$post_id = $this->event( [ $term_id ] );
		$this->assertSame( 'error', Wormiss_Event_Sync::sync( $post_id ) );
		$this->assertStringContainsString( 'up to 5 characters', get_post_meta( $post_id, Wormiss_Event_Sync::META_MESSAGE, true ) );
		$this->assertSame( [], $this->imis->requests );
	}

	/**
	 * A category iMIS does not have stops the send before the template is copied.
	 *
	 * @return void
	 */
	public function test_missing_category(): void {
		$post_id = $this->event( [ $this->type( 'Course', 'CERTTPL', 'COUR5' ) ] );
		$this->imis->token()->reply( '/api/EventCategory/COUR5', 404, 'NotFound' );
		$this->assertSame( 'error', Wormiss_Event_Sync::sync( $post_id ) );
		$this->assertStringContainsString( 'iMIS has no event category COUR5', get_post_meta( $post_id, Wormiss_Event_Sync::META_MESSAGE, true ) );
		$this->assertSame( 0, $this->imis->count( '/_execute' ) );
		$this->assertSame( '', get_post_meta( $post_id, Wormiss_Event_Sync::META_IMIS_ID, true ) );
	}

	/**
	 * When iMIS will not let the API create categories (HTTP 501), the type says what to do.
	 *
	 * @return void
	 */
	public function test_create_category_not_allowed(): void {
		$term_id = $this->type( 'Course', '', 'COUR5' );
		$this->imis->token()
			->reply( '/api/EventCategory/COUR5', 404, 'NotFound' )
			->reply( '/api/EventCategory', 501, 'OperationNotAvailable' );
		$this->assertSame( 'error', Wormiss_Event_Types::create_category( $term_id ) );
		$this->assertStringContainsString( 'Create COUR5 in iMIS', get_term_meta( $term_id, Wormiss_Event_Types::META_CATEGORY_STATUS, true ) );
	}
}
