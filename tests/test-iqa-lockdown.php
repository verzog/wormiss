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
 * Tests for the limits on showing approved IQA queries.
 *
 * @package    wormiss
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Approved queries show only their allowed columns, to their audience, on pages administrators
 * made, and never when they list people.
 *
 * @covers Wormiss_Iqa_Display
 * @covers Wormiss_Settings::query_rule
 * @covers Wormiss_Plugin::iqa_block_admins_only
 */
class Test_Wormiss_Iqa_Lockdown extends Wormiss_Test_Case {

	/**
	 * Set one rule for the test query.
	 *
	 * @param array $rule Rule.
	 * @return void
	 */
	private function rule( array $rule ): void {
		update_option( Wormiss_Settings::OPTION, array_merge( Wormiss_Settings::all(), [ 'query_rules' => [ self::QUERY => $rule ] ] ) );
	}

	/**
	 * Serve two rows with a personal column.
	 *
	 * @return void
	 */
	private function serve(): void {
		$this->imis->token()->reply(
			'/api/iqa',
			200,
			Wormiss_Fake_Imis::page(
				[
					[
						'EventName' => 'Dermoscopy',
						'Email'     => 'someone@example.org',
					],
				]
			)
		);
	}

	/**
	 * A newly approved query is for staff and shows no columns until an administrator says.
	 *
	 * @return void
	 */
	public function test_defaults(): void {
		$this->rule( [] );
		$this->assertSame(
			[
				'audience' => 'staff',
				'columns'  => [],
				'people'   => false,
			],
			Wormiss_Settings::query_rule( self::QUERY )
		);
		$this->assertSame( '', Wormiss_Iqa_Display::render( [ 'query' => self::QUERY ] ) );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );
		$this->assertStringContainsString( 'Choose which columns', Wormiss_Iqa_Display::render( [ 'query' => self::QUERY ] ) );
		$this->assertSame( [], $this->imis->requests );
	}

	/**
	 * Only the allowed columns reach the page, whatever the block asks for.
	 *
	 * @return void
	 */
	public function test_columns(): void {
		$this->rule(
			[
				'audience' => 'everyone',
				'columns'  => [ 'EventName' ],
			]
		);
		$this->serve();
		$html = Wormiss_Iqa_Display::render(
			[
				'query'   => self::QUERY,
				'columns' => 'EventName, Email',
			]
		);
		$this->assertStringContainsString( 'Dermoscopy', $html );
		$this->assertStringNotContainsString( 'someone@example.org', $html );
	}

	/**
	 * Members-only data needs a signed-in visitor; staff-only data an editor or administrator.
	 *
	 * @return void
	 */
	public function test_audience(): void {
		$this->rule(
			[
				'audience' => 'members',
				'columns'  => [ 'EventName' ],
			]
		);
		$this->assertSame( '', Wormiss_Iqa_Display::render( [ 'query' => self::QUERY ] ) );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );
		$this->serve();
		$this->assertStringContainsString( 'Dermoscopy', Wormiss_Iqa_Display::render( [ 'query' => self::QUERY ] ) );

		$this->rule(
			[
				'audience' => 'staff',
				'columns'  => [ 'EventName' ],
			]
		);
		$this->assertSame( '', Wormiss_Iqa_Display::render( [ 'query' => self::QUERY ] ) );
	}

	/**
	 * A query that lists people, by a column that looks personal or by an administrator's tick,
	 * never shows on the website, even to administrators.
	 *
	 * @return void
	 */
	public function test_people_never_shown(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$this->rule(
			[
				'audience' => 'everyone',
				'columns'  => [ 'EventName', 'Email' ],
			]
		);
		$this->assertTrue( Wormiss_Iqa_Display::lists_people( self::QUERY ) );
		$this->assertStringContainsString( 'lists people', Wormiss_Iqa_Display::render( [ 'query' => self::QUERY ] ) );

		$this->rule(
			[
				'audience' => 'everyone',
				'columns'  => [ 'EventName' ],
				'people'   => true,
			]
		);
		$this->assertStringContainsString( 'lists people', Wormiss_Iqa_Display::render( [ 'query' => self::QUERY ] ) );
		$this->assertSame( [], $this->imis->requests );

		foreach ( [ 'Email', 'Mobile_Phone', 'FirstName', 'BirthDate', 'Address 1', 'PartyId', 'Postcode' ] as $personal ) {
			$this->assertTrue( Wormiss_Iqa_Display::is_personal_column( $personal ), $personal );
		}
		foreach ( [ 'EventName', 'StartDate', 'City', 'Category' ] as $plain ) {
			$this->assertFalse( Wormiss_Iqa_Display::is_personal_column( $plain ), $plain );
		}
	}

	/**
	 * Only pages made and last edited by administrators show iMIS data.
	 *
	 * @return void
	 */
	public function test_placed_by_admin(): void {
		$admin   = self::factory()->user->create( [ 'role' => 'administrator' ] );
		$editor  = self::factory()->user->create( [ 'role' => 'editor' ] );
		$content = '<!-- wp:wormiss/iqa {"query":"' . self::QUERY . '"} /-->';
		$page    = static function ( int $author, int $last ) use ( $content ): int {
			$post_id = self::factory()->post->create(
				[
					'post_type'    => 'page',
					'post_author'  => $author,
					'post_status'  => 'publish',
					'post_content' => $content,
				]
			);
			update_post_meta( $post_id, '_edit_last', $last );
			return $post_id;
		};
		$show    = function ( int $post_id ): string {
			$GLOBALS['post'] = get_post( $post_id ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- The page being shown.
			$html            = Wormiss_Iqa_Display::render( [ 'query' => self::QUERY ] );
			unset( $GLOBALS['post'] );
			return $html;
		};
		$this->serve();
		$this->assertStringContainsString( 'Dermoscopy', $show( $page( $admin, $admin ) ) );
		$this->assertSame( '', $show( $page( $editor, $editor ) ) );
		$this->assertSame( '', $show( $page( $admin, $editor ) ) );
	}

	/**
	 * The settings list the pages that show each query; rules are kept for approved queries only.
	 *
	 * @return void
	 */
	public function test_where_used_and_saving(): void {
		$block = self::factory()->post->create(
			[
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_content' => '<!-- wp:group --><div><!-- wp:wormiss/iqa {"query":"' . self::QUERY . '"} /--></div><!-- /wp:group -->',
			]
		);
		$short = self::factory()->post->create(
			[
				'post_status'  => 'draft',
				'post_content' => '[wormiss_iqa query="' . self::QUERY . '" layout="list"]',
			]
		);
		$used  = Wormiss_Iqa_Display::where_used();
		$this->assertEqualsCanonicalizing( [ $block, $short ], array_keys( $used[ self::QUERY ] ) );

		$clean = Wormiss_Settings::sanitize(
			[
				'allowed_queries' => self::QUERY,
				'query_rules'     => [
					self::QUERY      => [
						'audience' => 'nobody',
						'columns'  => "EventName,\n Start Date , bad<col>",
					],
					'$/Not/Approved' => [ 'audience' => 'everyone' ],
				],
			]
		);
		$this->assertSame(
			[
				self::QUERY => [
					'audience' => 'staff',
					'columns'  => [ 'EventName', 'Start Date' ],
					'people'   => false,
				],
			],
			$clean['query_rules']
		);
	}

	/**
	 * The iMIS data block is in the inserter for administrators only.
	 *
	 * @return void
	 */
	public function test_block_inserter(): void {
		$metadata = [ 'name' => 'wormiss/iqa' ];
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );
		$this->assertFalse( Wormiss_Plugin::iqa_block_admins_only( $metadata )['supports']['inserter'] );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$this->assertSame( $metadata, Wormiss_Plugin::iqa_block_admins_only( $metadata ) );
	}
}
