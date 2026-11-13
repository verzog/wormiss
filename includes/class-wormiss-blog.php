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
 * The members' blog: posts with an intro for everyone, the full text for readers, and a
 * solution shown once its time comes.
 *
 * @package    wormiss
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined( 'ABSPATH' ) || exit;

/**
 * A "Members' blog" section at /blogs/, with its own categories and topics. Readers are the
 * roles an administrator chooses (e.g. a paid membership role) plus staff; everyone else sees
 * the post's intro and a sign-in message, never the full text, the solution or the comments,
 * on the page, in feeds or through the REST API. The solution shows to readers from its reveal
 * time (staff always see it, marked with that time).
 */
class Wormiss_Blog {

	/**
	 * The post type.
	 */
	const POST_TYPE = 'wormiss_blog';

	/**
	 * Categories (with subcategories).
	 */
	const TAX_CATEGORY = 'wormiss_blog_category';

	/**
	 * Topics (iMIS tags).
	 */
	const TAX_TOPIC = 'wormiss_blog_topic';

	/**
	 * The intro (HTML) everyone may see.
	 */
	const META_INTRO = '_wormiss_blog_intro';

	/**
	 * The solution (HTML).
	 */
	const META_SOLUTION = '_wormiss_blog_solution';

	/**
	 * When the solution shows (Unix time; 0 or none: as soon as there is one).
	 */
	const META_REVEAL = '_wormiss_blog_reveal';

	/**
	 * Hook in.
	 *
	 * @return void
	 */
	public static function init(): void {
		// Before the Events post type, which rebuilds permalinks after an update.
		add_action( 'init', [ __CLASS__, 'register' ], 9 );
		add_filter( 'the_content', [ __CLASS__, 'filter_content' ], 8 );
		add_action( 'wp_enqueue_scripts', [ __CLASS__, 'styles' ] );
		add_filter( 'comments_open', [ __CLASS__, 'comments_open' ], 20, 2 );
		add_filter( 'comments_array', [ __CLASS__, 'comments_array' ], 20, 2 );
		add_filter( 'comment_text', [ __CLASS__, 'comment_text' ], 20, 2 );
		add_filter( 'comment_text_rss', [ __CLASS__, 'comment_text_rss' ], 20 );
		add_filter( 'comment_feed_where', [ __CLASS__, 'comment_feed_where' ], 20 );
		add_filter( 'rest_prepare_' . self::POST_TYPE, [ __CLASS__, 'rest_post' ], 20, 2 );
		add_filter( 'rest_prepare_comment', [ __CLASS__, 'rest_comment' ], 20, 2 );
	}

	/**
	 * Register the post type and taxonomies.
	 *
	 * @return void
	 */
	public static function register(): void {
		register_post_type(
			'wormiss_blog', // Literal so its validity can be checked; the same as POST_TYPE.
			[
				'labels'       => [
					'name'          => __( 'Members\' blog', 'wormiss' ),
					'singular_name' => __( 'Blog post', 'wormiss' ),
					'add_new_item'  => __( 'Add blog post', 'wormiss' ),
					'edit_item'     => __( 'Edit blog post', 'wormiss' ),
					'all_items'     => __( 'All blog posts', 'wormiss' ),
					'menu_name'     => __( 'Members\' blog', 'wormiss' ),
				],
				'public'       => true,
				'has_archive'  => true,
				'rewrite'      => [
					'slug'       => 'blogs',
					'with_front' => false,
				],
				'menu_icon'    => 'dashicons-format-chat',
				'show_in_rest' => true,
				'supports'     => [ 'title', 'editor', 'excerpt', 'thumbnail', 'comments', 'author', 'custom-fields', 'revisions' ],
			]
		);
		register_taxonomy(
			self::TAX_CATEGORY,
			self::POST_TYPE,
			[
				'labels'            => [
					'name'          => __( 'Blog categories', 'wormiss' ),
					'singular_name' => __( 'Blog category', 'wormiss' ),
				],
				'hierarchical'      => true,
				'show_in_rest'      => true,
				'show_admin_column' => true,
				'rewrite'           => [
					'slug'       => 'blogs/category',
					'with_front' => false,
				],
			]
		);
		register_taxonomy(
			self::TAX_TOPIC,
			self::POST_TYPE,
			[
				'labels'            => [
					'name'          => __( 'Blog topics', 'wormiss' ),
					'singular_name' => __( 'Blog topic', 'wormiss' ),
				],
				'hierarchical'      => false,
				'show_in_rest'      => true,
				'show_admin_column' => true,
				'rewrite'           => [
					'slug'       => 'blogs/topic',
					'with_front' => false,
				],
			]
		);
	}

	/**
	 * Whether a person may read full blog posts: staff (can edit others' posts), or one of the
	 * reader roles chosen in Settings.
	 *
	 * @param int $user_id User ID (0: the current user).
	 * @return bool
	 */
	public static function can_read( int $user_id = 0 ): bool {
		$user = $user_id ? get_userdata( $user_id ) : wp_get_current_user();
		if ( ! $user instanceof WP_User || ! $user->exists() ) {
			return false;
		}
		if ( user_can( $user, 'edit_others_posts' ) ) {
			return true;
		}
		$roles = array_map( 'strval', (array) Wormiss_Settings::get( 'blog_reader_roles' ) );
		return (bool) array_intersect( $roles, (array) $user->roles );
	}

	/**
	 * Whether a post is a blog post.
	 *
	 * @param mixed $post Post or ID.
	 * @return bool
	 */
	private static function is_blog( $post ): bool {
		$post = get_post( $post );
		return $post instanceof WP_Post && self::POST_TYPE === $post->post_type;
	}

	/**
	 * A blog post's content for whoever is looking: readers get the text and (when due) the
	 * solution; others the intro and a sign-in message. Links to old iMIS blog addresses go to
	 * the post here.
	 *
	 * @param string $content Content.
	 * @return string
	 */
	public static function filter_content( $content ) {
		$post_id = (int) get_the_ID();
		if ( ! $post_id || ! self::is_blog( $post_id ) ) {
			return $content;
		}
		// What shows depends on who is looking: never page-cached.
		Wormiss_Members_Only::no_page_cache();
		if ( ! self::can_read() ) {
			$html = self::link_old_addresses( (string) get_post_meta( $post_id, self::META_INTRO, true ) ) . self::locked_notice();
		} else {
			$html = self::link_old_addresses( (string) $content ) . self::solution_html( $post_id );
		}
		// In an excerpt just the text; on the page, in a wrapper the blog styles hang off.
		return doing_filter( 'get_the_excerpt' ) ? $html : '<div class="wormiss-blog-post">' . $html . '</div>';
	}

	/**
	 * The blog's layout styles, on blog pages.
	 *
	 * @return void
	 */
	public static function styles(): void {
		if ( is_singular( self::POST_TYPE ) || is_post_type_archive( self::POST_TYPE ) || is_tax( [ self::TAX_CATEGORY, self::TAX_TOPIC ] ) ) {
			wp_enqueue_style( 'wormiss-blog', plugins_url( 'assets/blog.css', WORMISS_FILE ), [], WORMISS_VERSION );
		}
	}

	/**
	 * The solution section for a reader: shown from its reveal time; staff see it earlier,
	 * marked with when members will.
	 *
	 * @param int $post_id Post ID.
	 * @return string
	 */
	public static function solution_html( int $post_id ): string {
		$solution = trim( (string) get_post_meta( $post_id, self::META_SOLUTION, true ) );
		if ( '' === $solution ) {
			return '';
		}
		$reveal = (int) get_post_meta( $post_id, self::META_REVEAL, true );
		$due    = $reveal <= time();
		if ( ! $due && ! current_user_can( 'edit_post', $post_id ) ) {
			return '<div class="alert alert-info wormiss-blog-solution wormiss-blog-solution--waiting"><p>' . esc_html(
				sprintf(
					/* translators: %s: date and time. */
					__( 'The solution will be published on %s.', 'wormiss' ),
					wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $reveal )
				)
			) . '</p></div>';
		}
		$note = $due ? '' : '<p class="small text-muted wormiss-blog-solution__note">' . esc_html(
			sprintf(
				/* translators: %s: date and time. */
				__( 'Shown to staff only until %s.', 'wormiss' ),
				wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $reveal )
			)
		) . '</p>';
		return '<section class="card wormiss-blog-solution"><div class="card-header"><h2 class="h5 mb-0">'
			. esc_html__( 'Solution', 'wormiss' ) . '</h2></div><div class="card-body">'
			. $note . self::link_old_addresses( $solution ) . '</div></section>';
	}

	/**
	 * What people who cannot read the blog see after the intro.
	 *
	 * @return string
	 */
	private static function locked_notice(): string {
		$text = is_user_logged_in()
			? __( 'The full blog post and its discussion are for members.', 'wormiss' )
			: __( 'The full blog post and its discussion are for members. Sign in to read on.', 'wormiss' );
		$link = is_user_logged_in() ? '' : ' <a href="' . esc_url( wp_login_url( (string) get_permalink() ) ) . '">' . esc_html__( 'Sign in', 'wormiss' ) . '</a>';
		return '<div class="alert alert-secondary wormiss-blog-locked"><p>' . esc_html( $text ) . $link . '</p></div>';
	}

	/**
	 * Links to old iMIS blog addresses (/blogs?_r_path=/view/index/{blog key}) made links to the
	 * imported post here, where there is one.
	 *
	 * @param string $html HTML.
	 * @return string
	 */
	public static function link_old_addresses( string $html ): string {
		return (string) preg_replace_callback(
			'#href="[^"]*?/blogs\?_r_path=/view/index/([0-9a-fA-F-]{36})[^"]*"#',
			static function ( array $found ): string {
				$post_id = Wormiss_Blog_Import::post_for( strtolower( $found[1] ) );
				return $post_id ? 'href="' . esc_url( (string) get_permalink( $post_id ) ) . '"' : $found[0];
			},
			$html
		);
	}

	/**
	 * No new comments from people who cannot read.
	 *
	 * @param bool  $open    Whether comments are open.
	 * @param mixed $post_id Post.
	 * @return bool
	 */
	public static function comments_open( $open, $post_id ) {
		return self::is_blog( $post_id ) && ! self::can_read() ? false : $open;
	}

	/**
	 * No comments listed for people who cannot read.
	 *
	 * @param array $comments Comments.
	 * @param mixed $post_id  Post.
	 * @return array
	 */
	public static function comments_array( $comments, $post_id ) {
		return self::is_blog( $post_id ) && ! self::can_read() ? [] : $comments;
	}

	/**
	 * No comment text (feeds, widgets) for people who cannot read.
	 *
	 * @param string     $text    Comment text.
	 * @param WP_Comment $comment Comment.
	 * @return string
	 */
	public static function comment_text( $text, $comment = null ) {
		return $comment instanceof WP_Comment && self::is_blog( (int) $comment->comment_post_ID ) && ! self::can_read() ? '' : $text;
	}

	/**
	 * Comment feeds leave out blog comments for people who cannot read.
	 *
	 * @param string $where SQL WHERE clause of the comment feed.
	 * @return string
	 */
	public static function comment_feed_where( $where ) {
		if ( self::can_read() ) {
			return $where;
		}
		global $wpdb;
		return $where . $wpdb->prepare(
			" AND {$wpdb->comments}.comment_post_ID NOT IN ( SELECT ID FROM {$wpdb->posts} WHERE post_type = %s )",
			self::POST_TYPE
		);
	}

	/**
	 * Feed comment text: none for a blog comment when the reader cannot read (the feed query
	 * already leaves those out; this covers anything that slips through).
	 *
	 * @param string $text Comment text for a feed.
	 * @return string
	 */
	public static function comment_text_rss( $text ) {
		$comment = get_comment();
		return $comment instanceof WP_Comment && self::is_blog( (int) $comment->comment_post_ID ) && ! self::can_read() ? '' : $text;
	}

	/**
	 * Through the REST API, people who cannot read get the intro, not the text.
	 *
	 * @param WP_REST_Response $response Response.
	 * @param WP_Post          $post     Post.
	 * @return WP_REST_Response
	 */
	public static function rest_post( $response, $post ) {
		if ( $response instanceof WP_REST_Response && $post instanceof WP_Post && ! self::can_read() ) {
			$data = $response->get_data();
			if ( isset( $data['content'] ) && is_array( $data['content'] ) ) {
				$data['content']['rendered'] = (string) get_post_meta( $post->ID, self::META_INTRO, true );
				$data['content']['raw']      = '';
			}
			$response->set_data( $data );
		}
		return $response;
	}

	/**
	 * Through the REST API, blog comments are empty for people who cannot read.
	 *
	 * @param WP_REST_Response $response Response.
	 * @param WP_Comment       $comment  Comment.
	 * @return WP_REST_Response
	 */
	public static function rest_comment( $response, $comment ) {
		if ( $response instanceof WP_REST_Response && $comment instanceof WP_Comment
			&& self::is_blog( (int) $comment->comment_post_ID ) && ! self::can_read() ) {
			$data                        = $response->get_data();
			$data['content']['rendered'] = '';
			$data['author_name']         = '';
			$response->set_data( $data );
		}
		return $response;
	}
}
