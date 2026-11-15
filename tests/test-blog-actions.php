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
 * Tests for the members' blog actions (like, save, share).
 *
 * @package    wormiss
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Readers like and save posts; everyone sees the like count; likes and saves go with the
 * account.
 *
 * @covers Wormiss_Blog_Actions
 */
class Test_Wormiss_Blog_Actions extends Wormiss_Test_Case {

	/**
	 * Register the blog; reader role: subscriber.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();
		Wormiss_Blog::register();
		update_option( Wormiss_Settings::OPTION, array_merge( Wormiss_Settings::all(), [ 'blog_reader_roles' => [ 'subscriber' ] ] ) );
	}

	/**
	 * A published blog post.
	 *
	 * @return int
	 */
	private function post(): int {
		return self::factory()->post->create(
			[
				'post_type'   => Wormiss_Blog::POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => 'Going Pink',
			]
		);
	}

	/**
	 * Like and save turn on and off; counts are per post; non-readers and other posts are refused.
	 *
	 * @return void
	 */
	public function test_toggle(): void {
		$post_id = $this->post();
		$ann     = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		$bob     = self::factory()->user->create( [ 'role' => 'subscriber' ] );

		$this->assertSame(
			[
				'liked' => true,
				'likes' => 1,
				'saved' => false,
			],
			Wormiss_Blog_Actions::toggle( $post_id, $ann, 'like' )
		);
		Wormiss_Blog_Actions::toggle( $post_id, $bob, 'like' );
		$this->assertSame( 2, Wormiss_Blog_Actions::like_count( $post_id ) );
		$this->assertFalse( Wormiss_Blog_Actions::toggle( $post_id, $ann, 'like' )['liked'], 'A second press unlikes.' );
		$this->assertSame( 1, Wormiss_Blog_Actions::like_count( $post_id ) );
		$this->assertTrue( Wormiss_Blog_Actions::likes( $post_id, $bob ) );

		$this->assertTrue( Wormiss_Blog_Actions::toggle( $post_id, $ann, 'save' )['saved'] );
		$this->assertSame( [ $post_id ], Wormiss_Blog_Actions::saved( $ann ) );
		$this->assertSame( [], Wormiss_Blog_Actions::saved( $bob ) );
		$this->assertFalse( Wormiss_Blog_Actions::toggle( $post_id, $ann, 'save' )['saved'] );
		$this->assertSame( [], Wormiss_Blog_Actions::saved( $ann ) );

		$other = self::factory()->user->create( [ 'role' => 'author' ] );
		$this->assertNull( Wormiss_Blog_Actions::toggle( $post_id, $other, 'like' ), 'Not a reader.' );
		$this->assertNull( Wormiss_Blog_Actions::toggle( $post_id, 0, 'like' ), 'Signed out.' );
		$this->assertNull( Wormiss_Blog_Actions::toggle( self::factory()->post->create(), $ann, 'like' ), 'Not a blog post.' );
		$this->assertNull( Wormiss_Blog_Actions::toggle( $post_id, $ann, 'delete' ), 'Unknown action.' );
		$this->assertSame( 1, Wormiss_Blog_Actions::like_count( $post_id ) );
	}

	/**
	 * Readers get the like and save buttons in a form with a nonce; others just the count; all
	 * get Comments and a Share button that waits for the script.
	 *
	 * @return void
	 */
	public function test_render(): void {
		$post_id = $this->post();
		$ann     = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		Wormiss_Blog_Actions::toggle( $post_id, $ann, 'like' );

		wp_set_current_user( $ann );
		$html = Wormiss_Blog_Actions::render( $post_id );
		$this->assertStringContainsString( '<form class="wormiss-blog-actions__form" method="post"', $html );
		$this->assertStringContainsString( 'name="_wpnonce"', $html );
		$this->assertStringContainsString( 'value="like" aria-pressed="true"', $html );
		$this->assertStringContainsString( 'value="save" aria-pressed="false"', $html );
		$this->assertStringContainsString( '>Liked</span><span class="wormiss-blog-actions__count">1</span>', $html );
		$this->assertStringContainsString( 'href="#respond"', $html, 'No comments yet: to the form.' );
		$this->assertStringContainsString( 'wormiss-blog-actions__share" hidden', $html );
		$this->assertStringContainsString( 'data-url="' . esc_url( get_permalink( $post_id ) ) . '"', $html );

		self::factory()->comment->create( [ 'comment_post_ID' => $post_id ] );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'author' ] ) );
		$html = Wormiss_Blog_Actions::render( $post_id );
		$this->assertStringNotContainsString( '<form', $html, 'Not a reader: no buttons.' );
		$this->assertStringContainsString( 'wormiss-blog-actions__likes', $html );
		$this->assertStringContainsString( 'href="#comments"', $html );

		$this->assertSame( '', Wormiss_Blog_Actions::render( self::factory()->post->create() ), 'Blog posts only.' );
	}

	/**
	 * Deleting an account removes its likes and saves; erasure does too.
	 *
	 * @return void
	 */
	public function test_delete_for_user(): void {
		$post_id = $this->post();
		$ann     = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		$bob     = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		Wormiss_Blog_Actions::toggle( $post_id, $ann, 'like' );
		Wormiss_Blog_Actions::toggle( $post_id, $ann, 'save' );
		Wormiss_Blog_Actions::toggle( $post_id, $bob, 'like' );

		$this->assertSame( 2, Wormiss_Blog_Actions::delete_for_user( $ann ) );
		$this->assertSame( 1, Wormiss_Blog_Actions::like_count( $post_id ) );
		$this->assertSame( [], Wormiss_Blog_Actions::saved( $ann ) );

		require_once ABSPATH . 'wp-admin/includes/user.php';
		wp_delete_user( $bob );
		$this->assertSame( 0, Wormiss_Blog_Actions::like_count( $post_id ), 'Gone with the account.' );
	}
}
