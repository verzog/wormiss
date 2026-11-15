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
 * Bulk actions on the Events list: add the ticked events to an event type, or take them out of one.
 *
 * @package    wormiss
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined( 'ABSPATH' ) || exit;

/**
 * Two groups in the Events list's Bulk actions, "Add to event type" and "Remove from event type",
 * one entry per type (sub-types shown under their main type), for people who may assign event
 * types. WordPress's own bulk Edit can only add types, and hides them a step further in.
 */
class Wormiss_Event_Type_Bulk {

	/**
	 * Bulk action prefixes; the type's term ID follows.
	 */
	const ADD    = 'wormiss_type_add_';
	const REMOVE = 'wormiss_type_remove_';

	/**
	 * The query arguments the result notice reads.
	 */
	const DONE = 'wormiss_type_bulk';

	/**
	 * Hook in.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_filter( 'bulk_actions-edit-wormiss_event', [ __CLASS__, 'actions' ] );
		add_filter( 'handle_bulk_actions-edit-wormiss_event', [ __CLASS__, 'handle' ], 10, 3 );
		add_action( 'admin_notices', [ __CLASS__, 'notice' ] );
	}

	/**
	 * Whether the current person may assign event types.
	 *
	 * @return bool
	 */
	private static function allowed(): bool {
		$taxonomy = get_taxonomy( Wormiss_Event_Types::TAXONOMY );
		return $taxonomy instanceof WP_Taxonomy && current_user_can( $taxonomy->cap->assign_terms );
	}

	/**
	 * The event types, main types first and each one's sub-types after it.
	 *
	 * @return array Term ID => label (sub-types indented).
	 */
	public static function types(): array {
		$terms = get_terms(
			[
				'taxonomy'   => Wormiss_Event_Types::TAXONOMY,
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
				$out[ (int) $term->term_id ] = str_repeat( '— ', $depth ) . $term->name;
				$walk( (int) $term->term_id, $depth + 1 );
			}
		};
		$walk( 0, 0 );
		return $out;
	}

	/**
	 * Add the two groups to Bulk actions.
	 *
	 * @param array $actions Bulk actions.
	 * @return array
	 */
	public static function actions( $actions ): array {
		$actions = (array) $actions;
		$types   = self::types();
		if ( ! $types || ! self::allowed() ) {
			return $actions;
		}
		$add    = [];
		$remove = [];
		foreach ( $types as $id => $label ) {
			$add[ self::ADD . $id ]       = $label;
			$remove[ self::REMOVE . $id ] = $label;
		}
		// A nested list is shown as a labelled group in the drop-down.
		$actions[ __( 'Add to event type', 'wormiss' ) ]      = $add;
		$actions[ __( 'Remove from event type', 'wormiss' ) ] = $remove;
		return $actions;
	}

	/**
	 * Apply an add or remove action to the ticked events the person may edit.
	 *
	 * @param string $redirect Where to go afterwards.
	 * @param string $action   The action chosen.
	 * @param array  $post_ids Ticked events.
	 * @return string
	 */
	public static function handle( $redirect, $action, $post_ids ): string {
		$redirect = (string) $redirect;
		$action   = (string) $action;
		$add      = str_starts_with( $action, self::ADD );
		if ( ! $add && ! str_starts_with( $action, self::REMOVE ) ) {
			return $redirect;
		}
		$term = get_term( (int) substr( $action, strlen( $add ? self::ADD : self::REMOVE ) ), Wormiss_Event_Types::TAXONOMY );
		if ( ! $term instanceof WP_Term || ! self::allowed() ) {
			return $redirect;
		}
		$done = 0;
		foreach ( array_map( 'intval', (array) $post_ids ) as $post_id ) {
			if ( 'wormiss_event' !== get_post_type( $post_id ) || ! current_user_can( 'edit_post', $post_id ) ) {
				continue;
			}
			$result = $add
				? wp_set_object_terms( $post_id, [ (int) $term->term_id ], Wormiss_Event_Types::TAXONOMY, true )
				: wp_remove_object_terms( $post_id, [ (int) $term->term_id ], Wormiss_Event_Types::TAXONOMY );
			if ( ! is_wp_error( $result ) && false !== $result ) {
				++$done;
			}
		}
		return add_query_arg(
			[
				self::DONE        => $add ? 'add' : 'remove',
				self::DONE . '_n' => $done,
				self::DONE . '_t' => (int) $term->term_id,
			],
			remove_query_arg( [ self::DONE, self::DONE . '_n', self::DONE . '_t' ], $redirect )
		);
	}

	/**
	 * Say what was done, on the Events list after a bulk action.
	 *
	 * @return void
	 */
	public static function notice(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Only shows a notice about the bulk action just done.
		if ( ! isset( $_GET[ self::DONE ], $_GET[ self::DONE . '_n' ], $_GET[ self::DONE . '_t' ] ) ) {
			return;
		}
		$add  = 'add' === sanitize_key( wp_unslash( $_GET[ self::DONE ] ) );
		$n    = absint( $_GET[ self::DONE . '_n' ] );
		$term = get_term( absint( $_GET[ self::DONE . '_t' ] ), Wormiss_Event_Types::TAXONOMY );
		// phpcs:enable
		if ( ! $term instanceof WP_Term ) {
			return;
		}
		$text = $add
			/* translators: 1: number of events, 2: event type. */
			? sprintf( _n( '%1$d event added to %2$s.', '%1$d events added to %2$s.', $n, 'wormiss' ), $n, $term->name )
			/* translators: 1: number of events, 2: event type. */
			: sprintf( _n( '%1$d event removed from %2$s.', '%1$d events removed from %2$s.', $n, 'wormiss' ), $n, $term->name );
		echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $text ) . '</p></div>';
	}
}
