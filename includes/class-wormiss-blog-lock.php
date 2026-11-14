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
 * The members' blog hidden from everyone who cannot read it.
 *
 * Blog pages (the blog home, posts, categories, topics and their feeds) send visitors to the
 * sign-in page and show signed-in non-members "not found". Blog posts are also left out of
 * other listings and searches, the REST API, oEmbed and the XML sitemaps.
 *
 * @package    wormiss
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined( 'ABSPATH' ) || exit;

/**
 * Keeps the members' blog to its readers.
 */
class Wormiss_Blog_Lock {

	/**
	 * Hook in.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'template_redirect', [ __CLASS__, 'guard' ], 0 );
		add_action( 'pre_get_posts', [ __CLASS__, 'hide_posts' ], 5 );
		add_action( 'pre_get_comments', [ __CLASS__, 'hide_comments' ] );
		add_filter( 'rest_request_before_callbacks', [ __CLASS__, 'rest_guard' ], 10, 3 );
		add_filter( 'rest_term_search_query', [ __CLASS__, 'term_search' ] );
		add_filter( 'oembed_request_post_id', [ __CLASS__, 'oembed_post' ] );
		add_filter( 'wp_sitemaps_post_types', [ __CLASS__, 'sitemap_types' ] );
		add_filter( 'wp_sitemaps_taxonomies', [ __CLASS__, 'sitemap_taxonomies' ] );
	}

	/**
	 * Whether this request is a blog page: the blog home, a post, a category or a topic (or one
	 * of their feeds).
	 *
	 * @return bool
	 */
	public static function is_blog_page(): bool {
		global $wp_query;
		// A search (even one limited to blog posts, which WordPress also counts as the blog's
		// archive) is narrowed by hide_posts() instead.
		if ( is_search() ) {
			return false;
		}
		if ( is_singular( Wormiss_Blog::POST_TYPE ) || is_post_type_archive( Wormiss_Blog::POST_TYPE )
			|| is_tax( [ Wormiss_Blog::TAX_CATEGORY, Wormiss_Blog::TAX_TOPIC ] ) ) {
			return true;
		}
		// Any other page of blog posts (a dated listing, say); a search is narrowed instead.
		return $wp_query instanceof WP_Query && ! $wp_query->is_search() && self::asks_for_blog( $wp_query );
	}

	/**
	 * What a blog page does for the person asking: '' (show it), 'login' (send to sign in) or
	 * 'hide' (not found).
	 *
	 * @return string
	 */
	public static function decision(): string {
		if ( ! self::is_blog_page() ) {
			return '';
		}
		// Who sees the page decides what it holds: never page-cached, for members either.
		Wormiss_Members_Only::no_page_cache();
		if ( Wormiss_Blog::can_read() ) {
			return '';
		}
		return is_user_logged_in() || is_feed() ? 'hide' : 'login';
	}

	/**
	 * Send visitors on a blog page to sign in (and back), and show signed-in non-members the
	 * site's "not found" page.
	 *
	 * @return void
	 */
	public static function guard(): void {
		$decision = self::decision();
		if ( 'login' === $decision ) {
			wp_safe_redirect( wp_login_url( self::here() ) );
			exit;
		}
		if ( 'hide' === $decision ) {
			self::not_found();
		}
	}

	/**
	 * Turn the current request into "not found".
	 *
	 * @return void
	 */
	public static function not_found(): void {
		global $wp_query;
		$wp_query->set_404();
		status_header( 404 );
	}

	/**
	 * The address asked for, to come back to after signing in.
	 *
	 * @return string
	 */
	private static function here(): string {
		$path = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '/';
		// The request path already holds any folder the site is in, so only the site's scheme
		// and host are added (home_url() would add the folder twice).
		$home   = (array) wp_parse_url( home_url() );
		$origin = ( $home['scheme'] ?? 'https' ) . '://' . ( $home['host'] ?? '' ) . ( isset( $home['port'] ) ? ':' . $home['port'] : '' );
		return $origin . '/' . ltrim( $path, '/' );
	}

	/**
	 * Whether queries should be narrowed for this request: the public site and REST API, for
	 * people who cannot read (never the import, cron or the command line).
	 *
	 * @return bool
	 */
	private static function narrowing(): bool {
		if ( is_admin() || wp_doing_cron() || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			return false;
		}
		return ! Wormiss_Blog::can_read();
	}

	/**
	 * Leave blog posts out of other listings and searches (query loops, site search, REST
	 * listings) for people who cannot read them.
	 *
	 * @param WP_Query $query Query.
	 * @return void
	 */
	public static function hide_posts( $query ): void {
		if ( ! $query instanceof WP_Query || ! self::narrowing() ) {
			return;
		}
		// Wormiss's own lookups (get_posts, which suppresses filters) are not listings.
		if ( $query->get( 'suppress_filters' ) ) {
			return;
		}
		// A blog page itself is left to guard(): sign in, or "not found".
		if ( $query->is_main_query() && ! $query->is_search() && self::asks_for_blog( $query ) ) {
			return;
		}
		$type = $query->get( 'post_type' );
		if ( 'any' === $type || ( '' === $type && ( $query->is_search() || self::blog_tax_query( $query ) ) ) ) {
			// Every searchable type but the blog (what "any" means, less the blog).
			$type = array_values( array_diff( get_post_types( [ 'exclude_from_search' => false ] ), [ Wormiss_Blog::POST_TYPE ] ) );
			$query->set( 'post_type', $type ? $type : [ 'post' ] );
			if ( self::blog_tax_query( $query ) ) {
				$query->set( 'post__in', [ 0 ] );
			}
			return;
		}
		$types = array_map( 'strval', (array) $type );
		if ( ! in_array( Wormiss_Blog::POST_TYPE, $types, true ) ) {
			return;
		}
		$rest = array_values( array_diff( $types, [ Wormiss_Blog::POST_TYPE ] ) );
		if ( $rest ) {
			$query->set( 'post_type', $rest );
			return;
		}
		$query->set( 'post__in', [ 0 ] );
	}

	/**
	 * Leave comments on blog posts (with their writers' names) out of public comment lists for
	 * people who cannot read the blog: a post's discussion, "Latest comments" and the REST API.
	 * Lists of all statuses (Wormiss's own lookups, moderation) are left alone.
	 *
	 * @param WP_Comment_Query $query Comment query.
	 * @return void
	 */
	public static function hide_comments( $query ): void {
		if ( ! $query instanceof WP_Comment_Query || ! self::narrowing() ) {
			return;
		}
		$status = $query->query_vars['status'] ?? '';
		if ( ! in_array( $status, [ 'approve', 1, '1' ], true ) ) {
			return;
		}
		$not                                    = (array) ( $query->query_vars['post_type__not_in'] ?? [] );
		$query->query_vars['post_type__not_in'] = array_values( array_unique( array_merge( $not, [ Wormiss_Blog::POST_TYPE ] ) ) );
	}

	/**
	 * Whether a query asks for blog posts (by type, category or topic).
	 *
	 * @param WP_Query $query Query.
	 * @return bool
	 */
	private static function asks_for_blog( WP_Query $query ): bool {
		return in_array( Wormiss_Blog::POST_TYPE, array_map( 'strval', (array) $query->get( 'post_type' ) ), true )
			|| self::blog_tax_query( $query );
	}

	/**
	 * Whether a query asks for a blog category or topic.
	 *
	 * @param WP_Query $query Query.
	 * @return bool
	 */
	private static function blog_tax_query( WP_Query $query ): bool {
		foreach ( [ Wormiss_Blog::TAX_CATEGORY, Wormiss_Blog::TAX_TOPIC ] as $taxonomy ) {
			if ( '' !== (string) $query->get( $taxonomy ) ) {
				return true;
			}
		}
		foreach ( (array) $query->get( 'tax_query' ) as $clause ) {
			if ( is_array( $clause ) && in_array( $clause['taxonomy'] ?? '', [ Wormiss_Blog::TAX_CATEGORY, Wormiss_Blog::TAX_TOPIC ], true ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * The REST API's blog posts, categories and topics are for readers only.
	 *
	 * @param mixed           $response Response so far.
	 * @param array           $handler  Route handler.
	 * @param WP_REST_Request $request  Request.
	 * @return mixed
	 */
	public static function rest_guard( $response, $handler, $request ) {
		unset( $handler );
		if ( ! $request instanceof WP_REST_Request || Wormiss_Blog::can_read() ) {
			return $response;
		}
		$route = (string) $request->get_route();
		foreach ( self::rest_bases() as $base ) {
			if ( 1 === preg_match( '#^/wp/v2/' . preg_quote( $base, '#' ) . '(/|$)#', $route ) ) {
				return new WP_Error(
					'wormiss_blog_members_only',
					__( 'The blog is for members.', 'wormiss' ),
					[ 'status' => is_user_logged_in() ? 403 : 401 ]
				);
			}
		}
		return $response;
	}

	/**
	 * The REST API's term search (/wp/v2/search?type=term) leaves out blog categories and
	 * topics for people who cannot read the blog.
	 *
	 * @param array $args get_terms() arguments.
	 * @return array
	 */
	public static function term_search( $args ): array {
		$args = (array) $args;
		if ( Wormiss_Blog::can_read() ) {
			return $args;
		}
		$taxonomies = array_values( array_diff( (array) ( $args['taxonomy'] ?? [] ), [ Wormiss_Blog::TAX_CATEGORY, Wormiss_Blog::TAX_TOPIC ] ) );
		if ( ! $taxonomies ) {
			// Only blog taxonomies were asked for: nothing to find.
			$taxonomies   = [ 'category' ];
			$args['slug'] = [ 'wormiss-no-such-term' ];
		}
		$args['taxonomy'] = $taxonomies;
		return $args;
	}

	/**
	 * The REST bases of the blog post type and its taxonomies.
	 *
	 * @return string[]
	 */
	private static function rest_bases(): array {
		$bases = [];
		$type  = get_post_type_object( Wormiss_Blog::POST_TYPE );
		if ( $type ) {
			$bases[] = is_string( $type->rest_base ) && '' !== $type->rest_base ? $type->rest_base : $type->name;
		}
		foreach ( [ Wormiss_Blog::TAX_CATEGORY, Wormiss_Blog::TAX_TOPIC ] as $name ) {
			$taxonomy = get_taxonomy( $name );
			if ( $taxonomy ) {
				$bases[] = is_string( $taxonomy->rest_base ) && '' !== $taxonomy->rest_base ? $taxonomy->rest_base : $taxonomy->name;
			}
		}
		return $bases;
	}

	/**
	 * No embed of a blog post (title, summary, picture) for people who cannot read it.
	 *
	 * @param int $post_id Post ID.
	 * @return int
	 */
	public static function oembed_post( $post_id ) {
		return Wormiss_Blog::POST_TYPE === get_post_type( (int) $post_id ) && ! Wormiss_Blog::can_read() ? 0 : $post_id;
	}

	/**
	 * The blog left out of the XML sitemaps.
	 *
	 * @param array $types Post types.
	 * @return array
	 */
	public static function sitemap_types( $types ): array {
		$types = (array) $types;
		unset( $types[ Wormiss_Blog::POST_TYPE ] );
		return $types;
	}

	/**
	 * The blog's categories and topics left out of the XML sitemaps.
	 *
	 * @param array $taxonomies Taxonomies.
	 * @return array
	 */
	public static function sitemap_taxonomies( $taxonomies ): array {
		$taxonomies = (array) $taxonomies;
		unset( $taxonomies[ Wormiss_Blog::TAX_CATEGORY ], $taxonomies[ Wormiss_Blog::TAX_TOPIC ] );
		return $taxonomies;
	}
}
