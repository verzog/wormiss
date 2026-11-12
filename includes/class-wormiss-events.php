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
 * Shows upcoming iMIS events (from the iMIS Events API) on a page: block and shortcode.
 *
 * @package    wormiss
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined( 'ABSPATH' ) || exit;

/**
 * Reads (with caching), filters and renders iMIS events.
 */
class Wormiss_Events {

	/** How long the last good copy is kept, to show while iMIS is unavailable. */
	const STALE_SECONDS = WEEK_IN_SECONDS;

	/** How far back iMIS is asked for events, so multi-day events already under way are still read. */
	const LOOKBACK_DAYS = 90;

	/** Most events shown by one block. */
	const MAX_LIMIT = 50;

	/** Longest description kept for an event (characters of plain text). */
	const DESCRIPTION_LENGTH = 600;

	/** Publishing states that mean an event is not for the public. */
	const HIDDEN_STATES = [ 'archived', 'declined', 'deleted', 'expired', 'waitingforapproval', 'working' ];

	/** Template states that mark an iMIS event template rather than a real event. */
	const TEMPLATE_STATES = [ 'standardtemplate', 'defaulttemplate' ];

	/**
	 * Default display options.
	 *
	 * @return array
	 */
	public static function defaults(): array {
		return [
			'limit'            => 6,
			'layout'           => 'cards',
			'category'         => '',
			'show_description' => true,
			'show_image'       => true,
			'button_text'      => '',
			'empty_text'       => '',
		];
	}

	/**
	 * The [wormiss_events] shortcode.
	 *
	 * @param array|string $atts Shortcode attributes.
	 * @return string
	 */
	public static function shortcode( $atts ): string {
		// Block styles load only with a block on sites that load block assets on demand.
		wp_enqueue_style( 'wormiss-events-style' );
		$args = shortcode_atts( self::defaults(), is_array( $atts ) ? $atts : [], 'wormiss_events' );
		foreach ( [ 'show_description', 'show_image' ] as $flag ) {
			$args[ $flag ] = ! in_array( strtolower( (string) $args[ $flag ] ), [ '0', 'false', 'no', 'off', '' ], true );
		}
		return self::render( $args );
	}

	/**
	 * Render the block or shortcode.
	 *
	 * @param array $args Display options (see defaults()).
	 * @return string HTML.
	 */
	public static function render( array $args ): string {
		$args = array_merge( self::defaults(), $args );
		// The set-up and "not available" messages use the iMIS data block's styles.
		wp_enqueue_style( 'wormiss-iqa-style' );
		if ( ! Wormiss_Settings::is_configured() ) {
			return Wormiss_Iqa_Display::editor_notice( __( 'The iMIS connection is not set up yet (Wormiss > Settings).', 'wormiss' ) );
		}
		try {
			$events = self::get_events();
		} catch ( Wormiss_Exception $e ) {
			return Wormiss_Iqa_Display::unavailable( $e->getMessage() );
		}

		$events = self::upcoming( $events, (string) $args['category'], time() );
		$events = array_slice( $events, 0, min( self::MAX_LIMIT, max( 1, (int) $args['limit'] ) ) );
		if ( ! $events ) {
			$empty = trim( (string) $args['empty_text'] );
			$empty = '' !== $empty ? $empty : __( 'No upcoming events at the moment.', 'wormiss' );
			return '<div class="wormiss-events wormiss-events--empty"><p>' . esc_html( $empty ) . '</p></div>';
		}

		$layout = 'list' === $args['layout'] ? 'list' : 'cards';
		$html   = '<ul class="wormiss-events wormiss-events--' . $layout . '">';
		foreach ( $events as $event ) {
			$html .= self::render_event( $event, $args, $layout );
		}
		return $html . '</ul>';
	}

	/**
	 * One event as a list item.
	 *
	 * @param array  $event  Event from normalise().
	 * @param array  $args   Display options.
	 * @param string $layout cards or list.
	 * @return string
	 */
	private static function render_event( array $event, array $args, string $layout ): string {
		$url   = self::event_url( $event );
		$name  = esc_html( $event['name'] );
		$title = '' !== $url ? '<a href="' . esc_url( $url ) . '">' . $name . '</a>' : $name;
		$html  = '<li class="wormiss-events__event">';

		$image = $args['show_image'] && 'cards' === $layout ? self::absolute_url( $event['image'] ) : '';
		if ( '' !== $image ) {
			$html .= '<img class="wormiss-events__image" src="' . esc_url( $image ) . '" alt="" loading="lazy" />';
		}
		$html .= '<div class="wormiss-events__body"><h3 class="wormiss-events__title">' . $title . '</h3>';

		$start = self::timestamp( $event['start'] );
		if ( null !== $start ) {
			$html .= '<p class="wormiss-events__when"><time datetime="' . esc_attr( gmdate( 'c', $start ) ) . '">'
				. esc_html( self::when( $event['start'], $event['end'] ) ) . '</time></p>';
		}
		$where = '' !== $event['location'] ? $event['location'] : ( '' !== $event['online'] ? __( 'Online', 'wormiss' ) : '' );
		if ( '' !== $where ) {
			$html .= '<p class="wormiss-events__where">' . esc_html( $where ) . '</p>';
		}
		if ( $args['show_description'] && '' !== $event['description'] ) {
			$html .= '<p class="wormiss-events__description">' . esc_html( wp_trim_words( $event['description'], 30 ) ) . '</p>';
		}
		if ( '' !== $url ) {
			$text  = trim( (string) $args['button_text'] );
			$text  = '' !== $text ? $text : __( 'Find out more', 'wormiss' );
			$label = sprintf(
				/* translators: 1: button text, 2: event name. */
				__( '%1$s: %2$s', 'wormiss' ),
				$text,
				$event['name']
			);
			$html .= '<p class="wormiss-events__action"><a class="wp-element-button" href="' . esc_url( $url )
				. '" aria-label="' . esc_attr( $label ) . '">' . esc_html( $text ) . '</a></p>';
		}
		// Free events: a Register button for signed-in members (no per-event lookups in a list).
		$html .= Wormiss_Event_Registration::button( $event );
		return $html . '</div></li>';
	}

	/**
	 * Events from the cache, a fresh read, or the last good copy if iMIS fails.
	 *
	 * @return array[] Events from normalise().
	 * @throws Wormiss_Exception If iMIS fails and no earlier copy exists.
	 */
	public static function get_events(): array {
		$key    = self::cache_key();
		$cached = get_transient( $key );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		try {
			// Events that started up to LOOKBACK_DAYS ago are read too; upcoming() drops the finished ones.
			$from = wp_date( 'Y-m-d', time() - self::LOOKBACK_DAYS * DAY_IN_SECONDS );
			$raw  = ( new Wormiss_Rest_Client() )->get_events( (string) $from );
		} catch ( Wormiss_Exception $e ) {
			$stale = get_transient( $key . '_stale' );
			if ( is_array( $stale ) ) {
				return $stale;
			}
			throw $e;
		}
		$events  = array_values( array_filter( array_map( [ __CLASS__, 'normalise' ], $raw ) ) );
		$minutes = max( 1, (int) Wormiss_Settings::get( 'cache_minutes' ) );
		set_transient( $key, $events, $minutes * MINUTE_IN_SECONDS );
		set_transient( $key . '_stale', $events, self::STALE_SECONDS );
		return $events;
	}

	/**
	 * Cache key for the current iMIS connection.
	 *
	 * @return string
	 */
	public static function cache_key(): string {
		return 'wormiss_events_' . md5( Wormiss_Settings::base_url() . "\0" . Wormiss_Settings::username() );
	}

	/**
	 * The parts of an iMIS EventData object that pages use, as plain text.
	 *
	 * @param mixed $data EventData from iMIS.
	 * @return array|null Null if it has no ID or name.
	 */
	public static function normalise( $data ): ?array {
		if ( ! is_object( $data ) ) {
			return null;
		}
		$id   = self::text( $data->EventId ?? '' ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		$name = self::text( $data->Name ?? '' ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		if ( '' === $id || '' === $name ) {
			return null;
		}
		// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- iMIS fields.
		$location = $data->Location ?? null;
		$address  = is_object( $location ) ? ( $location->Address->Address ?? null ) : null;
		$place    = is_object( $location ) ? self::text( $location->Name ?? '' ) : '';
		if ( '' === $place && is_object( $address ) ) {
			$place = self::text( $address->FullAddress ?? '' );
			$place = '' !== $place ? $place : self::text( $address->CityName ?? '' );
		}
		$description = self::text( wp_strip_all_tags( (string) self::scalar( $data->Description ?? '' ) ) );
		$event       = [
			'id'          => $id,
			'code'        => self::text( $data->EventCode ?? '' ),
			'name'        => $name,
			'description' => mb_substr( $description, 0, self::DESCRIPTION_LENGTH ),
			'start'       => self::text( $data->StartDateTime ?? '' ),
			'end'         => self::text( $data->EndDateTime ?? '' ),
			'status'      => self::text( $data->Status ?? '' ),
			'location'    => $place,
			'online'      => self::text( $data->VirtualMeetingUrl ?? '' ),
			'image'       => self::text( $data->ImageUrl ?? '' ),
			'category'    => is_object( $data->Category ?? null ) ? self::text( $data->Category->Name ?? '' ) : '',
			'template'    => self::text( $data->TemplateState ?? '' ),
			// Registration: the type (RegistrationRequired is free), when it closes, and the first option.
			'reg_type'    => self::text( $data->RegistrationType ?? '' ),
			'reg_end'     => self::text( $data->RegistrationEndDate ?? '' ),
			'reg_option'  => self::first_option( $data->RegistrationOptions ?? null ),
			'publishing'  => is_object( $data->PublishingInformation ?? null )
				? self::text( $data->PublishingInformation->PublishingState ?? '' ) : '',
		];
		// phpcs:enable
		return $event;
	}

	/**
	 * The EventFunctionId of an event's first registration option, or ''.
	 *
	 * @param mixed $options RegistrationOptions collection.
	 * @return string
	 */
	private static function first_option( $options ): string {
		$values = is_object( $options ) && isset( $options->{'$values'} ) && is_array( $options->{'$values'} )
			? $options->{'$values'} : [];
		foreach ( $values as $option ) {
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- iMIS field.
			$id = is_object( $option ) ? self::text( $option->EventFunctionId ?? '' ) : '';
			if ( '' !== $id ) {
				return $id;
			}
		}
		return '';
	}

	/**
	 * Events to show: real (not template), public, with a listed status and category,
	 * not yet finished, soonest first.
	 *
	 * @param array[] $events   Events from normalise().
	 * @param string  $category Comma-separated category names ('' for all).
	 * @param int     $now      Current Unix time.
	 * @return array[]
	 */
	public static function upcoming( array $events, string $category, int $now ): array {
		$statuses   = array_map( 'strtolower', (array) Wormiss_Settings::get( 'event_statuses' ) );
		$categories = array_map( 'strtolower', Wormiss_Settings::parse_list( $category ) );
		$out        = [];
		foreach ( $events as $event ) {
			$start = self::timestamp( $event['start'] );
			$end   = self::timestamp( $event['end'] ) ?? $start;
			if ( null === $start || $end < $now ) {
				continue;
			}
			if ( in_array( strtolower( $event['template'] ), self::TEMPLATE_STATES, true )
				|| in_array( strtolower( $event['publishing'] ), self::HIDDEN_STATES, true ) ) {
				continue;
			}
			if ( $statuses && ! in_array( strtolower( $event['status'] ), $statuses, true ) ) {
				continue;
			}
			if ( $categories && ! in_array( strtolower( $event['category'] ), $categories, true ) ) {
				continue;
			}
			$event['_start'] = $start;
			$out[]           = $event;
		}
		usort( $out, static fn( $a, $b ) => $a['_start'] <=> $b['_start'] );
		return $out;
	}

	/**
	 * The event's public page in iMIS, from the address set in Settings.
	 *
	 * @param array $event Event from normalise().
	 * @return string http(s) URL, or '' if there is none.
	 */
	public static function event_url( array $event ): string {
		$template = (string) Wormiss_Settings::get( 'event_url' );
		if ( '' === $template ) {
			return '';
		}
		$url = strtr(
			$template,
			[
				'{EventId}'   => rawurlencode( $event['id'] ),
				'{EventCode}' => rawurlencode( $event['code'] ),
			]
		);
		return self::absolute_url( $url );
	}

	/**
	 * An address as an absolute http(s) URL: iMIS paths (/… or ~/…) are joined to the iMIS site.
	 *
	 * @param string $url URL or iMIS path.
	 * @return string
	 */
	public static function absolute_url( string $url ): string {
		$url = trim( $url );
		if ( '' === $url ) {
			return '';
		}
		if ( 0 === strpos( $url, '~/' ) ) {
			$url = substr( $url, 1 );
		}
		if ( 0 === strpos( $url, '/' ) && 0 !== strpos( $url, '//' ) ) {
			$base = Wormiss_Settings::base_url();
			if ( '' === $base ) {
				return '';
			}
			$url = $base . $url;
		}
		return (string) esc_url_raw( $url, [ 'http', 'https' ] );
	}

	/**
	 * When an event is on, in the site's date and time formats.
	 *
	 * @param string $start iMIS start date-time.
	 * @param string $end   iMIS end date-time ('' if none).
	 * @return string
	 */
	public static function when( string $start, string $end ): string {
		$from = self::timestamp( $start );
		if ( null === $from ) {
			return '';
		}
		$to          = self::timestamp( $end );
		$date_format = (string) get_option( 'date_format' );
		$time_format = (string) get_option( 'time_format' );
		$timed       = '00:00' !== wp_date( 'H:i', $from );
		$text        = (string) wp_date( $date_format, $from );
		if ( null === $to || $to <= $from ) {
			return $timed ? $text . ' ' . wp_date( $time_format, $from ) : $text;
		}
		if ( wp_date( 'Y-m-d', $from ) === wp_date( 'Y-m-d', $to ) ) {
			return $timed ? $text . ', ' . wp_date( $time_format, $from ) . ' – ' . wp_date( $time_format, $to ) : $text;
		}
		if ( $timed ) {
			// A timed event that runs past midnight keeps both times.
			return $text . ' ' . wp_date( $time_format, $from ) . ' – ' . wp_date( $date_format . ' ' . $time_format, $to );
		}
		return $text . ' – ' . wp_date( $date_format, $to );
	}

	/**
	 * Unix time for an iMIS date-time; one without a time zone is the site's local time.
	 *
	 * @param string $value Date-time such as 2026-12-01T09:00:00.
	 * @return int|null
	 */
	public static function timestamp( string $value ): ?int {
		$value = trim( $value );
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}/', $value ) ) {
			return null;
		}
		try {
			return ( new DateTimeImmutable( $value, wp_timezone() ) )->getTimestamp();
		} catch ( Exception $e ) {
			unset( $e ); // An unreadable date is treated as no date.
			return null;
		}
	}

	/**
	 * A scalar iMIS value, unwrapping {"$type":…,"$value":…}.
	 *
	 * @param mixed $value Value.
	 * @return mixed
	 */
	private static function scalar( $value ) {
		if ( is_object( $value ) && property_exists( $value, '$value' ) ) {
			return $value->{'$value'};
		}
		return $value;
	}

	/**
	 * A value as one line of trimmed plain text ('' for anything not scalar).
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	private static function text( $value ): string {
		$value = self::scalar( $value );
		if ( ! is_scalar( $value ) || is_bool( $value ) ) {
			return '';
		}
		return trim( (string) preg_replace( '/\s+/u', ' ', (string) $value ) );
	}
}
