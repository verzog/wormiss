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
 * Tests for Wormiss_Iqa_Display, the iMIS data block and the shortcode.
 *
 * @package    wormiss
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Showing IQA results on pages.
 *
 * @covers Wormiss_Iqa_Display
 */
class Test_Wormiss_Iqa_Display extends Wormiss_Test_Case {

	/**
	 * Rows used by most tests.
	 *
	 * @return array[]
	 */
	private static function rows(): array {
		return [
			[
				'EventKey'  => 'EV 1',
				'EventName' => 'Skin Cancer <b>Basics</b>',
				'StartDate' => '2027-03-05T00:00:00',
				'City'      => 'Brisbane',
				'Online'    => false,
			],
			[
				'EventKey'  => 'EV2',
				'EventName' => 'Dermoscopy Workshop',
				'StartDate' => '2027-04-10T18:30:00',
				'City'      => 'Sydney',
				'Online'    => true,
			],
		];
	}

	/**
	 * Queue a token and one page of rows.
	 *
	 * @param array[]|null $rows Rows (default: rows()).
	 * @return void
	 */
	private function serve( ?array $rows = null ): void {
		$this->imis->token()->reply( '/api/iqa', 200, Wormiss_Fake_Imis::page( $rows ?? self::rows() ) );
	}

	/**
	 * A table with chosen columns and headings, escaped values, local dates and safe links.
	 *
	 * @return void
	 */
	public function test_table(): void {
		$this->serve();
		$html = Wormiss_Iqa_Display::render(
			[
				'query'   => self::QUERY,
				'columns' => 'Event=EventName, Date=StartDate, City, Missing',
				'link'    => 'https://imis.example.org/Events/Detail?id={EventKey}',
			]
		);
		$this->assertStringContainsString( '<th scope="col">Event</th><th scope="col">Date</th><th scope="col">City</th></tr>', $html );
		$this->assertStringNotContainsString( 'Missing', $html );
		$this->assertStringContainsString( 'Skin Cancer &lt;b&gt;Basics&lt;/b&gt;', $html );
		$this->assertStringContainsString( '<td>05/03/2027</td>', $html );
		$this->assertStringContainsString( '<td>10/04/2027 6:30 pm</td>', $html );
		$this->assertStringContainsString( 'href="https://imis.example.org/Events/Detail?id=EV%201"', $html );
	}

	/**
	 * Cards use the first column as the heading; lists join the rest; booleans read Yes/No.
	 *
	 * @return void
	 */
	public function test_cards_and_list(): void {
		$this->serve();
		$cards = Wormiss_Iqa_Display::render(
			[
				'query'   => self::QUERY,
				'layout'  => 'cards',
				'columns' => 'EventName, City, Online=Online',
			]
		);
		$this->assertStringContainsString( '<h3 class="wormiss-iqa__title">Dermoscopy Workshop</h3>', $cards );
		$this->assertStringContainsString( '<dt>Online</dt><dd>Yes</dd>', $cards );

		$list = Wormiss_Iqa_Display::render(
			[
				'query'   => self::QUERY,
				'layout'  => 'list',
				'columns' => 'EventName, City, StartDate',
			]
		);
		$this->assertStringContainsString(
			'Dermoscopy Workshop <span class="wormiss-iqa__meta">Sydney · 10/04/2027 6:30 pm</span>',
			$list
		);
	}

	/**
	 * The row limit is applied and an empty result shows the chosen text.
	 *
	 * @return void
	 */
	public function test_limit_and_empty(): void {
		$this->serve();
		$html = Wormiss_Iqa_Display::render(
			[
				'query' => self::QUERY,
				'limit' => 1,
			]
		);
		$this->assertSame( 1, substr_count( $html, '<tr><td>' ) );

		update_option( Wormiss_Settings::OPTION, array_merge( Wormiss_Settings::all(), [ 'allowed_queries' => [ '$/Empty' ] ] ) );
		$this->serve( [] );
		$html = Wormiss_Iqa_Display::render(
			[
				'query'      => '$/Empty',
				'empty_text' => 'No courses scheduled.',
			]
		);
		$this->assertStringContainsString( 'No courses scheduled.', $html );
	}

	/**
	 * A query not on the approved list is never run: editors see why, visitors see nothing.
	 *
	 * @return void
	 */
	public function test_unapproved_query_is_never_run(): void {
		$html = Wormiss_Iqa_Display::render( [ 'query' => '$/Members/AllContactDetails' ] );
		$this->assertSame( '', $html );
		$this->assertSame( [], $this->imis->requests );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );
		$html = Wormiss_Iqa_Display::render( [ 'query' => '$/Members/AllContactDetails' ] );
		$this->assertStringContainsString( 'not on the approved list', $html );
		$this->assertSame( [], $this->imis->requests );
	}

	/**
	 * Results are cached; when iMIS later fails, the last good copy is shown.
	 *
	 * @return void
	 */
	public function test_cache_then_stale_copy(): void {
		$this->serve();
		$first = Wormiss_Iqa_Display::render( [ 'query' => self::QUERY ] );
		$again = Wormiss_Iqa_Display::render( [ 'query' => self::QUERY ] );
		$this->assertSame( $first, $again );
		$this->assertSame( 1, $this->imis->count( '/api/iqa' ) );

		// The fresh copy expires and iMIS is down: the stale copy is used.
		delete_transient( Wormiss_Iqa_Display::cache_key( self::QUERY ) );
		$this->imis->token()->reply( '/api/iqa', 503, 'Service Unavailable' );
		$this->assertSame( $first, Wormiss_Iqa_Display::render( [ 'query' => self::QUERY ] ) );
	}

	/**
	 * Cached results belong to one iMIS connection: a new address or account reads afresh.
	 *
	 * @return void
	 */
	public function test_cache_is_per_connection(): void {
		$this->serve();
		Wormiss_Iqa_Display::render( [ 'query' => self::QUERY ] );
		update_option( Wormiss_Settings::OPTION, array_merge( Wormiss_Settings::all(), [ 'base_url' => 'https://other.example.org' ] ) );
		$this->imis->token()->reply( '/api/iqa', 503, 'Service Unavailable' );
		$html = Wormiss_Iqa_Display::render( [ 'query' => self::QUERY ] );
		$this->assertSame( 2, $this->imis->count( '/api/iqa' ) );
		$this->assertStringNotContainsString( 'Dermoscopy Workshop', $html );
	}

	/**
	 * The shortcode loads the display stylesheet even when no block is on the page.
	 *
	 * @return void
	 */
	public function test_shortcode_enqueues_style(): void {
		$this->serve();
		wp_dequeue_style( 'wormiss-iqa-style' );
		do_shortcode( '[wormiss_iqa query="' . self::QUERY . '"]' );
		$this->assertTrue( wp_style_is( 'wormiss-iqa-style', 'enqueued' ) );
	}

	/**
	 * With no earlier copy, visitors get a polite message and only editors see the reason.
	 *
	 * @return void
	 */
	public function test_unavailable(): void {
		$this->imis->token()->reply( '/api/iqa', 500, '{"Message":"Query not found"}' );
		$html = Wormiss_Iqa_Display::render( [ 'query' => self::QUERY ] );
		$this->assertStringContainsString( 'This information isn&#039;t available right now.', $html );
		$this->assertStringNotContainsString( 'Query not found', $html );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );
		$this->imis->token()->reply( '/api/iqa', 500, '{"Message":"Query not found"}' );
		$html = Wormiss_Iqa_Display::render( [ 'query' => self::QUERY ] );
		$this->assertStringContainsString( 'Shown to editors only: HTTP 500: Query not found', $html );
	}

	/**
	 * Links only use http(s), and values are URL-encoded into them.
	 *
	 * @return void
	 */
	public function test_row_link(): void {
		$row = [ 'Key' => 'a b&c' ];
		$this->assertSame( 'https://x.example/?k=a%20b%26c', Wormiss_Iqa_Display::row_link( 'https://x.example/?k={Key}', $row ) );
		$this->assertSame( '', Wormiss_Iqa_Display::row_link( 'javascript:alert({Key})', $row ) );
		$this->assertSame( '', Wormiss_Iqa_Display::row_link( '', $row ) );
	}

	/**
	 * The block and the shortcode render the same results.
	 *
	 * @return void
	 */
	public function test_block_and_shortcode(): void {
		$this->serve();
		$block = do_blocks( '<!-- wp:wormiss/iqa {"query":"' . self::QUERY . '","columns":"EventName","layout":"list"} /-->' );
		$this->assertStringContainsString( 'wp-block-wormiss-iqa', $block );
		$this->assertStringContainsString( '<li>Dermoscopy Workshop</li>', $block );

		$short = do_shortcode( '[wormiss_iqa query="' . self::QUERY . '" columns="EventName" layout="list"]' );
		$this->assertStringContainsString( '<li>Dermoscopy Workshop</li>', $short );
		$this->assertSame( 1, $this->imis->count( '/api/iqa' ) );
	}
}
