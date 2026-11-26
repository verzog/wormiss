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
 * Tests for the media-category taxonomy, the reserved parent, staff detection, auto-filing,
 * the attachment picker and the library filter.
 *
 * @package    wormiss
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Media categories (phase one).
 *
 * @covers Wormiss_Media_Categories
 */
class Test_Wormiss_Media_Categories extends Wormiss_Test_Case {

	/**
	 * Register the taxonomy; start with no stored staff-roles setting.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();
		Wormiss_Media_Categories::register();
	}

	/**
	 * A media category.
	 *
	 * @param string $name      Name.
	 * @param int    $parent_id Parent term ID.
	 * @return int
	 */
	private function category( string $name, int $parent_id = 0 ): int {
		return (int) self::factory()->term->create(
			[
				'taxonomy' => Wormiss_Media_Categories::TAXONOMY,
				'name'     => $name,
				'parent'   => $parent_id,
			]
		);
	}

	/**
	 * An attachment owned by the given user.
	 *
	 * @param int $author Author user ID.
	 * @return int
	 */
	private function attachment( int $author = 0 ): int {
		return (int) self::factory()->post->create(
			[
				'post_type'      => 'attachment',
				'post_mime_type' => 'image/jpeg',
				'post_author'    => $author,
			]
		);
	}

	/**
	 * The taxonomy is hierarchical and registered on attachments.
	 *
	 * @return void
	 */
	public function test_register(): void {
		$this->assertTrue( taxonomy_exists( Wormiss_Media_Categories::TAXONOMY ) );
		$this->assertTrue( is_object_in_taxonomy( 'attachment', Wormiss_Media_Categories::TAXONOMY ) );
		$taxonomy = get_taxonomy( Wormiss_Media_Categories::TAXONOMY );
		$this->assertTrue( $taxonomy->hierarchical );
	}

	/**
	 * The reserved parent is created once, remembered, and found again rather than duplicated.
	 *
	 * @return void
	 */
	public function test_reserved_term(): void {
		$id = Wormiss_Media_Categories::ensure_reserved_term();
		$this->assertGreaterThan( 0, $id );
		$term = get_term( $id, Wormiss_Media_Categories::TAXONOMY );
		$this->assertInstanceOf( WP_Term::class, $term );
		$this->assertSame( 'End-user images', $term->name );
		$this->assertSame( 0, (int) $term->parent );
		$this->assertSame( $id, (int) get_option( Wormiss_Media_Categories::RESERVED_OPTION ) );
		$this->assertSame( $id, Wormiss_Media_Categories::ensure_reserved_term(), 'Not duplicated.' );
		$this->assertSame( $id, Wormiss_Media_Categories::reserved_term_id() );
		$this->assertTrue( Wormiss_Media_Categories::is_reserved( $id ) );
		$this->assertFalse( Wormiss_Media_Categories::is_reserved( $this->category( 'Logos' ) ) );
	}

	/**
	 * No-one (not even an administrator) may delete the reserved parent; an ordinary category can be
	 * deleted by someone who manages categories.
	 *
	 * @return void
	 */
	public function test_protect_reserved(): void {
		$reserved = Wormiss_Media_Categories::reserved_term_id();
		$logos    = $this->category( 'Logos' );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$this->assertFalse( current_user_can( 'delete_term', $reserved ), 'Reserved parent is not deletable.' );
		$this->assertTrue( current_user_can( 'delete_term', $logos ), 'An ordinary category can be deleted.' );

		// The capability check also guards the admin delete action and the REST API.
		$caps = Wormiss_Media_Categories::protect_reserved( [ 'manage_categories' ], 'delete_term', get_current_user_id(), [ $reserved ] );
		$this->assertSame( [ 'do_not_allow' ], $caps );
		$this->assertSame( [ 'manage_categories' ], Wormiss_Media_Categories::protect_reserved( [ 'manage_categories' ], 'delete_term', get_current_user_id(), [ $logos ] ) );
	}

	/**
	 * Staff by default is administrators and a "staff" role; the setting and filter override it.
	 *
	 * @return void
	 */
	public function test_staff_roles(): void {
		$default = Wormiss_Media_Categories::staff_roles();
		$this->assertContains( 'administrator', $default );
		$this->assertContains( 'staff', $default );
		$this->assertNotContains( 'editor', $default );
		$this->assertNotContains( 'subscriber', $default );

		update_option( Wormiss_Settings::OPTION, array_merge( Wormiss_Settings::all(), [ 'media_staff_roles' => [ 'author' ] ] ) );
		$this->assertSame( [ 'author' ], Wormiss_Media_Categories::staff_roles() );

		$filter = static fn() => [ 'contributor' ];
		add_filter( 'wormiss_media_staff_roles', $filter );
		$this->assertSame( [ 'contributor' ], Wormiss_Media_Categories::staff_roles() );
		remove_filter( 'wormiss_media_staff_roles', $filter );
	}

	/**
	 * Administrators and users in the "staff" role are staff; editors (not in that role), subscribers
	 * and no-one are not.
	 *
	 * @return void
	 */
	public function test_is_staff(): void {
		add_role( 'staff', 'Staff', [ 'read' => true ] );
		$admin      = self::factory()->user->create( [ 'role' => 'administrator' ] );
		$staff      = self::factory()->user->create( [ 'role' => 'staff' ] );
		$editor     = self::factory()->user->create( [ 'role' => 'editor' ] );
		$subscriber = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		$this->assertTrue( Wormiss_Media_Categories::is_staff( $admin ) );
		$this->assertTrue( Wormiss_Media_Categories::is_staff( $staff ) );
		$this->assertFalse( Wormiss_Media_Categories::is_staff( $editor ), 'An editor not in the staff role is not staff.' );
		$this->assertFalse( Wormiss_Media_Categories::is_staff( $subscriber ) );
		$this->assertFalse( Wormiss_Media_Categories::is_staff( 0 ) );

		wp_set_current_user( $subscriber );
		$this->assertFalse( Wormiss_Media_Categories::is_staff() );
		wp_set_current_user( $staff );
		$this->assertTrue( Wormiss_Media_Categories::is_staff() );
	}

	/**
	 * A non-staff user's upload is filed into "End-user images"; staff uploads and system uploads
	 * (no current user) are left alone.
	 *
	 * @return void
	 */
	public function test_auto_file(): void {
		add_role( 'staff', 'Staff', [ 'read' => true ] );
		$reserved   = Wormiss_Media_Categories::reserved_term_id();
		$subscriber = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		$staffer    = self::factory()->user->create( [ 'role' => 'staff' ] );

		wp_set_current_user( $subscriber );
		$theirs = $this->attachment( $subscriber );
		Wormiss_Media_Categories::auto_file( $theirs );
		$this->assertSame(
			[ $reserved ],
			wp_get_object_terms( $theirs, Wormiss_Media_Categories::TAXONOMY, [ 'fields' => 'ids' ] ),
			'Non-staff upload filed.'
		);

		wp_set_current_user( $staffer );
		$staff = $this->attachment( $staffer );
		Wormiss_Media_Categories::auto_file( $staff );
		$this->assertSame( [], wp_get_object_terms( $staff, Wormiss_Media_Categories::TAXONOMY, [ 'fields' => 'ids' ] ), 'Staff upload left alone.' );

		wp_set_current_user( 0 );
		$system = $this->attachment( 0 );
		Wormiss_Media_Categories::auto_file( $system );
		$this->assertSame( [], wp_get_object_terms( $system, Wormiss_Media_Categories::TAXONOMY, [ 'fields' => 'ids' ] ), 'System upload left alone.' );
	}

	/**
	 * A non-image attachment uploaded by a non-staff user is still filed (end-user uploads as a group).
	 *
	 * @return void
	 */
	public function test_auto_file_any_type(): void {
		$reserved   = Wormiss_Media_Categories::reserved_term_id();
		$subscriber = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		wp_set_current_user( $subscriber );
		$pdf = (int) self::factory()->post->create(
			[
				'post_type'      => 'attachment',
				'post_mime_type' => 'application/pdf',
				'post_author'    => $subscriber,
			]
		);
		Wormiss_Media_Categories::auto_file( $pdf );
		$this->assertSame( [ $reserved ], wp_get_object_terms( $pdf, Wormiss_Media_Categories::TAXONOMY, [ 'fields' => 'ids' ] ) );
	}

	/**
	 * The library filter turns a chosen category into a tax query, and ignores a missing or unknown one.
	 *
	 * @return void
	 */
	public function test_filter_query(): void {
		$logos = $this->category( 'Logos' );

		$this->assertSame( [ 'foo' => 'bar' ], Wormiss_Media_Categories::filter_query( [ 'foo' => 'bar' ] ), 'No choice: unchanged.' );

		$_REQUEST['query'] = [ Wormiss_Media_Categories::TAXONOMY => (string) $logos ];
		$args              = Wormiss_Media_Categories::filter_query( [] );
		$this->assertArrayHasKey( 'tax_query', $args );
		$this->assertSame( Wormiss_Media_Categories::TAXONOMY, $args['tax_query'][0]['taxonomy'] );
		$this->assertSame( [ $logos ], $args['tax_query'][0]['terms'] );
		$this->assertTrue( $args['tax_query'][0]['include_children'] );

		$_REQUEST['query'] = [ Wormiss_Media_Categories::TAXONOMY => '999999' ];
		$this->assertArrayNotHasKey( 'tax_query', Wormiss_Media_Categories::filter_query( [] ), 'Unknown term ignored.' );

		$_REQUEST['query'] = [ Wormiss_Media_Categories::TAXONOMY => '' ];
		$this->assertSame( [], Wormiss_Media_Categories::filter_query( [] ), 'Empty choice ignored.' );

		unset( $_REQUEST['query'] );
	}

	/**
	 * The category list for the dropdown is in hierarchical order, sub-categories indented.
	 *
	 * @return void
	 */
	public function test_term_choices(): void {
		$events = $this->category( 'Event photos' );
		$this->category( '2026', $events );
		$this->category( 'Logos' );
		$names = array_column( Wormiss_Media_Categories::term_choices(), 'name' );
		$this->assertSame( [ 'Event photos', '— 2026', 'Logos' ], $names );
	}

	/**
	 * The picker saves every ticked category (keyed by term ID, as the modal posts them); a save with
	 * the marker but no ticks clears them; an unknown term is dropped; without the marker nothing
	 * changes.
	 *
	 * @return void
	 */
	public function test_save_picker(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );
		$logos  = $this->category( 'Logos' );
		$badges = $this->category( 'Badges' );
		$file   = $this->attachment();

		// The media modal posts one entry per ticked box, keyed by term ID.
		$saved = Wormiss_Media_Categories::save_picker(
			[ 'ID' => $file ],
			[
				Wormiss_Media_Categories::PICKER_FIELD . '_present' => '1',
				Wormiss_Media_Categories::PICKER_FIELD => [
					$logos  => (string) $logos,
					$badges => (string) $badges,
					999999  => '999999',
				],
			]
		);
		$this->assertSame( [ 'ID' => $file ], $saved );
		$this->assertSame(
			[ $badges, $logos ],
			wp_get_object_terms( $file, Wormiss_Media_Categories::TAXONOMY, [ 'fields' => 'ids' ] ),
			'Both categories kept, unknown dropped.'
		);

		Wormiss_Media_Categories::save_picker(
			[ 'ID' => $file ],
			[ Wormiss_Media_Categories::PICKER_FIELD . '_present' => '1' ]
		);
		$this->assertSame( [], wp_get_object_terms( $file, Wormiss_Media_Categories::TAXONOMY, [ 'fields' => 'ids' ] ), 'Cleared.' );

		wp_set_object_terms( $file, [ $logos ], Wormiss_Media_Categories::TAXONOMY );
		Wormiss_Media_Categories::save_picker( [ 'ID' => $file ], [ 'something_else' => '1' ] );
		$this->assertSame( [ $logos ], wp_get_object_terms( $file, Wormiss_Media_Categories::TAXONOMY, [ 'fields' => 'ids' ] ), 'No marker: untouched.' );
	}

	/**
	 * The picker field is offered to someone who may assign categories, under its own field key (not
	 * the taxonomy name, which core would preg_split), with a unique indexed name per checkbox.
	 *
	 * @return void
	 */
	public function test_picker_field(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );
		$logos  = $this->category( 'Logos' );
		$file   = $this->attachment();
		$fields = Wormiss_Media_Categories::picker_field( [], get_post( $file ) );
		$this->assertArrayHasKey( Wormiss_Media_Categories::PICKER_FIELD, $fields );
		$this->assertArrayNotHasKey( Wormiss_Media_Categories::TAXONOMY, $fields, 'Not keyed by the taxonomy name (core would preg_split it).' );
		$html = $fields[ Wormiss_Media_Categories::PICKER_FIELD ]['html'];
		$this->assertStringContainsString( 'Logos', $html );
		$this->assertStringContainsString( '_present', $html );
		// Unique indexed name per checkbox, so the modal's serializeArray() keeps all ticked boxes.
		$this->assertStringContainsString( '[' . Wormiss_Media_Categories::PICKER_FIELD . '][' . $logos . ']', $html );
		$this->assertStringNotContainsString( '[' . Wormiss_Media_Categories::TAXONOMY . '][]', $html );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );
		$this->assertArrayNotHasKey(
			Wormiss_Media_Categories::PICKER_FIELD,
			Wormiss_Media_Categories::picker_field( [], get_post( $file ) ),
			'Not offered to someone who cannot assign categories.'
		);
	}
}
