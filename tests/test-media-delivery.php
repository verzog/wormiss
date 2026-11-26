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
 * Tests for protected media delivery: the private store, the gated endpoint and the access gate.
 *
 * @package    wormiss
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Protected delivery (media spec phase 4, part two).
 *
 * @covers Wormiss_Media_Delivery
 */
class Test_Wormiss_Media_Delivery extends Wormiss_Test_Case {

	/**
	 * A temporary protected store for the test.
	 *
	 * @var string
	 */
	private string $store = '';

	/**
	 * Register the taxonomy and protected meta; point the store at a temp directory.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();
		Wormiss_Media_Categories::register();
		Wormiss_Media_Protected::register_meta();
		$this->store = get_temp_dir() . 'wormiss-protected-test-' . wp_rand() . '/';
		add_filter( 'wormiss_protected_dir', fn() => $this->store );
	}

	/**
	 * Remove the temp store.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		if ( '' !== $this->store && is_dir( $this->store ) ) {
			$files = glob( $this->store . '*/*' );
			foreach ( is_array( $files ) ? $files : [] as $file ) {
				wp_delete_file( $file );
			}
		}
		parent::tear_down();
	}

	/**
	 * A protected media category.
	 *
	 * @return int
	 */
	private function protected_category(): int {
		$term = (int) self::factory()->term->create( [ 'taxonomy' => Wormiss_Media_Categories::TAXONOMY ] );
		update_term_meta( $term, Wormiss_Media_Protected::META_PROTECTED, 1 );
		return $term;
	}

	/**
	 * An attachment with a real file on disk.
	 *
	 * @param int[] $term_ids Category term IDs.
	 * @param int   $author   Author user ID.
	 * @return int
	 */
	private function attachment( array $term_ids = [], int $author = 0 ): int {
		$upload = wp_upload_bits( 'wormiss-' . wp_rand() . '.jpg', null, 'test-bytes' );
		$id     = (int) self::factory()->attachment->create_object(
			$upload['file'],
			0,
			[
				'post_mime_type' => 'image/jpeg',
				'post_author'    => $author,
			]
		);
		if ( $term_ids ) {
			wp_set_object_terms( $id, $term_ids, Wormiss_Media_Categories::TAXONOMY );
		}
		return $id;
	}

	/**
	 * The endpoint URL and the URL filter (protected attachments resolve to the endpoint).
	 *
	 * @return void
	 */
	public function test_filter_url(): void {
		$protected = $this->attachment( [ $this->protected_category() ] );
		$open      = $this->attachment( [ (int) self::factory()->term->create( [ 'taxonomy' => Wormiss_Media_Categories::TAXONOMY ] ) ] );

		$this->assertSame( home_url( '/wormiss-media/' . $protected ), wp_get_attachment_url( $protected ), 'Protected -> endpoint.' );
		$this->assertStringContainsString( '/wormiss-media/' . $protected, Wormiss_Media_Delivery::endpoint_url( $protected ) );
		$this->assertStringNotContainsString( '/wormiss-media/', (string) wp_get_attachment_url( $open ), 'Unprotected -> normal URL.' );
	}

	/**
	 * Access: administrators, the uploader (own via the matrix gate) and front-end members may view;
	 * an outsider may not.
	 *
	 * @return void
	 */
	public function test_can_view(): void {
		$admin      = (int) self::factory()->user->create( [ 'role' => 'administrator' ] );
		$uploader   = (int) self::factory()->user->create( [ 'role' => 'subscriber' ] );
		$outsider   = (int) self::factory()->user->create( [ 'role' => 'subscriber' ] );
		$attachment = $this->attachment( [ $this->protected_category() ], $uploader );

		$this->assertTrue( Wormiss_Media_Delivery::can_view( $attachment, $admin ), 'Administrator.' );
		$this->assertTrue( Wormiss_Media_Delivery::can_view( $attachment, $uploader ), 'Own upload.' );
		$this->assertFalse( Wormiss_Media_Delivery::can_view( $attachment, $outsider ), 'Outsider.' );

		// A member (a role allowed to read the blog) may view.
		update_option( Wormiss_Settings::OPTION, array_merge( Wormiss_Settings::all(), [ 'blog_reader_roles' => [ 'subscriber' ] ] ) );
		$this->assertTrue( Wormiss_Media_Delivery::can_view( $attachment, $outsider ), 'Member via the blog gate.' );
	}

	/**
	 * The decide() outcomes: 404 for anything not a protected image, 403 for no access, 200 otherwise.
	 *
	 * @return void
	 */
	public function test_decide(): void {
		$admin     = (int) self::factory()->user->create( [ 'role' => 'administrator' ] );
		$outsider  = (int) self::factory()->user->create( [ 'role' => 'subscriber' ] );
		$protected = $this->attachment( [ $this->protected_category() ] );
		$open      = $this->attachment();

		$this->assertSame( 404, Wormiss_Media_Delivery::decide( $open, $admin )['status'], 'Not protected.' );
		$this->assertSame( 403, Wormiss_Media_Delivery::decide( $protected, $outsider )['status'], 'No access.' );

		$served = Wormiss_Media_Delivery::decide( $protected, $admin );
		$this->assertSame( 200, $served['status'] );
		$this->assertSame( 'readfile', $served['mode'], 'Not yet in the store: streamed by PHP.' );
	}

	/**
	 * Storing moves the original into the private store, removes the public file, and the endpoint
	 * then serves it with X-Accel-Redirect.
	 *
	 * @return void
	 */
	public function test_store_and_serve(): void {
		$admin      = (int) self::factory()->user->create( [ 'role' => 'administrator' ] );
		$attachment = $this->attachment( [ $this->protected_category() ] );
		$original   = get_attached_file( $attachment );
		$this->assertFileExists( $original );

		$this->assertTrue( Wormiss_Media_Delivery::store( $attachment ) );
		$this->assertTrue( Wormiss_Media_Delivery::is_stored( $attachment ) );
		$relative = Wormiss_Media_Delivery::stored_relative( $attachment );
		$this->assertSame( $attachment . '/' . wp_basename( $original ), $relative );
		$this->assertFileExists( $this->store . $relative );
		$this->assertFileDoesNotExist( $original, 'Public original removed.' );

		$served = Wormiss_Media_Delivery::decide( $attachment, $admin );
		$this->assertSame( 200, $served['status'] );
		$this->assertSame( 'x-accel', $served['mode'] );
		$this->assertSame( $relative, $served['relative'] );
	}

	/**
	 * The stored path is kept inside the store: absolute paths and traversal are rejected.
	 *
	 * @return void
	 */
	public function test_stored_relative_guard(): void {
		$attachment = $this->attachment( [ $this->protected_category() ] );
		update_post_meta( $attachment, Wormiss_Media_Delivery::META_FILE, '../../etc/passwd' );
		$this->assertSame( '', Wormiss_Media_Delivery::stored_relative( $attachment ), 'Traversal rejected.' );
		update_post_meta( $attachment, Wormiss_Media_Delivery::META_FILE, '/etc/passwd' );
		$this->assertSame( '', Wormiss_Media_Delivery::stored_relative( $attachment ), 'Absolute rejected.' );
		update_post_meta( $attachment, Wormiss_Media_Delivery::META_FILE, '12/ok.jpg' );
		$this->assertSame( '12/ok.jpg', Wormiss_Media_Delivery::stored_relative( $attachment ) );
	}

	/**
	 * The store falls back to wp-content (flagged as not truly private) when the constant is unset.
	 *
	 * @return void
	 */
	public function test_fallback(): void {
		$this->assertTrue( Wormiss_Media_Delivery::using_fallback() );
		$this->assertStringEndsWith( '/', Wormiss_Media_Delivery::protected_dir() );
	}
}
