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
 * Check a sign-in: for an administrator, each step the sign-in page takes for a username or
 * email and password, with what it found, to see why a sign-in is refused.
 *
 * @package    wormiss
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined( 'ABSPATH' ) || exit;

/**
 * "Check a sign-in" on Wormiss > Settings. It signs nobody in, counts no failed tries, runs no
 * other sign-in plugins (so nothing such as WP Fail2Ban hears of a failure), and never keeps or
 * shows the password: only the steps' results are kept, for this administrator, for 10 minutes.
 */
class Wormiss_Sign_In_Check {

	/**
	 * The admin-post action.
	 */
	public const ACTION = 'wormiss_sign_in_check';

	/**
	 * Hook in.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'admin_post_' . self::ACTION, [ __CLASS__, 'handle' ] );
	}

	/**
	 * The steps for a username, email or iMIS ID and password.
	 *
	 * @param string $login    What was typed.
	 * @param string $password Password.
	 * @return array[] Each: label, ok (true, false or null for information), detail.
	 */
	public static function run( string $login, string $password ): array {
		$login   = trim( $login );
		$steps   = [];
		$kind    = is_email( $login ) ? __( 'an email address', 'wormiss' )
			: ( '' !== Wormiss_Member::clean_id( $login ) ? __( 'a username or iMIS ID', 'wormiss' ) : __( 'something that is not a username, email or iMIS ID', 'wormiss' ) );
		$steps[] = self::step( __( 'What was typed', 'wormiss' ), null, sprintf( '%1$s (%2$s)', $login, $kind ) );

		try {
			( new Wormiss_Rest_Client( null, $login, $password ) )->get_token( true );
			$steps[] = self::step( __( 'iMIS accepts these details', 'wormiss' ), true, __( 'Yes: iMIS signed them in.', 'wormiss' ) );
		} catch ( Wormiss_Exception $e ) {
			$steps[] = self::step( __( 'iMIS accepts these details', 'wormiss' ), false, $e->getMessage() );
		}

		$user = Wormiss_Sign_In::find( $login );
		if ( ! $user ) {
			$steps[] = self::step( __( 'Website account found', 'wormiss' ), false, __( 'No account has this email, iMIS ID or username.', 'wormiss' ) );
		} else {
			$how     = is_email( $login ) ? __( 'by email', 'wormiss' )
				: ( strtolower( $user->user_login ) === strtolower( $login ) ? __( 'by username', 'wormiss' ) : __( 'by iMIS ID', 'wormiss' ) );
			$steps[] = self::step(
				__( 'Website account found', 'wormiss' ),
				true,
				/* translators: 1: username, 2: account number, 3: roles, 4: how it was found. */
				sprintf( __( '%1$s (account %2$d; roles: %3$s), found %4$s.', 'wormiss' ), $user->user_login, $user->ID, implode( ', ', (array) $user->roles ), $how )
			);
			$imis    = Wormiss_Member::imis_id( $user->ID );
			$free    = (bool) get_user_meta( $user->ID, Wormiss_Sign_In::META_FREE, true );
			$steps[] = self::step(
				__( 'Linked to iMIS (a member\'s account)', 'wormiss' ),
				null,
				Wormiss_Sign_In::is_member_account( $user )
					/* translators: %s: iMIS ID. */
					? sprintf( __( 'Yes, iMIS ID %s: signs in with the iMIS password unless it is staff.', 'wormiss' ), $imis )
					: ( $free ? __( 'No: a free website account.', 'wormiss' ) : __( 'No: signs in with the website password.', 'wormiss' ) )
			);
			$staff   = user_can( $user, 'edit_posts' );
			$steps[] = self::step( __( 'Staff (can edit posts)', 'wormiss' ), null, $staff ? __( 'Yes.', 'wormiss' ) : __( 'No.', 'wormiss' ) );
			$hash    = (string) $user->user_pass;
			$steps[] = self::step(
				__( 'The website password matches', 'wormiss' ),
				Wormiss_Sign_In::password_matches( $user, $password ),
				/* translators: %s: how the password is stored, e.g. bcrypt. */
				sprintf( __( 'Stored as: %s.', 'wormiss' ), self::hash_kind( $hash ) )
			);
			// WordPress's own password functions can be replaced by a plugin: say where they come from,
			// and whether they agree with themselves on a made-up password.
			$probe   = wp_generate_password( 20, false );
			$steps[] = self::step(
				__( 'WordPress password checking works', 'wormiss' ),
				wp_check_password( $probe, wp_hash_password( $probe ) ),
				/* translators: %s: where wp_check_password() is defined. */
				sprintf( __( 'Password functions from: %s.', 'wormiss' ), self::defined_in( 'wp_check_password' ) )
			);
			$steps[] = self::step(
				__( 'Waiting for an email confirmation', 'wormiss' ),
				get_user_meta( $user->ID, Wormiss_Sign_In::META_CONFIRM, true ) ? false : true,
				get_user_meta( $user->ID, Wormiss_Sign_In::META_CONFIRM, true ) ? __( 'Yes: the account is not confirmed yet.', 'wormiss' ) : __( 'No.', 'wormiss' )
			);
			$steps[] = self::step(
				__( 'Blocked by the member sync (membership not current)', 'wormiss' ),
				Wormiss_User_Sync::is_blocked( $user->ID ) ? false : true,
				Wormiss_User_Sync::is_blocked( $user->ID ) ? __( 'Yes.', 'wormiss' ) : __( 'No.', 'wormiss' )
			);
		}
		// The sign-in page checks the pause first (for this name and this computer's address).
		$locked  = Wormiss_Sign_In::locked( $login );
		$steps[] = self::step(
			__( 'Paused after too many tries', 'wormiss' ),
			! $locked,
			$locked ? __( 'Yes: wait 15 minutes.', 'wormiss' ) : __( 'No.', 'wormiss' )
		);

		$result  = $locked ? null : Wormiss_Sign_In::authenticate( $login, $password );
		$steps[] = self::step(
			__( 'The sign-in page\'s answer', 'wormiss' ),
			$result instanceof WP_User,
			$locked ? __( 'Too many tries. Please wait 15 minutes and try again.', 'wormiss' )
				: ( $result instanceof WP_User
					/* translators: %s: username. */
					? sprintf( __( 'Signs in as %s.', 'wormiss' ), $result->user_login )
					: ( is_wp_error( $result ) ? $result->get_error_message() : __( 'Refused.', 'wormiss' ) ) )
		);
		return $steps;
	}

	/**
	 * One step.
	 *
	 * @param string    $label  What was checked.
	 * @param bool|null $ok     Passed, failed, or information only.
	 * @param string    $detail What was found.
	 * @return array
	 */
	private static function step( string $label, ?bool $ok, string $detail ): array {
		return [
			'label'  => $label,
			'ok'     => $ok,
			'detail' => $detail,
		];
	}

	/**
	 * Where a function is defined, relative to the WordPress folder (wp-includes/pluggable.php
	 * for WordPress's own).
	 *
	 * @param string $name Function name.
	 * @return string
	 */
	public static function defined_in( string $name ): string {
		if ( ! function_exists( $name ) ) {
			return __( 'not defined', 'wormiss' );
		}
		$file = (string) ( new ReflectionFunction( $name ) )->getFileName();
		$root = wp_normalize_path( untrailingslashit( ABSPATH ) );
		$file = wp_normalize_path( $file );
		return str_starts_with( $file, $root . '/' ) ? substr( $file, strlen( $root ) + 1 ) : $file;
	}

	/**
	 * How a password is stored, from the start of its hash only (never the hash itself).
	 *
	 * @param string $hash Stored hash.
	 * @return string
	 */
	public static function hash_kind( string $hash ): string {
		if ( '' === $hash ) {
			return __( 'no password', 'wormiss' );
		}
		if ( str_starts_with( $hash, '$wp$' ) ) {
			return __( 'WordPress bcrypt', 'wormiss' );
		}
		if ( str_starts_with( $hash, '$P$' ) || str_starts_with( $hash, '$H$' ) ) {
			return __( 'WordPress phpass (older)', 'wormiss' );
		}
		if ( str_starts_with( $hash, '$2y$' ) || str_starts_with( $hash, '$argon2' ) ) {
			return __( 'PHP password hash', 'wormiss' );
		}
		if ( 1 === preg_match( '/^[a-f0-9]{32}$/i', $hash ) ) {
			return __( 'MD5 (set directly in the database)', 'wormiss' );
		}
		return __( 'an unrecognised format', 'wormiss' );
	}

	/**
	 * Handle the button: administrators only; keep the steps (not the password) to show once.
	 *
	 * @return void
	 */
	public static function handle(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'wormiss' ), 403 );
		}
		check_admin_referer( self::ACTION );
		$login    = isset( $_POST['wormiss_check_log'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['wormiss_check_log'] ) ) : '';
		$password = isset( $_POST['wormiss_check_pwd'] ) ? (string) wp_unslash( $_POST['wormiss_check_pwd'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- A password is used as typed, and never kept.
		$steps    = '' === trim( $login ) || '' === $password
			? [ self::step( __( 'Details', 'wormiss' ), false, __( 'Enter a username, email or iMIS ID and a password.', 'wormiss' ) ) ]
			: self::run( $login, $password );
		unset( $password );
		set_transient( self::transient(), $steps, 10 * MINUTE_IN_SECONDS );
		wp_safe_redirect( Wormiss_Admin::settings_url() . '#wormiss-sign-in-check' );
		exit;
	}

	/**
	 * Where this administrator's last result is kept.
	 *
	 * @return string
	 */
	private static function transient(): string {
		return 'wormiss_sign_in_check_' . get_current_user_id();
	}

	/**
	 * The section on the settings page: the form, then the last result (shown once).
	 *
	 * @return void
	 */
	public static function render(): void {
		echo '<h2 id="wormiss-sign-in-check">' . esc_html__( 'Check a sign-in', 'wormiss' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'Shows each step the sign-in page takes for a username, email or iMIS ID and password,', 'wormiss' )
			. ' ' . esc_html__( 'to see why a sign-in is refused. Nobody is signed in, no failed try is counted, and the password', 'wormiss' )
			. ' ' . esc_html__( 'is never kept or shown.', 'wormiss' ) . '</p>';
		$steps = get_transient( self::transient() );
		if ( is_array( $steps ) ) {
			delete_transient( self::transient() );
			echo '<table class="widefat striped" style="max-width:60rem"><tbody>';
			foreach ( $steps as $step ) {
				$mark = null === $step['ok'] ? '&#8226;' : ( $step['ok'] ? '&#10004;' : '&#10008;' );
				echo '<tr><td style="width:2rem">' . $mark . '</td><th scope="row">' . esc_html( (string) $step['label'] ) . '</th><td>' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- A fixed entity.
					. esc_html( (string) $step['detail'] ) . '</td></tr>';
			}
			echo '</tbody></table>';
		}
		echo '<form action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" method="post" autocomplete="off">';
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '" />';
		wp_nonce_field( self::ACTION );
		echo '<p><label for="wormiss-check-log">' . esc_html__( 'Username, email or iMIS ID', 'wormiss' ) . '</label> ';
		echo '<input type="text" class="regular-text" id="wormiss-check-log" name="wormiss_check_log" autocomplete="off" /></p>';
		echo '<p><label for="wormiss-check-pwd">' . esc_html__( 'Password', 'wormiss' ) . '</label> ';
		echo '<input type="password" class="regular-text" id="wormiss-check-pwd" name="wormiss_check_pwd" autocomplete="new-password" /> ';
		submit_button( __( 'Check sign-in', 'wormiss' ), 'secondary', 'submit', false );
		echo '</p></form>';
	}
}
