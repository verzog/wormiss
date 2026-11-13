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
 * Import iMIS news articles as WordPress posts.
 *
 * @package    wormiss
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined( 'ABSPATH' ) || exit;

/**
 * Each news article an administrator's IQA lists becomes a post in the News category, with its
 * iMIS tags, publication date, summary, full text (from the content page iMIS keeps, its
 * formatting tidied) and images copied into the Media Library. Members-only articles stay
 * members-only. iMIS is the source: a later import updates the post, unless an editor ticks
 * "Stop updating from iMIS" on it. Nothing is changed in iMIS; posts are never removed.
 */
class Wormiss_News_Import {

	/**
	 * The daily import.
	 */
	const HOOK = 'wormiss_news_import';

	/**
	 * The next batch of an import.
	 */
	const MORE_HOOK = 'wormiss_news_import_more';

	/**
	 * The last run's report.
	 */
	const REPORT_OPTION = 'wormiss_news_import_report';

	/**
	 * The article list read at the start of an import, for its later batches.
	 */
	const LIST_OPTION = 'wormiss_news_import_list';

	/**
	 * Articles earlier batches of an import dealt with.
	 */
	const DONE_OPTION = 'wormiss_news_import_done';

	/**
	 * The lock that stops two imports running at once.
	 */
	const LOCK_OPTION = 'wormiss_news_import_lock';

	/**
	 * The article's iMIS document version key (the news view's Document Version Key).
	 */
	const META_KEY = '_wormiss_news_key';

	/**
	 * A fingerprint of what the post last took from iMIS.
	 */
	const META_SOURCE = '_wormiss_news_source';

	/**
	 * Ticked by an editor: later imports leave the post alone.
	 */
	const META_DETACHED = '_wormiss_news_detached';

	/**
	 * The article's page on the iMIS site.
	 */
	const META_URL = '_wormiss_news_url';

	/**
	 * The iMIS address of the image made the featured image.
	 */
	const META_FEATURE = '_wormiss_news_feature';

	/**
	 * On an attachment: the iMIS address it was copied from (so an image is copied once).
	 */
	const META_FROM = '_wormiss_news_image_from';

	/**
	 * Post changes per batch.
	 */
	const BATCH = 5;

	/**
	 * Articles checked per batch (each is one iMIS read, changed or not).
	 */
	const MAX_CHECKS = 25;

	/**
	 * Images copied per batch.
	 */
	const MAX_IMAGES = 6;

	/**
	 * Seconds between batches.
	 */
	const BATCH_GAP = 2 * MINUTE_IN_SECONDS;

	/**
	 * How long an import's article list is used by its later batches.
	 */
	const LIST_MAX_AGE = 30 * MINUTE_IN_SECONDS;

	/**
	 * Seconds after which a lock left by a run that died is taken over.
	 */
	const LOCK_SECONDS = 600;

	/**
	 * Most IQA rows read.
	 */
	const MAX_ROWS = 5000;

	/**
	 * Most notes kept in a report.
	 */
	const MAX_NOTES = 20;

	/**
	 * Tags and attributes kept in article text: structure, emphasis, links, images and tables;
	 * never styles, classes, fonts or scripts.
	 */
	const ALLOWED = [
		'p'          => [],
		'br'         => [],
		'h2'         => [],
		'h3'         => [],
		'h4'         => [],
		'h5'         => [],
		'h6'         => [],
		'strong'     => [],
		'b'          => [],
		'em'         => [],
		'i'          => [],
		'u'          => [],
		'sup'        => [],
		'sub'        => [],
		'blockquote' => [],
		'ul'         => [],
		'ol'         => [],
		'li'         => [],
		'a'          => [
			'href'   => true,
			'title'  => true,
			'target' => true,
		],
		'img'        => [
			'src'    => true,
			'alt'    => true,
			'width'  => true,
			'height' => true,
		],
		'figure'     => [],
		'figcaption' => [],
		'table'      => [],
		'thead'      => [],
		'tbody'      => [],
		'tr'         => [],
		'th'         => [],
		'td'         => [],
	];

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
		add_action( 'admin_post_wormiss_news_import_now', [ __CLASS__, 'handle_run_now' ] );
		add_action( 'add_meta_boxes_post', [ __CLASS__, 'add_box' ] );
		add_action( 'save_post_post', [ __CLASS__, 'save_box' ] );
		add_action( 'wp_enqueue_scripts', [ __CLASS__, 'styles' ] );
		add_filter( 'render_block_core/post-featured-image', [ __CLASS__, 'hide_featured' ], 10, 3 );
	}

	/**
	 * The article layout (text beside its image, stacked on phones) on an imported news post.
	 *
	 * @return void
	 */
	public static function styles(): void {
		if ( is_singular( 'post' ) && '' !== (string) get_post_meta( (int) get_queried_object_id(), self::META_KEY, true ) ) {
			wp_enqueue_style( 'wormiss-news', plugins_url( 'assets/news.css', WORMISS_FILE ), [], WORMISS_VERSION );
		}
	}

	/**
	 * On an imported article's own page, leave out the featured image when the article already
	 * shows it (beside the text or on its own), so it is not there twice. News cards elsewhere,
	 * on that page too, keep it.
	 *
	 * @param string   $html     Block HTML.
	 * @param array    $block    Block.
	 * @param WP_Block $instance Block instance.
	 * @return string
	 */
	public static function hide_featured( $html, $block, $instance ) {
		unset( $block ); // The instance has the post.
		$post_id = $instance instanceof WP_Block ? (int) ( $instance->context['postId'] ?? 0 ) : 0;
		if ( ! $post_id || ! is_singular() || (int) get_queried_object_id() !== $post_id
			|| '' === (string) get_post_meta( $post_id, self::META_KEY, true ) ) {
			return $html;
		}
		$content = (string) get_post_field( 'post_content', $post_id );
		return str_contains( $content, 'wormiss-news-lead' ) || str_contains( $content, 'wormiss-news-feature' ) ? '' : $html;
	}

	/**
	 * Keep the daily import scheduled while it is on, and none while it is off.
	 *
	 * @return void
	 */
	public static function maybe_schedule(): void {
		$next = wp_next_scheduled( self::HOOK );
		if ( Wormiss_Settings::get( 'news_import_enabled' ) && ! $next ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::HOOK );
		} elseif ( ! Wormiss_Settings::get( 'news_import_enabled' ) && $next ) {
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
		check_admin_referer( 'wormiss_news_import_now' );
		self::run();
		wp_safe_redirect( Wormiss_Admin::settings_url() . '#wormiss-news-import' );
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
	 * Import iMIS news, one batch of posts at a time. Nothing changes if iMIS cannot be read.
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
			'dry_run'   => (bool) Wormiss_Settings::get( 'news_import_dry_run' ),
			'read'      => 0,
			'created'   => 0,
			'updated'   => 0,
			'skipped'   => 0,
			'remaining' => 0,
			'notes'     => [],
		];
		if ( ! Wormiss_Settings::get( 'news_import_enabled' ) || ! Wormiss_Settings::is_configured()
			|| '' === (string) Wormiss_Settings::get( 'news_import_query' ) ) {
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
	 * Read the article list (or use the one read at the start of this import) and write a batch.
	 *
	 * @param array $report   Report so far.
	 * @param array $done     Article keys earlier batches dealt with (key => true).
	 * @param bool  $carry_on A later batch.
	 * @return array The report.
	 */
	private static function import( array $report, array $done, bool $carry_on ): array {
		self::$images = 0;
		$client       = new Wormiss_Rest_Client();
		$kept         = $carry_on ? get_option( self::LIST_OPTION ) : false;
		if ( is_array( $kept ) && (int) ( $kept['time'] ?? 0 ) > time() - self::LIST_MAX_AGE && is_array( $kept['articles'] ?? null ) ) {
			return self::write( $client, $report, $done, $kept['articles'], (int) $kept['time'] );
		}
		try {
			$rows = $client->run_iqa( (string) Wormiss_Settings::get( 'news_import_query' ), [], null, self::MAX_ROWS );
		} catch ( Wormiss_Exception $e ) {
			$report['status']  = 'error';
			$report['notes'][] = $e->getMessage();
			return self::save( $report );
		}
		$articles = self::articles( $rows );
		if ( ! $articles && $rows ) {
			$report['status']  = 'error';
			$report['notes'][] = __( 'The news query has no Document Version Key column, so nothing was imported.', 'wormiss' );
			return self::save( $report );
		}
		return self::write( $client, $report, $done, $articles, time() );
	}

	/**
	 * The articles in the IQA's rows: one per Document Version Key, with every tag it has (the
	 * view lists an article once per tag). Column names are matched loosely ("Document Version
	 * Key", "DocumentVersionKey" and "documentversionkey" are the same).
	 *
	 * @param array[] $rows IQA rows.
	 * @return array[] key => key, title, summary, date, thumbnail, tags.
	 */
	public static function articles( array $rows ): array {
		$out = [];
		foreach ( $rows as $row ) {
			$cells = [];
			foreach ( (array) $row as $name => $value ) {
				$cells[ strtolower( (string) preg_replace( '/[^A-Za-z0-9]/', '', (string) $name ) ) ] = is_scalar( $value ) ? trim( (string) $value ) : '';
			}
			$key = strtolower( $cells['documentversionkey'] ?? ( $cells['uniformkey'] ?? '' ) );
			if ( ! Wormiss_Content_Check::is_key( $key ) ) {
				continue;
			}
			if ( ! isset( $out[ $key ] ) ) {
				$out[ $key ] = [
					'key'       => $key,
					'title'     => sanitize_text_field( $cells['alternatename'] ?? '' ),
					'summary'   => sanitize_textarea_field( $cells['documentdescription'] ?? ( $cells['description'] ?? '' ) ),
					'date'      => $cells['publicationdate'] ?? '',
					'thumbnail' => $cells['thumbnailimage'] ?? '',
					'tags'      => [],
				];
			}
			$tag = sanitize_text_field( $cells['tagname'] ?? '' );
			if ( '' !== $tag && ! in_array( $tag, $out[ $key ]['tags'], true ) ) {
				$out[ $key ]['tags'][] = $tag;
			}
		}
		return $out;
	}

	/**
	 * Make or update one batch of posts.
	 *
	 * @param Wormiss_Rest_Client $client   Client.
	 * @param array               $report   Report so far.
	 * @param array               $done     Article keys earlier batches dealt with.
	 * @param array[]             $articles From articles().
	 * @param int                 $read     When the list was read.
	 * @return array The report.
	 */
	private static function write( Wormiss_Rest_Client $client, array $report, array $done, array $articles, int $read ): array {
		$report['read'] = count( $articles );
		$writes         = 0;
		$checked        = 0;
		$todo           = 0;
		foreach ( $articles as $key => $article ) {
			if ( isset( $done[ $key ] ) ) {
				continue;
			}
			if ( $writes >= self::BATCH || $checked >= self::MAX_CHECKS || self::$images >= self::MAX_IMAGES ) {
				++$todo;
				continue;
			}
			++$checked;
			$done[ $key ] = true;
			$result       = self::one( $client, $article, $report );
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
			update_option(
				self::LIST_OPTION,
				[
					'time'     => $read,
					'articles' => $articles,
				],
				false
			);
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
	 * Make or update the post for one article.
	 *
	 * @param Wormiss_Rest_Client $client  Client.
	 * @param array               $article From articles().
	 * @param array               $report  Report, for notes.
	 * @return string created, updated or skipped.
	 */
	private static function one( Wormiss_Rest_Client $client, array $article, array &$report ): string {
		$post_id = self::post_for( $article['key'] );
		if ( $post_id && ( 'trash' === get_post_status( $post_id ) || get_post_meta( $post_id, self::META_DETACHED, true ) ) ) {
			return 'skipped';
		}
		// A post made in the background before authors were set gets one, changed or not.
		if ( $post_id && empty( $report['dry_run'] ) && 0 === (int) get_post_field( 'post_author', $post_id ) ) {
			wp_update_post(
				[
					'ID'          => $post_id,
					'post_author' => self::author(),
				]
			);
		}
		try {
			$document = $client->find_document( $article['key'] );
		} catch ( Wormiss_Exception $e ) {
			/* translators: 1: article title, 2: reason. */
			self::note( $report, sprintf( __( '%1$s: not read from iMIS (%2$s).', 'wormiss' ), self::label( $article ), $e->getMessage() ) );
			return 'skipped';
		}
		// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- iMIS fields.
		if ( null === $document || 'Published' !== (string) ( $document->Status ?? '' ) ) {
			/* translators: %s: article title. */
			self::note( $report, sprintf( __( '%s: not published in iMIS, so not imported.', 'wormiss' ), self::label( $article ) ) );
			return 'skipped';
		}
		$data    = $document->Data ?? '';
		$data    = is_object( $data ) && property_exists( $data, '$value' ) ? $data->{'$value'} : $data;
		$content = self::parse( Wormiss_Content_Check::decode_blob( is_string( $data ) ? $data : '', false ) );
		if ( null === $content ) {
			/* translators: %s: article title. */
			self::note( $report, sprintf( __( '%s: its iMIS content could not be read, so not imported.', 'wormiss' ), self::label( $article ) ) );
			return 'skipped';
		}
		$title = '' !== $article['title'] ? $article['title'] : sanitize_text_field( (string) ( $document->AlternateName ?? '' ) );
		if ( '' === $article['summary'] ) {
			$article['summary'] = sanitize_textarea_field( (string) ( $document->Description ?? '' ) );
		}
		$path = (string) ( $document->Path ?? '' );
		// phpcs:enable
		$built = self::build( $title, $content );
		$date  = self::local_date( '' !== $content['date'] ? $content['date'] : $article['date'] );
		// The featured image actually used (the page's, else the IQA thumbnail) is part of it.
		$feature = '' !== $built['feature'] ? $built['feature'] : (string) $article['thumbnail'];
		$hash    = md5( (string) wp_json_encode( [ $title, $article['summary'], $date, $built['html'], $feature, $article['tags'], $content['members'] ] ) );
		if ( $post_id && get_post_meta( $post_id, self::META_SOURCE, true ) === $hash ) {
			return 'skipped';
		}
		if ( ! empty( $report['dry_run'] ) ) {
			self::note(
				$report,
				sprintf(
					/* translators: 1: would make or update, 2: article title. */
					__( 'Dry run: would %1$s "%2$s".', 'wormiss' ),
					$post_id ? __( 'update', 'wormiss' ) : __( 'make', 'wormiss' ),
					$title
				)
			);
			return $post_id ? 'updated' : 'created';
		}
		$fields = [
			'post_type'    => 'post',
			'post_title'   => $title,
			'post_excerpt' => $article['summary'],
			'post_content' => $built['html'],
		];
		if ( '' !== $date ) {
			$fields['post_date']     = $date;
			$fields['post_date_gmt'] = get_gmt_from_date( $date );
		}
		if ( $post_id ) {
			$fields['ID'] = $post_id;
			$saved        = wp_update_post( wp_slash( $fields ), true );
		} else {
			$fields['post_status'] = Wormiss_Settings::get( 'news_import_publish' ) ? 'publish' : 'draft';
			$fields['post_author'] = self::author();
			$fields['meta_input']  = [ self::META_KEY => $article['key'] ];
			$saved                 = wp_insert_post( wp_slash( $fields ), true );
		}
		if ( is_wp_error( $saved ) || ! $saved ) {
			$reason = is_wp_error( $saved ) ? $saved->get_error_message() : __( 'not saved', 'wormiss' );
			/* translators: 1: article title, 2: reason. */
			self::note( $report, sprintf( __( '%1$s: no post saved (%2$s).', 'wormiss' ), $title, $reason ) );
			return 'skipped';
		}
		$saved = (int) $saved;
		self::terms( $saved, $article['tags'] );
		update_post_meta( $saved, Wormiss_Access::META_ENABLED, $content['members'] );
		update_post_meta( $saved, self::META_URL, self::page_url( $path ) );
		$complete = self::images( $saved, $built, $article, $report );
		// Kept only once every image is copied, so an image that did not fit or failed is tried
		// again next time.
		if ( $complete ) {
			update_post_meta( $saved, self::META_SOURCE, $hash );
		} else {
			delete_post_meta( $saved, self::META_SOURCE );
		}
		return $post_id ? 'updated' : 'created';
	}

	/**
	 * Who imported posts are by: the chosen author if they can still write posts, else the first
	 * administrator. Background batches run with no one signed in, so this is always set.
	 *
	 * @return int User ID (0 only if the site has no administrator).
	 */
	public static function author(): int {
		$chosen = (int) Wormiss_Settings::get( 'news_import_author' );
		if ( $chosen > 0 && user_can( $chosen, 'edit_posts' ) ) {
			return $chosen;
		}
		$admins = get_users(
			[
				'role'    => 'administrator',
				'number'  => 1,
				'orderby' => 'ID',
				'order'   => 'ASC',
				'fields'  => 'ID',
			]
		);
		return $admins ? (int) $admins[0] : 0;
	}

	/**
	 * The parts of an iMIS content page: its HTML blocks in page order (zone, then order in the
	 * zone), whether it is members-only, its publication date and its page image.
	 *
	 * @param string $xml The content page (the document's Data, decoded).
	 * @return array|null items (name, html), members, date, image; null if it cannot be read.
	 */
	public static function parse( string $xml ): ?array {
		if ( '' === trim( $xml ) || ! class_exists( 'DOMDocument' ) ) {
			return null;
		}
		$dom      = new DOMDocument();
		$previous = libxml_use_internal_errors( true );
		// No network, no entity expansion beyond the document's own.
		$loaded = $dom->loadXML( $xml, LIBXML_NONET );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );
		// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- DOM properties.
		$root = $dom->documentElement;
		// phpcs:enable
		if ( ! $loaded || ! $root instanceof DOMElement ) {
			return null;
		}
		$items = [];
		foreach ( $root->getElementsByTagNameNS( '*', 'ContentItem' ) as $node ) {
			$body = self::child( $node, 'Body' );
			if ( '' === trim( $body ) ) {
				continue;
			}
			$items[] = [
				'name' => self::child( $node, 'ContentItemName' ),
				'zone' => (int) self::child( $node, 'LayoutZone' ),
				'sort' => (int) self::child( $node, 'SortOrder' ),
				// The page's HTML is escaped once more inside the XML.
				'html' => html_entity_decode( $body, ENT_QUOTES | ENT_HTML5, 'UTF-8' ),
			];
		}
		usort(
			$items,
			static fn( array $a, array $b ): int => [ $a['zone'], $a['sort'] ] <=> [ $b['zone'], $b['sort'] ]
		);
		$flag = static fn( string $name ): bool => 'true' === strtolower( self::child( $root, $name ) );
		return [
			'items'   => array_map(
				static fn( array $item ): array => [
					'name' => $item['name'],
					'html' => $item['html'],
				],
				$items
			),
			'members' => $flag( 'IsMemberOnly' ) || $flag( 'RequireAuthenticatedUser' ),
			'date'    => self::child( $root, 'PublicationDate' ),
			'image'   => self::child( $root, 'MetaImagePath' ),
		];
	}

	/**
	 * The text of a node's first direct child with this local name ('' if none).
	 *
	 * @param DOMElement $node Node.
	 * @param string     $name Local name.
	 * @return string
	 */
	private static function child( DOMElement $node, string $name ): string {
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- DOM property.
		foreach ( $node->childNodes as $child ) {
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- DOM property.
			if ( $child instanceof DOMElement && $child->localName === $name ) {
				// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- DOM property.
				return (string) $child->textContent;
			}
		}
		return '';
	}

	/**
	 * The post's text from the page's blocks, and its feature image: a block that is only the
	 * title as a heading is left out (the post has a title); a block that is only one image
	 * stays where it is (the first beside the text before it, as iMIS shows it) and the first
	 * becomes the featured image too; the rest is tidied (see clean()) and joined.
	 *
	 * @param string $title   Post title.
	 * @param array  $content From parse().
	 * @return array html, feature (an image address, '' for none).
	 */
	public static function build( string $title, array $content ): array {
		$parts   = [];
		$is_text = [];
		$feature = '';
		$lead    = false;
		foreach ( $content['items'] as $item ) {
			$html = self::clean( (string) $item['html'] );
			if ( '' === $html ) {
				continue;
			}
			$text = trim( html_entity_decode( wp_strip_all_tags( $html ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
			// A block of exactly one image (a gallery stays as it is) stays in the article where iMIS
			// has it: beside the text before it, as on the iMIS page, or on its own line. The first
			// such image is also the featured image (for news cards).
			if ( '' === $text && 1 === preg_match_all( '/<img\b[^>]*\bsrc="([^"]+)"/i', $html, $match ) ) {
				$first = '' === $feature;
				if ( $first ) {
					$feature = html_entity_decode( $match[1][0], ENT_QUOTES | ENT_HTML5, 'UTF-8' );
				}
				preg_match( '/<img\b[^>]*>/i', $html, $tag );
				$last = count( $parts ) - 1;
				if ( $first && ! $lead && $last >= 0 && $is_text[ $last ] ) {
					$parts[ $last ]   = '<div class="wormiss-news-lead"><div class="wormiss-news-lead__text">' . $parts[ $last ]
						. '</div><figure class="wormiss-news-lead__image">' . $tag[0] . '</figure></div>';
					$is_text[ $last ] = false;
					$lead             = true;
					continue;
				}
				$parts[]   = '<figure class="wormiss-news-image' . ( $first ? ' wormiss-news-feature' : '' ) . '">' . $tag[0] . '</figure>';
				$is_text[] = false;
				continue;
			}
			if ( preg_match( '#^<h2>(.*)</h2>$#s', $html, $match )
				&& 0 === strcasecmp( trim( wp_strip_all_tags( $match[1] ) ), trim( $title ) ) ) {
				continue;
			}
			$parts[]   = $html;
			$is_text[] = true;
		}
		if ( '' === $feature && '' !== (string) ( $content['image'] ?? '' ) ) {
			$feature = (string) $content['image'];
		}
		return [
			'html'    => implode( "\n\n", $parts ),
			'feature' => $feature,
		];
	}

	/**
	 * Article HTML tidied for the theme: fonts, styles and classes gone; h1 becomes h2 (the post
	 * title is the h1); runs of line breaks become paragraphs; empty paragraphs and emphasis gone;
	 * tags balanced; links and images on the iMIS site made full addresses.
	 *
	 * @param string $html HTML from iMIS.
	 * @return string
	 */
	public static function clean( string $html ): string {
		$html = (string) preg_replace( '#<(/?)h1\b[^>]*>#i', '<$1h2>', $html );
		$html = wp_kses( $html, self::ALLOWED );
		// Emphasis around nothing but line breaks or spaces is decoration: gone.
		$html = (string) preg_replace( '#<(i|em|b|strong|u)>(?:\s|&nbsp;|<br\s*/?>)*</\1>#iu', '', $html );
		$html = self::split_breaks( $html );
		$html = (string) preg_replace( '#<(\w+)>(?:\s|&nbsp;|&\#160;|\xc2\xa0)*</\1>#u', '', $html );
		$html = (string) preg_replace( '#<p>\s*<br\s*/?>#i', '<p>', $html );
		$html = (string) preg_replace( '#<br\s*/?>\s*</p>#i', '</p>', $html );
		// Paragraphs made inside emphasis leave tags crossing: close them properly.
		$html = force_balance_tags( $html );
		// Twice: removing an empty tag can empty the one around it.
		$html = (string) preg_replace( '#<(\w+)>(?:\s|&nbsp;|&\#160;|\xc2\xa0)*</\1>#u', '', $html );
		$html = (string) preg_replace_callback(
			'#\b(href|src)="(/[^/"][^"]*|~/[^"]*)"#i',
			static function ( array $found ): string {
				$url = Wormiss_Events::absolute_url( html_entity_decode( $found[2], ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
				return '' === $url ? $found[0] : $found[1] . '="' . esc_attr( $url ) . '"';
			},
			$html
		);
		return trim( $html );
	}

	/**
	 * Runs of two or more line breaks as new paragraphs, keeping emphasis that was open across
	 * them (closed before the break, opened again after), so italic text stays italic.
	 *
	 * @param string $html HTML.
	 * @return string
	 */
	public static function split_breaks( string $html ): string {
		$parts = preg_split( '#((?:\s*<br\s*/?>\s*){2,}|</?(?:i|em|b|strong|u)>)#i', $html, -1, PREG_SPLIT_DELIM_CAPTURE );
		$out   = '';
		$open  = [];
		foreach ( (array) $parts as $part ) {
			if ( preg_match( '#^<(/?)(i|em|b|strong|u)>$#i', $part, $tag ) ) {
				$name = strtolower( $tag[2] );
				if ( '' === $tag[1] ) {
					$open[] = $name;
				} else {
					$at = array_search( $name, array_reverse( $open, true ), true );
					if ( false !== $at ) {
						unset( $open[ $at ] );
						$open = array_values( $open );
					}
				}
				$out .= $part;
				continue;
			}
			if ( preg_match( '#^(?:\s*<br\s*/?>\s*){2,}$#i', $part ) ) {
				$close = '';
				foreach ( array_reverse( $open ) as $name ) {
					$close .= '</' . $name . '>';
				}
				$reopen = '';
				foreach ( $open as $name ) {
					$reopen .= '<' . $name . '>';
				}
				$out .= $close . '</p><p>' . $reopen;
				continue;
			}
			$out .= $part;
		}
		return $out;
	}

	/**
	 * Copy the article's images on the iMIS site into the Media Library (each address once, for
	 * every post), point the post's text at the copies, and set the featured image.
	 *
	 * @param int   $post_id Post ID.
	 * @param array $built   From build().
	 * @param array $article From articles().
	 * @param array $report  Report, for notes.
	 * @return bool Whether every image is done (false when one was not copied: no room, or it failed).
	 */
	private static function images( int $post_id, array $built, array $article, array &$report ): bool {
		/**
		 * Whether imported news copies its images into the Media Library.
		 *
		 * @param bool $copy Copy images (default true).
		 */
		if ( ! apply_filters( 'wormiss_news_import_images', true ) ) {
			return true;
		}
		$complete = true;
		$html     = $built['html'];
		$base     = Wormiss_Settings::base_url();
		if ( '' !== $base && preg_match_all( '/<img\b[^>]*\bsrc="([^"]+)"/i', $html, $matches ) ) {
			foreach ( array_unique( $matches[1] ) as $src ) {
				$url = html_entity_decode( $src, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
				if ( 0 !== strpos( $url, $base . '/' ) ) {
					continue; // Images on other sites stay where they are.
				}
				$attachment = self::copy( $post_id, $url, $article, $report, $complete );
				if ( $attachment ) {
					$html = str_replace( 'src="' . $src . '"', 'src="' . esc_attr( (string) wp_get_attachment_url( $attachment ) ) . '"', $html );
				}
			}
			if ( $html !== $built['html'] ) {
				wp_update_post(
					wp_slash(
						[
							'ID'           => $post_id,
							'post_content' => $html,
						]
					)
				);
			}
		}
		$feature = '' !== $built['feature'] ? $built['feature'] : (string) $article['thumbnail'];
		$feature = Wormiss_Events::absolute_url( $feature );
		// Only images on the iMIS site are copied, as in the text.
		if ( '' !== $feature && '' !== $base && 0 === strpos( $feature, $base . '/' )
			&& get_post_meta( $post_id, self::META_FEATURE, true ) !== $feature ) {
			$attachment = self::copy( $post_id, $feature, $article, $report, $complete );
			if ( $attachment ) {
				set_post_thumbnail( $post_id, $attachment );
				update_post_meta( $post_id, self::META_FEATURE, $feature );
			}
		}
		return $complete;
	}

	/**
	 * The Media Library copy of an iMIS image: one already copied, or a new copy (if this run
	 * has room).
	 *
	 * @param int    $post_id  Post the image belongs to.
	 * @param string $url      Image address.
	 * @param array  $article  From articles(), for its title.
	 * @param array  $report   Report, for notes.
	 * @param bool   $complete Set to false when it was not copied (no room, or it failed).
	 * @return int Attachment ID, or 0.
	 */
	private static function copy( int $post_id, string $url, array $article, array &$report, bool &$complete ): int {
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
			$complete = false; // Tried again next import.
			/* translators: 1: article title, 2: reason. */
			self::note( $report, sprintf( __( '%1$s: an image could not be copied (%2$s).', 'wormiss' ), self::label( $article ), $tmp->get_error_message() ) );
			return 0;
		}
		$name       = sanitize_file_name( rawurldecode( wp_basename( (string) wp_parse_url( $url, PHP_URL_PATH ) ) ) );
		$attachment = media_handle_sideload(
			[
				'name'     => '' !== $name ? $name : 'news-image.jpg',
				'tmp_name' => $tmp,
			],
			$post_id,
			self::label( $article )
		);
		if ( is_wp_error( $attachment ) ) {
			$complete = false; // Tried again next import.
			wp_delete_file( $tmp );
			/* translators: 1: article title, 2: reason. */
			self::note( $report, sprintf( __( '%1$s: an image could not be added (%2$s).', 'wormiss' ), self::label( $article ), $attachment->get_error_message() ) );
			return 0;
		}
		update_post_meta( (int) $attachment, self::META_FROM, $url );
		return (int) $attachment;
	}

	/**
	 * Put the post in the News category (made if missing) and give it the article's iMIS tags.
	 *
	 * @param int      $post_id Post ID.
	 * @param string[] $tags    Tag names.
	 * @return void
	 */
	private static function terms( int $post_id, array $tags ): void {
		$name = trim( (string) Wormiss_Settings::get( 'news_import_category' ) );
		$name = '' !== $name ? $name : 'News';
		$term = get_term_by( 'name', $name, 'category' );
		if ( ! $term instanceof WP_Term ) {
			$made = wp_insert_term( $name, 'category' );
			$term = is_wp_error( $made ) ? null : get_term( (int) $made['term_id'], 'category' );
		}
		if ( $term instanceof WP_Term ) {
			wp_set_post_categories( $post_id, [ $term->term_id ] );
		}
		wp_set_post_tags( $post_id, $tags );
	}

	/**
	 * An iMIS date-time (local time, e.g. 2026-06-08T11:45:00 or 8/06/2026 11:45:00 AM) as a
	 * WordPress post date, '' if it is not a date.
	 *
	 * @param string $value iMIS date-time.
	 * @return string Y-m-d H:i:s.
	 */
	public static function local_date( string $value ): string {
		$value = trim( $value );
		if ( '' === $value || 0 === strpos( $value, '0001-' ) ) {
			return '';
		}
		$zone = wp_timezone();
		foreach ( [ '!Y-m-d\TH:i:s', '!Y-m-d\TH:i:s.u', '!Y-m-d H:i:s', '!d/m/Y g:i:s A', '!j/m/Y g:i:s A', '!d/m/Y' ] as $format ) {
			$date = DateTimeImmutable::createFromFormat( $format, $value, $zone );
			if ( $date instanceof DateTimeImmutable ) {
				return $date->format( 'Y-m-d H:i:s' );
			}
		}
		return '';
	}

	/**
	 * The article's page on the iMIS site, from its document path (@/web/News/x → /web/News/x.aspx).
	 *
	 * @param string $path Document path.
	 * @return string
	 */
	public static function page_url( string $path ): string {
		if ( 0 !== strpos( $path, '@/' ) ) {
			return '';
		}
		$parts = array_map( 'rawurlencode', explode( '/', substr( $path, 2 ) ) );
		return Wormiss_Events::absolute_url( '/' . implode( '/', $parts ) . '.aspx' );
	}

	/**
	 * The post made for an article (trashed ones too, so a post someone deleted is not made
	 * again), or 0.
	 *
	 * @param string $key Document version key.
	 * @return int
	 */
	public static function post_for( string $key ): int {
		// phpcs:disable WordPress.DB.SlowDBQuery -- One meta lookup per article.
		$found = get_posts(
			[
				'post_type'        => 'post',
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
	 * The "iMIS news" box on an imported post: where it came from, and the tick box that stops
	 * later imports changing it.
	 *
	 * @param WP_Post $post Post.
	 * @return void
	 */
	public static function add_box( $post ): void {
		if ( $post instanceof WP_Post && '' !== (string) get_post_meta( $post->ID, self::META_KEY, true ) ) {
			add_meta_box( 'wormiss-news', __( 'iMIS news', 'wormiss' ), [ __CLASS__, 'render_box' ], 'post', 'side' );
		}
	}

	/**
	 * The box's content.
	 *
	 * @param WP_Post $post Post.
	 * @return void
	 */
	public static function render_box( $post ): void {
		wp_nonce_field( 'wormiss_news_box', 'wormiss_news_box_nonce' );
		echo '<p>' . esc_html__( 'Imported from iMIS. Each import brings this post up to date with the iMIS article.', 'wormiss' ) . '</p>';
		$url = (string) get_post_meta( $post->ID, self::META_URL, true );
		if ( '' !== $url ) {
			echo '<p><a href="' . esc_url( $url ) . '" target="_blank" rel="noopener">' . esc_html__( 'Open the iMIS article', 'wormiss' ) . '</a></p>';
		}
		printf(
			'<p><label><input type="checkbox" name="wormiss_news_detached" value="1" %1$s /> %2$s</label></p>',
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
		if ( ! isset( $_POST['wormiss_news_box_nonce'] )
			|| ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['wormiss_news_box_nonce'] ) ), 'wormiss_news_box' )
			|| ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		if ( ! empty( $_POST['wormiss_news_detached'] ) ) {
			update_post_meta( $post_id, self::META_DETACHED, 1 );
		} else {
			delete_post_meta( $post_id, self::META_DETACHED );
		}
	}

	/**
	 * An article's name for notes.
	 *
	 * @param array $article From articles().
	 * @return string
	 */
	private static function label( array $article ): string {
		return '' !== (string) ( $article['title'] ?? '' ) ? (string) $article['title'] : (string) ( $article['key'] ?? '' );
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
				'NEWS IMPORT',
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
			return sprintf( __( 'Last news import %1$s: nothing changed (%2$s).', 'wormiss' ), $when, implode( ' ', $report['notes'] ) );
		}
		if ( 'off' === $report['status'] ) {
			/* translators: %s: date and time. */
			return sprintf( __( 'Last news import %s: the news import is off or has no query, so nothing changed.', 'wormiss' ), $when );
		}
		$text = sprintf(
			/* translators: 1: date and time, 2: articles, 3: posts made, 4: posts updated, 5: unchanged or skipped. */
			__( 'Last news import %1$s: %2$d articles listed; %3$d posts made, %4$d updated, %5$d unchanged or skipped.', 'wormiss' ),
			$when,
			(int) $report['read'],
			(int) $report['created'],
			(int) $report['updated'],
			(int) $report['skipped']
		);
		if ( ! empty( $report['dry_run'] ) ) {
			$text .= ' ' . __( 'Dry run: nothing was saved.', 'wormiss' );
		}
		if ( ! empty( $report['remaining'] ) ) {
			/* translators: %d: articles still to check. */
			$text .= ' ' . sprintf( __( 'Still going: %d more articles to check in the background.', 'wormiss' ), (int) $report['remaining'] );
		}
		return $text;
	}
}
