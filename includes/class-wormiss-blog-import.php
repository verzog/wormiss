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
 * Import the members' blog from iMIS (Smart Blogs): posts, intros, solutions, comments, images.
 *
 * @package    wormiss
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined( 'ABSPATH' ) || exit;

/**
 * Every published Smart Blogs post becomes a blog post here: its blocks (old posts' HTML, new
 * posts' text, images and separators) as the text, the blocks iMIS shows to non-members as the
 * intro, its Conclusion as the solution with its reveal time, its header picture as the
 * featured image, its category, subcategory and tags, and its author. Every comment comes too,
 * with its replies, linked to the member's WordPress account by iMIS ID or named from iMIS.
 * Pictures on the iMIS site are copied into the Media Library. Works in small batches; a dry
 * run reports only. No CPD is ever recorded for imported activity; nothing changes in iMIS.
 */
class Wormiss_Blog_Import {

	/**
	 * The daily import.
	 */
	const HOOK = 'wormiss_blog_import';

	/**
	 * The next batch.
	 */
	const MORE_HOOK = 'wormiss_blog_import_more';

	/**
	 * The last run's report.
	 */
	const REPORT_OPTION = 'wormiss_blog_import_report';

	/**
	 * The post list read at the start of an import.
	 */
	const LIST_OPTION = 'wormiss_blog_import_list';

	/**
	 * Posts earlier batches dealt with.
	 */
	const DONE_OPTION = 'wormiss_blog_import_done';

	/**
	 * The lock.
	 */
	const LOCK_OPTION = 'wormiss_blog_import_lock';

	/**
	 * Names looked up in iMIS (iMIS ID => name) for commenters without an account here.
	 */
	const PEOPLE_OPTION = 'wormiss_blog_people';

	/**
	 * The post's Smart Blogs key.
	 */
	const META_KEY = '_wormiss_blog_key';

	/**
	 * A fingerprint of what the post last took from iMIS.
	 */
	const META_SOURCE = '_wormiss_blog_source';

	/**
	 * Ticked by an editor: later imports leave the post alone.
	 */
	const META_DETACHED = '_wormiss_blog_detached';

	/**
	 * The author's iMIS ID.
	 */
	const META_AUTHOR_ID = '_wormiss_blog_author_imis';

	/**
	 * The iMIS address of the featured image.
	 */
	const META_FEATURE = '_wormiss_blog_feature';

	/**
	 * On a comment: its Smart Blogs key.
	 */
	const COMMENT_KEY = '_wormiss_blog_comment_key';

	/**
	 * On a comment: the commenter's iMIS ID.
	 */
	const COMMENT_IMIS = '_wormiss_blog_comment_imis';

	/**
	 * On a comment: a fingerprint of what it last took from iMIS.
	 */
	const COMMENT_SOURCE = '_wormiss_blog_comment_source';

	/**
	 * On an attachment: the iMIS address it was copied from.
	 */
	const META_FROM = '_wormiss_blog_image_from';

	/**
	 * Posts changed per batch.
	 */
	const BATCH = 3;

	/**
	 * Posts checked per batch.
	 */
	const MAX_CHECKS = 15;

	/**
	 * Images copied per batch.
	 */
	const MAX_IMAGES = 30;

	/**
	 * Seconds between batches.
	 */
	const BATCH_GAP = MINUTE_IN_SECONDS;

	/**
	 * How long the post list is used by later batches.
	 */
	const LIST_MAX_AGE = 6 * HOUR_IN_SECONDS;

	/**
	 * Lock lifetime.
	 */
	const LOCK_SECONDS = 600;

	/**
	 * Rows per page of iMIS lists.
	 */
	const PAGE = 100;

	/**
	 * Most posts read.
	 */
	const MAX_POSTS = 20000;

	/**
	 * Most comments read for one post.
	 */
	const MAX_COMMENTS = 2000;

	/**
	 * Most notes kept.
	 */
	const MAX_NOTES = 20;

	/**
	 * Smart Blogs blocks that are page furniture (shown from the post's own details here).
	 */
	const SKIPPED = [ 'Category', 'Title', 'AuthorName', 'DatePublished', 'CommentCount', 'NoAccess' ];

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
		add_action( 'admin_post_wormiss_blog_import_now', [ __CLASS__, 'handle_run_now' ] );
		add_action( 'add_meta_boxes_' . Wormiss_Blog::POST_TYPE, [ __CLASS__, 'add_box' ] );
		add_action( 'save_post_' . Wormiss_Blog::POST_TYPE, [ __CLASS__, 'save_box' ] );
	}

	/**
	 * Keep the daily import scheduled while it is on.
	 *
	 * @return void
	 */
	public static function maybe_schedule(): void {
		$next = wp_next_scheduled( self::HOOK );
		if ( Wormiss_Settings::get( 'blog_import_enabled' ) && ! $next ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::HOOK );
		} elseif ( ! Wormiss_Settings::get( 'blog_import_enabled' ) && $next ) {
			wp_clear_scheduled_hook( self::HOOK );
		}
	}

	/**
	 * The Import now button.
	 *
	 * @return void
	 */
	public static function handle_run_now(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'wormiss' ), 403 );
		}
		check_admin_referer( 'wormiss_blog_import_now' );
		self::run();
		wp_safe_redirect( Wormiss_Admin::settings_url() . '#wormiss-blog-import' );
		exit;
	}

	/**
	 * The next batch (cron).
	 *
	 * @return array
	 */
	public static function run_more(): array {
		return self::run( true );
	}

	/**
	 * Import one batch of blog posts.
	 *
	 * @param bool $carry_on Carry on from the last batch.
	 * @return array The report.
	 */
	public static function run( $carry_on = false ): array {
		$done   = true === $carry_on ? get_option( self::DONE_OPTION, [] ) : [];
		$done   = is_array( $done ) ? $done : [];
		$report = [
			'time'      => time(),
			'status'    => 'ok',
			'dry_run'   => (bool) Wormiss_Settings::get( 'blog_import_dry_run' ),
			'read'      => 0,
			'created'   => 0,
			'updated'   => 0,
			'skipped'   => 0,
			'comments'  => 0,
			'remaining' => 0,
			'notes'     => [],
		];
		if ( ! Wormiss_Settings::get( 'blog_import_enabled' ) || ! Wormiss_Settings::is_configured() ) {
			$report['status'] = 'off';
			return self::save( $report );
		}
		if ( ! Wormiss_Event_Import::take_lock( self::LOCK_OPTION, self::LOCK_SECONDS ) ) {
			$report['status'] = 'busy';
			return $report;
		}
		try {
			return self::import( $report, $done, true === $carry_on );
		} finally {
			delete_option( self::LOCK_OPTION );
		}
	}

	/**
	 * Read the post list (or use the one read at the start) and write a batch.
	 *
	 * @param array $report   Report.
	 * @param array $done     Keys dealt with.
	 * @param bool  $carry_on A later batch.
	 * @return array
	 */
	private static function import( array $report, array $done, bool $carry_on ): array {
		self::$images = 0;
		$client       = new Wormiss_Rest_Client();
		$kept         = $carry_on ? get_option( self::LIST_OPTION ) : false;
		if ( is_array( $kept ) && (int) ( $kept['time'] ?? 0 ) > time() - self::LIST_MAX_AGE && is_array( $kept['posts'] ?? null ) ) {
			return self::write( $client, $report, $done, $kept, (int) $kept['time'] );
		}
		try {
			$listing = [
				'posts'         => self::post_list( $client ),
				'categories'    => self::names( $client, 'Smart_Blogs_Category', 'CategoryKey' ),
				'subcategories' => self::names( $client, 'Smart_Blogs_Subcategory', 'SubcategoryKey', 'ParentCategoryKey' ),
				'time'          => time(),
			];
		} catch ( Wormiss_Exception $e ) {
			$report['status']  = 'error';
			$report['notes'][] = $e->getMessage();
			return self::save( $report );
		}
		return self::write( $client, $report, $done, $listing, $listing['time'] );
	}

	/**
	 * Every published post's details (not its text), newest first: key => details.
	 *
	 * @param Wormiss_Rest_Client $client Client.
	 * @return array[]
	 * @throws Wormiss_Exception When iMIS cannot be read.
	 */
	private static function post_list( Wormiss_Rest_Client $client ): array {
		$posts = [];
		foreach ( self::read_all( $client, '/api/Smart_Blogs_Blog_Post', self::MAX_POSTS ) as $row ) {
			$key = strtolower( (string) ( $row['BlogKey'] ?? '' ) );
			if ( ! Wormiss_Content_Check::is_key( $key ) || 'Published' !== (string) ( $row['Status'] ?? '' ) ) {
				continue;
			}
			$posts[ $key ] = [
				'key'         => $key,
				'author'      => (string) ( $row['ID'] ?? '' ),
				'title'       => sanitize_text_field( (string) ( $row['Title'] ?? '' ) ),
				'summary'     => sanitize_textarea_field( (string) ( $row['Summary'] ?? '' ) ),
				'tags'        => (string) ( $row['Tags'] ?? '' ),
				'category'    => (string) ( $row['CategoryKey'] ?? '' ),
				'subcategory' => (string) ( $row['SubcategoryKey'] ?? '' ),
				'thumbnail'   => (string) ( $row['ThumbnailUrl'] ?? '' ),
				'published'   => (string) ( $row['PublishedOn'] ?? '' ),
				'updated'     => (string) ( $row['UpdatedOn'] ?? '' ),
			];
		}
		uasort( $posts, static fn( array $a, array $b ): int => strcmp( $b['published'], $a['published'] ) );
		return $posts;
	}

	/**
	 * Category or subcategory names: key => [name, parent key].
	 *
	 * @param Wormiss_Rest_Client $client Client.
	 * @param string              $entity Entity.
	 * @param string              $key    Key property.
	 * @param string              $parent_key Parent key property ('' for none).
	 * @return array[]
	 * @throws Wormiss_Exception When iMIS cannot be read.
	 */
	private static function names( Wormiss_Rest_Client $client, string $entity, string $key, string $parent_key = '' ): array {
		$out = [];
		foreach ( self::read_all( $client, '/api/' . $entity, 1000 ) as $row ) {
			$id = (string) ( $row[ $key ] ?? '' );
			if ( '' !== $id ) {
				$out[ $id ] = [
					'name'   => sanitize_text_field( (string) ( $row['Name'] ?? '' ) ),
					'parent' => '' !== $parent_key ? (string) ( $row[ $parent_key ] ?? '' ) : '',
				];
			}
		}
		return $out;
	}

	/**
	 * Every row of an iMIS entity list (all pages, up to $max), as property arrays.
	 *
	 * @param Wormiss_Rest_Client $client Client.
	 * @param string              $path   Path, with any filter (no Limit or Offset).
	 * @param int                 $max    Most rows.
	 * @return array[]
	 * @throws Wormiss_Exception When iMIS cannot be read.
	 */
	private static function read_all( Wormiss_Rest_Client $client, string $path, int $max ): array {
		$rows   = [];
		$offset = 0;
		$join   = str_contains( $path, '?' ) ? '&' : '?';
		do {
			$result = $client->request( 'GET', $path . $join . 'Limit=' . self::PAGE . '&Offset=' . $offset, null, null, false );
			if ( ! is_object( $result->body ) ) {
				throw new Wormiss_Exception( __( 'iMIS sent a response that could not be read.', 'wormiss' ) );
			}
			$page = Wormiss_Rest_Client::items( $result->body );
			foreach ( $page as $item ) {
				if ( is_object( $item ) ) {
					$rows[] = Wormiss_Rest_Client::properties( $item );
				}
			}
			[ $more, $offset ] = Wormiss_Rest_Client::next_page( $result->body, $offset, count( $page ) );
			$total             = count( $rows );
		} while ( $more && $page && $total < $max );
		return array_slice( $rows, 0, $max );
	}

	/**
	 * Write one batch.
	 *
	 * @param Wormiss_Rest_Client $client Client.
	 * @param array               $report Report.
	 * @param array               $done   Keys dealt with.
	 * @param array               $listing   posts, categories, subcategories.
	 * @param int                 $read   When the list was read.
	 * @return array
	 */
	private static function write( Wormiss_Rest_Client $client, array $report, array $done, array $listing, int $read ): array {
		$report['read'] = count( $listing['posts'] );
		$writes         = 0;
		$checked        = 0;
		$todo           = 0;
		foreach ( $listing['posts'] as $key => $post ) {
			if ( isset( $done[ $key ] ) ) {
				continue;
			}
			if ( $writes >= self::BATCH || $checked >= self::MAX_CHECKS || self::$images >= self::MAX_IMAGES ) {
				++$todo;
				continue;
			}
			++$checked;
			$done[ $key ] = true;
			$result       = self::one( $client, $post, $listing, $report );
			if ( 'created' === $result || 'updated' === $result ) {
				++$writes;
				++$report[ $result ];
			} else {
				++$report['skipped'];
			}
		}
		$report['remaining'] = $todo;
		if ( $todo > 0 ) {
			update_option( self::DONE_OPTION, $done, false );
			update_option( self::LIST_OPTION, array_merge( $listing, [ 'time' => $read ] ), false );
			if ( ! wp_next_scheduled( self::MORE_HOOK ) ) {
				wp_schedule_single_event( time() + self::BATCH_GAP, self::MORE_HOOK );
			}
		} else {
			delete_option( self::DONE_OPTION );
			delete_option( self::LIST_OPTION );
		}
		return self::save( $report );
	}

	/**
	 * Make or update one blog post and its comments.
	 *
	 * @param Wormiss_Rest_Client $client Client.
	 * @param array               $post   From post_list().
	 * @param array               $listing   The list (category names).
	 * @param array               $report Report.
	 * @return string created, updated or skipped.
	 */
	private static function one( Wormiss_Rest_Client $client, array $post, array $listing, array &$report ): string {
		$post_id = self::post_for( $post['key'] );
		if ( $post_id && ( 'trash' === get_post_status( $post_id ) || get_post_meta( $post_id, self::META_DETACHED, true ) ) ) {
			return 'skipped';
		}
		try {
			$rows     = self::read_all( $client, '/api/Smart_Blogs_Blog_Post?BlogKey=' . rawurlencode( $post['key'] ), 1 );
			$comments = self::read_all( $client, '/api/Smart_Blogs_Comment?BlogKey=' . rawurlencode( $post['key'] ), self::MAX_COMMENTS );
		} catch ( Wormiss_Exception $e ) {
			/* translators: 1: blog title, 2: reason. */
			self::note( $report, sprintf( __( '%1$s: not read from iMIS (%2$s).', 'wormiss' ), $post['title'], $e->getMessage() ) );
			return 'skipped';
		}
		$data  = $rows ? ( $rows[0]['BlogPostData'] ?? '' ) : '';
		$json  = json_decode( Wormiss_Content_Check::decode_blob( is_string( $data ) ? $data : '', false ), true );
		$parts = self::convert( is_array( $json ) ? $json : [] );
		if ( '' === $parts['body'] && '' === $parts['intro'] ) {
			/* translators: %s: blog title. */
			self::note( $report, sprintf( __( '%s: its iMIS content could not be read, so not imported.', 'wormiss' ), $post['title'] ) );
			return 'skipped';
		}
		$feature = '' !== $parts['header'] ? $parts['header'] : $post['thumbnail'];
		$hash    = md5( (string) wp_json_encode( [ $post, $parts, $feature, self::comments_hash( $comments ) ] ) );
		if ( $post_id && get_post_meta( $post_id, self::META_SOURCE, true ) === $hash ) {
			return 'skipped';
		}
		if ( ! empty( $report['dry_run'] ) ) {
			self::note(
				$report,
				sprintf(
					/* translators: 1: make or update, 2: title, 3: number of comments. */
					__( 'Dry run: would %1$s "%2$s" with %3$d comments.', 'wormiss' ),
					$post_id ? __( 'update', 'wormiss' ) : __( 'make', 'wormiss' ),
					$post['title'],
					count( $comments )
				)
			);
			return $post_id ? 'updated' : 'created';
		}
		$author = get_user_by( 'login', $post['author'] );
		$date   = Wormiss_News_Import::local_date( $post['published'] );
		$fields = [
			'post_type'      => Wormiss_Blog::POST_TYPE,
			'post_title'     => $post['title'],
			'post_excerpt'   => $post['summary'],
			'post_content'   => $parts['body'],
			'post_status'    => 'publish',
			'comment_status' => 'open',
			'post_author'    => $author instanceof WP_User ? $author->ID : Wormiss_News_Import::author(),
		];
		if ( '' !== $date ) {
			$fields['post_date']     = $date;
			$fields['post_date_gmt'] = get_gmt_from_date( $date );
		}
		if ( $post_id ) {
			$fields['ID'] = $post_id;
			$saved        = wp_update_post( wp_slash( $fields ), true );
		} else {
			$fields['meta_input'] = [ self::META_KEY => $post['key'] ];
			$saved                = wp_insert_post( wp_slash( $fields ), true );
		}
		if ( is_wp_error( $saved ) || ! $saved ) {
			$reason = is_wp_error( $saved ) ? $saved->get_error_message() : __( 'not saved', 'wormiss' );
			/* translators: 1: blog title, 2: reason. */
			self::note( $report, sprintf( __( '%1$s: no post saved (%2$s).', 'wormiss' ), $post['title'], $reason ) );
			return 'skipped';
		}
		$saved = (int) $saved;
		update_post_meta( $saved, Wormiss_Blog::META_INTRO, wp_slash( $parts['intro'] ) );
		update_post_meta( $saved, Wormiss_Blog::META_SOLUTION, wp_slash( $parts['solution'] ) );
		update_post_meta( $saved, Wormiss_Blog::META_REVEAL, $parts['reveal'] );
		update_post_meta( $saved, self::META_AUTHOR_ID, $post['author'] );
		self::terms( $saved, $post, $listing );
		$report['comments'] += self::comments( $client, $saved, $comments );
		$complete            = self::images( $saved, $feature, $post['title'], $report );
		if ( $complete ) {
			update_post_meta( $saved, self::META_SOURCE, $hash );
		} else {
			delete_post_meta( $saved, self::META_SOURCE );
		}
		return $post_id ? 'updated' : 'created';
	}

	/**
	 * A Smart Blogs post's blocks as HTML: the text (every block but page furniture and the
	 * header picture), the intro (the blocks iMIS shows to non-members), the solution (the
	 * Conclusion block) and its reveal time, and the header picture (an image before the first
	 * dividing line). Old posts, which mark no intro, get their first paragraph as the intro.
	 *
	 * @param array $data The decoded BlogPostData.
	 * @return array body, intro, solution, reveal (Unix time), header (image address), unknown (block types).
	 */
	public static function convert( array $data ): array {
		$out     = [
			'body'     => [],
			'intro'    => [],
			'solution' => [],
			'reveal'   => 0,
			'header'   => '',
			'unknown'  => [],
		];
		$divided = false;
		foreach ( (array) ( $data['content'] ?? [] ) as $block ) {
			if ( ! is_array( $block ) ) {
				continue;
			}
			$type  = (string) ( $block['type'] ?? '' );
			$props = is_array( $block['props'] ?? null ) ? $block['props'] : [];
			if ( 'Conclusion' === $type ) {
				foreach ( (array) ( $props['items'] ?? [] ) as $item ) {
					$html = is_array( $item ) ? self::block_html( $item, $out['unknown'] ) : '';
					if ( '' !== $html ) {
						$out['solution'][] = $html;
					}
				}
				$out['reveal'] = self::reveal_time( (string) ( $props['revealAt'] ?? '' ) );
				continue;
			}
			if ( 'Separator' === $type && 'vertical' !== ( $props['orientation'] ?? '' ) ) {
				$divided = true;
			}
			if ( 'Image' === $type && ! $divided && '' === $out['header'] ) {
				$out['header'] = self::address( (string) ( $props['imageSrc'] ?? ( $props['src'] ?? '' ) ) );
				continue;
			}
			$html = self::block_html( $block, $out['unknown'] );
			if ( '' === $html ) {
				continue;
			}
			$out['body'][] = $html;
			if ( 'visible' === ( $block['visibleWhenRestricted'] ?? '' ) ) {
				$out['intro'][] = $html;
			}
		}
		// A leading or trailing dividing line is just decoration.
		foreach ( [ 'body', 'intro', 'solution' ] as $part ) {
			while ( $out[ $part ] && '<hr />' === reset( $out[ $part ] ) ) {
				array_shift( $out[ $part ] );
			}
			while ( $out[ $part ] && '<hr />' === end( $out[ $part ] ) ) {
				array_pop( $out[ $part ] );
			}
			$out[ $part ] = implode( "\n\n", $out[ $part ] );
		}
		// Old posts mark no intro: their first paragraph with words in it (pictures left out).
		if ( '' === $out['intro'] && preg_match_all( '#<p\b[^>]*>.*?</p>#s', $out['body'], $found ) ) {
			foreach ( $found[0] as $paragraph ) {
				$paragraph = (string) preg_replace( '#<a\b[^>]*>\s*<img\b[^>]*>\s*</a>|<img\b[^>]*>#i', '', $paragraph );
				if ( '' !== trim( html_entity_decode( wp_strip_all_tags( $paragraph ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ), " \t\n\r\0\x0B\xc2\xa0" ) ) {
					$out['intro'] = $paragraph;
					break;
				}
			}
		}
		$out['unknown'] = array_values( array_unique( $out['unknown'] ) );
		return $out;
	}

	/**
	 * One block as HTML ('' for furniture or nothing to show).
	 *
	 * @param array    $block   Block.
	 * @param string[] $unknown Block types not understood, added to.
	 * @return string
	 */
	private static function block_html( array $block, array &$unknown ): string {
		$type  = (string) ( $block['type'] ?? '' );
		$props = is_array( $block['props'] ?? null ) ? $block['props'] : [];
		if ( in_array( $type, self::SKIPPED, true ) ) {
			return '';
		}
		switch ( $type ) {
			case 'LegacyHtml':
				$html = (string) ( $props['html'] ?? '' );
				$html = (string) preg_replace( '#</?(?:html|head|body)\b[^>]*>#i', '', $html );
				return Wormiss_News_Import::clean( self::site_paths( $html ) );
			case 'Text':
				return self::text_html( (string) ( $props['text'] ?? '' ), (string) ( $props['align'] ?? '' ), ! empty( $props['bold'] ) );
			case 'Image':
				$src = self::address( (string) ( $props['imageSrc'] ?? ( $props['src'] ?? '' ) ) );
				if ( '' === $src ) {
					return '';
				}
				return '<figure class="wormiss-blog-image"><img src="' . esc_url( $src ) . '" alt="' . esc_attr( (string) ( $props['alt'] ?? '' ) ) . '" /></figure>';
			case 'Separator':
				return 'vertical' === ( $props['orientation'] ?? '' ) ? '' : '<hr />';
		}
		// Containers (e.g. Flex): their items, in order.
		if ( is_array( $props['items'] ?? null ) ) {
			$parts = [];
			foreach ( $props['items'] as $item ) {
				$html = is_array( $item ) ? self::block_html( $item, $unknown ) : '';
				if ( '' !== $html ) {
					$parts[] = $html;
				}
			}
			return implode( "\n\n", $parts );
		}
		if ( isset( $props['html'] ) && is_string( $props['html'] ) ) {
			return Wormiss_News_Import::clean( self::site_paths( $props['html'] ) );
		}
		if ( isset( $props['text'] ) && is_string( $props['text'] ) ) {
			return self::text_html( $props['text'], (string) ( $props['align'] ?? '' ), ! empty( $props['bold'] ) );
		}
		if ( '' !== $type ) {
			$unknown[] = $type;
		}
		return '';
	}

	/**
	 * Plain text as paragraphs (a blank line between paragraphs, single line breaks kept).
	 *
	 * @param string $text  Text.
	 * @param string $align left, center or right.
	 * @param bool   $bold  Bold.
	 * @return string
	 */
	public static function text_html( string $text, string $align = '', bool $bold = false ): string {
		$text = trim( str_replace( "\r", '', $text ) );
		if ( '' === $text ) {
			return '';
		}
		$class = in_array( $align, [ 'center', 'right' ], true ) ? ' class="has-text-align-' . $align . '"' : '';
		$out   = [];
		foreach ( preg_split( "/\n\s*\n/", $text ) as $paragraph ) {
			$html  = nl2br( esc_html( trim( $paragraph ) ), false );
			$html  = $bold ? '<strong>' . $html . '</strong>' : $html;
			$out[] = '<p' . $class . '>' . $html . '</p>';
		}
		return implode( "\n", $out );
	}

	/**
	 * An image or page address from Smart Blogs as a full address on the iMIS site ($/x and ~/x
	 * are the site root).
	 *
	 * @param string $src Address.
	 * @return string
	 */
	public static function address( string $src ): string {
		$src = trim( $src );
		if ( 0 === strpos( $src, '$/' ) ) {
			$src = substr( $src, 1 );
		}
		return '' === $src ? '' : Wormiss_Events::absolute_url( $src );
	}

	/**
	 * Old HTML's $/ addresses as site addresses (clean() then makes them full).
	 *
	 * @param string $html HTML.
	 * @return string
	 */
	private static function site_paths( string $html ): string {
		return (string) preg_replace( '#\b(href|src)="\$/#i', '$1="/', $html );
	}

	/**
	 * A reveal time (2025-01-01, or 2026-06-19T21:00:00+10:00[Australia/Brisbane]) as Unix time
	 * (0 for none). A time with no zone is in the site's time zone.
	 *
	 * @param string $value Value.
	 * @return int
	 */
	public static function reveal_time( string $value ): int {
		$value = trim( (string) preg_replace( '/\[[^\]]*\]$/', '', trim( $value ) ) );
		if ( '' === $value ) {
			return 0;
		}
		try {
			return ( new DateTimeImmutable( $value, wp_timezone() ) )->getTimestamp();
		} catch ( Exception $e ) {
			unset( $e ); // Not a date: shown straight away.
			return 0;
		}
	}

	/**
	 * Category (with its subcategory beneath it) and topics.
	 *
	 * @param int   $post_id Post ID.
	 * @param array $post    From post_list().
	 * @param array $listing    The list.
	 * @return void
	 */
	private static function terms( int $post_id, array $post, array $listing ): void {
		$ids      = [];
		$category = $listing['categories'][ $post['category'] ]['name'] ?? '';
		$parent   = 0;
		if ( '' !== $category ) {
			$parent = self::term( $category, 0 );
			$ids[]  = $parent;
		}
		$sub = $listing['subcategories'][ $post['subcategory'] ]['name'] ?? '';
		if ( '' !== $sub ) {
			$ids[] = self::term( $sub, $parent );
		}
		wp_set_object_terms( $post_id, array_values( array_filter( $ids ) ), Wormiss_Blog::TAX_CATEGORY );
		$topics = array_filter( array_map( 'trim', array_map( 'sanitize_text_field', explode( ',', $post['tags'] ) ) ) );
		wp_set_object_terms( $post_id, array_values( $topics ), Wormiss_Blog::TAX_TOPIC );
	}

	/**
	 * A blog category's term ID (made if missing).
	 *
	 * @param string $name      Name.
	 * @param int    $parent_id Parent term ID.
	 * @return int
	 */
	private static function term( string $name, int $parent_id ): int {
		$found = term_exists( $name, Wormiss_Blog::TAX_CATEGORY, $parent_id );
		if ( is_array( $found ) ) {
			return (int) $found['term_id'];
		}
		$made = wp_insert_term( $name, Wormiss_Blog::TAX_CATEGORY, [ 'parent' => $parent_id ] );
		return is_wp_error( $made ) ? 0 : (int) $made['term_id'];
	}

	/**
	 * A fingerprint of a post's comments.
	 *
	 * @param array[] $comments Comment rows.
	 * @return string
	 */
	private static function comments_hash( array $comments ): string {
		$parts = [];
		foreach ( $comments as $row ) {
			$parts[] = [ $row['CommentKey'] ?? '', $row['ParentCommentKey'] ?? '', $row['Comment'] ?? '', $row['Status'] ?? '', $row['ImisId'] ?? '' ];
		}
		return md5( (string) wp_json_encode( $parts ) );
	}

	/**
	 * Make or update a post's comments (replies under their parents), oldest first.
	 *
	 * @param Wormiss_Rest_Client $client   Client.
	 * @param int                 $post_id  Post ID.
	 * @param array[]             $comments Comment rows.
	 * @return int Comments made or changed.
	 */
	private static function comments( Wormiss_Rest_Client $client, int $post_id, array $comments ): int {
		usort( $comments, static fn( array $a, array $b ): int => strcmp( (string) ( $a['CreatedOn'] ?? '' ), (string) ( $b['CreatedOn'] ?? '' ) ) );
		$by_key  = self::existing_comments( $post_id );
		$changed = 0;
		$pending = [];
		foreach ( $comments as $row ) {
			$key = strtolower( (string) ( $row['CommentKey'] ?? '' ) );
			if ( ! Wormiss_Content_Check::is_key( $key ) ) {
				continue;
			}
			$text     = self::comment_text( $row['Comment'] ?? '' );
			$imis     = (string) ( $row['ImisId'] ?? '' );
			$parent   = strtolower( (string) ( $row['ParentCommentKey'] ?? '' ) );
			$approved = 'Published' === (string) ( $row['Status'] ?? '' ) ? 1 : 0;
			$hash     = md5( $text . "\0" . $imis . "\0" . $parent . "\0" . $approved );
			$person   = get_user_by( 'login', $imis );
			$date     = Wormiss_News_Import::local_date( (string) ( $row['CreatedOn'] ?? '' ) );
			$fields   = [
				'comment_post_ID'  => $post_id,
				'comment_content'  => $text,
				'comment_approved' => $approved,
				'comment_parent'   => '' !== $parent ? (int) ( $by_key[ $parent ] ?? 0 ) : 0,
				'user_id'          => $person instanceof WP_User ? $person->ID : 0,
				'comment_author'   => $person instanceof WP_User ? $person->display_name : self::person_name( $client, $imis ),
				'comment_type'     => 'comment',
			];
			if ( '' !== $date ) {
				$fields['comment_date']     = $date;
				$fields['comment_date_gmt'] = get_gmt_from_date( $date );
			}
			if ( isset( $by_key[ $key ] ) ) {
				if ( get_comment_meta( $by_key[ $key ], self::COMMENT_SOURCE, true ) === $hash ) {
					continue;
				}
				$fields['comment_ID'] = $by_key[ $key ];
				wp_update_comment( wp_slash( $fields ) );
				$comment_id = $by_key[ $key ];
			} else {
				$comment_id = (int) wp_insert_comment( wp_slash( $fields ) );
				if ( ! $comment_id ) {
					continue;
				}
				$by_key[ $key ] = $comment_id;
				add_comment_meta( $comment_id, self::COMMENT_KEY, $key, true );
			}
			update_comment_meta( $comment_id, self::COMMENT_IMIS, $imis );
			update_comment_meta( $comment_id, self::COMMENT_SOURCE, $hash );
			if ( '' !== $parent && 0 === $fields['comment_parent'] ) {
				$pending[ $comment_id ] = $parent; // Parent comes later in the list: linked below.
			}
			++$changed;
		}
		foreach ( $pending as $comment_id => $parent ) {
			if ( isset( $by_key[ $parent ] ) ) {
				wp_update_comment(
					[
						'comment_ID'     => $comment_id,
						'comment_parent' => $by_key[ $parent ],
					]
				);
			}
		}
		return $changed;
	}

	/**
	 * A post's imported comments: comment key => comment ID.
	 *
	 * @param int $post_id Post ID.
	 * @return int[]
	 */
	private static function existing_comments( int $post_id ): array {
		$out = [];
		foreach ( get_comments(
			[
				'post_id'  => $post_id,
				'status'   => 'all',
				'meta_key' => self::COMMENT_KEY, // phpcs:ignore WordPress.DB.SlowDBQuery -- One query per post.
				'fields'   => 'ids',
				'number'   => 0,
			]
		) as $comment_id ) {
			$key = (string) get_comment_meta( (int) $comment_id, self::COMMENT_KEY, true );
			if ( '' !== $key ) {
				$out[ $key ] = (int) $comment_id;
			}
		}
		return $out;
	}

	/**
	 * A comment's text: iMIS keeps it encoded (base 64); plain text either way.
	 *
	 * @param mixed $value Comment value.
	 * @return string
	 */
	public static function comment_text( $value ): string {
		$value = is_string( $value ) ? trim( $value ) : '';
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- iMIS keeps comments as base 64.
		$decoded = base64_decode( $value, true );
		$text    = false !== $decoded && '' !== $decoded && 1 === preg_match( '//u', $decoded ) ? $decoded : $value;
		return sanitize_textarea_field( str_replace( "\r", '', $text ) );
	}

	/**
	 * The name to show for a commenter without an account here, looked up in iMIS once.
	 *
	 * @param Wormiss_Rest_Client $client Client.
	 * @param string              $imis   iMIS ID.
	 * @return string
	 */
	private static function person_name( Wormiss_Rest_Client $client, string $imis ): string {
		$people = get_option( self::PEOPLE_OPTION, [] );
		$people = is_array( $people ) ? $people : [];
		if ( isset( $people[ $imis ] ) ) {
			return (string) $people[ $imis ];
		}
		$name = '';
		try {
			$party = $client->get_party( $imis );
			// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- iMIS fields.
			$name = is_scalar( $party->Name ?? null ) ? (string) $party->Name : '';
			if ( '' === $name && isset( $party->PersonName ) && is_object( $party->PersonName ) ) {
				$name = trim( (string) ( $party->PersonName->FirstName ?? '' ) . ' ' . (string) ( $party->PersonName->LastName ?? '' ) );
			}
			// phpcs:enable
		} catch ( Wormiss_Exception $e ) {
			unset( $e ); // Unknown or gone: shown as a member.
		}
		$name            = '' !== trim( $name ) ? sanitize_text_field( $name ) : __( 'A member', 'wormiss' );
		$people[ $imis ] = $name;
		update_option( self::PEOPLE_OPTION, $people, false );
		return $name;
	}

	/**
	 * Copy the post's pictures on the iMIS site (text, intro, solution, linked full-size files
	 * and the featured image) into the Media Library, and point the post at the copies.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $feature Featured image address.
	 * @param string $title   Post title (for notes and image titles).
	 * @param array  $report  Report.
	 * @return bool Whether every picture is done.
	 */
	private static function images( int $post_id, string $feature, string $title, array &$report ): bool {
		/**
		 * Whether the blog import copies pictures into the Media Library.
		 *
		 * @param bool $copy Copy (default true).
		 */
		if ( ! apply_filters( 'wormiss_blog_import_images', true ) ) {
			return true;
		}
		$base     = Wormiss_Settings::base_url();
		$complete = true;
		if ( '' === $base ) {
			return true;
		}
		$texts = [
			'content'  => (string) get_post_field( 'post_content', $post_id, 'raw' ),
			'intro'    => (string) get_post_meta( $post_id, Wormiss_Blog::META_INTRO, true ),
			'solution' => (string) get_post_meta( $post_id, Wormiss_Blog::META_SOLUTION, true ),
		];
		$urls  = [];
		foreach ( $texts as $text ) {
			if ( preg_match_all( '#\b(?:src|href)="(' . preg_quote( $base, '#' ) . '/[^"]+\.(?:jpe?g|png|gif|webp))"#i', $text, $found ) ) {
				$urls = array_merge( $urls, $found[1] );
			}
		}
		$map = [];
		foreach ( array_unique( $urls ) as $attr ) {
			$url        = html_entity_decode( $attr, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
			$attachment = self::copy( $post_id, $url, $title, $report, $complete );
			if ( $attachment ) {
				$map[ '"' . $attr . '"' ] = '"' . esc_url( (string) wp_get_attachment_url( $attachment ) ) . '"';
			}
		}
		if ( $map ) {
			wp_update_post(
				wp_slash(
					[
						'ID'           => $post_id,
						'post_content' => strtr( $texts['content'], $map ),
					]
				)
			);
			update_post_meta( $post_id, Wormiss_Blog::META_INTRO, wp_slash( strtr( $texts['intro'], $map ) ) );
			update_post_meta( $post_id, Wormiss_Blog::META_SOLUTION, wp_slash( strtr( $texts['solution'], $map ) ) );
		}
		$feature = self::address( $feature );
		if ( '' !== $feature && 0 === strpos( $feature, $base . '/' ) && get_post_meta( $post_id, self::META_FEATURE, true ) !== $feature ) {
			$attachment = self::copy( $post_id, $feature, $title, $report, $complete );
			if ( $attachment ) {
				set_post_thumbnail( $post_id, $attachment );
				update_post_meta( $post_id, self::META_FEATURE, $feature );
			}
		}
		return $complete;
	}

	/**
	 * The Media Library copy of an iMIS picture: one already copied, or a new copy if this run
	 * has room.
	 *
	 * @param int    $post_id  Post the picture belongs to.
	 * @param string $url      Address.
	 * @param string $title    Post title.
	 * @param array  $report   Report.
	 * @param bool   $complete Set to false when not copied.
	 * @return int Attachment ID, or 0.
	 */
	private static function copy( int $post_id, string $url, string $title, array &$report, bool &$complete ): int {
		// phpcs:disable WordPress.DB.SlowDBQuery -- One lookup per picture.
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
		if ( self::$images >= self::MAX_IMAGES ) {
			$complete = false;
			return 0;
		}
		++self::$images;
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		$tmp = download_url( $url, 30 );
		if ( is_wp_error( $tmp ) ) {
			$complete = false;
			/* translators: 1: title, 2: reason. */
			self::note( $report, sprintf( __( '%1$s: a picture could not be copied (%2$s).', 'wormiss' ), $title, $tmp->get_error_message() ) );
			return 0;
		}
		$name       = sanitize_file_name( rawurldecode( wp_basename( (string) wp_parse_url( $url, PHP_URL_PATH ) ) ) );
		$attachment = media_handle_sideload(
			[
				'name'     => '' !== $name ? $name : 'blog-image.jpg',
				'tmp_name' => $tmp,
			],
			$post_id,
			$title
		);
		if ( is_wp_error( $attachment ) ) {
			$complete = false;
			wp_delete_file( $tmp );
			/* translators: 1: title, 2: reason. */
			self::note( $report, sprintf( __( '%1$s: a picture could not be added (%2$s).', 'wormiss' ), $title, $attachment->get_error_message() ) );
			return 0;
		}
		update_post_meta( (int) $attachment, self::META_FROM, $url );
		return (int) $attachment;
	}

	/**
	 * The post made for a Smart Blogs post (trashed ones too), or 0.
	 *
	 * @param string $key Blog key.
	 * @return int
	 */
	public static function post_for( string $key ): int {
		// phpcs:disable WordPress.DB.SlowDBQuery -- One meta lookup.
		$found = get_posts(
			[
				'post_type'        => Wormiss_Blog::POST_TYPE,
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
	 * The "iMIS blog" box on an imported post.
	 *
	 * @param WP_Post $post Post.
	 * @return void
	 */
	public static function add_box( $post ): void {
		if ( $post instanceof WP_Post && '' !== (string) get_post_meta( $post->ID, self::META_KEY, true ) ) {
			add_meta_box( 'wormiss-blog', __( 'iMIS blog', 'wormiss' ), [ __CLASS__, 'render_box' ], Wormiss_Blog::POST_TYPE, 'side' );
		}
	}

	/**
	 * The box's content.
	 *
	 * @param WP_Post $post Post.
	 * @return void
	 */
	public static function render_box( $post ): void {
		wp_nonce_field( 'wormiss_blog_box', 'wormiss_blog_box_nonce' );
		echo '<p>' . esc_html__( 'Imported from iMIS (Smart Blogs). Each import brings this post and its comments up to date.', 'wormiss' ) . '</p>';
		printf(
			'<p><label><input type="checkbox" name="wormiss_blog_detached" value="1" %1$s /> %2$s</label></p>',
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
		if ( ! isset( $_POST['wormiss_blog_box_nonce'] )
			|| ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['wormiss_blog_box_nonce'] ) ), 'wormiss_blog_box' )
			|| ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		if ( ! empty( $_POST['wormiss_blog_detached'] ) ) {
			update_post_meta( $post_id, self::META_DETACHED, 1 );
		} else {
			delete_post_meta( $post_id, self::META_DETACHED );
		}
	}

	/**
	 * Add a note (up to MAX_NOTES).
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
				'BLOG IMPORT',
				'error' === $report['status'] ? Wormiss_Logger::STATUS_ERROR : Wormiss_Logger::STATUS_SUCCESS,
				'error' === $report['status'] ? implode( ' ', $report['notes'] ) : null,
				null,
				(string) wp_json_encode( $report )
			);
		}
		return $report;
	}

	/**
	 * The last run's notes.
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
	 * The last run in words.
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
			return sprintf( __( 'Last blog import %1$s: nothing changed (%2$s).', 'wormiss' ), $when, implode( ' ', $report['notes'] ) );
		}
		if ( 'off' === $report['status'] ) {
			/* translators: %s: date and time. */
			return sprintf( __( 'Last blog import %s: the blog import is off, so nothing changed.', 'wormiss' ), $when );
		}
		$text = sprintf(
			/* translators: 1: date and time, 2: posts, 3: made, 4: updated, 5: unchanged, 6: comments. */
			__( 'Last blog import %1$s: %2$d blog posts in iMIS; %3$d made, %4$d updated, %5$d unchanged or skipped; %6$d comments made or changed.', 'wormiss' ),
			$when,
			(int) $report['read'],
			(int) $report['created'],
			(int) $report['updated'],
			(int) $report['skipped'],
			(int) ( $report['comments'] ?? 0 )
		);
		if ( ! empty( $report['dry_run'] ) ) {
			$text .= ' ' . __( 'Dry run: nothing was saved.', 'wormiss' );
		}
		if ( ! empty( $report['remaining'] ) ) {
			/* translators: %d: posts still to check. */
			$text .= ' ' . sprintf( __( 'Still going: %d more blog posts to check in the background.', 'wormiss' ), (int) $report['remaining'] );
		}
		return $text;
	}
}
