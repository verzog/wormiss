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
 * An event's CPD hours, read from its iMIS "CPD Hours" panel (the EventCPD business object).
 *
 * @package    wormiss
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined( 'ABSPATH' ) || exit;

/**
 * Reads the CPD rows iMIS keeps for an event (GET /api/EventCPD?EventKey=), each with hours in
 * three categories and a program level requirement, and shows them: a table on the event page
 * (hours added up per category, with the Australian and New Zealand names) and a tab in the
 * "Event details" box. Read only; off until an administrator turns it on.
 */
class Wormiss_Event_Cpd {

	/** The iMIS business object behind the event's CPD Hours panel. */
	const ENTITY = 'EventCPD';

	/** The hours categories: key => iMIS property. */
	const FIELDS = [
		'education' => 'EducationPoints',
		'reviewing' => 'ReviewingPerformancePoints',
		'outcomes'  => 'MeasuringOutcomesPoints',
	];

	/** The iMIS property holding the program level requirement. */
	const REQUIREMENT = 'ProgramLevelRequirements';

	/** Transient prefix for an event's rows. */
	const CACHE_PREFIX = 'wormiss_cpd_';

	/**
	 * Hook in.
	 *
	 * @return void
	 */
	public static function init(): void {
		// After the details box (20), so the CPD table comes after the event's text.
		add_filter( 'the_content', [ __CLASS__, 'append' ], 25 );
	}

	/**
	 * Whether CPD hours are read from iMIS.
	 *
	 * @return bool
	 */
	public static function is_on(): bool {
		return (bool) Wormiss_Settings::get( 'event_cpd' ) && Wormiss_Settings::is_configured();
	}

	/**
	 * The categories' names: key => [Australian name, New Zealand name].
	 *
	 * @return array
	 */
	public static function categories(): array {
		return [
			'education' => [ __( 'Educational Activities', 'wormiss' ), __( 'Medical Education', 'wormiss' ) ],
			'reviewing' => [ __( 'Reviewing Performance', 'wormiss' ), __( 'Reviewing Practices', 'wormiss' ) ],
			'outcomes'  => [ __( 'Measuring Outcomes', 'wormiss' ), __( 'Patient Outcomes', 'wormiss' ) ],
		];
	}

	/**
	 * An event's CPD rows from iMIS (cached), or null when they could not be read.
	 *
	 * @param string $event_id iMIS event ID.
	 * @return array[]|null Each: education, reviewing, outcomes (hours) and requirement.
	 */
	public static function rows( string $event_id ): ?array {
		if ( ! Wormiss_Rest_Client::is_event_id( $event_id ) ) {
			return null;
		}
		// Per connection too, so a changed iMIS address or account never shows the old one's rows.
		$key    = self::CACHE_PREFIX . md5( Wormiss_Settings::base_url() . "\0" . Wormiss_Settings::username() . "\0" . strtoupper( $event_id ) );
		$cached = get_transient( $key );
		if ( is_array( $cached ) ) {
			return $cached['rows'];
		}
		$rows = null;
		try {
			$rows = array_map( [ __CLASS__, 'row' ], ( new Wormiss_Rest_Client() )->get_entities( self::ENTITY, [ 'EventKey' => $event_id ] ) );
		} catch ( Wormiss_Exception $e ) {
			unset( $e ); // In the audit log; the page shows no CPD rather than an error.
		}
		// A failure is kept too, briefly, so a page view does not ask iMIS again each time.
		$minutes = null !== $rows ? max( 1, (int) Wormiss_Settings::get( 'cache_minutes' ) ) : 5;
		set_transient( $key, [ 'rows' => $rows ], $minutes * MINUTE_IN_SECONDS );
		return $rows;
	}

	/**
	 * One row in WordPress terms.
	 *
	 * @param array $properties The row's properties (name => value).
	 * @return array
	 */
	public static function row( array $properties ): array {
		$row = [];
		foreach ( self::FIELDS as $key => $property ) {
			$value       = $properties[ $property ] ?? 0;
			$row[ $key ] = is_numeric( $value ) ? max( 0.0, (float) $value ) : 0.0;
		}
		$requirement        = trim( (string) ( $properties[ self::REQUIREMENT ] ?? '' ) );
		$row['requirement'] = '(None)' === $requirement ? '' : $requirement;
		return $row;
	}

	/**
	 * The hours per category, added up across the rows.
	 *
	 * @param array[] $rows From rows().
	 * @return float[] key => hours.
	 */
	public static function totals( array $rows ): array {
		$totals = array_fill_keys( array_keys( self::FIELDS ), 0.0 );
		foreach ( $rows as $row ) {
			foreach ( array_keys( self::FIELDS ) as $key ) {
				$totals[ $key ] += (float) ( $row[ $key ] ?? 0 );
			}
		}
		return $totals;
	}

	/**
	 * The program level requirements the rows cover, each once.
	 *
	 * @param array[] $rows From rows().
	 * @return string[]
	 */
	public static function requirements( array $rows ): array {
		return array_values( array_unique( array_filter( array_map( static fn( $row ) => (string) ( $row['requirement'] ?? '' ), $rows ) ) ) );
	}

	/**
	 * Hours for people: up to two decimals, without trailing zeros (1.5, 2, 0.25).
	 *
	 * @param float $hours Hours.
	 * @return string
	 */
	public static function hours( float $hours ): string {
		return rtrim( rtrim( number_format( $hours, 2, '.', '' ), '0' ), '.' );
	}

	/**
	 * The CPD table, or '' when there are no rows.
	 *
	 * @param array[] $rows From rows().
	 * @return string HTML.
	 */
	public static function table( array $rows ): string {
		if ( ! $rows ) {
			return '';
		}
		$totals = self::totals( $rows );
		$body   = '';
		foreach ( self::categories() as $key => [ $australia, $new_zealand ] ) {
			$body .= '<tr><td>' . esc_html( $australia ) . '</td><td>' . esc_html( $new_zealand ) . '</td><td class="wormiss-cpd__hours">'
				. esc_html( self::hours( $totals[ $key ] ) ) . '</td></tr>';
		}
		$html         = '<table class="wormiss-cpd__table"><thead><tr><th scope="col">' . esc_html__( 'Australia', 'wormiss' )
			. '</th><th scope="col">' . esc_html__( 'New Zealand', 'wormiss' ) . '</th><th scope="col">'
			. esc_html__( 'Hours', 'wormiss' ) . '</th></tr></thead><tbody>' . $body . '</tbody></table>';
		$requirements = self::requirements( $rows );
		if ( $requirements ) {
			$html .= '<p class="wormiss-cpd__requirements"><strong>' . esc_html__( 'Program level requirements:', 'wormiss' ) . '</strong> '
				. esc_html( implode( ', ', $requirements ) ) . '</p>';
		}
		return $html;
	}

	/**
	 * The iMIS event ID whose CPD an Event page shows ('' for none).
	 *
	 * @param int $post_id Post ID.
	 * @return string
	 */
	public static function event_id( int $post_id ): string {
		return (string) get_post_meta( $post_id, Wormiss_Event_Sync::META_IMIS_ID, true );
	}

	/**
	 * After an Event's text, its CPD information.
	 *
	 * @param string $content Post content.
	 * @return string
	 */
	public static function append( $content ) {
		$post = get_post();
		if ( ! $post || Wormiss_Event_Sync::POST_TYPE !== $post->post_type || ! is_singular( Wormiss_Event_Sync::POST_TYPE )
			|| ! in_the_loop() || ! self::is_on() ) {
			return $content;
		}
		$event_id = self::event_id( $post->ID );
		$rows     = '' !== $event_id ? self::rows( $event_id ) : null;
		$table    = null !== $rows ? self::table( $rows ) : '';
		if ( '' === $table ) {
			return $content;
		}
		return $content . '<section class="wormiss-cpd"><h2 class="wormiss-cpd__title">' . esc_html__( 'CPD information', 'wormiss' )
			. '</h2>' . $table . '</section>';
	}

	/**
	 * The "CPD hours" tab of the Event details box: the rows as iMIS has them.
	 *
	 * @param int $post_id Post ID.
	 * @return string HTML.
	 */
	public static function admin_html( int $post_id ): string {
		$event_id = self::event_id( $post_id );
		if ( '' === $event_id ) {
			return '<p class="description">' . esc_html__( 'CPD hours show here once the event is in iMIS.', 'wormiss' ) . '</p>';
		}
		$rows = self::rows( $event_id );
		if ( null === $rows ) {
			return '<p class="description">' . esc_html__( 'The CPD hours could not be read from iMIS (see the audit log).', 'wormiss' ) . '</p>';
		}
		$note = '<p class="description">'
			. esc_html(
				sprintf(
					/* translators: %s: iMIS event ID. */
					__( 'From the CPD Hours panel of iMIS event %s. Change them there for now; the event page shows them within the cache time.', 'wormiss' ),
					$event_id
				)
			) . '</p>';
		if ( ! $rows ) {
			return '<p class="description">' . esc_html__( 'iMIS has no CPD hours for this event.', 'wormiss' ) . '</p>' . $note;
		}
		$list = '';
		foreach ( $rows as $row ) {
			$list .= '<tr>';
			foreach ( array_keys( self::FIELDS ) as $key ) {
				$list .= '<td>' . esc_html( self::hours( $row[ $key ] ) ) . '</td>';
			}
			$list .= '<td>' . esc_html( '' !== $row['requirement'] ? $row['requirement'] : __( '(None)', 'wormiss' ) ) . '</td></tr>';
		}
		$head = '';
		foreach ( self::categories() as [ $australia, $new_zealand ] ) {
			$head .= '<th scope="col">' . esc_html( $australia . ' – ' . $new_zealand ) . '</th>';
		}
		return '<table class="widefat striped"><thead><tr>' . $head . '<th scope="col">'
			. esc_html__( 'Program level requirement', 'wormiss' ) . '</th></tr></thead><tbody>' . $list . '</tbody></table>'
			. '<h4>' . esc_html__( 'On the event page', 'wormiss' ) . '</h4>' . self::table( $rows ) . $note;
	}
}
