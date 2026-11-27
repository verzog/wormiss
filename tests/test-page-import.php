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
	 * RelatedDocumentVersionId (the REST field name) that names its content page.
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
				'$type'                    => 'Asi.Soa.Core.DataContracts.DocumentData, Asi.Contracts',
				'Status'                   => 'Published',
				'AlternateName'            => $breadcrumb,
				'Path'                     => $path,
				'RelatedDocumentVersionId' => $related,
				'Data'                     => [
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
	 * A DocumentData reply whose Data is the given (already-decoded) XML.
	 *
	 * @param string $xml The document's XML.
	 * @return array
	 */
	private function doc_with_data( string $xml ): array {
		return [
			'Result' => [
				'$type'  => 'Asi.Soa.Core.DataContracts.DocumentData, Asi.Contracts',
				'Status' => 'Published',
				'Data'   => [
					'$type'  => 'System.Byte[], mscorlib',
					// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- As iMIS sends it.
					'$value' => base64_encode( $xml ),
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
					'zone' => 1,
					'html' => '<h1>About Us</h1>',
				],
				[
					'name' => 'Body Text',
					'zone' => 2,
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
	 * The importer rebuilds the iMIS column layout as blocks: a heading-only cell is a full-width
	 * section, and the run of cells after it becomes an equal-column row.
	 *
	 * @return void
	 */
	public function test_build_body_groups_rows_into_columns(): void {
		$content = [
			'items' => [
				[
					'name' => 'Heading',
					'zone' => 1,
					'html' => '<h2>Our Values</h2>',
				],
				[
					'name' => 'Card A',
					'zone' => 2,
					'html' => '<h3>Progressive</h3><p>We are innovative.</p>',
				],
				[
					'name' => 'Card B',
					'zone' => 3,
					'html' => '<h3>Inclusive</h3><p>We welcome all.</p>',
				],
			],
		];
		$body    = Wormiss_Page_Import::build_body( 'About Us', $content );
		$this->assertStringContainsString( '<!-- wp:group {"className":"wormiss-section wormiss-heading"} -->', $body, 'The heading is a full-width section.' );
		$this->assertStringContainsString( '<!-- wp:columns {"className":"wormiss-row"} -->', $body, 'The two cards form a columns row.' );
		$this->assertSame( 2, substr_count( $body, '<!-- wp:column -->' ), 'One column per card.' );
		$this->assertStringContainsString( 'Progressive', $body );
		$this->assertStringContainsString( 'Inclusive', $body );
	}

	/**
	 * A card that merely ends with a sub-heading is row content, not a full-width section, so such
	 * cards still group into columns.
	 *
	 * @return void
	 */
	public function test_build_body_card_ending_in_heading_is_not_a_section(): void {
		$content = [
			'items' => [
				[
					'name' => 'Card A',
					'zone' => 2,
					'html' => '<h3>Benefit</h3><p>Description.</p><h4>Learn more</h4>',
				],
				[
					'name' => 'Card B',
					'zone' => 3,
					'html' => '<h3>Benefit two</h3><p>More.</p><h4>Learn more</h4>',
				],
			],
		];
		$body    = Wormiss_Page_Import::build_body( 'X', $content );
		$this->assertStringContainsString( '<!-- wp:columns {"className":"wormiss-row"} -->', $body, 'The two cards still form a row.' );
		$this->assertStringNotContainsString( 'wormiss-heading', $body, 'Neither card is a full-width heading.' );
		$this->assertSame( 2, substr_count( $body, '<!-- wp:column -->' ) );
	}

	/**
	 * With the wormiss_page_import_blocks filter off, the body is a single column of content (no
	 * column blocks), as before.
	 *
	 * @return void
	 */
	public function test_build_body_blocks_can_be_disabled(): void {
		add_filter( 'wormiss_page_import_blocks', '__return_false' );
		$content = [
			'items' => [
				[
					'name' => 'A',
					'zone' => 2,
					'html' => '<p>One.</p>',
				],
				[
					'name' => 'B',
					'zone' => 3,
					'html' => '<p>Two.</p>',
				],
			],
		];
		$body    = Wormiss_Page_Import::build_body( 'X', $content );
		remove_filter( 'wormiss_page_import_blocks', '__return_false' );
		$this->assertStringNotContainsString( 'wp:columns', $body );
		$this->assertStringContainsString( '<p>One.</p>', $body );
		$this->assertStringContainsString( '<p>Two.</p>', $body );
	}

	/**
	 * A cell's content is rebuilt as native blocks (heading, paragraph), not sealed in one HTML block,
	 * so it can be edited in the block editor and styled by the theme.
	 *
	 * @return void
	 */
	public function test_cell_content_becomes_native_blocks(): void {
		$content = [
			'items' => [
				[
					'name' => 'Intro',
					'zone' => 2,
					'html' => '<h3>Our Board</h3><p>Our Board oversees governance.</p>',
				],
			],
		];
		$body    = Wormiss_Page_Import::build_body( 'X', $content );
		$this->assertStringContainsString( '<!-- wp:heading {"level":3} -->', $body, 'The h3 becomes a heading block.' );
		$this->assertStringContainsString( '<h3 class="wp-block-heading">Our Board</h3>', $body );
		$this->assertStringContainsString( '<!-- wp:paragraph -->', $body, 'The paragraph becomes a paragraph block.' );
		$this->assertStringNotContainsString( '<!-- wp:html -->', $body, 'Simple content is not left as an HTML block.' );
	}

	/**
	 * A list becomes a list block with a list-item block per item, keeping any icon image inside the
	 * item.
	 *
	 * @return void
	 */
	public function test_list_becomes_list_block(): void {
		$content = [
			'items' => [
				[
					'name' => 'Benefits',
					'zone' => 2,
					'html' => '<ul><li>One</li><li>Two</li></ul>',
				],
			],
		];
		$body    = Wormiss_Page_Import::build_body( 'X', $content );
		$this->assertStringContainsString( '<!-- wp:list -->', $body );
		$this->assertSame( 2, substr_count( $body, '<!-- wp:list-item -->' ), 'One list-item block per item.' );
		$this->assertStringNotContainsString( '<!-- wp:html -->', $body );
	}

	/**
	 * A figure with an image becomes an image block, its caption carried across.
	 *
	 * @return void
	 */
	public function test_figure_becomes_image_block(): void {
		$content = [
			'items' => [
				[
					'name' => 'Picture',
					'zone' => 2,
					'html' => '<figure><img src="/logo.webp" alt="Logo"><figcaption>A caption</figcaption></figure>',
				],
			],
		];
		$body    = Wormiss_Page_Import::build_body( 'X', $content );
		$this->assertStringContainsString( '<!-- wp:image -->', $body );
		$this->assertStringContainsString( '<figure class="wp-block-image">', $body );
		$this->assertStringContainsString( 'A caption', $body );
	}

	/**
	 * Markup with no native-block equivalent (here a list nesting another list) is preserved whole in
	 * an HTML block rather than dropped, so no imported content is lost.
	 *
	 * @return void
	 */
	public function test_unconvertible_markup_falls_back_to_html(): void {
		$content = [
			'items' => [
				[
					'name' => 'Nested',
					'zone' => 2,
					'html' => '<ul><li>Parent<ul><li>Child</li></ul></li></ul>',
				],
			],
		];
		$body    = Wormiss_Page_Import::build_body( 'X', $content );
		$this->assertStringContainsString( '<!-- wp:html -->', $body );
		$this->assertStringContainsString( 'Child', $body, 'The nested content is kept, not dropped.' );
	}

	/**
	 * Loose text with an inline link (content not wrapped in a paragraph) stays a single paragraph with
	 * its spacing, rather than being split across a paragraph, an HTML block and another paragraph.
	 *
	 * @return void
	 */
	public function test_adjacent_inline_content_stays_one_paragraph(): void {
		$content = [
			'items' => [
				[
					'name' => 'Loose',
					'zone' => 2,
					'html' => 'Read <a href="/guide">this guide</a> before applying.',
				],
			],
		];
		$body    = Wormiss_Page_Import::build_body( 'X', $content );
		$this->assertSame( 1, substr_count( $body, '<!-- wp:paragraph -->' ), 'The sentence is one paragraph.' );
		$this->assertStringNotContainsString( '<!-- wp:html -->', $body, 'The link is not split into its own block.' );
		// clean() may rewrite the link (absolute URL, target/rel), so assert only the spacing around it.
		$this->assertStringContainsString( 'Read <a ', $body, 'The space before the link is kept.' );
		$this->assertStringContainsString( '>this guide</a> before applying.', $body, 'The space after the link is kept.' );
	}

	/**
	 * A figure holding more than a single image (here an image and a paragraph) is preserved whole as an
	 * HTML block, so the content beside the image is not dropped.
	 *
	 * @return void
	 */
	public function test_figure_with_extra_content_falls_back(): void {
		$content = [
			'items' => [
				[
					'name' => 'Figure',
					'zone' => 2,
					'html' => '<figure><img src="/a.jpg" alt=""><p>Note beside image.</p></figure>',
				],
			],
		];
		$body    = Wormiss_Page_Import::build_body( 'X', $content );
		$this->assertStringContainsString( '<!-- wp:html -->', $body );
		$this->assertStringNotContainsString( '<!-- wp:image -->', $body, 'A figure with extra content is not forced into an image block.' );
		$this->assertStringContainsString( 'Note beside image.', $body, 'The paragraph beside the image is kept.' );
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
	 * The import records each page's content-panel names and zones in the report (for the audit log),
	 * as a diagnostic that does not change the imported pages.
	 *
	 * @return void
	 */
	public function test_run_records_panel_names(): void {
		$this->queue_tree();
		$report = Wormiss_Page_Import::run();
		$this->assertArrayHasKey( 'panels', $report );
		$this->assertNotEmpty( $report['panels'], 'Each imported page records its panels.' );
		// queue_tree() gives each page one content item named "Body Text" in zone 4.
		$this->assertStringContainsString( 'Body Text (zone 4)', implode( ' | ', $report['panels'] ) );
	}

	/**
	 * The panel inventory lists every content item, including a panel with no HTML body (such as a
	 * query listing or other non-HTML iPart), so the diagnostic is complete.
	 *
	 * @return void
	 */
	public function test_panel_index_includes_empty_body_panels(): void {
		$xml   = '<Content xmlns="http://schemas.imis.com/2008/01/DataContracts/Content">'
			. '<ContentItems xmlns:a="http://schemas.imis.com/2008/01/DataContracts/ContentItem">'
			. '<a:ContentItem><a:ContentItemName>Content</a:ContentItemName><a:LayoutZone>2</a:LayoutZone>'
			. '<a:SortOrder>1</a:SortOrder></a:ContentItem>'
			. '<a:ContentItem><a:ContentItemName>BoardListing</a:ContentItemName><a:LayoutZone>4</a:LayoutZone>'
			. '<a:SortOrder>1</a:SortOrder></a:ContentItem>'
			. '</ContentItems></Content>';
		$names = array_column( Wormiss_News_Import::panel_index( $xml ), 'name' );
		$this->assertContains( 'Content', $names );
		$this->assertContains( 'BoardListing', $names, 'A panel with no HTML body is still inventoried.' );
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
	 * A page imported by an earlier version (whose fingerprint predates the source version) is
	 * reprocessed on the next import, not skipped as unchanged, so its images get localised.
	 *
	 * @return void
	 */
	public function test_reprocesses_pages_from_before_this_version(): void {
		$page = (int) self::factory()->post->create(
			[
				'post_type'    => 'page',
				'post_title'   => 'About Us',
				'post_content' => '<p>old</p>',
				'post_status'  => 'publish',
			]
		);
		update_post_meta( $page, Wormiss_Page_Import::META_KEY, self::ABOUT_H );
		// The legacy fingerprint: the same fields, but without the source version the new code adds.
		$legacy = md5( (string) wp_json_encode( [ 'About Us', '<p>About us text.</p>', false, '', '' ] ) );
		update_post_meta( $page, Wormiss_Page_Import::META_SOURCE, $legacy );

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
			->reply( '/api/Document/_Execute', 200, $this->content_doc( '<p>About us text.</p>' ) );

		$report = Wormiss_Page_Import::run();
		$this->assertSame( 1, $report['updated'], 'A page from before the source version is reprocessed, not skipped.' );
		$this->assertStringContainsString( 'About us text.', get_post_field( 'post_content', $page ) );
	}

	/**
	 * Image copying recognises a single-quoted src attribute too (not only double-quoted).
	 *
	 * @return void
	 */
	public function test_copies_single_quoted_image(): void {
		if ( ! function_exists( 'imagejpeg' ) ) {
			$this->markTestSkipped( 'GD is not available.' );
		}
		$serve = static function ( $pre, $args, $url ) {
			if ( false !== strpos( $url, 'team.jpg' ) && ! empty( $args['filename'] ) ) {
				imagejpeg( imagecreatetruecolor( 4, 4 ), $args['filename'] );
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

		$page = (int) self::factory()->post->create( [ 'post_type' => 'page' ] );
		// Reset the per-batch counter so copy() does not short-circuit. Reflection reaches private
		// members without setAccessible() on PHP 8.1+, where that method is a deprecated no-op.
		( new ReflectionProperty( Wormiss_Page_Import::class, 'images' ) )->setValue( null, 0 );
		$method   = new ReflectionMethod( Wormiss_Page_Import::class, 'images' );
		$report   = [ 'notes' => [] ];
		$html     = "<p><img src='https://imis.example.org/images/team.jpg' alt='Team'></p>";
		$complete = (bool) $method->invokeArgs( null, [ $page, $html, 'About Us', &$report ] );

		remove_filter( 'pre_http_request', $serve, 20 );

		$this->assertTrue( $complete );
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
		$this->assertCount( 1, $found, 'The single-quoted image was copied.' );
		$this->assertStringContainsString( (string) wp_get_attachment_url( (int) $found[0] ), get_post_field( 'post_content', $page ) );
	}

	/**
	 * An image whose URL has no usable file extension (as iMIS serves them) is still copied: the
	 * file is named from its real type, sniffed from the downloaded bytes, so WordPress accepts it.
	 *
	 * @return void
	 */
	public function test_copies_image_without_extension(): void {
		if ( ! function_exists( 'imagejpeg' ) ) {
			$this->markTestSkipped( 'GD is not available.' );
		}
		$source = 'https://imis.example.org/iCore/GetImage?id=7';
		$serve  = static function ( $pre, $args, $url ) {
			if ( false !== strpos( $url, 'GetImage' ) && ! empty( $args['filename'] ) ) {
				imagejpeg( imagecreatetruecolor( 4, 4 ), $args['filename'] );
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

		$page = (int) self::factory()->post->create( [ 'post_type' => 'page' ] );
		( new ReflectionProperty( Wormiss_Page_Import::class, 'images' ) )->setValue( null, 0 );
		$method   = new ReflectionMethod( Wormiss_Page_Import::class, 'images' );
		$report   = [ 'notes' => [] ];
		$html     = '<p><img src="' . $source . '" alt="Logo"></p>';
		$complete = (bool) $method->invokeArgs( null, [ $page, $html, 'About Us', &$report ] );

		remove_filter( 'pre_http_request', $serve, 20 );

		$this->assertTrue( $complete, 'The extension-less image was copied without blocking the page.' );
		// phpcs:disable WordPress.DB.SlowDBQuery -- Test assertion.
		$found = get_posts(
			[
				'post_type'        => 'attachment',
				'post_status'      => 'inherit',
				'meta_key'         => Wormiss_Page_Import::META_FROM,
				'meta_value'       => $source,
				'fields'           => 'ids',
				'numberposts'      => -1,
				'suppress_filters' => true,
			]
		);
		// phpcs:enable
		$this->assertCount( 1, $found, 'The extension-less image was copied once.' );
		$this->assertStringEndsWith( '.jpg', (string) get_attached_file( (int) $found[0] ), 'The file was named from its real type.' );
	}

	/**
	 * An SVG icon is sanitised and copied into the Media Library (WordPress blocks SVG by default),
	 * and its address in the body is rewritten to the local copy.
	 *
	 * @return void
	 */
	public function test_copies_svg_icon(): void {
		$source = 'https://imis.example.org/images/_SCCA/about-us/vision.svg';
		$svg    = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><script>alert(1)</script><path d="M4 4h16v16H4z" fill="#512b7f"/></svg>';
		$serve  = static function ( $pre, $args, $url ) use ( $svg ) {
			if ( false !== strpos( $url, 'vision.svg' ) && ! empty( $args['filename'] ) ) {
				file_put_contents( $args['filename'], $svg ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- The download's temporary file.
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

		$page = (int) self::factory()->post->create( [ 'post_type' => 'page' ] );
		( new ReflectionProperty( Wormiss_Page_Import::class, 'images' ) )->setValue( null, 0 );
		$method   = new ReflectionMethod( Wormiss_Page_Import::class, 'images' );
		$report   = [ 'notes' => [] ];
		$html     = '<p><img src="' . $source . '" alt="Vision"></p>';
		$complete = (bool) $method->invokeArgs( null, [ $page, $html, 'About Us', &$report ] );

		remove_filter( 'pre_http_request', $serve, 20 );

		$this->assertTrue( $complete, 'The SVG was copied.' );
		// phpcs:disable WordPress.DB.SlowDBQuery -- Test assertion.
		$found = get_posts(
			[
				'post_type'        => 'attachment',
				'post_status'      => 'inherit',
				'meta_key'         => Wormiss_Page_Import::META_FROM,
				'meta_value'       => $source,
				'fields'           => 'ids',
				'numberposts'      => -1,
				'suppress_filters' => true,
			]
		);
		// phpcs:enable
		$this->assertCount( 1, $found, 'The SVG was copied once.' );
		$this->assertSame( 'image/svg+xml', get_post_mime_type( (int) $found[0] ), 'It was stored as an SVG.' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents, WordPress.WP.AlternativeFunctions.file_system_operations_file_get_contents -- Test assertion.
		$this->assertStringNotContainsString( 'script', (string) file_get_contents( (string) get_attached_file( (int) $found[0] ) ), 'The script was sanitised out.' );
		$this->assertStringContainsString( (string) wp_get_attachment_url( (int) $found[0] ), get_post_field( 'post_content', $page ) );
	}

	/**
	 * Wormiss_Page_Layout::rows() turns a template's LayoutMarkup into ordered rows of zone and width.
	 *
	 * @return void
	 */
	public function test_layout_rows_parses_the_grid(): void {
		$xml = '<ContentLayout xmlns="http://schemas.imis.com/2008/01/DataContracts/ContentLayout"><LayoutMarkup> <div> '
			. '<div class="row"><div class="col-sm-12"> {1} </div></div>'
			. '<div class="row"><div class="col-sm-8"> {2} </div><div class="col-sm-4"> {3} </div></div>'
			. '<div class="row"><div class="col-sm-3"> {7} </div><div class="col-sm-3"> {8} </div><div class="col-sm-3"> {9} </div><div class="col-sm-3"> {10} </div></div>'
			. ' </div> </LayoutMarkup></ContentLayout>';
		$this->assertSame(
			[
				[
					[
						'zone'  => 1,
						'width' => 12,
					],
				],
				[
					[
						'zone'  => 2,
						'width' => 8,
					],
					[
						'zone'  => 3,
						'width' => 4,
					],
				],
				[
					[
						'zone'  => 7,
						'width' => 3,
					],
					[
						'zone'  => 8,
						'width' => 3,
					],
					[
						'zone'  => 9,
						'width' => 3,
					],
					[
						'zone'  => 10,
						'width' => 3,
					],
				],
			],
			Wormiss_Page_Layout::rows( $xml )
		);
		$this->assertNull( Wormiss_Page_Layout::rows( '<ContentLayout></ContentLayout>' ), 'No markup gives null.' );
	}

	/**
	 * A nested grid is too complex to flatten, so the parser returns null (the caller falls back to
	 * the heading grouping) rather than duplicating zones.
	 *
	 * @return void
	 */
	public function test_layout_rows_rejects_nested_grid(): void {
		$xml = '<ContentLayout xmlns="http://schemas.imis.com/2008/01/DataContracts/ContentLayout"><LayoutMarkup><div>'
			. '<div class="row"><div class="col-sm-8"><div class="row"><div class="col-sm-6"> {2} </div><div class="col-sm-6"> {3} </div></div></div>'
			. '<div class="col-sm-4"> {4} </div></div></div></LayoutMarkup></ContentLayout>';
		$this->assertNull( Wormiss_Page_Layout::rows( $xml ) );
	}

	/**
	 * In a template row, each full-width (col-sm-12) cell is its own section, not a column shared with
	 * its neighbours.
	 *
	 * @return void
	 */
	public function test_template_full_width_cells_become_sections(): void {
		$content = [
			'items' => [
				[
					'name' => 'A',
					'zone' => 1,
					'html' => '<p>One.</p>',
				],
				[
					'name' => 'B',
					'zone' => 2,
					'html' => '<p>Two.</p>',
				],
			],
		];
		$layout  = [
			[
				[
					'zone'  => 1,
					'width' => 12,
				],
				[
					'zone'  => 2,
					'width' => 12,
				],
			],
		];
		$body    = Wormiss_Page_Import::build_body( 'X', $content, $layout );
		$this->assertStringNotContainsString( 'wp:columns', $body, 'Two full-width cells are separate sections, not a columns row.' );
		$this->assertSame( 2, substr_count( $body, '<!-- wp:group' ), 'Each full-width cell is its own section.' );
		$this->assertStringContainsString( 'One.', $body );
		$this->assertStringContainsString( 'Two.', $body );
	}

	/**
	 * The importer rebuilds a page's exact column grid from its layout template: a template row with
	 * col-sm-8 and col-sm-4 becomes a columns row with those widths (two thirds and one third).
	 *
	 * @return void
	 */
	public function test_uses_layout_template_for_columns(): void {
		$layout_key = 'cccccccc-0000-0000-0000-000000000009';
		$item       = static function ( string $name, int $zone, string $html ): string {
			return '<a:ContentItem i:type="b:ContentHtml" xmlns:b="http://schemas.imis.com/2008/01/DataContracts/ContentHtml">'
				. '<a:ContentItemName>' . $name . '</a:ContentItemName><a:LayoutZone>' . $zone . '</a:LayoutZone>'
				. '<a:SortOrder>1</a:SortOrder><b:Body>' . htmlspecialchars( htmlspecialchars( $html ) ) . '</b:Body></a:ContentItem>';
		};
		$content    = '<Content xmlns="http://schemas.imis.com/2008/01/DataContracts/Content" xmlns:i="http://www.w3.org/2001/XMLSchema-instance">'
			. '<ContentItems xmlns:a="http://schemas.imis.com/2008/01/DataContracts/ContentItem">'
			. $item( 'Intro', 2, '<p>Intro text.</p>' )
			. $item( 'Image', 3, '<p>Picture here.</p>' )
			. '</ContentItems><IsMemberOnly>false</IsMemberOnly><RequireAuthenticatedUser>false</RequireAuthenticatedUser>'
			. '<LayoutDocumentVersionKey>' . $layout_key . '</LayoutDocumentVersionKey></Content>';
		$template   = '<ContentLayout xmlns="http://schemas.imis.com/2008/01/DataContracts/ContentLayout">'
			. '<LayoutMarkup><div><div class="row"><div class="col-sm-8"> {2} </div><div class="col-sm-4"> {3} </div></div></div></LayoutMarkup></ContentLayout>';
		$this->imis->token()
			->reply(
				'/api/iqa',
				200,
				Wormiss_Fake_Imis::page(
					[
						[
							'HierarchyKey'       => strtoupper( self::ABOUT_H ),
							'DocumentVersionKey' => self::ABOUT_NAV,
							'AlternateName'      => 'About Us',
						],
					]
				)
			)
			->reply( '/api/Document/_Execute', 200, $this->nav_doc( self::ABOUT_CON, self::ROOT_H, 'About Us', '@/web/About-Us' ) )
			->reply( '/api/Document/_Execute', 200, $this->doc_with_data( $content ) )
			->reply( '/api/Document/_Execute', 200, $this->doc_with_data( $template ) );

		Wormiss_Page_Import::run();

		$body = get_post_field( 'post_content', Wormiss_Page_Import::post_for( self::ABOUT_H ) );
		$this->assertStringContainsString( '<!-- wp:columns {"className":"wormiss-row"} -->', $body );
		$this->assertStringContainsString( '"width":"66.67%"', $body, 'Zone 2 takes eight twelfths.' );
		$this->assertStringContainsString( '"width":"33.33%"', $body, 'Zone 3 takes four twelfths.' );
		$this->assertStringContainsString( 'Intro text.', $body );
		$this->assertStringContainsString( 'Picture here.', $body );
	}

	/**
	 * A page that already exists for an iMIS item (made before the import, so not yet stamped) is taken
	 * over and updated, not duplicated.
	 *
	 * @return void
	 */
	public function test_adopts_existing_page_instead_of_duplicating(): void {
		$existing = (int) self::factory()->post->create(
			[
				'post_type'    => 'page',
				'post_title'   => 'About Us',
				'post_name'    => 'about-us',
				'post_content' => '<p>Old hand-made content.</p>',
				'post_status'  => 'publish',
			]
		);
		$this->imis->token()
			->reply(
				'/api/iqa',
				200,
				Wormiss_Fake_Imis::page(
					[
						[
							'HierarchyKey'       => strtoupper( self::ABOUT_H ),
							'DocumentVersionKey' => self::ABOUT_NAV,
							'AlternateName'      => 'About Us',
						],
					]
				)
			)
			->reply( '/api/Document/_Execute', 200, $this->nav_doc( self::ABOUT_CON, self::ROOT_H, 'About Us', '@/web/About-Us' ) )
			->reply( '/api/Document/_Execute', 200, $this->content_doc( '<p>Imported about text.</p>' ) );

		$report = Wormiss_Page_Import::run();
		$this->assertSame( 0, $report['created'], 'No duplicate page was created.' );
		$this->assertSame( 1, $report['updated'], 'The existing page was taken over and updated.' );

		$pages = get_posts(
			[
				'post_type'        => 'page',
				'title'            => 'About Us',
				'post_status'      => [ 'publish', 'draft', 'pending', 'private' ],
				'fields'           => 'ids',
				'numberposts'      => -1,
				'suppress_filters' => true,
			]
		);
		$this->assertSame( [ $existing ], array_map( 'intval', $pages ), 'The import adopted the existing page rather than duplicating it.' );
		$this->assertSame( $existing, Wormiss_Page_Import::post_for( self::ABOUT_H ), 'The adopted page is now stamped with the iMIS key.' );
		$this->assertStringContainsString( 'Imported about text.', get_post_field( 'post_content', $existing ) );
	}

	/**
	 * When two existing pages share the title and neither matches by slug, the import does not guess
	 * which to adopt: it makes a fresh page and leaves both existing pages untouched.
	 *
	 * @return void
	 */
	public function test_does_not_adopt_when_the_match_is_ambiguous(): void {
		$one = (int) self::factory()->post->create(
			[
				'post_type'   => 'page',
				'post_title'  => 'Board',
				'post_name'   => 'board',
				'post_status' => 'publish',
			]
		);
		$two = (int) self::factory()->post->create(
			[
				'post_type'   => 'page',
				'post_title'  => 'Board',
				'post_name'   => 'board-2',
				'post_status' => 'publish',
			]
		);
		$this->imis->token()
			->reply(
				'/api/iqa',
				200,
				Wormiss_Fake_Imis::page(
					[
						[
							'HierarchyKey'       => strtoupper( self::ABOUT_H ),
							'DocumentVersionKey' => self::ABOUT_NAV,
							'AlternateName'      => 'Board',
						],
					]
				)
			)
			->reply( '/api/Document/_Execute', 200, $this->nav_doc( self::ABOUT_CON, self::ROOT_H, 'Board', '@/web/governance/board-members' ) )
			->reply( '/api/Document/_Execute', 200, $this->content_doc( '<p>Imported board text.</p>' ) );

		$report = Wormiss_Page_Import::run();
		$this->assertSame( 1, $report['created'], 'Two same-titled pages are too ambiguous to adopt, so a fresh page is made.' );
		$new = Wormiss_Page_Import::post_for( self::ABOUT_H );
		$this->assertGreaterThan( 0, $new );
		$this->assertNotSame( $one, $new );
		$this->assertNotSame( $two, $new );
		$this->assertSame( '', (string) get_post_meta( $one, Wormiss_Page_Import::META_KEY, true ), 'The existing pages are left unstamped.' );
		$this->assertSame( '', (string) get_post_meta( $two, Wormiss_Page_Import::META_KEY, true ) );
	}

	/**
	 * A nav item that matches one of the site's own system pages (here the front page) is skipped, not
	 * imported, so it cannot collide with or overwrite a working page the site needs.
	 *
	 * @return void
	 */
	public function test_skips_the_sites_system_pages(): void {
		$front = (int) self::factory()->post->create(
			[
				'post_type'    => 'page',
				'post_title'   => 'Welcome',
				'post_name'    => 'welcome',
				'post_content' => '<p>Front page.</p>',
				'post_status'  => 'publish',
			]
		);
		update_option( 'show_on_front', 'page' );
		update_option( 'page_on_front', $front );
		$this->imis->token()
			->reply(
				'/api/iqa',
				200,
				Wormiss_Fake_Imis::page(
					[
						[
							'HierarchyKey'       => strtoupper( self::ABOUT_H ),
							'DocumentVersionKey' => self::ABOUT_NAV,
							'AlternateName'      => 'Welcome',
						],
					]
				)
			)
			->reply( '/api/Document/_Execute', 200, $this->nav_doc( self::ABOUT_CON, self::ROOT_H, 'Welcome', '@/web/Welcome' ) );

		$report = Wormiss_Page_Import::run();
		update_option( 'page_on_front', 0 );
		update_option( 'show_on_front', 'posts' );

		$this->assertSame( 0, $report['created'], 'The front page is not duplicated.' );
		$this->assertSame( 1, $report['skipped'] );
		$this->assertSame( 0, Wormiss_Page_Import::post_for( self::ABOUT_H ), 'Nothing was stamped for the skipped item.' );
		$this->assertSame( '', (string) get_post_meta( $front, Wormiss_Page_Import::META_KEY, true ), 'The front page was left untouched.' );
		$this->assertStringContainsString( 'Front page.', get_post_field( 'post_content', $front ), 'The front page content was not overwritten.' );
	}

	/**
	 * A system page whose slug differs from its title (so the slug-based skip does not catch it) is
	 * still never adopted by title: adoption excludes system pages, so a fresh page is made instead of
	 * overwriting the protected one.
	 *
	 * @return void
	 */
	public function test_system_page_is_not_adopted_by_title(): void {
		$front = (int) self::factory()->post->create(
			[
				'post_type'    => 'page',
				'post_title'   => 'Welcome',
				'post_name'    => 'home',
				'post_content' => '<p>Front page.</p>',
				'post_status'  => 'publish',
			]
		);
		update_option( 'show_on_front', 'page' );
		update_option( 'page_on_front', $front );
		$this->imis->token()
			->reply(
				'/api/iqa',
				200,
				Wormiss_Fake_Imis::page(
					[
						[
							'HierarchyKey'       => strtoupper( self::ABOUT_H ),
							'DocumentVersionKey' => self::ABOUT_NAV,
							'AlternateName'      => 'Welcome',
						],
					]
				)
			)
			->reply( '/api/Document/_Execute', 200, $this->nav_doc( self::ABOUT_CON, self::ROOT_H, 'Welcome', '@/web/Welcome' ) )
			->reply( '/api/Document/_Execute', 200, $this->content_doc( '<p>Imported welcome text.</p>' ) );

		$report = Wormiss_Page_Import::run();
		update_option( 'page_on_front', 0 );
		update_option( 'show_on_front', 'posts' );

		$this->assertSame( 1, $report['created'], 'A fresh page is made rather than adopting the front page.' );
		$new = Wormiss_Page_Import::post_for( self::ABOUT_H );
		$this->assertGreaterThan( 0, $new );
		$this->assertNotSame( $front, $new, 'The front page was not adopted.' );
		$this->assertSame( '', (string) get_post_meta( $front, Wormiss_Page_Import::META_KEY, true ) );
		$this->assertStringContainsString( 'Front page.', get_post_field( 'post_content', $front ), 'The front page content was not overwritten.' );
	}

	/**
	 * When the slug matches one page and the exact title matches a different page, the two criteria
	 * disagree: the import treats that as ambiguous and makes a fresh page rather than overwriting
	 * either.
	 *
	 * @return void
	 */
	public function test_does_not_adopt_when_slug_and_title_match_different_pages(): void {
		$by_slug  = (int) self::factory()->post->create(
			[
				'post_type'    => 'page',
				'post_title'   => 'Other',
				'post_name'    => 'about-us',
				'post_content' => '<p>Unrelated page.</p>',
				'post_status'  => 'publish',
			]
		);
		$by_title = (int) self::factory()->post->create(
			[
				'post_type'   => 'page',
				'post_title'  => 'About Us',
				'post_name'   => 'about-us-page',
				'post_status' => 'publish',
			]
		);
		$this->imis->token()
			->reply(
				'/api/iqa',
				200,
				Wormiss_Fake_Imis::page(
					[
						[
							'HierarchyKey'       => strtoupper( self::ABOUT_H ),
							'DocumentVersionKey' => self::ABOUT_NAV,
							'AlternateName'      => 'About Us',
						],
					]
				)
			)
			->reply( '/api/Document/_Execute', 200, $this->nav_doc( self::ABOUT_CON, self::ROOT_H, 'About Us', '@/web/About-Us' ) )
			->reply( '/api/Document/_Execute', 200, $this->content_doc( '<p>Imported about text.</p>' ) );

		$report = Wormiss_Page_Import::run();
		$this->assertSame( 1, $report['created'], 'Disagreeing slug and title matches are ambiguous, so a fresh page is made.' );
		$new = Wormiss_Page_Import::post_for( self::ABOUT_H );
		$this->assertNotSame( $by_slug, $new );
		$this->assertNotSame( $by_title, $new );
		$this->assertSame( '', (string) get_post_meta( $by_slug, Wormiss_Page_Import::META_KEY, true ), 'The slug-matching page was not overwritten.' );
		$this->assertStringContainsString( 'Unrelated page.', get_post_field( 'post_content', $by_slug ) );
		$this->assertSame( '', (string) get_post_meta( $by_title, Wormiss_Page_Import::META_KEY, true ), 'The title-matching page was not overwritten.' );
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
