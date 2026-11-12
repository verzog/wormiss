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
 * Imports upcoming iMIS events as linked Event pages (drafts for editors to publish).
 *
 * @package    wormiss
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined( 'ABSPATH' ) || exit;

/**
 * Reads the iMIS events list (the same events the events block shows) and makes a linked
 * Event page, as a draft, for each one that has none yet. Pages already linked get their title
 * and kept details brought up to date. Nothing is ever changed in iMIS, and pages are never
 * removed: finished events keep their pages. Off until an administrator turns it on.
 */
class Wormiss_Event_Import {

	/** Cron hook for the daily import. */
	const HOOK = 'wormiss_event_import';

	/** Option holding the last run's report. */
	const REPORT_OPTION = 'wormiss_event_import_report';

	/** Post meta: set on pages the import made. */
	const META_IMPORTED = '_wormiss_event_imported';

	/** Most pages made in one run (the rest come on the next run). */
	const MAX_CREATE = 100;

	/** Option used as the one-run-at-a-time lock (its value is when it was taken). */
	const LOCK_OPTION = 'wormiss_event_import_lock';

	/** Seconds after which a lock left by a run that died is taken over. */
	const LOCK_SECONDS = 600;

	/** Most notes kept in a report. */
	const MAX_NOTES = 20;

	/**
	 * Hook in.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( self::HOOK, [ __CLASS__, 'run' ] );
		add_action( 'init', [ __CLASS__, 'maybe_schedule' ] );
		add_action( 'admin_post_wormiss_event_import_now', [ __CLASS__, 'handle_run_now' ] );
	}

	/**
	 * Keep the daily import scheduled while it is on, and none while it is off.
	 *
	 * @return void
	 */
	public static function maybe_schedule(): void {
		$next = wp_next_scheduled( self::HOOK );
		if ( Wormiss_Settings::get( 'event_import_enabled' ) && ! $next ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::HOOK );
		} elseif ( ! Wormiss_Settings::get( 'event_import_enabled' ) && $next ) {
			wp_clear_scheduled_hook( self::HOOK );
		}
	}

	/**
	 * The Import now button (Settings): import and go back.
	 *
	 * @return void
	 */
	public static function handle_run_now(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'wormiss' ), 403 );
		}
		check_admin_referer( 'wormiss_event_import_now' );
		self::run();
		wp_safe_redirect( Wormiss_Admin::settings_url() . '#wormiss-event-import' );
		exit;
	}

	/**
	 * Import upcoming iMIS events. Nothing changes if iMIS cannot be read.
	 *
	 * @return array The report (also saved for the settings page).
	 */
	public static function run(): array {
		$report = [
			'time'    => time(),
			'status'  => 'ok',
			'read'    => 0,
			'created' => 0,
			'updated' => 0,
			'skipped' => 0,
			'notes'   => [],
		];
		if ( ! Wormiss_Settings::get( 'event_import_enabled' ) || ! Wormiss_Settings::is_configured() ) {
			$report['status'] = 'off';
			return self::save( $report );
		}
		if ( ! self::lock() ) {
			$report['status'] = 'busy';
			return $report;
		}
		try {
			return self::import( $report );
		} finally {
			delete_option( self::LOCK_OPTION );
		}
	}

	/**
	 * Take the lock in one database step, so two runs started together cannot both go ahead
	 * (and make two pages for one event). A lock older than LOCK_SECONDS is taken over.
	 *
	 * @return bool Whether this run holds the lock.
	 */
	private static function lock(): bool {
		global $wpdb;
		for ( $try = 0; $try < 2; $try++ ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- INSERT IGNORE is the atomic part; options have no such API.
			$taken = $wpdb->query(
				$wpdb->prepare(
					"INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')",
					self::LOCK_OPTION,
					(string) time()
				)
			);
			wp_cache_delete( self::LOCK_OPTION, 'options' );
			wp_cache_delete( 'notoptions', 'options' );
			if ( 1 === (int) $taken ) {
				return true;
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Read past the options cache.
			$since = (int) $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", self::LOCK_OPTION ) );
			if ( $since > time() - self::LOCK_SECONDS ) {
				return false;
			}
			delete_option( self::LOCK_OPTION ); // Left by a run that died: take it over.
		}
		return false;
	}

	/**
	 * Read the events and make or update their pages.
	 *
	 * @param array $report Report so far.
	 * @return array The report.
	 */
	private static function import( array $report ): array {
		$since = (string) Wormiss_Settings::get( 'event_import_since' );
		// A fresh read: new events come in today, and an earlier copy is never imported.
		try {
			if ( '' !== $since ) {
				// Past events too: read from that date (not kept in the events cache).
				$raw    = ( new Wormiss_Rest_Client() )->get_events( $since );
				$events = self::with_past( array_values( array_filter( array_map( [ 'Wormiss_Events', 'normalise' ], $raw ) ) ), $since );
			} else {
				// The same events the events block shows: real, public, listed statuses, not finished.
				$events = Wormiss_Events::upcoming( Wormiss_Events::get_events_fresh(), '', time() );
			}
		} catch ( Wormiss_Exception $e ) {
			$report['status']  = 'error';
			$report['notes'][] = $e->getMessage();
			return self::save( $report );
		}
		$report['read'] = count( $events );
		foreach ( $events as $event ) {
			unset( $event['_start'] );
			$post_id = self::page_for( (string) $event['id'] );
			if ( 0 === $post_id ) {
				if ( $report['created'] >= self::MAX_CREATE ) {
					++$report['skipped'];
					continue;
				}
				if ( self::create( $event, $report ) ) {
					++$report['created'];
				}
				continue;
			}
			if ( self::refresh( $post_id, $event ) ) {
				++$report['updated'];
			} else {
				++$report['skipped'];
			}
		}
		if ( $report['created'] >= self::MAX_CREATE && $report['skipped'] > 0 ) {
			self::note(
				$report,
				/* translators: %d: pages made per run. */
				sprintf( __( 'Made the first %d pages; the rest come on the next import.', 'wormiss' ), self::MAX_CREATE )
			);
		}
		return self::save( $report );
	}

	/**
	 * Upcoming events as the events block lists them, plus finished ones that started on or
	 * after $since. Finished events skip publish dates and may be expired or archived in iMIS
	 * (as most past events are); templates, other unpublished events and unlisted statuses
	 * stay out.
	 *
	 * @param array[] $events Events from Wormiss_Events::normalise().
	 * @param string  $since  Earliest start, YYYY-MM-DD.
	 * @return array[]
	 */
	public static function with_past( array $events, string $since ): array {
		$now      = time();
		$from     = Wormiss_Events::timestamp( $since ) ?? 0;
		$statuses = array_map( 'strtolower', (array) Wormiss_Settings::get( 'event_statuses' ) );
		$hidden   = array_diff( Wormiss_Events::HIDDEN_STATES, [ 'expired', 'archived' ] );
		$out      = Wormiss_Events::upcoming( $events, '', $now );
		foreach ( $events as $event ) {
			$start = Wormiss_Events::timestamp( $event['start'] );
			$end   = Wormiss_Events::timestamp( $event['end'] ) ?? $start;
			if ( null === $start || $start < $from || $end >= $now ) {
				continue; // No date, too early, or not finished (upcoming() decided those).
			}
			if ( in_array( strtolower( $event['template'] ), Wormiss_Events::TEMPLATE_STATES, true )
				|| in_array( strtolower( $event['publishing'] ), $hidden, true )
				|| ( $statuses && ! in_array( strtolower( $event['status'] ), $statuses, true ) ) ) {
				continue;
			}
			$out[] = $event;
		}
		return $out;
	}

	/**
	 * The Event page linked to an iMIS event (trashed ones too, so a page someone deleted is
	 * not made again), or 0.
	 *
	 * @param string $imis_id iMIS event ID.
	 * @return int
	 */
	private static function page_for( string $imis_id ): int {
		// phpcs:disable WordPress.DB.SlowDBQuery -- One meta lookup per event.
		$found = get_posts(
			[
				'post_type'        => Wormiss_Event_Sync::POST_TYPE,
				'post_status'      => [ 'publish', 'future', 'draft', 'pending', 'private', 'trash' ],
				'meta_key'         => Wormiss_Event_Sync::META_IMIS_ID,
				'meta_value'       => $imis_id,
				'numberposts'      => 1,
				'fields'           => 'ids',
				'suppress_filters' => true,
			]
		);
		// phpcs:enable
		return $found ? (int) $found[0] : 0;
	}

	/**
	 * Make a draft Event page linked to an iMIS event.
	 *
	 * @param array $event  Event from Wormiss_Events::normalise().
	 * @param array $report Report, for notes.
	 * @return bool
	 */
	private static function create( array $event, array &$report ): bool {
		$content = '' !== $event['description']
			? '<!-- wp:paragraph --><p>' . esc_html( $event['description'] ) . '</p><!-- /wp:paragraph -->'
			: '';
		// The link is saved with the page, so it is never queued to be sent to iMIS. Slashed, as
		// WordPress strips one level of backslashes from what it saves.
		$post_id = wp_insert_post(
			wp_slash(
				[
					'post_type'    => Wormiss_Event_Sync::POST_TYPE,
					'post_status'  => 'draft',
					'post_title'   => $event['name'],
					'post_content' => $content,
					'meta_input'   => [
						Wormiss_Event_Sync::META_EXISTING => $event['id'],
						Wormiss_Event_Sync::META_IMIS_ID  => $event['id'],
						Wormiss_Event_Sync::META_LINKED   => 1,
						Wormiss_Event_Sync::META_LINKED_DETAILS => $event,
						Wormiss_Event_Sync::META_STATUS   => 'linked',
						Wormiss_Event_Sync::META_MESSAGE  => sprintf(
							/* translators: %s: iMIS event ID. */
							__( 'Imported from iMIS event %s. Its details come from iMIS; WordPress never changes or cancels it.', 'wormiss' ),
							$event['id']
						),
						self::META_IMPORTED               => 1,
					],
				]
			),
			true
		);
		if ( is_wp_error( $post_id ) ) {
			/* translators: 1: iMIS event ID, 2: reason. */
			self::note( $report, sprintf( __( 'Event %1$s: no page made (%2$s).', 'wormiss' ), $event['id'], $post_id->get_error_message() ) );
			return false;
		}
		return true;
	}

	/**
	 * Bring a linked page's title and kept details up to date. Pages WordPress sent to iMIS, and
	 * trashed pages, are left alone.
	 *
	 * @param int   $post_id Post ID.
	 * @param array $event   Event from Wormiss_Events::normalise().
	 * @return bool Whether the page was changed.
	 */
	private static function refresh( int $post_id, array $event ): bool {
		if ( 'trash' === get_post_status( $post_id ) || ! get_post_meta( $post_id, Wormiss_Event_Sync::META_LINKED, true ) ) {
			return false;
		}
		$changed = get_post_meta( $post_id, Wormiss_Event_Sync::META_LINKED_DETAILS, true ) !== $event;
		if ( $changed ) {
			update_post_meta( $post_id, Wormiss_Event_Sync::META_LINKED_DETAILS, wp_slash( $event ) );
		}
		if ( '' !== $event['name'] && get_post_field( 'post_title', $post_id, 'raw' ) !== $event['name'] ) {
			wp_update_post(
				wp_slash(
					[
						'ID'         => $post_id,
						'post_title' => $event['name'],
					]
				)
			);
			$changed = true;
		}
		return $changed;
	}

	/**
	 * Add a note to a report (up to MAX_NOTES).
	 *
	 * @param array  $report Report.
	 * @param string $note   Note.
	 * @return void
	 */
	private static function note( array &$report, string $note ): void {
		if ( count( $report['notes'] ) < self::MAX_NOTES ) {
			$report['notes'][] = $note;
		}
	}

	/**
	 * Save the report and log the run.
	 *
	 * @param array $report Report.
	 * @return array
	 */
	private static function save( array $report ): array {
		update_option( self::REPORT_OPTION, $report, false );
		if ( 'off' !== $report['status'] ) {
			Wormiss_Logger::record_call(
				'EVENT IMPORT',
				'error' === $report['status'] ? Wormiss_Logger::STATUS_ERROR : Wormiss_Logger::STATUS_SUCCESS,
				'error' === $report['status'] ? implode( ' ', $report['notes'] ) : null,
				null,
				(string) wp_json_encode( $report )
			);
		}
		return $report;
	}

	/**
	 * The last run's notes, for the settings page.
	 *
	 * @return string[]
	 */
	public static function notes(): array {
		$report = get_option( self::REPORT_OPTION );
		if ( ! is_array( $report ) || 'error' === ( $report['status'] ?? '' ) ) {
			return [];
		}
		return array_map( 'strval', (array) ( $report['notes'] ?? [] ) );
	}

	/**
	 * The last run, in words, for the settings page ('' if it has never run).
	 *
	 * @return string
	 */
	public static function summary(): string {
		$report = get_option( self::REPORT_OPTION );
		if ( ! is_array( $report ) || empty( $report['time'] ) ) {
			return '';
		}
		$when = wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $report['time'] );
		if ( 'error' === $report['status'] ) {
			/* translators: 1: date and time, 2: reason. */
			return sprintf( __( 'Last import %1$s: iMIS could not be read, so nothing changed (%2$s).', 'wormiss' ), $when, implode( ' ', $report['notes'] ) );
		}
		if ( 'off' === $report['status'] ) {
			/* translators: %s: date and time. */
			return sprintf( __( 'Last import %s: importing is off, so nothing changed.', 'wormiss' ), $when );
		}
		return sprintf(
			/* translators: 1: date and time, 2: events read, 3: pages made, 4: pages updated, 5: unchanged. */
			__( 'Last import %1$s: %2$d upcoming events in iMIS; %3$d draft pages made, %4$d updated, %5$d unchanged.', 'wormiss' ),
			$when,
			(int) $report['read'],
			(int) $report['created'],
			(int) $report['updated'],
			(int) $report['skipped']
		);
	}
}
