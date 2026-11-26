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
	 * Option: the blog post being imported, and how many batches have tried it.
	 */
	const WORKING_OPTION = 'wormiss_blog_import_working';

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
	 * Post meta: the name of the iMIS (Smart Blogs) template the post was made from, as matched.
	 */
	const META_TEMPLATE = '_wormiss_blog_template';

	/**
	 * Template text an author never changed, left out: the whole text.
	 */
	const PLACEHOLDER = 'Text';

	/**
	 * Template text an author never changed, left out: the start of the text.
	 */
	const PLACEHOLDER_START = 'Lorem ipsum dolor';

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
	 * On a blog category: its iMIS category or subcategory key.
	 */
	const TERM_KEY = '_wormiss_blog_category_key';

	/**
	 * Posts changed per batch, by default (Settings > Members' blog).
	 */
	const BATCH = 10;

	/**
	 * Most posts changed per batch that the setting allows.
	 */
	const BATCH_MAX = 20;

	/**
	 * Minutes between batches, by default (Settings > Members' blog).
	 */
	const GAP_MINUTES = 1;

	/**
	 * Most minutes between batches that the setting allows.
	 */
	const GAP_MAX = 10;

	/**
	 * Seconds each batch works for, by default (Settings > Members' blog).
	 */
	const SECONDS = 40;

	/**
	 * Fewest seconds per batch the setting allows.
	 */
	const SECONDS_MIN = 10;

	/**
	 * Most seconds per batch the setting allows.
	 */
	const SECONDS_MAX = 60;

	/**
	 * How long the post list is used by later batches.
	 */
	const LIST_MAX_AGE = 6 * HOUR_IN_SECONDS;

	/**
	 * Lock lifetime.
	 */
	const LOCK_SECONDS = 300;

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
		wp_safe_redirect( Wormiss_Admin::settings_url( 'blog' ) . '#wormiss-blog-import' );
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
	 * Posts changed per batch (Settings > Members' blog); each batch also stops after about 20
	 * seconds, so a large setting only helps while the site keeps up. Up to 3 pictures each.
	 *
	 * @return int
	 */
	public static function batch(): int {
		return min( self::BATCH_MAX, max( 1, (int) Wormiss_Settings::get( 'blog_batch_posts' ) ) );
	}

	/**
	 * Seconds each batch works for (Settings > Members' blog).
	 *
	 * @return int
	 */
	public static function seconds(): int {
		return min( self::SECONDS_MAX, max( self::SECONDS_MIN, (int) Wormiss_Settings::get( 'blog_batch_seconds' ) ) );
	}

	/**
	 * Seconds between batches (Settings > Members' blog).
	 *
	 * @return int
	 */
	public static function gap(): int {
		return min( self::GAP_MAX, max( 1, (int) Wormiss_Settings::get( 'blog_batch_minutes' ) ) ) * MINUTE_IN_SECONDS;
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
		if ( true === $carry_on && false === get_option( self::LIST_OPTION ) ) {
			$report['status'] = 'done'; // Finished already: nothing to carry on with.
			return $report;
		}
		if ( ! Wormiss_Event_Import::take_lock( self::LOCK_OPTION, self::LOCK_SECONDS ) ) {
			$report['status'] = 'busy';
			if ( true === $carry_on && ! wp_next_scheduled( self::MORE_HOOK ) ) {
				// Another batch has it (or one the host stopped holds it until the lock runs out).
				wp_schedule_single_event( time() + self::gap(), self::MORE_HOOK );
			}
			return $report;
		}
		Wormiss_Batch::start( self::seconds() );
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
		// A list still being read stays in use while each batch adds to it; a finished one ages
		// from when its last page was read.
		$since = is_array( $kept ) ? (int) ( $kept['touched'] ?? $kept['time'] ?? 0 ) : 0;
		if ( is_array( $kept ) && $since > time() - self::LIST_MAX_AGE && is_array( $kept['posts'] ?? null ) ) {
			$listing = $kept;
		} else {
			// A fresh list: what an earlier, unfinished import did no longer counts.
			delete_option( self::DONE_OPTION );
			$done = [];
			try {
				$listing = [
					'posts'         => [],
					'offset'        => 0,
					'categories'    => self::names( $client, 'Smart_Blogs_Category', 'CategoryKey' ),
					'subcategories' => self::names( $client, 'Smart_Blogs_Subcategory', 'SubcategoryKey', 'ParentCategoryKey' ),
					'templates'     => self::templates( $client ),
					'time'          => time(),
				];
			} catch ( Wormiss_Exception $e ) {
				$report['status']  = 'error';
				$report['notes'][] = $e->getMessage();
				return self::save( $report );
			}
			self::categories( $listing, $report );
		}
		if ( null !== ( $listing['offset'] ?? null ) ) {
			try {
				$listing = self::read_list( $client, $listing );
			} catch ( Wormiss_Exception $e ) {
				$report['status']  = 'error';
				$report['notes'][] = $e->getMessage();
				return self::save( $report );
			}
			$listing['touched'] = time();
			if ( null !== $listing['offset'] ) {
				// A long list is read over several batches, a page at a time.
				update_option( self::LIST_OPTION, $listing, false );
				if ( ! wp_next_scheduled( self::MORE_HOOK ) ) {
					wp_schedule_single_event( time() + self::gap(), self::MORE_HOOK );
				}
				$report['read'] = count( $listing['posts'] );
				/* translators: %d: blog posts found so far. */
				self::note( $report, sprintf( __( 'Still reading the list of blog posts from iMIS (%d published so far); carries on in the background.', 'wormiss' ), count( $listing['posts'] ) ) );
				return self::save( $report );
			}
			uasort( $listing['posts'], static fn( array $a, array $b ): int => strcmp( $b['published'], $a['published'] ) );
			$listing['time'] = $listing['touched'];
		}
		return self::write( $client, $report, $done, $listing, (int) $listing['time'] );
	}

	/**
	 * Read more of the blog post list, a page at a time, until it ends or the batch is out of
	 * time. Each page's text is dropped as soon as the post's details are taken, so a long
	 * blog never sits in memory.
	 *
	 * @param Wormiss_Rest_Client $client  Client.
	 * @param array               $listing The list so far; its offset is the next page.
	 * @return array The list; offset null once every page is read.
	 * @throws Wormiss_Exception When iMIS cannot be read.
	 */
	private static function read_list( Wormiss_Rest_Client $client, array $listing ): array {
		$offset = (int) $listing['offset'];
		do {
			$result = $client->request( 'GET', '/api/Smart_Blogs_Blog_Post?Limit=' . self::PAGE . '&Offset=' . $offset, null, null, false );
			if ( ! is_object( $result->body ) ) {
				throw new Wormiss_Exception( __( 'iMIS sent a response that could not be read.', 'wormiss' ) );
			}
			$page = Wormiss_Rest_Client::items( $result->body );
			foreach ( $page as $item ) {
				$post = is_object( $item ) ? self::list_entry( Wormiss_Rest_Client::properties( $item ) ) : null;
				if ( $post ) {
					$listing['posts'][ $post['key'] ] = $post;
				}
			}
			[ $more, $offset ] = Wormiss_Rest_Client::next_page( $result->body, $offset, count( $page ) );
			$more              = $more && $page && $offset < self::MAX_POSTS;
			unset( $result, $page );
		} while ( $more && ! Wormiss_Batch::out_of_time() );
		$listing['offset'] = $more ? $offset : null;
		return $listing;
	}

	/**
	 * A published post's details (not its text) from its list row.
	 *
	 * @param array $row Row properties.
	 * @return array|null Null for a draft, or a row without a blog key.
	 */
	private static function list_entry( array $row ): ?array {
		$key = strtolower( (string) ( $row['BlogKey'] ?? '' ) );
		if ( ! Wormiss_Content_Check::is_key( $key ) || 'Published' !== (string) ( $row['Status'] ?? '' ) ) {
			return null;
		}
		return [
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
		// Kept from the start, so a batch the host stops part way is carried on, not lost.
		update_option( self::LIST_OPTION, array_merge( $listing, [ 'time' => $read ] ), false );
		if ( ! wp_next_scheduled( self::MORE_HOOK ) ) {
			wp_schedule_single_event( time() + self::gap(), self::MORE_HOOK );
		}
		foreach ( $listing['posts'] as $key => $post ) {
			if ( isset( $done[ $key ] ) ) {
				continue;
			}
			if ( $writes >= self::batch() || $checked >= 3 * self::batch() + 5 || Wormiss_Batch::stopped() ) {
				++$todo;
				continue;
			}
			$done[ $key ] = true;
			if ( Wormiss_Batch::begin( self::WORKING_OPTION, (string) $key ) >= Wormiss_Batch::MAX_TRIES ) {
				Wormiss_Batch::end( self::WORKING_OPTION );
				++$report['skipped'];
				/* translators: %s: title. */
				self::note( $report, sprintf( __( '%s: skipped, as two batches could not finish it (too big or too slow for one go); tried again next import.', 'wormiss' ), $post['title'] ) );
				update_option( self::DONE_OPTION, $done, false );
				continue;
			}
			++$checked;
			$result = self::one( $client, $post, $listing, $report );
			Wormiss_Batch::done( self::WORKING_OPTION );
			if ( 'created' === $result || 'updated' === $result ) {
				++$writes;
				++$report[ $result ];
			} else {
				++$report['skipped'];
			}
			if ( Wormiss_Batch::put_off() ) {
				// Put off part way (out of time or pictures): finished in the next batch.
				unset( $done[ $key ] );
				++$todo;
			}
			update_option( self::DONE_OPTION, $done, false );
		}
		$report['remaining'] = $todo;
		if ( 0 === $todo ) {
			delete_option( self::DONE_OPTION );
			delete_option( self::LIST_OPTION );
			wp_clear_scheduled_hook( self::MORE_HOOK );
		}
		return self::save( $report );
	}

	/**
	 * Make or update one blog post and its comments.
	 *
	 * @param Wormiss_Rest_Client $client Client.
	 * @param array               $post   From list_entry().
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
		$parts = self::convert( self::post_data( $rows ? (array) $rows[0] : [] ) );
		if ( '' === $parts['body'] && '' === $parts['intro'] ) {
			/* translators: %s: blog title. */
			self::note( $report, sprintf( __( '%s: its iMIS content could not be read, so not imported.', 'wormiss' ), $post['title'] ) );
			return 'skipped';
		}
		$feature = '' !== $parts['header'] ? $parts['header'] : $post['thumbnail'];
		// The category and subcategory names are part of it, so a rename in iMIS reaches the post.
		$names    = [ $listing['categories'][ $post['category'] ] ?? null, $listing['subcategories'][ $post['subcategory'] ] ?? null ];
		$template = self::template_for( $parts['ids'], $parts['legacy'], (array) ( $listing['templates'] ?? [] ) );
		$hash     = md5( (string) wp_json_encode( [ $post, $parts, $feature, $names, $template, self::comments_hash( $comments ) ] ) );
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
		update_post_meta( $saved, self::META_TEMPLATE, $template );
		self::terms( $saved, $post, $listing );
		[ $changed, $comments_done ] = self::comments( $client, $saved, $comments );
		$report['comments']         += $changed;
		$complete                    = self::images( $saved, $feature, $post['title'], $report ) && $comments_done;
		if ( ! $comments_done ) {
			/* translators: %s: blog title. */
			self::note( $report, sprintf( __( '%s: some comments could not be saved; tried again next import.', 'wormiss' ), $post['title'] ) );
		}
		if ( $complete ) {
			update_post_meta( $saved, self::META_SOURCE, $hash );
		} else {
			delete_post_meta( $saved, self::META_SOURCE );
		}
		return $post_id ? 'updated' : 'created';
	}

	/**
	 * A post's blocks. Some posts keep them only in BlogPostPreviewData (BlogPostData is left
	 * empty when a post is edited after it was published), so that is read when BlogPostData
	 * has no blocks.
	 *
	 * @param array $row The Smart_Blogs_Blog_Post row.
	 * @return array The decoded post data (empty when neither field can be read).
	 */
	public static function post_data( array $row ): array {
		$found = [];
		foreach ( [ 'BlogPostData', 'BlogPostPreviewData' ] as $field ) {
			$blob = $row[ $field ] ?? '';
			$json = json_decode( Wormiss_Content_Check::decode_blob( is_string( $blob ) ? $blob : '', false ), true );
			if ( ! is_array( $json ) ) {
				continue;
			}
			if ( ! empty( $json['content'] ) ) {
				return $json;
			}
			$found = $found ? $found : $json;
		}
		return $found;
	}

	/**
	 * A Smart Blogs post's blocks as HTML: the text (every block but page furniture and the
	 * header picture), the intro (the blocks iMIS shows to non-members), the solution (the
	 * Conclusion block) and its reveal time, and the header picture (an image before the first
	 * dividing line). Old (LegacyHtml) posts, which mark no intro, get their first paragraph.
	 *
	 * @param array $data The decoded BlogPostData.
	 * @return array body, intro, solution, reveal (Unix time), header (image address), unknown (block types),
	 *               ids (every block's id), legacy (an old HTML post).
	 */
	public static function convert( array $data ): array {
		$out     = [
			'body'     => [],
			'intro'    => [],
			'solution' => [],
			'reveal'   => 0,
			'header'   => '',
			'unknown'  => [],
			'ids'      => self::block_ids( (array) ( $data['content'] ?? [] ) ),
			'legacy'   => false,
		];
		$divided = false;
		$legacy  = false;
		foreach ( (array) ( $data['content'] ?? [] ) as $block ) {
			if ( ! is_array( $block ) ) {
				continue;
			}
			$type   = (string) ( $block['type'] ?? '' );
			$props  = is_array( $block['props'] ?? null ) ? $block['props'] : [];
			$legacy = $legacy || 'LegacyHtml' === $type;
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
		// Old posts (LegacyHtml) mark no intro: their first paragraph with words in it (pictures left
		// out). A new-style post with no visible blocks has no intro: nothing of it is public.
		if ( $legacy && '' === $out['intro'] && preg_match_all( '#<p\b[^>]*>.*?</p>#s', $out['body'], $found ) ) {
			foreach ( $found[0] as $paragraph ) {
				$paragraph = (string) preg_replace( '#<a\b[^>]*>\s*<img\b[^>]*>\s*</a>|<img\b[^>]*>#i', '', $paragraph );
				if ( '' !== trim( html_entity_decode( wp_strip_all_tags( $paragraph ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ), " \t\n\r\0\x0B\xc2\xa0" ) ) {
					$out['intro'] = $paragraph;
					break;
				}
			}
		}
		$out['unknown'] = array_values( array_unique( $out['unknown'] ) );
		$out['legacy']  = $legacy;
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
				return self::bootstrap( Wormiss_News_Import::clean( self::site_paths( $html ) ) );
			case 'Text':
				return self::text_html( self::unplaceholder( (string) ( $props['text'] ?? '' ) ), (string) ( $props['align'] ?? '' ), ! empty( $props['bold'] ) );
			case 'Poll':
				return self::poll_html( $props );
			case 'Grid':
				return self::grid_html( (array) ( $props['items'] ?? [] ), (int) ( $props['numColumns'] ?? 2 ), $unknown );
			case 'ImageShowcase':
				return self::showcase_html( (array) ( $props['images'] ?? [] ), (int) ( $props['columns'] ?? 3 ) );
			case 'Image':
				$src = self::address( (string) ( $props['imageSrc'] ?? ( $props['src'] ?? '' ) ) );
				if ( '' === $src ) {
					return '';
				}
				return '<figure class="figure wormiss-blog-image"><img class="img-fluid" src="' . esc_url( $src )
					. '" alt="' . esc_attr( (string) ( $props['alt'] ?? '' ) ) . '" /></figure>';
			case 'Separator':
				return 'vertical' === ( $props['orientation'] ?? '' ) ? '' : '<hr />';
		}
		// Containers (e.g. Flex): their items, in order; side by side (a Bootstrap-style row of
		// columns, stacked on phones) when the container lays out a row of two or more.
		if ( is_array( $props['items'] ?? null ) ) {
			$parts = [];
			foreach ( $props['items'] as $item ) {
				$html = is_array( $item ) ? self::block_html( $item, $unknown ) : '';
				if ( '' !== $html ) {
					$parts[] = $html;
				}
			}
			// Dividing lines are not columns; stacked, they stay between the blocks they divide.
			$content = array_values( array_filter( $parts, static fn( string $part ): bool => '<hr />' !== $part ) );
			if ( ! $content ) {
				return '';
			}
			if ( count( $content ) > 1 && 'column' !== ( $props['direction'] ?? '' ) ) {
				return '<div class="row wormiss-blog-row">' . "\n"
					. implode( "\n", array_map( static fn( string $part ): string => '<div class="col-md">' . "\n" . $part . "\n</div>", $content ) )
					. "\n</div>";
			}
			return implode( "\n\n", $parts );
		}
		if ( isset( $props['html'] ) && is_string( $props['html'] ) ) {
			return self::bootstrap( Wormiss_News_Import::clean( self::site_paths( $props['html'] ) ) );
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
	 * Text with template placeholders taken out ('' when it was only a placeholder).
	 *
	 * @param string $text Text.
	 * @return string
	 */
	public static function unplaceholder( string $text ): string {
		$trimmed = trim( $text );
		if ( self::PLACEHOLDER === $trimmed || 0 === strpos( $trimmed, self::PLACEHOLDER_START ) ) {
			return '';
		}
		return $text;
	}

	/**
	 * A poll as its question and choices (votes are not in iMIS's blog data). The template's
	 * "Your question" and "Option 1" wording is taken out; an untouched poll shows nothing.
	 *
	 * @param array $props Poll properties.
	 * @return string
	 */
	private static function poll_html( array $props ): string {
		$question = trim( (string) preg_replace( '/^\s*Your question\s*/i', '', (string) ( $props['question'] ?? '' ) ) );
		$choices  = [];
		foreach ( (array) ( $props['options'] ?? [] ) as $option ) {
			$text = is_array( $option ) ? trim( (string) preg_replace( '/^\s*Option\s*\d+\s*/i', '', (string) ( $option['text'] ?? '' ) ) ) : '';
			if ( '' !== $text ) {
				$choices[] = '<li>' . esc_html( (string) preg_replace( '/\s+/', ' ', $text ) ) . '</li>';
			}
		}
		if ( '' === $question && ! $choices ) {
			return '';
		}
		return '<div class="card wormiss-blog-poll"><div class="card-header"><h2 class="h5 mb-0">' . esc_html__( 'Poll', 'wormiss' ) . '</h2></div>'
			. '<div class="card-body">' . ( '' !== $question ? '<p class="wormiss-blog-poll__question">' . esc_html( (string) preg_replace( '/\s+/', ' ', $question ) ) . '</p>' : '' )
			. ( $choices ? '<ul class="wormiss-blog-poll__choices">' . implode( '', $choices ) . '</ul>' : '' )
			. '<p class="small text-muted">' . esc_html__( 'Give your answer and reasons in the discussion below.', 'wormiss' ) . '</p></div></div>';
	}

	/**
	 * A grid of blocks in its number of columns (one column on phones); dividing lines and
	 * empty blocks are not cells.
	 *
	 * @param array    $items   Items.
	 * @param int      $columns Columns.
	 * @param string[] $unknown Block types not understood, added to.
	 * @return string
	 */
	private static function grid_html( array $items, int $columns, array &$unknown ): string {
		$cells = [];
		foreach ( $items as $item ) {
			$html = is_array( $item ) ? self::block_html( $item, $unknown ) : '';
			if ( '' !== $html && '<hr />' !== $html ) {
				$cells[] = '<div class="wormiss-blog-grid__item">' . "\n" . $html . "\n</div>";
			}
		}
		if ( ! $cells ) {
			return '';
		}
		if ( 1 === count( $cells ) ) {
			return (string) preg_replace( '#^<div class="wormiss-blog-grid__item">\n|\n</div>$#', '', $cells[0] );
		}
		$columns = min( 4, max( 1, $columns ) );
		return '<div class="wormiss-blog-grid wormiss-blog-grid--cols-' . $columns . '">' . "\n" . implode( "\n", $cells ) . "\n</div>";
	}

	/**
	 * An image showcase (gallery) as a grid of pictures with their captions.
	 *
	 * @param array $images  Images.
	 * @param int   $columns Columns.
	 * @return string
	 */
	private static function showcase_html( array $images, int $columns ): string {
		$cells = [];
		foreach ( $images as $image ) {
			if ( is_string( $image ) ) {
				$image = [ 'src' => $image ];
			}
			if ( ! is_array( $image ) ) {
				continue;
			}
			$src = self::address( (string) ( $image['imageSrc'] ?? $image['src'] ?? $image['url'] ?? '' ) );
			if ( '' === $src ) {
				continue;
			}
			$caption = trim( (string) ( $image['caption'] ?? $image['title'] ?? '' ) );
			$cells[] = '<figure class="figure wormiss-blog-image"><img class="img-fluid" src="' . esc_url( $src ) . '" alt="'
				. esc_attr( (string) ( $image['alt'] ?? $caption ) ) . '" />'
				. ( '' !== $caption ? '<figcaption class="figure-caption">' . esc_html( $caption ) . '</figcaption>' : '' ) . '</figure>';
		}
		if ( ! $cells ) {
			return '';
		}
		$columns = min( 4, max( 1, $columns ) );
		return '<div class="wormiss-blog-grid wormiss-blog-grid--cols-' . $columns . ' wormiss-blog-showcase">' . "\n"
			. implode( "\n", array_map( static fn( string $cell ): string => '<div class="wormiss-blog-grid__item">' . $cell . '</div>', $cells ) )
			. "\n</div>";
	}

	/**
	 * Every block id in some blocks, items within containers included.
	 *
	 * @param array $blocks Blocks.
	 * @return string[]
	 */
	public static function block_ids( array $blocks ): array {
		$ids = [];
		foreach ( $blocks as $block ) {
			if ( ! is_array( $block ) ) {
				continue;
			}
			$props = is_array( $block['props'] ?? null ) ? $block['props'] : [];
			if ( isset( $props['id'] ) && is_string( $props['id'] ) && '' !== $props['id'] ) {
				$ids[] = $props['id'];
			}
			if ( is_array( $props['items'] ?? null ) ) {
				$ids = array_merge( $ids, self::block_ids( $props['items'] ) );
			}
		}
		return array_values( array_unique( $ids ) );
	}

	/**
	 * The Smart Blogs templates: name => the block ids only that template has (the ones every
	 * template copied from another are no help in telling them apart). Not read: none.
	 *
	 * @param Wormiss_Rest_Client $client Client.
	 * @return array[]
	 */
	private static function templates( Wormiss_Rest_Client $client ): array {
		try {
			$rows = self::read_all( $client, '/api/Smart_Blogs_Template', 200 );
		} catch ( Wormiss_Exception $e ) {
			unset( $e ); // Templates only label posts: an import goes on without them.
			return [];
		}
		$all = [];
		foreach ( $rows as $row ) {
			$name = sanitize_text_field( (string) ( $row['Name'] ?? '' ) );
			$data = $row['TemplateData'] ?? '';
			$json = json_decode( Wormiss_Content_Check::decode_blob( is_string( $data ) ? $data : '', false ), true );
			if ( '' !== $name && is_array( $json ) ) {
				$all[ $name ] = self::block_ids( (array) ( $json['content'] ?? [] ) );
			}
		}
		return self::unique_ids( $all );
	}

	/**
	 * Each template's block ids that no other template has.
	 *
	 * @param array[] $all Template name => block ids.
	 * @return array[]
	 */
	public static function unique_ids( array $all ): array {
		$counts = $all ? array_count_values( array_merge( ...array_values( $all ) ) ) : [];
		$unique = [];
		foreach ( $all as $name => $ids ) {
			$unique[ $name ] = array_values( array_filter( $ids, static fn( string $id ): bool => 1 === $counts[ $id ] ) );
		}
		return $unique;
	}

	/**
	 * The template a post was made from: the one whose own blocks it shares most; "Legacy" for
	 * an old HTML post; "Other" when none matches.
	 *
	 * @param string[] $ids       The post's block ids.
	 * @param bool     $legacy    An old HTML post.
	 * @param array[]  $templates Template name => its own block ids.
	 * @return string
	 */
	public static function template_for( array $ids, bool $legacy, array $templates ): string {
		$best  = '';
		$score = 0;
		foreach ( $templates as $name => $own ) {
			$shared = count( array_intersect( (array) $own, $ids ) );
			if ( $shared > $score ) {
				$best  = (string) $name;
				$score = $shared;
			}
		}
		if ( '' !== $best ) {
			return $best;
		}
		return $legacy ? 'Legacy' : 'Other';
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
		$aligns = [
			'center' => 'text-center has-text-align-center',
			'right'  => 'text-end has-text-align-right',
		];
		$class  = isset( $aligns[ $align ] ) ? ' class="' . $aligns[ $align ] . '"' : '';
		$out    = [];
		foreach ( preg_split( "/\n\s*\n/", $text ) as $paragraph ) {
			$html  = nl2br( esc_html( trim( $paragraph ) ), false );
			$html  = $bold ? '<strong>' . $html . '</strong>' : $html;
			$out[] = '<p' . $class . '>' . $html . '</p>';
		}
		return implode( "\n", $out );
	}

	/**
	 * Old posts' tidied HTML in simple Bootstrap-style markup: pictures that fit their column,
	 * tables that scroll on phones, quotes styled as quotes, and no empty paragraphs or runs of
	 * line breaks used as spacing.
	 *
	 * @param string $html Cleaned HTML (no classes or styles left).
	 * @return string
	 */
	public static function bootstrap( string $html ): string {
		$html = (string) preg_replace( '#<img\b(?![^>]*\bclass=)#i', '<img class="img-fluid"', $html );
		$html = (string) preg_replace( '#<table\b[^>]*>#i', '<div class="table-responsive"><table class="table table-sm">', $html );
		$html = (string) preg_replace( '#</table>#i', '</table></div>', $html );
		$html = (string) preg_replace( '#<blockquote\b[^>]*>#i', '<blockquote class="blockquote">', $html );
		// Spacing done with breaks: at most two in a row, and none at a paragraph's start or end.
		$html = (string) preg_replace( '#(?:<br\s*/?>\s*){3,}#i', '<br /><br />', $html );
		$html = (string) preg_replace( '#(<p\b[^>]*>)\s*(?:<br\s*/?>\s*)+#i', '$1', $html );
		$html = (string) preg_replace( '#(?:<br\s*/?>\s*)+</p>#i', '</p>', $html );
		// Empty paragraphs (nothing, spaces or non-breaking spaces) used as spacing.
		$html = (string) preg_replace( '#<p\b[^>]*>(?:\s|&nbsp;|&\#160;|\x{00a0})*</p>\s*#iu', '', $html );
		return trim( $html );
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
	 * Make every iMIS category, with its subcategories beneath it, so the list matches iMIS
	 * before any post uses one; a rename in iMIS renames it here.
	 *
	 * @param array $listing The list.
	 * @param array $report  Report.
	 * @return void
	 */
	private static function categories( array $listing, array &$report ): void {
		$dry  = ! empty( $report['dry_run'] );
		$made = 0;
		$ids  = [];
		// Renames and moves first, so a name freed up in iMIS is free here before it is reused.
		if ( ! $dry ) {
			foreach ( $listing['categories'] as $key => $category ) {
				self::follow( self::by_key( (string) $key ), $category['name'], 0 );
			}
			foreach ( $listing['subcategories'] as $key => $sub ) {
				self::follow( self::by_key( (string) $key ), $sub['name'], self::by_key( $sub['parent'] ) );
			}
		}
		foreach ( $listing['categories'] as $key => $category ) {
			$ids[ $key ] = self::term( (string) $key, $category['name'], 0, $dry, $made );
		}
		foreach ( $listing['subcategories'] as $key => $sub ) {
			self::term( (string) $key, $sub['name'], $ids[ $sub['parent'] ] ?? 0, $dry, $made );
		}
		if ( $made > 0 ) {
			self::note(
				$report,
				$dry
					/* translators: %d: number of categories. */
					? sprintf( _n( 'Dry run: would make %d blog category.', 'Dry run: would make %d blog categories.', $made, 'wormiss' ), $made )
					/* translators: %d: number of categories. */
					: sprintf( _n( '%d blog category made.', '%d blog categories made.', $made, 'wormiss' ), $made )
			);
		}
	}

	/**
	 * A post's category (with its subcategory beneath it) and topics.
	 *
	 * @param int   $post_id Post ID.
	 * @param array $post    From list_entry().
	 * @param array $listing The list.
	 * @return void
	 */
	private static function terms( int $post_id, array $post, array $listing ): void {
		$ids      = [];
		$made     = 0;
		$category = $listing['categories'][ $post['category'] ] ?? null;
		$parent   = $category ? self::term( $post['category'], $category['name'], 0, false, $made ) : 0;
		$ids[]    = $parent;
		$sub      = $listing['subcategories'][ $post['subcategory'] ] ?? null;
		if ( $sub ) {
			$own   = $listing['categories'][ $sub['parent'] ] ?? null;
			$under = $own ? self::term( $sub['parent'], $own['name'], 0, false, $made ) : $parent;
			$ids[] = self::term( $post['subcategory'], $sub['name'], $under, false, $made );
		}
		wp_set_object_terms( $post_id, array_values( array_filter( $ids ) ), Wormiss_Blog::TAX_CATEGORY );
		$topics = array_filter( array_map( 'trim', array_map( 'sanitize_text_field', explode( ',', $post['tags'] ) ) ) );
		wp_set_object_terms( $post_id, array_values( $topics ), Wormiss_Blog::TAX_TOPIC );
	}

	/**
	 * A blog category's term ID, found by its iMIS key (or, the first time, the name of one
	 * made before keys were kept) and made if missing; its name and parent follow iMIS.
	 *
	 * @param string $key       iMIS category or subcategory key.
	 * @param string $name      Name.
	 * @param int    $parent_id Parent term ID.
	 * @param bool   $dry       Dry run: change nothing.
	 * @param int    $made      Count of categories made (or that would be).
	 * @return int 0 when there is none.
	 */
	private static function term( string $key, string $name, int $parent_id, bool $dry, int &$made ): int {
		if ( '' === $key || '' === $name ) {
			return 0;
		}
		$id = self::by_key( $key );
		if ( $id ) {
			if ( ! $dry ) {
				self::follow( $id, $name, $parent_id );
			}
			return $id;
		}
		// Only a category without a key is taken over by name: one with another key is
		// another iMIS category.
		$exists = term_exists( wp_slash( $name ), Wormiss_Blog::TAX_CATEGORY, $parent_id );
		if ( is_array( $exists ) && '' === (string) get_term_meta( (int) $exists['term_id'], self::TERM_KEY, true ) ) {
			$id = (int) $exists['term_id'];
			if ( ! $dry ) {
				update_term_meta( $id, self::TERM_KEY, $key );
			}
			return $id;
		}
		++$made;
		if ( $dry ) {
			return 0;
		}
		$new = wp_insert_term( wp_slash( $name ), Wormiss_Blog::TAX_CATEGORY, [ 'parent' => $parent_id ] );
		if ( is_wp_error( $new ) ) {
			--$made;
			return 0;
		}
		update_term_meta( (int) $new['term_id'], self::TERM_KEY, $key );
		return (int) $new['term_id'];
	}

	/**
	 * The blog category with this iMIS key.
	 *
	 * @param string $key iMIS category or subcategory key.
	 * @return int Term ID, or 0.
	 */
	private static function by_key( string $key ): int {
		if ( '' === $key ) {
			return 0;
		}
		$found = get_terms(
			[
				'taxonomy'   => Wormiss_Blog::TAX_CATEGORY,
				'hide_empty' => false,
				'fields'     => 'ids',
				'number'     => 1,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- A handful of categories.
				'meta_query' => [
					[
						'key'   => self::TERM_KEY,
						'value' => $key,
					],
				],
			]
		);
		return is_array( $found ) && $found ? (int) $found[0] : 0;
	}

	/**
	 * Give a blog category the name and parent it has in iMIS.
	 *
	 * @param int    $id        Term ID (0 for none).
	 * @param string $name      Name.
	 * @param int    $parent_id Parent term ID.
	 * @return void
	 */
	private static function follow( int $id, string $name, int $parent_id ): void {
		$term = $id && '' !== $name ? get_term( $id, Wormiss_Blog::TAX_CATEGORY ) : null;
		if ( $term instanceof WP_Term && ( html_entity_decode( $term->name, ENT_QUOTES ) !== $name || (int) $term->parent !== $parent_id ) ) {
			wp_update_term(
				$id,
				Wormiss_Blog::TAX_CATEGORY,
				[
					'name'   => wp_slash( $name ),
					'parent' => $parent_id,
				]
			);
		}
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
	 * @return array [ comments made or changed, whether every comment was saved ].
	 */
	private static function comments( Wormiss_Rest_Client $client, int $post_id, array $comments ): array {
		usort( $comments, static fn( array $a, array $b ): int => strcmp( (string) ( $a['CreatedOn'] ?? '' ), (string) ( $b['CreatedOn'] ?? '' ) ) );
		$by_key  = self::existing_comments( $post_id );
		$changed = 0;
		$done    = true;
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
			if ( ! $person instanceof WP_User && ! self::named( $imis ) && Wormiss_Batch::out_of_time() ) {
				// Out of time to look the commenter up in iMIS: finished in the next batch.
				Wormiss_Batch::stop();
				$done = false;
				continue;
			}
			$date   = Wormiss_News_Import::local_date( (string) ( $row['CreatedOn'] ?? '' ) );
			$fields = [
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
				$updated              = wp_update_comment( wp_slash( $fields ), true );
				if ( false === $updated || is_wp_error( $updated ) ) {
					$done = false; // Fingerprint kept as it was: tried again next import.
					continue;
				}
				$comment_id = $by_key[ $key ];
			} else {
				$comment_id = (int) wp_insert_comment( wp_slash( $fields ) );
				if ( ! $comment_id ) {
					$done = false;
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
			if ( ! isset( $by_key[ $parent ] ) ) {
				// Parent not saved this time: the reply is saved again next time and linked then.
				$done = false;
				delete_comment_meta( $comment_id, self::COMMENT_SOURCE );
				continue;
			}
			$linked = wp_update_comment(
				[
					'comment_ID'     => $comment_id,
					'comment_parent' => $by_key[ $parent ],
				],
				true
			);
			if ( false === $linked || is_wp_error( $linked ) ) {
				$done = false;
				delete_comment_meta( $comment_id, self::COMMENT_SOURCE );
			}
		}
		return [ $changed, $done ];
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
	 * Whether a commenter's name was already looked up.
	 *
	 * @param string $imis iMIS ID.
	 * @return bool
	 */
	private static function named( string $imis ): bool {
		$people = get_option( self::PEOPLE_OPTION, [] );
		return is_array( $people ) && isset( $people[ $imis ] );
	}

	/**
	 * A commenter's name from iMIS (looked up once, then kept).
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
		Wormiss_Batch::progress();
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
			$urls = array_merge( $urls, self::image_urls( $text, $base ) );
		}
		$map = [];
		foreach ( array_unique( $urls ) as $attr ) {
			$url        = html_entity_decode( $attr, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
			$attachment = self::copy( $post_id, $url, $title, $report, $complete );
			if ( $attachment ) {
				// File (and, if the category is protected, privately store) the picture before its URL
				// is read, so a protected picture maps to the gated endpoint in the post content. When
				// the private store fails, leave the original URL in place (so this run stays
				// incomplete and the next retries it) rather than present a protected picture whose
				// public file is still reachable.
				if ( Wormiss_Media_Delivery::protect_imported( $attachment ) ) {
					$map[ '"' . $attr . '"' ] = '"' . esc_url( (string) wp_get_attachment_url( $attachment ) ) . '"';
				} else {
					$complete = false;
					/* translators: %s: post title. */
					self::note( $report, sprintf( __( '%s: a protected picture could not be moved into the private store; it will be retried.', 'wormiss' ), $title ) );
				}
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
				if ( Wormiss_Media_Delivery::protect_imported( $attachment ) ) {
					set_post_thumbnail( $post_id, $attachment );
					update_post_meta( $post_id, self::META_FEATURE, $feature );
				} else {
					// Leave META_FEATURE unset so the next run retries the private store.
					$complete = false;
					/* translators: %s: post title. */
					self::note( $report, sprintf( __( '%s: the featured picture could not be moved into the private store; it will be retried.', 'wormiss' ), $title ) );
				}
			}
		}
		return $complete;
	}

	/**
	 * The picture addresses on the iMIS site in some HTML (in src or href, as written there),
	 * including ones with a query string (e.g. case.jpg?v=2).
	 *
	 * @param string $html HTML.
	 * @param string $base The iMIS site address.
	 * @return string[]
	 */
	public static function image_urls( string $html, string $base ): array {
		$pattern = '#\b(?:src|href)="(' . preg_quote( $base, '#' ) . '/[^"?\#]+\.(?:jpe?g|png|gif|webp)(?:\?[^"\#]*)?)"#i';
		return preg_match_all( $pattern, $html, $found ) ? array_values( array_unique( $found[1] ) ) : [];
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
		if ( self::$images >= 3 * self::batch() || Wormiss_Batch::out_of_time() ) {
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
			$complete = false;
			/* translators: 1: title, 2: reason. */
			self::note( $report, sprintf( __( '%1$s: a picture could not be copied (%2$s).', 'wormiss' ), $title, $tmp->get_error_message() ) );
			return 0;
		}
		$name = sanitize_file_name( rawurldecode( wp_basename( (string) wp_parse_url( $url, PHP_URL_PATH ) ) ) );
		// Only the sizes the blog shows: each size is another resize, the slow part of a copy.
		add_filter( 'intermediate_image_sizes_advanced', [ __CLASS__, 'blog_sizes' ] );
		$attachment = media_handle_sideload(
			[
				'name'     => '' !== $name ? $name : 'blog-image.jpg',
				'tmp_name' => $tmp,
			],
			$post_id,
			$title
		);
		remove_filter( 'intermediate_image_sizes_advanced', [ __CLASS__, 'blog_sizes' ] );
		if ( is_wp_error( $attachment ) ) {
			$complete = false;
			wp_delete_file( $tmp );
			/* translators: 1: title, 2: reason. */
			self::note( $report, sprintf( __( '%1$s: a picture could not be added (%2$s).', 'wormiss' ), $title, $attachment->get_error_message() ) );
			return 0;
		}
		update_post_meta( (int) $attachment, self::META_FROM, $url );
		Wormiss_Batch::progress();
		return (int) $attachment;
	}

	/**
	 * The resized copies made of an imported blog picture: the ones the blog shows (thumbnail,
	 * medium and large) rather than every size the site has.
	 *
	 * @param array $sizes Sizes WordPress would make (name => size).
	 * @return array
	 */
	public static function blog_sizes( array $sizes ): array {
		/**
		 * The image sizes made for pictures the blog import copies.
		 *
		 * @param string[] $keep Size names (default thumbnail, medium and large).
		 */
		$keep = (array) apply_filters( 'wormiss_blog_image_sizes', [ 'thumbnail', 'medium', 'large' ] );
		return array_intersect_key( $sizes, array_flip( array_map( 'strval', $keep ) ) );
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
		$template = (string) get_post_meta( $post->ID, self::META_TEMPLATE, true );
		if ( '' !== $template ) {
			/* translators: %s: template name. */
			echo '<p>' . esc_html( sprintf( __( 'iMIS template: %s', 'wormiss' ), $template ) ) . '</p>';
		}
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
