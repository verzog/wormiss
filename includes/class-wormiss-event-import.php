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
 * Event page for each one that has none yet, with iMIS's name, description, event category (from
 * its category) and image; published or a draft, as Settings says. Later imports keep those
 * in step with iMIS. Nothing is ever changed in iMIS, and pages are never
 * removed: finished events keep their pages. Off until an administrator turns it on.
 */
class Wormiss_Event_Import {

	/** Cron hook for the daily import. */
	const HOOK = 'wormiss_event_import';

	/** Option holding the last run's report. */
	const REPORT_OPTION = 'wormiss_event_import_report';

	/** Post meta: set on pages the import made. */
	const META_IMPORTED = '_wormiss_event_imported';

	/** Post meta: a fingerprint of the iMIS name and description the page last took. */
	const META_SOURCE = '_wormiss_event_import_source';

	/** Post meta: set once the import has published the page (so an editor's unpublishing stands). */
	const META_PUBLISHED = '_wormiss_event_import_published';

	/** Post meta: the iMIS image address last used for the featured image. */
	const META_IMAGE = '_wormiss_event_import_image';

	/** Post meta: the attachment the import made the featured image. */
	const META_IMAGE_ID = '_wormiss_event_import_image_id';

	/** Cron hook that carries on an import with more changes than one batch. */
	const MORE_HOOK = 'wormiss_event_import_more';

	/**
	 * Most pages made or changed in one run. Kept small: runs happen in the background during
	 * visits to the site, so each must be light.
	 */
	const BATCH = 5;

	/** Most images downloaded in one run (each is saved in several sizes, which is heavy). */
	const MAX_IMAGES = 2;

	/** Seconds before the next batch runs. */
	const BATCH_GAP = 2 * MINUTE_IN_SECONDS;

	/** Option: the events read at the start of an import, kept for its later batches. */
	const LIST_OPTION = 'wormiss_event_import_list';

	/** Seconds the kept list is used for; an older one is read again from iMIS. */
	const LIST_MAX_AGE = 30 * MINUTE_IN_SECONDS;

	/** Option: the iMIS event IDs this import has already dealt with (event ID => true). */
	const DONE_OPTION = 'wormiss_event_import_done';

	/** Option used as the one-run-at-a-time lock (its value is when it was taken). */
	const LOCK_OPTION = 'wormiss_event_import_lock';

	/** Seconds after which a lock left by a run that died is taken over. */
	const LOCK_SECONDS = 600;

	/** Most iMIS events read for a past-events import; more is an error, never a part-import. */
	const MAX_READ = 10000;

	/** Most notes kept in a report. */
	const MAX_NOTES = 20;

	/**
	 * Images downloaded so far in this run.
	 *
	 * @var int
	 */
	private static $images = 0;

	/**
	 * Hook in.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( self::HOOK, [ __CLASS__, 'run' ] );
		add_action( self::MORE_HOOK, [ __CLASS__, 'run_more' ] );
		add_action( 'init', [ __CLASS__, 'maybe_schedule' ] );
		add_action( 'admin_post_wormiss_event_import_now', [ __CLASS__, 'handle_run_now' ] );
		add_action( 'transition_post_status', [ __CLASS__, 'note_published' ], 10, 3 );
	}

	/**
	 * Remember that an imported page has been live, whoever published it, so that once an
	 * editor takes it down the import never publishes it again.
	 *
	 * @param string  $new_status New status.
	 * @param string  $old_status Old status.
	 * @param WP_Post $post       Post.
	 * @return void
	 */
	public static function note_published( $new_status, $old_status, $post ): void {
		unset( $old_status ); // Only where the page is going matters.
		if ( 'publish' === $new_status && $post instanceof WP_Post && Wormiss_Event_Sync::POST_TYPE === $post->post_type
			&& get_post_meta( $post->ID, self::META_IMPORTED, true ) ) {
			update_post_meta( $post->ID, self::META_PUBLISHED, 1 );
		}
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
		wp_safe_redirect( Wormiss_Admin::settings_url( 'events' ) . '#wormiss-event-import' );
		exit;
	}

	/**
	 * The next batch of an import (cron): carries on after the events the last batch reached.
	 *
	 * @return array The report.
	 */
	public static function run_more(): array {
		return self::run( true );
	}

	/**
	 * Import iMIS events, one batch of page changes at a time. Nothing changes if iMIS cannot
	 * be read.
	 *
	 * @param bool $carry_on Carry on from the last batch (else start from the first event).
	 * @return array The report (also saved for the settings page).
	 */
	public static function run( $carry_on = false ): array {
		$done   = true === $carry_on ? get_option( self::DONE_OPTION, [] ) : [];
		$done   = is_array( $done ) ? $done : [];
		$report = [
			'time'      => time(),
			'status'    => 'ok',
			'read'      => 0,
			'created'   => 0,
			'updated'   => 0,
			'skipped'   => 0,
			'remaining' => 0,
			'notes'     => [],
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
			return self::import( $report, $done, true === $carry_on );
		} finally {
			delete_option( self::LOCK_OPTION );
		}
	}

	/**
	 * Take the import lock (see take_lock()).
	 *
	 * @return bool Whether this run holds the lock.
	 */
	private static function lock(): bool {
		return self::take_lock( self::LOCK_OPTION, self::LOCK_SECONDS );
	}

	/**
	 * Take a lock in one database step, so two runs started together cannot both go ahead
	 * (and make two pages for one event). A lock older than $seconds is taken over.
	 * Release it with delete_option( $name ).
	 *
	 * @param string $name    Option name that holds the lock.
	 * @param int    $seconds Seconds after which a lock left by a run that died is taken over.
	 * @return bool Whether this run holds the lock.
	 */
	public static function take_lock( string $name, int $seconds ): bool {
		global $wpdb;
		for ( $try = 0; $try < 2; $try++ ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- INSERT IGNORE is the atomic part; options have no such API.
			$taken = $wpdb->query(
				$wpdb->prepare(
					"INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')",
					$name,
					(string) time()
				)
			);
			wp_cache_delete( $name, 'options' );
			wp_cache_delete( 'notoptions', 'options' );
			if ( 1 === (int) $taken ) {
				return true;
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Read past the options cache.
			$since = (int) $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name ) );
			if ( $since > time() - $seconds ) {
				return false;
			}
			delete_option( $name ); // Left by a run that died: take it over.
		}
		return false;
	}

	/**
	 * Read the events and make or update their pages.
	 *
	 * @param array $report   Report so far.
	 * @param array $done     iMIS event IDs earlier batches of this import dealt with (ID => true).
	 * @param bool  $carry_on A later batch, which uses the list the import read at its start.
	 * @return array The report.
	 */
	private static function import( array $report, array $done, bool $carry_on = false ): array {
		self::$images = 0;
		$since        = (string) Wormiss_Settings::get( 'event_import_since' );
		// A later batch uses the list read at the start, rather than reading every event from
		// iMIS again for each batch of a few pages (the heaviest part of a run).
		$kept = $carry_on ? get_option( self::LIST_OPTION ) : false;
		if ( is_array( $kept ) && (int) ( $kept['time'] ?? 0 ) > time() - self::LIST_MAX_AGE
			&& is_array( $kept['events'] ?? null ) && is_array( $kept['extras'] ?? null ) ) {
			return self::write( $report, $done, $kept['events'], $kept['extras'], (int) $kept['time'] );
		}
		// The read starts at the earlier of the past-events date and the usual lookback, so events
		// under way are never missed; the date only limits finished events (with_past()).
		$lookback = (string) wp_date( 'Y-m-d', time() - Wormiss_Events::LOOKBACK_DAYS * DAY_IN_SECONDS );
		$from     = '' !== $since ? min( $since, $lookback ) : $lookback;
		// A fresh read (never an earlier copy), kept as iMIS sent it for the full descriptions.
		try {
			// With a past-events date, every event since then is read (up to MAX_READ), not a capped
			// first batch, so a long history is complete; more than that is an error, not a part-import.
			$max = '' !== $since ? self::MAX_READ + 1 : Wormiss_Rest_Client::MAX_EVENTS;
			$raw = ( new Wormiss_Rest_Client() )->get_events( $from, $max );
			if ( '' !== $since && count( $raw ) > self::MAX_READ ) {
				$report['status']  = 'error';
				$report['notes'][] = sprintf(
					/* translators: %s: number of events. */
					__( 'More than %s iMIS events since that date, so nothing was imported. Choose a later date.', 'wormiss' ),
					number_format_i18n( self::MAX_READ )
				);
				return self::save( $report );
			}
		} catch ( Wormiss_Exception $e ) {
			$report['status']  = 'error';
			$report['notes'][] = $e->getMessage();
			return self::save( $report );
		}
		$events = [];
		$extras = [];
		foreach ( $raw as $data ) {
			$event = Wormiss_Events::normalise( $data );
			if ( null !== $event ) {
				$events[]               = $event;
				$extras[ $event['id'] ] = self::extras( $data );
			}
		}
		// The events the events block shows (real, public, listed statuses, not finished), plus
		// finished ones since the past-events date when one is set.
		$events = '' !== $since ? self::with_past( $events, $since ) : Wormiss_Events::upcoming( $events, '', time() );
		return self::write( $report, $done, $events, $extras, time() );
	}

	/**
	 * Make or update one batch of pages from the events read.
	 *
	 * @param array   $report Report so far.
	 * @param array   $done   iMIS event IDs earlier batches dealt with (ID => true).
	 * @param array[] $events Events to import (normalised).
	 * @param array   $extras Each event's extras (ID => extras()).
	 * @param int     $read   When the events were read from iMIS.
	 * @return array The report.
	 */
	private static function write( array $report, array $done, array $events, array $extras, int $read ): array {
		$report['read'] = count( $events );
		// In every batch (each one's report replaces the last), so the final report has it too.
		self::note_categories( $report, $events, $extras );
		// At most BATCH page changes per run; the rest carry on in the background BATCH_GAP later.
		// Batches remember the events they dealt with by ID (not by place in the list, which
		// shifts as events finish or change), so none is skipped and none holds others back.
		$writes = 0;
		$todo   = 0;
		foreach ( $events as $event ) {
			if ( isset( $done[ $event['id'] ] ) ) {
				continue;
			}
			if ( $writes >= self::BATCH || self::$images >= self::MAX_IMAGES ) {
				++$todo;
				continue;
			}
			$done[ $event['id'] ] = true;
			unset( $event['_start'] );
			$extra   = $extras[ $event['id'] ] ?? self::extras( null );
			$post_id = self::page_for( (string) $event['id'] );
			if ( 0 === $post_id ) {
				++$writes;
				if ( self::create( $event, $extra, $report ) ) {
					++$report['created'];
				}
				continue;
			}
			if ( self::refresh( $post_id, $event, $extra, $report ) ) {
				++$writes;
				++$report['updated'];
			} else {
				++$report['skipped'];
			}
		}
		$report['remaining'] = $todo;
		if ( $todo > 0 ) {
			update_option( self::DONE_OPTION, $done, false );
			update_option(
				self::LIST_OPTION,
				[
					'time'   => $read,
					'events' => $events,
					'extras' => $extras,
				],
				false
			);
			if ( ! wp_next_scheduled( self::MORE_HOOK ) ) {
				wp_schedule_single_event( time() + self::BATCH_GAP, self::MORE_HOOK );
			}
			self::note(
				$report,
				sprintf(
					/* translators: 1: pages per batch, 2: events still to check. */
					__( 'Imported in batches of %1$d pages: carrying on in the background with %2$d more events to check.', 'wormiss' ),
					self::BATCH,
					$report['remaining']
				)
			);
		} else {
			delete_option( self::DONE_OPTION );
			delete_option( self::LIST_OPTION );
		}
		return self::save( $report );
	}

	/**
	 * What the import takes from an iMIS event besides normalise(): the full description (safe
	 * HTML) and the category's ID and name.
	 *
	 * @param mixed $data EventData from iMIS.
	 * @return array{html: string, category_id: string, category: string}
	 */
	public static function extras( $data ): array {
		$out = [
			'html'        => '',
			'category_id' => '',
			'category'    => '',
		];
		if ( ! is_object( $data ) ) {
			return $out;
		}
		// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- iMIS fields.
		$description = $data->Description ?? '';
		if ( is_object( $description ) && property_exists( $description, '$value' ) ) {
			$description = $description->{'$value'};
		}
		$out['html'] = is_string( $description ) ? trim( wp_kses_post( $description ) ) : '';
		$category    = $data->Category ?? null;
		if ( is_object( $category ) ) {
			$out['category_id'] = Wormiss_Event_Types::clean_category( $category->EventCategoryId ?? '' );
			// The type's name: the category's Name, else its Description, else its ID (e.g. COURSE).
			foreach ( [ 'Name', 'Description', 'EventCategoryId' ] as $field ) {
				$value = $category->{$field} ?? null;
				$value = is_scalar( $value ) ? trim( sanitize_text_field( (string) $value ) ) : '';
				if ( '' !== $value ) {
					$out['category'] = $value;
					break;
				}
			}
		}
		// phpcs:enable
		return $out;
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
	 * Make an Event page linked to an iMIS event: published, or a draft, as Settings says.
	 *
	 * @param array $event  Event from Wormiss_Events::normalise().
	 * @param array $extra  From extras().
	 * @param array $report Report, for notes.
	 * @return bool
	 */
	private static function create( array $event, array $extra, array &$report ): bool {
		$publish = (bool) Wormiss_Settings::get( 'event_import_publish' );
		$meta    = [
			Wormiss_Event_Sync::META_EXISTING       => $event['id'],
			Wormiss_Event_Sync::META_IMIS_ID        => $event['id'],
			Wormiss_Event_Sync::META_LINKED         => 1,
			Wormiss_Event_Sync::META_LINKED_DETAILS => $event,
			Wormiss_Event_Sync::META_STATUS         => 'linked',
			Wormiss_Event_Sync::META_MESSAGE        => sprintf(
				/* translators: %s: iMIS event ID. */
				__( 'Imported from iMIS event %s. Its details come from iMIS; WordPress never changes or cancels it.', 'wormiss' ),
				$event['id']
			),
			self::META_IMPORTED                     => 1,
			self::META_SOURCE                       => self::source_hash( $event, $extra ),
		];
		if ( $publish ) {
			$meta[ self::META_PUBLISHED ] = 1;
		}
		// The link is saved with the page, so it is never queued to be sent to iMIS. Slashed, as
		// WordPress strips one level of backslashes from what it saves.
		$post_id = wp_insert_post(
			wp_slash(
				[
					'post_type'    => Wormiss_Event_Sync::POST_TYPE,
					'post_status'  => $publish ? 'publish' : 'draft',
					'post_title'   => $event['name'],
					'post_content' => $extra['html'],
					'meta_input'   => $meta,
				]
			),
			true
		);
		if ( is_wp_error( $post_id ) ) {
			/* translators: 1: iMIS event ID, 2: reason. */
			self::note( $report, sprintf( __( 'Event %1$s: no page made (%2$s).', 'wormiss' ), $event['id'], $post_id->get_error_message() ) );
			return false;
		}
		self::apply_extras( (int) $post_id, $event, $extra, $report );
		return true;
	}

	/**
	 * Bring a linked page up to date. Every linked page's title follows iMIS; pages the import
	 * made also get iMIS's description, event category and image, and are published when Settings
	 * says so (unless an editor has since unpublished them). Pages WordPress sent to iMIS, and
	 * trashed pages, are left alone.
	 *
	 * @param int   $post_id Post ID.
	 * @param array $event   Event from Wormiss_Events::normalise().
	 * @param array $extra   From extras().
	 * @param array $report  Report, for notes.
	 * @return bool Whether the page was changed.
	 */
	private static function refresh( int $post_id, array $event, array $extra, array &$report ): bool {
		$status = get_post_status( $post_id );
		if ( 'trash' === $status || ! get_post_meta( $post_id, Wormiss_Event_Sync::META_LINKED, true ) ) {
			return false;
		}
		$imported = (bool) get_post_meta( $post_id, self::META_IMPORTED, true );
		$changed  = get_post_meta( $post_id, Wormiss_Event_Sync::META_LINKED_DETAILS, true ) !== $event;
		if ( $changed ) {
			update_post_meta( $post_id, Wormiss_Event_Sync::META_LINKED_DETAILS, wp_slash( $event ) );
		}
		$update = [];
		if ( $imported ) {
			// What iMIS sent changed: its name and description replace the page's.
			$hash = self::source_hash( $event, $extra );
			if ( get_post_meta( $post_id, self::META_SOURCE, true ) !== $hash ) {
				$update['post_title']   = $event['name'];
				$update['post_content'] = $extra['html'];
				update_post_meta( $post_id, self::META_SOURCE, $hash );
			}
			// Live now (pages published by hand before this was tracked included): remember it.
			if ( 'publish' === $status ) {
				update_post_meta( $post_id, self::META_PUBLISHED, 1 );
			}
			// Once live, a draft means an editor took it down: it stays down.
			if ( 'draft' === $status && Wormiss_Settings::get( 'event_import_publish' ) && ! get_post_meta( $post_id, self::META_PUBLISHED, true ) ) {
				$update['post_status'] = 'publish';
				update_post_meta( $post_id, self::META_PUBLISHED, 1 );
			}
		} elseif ( '' !== $event['name'] && get_post_field( 'post_title', $post_id, 'raw' ) !== $event['name'] ) {
			$update['post_title'] = $event['name'];
		}
		if ( $update ) {
			wp_update_post( wp_slash( array_merge( [ 'ID' => $post_id ], $update ) ) );
			$changed = true;
		}
		if ( $imported && self::apply_extras( $post_id, $event, $extra, $report ) ) {
			$changed = true;
		}
		return $changed;
	}

	/**
	 * A fingerprint of what the page takes from iMIS (name and description), to tell when iMIS
	 * changed them (comparing the saved page would see WordPress's own clean-up as a change).
	 *
	 * @param array $event Event from Wormiss_Events::normalise().
	 * @param array $extra From extras().
	 * @return string
	 */
	private static function source_hash( array $event, array $extra ): string {
		return md5( $event['name'] . "\0" . $extra['html'] );
	}

	/**
	 * The event category for an iMIS category: the one holding its category ID (so a type renamed
	 * here is still found), else a main type with its name.
	 *
	 * @param string $category_id iMIS category ID ('' if none).
	 * @param string $name        Category name.
	 * @return WP_Term|null
	 */
	private static function type_for_category( string $category_id, string $name ): ?WP_Term {
		if ( '' !== $category_id ) {
			$found = get_terms(
				[
					'taxonomy'   => Wormiss_Event_Types::TAXONOMY,
					'hide_empty' => false,
					'number'     => 1,
					'orderby'    => 'term_id',
					'meta_key'   => Wormiss_Event_Types::META_CATEGORY, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- One small lookup per imported category.
					'meta_value' => $category_id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- As above.
				]
			);
			if ( is_array( $found ) && $found && $found[0] instanceof WP_Term ) {
				return $found[0];
			}
		}
		$found = get_terms(
			[
				'taxonomy'   => Wormiss_Event_Types::TAXONOMY,
				'hide_empty' => false,
				'name'       => $name,
				'parent'     => 0,
				'number'     => 1,
			]
		);
		return is_array( $found ) && $found && $found[0] instanceof WP_Term ? $found[0] : null;
	}

	/**
	 * Give an imported page its event category (from the iMIS category: kept when it is a sub-category of
	 * the category's type) and featured image (from the iMIS event image).
	 *
	 * @param int   $post_id Post ID.
	 * @param array $event   Event from Wormiss_Events::normalise().
	 * @param array $extra   From extras().
	 * @param array $report  Report, for notes.
	 * @return bool Whether anything changed.
	 */
	private static function apply_extras( int $post_id, array $event, array $extra, array &$report ): bool {
		$changed = false;
		if ( '' !== $extra['category'] ) {
			$term = self::type_for_category( $extra['category_id'], $extra['category'] );
			if ( ! $term instanceof WP_Term ) {
				$made = wp_insert_term( $extra['category'], Wormiss_Event_Types::TAXONOMY );
				$term = is_wp_error( $made ) ? null : get_term( (int) $made['term_id'], Wormiss_Event_Types::TAXONOMY );
				if ( $term instanceof WP_Term && '' !== $extra['category_id'] ) {
					update_term_meta( $term->term_id, Wormiss_Event_Types::META_CATEGORY, $extra['category_id'] );
				}
			}
			if ( $term instanceof WP_Term ) {
				$now = wp_get_object_terms( $post_id, Wormiss_Event_Types::TAXONOMY, [ 'fields' => 'ids' ] );
				$now = is_array( $now ) ? array_map( 'intval', $now ) : [];
				// A sub-category of the category's type (e.g. Essential under Courses), chosen here, stays.
				$kept = 1 === count( $now ) && ( $now[0] === (int) $term->term_id
					|| term_is_ancestor_of( $term, $now[0], Wormiss_Event_Types::TAXONOMY ) );
				if ( ! $kept ) {
					wp_set_object_terms( $post_id, [ $term->term_id ], Wormiss_Event_Types::TAXONOMY );
					$changed = true;
				}
			}
		}
		/**
		 * Whether imported pages get the iMIS event image as their featured image.
		 *
		 * @param bool $download Download images (default true).
		 */
		$download = (bool) apply_filters( 'wormiss_event_import_images', true );
		$image    = Wormiss_Events::absolute_url( (string) $event['image'] );
		if ( '' === $image && '' !== (string) get_post_meta( $post_id, self::META_IMAGE, true ) ) {
			// The image was taken off the event in iMIS: take the imported one off the page too,
			// unless an editor has chosen a different one since.
			$imported = (int) get_post_meta( $post_id, self::META_IMAGE_ID, true );
			if ( $imported && get_post_thumbnail_id( $post_id ) === $imported ) {
				delete_post_thumbnail( $post_id );
				$changed = true;
			}
			delete_post_meta( $post_id, self::META_IMAGE );
			delete_post_meta( $post_id, self::META_IMAGE_ID );
		}
		if ( $download && '' !== $image && get_post_meta( $post_id, self::META_IMAGE, true ) !== $image
			&& self::set_image( $post_id, $image, $event, $report ) ) {
			$changed = true;
		}
		return $changed;
	}

	/**
	 * Download an image into the Media Library and make it the page's featured image.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $url     Image address.
	 * @param array  $event   Event, for the image's name and notes.
	 * @param array  $report  Report, for notes.
	 * @return bool
	 */
	private static function set_image( int $post_id, string $url, array $event, array &$report ): bool {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		// Remembered whatever happens, so a broken image is not downloaded again every day.
		update_post_meta( $post_id, self::META_IMAGE, $url );
		++self::$images;
		$tmp = download_url( $url, 30 );
		if ( is_wp_error( $tmp ) ) {
			/* translators: 1: iMIS event ID, 2: reason. */
			self::note( $report, sprintf( __( 'Event %1$s: its image could not be downloaded (%2$s).', 'wormiss' ), $event['id'], $tmp->get_error_message() ) );
			return false;
		}
		// iMIS image URLs often have no usable extension (a "GetImage" handler) or a misleading one, so
		// name the file from its real type (see Wormiss_Media::sideload_name()).
		$name = Wormiss_Media::sideload_name( $tmp, $url, 'event-image' );
		if ( null === $name ) {
			// Not an image type WordPress accepts (e.g. SVG). The address is already remembered above,
			// so it is not downloaded again every day.
			wp_delete_file( $tmp );
			/* translators: %s: iMIS event ID. */
			self::note( $report, sprintf( __( 'Event %s: its image is not a supported type, so it was not added.', 'wormiss' ), $event['id'] ) );
			return false;
		}
		$file       = [
			'name'     => $name,
			'tmp_name' => $tmp,
		];
		$attachment = media_handle_sideload( $file, $post_id, $event['name'] );
		if ( is_wp_error( $attachment ) ) {
			wp_delete_file( $tmp );
			/* translators: 1: iMIS event ID, 2: reason. */
			self::note( $report, sprintf( __( 'Event %1$s: its image could not be added (%2$s).', 'wormiss' ), $event['id'], $attachment->get_error_message() ) );
			return false;
		}
		set_post_thumbnail( $post_id, (int) $attachment );
		update_post_meta( $post_id, self::META_IMAGE_ID, (int) $attachment );
		return true;
	}

	/**
	 * Say in the report how many events iMIS sent with a category (which becomes the event category)
	 * and which categories, so a missing one is plain to see.
	 *
	 * @param array   $report Report.
	 * @param array[] $events Events to import.
	 * @param array   $extras Each event's extras (ID => extras()).
	 * @return void
	 */
	private static function note_categories( array &$report, array $events, array $extras ): void {
		if ( ! $events ) {
			return;
		}
		$names = [];
		$none  = 0;
		foreach ( $events as $event ) {
			$name = (string) ( $extras[ $event['id'] ]['category'] ?? '' );
			if ( '' === $name ) {
				++$none;
			} else {
				$names[ $name ] = true;
			}
		}
		$with = count( $events ) - $none;
		self::note(
			$report,
			$with
				? sprintf(
					/* translators: 1: number of events with a category, 2: the categories, 3: number without. */
					__( 'Event categories from iMIS: %1$d events have one (%2$s); %3$d have none.', 'wormiss' ),
					$with,
					implode( ', ', array_slice( array_keys( $names ), 0, 10 ) ),
					$none
				)
				: sprintf(
					/* translators: %d: number of events. */
					__( 'Event categories from iMIS: iMIS sent no category for any of the %d events, so none was given a category.', 'wormiss' ),
					$none
				)
		);
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
		$text = sprintf(
			/* translators: 1: date and time, 2: events read, 3: pages made, 4: pages updated, 5: unchanged. */
			__( 'Last import %1$s: %2$d events in iMIS to import; %3$d pages made, %4$d updated, %5$d unchanged.', 'wormiss' ),
			$when,
			(int) $report['read'],
			(int) $report['created'],
			(int) $report['updated'],
			(int) $report['skipped']
		);
		if ( ! empty( $report['remaining'] ) ) {
			/* translators: %d: events still to check. */
			$text .= ' ' . sprintf( __( 'Still going: %d more events to check in the background.', 'wormiss' ), (int) $report['remaining'] );
		}
		return $text;
	}
}
