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
 * Protected-delivery flag on media categories (media spec phase 4, part one).
 *
 * A media category can be flagged for "protected delivery": its images are meant to be served
 * with real byte-level protection (stored outside the public path and streamed through a gated
 * endpoint), not just governed in the editor. This file adds the per-category flag, the admin
 * toggle on the category screens, and helpers to tell whether an attachment is protected. The
 * gated endpoint, protected storage and content-URL rewriting are a following part.
 *
 * @package    wormiss
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined( 'ABSPATH' ) || exit;

/**
 * The per-category protected-delivery flag.
 */
class Wormiss_Media_Protected {

	/** Term meta: whether this category is served with protected delivery. */
	const META_PROTECTED = 'wormiss_media_protected';

	/** Nonce action for the category form field. */
	const NONCE = 'wormiss_media_protected';

	/** Field that marks the protected checkbox as having been shown (so unticking clears it). */
	const FIELD_PRESENT = 'wormiss_media_protected_present';

	/**
	 * Hook in: register the term meta, and add the toggle and column on the category screens.
	 *
	 * @return void
	 */
	public static function init(): void {
		// After the taxonomy is registered (Wormiss_Media_Categories::register at the default priority).
		add_action( 'init', [ __CLASS__, 'register_meta' ], 11 );
		$taxonomy = Wormiss_Media_Categories::TAXONOMY;
		add_action( $taxonomy . '_add_form_fields', [ __CLASS__, 'add_field' ] );
		add_action( $taxonomy . '_edit_form_fields', [ __CLASS__, 'edit_field' ] );
		add_action( 'created_' . $taxonomy, [ __CLASS__, 'save_field' ] );
		add_action( 'edited_' . $taxonomy, [ __CLASS__, 'save_field' ] );
		add_filter( 'manage_edit-' . $taxonomy . '_columns', [ __CLASS__, 'columns' ] );
		add_filter( 'manage_' . $taxonomy . '_custom_column', [ __CLASS__, 'column' ], 10, 3 );
	}

	/**
	 * Register the protected-delivery term meta.
	 *
	 * @return void
	 */
	public static function register_meta(): void {
		register_term_meta(
			Wormiss_Media_Categories::TAXONOMY,
			self::META_PROTECTED,
			[
				'type'              => 'boolean',
				'single'            => true,
				'default'           => false,
				'show_in_rest'      => true,
				'sanitize_callback' => static fn( $value ) => (bool) $value,
				'auth_callback'     => static fn( $allowed, $meta_key, $term_id ) => current_user_can( 'edit_term', (int) $term_id ),
			]
		);
	}

	/**
	 * Whether a category is flagged for protected delivery.
	 *
	 * @param int $term_id Term ID.
	 * @return bool
	 */
	public static function is_protected( int $term_id ): bool {
		return (bool) get_term_meta( $term_id, self::META_PROTECTED, true );
	}

	/**
	 * The category term IDs flagged for protected delivery.
	 *
	 * @return int[]
	 */
	public static function protected_term_ids(): array {
		$terms = get_terms(
			[
				'taxonomy'   => Wormiss_Media_Categories::TAXONOMY,
				'hide_empty' => false,
				'meta_key'   => self::META_PROTECTED, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Admin-scale taxonomy.
				'meta_value' => '1', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- As above.
				'fields'     => 'ids',
			]
		);
		return is_array( $terms ) ? array_map( 'intval', $terms ) : [];
	}

	/**
	 * Whether an attachment is in a protected category, counting a protected parent as protecting
	 * its sub-categories.
	 *
	 * @param int $attachment Attachment ID.
	 * @return bool
	 */
	public static function attachment_is_protected( int $attachment ): bool {
		$terms = wp_get_object_terms( $attachment, Wormiss_Media_Categories::TAXONOMY, [ 'fields' => 'ids' ] );
		if ( is_wp_error( $terms ) || ! $terms ) {
			return false;
		}
		foreach ( array_map( 'intval', $terms ) as $term_id ) {
			if ( self::is_protected( $term_id ) ) {
				return true;
			}
			foreach ( get_ancestors( $term_id, Wormiss_Media_Categories::TAXONOMY, 'taxonomy' ) as $ancestor ) {
				if ( self::is_protected( (int) $ancestor ) ) {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * The protected-delivery checkbox on the "add category" screen.
	 *
	 * @return void
	 */
	public static function add_field(): void {
		wp_nonce_field( self::NONCE, 'wormiss_media_protected_nonce' );
		printf(
			'<div class="form-field"><input type="hidden" name="%1$s" value="1" />'
				. '<label><input type="checkbox" name="%2$s" value="1" /> %3$s</label><p>%4$s</p></div>',
			esc_attr( self::FIELD_PRESENT ),
			esc_attr( self::META_PROTECTED ),
			esc_html__( 'Protected delivery', 'wormiss' ),
			esc_html__( 'Serve this category\'s images with byte-level protection (stored privately, streamed through a gated endpoint). For sensitive images such as members\' blog pictures.', 'wormiss' )
		);
	}

	/**
	 * The protected-delivery checkbox on the "edit category" screen.
	 *
	 * @param WP_Term $term The category.
	 * @return void
	 */
	public static function edit_field( $term ): void {
		$term_id = $term instanceof WP_Term ? (int) $term->term_id : 0;
		wp_nonce_field( self::NONCE, 'wormiss_media_protected_nonce' );
		printf(
			'<tr class="form-field"><th scope="row">%1$s</th><td>'
				. '<input type="hidden" name="%2$s" value="1" />'
				. '<label><input type="checkbox" name="%3$s" value="1" %4$s /> %5$s</label>'
				. '<p class="description">%6$s</p></td></tr>',
			esc_html__( 'Protected delivery', 'wormiss' ),
			esc_attr( self::FIELD_PRESENT ),
			esc_attr( self::META_PROTECTED ),
			checked( self::is_protected( $term_id ), true, false ),
			esc_html__( 'Serve this category\'s images with byte-level protection', 'wormiss' ),
			esc_html__( 'Images here are stored privately and streamed through a gated endpoint, for sensitive images such as members\' blog pictures. Sub-categories are protected too.', 'wormiss' )
		);
	}

	/**
	 * Save the protected-delivery flag (capability, then nonce, then the value).
	 *
	 * @param int $term_id Term ID.
	 * @return void
	 */
	public static function save_field( $term_id ): void {
		$term_id = (int) $term_id;
		$nonce   = isset( $_POST['wormiss_media_protected_nonce'] )
			? sanitize_text_field( wp_unslash( $_POST['wormiss_media_protected_nonce'] ) )
			: '';
		if ( ! wp_verify_nonce( $nonce, self::NONCE ) || ! current_user_can( 'edit_term', $term_id ) ) {
			return;
		}
		if ( ! isset( $_POST[ self::FIELD_PRESENT ] ) ) {
			return;
		}
		update_term_meta( $term_id, self::META_PROTECTED, isset( $_POST[ self::META_PROTECTED ] ) ? 1 : 0 );
	}

	/**
	 * Add the "Protected" column to the category list.
	 *
	 * @param array $columns Columns.
	 * @return array
	 */
	public static function columns( array $columns ): array {
		$columns['wormiss_media_protected'] = __( 'Protected', 'wormiss' );
		return $columns;
	}

	/**
	 * Show whether a category is protected in the list column.
	 *
	 * @param string $content Column content.
	 * @param string $column  Column name.
	 * @param int    $term_id Term ID.
	 * @return string
	 */
	public static function column( $content, $column, $term_id ): string {
		if ( 'wormiss_media_protected' !== $column ) {
			return (string) $content;
		}
		return self::is_protected( (int) $term_id ) ? esc_html__( 'Protected', 'wormiss' ) : '—';
	}
}
