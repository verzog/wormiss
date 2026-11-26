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
	 * A category whose images are locked to the post they are embedded in.
	 *
	 * @return int
	 */
	private function locked_category(): int {
		$term = (int) self::factory()->term->create( [ 'taxonomy' => Wormiss_Media_Categories::TAXONOMY ] );
		update_term_meta( $term, Wormiss_Media_Protected::META_LOCKED, 1 );
		return $term;
	}

	/**
	 * An attachment with a real file on disk.
	 *
	 * @param int[] $term_ids Category term IDs.
	 * @param int   $author   Author user ID.
	 * @param int   $parent_id Parent post ID (post_parent).
	 * @return int
	 */
	private function attachment( array $term_ids = [], int $author = 0, int $parent_id = 0 ): int {
		$upload = wp_upload_bits( 'wormiss-' . wp_rand() . '.jpg', null, 'test-bytes' );
		$id     = (int) self::factory()->attachment->create_object(
			$upload['file'],
			$parent_id,
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

	/**
	 * Any requested size of a protected image resolves to the gated endpoint (not a per-size file
	 * URL), and responsive srcset is dropped.
	 *
	 * @return void
	 */
	public function test_sized_images_use_endpoint(): void {
		$id = $this->attachment( [ $this->protected_category() ] );
		wp_update_attachment_metadata(
			$id,
			[
				'width'  => 800,
				'height' => 600,
				'sizes'  => [
					'thumbnail' => [
						'file'   => 't-150x150.jpg',
						'width'  => 150,
						'height' => 150,
					],
				],
			]
		);
		$src = wp_get_attachment_image_src( $id, 'thumbnail' );
		$this->assertSame( home_url( '/wormiss-media/' . $id ), $src[0], 'Sized src is the endpoint.' );
		$this->assertFalse( (bool) $src[3], 'Not treated as an intermediate file.' );

		$this->assertSame( [], Wormiss_Media_Delivery::filter_srcset( [ 'a' => 1 ], [ 1, 1 ], 'u', [], $id ), 'Protected: no srcset.' );
		$this->assertSame( [ 'a' => 1 ], Wormiss_Media_Delivery::filter_srcset( [ 'a' => 1 ], [ 1, 1 ], 'u', [], 999999 ), 'Others unchanged.' );
	}

	/**
	 * Storing removes every public copy, including generated sizes, from the uploads directory.
	 *
	 * @return void
	 */
	public function test_store_removes_sizes(): void {
		$id       = $this->attachment( [ $this->protected_category() ] );
		$original = get_attached_file( $id );
		$sizefile = dirname( $original ) . '/t-150x150.jpg';
		copy( $original, $sizefile ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_copy -- Test fixture.
		wp_update_attachment_metadata(
			$id,
			[
				'width'  => 10,
				'height' => 10,
				'file'   => _wp_relative_upload_path( $original ),
				'sizes'  => [
					'thumbnail' => [
						'file'      => 't-150x150.jpg',
						'width'     => 150,
						'height'    => 150,
						'mime-type' => 'image/jpeg',
					],
				],
			]
		);
		$this->assertFileExists( $sizefile );

		Wormiss_Media_Delivery::store( $id );
		$this->assertFileDoesNotExist( $sizefile, 'Generated size removed from uploads.' );
		$this->assertFileDoesNotExist( $original, 'Original removed from uploads.' );
	}

	/**
	 * Serve mode: X-Accel by default, X-Sendfile when configured (takes precedence), and decide()
	 * carries the chosen mode for a stored file.
	 *
	 * @return void
	 */
	public function test_serve_mode(): void {
		$this->assertSame( 'x-accel', Wormiss_Media_Delivery::serve_mode() );

		$xsendfile = static fn() => 'x-sendfile';
		add_filter( 'wormiss_media_serve_mode', $xsendfile );
		$this->assertSame( 'x-sendfile', Wormiss_Media_Delivery::serve_mode() );

		$admin = (int) self::factory()->user->create( [ 'role' => 'administrator' ] );
		$id    = $this->attachment( [ $this->protected_category() ] );
		Wormiss_Media_Delivery::store( $id );
		$this->assertSame( 'x-sendfile', Wormiss_Media_Delivery::decide( $id, $admin )['mode'], 'Stored file uses the configured mode.' );
		remove_filter( 'wormiss_media_serve_mode', $xsendfile );
	}

	/**
	 * Lock-to-post implies protected delivery, and a locked attachment reports as both.
	 *
	 * @return void
	 */
	public function test_locked_implies_protected(): void {
		$locked = $this->attachment( [ $this->locked_category() ] );
		$this->assertTrue( Wormiss_Media_Protected::attachment_is_locked( $locked ), 'Locked.' );
		$this->assertTrue( Wormiss_Media_Protected::attachment_is_protected( $locked ), 'Locked implies protected.' );

		$protected = $this->attachment( [ $this->protected_category() ] );
		$this->assertFalse( Wormiss_Media_Protected::attachment_is_locked( $protected ), 'Protected-only is not locked.' );
	}

	/**
	 * A lock token is valid only for its attachment, viewer and window: tampered, expired, or
	 * foreign-viewer tokens are rejected.
	 *
	 * @return void
	 */
	public function test_lock_token_validity(): void {
		$post = (int) self::factory()->post->create();
		$att  = $this->attachment( [ $this->locked_category() ], 0, $post );
		$user = (int) self::factory()->user->create( [ 'role' => 'subscriber' ] );

		$token = Wormiss_Media_Delivery::mint_lock_token( $att, $post, $user );
		$this->assertTrue( Wormiss_Media_Delivery::valid_lock_token( $att, $user, $token ), 'Fresh token.' );
		$this->assertFalse( Wormiss_Media_Delivery::valid_lock_token( $att, $user + 1, $token ), 'Another viewer.' );
		$this->assertFalse( Wormiss_Media_Delivery::valid_lock_token( $att + 1, $user, $token ), 'Another attachment.' );
		$this->assertFalse( Wormiss_Media_Delivery::valid_lock_token( $att, $user, $token . 'x' ), 'Tampered token.' );
		$this->assertFalse( Wormiss_Media_Delivery::valid_lock_token( $att, $user, 'not-a-token' ), 'Malformed token.' );

		// A token whose expiry has passed (signed correctly for that past time) is rejected.
		$sign    = new ReflectionMethod( Wormiss_Media_Delivery::class, 'lock_signature' );
		$past    = time() - 10;
		$sig     = (string) $sign->invoke( null, $att, $post, $user, $past );
		$expired = $post . '.' . $past . '.' . $sig;
		$this->assertFalse( Wormiss_Media_Delivery::valid_lock_token( $att, $user, $expired ), 'Expired token.' );
	}

	/**
	 * The endpoint locks a locked image to its post: a member needs a valid token (a bare URL is
	 * Forbidden), another member's token fails, and an administrator needs none.
	 *
	 * @return void
	 */
	public function test_locked_endpoint_requires_token(): void {
		update_option( Wormiss_Settings::OPTION, array_merge( Wormiss_Settings::all(), [ 'blog_reader_roles' => [ 'subscriber' ] ] ) );
		$post   = (int) self::factory()->post->create();
		$att    = $this->attachment( [ $this->locked_category() ], 0, $post );
		$member = (int) self::factory()->user->create( [ 'role' => 'subscriber' ] );
		$admin  = (int) self::factory()->user->create( [ 'role' => 'administrator' ] );

		wp_set_current_user( $member );
		$this->assertSame( 403, Wormiss_Media_Delivery::decide( $att )['status'], 'Member, no token: Forbidden.' );

		$token = Wormiss_Media_Delivery::mint_lock_token( $att, $post, $member );
		$this->assertSame( 200, Wormiss_Media_Delivery::decide( $att, 0, $token )['status'], 'Member with a valid token.' );

		$other = Wormiss_Media_Delivery::mint_lock_token( $att, $post, $member + 1000 );
		$this->assertSame( 403, Wormiss_Media_Delivery::decide( $att, 0, $other )['status'], "Another member's token." );

		wp_set_current_user( $admin );
		$this->assertSame( 200, Wormiss_Media_Delivery::decide( $att )['status'], 'Administrator needs no token.' );
	}

	/**
	 * Start a loop over a single post, so in_the_loop() is true and get_the_ID() is that post.
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	private function enter_loop( int $post_id ): void {
		$GLOBALS['post'] = get_post( $post_id ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Loop context for the test.
		setup_postdata( $GLOBALS['post'] );
		$GLOBALS['wp_query']->in_the_loop = true;
	}

	/**
	 * End the loop set up by enter_loop().
	 *
	 * @return void
	 */
	private function leave_loop(): void {
		$GLOBALS['wp_query']->in_the_loop = false;
		wp_reset_postdata();
		unset( $GLOBALS['post'] );
	}

	/**
	 * While a member renders the post a locked image is attached to, its URL carries a token that
	 * validates; an administrator's URL is tokenless (they do not need one).
	 *
	 * @return void
	 */
	public function test_locked_url_carries_token_in_post(): void {
		update_option( Wormiss_Settings::OPTION, array_merge( Wormiss_Settings::all(), [ 'blog_reader_roles' => [ 'subscriber' ] ] ) );
		$post_id = (int) self::factory()->post->create();
		$att     = $this->attachment( [ $this->locked_category() ], 0, $post_id );
		$member  = (int) self::factory()->user->create( [ 'role' => 'subscriber' ] );

		wp_set_current_user( $member );
		$this->enter_loop( $post_id );
		$url = (string) wp_get_attachment_url( $att );
		$this->assertStringContainsString( '/wormiss-media/' . $att, $url, 'Points at the endpoint.' );
		parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $args );
		$this->assertArrayHasKey( Wormiss_Media_Delivery::LOCK_ARG, $args, 'Carries a token.' );
		$this->assertTrue(
			Wormiss_Media_Delivery::valid_lock_token( $att, $member, (string) $args[ Wormiss_Media_Delivery::LOCK_ARG ] ),
			'The emitted token validates for that member.'
		);

		wp_set_current_user( (int) self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$this->assertStringNotContainsString( '?', (string) wp_get_attachment_url( $att ), 'Administrator URL has no token.' );
		$this->leave_loop();
	}

	/**
	 * A bare wp_get_attachment_url() outside a post render (e.g. the download handler or REST) mints
	 * no token, so the URL is Forbidden at the endpoint — tokens come only from rendering the post.
	 *
	 * @return void
	 */
	public function test_locked_url_has_no_token_outside_loop(): void {
		update_option( Wormiss_Settings::OPTION, array_merge( Wormiss_Settings::all(), [ 'blog_reader_roles' => [ 'subscriber' ] ] ) );
		$post_id = (int) self::factory()->post->create();
		$att     = $this->attachment( [ $this->locked_category() ], 0, $post_id );
		wp_set_current_user( (int) self::factory()->user->create( [ 'role' => 'subscriber' ] ) );

		$url = (string) wp_get_attachment_url( $att );
		$this->assertStringContainsString( '/wormiss-media/' . $att, $url );
		$this->assertStringNotContainsString( '?', $url, 'No token minted outside a post render.' );
	}

	/**
	 * Content rewriting resolves a stored gated URL (as the blog import saves) for a locked image and
	 * mints a fresh token while the member renders that image's post.
	 *
	 * @return void
	 */
	public function test_rewrite_content_retokenises_locked_endpoint(): void {
		update_option( Wormiss_Settings::OPTION, array_merge( Wormiss_Settings::all(), [ 'blog_reader_roles' => [ 'subscriber' ] ] ) );
		$post_id = (int) self::factory()->post->create();
		$att     = $this->attachment( [ $this->locked_category() ], 0, $post_id );
		$member  = (int) self::factory()->user->create( [ 'role' => 'subscriber' ] );

		$content = '<p><img src="' . home_url( '/wormiss-media/' . $att ) . '" alt="x" /></p>';

		wp_set_current_user( $member );
		$this->enter_loop( $post_id );
		$out = Wormiss_Media_Delivery::rewrite_content( $content );
		$this->leave_loop();

		$this->assertMatchesRegularExpression( '#/wormiss-media/' . $att . '\?' . Wormiss_Media_Delivery::LOCK_ARG . '=#', $out, 'A fresh token was inserted onto the stored gated URL.' );
	}

	/**
	 * An imported picture is filed into the configured category; when that category is protected it is
	 * moved into the private store, and when it is not it stays public.
	 *
	 * @return void
	 */
	public function test_protect_imported(): void {
		$protected = $this->protected_category();
		update_option( Wormiss_Settings::OPTION, array_merge( Wormiss_Settings::all(), [ 'blog_media_category' => $protected ] ) );
		$id = $this->attachment();
		$this->assertTrue( Wormiss_Media_Delivery::protect_imported( $id ), 'Protected category stored -> true.' );
		$this->assertTrue( has_term( $protected, Wormiss_Media_Categories::TAXONOMY, $id ), 'Filed into the category.' );
		$this->assertTrue( Wormiss_Media_Delivery::is_stored( $id ), 'Protected category -> stored.' );

		$open = (int) self::factory()->term->create( [ 'taxonomy' => Wormiss_Media_Categories::TAXONOMY ] );
		update_option( Wormiss_Settings::OPTION, array_merge( Wormiss_Settings::all(), [ 'blog_media_category' => $open ] ) );
		$id2 = $this->attachment();
		$this->assertTrue( Wormiss_Media_Delivery::protect_imported( $id2 ), 'Nothing to store -> true.' );
		$this->assertTrue( has_term( $open, Wormiss_Media_Categories::TAXONOMY, $id2 ), 'Filed into the open category.' );
		$this->assertFalse( Wormiss_Media_Delivery::is_stored( $id2 ), 'Open category -> not stored.' );
	}

	/**
	 * When the private store cannot be written, protect_imported() reports failure so the import can
	 * stay incomplete and retry, instead of silently leaving the public file in place.
	 *
	 * @return void
	 */
	public function test_protect_imported_store_failure(): void {
		$protected = $this->protected_category();
		update_option( Wormiss_Settings::OPTION, array_merge( Wormiss_Settings::all(), [ 'blog_media_category' => $protected ] ) );
		// Point the store at a path whose parent is a file, so the directory cannot be created.
		$blocker = get_temp_dir() . 'wormiss-blocker-' . wp_rand();
		file_put_contents( $blocker, 'x' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture.
		$bad = static fn() => $blocker . '/store/';
		add_filter( 'wormiss_protected_dir', $bad, 99 );

		$id = $this->attachment();
		$this->assertFalse( Wormiss_Media_Delivery::protect_imported( $id ), 'Store failure -> false.' );
		$this->assertFalse( Wormiss_Media_Delivery::is_stored( $id ), 'Not stored.' );
		$this->assertFileExists( (string) get_attached_file( $id ), 'Public file left intact for retry.' );

		remove_filter( 'wormiss_protected_dir', $bad, 99 );
		wp_delete_file( $blocker );
	}

	/**
	 * Post content is rewritten so a protected picture's hardcoded URL points at the gated endpoint
	 * and its srcset is dropped, while an unprotected picture's URL is left alone.
	 *
	 * @return void
	 */
	public function test_rewrite_content(): void {
		// Capture the plain file URLs before the attachments become protected (the URL filter would
		// otherwise return the endpoint).
		$protected_id  = $this->attachment();
		$open_id       = $this->attachment();
		$protected_url = wp_get_attachment_url( $protected_id );
		$open_url      = wp_get_attachment_url( $open_id );
		$this->assertStringContainsString( '/uploads/', $protected_url, 'Starts as a public URL.' );

		wp_set_object_terms( $protected_id, [ $this->protected_category() ], Wormiss_Media_Categories::TAXONOMY );
		$this->assertSame( $protected_id, attachment_url_to_postid( $protected_url ), 'URL resolves to the attachment.' );

		$content = '<p><img src="' . esc_url( $protected_url ) . '" srcset="' . esc_url( $protected_url ) . ' 800w" sizes="100vw" alt="a" />'
			. '<img src="' . esc_url( $open_url ) . '" alt="b" /></p>';
		$out     = Wormiss_Media_Delivery::rewrite_content( $content );

		$this->assertStringContainsString( 'src="' . home_url( '/wormiss-media/' . $protected_id ) . '"', $out, 'Protected src -> endpoint.' );
		$this->assertStringNotContainsString( 'srcset', $out, 'srcset dropped.' );
		$this->assertStringContainsString( 'src="' . esc_url( $open_url ) . '"', $out, 'Unprotected src unchanged.' );
	}

	/**
	 * The content rewrite is a no-op when no category is protected.
	 *
	 * @return void
	 */
	public function test_rewrite_content_noop_without_protected(): void {
		$id      = $this->attachment();
		$url     = wp_get_attachment_url( $id );
		$content = '<img src="' . esc_url( $url ) . '" alt="x" />';
		$this->assertSame( $content, Wormiss_Media_Delivery::rewrite_content( $content ), 'No protected categories: unchanged.' );
	}
}
