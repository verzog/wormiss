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
 * "Event details" box. Events made in WordPress have their CPD set there and saved in iMIS.
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
	 * Post meta: the CPD rows set in WordPress for an event made in WordPress, each with its iMIS
	 * row number once saved there. Absent until someone saves the CPD hours tab, and only then
	 * does WordPress change the event's CPD in iMIS.
	 */
	const META = '_wormiss_event_cpd';

	/** Form field marking that the CPD hours tab was sent. */
	const FIELD_SENT = 'wormiss_cpd_sent';

	/** Form field holding the rows. */
	const FIELD_ROWS = 'wormiss_cpd';

	/** Most hours in one category of one row. */
	const MAX_HOURS = 1000;

	/** Most lines an event can have; more is refused, never cut short (that would remove lines). */
	const MAX_ROWS = 50;

	/** Post meta: why the CPD hours tab was last not saved ('' when it was). */
	const META_PROBLEM = '_wormiss_event_cpd_problem';

	/**
	 * The program level requirements iMIS offers (its CPD Hours panel's list), as iMIS stores them.
	 * Blank is "(None)".
	 */
	const REQUIREMENTS = [
		'Addressing health inequities',
		'Culturally safe practice',
		'Maintaining and developing ethical practice',
		'Maintaining and developing professionalism',
	];

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
		$key    = self::cache_key( $event_id );
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
	 * The cache key for an event's rows, per iMIS connection (so a changed iMIS address or account
	 * never shows the old one's rows).
	 *
	 * @param string $event_id iMIS event ID.
	 * @return string
	 */
	private static function cache_key( string $event_id ): string {
		return self::CACHE_PREFIX . md5( Wormiss_Settings::base_url() . "\0" . Wormiss_Settings::username() . "\0" . strtoupper( $event_id ) );
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
		$row['ordinal']     = (int) ( $properties['Ordinal'] ?? 0 );
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
					__( 'From the CPD Hours panel of iMIS event %s. This event\'s CPD comes from iMIS: change it there. The event page shows changes within the cache time.', 'wormiss' ),
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

	/**
	 * Whether this user may set an event's CPD hours here: editors and administrators, on events
	 * made in WordPress (a linked or imported event's CPD is iMIS's).
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	public static function editable( int $post_id ): bool {
		return current_user_can( 'edit_others_posts' ) && ! Wormiss_Event_Sync::is_linked( $post_id );
	}

	/**
	 * Whether WordPress sets this event's CPD (someone has saved its CPD hours tab).
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	public static function owned( int $post_id ): bool {
		return metadata_exists( 'post', $post_id, self::META );
	}

	/**
	 * The rows set in WordPress.
	 *
	 * @param int $post_id Post ID.
	 * @return array[]
	 */
	public static function saved_rows( int $post_id ): array {
		$rows = get_post_meta( $post_id, self::META, true );
		return is_array( $rows ) ? array_values( array_filter( $rows, 'is_array' ) ) : [];
	}

	/**
	 * Hours as typed: a number from 0 to MAX_HOURS, to two decimal places.
	 *
	 * @param mixed $value Raw value.
	 * @return float
	 */
	public static function clean_hours( $value ): float {
		$value = str_replace( ',', '.', trim( (string) $value ) );
		return is_numeric( $value ) ? round( min( (float) self::MAX_HOURS, max( 0.0, (float) $value ) ), 2 ) : 0.0;
	}

	/**
	 * Rows as sent from the tab: cleaned, without rows marked for removal or left empty.
	 *
	 * @param mixed $input Rows from the form.
	 * @return array[]
	 */
	public static function clean_rows( $input ): array {
		$rows = [];
		foreach ( is_array( $input ) ? $input : [] as $row ) {
			if ( ! is_array( $row ) || ! empty( $row['remove'] ) ) {
				continue;
			}
			$clean = [];
			foreach ( array_keys( self::FIELDS ) as $key ) {
				$clean[ $key ] = self::clean_hours( $row[ $key ] ?? '' );
			}
			$requirement          = (string) ( $row['requirement'] ?? '' );
			$clean['requirement'] = in_array( $requirement, self::REQUIREMENTS, true ) ? $requirement : '';
			$clean['ordinal']     = absint( $row['ordinal'] ?? 0 );
			// A line's own key, so a row number iMIS gives it reaches the right line even when
			// the lines were saved again meanwhile.
			$uid          = preg_replace( '/[^a-z0-9]/', '', strtolower( (string) ( $row['uid'] ?? '' ) ) );
			$clean['uid'] = '' !== $uid ? substr( (string) $uid, 0, 20 ) : strtolower( wp_generate_password( 12, false ) );
			if ( 0 === $clean['ordinal'] && '' === $clean['requirement'] && 0.0 === array_sum( array_intersect_key( $clean, self::FIELDS ) ) ) {
				continue; // A blank new row.
			}
			$rows[] = $clean;
		}
		return $rows;
	}

	/**
	 * Save the CPD hours tab (from the Event details box's save, after its nonce check).
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	public static function save( int $post_id ): void {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Checked by Wormiss_Event_Editor::save().
		if ( empty( $_POST[ self::FIELD_SENT ] ) || ! self::editable( $post_id ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Cleaned by clean_rows().
		$input = isset( $_POST[ self::FIELD_ROWS ] ) ? wp_unslash( $_POST[ self::FIELD_ROWS ] ) : [];
		// phpcs:enable
		$rows = self::clean_rows( $input );
		if ( count( $rows ) > self::MAX_ROWS ) {
			// Refused, keeping the lines as they were: cutting them short would remove lines in iMIS.
			update_post_meta(
				$post_id,
				self::META_PROBLEM,
				sprintf(
					/* translators: %d: most lines. */
					__( 'CPD hours were not saved: an event can have at most %d lines.', 'wormiss' ),
					self::MAX_ROWS
				)
			);
			return;
		}
		delete_post_meta( $post_id, self::META_PROBLEM );
		update_post_meta( $post_id, self::META, $rows );
	}

	/**
	 * Record the row number iMIS gave a new line, on the line as now saved (found by its key), so
	 * lines saved again while it was being added keep their changes.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $uid     The line's key.
	 * @param int    $ordinal Its iMIS row number.
	 * @return void
	 */
	private static function record_ordinal( int $post_id, string $uid, int $ordinal ): void {
		wp_cache_delete( $post_id, 'post_meta' );
		$rows = self::saved_rows( $post_id );
		foreach ( $rows as &$row ) {
			if ( '' !== $uid && ( $row['uid'] ?? '' ) === $uid && 0 === (int) ( $row['ordinal'] ?? 0 ) ) {
				$row['ordinal'] = $ordinal;
			}
		}
		unset( $row );
		update_post_meta( $post_id, self::META, $rows );
	}

	/**
	 * Make the event's CPD in iMIS match the rows set in WordPress: rows changed (by their iMIS row
	 * number), added (keeping the number iMIS gives them) and removed. Read fresh, never from the
	 * cache. In a dry run nothing is sent; what would be is logged.
	 *
	 * @param int                 $post_id  Post ID.
	 * @param string              $event_id iMIS event ID.
	 * @param bool                $dry_run  Only log.
	 * @param Wormiss_Rest_Client $client   Client.
	 * @return string What happened, for the event's message ('' for nothing).
	 * @throws Wormiss_Exception On an iMIS failure (rows done so far keep their numbers).
	 */
	public static function push( int $post_id, string $event_id, bool $dry_run, Wormiss_Rest_Client $client ): string {
		$wanted  = self::saved_rows( $post_id );
		$current = [];
		foreach ( $client->get_entities( self::ENTITY, [ 'EventKey' => $event_id ] ) as $properties ) {
			$row = self::row( $properties );
			if ( $row['ordinal'] > 0 ) {
				$current[ $row['ordinal'] ] = $row;
			}
		}
		$plan = [];
		$kept = [];
		foreach ( $wanted as $i => $row ) {
			$ordinal = (int) ( $row['ordinal'] ?? 0 );
			if ( $ordinal > 0 && isset( $current[ $ordinal ] ) ) {
				$kept[ $ordinal ] = true;
				if ( self::same( $row, $current[ $ordinal ] ) ) {
					continue;
				}
				$plan[] = [ 'PUT', $i, $ordinal ];
				continue;
			}
			// New here, or its iMIS row was removed in iMIS: added (again).
			$plan[] = [ 'POST', $i, 0 ];
		}
		foreach ( array_keys( $current ) as $ordinal ) {
			if ( ! isset( $kept[ $ordinal ] ) ) {
				$plan[] = [ 'DELETE', -1, $ordinal ];
			}
		}
		if ( ! $plan ) {
			return '';
		}
		$done = 0;
		try {
			foreach ( $plan as [ $method, $i, $ordinal ] ) {
				$keys = $ordinal > 0 ? [ $event_id, (string) $ordinal ] : [];
				$body = $i >= 0 ? self::entity_body( $event_id, $wanted[ $i ], $ordinal ) : null;
				if ( $dry_run ) {
					Wormiss_Logger::record_call(
						'DRY RUN ' . $method . ' ' . Wormiss_Rest_Client::entity_path( self::ENTITY, $keys ),
						Wormiss_Logger::STATUS_SUCCESS,
						null,
						null !== $body ? (string) wp_json_encode( $body ) : null
					);
				} elseif ( 'PUT' === $method ) {
					$client->update_entity( self::ENTITY, $keys, (array) $body );
				} elseif ( 'DELETE' === $method ) {
					$client->delete_entity( self::ENTITY, $keys );
				} else {
					$ordinal = self::new_ordinal( $client->create_entity( self::ENTITY, (array) $body ) );
					// Kept straight away, so a failure later never adds this row a second time.
					self::record_ordinal( $post_id, (string) ( $wanted[ $i ]['uid'] ?? '' ), $ordinal );
				}
				++$done;
			}
		} finally {
			if ( ! $dry_run ) {
				delete_transient( self::cache_key( $event_id ) );
			}
		}
		return $dry_run
			? sprintf(
				/* translators: %d: number of changes. */
				_n( 'CPD hours: %d change would be made.', 'CPD hours: %d changes would be made.', $done, 'wormiss' ),
				$done
			)
			: sprintf(
				/* translators: %d: number of changes. */
				_n( 'CPD hours: %d change saved.', 'CPD hours: %d changes saved.', $done, 'wormiss' ),
				$done
			);
	}

	/**
	 * The row number iMIS gave a new row: from its Ordinal, else the last part of its identity.
	 *
	 * @param object $saved The row as iMIS saved it.
	 * @return int
	 * @throws Wormiss_Exception When there is none (the row would be added again next time).
	 */
	private static function new_ordinal( object $saved ): int {
		$ordinal = (int) ( Wormiss_Rest_Client::properties( $saved )['Ordinal'] ?? 0 );
		if ( $ordinal < 1 ) {
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- iMIS fields.
			$keys    = $saved->Identity->IdentityElements->{'$values'} ?? [];
			$ordinal = is_array( $keys ) && count( $keys ) > 1 ? (int) end( $keys ) : 0;
		}
		if ( $ordinal < 1 ) {
			throw new Wormiss_Exception( __( 'iMIS saved a CPD line but did not say its row number. Check the event\'s CPD Hours panel in iMIS.', 'wormiss' ) );
		}
		return $ordinal;
	}

	/**
	 * Whether a WordPress row and an iMIS row hold the same hours and requirement.
	 *
	 * @param array $a Row.
	 * @param array $b Row.
	 * @return bool
	 */
	private static function same( array $a, array $b ): bool {
		foreach ( array_keys( self::FIELDS ) as $key ) {
			if ( abs( (float) ( $a[ $key ] ?? 0 ) - (float) ( $b[ $key ] ?? 0 ) ) > 0.001 ) {
				return false;
			}
		}
		return (string) ( $a['requirement'] ?? '' ) === (string) ( $b['requirement'] ?? '' );
	}

	/**
	 * An EventCPD row for iMIS (GenericEntityData), as iMIS sends them.
	 *
	 * @param string $event_id iMIS event ID.
	 * @param array  $row      Row.
	 * @param int    $ordinal  Its iMIS row number (0 for a new row: iMIS gives it one).
	 * @return array
	 */
	public static function entity_body( string $event_id, array $row, int $ordinal ): array {
		$identity   = static fn( string $type, array $keys ) => [
			'$type'            => 'Asi.Soa.Core.DataContracts.IdentityData, Asi.Contracts',
			'EntityTypeName'   => $type,
			'IdentityElements' => [
				'$type'   => 'System.Collections.ObjectModel.Collection`1[[System.String, mscorlib]], mscorlib',
				'$values' => $keys,
			],
		];
		$property   = static fn( string $name, $value ) => [
			'$type' => 'Asi.Soa.Core.DataContracts.GenericPropertyData, Asi.Contracts',
			'Name'  => $name,
			'Value' => $value,
		];
		$decimal    = static fn( $value ) => [
			'$type'  => 'System.Decimal',
			'$value' => round( (float) $value, 2 ),
		];
		$properties = [ $property( 'EventKey', $event_id ) ];
		if ( $ordinal > 0 ) {
			$properties[] = $property(
				'Ordinal',
				[
					'$type'  => 'System.Int32',
					'$value' => $ordinal,
				]
			);
		}
		foreach ( self::FIELDS as $key => $name ) {
			$properties[] = $property( $name, $decimal( $row[ $key ] ?? 0 ) );
		}
		$properties[] = $property( self::REQUIREMENT, (string) ( $row['requirement'] ?? '' ) );
		return [
			'$type'                       => 'Asi.Soa.Core.DataContracts.GenericEntityData, Asi.Contracts',
			'EntityTypeName'              => self::ENTITY,
			'PrimaryParentEntityTypeName' => 'Event',
			'Identity'                    => $identity( self::ENTITY, $ordinal > 0 ? [ $event_id, (string) $ordinal ] : [ $event_id ] ),
			'PrimaryParentIdentity'       => $identity( 'Event', [ $event_id ] ),
			'Properties'                  => [
				'$type'   => 'Asi.Soa.Core.DataContracts.GenericPropertyDataCollection, Asi.Contracts',
				'$values' => $properties,
			],
		];
	}

	/**
	 * The editable CPD hours tab: one row per line, with Remove, and an Add row button. Starts
	 * from the rows set here, else (an event already in iMIS) from iMIS's, so a first save keeps
	 * them.
	 *
	 * @param int $post_id Post ID.
	 * @return string HTML.
	 */
	public static function form_html( int $post_id ): string {
		$rows = self::saved_rows( $post_id );
		if ( ! self::owned( $post_id ) && '' !== self::event_id( $post_id ) ) {
			$read = self::rows( self::event_id( $post_id ) );
			if ( null === $read ) {
				// Not read: no form, so a save cannot take iMIS's lines for none (and remove them).
				return '<p class="description">' . esc_html__( 'The CPD hours could not be read from iMIS, so they cannot be changed now. Try again later (see the audit log).', 'wormiss' ) . '</p>';
			}
			$rows = $read;
		}
		$problem = (string) get_post_meta( $post_id, self::META_PROBLEM, true );
		$head    = '';
		foreach ( self::categories() as [ $australia, $new_zealand ] ) {
			$head .= '<th scope="col">' . esc_html( $australia . ' – ' . $new_zealand ) . '</th>';
		}
		$html  = '<input type="hidden" name="' . esc_attr( self::FIELD_SENT ) . '" value="1" />'
			. ( '' !== $problem ? '<div class="notice notice-error inline"><p>' . esc_html( $problem ) . '</p></div>' : '' )
			. '<p class="description">' . esc_html__( 'Hours in each category (decimals allowed, e.g. 1.5), one line per program level requirement.', 'wormiss' )
			. ' ' . esc_html__( 'On update they are saved in the event\'s CPD Hours panel in iMIS: lines added, changed and removed to match.', 'wormiss' ) . '</p>'
			. '<table class="widefat wormiss-cpd-rows"><thead><tr>' . $head . '<th scope="col">' . esc_html__( 'Program level requirement', 'wormiss' )
			. '</th><th scope="col">' . esc_html__( 'Remove', 'wormiss' ) . '</th></tr></thead><tbody>';
		$index = 0;
		foreach ( $rows as $row ) {
			$html .= self::form_row( (string) $index++, $row );
		}
		$html .= '</tbody></table>';
		// A blank line to copy for "Add line" (the first one shows without JavaScript).
		$html .= '<template class="wormiss-cpd-template">' . self::form_row( '__i__', [] ) . '</template>'
			. '<p><button type="button" class="button wormiss-cpd-add" data-next="' . esc_attr( (string) $index ) . '" data-max="'
			. esc_attr( (string) self::MAX_ROWS ) . '">'
			. esc_html__( 'Add line', 'wormiss' ) . '</button></p>';
		$html .= '<noscript><table class="widefat"><tbody>' . self::form_row( (string) $index, [] ) . '</tbody></table></noscript>';
		return $html;
	}

	/**
	 * One editable line.
	 *
	 * @param string $index Field index.
	 * @param array  $row   Row ([] for blank).
	 * @return string HTML.
	 */
	private static function form_row( string $index, array $row ): string {
		$name = static fn( string $field ) => self::FIELD_ROWS . '[' . $index . '][' . $field . ']';
		$html = '<tr>';
		foreach ( self::categories() as $key => [ $australia, $new_zealand ] ) {
			$value = isset( $row[ $key ] ) ? self::hours( (float) $row[ $key ] ) : '';
			$html .= '<td><input type="number" min="0" max="' . esc_attr( (string) self::MAX_HOURS ) . '" step="0.01" class="small-text" name="'
				. esc_attr( $name( $key ) ) . '" value="' . esc_attr( $value ) . '" aria-label="' . esc_attr( $australia . ' – ' . $new_zealand ) . '" /></td>';
		}
		$html .= '<td><select name="' . esc_attr( $name( 'requirement' ) ) . '" aria-label="' . esc_attr__( 'Program level requirement', 'wormiss' ) . '">'
			. '<option value="">' . esc_html__( '(None)', 'wormiss' ) . '</option>';
		foreach ( self::REQUIREMENTS as $requirement ) {
			$html .= '<option value="' . esc_attr( $requirement ) . '"' . selected( (string) ( $row['requirement'] ?? '' ), $requirement, false ) . '>'
				. esc_html( $requirement ) . '</option>';
		}
		$html .= '</select><input type="hidden" name="' . esc_attr( $name( 'ordinal' ) ) . '" value="' . esc_attr( (string) (int) ( $row['ordinal'] ?? 0 ) ) . '" />'
			. '<input type="hidden" name="' . esc_attr( $name( 'uid' ) ) . '" value="' . esc_attr( (string) ( $row['uid'] ?? '' ) ) . '" /></td>'
			. '<td><input type="checkbox" value="1" name="' . esc_attr( $name( 'remove' ) ) . '" aria-label="' . esc_attr__( 'Remove this line', 'wormiss' ) . '" /></td></tr>';
		return $html;
	}
}
