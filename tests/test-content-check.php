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
 * Tests for checking a news article.
 *
 * @package    wormiss
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Checking a news article makes only fixed read-only lookups, and only for a proper key.
 *
 * @covers Wormiss_Content_Check
 */
class Test_Wormiss_Content_Check extends Wormiss_Test_Case {

	/**
	 * Only an iMIS key is accepted.
	 *
	 * @return void
	 */
	public function test_is_key(): void {
		$this->assertTrue( Wormiss_Content_Check::is_key( '6fdbde19-109c-43f8-b008-8b95e69f1704' ) );
		$this->assertTrue( Wormiss_Content_Check::is_key( '6FDBDE19-109C-43F8-B008-8B95E69F1704' ) );
		$this->assertFalse( Wormiss_Content_Check::is_key( '' ) );
		$this->assertFalse( Wormiss_Content_Check::is_key( '6fdbde19-109c-43f8-b008-8b95e69f1704&x=1' ) );
		$this->assertFalse( Wormiss_Content_Check::is_key( '../Party/123' ) );
		$this->assertSame( [], Wormiss_Content_Check::run( 'not a key' ) );
		$this->assertSame( [], $this->imis->requests );
	}

	/**
	 * Each lookup is a GET; replies and failures are both reported.
	 *
	 * @return void
	 */
	public function test_run(): void {
		$key = '6fdbde19-109c-43f8-b008-8b95e69f1704';
		$this->imis->token()
			->reply( '/api/ContentItem', 200, [ 'Html' => '<p>Vale</p>' ] )
			->reply( '/api/Content/', 404, '' )
			->reply( '/api/Document/', 501, [ 'Message' => 'Not implemented' ] );
		$results = Wormiss_Content_Check::run( strtoupper( $key ) );
		$this->assertCount( 3, $results );
		$this->assertTrue( $results[0]['ok'] );
		$this->assertStringContainsString( '<p>Vale</p>', $results[0]['body'] );
		$this->assertSame( '/api/ContentItem?ContentKey=' . $key, $results[0]['path'] );
		$this->assertFalse( $results[1]['ok'] );
		$this->assertStringContainsString( '404', $results[1]['status'] );
		$this->assertStringContainsString( '501', $results[2]['status'] );
		foreach ( $this->imis->requests as $request ) {
			$this->assertContains( $request['method'], [ 'GET', 'POST' ], 'Only the token is a POST.' );
			if ( 'POST' === $request['method'] ) {
				$this->assertStringContainsString( '/token', $request['url'] );
			}
		}
	}

	/**
	 * Long replies are cut.
	 *
	 * @return void
	 */
	public function test_pretty(): void {
		$this->assertStringContainsString( "\n", Wormiss_Content_Check::pretty( '{"a":1,"b":2}' ) );
		$long = Wormiss_Content_Check::pretty( str_repeat( 'x', Wormiss_Content_Check::MAX_SHOWN + 10 ) );
		$this->assertSame( Wormiss_Content_Check::MAX_SHOWN + 2, mb_strlen( $long ) );
	}
}
