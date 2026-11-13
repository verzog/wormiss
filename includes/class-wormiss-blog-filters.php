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
 * Members' blog filters: a form on the blog's listing pages (category, level, topic,
 * author, published dates, keyword) and the main query narrowed to match.
 *
 * @package    wormiss
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined( 'ABSPATH' ) || exit;

/**
 * Blog filters.
 */
class Wormiss_Blog_Filters {

	/**
	 * The form's fields (query string names).
	 */
	const FIELDS = [ 'blog_category', 'blog_level', 'blog_topic', 'blog_author', 'blog_from', 'blog_to', 'blog_q' ];

	/**
	 * Transient: the blog's authors (user IDs).
	 */
	const AUTHORS = 'wormiss_blog_authors';

	/**
	 * Hook in.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_filter( 'query_vars', [ __CLASS__, 'query_vars' ] );
		add_action( 'pre_get_posts', [ __CLASS__, 'filter_query' ] );
		add_action( 'save_post_' . Wormiss_Blog::POST_TYPE, [ __CLASS__, 'forget_authors' ] );
	}

	/**
	 * The form's fields are query variables.
	 *
	 * @param string[] $vars Query variables.
	 * @return string[]
	 */
	public static function query_vars( $vars ): array {
		return array_merge( (array) $vars, self::FIELDS );
	}

	/**
	 * The choices made, cleaned.
	 *
	 * @return array category, level, topic (slugs), author (user ID), from, to (Y-m-d), q.
	 */
	public static function chosen(): array {
		$date = static function ( string $value ): string {
			$value = trim( $value );
			return 1 === preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ? $value : '';
		};
		return [
			'category' => sanitize_title( (string) get_query_var( 'blog_category' ) ),
			'level'    => sanitize_title( (string) get_query_var( 'blog_level' ) ),
			'topic'    => sanitize_title( (string) get_query_var( 'blog_topic' ) ),
			'author'   => absint( get_query_var( 'blog_author' ) ),
			'from'     => $date( (string) get_query_var( 'blog_from' ) ),
			'to'       => $date( (string) get_query_var( 'blog_to' ) ),
			'q'        => sanitize_text_field( (string) get_query_var( 'blog_q' ) ),
		];
	}

	/**
	 * Narrow the blog's listing pages to the choices.
	 *
	 * @param WP_Query $query Query.
	 * @return void
	 */
	public static function filter_query( $query ): void {
		if ( is_admin() || ! $query instanceof WP_Query || ! $query->is_main_query() ) {
			return;
		}
		if ( ! $query->is_post_type_archive( Wormiss_Blog::POST_TYPE ) && ! $query->is_tax( [ Wormiss_Blog::TAX_CATEGORY, Wormiss_Blog::TAX_TOPIC ] ) ) {
			return;
		}
		$chosen = self::chosen();
		$tax    = [];
		if ( '' !== $chosen['category'] ) {
			$tax[] = [
				'taxonomy' => Wormiss_Blog::TAX_CATEGORY,
				'field'    => 'slug',
				'terms'    => $chosen['category'],
			];
		}
		if ( '' !== $chosen['level'] ) {
			// A level (e.g. All Levels) sits under several categories: every one of that name.
			$tax[] = [
				'taxonomy'         => Wormiss_Blog::TAX_CATEGORY,
				'field'            => 'term_id',
				'terms'            => self::level_ids( $chosen['level'] ),
				'include_children' => false,
			];
		}
		if ( '' !== $chosen['topic'] ) {
			$tax[] = [
				'taxonomy' => Wormiss_Blog::TAX_TOPIC,
				'field'    => 'slug',
				'terms'    => $chosen['topic'],
			];
		}
		if ( $tax ) {
			$query->set( 'tax_query', array_merge( [ 'relation' => 'AND' ], $tax ) ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- The point of the filter.
		}
		if ( $chosen['author'] ) {
			$query->set( 'author', $chosen['author'] );
		}
		$dates = array_filter(
			[
				'after'  => $chosen['from'],
				'before' => $chosen['to'],
			]
		);
		if ( $dates ) {
			$query->set( 'date_query', [ array_merge( $dates, [ 'inclusive' => true ] ) ] );
		}
		if ( '' !== $chosen['q'] ) {
			$query->set( 's', $chosen['q'] );
			add_filter( 'posts_search', [ __CLASS__, 'search_where' ], 10, 2 );
		}
	}

	/**
	 * The levels (subcategories) with the same name as the one chosen.
	 *
	 * @param string $slug Chosen level's slug.
	 * @return int[] Term IDs (0 when there is none, so nothing matches).
	 */
	private static function level_ids( string $slug ): array {
		$chosen = get_term_by( 'slug', $slug, Wormiss_Blog::TAX_CATEGORY );
		if ( ! $chosen instanceof WP_Term ) {
			return [ 0 ];
		}
		$ids = get_terms(
			[
				'taxonomy'   => Wormiss_Blog::TAX_CATEGORY,
				'hide_empty' => false,
				'name'       => $chosen->name,
				'fields'     => 'ids',
			]
		);
		return is_array( $ids ) && $ids ? array_map( 'intval', $ids ) : [ (int) $chosen->term_id ];
	}

	/**
	 * For people who cannot read the blog, a keyword only matches titles and summaries: matching
	 * the members-only text would tell them what it says.
	 *
	 * @param string   $search SQL.
	 * @param WP_Query $query  Query.
	 * @return string
	 */
	public static function search_where( $search, $query ): string {
		global $wpdb;
		if ( '' === (string) $search || ! $query instanceof WP_Query || ! $query->is_main_query() || Wormiss_Blog::can_read() ) {
			return (string) $search;
		}
		$like = '%' . $wpdb->esc_like( (string) $query->get( 's' ) ) . '%';
		return $wpdb->prepare( " AND ({$wpdb->posts}.post_title LIKE %s OR {$wpdb->posts}.post_excerpt LIKE %s) ", $like, $like );
	}

	/**
	 * The blog's authors, by name.
	 *
	 * @return WP_User[]
	 */
	private static function authors(): array {
		global $wpdb;
		$ids = get_transient( self::AUTHORS );
		if ( ! is_array( $ids ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- One small grouped query, kept for a day.
			$ids = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT post_author FROM {$wpdb->posts} WHERE post_type = %s AND post_status = 'publish'", Wormiss_Blog::POST_TYPE ) ) );
			set_transient( self::AUTHORS, $ids, DAY_IN_SECONDS );
		}
		if ( ! $ids ) {
			return [];
		}
		return get_users(
			[
				'include' => $ids,
				'orderby' => 'display_name',
				'fields'  => [ 'ID', 'display_name' ],
			]
		);
	}

	/**
	 * A new or changed blog post may have a new author.
	 *
	 * @return void
	 */
	public static function forget_authors(): void {
		delete_transient( self::AUTHORS );
	}

	/**
	 * A select of terms.
	 *
	 * @param string    $name     Field name.
	 * @param string    $label    Label.
	 * @param string    $any      The "any" choice.
	 * @param WP_Term[] $terms    Terms.
	 * @param string    $selected Chosen slug.
	 * @return string
	 */
	private static function select( string $name, string $label, string $any, array $terms, string $selected ): string {
		$options = '<option value="">' . esc_html( $any ) . '</option>';
		foreach ( $terms as $term ) {
			$options .= '<option value="' . esc_attr( $term->slug ) . '"' . selected( $selected, $term->slug, false ) . '>' . esc_html( $term->name ) . '</option>';
		}
		return self::field( $name, $label, '<select id="wormiss-' . esc_attr( $name ) . '" name="' . esc_attr( $name ) . '">' . $options . '</select>' );
	}

	/**
	 * A labelled field.
	 *
	 * @param string $name  Field name.
	 * @param string $label Label.
	 * @param string $input The input's HTML (escaped).
	 * @return string
	 */
	private static function field( string $name, string $label, string $input ): string {
		return '<p class="wormiss-blog-filters__field"><label for="wormiss-' . esc_attr( $name ) . '">' . esc_html( $label ) . '</label>' . $input . '</p>';
	}

	/**
	 * The form.
	 *
	 * @return string
	 */
	public static function render(): string {
		$action = get_post_type_archive_link( Wormiss_Blog::POST_TYPE );
		if ( ! $action ) {
			return '';
		}
		$chosen     = self::chosen();
		$all        = get_terms(
			[
				'taxonomy'   => Wormiss_Blog::TAX_CATEGORY,
				'hide_empty' => false,
			]
		);
		$all        = is_array( $all ) ? $all : [];
		$categories = array_values( array_filter( $all, static fn( WP_Term $term ): bool => 0 === (int) $term->parent ) );
		$levels     = [];
		foreach ( $all as $term ) {
			// One choice per level name: the same level (e.g. All Levels) sits under several categories.
			if ( $term->parent && ! isset( $levels[ $term->slug ] ) && ! in_array( $term->name, array_map( static fn( WP_Term $t ): string => $t->name, $levels ), true ) ) {
				$levels[ $term->slug ] = $term;
			}
		}
		$topics = get_terms(
			[
				'taxonomy'   => Wormiss_Blog::TAX_TOPIC,
				'hide_empty' => true,
			]
		);
		$topics = is_array( $topics ) ? $topics : [];

		$authors = '<option value="">' . esc_html__( 'Any author', 'wormiss' ) . '</option>';
		foreach ( self::authors() as $user ) {
			$authors .= '<option value="' . esc_attr( (string) $user->ID ) . '"' . selected( $chosen['author'], (int) $user->ID, false ) . '>' . esc_html( $user->display_name ) . '</option>';
		}
		$date = static fn( string $name, string $value ): string => '<input type="date" id="wormiss-' . esc_attr( $name ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '" />';

		$html  = '<form class="wormiss-blog-filters" method="get" action="' . esc_url( $action ) . '" role="search" aria-label="' . esc_attr__( 'Filter blog posts', 'wormiss' ) . '">';
		$html .= self::select( 'blog_category', __( 'Category', 'wormiss' ), __( 'Any category', 'wormiss' ), $categories, $chosen['category'] );
		$html .= self::select( 'blog_level', __( 'Level', 'wormiss' ), __( 'Any level', 'wormiss' ), array_values( $levels ), $chosen['level'] );
		$html .= self::select( 'blog_topic', __( 'Topic', 'wormiss' ), __( 'Any topic', 'wormiss' ), $topics, $chosen['topic'] );
		$html .= self::field( 'blog_author', __( 'Author', 'wormiss' ), '<select id="wormiss-blog_author" name="blog_author">' . $authors . '</select>' );
		$html .= self::field( 'blog_from', __( 'Published from', 'wormiss' ), $date( 'blog_from', $chosen['from'] ) );
		$html .= self::field( 'blog_to', __( 'Published to', 'wormiss' ), $date( 'blog_to', $chosen['to'] ) );
		$html .= self::field( 'blog_q', __( 'Keyword', 'wormiss' ), '<input type="search" id="wormiss-blog_q" name="blog_q" value="' . esc_attr( $chosen['q'] ) . '" />' );
		$html .= '<p class="wormiss-blog-filters__actions"><button type="submit" class="wp-element-button">' . esc_html__( 'Filter', 'wormiss' ) . '</button> '
			. '<a class="wormiss-blog-filters__reset" href="' . esc_url( $action ) . '">' . esc_html__( 'Reset', 'wormiss' ) . '</a></p>';
		return $html . '</form>';
	}
}
