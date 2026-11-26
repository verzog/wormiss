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
 * Media access: the department x category visibility gate (media spec phase 2).
 *
 * Who may see and use which images while editing is controlled by a department x category matrix.
 * Each department (Wormiss_Roles) is allowed a set of media categories; a non-administrator's
 * effective visibility is: images they uploaded themselves, plus images in any category allowed to
 * a department they belong to. Administrators are exempt and see everything.
 *
 * The gate is enforced on every surface that lists or serves attachments in the editor/library, or
 * leaks: the "Add media" modal and the Media grid, the Media Library list view, the REST media
 * endpoint, and per-image edit/read/delete capability checks.
 *
 * This governs visibility and usability in the editor/library only. It does NOT make a file's raw
 * public URL unreachable; that is byte-level protected delivery, a later phase.
 *
 * @package    wormiss
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined( 'ABSPATH' ) || exit;

/**
 * Enforces the department x category media gate.
 */
class Wormiss_Media_Access {

	/** Option holding the matrix: department slug => list of allowed category term IDs. */
	const MATRIX_OPTION = 'wormiss_media_matrix';

	/** Option holding the per-department category term IDs: department slug => term ID. */
	const DEPARTMENT_TERMS_OPTION = 'wormiss_media_department_terms';

	/** Query var that marks a query for gating (its value is the user ID to gate for). */
	const QUERY_FLAG = 'wormiss_media_gate';

	/**
	 * Per-request cache of allowed term IDs, keyed by user ID.
	 *
	 * @var array<int, int[]>
	 */
	private static array $allowed_cache = [];

	/**
	 * Hook in.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'admin_init', [ __CLASS__, 'maybe_install' ] );
		// The surfaces that list attachments.
		add_filter( 'ajax_query_attachments_args', [ __CLASS__, 'gate_ajax_args' ], 20 );
		add_filter( 'rest_attachment_query', [ __CLASS__, 'gate_rest_args' ], 20, 2 );
		add_action( 'pre_get_posts', [ __CLASS__, 'gate_list_query' ], 20 );
		add_filter( 'posts_where', [ __CLASS__, 'where' ], 10, 2 );
		// Per-image edit/read/delete.
		add_filter( 'map_meta_cap', [ __CLASS__, 'map_cap' ], 10, 4 );
		// Single-item reads bypass the collection filters and map_meta_cap: wp_ajax_get_attachment
		// checks only upload_files, and the REST read-permission check treats an inherit attachment
		// as public. Gate the individual AJAX and REST representations too.
		add_filter( 'wp_prepare_attachment_for_js', [ __CLASS__, 'gate_js_item' ], 20, 2 );
		add_filter( 'rest_pre_dispatch', [ __CLASS__, 'gate_rest_read' ], 10, 3 );
	}

	/**
	 * Create the per-department categories and a default matrix if they are missing (on activation,
	 * and once per admin load after an update).
	 *
	 * @return void
	 */
	public static function maybe_install(): void {
		if ( ! get_option( self::DEPARTMENT_TERMS_OPTION ) ) {
			self::install();
		}
	}

	/**
	 * Create a top-level media category per department and default the matrix so each department is
	 * allowed its own category. Idempotent: existing terms (by stored ID) are reused.
	 *
	 * @return void
	 */
	public static function install(): void {
		if ( ! taxonomy_exists( Wormiss_Media_Categories::TAXONOMY ) ) {
			Wormiss_Media_Categories::register();
		}
		$terms = get_option( self::DEPARTMENT_TERMS_OPTION );
		$terms = is_array( $terms ) ? $terms : [];
		foreach ( Wormiss_Roles::departments() as $slug => $label ) {
			if ( isset( $terms[ $slug ] ) && get_term( (int) $terms[ $slug ], Wormiss_Media_Categories::TAXONOMY ) instanceof WP_Term ) {
				continue;
			}
			$existing = get_term_by( 'name', $label, Wormiss_Media_Categories::TAXONOMY );
			if ( $existing instanceof WP_Term && 0 === (int) $existing->parent ) {
				$terms[ $slug ] = (int) $existing->term_id;
				continue;
			}
			$result = wp_insert_term( $label, Wormiss_Media_Categories::TAXONOMY, [ 'parent' => 0 ] );
			if ( ! is_wp_error( $result ) ) {
				$terms[ $slug ] = (int) $result['term_id'];
			}
		}
		update_option( self::DEPARTMENT_TERMS_OPTION, $terms, false );

		if ( ! is_array( get_option( self::MATRIX_OPTION ) ) ) {
			$matrix = [];
			foreach ( $terms as $slug => $term_id ) {
				$matrix[ $slug ] = [ (int) $term_id ];
			}
			update_option( self::MATRIX_OPTION, $matrix, false );
		}
	}

	/**
	 * Remove the matrix and department-term options (for uninstall).
	 *
	 * @return void
	 */
	public static function uninstall(): void {
		delete_option( self::MATRIX_OPTION );
		delete_option( self::DEPARTMENT_TERMS_OPTION );
	}

	/**
	 * The matrix (department slug => allowed category term IDs).
	 *
	 * @return array<string, int[]>
	 */
	public static function matrix(): array {
		$matrix = get_option( self::MATRIX_OPTION );
		if ( ! is_array( $matrix ) ) {
			return [];
		}
		$clean = [];
		foreach ( $matrix as $slug => $ids ) {
			if ( Wormiss_Roles::is_department( (string) $slug ) && is_array( $ids ) ) {
				$clean[ (string) $slug ] = array_values( array_unique( array_map( 'intval', $ids ) ) );
			}
		}
		return $clean;
	}

	/**
	 * Whether a user is exempt from the gate (administrators see everything).
	 *
	 * @param int $user_id User ID.
	 * @return bool
	 */
	public static function is_exempt( int $user_id ): bool {
		return $user_id > 0 && user_can( $user_id, 'manage_options' );
	}

	/**
	 * The category term IDs a user is allowed, across their departments, including sub-categories.
	 *
	 * @param int $user_id User ID.
	 * @return int[]
	 */
	public static function allowed_term_ids( int $user_id ): array {
		if ( isset( self::$allowed_cache[ $user_id ] ) ) {
			return self::$allowed_cache[ $user_id ];
		}
		$matrix = self::matrix();
		$ids    = [];
		foreach ( Wormiss_Roles::user_departments( $user_id ) as $department ) {
			if ( ! empty( $matrix[ $department ] ) ) {
				$ids = array_merge( $ids, $matrix[ $department ] );
			}
		}
		$expanded = [];
		foreach ( array_unique( array_map( 'intval', $ids ) ) as $id ) {
			if ( $id > 0 && get_term( $id, Wormiss_Media_Categories::TAXONOMY ) instanceof WP_Term ) {
				$expanded[ $id ] = true;
				foreach ( (array) get_term_children( $id, Wormiss_Media_Categories::TAXONOMY ) as $child ) {
					$expanded[ (int) $child ] = true;
				}
			}
		}
		$result                          = array_values( array_map( 'intval', array_keys( $expanded ) ) );
		self::$allowed_cache[ $user_id ] = $result;
		return $result;
	}

	/**
	 * Whether a user may see a given attachment: they uploaded it, or it is in a category they are
	 * allowed. Administrators (exempt) always may.
	 *
	 * @param int $user_id    User ID.
	 * @param int $attachment Attachment ID.
	 * @return bool
	 */
	public static function user_can_see( int $user_id, int $attachment ): bool {
		if ( self::is_exempt( $user_id ) ) {
			return true;
		}
		$post = get_post( $attachment );
		if ( ! $post instanceof WP_Post ) {
			return false;
		}
		if ( (int) $post->post_author === $user_id && $user_id > 0 ) {
			return true;
		}
		$terms = wp_get_object_terms( $attachment, Wormiss_Media_Categories::TAXONOMY, [ 'fields' => 'ids' ] );
		$terms = is_wp_error( $terms ) ? [] : array_map( 'intval', $terms );
		if ( ! $terms ) {
			// Uncategorised: visible only if the user's department allows uncategorised images.
			return self::sees_uncategorised( $user_id );
		}
		return (bool) array_intersect( self::allowed_term_ids( $user_id ), $terms );
	}

	/**
	 * Whether a user may see uncategorised images (ones with no media category), per the
	 * uncategorised-visibility setting. Their department must be listed; the uploader and admins can
	 * always see their own/all regardless (handled in user_can_see() and the gate).
	 *
	 * @param int $user_id User ID.
	 * @return bool
	 */
	public static function sees_uncategorised( int $user_id ): bool {
		$allowed = self::uncategorised_departments();
		if ( ! $allowed ) {
			return false;
		}
		return (bool) array_intersect( $allowed, Wormiss_Roles::user_departments( $user_id ) );
	}

	/**
	 * The departments whose members may also see uncategorised images.
	 *
	 * @return string[]
	 */
	public static function uncategorised_departments(): array {
		$chosen = (array) Wormiss_Settings::get( 'media_uncategorised_departments' );
		return array_values( array_intersect( array_map( 'strval', $chosen ), array_keys( Wormiss_Roles::departments() ) ) );
	}

	/**
	 * Clean a submitted matrix (department slug => allowed category term IDs): keep known
	 * departments and term IDs that exist in the media taxonomy.
	 *
	 * @param mixed $raw Submitted matrix.
	 * @return array<string, int[]>
	 */
	public static function sanitize_matrix( $raw ): array {
		$raw   = is_array( $raw ) ? $raw : [];
		$clean = [];
		foreach ( Wormiss_Roles::departments() as $slug => $label ) {
			$ids  = isset( $raw[ $slug ] ) && is_array( $raw[ $slug ] ) ? array_map( 'intval', $raw[ $slug ] ) : [];
			$kept = [];
			foreach ( array_unique( $ids ) as $id ) {
				if ( $id > 0 && get_term( $id, Wormiss_Media_Categories::TAXONOMY ) instanceof WP_Term ) {
					$kept[] = $id;
				}
			}
			if ( $kept ) {
				$clean[ $slug ] = $kept;
			}
		}
		return $clean;
	}

	/**
	 * Save the department x category matrix.
	 *
	 * @param mixed $raw Submitted matrix.
	 * @return void
	 */
	public static function save_matrix( $raw ): void {
		update_option( self::MATRIX_OPTION, self::sanitize_matrix( $raw ), false );
		self::$allowed_cache = [];
	}

	/**
	 * Mark the media modal and grid query for gating.
	 *
	 * @param array $args WP_Query arguments.
	 * @return array
	 */
	public static function gate_ajax_args( $args ) {
		$args = is_array( $args ) ? $args : [];
		$user = get_current_user_id();
		if ( $user > 0 && ! self::is_exempt( $user ) ) {
			$args[ self::QUERY_FLAG ] = $user;
		}
		return $args;
	}

	/**
	 * Mark the REST media query for gating.
	 *
	 * @param array           $args    WP_Query arguments.
	 * @param WP_REST_Request $request The REST request.
	 * @return array
	 */
	public static function gate_rest_args( $args, $request ) {
		unset( $request );
		$args = is_array( $args ) ? $args : [];
		$user = get_current_user_id();
		if ( $user > 0 && ! self::is_exempt( $user ) ) {
			$args[ self::QUERY_FLAG ] = $user;
		}
		return $args;
	}

	/**
	 * Mark the Media Library list view's main query for gating.
	 *
	 * @param WP_Query $query The query.
	 * @return void
	 */
	public static function gate_list_query( $query ): void {
		if ( ! is_admin() || ! $query instanceof WP_Query || ! $query->is_main_query() ) {
			return;
		}
		if ( 'attachment' !== $query->get( 'post_type' ) ) {
			return;
		}
		$user = get_current_user_id();
		if ( $user > 0 && ! self::is_exempt( $user ) ) {
			$query->set( self::QUERY_FLAG, $user );
		}
	}

	/**
	 * Add the visibility condition to a gated query: the author, OR an image in an allowed category.
	 *
	 * @param string   $where The WHERE clause.
	 * @param WP_Query $query The query.
	 * @return string
	 */
	public static function where( $where, $query ) {
		global $wpdb;
		if ( ! $query instanceof WP_Query ) {
			return $where;
		}
		$user = (int) $query->get( self::QUERY_FLAG );
		if ( $user < 1 || self::is_exempt( $user ) ) {
			return $where;
		}
		// Always: the user's own uploads.
		$parts = [ $wpdb->prepare( "{$wpdb->posts}.post_author = %d", $user ) ];

		$allowed = self::allowed_term_ids( $user );
		if ( $allowed ) {
			$placeholders = implode( ', ', array_fill( 0, count( $allowed ), '%d' ) );
			$params       = array_merge( [ Wormiss_Media_Categories::TAXONOMY ], $allowed );
			// A variable-length IN list of %d placeholders; the taxonomy and every ID are passed to
			// prepare(), so the interpolated $placeholders holds no data, only markers.
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
			$parts[] = $wpdb->prepare(
				"{$wpdb->posts}.ID IN (
					SELECT tr.object_id FROM {$wpdb->term_relationships} tr
					INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
					WHERE tt.taxonomy = %s AND tt.term_id IN ( {$placeholders} )
				)",
				$params
			);
			// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
		}

		if ( self::sees_uncategorised( $user ) ) {
			// Images with no media category at all.
			$parts[] = $wpdb->prepare(
				"{$wpdb->posts}.ID NOT IN (
					SELECT tr.object_id FROM {$wpdb->term_relationships} tr
					INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
					WHERE tt.taxonomy = %s
				)",
				Wormiss_Media_Categories::TAXONOMY
			);
		}

		return $where . ' AND ( ' . implode( ' OR ', $parts ) . ' )';
	}

	/**
	 * Deny editing, reading or deleting an attachment the user may not see.
	 *
	 * @param string[] $caps    The required primitive capabilities.
	 * @param string   $cap     The capability being checked.
	 * @param int      $user_id The user.
	 * @param array    $args    Context ($args[0] is the post ID).
	 * @return string[]
	 */
	public static function map_cap( $caps, $cap, $user_id, $args ) {
		if ( ! in_array( $cap, [ 'edit_post', 'read_post', 'delete_post' ], true ) || empty( $args[0] ) ) {
			return $caps;
		}
		$post_id = (int) $args[0];
		if ( 'attachment' !== get_post_type( $post_id ) ) {
			return $caps;
		}
		$user_id = (int) $user_id;
		if ( $user_id < 1 || self::is_exempt( $user_id ) ) {
			return $caps;
		}
		if ( ! self::user_can_see( $user_id, $post_id ) ) {
			return [ 'do_not_allow' ];
		}
		return $caps;
	}

	/**
	 * Suppress an attachment's JavaScript representation (used by the single-item get-attachment AJAX
	 * handler, which checks only upload_files) when the current user may not see it.
	 *
	 * @param array   $response   The prepared attachment data.
	 * @param WP_Post $attachment The attachment.
	 * @return array
	 */
	public static function gate_js_item( $response, $attachment ) {
		$user = get_current_user_id();
		if ( $user < 1 || self::is_exempt( $user ) ) {
			return $response;
		}
		$id = $attachment instanceof WP_Post ? (int) $attachment->ID : 0;
		if ( $id > 0 && ! self::user_can_see( $user, $id ) ) {
			// Empty: wp_ajax_get_attachment then returns an error and the item is not shown.
			return [];
		}
		return $response;
	}

	/**
	 * Deny a REST read of a single media item the current user may not see, before the request is
	 * dispatched (the read-permission check treats an inherit attachment as public, and denying in
	 * the prepare filter would still let the controller append metadata). Reads only; writes go
	 * through the edit/delete capabilities. Anonymous requests are not gated: public reachability of
	 * the raw file is a separate (later) concern.
	 *
	 * @param mixed           $result  Dispatch result (non-null short-circuits).
	 * @param WP_REST_Server  $server  The REST server.
	 * @param WP_REST_Request $request The request.
	 * @return mixed
	 */
	public static function gate_rest_read( $result, $server, $request ) {
		unset( $server );
		if ( null !== $result || ! $request instanceof WP_REST_Request || 'GET' !== $request->get_method() ) {
			return $result;
		}
		if ( ! preg_match( '#^/wp/v2/media/(\d+)$#', $request->get_route(), $matches ) ) {
			return $result;
		}
		$user = get_current_user_id();
		if ( $user < 1 || self::is_exempt( $user ) ) {
			return $result;
		}
		$id = (int) $matches[1];
		if ( 'attachment' === get_post_type( $id ) && ! self::user_can_see( $user, $id ) ) {
			return new WP_Error(
				'wormiss_media_forbidden',
				__( 'You are not allowed to view this media item.', 'wormiss' ),
				[ 'status' => 403 ]
			);
		}
		return $result;
	}
}
