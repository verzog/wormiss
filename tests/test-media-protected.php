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
 * Tests for the protected-delivery category flag.
 *
 * @package    wormiss
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Protected-delivery flag (media spec phase 4, part one).
 *
 * @covers Wormiss_Media_Protected
 */
class Test_Wormiss_Media_Protected extends Wormiss_Test_Case {

	/**
	 * Register the taxonomy and the term meta.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();
		Wormiss_Media_Categories::register();
		Wormiss_Media_Protected::register_meta();
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
	 * An attachment in the given categories.
	 *
	 * @param int[] $term_ids Category term IDs.
	 * @return int
	 */
	private function attachment( array $term_ids = [] ): int {
		$id = (int) self::factory()->post->create(
			[
				'post_type'      => 'attachment',
				'post_mime_type' => 'image/jpeg',
				'post_status'    => 'inherit',
			]
		);
		if ( $term_ids ) {
			wp_set_object_terms( $id, $term_ids, Wormiss_Media_Categories::TAXONOMY );
		}
		return $id;
	}

	/**
	 * The flag defaults off and can be set; protected_term_ids lists the flagged categories.
	 *
	 * @return void
	 */
	public function test_flag(): void {
		$blog  = $this->category( 'Member blog' );
		$logos = $this->category( 'Logos' );
		$this->assertFalse( Wormiss_Media_Protected::is_protected( $blog ) );

		update_term_meta( $blog, Wormiss_Media_Protected::META_PROTECTED, 1 );
		$this->assertTrue( Wormiss_Media_Protected::is_protected( $blog ) );
		$this->assertFalse( Wormiss_Media_Protected::is_protected( $logos ) );
		$this->assertSame( [ $blog ], Wormiss_Media_Protected::protected_term_ids() );
	}

	/**
	 * An attachment is protected when in a protected category or a sub-category of one.
	 *
	 * @return void
	 */
	public function test_attachment_is_protected(): void {
		$blog = $this->category( 'Member blog' );
		$sub  = $this->category( '2026', $blog );
		$open = $this->category( 'Logos' );
		update_term_meta( $blog, Wormiss_Media_Protected::META_PROTECTED, 1 );

		$this->assertTrue( Wormiss_Media_Protected::attachment_is_protected( $this->attachment( [ $blog ] ) ), 'In the protected category.' );
		$this->assertTrue( Wormiss_Media_Protected::attachment_is_protected( $this->attachment( [ $sub ] ) ), 'In a sub-category of a protected one.' );
		$this->assertFalse( Wormiss_Media_Protected::attachment_is_protected( $this->attachment( [ $open ] ) ), 'In an unprotected category.' );
		$this->assertFalse( Wormiss_Media_Protected::attachment_is_protected( $this->attachment() ), 'Uncategorised.' );
	}

	/**
	 * Saving the flag needs the capability and a valid nonce; the present-marker lets unticking
	 * clear it.
	 *
	 * @return void
	 */
	public function test_save_field(): void {
		$term = $this->category( 'Member blog' );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$_POST['wormiss_media_protected_nonce']           = wp_create_nonce( Wormiss_Media_Protected::NONCE );
		$_POST[ Wormiss_Media_Protected::FIELD_PRESENT ]  = '1';
		$_POST[ Wormiss_Media_Protected::META_PROTECTED ] = '1';
		Wormiss_Media_Protected::save_field( $term );
		$this->assertTrue( Wormiss_Media_Protected::is_protected( $term ), 'Ticked and saved.' );

		// Present but unticked clears it.
		unset( $_POST[ Wormiss_Media_Protected::META_PROTECTED ] );
		Wormiss_Media_Protected::save_field( $term );
		$this->assertFalse( Wormiss_Media_Protected::is_protected( $term ), 'Unticked and cleared.' );

		// Bad nonce changes nothing.
		$_POST['wormiss_media_protected_nonce']           = 'nope';
		$_POST[ Wormiss_Media_Protected::META_PROTECTED ] = '1';
		Wormiss_Media_Protected::save_field( $term );
		$this->assertFalse( Wormiss_Media_Protected::is_protected( $term ), 'Bad nonce ignored.' );

		// No capability changes nothing.
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );
		$_POST['wormiss_media_protected_nonce'] = wp_create_nonce( Wormiss_Media_Protected::NONCE );
		Wormiss_Media_Protected::save_field( $term );
		$this->assertFalse( Wormiss_Media_Protected::is_protected( $term ), 'No capability, no change.' );

		unset(
			$_POST['wormiss_media_protected_nonce'],
			$_POST[ Wormiss_Media_Protected::FIELD_PRESENT ],
			$_POST[ Wormiss_Media_Protected::META_PROTECTED ]
		);
	}

	/**
	 * The list column reports the flag.
	 *
	 * @return void
	 */
	public function test_column(): void {
		$term = $this->category( 'Member blog' );
		$this->assertArrayHasKey( 'wormiss_media_protected', Wormiss_Media_Protected::columns( [] ) );
		$this->assertSame( '—', Wormiss_Media_Protected::column( '', 'wormiss_media_protected', $term ) );
		update_term_meta( $term, Wormiss_Media_Protected::META_PROTECTED, 1 );
		$this->assertSame( 'Protected', Wormiss_Media_Protected::column( '', 'wormiss_media_protected', $term ) );
		update_term_meta( $term, Wormiss_Media_Protected::META_LOCKED, 1 );
		$this->assertSame( 'Protected · locked to post', Wormiss_Media_Protected::column( '', 'wormiss_media_protected', $term ) );
	}
}
