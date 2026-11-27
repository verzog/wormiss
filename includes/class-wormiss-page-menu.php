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
 * Build a WordPress navigation menu from the imported iMIS pages.
 *
 * @package    wormiss
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined( 'ABSPATH' ) || exit;

/**
 * Keeps a WordPress navigation menu in step with the imported iMIS site tree.
 *
 * When the page import finishes a run it calls rebuild() with the navigation items in tree order.
 * This makes (or updates) a menu named in the settings, with one menu item per imported, published
 * page, nested under its parent as in iMIS and in the same order. The menu is managed by the import:
 * items that point at imported pages are kept in step and stale ones removed, but any item an editor
 * adds to the menu by hand (not pointing at an imported page) is left alone. The menu is not assigned
 * to a theme location; an editor does that once under Appearance > Menus. Nothing is assigned or
 * removed when the feature is off.
 */
class Wormiss_Page_Menu {

	/** On a nav menu item: marks it as one this import manages. */
	const ITEM_FLAG = '_wormiss_page_menu';

	/** The default menu name, used when the setting is blank. */
	const DEFAULT_NAME = 'iMIS site';

	/**
	 * Whether building the menu is turned on.
	 *
	 * @return bool
	 */
	public static function enabled(): bool {
		return (bool) Wormiss_Settings::get( 'page_import_menu' );
	}

	/**
	 * The menu's name (the setting, or the default when blank).
	 *
	 * @return string
	 */
	public static function menu_name(): string {
		$name = trim( (string) Wormiss_Settings::get( 'page_import_menu_name' ) );
		return '' !== $name ? $name : self::DEFAULT_NAME;
	}

	/**
	 * Make or update the menu from the imported pages, in the given tree order.
	 *
	 * @param string[] $ordered_keys Imported navigation items' hierarchy keys, in tree order.
	 * @return void
	 */
	public static function rebuild( array $ordered_keys ): void {
		if ( ! self::enabled() ) {
			return;
		}
		$menu = self::menu();
		if ( ! $menu instanceof WP_Term ) {
			return;
		}
		// wp_update_nav_menu_item() lives in the admin includes; the import runs in the background.
		require_once ABSPATH . 'wp-admin/includes/nav-menu.php';

		$managed  = self::managed_items( $menu->term_id );
		$item_for = []; // Hierarchy key => menu item ID (this run).
		$page_for = []; // Hierarchy key => page ID (this run).
		$kept     = []; // Menu item IDs kept this run.
		$position = 0;
		foreach ( $ordered_keys as $hkey ) {
			$page = Wormiss_Page_Import::post_for( (string) $hkey );
			// Only published pages go in the menu; a draft (not yet published) would 404 for visitors.
			if ( ! $page || 'publish' !== get_post_status( $page ) ) {
				continue;
			}
			++$position;
			$item_id = wp_update_nav_menu_item(
				$menu->term_id,
				(int) ( $managed['by_page'][ $page ] ?? 0 ),
				[
					'menu-item-object-id' => $page,
					'menu-item-object'    => 'page',
					'menu-item-type'      => 'post_type',
					'menu-item-status'    => 'publish',
					'menu-item-title'     => get_the_title( $page ),
					'menu-item-position'  => $position,
				]
			);
			if ( is_wp_error( $item_id ) || ! $item_id ) {
				continue;
			}
			$item_id = (int) $item_id;
			update_post_meta( $item_id, self::ITEM_FLAG, 1 );
			$item_for[ (string) $hkey ] = $item_id;
			$page_for[ (string) $hkey ] = $page;
			$kept[ $item_id ]           = true;
		}
		self::set_parents( $item_for, $page_for );
		// Remove menu items this import made for pages no longer in the tree.
		foreach ( $managed['ids'] as $item_id ) {
			if ( empty( $kept[ $item_id ] ) ) {
				wp_delete_post( $item_id, true );
			}
		}
	}

	/**
	 * Nest each menu item under its parent's menu item (from the page's stored parent key).
	 *
	 * @param array $item_for Hierarchy key => menu item ID.
	 * @param array $page_for Hierarchy key => page ID.
	 * @return void
	 */
	private static function set_parents( array $item_for, array $page_for ): void {
		foreach ( $item_for as $hkey => $item_id ) {
			$parent_key  = (string) get_post_meta( $page_for[ $hkey ], Wormiss_Page_Import::META_PARENT, true );
			$parent_item = ( '' !== $parent_key && isset( $item_for[ $parent_key ] ) ) ? (int) $item_for[ $parent_key ] : 0;
			// This is the field wp_update_nav_menu_item() itself writes for the parent item.
			update_post_meta( $item_id, '_menu_item_menu_item_parent', (string) $parent_item );
		}
	}

	/**
	 * The menu items this import manages: their IDs, and a page ID => item ID map for reuse.
	 *
	 * @param int $menu_id Menu term ID.
	 * @return array {ids: int[], by_page: array<int,int>}
	 */
	private static function managed_items( int $menu_id ): array {
		$ids     = [];
		$by_page = [];
		$items   = wp_get_nav_menu_items( $menu_id );
		foreach ( is_array( $items ) ? $items : [] as $item ) {
			if ( ! get_post_meta( $item->ID, self::ITEM_FLAG, true ) ) {
				continue; // Left alone: added to this menu by hand, not by the import.
			}
			$ids[] = (int) $item->ID;
			if ( 'page' === $item->object ) {
				$by_page[ (int) $item->object_id ] = (int) $item->ID;
			}
		}
		return [
			'ids'     => $ids,
			'by_page' => $by_page,
		];
	}

	/**
	 * The menu to manage, found by its name or made if missing.
	 *
	 * @return WP_Term|null
	 */
	public static function menu(): ?WP_Term {
		$menu = wp_get_nav_menu_object( self::menu_name() );
		if ( $menu instanceof WP_Term ) {
			return $menu;
		}
		$made = wp_create_nav_menu( self::menu_name() );
		if ( is_wp_error( $made ) ) {
			return null;
		}
		$menu = wp_get_nav_menu_object( (int) $made );
		return $menu instanceof WP_Term ? $menu : null;
	}
}
