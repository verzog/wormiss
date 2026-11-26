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
 * Site-wide members-only rules: a whole category, tag, post type or page tree made
 * members-only at once, instead of flagging each post. A rule is a match (what
 * content it covers) plus the same access terms Wormiss_Access already uses (allowed
 * iMIS membership types and statuses). Rules are resolved through Wormiss_Access, so
 * the content enforcement, teaser, Members only block, activity log and media gate
 * all honour them with no further change.
 *
 * @package    wormiss
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined( 'ABSPATH' ) || exit;

/**
 * Stores and resolves the site-wide members-only rules.
 */
class Wormiss_Access_Rules {

	/** Option holding the list of rules. */
	const OPTION = 'wormiss_access_rules';

	/** Match kinds, most specific first (a page tree beats a category beats a post type). */
	const KINDS = [ 'page_tree', 'category', 'tag', 'post_type' ];

	/**
	 * The rules read this request (null until first read).
	 *
	 * @var array|null
	 */
	private static ?array $rules = null;

	/**
	 * The resolved access terms per post this request, by post ID.
	 *
	 * @var array
	 */
	private static array $cache = [];

	/**
	 * Forget what was read this request (tests, and after the rules are saved).
	 *
	 * @return void
	 */
	public static function reset(): void {
		self::$rules = null;
		self::$cache = [];
	}

	/**
	 * Every stored rule, well-formed, read once per request.
	 *
	 * @return array[] Each {kind: string, ref: int|string, types: string[], statuses: string[]}.
	 */
	public static function all(): array {
		if ( null === self::$rules ) {
			$stored      = get_option( self::OPTION, [] );
			self::$rules = self::sanitize( is_array( $stored ) ? $stored : [] );
		}
		return self::$rules;
	}

	/**
	 * The access terms a post inherits from the strongest matching site rule, or null if no rule
	 * matches it. Shape matches Wormiss_Access::post_rules(): {types: string[], statuses: string[]}.
	 *
	 * @param int $post_id Post ID.
	 * @return array|null
	 */
	public static function rule_for_post( int $post_id ): ?array {
		if ( $post_id < 1 ) {
			return null;
		}
		if ( array_key_exists( $post_id, self::$cache ) ) {
			return self::$cache[ $post_id ];
		}
		$best      = null;
		$best_rank = -1;
		foreach ( self::all() as $rule ) {
			if ( ! self::matches( $rule, $post_id ) ) {
				continue;
			}
			// A lower index in KINDS is more specific; invert so a larger number is stronger.
			$index    = array_search( $rule['kind'], self::KINDS, true );
			$strength = count( self::KINDS ) - ( false === $index ? count( self::KINDS ) : (int) $index );
			if ( $strength > $best_rank ) {
				$best_rank = $strength;
				$best      = [
					'types'    => $rule['types'],
					'statuses' => $rule['statuses'],
				];
			}
		}
		self::$cache[ $post_id ] = $best;
		return $best;
	}

	/**
	 * Whether a rule covers a post.
	 *
	 * @param array $rule    A rule from all().
	 * @param int   $post_id Post ID.
	 * @return bool
	 */
	private static function matches( array $rule, int $post_id ): bool {
		switch ( $rule['kind'] ) {
			case 'post_type':
				return get_post_type( $post_id ) === $rule['ref'];
			case 'category':
				return self::in_term( $post_id, 'category', (int) $rule['ref'] );
			case 'tag':
				return self::in_term( $post_id, 'post_tag', (int) $rule['ref'] );
			case 'page_tree':
				$ref = (int) $rule['ref'];
				return $post_id === $ref || in_array( $ref, array_map( 'intval', get_post_ancestors( $post_id ) ), true );
			default:
				return false;
		}
	}

	/**
	 * Whether a post is in a term or any of its descendants.
	 *
	 * @param int    $post_id  Post ID.
	 * @param string $taxonomy Taxonomy.
	 * @param int    $term_id  Term ID.
	 * @return bool
	 */
	private static function in_term( int $post_id, string $taxonomy, int $term_id ): bool {
		if ( $term_id < 1 ) {
			return false;
		}
		$terms = [ $term_id ];
		if ( is_taxonomy_hierarchical( $taxonomy ) ) {
			$children = get_term_children( $term_id, $taxonomy );
			if ( is_array( $children ) ) {
				$terms = array_merge( $terms, array_map( 'intval', $children ) );
			}
		}
		return (bool) has_term( $terms, $taxonomy, $post_id );
	}

	/**
	 * Clean a list of submitted rows into well-formed rules, dropping any that are empty or point at
	 * content that no longer exists.
	 *
	 * @param array $raw Rows (each with kind, ref, types, statuses).
	 * @return array[]
	 */
	public static function sanitize( array $raw ): array {
		$clean = [];
		foreach ( $raw as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$kind = isset( $row['kind'] ) ? sanitize_key( (string) $row['kind'] ) : '';
			if ( ! in_array( $kind, self::KINDS, true ) ) {
				continue;
			}
			$ref = self::clean_ref( $kind, $row['ref'] ?? '' );
			if ( null === $ref ) {
				continue;
			}
			$clean[] = [
				'kind'     => $kind,
				'ref'      => $ref,
				'types'    => self::to_list( $row['types'] ?? '' ),
				'statuses' => self::to_list( $row['statuses'] ?? '' ),
			];
		}
		return $clean;
	}

	/**
	 * A comma-separated string or an array of terms as a clean list. Tolerant of both so that
	 * sanitize() is idempotent: it runs on form rows (strings) and again on stored rules (arrays).
	 *
	 * @param mixed $value A comma-separated string or an array.
	 * @return string[]
	 */
	private static function to_list( $value ): array {
		if ( is_array( $value ) ) {
			$value = implode( ',', array_map( 'strval', $value ) );
		}
		return Wormiss_Settings::parse_list( (string) $value );
	}

	/**
	 * A rule's match reference, validated against what exists, or null when it is invalid.
	 *
	 * @param string $kind Match kind.
	 * @param mixed  $ref  Submitted reference (term/page ID, or a post-type slug).
	 * @return int|string|null
	 */
	private static function clean_ref( string $kind, $ref ) {
		if ( 'post_type' === $kind ) {
			$slug = sanitize_key( (string) $ref );
			return post_type_exists( $slug ) ? $slug : null;
		}
		$id = (int) $ref;
		if ( $id < 1 ) {
			return null;
		}
		if ( 'page_tree' === $kind ) {
			return get_post( $id ) instanceof WP_Post ? $id : null;
		}
		$taxonomy = 'category' === $kind ? 'category' : 'post_tag';
		return get_term( $id, $taxonomy ) instanceof WP_Term ? $id : null;
	}

	/**
	 * Save a submitted set of rows as the rules.
	 *
	 * @param array $raw Rows from the admin form.
	 * @return void
	 */
	public static function save( array $raw ): void {
		update_option( self::OPTION, self::sanitize( $raw ), false );
		self::reset();
	}

	/**
	 * A human-readable label for a rule's match (for the admin table and audit).
	 *
	 * @param array $rule A rule from all().
	 * @return string
	 */
	public static function label( array $rule ): string {
		switch ( $rule['kind'] ) {
			case 'post_type':
				$obj = get_post_type_object( (string) $rule['ref'] );
				/* translators: %s: post type name. */
				return sprintf( __( 'All %s', 'wormiss' ), $obj ? $obj->labels->name : (string) $rule['ref'] );
			case 'category':
				$term = get_term( (int) $rule['ref'], 'category' );
				/* translators: %s: category name. */
				return sprintf( __( 'Category: %s (and sub-categories)', 'wormiss' ), $term instanceof WP_Term ? $term->name : (string) $rule['ref'] );
			case 'tag':
				$term = get_term( (int) $rule['ref'], 'post_tag' );
				/* translators: %s: tag name. */
				return sprintf( __( 'Tag: %s', 'wormiss' ), $term instanceof WP_Term ? $term->name : (string) $rule['ref'] );
			case 'page_tree':
				$page = get_post( (int) $rule['ref'] );
				/* translators: %s: page title. */
				return sprintf( __( 'Page and its children: %s', 'wormiss' ), $page instanceof WP_Post ? get_the_title( $page ) : (string) $rule['ref'] );
			default:
				return '';
		}
	}

	/**
	 * Encode a rule's match as a single "kind:ref" token (for the admin dropdown).
	 *
	 * @param string     $kind Match kind.
	 * @param int|string $ref  Reference.
	 * @return string
	 */
	public static function token( string $kind, $ref ): string {
		return $kind . ':' . $ref;
	}

	/**
	 * Split a "kind:ref" token back into {kind, ref}, or null if malformed.
	 *
	 * @param string $token Token.
	 * @return array|null
	 */
	public static function parse_token( string $token ): ?array {
		$parts = explode( ':', $token, 2 );
		if ( 2 !== count( $parts ) || '' === $parts[1] ) {
			return null;
		}
		return [
			'kind' => $parts[0],
			'ref'  => $parts[1],
		];
	}
}
