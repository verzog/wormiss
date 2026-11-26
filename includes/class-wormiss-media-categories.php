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
 * Media categories: a hierarchical taxonomy on attachments, a reserved "End-user images" parent,
 * a category picker in the attachment details, a category filter in the media modal and grid, and
 * auto-filing of non-staff uploads.
 *
 * This is phase one of the media-access feature (docs/media-access-spec.md): the buckets and the
 * tools to put images in them. The role x category visibility gate across every surface, the admin
 * matrix and protected byte-level delivery come in later phases; nothing here restricts who may see
 * or use an image.
 *
 * @package    wormiss
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined( 'ABSPATH' ) || exit;

/**
 * Registers the media-category taxonomy and the phase-one tools around it.
 */
class Wormiss_Media_Categories {

	/**
	 * The taxonomy name.
	 */
	const TAXONOMY = 'wormiss_media_cat';

	/**
	 * The reserved parent term's slug ("End-user images").
	 */
	const RESERVED_SLUG = 'end-user-images';

	/**
	 * Option holding the reserved parent term's ID.
	 */
	const RESERVED_OPTION = 'wormiss_media_enduser_term';

	/**
	 * The attachment-details field name for the category picker. Deliberately NOT the taxonomy name:
	 * core's compat save (wp_ajax_save_attachment_compat) runs preg_split() on a field named after an
	 * attachment taxonomy, which would fatal on the posted array, so the picker uses its own key.
	 */
	const PICKER_FIELD = 'wormiss_media_categories';

	/**
	 * Hook in.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'init', [ __CLASS__, 'register' ] );
		add_action( 'admin_init', [ __CLASS__, 'maybe_install' ] );
		// Non-staff uploads are filed into "End-user images" as they are added.
		add_action( 'add_attachment', [ __CLASS__, 'auto_file' ] );
		// The reserved parent cannot be deleted (in the term UI, bulk actions or the REST API).
		add_filter( 'map_meta_cap', [ __CLASS__, 'protect_reserved' ], 10, 4 );
		// The category picker in the attachment details, and saving it.
		add_filter( 'attachment_fields_to_edit', [ __CLASS__, 'picker_field' ], 10, 2 );
		add_filter( 'attachment_fields_to_save', [ __CLASS__, 'save_picker' ], 10, 2 );
		// The category filter in the media modal and grid.
		add_action( 'wp_enqueue_media', [ __CLASS__, 'enqueue_filter' ] );
		add_filter( 'ajax_query_attachments_args', [ __CLASS__, 'filter_query' ] );
	}

	/**
	 * Register the taxonomy on attachments.
	 *
	 * @return void
	 */
	public static function register(): void {
		register_taxonomy(
			self::TAXONOMY,
			'attachment',
			[
				'labels'                => [
					'name'          => __( 'Media categories', 'wormiss' ),
					'singular_name' => __( 'Media category', 'wormiss' ),
					'add_new_item'  => __( 'Add new media category', 'wormiss' ),
					'edit_item'     => __( 'Edit media category', 'wormiss' ),
					'search_items'  => __( 'Search media categories', 'wormiss' ),
					'not_found'     => __( 'No media categories found.', 'wormiss' ),
					'all_items'     => __( 'All media categories', 'wormiss' ),
					'parent_item'   => __( 'Parent media category', 'wormiss' ),
					'menu_name'     => __( 'Media categories', 'wormiss' ),
				],
				// Hierarchical: curated buckets with sub-categories (e.g. Event photos > 2026).
				'hierarchical'          => true,
				// No public front-end archive: this organises the library, it is not a content taxonomy.
				'public'                => false,
				'show_ui'               => true,
				'show_in_menu'          => true,
				'show_in_rest'          => true,
				'show_admin_column'     => true,
				'show_in_nav_menus'     => false,
				'rewrite'               => false,
				// Attachments are stored as "inherit", so the standard term count would always be 0.
				'update_count_callback' => '_update_generic_term_count',
			]
		);
	}

	/**
	 * Create the reserved parent term if it is missing (runs once per admin load after install or
	 * an update, since activation does not run on an update).
	 *
	 * @return void
	 */
	public static function maybe_install(): void {
		if ( (int) get_option( self::RESERVED_OPTION, 0 ) < 1 ) {
			self::ensure_reserved_term();
		}
	}

	/**
	 * The reserved "End-user images" term's ID, creating it if need be.
	 *
	 * @return int
	 */
	public static function reserved_term_id(): int {
		$id = (int) get_option( self::RESERVED_OPTION, 0 );
		if ( $id > 0 && get_term( $id, self::TAXONOMY ) instanceof WP_Term ) {
			return $id;
		}
		return self::ensure_reserved_term();
	}

	/**
	 * Create the reserved "End-user images" parent term (and remember its ID), or find it if it is
	 * already there. Registers the taxonomy first, so it also works during activation.
	 *
	 * @return int The term ID, or 0 if it could not be made.
	 */
	public static function ensure_reserved_term(): int {
		if ( ! taxonomy_exists( self::TAXONOMY ) ) {
			self::register();
		}
		$term = get_term_by( 'slug', self::RESERVED_SLUG, self::TAXONOMY );
		if ( $term instanceof WP_Term ) {
			$id = (int) $term->term_id;
		} else {
			$result = wp_insert_term(
				__( 'End-user images', 'wormiss' ),
				self::TAXONOMY,
				[
					'slug'   => self::RESERVED_SLUG,
					'parent' => 0,
				]
			);
			if ( is_wp_error( $result ) ) {
				// A term with that name already existed (or a race): look it up by slug again.
				$term = get_term_by( 'slug', self::RESERVED_SLUG, self::TAXONOMY );
				$id   = $term instanceof WP_Term ? (int) $term->term_id : 0;
			} else {
				$id = (int) $result['term_id'];
			}
		}
		if ( $id > 0 ) {
			update_option( self::RESERVED_OPTION, $id, false );
		}
		return $id;
	}

	/**
	 * Whether a term is the reserved "End-user images" parent.
	 *
	 * @param int $term_id Term ID.
	 * @return bool
	 */
	public static function is_reserved( int $term_id ): bool {
		return $term_id > 0 && self::reserved_term_id() === $term_id;
	}

	/**
	 * The roles that count as staff: the ones chosen in Settings, or (by default) administrators and
	 * a dedicated "staff" role. Internal team roles (e.g. Marketing, Membership, Education,
	 * Management) can be added to the setting once they exist. Administrators are always staff
	 * whether or not they are in the list (see is_staff()).
	 *
	 * @return string[] Role slugs.
	 */
	public static function staff_roles(): array {
		$roles = array_values( array_filter( array_map( 'strval', (array) Wormiss_Settings::get( 'media_staff_roles' ) ) ) );
		if ( ! $roles ) {
			$roles = [ 'administrator', 'staff' ];
		}
		/**
		 * The roles that count as staff for media categories (whose uploads are not auto-filed).
		 *
		 * @param string[] $roles Role slugs.
		 */
		$roles = (array) apply_filters( 'wormiss_media_staff_roles', $roles );
		return array_values( array_unique( array_map( 'strval', $roles ) ) );
	}

	/**
	 * Whether a user is staff (so their uploads are not auto-filed). Administrators always are.
	 *
	 * @param int|WP_User|null $user User, ID, or null for the current user.
	 * @return bool
	 */
	public static function is_staff( $user = null ): bool {
		if ( $user instanceof WP_User ) {
			$object = $user;
		} elseif ( $user ) {
			$object = get_userdata( (int) $user );
		} else {
			$object = wp_get_current_user();
		}
		if ( ! $object instanceof WP_User || ! $object->exists() ) {
			return false;
		}
		// Administrators are exempt from the whole feature, so they are always staff.
		if ( user_can( $object, 'manage_options' ) ) {
			return true;
		}
		return (bool) array_intersect( self::staff_roles(), array_map( 'strval', (array) $object->roles ) );
	}

	/**
	 * File a non-staff user's upload into the reserved "End-user images" category.
	 *
	 * Only interactive uploads by a signed-in non-staff user are filed; system imports (which run
	 * with no current user) are left alone, so imported images are not swept into the bucket.
	 *
	 * @param int $post_id Attachment ID.
	 * @return void
	 */
	public static function auto_file( $post_id ): void {
		$post_id = (int) $post_id;
		// No current user means a system/import context, not a user's own upload.
		if ( get_current_user_id() < 1 || self::is_staff() ) {
			return;
		}
		if ( 'attachment' !== get_post_type( $post_id ) ) {
			return;
		}
		$term_id = self::reserved_term_id();
		if ( $term_id > 0 ) {
			// Append, so any category set in the same request is kept.
			wp_set_object_terms( $post_id, [ $term_id ], self::TAXONOMY, true );
		}
	}

	/**
	 * Deny the capability to delete the reserved "End-user images" parent, so it cannot be removed
	 * from the term list, by a bulk action or through the REST API. Looks only at the stored term
	 * ID (no term is created from inside a capability check).
	 *
	 * @param string[] $caps    The required primitive capabilities.
	 * @param string   $cap     The capability being checked.
	 * @param int      $user_id The user.
	 * @param array    $args    Context ($args[0] is the term ID for delete_term).
	 * @return string[]
	 */
	public static function protect_reserved( $caps, $cap, $user_id, $args ) {
		if ( 'delete_term' !== $cap || ! isset( $args[0] ) ) {
			return $caps;
		}
		$reserved = (int) get_option( self::RESERVED_OPTION, 0 );
		if ( $reserved > 0 && (int) $args[0] === $reserved ) {
			return [ 'do_not_allow' ];
		}
		return $caps;
	}

	/**
	 * The categories as an indented, hierarchical list for a dropdown (parents first, each one's
	 * children under it, sub-categories prefixed with em dashes).
	 *
	 * @return array<int, array{id: int, name: string}>
	 */
	public static function term_choices(): array {
		$out = [];
		foreach ( self::nested_terms() as $row ) {
			$out[] = [
				'id'   => (int) $row['id'],
				'name' => str_repeat( '— ', (int) $row['depth'] ) . $row['name'],
			];
		}
		return $out;
	}

	/**
	 * Add a media-category picker to the attachment details (a checkbox list of the categories).
	 *
	 * @param array   $fields Attachment fields.
	 * @param WP_Post $post   The attachment.
	 * @return array
	 */
	public static function picker_field( $fields, $post ) {
		$taxonomy = get_taxonomy( self::TAXONOMY );
		// phpcs:ignore WordPress.WP.Capabilities.Undetermined -- The taxonomy's own "assign terms" capability.
		if ( ! $taxonomy instanceof WP_Taxonomy || ! current_user_can( $taxonomy->cap->assign_terms ) ) {
			return $fields;
		}
		$fields   = is_array( $fields ) ? $fields : [];
		$post_id  = (int) ( $post->ID ?? 0 );
		$selected = wp_get_object_terms( $post_id, self::TAXONOMY, [ 'fields' => 'ids' ] );
		$selected = is_wp_error( $selected ) ? [] : array_map( 'intval', $selected );
		$base     = 'attachments[' . $post_id . '][' . self::PICKER_FIELD . ']';
		// A hidden marker so a save with every box unticked still clears the categories.
		$html = '<input type="hidden" name="attachments[' . $post_id . '][' . self::PICKER_FIELD . '_present]" value="1" />';
		$rows = self::nested_terms();
		if ( ! $rows ) {
			$html .= '<span class="description">' . esc_html__( 'No media categories yet.', 'wormiss' ) . '</span>';
		}
		foreach ( $rows as $row ) {
			$id = (int) $row['id'];
			// Each checkbox has its own indexed name so the media modal's serializeArray() (which
			// collapses duplicate names) keeps every ticked category, not just the last.
			$name  = $base . '[' . $id . ']';
			$html .= '<label style="display:block;margin:2px 0 2px ' . ( (int) $row['depth'] * 16 ) . 'px;">';
			$html .= '<input type="checkbox" name="' . esc_attr( $name ) . '" value="' . $id . '"'
				. checked( in_array( $id, $selected, true ), true, false ) . ' /> ';
			$html .= esc_html( (string) $row['name'] ) . '</label>';
		}
		$fields[ self::PICKER_FIELD ] = [
			'label' => __( 'Media categories', 'wormiss' ),
			'input' => 'html',
			'html'  => $html,
		];
		return $fields;
	}

	/**
	 * Save the media-category picker.
	 *
	 * @param array $post       The attachment's post data (with ID).
	 * @param array $attachment The submitted attachment fields.
	 * @return array
	 */
	public static function save_picker( $post, $attachment ) {
		if ( ! is_array( $attachment ) || ! isset( $attachment[ self::PICKER_FIELD . '_present' ] ) ) {
			return $post;
		}
		$post_id  = (int) ( $post['ID'] ?? 0 );
		$taxonomy = get_taxonomy( self::TAXONOMY );
		// phpcs:ignore WordPress.WP.Capabilities.Undetermined -- The taxonomy's own "assign terms" capability.
		if ( ! $taxonomy instanceof WP_Taxonomy || ! current_user_can( $taxonomy->cap->assign_terms ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return $post;
		}
		$submitted = isset( $attachment[ self::PICKER_FIELD ] ) && is_array( $attachment[ self::PICKER_FIELD ] )
			? array_map( 'intval', array_values( $attachment[ self::PICKER_FIELD ] ) )
			: [];
		$valid     = [];
		foreach ( $submitted as $id ) {
			if ( $id > 0 && get_term( $id, self::TAXONOMY ) instanceof WP_Term ) {
				$valid[] = $id;
			}
		}
		wp_set_object_terms( $post_id, $valid, self::TAXONOMY, false );
		return $post;
	}

	/**
	 * Enqueue the media modal/grid category filter (a dropdown added to the attachments browser).
	 *
	 * @return void
	 */
	public static function enqueue_filter(): void {
		if ( ! current_user_can( 'upload_files' ) ) {
			return;
		}
		wp_enqueue_script(
			'wormiss-media-filter',
			plugins_url( 'assets/media-filter.js', WORMISS_FILE ),
			[ 'media-views' ],
			WORMISS_VERSION,
			true
		);
		wp_add_inline_script(
			'wormiss-media-filter',
			'window.wormissMediaFilter = ' . wp_json_encode(
				[
					'taxonomy' => self::TAXONOMY,
					'all'      => __( 'All media categories', 'wormiss' ),
					'terms'    => self::term_choices(),
				]
			) . ';',
			'before'
		);
	}

	/**
	 * Honour the media library's category filter (the dropdown's chosen category).
	 *
	 * This only applies the viewer's own choice; it does not restrict what they may see (that gate
	 * is a later phase). The AJAX request is authenticated by core before this filter runs.
	 *
	 * @param array $args WP_Query arguments.
	 * @return array
	 */
	public static function filter_query( $args ) {
		$args = is_array( $args ) ? $args : [];
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only; core's wp_ajax_query_attachments() has already verified the nonce.
		if ( ! isset( $_REQUEST['query'][ self::TAXONOMY ] ) ) {
			return $args;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- As above; core has verified the nonce.
		$term_id = (int) sanitize_text_field( wp_unslash( $_REQUEST['query'][ self::TAXONOMY ] ) );
		if ( $term_id < 1 || ! get_term( $term_id, self::TAXONOMY ) instanceof WP_Term ) {
			return $args;
		}
		$clauses   = isset( $args['tax_query'] ) && is_array( $args['tax_query'] ) ? $args['tax_query'] : [];
		$clauses[] = [
			'taxonomy'         => self::TAXONOMY,
			'field'            => 'term_id',
			'terms'            => [ $term_id ],
			// A parent category also shows images in its sub-categories.
			'include_children' => true,
		];
		// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- The media library's own category filter, chosen by the viewer.
		$args['tax_query'] = $clauses;
		return $args;
	}

	/**
	 * The categories in hierarchical order, each with its depth and plain name (parents first, each
	 * one's children under it).
	 *
	 * @return array<int, array{id: int, name: string, depth: int}>
	 */
	private static function nested_terms(): array {
		$terms = get_terms(
			[
				'taxonomy'   => self::TAXONOMY,
				'hide_empty' => false,
				'orderby'    => 'name',
			]
		);
		if ( ! is_array( $terms ) ) {
			return [];
		}
		$children = [];
		foreach ( $terms as $term ) {
			$children[ (int) $term->parent ][] = $term;
		}
		$out  = [];
		$walk = static function ( int $above, int $depth ) use ( &$walk, &$out, $children ): void {
			foreach ( $children[ $above ] ?? [] as $term ) {
				$out[] = [
					'id'    => (int) $term->term_id,
					'name'  => (string) $term->name,
					'depth' => $depth,
				];
				$walk( (int) $term->term_id, $depth + 1 );
			}
		};
		$walk( 0, 0 );
		return $out;
	}
}
