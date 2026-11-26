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
 * Bulk-categorise existing media (media spec phase 3).
 *
 * Adds one media category to many existing attachments at once, as a throttled, resumable
 * background job (no long inline loop): a queue of attachment IDs is worked through a batch at a
 * time, each batch stopping on a time limit and rescheduling the next. There is a config
 * kill-switch (the wormiss_media_bulk_enabled filter / WORMISS_MEDIA_BULK_DISABLED constant), the
 * admin must confirm before starting, and the start control is hidden while a job runs.
 *
 * @package    wormiss
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined( 'ABSPATH' ) || exit;

/**
 * Runs the bulk-categorise job.
 */
class Wormiss_Media_Bulk {

	/** Cron hook that runs a batch. */
	const HOOK = 'wormiss_media_bulk';

	/** Option holding the running job. */
	const JOB_OPTION = 'wormiss_media_bulk_job';

	/** Option holding the last finished job's summary. */
	const REPORT_OPTION = 'wormiss_media_bulk_report';

	/** Attachments processed per batch (also stops on the batch time limit). */
	const BATCH = 200;

	/** Seconds a batch works for. */
	const SECONDS = 15;

	/**
	 * Hook in (always, so the cron batch runs; the admin UI lives in Wormiss_Admin).
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( self::HOOK, [ __CLASS__, 'run' ] );
		add_action( 'admin_post_wormiss_media_bulk_start', [ __CLASS__, 'handle_start' ] );
		add_action( 'admin_post_wormiss_media_bulk_cancel', [ __CLASS__, 'handle_cancel' ] );
		add_action( 'init', [ __CLASS__, 'maybe_resume' ] );
	}

	/**
	 * Reschedule a pending job whose continuation was lost (e.g. the plugin was deactivated between
	 * batches, which clears the cron event but keeps the job). Does nothing while the tool is off.
	 *
	 * @return void
	 */
	public static function maybe_resume(): void {
		if ( self::enabled() && self::is_running() && ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_single_event( time() + MINUTE_IN_SECONDS, self::HOOK );
		}
	}

	/**
	 * Attachments processed per batch (filterable, chiefly for tests).
	 *
	 * @return int
	 */
	public static function batch(): int {
		return max( 1, (int) apply_filters( 'wormiss_media_bulk_batch', self::BATCH ) );
	}

	/**
	 * Whether bulk categorising is available (the config kill-switch).
	 *
	 * @return bool
	 */
	public static function enabled(): bool {
		$off = defined( 'WORMISS_MEDIA_BULK_DISABLED' ) && WORMISS_MEDIA_BULK_DISABLED;
		/**
		 * Whether the bulk-categorise tool is available.
		 *
		 * @param bool $enabled Enabled.
		 */
		return (bool) apply_filters( 'wormiss_media_bulk_enabled', ! $off );
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
	 * The attachment IDs matching a scope ("uncategorised": no media category; "all": every
	 * attachment).
	 *
	 * @param string $scope Scope.
	 * @return int[]
	 */
	public static function matching_ids( string $scope ): array {
		$args = [
			'post_type'              => 'attachment',
			'post_status'            => 'inherit',
			'posts_per_page'         => -1,
			'orderby'                => 'ID',
			'order'                  => 'ASC',
			'fields'                 => 'ids',
			'no_found_rows'          => true,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
		];
		if ( 'all' !== $scope ) {
			// The "uncategorised" scope is labelled "images with no category": restrict it to image
			// files so non-image attachments (PDFs, audio, ...) are not swept in.
			$args['post_mime_type'] = 'image';
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- One-off admin bulk tool.
			$args['tax_query'] = [
				[
					'taxonomy' => Wormiss_Media_Categories::TAXONOMY,
					'operator' => 'NOT EXISTS',
				],
			];
		}
		$query = new WP_Query( $args );
		return array_map( 'intval', $query->posts );
	}

	/**
	 * How many attachments a scope matches.
	 *
	 * @param string $scope Scope.
	 * @return int
	 */
	public static function count( string $scope ): int {
		return count( self::matching_ids( $scope ) );
	}

	/**
	 * Start a job: add the given category to every attachment matching the scope. Returns false if
	 * the tool is off, a job is already running, the category is unknown, or nothing matches.
	 *
	 * @param int    $category Target category term ID.
	 * @param string $scope    "uncategorised" or "all".
	 * @return bool
	 */
	public static function start( int $category, string $scope ): bool {
		if ( ! self::enabled() || self::is_running() ) {
			return false;
		}
		if ( ! get_term( $category, Wormiss_Media_Categories::TAXONOMY ) instanceof WP_Term ) {
			return false;
		}
		$scope = in_array( $scope, [ 'uncategorised', 'all' ], true ) ? $scope : 'uncategorised';
		$ids   = self::matching_ids( $scope );
		if ( ! $ids ) {
			return false;
		}
		update_option(
			self::JOB_OPTION,
			[
				'category' => $category,
				'scope'    => $scope,
				'queue'    => $ids,
				'total'    => count( $ids ),
				'done'     => 0,
				'started'  => time(),
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
	 * Work through one batch of the queue, then reschedule the next batch or finish.
	 *
	 * @return void
	 */
	public static function run(): void {
		$job = self::job();
		if ( ! $job ) {
			return;
		}
		// Kill-switch: if the tool was turned off mid-job, stop and clear it rather than carrying on.
		if ( ! self::enabled() ) {
			self::cancel();
			return;
		}
		Wormiss_Batch::start( self::SECONDS );
		$category  = (int) $job['category'];
		$started   = (int) ( $job['started'] ?? 0 );
		$queue     = array_map( 'intval', (array) ( $job['queue'] ?? [] ) );
		$batch     = self::batch();
		$valid     = get_term( $category, Wormiss_Media_Categories::TAXONOMY ) instanceof WP_Term;
		$processed = 0;
		while ( $queue && $processed < $batch && ! Wormiss_Batch::out_of_time() ) {
			$id = (int) array_shift( $queue );
			if ( $valid && 'attachment' === get_post_type( $id ) ) {
				// Append, so existing categories are kept.
				wp_set_object_terms( $id, [ $category ], Wormiss_Media_Categories::TAXONOMY, true );
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
				'category' => (int) $job['category'],
				'total'    => (int) $job['total'],
				'done'     => (int) $job['done'],
				'time'     => time(),
			],
			false
		);
		delete_option( self::JOB_OPTION );
		wp_clear_scheduled_hook( self::HOOK );
	}

	/**
	 * The "Start bulk categorise" button.
	 *
	 * @return void
	 */
	public static function handle_start(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'wormiss' ), 403 );
		}
		check_admin_referer( 'wormiss_media_bulk_start', 'wormiss_media_bulk_nonce' );
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Verified by check_admin_referer() above.
		$category = isset( $_POST['wormiss_bulk_category'] ) ? absint( $_POST['wormiss_bulk_category'] ) : 0;
		$scope    = isset( $_POST['wormiss_bulk_scope'] ) ? sanitize_key( wp_unslash( $_POST['wormiss_bulk_scope'] ) ) : 'uncategorised';
		$confirm  = isset( $_POST['wormiss_bulk_confirm'] );
		// phpcs:enable WordPress.Security.NonceVerification.Missing
		if ( self::enabled() && $confirm && $category > 0 && self::start( $category, $scope ) ) {
			// The first batch runs now; the rest follow on cron.
			self::run();
		}
		wp_safe_redirect( Wormiss_Admin::settings_url( 'media' ) . '#wormiss-media-bulk' );
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
		check_admin_referer( 'wormiss_media_bulk_cancel', 'wormiss_media_bulk_nonce' );
		self::cancel();
		wp_safe_redirect( Wormiss_Admin::settings_url( 'media' ) . '#wormiss-media-bulk' );
		exit;
	}
}
