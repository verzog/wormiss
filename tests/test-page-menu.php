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
 * Tests for building a WordPress navigation menu from the imported iMIS pages.
 *
 * @package    wormiss
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * The menu mirrors the imported tree: nested, ordered, deduplicated and pruned; manual items and
 * the off switch are respected.
 *
 * @covers Wormiss_Page_Menu
 */
class Test_Wormiss_Page_Menu extends Wormiss_Test_Case {

	/** The menu name used by the tests. */
	const MENU = 'iMIS site';

	/**
	 * Turn the menu build on.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();
		update_option(
			Wormiss_Settings::OPTION,
			array_merge(
				Wormiss_Settings::all(),
				[
					'page_import_menu'      => true,
					'page_import_menu_name' => self::MENU,
				]
			)
		);
	}

	/**
	 * Remove the menu between tests.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		delete_option( Wormiss_Page_Menu::JOB_OPTION );
		wp_clear_scheduled_hook( Wormiss_Page_Menu::HOOK );
		$menu = wp_get_nav_menu_object( self::MENU );
		if ( $menu instanceof WP_Term ) {
			wp_delete_nav_menu( $menu->term_id );
		}
		parent::tear_down();
	}

	/**
	 * Queue a build and work it to completion (small trees finish in one batch).
	 *
	 * @param string[] $keys Hierarchy keys in tree order.
	 * @return void
	 */
	private function build( array $keys ): void {
		Wormiss_Page_Menu::schedule( $keys );
		$guard = 0;
		do {
			Wormiss_Page_Menu::run();
			++$guard;
		} while ( null !== Wormiss_Page_Menu::job() && $guard < 50 );
	}

	/**
	 * An imported page: a published page carrying its hierarchy key and parent key.
	 *
	 * @param string $key        Hierarchy key.
	 * @param string $parent_key Parent hierarchy key ('' for the top level).
	 * @param string $title      Title.
	 * @param string $status     Post status.
	 * @return int
	 */
	private function page( string $key, string $parent_key, string $title, string $status = 'publish' ): int {
		$id = (int) self::factory()->post->create(
			[
				'post_type'   => 'page',
				'post_title'  => $title,
				'post_status' => $status,
			]
		);
		update_post_meta( $id, Wormiss_Page_Import::META_KEY, $key );
		update_post_meta( $id, Wormiss_Page_Import::META_PARENT, $parent_key );
		return $id;
	}

	/**
	 * The managed menu's items (those the import made), keyed by the page they point at.
	 *
	 * @return array<int,object> Page ID => menu item.
	 */
	private function managed(): array {
		$menu = wp_get_nav_menu_object( self::MENU );
		$out  = [];
		foreach ( (array) wp_get_nav_menu_items( $menu->term_id ) as $item ) {
			if ( get_post_meta( $item->ID, Wormiss_Page_Menu::ITEM_FLAG, true ) && 'page' === $item->object ) {
				$out[ (int) $item->object_id ] = $item;
			}
		}
		return $out;
	}

	/**
	 * The menu is made with one item per page, nested under its parent and ordered as given.
	 *
	 * @return void
	 */
	public function test_builds_nested_ordered_menu(): void {
		$about   = $this->page( 'a', '', 'About Us' );
		$team    = $this->page( 'b', 'a', 'Our Team' );
		$contact = $this->page( 'c', '', 'Contact' );

		$this->build( [ 'a', 'b', 'c' ] );

		$this->assertInstanceOf( WP_Term::class, wp_get_nav_menu_object( self::MENU ) );
		$items = $this->managed();
		$this->assertCount( 3, $items );

		$about_item = $items[ $about ];
		$team_item  = $items[ $team ];
		$this->assertSame( 0, (int) get_post_meta( $about_item->ID, '_menu_item_menu_item_parent', true ), 'Top-level page has no parent item.' );
		$this->assertSame( $about_item->ID, (int) get_post_meta( $team_item->ID, '_menu_item_menu_item_parent', true ), 'Child nests under its parent.' );
		$this->assertSame( 1, (int) $about_item->menu_order );
		$this->assertSame( 2, (int) $team_item->menu_order );
		$this->assertSame( 3, (int) $items[ $contact ]->menu_order );
	}

	/**
	 * A second build reuses the existing items (no duplicates) and removes items for pages that have
	 * left the tree.
	 *
	 * @return void
	 */
	public function test_reuses_and_prunes(): void {
		$about = $this->page( 'a', '', 'About Us' );
		$team  = $this->page( 'b', 'a', 'Our Team' );
		$this->build( [ 'a', 'b' ] );
		$first      = $this->managed();
		$about_item = $first[ $about ]->ID;

		// The team page leaves the tree.
		wp_delete_post( $team, true );
		$this->build( [ 'a' ] );

		$second = $this->managed();
		$this->assertCount( 1, $second, 'The item for the removed page is pruned.' );
		$this->assertArrayHasKey( $about, $second );
		$this->assertSame( $about_item, $second[ $about ]->ID, 'The surviving item is reused, not duplicated.' );
	}

	/**
	 * Draft (not yet published) pages are left out of the menu.
	 *
	 * @return void
	 */
	public function test_skips_drafts(): void {
		$this->page( 'a', '', 'About Us' );
		$this->page( 'b', '', 'Draft', 'draft' );
		$this->build( [ 'a', 'b' ] );
		$this->assertCount( 1, $this->managed(), 'Only the published page is in the menu.' );
	}

	/**
	 * An item an editor adds to the menu by hand is left in place by a rebuild.
	 *
	 * @return void
	 */
	public function test_leaves_manual_items(): void {
		$this->page( 'a', '', 'About Us' );
		$this->build( [ 'a' ] );

		$menu   = wp_get_nav_menu_object( self::MENU );
		$manual = wp_update_nav_menu_item(
			$menu->term_id,
			0,
			[
				'menu-item-title'  => 'Hand-added',
				'menu-item-url'    => 'https://example.org/',
				'menu-item-status' => 'publish',
			]
		);
		$this->assertIsInt( $manual );

		$this->build( [ 'a' ] );
		$ids = array_map( static fn( $i ) => (int) $i->ID, (array) wp_get_nav_menu_items( $menu->term_id ) );
		$this->assertContains( (int) $manual, $ids, 'The hand-added item survives a rebuild.' );
	}

	/**
	 * A build spanning several batches finishes and nests correctly: it is resumable and does not
	 * depend on placing the whole tree in one request.
	 *
	 * @return void
	 */
	public function test_resumable_across_batches(): void {
		$about = $this->page( 'a', '', 'About Us' );
		$team  = $this->page( 'b', 'a', 'Our Team' );
		$this->page( 'c', '', 'Contact' );

		$one = static fn() => 1;
		add_filter( 'wormiss_page_menu_batch', $one );

		Wormiss_Page_Menu::schedule( [ 'a', 'b', 'c' ] );
		Wormiss_Page_Menu::run();
		$this->assertNotNull( Wormiss_Page_Menu::job(), 'The build is still going after one batch.' );
		$this->assertCount( 1, $this->managed(), 'One item placed per batch.' );

		Wormiss_Page_Menu::run();
		Wormiss_Page_Menu::run();
		remove_filter( 'wormiss_page_menu_batch', $one );

		$this->assertNull( Wormiss_Page_Menu::job(), 'The build finishes after enough batches.' );
		$items = $this->managed();
		$this->assertCount( 3, $items );
		$this->assertSame(
			$items[ $about ]->ID,
			(int) get_post_meta( $items[ $team ]->ID, '_menu_item_menu_item_parent', true ),
			'Nesting is applied once the build finishes.'
		);
	}

	/**
	 * With the feature off, no menu is made.
	 *
	 * @return void
	 */
	public function test_off(): void {
		update_option( Wormiss_Settings::OPTION, array_merge( Wormiss_Settings::all(), [ 'page_import_menu' => false ] ) );
		$this->page( 'a', '', 'About Us' );
		$this->build( [ 'a' ] );
		$this->assertFalse( wp_get_nav_menu_object( self::MENU ), 'No menu is created when the feature is off.' );
	}
}
