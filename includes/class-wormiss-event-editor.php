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
 * The "Event details" box under an Event's content: its details in tabs.
 *
 * @package    wormiss
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined( 'ABSPATH' ) || exit;

/**
 * A full-width box under the content of an Event, with tabs for when, where, places, waitlist
 * and iMIS. It edits the same post meta the sync sends, and saves it before the sync is queued.
 * Without JavaScript every tab shows, one under another.
 */
class Wormiss_Event_Editor {

	/** Nonce action and field. */
	const NONCE = 'wormiss_event_details';

	/** Form field that marks the box as sent (only fields in a sent box are saved). */
	const FIELD_SENT = 'wormiss_event_details_sent';

	/**
	 * Hook in.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'add_meta_boxes_' . Wormiss_Event_Sync::POST_TYPE, [ __CLASS__, 'add_box' ] );
		// Before the sync is queued (priority 20), so it sends what was just saved.
		add_action( 'save_post_' . Wormiss_Event_Sync::POST_TYPE, [ __CLASS__, 'save' ], 5 );
		add_action( 'admin_enqueue_scripts', [ __CLASS__, 'assets' ] );
	}

	/**
	 * The tabs: key => [label, fields]. Each field: meta key => [label, type, help, extra].
	 *
	 * @return array
	 */
	public static function tabs(): array {
		$type_default = __( 'Event type\'s default, else iMIS\'s', 'wormiss' );
		return [
			'when'     => [
				__( 'When', 'wormiss' ),
				[
					Wormiss_Event_Sync::META_START => [ __( 'Starts', 'wormiss' ), 'datetime-local', '', [] ],
					Wormiss_Event_Sync::META_END   => [ __( 'Ends', 'wormiss' ), 'datetime-local', '', [] ],
				],
			],
			'where'    => [
				__( 'Where', 'wormiss' ),
				[
					Wormiss_Event_Sync::META_LOCATION => [ __( 'Place', 'wormiss' ), 'text', __( 'Venue name. Blank keeps the template\'s.', 'wormiss' ), [] ],
					Wormiss_Event_Sync::META_ADDRESS1 => [ __( 'Street address', 'wormiss' ), 'text', '', [ 'maxlength' => 100 ] ],
					Wormiss_Event_Sync::META_ADDRESS2 => [ __( 'Street address, line 2', 'wormiss' ), 'text', '', [ 'maxlength' => 100 ] ],
					Wormiss_Event_Sync::META_CITY     => [ __( 'Suburb or city', 'wormiss' ), 'text', '', [ 'maxlength' => 100 ] ],
					Wormiss_Event_Sync::META_STATE    => [ __( 'State', 'wormiss' ), 'text', __( 'As iMIS has it, e.g. QLD, NSW or VIC.', 'wormiss' ), [ 'maxlength' => 15 ] ],
					Wormiss_Event_Sync::META_POSTCODE => [ __( 'Postcode', 'wormiss' ), 'text', '', [ 'maxlength' => 10 ] ],
					Wormiss_Event_Sync::META_COUNTRY  => [
						__( 'Country', 'wormiss' ),
						'select',
						__( 'With any part of the address filled in, the whole address is saved in iMIS (a blank state or country keeps the template\'s).', 'wormiss' ),
						[
							'options' => [
								''            => __( 'The template\'s', 'wormiss' ),
								'Australia'   => __( 'Australia', 'wormiss' ),
								'New Zealand' => __( 'New Zealand', 'wormiss' ),
							],
						],
					],
					Wormiss_Event_Sync::META_ONLINE   => [ __( 'Online link', 'wormiss' ), 'url', '', [] ],
				],
			],
			'places'   => [
				__( 'Places', 'wormiss' ),
				[
					Wormiss_Event_Sync::META_CAPACITY => [
						__( 'Capacity', 'wormiss' ),
						'number',
						__( '0 uses the event type\'s default, else the template\'s. 1000 or more shows as "Places available".', 'wormiss' ),
						[ 'min' => 0 ],
					],
					Wormiss_Event_Sync::META_MINIMUM  => [
						__( 'Minimum attendance', 'wormiss' ),
						'number',
						__( 'Fewest people for the event to go ahead. 0 uses the event type\'s default.', 'wormiss' ),
						[ 'min' => 0 ],
					],
					Wormiss_Event_Sync::META_EXPECTED => [
						__( 'Expected attendance', 'wormiss' ),
						'number',
						__( 'For planning. 0 uses the event type\'s default.', 'wormiss' ),
						[ 'min' => 0 ],
					],
				],
			],
			'waitlist' => [
				__( 'Waitlist', 'wormiss' ),
				[
					Wormiss_Event_Sync::META_WAITLIST     => [
						__( 'Waitlist', 'wormiss' ),
						'select',
						'',
						[
							'options' => [
								''                    => $type_default,
								'None'                => __( 'No waitlist', 'wormiss' ),
								'OnlyStaff'           => __( 'Staff can add people', 'wormiss' ),
								'StaffAndPublicUsers' => __( 'Anyone can join', 'wormiss' ),
							],
						],
					],
					Wormiss_Event_Sync::META_AUTO_RELEASE => [
						__( 'When a place frees up', 'wormiss' ),
						'select',
						'',
						[
							'options' => [
								''    => $type_default,
								'yes' => __( 'Offer it to the waitlist automatically', 'wormiss' ),
								'no'  => __( 'Staff offer it', 'wormiss' ),
							],
						],
					],
				],
			],
			'imis'     => [
				__( 'iMIS', 'wormiss' ),
				[
					Wormiss_Event_Sync::META_CODE     => [
						__( 'Event code', 'wormiss' ),
						'text',
						__( 'Up to 10 letters, digits, _ and -, e.g. CONF27. Becomes the iMIS event code.', 'wormiss' ),
						[ 'maxlength' => Wormiss_Event_Sync::CODE_LENGTH ],
					],
					Wormiss_Event_Sync::META_EXISTING => [
						__( 'Existing iMIS event ID', 'wormiss' ),
						'text',
						__( 'To use an event already in iMIS (e.g. CONGRESS26) instead of creating one. Its details, places and registration come from iMIS, and WordPress never changes or cancels it. Clear it to unlink.', 'wormiss' ),
						[ 'maxlength' => Wormiss_Event_Sync::CODE_LENGTH ],
					],
				],
			],
		];
	}

	/**
	 * Add the box.
	 *
	 * @return void
	 */
	public static function add_box(): void {
		add_meta_box(
			'wormiss-event-details',
			__( 'Event details', 'wormiss' ),
			[ __CLASS__, 'render' ],
			Wormiss_Event_Sync::POST_TYPE,
			'normal',
			'high'
		);
	}

	/**
	 * The box's styles and tab script, on the Event editor only.
	 *
	 * @param string $hook Admin page.
	 * @return void
	 */
	public static function assets( $hook ): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! in_array( $hook, [ 'post.php', 'post-new.php' ], true ) || ! $screen || Wormiss_Event_Sync::POST_TYPE !== $screen->post_type ) {
			return;
		}
		wp_enqueue_style( 'wormiss-event-details', plugins_url( 'assets/event-details.css', WORMISS_FILE ), [], WORMISS_VERSION );
		wp_enqueue_script( 'wormiss-event-details', plugins_url( 'assets/event-details.js', WORMISS_FILE ), [], WORMISS_VERSION, true );
	}

	/**
	 * Show the box.
	 *
	 * @param WP_Post $post Event.
	 * @return void
	 */
	public static function render( $post ): void {
		$imis_id = (string) get_post_meta( $post->ID, Wormiss_Event_Sync::META_IMIS_ID, true );
		$linked  = (bool) get_post_meta( $post->ID, Wormiss_Event_Sync::META_LINKED, true )
			|| '' !== (string) get_post_meta( $post->ID, Wormiss_Event_Sync::META_EXISTING, true );
		$tabs    = self::tabs();
		if ( $linked ) {
			// A linked event is only read from iMIS: only its link can be changed here.
			$tabs = [
				'imis' => [
					$tabs['imis'][0],
					[ Wormiss_Event_Sync::META_EXISTING => $tabs['imis'][1][ Wormiss_Event_Sync::META_EXISTING ] ],
				],
			];
		}
		wp_nonce_field( self::NONCE, self::NONCE );
		echo '<input type="hidden" name="' . esc_attr( self::FIELD_SENT ) . '" value="1" />';
		echo '<div class="wormiss-tabs">';
		if ( $linked ) {
			echo '<p class="description">' . esc_html__( 'Linked to an iMIS event: its dates, place, places and registration come from iMIS.', 'wormiss' ) . '</p>';
		}
		echo '<div class="wormiss-tabs__list" role="tablist" hidden>';
		$first = true;
		foreach ( $tabs as $key => [ $label ] ) {
			printf(
				'<button type="button" role="tab" class="wormiss-tabs__tab" id="wormiss-tab-%1$s" aria-controls="wormiss-panel-%1$s" aria-selected="%2$s" tabindex="%3$s">%4$s</button>',
				esc_attr( $key ),
				$first ? 'true' : 'false',
				$first ? '0' : '-1',
				esc_html( $label )
			);
			$first = false;
		}
		echo '</div>';
		foreach ( $tabs as $key => [ $label, $fields ] ) {
			printf(
				'<div class="wormiss-tabs__panel" role="tabpanel" id="wormiss-panel-%1$s" aria-labelledby="wormiss-tab-%1$s"><h3 class="wormiss-tabs__heading">%2$s</h3><div class="wormiss-fields">',
				esc_attr( $key ),
				esc_html( $label )
			);
			foreach ( $fields as $meta_key => $field ) {
				// The code becomes the iMIS event ID, so it cannot change once the event is in iMIS.
				$locked = Wormiss_Event_Sync::META_CODE === $meta_key && '' !== $imis_id;
				if ( $locked ) {
					/* translators: %s: iMIS event ID. */
					$field[2] = sprintf( __( 'In iMIS as %s. The code cannot change now.', 'wormiss' ), $imis_id );
				}
				self::field( $meta_key, $field, get_post_meta( $post->ID, $meta_key, true ), $locked );
			}
			echo '</div></div>';
		}
		echo '</div>';
	}

	/**
	 * One field.
	 *
	 * @param string $meta_key Meta key (also the field name).
	 * @param array  $field    [label, type, help, extra].
	 * @param mixed  $value    Saved value.
	 * @param bool   $locked   Shown but not editable.
	 * @return void
	 */
	private static function field( string $meta_key, array $field, $value, bool $locked ): void {
		[ $label, $type, $help, $extra ] = $field;
		$id                              = 'wormiss-field' . str_replace( '_', '-', $meta_key );
		$value                           = (string) $value;
		echo '<p class="wormiss-field"><label for="' . esc_attr( $id ) . '">' . esc_html( $label ) . '</label>';
		if ( 'select' === $type ) {
			echo '<select id="' . esc_attr( $id ) . '" name="' . esc_attr( $meta_key ) . '">';
			foreach ( $extra['options'] as $option => $option_label ) {
				printf(
					'<option value="%1$s"%2$s>%3$s</option>',
					esc_attr( (string) $option ),
					selected( $value, (string) $option, false ),
					esc_html( $option_label )
				);
			}
			echo '</select>';
		} else {
			$attributes = '';
			foreach ( $extra as $name => $attribute ) {
				$attributes .= ' ' . esc_attr( $name ) . '="' . esc_attr( (string) $attribute ) . '"';
			}
			printf(
				'<input type="%1$s" id="%2$s" name="%3$s" value="%4$s" class="widefat"%5$s%6$s />',
				esc_attr( $type ),
				esc_attr( $id ),
				esc_attr( $meta_key ),
				esc_attr( $value ),
				$attributes, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped above.
				$locked ? ' readonly' : ''
			);
		}
		if ( '' !== $help ) {
			echo '<span class="description">' . esc_html( $help ) . '</span>';
		}
		echo '</p>';
	}

	/**
	 * Save the box's fields (cleaned by each meta key's sanitizer), before the sync is queued.
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	public static function save( $post_id ): void {
		if ( empty( $_POST[ self::FIELD_SENT ] ) || wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}
		$nonce = isset( $_POST[ self::NONCE ] ) ? sanitize_text_field( wp_unslash( $_POST[ self::NONCE ] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, self::NONCE ) || ! current_user_can( 'edit_post', (int) $post_id ) ) {
			return;
		}
		$imis_id = (string) get_post_meta( $post_id, Wormiss_Event_Sync::META_IMIS_ID, true );
		foreach ( self::tabs() as [ , $fields ] ) {
			foreach ( array_keys( $fields ) as $meta_key ) {
				if ( ! isset( $_POST[ $meta_key ] ) ) {
					continue; // Not in the box (e.g. a linked event shows only its link).
				}
				if ( Wormiss_Event_Sync::META_CODE === $meta_key && '' !== $imis_id ) {
					continue; // In iMIS already: the code is fixed.
				}
				// Each key's registered sanitizer cleans the value as it is saved.
				update_post_meta( (int) $post_id, $meta_key, sanitize_text_field( wp_unslash( $_POST[ $meta_key ] ) ) );
			}
		}
	}
}
