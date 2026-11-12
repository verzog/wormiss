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
 * Sends members' name and email changes made in WordPress to their iMIS contact record.
 *
 * @package    wormiss
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined( 'ABSPATH' ) || exit;

/**
 * When a member's first name, last name or email changes in WordPress, their iMIS contact
 * (Party) is read, those three are set, and it is saved, in the background. Only members with
 * an iMIS ID, never staff, and never the member sync's own changes (which come from iMIS). Off,
 * and a dry run, until an administrator turns it on.
 */
class Wormiss_Member_Push {

	/** Cron hook that sends one member. */
	const HOOK = 'wormiss_member_push';

	/** User meta: how many sends in a row failed (for the retries). */
	const META_TRIES = '_wormiss_member_push_tries';

	/** Tries in all when iMIS cannot be reached (the first send plus retries). */
	const MAX_TRIES = 4;

	/** Seconds before a retry, times the number of tries so far. */
	const RETRY_GAP = 900;

	/**
	 * Each user's names as they were before the save under way (insert_user_meta runs before the
	 * new names are stored; the old WP_User would read the new ones).
	 *
	 * @var array User ID => first and last name.
	 */
	private static $before = [];

	/**
	 * Hook in.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_filter( 'insert_user_meta', [ __CLASS__, 'remember_names' ], 10, 3 );
		add_action( 'profile_update', [ __CLASS__, 'maybe_queue' ], 20, 2 );
		add_action( self::HOOK, [ __CLASS__, 'push' ] );
	}

	/**
	 * Whether sending member changes is on (and iMIS is set up).
	 *
	 * @return bool
	 */
	public static function is_on(): bool {
		return (bool) Wormiss_Settings::get( 'member_push_enabled' ) && Wormiss_Settings::is_configured();
	}

	/**
	 * What would be sent for a user: their first name, last name and email.
	 *
	 * @param WP_User $user User.
	 * @return array{first: string, last: string, email: string}
	 */
	public static function details( WP_User $user ): array {
		return [
			'first' => trim( (string) $user->first_name ),
			'last'  => trim( (string) $user->last_name ),
			'email' => trim( (string) $user->user_email ),
		];
	}

	/**
	 * Before an existing user's names are saved, remember the old ones (for maybe_queue()).
	 *
	 * @param array   $meta   User meta about to be saved (returned as it is).
	 * @param WP_User $user   User.
	 * @param bool    $update Whether this updates an existing user.
	 * @return array
	 */
	public static function remember_names( $meta, $user, $update ) {
		if ( $update && $user instanceof WP_User ) {
			self::$before[ (int) $user->ID ] = [
				'first' => trim( (string) get_user_meta( $user->ID, 'first_name', true ) ),
				'last'  => trim( (string) get_user_meta( $user->ID, 'last_name', true ) ),
			];
		}
		return $meta;
	}

	/**
	 * After a user is saved, queue sending their details to iMIS if this save changed their
	 * name or email (a password or biography change sends nothing, so it cannot put older
	 * WordPress details over newer ones in iMIS).
	 *
	 * @param int          $user_id  User ID.
	 * @param WP_User|null $old_user The user as they were before the save.
	 * @return void
	 */
	public static function maybe_queue( $user_id, $old_user = null ): void {
		$user_id = (int) $user_id;
		// The member sync's changes came from iMIS: sending them back would be a loop.
		if ( ! self::is_on() || Wormiss_User_Sync::$applying ) {
			return;
		}
		$user = get_userdata( $user_id );
		if ( ! $user instanceof WP_User || user_can( $user, 'edit_posts' ) || '' === Wormiss_Member::imis_id( $user_id ) ) {
			return;
		}
		$names = self::$before[ $user_id ] ?? null;
		unset( self::$before[ $user_id ] );
		if ( null === $names || ! $old_user instanceof WP_User ) {
			return; // Not a normal profile save: what changed is unknown, so nothing is sent.
		}
		$old = $names + [ 'email' => trim( (string) $old_user->user_email ) ];
		if ( self::details( $user ) === $old ) {
			return; // Nothing iMIS needs has changed.
		}
		delete_user_meta( $user_id, self::META_TRIES );
		if ( ! wp_next_scheduled( self::HOOK, [ $user_id ] ) ) {
			wp_schedule_single_event( time(), self::HOOK, [ $user_id ] );
		}
	}

	/**
	 * Send one member's details to iMIS (cron).
	 *
	 * @param int $user_id User ID.
	 * @return string Result: sent, unchanged, dry_run, skipped, off or error.
	 */
	public static function push( $user_id ): string {
		$user_id = (int) $user_id;
		$user    = get_userdata( $user_id );
		if ( ! self::is_on() ) {
			return 'off';
		}
		$imis_id = $user instanceof WP_User ? Wormiss_Member::imis_id( $user_id ) : '';
		if ( '' === $imis_id || user_can( $user, 'edit_posts' ) ) {
			return 'skipped';
		}
		$details = self::details( $user );
		if ( ! is_email( $details['email'] ) ) {
			return 'skipped';
		}
		$client = new Wormiss_Rest_Client();
		try {
			$party   = $client->get_party( $imis_id );
			$changed = self::apply( $party, $details );
			if ( ! $changed ) {
				delete_user_meta( $user_id, self::META_TRIES );
				return 'unchanged';
			}
			if ( Wormiss_Settings::get( 'member_push_dry_run' ) ) {
				Wormiss_Logger::record_call(
					'DRY RUN PUT /api/Party/' . $imis_id,
					Wormiss_Logger::STATUS_SUCCESS,
					null,
					(string) wp_json_encode( $party ),
					null,
					$imis_id
				);
				return 'dry_run';
			}
			$client->update_party( $imis_id, $party );
		} catch ( Wormiss_Exception $e ) {
			self::retry( $user_id ); // The reason is in the audit log.
			return 'error';
		}
		delete_user_meta( $user_id, self::META_TRIES );
		return 'sent';
	}

	/**
	 * After a failed send, try again later (iMIS may be down for a while), a few times with a
	 * growing gap, so a change is not lost; after the last try it waits for the next save.
	 *
	 * @param int $user_id User ID.
	 * @return void
	 */
	private static function retry( int $user_id ): void {
		$tries = (int) get_user_meta( $user_id, self::META_TRIES, true ) + 1;
		if ( $tries >= self::MAX_TRIES ) {
			delete_user_meta( $user_id, self::META_TRIES );
			return;
		}
		update_user_meta( $user_id, self::META_TRIES, $tries );
		if ( ! wp_next_scheduled( self::HOOK, [ $user_id ] ) ) {
			wp_schedule_single_event( time() + self::RETRY_GAP * $tries, self::HOOK, [ $user_id ] );
		}
	}

	/**
	 * Set the first name, last name and primary email on an iMIS PartyData (everything else is
	 * kept). Blank WordPress values never clear iMIS's.
	 *
	 * @param object $party   PartyData from iMIS, changed in place.
	 * @param array  $details From details().
	 * @return bool Whether anything changed.
	 */
	public static function apply( object $party, array $details ): bool {
		$changed = false;
		// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- iMIS fields.
		if ( isset( $party->PersonName ) && is_object( $party->PersonName ) ) {
			foreach ( [
				'first' => 'FirstName',
				'last'  => 'LastName',
			] as $key => $field ) {
				if ( '' !== $details[ $key ] && (string) ( $party->PersonName->{$field} ?? '' ) !== $details[ $key ] ) {
					$party->PersonName->{$field} = $details[ $key ];
					$changed                     = true;
				}
			}
		}
		if ( '' !== $details['email'] ) {
			$emails = isset( $party->Emails ) && is_object( $party->Emails ) ? $party->Emails : null;
			$values = null !== $emails && isset( $emails->{'$values'} ) && is_array( $emails->{'$values'} ) ? $emails->{'$values'} : [];
			$main   = null;
			foreach ( $values as $email ) {
				if ( is_object( $email ) && ! empty( $email->IsPrimary ) ) {
					$main = $email;
					break;
				}
			}
			// With no primary email, one already holding this address becomes the primary; other
			// addresses (Work and so on) are never overwritten, and a new primary is added instead.
			foreach ( null === $main ? $values : [] as $email ) {
				if ( is_object( $email ) && 0 === strcasecmp( (string) ( $email->Address ?? '' ), $details['email'] ) ) {
					$email->IsPrimary = true;
					return true;
				}
			}
			if ( null !== $main ) {
				if ( 0 !== strcasecmp( (string) ( $main->Address ?? '' ), $details['email'] ) ) {
					$main->Address = $details['email'];
					$changed       = true;
				}
			} else {
				$values[]      = (object) [
					'$type'     => 'Asi.Soa.Membership.DataContracts.EmailData, Asi.Contracts',
					'Address'   => $details['email'],
					'EmailType' => '_Primary',
					'IsPrimary' => true,
				];
				$party->Emails = (object) [
					'$type'   => 'Asi.Soa.Membership.DataContracts.EmailDataCollection, Asi.Contracts',
					'$values' => $values,
				];
				$changed       = true;
			}
		}
		// phpcs:enable
		return $changed;
	}
}
