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
 * Sends WordPress events to iMIS in the background, in batches: "Send events to iMIS now" and daily.
 *
 * @package    wormiss
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined( 'ABSPATH' ) || exit;

/**
 * Finds published Events made in WordPress that iMIS does not have up to date (never sent,
 * edited since they were last saved there, or last sent with a problem) and sends them, a
 * batch at a time, through the usual sync (so the sending switch and dry run apply). Events
 * linked to, or imported from, iMIS are never sent.
 */
class Wormiss_Event_Push {

	/** Cron hook for the daily run. */
	const HOOK = 'wormiss_event_push';

	/** Cron hook for the next batch. */
	const MORE_HOOK = 'wormiss_event_push_more';

	/** Option holding the last run's report. */
	const REPORT_OPTION = 'wormiss_event_push_report';

	/** Option: the events this run has already sent (post ID => true). */
	const DONE_OPTION = 'wormiss_event_push_done';

	/** Most events sent in one run (each is a few iMIS calls), so a page never times out. */
	const BATCH = 10;

	/** Seconds before the next batch runs. */
	const BATCH_GAP = MINUTE_IN_SECONDS;

	/** Option that holds the lock while a batch runs (taken atomically). */
	const LOCK_OPTION = 'wormiss_event_push_lock';

	/** Seconds one run holds its lock. */
	const LOCK_SECONDS = 600;

	/**
	 * Hook in.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( self::HOOK, [ __CLASS__, 'run' ] );
		add_action( self::MORE_HOOK, [ __CLASS__, 'run_more' ] );
		add_action( 'init', [ __CLASS__, 'maybe_schedule' ] );
		add_action( 'admin_post_wormiss_event_push_now', [ __CLASS__, 'handle_run_now' ] );
	}

	/**
	 * Keep the daily run scheduled while sending events is on, and none while it is off.
	 *
	 * @return void
	 */
	public static function maybe_schedule(): void {
		$next = wp_next_scheduled( self::HOOK );
		if ( Wormiss_Settings::get( 'event_sync_enabled' ) && ! $next ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::HOOK );
		} elseif ( ! Wormiss_Settings::get( 'event_sync_enabled' ) && $next ) {
			wp_clear_scheduled_hook( self::HOOK );
		}
	}

	/**
	 * The Send now button (Settings): send a batch and go back.
	 *
	 * @return void
	 */
	public static function handle_run_now(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'wormiss' ), 403 );
		}
		check_admin_referer( 'wormiss_event_push_now' );
		self::run();
		wp_safe_redirect( Wormiss_Admin::settings_url( 'events' ) . '#wormiss-event-push' );
		exit;
	}

	/**
	 * The next batch (cron): carries on after the events earlier batches sent.
	 *
	 * @return array The report.
	 */
	public static function run_more(): array {
		return self::run( true );
	}

	/**
	 * Send one batch of events that iMIS does not have up to date.
	 *
	 * @param bool $carry_on Carry on from the last batch (else start afresh).
	 * @return array The report (also saved for the settings page).
	 */
	public static function run( $carry_on = false ): array {
		$report = [
			'time'      => time(),
			'status'    => 'ok',
			'dry_run'   => (bool) Wormiss_Settings::get( 'event_sync_dry_run' ),
			'found'     => 0,
			'sent'      => 0,
			'checked'   => 0,
			'problems'  => 0,
			'remaining' => 0,
		];
		if ( ! Wormiss_Settings::get( 'event_sync_enabled' ) || ! Wormiss_Settings::is_configured() ) {
			$report['status'] = 'off';
			return self::save( $report );
		}
		if ( ! Wormiss_Event_Import::take_lock( self::LOCK_OPTION, self::LOCK_SECONDS ) ) {
			$report['status'] = 'busy';
			return $report;
		}
		try {
			$done = true === $carry_on ? get_option( self::DONE_OPTION, [] ) : [];
			$done = is_array( $done ) ? $done : [];
			$todo = array_values( array_filter( self::due(), static fn( $id ) => ! isset( $done[ $id ] ) ) );

			$report['found'] = count( $todo );
			foreach ( array_slice( $todo, 0, self::BATCH ) as $post_id ) {
				$done[ $post_id ] = true;
				$result           = Wormiss_Event_Sync::sync( $post_id );
				if ( 'synced' === $result ) {
					++$report['sent'];
				} elseif ( 'dry_run' === $result ) {
					++$report['checked'];
				} elseif ( 'error' === $result ) {
					++$report['problems'];
				}
			}
			$report['remaining'] = max( 0, count( $todo ) - self::BATCH );
			if ( $report['remaining'] > 0 ) {
				update_option( self::DONE_OPTION, $done, false );
				if ( ! wp_next_scheduled( self::MORE_HOOK ) ) {
					wp_schedule_single_event( time() + self::BATCH_GAP, self::MORE_HOOK );
				}
			} else {
				delete_option( self::DONE_OPTION );
			}
		} finally {
			delete_option( self::LOCK_OPTION );
		}
		return self::save( $report );
	}

	/**
	 * Published, editor-approved Events made in WordPress that iMIS does not have up to date: never
	 * sent, edited since they were last saved there, or last sent with a problem or still waiting.
	 *
	 * @return int[] Post IDs, oldest first.
	 */
	public static function due(): array {
		$ids  = get_posts(
			[
				'post_type'        => Wormiss_Event_Sync::POST_TYPE,
				'post_status'      => 'publish',
				'numberposts'      => -1,
				'fields'           => 'ids',
				'orderby'          => 'ID',
				'order'            => 'ASC',
				'suppress_filters' => true,
			]
		);
		$out  = [];
		$live = ! Wormiss_Settings::get( 'event_sync_dry_run' );
		foreach ( $ids as $post_id ) {
			$post_id = (int) $post_id;
			if ( Wormiss_Event_Sync::is_linked( $post_id ) ) {
				continue; // Linked to, or imported from, iMIS: only ever read.
			}
			// Only events an editor last saved, as when an event is published (an Author's save
			// clears the approval, so their new events and edits wait for an editor).
			if ( ! get_post_meta( $post_id, Wormiss_Event_Sync::META_APPROVED, true ) ) {
				continue;
			}
			$status = (string) get_post_meta( $post_id, Wormiss_Event_Sync::META_STATUS, true );
			$synced = (int) get_post_meta( $post_id, Wormiss_Event_Sync::META_SYNCED_AT, true );
			$edited = (int) get_post_modified_time( 'U', true, $post_id );
			$due    = '' === (string) get_post_meta( $post_id, Wormiss_Event_Sync::META_IMIS_ID, true )
				|| in_array( $status, [ 'error', 'queued', '' ], true )
				// Only checked in a dry run: due once sending is live.
				|| ( 'dry_run' === $status && $live )
				// Edited since it was last saved in iMIS (events sent before this was kept count as up to date).
				|| ( $synced > 0 && $edited > $synced );
			if ( $due ) {
				$out[] = $post_id;
			}
		}
		return $out;
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
				( $report['dry_run'] ? 'DRY RUN ' : '' ) . 'EVENT PUSH',
				$report['problems'] > 0 ? Wormiss_Logger::STATUS_ERROR : Wormiss_Logger::STATUS_SUCCESS,
				$report['problems'] > 0 ? __( 'Some events could not be sent; see each event\'s iMIS panel.', 'wormiss' ) : null,
				null,
				(string) wp_json_encode( $report )
			);
		}
		return $report;
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
		if ( 'off' === $report['status'] ) {
			/* translators: %s: date and time. */
			return sprintf( __( 'Last run %s: sending events to iMIS is off, so nothing was sent.', 'wormiss' ), $when );
		}
		$text = sprintf(
			/* translators: 1: date and time, 2: events due, 3: saved in iMIS, 4: checked (dry run), 5: problems. */
			__( 'Last run %1$s: %2$d events to send; %3$d saved in iMIS, %4$d checked (dry run), %5$d with a problem.', 'wormiss' ),
			$when,
			(int) $report['found'],
			(int) $report['sent'],
			(int) $report['checked'],
			(int) $report['problems']
		);
		if ( ! empty( $report['remaining'] ) ) {
			/* translators: %d: events still to send. */
			$text .= ' ' . sprintf( __( 'Still going: %d more events to send in the background.', 'wormiss' ), (int) $report['remaining'] );
		}
		return $text;
	}
}
