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
 * Members: which iMIS contact a WordPress user is, and the iMIS ID field on user profiles.
 *
 * With iMIS single sign-on, the SSO plugin stores each member's iMIS ID on their
 * WordPress account (by default in the user meta field "imis_id"); Wormiss reads it
 * from there, or from the username, as set in Wormiss > Settings. Developers can
 * replace the lookup with the "wormiss_member_imis_id" filter.
 *
 * @package    wormiss
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined( 'ABSPATH' ) || exit;

/**
 * Resolves members' iMIS IDs and adds the field to user profiles.
 */
class Wormiss_Member {

	/** Longest iMIS ID accepted. */
	const MAX_ID_LENGTH = 64;

	/**
	 * Hook the profile field.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'show_user_profile', [ __CLASS__, 'profile_field' ] );
		add_action( 'edit_user_profile', [ __CLASS__, 'profile_field' ] );
		add_action( 'personal_options_update', [ __CLASS__, 'save_profile_field' ] );
		add_action( 'edit_user_profile_update', [ __CLASS__, 'save_profile_field' ] );
		add_filter( 'manage_users_columns', [ __CLASS__, 'users_column' ] );
		add_filter( 'manage_users_custom_column', [ __CLASS__, 'users_column_value' ], 10, 3 );
	}

	/**
	 * The iMIS ID of a user (default: the signed-in user), or '' if there is none.
	 *
	 * @param int|null $user_id WordPress user ID.
	 * @return string
	 */
	public static function imis_id( ?int $user_id = null ): string {
		$user_id = null === $user_id ? get_current_user_id() : $user_id;
		$user    = $user_id ? get_userdata( $user_id ) : false;
		if ( ! $user ) {
			return '';
		}
		if ( 'login' === Wormiss_Settings::get( 'member_id_source' ) ) {
			$id = $user->user_login;
		} else {
			$id = get_user_meta( $user->ID, (string) Wormiss_Settings::get( 'member_id_meta' ), true );
		}

		/**
		 * Filters the iMIS ID used for a WordPress user.
		 *
		 * @param string  $id   The iMIS ID found ('' if none).
		 * @param WP_User $user The user.
		 */
		$id = apply_filters( 'wormiss_member_imis_id', is_scalar( $id ) ? (string) $id : '', $user );
		return self::clean_id( is_scalar( $id ) ? (string) $id : '' );
	}

	/**
	 * A trimmed iMIS ID, or '' if it is not one (letters, digits, dot, dash, underscore; 1 to 64).
	 *
	 * @param string $id Raw value.
	 * @return string
	 */
	public static function clean_id( string $id ): string {
		$id = trim( $id );
		return preg_match( '/^[A-Za-z0-9._-]{1,' . self::MAX_ID_LENGTH . '}$/', $id ) ? $id : '';
	}

	/**
	 * Whether the current user may set other people's iMIS IDs.
	 *
	 * Members never set their own: a changed ID would show them someone else's iMIS data.
	 *
	 * @param int $user_id The profile being edited.
	 * @return bool
	 */
	public static function can_edit_id( int $user_id ): bool {
		return current_user_can( 'edit_users' ) && current_user_can( 'edit_user', $user_id );
	}

	/**
	 * The iMIS ID field on a user's profile (shown when the ID lives in user meta).
	 *
	 * @param WP_User $user The profile's user.
	 * @return void
	 */
	public static function profile_field( $user ): void {
		if ( 'meta' !== Wormiss_Settings::get( 'member_id_source' ) || ! ( $user instanceof WP_User ) ) {
			return;
		}
		$editable = self::can_edit_id( $user->ID );
		$value    = self::imis_id( $user->ID );
		if ( ! $editable && '' === $value ) {
			return;
		}
		echo '<h2>' . esc_html__( 'iMIS', 'wormiss' ) . '</h2><table class="form-table" role="presentation"><tr>';
		echo '<th><label for="wormiss_imis_id">' . esc_html__( 'iMIS ID', 'wormiss' ) . '</label></th><td>';
		if ( $editable ) {
			printf(
				'<input type="text" class="regular-text" id="wormiss_imis_id" name="wormiss_imis_id" value="%1$s" maxlength="%2$d" />',
				esc_attr( $value ),
				(int) self::MAX_ID_LENGTH
			);
			echo '<p class="description">'
				. esc_html__( 'The member\'s iMIS ID, e.g. 20022 (set by the member sync or single sign-on). It decides whose iMIS data this person sees.', 'wormiss' )
				. '</p>';
		} else {
			echo '<code>' . esc_html( $value ) . '</code>';
		}
		echo '</td></tr></table>';
	}

	/**
	 * An iMIS ID column on the Users screen.
	 *
	 * @param array $columns Columns.
	 * @return array
	 */
	public static function users_column( $columns ): array {
		$columns                    = (array) $columns;
		$columns['wormiss_imis_id'] = __( 'iMIS ID', 'wormiss' );
		return $columns;
	}

	/**
	 * A user's iMIS ID in the Users screen's column ("Free account" for free website accounts).
	 *
	 * @param string $output  Output so far.
	 * @param string $column  Column.
	 * @param int    $user_id User ID.
	 * @return string
	 */
	public static function users_column_value( $output, $column, $user_id ) {
		if ( 'wormiss_imis_id' !== $column ) {
			return $output;
		}
		if ( get_user_meta( (int) $user_id, Wormiss_Sign_In::META_FREE, true ) ) {
			return esc_html__( 'Free account', 'wormiss' );
		}
		return esc_html( self::imis_id( (int) $user_id ) );
	}

	/**
	 * Save the iMIS ID field (users who can manage other users only).
	 *
	 * @param int $user_id The profile's user ID.
	 * @return void
	 */
	public static function save_profile_field( $user_id ): void {
		$user_id = (int) $user_id;
		if ( 'meta' !== Wormiss_Settings::get( 'member_id_source' ) || ! self::can_edit_id( $user_id ) ) {
			return;
		}
		check_admin_referer( 'update-user_' . $user_id );
		if ( ! isset( $_POST['wormiss_imis_id'] ) ) {
			return;
		}
		$key = (string) Wormiss_Settings::get( 'member_id_meta' );
		$raw = trim( sanitize_text_field( wp_unslash( $_POST['wormiss_imis_id'] ) ) );
		$id  = self::clean_id( $raw );
		if ( '' === $raw ) {
			delete_user_meta( $user_id, $key );
		} elseif ( '' !== $id ) {
			update_user_meta( $user_id, $key, $id );
		}
		// Anything else is not an iMIS ID: the saved value is kept.
	}
}
