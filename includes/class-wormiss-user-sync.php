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
 * Members synced from iMIS into WordPress users: created, updated, and blocked when lapsed.
 *
 * @package    wormiss
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined( 'ABSPATH' ) || exit;

/**
 * Reads members from an iMIS IQA and keeps WordPress users in step.
 *
 * Each row is matched to a user by iMIS ID, then by email; unmatched rows create a user.
 * Names and email are kept up to date. A member whose status is not current is blocked
 * from signing in (password or single sign-on) until it is current again. Staff accounts
 * (anyone who can edit posts) are never changed or blocked. Off, and a dry run, by default.
 */
class Wormiss_User_Sync {

	/** Cron hook for the daily sync. */
	const HOOK = 'wormiss_user_sync';

	/** User meta: set while a member is blocked (when it was set). */
	const META_BLOCKED = '_wormiss_blocked';

	/** Option holding the last run's report. */
	const REPORT_OPTION = 'wormiss_user_sync_report';

	/** Most notes kept in a report. */
	const MAX_NOTES = 20;

	/** A run that would block more than this share of matched members blocks no one. */
	const MASS_BLOCK_SHARE = 0.2;

	/** ... unless it is this many or fewer. */
	const MASS_BLOCK_MIN = 10;

	/**
	 * Hook in.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( self::HOOK, [ __CLASS__, 'run' ] );
		add_action( 'init', [ __CLASS__, 'maybe_schedule' ] );
		add_action( 'admin_post_wormiss_user_sync_now', [ __CLASS__, 'handle_run_now' ] );
		// Blocked members: password sign-in is refused, and any session (including single
		// sign-on, which may log people in without a password) is treated as signed out.
		add_filter( 'authenticate', [ __CLASS__, 'refuse_blocked' ], 99 );
		add_filter( 'determine_current_user', [ __CLASS__, 'drop_blocked' ], 99 );
	}

	/**
	 * Keep the daily run scheduled while syncing is on, and none while it is off.
	 *
	 * @return void
	 */
	public static function maybe_schedule(): void {
		$next = wp_next_scheduled( self::HOOK );
		if ( Wormiss_Settings::get( 'user_sync_enabled' ) && ! $next ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::HOOK );
		} elseif ( ! Wormiss_Settings::get( 'user_sync_enabled' ) && $next ) {
			wp_clear_scheduled_hook( self::HOOK );
		}
	}

	/**
	 * Whether a user is blocked (never staff).
	 *
	 * @param int $user_id User ID.
	 * @return bool
	 */
	public static function is_blocked( int $user_id ): bool {
		return $user_id > 0 && (bool) get_user_meta( $user_id, self::META_BLOCKED, true ) && ! self::is_staff( $user_id );
	}

	/**
	 * Refuse a blocked member's sign-in with a plain message.
	 *
	 * @param mixed $user WP_User, WP_Error or null.
	 * @return mixed
	 */
	public static function refuse_blocked( $user ) {
		if ( $user instanceof WP_User && self::is_blocked( $user->ID ) ) {
			return new WP_Error(
				'wormiss_lapsed',
				__( 'Your membership is not current, so you cannot sign in here. Please contact us to renew.', 'wormiss' )
			);
		}
		return $user;
	}

	/**
	 * Treat a blocked member's session as signed out.
	 *
	 * @param int|false $user_id User ID found so far.
	 * @return int|false
	 */
	public static function drop_blocked( $user_id ) {
		return ( $user_id && self::is_blocked( (int) $user_id ) ) ? 0 : $user_id;
	}

	/**
	 * The Run now button (Settings): run the sync and go back.
	 *
	 * @return void
	 */
	public static function handle_run_now(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'wormiss' ), 403 );
		}
		check_admin_referer( 'wormiss_user_sync_now' );
		self::run();
		wp_safe_redirect( Wormiss_Admin::settings_url() . '#wormiss-user-sync' );
		exit;
	}

	/**
	 * Sync members from iMIS. Nothing changes if iMIS cannot be read.
	 *
	 * @return array The report (also saved for the settings page).
	 */
	public static function run(): array {
		$report = [
			'time'      => time(),
			'dry_run'   => (bool) Wormiss_Settings::get( 'user_sync_dry_run' ),
			'status'    => 'ok',
			'rows'      => 0,
			'created'   => 0,
			'updated'   => 0,
			'blocked'   => 0,
			'unblocked' => 0,
			'skipped'   => 0,
			'notes'     => [],
		];
		$query  = (string) Wormiss_Settings::get( 'user_sync_query' );
		if ( ! Wormiss_Settings::get( 'user_sync_enabled' ) || '' === $query || ! Wormiss_Settings::is_configured() ) {
			$report['status'] = 'off';
			return self::save( $report );
		}
		try {
			$rows = ( new Wormiss_Rest_Client() )->run_iqa( $query );
		} catch ( Wormiss_Exception $e ) {
			$report['status']  = 'error';
			$report['notes'][] = $e->getMessage();
			return self::save( $report );
		}
		$report['rows'] = count( $rows );

		$current = array_map( 'strtolower', (array) Wormiss_Settings::get( 'access_statuses' ) );
		if ( ! $current ) {
			$report['notes'][] = __( 'No current statuses are set (Members-only content), so no one is blocked.', 'wormiss' );
		}

		// Work out every change first, so a run that would block too many can hold back.
		$plans = [];
		foreach ( $rows as $row ) {
			$plan = self::plan( self::member( $row ), $current );
			if ( isset( $plan['note'] ) ) {
				self::note( $report, $plan['note'] );
			}
			$plans[] = $plan;
		}
		$blocks  = count( array_filter( $plans, static fn( $p ) => 'block' === ( $p['access'] ?? '' ) ) );
		$matched = max( 1, count( array_filter( $plans, static fn( $p ) => ! empty( $p['user_id'] ) ) ) );
		$hold    = $blocks > self::MASS_BLOCK_MIN && $blocks / $matched > self::MASS_BLOCK_SHARE;
		if ( $hold ) {
			self::note(
				$report,
				sprintf(
					/* translators: %d: number of members. */
					__( '%d members would have been blocked, an unusually large share, so no one was blocked.', 'wormiss' )
						. ' ' . __( 'Check the query and the current statuses.', 'wormiss' ),
					$blocks
				)
			);
		}

		// Changing a member's email must not email them (or anyone) about it.
		add_filter( 'send_email_change_email', '__return_false' );
		add_filter( 'send_password_change_email', '__return_false' );
		foreach ( $plans as $plan ) {
			self::apply( $plan, $report, $hold );
		}
		remove_filter( 'send_email_change_email', '__return_false' );
		remove_filter( 'send_password_change_email', '__return_false' );

		return self::save( $report );
	}

	/**
	 * A row's member details, by column name ignoring case, spaces and underscores.
	 *
	 * @param array $row IQA row.
	 * @return array{id: string, email: string, first: string, last: string, status: string}
	 */
	public static function member( array $row ): array {
		$by = [];
		foreach ( $row as $name => $value ) {
			$by[ str_replace( [ ' ', '_' ], '', strtolower( (string) $name ) ) ] = is_scalar( $value ) ? trim( (string) $value ) : '';
		}
		$pick = static function ( array $names ) use ( $by ): string {
			foreach ( $names as $name ) {
				if ( isset( $by[ $name ] ) && '' !== $by[ $name ] ) {
					return $by[ $name ];
				}
			}
			return '';
		};
		return [
			'id'     => Wormiss_Member::clean_id( $pick( [ 'id', 'imisid', 'contactid', 'partyid' ] ) ),
			'email'  => sanitize_email( $pick( [ 'email', 'emailaddress' ] ) ),
			'first'  => sanitize_text_field( $pick( [ 'firstname' ] ) ),
			'last'   => sanitize_text_field( $pick( [ 'lastname' ] ) ),
			'status' => sanitize_text_field( $pick( [ 'status', 'memberstatus' ] ) ),
		];
	}

	/**
	 * What to do for one member.
	 *
	 * @param array    $member  From member().
	 * @param string[] $current Current statuses, lower case ('' list: never block).
	 * @return array
	 */
	private static function plan( array $member, array $current ): array {
		if ( '' === $member['id'] || ! is_email( $member['email'] ) ) {
			/* translators: %s: iMIS ID (may be blank). */
			return [ 'note' => sprintf( __( 'Skipped a row without a usable iMIS ID and email (ID "%s").', 'wormiss' ), $member['id'] ) ];
		}
		$lapsed  = $current && '' !== $member['status'] && ! in_array( strtolower( $member['status'] ), $current, true );
		$user_id = self::find_user( $member );
		$plan    = [
			'member'  => $member,
			'user_id' => $user_id,
			'lapsed'  => $lapsed,
		];
		if ( $user_id && self::is_staff( $user_id ) ) {
			$plan['staff'] = true;
			return $plan;
		}
		if ( $user_id ) {
			$blocked = (bool) get_user_meta( $user_id, self::META_BLOCKED, true );
			if ( $lapsed && ! $blocked ) {
				$plan['access'] = 'block';
			} elseif ( ! $lapsed && $blocked ) {
				$plan['access'] = 'unblock';
			}
		}
		return $plan;
	}

	/**
	 * Carry out one member's plan (or count it, in a dry run).
	 *
	 * @param array $plan   From plan().
	 * @param array $report Report, updated.
	 * @param bool  $hold   Do not block anyone this run.
	 * @return void
	 */
	private static function apply( array $plan, array &$report, bool $hold ): void {
		if ( ! isset( $plan['member'] ) ) {
			++$report['skipped'];
			return;
		}
		$member  = $plan['member'];
		$user_id = (int) $plan['user_id'];
		$dry     = $report['dry_run'];
		if ( ! empty( $plan['staff'] ) ) {
			++$report['skipped'];
			return;
		}
		if ( ! $user_id ) {
			if ( $plan['lapsed'] ) {
				++$report['skipped']; // Lapsed members are not given new accounts.
				return;
			}
			$created = $dry ? 1 : self::create( $member, $report );
			if ( $created ) {
				++$report['created'];
			}
			return;
		}
		if ( self::changes( $user_id, $member ) ) {
			if ( $dry || self::update( $user_id, $member, $report ) ) {
				++$report['updated'];
			}
		}
		$access = $plan['access'] ?? '';
		if ( 'block' === $access && ! $hold ) {
			if ( ! $dry ) {
				update_user_meta( $user_id, self::META_BLOCKED, time() );
				WP_Session_Tokens::get_instance( $user_id )->destroy_all();
			}
			++$report['blocked'];
		} elseif ( 'unblock' === $access ) {
			if ( ! $dry ) {
				delete_user_meta( $user_id, self::META_BLOCKED );
			}
			++$report['unblocked'];
		}
	}

	/**
	 * The WordPress user for a member: by iMIS ID, then by email; 0 if none.
	 *
	 * @param array $member From member().
	 * @return int
	 */
	private static function find_user( array $member ): int {
		if ( 'login' === Wormiss_Settings::get( 'member_id_source' ) ) {
			$user = get_user_by( 'login', $member['id'] );
		} else {
			// phpcs:disable WordPress.DB.SlowDBQuery -- One indexed meta lookup per member.
			$found = get_users(
				[
					'meta_key'   => (string) Wormiss_Settings::get( 'member_id_meta' ),
					'meta_value' => $member['id'],
					'number'     => 1,
					'fields'     => 'ID',
				]
			);
			// phpcs:enable
			$user = $found ? get_userdata( (int) $found[0] ) : false;
		}
		if ( ! $user ) {
			$user = get_user_by( 'email', $member['email'] );
			// An email match that already belongs to another iMIS ID is not this member.
			if ( $user && '' !== Wormiss_Member::imis_id( $user->ID ) && Wormiss_Member::imis_id( $user->ID ) !== $member['id'] ) {
				$user = false;
			}
		}
		return $user ? (int) $user->ID : 0;
	}

	/**
	 * Whether a user is staff (can edit posts): never changed or blocked by the sync.
	 *
	 * @param int $user_id User ID.
	 * @return bool
	 */
	private static function is_staff( int $user_id ): bool {
		return user_can( $user_id, 'edit_posts' );
	}

	/**
	 * Whether a user's names or email differ from iMIS, or their iMIS ID is not stored yet.
	 *
	 * @param int   $user_id User ID.
	 * @param array $member  From member().
	 * @return bool
	 */
	private static function changes( int $user_id, array $member ): bool {
		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return false;
		}
		return strtolower( $user->user_email ) !== strtolower( $member['email'] )
			|| ( '' !== $member['first'] && $user->first_name !== $member['first'] )
			|| ( '' !== $member['last'] && $user->last_name !== $member['last'] )
			|| Wormiss_Member::imis_id( $user_id ) !== $member['id'];
	}

	/**
	 * Create a user for a member: login = iMIS ID, the sync role, a random password, no email.
	 *
	 * @param array $member From member().
	 * @param array $report Report, for notes.
	 * @return bool
	 */
	private static function create( array $member, array &$report ): bool {
		if ( username_exists( $member['id'] ) || email_exists( $member['email'] ) ) {
			/* translators: %s: iMIS ID. */
			$message = __( 'Member %s: their username or email is already used by another account.', 'wormiss' );
			self::note( $report, sprintf( $message, $member['id'] ) );
			return false;
		}
		$name    = trim( $member['first'] . ' ' . $member['last'] );
		$user_id = wp_insert_user(
			[
				'user_login'   => $member['id'],
				'user_email'   => $member['email'],
				'user_pass'    => wp_generate_password( 32, true, true ),
				'first_name'   => $member['first'],
				'last_name'    => $member['last'],
				'display_name' => '' !== $name ? $name : $member['id'],
				'role'         => (string) Wormiss_Settings::get( 'user_sync_role' ),
			]
		);
		if ( is_wp_error( $user_id ) ) {
			/* translators: 1: iMIS ID, 2: reason. */
			$message = __( 'Member %1$s: not created (%2$s).', 'wormiss' );
			self::note( $report, sprintf( $message, $member['id'], $user_id->get_error_message() ) );
			return false;
		}
		if ( 'login' !== Wormiss_Settings::get( 'member_id_source' ) ) {
			update_user_meta( $user_id, (string) Wormiss_Settings::get( 'member_id_meta' ), $member['id'] );
		}
		return true;
	}

	/**
	 * Update a user's names, email and stored iMIS ID from iMIS.
	 *
	 * @param int   $user_id User ID.
	 * @param array $member  From member().
	 * @param array $report  Report, for notes.
	 * @return bool
	 */
	private static function update( int $user_id, array $member, array &$report ): bool {
		$data  = [ 'ID' => $user_id ];
		$owner = email_exists( $member['email'] );
		if ( $owner && (int) $owner !== $user_id ) {
			/* translators: %s: iMIS ID. */
			$message = __( 'Member %s: their iMIS email belongs to another account, so it was not changed.', 'wormiss' );
			self::note( $report, sprintf( $message, $member['id'] ) );
		} else {
			$data['user_email'] = $member['email'];
		}
		if ( '' !== $member['first'] ) {
			$data['first_name'] = $member['first'];
		}
		if ( '' !== $member['last'] ) {
			$data['last_name'] = $member['last'];
		}
		$result = wp_update_user( $data );
		if ( is_wp_error( $result ) ) {
			/* translators: 1: iMIS ID, 2: reason. */
			$message = __( 'Member %1$s: not updated (%2$s).', 'wormiss' );
			self::note( $report, sprintf( $message, $member['id'], $result->get_error_message() ) );
			return false;
		}
		if ( 'login' !== Wormiss_Settings::get( 'member_id_source' ) ) {
			update_user_meta( $user_id, (string) Wormiss_Settings::get( 'member_id_meta' ), $member['id'] );
		}
		return true;
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
	 * Keep the report for the settings page and the audit log.
	 *
	 * @param array $report Report.
	 * @return array
	 */
	private static function save( array $report ): array {
		update_option( self::REPORT_OPTION, $report, false );
		if ( 'off' !== $report['status'] ) {
			Wormiss_Logger::record_call(
				( $report['dry_run'] ? 'DRY RUN ' : '' ) . 'USER SYNC',
				'error' === $report['status'] ? Wormiss_Logger::STATUS_ERROR : Wormiss_Logger::STATUS_SUCCESS,
				'error' === $report['status'] ? implode( ' ', $report['notes'] ) : null,
				null,
				(string) wp_json_encode( $report )
			);
		}
		return $report;
	}

	/**
	 * The last run, in words, for the settings page.
	 *
	 * @return string Plain text ('' if it has never run).
	 */
	public static function summary(): string {
		$report = get_option( self::REPORT_OPTION );
		if ( ! is_array( $report ) || empty( $report['time'] ) ) {
			return '';
		}
		$when = wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $report['time'] );
		if ( 'error' === $report['status'] ) {
			/* translators: 1: date and time, 2: reason. */
			$message = __( 'Last run %1$s: iMIS could not be read, so nothing changed (%2$s).', 'wormiss' );
			return sprintf( $message, $when, implode( ' ', $report['notes'] ) );
		}
		if ( 'off' === $report['status'] ) {
			/* translators: %s: date and time. */
			return sprintf( __( 'Last run %s: syncing is off or has no query, so nothing changed.', 'wormiss' ), $when );
		}
		$text = sprintf(
			/* translators: 1: date and time, 2: rows, 3: created, 4: updated, 5: blocked, 6: unblocked, 7: skipped. */
			__( 'Last run %1$s: %2$d members read; %3$d created, %4$d updated, %5$d blocked, %6$d unblocked, %7$d skipped.', 'wormiss' ),
			$when,
			(int) $report['rows'],
			(int) $report['created'],
			(int) $report['updated'],
			(int) $report['blocked'],
			(int) $report['unblocked'],
			(int) $report['skipped']
		);
		if ( $report['dry_run'] ) {
			$text .= ' ' . __( 'Dry run: these are what would happen; nothing was changed.', 'wormiss' );
		}
		return $text;
	}
}
