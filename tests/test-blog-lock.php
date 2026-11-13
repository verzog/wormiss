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
 * Tests for the members' blog lock.
 *
 * @package    wormiss
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Blog lock.
 *
 * @covers Wormiss_Blog_Lock
 */
class Test_Wormiss_Blog_Lock extends Wormiss_Test_Case {

	/**
	 * A blog post.
	 *
	 * @var int
	 */
	private $blog = 0;

	/**
	 * An ordinary post.
	 *
	 * @var int
	 */
	private $news = 0;

	/**
	 * Register the blog (reader role: subscriber) and make a blog post and a news post.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();
		Wormiss_Blog::register();
		update_option( Wormiss_Settings::OPTION, array_merge( Wormiss_Settings::all(), [ 'blog_reader_roles' => [ 'subscriber' ] ] ) );
		$this->blog = self::factory()->post->create(
			[
				'post_type'   => Wormiss_Blog::POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => 'Going Pink',
			]
		);
		$this->news = self::factory()->post->create(
			[
				'post_status' => 'publish',
				'post_title'  => 'Pink news',
			]
		);
		wp_set_object_terms( $this->blog, [ 'Pigmented' ], Wormiss_Blog::TAX_TOPIC );
	}

	/**
	 * Visitors are sent to sign in, signed-in non-members get "not found", readers the page.
	 *
	 * @return void
	 */
	public function test_blog_pages(): void {
		$pages = [
			get_permalink( $this->blog ),
			get_post_type_archive_link( Wormiss_Blog::POST_TYPE ),
			get_term_link( 'pigmented', Wormiss_Blog::TAX_TOPIC ),
		];
		foreach ( $pages as $page ) {
			wp_set_current_user( 0 );
			$this->go_to( $page );
			$this->assertSame( 'login', Wormiss_Blog_Lock::decision(), $page );

			wp_set_current_user( self::factory()->user->create( [ 'role' => 'contributor' ] ) );
			$this->go_to( $page );
			$this->assertSame( 'hide', Wormiss_Blog_Lock::decision(), $page );

			wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );
			$this->go_to( $page );
			$this->assertSame( '', Wormiss_Blog_Lock::decision(), $page );
		}

		wp_set_current_user( 0 );
		$this->go_to( get_permalink( $this->news ) );
		$this->assertSame( '', Wormiss_Blog_Lock::decision(), 'Other pages are not touched.' );

		$this->go_to( get_permalink( $this->blog ) );
		Wormiss_Blog_Lock::not_found();
		$this->assertTrue( is_404() );
	}

	/**
	 * Other listings and searches leave blog posts out for people who cannot read them.
	 *
	 * @return void
	 */
	public function test_listings(): void {
		$titles = static function ( array $args ): array {
			return wp_list_pluck( ( new WP_Query( $args ) )->posts, 'post_title' );
		};
		wp_set_current_user( 0 );
		$this->assertSame( [], $titles( [ 'post_type' => Wormiss_Blog::POST_TYPE ] ) );
		$this->assertSame( [ 'Pink news' ], $titles( [ 'post_type' => [ 'post', Wormiss_Blog::POST_TYPE ] ] ) );
		$this->assertSame( [ 'Pink news' ], $titles( [ 's' => 'Pink' ] ) );
		$this->assertSame( [ 'Pink news' ], $titles( [ 'post_type' => 'any' ] ) );
		$this->assertSame( [], $titles( [ Wormiss_Blog::TAX_TOPIC => 'pigmented' ] ) );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );
		$this->assertSame( [ 'Going Pink' ], $titles( [ 'post_type' => Wormiss_Blog::POST_TYPE ] ) );
		$this->assertCount( 2, $titles( [ 's' => 'Pink' ] ) );
	}

	/**
	 * The REST API's blog routes answer readers only.
	 *
	 * @return void
	 */
	public function test_rest(): void {
		$GLOBALS['wp_rest_server'] = null;
		$status                    = static function ( string $route ): int {
			return rest_get_server()->dispatch( new WP_REST_Request( 'GET', $route ) )->get_status();
		};
		wp_set_current_user( 0 );
		$this->assertSame( 401, $status( '/wp/v2/' . Wormiss_Blog::POST_TYPE ) );
		$this->assertSame( 401, $status( '/wp/v2/' . Wormiss_Blog::POST_TYPE . '/' . $this->blog ) );
		$this->assertSame( 401, $status( '/wp/v2/' . Wormiss_Blog::TAX_CATEGORY ) );
		$this->assertSame( 200, $status( '/wp/v2/posts' ), 'Other routes are not touched.' );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'contributor' ] ) );
		$this->assertSame( 403, $status( '/wp/v2/' . Wormiss_Blog::TAX_TOPIC ) );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );
		$this->assertSame( 200, $status( '/wp/v2/' . Wormiss_Blog::POST_TYPE . '/' . $this->blog ) );
	}

	/**
	 * No embeds for non-readers, and nothing of the blog in the XML sitemaps.
	 *
	 * @return void
	 */
	public function test_embeds_and_sitemaps(): void {
		wp_set_current_user( 0 );
		$this->assertSame( 0, Wormiss_Blog_Lock::oembed_post( $this->blog ) );
		$this->assertSame( $this->news, Wormiss_Blog_Lock::oembed_post( $this->news ) );

		$types = apply_filters( 'wp_sitemaps_post_types', get_post_types( [ 'public' => true ], 'objects' ) );
		$this->assertArrayNotHasKey( Wormiss_Blog::POST_TYPE, $types );
		$this->assertArrayHasKey( 'post', $types );
		$taxonomies = apply_filters( 'wp_sitemaps_taxonomies', get_taxonomies( [ 'public' => true ], 'objects' ) );
		$this->assertArrayNotHasKey( Wormiss_Blog::TAX_CATEGORY, $taxonomies );
		$this->assertArrayNotHasKey( Wormiss_Blog::TAX_TOPIC, $taxonomies );
	}
}
