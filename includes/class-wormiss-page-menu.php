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

	/** Cron hook that works a batch of the menu build. */
	const HOOK = 'wormiss_page_menu';

	/** Option holding the running build (its keys, how far it has got, and the items so far). */
	const JOB_OPTION = 'wormiss_page_menu_job';

	/** Menu items placed per batch. */
	const BATCH = 50;

	/** Seconds a batch works for. */
	const SECONDS = 15;

	/** Seconds between batches. */
	const BATCH_GAP = MINUTE_IN_SECONDS;

	/**
	 * Hook in (the cron batch and a resume check).
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( self::HOOK, [ __CLASS__, 'run' ] );
		add_action( 'init', [ __CLASS__, 'maybe_resume' ] );
	}

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
	 * Menu items placed per batch (filterable, chiefly for tests).
	 *
	 * @return int
	 */
	public static function batch(): int {
		return max( 1, (int) apply_filters( 'wormiss_page_menu_batch', self::BATCH ) );
	}

	/**
	 * Queue a build of the menu from the imported pages, in tree order. The build then runs in the
	 * background, in batches, so a large tree cannot exhaust one request (and cannot hold up the
	 * import that queued it). Nothing is queued when the feature is off or there is nothing to build.
	 *
	 * @param string[] $ordered_keys Imported navigation items' hierarchy keys, in tree order.
	 * @return void
	 */
	public static function schedule( array $ordered_keys ): void {
		if ( ! self::enabled() ) {
			return;
		}
		$keys = array_values( array_unique( array_map( 'strval', $ordered_keys ) ) );
		if ( ! $keys ) {
			// Never wipe the menu from an empty list (e.g. a query that returned nothing this run).
			return;
		}
		update_option(
			self::JOB_OPTION,
			[
				'keys'  => $keys,
				'pos'   => 0,
				'items' => [],
			],
			false
		);
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_single_event( time() + 5, self::HOOK );
		}
	}

	/**
	 * Reschedule a build whose continuation was lost (e.g. the plugin was deactivated between batches).
	 *
	 * @return void
	 */
	public static function maybe_resume(): void {
		if ( self::enabled() && is_array( get_option( self::JOB_OPTION ) ) && ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_single_event( time() + self::BATCH_GAP, self::HOOK );
		}
	}

	/**
	 * The running build, or null.
	 *
	 * @return array|null
	 */
	public static function job(): ?array {
		$job = get_option( self::JOB_OPTION );
		return is_array( $job ) ? $job : null;
	}

	/**
	 * Work through a batch of the queued build: place or update each page's menu item in tree order,
	 * then reschedule. When the last item is placed, nest them and remove items for pages that have
	 * left the tree. Safe to resume: items placed in an earlier batch are reused, not duplicated.
	 *
	 * @return void
	 */
	public static function run(): void {
		$job = self::job();
		if ( ! $job ) {
			return;
		}
		if ( ! self::enabled() ) {
			self::finish();
			return;
		}
		$menu = self::menu();
		if ( ! $menu instanceof WP_Term ) {
			self::finish();
			return;
		}
		// wp_update_nav_menu_item() lives in the admin includes; the build runs in the background.
		require_once ABSPATH . 'wp-admin/includes/nav-menu.php';

		Wormiss_Batch::start( self::SECONDS );
		$managed   = self::managed_items( $menu->term_id );
		$keys      = array_map( 'strval', (array) ( $job['keys'] ?? [] ) );
		$pos       = (int) ( $job['pos'] ?? 0 );
		$items     = is_array( $job['items'] ?? null ) ? $job['items'] : [];
		$total     = count( $keys );
		$batch     = self::batch();
		$processed = 0;
		while ( $pos < $total && $processed < $batch && ! Wormiss_Batch::out_of_time() ) {
			$hkey = $keys[ $pos ];
			++$pos;
			++$processed;
			$page = Wormiss_Page_Import::post_for( $hkey );
			// Only published pages go in the menu; a draft (not yet published) would 404 for visitors.
			if ( ! $page || 'publish' !== get_post_status( $page ) ) {
				continue;
			}
			$existing = (int) ( $items[ $hkey ] ?? ( $managed['by_page'][ $page ] ?? 0 ) );
			$item_id  = wp_update_nav_menu_item(
				$menu->term_id,
				$existing,
				[
					'menu-item-object-id' => $page,
					'menu-item-object'    => 'page',
					'menu-item-type'      => 'post_type',
					'menu-item-status'    => 'publish',
					'menu-item-title'     => get_the_title( $page ),
					'menu-item-position'  => $pos,
				]
			);
			if ( is_wp_error( $item_id ) || ! $item_id ) {
				continue;
			}
			$item_id = (int) $item_id;
			update_post_meta( $item_id, self::ITEM_FLAG, 1 );
			$items[ $hkey ] = $item_id;
		}
		$job['pos']   = $pos;
		$job['items'] = $items;
		if ( $pos < $total ) {
			update_option( self::JOB_OPTION, $job, false );
			if ( ! wp_next_scheduled( self::HOOK ) ) {
				wp_schedule_single_event( time() + self::BATCH_GAP, self::HOOK );
			}
			return;
		}
		self::nest_and_prune( $menu->term_id, $items );
		self::finish();
	}

	/**
	 * Clear the running build.
	 *
	 * @return void
	 */
	private static function finish(): void {
		delete_option( self::JOB_OPTION );
		wp_clear_scheduled_hook( self::HOOK );
	}

	/**
	 * Nest each placed item under its parent's item (from the page's stored parent key), then remove
	 * items this import made for pages no longer in the tree.
	 *
	 * @param int   $menu_id Menu term ID.
	 * @param array $items   Hierarchy key => menu item ID (placed this build).
	 * @return void
	 */
	private static function nest_and_prune( int $menu_id, array $items ): void {
		foreach ( $items as $hkey => $item_id ) {
			$page        = Wormiss_Page_Import::post_for( (string) $hkey );
			$parent_key  = $page ? (string) get_post_meta( $page, Wormiss_Page_Import::META_PARENT, true ) : '';
			$parent_item = ( '' !== $parent_key && isset( $items[ $parent_key ] ) ) ? (int) $items[ $parent_key ] : 0;
			// This is the field wp_update_nav_menu_item() itself writes for the parent item.
			update_post_meta( (int) $item_id, '_menu_item_menu_item_parent', (string) $parent_item );
		}
		$kept = array_map( 'intval', array_values( $items ) );
		foreach ( self::managed_items( $menu_id )['ids'] as $item_id ) {
			if ( ! in_array( (int) $item_id, $kept, true ) ) {
				wp_delete_post( (int) $item_id, true );
			}
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
