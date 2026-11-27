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
 * Move existing protected images into the private store.
 *
 * Flagging a media category "protected delivery" or "lock to post" only gates references to its
 * images at the endpoint; an image already in the Media Library keeps its public original file
 * until that file is moved into the private store. The blog import does this for imported pictures;
 * this tool does it for everything else — every image in a protected/locked category whose original
 * is still public — as a throttled, resumable background job, mirroring the bulk-categorise tool. It
 * calls Wormiss_Media_Delivery::store() on each, which moves the original out of the public uploads
 * folder. There is a config kill-switch, the admin confirms before starting, and the start control
 * is hidden while a job runs.
 *
 * @package    wormiss
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined( 'ABSPATH' ) || exit;

/**
 * Runs the "move protected images into the private store" job.
 */
class Wormiss_Media_Migrate {

	/** Cron hook that runs a batch. */
	const HOOK = 'wormiss_media_migrate';

	/** Option holding the running job. */
	const JOB_OPTION = 'wormiss_media_migrate_job';

	/** Option holding the last finished job's summary. */
	const REPORT_OPTION = 'wormiss_media_migrate_report';

	/** Attachments processed per batch (also stops on the batch time limit). */
	const BATCH = 50;

	/** Seconds a batch works for. */
	const SECONDS = 15;

	/**
	 * Hook in (always, so the cron batch runs; the admin UI lives in Wormiss_Admin).
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( self::HOOK, [ __CLASS__, 'run' ] );
		add_action( 'admin_post_wormiss_media_migrate_start', [ __CLASS__, 'handle_start' ] );
		add_action( 'admin_post_wormiss_media_migrate_cancel', [ __CLASS__, 'handle_cancel' ] );
		add_action( 'init', [ __CLASS__, 'maybe_resume' ] );
	}

	/**
	 * Reschedule a job whose continuation was lost (e.g. the plugin was deactivated between batches).
	 *
	 * @return void
	 */
	public static function maybe_resume(): void {
		if ( self::enabled() && self::is_running() && ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_single_event( time() + MINUTE_IN_SECONDS, self::HOOK );
		}
	}

	/**
	 * Attachments processed per batch (filterable, chiefly for tests). Smaller than the categorise
	 * batch because each item moves a file and regenerates nothing but still does disk work.
	 *
	 * @return int
	 */
	public static function batch(): int {
		return max( 1, (int) apply_filters( 'wormiss_media_migrate_batch', self::BATCH ) );
	}

	/**
	 * Whether the tool is available (the config kill-switch).
	 *
	 * @return bool
	 */
	public static function enabled(): bool {
		$off = defined( 'WORMISS_MEDIA_MIGRATE_DISABLED' ) && WORMISS_MEDIA_MIGRATE_DISABLED;
		/**
		 * Whether the "move protected images into the private store" tool is available.
		 *
		 * @param bool $enabled Enabled.
		 */
		return (bool) apply_filters( 'wormiss_media_migrate_enabled', ! $off );
	}

	/**
	 * The running job, or null.
	 *
	 * @return array|null
	 */
	public static function job(): ?array {
		$job = get_option( self::JOB_OPTION );
		return is_array( $job ) ? $job : null;
	}

	/**
	 * Whether a job is running.
	 *
	 * @return bool
	 */
	public static function is_running(): bool {
		return null !== self::job();
	}

	/**
	 * Image attachments in a protected or locked category whose original is still public (not yet in
	 * the private store).
	 *
	 * @return int[]
	 */
	public static function matching_ids(): array {
		$terms = self::protected_terms();
		if ( ! $terms ) {
			return [];
		}
		$query = new WP_Query(
			[
				'post_type'              => 'attachment',
				'post_status'            => 'inherit',
				'post_mime_type'         => 'image',
				'posts_per_page'         => -1,
				'orderby'                => 'ID',
				'order'                  => 'ASC',
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- One-off admin tool.
				'tax_query'              => [
					[
						'taxonomy'         => Wormiss_Media_Categories::TAXONOMY,
						'field'            => 'term_id',
						'terms'            => $terms,
						'include_children' => false,
					],
				],
			]
		);
		$out   = [];
		foreach ( array_map( 'intval', $query->posts ) as $id ) {
			if ( ! Wormiss_Media_Delivery::is_stored( $id ) ) {
				$out[] = $id;
			}
		}
		return $out;
	}

	/**
	 * Every protected/locked category term and its descendants (a protected parent protects its
	 * sub-categories), so the query covers images filed under a sub-category too.
	 *
	 * @return int[]
	 */
	private static function protected_terms(): array {
		$terms = [];
		foreach ( Wormiss_Media_Protected::flagged_term_ids() as $term_id ) {
			$terms[]  = (int) $term_id;
			$children = get_term_children( (int) $term_id, Wormiss_Media_Categories::TAXONOMY );
			if ( is_array( $children ) ) {
				$terms = array_merge( $terms, array_map( 'intval', $children ) );
			}
		}
		return array_values( array_unique( $terms ) );
	}

	/**
	 * How many images are waiting to be moved.
	 *
	 * @return int
	 */
	public static function count(): int {
		return count( self::matching_ids() );
	}

	/**
	 * Start a job. Returns false if the tool is off, a job is already running, or nothing matches.
	 *
	 * @return bool
	 */
	public static function start(): bool {
		if ( ! self::enabled() || self::is_running() ) {
			return false;
		}
		$ids = self::matching_ids();
		if ( ! $ids ) {
			return false;
		}
		update_option(
			self::JOB_OPTION,
			[
				'queue'   => $ids,
				'total'   => count( $ids ),
				'done'    => 0,
				'moved'   => 0,
				'failed'  => 0,
				'started' => time(),
			],
			false
		);
		return true;
	}

	/**
	 * Cancel the running job.
	 *
	 * @return void
	 */
	public static function cancel(): void {
		delete_option( self::JOB_OPTION );
		wp_clear_scheduled_hook( self::HOOK );
	}

	/**
	 * Work through one batch of the queue, then reschedule or finish.
	 *
	 * @return void
	 */
	public static function run(): void {
		$job = self::job();
		if ( ! $job ) {
			return;
		}
		// Kill-switch: if the tool was turned off mid-job, stop and clear it.
		if ( ! self::enabled() ) {
			self::cancel();
			return;
		}
		Wormiss_Batch::start( self::SECONDS );
		$started   = (int) ( $job['started'] ?? 0 );
		$queue     = array_map( 'intval', (array) ( $job['queue'] ?? [] ) );
		$batch     = self::batch();
		$processed = 0;
		while ( $queue && $processed < $batch && ! Wormiss_Batch::out_of_time() ) {
			$id = (int) array_shift( $queue );
			if ( 'attachment' === get_post_type( $id ) && Wormiss_Media_Protected::attachment_is_protected( $id ) ) {
				if ( Wormiss_Media_Delivery::store( $id ) ) {
					++$job['moved'];
				} else {
					++$job['failed'];
				}
			}
			++$job['done'];
			++$processed;
		}
		// Don't resurrect a job that was cancelled or replaced while this batch ran.
		$current = self::job();
		if ( ! $current || (int) ( $current['started'] ?? 0 ) !== $started ) {
			return;
		}
		$job['queue'] = array_values( $queue );
		if ( ! $queue ) {
			self::finish( $job );
			return;
		}
		update_option( self::JOB_OPTION, $job, false );
		wp_schedule_single_event( time() + MINUTE_IN_SECONDS, self::HOOK );
	}

	/**
	 * Record the finished job and clear it.
	 *
	 * @param array $job The job.
	 * @return void
	 */
	private static function finish( array $job ): void {
		update_option(
			self::REPORT_OPTION,
			[
				'total'  => (int) $job['total'],
				'moved'  => (int) $job['moved'],
				'failed' => (int) $job['failed'],
				'time'   => time(),
			],
			false
		);
		delete_option( self::JOB_OPTION );
		wp_clear_scheduled_hook( self::HOOK );
	}

	/**
	 * The "Move protected images into the private store" button.
	 *
	 * @return void
	 */
	public static function handle_start(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'wormiss' ), 403 );
		}
		check_admin_referer( 'wormiss_media_migrate_start', 'wormiss_media_migrate_nonce' );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified by check_admin_referer() above.
		$confirm = isset( $_POST['wormiss_migrate_confirm'] );
		if ( self::enabled() && $confirm && self::start() ) {
			// The first batch runs now; the rest follow on cron.
			self::run();
		}
		wp_safe_redirect( Wormiss_Admin::settings_url( 'media' ) . '#wormiss-media-migrate' );
		exit;
	}

	/**
	 * The "Cancel" button.
	 *
	 * @return void
	 */
	public static function handle_cancel(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'wormiss' ), 403 );
		}
		check_admin_referer( 'wormiss_media_migrate_cancel', 'wormiss_media_migrate_nonce' );
		self::cancel();
		wp_safe_redirect( Wormiss_Admin::settings_url( 'media' ) . '#wormiss-media-migrate' );
		exit;
	}
}
