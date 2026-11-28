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
 * Doctor directory sync: reads an approved iMIS IQA and keeps the "Locate a Doctor"
 * (lad_doctor) directory in step with it, so the manual CSV import is no longer the
 * only way to feed the public map.
 *
 * Each row becomes one lad_doctor post (one pin), keyed on iMIS ID + location so a
 * doctor with several practices keeps a separate, stable pin per location. Opt-out
 * and non-practice rows are never published. Because the source IQA filters opt-outs
 * out of the feed entirely, a withdrawn doctor simply stops appearing; so after a
 * confirmed-complete, successful fetch the sync reconciles — it drafts any
 * sync-managed pin that the feed no longer contains. A partial or failed fetch never
 * reconciles, so iMIS being down can never mass-unpublish the directory.
 *
 * This writes lad_doctor posts, so the Locate a Doctor plugin must be active; lad
 * itself stays installable standalone on CSV with no dependency on wormiss.
 *
 * @package    wormiss
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined( 'ABSPATH' ) || exit;

/**
 * Pulls the doctor IQA into the lad_doctor directory.
 */
class Wormiss_Doctor_Sync {

	/**
	 * The Locate a Doctor post type this sync writes.
	 */
	const POST_TYPE = 'lad_doctor';

	/**
	 * The Locate a Doctor accreditation taxonomy.
	 */
	const TAXONOMY = 'lad_accreditation';

	/**
	 * Prefix for the lad meta keys (matches LAD_Meta::PREFIX).
	 */
	const LAD_PREFIX = '_lad_';

	/**
	 * Meta holding the iMIS contact ID (matches lad).
	 */
	const IMIS_META = '_lad_imis_id';

	/**
	 * Meta holding the per-practice location key (forward-compatible with lad multi-location).
	 */
	const LOCATION_META = '_lad_location_id';

	/**
	 * Meta stamping a pin as managed by this sync, so reconciliation only ever drafts
	 * pins the sync created and never a hand-made or CSV-only record.
	 */
	const MANAGED_META = '_wormiss_doctor_sync';

	/**
	 * Option holding this feature's settings ({enabled: bool, query: string}) and last run.
	 */
	const OPTION = 'wormiss_doctor_sync';

	/**
	 * The background hook that runs a sync (on-demand now; a schedule is added in a later phase).
	 */
	const HOOK = 'wormiss_doctor_sync_run';

	/**
	 * The most rows one run reads from the IQA.
	 */
	const MAX_ROWS = 20000;

	/**
	 * The nightly schedule hook.
	 */
	const DAILY_HOOK = 'wormiss_doctor_sync_daily';

	/**
	 * The hook that continues geocoding in the background (resumable).
	 */
	const MORE_HOOK = 'wormiss_doctor_sync_geocode';

	/**
	 * Pins geocoded per background pass (kept small; Nominatim is about 1s each).
	 */
	const GEOCODE_CHUNK = 15;

	/**
	 * Hook registration.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( self::HOOK, [ __CLASS__, 'run' ] );
		add_action( self::DAILY_HOOK, [ __CLASS__, 'run' ] );
		add_action( self::MORE_HOOK, [ __CLASS__, 'geocode_step' ] );
		add_action( 'admin_init', [ __CLASS__, 'ensure_schedule' ] );
		add_action( 'admin_post_wormiss_save_doctor_sync', [ __CLASS__, 'save_settings' ] );
		add_action( 'admin_post_wormiss_sync_doctors', [ __CLASS__, 'sync_now' ] );
	}

	/**
	 * This feature's stored settings.
	 *
	 * @return array{enabled: bool, query: string}
	 */
	public static function config(): array {
		$stored = get_option( self::OPTION, [] );
		$stored = is_array( $stored ) ? $stored : [];
		return [
			'enabled' => ! empty( $stored['enabled'] ),
			'query'   => isset( $stored['query'] ) ? (string) $stored['query'] : '',
		];
	}

	/**
	 * Whether a sync can run: the feature is on, an IQA is set, iMIS is configured, and
	 * the Locate a Doctor post type is registered (the plugin is active).
	 *
	 * @return bool
	 */
	public static function is_ready(): bool {
		$config = self::config();
		return $config['enabled']
			&& '' !== $config['query']
			&& Wormiss_Settings::is_configured()
			&& post_type_exists( self::POST_TYPE );
	}

	/**
	 * Run one full sync: fetch the IQA, upsert every row, then reconcile withdrawals.
	 *
	 * Returns a report. The sync never throws: a failed or incomplete fetch is caught,
	 * logged, and returned with complete=false, and in that case nothing is reconciled.
	 *
	 * @return array<string, int|bool|string>
	 */
	public static function run(): array {
		$report = [
			'fetched'         => 0,
			'created'         => 0,
			'updated'         => 0,
			'drafted'         => 0,
			'skipped_optout'  => 0,
			'skipped_private' => 0,
			'skipped'         => 0,
			'errors'          => 0,
			'complete'        => false,
			'message'         => '',
		];

		if ( ! self::is_ready() ) {
			$report['message'] = __( 'Doctor sync is not ready (turned off, no query, iMIS not configured, or Locate a Doctor inactive).', 'wormiss' );
			self::remember( $report );
			return $report;
		}

		$config = self::config();
		Wormiss_Batch::start( 120 );

		try {
			$rows = ( new Wormiss_Rest_Client() )->run_iqa( $config['query'], [], null, self::MAX_ROWS );
		} catch ( Wormiss_Exception $e ) {
			// A failed or partial fetch: do NOT reconcile (never mass-unpublish on an outage).
			$report['message'] = $e->getMessage();
			Wormiss_Logger::record_call( 'doctor-sync', Wormiss_Logger::STATUS_ERROR, $e->getMessage() );
			self::remember( $report );
			return $report;
		}

		$report['fetched'] = count( $rows );
		$run_token         = (string) time();
		$seen              = [];

		foreach ( $rows as $raw ) {
			$fields = self::normalize( (array) $raw );
			if ( '' === $fields['name'] ) {
				++$report['skipped'];
				continue;
			}
			if ( '' === $fields['imis_id'] ) {
				// Without a stable iMIS ID the pin cannot be matched on a later sync, so skip it
				// rather than create a fresh duplicate every run.
				++$report['skipped'];
				continue;
			}
			if ( self::is_truthy( $fields['optout'] ) ) {
				// Opted out: leave it out of the seen set so reconciliation drafts any existing pin.
				++$report['skipped_optout'];
				continue;
			}
			if ( '' !== $fields['address_type'] && ! self::is_public_address_type( $fields['address_type'] ) ) {
				++$report['skipped_private'];
				continue;
			}

			$status = self::upsert( $fields, $run_token );
			if ( 'created' === $status ) {
				++$report['created'];
			} elseif ( 'updated' === $status ) {
				++$report['updated'];
			} else {
				++$report['errors'];
				continue;
			}
			$seen[ self::key( $fields['imis_id'], $fields['location_id'] ) ] = true;
		}

		// The fetch completed, so withdrawals (rows now absent) can be safely drafted.
		$report['complete'] = true;
		$report['drafted']  = self::reconcile( $seen );
		self::maybe_schedule_geocode();

		Wormiss_Logger::record_call( 'doctor-sync', Wormiss_Logger::STATUS_SUCCESS );
		self::remember( $report );
		return $report;
	}

	/**
	 * Create or update one pin for a normalised row.
	 *
	 * @param array<string, string> $fields    Normalised fields.
	 * @param string                $run_token This run's management stamp.
	 * @return string "created", "updated" or "error".
	 */
	private static function upsert( array $fields, string $run_token ): string {
		$existing = self::find_post( $fields['imis_id'], $fields['location_id'] );

		$postarr = [
			'post_type'   => self::POST_TYPE,
			'post_status' => 'publish',
			'post_title'  => sanitize_text_field( $fields['name'] ),
		];
		if ( '' !== $fields['bio'] ) {
			$postarr['post_content'] = wp_kses_post( $fields['bio'] );
		}

		if ( null !== $existing ) {
			$postarr['ID'] = $existing;
			$post_id       = wp_update_post( $postarr, true );
			$result        = 'updated';
		} else {
			$post_id = wp_insert_post( $postarr, true );
			$result  = 'created';
		}

		if ( is_wp_error( $post_id ) || 0 === (int) $post_id ) {
			return 'error';
		}
		$post_id = (int) $post_id;

		$old_address = ( 'updated' === $result ) ? (string) get_post_meta( $post_id, self::LAD_PREFIX . 'address', true ) : '';
		self::write_fields( $post_id, $fields );
		self::write_coords( $post_id, $fields, $old_address );
		update_post_meta( $post_id, self::IMIS_META, sanitize_text_field( $fields['imis_id'] ) );
		update_post_meta( $post_id, self::LOCATION_META, sanitize_text_field( $fields['location_id'] ) );
		update_post_meta( $post_id, self::MANAGED_META, $run_token );

		// Accreditation terms, only when the feed carried a value (a blank never clears them).
		if ( '' !== $fields['accreditation'] ) {
			$terms = array_filter( array_map( 'trim', explode( '|', $fields['accreditation'] ) ) );
			wp_set_object_terms( $post_id, $terms, self::TAXONOMY, false );
		}

		// Optional photo, best effort, only when none is set yet.
		if ( '' !== $fields['photo_url'] && ! has_post_thumbnail( $post_id ) ) {
			self::sideload_photo( $post_id, esc_url_raw( $fields['photo_url'] ) );
		}

		// Let a corrected address be geocoded again (lad clears this marker on import too).
		delete_post_meta( $post_id, self::LAD_PREFIX . 'geocode_failed' );

		return $result;
	}

	/**
	 * Write the standard lad meta fields, sanitised by type.
	 *
	 * @param int                   $post_id Pin post ID.
	 * @param array<string, string> $fields  Normalised fields.
	 * @return void
	 */
	private static function write_fields( int $post_id, array $fields ): void {
		$text = [ 'clinic', 'address', 'suburb', 'state', 'postcode', 'region', 'phone' ];
		foreach ( $text as $key ) {
			update_post_meta( $post_id, self::LAD_PREFIX . $key, sanitize_text_field( $fields[ $key ] ) );
		}
		update_post_meta( $post_id, self::LAD_PREFIX . 'email', sanitize_email( $fields['email'] ) );
		update_post_meta( $post_id, self::LAD_PREFIX . 'website', esc_url_raw( $fields['website'] ) );
	}

	/**
	 * Write coordinates. Feed coordinates are used when present. When the feed carries none,
	 * stored coordinates are cleared only if the address changed (so the geocode pass recomputes
	 * them) and are otherwise kept — a coordinate-less feed never wipes geocoded pins.
	 *
	 * @param int                   $post_id     Pin post ID.
	 * @param array<string, string> $fields      Normalised fields.
	 * @param string                $old_address The address stored before this update ('' when new).
	 * @return void
	 */
	private static function write_coords( int $post_id, array $fields, string $old_address ): void {
		if ( '' !== $fields['latitude'] && '' !== $fields['longitude'] ) {
			update_post_meta( $post_id, self::LAD_PREFIX . 'latitude', sanitize_text_field( $fields['latitude'] ) );
			update_post_meta( $post_id, self::LAD_PREFIX . 'longitude', sanitize_text_field( $fields['longitude'] ) );
			return;
		}
		if ( $old_address !== $fields['address'] ) {
			delete_post_meta( $post_id, self::LAD_PREFIX . 'latitude' );
			delete_post_meta( $post_id, self::LAD_PREFIX . 'longitude' );
		}
	}

	/**
	 * Find the pin for an iMIS ID + location. An exact (iMIS ID + location) match wins;
	 * failing that, a pin for the same iMIS ID with no location yet is adopted (so a
	 * single-location pin from an earlier CSV import gains its location rather than
	 * being duplicated).
	 *
	 * @param string $imis_id     iMIS contact ID.
	 * @param string $location_id Per-practice location key ('' for single-location).
	 * @return int|null Post ID, or null for none.
	 */
	private static function find_post( string $imis_id, string $location_id ): ?int {
		if ( '' === $imis_id ) {
			return null;
		}

		// An exact (iMIS ID + this location) match, when the row names a location.
		if ( '' !== $location_id ) {
			$exact = self::query_post(
				[
					'relation' => 'AND',
					[
						'key'   => self::IMIS_META,
						'value' => $imis_id,
					],
					[
						'key'   => self::LOCATION_META,
						'value' => $location_id,
					],
				]
			);
			if ( null !== $exact ) {
				return $exact;
			}
		}

		// Otherwise (single-location row, or the first location of a multi-location doctor) adopt
		// a pin for this iMIS ID that has no location yet — including a CSV-seeded pin that predates
		// the location meta entirely — so a pin is updated in place rather than duplicated.
		return self::query_post(
			[
				'relation' => 'AND',
				[
					'key'   => self::IMIS_META,
					'value' => $imis_id,
				],
				[
					'relation' => 'OR',
					[
						'key'     => self::LOCATION_META,
						'value'   => '',
						'compare' => '=',
					],
					[
						'key'     => self::LOCATION_META,
						'compare' => 'NOT EXISTS',
					],
				],
			]
		);
	}

	/**
	 * The first lad_doctor post (any status) matching a meta query, or null.
	 *
	 * @param array $meta_query A meta_query array.
	 * @return int|null
	 */
	private static function query_post( array $meta_query ): ?int {
		$found = get_posts(
			[
				'post_type'        => self::POST_TYPE,
				'post_status'      => 'any',
				'posts_per_page'   => 1,
				'fields'           => 'ids',
				'suppress_filters' => false,
				'meta_query'       => $meta_query, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Keyed lookup; the directory is small.
			]
		);
		return empty( $found ) ? null : (int) $found[0];
	}

	/**
	 * Draft every sync-managed, currently published pin whose (iMIS ID + location) the
	 * latest complete feed did not contain. Only pins carrying the management stamp are
	 * ever touched, so CSV-only or hand-made records are left alone.
	 *
	 * @param array<string, bool> $seen Keys seen in this run.
	 * @return int How many pins were drafted.
	 */
	private static function reconcile( array $seen ): int {
		$managed = get_posts(
			[
				'post_type'        => self::POST_TYPE,
				'post_status'      => 'publish',
				'posts_per_page'   => -1, // phpcs:ignore WordPress.WP.PostsPerPage.posts_per_page_posts_per_page -- One reconciliation pass over a small directory.
				'fields'           => 'ids',
				'suppress_filters' => false,
				'no_found_rows'    => true,
				'meta_query'       => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- One reconciliation pass over a small directory.
					[
						'key'     => self::MANAGED_META,
						'compare' => 'EXISTS',
					],
				],
			]
		);

		$drafted = 0;
		foreach ( $managed as $post_id ) {
			$post_id = (int) $post_id;
			$imis_id = (string) get_post_meta( $post_id, self::IMIS_META, true );
			$loc     = (string) get_post_meta( $post_id, self::LOCATION_META, true );
			if ( isset( $seen[ self::key( $imis_id, $loc ) ] ) ) {
				continue;
			}
			wp_update_post(
				[
					'ID'          => $post_id,
					'post_status' => 'draft',
				]
			);
			++$drafted;
		}
		return $drafted;
	}

	/**
	 * The upsert/reconcile key for an iMIS ID + location.
	 *
	 * @param string $imis_id     iMIS contact ID.
	 * @param string $location_id Location key.
	 * @return string
	 */
	private static function key( string $imis_id, string $location_id ): string {
		return $imis_id . '::' . $location_id;
	}

	/**
	 * Translate one IQA row (any of the recognised column names) into the lad fields.
	 * Column names are matched case- and space-insensitively, as the lad importer does.
	 *
	 * @param array<string, mixed> $row Property name => value.
	 * @return array<string, string>
	 */
	private static function normalize( array $row ): array {
		$lookup = [];
		foreach ( $row as $name => $value ) {
			$compact = strtolower( str_replace( [ ' ', '_' ], '', (string) $name ) );
			if ( '' !== $compact && ! array_key_exists( $compact, $lookup ) ) {
				$lookup[ $compact ] = is_scalar( $value ) ? trim( (string) $value ) : '';
			}
		}
		$get = static function ( ...$keys ) use ( $lookup ): string {
			foreach ( $keys as $k ) {
				if ( isset( $lookup[ $k ] ) && '' !== $lookup[ $k ] ) {
					return $lookup[ $k ];
				}
			}
			return '';
		};

		$name = $get( 'name', 'fullname' );
		if ( '' === $name ) {
			$name = trim( $get( 'firstname' ) . ' ' . $get( 'lastname' ) );
		}
		if ( '' === $name ) {
			$name = $get( 'company' );
		}

		$address = $get( 'address', 'fulladdress', 'geocodeaddress' );
		if ( '' === $address ) {
			$line1   = $get( 'addressnumber1', 'address1', 'addressline1' );
			$line2   = $get( 'addressnumber2', 'address2', 'addressline2' );
			$joiner  = ( '' !== $line1 && '' !== $line2 ) ? ', ' : '';
			$address = trim( $line1 . $joiner . $line2 );
		}

		return [
			'name'          => $name,
			'clinic'        => $get( 'clinic', 'company', 'workplace' ),
			'address'       => $address,
			'suburb'        => $get( 'suburb', 'city' ),
			'state'         => $get( 'state', 'county' ),
			'postcode'      => $get( 'postcode', 'postalcode', 'zip', 'zipcode', 'pc2' ),
			'region'        => self::normalize_region( $get( 'region', 'country' ) ),
			'phone'         => $get( 'phone', 'workphone', 'phonenumber', 'telephone' ),
			'email'         => $get( 'email' ),
			'website'       => $get( 'website', 'url' ),
			'latitude'      => $get( 'latitude', 'lat' ),
			'longitude'     => $get( 'longitude', 'lng', 'long' ),
			'accreditation' => $get( 'accreditation' ),
			'photo_url'     => $get( 'photourl', 'photo', 'image' ),
			'bio'           => $get( 'bio' ),
			'imis_id'       => $get( 'imisid', 'id' ),
			'location_id'   => $get( 'location', 'locationid', 'addressnumber' ),
			'address_type'  => $get( 'addresstype', 'addresspurpose', 'purpose' ),
			'optout'        => $get( 'optout', 'smartmapsoptout', 'donotpublish', 'donotshow' ),
		];
	}

	/**
	 * Interpret a value as a yes/true flag (1, true, yes, y, t, x).
	 *
	 * @param string $value Raw value.
	 * @return bool
	 */
	private static function is_truthy( string $value ): bool {
		return in_array( strtolower( trim( $value ) ), [ '1', 'true', 'yes', 'y', 't', 'x' ], true );
	}

	/**
	 * Whether an address purpose/type is safe to publish (practice/work only).
	 *
	 * @param string $type Address purpose/type.
	 * @return bool
	 */
	private static function is_public_address_type( string $type ): bool {
		$type = strtolower( trim( $type ) );
		if ( '' === $type ) {
			return true;
		}
		foreach ( [ 'work', 'business', 'practice', 'company', 'clinic', 'professional', 'office', 'surgery', 'rooms' ] as $allowed ) {
			if ( false !== strpos( $type, $allowed ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Normalise a country/region value to AU / NZ (anything else uppercased as-is).
	 *
	 * @param string $value Raw country/region.
	 * @return string
	 */
	private static function normalize_region( string $value ): string {
		$value = strtoupper( trim( $value ) );
		if ( '' === $value ) {
			return '';
		}
		if ( in_array( $value, [ 'AU', 'AUS', 'AUSTRALIA' ], true ) ) {
			return 'AU';
		}
		if ( in_array( $value, [ 'NZ', 'NZL', 'NEW ZEALAND', 'AOTEAROA', 'AOTEAROA NEW ZEALAND' ], true ) ) {
			return 'NZ';
		}
		return $value;
	}

	/**
	 * Download a photo and set it as the pin's featured image (best effort).
	 *
	 * @param int    $post_id Pin post ID.
	 * @param string $url     Image URL.
	 * @return void
	 */
	private static function sideload_photo( int $post_id, string $url ): void {
		if ( '' === $url ) {
			return;
		}
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		$attachment_id = media_sideload_image( $url, $post_id, null, 'id' );
		if ( ! is_wp_error( $attachment_id ) ) {
			set_post_thumbnail( $post_id, (int) $attachment_id );
		}
	}

	/**
	 * Store the last run's report and time.
	 *
	 * @param array<string, int|bool|string> $report Run report.
	 * @return void
	 */
	private static function remember( array $report ): void {
		$config         = self::config();
		$config['last'] = $report;
		$config['when'] = time();
		update_option( self::OPTION, $config, false );
	}

	/**
	 * Save the feature's settings (admin-post handler).
	 *
	 * @return void
	 */
	public static function save_settings(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'wormiss' ) );
		}
		check_admin_referer( 'wormiss_save_doctor_sync', 'wormiss_doctor_sync_nonce' );

		$existing = get_option( self::OPTION, [] );
		$existing = is_array( $existing ) ? $existing : [];
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Verified by check_admin_referer above.
		$existing['enabled'] = isset( $_POST['wormiss_doctor_sync_enabled'] );
		$existing['query']   = isset( $_POST['wormiss_doctor_sync_query'] )
			? sanitize_text_field( wp_unslash( $_POST['wormiss_doctor_sync_query'] ) )
			: '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing
		update_option( self::OPTION, $existing, false );
		self::ensure_schedule();

		wp_safe_redirect( self::return_url( 'wormiss_doctor_sync_saved' ) );
		exit;
	}

	/**
	 * Create or clear the nightly schedule to match the enabled setting. Self-heals on admin
	 * page loads and is refreshed when the settings are saved.
	 *
	 * @return void
	 */
	public static function ensure_schedule(): void {
		$config = self::config();
		if ( $config['enabled'] && '' !== $config['query'] ) {
			if ( false === wp_next_scheduled( self::DAILY_HOOK ) ) {
				wp_schedule_event( self::next_night(), 'daily', self::DAILY_HOOK );
			}
		} else {
			wp_clear_scheduled_hook( self::DAILY_HOOK );
		}
	}

	/**
	 * The timestamp of the next early-morning run in the site's timezone (off-peak, DST-safe).
	 *
	 * @return int
	 */
	private static function next_night(): int {
		$zone = wp_timezone();
		$now  = new DateTimeImmutable( 'now', $zone );
		$next = $now->setTime( 2, 15 );
		if ( $next <= $now ) {
			$next = $next->modify( '+1 day' );
		}
		return $next->getTimestamp();
	}

	/**
	 * The next scheduled automatic run, or 0 if none.
	 *
	 * @return int
	 */
	public static function next_run(): int {
		$when = wp_next_scheduled( self::DAILY_HOOK );
		return false === $when ? 0 : (int) $when;
	}

	/**
	 * Queue a background geocoding pass if any pins still need coordinates.
	 *
	 * @return void
	 */
	private static function maybe_schedule_geocode(): void {
		if ( self::count_pending_geocode() > 0 && false === wp_next_scheduled( self::MORE_HOOK ) ) {
			wp_schedule_single_event( time() + 5, self::MORE_HOOK );
		}
	}

	/**
	 * Geocode a batch of pins missing coordinates, rescheduling itself while more remain. Reuses
	 * the Locate a Doctor geocoder (Google when a key is set in lad, otherwise Nominatim).
	 *
	 * @return void
	 */
	public static function geocode_step(): void {
		if ( ! class_exists( 'LAD_Geocode' ) ) {
			return;
		}
		$ids = self::pending_geocode_ids( self::GEOCODE_CHUNK );
		Wormiss_Batch::start( 60 );
		foreach ( $ids as $post_id ) {
			if ( Wormiss_Batch::out_of_time() ) {
				break;
			}
			if ( ! LAD_Geocode::geocode_post( $post_id ) ) {
				// Mark failed so it is not retried every pass; a later sync clears this to retry.
				update_post_meta( $post_id, self::LAD_PREFIX . 'geocode_failed', '1' );
				Wormiss_Logger::record_call( 'doctor-geocode', Wormiss_Logger::STATUS_ERROR, 'No coordinates for post ' . $post_id );
			}
		}
		if ( self::count_pending_geocode() > 0 && false === wp_next_scheduled( self::MORE_HOOK ) ) {
			wp_schedule_single_event( time() + 5, self::MORE_HOOK );
		}
	}

	/**
	 * Pins that still need coordinates: published, no latitude yet, not already marked failed.
	 *
	 * @param int $limit Most pins to return.
	 * @return int[]
	 */
	private static function pending_geocode_ids( int $limit ): array {
		return array_map(
			'intval',
			get_posts(
				[
					'post_type'        => self::POST_TYPE,
					'post_status'      => 'publish',
					'posts_per_page'   => $limit,
					'fields'           => 'ids',
					'suppress_filters' => false,
					'no_found_rows'    => true,
					'meta_query'       => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Keyed lookup over a small directory.
						'relation' => 'AND',
						[
							'relation' => 'OR',
							[
								'key'     => self::LAD_PREFIX . 'latitude',
								'compare' => 'NOT EXISTS',
							],
							[
								'key'     => self::LAD_PREFIX . 'latitude',
								'value'   => '',
								'compare' => '=',
							],
						],
						[
							'key'     => self::LAD_PREFIX . 'geocode_failed',
							'compare' => 'NOT EXISTS',
						],
					],
				]
			)
		);
	}

	/**
	 * How many pins still need coordinates.
	 *
	 * @return int
	 */
	private static function count_pending_geocode(): int {
		return count( self::pending_geocode_ids( self::MAX_ROWS ) );
	}

	/**
	 * The admin URL to return to after a form post, with a notice flag added.
	 *
	 * @param string $flag Query flag to add.
	 * @return string
	 */
	private static function return_url( string $flag ): string {
		$referer = wp_get_referer();
		if ( false === $referer ) {
			$referer = admin_url();
		}
		return add_query_arg( $flag, '1', $referer );
	}

	/**
	 * Queue an immediate background sync (admin-post handler).
	 *
	 * @return void
	 */
	public static function sync_now(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'wormiss' ) );
		}
		check_admin_referer( 'wormiss_sync_doctors' );

		// Run in the background shortly. WordPress de-duplicates an identical single event within
		// ten minutes, so a double click will not start two syncs. The nightly run uses a separate
		// hook, so this still queues when a nightly run is pending.
		wp_schedule_single_event( time() + 5, self::HOOK );

		wp_safe_redirect( self::return_url( 'wormiss_doctor_sync_queued' ) );
		exit;
	}

	/**
	 * A one-line summary of the last run, for the admin screen.
	 *
	 * @return string
	 */
	public static function summary(): string {
		$stored = get_option( self::OPTION, [] );
		$last   = is_array( $stored ) && isset( $stored['last'] ) && is_array( $stored['last'] ) ? $stored['last'] : null;

		if ( null === $last ) {
			$base = __( 'Not run yet.', 'wormiss' );
		} elseif ( empty( $last['complete'] ) ) {
			$base = sprintf(
				/* translators: %s: error message from iMIS. */
				__( 'Last run did not complete (directory left unchanged): %s', 'wormiss' ),
				'' !== (string) ( $last['message'] ?? '' ) ? (string) $last['message'] : __( 'unknown error', 'wormiss' )
			);
		} else {
			$base = sprintf(
				/* translators: 1: created count, 2: updated count, 3: drafted count, 4: skipped opt-out count. */
				__( '%1$d created, %2$d updated, %3$d drafted (withdrawn), %4$d opted out.', 'wormiss' ),
				(int) ( $last['created'] ?? 0 ),
				(int) ( $last['updated'] ?? 0 ),
				(int) ( $last['drafted'] ?? 0 ),
				(int) ( $last['skipped_optout'] ?? 0 )
			);
		}

		$pending = self::count_pending_geocode();
		if ( $pending > 0 ) {
			/* translators: %d: number of pins still needing coordinates. */
			$base .= ' ' . sprintf( _n( '%d pin still needs geocoding.', '%d pins still need geocoding.', $pending, 'wormiss' ), $pending );
		}

		$next = self::next_run();
		if ( $next > 0 ) {
			/* translators: %s: date and time of the next automatic run. */
			$base .= ' ' . sprintf( __( 'Next automatic sync: %s.', 'wormiss' ), wp_date( 'j M Y, g:i a', $next ) );
		}

		return $base;
	}

	/**
	 * The admin section: enable toggle, the IQA query, Save, and a Sync now button.
	 * Called from the wormiss settings screen.
	 *
	 * @return void
	 */
	public static function render_admin_section(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$config = self::config();
		$lad_on = post_type_exists( self::POST_TYPE );

		echo '<hr /><h2>' . esc_html__( 'Doctor directory sync', 'wormiss' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'Fill the Locate a Doctor map from an approved iMIS IQA. Opt-out and non-practice rows are never published, and doctors the feed no longer lists are drafted after a complete, successful sync.', 'wormiss' ) . '</p>';

		if ( ! $lad_on ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'The Locate a Doctor plugin is not active, so the sync has nowhere to write.', 'wormiss' ) . '</p></div>';
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only notice flags, no state change.
		if ( isset( $_GET['wormiss_doctor_sync_saved'] ) ) {
			echo '<div class="notice notice-success inline"><p>' . esc_html__( 'Doctor sync settings saved.', 'wormiss' ) . '</p></div>';
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only notice flags, no state change.
		if ( isset( $_GET['wormiss_doctor_sync_queued'] ) ) {
			echo '<div class="notice notice-success inline"><p>' . esc_html__( 'Sync queued; it runs in the background shortly.', 'wormiss' ) . '</p></div>';
		}

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'wormiss_save_doctor_sync', 'wormiss_doctor_sync_nonce' );
		echo '<input type="hidden" name="action" value="wormiss_save_doctor_sync" />';
		echo '<table class="form-table" role="presentation"><tbody>';
		echo '<tr><th scope="row">' . esc_html__( 'Enabled', 'wormiss' ) . '</th><td><label><input type="checkbox" name="wormiss_doctor_sync_enabled" value="1" ';
		checked( $config['enabled'] );
		echo ' /> ' . esc_html__( 'Allow the doctor directory to sync from iMIS', 'wormiss' ) . '</label></td></tr>';
		echo '<tr><th scope="row"><label for="wormiss_doctor_sync_query">' . esc_html__( 'IQA query', 'wormiss' ) . '</label></th><td><input type="text" class="regular-text code" id="wormiss_doctor_sync_query" name="wormiss_doctor_sync_query" value="' . esc_attr( $config['query'] ) . '" placeholder="$/SCCA/LocateADoctor" /><p class="description">' . esc_html__( 'The document path of the IQA, which must have "Available via the REST API" ticked.', 'wormiss' ) . '</p></td></tr>';
		echo '</tbody></table>';
		submit_button( __( 'Save', 'wormiss' ) );
		echo '</form>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin-top:8px">';
		wp_nonce_field( 'wormiss_sync_doctors' );
		echo '<input type="hidden" name="action" value="wormiss_sync_doctors" />';
		$queued = false !== wp_next_scheduled( self::HOOK );
		echo '<button type="submit" class="button button-primary"';
		disabled( self::is_ready() && ! $queued, false );
		echo '>' . esc_html__( 'Sync now', 'wormiss' ) . '</button> ';
		if ( $queued ) {
			echo '<span class="description">' . esc_html__( 'A sync is queued and runs shortly…', 'wormiss' ) . '</span> ';
		}
		echo '<span class="description">' . esc_html( self::summary() ) . '</span>';
		echo '</form>';
	}
}
