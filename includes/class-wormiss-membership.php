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
 * A copy of each member's iMIS membership details on their WordPress account.
 *
 * @package    wormiss
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined( 'ABSPATH' ) || exit;

/**
 * The member sync copies each member's type, status, join date and expiry date from the
 * members query onto their account. iMIS stays the record: the copy is replaced by each sync
 * and is never edited in WordPress. Shown on the member's profile (read only) and in the
 * My membership block, and included in personal data exports and erasures.
 */
class Wormiss_Membership {

	/** User meta holding the copy: type, status, joined and expires (Y-m-d, or ''). */
	const META = '_wormiss_membership';

	/** The details kept, in the order shown. */
	const FIELDS = [ 'type', 'status', 'joined', 'expires' ];

	/**
	 * Hook in.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'show_user_profile', [ __CLASS__, 'profile_section' ] );
		add_action( 'edit_user_profile', [ __CLASS__, 'profile_section' ] );
	}

	/**
	 * The field names shown for each detail.
	 *
	 * @return string[] By field.
	 */
	public static function labels(): array {
		return [
			'type'    => __( 'Member type', 'wormiss' ),
			'status'  => __( 'Status', 'wormiss' ),
			'joined'  => __( 'Member since', 'wormiss' ),
			'expires' => __( 'Paid until', 'wormiss' ),
		];
	}

	/**
	 * An iMIS date (e.g. 2026-12-31T00:00:00, or 31/12/2026) as Y-m-d; '' if none or not a date.
	 *
	 * @param string $value Date from iMIS.
	 * @return string
	 */
	public static function date( string $value ): string {
		$value = trim( $value );
		if ( preg_match( '/^(\d{4})-(\d{2})-(\d{2})/', $value, $match ) ) {
			[ , $year, $month, $day ] = $match;
		} elseif ( preg_match( '#^(\d{1,2})/(\d{1,2})/(\d{4})$#', $value, $match ) ) {
			// Australian order: day first.
			[ , $day, $month, $year ] = $match;
		} else {
			return '';
		}
		// iMIS uses 1900-01-01 for "no date".
		if ( ! checkdate( (int) $month, (int) $day, (int) $year ) || (int) $year <= 1900 ) {
			return '';
		}
		return sprintf( '%04d-%02d-%02d', $year, $month, $day );
	}

	/**
	 * The details to keep, from a member sync row.
	 *
	 * @param array $member From Wormiss_User_Sync::member().
	 * @return string[] By field.
	 */
	public static function from_member( array $member ): array {
		$details = [];
		foreach ( self::FIELDS as $field ) {
			$details[ $field ] = (string) ( $member[ $field ] ?? '' );
		}
		return $details;
	}

	/**
	 * A member's kept details (all '' when there are none).
	 *
	 * @param int $user_id User ID.
	 * @return string[] By field.
	 */
	public static function get( int $user_id ): array {
		$saved = get_user_meta( $user_id, self::META, true );
		return self::from_member( is_array( $saved ) ? $saved : [] );
	}

	/**
	 * Whether a member has any details kept.
	 *
	 * @param int $user_id User ID.
	 * @return bool
	 */
	public static function has( int $user_id ): bool {
		return [] !== array_filter( self::get( $user_id ) );
	}

	/**
	 * Whether the kept details differ from these.
	 *
	 * @param int      $user_id User ID.
	 * @param string[] $details From from_member().
	 * @return bool
	 */
	public static function differs( int $user_id, array $details ): bool {
		return self::get( $user_id ) !== self::from_member( $details );
	}

	/**
	 * Keep a member's details (none at all removes the copy).
	 *
	 * @param int      $user_id User ID.
	 * @param string[] $details From from_member().
	 * @return void
	 */
	public static function save( int $user_id, array $details ): void {
		$details = self::from_member( $details );
		if ( [] === array_filter( $details ) ) {
			delete_user_meta( $user_id, self::META );
			return;
		}
		update_user_meta( $user_id, self::META, $details );
	}

	/**
	 * Remove a member's details (privacy erasure).
	 *
	 * @param int $user_id User ID.
	 * @return int 1 if there were details, else 0.
	 */
	public static function delete_for_user( int $user_id ): int {
		$had = self::has( $user_id );
		delete_user_meta( $user_id, self::META );
		return $had ? 1 : 0;
	}

	/**
	 * A Y-m-d date in the site's date format.
	 *
	 * @param string $ymd Date.
	 * @return string
	 */
	public static function show_date( string $ymd ): string {
		$date = DateTimeImmutable::createFromFormat( '!Y-m-d', $ymd, wp_timezone() );
		return $date ? wp_date( (string) get_option( 'date_format' ), $date->getTimestamp(), wp_timezone() ) : '';
	}

	/**
	 * Whether a membership's paid-until date has passed (in the site's time zone).
	 *
	 * @param string $expires Y-m-d, or ''.
	 * @return bool
	 */
	public static function has_expired( string $expires ): bool {
		return '' !== $expires && $expires < wp_date( 'Y-m-d', null, wp_timezone() );
	}

	/**
	 * The details as name and value pairs, leaving out blank ones.
	 *
	 * @param int $user_id User ID.
	 * @return string[] By label.
	 */
	public static function rows( int $user_id ): array {
		$details = self::get( $user_id );
		$rows    = [];
		foreach ( self::labels() as $field => $label ) {
			$value = $details[ $field ];
			if ( '' === $value ) {
				continue;
			}
			if ( 'joined' === $field || 'expires' === $field ) {
				$value = self::show_date( $value );
			}
			if ( 'expires' === $field && self::has_expired( $details['expires'] ) ) {
				/* translators: %s: date. */
				$value = sprintf( __( '%s (expired)', 'wormiss' ), $value );
			}
			$rows[ $label ] = $value;
		}
		return $rows;
	}

	/**
	 * The My membership block's content for the signed-in member ('' when signed out).
	 *
	 * @return string
	 */
	public static function block_html(): string {
		if ( ! is_user_logged_in() ) {
			return '';
		}
		$rows = self::rows( get_current_user_id() );
		if ( ! $rows ) {
			return '<p class="wormiss-membership__empty">'
				. esc_html__( 'Your membership details will show here after the next daily update from iMIS.', 'wormiss' ) . '</p>';
		}
		$html = '<dl class="wormiss-membership">';
		foreach ( $rows as $label => $value ) {
			$html .= '<dt>' . esc_html( $label ) . '</dt><dd>' . esc_html( $value ) . '</dd>';
		}
		return $html . '</dl>';
	}

	/**
	 * The iMIS membership section on a user's profile (read only).
	 *
	 * @param WP_User $user User being shown.
	 * @return void
	 */
	public static function profile_section( $user ): void {
		if ( ! $user instanceof WP_User || ! self::has( $user->ID ) ) {
			return;
		}
		echo '<h2>' . esc_html__( 'iMIS membership', 'wormiss' ) . '</h2>';
		echo '<p class="description">'
			. esc_html__( 'Copied from iMIS by the daily member sync. Change these in iMIS.', 'wormiss' ) . '</p>';
		echo '<table class="form-table" role="presentation"><tbody>';
		foreach ( self::rows( $user->ID ) as $label => $value ) {
			echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td>' . esc_html( $value ) . '</td></tr>';
		}
		echo '</tbody></table>';
	}

	/**
	 * A member's details for Tools > Export Personal Data.
	 *
	 * @param int $user_id User ID.
	 * @return array Export items (none when there are no details).
	 */
	public static function export_items( int $user_id ): array {
		$data = [];
		foreach ( self::rows( $user_id ) as $label => $value ) {
			$data[] = [
				'name'  => $label,
				'value' => $value,
			];
		}
		if ( ! $data ) {
			return [];
		}
		return [
			[
				'group_id'    => 'wormiss-membership',
				'group_label' => __( 'iMIS membership', 'wormiss' ),
				'item_id'     => 'wormiss-membership-' . $user_id,
				'data'        => $data,
			],
		];
	}
}
