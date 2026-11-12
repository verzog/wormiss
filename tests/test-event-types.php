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
		$workshop = $this->type( 'Workshop', 'WKSHPTPL', 'WORKSHOP' );
		$plain    = $this->type( 'Meeting' );

		$this->assertSame( 'DEFAULTTPL', Wormiss_Event_Types::for_event( $this->event() )['template'] );

		$typed = Wormiss_Event_Types::for_event( $this->event( [ $workshop ] ) );
		$this->assertSame( 'WKSHPTPL', $typed['template'] );
		$this->assertSame( 'WORKSHOP', $typed['category'] );
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
		$post_id = $this->event( [ $this->type( 'Workshop', 'WKSHPTPL', 'WORKSHOP' ) ] );
		$this->imis->token()
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
		$clone = json_decode( (string) $this->imis->requests[1]['body'], true );
		$this->assertSame( 'WKSHPTPL', $clone['EventId'] );
		$saved = json_decode( (string) $this->imis->requests[3]['body'], true );
		$this->assertSame( 'WORKSHOP', $saved['Category']['EventCategoryId'] );
		$this->assertArrayNotHasKey( 'Name', $saved['Category'] );
	}

	/**
	 * A dry run checks the type's template, with the type's category.
	 *
	 * @return void
	 */
	public function test_dry_run_uses_type_template(): void {
		$this->turn_on( true );
		$post_id = $this->event( [ $this->type( 'Webinar', 'WEBTPL', 'ONLINE' ) ] );
		$this->imis->token()
			->reply( '/api/Event/WEBTPL', 200, [ 'EventId' => 'WEBTPL' ] )
			->reply( '/api/Event/_validate', 200, '{}' );
		$this->assertSame( 'dry_run', Wormiss_Event_Sync::sync( $post_id ) );
		$checked = json_decode( (string) $this->imis->requests[2]['body'], true );
		$this->assertSame( 'ONLINE', $checked['Category']['EventCategoryId'] );
		$this->assertSame( 'DERM27', $checked['EventCode'] );
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
		$_POST = [];

		$this->assertSame( 'CONFTPL', Wormiss_Event_Types::column( '', Wormiss_Event_Types::META_TEMPLATE, $term_id ) );
		$this->assertSame( 'Default', Wormiss_Event_Types::column( '', Wormiss_Event_Types::META_TEMPLATE, $this->type( 'Other' ) ) );
	}
}
