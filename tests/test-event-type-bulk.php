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
 * Tests for the Events list's bulk event type actions.
 *
 * @package    wormiss
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * "Add to event type" and "Remove from event type" in Bulk actions.
 *
 * @covers Wormiss_Event_Type_Bulk
 */
class Test_Wormiss_Event_Type_Bulk extends Wormiss_Test_Case {

	/**
	 * Register the taxonomy and post type; sign in an editor.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();
		Wormiss_Event_Types::register();
		Wormiss_Event_Sync::register();
		wp_unschedule_hook( Wormiss_Event_Sync::SYNC_HOOK );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );
	}

	/**
	 * An event type.
	 *
	 * @param string $name   Name.
	 * @param int    $main   Main type's term ID.
	 * @return int
	 */
	private function type( string $name, int $main = 0 ): int {
		return (int) self::factory()->term->create(
			[
				'taxonomy' => Wormiss_Event_Types::TAXONOMY,
				'name'     => $name,
				'parent'   => $main,
			]
		);
	}

	/**
	 * The groups list every type, sub-types under their main type; nobody without the right
	 * to assign types gets them.
	 *
	 * @return void
	 */
	public function test_actions(): void {
		$courses  = $this->type( 'Courses' );
		$advanced = $this->type( 'Advanced', $courses );
		$this->type( 'Essential', $courses );
		$actions = Wormiss_Event_Type_Bulk::actions( [ 'edit' => 'Edit' ] );
		$this->assertSame( 'Edit', $actions['edit'] );
		$add = $actions['Add to event type'];
		$this->assertSame( [ 'Courses', '— Advanced', '— Essential' ], array_values( $add ) );
		$this->assertArrayHasKey( Wormiss_Event_Type_Bulk::REMOVE . $advanced, $actions['Remove from event type'] );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );
		$this->assertSame( [ 'edit' => 'Edit' ], Wormiss_Event_Type_Bulk::actions( [ 'edit' => 'Edit' ] ) );
	}

	/**
	 * Adding keeps the event's other types; removing takes only that one off; other posts and
	 * other actions are left alone.
	 *
	 * @return void
	 */
	public function test_handle(): void {
		$courses   = $this->type( 'Courses' );
		$essential = $this->type( 'Essential', $courses );
		$event     = self::factory()->post->create( [ 'post_type' => 'wormiss_event' ] );
		$other     = self::factory()->post->create( [ 'post_type' => 'wormiss_event' ] );
		$page      = self::factory()->post->create( [ 'post_type' => 'page' ] );
		wp_set_object_terms( $event, [ $courses ], Wormiss_Event_Types::TAXONOMY );

		$back = Wormiss_Event_Type_Bulk::handle( 'https://example.org/wp-admin/edit.php?post_type=wormiss_event', Wormiss_Event_Type_Bulk::ADD . $essential, [ $event, $other, $page ] );
		$this->assertStringContainsString( 'wormiss_type_bulk_n=2', $back );
		$this->assertEqualsCanonicalizing( [ $courses, $essential ], wp_get_object_terms( $event, Wormiss_Event_Types::TAXONOMY, [ 'fields' => 'ids' ] ) );
		$this->assertSame( [ $essential ], wp_get_object_terms( $other, Wormiss_Event_Types::TAXONOMY, [ 'fields' => 'ids' ] ) );
		$this->assertSame( [], wp_get_object_terms( $page, Wormiss_Event_Types::TAXONOMY, [ 'fields' => 'ids' ] ), 'Not an event.' );

		Wormiss_Event_Type_Bulk::handle( '', Wormiss_Event_Type_Bulk::REMOVE . $essential, [ $event ] );
		$this->assertSame( [ $courses ], wp_get_object_terms( $event, Wormiss_Event_Types::TAXONOMY, [ 'fields' => 'ids' ] ) );

		$this->assertSame( 'back', Wormiss_Event_Type_Bulk::handle( 'back', 'trash', [ $event ] ), 'Other actions are not ours.' );
		$this->assertSame( 'back', Wormiss_Event_Type_Bulk::handle( 'back', Wormiss_Event_Type_Bulk::ADD . '999999', [ $event ] ), 'No such type.' );
	}
}
