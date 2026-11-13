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
 * Tests for the members' blog and its import.
 *
 * @package    wormiss
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Smart Blogs posts become blog posts with intros, solutions and threaded comments; only
 * readers see full posts.
 *
 * @covers Wormiss_Blog
 * @covers Wormiss_Blog_Import
 */
class Test_Wormiss_Blog extends Wormiss_Test_Case {

	/** A blog key. */
	const KEY = 'd61ef139-372b-4e44-a52e-5ef022610b20';

	/**
	 * An older blog post's key.
	 */
	const OLDER = '0b6a1d2e-1f3c-4a5b-8c9d-0e1f2a3b4c5d';

	/**
	 * Register the blog; turn the import on (not a dry run); keep pictures in iMIS.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();
		Wormiss_Blog::register();
		update_option(
			Wormiss_Settings::OPTION,
			array_merge(
				Wormiss_Settings::all(),
				[
					'blog_import_enabled' => true,
					'blog_import_dry_run' => false,
					'blog_reader_roles'   => [ 'subscriber' ],
				]
			)
		);
		add_filter( 'wormiss_blog_import_images', '__return_false' );
	}

	/**
	 * Clean up.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		remove_filter( 'wormiss_blog_import_images', '__return_false' );
		delete_option( Wormiss_Blog_Import::LOCK_OPTION );
		delete_option( Wormiss_Blog_Import::PEOPLE_OPTION );
		delete_option( Wormiss_Blog_Import::WORKING_OPTION );
		delete_option( Wormiss_Blog_Import::LIST_OPTION );
		delete_option( Wormiss_Blog_Import::DONE_OPTION );
		wp_clear_scheduled_hook( Wormiss_Blog_Import::MORE_HOOK );
		parent::tear_down();
	}

	/**
	 * A new-style post: furniture, header picture, intro text, body text, picture, solution.
	 *
	 * @param string $solution Solution text.
	 * @param string $reveal   Reveal time.
	 * @return array
	 */
	private function modern( string $solution = 'It was a BCC.', string $reveal = '2026-06-19T21:00:00+10:00[Australia/Brisbane]' ): array {
		return [
			'root'    => [ 'props' => [ 'title' => 'Small Bowl' ] ],
			'content' => [
				[
					'type'                  => 'Title',
					'props'                 => [],
					'visibleWhenRestricted' => 'visible',
				],
				[
					'type'  => 'Flex',
					'props' => [
						'items' => [
							[
								'type'  => 'AuthorName',
								'props' => [],
							],
							[
								'type'  => 'Separator',
								'props' => [ 'orientation' => 'vertical' ],
							],
						],
					],
				],
				[
					'type'                  => 'Image',
					'props'                 => [ 'imageSrc' => '$/images/Causeis/smart-blogs/13397/k/header.jpg' ],
					'visibleWhenRestricted' => 'visible',
				],
				[
					'type'  => 'Separator',
					'props' => [ 'orientation' => 'horizontal' ],
				],
				[
					'type'                  => 'Text',
					'props'                 => [ 'text' => "Hi everyone,\nwelcome." ],
					'visibleWhenRestricted' => 'visible',
				],
				[
					'type'  => 'Text',
					'props' => [ 'text' => "Members only part.\n\nSecond <paragraph>." ],
				],
				[
					'type'  => 'Image',
					'props' => [
						'imageSrc' => '$/images/Causeis/smart-blogs/13397/k/case.jpg',
						'alt'      => 'Case',
					],
				],
				[
					'type'  => 'Conclusion',
					'props' => [
						'items'    => [
							[
								'type'  => 'Text',
								'props' => [ 'text' => $solution ],
							],
						],
						'revealAt' => $reveal,
					],
				],
				[
					'type'                  => 'NoAccess',
					'props'                 => [ 'title' => 'Access Restricted' ],
					'visibleWhenRestricted' => 'restricted-only',
				],
			],
		];
	}

	/**
	 * New-style blocks: the header picture is set apart, visible blocks make the intro, the
	 * rest the text, the Conclusion the solution; text is escaped.
	 *
	 * @return void
	 */
	public function test_convert_modern(): void {
		$parts = Wormiss_Blog_Import::convert( $this->modern() );
		$this->assertSame( 'https://imis.example.org/images/Causeis/smart-blogs/13397/k/header.jpg', $parts['header'] );
		$this->assertSame( "<p>Hi everyone,<br>\nwelcome.</p>", $parts['intro'] );
		$this->assertStringContainsString( 'Members only part.', $parts['body'] );
		$this->assertStringContainsString( 'Second &lt;paragraph&gt;.', $parts['body'] );
		$this->assertStringContainsString( '<img src="https://imis.example.org/images/Causeis/smart-blogs/13397/k/case.jpg" alt="Case" />', $parts['body'] );
		$this->assertStringNotContainsString( 'header.jpg', $parts['body'] );
		$this->assertStringNotContainsString( 'Access Restricted', $parts['body'] );
		$this->assertSame( '<p>It was a BCC.</p>', $parts['solution'] );
		$this->assertSame( strtotime( '2026-06-19T11:00:00Z' ), $parts['reveal'] );
		$this->assertSame( [], $parts['unknown'] );
	}

	/**
	 * Old posts: their HTML tidied, the first paragraph as the intro, the date-only reveal.
	 *
	 * @return void
	 */
	public function test_convert_legacy(): void {
		$html  = '<html><head></head><body><p><a href="/x.jpg"><img src="/x.jpg" alt=""></a></p>'
			. '<p>Hi blog <span style="font-size:1rem">community</span>!</p><p>More.</p></body></html>';
		$parts = Wormiss_Blog_Import::convert(
			[
				'content' => [
					[
						'type'  => 'LegacyHtml',
						'props' => [ 'html' => $html ],
					],
					[
						'type'  => 'Conclusion',
						'props' => [
							'items'    => [
								[
									'type'  => 'LegacyHtml',
									'props' => [ 'html' => '<html><body><p>All LM.</p></body></html>' ],
								],
							],
							'revealAt' => '2025-01-01',
						],
					],
					[
						'type'  => 'Gallery',
						'props' => [ 'id' => 'g' ],
					],
				],
			]
		);
		$this->assertSame( '<p>Hi blog community!</p>', $parts['intro'] );
		$this->assertStringContainsString( 'src="https://imis.example.org/x.jpg"', $parts['body'] );
		$this->assertStringNotContainsString( 'style', $parts['body'] );
		$this->assertStringNotContainsString( '<body>', $parts['body'] );
		$this->assertSame( '<p>All LM.</p>', $parts['solution'] );
		$this->assertSame( ( new DateTimeImmutable( '2025-01-01', wp_timezone() ) )->getTimestamp(), $parts['reveal'] );
		$this->assertSame( [ 'Gallery' ], $parts['unknown'] );
	}

	/**
	 * A new-style post with no visible blocks has no intro: nothing of it is made public.
	 *
	 * @return void
	 */
	public function test_no_intro_for_new_posts(): void {
		$parts = Wormiss_Blog_Import::convert(
			[
				'content' => [
					[
						'type'  => 'Text',
						'props' => [ 'text' => 'Members only.' ],
					],
				],
			]
		);
		$this->assertSame( '', $parts['intro'] );
		$this->assertSame( '<p>Members only.</p>', $parts['body'] );
	}

	/**
	 * Pictures on the iMIS site are found with or without a query string; others are not.
	 *
	 * @return void
	 */
	public function test_image_urls(): void {
		$html = '<img src="https://imis.example.org/a/case.jpg?v=2"><a href="https://imis.example.org/b.PNG">'
			. '<img src="https://cdn.example.com/c.jpg"><a href="https://imis.example.org/page.aspx">';
		$this->assertSame(
			[ 'https://imis.example.org/a/case.jpg?v=2', 'https://imis.example.org/b.PNG' ],
			Wormiss_Blog_Import::image_urls( $html, 'https://imis.example.org' )
		);
	}

	/**
	 * Comment feeds leave out blog comments for people who cannot read.
	 *
	 * @return void
	 */
	public function test_comment_feed(): void {
		wp_set_current_user( 0 );
		$this->assertStringContainsString( "post_type = 'wormiss_blog'", Wormiss_Blog::comment_feed_where( 'WHERE 1=1' ) );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );
		$this->assertSame( 'WHERE 1=1', Wormiss_Blog::comment_feed_where( 'WHERE 1=1' ) );
	}

	/**
	 * Comment text: decoded from base 64, plain text kept as it is.
	 *
	 * @return void
	 */
	public function test_comment_text(): void {
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- As iMIS sends it.
		$this->assertSame( "Thanks Tristan\n\nGreat case 👍", Wormiss_Blog_Import::comment_text( base64_encode( "Thanks Tristan\r\n\r\nGreat case 👍" ) ) );
		$this->assertSame( 'Plain words here', Wormiss_Blog_Import::comment_text( 'Plain words here' ) );
	}

	/**
	 * Queue one import: the post list, categories, the post and its comments, a name.
	 *
	 * @param array  $data       The post's blocks.
	 * @param string $management The first category's name in iMIS.
	 * @param string $diagnosis  The second category's name in iMIS.
	 * @param array  $older      More (older) posts in the list.
	 * @return void
	 */
	private function queue( array $data, string $management = 'Management', string $diagnosis = 'Diagnosis', array $older = [] ): void {
		$post    = [
			'ID'             => '13397',
			'BlogKey'        => self::KEY,
			'Title'          => 'Small Bowl, Big Decisions!',
			'Summary'        => 'ear reconstruction',
			'Tags'           => 'BCC (Basal Cell Carcinoma),Ears (peri-aural)',
			'Status'         => 'Published',
			'CategoryKey'    => 'cat-1',
			'SubcategoryKey' => 'sub-1',
			'PublishedOn'    => '2026-06-18T10:00:38.173',
			'UpdatedOn'      => '2026-06-18T10:04:59.743',
		];
		$full    = array_merge(
			$post,
			[
				'BlogPostData' => [
					'$type'  => 'System.Byte[], mscorlib',
					// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- As iMIS sends it.
					'$value' => base64_encode( (string) wp_json_encode( $data ) ),
				],
			]
		);
		$comment = static function ( string $key, string $parent_key, string $imis, string $text, string $when ): array {
			return [
				'BlogKey'          => self::KEY,
				'CommentKey'       => $key,
				'ParentCommentKey' => $parent_key,
				// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- As iMIS sends it.
				'Comment'          => base64_encode( $text ),
				'ImisId'           => $imis,
				'Status'           => 'Published',
				'CreatedOn'        => $when,
			];
		};
		$this->imis->token()
			->reply( '/api/Smart_Blogs_Blog_Post?Limit', 200, Wormiss_Fake_Imis::page( array_merge( [ $post ], $older ) ) )
			->reply(
				'/api/Smart_Blogs_Category',
				200,
				Wormiss_Fake_Imis::page(
					[
						[
							'CategoryKey' => 'cat-1',
							'Name'        => $management,
						],
						[
							'CategoryKey' => 'cat-2',
							'Name'        => $diagnosis,
						],
					]
				)
			)
			->reply(
				'/api/Smart_Blogs_Subcategory',
				200,
				Wormiss_Fake_Imis::page(
					[
						[
							'SubcategoryKey'    => 'sub-1',
							'ParentCategoryKey' => 'cat-1',
							'Name'              => 'Surgery',
						],
						[
							'SubcategoryKey'    => 'sub-2',
							'ParentCategoryKey' => 'cat-2',
							'Name'              => 'Dermoscopy',
						],
					]
				)
			)
			->reply( '/api/Smart_Blogs_Blog_Post?BlogKey', 200, Wormiss_Fake_Imis::page( [ $full ] ) )
			->reply(
				'/api/Smart_Blogs_Comment',
				200,
				Wormiss_Fake_Imis::page(
					[
						// The reply comes first in the list; it still goes under its parent.
						$comment( 'c340cbe0-1cce-4975-a27d-55d9cbdebf07', '6093a8f1-fa14-424d-b3f6-04d6bfeeee7c', '13397', 'Thanks Lars', '2026-06-18T20:29:23.93' ),
						$comment( '6093a8f1-fa14-424d-b3f6-04d6bfeeee7c', '', '12583', 'I would excise it.', '2026-06-18T10:37:22.577' ),
					]
				)
			)
			->reply( '/api/Party/', 200, [ 'Name' => 'Dr Lars Example' ] );
	}

	/**
	 * An import makes the blog post (category under its parent, topics, intro, solution) and
	 * its comments, the reply under its parent, linked to the author's account or named from
	 * iMIS; the same again changes nothing.
	 *
	 * @return void
	 */
	public function test_import(): void {
		$author = self::factory()->user->create(
			[
				'user_login'   => '13397',
				'display_name' => 'Dr Arya Author',
			]
		);
		$this->queue( $this->modern() );
		$report = Wormiss_Blog_Import::run();
		$this->assertSame( 'ok', $report['status'], implode( ' ', $report['notes'] ) );
		$this->assertSame( 1, $report['created'] );
		$this->assertSame( 2, $report['comments'] );
		$post_id = Wormiss_Blog_Import::post_for( self::KEY );
		$post    = get_post( $post_id );
		$this->assertSame( Wormiss_Blog::POST_TYPE, $post->post_type );
		$this->assertSame( 'Small Bowl, Big Decisions!', $post->post_title );
		$this->assertSame( $author, (int) $post->post_author );
		$this->assertSame( 'publish', $post->post_status );
		$this->assertSame( 'ear reconstruction', $post->post_excerpt );
		$this->assertStringContainsString( 'Members only part.', $post->post_content );
		$this->assertStringContainsString( 'welcome.', get_post_meta( $post_id, Wormiss_Blog::META_INTRO, true ) );
		$this->assertSame( '<p>It was a BCC.</p>', get_post_meta( $post_id, Wormiss_Blog::META_SOLUTION, true ) );
		$categories = wp_get_object_terms( $post_id, Wormiss_Blog::TAX_CATEGORY );
		$this->assertEqualsCanonicalizing( [ 'Management', 'Surgery' ], wp_list_pluck( $categories, 'name' ) );
		$surgery = get_term_by( 'name', 'Surgery', Wormiss_Blog::TAX_CATEGORY );
		$this->assertSame( get_term_by( 'name', 'Management', Wormiss_Blog::TAX_CATEGORY )->term_id, $surgery->parent );
		$this->assertEqualsCanonicalizing(
			[ 'BCC (Basal Cell Carcinoma)', 'Ears (peri-aural)' ],
			wp_list_pluck( wp_get_object_terms( $post_id, Wormiss_Blog::TAX_TOPIC ), 'name' )
		);

		$comments = get_comments(
			[
				'post_id' => $post_id,
				'orderby' => 'comment_date_gmt',
				'order'   => 'ASC',
			]
		);
		$this->assertCount( 2, $comments );
		$this->assertSame( 'I would excise it.', $comments[0]->comment_content );
		$this->assertSame( 'Dr Lars Example', $comments[0]->comment_author, 'Named from iMIS.' );
		$this->assertSame( '0', $comments[0]->user_id );
		$this->assertSame( (string) $author, $comments[1]->user_id, 'Linked to the account.' );
		$this->assertSame( $comments[0]->comment_ID, $comments[1]->comment_parent, 'Under its parent.' );

		$this->queue( $this->modern() );
		$report = Wormiss_Blog_Import::run();
		$this->assertSame( 1, $report['skipped'] );
		$this->assertCount( 2, get_comments( [ 'post_id' => $post_id ] ) );
	}

	/**
	 * Every iMIS category and subcategory is made, even with no posts yet (none in a dry run);
	 * one already here from an earlier import is kept, and a rename in iMIS renames it.
	 *
	 * @return void
	 */
	public function test_categories(): void {
		$tax = Wormiss_Blog::TAX_CATEGORY;
		update_option( Wormiss_Settings::OPTION, array_merge( Wormiss_Settings::all(), [ 'blog_import_dry_run' => true ] ) );
		$this->queue( $this->modern() );
		$report = Wormiss_Blog_Import::run();
		$this->assertContains( 'Dry run: would make 4 blog categories.', $report['notes'] );
		$this->assertSame( 0, count( get_terms( 'taxonomy=' . $tax . '&hide_empty=0&fields=ids' ) ) );

		// Made by 0.32.0, before categories had their iMIS key.
		$old = wp_insert_term( 'Management', $tax );
		update_option( Wormiss_Settings::OPTION, array_merge( Wormiss_Settings::all(), [ 'blog_import_dry_run' => false ] ) );
		$this->queue( $this->modern() );
		$report = Wormiss_Blog_Import::run();
		$this->assertContains( '3 blog categories made.', $report['notes'] );
		$diagnosis = get_term_by( 'name', 'Diagnosis', $tax );
		$this->assertSame( 0, $diagnosis->count, 'Made with no posts.' );
		$this->assertSame( $diagnosis->term_id, get_term_by( 'name', 'Dermoscopy', $tax )->parent );
		$this->assertSame( $old['term_id'], get_term_by( 'name', 'Management', $tax )->term_id, 'Kept.' );
		$this->assertSame( 4, count( get_terms( 'taxonomy=' . $tax . '&hide_empty=0&fields=ids' ) ) );

		$this->queue( $this->modern(), 'Practice management' );
		$report = Wormiss_Blog_Import::run();
		$this->assertSame( 1, $report['updated'], 'The new name reaches the post.' );
		$renamed = get_term( $old['term_id'], $tax );
		$this->assertSame( 'Practice management', $renamed->name );
		$this->assertSame( 4, count( get_terms( 'taxonomy=' . $tax . '&hide_empty=0&fields=ids' ) ) );
		$post_id = Wormiss_Blog_Import::post_for( self::KEY );
		$this->assertContains( 'Practice management', wp_list_pluck( wp_get_object_terms( $post_id, $tax ), 'name' ) );

		// The first takes the second's old name, which iMIS gave up in the same change: each
		// keeps its own category here rather than taking over the other's.
		$this->queue( $this->modern(), 'Diagnosis', 'Clinical' );
		Wormiss_Blog_Import::run();
		$this->assertSame( 'Diagnosis', get_term( $old['term_id'], $tax )->name );
		$this->assertSame( 'Clinical', get_term( $diagnosis->term_id, $tax )->name );
		$this->assertSame( $diagnosis->term_id, get_term_by( 'name', 'Dermoscopy', $tax )->parent );
		$this->assertSame( 4, count( get_terms( 'taxonomy=' . $tax . '&hide_empty=0&fields=ids' ) ) );
	}

	/**
	 * One post per batch: progress is kept after each post and the next batch is booked at the
	 * start (so a batch the host stops is carried on), and the last batch tidies up.
	 *
	 * @return void
	 */
	public function test_batches(): void {
		$older = [
			'ID'          => '13397',
			'BlogKey'     => self::OLDER,
			'Title'       => 'An older case',
			'Status'      => 'Published',
			'PublishedOn' => '2025-02-01T09:00:00',
		];
		$this->queue( $this->modern(), 'Management', 'Diagnosis', [ $older ] );
		$report = Wormiss_Blog_Import::run();
		$this->assertSame( 1, $report['created'] );
		$this->assertSame( 1, $report['remaining'] );
		$this->assertSame( [ self::KEY => true ], get_option( Wormiss_Blog_Import::DONE_OPTION ) );
		$this->assertIsArray( get_option( Wormiss_Blog_Import::LIST_OPTION ) );
		$this->assertNotFalse( wp_next_scheduled( Wormiss_Blog_Import::MORE_HOOK ) );
		$this->assertFalse( get_option( Wormiss_Blog_Import::WORKING_OPTION ), 'Finished posts are not left as being worked on.' );

		$data = [
			'content' => [
				[
					'type'  => 'LegacyHtml',
					'props' => [ 'html' => '<p>An older case, in full.</p>' ],
				],
			],
		];
		$this->imis->token()->reply(
			'/api/Smart_Blogs_Blog_Post?BlogKey',
			200,
			Wormiss_Fake_Imis::page(
				[
					array_merge(
						$older,
						[
							'BlogPostData' => [
								'$type'  => 'System.Byte[], mscorlib',
								// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- As iMIS sends it.
								'$value' => base64_encode( (string) wp_json_encode( $data ) ),
							],
						]
					),
				]
			)
		)->reply( '/api/Smart_Blogs_Comment', 200, Wormiss_Fake_Imis::page( [] ) );
		$report = Wormiss_Blog_Import::run_more();
		$this->assertSame( 1, $report['created'], implode( ' ', $report['notes'] ) );
		$this->assertSame( 0, $report['remaining'] );
		$this->assertNotSame( 0, Wormiss_Blog_Import::post_for( self::OLDER ) );
		$this->assertFalse( get_option( Wormiss_Blog_Import::DONE_OPTION ) );
		$this->assertFalse( get_option( Wormiss_Blog_Import::LIST_OPTION ) );
		$this->assertFalse( wp_next_scheduled( Wormiss_Blog_Import::MORE_HOOK ) );

		// Nothing left to carry on with: a late batch does nothing.
		$this->assertSame( 'done', Wormiss_Blog_Import::run_more()['status'] );
	}

	/**
	 * A long post list is read a page at a time over several batches, then imported.
	 *
	 * @return void
	 */
	public function test_list_over_batches(): void {
		// Left by an earlier, unfinished import: a fresh import does not count it.
		update_option( Wormiss_Blog_Import::DONE_OPTION, [ self::KEY => true ] );
		// The first page has a draft and says there is more; the usual list is the second page.
		$draft = [
			'BlogKey' => self::OLDER,
			'Title'   => 'Not yet',
			'Status'  => 'Draft',
		];
		$this->imis->reply( '/api/Smart_Blogs_Blog_Post?Limit=100&Offset=0', 200, Wormiss_Fake_Imis::page( [ $draft ], true, 1 ) );
		$this->queue( $this->modern() );
		add_filter( 'wormiss_batch_seconds', '__return_zero' );
		$report = Wormiss_Blog_Import::run();
		remove_filter( 'wormiss_batch_seconds', '__return_zero' );
		$this->assertSame( 0, $report['created'] );
		$this->assertStringContainsString( 'Still reading the list', implode( ' ', $report['notes'] ) );
		$this->assertSame( 1, get_option( Wormiss_Blog_Import::LIST_OPTION )['offset'] );
		$this->assertNotFalse( wp_next_scheduled( Wormiss_Blog_Import::MORE_HOOK ) );

		// Started long ago, but still being read: carried on, not started again.
		$list         = get_option( Wormiss_Blog_Import::LIST_OPTION );
		$list['time'] = time() - DAY_IN_SECONDS;
		update_option( Wormiss_Blog_Import::LIST_OPTION, $list );
		$this->imis->token(); // Each batch signs in afresh.
		$report = Wormiss_Blog_Import::run_more();
		$this->assertSame( 1, $report['read'], implode( ' ', $report['notes'] ) );
		$this->assertSame( 1, $report['created'] );
		$this->assertNotSame( 0, Wormiss_Blog_Import::post_for( self::KEY ) );
	}

	/**
	 * A post two batches started and never finished (the host stopped them) is skipped with a
	 * note; a batch that finds another one running books itself again.
	 *
	 * @return void
	 */
	public function test_stuck_post_skipped(): void {
		update_option(
			Wormiss_Blog_Import::WORKING_OPTION,
			[
				'key'   => self::KEY,
				'tries' => Wormiss_Batch::MAX_TRIES,
			]
		);
		$this->queue( $this->modern() );
		$report = Wormiss_Blog_Import::run();
		$this->assertSame( 1, $report['skipped'] );
		$this->assertSame( 0, Wormiss_Blog_Import::post_for( self::KEY ) );
		$this->assertStringContainsString( 'two batches could not finish it', implode( ' ', $report['notes'] ) );
		$this->assertFalse( get_option( Wormiss_Blog_Import::WORKING_OPTION ) );

		update_option( Wormiss_Blog_Import::LIST_OPTION, [ 'time' => time() ] );
		update_option( Wormiss_Blog_Import::LOCK_OPTION, (string) time() );
		wp_clear_scheduled_hook( Wormiss_Blog_Import::MORE_HOOK );
		$this->assertSame( 'busy', Wormiss_Blog_Import::run_more()['status'] );
		$this->assertNotFalse( wp_next_scheduled( Wormiss_Blog_Import::MORE_HOOK ) );
	}

	/**
	 * The batch helper counts unfinished tries per item and keeps within PHP's time limit.
	 *
	 * @return void
	 */
	public function test_batch_helper(): void {
		$option = 'wormiss_test_working';
		$this->assertSame( 0, Wormiss_Batch::begin( $option, 'a' ) );
		$this->assertSame( 1, Wormiss_Batch::begin( $option, 'a' ) );
		$this->assertSame( 0, Wormiss_Batch::begin( $option, 'b' ), 'Another item starts again.' );
		Wormiss_Batch::end( $option );
		$this->assertSame( 0, Wormiss_Batch::begin( $option, 'b' ), 'Finished: counted afresh.' );
		Wormiss_Batch::end( $option );

		$limit = ini_get( 'max_execution_time' );
		set_time_limit( 12 ); // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- Restored below.
		$this->assertSame( 6, Wormiss_Batch::seconds() );
		set_time_limit( (int) $limit ); // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- Restoring.

		Wormiss_Batch::start();
		$this->assertFalse( Wormiss_Batch::stopped() );
		Wormiss_Batch::stop();
		$this->assertTrue( Wormiss_Batch::stopped() );
		$this->assertTrue( Wormiss_Batch::put_off() );
		Wormiss_Batch::start();
		$this->assertFalse( Wormiss_Batch::put_off() );

		// Put off without getting further: a try. Put off after getting further: not.
		$this->assertSame( 0, Wormiss_Batch::begin( $option, 'c' ) );
		Wormiss_Batch::stop();
		Wormiss_Batch::done( $option );
		$this->assertSame( 1, Wormiss_Batch::begin( $option, 'c' ) );
		Wormiss_Batch::progress();
		Wormiss_Batch::done( $option );
		$this->assertSame( 0, Wormiss_Batch::begin( $option, 'c' ) );
		Wormiss_Batch::end( $option );
		Wormiss_Batch::start();
	}

	/**
	 * Readers (a chosen role, or staff) see the text and, once due, the solution; others the
	 * intro and a sign-in message; comments and the REST API follow suit.
	 *
	 * @return void
	 */
	public function test_access(): void {
		$post_id = self::factory()->post->create(
			[
				'post_type'    => Wormiss_Blog::POST_TYPE,
				'post_content' => '<p>Full text.</p>',
				'post_status'  => 'publish',
			]
		);
		update_post_meta( $post_id, Wormiss_Blog::META_INTRO, '<p>The intro.</p>' );
		update_post_meta( $post_id, Wormiss_Blog::META_SOLUTION, '<p>The answer.</p>' );
		update_post_meta( $post_id, Wormiss_Blog::META_REVEAL, time() + DAY_IN_SECONDS );
		$this->go_to( get_permalink( $post_id ) );
		$GLOBALS['post'] = get_post( $post_id ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Test set-up.
		setup_postdata( $GLOBALS['post'] );

		wp_set_current_user( 0 );
		$html = Wormiss_Blog::filter_content( '<p>Full text.</p>' );
		$this->assertStringContainsString( 'The intro.', $html );
		$this->assertStringNotContainsString( 'Full text.', $html );
		$this->assertStringContainsString( 'Sign in', $html );
		$this->assertFalse( Wormiss_Blog::comments_open( true, $post_id ) );
		$this->assertSame( [], Wormiss_Blog::comments_array( [ 'x' ], $post_id ) );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'author' ] ) );
		$this->assertFalse( Wormiss_Blog::can_read(), 'A role not ticked cannot read.' );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );
		$html = Wormiss_Blog::filter_content( '<p>Full text.</p>' );
		$this->assertStringContainsString( 'Full text.', $html );
		$this->assertStringNotContainsString( 'The answer.', $html, 'Not before its time.' );
		$this->assertStringContainsString( 'will be published on', $html );
		$this->assertTrue( Wormiss_Blog::comments_open( true, $post_id ) );

		update_post_meta( $post_id, Wormiss_Blog::META_REVEAL, time() - 60 );
		$this->assertStringContainsString( 'The answer.', Wormiss_Blog::filter_content( '<p>Full text.</p>' ) );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );
		$this->assertTrue( Wormiss_Blog::can_read(), 'Staff can always read.' );
		wp_reset_postdata();
	}

	/**
	 * Links to old iMIS blog addresses go to the imported post here.
	 *
	 * @return void
	 */
	public function test_old_addresses(): void {
		$post_id = self::factory()->post->create(
			[
				'post_type'   => Wormiss_Blog::POST_TYPE,
				'post_status' => 'publish',
			]
		);
		update_post_meta( $post_id, Wormiss_Blog_Import::META_KEY, self::KEY );
		$html = Wormiss_Blog::link_old_addresses( '<a href="https://imis.example.org/blogs?_r_path=/view/index/' . strtoupper( self::KEY ) . '" target="_blank">x</a>' );
		$this->assertStringContainsString( 'href="' . get_permalink( $post_id ) . '"', $html );
	}

	/**
	 * Off: nothing is read.
	 *
	 * @return void
	 */
	public function test_off(): void {
		update_option( Wormiss_Settings::OPTION, array_merge( Wormiss_Settings::all(), [ 'blog_import_enabled' => false ] ) );
		$this->assertSame( 'off', Wormiss_Blog_Import::run()['status'] );
		$this->assertSame( [], $this->imis->requests );
	}
}
