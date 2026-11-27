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
 * Tests for importing an iMIS website's pages, keeping the menu hierarchy.
 *
 * @package    wormiss
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Navigation items become pages nested as in iMIS; content pages supply the body; iParts-only
 * pages come in empty; iMIS stays the source unless a page is detached.
 *
 * @covers Wormiss_Page_Import
 */
class Test_Wormiss_Page_Import extends Wormiss_Test_Case {

	/** The page query. */
	const PAGE_QUERY = '$/Website/Navigation';

	/** The "About Us" navigation item and its content page. */
	const ABOUT_H   = '1be126c7-c786-4721-93f6-4aee09487dc2';
	const ABOUT_NAV = '0d7dddd3-7811-486c-8ea1-bfaaf8ab1217';
	const ABOUT_CON = 'aaaaaaaa-0000-0000-0000-000000000001';

	/** The "Our Team" navigation item (a child of About Us) and its content page. */
	const TEAM_H   = '5b72062e-b7fb-4387-852c-3a14d80d5de0';
	const TEAM_NAV = 'c7e3a810-9ebd-44ec-ade4-52b7000b5d38';
	const TEAM_CON = 'aaaaaaaa-0000-0000-0000-000000000002';

	/** The "Web" tree root (a top-level item's parent). */
	const ROOT_H = '0fe75a0c-b604-495d-8eff-8be46fea9080';

	/**
	 * Turn the import on (not a dry run), publishing imported pages.
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
					'page_import_enabled' => true,
					'page_import_dry_run' => false,
					'page_import_publish' => true,
					'page_import_query'   => self::PAGE_QUERY,
				]
			)
		);
	}

	/**
	 * Clean up.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		delete_option( Wormiss_Page_Import::LOCK_OPTION );
		delete_option( Wormiss_Page_Import::LIST_OPTION );
		delete_option( Wormiss_Page_Import::DONE_OPTION );
		wp_clear_scheduled_hook( Wormiss_Page_Import::MORE_HOOK );
		parent::tear_down();
	}

	/**
	 * A Navigation blob (the navigation item's content), naming its parent and breadcrumb.
	 *
	 * @param string $parent_key Parent hierarchy key (the tree root for a top-level item).
	 * @param string $breadcrumb The item's breadcrumb name.
	 * @return string
	 */
	private function nav_xml( string $parent_key, string $breadcrumb ): string {
		return '<Navigation xmlns="http://schemas.imis.com/2008/01/DataContracts/Navigation" '
			. 'xmlns:i="http://www.w3.org/2001/XMLSchema-instance">'
			. '<AdjacentHierarchyKey xmlns="http://schemas.imis.com/2008/01/DataContracts/AtomBaseHierarchy">'
			. $parent_key . '</AdjacentHierarchyKey>'
			. '<BreadCrumbName>' . $breadcrumb . '</BreadCrumbName>'
			. '<CssClass>Button</CssClass><IsHidden>false</IsHidden>'
			. '</Navigation>';
	}

	/**
	 * A content page as iMIS keeps it (its HTML escaped once more inside the XML).
	 *
	 * @param string $body    The body text's HTML.
	 * @param bool   $members Members only.
	 * @return string
	 */
	private function content_xml( string $body, bool $members = false ): string {
		$item = static function ( string $name, int $zone, string $html ): string {
			return '<a:ContentItem i:type="b:ContentHtml" xmlns:b="http://schemas.imis.com/2008/01/DataContracts/ContentHtml">'
				. '<a:ContentItemName>' . $name . '</a:ContentItemName><a:LayoutZone>' . $zone . '</a:LayoutZone>'
				. '<a:SortOrder>1</a:SortOrder><b:Body>' . htmlspecialchars( htmlspecialchars( $html ) ) . '</b:Body></a:ContentItem>';
		};
		return '<Content xmlns="http://schemas.imis.com/2008/01/DataContracts/Content" xmlns:i="http://www.w3.org/2001/XMLSchema-instance">'
			. '<ContentItems xmlns:a="http://schemas.imis.com/2008/01/DataContracts/ContentItem">'
			. $item( 'Body Text', 4, $body )
			. '</ContentItems><IsMemberOnly>' . ( $members ? 'true' : 'false' ) . '</IsMemberOnly>'
			. '<RequireAuthenticatedUser>false</RequireAuthenticatedUser></Content>';
	}

	/**
	 * A navigation DocumentData reply: its Data is the Navigation blob, plus the document-level
	 * RelatedDocumentVersionKey that names its content page.
	 *
	 * @param string $related    Content document version key ('' / zero-GUID for none).
	 * @param string $parent_key Parent hierarchy key.
	 * @param string $breadcrumb Breadcrumb name.
	 * @param string $path       The page's iMIS path.
	 * @return array
	 */
	private function nav_doc( string $related, string $parent_key, string $breadcrumb, string $path = '' ): array {
		return [
			'Result' => [
				'$type'                     => 'Asi.Soa.Core.DataContracts.DocumentData, Asi.Contracts',
				'Status'                    => 'Published',
				'AlternateName'             => $breadcrumb,
				'Path'                      => $path,
				'RelatedDocumentVersionKey' => $related,
				'Data'                      => [
					'$type'  => 'System.Byte[], mscorlib',
					// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- As iMIS sends it.
					'$value' => base64_encode( $this->nav_xml( $parent_key, $breadcrumb ) ),
				],
			],
		];
	}

	/**
	 * A content DocumentData reply.
	 *
	 * @param string $body    Body HTML.
	 * @param bool   $members Members only.
	 * @param string $status  Document status.
	 * @return array
	 */
	private function content_doc( string $body, bool $members = false, string $status = 'Published' ): array {
		return [
			'Result' => [
				'$type'  => 'Asi.Soa.Core.DataContracts.DocumentData, Asi.Contracts',
				'Status' => $status,
				'Data'   => [
					'$type'  => 'System.Byte[], mscorlib',
					// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- As iMIS sends it.
					'$value' => base64_encode( $this->content_xml( $body, $members ) ),
				],
			],
		];
	}

	/**
	 * Queue the IQA list and, for About Us then its child Our Team, each one's navigation document
	 * and content document in processing order.
	 *
	 * @param string $about_body Body for About Us.
	 * @param string $team_body  Body for Our Team.
	 * @return void
	 */
	private function queue_tree( string $about_body = '<p>About us text.</p>', string $team_body = '<p>Meet the team.</p>' ): void {
		$rows = [
			[
				'HierarchyKey'       => strtoupper( self::ABOUT_H ),
				'DocumentVersionKey' => self::ABOUT_NAV,
				'AlternateName'      => 'About Us',
			],
			[
				'HierarchyKey'       => self::TEAM_H,
				'DocumentVersionKey' => self::TEAM_NAV,
				'AlternateName'      => 'Our Team',
			],
		];
		$this->imis->token()
			->reply( '/api/iqa', 200, Wormiss_Fake_Imis::page( $rows ) )
			->reply( '/api/Document/_Execute', 200, $this->nav_doc( self::ABOUT_CON, self::ROOT_H, 'About Us', '@/web/About-Us' ) )
			->reply( '/api/Document/_Execute', 200, $this->content_doc( $about_body ) )
			->reply( '/api/Document/_Execute', 200, $this->nav_doc( self::TEAM_CON, self::ABOUT_H, 'Our Team', '@/web/About-Us/Our-Team' ) )
			->reply( '/api/Document/_Execute', 200, $this->content_doc( $team_body ) );
	}

	/**
	 * The list has one row per hierarchy key; column names match loosely; a row with no hierarchy
	 * key is dropped; a parent/content column is captured when present.
	 *
	 * @return void
	 */
	public function test_nav_list(): void {
		$nodes = Wormiss_Page_Import::nav_list(
			[
				[
					'Hierarchy Key'                => strtoupper( self::ABOUT_H ),
					'Document Version Key'         => self::ABOUT_NAV,
					'Alternate Name'               => 'About Us',
					'Related Document Version Key' => self::ABOUT_CON,
					'Parent Hierarchy Key'         => self::ROOT_H,
				],
				[ 'HierarchyKey' => self::ABOUT_H ],
				[ 'HierarchyKey' => 'not-a-key' ],
			]
		);
		$this->assertSame( [ self::ABOUT_H ], array_keys( $nodes ) );
		$this->assertSame( self::ABOUT_NAV, $nodes[ self::ABOUT_H ]['version'] );
		$this->assertSame( self::ABOUT_CON, $nodes[ self::ABOUT_H ]['content'] );
		$this->assertSame( self::ROOT_H, $nodes[ self::ABOUT_H ]['parent'] );
		$this->assertSame( 'About Us', $nodes[ self::ABOUT_H ]['title'] );
	}

	/**
	 * The navigation blob yields the breadcrumb and parent; the tree root reads as no parent.
	 *
	 * @return void
	 */
	public function test_parse_navigation(): void {
		$parsed = Wormiss_Page_Import::parse_navigation( $this->nav_xml( self::ABOUT_H, 'Our Team' ) );
		$this->assertSame( 'Our Team', $parsed['title'] );
		$this->assertSame( self::ABOUT_H, $parsed['parent'] );

		$root = Wormiss_Page_Import::parse_navigation( $this->nav_xml( '00000000-0000-0000-0000-000000000000', 'Top' ) );
		$this->assertSame( '', $root['parent'], 'The zero-GUID parent reads as the root (no parent).' );
		$this->assertSame( 'Top', $root['title'] );
	}

	/**
	 * The body is the content items' HTML, tidied; a block that is only the page title heading is
	 * left out.
	 *
	 * @return void
	 */
	public function test_build_body(): void {
		// A content page has one block per content item: the page heading is its own block (dropped
		// when it is only the page title), the body is another (kept and tidied).
		$content = [
			'items' => [
				[
					'name' => 'PageHeading',
					'html' => '<h1>About Us</h1>',
				],
				[
					'name' => 'Body Text',
					'html' => '<p>Our <font size="4">story</font>.</p>',
				],
			],
		];
		$body    = Wormiss_Page_Import::build_body( 'About Us', $content );
		$this->assertStringContainsString( 'Our story.', $body );
		$this->assertStringNotContainsString( 'font', $body );
		$this->assertStringNotContainsString( 'About Us', $body, 'The title heading block is left out.' );
		$this->assertSame( '', Wormiss_Page_Import::build_body( 'X', [ 'items' => [] ] ) );
	}

	/**
	 * An import makes published pages, nests the child under its parent, and brings in the body.
	 *
	 * @return void
	 */
	public function test_run_builds_pages_with_hierarchy(): void {
		$this->queue_tree();
		$report = Wormiss_Page_Import::run();
		$this->assertSame( 'ok', $report['status'], implode( ' ', $report['notes'] ) );
		$this->assertSame( 2, $report['created'] );

		$about = Wormiss_Page_Import::post_for( self::ABOUT_H );
		$team  = Wormiss_Page_Import::post_for( self::TEAM_H );
		$this->assertGreaterThan( 0, $about );
		$this->assertGreaterThan( 0, $team );
		$this->assertSame( 'page', get_post_type( $about ) );
		$this->assertSame( 'About Us', get_post( $about )->post_title );
		$this->assertSame( 'publish', get_post_status( $about ) );
		$this->assertStringContainsString( 'About us text.', get_post_field( 'post_content', $about ) );
		$this->assertStringContainsString( 'Meet the team.', get_post_field( 'post_content', $team ) );
		// The child sits under its parent; the top-level page sits at the root.
		$this->assertSame( 0, (int) get_post_field( 'post_parent', $about ) );
		$this->assertSame( $about, (int) get_post_field( 'post_parent', $team ) );
		$this->assertSame(
			'https://imis.example.org/web/About-Us.aspx',
			get_post_meta( $about, Wormiss_Page_Import::META_URL, true )
		);

		// The same tree again changes nothing.
		$this->queue_tree();
		$report = Wormiss_Page_Import::run();
		$this->assertSame( 0, $report['created'] + $report['updated'] );
		$this->assertSame( 2, $report['skipped'] );
	}

	/**
	 * A content read that fails (a temporary iMIS problem) leaves an existing page unchanged, rather
	 * than blanking its body.
	 *
	 * @return void
	 */
	public function test_content_read_failure_keeps_page(): void {
		$this->queue_tree();
		Wormiss_Page_Import::run();
		$about = Wormiss_Page_Import::post_for( self::ABOUT_H );
		$this->assertStringContainsString( 'About us text.', get_post_field( 'post_content', $about ) );

		// The navigation document reads, but the content document request fails.
		$rows = [
			[
				'HierarchyKey'       => self::ABOUT_H,
				'DocumentVersionKey' => self::ABOUT_NAV,
				'AlternateName'      => 'About Us',
			],
		];
		$this->imis->token()
			->reply( '/api/iqa', 200, Wormiss_Fake_Imis::page( $rows ) )
			->reply( '/api/Document/_Execute', 200, $this->nav_doc( self::ABOUT_CON, self::ROOT_H, 'About Us', '@/web/About-Us' ) )
			->fail( '/api/Document/_Execute' );

		$report = Wormiss_Page_Import::run();
		$this->assertSame( 0, $report['created'] + $report['updated'] );
		$this->assertSame( 1, $report['skipped'] );
		$this->assertStringContainsString( 'About us text.', get_post_field( 'post_content', $about ), 'Existing content is not blanked by a failed read.' );
	}

	/**
	 * A page an editor has detached and moved keeps its WordPress parent: the hierarchy rebuild
	 * leaves detached pages where they are.
	 *
	 * @return void
	 */
	public function test_detached_page_keeps_parent(): void {
		$this->queue_tree();
		Wormiss_Page_Import::run();
		$about = Wormiss_Page_Import::post_for( self::ABOUT_H );
		$team  = Wormiss_Page_Import::post_for( self::TEAM_H );
		$this->assertSame( $about, (int) get_post_field( 'post_parent', $team ) );

		// The editor detaches the child and moves it to the top level.
		update_post_meta( $team, Wormiss_Page_Import::META_DETACHED, 1 );
		wp_update_post(
			[
				'ID'          => $team,
				'post_parent' => 0,
			]
		);

		$this->queue_tree();
		Wormiss_Page_Import::run();
		$this->assertSame( 0, (int) get_post_field( 'post_parent', $team ), 'The detached page keeps the editor\'s parent.' );
	}

	/**
	 * A navigation item with no content page (an iParts-only or container page) is imported as an
	 * empty page, in the right place, and counted.
	 *
	 * @return void
	 */
	public function test_empty_page(): void {
		$rows = [
			[
				'HierarchyKey'       => self::ABOUT_H,
				'DocumentVersionKey' => self::ABOUT_NAV,
				'AlternateName'      => 'Locate a Doctor',
			],
		];
		$this->imis->token()
			->reply( '/api/iqa', 200, Wormiss_Fake_Imis::page( $rows ) )
			->reply( '/api/Document/_Execute', 200, $this->nav_doc( '00000000-0000-0000-0000-000000000000', self::ROOT_H, 'Locate a Doctor' ) );

		$report = Wormiss_Page_Import::run();
		$this->assertSame( 1, $report['created'] );
		$this->assertSame( 1, $report['empty'] );
		$page = Wormiss_Page_Import::post_for( self::ABOUT_H );
		$this->assertSame( 'Locate a Doctor', get_post( $page )->post_title );
		$this->assertSame( '', (string) get_post_field( 'post_content', $page ) );
	}

	/**
	 * A members-only content page makes a members-only WordPress page.
	 *
	 * @return void
	 */
	public function test_members_only_page(): void {
		$rows = [
			[
				'HierarchyKey'       => self::ABOUT_H,
				'DocumentVersionKey' => self::ABOUT_NAV,
				'AlternateName'      => 'Members Area',
			],
		];
		$this->imis->token()
			->reply( '/api/iqa', 200, Wormiss_Fake_Imis::page( $rows ) )
			->reply( '/api/Document/_Execute', 200, $this->nav_doc( self::ABOUT_CON, self::ROOT_H, 'Members Area' ) )
			->reply( '/api/Document/_Execute', 200, $this->content_doc( '<p>Members only.</p>', true ) );

		Wormiss_Page_Import::run();
		$page = Wormiss_Page_Import::post_for( self::ABOUT_H );
		$this->assertTrue( (bool) get_post_meta( $page, Wormiss_Access::META_ENABLED, true ) );
	}

	/**
	 * Images in the page body that live on the iMIS site are copied into the Media Library and the
	 * body is pointed at the local copies; a second import reuses the copy rather than copying again.
	 *
	 * @return void
	 */
	public function test_copies_body_images(): void {
		if ( ! function_exists( 'imagejpeg' ) ) {
			$this->markTestSkipped( 'GD is not available.' );
		}
		// Serve a real JPEG into download_url()'s stream target for the image request. Added at a
		// later priority than the fake iMIS so its return value wins for this URL.
		$serve = static function ( $pre, $args, $url ) {
			if ( false !== strpos( $url, 'team.jpg' ) && ! empty( $args['filename'] ) ) {
				$image = imagecreatetruecolor( 4, 4 );
				imagejpeg( $image, $args['filename'] );
				return [
					'headers'  => [],
					'body'     => '',
					'response' => [
						'code'    => 200,
						'message' => 'OK',
					],
					'cookies'  => [],
					'filename' => $args['filename'],
				];
			}
			return $pre;
		};
		add_filter( 'pre_http_request', $serve, 20, 3 );

		$body = '<p>Meet the team.</p><p><img src="/images/team.jpg" alt="Team" /></p>';
		$rows = [
			[
				'HierarchyKey'       => self::ABOUT_H,
				'DocumentVersionKey' => self::ABOUT_NAV,
				'AlternateName'      => 'About Us',
			],
		];
		$this->imis->token()
			->reply( '/api/iqa', 200, Wormiss_Fake_Imis::page( $rows ) )
			->reply( '/api/Document/_Execute', 200, $this->nav_doc( self::ABOUT_CON, self::ROOT_H, 'About Us' ) )
			->reply( '/api/Document/_Execute', 200, $this->content_doc( $body ) );
		Wormiss_Page_Import::run();
		remove_filter( 'pre_http_request', $serve, 20 );

		$about   = Wormiss_Page_Import::post_for( self::ABOUT_H );
		$content = get_post_field( 'post_content', $about );
		$this->assertStringNotContainsString( 'imis.example.org/images/team.jpg', $content, 'The iMIS image address is replaced.' );

		// phpcs:disable WordPress.DB.SlowDBQuery -- Test assertion.
		$found = get_posts(
			[
				'post_type'        => 'attachment',
				'post_status'      => 'inherit',
				'meta_key'         => Wormiss_Page_Import::META_FROM,
				'meta_value'       => 'https://imis.example.org/images/team.jpg',
				'fields'           => 'ids',
				'numberposts'      => -1,
				'suppress_filters' => true,
			]
		);
		// phpcs:enable
		$this->assertCount( 1, $found, 'The image was copied once.' );
		$this->assertStringContainsString( (string) wp_get_attachment_url( (int) $found[0] ), $content, 'The body points at the local copy.' );
		$this->assertSame( $about, (int) wp_get_post_parent_id( (int) $found[0] ), 'The copy is attached to the page.' );
	}

	/**
	 * A dry run saves nothing.
	 *
	 * @return void
	 */
	public function test_dry_run(): void {
		update_option( Wormiss_Settings::OPTION, array_merge( Wormiss_Settings::all(), [ 'page_import_dry_run' => true ] ) );
		$this->queue_tree();
		$report = Wormiss_Page_Import::run();
		$this->assertSame( 2, $report['created'] );
		$this->assertSame( 0, Wormiss_Page_Import::post_for( self::ABOUT_H ) );
		$this->assertStringContainsString( 'Dry run', implode( ' ', $report['notes'] ) );
	}

	/**
	 * A changed page updates, unless the page is detached.
	 *
	 * @return void
	 */
	public function test_update_and_detach(): void {
		$this->queue_tree();
		Wormiss_Page_Import::run();
		$about = Wormiss_Page_Import::post_for( self::ABOUT_H );

		$this->queue_tree( '<p>About us, revised.</p>' );
		$report = Wormiss_Page_Import::run();
		$this->assertSame( 1, $report['updated'] );
		$this->assertStringContainsString( 'About us, revised.', get_post_field( 'post_content', $about ) );

		update_post_meta( $about, Wormiss_Page_Import::META_DETACHED, 1 );
		$this->queue_tree( '<p>About us, again.</p>' );
		$report = Wormiss_Page_Import::run();
		$this->assertStringContainsString( 'About us, revised.', get_post_field( 'post_content', $about ) );
		$this->assertStringNotContainsString( 'again', get_post_field( 'post_content', $about ) );
	}

	/**
	 * Off, or with no query, nothing is read.
	 *
	 * @return void
	 */
	public function test_off(): void {
		update_option( Wormiss_Settings::OPTION, array_merge( Wormiss_Settings::all(), [ 'page_import_query' => '' ] ) );
		$this->assertSame( 'off', Wormiss_Page_Import::run()['status'] );
		$this->assertSame( [], $this->imis->requests );
	}
}
