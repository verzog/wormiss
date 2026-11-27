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
 * Import an iMIS website's pages into WordPress, keeping the menu hierarchy.
 *
 * @package    wormiss
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined( 'ABSPATH' ) || exit;

/**
 * Each navigation item an administrator's IQA lists becomes a WordPress page, nested under its
 * parent as it is in iMIS.
 *
 * A website is kept in iMIS as a tree of navigation items (DocumentTypeCode "NAV"). Each one carries a
 * breadcrumb name, its place in the tree (its own HierarchyKey and its parent's AdjacentHierarchyKey)
 * and the content page it shows (RelatedDocumentVersionKey). The content page (a "CON" document) holds
 * the HTML, in the same RiSE format the news import reads, so its body is tidied the same way. A page
 * whose content is made of iMIS iParts (a sign-in form, a map, a cart) has no portable HTML, so it is
 * imported as an empty page in the right place in the tree, for an editor to fill in.
 *
 * iMIS is the source: a later import updates the page, unless an editor ticks "Stop updating from iMIS"
 * on it. Nothing is changed in iMIS; pages are never removed. The import reads only, runs as a throttled
 * resumable background job, has a dry run and a config kill-switch, and rebuilds the parent of each page
 * at the end of a run (a child may be read before its parent).
 */
class Wormiss_Page_Import {

	/** The daily import. */
	const HOOK = 'wormiss_page_import';

	/** The next batch of an import. */
	const MORE_HOOK = 'wormiss_page_import_more';

	/** The last run's report. */
	const REPORT_OPTION = 'wormiss_page_import_report';

	/** The navigation list read at the start of an import, for its later batches. */
	const LIST_OPTION = 'wormiss_page_import_list';

	/** Navigation items earlier batches of an import dealt with. */
	const DONE_OPTION = 'wormiss_page_import_done';

	/** The lock that stops two imports running at once. */
	const LOCK_OPTION = 'wormiss_page_import_lock';

	/** The item being imported, and how many batches have tried it. */
	const WORKING_OPTION = 'wormiss_page_import_working';

	/** On a page: the navigation item's iMIS HierarchyKey (stable; matches a page to its iMIS node). */
	const META_KEY = '_wormiss_page_key';

	/** On a page: its parent navigation item's HierarchyKey (for rebuilding the tree). */
	const META_PARENT = '_wormiss_page_parent';

	/** On a page: a fingerprint of what it last took from iMIS. */
	const META_SOURCE = '_wormiss_page_source';

	/** On a page: ticked by an editor so later imports leave it alone. */
	const META_DETACHED = '_wormiss_page_detached';

	/** On a page: the page's address on the iMIS site. */
	const META_URL = '_wormiss_page_url';

	/** On an imported image: the iMIS address it was copied from (so an image is copied once). */
	const META_FROM = '_wormiss_page_image_from';

	/** Pages made or updated per batch. */
	const BATCH = 5;

	/** Navigation items checked per batch (each is one or two iMIS reads, changed or not). */
	const MAX_CHECKS = 20;

	/** Images copied per batch. */
	const MAX_IMAGES = 6;

	/** Seconds between batches. */
	const BATCH_GAP = 2 * MINUTE_IN_SECONDS;

	/** How long an import's navigation list is used by its later batches. */
	const LIST_MAX_AGE = 30 * MINUTE_IN_SECONDS;

	/** Seconds after which a lock left by a run that died is taken over. */
	const LOCK_SECONDS = 300;

	/** Most IQA rows read. */
	const MAX_ROWS = 5000;

	/** Most notes kept in a report. */
	const MAX_NOTES = 20;

	/** An all-zero GUID: iMIS's "no key" (a navigation item with no content page, or the tree root). */
	const EMPTY_KEY = '00000000-0000-0000-0000-000000000000';

	/**
	 * Bumped when a change means every page should be reprocessed on the next import even if its iMIS
	 * content has not changed. It is part of each page's source fingerprint, so raising it makes the
	 * stored fingerprints no longer match. (2: copy page images into the Media Library.)
	 */
	const SOURCE_VERSION = 2;

	/**
	 * Images copied so far in this run.
	 *
	 * @var int
	 */
	private static $images = 0;

	/**
	 * Hook in.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( self::HOOK, [ __CLASS__, 'run' ] );
		add_action( self::MORE_HOOK, [ __CLASS__, 'run_more' ] );
		add_action( 'init', [ __CLASS__, 'maybe_schedule' ] );
		add_action( 'admin_post_wormiss_page_import_now', [ __CLASS__, 'handle_run_now' ] );
		add_action( 'add_meta_boxes_page', [ __CLASS__, 'add_box' ] );
		add_action( 'save_post_page', [ __CLASS__, 'save_box' ] );
	}

	/**
	 * Keep the daily import scheduled while it is on, and none while it is off.
	 *
	 * @return void
	 */
	public static function maybe_schedule(): void {
		$next = wp_next_scheduled( self::HOOK );
		if ( Wormiss_Settings::get( 'page_import_enabled' ) && ! $next ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::HOOK );
		} elseif ( ! Wormiss_Settings::get( 'page_import_enabled' ) && $next ) {
			wp_clear_scheduled_hook( self::HOOK );
		}
	}

	/**
	 * The Import now button (Settings): import and go back.
	 *
	 * @return void
	 */
	public static function handle_run_now(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'wormiss' ), 403 );
		}
		check_admin_referer( 'wormiss_page_import_now' );
		self::run();
		wp_safe_redirect( Wormiss_Admin::settings_url( 'pages' ) . '#wormiss-page-import' );
		exit;
	}

	/**
	 * The next batch of an import (cron).
	 *
	 * @return array The report.
	 */
	public static function run_more(): array {
		return self::run( true );
	}

	/**
	 * Import iMIS pages, one batch at a time. Nothing changes if iMIS cannot be read.
	 *
	 * @param bool $carry_on Carry on from the last batch (else start again).
	 * @return array The report (also saved for the settings page).
	 */
	public static function run( $carry_on = false ): array {
		$done   = true === $carry_on ? get_option( self::DONE_OPTION, [] ) : [];
		$done   = is_array( $done ) ? $done : [];
		$report = [
			'time'      => time(),
			'status'    => 'ok',
			'dry_run'   => (bool) Wormiss_Settings::get( 'page_import_dry_run' ),
			'read'      => 0,
			'created'   => 0,
			'updated'   => 0,
			'skipped'   => 0,
			'empty'     => 0,
			'remaining' => 0,
			'notes'     => [],
		];
		if ( ! Wormiss_Settings::get( 'page_import_enabled' ) || ! Wormiss_Settings::is_configured()
			|| '' === (string) Wormiss_Settings::get( 'page_import_query' ) ) {
			$report['status'] = 'off';
			return self::save( $report );
		}
		if ( true === $carry_on && false === get_option( self::LIST_OPTION ) ) {
			$report['status'] = 'done'; // Finished already: nothing to carry on with.
			return $report;
		}
		if ( ! Wormiss_Event_Import::take_lock( self::LOCK_OPTION, self::LOCK_SECONDS ) ) {
			$report['status'] = 'busy';
			if ( true === $carry_on && ! wp_next_scheduled( self::MORE_HOOK ) ) {
				wp_schedule_single_event( time() + self::BATCH_GAP, self::MORE_HOOK );
			}
			return $report;
		}
		Wormiss_Batch::start();
		try {
			return self::import( $report, $done, true === $carry_on );
		} finally {
			delete_option( self::LOCK_OPTION );
		}
	}

	/**
	 * Read the navigation list (or use the one read at the start of this import) and write a batch.
	 *
	 * @param array $report   Report so far.
	 * @param array $done     Navigation keys earlier batches dealt with (key => true).
	 * @param bool  $carry_on A later batch.
	 * @return array The report.
	 */
	private static function import( array $report, array $done, bool $carry_on ): array {
		$client = new Wormiss_Rest_Client();
		$kept   = $carry_on ? get_option( self::LIST_OPTION ) : false;
		if ( is_array( $kept ) && (int) ( $kept['time'] ?? 0 ) > time() - self::LIST_MAX_AGE && is_array( $kept['nodes'] ?? null ) ) {
			return self::write( $client, $report, $done, $kept['nodes'], (int) $kept['time'] );
		}
		try {
			$rows = $client->run_iqa( (string) Wormiss_Settings::get( 'page_import_query' ), [], null, self::MAX_ROWS );
		} catch ( Wormiss_Exception $e ) {
			$report['status']  = 'error';
			$report['notes'][] = $e->getMessage();
			return self::save( $report );
		}
		$nodes = self::nav_list( $rows );
		if ( ! $nodes && $rows ) {
			$report['status']  = 'error';
			$report['notes'][] = __( 'The page query has no Hierarchy Key column, so nothing was imported.', 'wormiss' );
			return self::save( $report );
		}
		return self::write( $client, $report, $done, $nodes, time() );
	}

	/**
	 * The navigation items in the IQA's rows: one per Hierarchy Key, with its version key and name.
	 * Column names are matched loosely ("Hierarchy Key", "HierarchyKey" and "hierarchykey" are the
	 * same).
	 *
	 * @param array[] $rows IQA rows.
	 * @return array[] hkey => key (hkey), version, content, parent, title.
	 */
	public static function nav_list( array $rows ): array {
		$out = [];
		foreach ( $rows as $row ) {
			$cells = [];
			foreach ( (array) $row as $name => $value ) {
				$cells[ strtolower( (string) preg_replace( '/[^A-Za-z0-9]/', '', (string) $name ) ) ] = is_scalar( $value ) ? trim( (string) $value ) : '';
			}
			$hkey = strtolower( $cells['hierarchykey'] ?? '' );
			if ( ! Wormiss_Content_Check::is_key( $hkey ) || isset( $out[ $hkey ] ) ) {
				continue;
			}
			$version      = strtolower( $cells['documentversionkey'] ?? ( $cells['documentversionid'] ?? '' ) );
			$content      = strtolower( $cells['relateddocumentversionkey'] ?? '' );
			$parent       = strtolower( $cells['parenthierarchykey'] ?? ( $cells['adjacenthierarchykey'] ?? '' ) );
			$out[ $hkey ] = [
				'key'     => $hkey,
				'version' => Wormiss_Content_Check::is_key( $version ) ? $version : '',
				// A content version and a parent may come from the query (if its columns include
				// them) or, failing that, from the navigation document read below.
				'content' => ( Wormiss_Content_Check::is_key( $content ) && self::EMPTY_KEY !== $content ) ? $content : '',
				'parent'  => ( Wormiss_Content_Check::is_key( $parent ) && self::EMPTY_KEY !== $parent ) ? $parent : '',
				'title'   => sanitize_text_field( $cells['alternatename'] ?? ( $cells['name'] ?? '' ) ),
			];
		}
		return $out;
	}

	/**
	 * Make or update one batch of pages, then (when a run finishes) rebuild the tree.
	 *
	 * @param Wormiss_Rest_Client $client Client.
	 * @param array               $report Report so far.
	 * @param array               $done   Navigation keys earlier batches dealt with.
	 * @param array[]             $nodes  From nav_list().
	 * @param int                 $read   When the list was read.
	 * @return array The report.
	 */
	private static function write( Wormiss_Rest_Client $client, array $report, array $done, array $nodes, int $read ): array {
		self::$images   = 0;
		$report['read'] = count( $nodes );
		$writes         = 0;
		$checked        = 0;
		$todo           = 0;
		update_option(
			self::LIST_OPTION,
			[
				'time'  => $read,
				'nodes' => $nodes,
			],
			false
		);
		if ( ! wp_next_scheduled( self::MORE_HOOK ) ) {
			wp_schedule_single_event( time() + self::BATCH_GAP, self::MORE_HOOK );
		}
		foreach ( $nodes as $hkey => $node ) {
			if ( isset( $done[ $hkey ] ) ) {
				continue;
			}
			if ( $writes >= self::BATCH || $checked >= self::MAX_CHECKS || Wormiss_Batch::stopped() ) {
				++$todo;
				continue;
			}
			$done[ $hkey ] = true;
			if ( Wormiss_Batch::begin( self::WORKING_OPTION, (string) $hkey ) >= Wormiss_Batch::MAX_TRIES ) {
				Wormiss_Batch::end( self::WORKING_OPTION );
				++$report['skipped'];
				/* translators: %s: page title. */
				self::note( $report, sprintf( __( '%s: skipped, as two batches could not finish it (too big or too slow for one go); tried again next import.', 'wormiss' ), self::label( $node ) ) );
				update_option( self::DONE_OPTION, $done, false );
				continue;
			}
			++$checked;
			$result = self::one( $client, $node, $report );
			Wormiss_Batch::done( self::WORKING_OPTION );
			if ( 'created' === $result || 'updated' === $result ) {
				++$writes;
				++$report[ $result ];
			} else {
				++$report['skipped'];
			}
			if ( Wormiss_Batch::put_off() ) {
				unset( $done[ $hkey ] );
				++$todo;
			}
			update_option( self::DONE_OPTION, $done, false );
		}
		$report['remaining'] = $todo;
		if ( 0 === $todo ) {
			if ( empty( $report['dry_run'] ) ) {
				self::relink( $nodes );
				// Queue a rebuild of the WordPress navigation menu from the tree, if that is turned on
				// (it runs in its own background batches, so it never holds up this import).
				Wormiss_Page_Menu::schedule( array_keys( $nodes ) );
			}
			delete_option( self::DONE_OPTION );
			delete_option( self::LIST_OPTION );
			wp_clear_scheduled_hook( self::MORE_HOOK );
		}
		return self::save( $report );
	}

	/**
	 * Make or update the page for one navigation item.
	 *
	 * @param Wormiss_Rest_Client $client Client.
	 * @param array               $node   From nav_list().
	 * @param array               $report Report, for notes.
	 * @return string created, updated or skipped.
	 */
	private static function one( Wormiss_Rest_Client $client, array $node, array &$report ): string {
		$post_id = self::post_for( $node['key'] );
		if ( $post_id && ( 'trash' === get_post_status( $post_id ) || get_post_meta( $post_id, self::META_DETACHED, true ) ) ) {
			return 'skipped';
		}
		if ( '' === $node['version'] ) {
			/* translators: %s: page title. */
			self::note( $report, sprintf( __( '%s: no Document Version Key in the query, so it was not read.', 'wormiss' ), self::label( $node ) ) );
			return 'skipped';
		}
		try {
			$nav = $client->find_document( $node['version'] );
		} catch ( Wormiss_Exception $e ) {
			/* translators: 1: page title, 2: reason. */
			self::note( $report, sprintf( __( '%1$s: not read from iMIS (%2$s).', 'wormiss' ), self::label( $node ), $e->getMessage() ) );
			return 'skipped';
		}
		if ( null === $nav ) {
			/* translators: %s: page title. */
			self::note( $report, sprintf( __( '%s: its iMIS navigation item could not be read, so not imported.', 'wormiss' ), self::label( $node ) ) );
			return 'skipped';
		}
		$navigation = self::parse_navigation( self::document_data( $nav ) );
		// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- iMIS fields.
		// The content page and parent may come from the query's own columns; failing that, from the
		// navigation document. The REST DocumentData names it RelatedDocumentVersionId (the XML export
		// uses RelatedDocumentVersionKey, so accept either); the parent is in the Navigation blob.
		$related = strtolower( trim( (string) ( $nav->RelatedDocumentVersionId ?? ( $nav->RelatedDocumentVersionKey ?? '' ) ) ) );
		$content = '' !== $node['content'] ? $node['content']
			: ( ( Wormiss_Content_Check::is_key( $related ) && self::EMPTY_KEY !== $related ) ? $related : '' );
		$parent  = '' !== $node['parent'] ? $node['parent'] : $navigation['parent'];
		$title   = '' !== $navigation['title'] ? $navigation['title']
			: ( '' !== $node['title'] ? $node['title'] : sanitize_text_field( (string) ( $nav->AlternateName ?? '' ) ) );
		$path    = (string) ( $nav->Path ?? '' );
		// phpcs:enable
		$built = self::content( $client, $content, $title, $report, self::label( $node ) );
		if ( null === $built ) {
			// The content could not be read this time (content() noted why): leave the page as it is,
			// so a temporary iMIS problem does not blank an existing page. Tried again next import.
			return 'skipped';
		}
		$hash = md5( (string) wp_json_encode( [ self::SOURCE_VERSION, $title, $built['html'], $built['members'], $parent, $path ] ) );
		if ( $post_id && get_post_meta( $post_id, self::META_SOURCE, true ) === $hash
			&& get_post_meta( $post_id, self::META_PARENT, true ) === $parent ) {
			return 'skipped';
		}
		if ( '' === $built['html'] ) {
			++$report['empty'];
		}
		if ( ! empty( $report['dry_run'] ) ) {
			self::note(
				$report,
				sprintf(
					/* translators: 1: would make or update, 2: page title, 3: empty-body note. */
					__( 'Dry run: would %1$s "%2$s"%3$s.', 'wormiss' ),
					$post_id ? __( 'update', 'wormiss' ) : __( 'make', 'wormiss' ),
					$title,
					'' === $built['html'] ? __( ' (no page content in iMIS: an empty page)', 'wormiss' ) : ''
				)
			);
			return $post_id ? 'updated' : 'created';
		}
		$fields = [
			'post_type'    => 'page',
			'post_title'   => $title,
			'post_content' => $built['html'],
		];
		if ( $post_id ) {
			$fields['ID'] = $post_id;
			$saved        = wp_update_post( wp_slash( $fields ), true );
		} else {
			$fields['post_status'] = Wormiss_Settings::get( 'page_import_publish' ) ? 'publish' : 'draft';
			$fields['post_author'] = self::author();
			$fields['meta_input']  = [ self::META_KEY => $node['key'] ];
			$saved                 = wp_insert_post( wp_slash( $fields ), true );
		}
		if ( is_wp_error( $saved ) || ! $saved ) {
			$reason = is_wp_error( $saved ) ? $saved->get_error_message() : __( 'not saved', 'wormiss' );
			/* translators: 1: page title, 2: reason. */
			self::note( $report, sprintf( __( '%1$s: no page saved (%2$s).', 'wormiss' ), $title, $reason ) );
			return 'skipped';
		}
		$saved = (int) $saved;
		update_post_meta( $saved, self::META_PARENT, $parent );
		update_post_meta( $saved, Wormiss_Access::META_ENABLED, $built['members'] );
		update_post_meta( $saved, self::META_URL, Wormiss_News_Import::page_url( $path ) );
		$complete = self::images( $saved, $built['html'], self::label( $node ), $report );
		// Record the fingerprint only once every image is copied, so an image that did not fit this
		// batch or failed is tried again next import.
		if ( $complete ) {
			update_post_meta( $saved, self::META_SOURCE, $hash );
		} else {
			delete_post_meta( $saved, self::META_SOURCE );
		}
		return $post_id ? 'updated' : 'created';
	}

	/**
	 * Copy the page's images on the iMIS site into the Media Library (each address once, across all
	 * pages) and point the page's content at the copies. Images on other sites are left where they
	 * are. Copying can be turned off with the wormiss_page_import_images filter.
	 *
	 * @param int    $post_id Page ID.
	 * @param string $html    The page body (with iMIS image addresses).
	 * @param string $label   The page's name, for notes.
	 * @param array  $report  Report, for notes.
	 * @return bool Whether every image is done (false when one did not fit this batch or failed).
	 */
	private static function images( int $post_id, string $html, string $label, array &$report ): bool {
		/**
		 * Whether imported pages copy their images into the Media Library.
		 *
		 * @param bool $copy Copy images (default true).
		 */
		if ( ! apply_filters( 'wormiss_page_import_images', true ) ) {
			return true;
		}
		$base = Wormiss_Settings::base_url();
		// Match a double- or single-quoted src (clean() double-quotes attributes, but accept either so
		// a change to how the body is built cannot silently skip an image).
		if ( '' === $base || ! preg_match_all( '/<img\b[^>]*?\bsrc=(["\'])(.*?)\1/i', $html, $matches, PREG_SET_ORDER ) ) {
			return true;
		}
		$complete = true;
		$original = $html;
		$seen     = [];
		foreach ( $matches as $match ) {
			$quote = $match[1];
			$raw   = $match[2];
			if ( isset( $seen[ $quote . $raw ] ) ) {
				continue; // The same address already handled in this body.
			}
			$seen[ $quote . $raw ] = true;
			$url                   = html_entity_decode( $raw, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
			if ( 0 !== strpos( $url, $base . '/' ) ) {
				continue; // Images on other sites stay where they are.
			}
			$attachment = self::copy( $post_id, $url, $label, $report, $complete );
			if ( $attachment ) {
				$local = esc_attr( (string) wp_get_attachment_url( $attachment ) );
				$html  = str_replace( 'src=' . $quote . $raw . $quote, 'src="' . $local . '"', $html );
			}
		}
		if ( $html !== $original ) {
			wp_update_post(
				wp_slash(
					[
						'ID'           => $post_id,
						'post_content' => $html,
					]
				)
			);
		}
		return $complete;
	}

	/**
	 * The Media Library copy of an iMIS image: one already copied, or a new copy (if this batch has
	 * room).
	 *
	 * @param int    $post_id  Page the image belongs to.
	 * @param string $url      Image address.
	 * @param string $label    The page's name, for notes.
	 * @param array  $report   Report, for notes.
	 * @param bool   $complete Set to false when it was not copied (no room, or it failed).
	 * @return int Attachment ID, or 0.
	 */
	private static function copy( int $post_id, string $url, string $label, array &$report, bool &$complete ): int {
		// phpcs:disable WordPress.DB.SlowDBQuery -- One lookup per image.
		$found = get_posts(
			[
				'post_type'        => 'attachment',
				'post_status'      => 'inherit',
				'meta_key'         => self::META_FROM,
				'meta_value'       => $url,
				'numberposts'      => 1,
				'fields'           => 'ids',
				'suppress_filters' => true,
			]
		);
		// phpcs:enable
		if ( $found ) {
			return (int) $found[0];
		}
		if ( self::$images >= self::MAX_IMAGES || Wormiss_Batch::out_of_time() ) {
			Wormiss_Batch::stop();
			$complete = false;
			return 0;
		}
		++self::$images;
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		$tmp = download_url( $url, 30 );
		if ( is_wp_error( $tmp ) ) {
			$complete = false; // Tried again next import.
			/* translators: 1: page title, 2: reason. */
			self::note( $report, sprintf( __( '%1$s: an image could not be copied (%2$s).', 'wormiss' ), $label, $tmp->get_error_message() ) );
			return 0;
		}
		// iMIS image URLs often have no usable extension (a "GetImage" handler) or a misleading one, so
		// name the file from its real type (see Wormiss_Media::sideload_name()).
		$name   = Wormiss_Media::sideload_name( $tmp, $url, 'page-image' );
		$is_svg = false;
		if ( null === $name ) {
			// An SVG (which WordPress blocks by default) is sanitised and imported if SVG import is on.
			$name = Wormiss_Media::svg_name( $tmp, $url, 'page-image' );
			if ( null === $name ) {
				// Not an image type WordPress accepts: leave the page pointing at the iMIS image
				// rather than failing the page and retrying it every import.
				wp_delete_file( $tmp );
				/* translators: %s: page title. */
				self::note( $report, sprintf( __( '%s: an image is not a supported type, so it was left on the iMIS site.', 'wormiss' ), $label ) );
				return 0;
			}
			$is_svg = true;
		}
		$attachment = Wormiss_Media::handle_sideload(
			[
				'name'     => $name,
				'tmp_name' => $tmp,
			],
			$post_id,
			$label,
			$is_svg
		);
		if ( is_wp_error( $attachment ) ) {
			$complete = false; // Tried again next import.
			wp_delete_file( $tmp );
			/* translators: 1: page title, 2: reason. */
			self::note( $report, sprintf( __( '%1$s: an image could not be added (%2$s).', 'wormiss' ), $label, $attachment->get_error_message() ) );
			return 0;
		}
		update_post_meta( (int) $attachment, self::META_FROM, $url );
		Wormiss_Batch::progress();
		return (int) $attachment;
	}

	/**
	 * The content page a navigation item shows, tidied: its HTML and whether it is members-only.
	 *
	 * A navigation item with no content page (an iParts-only or container page) gives a legitimately
	 * empty page. A read that fails, or a content document that is missing, not published or
	 * unreadable, returns null: the caller leaves the page unchanged rather than blanking it, so a
	 * temporary iMIS problem cannot erase a published page's content or change its access.
	 *
	 * @param Wormiss_Rest_Client $client  Client.
	 * @param string              $version The content document's version key ('' for none).
	 * @param string              $title   The page title (so a title-only heading block is left out).
	 * @param array               $report  Report, for notes.
	 * @param string              $label   The page's name, for notes.
	 * @return array|null html, members; or null when the content could not be read.
	 */
	private static function content( Wormiss_Rest_Client $client, string $version, string $title, array &$report, string $label ): ?array {
		if ( '' === $version ) {
			// No content page: a legitimately empty page (a menu folder or an iParts-only page).
			return [
				'html'    => '',
				'members' => false,
			];
		}
		try {
			$document = $client->find_document( $version );
		} catch ( Wormiss_Exception $e ) {
			/* translators: 1: page title, 2: reason. */
			self::note( $report, sprintf( __( '%1$s: its page content was not read (%2$s); the page was left unchanged.', 'wormiss' ), $label, $e->getMessage() ) );
			return null;
		}
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- iMIS field.
		if ( null === $document || 'Published' !== (string) ( $document->Status ?? '' ) ) {
			/* translators: %s: page title. */
			self::note( $report, sprintf( __( '%s: its iMIS page content is missing or not published; the page was left unchanged.', 'wormiss' ), $label ) );
			return null;
		}
		$content = Wormiss_News_Import::parse( self::document_data( $document ) );
		if ( null === $content ) {
			/* translators: %s: page title. */
			self::note( $report, sprintf( __( '%s: its iMIS page content could not be read; the page was left unchanged.', 'wormiss' ), $label ) );
			return null;
		}
		return [
			'html'    => self::build_body( $title, $content ),
			'members' => (bool) $content['members'],
		];
	}

	/**
	 * A document's content (its Data, decoded from base 64). DocumentData sends it as a typed value.
	 *
	 * @param object $document DocumentData.
	 * @return string
	 */
	private static function document_data( object $document ): string {
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- iMIS field.
		$data = $document->Data ?? '';
		$data = is_object( $data ) && property_exists( $data, '$value' ) ? $data->{'$value'} : $data;
		return Wormiss_Content_Check::decode_blob( is_string( $data ) ? $data : '', false );
	}

	/**
	 * The page body from the content page's blocks (in page order), tidied as the news import tidies
	 * article text; a block that is only the page title as a heading is left out.
	 *
	 * @param string $title   Page title.
	 * @param array  $content From Wormiss_News_Import::parse().
	 * @return string
	 */
	public static function build_body( string $title, array $content ): string {
		$items = (array) ( $content['items'] ?? [] );
		if ( Wormiss_Page_Blocks::enabled() ) {
			// Rebuild the iMIS column layout as block markup (see Wormiss_Page_Blocks).
			return Wormiss_Page_Blocks::build( $title, $items );
		}
		$parts = [];
		foreach ( $items as $item ) {
			$html = Wormiss_News_Import::clean( (string) ( $item['html'] ?? '' ) );
			if ( '' === $html ) {
				continue;
			}
			if ( preg_match( '#^<h[1-6]>(.*)</h[1-6]>$#s', $html, $match )
				&& 0 === strcasecmp( self::plain( $match[1] ), self::plain( $title ) ) ) {
				continue;
			}
			$parts[] = $html;
		}
		return implode( "\n\n", $parts );
	}

	/**
	 * The parts of a navigation item from its Navigation blob: its breadcrumb name and its parent in
	 * the tree (the AdjacentHierarchyKey). The content page it shows is a document-level field
	 * (RelatedDocumentVersionKey), not in this blob, so it is read by the caller.
	 *
	 * @param string $xml The navigation item (the document's Data, decoded).
	 * @return array title, parent (a hierarchy key, '' for the root).
	 */
	public static function parse_navigation( string $xml ): array {
		$out = [
			'title'  => '',
			'parent' => '',
		];
		if ( '' === trim( $xml ) || ! class_exists( 'DOMDocument' ) ) {
			return $out;
		}
		$dom      = new DOMDocument();
		$previous = libxml_use_internal_errors( true );
		$loaded   = $dom->loadXML( $xml, LIBXML_NONET );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- DOM property.
		$root = $dom->documentElement;
		if ( ! $loaded || ! $root instanceof DOMElement ) {
			return $out;
		}
		$out['title']  = sanitize_text_field( self::child( $root, 'BreadCrumbName' ) );
		$parent        = strtolower( trim( self::child( $root, 'AdjacentHierarchyKey' ) ) );
		$out['parent'] = ( Wormiss_Content_Check::is_key( $parent ) && self::EMPTY_KEY !== $parent ) ? $parent : '';
		return $out;
	}

	/**
	 * The text of a node's first descendant with this local name ('' if none).
	 *
	 * @param DOMElement $node Node.
	 * @param string     $name Local name.
	 * @return string
	 */
	private static function child( DOMElement $node, string $name ): string {
		foreach ( $node->getElementsByTagNameNS( '*', $name ) as $found ) {
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- DOM property.
			return (string) $found->textContent;
		}
		return '';
	}

	/**
	 * Rebuild the parent of every imported page from the navigation tree: a page whose parent is
	 * another imported page is nested under it; one whose parent is the tree root (or not imported)
	 * sits at the top. Done at the end of a run, so a child read before its parent is still nested.
	 *
	 * @param array[] $nodes From nav_list() (unused; the pages carry their own keys).
	 * @return void
	 */
	private static function relink( array $nodes ): void {
		unset( $nodes );
		$pages = get_posts(
			[
				'post_type'        => 'page',
				'post_status'      => [ 'publish', 'future', 'draft', 'pending', 'private' ],
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- One-off after an import.
				'meta_key'         => self::META_KEY,
				'numberposts'      => -1,
				'fields'           => 'ids',
				'suppress_filters' => true,
			]
		);
		$by_key = [];
		foreach ( array_map( 'intval', $pages ) as $id ) {
			$key = (string) get_post_meta( $id, self::META_KEY, true );
			if ( '' !== $key ) {
				$by_key[ $key ] = $id;
			}
		}
		foreach ( array_map( 'intval', $pages ) as $id ) {
			// A detached page keeps the parent an editor gave it (as one() leaves its content alone);
			// it stays in $by_key above, so other imported pages can still nest beneath it.
			if ( get_post_meta( $id, self::META_DETACHED, true ) ) {
				continue;
			}
			$parent_key = (string) get_post_meta( $id, self::META_PARENT, true );
			$parent     = ( '' !== $parent_key && isset( $by_key[ $parent_key ] ) && $by_key[ $parent_key ] !== $id ) ? $by_key[ $parent_key ] : 0;
			if ( (int) get_post_field( 'post_parent', $id ) !== $parent ) {
				wp_update_post(
					[
						'ID'          => $id,
						'post_parent' => $parent,
					]
				);
			}
		}
	}

	/**
	 * Who imported pages are by: the chosen news author if they can still write, else the first
	 * administrator. Background batches run with no one signed in, so this is always set.
	 *
	 * @return int User ID (0 only if the site has no administrator).
	 */
	public static function author(): int {
		return Wormiss_News_Import::author();
	}

	/**
	 * The page made for a navigation item (trashed ones too, so a page someone deleted is not made
	 * again), or 0.
	 *
	 * @param string $key Hierarchy key.
	 * @return int
	 */
	public static function post_for( string $key ): int {
		// phpcs:disable WordPress.DB.SlowDBQuery -- One meta lookup per item.
		$found = get_posts(
			[
				'post_type'        => 'page',
				'post_status'      => [ 'publish', 'future', 'draft', 'pending', 'private', 'trash' ],
				'meta_key'         => self::META_KEY,
				'meta_value'       => $key,
				'numberposts'      => 1,
				'fields'           => 'ids',
				'suppress_filters' => true,
			]
		);
		// phpcs:enable
		return $found ? (int) $found[0] : 0;
	}

	/**
	 * Text for comparing: tags and entities gone, spaces collapsed.
	 *
	 * @param string $text Text or HTML.
	 * @return string
	 */
	private static function plain( string $text ): string {
		$text = html_entity_decode( wp_strip_all_tags( $text ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		return trim( (string) preg_replace( '/[\s\x{00a0}]+/u', ' ', $text ) );
	}

	/**
	 * The "iMIS page" box on an imported page: where it came from, and the tick box that stops later
	 * imports changing it.
	 *
	 * @param WP_Post $post Post.
	 * @return void
	 */
	public static function add_box( $post ): void {
		if ( $post instanceof WP_Post && '' !== (string) get_post_meta( $post->ID, self::META_KEY, true ) ) {
			add_meta_box( 'wormiss-page', __( 'iMIS page', 'wormiss' ), [ __CLASS__, 'render_box' ], 'page', 'side' );
		}
	}

	/**
	 * The box's content.
	 *
	 * @param WP_Post $post Post.
	 * @return void
	 */
	public static function render_box( $post ): void {
		wp_nonce_field( 'wormiss_page_box', 'wormiss_page_box_nonce' );
		echo '<p>' . esc_html__( 'Imported from iMIS. Each import brings this page up to date with the iMIS page.', 'wormiss' ) . '</p>';
		$url = (string) get_post_meta( $post->ID, self::META_URL, true );
		if ( '' !== $url ) {
			echo '<p><a href="' . esc_url( $url ) . '" target="_blank" rel="noopener">' . esc_html__( 'Open the iMIS page', 'wormiss' ) . '</a></p>';
		}
		printf(
			'<p><label><input type="checkbox" name="wormiss_page_detached" value="1" %1$s /> %2$s</label></p>',
			checked( (bool) get_post_meta( $post->ID, self::META_DETACHED, true ), true, false ),
			esc_html__( 'Stop updating from iMIS (keep changes made here)', 'wormiss' )
		);
	}

	/**
	 * Save the tick box.
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	public static function save_box( $post_id ): void {
		$post_id = (int) $post_id;
		if ( ! isset( $_POST['wormiss_page_box_nonce'] )
			|| ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['wormiss_page_box_nonce'] ) ), 'wormiss_page_box' )
			|| ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		if ( ! empty( $_POST['wormiss_page_detached'] ) ) {
			update_post_meta( $post_id, self::META_DETACHED, 1 );
		} else {
			delete_post_meta( $post_id, self::META_DETACHED );
		}
	}

	/**
	 * A navigation item's name for notes.
	 *
	 * @param array $node From nav_list().
	 * @return string
	 */
	private static function label( array $node ): string {
		return '' !== (string) ( $node['title'] ?? '' ) ? (string) $node['title'] : (string) ( $node['key'] ?? '' );
	}

	/**
	 * Add a note to a report (up to MAX_NOTES).
	 *
	 * @param array  $report Report.
	 * @param string $note   Note.
	 * @return void
	 */
	private static function note( array &$report, string $note ): void {
		if ( count( $report['notes'] ) < self::MAX_NOTES ) {
			$report['notes'][] = $note;
		}
	}

	/**
	 * Save the report and log the run.
	 *
	 * @param array $report Report.
	 * @return array
	 */
	private static function save( array $report ): array {
		update_option( self::REPORT_OPTION, $report, false );
		if ( 'off' !== $report['status'] ) {
			Wormiss_Logger::record_call(
				'PAGE IMPORT',
				'error' === $report['status'] ? Wormiss_Logger::STATUS_ERROR : Wormiss_Logger::STATUS_SUCCESS,
				'error' === $report['status'] ? implode( ' ', $report['notes'] ) : null,
				null,
				(string) wp_json_encode( $report )
			);
		}
		return $report;
	}

	/**
	 * The last run's notes, for the settings page.
	 *
	 * @return string[]
	 */
	public static function notes(): array {
		$report = get_option( self::REPORT_OPTION );
		if ( ! is_array( $report ) || 'error' === ( $report['status'] ?? '' ) ) {
			return [];
		}
		return array_map( 'strval', (array) ( $report['notes'] ?? [] ) );
	}

	/**
	 * The last run, in words, for the settings page ('' if it has never run).
	 *
	 * @return string
	 */
	public static function summary(): string {
		$report = get_option( self::REPORT_OPTION );
		if ( ! is_array( $report ) || empty( $report['time'] ) ) {
			return '';
		}
		$when = wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $report['time'] );
		if ( 'error' === $report['status'] ) {
			/* translators: 1: date and time, 2: reason. */
			return sprintf( __( 'Last page import %1$s: nothing changed (%2$s).', 'wormiss' ), $when, implode( ' ', $report['notes'] ) );
		}
		if ( 'off' === $report['status'] ) {
			/* translators: %s: date and time. */
			return sprintf( __( 'Last page import %s: the page import is off or has no query, so nothing changed.', 'wormiss' ), $when );
		}
		$text = sprintf(
			/* translators: 1: date and time, 2: items, 3: pages made, 4: pages updated, 5: unchanged or skipped. */
			__( 'Last page import %1$s: %2$d navigation items listed; %3$d pages made, %4$d updated, %5$d unchanged or skipped.', 'wormiss' ),
			$when,
			(int) $report['read'],
			(int) $report['created'],
			(int) $report['updated'],
			(int) $report['skipped']
		);
		if ( ! empty( $report['empty'] ) ) {
			/* translators: %d: pages imported empty. */
			$text .= ' ' . sprintf( __( '%d had no page content in iMIS and were imported as empty pages.', 'wormiss' ), (int) $report['empty'] );
		}
		if ( ! empty( $report['dry_run'] ) ) {
			$text .= ' ' . __( 'Dry run: nothing was saved.', 'wormiss' );
		}
		if ( ! empty( $report['remaining'] ) ) {
			/* translators: %d: items still to check. */
			$text .= ' ' . sprintf( __( 'Still going: %d more items to check in the background.', 'wormiss' ), (int) $report['remaining'] );
		}
		return $text;
	}
}
