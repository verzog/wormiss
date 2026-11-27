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
	 * @param string $xml     The content page.
	 * @param string $status  Document status.
	 * @param string $updated An Updated On value for the query rows ('' for no such column).
	 * @return void
	 */
	private function queue( string $xml, string $status = 'Published', string $updated = '' ): void {
		$this->queue_list( $updated );
		$this->imis
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
	 * Queue only the list-level read (token and the IQA), not the article's document. Used to prove
	 * an unchanged article is skipped without its per-article read.
	 *
	 * @param string $updated An Updated On value for the first row ('' for no such column).
	 * @return void
	 */
	private function queue_list( string $updated = '' ): void {
		$first = [
			'DocumentVersionKey'  => self::KEY,
			'AlternateName'       => 'Vale Professor',
			'DocumentDescription' => 'A summary.',
			'PublicationDate'     => '8/06/2026 11:45:00 AM',
			'TagName'             => 'Latest News',
		];
		if ( '' !== $updated ) {
			$first['UpdatedOn'] = $updated;
		}
		$rows = [
			$first,
			[
				'DocumentVersionKey' => self::KEY,
				'TagName'            => 'Advocacy',
			],
		];
		$this->imis->token()->reply( '/api/iqa', 200, Wormiss_Fake_Imis::page( $rows ) );
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
		// The image stays beside the intro before it, as on the iMIS page; intro stays italic.
		$this->assertStringContainsString( '<div class="wormiss-news-lead"><div class="wormiss-news-lead__text">', $built['html'] );
		$this->assertStringContainsString( 'src="https://imis.example.org/images/Vale%20Professor.png"', $built['html'] );
		$this->assertStringContainsString( '<i>More intro.</i>', $built['html'] );
		$this->assertStringNotContainsString( '&nbsp;', $built['html'] );
		$this->assertTrue( Wormiss_News_Import::parse( $this->page( '<p>x</p>', true ) )['members'] );
		$this->assertNull( Wormiss_News_Import::parse( 'not xml' ) );
	}

	/**
	 * A title heading with an ampersand (escaped in iMIS) is still recognised as the title.
	 *
	 * @return void
	 */
	public function test_title_heading_with_ampersand(): void {
		$content = [
			'items' => [
				[
					'name' => 'PageHeading',
					'html' => '<h1>Skin Cancer Blog &amp; SkinTalk are Back Online!</h1>',
				],
				[
					'name' => 'Body',
					'html' => '<p>Text.</p>',
				],
			],
			'image' => '',
		];
		$built   = Wormiss_News_Import::build( 'Skin Cancer Blog & SkinTalk are Back Online!', $content );
		$this->assertSame( '<p>Text.</p>', $built['html'] );
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
		$this->assertStringContainsString( 'href="https://abc.net.au/x" target="_blank" rel="noopener noreferrer"', $html );
		$this->assertSame( 2, substr_count( $html, 'target="_blank"' ), 'Every link opens in a new tab, once.' );
		// iMIS's own-site link token.
		$this->assertStringContainsString(
			'href="https://imis.example.org/web/membership/join-now.aspx"',
			Wormiss_News_Import::clean( '<p><a href="[@]web/membership/join-now.aspx">join</a></p>' )
		);
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

		// A linked single image keeps its link.
		$content['items'][] = [
			'name' => 'Banner',
			'html' => '<p><a href="https://example.org/register"><img src="https://cdn.example.org/c.png" alt="Register"></a></p>',
		];
		$built              = Wormiss_News_Import::build( 'Title', $content );
		$this->assertMatchesRegularExpression( '#<figure class="wormiss-news-image wormiss-news-feature"><a href="https://example.org/register"[^>]*><img[^>]*c\.png#', $built['html'] );

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
	 * On the article's own page the featured image is left out when the article shows it; a
	 * card for another post keeps its image.
	 *
	 * @return void
	 */
	public function test_featured_image_not_twice(): void {
		$this->queue( $this->page() );
		Wormiss_News_Import::run();
		$post_id = Wormiss_News_Import::post_for( self::KEY );
		$other   = self::factory()->post->create();
		$this->go_to( get_permalink( $post_id ) );
		$block = static fn( int $id ): WP_Block => new WP_Block( [ 'blockName' => 'core/post-featured-image' ], [ 'postId' => $id ] );
		$this->assertSame( '', Wormiss_News_Import::hide_featured( '<img>', [], $block( $post_id ) ) );
		$this->assertSame( '<img>', Wormiss_News_Import::hide_featured( '<img>', [], $block( $other ) ) );
	}

	/**
	 * When iMIS refuses the query, its reason shows on the settings page, and nothing changes.
	 *
	 * @return void
	 */
	public function test_query_refused_shows_reason(): void {
		$this->imis->token()->reply(
			'/api/iqa',
			400,
			[ 'Errors' => [ '$values' => [ [ 'Message' => 'The text data type cannot be selected as DISTINCT.' ] ] ] ]
		);
		$report = Wormiss_News_Import::run();
		$this->assertSame( 'error', $report['status'] );
		$this->assertStringContainsString( 'cannot be selected as DISTINCT', Wormiss_News_Import::summary() );
	}

	/**
	 * With an Updated On column and "changed only" on, the first import (a reconcile) reads the
	 * article and records its updated value; the next import skips it without reading its document.
	 *
	 * @return void
	 */
	public function test_incremental_skips_unchanged(): void {
		$this->queue( $this->page(), 'Published', '2026-06-18T10:04:59.743' );
		$this->assertSame( 1, Wormiss_News_Import::run()['created'] );
		$post_id = Wormiss_News_Import::post_for( self::KEY );
		$this->assertSame( '2026-06-18T10:04:59.743', (string) get_post_meta( $post_id, Wormiss_News_Import::META_UPDATED, true ) );
		$this->assertGreaterThan( 0, (int) get_option( Wormiss_News_Import::FULL_OPTION ), 'The reconcile time is recorded.' );

		// Next import: same updated value, reconcile not due. Only the IQA is queued, so reading the
		// article's document would be an unexpected request and fail the test.
		$this->imis->requests = [];
		$this->queue_list( '2026-06-18T10:04:59.743' );
		$report = Wormiss_News_Import::run();
		$this->assertSame( 0, $report['created'] + $report['updated'] );
		$this->assertSame( 1, $report['skipped'] );
		$this->assertSame( 0, $this->imis->count( '/api/Document/_Execute' ), 'No per-article read for an unchanged article.' );
	}

	/**
	 * An article whose updated value has changed is re-read on the next incremental import.
	 *
	 * @return void
	 */
	public function test_incremental_rereads_changed(): void {
		$this->queue( $this->page(), 'Published', '2026-06-18T10:04:59.743' );
		Wormiss_News_Import::run();

		$this->queue( $this->page( '<p>Changed text.</p>' ), 'Published', '2026-07-01T09:00:00.000' );
		$report = Wormiss_News_Import::run();
		$this->assertSame( 1, $report['updated'] );
		$this->assertStringContainsString( 'Changed text.', get_post_field( 'post_content', Wormiss_News_Import::post_for( self::KEY ) ) );
	}

	/**
	 * A query with no Updated On column keeps reading every article (no fast skip possible).
	 *
	 * @return void
	 */
	public function test_no_updated_column_rereads_every_article(): void {
		$this->queue( $this->page() );
		Wormiss_News_Import::run();

		// No Updated On in the rows, so the article is read again even though it is unchanged.
		$this->imis->requests = [];
		$this->queue( $this->page() );
		$report = Wormiss_News_Import::run();
		$this->assertSame( 1, $this->imis->count( '/api/Document/_Execute' ), 'The article is re-read without an updated column.' );
		$this->assertSame( 1, $report['skipped'], 'Its content hash is unchanged, so nothing is written.' );
	}

	/**
	 * A dry-run reconcile does not record the reconcile time, so a preview does not stop the next
	 * live import from re-reading and applying changes.
	 *
	 * @return void
	 */
	public function test_dry_run_reconcile_does_not_advance(): void {
		update_option( Wormiss_Settings::OPTION, array_merge( Wormiss_Settings::all(), [ 'news_import_dry_run' => true ] ) );
		$this->queue( $this->page(), 'Published', '2026-06-18T10:04:59.743' );
		Wormiss_News_Import::run();
		$this->assertSame( 0, (int) get_option( Wormiss_News_Import::FULL_OPTION, 0 ), 'A dry-run reconcile does not consume the weekly window.' );
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
