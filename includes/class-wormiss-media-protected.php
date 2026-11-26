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
 * Protection flags on media categories.
 *
 * A media category can be flagged "protected delivery" (its images are stored outside the public
 * path and streamed through a gated endpoint, Wormiss_Media_Delivery) and, more strictly, "lock to
 * post" (an image may only load while a member is viewing the specific post it is embedded in).
 * Lock-to-post implies protected delivery. This file owns the per-category flags, the admin toggles
 * and columns on the category screens, and the helpers that tell whether an attachment is protected
 * or locked.
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

	/**
	 * Term meta: whether this category's images are locked to the post they are embedded in (a
	 * stricter mode than protected delivery; it implies protected delivery).
	 */
	const META_LOCKED = 'wormiss_media_locked';

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
		$args = [
			'type'              => 'boolean',
			'single'            => true,
			'default'           => false,
			'show_in_rest'      => true,
			'sanitize_callback' => static fn( $value ) => (bool) $value,
			'auth_callback'     => static fn( $allowed, $meta_key, $term_id ) => current_user_can( 'edit_term', (int) $term_id ),
		];
		register_term_meta( Wormiss_Media_Categories::TAXONOMY, self::META_PROTECTED, $args );
		register_term_meta( Wormiss_Media_Categories::TAXONOMY, self::META_LOCKED, $args );
	}

	/**
	 * Whether a category's images are locked to the post they are embedded in.
	 *
	 * @param int $term_id Term ID.
	 * @return bool
	 */
	public static function is_locked( int $term_id ): bool {
		return (bool) get_term_meta( $term_id, self::META_LOCKED, true );
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
	 * The category term IDs flagged with any protection (protected delivery or lock-to-post).
	 *
	 * @return int[]
	 */
	public static function locked_term_ids(): array {
		$terms = get_terms(
			[
				'taxonomy'   => Wormiss_Media_Categories::TAXONOMY,
				'hide_empty' => false,
				'meta_key'   => self::META_LOCKED, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Admin-scale taxonomy.
				'meta_value' => '1', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- As above.
				'fields'     => 'ids',
			]
		);
		return is_array( $terms ) ? array_map( 'intval', $terms ) : [];
	}

	/**
	 * The category term IDs with any protection flag (protected delivery or lock-to-post).
	 *
	 * @return int[]
	 */
	public static function flagged_term_ids(): array {
		return array_values( array_unique( array_merge( self::protected_term_ids(), self::locked_term_ids() ) ) );
	}

	/**
	 * Whether an attachment is in a category with any of the given flags, counting a flagged parent
	 * as flagging its sub-categories.
	 *
	 * @param int      $attachment Attachment ID.
	 * @param string[] $meta_keys  Term-meta keys to test (true if any is set on the term or an ancestor).
	 * @return bool
	 */
	private static function attachment_in_flagged( int $attachment, array $meta_keys ): bool {
		$terms = wp_get_object_terms( $attachment, Wormiss_Media_Categories::TAXONOMY, [ 'fields' => 'ids' ] );
		if ( is_wp_error( $terms ) || ! $terms ) {
			return false;
		}
		foreach ( array_map( 'intval', $terms ) as $term_id ) {
			$chain = array_merge( [ $term_id ], array_map( 'intval', get_ancestors( $term_id, Wormiss_Media_Categories::TAXONOMY, 'taxonomy' ) ) );
			foreach ( $chain as $id ) {
				foreach ( $meta_keys as $meta_key ) {
					if ( (bool) get_term_meta( $id, $meta_key, true ) ) {
						return true;
					}
				}
			}
		}
		return false;
	}

	/**
	 * Whether an attachment is served with protected delivery. Lock-to-post implies protected
	 * delivery, so a locked category counts too.
	 *
	 * @param int $attachment Attachment ID.
	 * @return bool
	 */
	public static function attachment_is_protected( int $attachment ): bool {
		return self::attachment_in_flagged( $attachment, [ self::META_PROTECTED, self::META_LOCKED ] );
	}

	/**
	 * Whether an attachment is locked to the post it is embedded in (the stricter mode).
	 *
	 * @param int $attachment Attachment ID.
	 * @return bool
	 */
	public static function attachment_is_locked( int $attachment ): bool {
		return self::attachment_in_flagged( $attachment, [ self::META_LOCKED ] );
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
				. '<label><input type="checkbox" name="%2$s" value="1" /> %3$s</label><p>%4$s</p>'
				. '<label><input type="checkbox" name="%5$s" value="1" /> %6$s</label><p>%7$s</p></div>',
			esc_attr( self::FIELD_PRESENT ),
			esc_attr( self::META_PROTECTED ),
			esc_html__( 'Protected delivery', 'wormiss' ),
			esc_html__( 'Serve this category\'s images with byte-level protection (stored outside the public path, streamed through a gated endpoint), for sensitive images such as members\' blog pictures.', 'wormiss' ),
			esc_attr( self::META_LOCKED ),
			esc_html__( 'Lock to post (strict)', 'wormiss' ),
			esc_html__( 'Stricter: an image may only load while the member is viewing the specific post it is embedded in; the image URL returns Forbidden out of that context. Implies protected delivery. It cannot stop a viewer saving an image they can already see.', 'wormiss' )
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
				. '<p class="description">%6$s</p>'
				. '<label><input type="checkbox" name="%7$s" value="1" %8$s /> %9$s</label>'
				. '<p class="description">%10$s</p></td></tr>',
			esc_html__( 'Protected delivery', 'wormiss' ),
			esc_attr( self::FIELD_PRESENT ),
			esc_attr( self::META_PROTECTED ),
			checked( self::is_protected( $term_id ), true, false ),
			esc_html__( 'Serve this category\'s images with byte-level protected delivery', 'wormiss' ),
			esc_html__( 'Stored outside the public path and streamed through a gated endpoint; for sensitive images such as members\' blog pictures. A flagged parent flags its sub-categories too.', 'wormiss' ),
			esc_attr( self::META_LOCKED ),
			checked( self::is_locked( $term_id ), true, false ),
			esc_html__( 'Lock to post (strict)', 'wormiss' ),
			esc_html__( 'An image may only load while the member is viewing the specific post it is embedded in; its URL returns Forbidden out of that context. Implies protected delivery, and also flags sub-categories. It cannot stop a viewer saving an image they can already see.', 'wormiss' )
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
		update_term_meta( $term_id, self::META_LOCKED, isset( $_POST[ self::META_LOCKED ] ) ? 1 : 0 );
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
		$term_id = (int) $term_id;
		if ( self::is_locked( $term_id ) ) {
			return esc_html__( 'Protected · locked to post', 'wormiss' );
		}
		return self::is_protected( $term_id ) ? esc_html__( 'Protected', 'wormiss' ) : '—';
	}
}
