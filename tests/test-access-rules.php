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
 * Tests for site-wide members-only rules (whole category/tag/post type/page tree).
 *
 * @package    wormiss
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Members-only rules.
 *
 * @covers Wormiss_Access_Rules
 */
class Test_Wormiss_Access_Rules extends Wormiss_Test_Case {

	/**
	 * Clear the per-request memo.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();
		Wormiss_Access::reset();
	}

	/**
	 * Store a set of rules (rows as the admin form submits them).
	 *
	 * @param array $rows Rule rows.
	 * @return void
	 */
	private function set_rules( array $rows ): void {
		Wormiss_Access_Rules::save( $rows );
	}

	/**
	 * A post in a given category.
	 *
	 * @param int $category Category term ID.
	 * @return int
	 */
	private function post_in_category( int $category ): int {
		$post_id = (int) self::factory()->post->create();
		wp_set_object_terms( $post_id, [ $category ], 'category' );
		return $post_id;
	}

	/**
	 * A category rule covers posts in the category and in its sub-categories, and nothing else.
	 *
	 * @return void
	 */
	public function test_category_rule_and_descendants(): void {
		$parent = (int) self::factory()->category->create();
		$child  = (int) self::factory()->category->create( [ 'parent' => $parent ] );
		$other  = (int) self::factory()->category->create();
		$this->set_rules(
			[
				[
					'kind'     => 'category',
					'ref'      => $parent,
					'types'    => 'full',
					'statuses' => '',
				],
			]
		);

		$in       = $this->post_in_category( $parent );
		$in_child = $this->post_in_category( $child );
		$outside  = $this->post_in_category( $other );

		$this->assertSame( [ 'full' ], Wormiss_Access_Rules::rule_for_post( $in )['types'], 'Direct category.' );
		$this->assertSame( [ 'full' ], Wormiss_Access_Rules::rule_for_post( $in_child )['types'], 'Sub-category.' );
		$this->assertNull( Wormiss_Access_Rules::rule_for_post( $outside ), 'Unrelated category.' );
	}

	/**
	 * A tag rule covers tagged posts.
	 *
	 * @return void
	 */
	public function test_tag_rule(): void {
		$tag     = (int) self::factory()->tag->create();
		$post_id = (int) self::factory()->post->create();
		wp_set_object_terms( $post_id, [ $tag ], 'post_tag' );
		$this->set_rules(
			[
				[
					'kind'     => 'tag',
					'ref'      => $tag,
					'types'    => '',
					'statuses' => '',
				],
			]
		);

		$this->assertNotNull( Wormiss_Access_Rules::rule_for_post( $post_id ) );
		$this->assertNull( Wormiss_Access_Rules::rule_for_post( (int) self::factory()->post->create() ) );
	}

	/**
	 * A post-type rule covers every post of that type.
	 *
	 * @return void
	 */
	public function test_post_type_rule(): void {
		$this->set_rules(
			[
				[
					'kind'     => 'post_type',
					'ref'      => 'page',
					'types'    => '',
					'statuses' => '',
				],
			]
		);
		$page = (int) self::factory()->post->create( [ 'post_type' => 'page' ] );
		$post = (int) self::factory()->post->create();
		$this->assertNotNull( Wormiss_Access_Rules::rule_for_post( $page ), 'A page is covered.' );
		$this->assertNull( Wormiss_Access_Rules::rule_for_post( $post ), 'A post is not.' );
	}

	/**
	 * A page-tree rule covers the page and its descendants.
	 *
	 * @return void
	 */
	public function test_page_tree_rule(): void {
		$parent = (int) self::factory()->post->create( [ 'post_type' => 'page' ] );
		$child  = (int) self::factory()->post->create(
			[
				'post_type'   => 'page',
				'post_parent' => $parent,
			]
		);
		$grand  = (int) self::factory()->post->create(
			[
				'post_type'   => 'page',
				'post_parent' => $child,
			]
		);
		$other  = (int) self::factory()->post->create( [ 'post_type' => 'page' ] );
		$this->set_rules(
			[
				[
					'kind'     => 'page_tree',
					'ref'      => $parent,
					'types'    => '',
					'statuses' => '',
				],
			]
		);

		$this->assertNotNull( Wormiss_Access_Rules::rule_for_post( $parent ), 'The page itself.' );
		$this->assertNotNull( Wormiss_Access_Rules::rule_for_post( $child ), 'A child page.' );
		$this->assertNotNull( Wormiss_Access_Rules::rule_for_post( $grand ), 'A grandchild page.' );
		$this->assertNull( Wormiss_Access_Rules::rule_for_post( $other ), 'An unrelated page.' );
	}

	/**
	 * The most specific matching rule wins: a category beats a whole post type.
	 *
	 * @return void
	 */
	public function test_precedence_category_over_post_type(): void {
		$category = (int) self::factory()->category->create();
		$this->set_rules(
			[
				[
					'kind'     => 'post_type',
					'ref'      => 'post',
					'types'    => 'broad',
					'statuses' => '',
				],
				[
					'kind'     => 'category',
					'ref'      => $category,
					'types'    => 'narrow',
					'statuses' => '',
				],
			]
		);
		$post_id = $this->post_in_category( $category );
		$this->assertSame( [ 'narrow' ], Wormiss_Access_Rules::rule_for_post( $post_id )['types'], 'Category wins over post type.' );
	}

	/**
	 * A post's own Members only switch overrides any site rule.
	 *
	 * @return void
	 */
	public function test_per_post_overrides_rule(): void {
		$category = (int) self::factory()->category->create();
		$this->set_rules(
			[
				[
					'kind'     => 'category',
					'ref'      => $category,
					'types'    => 'rule',
					'statuses' => '',
				],
			]
		);
		$post_id = $this->post_in_category( $category );
		update_post_meta( $post_id, Wormiss_Access::META_ENABLED, true );
		update_post_meta( $post_id, Wormiss_Access::META_TYPES, 'own' );

		$this->assertSame( [ 'own' ], Wormiss_Access::post_rules( $post_id )['types'], 'The post\'s own setting wins.' );
	}

	/**
	 * A post covered by no rule has no site rule.
	 *
	 * @return void
	 */
	public function test_no_rule(): void {
		$this->set_rules(
			[
				[
					'kind'     => 'category',
					'ref'      => (int) self::factory()->category->create(),
					'types'    => '',
					'statuses' => '',
				],
			]
		);
		$this->assertNull( Wormiss_Access_Rules::rule_for_post( (int) self::factory()->post->create() ) );
		$this->assertNull( Wormiss_Access::post_rules( (int) self::factory()->post->create() ) );
	}

	/**
	 * Saving drops malformed rows and rows pointing at content that does not exist.
	 *
	 * @return void
	 */
	public function test_sanitize_drops_invalid(): void {
		$good = (int) self::factory()->category->create();
		$this->set_rules(
			[
				[
					'kind'     => 'nonsense',
					'ref'      => 1,
					'types'    => '',
					'statuses' => '',
				],
				[
					'kind'     => 'category',
					'ref'      => 999999,
					'types'    => '',
					'statuses' => '',
				],
				[
					'kind'     => 'post_type',
					'ref'      => 'no_such_type',
					'types'    => '',
					'statuses' => '',
				],
				[
					'kind'     => 'category',
					'ref'      => $good,
					'types'    => 'full',
					'statuses' => 'active',
				],
			]
		);
		$rules = Wormiss_Access_Rules::all();
		$this->assertCount( 1, $rules, 'Only the valid rule is kept.' );
		$this->assertSame( 'category', $rules[0]['kind'] );
		$this->assertSame( $good, $rules[0]['ref'] );
		$this->assertSame( [ 'active' ], $rules[0]['statuses'] );
	}

	/**
	 * A rule gates content through the shared access check: a signed-out visitor is refused, a linked
	 * member (any current member, no type required) is allowed.
	 *
	 * @return void
	 */
	public function test_rule_gates_can_view_post(): void {
		$category = (int) self::factory()->category->create();
		$this->set_rules(
			[
				[
					'kind'     => 'category',
					'ref'      => $category,
					'types'    => '',
					'statuses' => '',
				],
			]
		);
		$post_id = $this->post_in_category( $category );

		$this->assertFalse( Wormiss_Access::can_view_post( $post_id ), 'Signed out: refused.' );

		Wormiss_Access::reset();
		$member = (int) self::factory()->user->create( [ 'role' => 'subscriber' ] );
		update_user_meta( $member, 'imis_id', '12345' );
		wp_set_current_user( $member );
		$this->assertTrue( Wormiss_Access::can_view_post( $post_id ), 'Linked member: allowed (no query needed for any-member).' );
		$this->assertSame( [], $this->imis->requests, 'iMIS not contacted without an access query.' );
	}

	/**
	 * A page-tree rule whose page is later unpublished must stay selected in the rules form, so
	 * saving the form cannot silently drop an active restriction (a draft page is still listed; a
	 * trashed one is carried as a "current selection" option).
	 *
	 * @return void
	 */
	public function test_unpublished_page_rule_survives_render(): void {
		$page = (int) self::factory()->post->create(
			[
				'post_type'  => 'page',
				'post_title' => 'Gated area',
			]
		);
		$this->set_rules(
			[
				[
					'kind'     => 'page_tree',
					'ref'      => $page,
					'types'    => '',
					'statuses' => '',
				],
			]
		);

		// Draft: get_pages() would omit it by default, but the dropdown now includes draft pages.
		wp_update_post(
			[
				'ID'          => $page,
				'post_status' => 'draft',
			]
		);
		ob_start();
		Wormiss_Admin::render_access_rules();
		$html = (string) ob_get_clean();
		$this->assertMatchesRegularExpression( '/value="page_tree:' . $page . '"[^>]*selected/', $html, 'Draft page stays selected.' );

		// Trashed: not in any list, so it is carried as its own selected option.
		wp_trash_post( $page );
		Wormiss_Access_Rules::reset();
		ob_start();
		Wormiss_Admin::render_access_rules();
		$html = (string) ob_get_clean();
		$this->assertMatchesRegularExpression( '/value="page_tree:' . $page . '"[^>]*selected/', $html, 'Trashed page carried as current selection.' );
		$this->assertStringContainsString( 'Current selection', $html );
	}

	/**
	 * The token round-trips through parse_token(); a malformed token is rejected.
	 *
	 * @return void
	 */
	public function test_token_round_trip(): void {
		$this->assertSame(
			[
				'kind' => 'category',
				'ref'  => '12',
			],
			Wormiss_Access_Rules::parse_token( Wormiss_Access_Rules::token( 'category', 12 ) )
		);
		$this->assertNull( Wormiss_Access_Rules::parse_token( 'category' ), 'No ref part.' );
		$this->assertNull( Wormiss_Access_Rules::parse_token( 'category:' ), 'Empty ref.' );
	}
}
