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
 * Open events: the Event pages of one event type (and its sub-types) whose enrolment is still
 * open, soonest first, as cards. For a page such as the Course Education Pathway.
 *
 * @package    wormiss
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined( 'ABSPATH' ) || exit;

/**
 * An event stays listed until its enrolment closes: the iMIS registration closing date for an
 * event from iMIS, else its start. Events without either date stay listed.
 */
class Wormiss_Open_Events {

	/**
	 * Most Event pages read for one block (before those closed are left out).
	 */
	const MAX_READ = 200;

	/**
	 * An Event page's dates and place: from iMIS for a linked or imported event, else as saved.
	 *
	 * @param int $post_id Event page.
	 * @return array start, end, reg_end, location, online.
	 */
	public static function facts( int $post_id ): array {
		$linked = get_post_meta( $post_id, Wormiss_Event_Sync::META_LINKED_DETAILS, true );
		if ( is_array( $linked ) && ! empty( $linked['start'] ) ) {
			return [
				'start'    => (string) $linked['start'],
				'end'      => (string) ( $linked['end'] ?? '' ),
				'reg_end'  => (string) ( $linked['reg_end'] ?? '' ),
				'location' => (string) ( $linked['location'] ?? '' ),
				'online'   => (string) ( $linked['online'] ?? '' ),
			];
		}
		$meta = static fn( string $key ): string => (string) get_post_meta( $post_id, $key, true );
		return [
			'start'    => $meta( Wormiss_Event_Sync::META_START ),
			'end'      => $meta( Wormiss_Event_Sync::META_END ),
			'reg_end'  => '',
			'location' => $meta( Wormiss_Event_Sync::META_LOCATION ),
			'online'   => $meta( Wormiss_Event_Sync::META_ONLINE ),
		];
	}

	/**
	 * When an event's enrolment closes: its registration closing date, else its start.
	 *
	 * @param array $facts From facts().
	 * @return int|null Unix time, or null when it has neither date.
	 */
	public static function closes( array $facts ): ?int {
		$closes = Wormiss_Events::timestamp( (string) $facts['reg_end'] );
		return null !== $closes ? $closes : Wormiss_Events::timestamp( (string) $facts['start'] );
	}

	/**
	 * The open events of a type, soonest first.
	 *
	 * @param string $type     Event type slug ('' for every type).
	 * @param bool   $children Include the type's sub-types.
	 * @param int    $limit    Most to return.
	 * @param int    $now      Unix time.
	 * @return array[] Each: post (WP_Post), facts, start (Unix time or null).
	 */
	public static function find( string $type, bool $children, int $limit, int $now ): array {
		$args = [
			'post_type'      => Wormiss_Event_Sync::POST_TYPE,
			'post_status'    => 'publish',
			'posts_per_page' => self::MAX_READ,
			'no_found_rows'  => true,
		];
		if ( '' !== $type ) {
			$args['tax_query'] = [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- The point of the block.
				[
					'taxonomy'         => Wormiss_Event_Types::TAXONOMY,
					'field'            => 'slug',
					'terms'            => $type,
					'include_children' => $children,
				],
			];
		}
		$open = [];
		foreach ( get_posts( $args ) as $post ) {
			$facts  = self::facts( $post->ID );
			$closes = self::closes( $facts );
			if ( null !== $closes && $closes < $now ) {
				continue; // Enrolment has closed.
			}
			$open[] = [
				'post'  => $post,
				'facts' => $facts,
				'start' => Wormiss_Events::timestamp( $facts['start'] ),
			];
		}
		// Soonest first; events without a date last, by title.
		usort(
			$open,
			static fn( array $a, array $b ): int => [ null === $a['start'], $a['start'] ?? 0, $a['post']->post_title ]
				<=> [ null === $b['start'], $b['start'] ?? 0, $b['post']->post_title ]
		);
		return array_slice( $open, 0, max( 1, $limit ) );
	}

	/**
	 * The block's cards (the same look as the Upcoming iMIS events block).
	 *
	 * @param array $args type, children, limit, show_description, button_text, empty_text.
	 * @return string
	 */
	public static function render( array $args ): string {
		$events = self::find( (string) $args['type'], (bool) $args['children'], (int) $args['limit'], time() );
		if ( ! $events ) {
			$empty = trim( (string) $args['empty_text'] );
			$empty = '' !== $empty ? $empty : __( 'Nothing is open for enrolment at the moment.', 'wormiss' );
			return '<div class="wormiss-events wormiss-events--empty"><p>' . esc_html( $empty ) . '</p></div>';
		}
		$text = trim( (string) $args['button_text'] );
		$text = '' !== $text ? $text : __( 'Learn more', 'wormiss' );
		$html = '<ul class="wormiss-events wormiss-events--cards wormiss-open-events">';
		foreach ( $events as $event ) {
			$post  = $event['post'];
			$facts = $event['facts'];
			$url   = (string) get_permalink( $post );
			$name  = wp_strip_all_tags( get_the_title( $post ) );
			$html .= '<li class="wormiss-events__event">';
			$image = (string) get_the_post_thumbnail_url( $post, 'medium_large' );
			if ( '' !== $image ) {
				$html .= '<a href="' . esc_url( $url ) . '" tabindex="-1" aria-hidden="true"><img class="wormiss-events__image" src="' . esc_url( $image ) . '" alt="" loading="lazy" /></a>';
			}
			$where = '' !== $facts['location'] ? $facts['location'] : ( '' !== $facts['online'] ? __( 'Online', 'wormiss' ) : '' );
			if ( '' !== $where ) {
				$html .= '<p class="wormiss-open-events__label">' . esc_html( $where ) . '</p>';
			}
			$html .= '<div class="wormiss-events__body"><h3 class="wormiss-events__title"><a href="' . esc_url( $url ) . '">' . esc_html( $name ) . '</a></h3>';
			if ( null !== $event['start'] ) {
				$html .= '<p class="wormiss-events__when"><time datetime="' . esc_attr( gmdate( 'c', $event['start'] ) ) . '">'
					. esc_html( Wormiss_Events::when( $facts['start'], $facts['end'] ) ) . '</time></p>';
			}
			$closes = Wormiss_Events::timestamp( $facts['reg_end'] );
			if ( null !== $closes ) {
				$html .= '<p class="wormiss-open-events__closes">' . esc_html(
					/* translators: %s: date. */
					sprintf( __( 'Enrolments close %s', 'wormiss' ), wp_date( 'd/m/Y', $closes ) )
				) . '</p>';
			}
			$excerpt = wp_strip_all_tags( (string) get_the_excerpt( $post ) );
			if ( $args['show_description'] && '' !== $excerpt ) {
				$html .= '<p class="wormiss-events__description">' . esc_html( wp_trim_words( $excerpt, 30 ) ) . '</p>';
			}
			$html .= '<p class="wormiss-events__action"><a class="wp-element-button" href="' . esc_url( $url ) . '" aria-label="'
				/* translators: 1: button text, 2: event name. */
				. esc_attr( sprintf( __( '%1$s: %2$s', 'wormiss' ), $text, $name ) ) . '">' . esc_html( $text ) . '</a></p>';
			$html .= '</div></li>';
		}
		return $html . '</ul>';
	}
}
