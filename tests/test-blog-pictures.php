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
 * Tests for grouping the members' blog pictures.
 *
 * @package    wormiss
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Pictures one after another go side by side; those already in a row or grid stay.
 *
 * @covers Wormiss_Blog_Pictures
 */
class Test_Wormiss_Blog_Pictures extends Wormiss_Test_Case {

	/**
	 * A picture as the blog import writes it.
	 *
	 * @param string $src Address.
	 * @return string
	 */
	private function figure( string $src ): string {
		return '<figure class="figure wormiss-blog-image"><img class="img-fluid" src="https://example.org/' . $src . '" alt="" /></figure>';
	}

	/**
	 * Two as a pair, three across, four as two pairs, more in threes.
	 *
	 * @return void
	 */
	public function test_columns(): void {
		$this->assertSame( 2, Wormiss_Blog_Pictures::columns( 2 ) );
		$this->assertSame( 3, Wormiss_Blog_Pictures::columns( 3 ) );
		$this->assertSame( 2, Wormiss_Blog_Pictures::columns( 4 ) );
		$this->assertSame( 3, Wormiss_Blog_Pictures::columns( 5 ) );
	}

	/**
	 * Runs of two or more are grouped; a single picture, pictures with text between and pictures
	 * already in a row or grid are not; a paragraph of only pictures counts as its pictures.
	 *
	 * @return void
	 */
	public function test_group(): void {
		$html = '<p>Café – “one”</p>' . "\n" . $this->figure( 'a.jpg' ) . "\n<p>Text</p>\n"
			. $this->figure( 'b.jpg' ) . "\n" . $this->figure( 'c.jpg' ) . "\n<p>Q</p>\n"
			. '<p><img src="https://example.org/d.jpg"><br><img src="https://example.org/e.jpg"> <img src="https://example.org/f.jpg"></p>'
			. '<div class="row wormiss-blog-row"><div class="col-md">' . $this->figure( 'g.jpg' ) . '</div><div class="col-md">' . $this->figure( 'h.jpg' ) . '</div></div>';
		$out  = Wormiss_Blog_Pictures::group( $html );

		$this->assertStringContainsString( '<p>Café – “one”</p>', $out, 'Text kept as it was.' );
		$this->assertSame( 2, substr_count( $out, 'wormiss-blog-gallery' ) );
		$this->assertMatchesRegularExpression( '#wormiss-blog-grid--cols-2 wormiss-blog-gallery"><div class="wormiss-blog-grid__item"><figure[^>]*><img[^>]*b\.jpg#', $out );
		$this->assertMatchesRegularExpression( '#wormiss-blog-grid--cols-3 wormiss-blog-gallery"><div class="wormiss-blog-grid__item"><img[^>]*d\.jpg#', $out );
		$this->assertStringNotContainsString( '<p></p>', $out, 'The paragraph that held them is gone.' );
		$this->assertMatchesRegularExpression( '#<p>Text</p>#', $out );
		$this->assertDoesNotMatchRegularExpression( '#wormiss-blog-grid__item"><figure[^>]*><img[^>]*a\.jpg#', $out, 'One picture alone stays.' );
		$this->assertStringContainsString( '<div class="col-md"><figure class="figure wormiss-blog-image"><img class="img-fluid" src="https://example.org/g.jpg"', $out, 'A row stays.' );

		$alone = '<p>x</p>' . $this->figure( 'a.jpg' ) . '<p>y</p>' . $this->figure( 'b.jpg' );
		$this->assertSame( $alone, Wormiss_Blog_Pictures::group( $alone ), 'Nothing to group: unchanged.' );
	}

	/**
	 * Readers see a post's pictures grouped.
	 *
	 * @return void
	 */
	public function test_post(): void {
		Wormiss_Blog::register();
		update_option( Wormiss_Settings::OPTION, array_merge( Wormiss_Settings::all(), [ 'blog_reader_roles' => [ 'subscriber' ] ] ) );
		$post_id = self::factory()->post->create(
			[
				'post_type'    => Wormiss_Blog::POST_TYPE,
				'post_status'  => 'publish',
				'post_content' => '<p>Look</p>' . "\n" . $this->figure( 'a.jpg' ) . "\n" . $this->figure( 'b.jpg' ),
			]
		);
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );
		$this->go_to( get_permalink( $post_id ) );
		the_post();
		$this->assertStringContainsString( 'wormiss-blog-grid--cols-2 wormiss-blog-gallery', (string) apply_filters( 'the_content', get_the_content() ) );
	}
}
