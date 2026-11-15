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
 * Tests for the members' blog filters.
 *
 * @package    wormiss
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Blog filters.
 *
 * @covers Wormiss_Blog_Filters
 */
class Test_Wormiss_Blog_Filters extends Wormiss_Test_Case {

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
	 * A blog post.
	 *
	 * @param string $title   Title.
	 * @param string $content Members-only text.
	 * @param string $date    Date.
	 * @param int    $author  Author.
	 * @return int
	 */
	private function post( string $title, string $content, string $date, int $author ): int {
		return self::factory()->post->create(
			[
				'post_type'    => Wormiss_Blog::POST_TYPE,
				'post_status'  => 'publish',
				'post_title'   => $title,
				'post_content' => $content,
				'post_excerpt' => $title . ' summary',
				'post_date'    => $date,
				'post_author'  => $author,
			]
		);
	}

	/**
	 * Titles of the main query's posts.
	 *
	 * @param array $args Query string.
	 * @return string[]
	 */
	private function titles( array $args ): array {
		$this->go_to( add_query_arg( array_merge( [ 'post_type' => Wormiss_Blog::POST_TYPE ], $args ), home_url( '/' ) ) );
		return wp_list_pluck( $GLOBALS['wp_query']->posts, 'post_title' );
	}

	/**
	 * Category (with its levels), level, topic, author and dates narrow the listing; a keyword
	 * matches the members-only text only for readers.
	 *
	 * @return void
	 */
	public function test_filters(): void {
		$tax       = Wormiss_Blog::TAX_CATEGORY;
		$diagnosis = self::factory()->term->create(
			[
				'taxonomy' => $tax,
				'name'     => 'Diagnosis',
			]
		);
		$all       = self::factory()->term->create(
			[
				'taxonomy' => $tax,
				'name'     => 'All Levels',
				'parent'   => $diagnosis,
			]
		);
		$surgery   = self::factory()->term->create(
			[
				'taxonomy' => $tax,
				'name'     => 'Management',
			]
		);
		// A top-level category with a level's name is not that level.
		$top  = self::factory()->term->create(
			[
				'taxonomy' => $tax,
				'name'     => 'All Levels',
			]
		);
		$ann  = self::factory()->user->create( [ 'display_name' => 'Dr Ann' ] );
		$bob  = self::factory()->user->create( [ 'display_name' => 'Dr Bob' ] );
		$pink = $this->post( 'Going Pink', 'a lentigo maligna', '2026-11-01 09:00:00', $ann );
		$neck = $this->post( 'R Neck Surgical Case', 'two lesions', '2026-06-17 09:00:00', $bob );
		wp_set_object_terms( $pink, [ $all ], $tax );
		wp_set_object_terms( $neck, [ $surgery, $top ], $tax );
		wp_set_object_terms( $pink, [ 'Pigmented' ], Wormiss_Blog::TAX_TOPIC );

		$this->assertSame( [ 'Going Pink' ], $this->titles( [ 'blog_category' => 'diagnosis' ] ), 'A category includes its levels.' );
		$this->assertSame( [ 'Going Pink' ], $this->titles( [ 'blog_level' => 'all-levels' ] ) );
		$this->assertSame( [ 'Going Pink' ], $this->titles( [ 'blog_topic' => 'pigmented' ] ) );
		$this->assertSame( [ 'R Neck Surgical Case' ], $this->titles( [ 'blog_author' => $bob ] ) );
		$this->assertSame(
			[ 'R Neck Surgical Case' ],
			$this->titles(
				[
					'blog_from' => '2026-06-01',
					'blog_to'   => '2026-06-30',
				]
			)
		);
		$this->assertCount( 2, $this->titles( [ 'blog_from' => 'not-a-date' ] ), 'Bad dates are ignored.' );
		$this->assertCount( 2, $this->titles( [ 'blog_from' => '2026-99-99' ] ), 'Impossible dates are ignored.' );
		$this->assertSame( [ 'Going Pink', 'R Neck Surgical Case' ], $this->titles( [] ), 'Newest first.' );
		$this->assertSame( [ 'R Neck Surgical Case', 'Going Pink' ], $this->titles( [ 'blog_order' => 'asc' ] ), 'Oldest first.' );

		wp_set_current_user( 0 );
		$this->assertSame( [], $this->titles( [ 'blog_q' => 'lentigo' ] ), 'Members-only text is not searched for visitors.' );
		$this->assertSame( [ 'Going Pink' ], $this->titles( [ 'blog_q' => 'Pink' ] ) );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );
		$this->assertSame( [ 'Going Pink' ], $this->titles( [ 'blog_q' => 'lentigo' ] ), 'Readers search the text.' );
	}

	/**
	 * The form lists categories, levels (once per name) and authors, keeps the choices made and
	 * goes to the blog's address.
	 *
	 * @return void
	 */
	public function test_form(): void {
		$tax       = Wormiss_Blog::TAX_CATEGORY;
		$diagnosis = self::factory()->term->create(
			[
				'taxonomy' => $tax,
				'name'     => 'Diagnosis',
			]
		);
		$manage    = self::factory()->term->create(
			[
				'taxonomy' => $tax,
				'name'     => 'Management',
			]
		);
		foreach ( [ $diagnosis, $manage ] as $parent ) {
			self::factory()->term->create(
				[
					'taxonomy' => $tax,
					'name'     => 'All Levels',
					'parent'   => $parent,
				]
			);
		}
		$ann = self::factory()->user->create( [ 'display_name' => 'Dr Ann' ] );
		$this->post( 'Going Pink', '', '2026-11-01 09:00:00', $ann );
		Wormiss_Blog_Filters::forget_authors();

		$this->go_to( add_query_arg( 'blog_q', '<b>pink</b>', get_post_type_archive_link( Wormiss_Blog::POST_TYPE ) ) );
		$html = Wormiss_Blog_Filters::render();
		$this->assertStringContainsString( 'action="' . esc_url( get_post_type_archive_link( Wormiss_Blog::POST_TYPE ) ) . '"', $html );
		$this->assertStringContainsString( '>Diagnosis</option>', $html );
		$this->assertSame( 1, substr_count( $html, '>All Levels</option>' ), 'One choice per level name.' );
		$this->assertStringContainsString( '>Dr Ann</option>', $html );
		$this->assertStringContainsString( 'value="pink"', $html, 'Kept, as plain text.' );
		$this->assertStringNotContainsString( '<b>', $html );
		$this->assertStringContainsString( '>Subcategories</label>', $html );
		$this->assertStringContainsString( 'name="blog_order" value="asc"', $html, 'The sort button turns to oldest first.' );
		$this->assertLessThan( strpos( $html, 'wormiss-blog-filters__sort' ), strpos( $html, 'wormiss-blog-filters__apply' ), 'Enter applies the filters.' );
		if ( str_contains( (string) get_post_type_archive_link( Wormiss_Blog::POST_TYPE ), 'post_type=' ) ) {
			$this->assertStringContainsString( '<input type="hidden" name="post_type" value="wormiss_blog" />', $html, 'Plain permalinks keep the post type.' );
		}
	}

	/**
	 * "Saved posts only" shows the member's saved posts (none saved: none); the tick box is for
	 * signed-in people only.
	 *
	 * @return void
	 */
	public function test_saved(): void {
		$ann    = self::factory()->user->create();
		$pink   = $this->post( 'Going Pink', '', '2026-11-01 09:00:00', $ann );
		$neck   = $this->post( 'R Neck Surgical Case', '', '2026-06-17 09:00:00', $ann );
		$member = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		unset( $neck );
		wp_set_current_user( $member );
		$this->assertSame( [], $this->titles( [ 'blog_saved' => '1' ] ), 'Nothing saved yet.' );
		Wormiss_Blog_Actions::toggle( $pink, $member, 'save' );
		$this->assertSame( [ 'Going Pink' ], $this->titles( [ 'blog_saved' => '1' ] ) );
		$this->assertCount( 2, $this->titles( [] ) );
		$this->assertStringContainsString( 'name="blog_saved" value="1" checked=', Wormiss_Blog_Filters::render() );

		wp_set_current_user( 0 );
		$this->assertCount( 2, $this->titles( [ 'blog_saved' => '1' ] ), 'Ignored for visitors.' );
		$this->assertStringNotContainsString( 'blog_saved', Wormiss_Blog_Filters::render() );
	}
}
