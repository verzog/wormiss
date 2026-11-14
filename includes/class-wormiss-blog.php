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
		add_action( 'pre_get_posts', [ __CLASS__, 'per_page' ] );
		add_filter( 'render_block_core/post-terms', [ __CLASS__, 'term_classes' ], 10, 3 );
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
		$template = (string) get_post_meta( $post_id, Wormiss_Blog_Import::META_TEMPLATE, true );
		$class    = '' !== $template ? ' wormiss-blog-post--' . sanitize_html_class( sanitize_title( $template ) ) : '';
		return doing_filter( 'get_the_excerpt' ) ? $html : '<div class="wormiss-blog-post' . esc_attr( $class ) . '">' . $html . '</div>';
	}

	/**
	 * Posts per page on the blog's listing pages (/blogs/, its categories and topics).
	 */
	const PER_PAGE = 12;

	/**
	 * Twelve posts a page on the blog's listing pages, as on the iMIS blog.
	 *
	 * @param WP_Query $query Query.
	 * @return void
	 */
	public static function per_page( $query ): void {
		if ( is_admin() || ! $query instanceof WP_Query || ! $query->is_main_query() ) {
			return;
		}
		if ( $query->is_post_type_archive( self::POST_TYPE ) || $query->is_tax( [ self::TAX_CATEGORY, self::TAX_TOPIC ] ) ) {
			$query->set( 'posts_per_page', self::PER_PAGE );
		}
	}

	/**
	 * A post's blog categories marked by level (Post Terms block), so a theme can show the
	 * category and the subcategory (level) differently: wormiss-term--category on a top-level
	 * category, wormiss-term--subcategory on one beneath it.
	 *
	 * @param string   $html     Block HTML.
	 * @param array    $block    Block.
	 * @param WP_Block $instance Block instance.
	 * @return string
	 */
	public static function term_classes( $html, $block, $instance ): string {
		$html = (string) $html;
		if ( self::TAX_CATEGORY !== ( $block['attrs']['term'] ?? '' ) || '' === $html ) {
			return $html;
		}
		$post_id = (int) ( $instance->context['postId'] ?? get_the_ID() );
		$terms   = $post_id ? get_the_terms( $post_id, self::TAX_CATEGORY ) : false;
		if ( ! is_array( $terms ) ) {
			return $html;
		}
		$levels = [];
		foreach ( $terms as $term ) {
			$link = get_term_link( $term );
			if ( is_string( $link ) ) {
				$levels[ $link ] = $term->parent ? 'wormiss-term--subcategory' : 'wormiss-term--category';
			}
		}
		$tags = new WP_HTML_Tag_Processor( $html );
		while ( $tags->next_tag( 'a' ) ) {
			$href = (string) $tags->get_attribute( 'href' );
			if ( isset( $levels[ $href ] ) ) {
				$tags->add_class( $levels[ $href ] );
			}
		}
		return $tags->get_updated_html();
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
	 * A blog post's details for the Blog post details block: its comment count ('comments'),
	 * author, date and comment count ('line'), or Back to posts, author, reading time and date
	 * ('sidebar').
	 *
	 * @param int    $post_id Post ID.
	 * @param string $show    comments, line or sidebar.
	 * @return string
	 */
	public static function meta_html( int $post_id, string $show ): string {
		$post = get_post( $post_id );
		if ( ! $post instanceof WP_Post || self::POST_TYPE !== $post->post_type ) {
			return '';
		}
		$count    = (int) get_comments_number( $post );
		$comments = '<span class="wormiss-blog-meta__comments" title="' . esc_attr(
			/* translators: %d: number of comments. */
			sprintf( _n( '%d comment', '%d comments', $count, 'wormiss' ), $count )
		) . '">' . self::icon( 'comment' ) . '<span>' . esc_html( number_format_i18n( $count ) ) . '</span></span>';
		if ( 'comments' === $show ) {
			return $comments;
		}
		$author = get_the_author_meta( 'display_name', (int) $post->post_author );
		if ( 'line' === $show ) {
			return '<span class="wormiss-blog-meta__author">' . esc_html( $author ) . '</span>'
				. '<span class="wormiss-blog-meta__date">' . esc_html( get_the_date( 'd/m/Y', $post ) ) . '</span>' . $comments;
		}
		$words   = str_word_count( wp_strip_all_tags( (string) $post->post_content ) );
		$minutes = max( 1, (int) ceil( $words / 200 ) );
		$back    = (string) get_post_type_archive_link( self::POST_TYPE );
		return '<a class="wormiss-blog-meta__back" href="' . esc_url( $back ) . '">' . self::icon( 'back' ) . esc_html__( 'Back to posts', 'wormiss' ) . '</a>'
			. '<span class="wormiss-blog-meta__item"><span class="wormiss-blog-meta__initials" aria-hidden="true">' . esc_html( self::initials( $author ) ) . '</span>' . esc_html( $author ) . '</span>'
			. '<span class="wormiss-blog-meta__item">' . self::icon( 'clock' ) . esc_html(
				/* translators: %d: minutes. */
				sprintf( _n( '%d min read', '%d min read', $minutes, 'wormiss' ), $minutes )
			) . '</span>'
			. '<span class="wormiss-blog-meta__item">' . self::icon( 'calendar' ) . esc_html( get_the_date( 'j M Y', $post ) ) . '</span>';
	}

	/**
	 * A name's initials (e.g. LP for Lloyd Peterson).
	 *
	 * @param string $name Name.
	 * @return string
	 */
	private static function initials( string $name ): string {
		$words   = array_filter( explode( ' ', (string) preg_replace( '/\s+/', ' ', trim( $name ) ) ) );
		$letters = '';
		foreach ( $words as $word ) {
			$first    = mb_substr( $word, 0, 1 );
			$letters .= function_exists( 'mb_strtoupper' ) ? mb_strtoupper( $first ) : strtoupper( $first );
		}
		// First and last name's initials.
		return mb_strlen( $letters ) > 2 ? mb_substr( $letters, 0, 1 ) . mb_substr( $letters, -1 ) : $letters;
	}

	/**
	 * A small line icon.
	 *
	 * @param string $name comment, back, clock, calendar or lock.
	 * @return string SVG.
	 */
	private static function icon( string $name ): string {
		$paths = [
			'comment'  => '<path d="M21 12a8 8 0 0 1-11.6 7.1L4 20l1-4.6A8 8 0 1 1 21 12z"/>',
			'back'     => '<path d="M15 18l-6-6 6-6"/>',
			'clock'    => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
			'calendar' => '<rect x="4" y="5" width="16" height="16" rx="2"/><path d="M4 10h16M9 3v4M15 3v4"/>',
			'lock'     => '<rect x="5" y="11" width="14" height="10" rx="2"/><path d="M8 11V8a4 4 0 0 1 8 0v3"/>',
		];
		return '<svg class="wormiss-blog-icon" width="16" height="16" viewBox="0 0 24 24" aria-hidden="true" focusable="false" fill="none" stroke="currentColor"'
			. ' stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' . ( $paths[ $name ] ?? '' ) . '</svg>';
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
			return '<div class="alert alert-info wormiss-blog-solution wormiss-blog-solution--waiting">' . self::eye_icon() . '<p>' . esc_html(
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
		// Closed until the reader chooses to see it, as on the iMIS blog (no script needed).
		return '<details class="card wormiss-blog-solution"><summary class="card-header wormiss-blog-solution__reveal">'
			. self::eye_icon()
			. '<span class="wormiss-blog-solution__title h5">' . esc_html__( 'Solution', 'wormiss' ) . '</span>'
			. '<span class="wormiss-blog-solution__hint">' . esc_html__( 'Think it through first: the solution is hidden until you choose to see it.', 'wormiss' ) . '</span>'
			. '<span class="btn wormiss-blog-solution__button">'
			. '<span class="wormiss-blog-solution__show">' . esc_html__( 'Show the solution', 'wormiss' ) . '</span>'
			. '<span class="wormiss-blog-solution__hide">' . esc_html__( 'Hide the solution', 'wormiss' ) . '</span>'
			. '</span></summary><div class="card-body">'
			. $note . self::link_old_addresses( $solution ) . '</div></details>';
	}

	/**
	 * An eye icon (decorative).
	 *
	 * @return string SVG.
	 */
	private static function eye_icon(): string {
		return '<svg class="wormiss-blog-solution__icon" aria-hidden="true" focusable="false" width="24" height="24" viewBox="0 0 24 24"'
			. ' fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">'
			. '<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>';
	}

	/**
	 * What people who cannot read the blog see after the intro.
	 *
	 * @return string
	 */
	private static function locked_notice(): string {
		$html = '<div class="wormiss-blog-locked"><span class="wormiss-blog-locked__icon">' . self::icon( 'lock' ) . '</span>'
			. '<p class="wormiss-blog-locked__title">' . esc_html__( 'Access restricted', 'wormiss' ) . '</p>';
		if ( is_user_logged_in() ) {
			return $html . '<p class="wormiss-blog-locked__text">' . esc_html__( 'The full blog post and its discussion are for members.', 'wormiss' ) . '</p></div>';
		}
		$html .= '<p class="wormiss-blog-locked__text">' . esc_html__( 'Sign in or join to continue reading', 'wormiss' ) . '</p>'
			. '<p class="wormiss-blog-locked__actions"><a class="wormiss-blog-locked__sign-in" href="' . esc_url( wp_login_url( (string) get_permalink() ) ) . '">'
			. esc_html__( 'Sign in', 'wormiss' ) . '</a>';
		if ( get_option( 'users_can_register' ) ) {
			$html .= '<a class="wormiss-blog-locked__join" href="' . esc_url( wp_registration_url() ) . '">' . esc_html__( 'Create account', 'wormiss' ) . '</a>';
		}
		return $html . '</p></div>';
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
