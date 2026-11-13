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
 * Tests for importing iMIS news.
 *
 * @package    wormiss
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * News articles become posts with their tags, date, summary and tidied text; iMIS stays the
 * source unless a post is detached.
 *
 * @covers Wormiss_News_Import
 */
class Test_Wormiss_News_Import extends Wormiss_Test_Case {

	/** An article's version key. */
	const KEY = '6fdbde19-109c-43f8-b008-8b95e69f1704';

	/** The news query. */
	const NEWS_QUERY = '$/News/Articles';

	/**
	 * Turn the import on (not a dry run) and keep images in iMIS.
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
					'news_import_enabled' => true,
					'news_import_dry_run' => false,
					'news_import_publish' => true,
					'news_import_query'   => self::NEWS_QUERY,
				]
			)
		);
		add_filter( 'wormiss_news_import_images', '__return_false' );
	}

	/**
	 * Clean up.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		remove_filter( 'wormiss_news_import_images', '__return_false' );
		delete_option( Wormiss_News_Import::LOCK_OPTION );
		parent::tear_down();
	}

	/**
	 * A content page as iMIS keeps it (its HTML escaped once more inside the XML).
	 *
	 * @param string $body    The body text's HTML.
	 * @param bool   $members Members only.
	 * @return string
	 */
	private function page( string $body = '<p>Full article text.</p>', bool $members = false ): string {
		$item = static function ( string $name, int $zone, string $html ): string {
			return '<a:ContentItem i:type="b:ContentHtml" xmlns:b="http://schemas.imis.com/2008/01/DataContracts/ContentHtml">'
				. '<a:ContentItemName>' . $name . '</a:ContentItemName><a:LayoutZone>' . $zone . '</a:LayoutZone>'
				. '<a:SortOrder>1</a:SortOrder><b:Body>' . htmlspecialchars( htmlspecialchars( $html ) ) . '</b:Body></a:ContentItem>';
		};
		return '<Content xmlns="http://schemas.imis.com/2008/01/DataContracts/Content" xmlns:i="http://www.w3.org/2001/XMLSchema-instance">'
			. '<ContentItems xmlns:a="http://schemas.imis.com/2008/01/DataContracts/ContentItem">'
			. $item( 'Body Text', 4, $body )
			. $item( 'PageHeading', 1, '<h1><font size="5">Vale Professor</font></h1>' )
			. $item( 'Feature Image', 3, '<img src="/images/Vale%20Professor.png" alt="" style="">' )
			. $item( 'Subheading/CTA', 2, '<p><i style="font-size: inherit;"><font size="4">Intro.<br style="color: red;" /><br style="color: red;" />More intro.</font></i></p><p>&nbsp;</p>' )
			. '</ContentItems><IsMemberOnly>' . ( $members ? 'true' : 'false' ) . '</IsMemberOnly>'
			. '<MetaImagePath>~/images/meta.png</MetaImagePath><PublicationDate>2026-06-08T11:45:00</PublicationDate>'
			. '<RequireAuthenticatedUser>false</RequireAuthenticatedUser></Content>';
	}

	/**
	 * Queue the IQA rows and the article's document.
	 *
	 * @param string $xml    The content page.
	 * @param string $status Document status.
	 * @return void
	 */
	private function queue( string $xml, string $status = 'Published' ): void {
		$rows = [
			[
				'DocumentVersionKey'  => self::KEY,
				'AlternateName'       => 'Vale Professor',
				'DocumentDescription' => 'A summary.',
				'PublicationDate'     => '8/06/2026 11:45:00 AM',
				'TagName'             => 'Latest News',
			],
			[
				'DocumentVersionKey' => self::KEY,
				'TagName'            => 'Advocacy',
			],
		];
		$this->imis->token()
			->reply( '/api/iqa', 200, Wormiss_Fake_Imis::page( $rows ) )
			->reply(
				'/api/Document/_Execute',
				200,
				[
					'Result' => [
						'$type'             => 'Asi.Soa.Core.DataContracts.DocumentData, Asi.Contracts',
						'DocumentVersionId' => self::KEY,
						'AlternateName'     => 'Vale Professor',
						'Path'              => '@/web/Latest-News/Articles/vale-professor',
						'Status'            => $status,
						'Data'              => [
							'$type'  => 'System.Byte[], mscorlib',
							// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- As iMIS sends it.
							'$value' => base64_encode( $xml ),
						],
					],
				]
			);
	}

	/**
	 * The view lists an article once per tag: one article with every tag; column names match loosely.
	 *
	 * @return void
	 */
	public function test_articles(): void {
		$articles = Wormiss_News_Import::articles(
			[
				[
					'Document Version Key' => strtoupper( self::KEY ),
					'Tag Name'             => 'Latest News',
					'Alternate Name'       => 'Vale',
				],
				[
					'DocumentVersionKey' => self::KEY,
					'TagName'            => 'Advocacy',
				],
				[ 'DocumentVersionKey' => 'not-a-key' ],
			]
		);
		$this->assertSame( [ self::KEY ], array_keys( $articles ) );
		$this->assertSame( [ 'Latest News', 'Advocacy' ], $articles[ self::KEY ]['tags'] );
		$this->assertSame( 'Vale', $articles[ self::KEY ]['title'] );
	}

	/**
	 * The page's blocks come out in page order, the title heading is left out, the image-only
	 * block is the feature image, and the HTML is tidied.
	 *
	 * @return void
	 */
	public function test_parse_and_build(): void {
		$content = Wormiss_News_Import::parse( $this->page() );
		$this->assertNotNull( $content );
		$this->assertSame( [ 'PageHeading', 'Subheading/CTA', 'Feature Image', 'Body Text' ], array_column( $content['items'], 'name' ) );
		$this->assertFalse( $content['members'] );
		$built = Wormiss_News_Import::build( 'Vale Professor', $content );
		$this->assertSame( 'https://imis.example.org/images/Vale%20Professor.png', $built['feature'] );
		$this->assertStringNotContainsString( 'Vale Professor', $built['html'], 'The title heading is left out.' );
		$this->assertStringNotContainsString( 'font', $built['html'] );
		$this->assertStringNotContainsString( 'style', $built['html'] );
		$this->assertStringContainsString( 'Intro.', $built['html'] );
		$this->assertStringContainsString( '<p>Full article text.</p>', $built['html'] );
		$this->assertStringNotContainsString( '&nbsp;', $built['html'] );
		$this->assertTrue( Wormiss_News_Import::parse( $this->page( '<p>x</p>', true ) )['members'] );
		$this->assertNull( Wormiss_News_Import::parse( 'not xml' ) );
	}

	/**
	 * Tidying: fonts, styles and scripts gone, h1 to h2, line-break runs to paragraphs, iMIS
	 * links made full addresses (other sites' left alone).
	 *
	 * @return void
	 */
	public function test_clean(): void {
		$html = Wormiss_News_Import::clean(
			'<h1 class="x">Head</h1><p style="color:red"><font size="4">One<br /><br />Two</font></p>'
			. '<script>alert(1)</script><p><a href="/web/About" style="x">us</a> <a href="https://abc.net.au/x">abc</a></p>'
		);
		$this->assertStringContainsString( '<h2>Head</h2>', $html );
		$this->assertStringContainsString( 'One</p><p>Two', $html );
		$this->assertStringNotContainsString( 'script', $html );
		$this->assertStringNotContainsString( 'style', $html );
		$this->assertStringContainsString( 'href="https://imis.example.org/web/About"', $html );
		$this->assertStringContainsString( 'href="https://abc.net.au/x"', $html );
	}

	/**
	 * Dates and the iMIS page address.
	 *
	 * @return void
	 */
	public function test_dates_and_urls(): void {
		$this->assertSame( '2026-06-08 11:45:00', Wormiss_News_Import::local_date( '2026-06-08T11:45:00' ) );
		$this->assertSame( '2026-06-08 11:45:00', Wormiss_News_Import::local_date( '8/06/2026 11:45:00 AM' ) );
		$this->assertSame( '', Wormiss_News_Import::local_date( '0001-01-01T00:00:00' ) );
		$this->assertSame(
			'https://imis.example.org/web/Latest-News/Articles/vale-professor.aspx',
			Wormiss_News_Import::page_url( '@/web/Latest-News/Articles/vale-professor' )
		);
	}

	/**
	 * An import makes a published post in News with both tags, the summary, the iMIS date and
	 * the tidied text; the same article again changes nothing.
	 *
	 * @return void
	 */
	public function test_run_makes_post(): void {
		$this->queue( $this->page() );
		$report = Wormiss_News_Import::run();
		$this->assertSame( 'ok', $report['status'], implode( ' ', $report['notes'] ) );
		$this->assertSame( 1, $report['created'] );
		$post_id = Wormiss_News_Import::post_for( self::KEY );
		$this->assertGreaterThan( 0, $post_id );
		$post = get_post( $post_id );
		$this->assertSame( 'Vale Professor', $post->post_title );
		$this->assertSame( 'A summary.', $post->post_excerpt );
		$this->assertSame( '2026-06-08 11:45:00', $post->post_date );
		$this->assertSame( 'publish', $post->post_status );
		$this->assertStringContainsString( 'Full article text.', $post->post_content );
		$this->assertSame( [ 'News' ], wp_get_post_categories( $post_id, [ 'fields' => 'names' ] ) );
		$this->assertEqualsCanonicalizing( [ 'Latest News', 'Advocacy' ], wp_get_post_tags( $post_id, [ 'fields' => 'names' ] ) );
		$this->assertFalse( (bool) get_post_meta( $post_id, Wormiss_Access::META_ENABLED, true ) );
		$this->assertSame(
			'https://imis.example.org/web/Latest-News/Articles/vale-professor.aspx',
			get_post_meta( $post_id, Wormiss_News_Import::META_URL, true )
		);

		$this->queue( $this->page() );
		$report = Wormiss_News_Import::run();
		$this->assertSame( 0, $report['created'] + $report['updated'] );
		$this->assertSame( 1, $report['skipped'] );
	}

	/**
	 * A changed article updates its post (members-only now), unless the post is detached.
	 *
	 * @return void
	 */
	public function test_update_and_detach(): void {
		$this->queue( $this->page() );
		Wormiss_News_Import::run();
		$post_id = Wormiss_News_Import::post_for( self::KEY );

		$this->queue( $this->page( '<p>Changed text.</p>', true ) );
		$report = Wormiss_News_Import::run();
		$this->assertSame( 1, $report['updated'] );
		$this->assertStringContainsString( 'Changed text.', get_post_field( 'post_content', $post_id ) );
		$this->assertTrue( (bool) get_post_meta( $post_id, Wormiss_Access::META_ENABLED, true ) );

		update_post_meta( $post_id, Wormiss_News_Import::META_DETACHED, 1 );
		$this->queue( $this->page( '<p>Changed again.</p>' ) );
		$report = Wormiss_News_Import::run();
		$this->assertSame( 0, $report['updated'] );
		$this->assertStringContainsString( 'Changed text.', get_post_field( 'post_content', $post_id ) );
	}

	/**
	 * A dry run saves nothing; an unpublished document is not imported.
	 *
	 * @return void
	 */
	public function test_dry_run_and_unpublished(): void {
		update_option( Wormiss_Settings::OPTION, array_merge( Wormiss_Settings::all(), [ 'news_import_dry_run' => true ] ) );
		$this->queue( $this->page() );
		$report = Wormiss_News_Import::run();
		$this->assertSame( 1, $report['created'] );
		$this->assertSame( 0, Wormiss_News_Import::post_for( self::KEY ) );
		$this->assertStringContainsString( 'Dry run', implode( ' ', $report['notes'] ) );

		update_option( Wormiss_Settings::OPTION, array_merge( Wormiss_Settings::all(), [ 'news_import_dry_run' => false ] ) );
		$this->queue( $this->page(), 'Archived' );
		$report = Wormiss_News_Import::run();
		$this->assertSame( 0, Wormiss_News_Import::post_for( self::KEY ) );
		$this->assertSame( 1, $report['skipped'] );
	}

	/**
	 * A block of several images stays in the text (only a single image is the feature image);
	 * long content is decoded whole for the import.
	 *
	 * @return void
	 */
	public function test_gallery_and_long_content(): void {
		$content = [
			'items' => [
				[
					'name' => 'Gallery',
					'html' => '<p><img src="https://cdn.example.org/a.png" alt=""><img src="https://cdn.example.org/b.png" alt=""></p>',
				],
			],
			'image' => '',
		];
		$built   = Wormiss_News_Import::build( 'Title', $content );
		$this->assertSame( '', $built['feature'] );
		$this->assertStringContainsString( 'b.png', $built['html'] );

		$long = str_repeat( 'x', Wormiss_Content_Check::MAX_SHOWN + 100 );
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- As iMIS sends it.
		$this->assertSame( $long, Wormiss_Content_Check::decode_blob( base64_encode( $long ), false ) );
	}

	/**
	 * A batch checks at most MAX_CHECKS articles, even when none changes, and carries on later.
	 *
	 * @return void
	 */
	public function test_batch_checks_bounded(): void {
		$rows = [];
		for ( $i = 0; $i <= Wormiss_News_Import::MAX_CHECKS; $i++ ) {
			$rows[] = [ 'DocumentVersionKey' => sprintf( '00000000-0000-0000-0000-%012d', $i ) ];
		}
		$this->imis->token()->reply( '/api/iqa', 200, Wormiss_Fake_Imis::page( $rows ) );
		for ( $i = 0; $i < Wormiss_News_Import::MAX_CHECKS; $i++ ) {
			$this->imis->reply( '/api/Document/_Execute', 200, [ 'Result' => [ 'Status' => 'Archived' ] ] );
		}
		$report = Wormiss_News_Import::run();
		$this->assertSame( Wormiss_News_Import::MAX_CHECKS, $report['skipped'] );
		$this->assertSame( 1, $report['remaining'] );
		$this->assertNotFalse( wp_next_scheduled( Wormiss_News_Import::MORE_HOOK ) );
		wp_clear_scheduled_hook( Wormiss_News_Import::MORE_HOOK );
		delete_option( Wormiss_News_Import::LIST_OPTION );
		delete_option( Wormiss_News_Import::DONE_OPTION );
	}

	/**
	 * Posts made with no one signed in (background batches) get the chosen author, else the
	 * first administrator; a post left without one gets it on the next import, changed or not.
	 *
	 * @return void
	 */
	public function test_author(): void {
		wp_set_current_user( 0 );
		self::factory()->user->create( [ 'role' => 'administrator' ] );
		$this->assertGreaterThan( 0, Wormiss_News_Import::author() );
		$this->assertTrue( user_can( Wormiss_News_Import::author(), 'edit_posts' ) );

		$editor = self::factory()->user->create( [ 'role' => 'editor' ] );
		update_option( Wormiss_Settings::OPTION, array_merge( Wormiss_Settings::all(), [ 'news_import_author' => $editor ] ) );
		$this->queue( $this->page() );
		Wormiss_News_Import::run();
		$post_id = Wormiss_News_Import::post_for( self::KEY );
		$this->assertSame( $editor, (int) get_post_field( 'post_author', $post_id ) );

		$subscriber = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		update_option( Wormiss_Settings::OPTION, array_merge( Wormiss_Settings::all(), [ 'news_import_author' => $subscriber ] ) );
		$this->assertNotSame( $subscriber, Wormiss_News_Import::author(), 'Someone who cannot write posts is never the author.' );

		wp_update_post(
			[
				'ID'          => $post_id,
				'post_author' => 0,
			]
		);
		$this->queue( $this->page() );
		$report = Wormiss_News_Import::run();
		$this->assertSame( 1, $report['skipped'], 'Unchanged, so not updated.' );
		$this->assertGreaterThan( 0, (int) get_post_field( 'post_author', $post_id ), 'But given an author.' );
	}

	/**
	 * Off, or with no query, nothing is read.
	 *
	 * @return void
	 */
	public function test_off(): void {
		update_option( Wormiss_Settings::OPTION, array_merge( Wormiss_Settings::all(), [ 'news_import_query' => '' ] ) );
		$this->assertSame( 'off', Wormiss_News_Import::run()['status'] );
		$this->assertSame( [], $this->imis->requests );
	}
}
