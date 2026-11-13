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
 * Tests for checking a blog post.
 *
 * @package    wormiss
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Checking a blog post makes only fixed GET lookups, only for a proper key, and decodes the
 * post's binary text.
 *
 * @covers Wormiss_Blog_Check
 */
class Test_Wormiss_Blog_Check extends Wormiss_Test_Case {

	/**
	 * Only a plain blog key is accepted; nothing is asked of iMIS otherwise.
	 *
	 * @return void
	 */
	public function test_is_key(): void {
		$this->assertTrue( Wormiss_Blog_Check::is_key( '6fdbde19-109c-43f8-b008-8b95e69f1704' ) );
		$this->assertTrue( Wormiss_Blog_Check::is_key( 'BLOG_123' ) );
		$this->assertFalse( Wormiss_Blog_Check::is_key( '' ) );
		$this->assertFalse( Wormiss_Blog_Check::is_key( 'x&Limit=10000' ) );
		$this->assertFalse( Wormiss_Blog_Check::is_key( '../Party' ) );
		$this->assertSame( [], Wormiss_Blog_Check::run( 'x y' ) );
		$this->assertSame( [], $this->imis->requests );
	}

	/**
	 * Every lookup is a GET to its fixed address; the post's text is decoded.
	 *
	 * @return void
	 */
	public function test_run(): void {
		$this->imis->token()->reply(
			'/api/Smart_Blogs_Blog_Post',
			200,
			[
				'Items' => [
					'$values' => [
						[
							'Properties' => [
								'$values' => [
									[
										'Name'  => 'BlogPostData',
										'Value' => [
											'$type'  => 'System.Byte[], mscorlib',
											// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- As iMIS sends it.
											'$value' => base64_encode( '{"blocks":[{"html":"<p>Case</p>"}]}' ),
										],
									],
								],
							],
						],
					],
				],
			]
		);
		foreach ( array_slice( Wormiss_Blog_Check::LOOKUPS, 1 ) as $lookup ) {
			$this->imis->reply( strtok( (string) $lookup['path'], '?' ), 200, [ 'Items' => [ '$values' => [] ] ] );
		}
		$results = Wormiss_Blog_Check::run( 'BLOG_1' );
		$this->assertCount( count( Wormiss_Blog_Check::LOOKUPS ), $results );
		$this->assertSame( '/api/Smart_Blogs_Blog_Post?BlogKey=BLOG_1', $results[0]['path'] );
		$this->assertStringContainsString( '<p>Case</p>', implode( '', $results[0]['decoded'] ) );
		foreach ( $this->imis->requests as $request ) {
			if ( 'POST' === $request['method'] ) {
				$this->assertStringContainsString( '/token', $request['url'] );
			} else {
				$this->assertSame( 'GET', $request['method'] );
			}
		}
	}
}
