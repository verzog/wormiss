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
		$this->assertStringContainsString( '<img class="img-fluid" src="https://imis.example.org/images/Causeis/smart-blogs/13397/k/case.jpg" alt="Case" />', $parts['body'] );
		$this->assertStringNotContainsString( 'header.jpg', $parts['body'] );
		$this->assertStringNotContainsString( 'Access Restricted', $parts['body'] );
		$this->assertSame( '<p>It was a BCC.</p>', $parts['solution'] );
		$this->assertSame( strtotime( '2026-06-19T11:00:00Z' ), $parts['reveal'] );
		$this->assertSame( [], $parts['unknown'] );
	}

	/**
	 * A Flex row of two or more blocks becomes a Bootstrap-style row of columns; a Flex column
	 * (dividing lines kept), or a row with one block left, stays stacked.
	 *
	 * @return void
	 */
	public function test_convert_rows(): void {
		$text = static fn( string $words ): array => [
			'type'  => 'Text',
			'props' => [ 'text' => $words ],
		];
		$flex = static fn( string $direction, array $items ): array => [
			'type'  => 'Flex',
			'props' => [
				'direction' => $direction,
				'items'     => $items,
			],
		];
		$body = Wormiss_Blog_Import::convert(
			[
				'content' => [
					$flex( 'row', [ $text( 'Left.' ), $text( 'Right.' ) ] ),
					$flex(
						'column',
						[
							$text( 'Top.' ),
							[
								'type'  => 'Separator',
								'props' => [ 'orientation' => 'horizontal' ],
							],
							$text( 'Bottom.' ),
						]
					),
					$flex(
						'row',
						[
							[
								'type'  => 'AuthorName',
								'props' => [],
							],
							$text( 'Alone.' ),
						]
					),
				],
			]
		)['body'];
		$this->assertStringContainsString(
			"<div class=\"row wormiss-blog-row\">\n<div class=\"col-md\">\n<p>Left.</p>\n</div>\n<div class=\"col-md\">\n<p>Right.</p>\n</div>\n</div>",
			$body
		);
		$this->assertSame( 1, substr_count( $body, 'wormiss-blog-row' ) );
		$this->assertStringContainsString( "<p>Top.</p>\n\n<hr />\n\n<p>Bottom.</p>", $body, 'Stacked: the dividing line kept.' );
		$this->assertStringContainsString( '<p>Alone.</p>', $body );
	}

	/**
	 * A grid keeps its columns (dividing lines are not cells), a poll shows its question and
	 * choices without the template wording, and untouched template text is left out.
	 *
	 * @return void
	 */
	public function test_convert_grids_polls(): void {
		$image = static fn( string $name ): array => [
			'type'  => 'Image',
			'props' => [ 'imageSrc' => '$/images/b/' . $name . '.jpg' ],
		];
		$text  = static fn( string $words, string $id = '' ): array => [
			'type'  => 'Text',
			'props' => [
				'text' => $words,
				'id'   => $id,
			],
		];
		$parts = Wormiss_Blog_Import::convert(
			[
				'content' => [
					[
						'type'  => 'Separator',
						'props' => [ 'orientation' => 'horizontal' ],
					],
					[
						'type'  => 'Grid',
						'props' => [
							'numColumns' => 2,
							'id'         => 'Grid-3942ecb2',
							'items'      => [
								[
									'type'  => 'Flex',
									'props' => [ 'items' => [ $text( 'Text' ) ] ],
								],
								$image( 'a' ),
								[
									'type'  => 'Separator',
									'props' => [ 'orientation' => 'horizontal' ],
								],
								$image( 'b' ),
								$image( 'c' ),
							],
						],
					],
					$text( 'Lorem ipsum dolor, sit amet consectetur.' ),
					[
						'type'  => 'Poll',
						'props' => [
							'id'       => 'Poll-1dbdb643',
							'question' => 'Your question  so  what  is  this  lesion?',
							'options'  => [
								[ 'text' => 'Option 1  leave  it  alone' ],
								[ 'text' => '' ],
								[ 'text' => 'monitor' ],
							],
						],
					],
					[
						'type'  => 'Poll',
						'props' => [
							'question' => 'Your question',
							'options'  => [ [ 'text' => 'Option 1' ], [ 'text' => 'Option 2' ] ],
						],
					],
				],
			]
		);
		$body  = $parts['body'];
		$this->assertStringContainsString( '<div class="wormiss-blog-grid wormiss-blog-grid--cols-2">', $body );
		$this->assertSame( 3, substr_count( $body, 'wormiss-blog-grid__item' ), 'Three pictures; no line or empty text as cells.' );
		$this->assertStringNotContainsString( '<p>Text</p>', $body );
		$this->assertStringNotContainsString( 'Lorem ipsum', $body );
		$this->assertSame( 1, substr_count( $body, 'wormiss-blog-poll' ) - substr_count( $body, 'wormiss-blog-poll__' ), 'The untouched poll shows nothing.' );
		$this->assertStringContainsString( '<p class="wormiss-blog-poll__question">so what is this lesion?</p>', $body );
		$this->assertStringContainsString( '<li>leave it alone</li><li>monitor</li>', $body );
		$this->assertSame( [], $parts['unknown'] );
		$this->assertContains( 'Poll-1dbdb643', $parts['ids'] );
		$this->assertContains( 'Grid-3942ecb2', $parts['ids'] );
	}

	/**
	 * Imported pictures get only the sizes the blog shows.
	 */
	public function test_blog_sizes(): void {
		$size  = [
			'width'  => 100,
			'height' => 100,
			'crop'   => false,
		];
		$sizes = array_fill_keys( [ 'thumbnail', 'medium', 'medium_large', 'large', '1536x1536', '2048x2048' ], $size );
		$this->assertSame( [ 'thumbnail', 'medium', 'large' ], array_keys( Wormiss_Blog_Import::blog_sizes( $sizes ) ) );

		$more = static function (): array {
			return [ 'medium', '1536x1536' ];
		};
		add_filter( 'wormiss_blog_image_sizes', $more );
		$this->assertSame( [ 'medium', '1536x1536' ], array_keys( Wormiss_Blog_Import::blog_sizes( $sizes ) ) );
		remove_filter( 'wormiss_blog_image_sizes', $more );
	}

	/**
	 * An imported picture is attributed to the blog post's author, not the account running the import.
	 *
	 * @return void
	 */
	public function test_imported_picture_gets_post_author(): void {
		if ( ! function_exists( 'imagejpeg' ) ) {
			$this->markTestSkipped( 'GD is not available.' );
		}
		$author  = (int) self::factory()->user->create( [ 'role' => 'author' ] );
		$post_id = (int) self::factory()->post->create(
			[
				'post_type'   => Wormiss_Blog::POST_TYPE,
				'post_author' => $author,
			]
		);

		// Serve a real JPEG into download_url()'s stream target for the picture request.
		$serve = static function ( $pre, $args, $url ) {
			if ( false !== strpos( $url, 'case.jpg' ) && ! empty( $args['filename'] ) ) {
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

		// Reset the per-run image counter so copy() does not short-circuit. Reflection reaches private
		// members without setAccessible() on PHP 8.1+, where that method is a deprecated no-op.
		$counter = new ReflectionProperty( Wormiss_Blog_Import::class, 'images' );
		$counter->setValue( null, 0 );

		$copy       = new ReflectionMethod( Wormiss_Blog_Import::class, 'copy' );
		$report     = [ 'notes' => [] ];
		$complete   = true;
		$attachment = (int) $copy->invokeArgs( null, [ $post_id, 'https://imis.example.org/images/x/case.jpg', 'Title', &$report, &$complete ] );

		remove_filter( 'pre_http_request', $serve, 20 );

		$this->assertGreaterThan( 0, $attachment, 'The picture was imported. Notes: ' . implode( ' | ', $report['notes'] ) );
		$this->assertSame( $author, (int) get_post_field( 'post_author', $attachment ), 'Attributed to the post author.' );
		$this->assertSame( $post_id, (int) wp_get_post_parent_id( $attachment ), 'Attached to the post.' );
	}

	/**
	 * A post whose blocks are only in its preview data (BlogPostData left empty) is read from there.
	 */
	public function test_post_data_preview(): void {
		$blob    = static function ( array $data ): string {
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- As iMIS sends it.
			return base64_encode( (string) wp_json_encode( $data ) );
		};
		$empty   = [
			'content' => [],
			'root'    => [ 'props' => [ 'title' => '' ] ],
			'zones'   => (object) [],
		];
		$preview = [
			'root'    => [ 'props' => [ 'title' => 'BCC or Melanoma or Both' ] ],
			'content' => [
				[
					'type'  => 'Text',
					'props' => [ 'text' => '<p>A torso lesion.</p>' ],
				],
			],
		];
		$data    = Wormiss_Blog_Import::post_data(
			[
				'BlogPostData'        => $blob( $empty ),
				'BlogPostPreviewData' => $blob( $preview ),
			]
		);
		$this->assertSame( 'BCC or Melanoma or Both', $data['root']['props']['title'] );
		$this->assertCount( 1, $data['content'] );

		// BlogPostData is used when it has blocks.
		$main = Wormiss_Blog_Import::post_data(
			[
				'BlogPostData'        => $blob( $preview ),
				'BlogPostPreviewData' => $blob( $empty ),
			]
		);
		$this->assertCount( 1, $main['content'] );
		$this->assertSame( [], Wormiss_Blog_Import::post_data( [] ) );
	}

	/**
	 * A post is matched to the template whose own blocks it shares most; blocks every template
	 * copied are no help; old HTML posts are "Legacy", others "Other".
	 *
	 * @return void
	 */
	public function test_template_for(): void {
		$own = Wormiss_Blog_Import::unique_ids(
			[
				'Basic Template' => [ 'Category-03a1', 'Title-f097', 'Grid-328b', 'Text-c8cb' ],
				'Template 3'     => [ 'Category-03a1', 'Title-f097', 'Grid-328b', 'Grid-3942', 'Poll-1dbd' ],
				'Template 2'     => [ 'Category-03a1', 'Title-f097', 'ImageShowcase-ead0' ],
			]
		);
		$this->assertSame( [ 'Text-c8cb' ], $own['Basic Template'] );
		$this->assertSame( 'Template 3', Wormiss_Blog_Import::template_for( [ 'Category-03a1', 'Grid-328b', 'Grid-3942', 'Poll-1dbd' ], false, $own ) );
		$this->assertSame( 'Template 2', Wormiss_Blog_Import::template_for( [ 'Title-f097', 'ImageShowcase-ead0' ], false, $own ) );
		$this->assertSame( 'Other', Wormiss_Blog_Import::template_for( [ 'Category-03a1', 'Title-f097' ], false, $own ) );
		$this->assertSame( 'Legacy', Wormiss_Blog_Import::template_for( [], true, $own ) );
		$this->assertSame( 'Other', Wormiss_Blog_Import::template_for( [ 'Poll-1dbd' ], false, [] ) );
	}

	/**
	 * Old posts' tidied HTML gets Bootstrap-style classes, and spacing done with empty
	 * paragraphs or runs of line breaks is taken out.
	 *
	 * @return void
	 */
	public function test_bootstrap(): void {
		$html = '<p>&nbsp;</p><p><br>Line one<br><br><br><br>line two<br></p><p> </p>'
			. '<p><img src="a.jpg" alt=""></p><table><tr><td>1</td></tr></table><blockquote>Quote</blockquote>';
		$this->assertSame(
			'<p>Line one<br /><br />line two</p><p><img class="img-fluid" src="a.jpg" alt=""></p>'
			. '<div class="table-responsive"><table class="table table-sm"><tr><td>1</td></tr></table></div>'
			. '<blockquote class="blockquote">Quote</blockquote>',
			Wormiss_Blog_Import::bootstrap( $html )
		);
	}

	/**
	 * Uploads are scaled down to 1600 pixels on their longest side; 0 keeps WordPress's limit.
	 *
	 * @return void
	 */
	public function test_upload_size(): void {
		$this->assertSame( 1600, apply_filters( 'big_image_size_threshold', 2560, [ 4000, 3000 ], '/tmp/a.jpg', 0 ) );
		add_filter( 'wormiss_image_max_size', '__return_zero' );
		$this->assertSame( 2560, apply_filters( 'big_image_size_threshold', 2560, [ 4000, 3000 ], '/tmp/a.jpg', 0 ) );
		remove_filter( 'wormiss_image_max_size', '__return_zero' );
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
			->reply(
				'/api/Smart_Blogs_Template',
				200,
				Wormiss_Fake_Imis::page(
					[
						[
							'Name'         => 'Template 9',
							'TemplateData' => [
								'$type'  => 'System.Byte[], mscorlib',
								// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- As iMIS sends it.
								'$value' => base64_encode( (string) wp_json_encode( [ 'content' => [ [ 'props' => [ 'id' => 'Text-case' ] ] ] ] ) ),
							],
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
		$this->assertSame( 'Other', get_post_meta( $post_id, Wormiss_Blog_Import::META_TEMPLATE, true ), 'No template block in it.' );
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
	 * BATCH posts per batch: progress is kept after each post and the next batch is booked at the
	 * start (so a batch the host stops is carried on), and the last batch tidies up.
	 *
	 * @return void
	 */
	public function test_batches(): void {
		// BATCH older posts, so the first batch makes BATCH posts and leaves the oldest.
		$older = [];
		for ( $i = 1; $i <= Wormiss_Blog_Import::BATCH; $i++ ) {
			$older[] = [
				'ID'          => '13397',
				'BlogKey'     => sprintf( '0b6a1d2e-1f3c-4a5b-8c9d-%012d', $i ),
				'Title'       => 'Older case ' . $i,
				'Status'      => 'Published',
				'PublishedOn' => sprintf( '2025-%02d-01T09:00:00', 13 - $i ),
			];
		}
		$data = [
			'content' => [
				[
					'type'  => 'LegacyHtml',
					'props' => [ 'html' => '<p>An older case, in full.</p>' ],
				],
			],
		];
		$full = static fn( array $row ): array => array_merge(
			$row,
			[
				'BlogPostData' => [
					'$type'  => 'System.Byte[], mscorlib',
					// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- As iMIS sends it.
					'$value' => base64_encode( (string) wp_json_encode( $data ) ),
				],
			]
		);
		$this->queue( $this->modern(), 'Management', 'Diagnosis', $older );
		for ( $i = 0; $i < Wormiss_Blog_Import::BATCH - 1; $i++ ) {
			$this->imis->reply( '/api/Smart_Blogs_Blog_Post?BlogKey', 200, Wormiss_Fake_Imis::page( [ $full( $older[ $i ] ) ] ) )
				->reply( '/api/Smart_Blogs_Comment', 200, Wormiss_Fake_Imis::page( [] ) );
		}
		$report = Wormiss_Blog_Import::run();
		$this->assertSame( Wormiss_Blog_Import::BATCH, $report['created'], implode( ' ', $report['notes'] ) );
		$this->assertSame( 1, $report['remaining'] );
		$this->assertCount( Wormiss_Blog_Import::BATCH, get_option( Wormiss_Blog_Import::DONE_OPTION ) );
		$this->assertIsArray( get_option( Wormiss_Blog_Import::LIST_OPTION ) );
		$this->assertNotFalse( wp_next_scheduled( Wormiss_Blog_Import::MORE_HOOK ) );
		$this->assertFalse( get_option( Wormiss_Blog_Import::WORKING_OPTION ), 'Finished posts are not left as being worked on.' );

		$last = end( $older );
		$this->imis->token()
			->reply( '/api/Smart_Blogs_Blog_Post?BlogKey', 200, Wormiss_Fake_Imis::page( [ $full( $last ) ] ) )
			->reply( '/api/Smart_Blogs_Comment', 200, Wormiss_Fake_Imis::page( [] ) );
		$report = Wormiss_Blog_Import::run_more();
		$this->assertSame( 1, $report['created'], implode( ' ', $report['notes'] ) );
		$this->assertSame( 0, $report['remaining'] );
		$this->assertNotSame( 0, Wormiss_Blog_Import::post_for( $last['BlogKey'] ) );
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
	 * Posts per batch and minutes between batches come from the settings, kept within limits.
	 *
	 * @return void
	 */
	public function test_batch_settings(): void {
		$this->assertSame( Wormiss_Blog_Import::BATCH, Wormiss_Blog_Import::batch() );
		$this->assertSame( MINUTE_IN_SECONDS, Wormiss_Blog_Import::gap() );
		$clean = Wormiss_Settings::sanitize(
			[
				'blog_batch_posts'   => '500',
				'blog_batch_minutes' => '0',
			]
		);
		$this->assertSame( Wormiss_Blog_Import::BATCH_MAX, $clean['blog_batch_posts'] );
		$this->assertSame( Wormiss_Blog_Import::SECONDS, Wormiss_Blog_Import::seconds() );
		$this->assertSame( Wormiss_Blog_Import::SECONDS_MAX, Wormiss_Settings::sanitize( [ 'blog_batch_seconds' => '999' ] )['blog_batch_seconds'] );
		$this->assertSame( 1, $clean['blog_batch_minutes'] );
		$saved = [
			'blog_batch_posts'   => 4,
			'blog_batch_minutes' => 3,
		];
		update_option( Wormiss_Settings::OPTION, array_merge( Wormiss_Settings::all(), $saved ) );
		$this->assertSame( 4, Wormiss_Blog_Import::batch() );
		$this->assertSame( 3 * MINUTE_IN_SECONDS, Wormiss_Blog_Import::gap() );
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

		// A batch wanting 40 seconds where PHP allows 30 asks PHP for more, then gets its 40.
		set_time_limit( 30 ); // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- Restored below.
		Wormiss_Batch::start( 40 );
		$this->assertSame( 110, (int) ini_get( 'max_execution_time' ) );
		$this->assertSame( 40, Wormiss_Batch::seconds() );
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
	 * Post Terms blocks mark a post's category and subcategory by level, and the blog's
	 * listing pages show twelve posts.
	 *
	 * @return void
	 */
	public function test_cards(): void {
		$tax     = Wormiss_Blog::TAX_CATEGORY;
		$parent  = self::factory()->term->create(
			[
				'taxonomy' => $tax,
				'name'     => 'Diagnosis',
			]
		);
		$child   = self::factory()->term->create(
			[
				'taxonomy' => $tax,
				'name'     => 'All Levels',
				'parent'   => $parent,
			]
		);
		$post_id = self::factory()->post->create(
			[
				'post_type'   => Wormiss_Blog::POST_TYPE,
				'post_status' => 'publish',
			]
		);
		wp_set_object_terms( $post_id, [ $parent, $child ], $tax );
		$GLOBALS['post'] = get_post( $post_id ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Test set-up.
		$html            = render_block(
			[
				'blockName'    => 'core/post-terms',
				'attrs'        => [ 'term' => $tax ],
				'innerBlocks'  => [],
				'innerHTML'    => '',
				'innerContent' => [],
			]
		);
		$this->assertMatchesRegularExpression( '#<a [^>]*class="wormiss-term--category"[^>]*>Diagnosis</a>#', $html );
		$this->assertMatchesRegularExpression( '#<a [^>]*class="wormiss-term--subcategory"[^>]*>All Levels</a>#', $html );
		unset( $GLOBALS['post'] );

		$this->go_to( add_query_arg( 'post_type', Wormiss_Blog::POST_TYPE, home_url( '/' ) ) );
		$this->assertSame( Wormiss_Blog::PER_PAGE, (int) get_query_var( 'posts_per_page' ) );
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
		$html = Wormiss_Blog::filter_content( '<p>Full text.</p>' );
		$this->assertStringContainsString( 'The answer.', $html );
		$this->assertStringStartsWith( '<div class="wormiss-blog-post">', $html );
		$this->assertStringContainsString( '<details class="card wormiss-blog-solution">', $html, 'Closed until opened.' );
		$this->assertStringContainsString( 'Show the solution', $html );
		$this->assertStringNotContainsString( '<details open', $html );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );
		$this->assertTrue( Wormiss_Blog::can_read(), 'Staff can always read.' );
		wp_reset_postdata();
	}

	/**
	 * The Blog post details block: comment count, the line under the title, and the column with
	 * Back to posts, initials, reading time and date.
	 *
	 * @return void
	 */
	public function test_meta(): void {
		$author  = self::factory()->user->create(
			[
				'display_name' => 'Lloyd Peterson',
				'description'  => 'Fellow, Skin Cancer College Australasia',
			]
		);
		$post_id = self::factory()->post->create(
			[
				'post_type'    => Wormiss_Blog::POST_TYPE,
				'post_status'  => 'publish',
				'post_author'  => $author,
				'post_date'    => '2026-11-13 09:00:00',
				'post_content' => '<p>' . str_repeat( 'word ', 450 ) . '</p>',
			]
		);
		self::factory()->comment->create_post_comments( $post_id, 3 );

		$this->assertStringContainsString( '<span>3</span>', Wormiss_Blog::meta_html( $post_id, 'comments' ) );
		$line = Wormiss_Blog::meta_html( $post_id, 'line' );
		$this->assertStringContainsString( 'Lloyd Peterson', $line );
		$this->assertStringContainsString( '13/11/2026', $line );
		$side = Wormiss_Blog::meta_html( $post_id, 'sidebar' );
		$this->assertStringContainsString( 'Back to posts', $side );
		$this->assertStringContainsString( '>LP<', $side );
		$this->assertStringContainsString( '3 min read', $side );
		$this->assertStringContainsString( '13 Nov 2026', $side );
		$card = Wormiss_Blog::meta_html( $post_id, 'author' );
		$this->assertStringContainsString( 'By <strong>Lloyd Peterson</strong>', $card );
		$this->assertStringContainsString( 'Fellow, Skin Cancer College Australasia', $card );
		$this->assertStringContainsString( '>LP<', $card );
		$this->assertSame( '', Wormiss_Blog::meta_html( self::factory()->post->create(), 'comments' ), 'Blog posts only.' );
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
