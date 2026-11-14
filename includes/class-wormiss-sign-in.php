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
 * The sign-in page: members sign in with their iMIS email or iMIS ID and iMIS password, as on
 * the iMIS site; staff and non-member website accounts with their WordPress password. Beside
 * it, a "Become a member" panel (to the iMIS join page) and, when sign-ups are on, a small form
 * for a free website account.
 *
 * Everything happens on the sign-in page itself, so a host that takes over wp-login.php (such as
 * YunoHost's single sign-on) does not get in the way. With a page chosen in Settings, every
 * "Sign in" link and redirect (wp_login_url) goes there.
 *
 * @package    wormiss
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined( 'ABSPATH' ) || exit;

/**
 * The Wormiss sign-in page.
 */
class Wormiss_Sign_In {

	/** Failed sign-ins allowed from one address, or for one name, in LOCK_SECONDS. */
	const MAX_FAILS = 5;

	/** How long failed sign-ins are counted. */
	const LOCK_SECONDS = 15 * MINUTE_IN_SECONDS;

	/** Website accounts one address may make an hour. */
	const MAX_SIGN_UPS = 3;

	/** Shortest password for a website account. */
	const MIN_PASSWORD = 10;

	/**
	 * Messages for the form on this request: errors, and which form they belong to.
	 *
	 * @var array
	 */
	private static array $messages = [];

	/**
	 * Hook in.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_filter( 'login_url', [ __CLASS__, 'login_url' ], 20, 2 );
		add_action( 'template_redirect', [ __CLASS__, 'handle' ], 1 );
		add_action( 'admin_post_wormiss_create_sign_in_page', [ __CLASS__, 'create_page' ] );
	}

	/**
	 * The chosen sign-in page's ID, or 0 when none is chosen (or it is not published).
	 *
	 * @return int
	 */
	public static function page_id(): int {
		$id = (int) Wormiss_Settings::get( 'signin_page' );
		return $id && 'publish' === get_post_status( $id ) ? $id : 0;
	}

	/**
	 * Every "Sign in" link and redirect goes to the sign-in page, when one is chosen.
	 *
	 * @param string $url      WordPress's sign-in address.
	 * @param string $redirect Where to go after signing in.
	 * @return string
	 */
	public static function login_url( $url, $redirect = '' ) {
		$page = self::page_id();
		if ( ! $page ) {
			return $url;
		}
		$link = (string) get_permalink( $page );
		return '' !== (string) $redirect ? add_query_arg( 'redirect_to', rawurlencode( (string) $redirect ), $link ) : $link;
	}

	/**
	 * Where to go after signing in: the page asked for (on this site), else the home page.
	 *
	 * @return string
	 */
	public static function redirect_to(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.NonceVerification.Missing -- Only read, and checked as a safe address.
		$asked = isset( $_REQUEST['redirect_to'] ) ? esc_url_raw( wp_unslash( (string) $_REQUEST['redirect_to'] ) ) : '';
		$home  = home_url( '/' );
		$to    = wp_validate_redirect( $asked, $home );
		$page  = self::page_id();
		// Never back to the sign-in page itself.
		return $page && untrailingslashit( strtok( $to, '?' ) ) === untrailingslashit( (string) get_permalink( $page ) ) ? $home : $to;
	}

	/**
	 * Handle the sign-in page's forms (sign in, website account, sign out).
	 *
	 * @return void
	 */
	public static function handle(): void {
		$page = self::page_id();
		if ( ! $page || ! is_page( $page ) ) {
			return;
		}
		Wormiss_Members_Only::no_page_cache();
		// phpcs:disable WordPress.Security.NonceVerification -- Each action checks its own nonce below.
		if ( isset( $_GET['wormiss_sign_out'] ) && is_user_logged_in() ) {
			if ( wp_verify_nonce( sanitize_text_field( wp_unslash( (string) $_GET['wormiss_sign_out'] ) ), 'wormiss_sign_out' ) ) {
				wp_logout();
				wp_safe_redirect( home_url( '/' ) );
				exit;
			}
		}
		$action = isset( $_POST['wormiss_action'] ) ? sanitize_key( wp_unslash( (string) $_POST['wormiss_action'] ) ) : '';
		// phpcs:enable
		if ( 'sign_in' === $action ) {
			$result = self::sign_in_request();
		} elseif ( 'sign_up' === $action ) {
			$result = self::sign_up_request();
		} else {
			return;
		}
		if ( $result instanceof WP_User ) {
			wp_safe_redirect( self::redirect_to() );
			exit;
		}
	}

	/**
	 * The sign-in form was sent: check it and sign in.
	 *
	 * @return WP_User|null The person signed in, or null (with a message for the form).
	 */
	private static function sign_in_request(): ?WP_User {
		if ( ! check_ajax_referer( 'wormiss_sign_in', '_wormiss_nonce', false ) ) {
			self::$messages['sign_in'] = __( 'This form has expired. Please try again.', 'wormiss' );
			return null;
		}
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Checked above.
		$login    = isset( $_POST['log'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['log'] ) ) : '';
		$password = isset( $_POST['pwd'] ) ? (string) wp_unslash( $_POST['pwd'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- A password is used as typed.
		$remember = ! empty( $_POST['rememberme'] );
		// phpcs:enable
		self::$messages['login'] = $login;
		if ( self::locked( $login ) ) {
			self::$messages['sign_in'] = __( 'Too many tries. Please wait 15 minutes and try again.', 'wormiss' );
			return null;
		}
		$user = self::authenticate( $login, $password );
		if ( is_wp_error( $user ) ) {
			self::failed( $login );
			self::$messages['sign_in'] = $user->get_error_message();
			return null;
		}
		self::sign_in( $user, $remember );
		return $user;
	}

	/**
	 * Who an iMIS email or iMIS ID and password belong to. Members (website accounts linked to an
	 * iMIS ID) are checked with iMIS; staff and other website accounts with their WordPress
	 * password. Lapsed members stay out.
	 *
	 * @param string $login    iMIS ID, email, or website username.
	 * @param string $password Password.
	 * @return WP_User|WP_Error
	 */
	public static function authenticate( string $login, string $password ) {
		$wrong = new WP_Error( 'wormiss_sign_in', __( 'That iMIS ID or email and password do not match. Please try again.', 'wormiss' ) );
		$login = trim( $login );
		if ( '' === $login || '' === $password ) {
			return new WP_Error( 'wormiss_sign_in', __( 'Please enter your iMIS ID or email and your password.', 'wormiss' ) );
		}
		$imis_ok = false;
		try {
			( new Wormiss_Rest_Client( null, $login, $password ) )->get_token( true );
			$imis_ok = true;
		} catch ( Wormiss_Exception $e ) {
			unset( $e ); // Not an iMIS sign-in (or iMIS refused it): maybe a website account.
		}
		$user = self::find( $login );
		if ( $imis_ok ) {
			// Only an account linked to iMIS (and, for an ID, to that ID) is signed in by iMIS.
			$linked = $user ? Wormiss_Member::imis_id( $user->ID ) : '';
			if ( '' === $linked || ( ctype_digit( $login ) && $linked !== $login ) ) {
				$user = null;
			}
			if ( ! $user ) {
				return new WP_Error( 'wormiss_sign_in', __( 'Your iMIS sign-in worked, but your website account is not ready yet. It is made from iMIS each night; please try again tomorrow.', 'wormiss' ) );
			}
		} elseif ( ! $user || ( '' !== Wormiss_Member::imis_id( $user->ID ) && ! user_can( $user, 'edit_posts' ) )
			|| ! wp_check_password( $password, $user->user_pass, $user->ID ) ) {
			// Members always use their iMIS password; staff and other accounts their website one.
			return $wrong;
		}
		if ( Wormiss_User_Sync::is_blocked( $user->ID ) ) {
			return new WP_Error( 'wormiss_sign_in', __( 'Your membership is not current, so you cannot sign in. Please contact the College.', 'wormiss' ) );
		}
		return $user;
	}

	/**
	 * The website account for an iMIS ID (all digits), an email, or a username.
	 *
	 * @param string $login What was typed.
	 * @return WP_User|null
	 */
	public static function find( string $login ): ?WP_User {
		if ( is_email( $login ) ) {
			$user = get_user_by( 'email', $login );
			return $user instanceof WP_User ? $user : null;
		}
		if ( ctype_digit( $login ) && 'login' !== Wormiss_Settings::get( 'member_id_source' ) ) {
			// phpcs:disable WordPress.DB.SlowDBQuery -- One indexed meta lookup.
			$found = get_users(
				[
					'meta_key'   => (string) Wormiss_Settings::get( 'member_id_meta' ),
					'meta_value' => $login,
					'number'     => 1,
					'fields'     => 'ID',
				]
			);
			// phpcs:enable
			if ( $found ) {
				$user = get_userdata( (int) $found[0] );
				return $user instanceof WP_User ? $user : null;
			}
		}
		$user = get_user_by( 'login', $login );
		return $user instanceof WP_User ? $user : null;
	}

	/**
	 * Sign a person in.
	 *
	 * @param WP_User $user     Person.
	 * @param bool    $remember Keep them signed in for two weeks.
	 * @return void
	 */
	private static function sign_in( WP_User $user, bool $remember ): void {
		wp_set_current_user( $user->ID );
		wp_set_auth_cookie( $user->ID, $remember, is_ssl() );
		do_action( 'wp_login', $user->user_login, $user ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress's own hook.
	}

	/**
	 * The website-account form was sent: make the account and sign in.
	 *
	 * @return WP_User|null
	 */
	private static function sign_up_request(): ?WP_User {
		if ( ! get_option( 'users_can_register' ) ) {
			return null;
		}
		if ( ! check_ajax_referer( 'wormiss_sign_up', '_wormiss_nonce', false ) ) {
			self::$messages['sign_up'] = __( 'This form has expired. Please try again.', 'wormiss' );
			return null;
		}
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Checked above.
		$name     = isset( $_POST['wormiss_name'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['wormiss_name'] ) ) : '';
		$email    = isset( $_POST['wormiss_email'] ) ? sanitize_email( wp_unslash( (string) $_POST['wormiss_email'] ) ) : '';
		$password = isset( $_POST['wormiss_password'] ) ? (string) wp_unslash( $_POST['wormiss_password'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- A password is used as typed.
		$trap     = isset( $_POST['wormiss_website'] ) ? (string) wp_unslash( $_POST['wormiss_website'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Only checked for being empty.
		// phpcs:enable
		self::$messages['name']  = $name;
		self::$messages['email'] = $email;
		$result                  = self::create_account( $name, $email, $password, $trap );
		if ( is_wp_error( $result ) ) {
			self::$messages['sign_up'] = $result->get_error_message();
			return null;
		}
		self::sign_in( $result, false );
		return $result;
	}

	/**
	 * Make a free website account (not a membership).
	 *
	 * @param string $name     Full name.
	 * @param string $email    Email.
	 * @param string $password Password.
	 * @param string $trap     A field people cannot see; robots fill it in.
	 * @return WP_User|WP_Error
	 */
	public static function create_account( string $name, string $email, string $password, string $trap = '' ) {
		if ( '' !== $trap ) {
			return new WP_Error( 'wormiss_sign_up', __( 'Your account could not be made.', 'wormiss' ) );
		}
		$key   = 'wormiss_sign_ups_' . md5( self::ip() );
		$count = (int) get_transient( $key );
		if ( $count >= self::MAX_SIGN_UPS ) {
			return new WP_Error( 'wormiss_sign_up', __( 'Too many accounts made from here. Please try again later.', 'wormiss' ) );
		}
		if ( '' === $name || ! is_email( $email ) ) {
			return new WP_Error( 'wormiss_sign_up', __( 'Please enter your name and a valid email address.', 'wormiss' ) );
		}
		if ( strlen( $password ) < self::MIN_PASSWORD ) {
			/* translators: %d: number of characters. */
			return new WP_Error( 'wormiss_sign_up', sprintf( __( 'Please choose a password of at least %d characters.', 'wormiss' ), self::MIN_PASSWORD ) );
		}
		if ( email_exists( $email ) ) {
			return new WP_Error( 'wormiss_sign_up', __( 'There is already an account with that email. Please sign in instead.', 'wormiss' ) );
		}
		$base  = sanitize_user( strtolower( (string) strtok( $email, '@' ) ), true );
		$base  = '' !== $base && ! ctype_digit( $base ) ? $base : 'member';
		$login = $base;
		$i     = 2;
		while ( username_exists( $login ) ) {
			$login = $base . $i;
			++$i;
		}
		$parts   = explode( ' ', $name, 2 );
		$user_id = wp_insert_user(
			[
				'user_login'   => $login,
				'user_email'   => $email,
				'user_pass'    => $password,
				'first_name'   => $parts[0],
				'last_name'    => $parts[1] ?? '',
				'display_name' => $name,
				'role'         => (string) get_option( 'default_role', 'subscriber' ),
			]
		);
		if ( is_wp_error( $user_id ) ) {
			return new WP_Error( 'wormiss_sign_up', __( 'Your account could not be made. Please try again.', 'wormiss' ) );
		}
		set_transient( $key, $count + 1, HOUR_IN_SECONDS );
		$user = get_userdata( (int) $user_id );
		return $user instanceof WP_User ? $user : new WP_Error( 'wormiss_sign_up', __( 'Your account could not be made. Please try again.', 'wormiss' ) );
	}

	/**
	 * Whether sign-ins from this address, or for this name, are paused after too many failures.
	 *
	 * @param string $login What was typed.
	 * @return bool
	 */
	private static function locked( string $login ): bool {
		foreach ( self::fail_keys( $login ) as $key ) {
			if ( (int) get_transient( $key ) >= self::MAX_FAILS ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Count a failed sign-in.
	 *
	 * @param string $login What was typed.
	 * @return void
	 */
	private static function failed( string $login ): void {
		foreach ( self::fail_keys( $login ) as $key ) {
			set_transient( $key, (int) get_transient( $key ) + 1, self::LOCK_SECONDS );
		}
	}

	/**
	 * The failure counters for this address and this name.
	 *
	 * @param string $login What was typed.
	 * @return string[]
	 */
	private static function fail_keys( string $login ): array {
		$keys = [ 'wormiss_sign_in_ip_' . md5( self::ip() ) ];
		if ( '' !== trim( $login ) ) {
			$keys[] = 'wormiss_sign_in_name_' . md5( strtolower( trim( $login ) ) );
		}
		return $keys;
	}

	/**
	 * The visitor's address (as the web server saw it).
	 *
	 * @return string
	 */
	private static function ip(): string {
		return isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['REMOTE_ADDR'] ) ) : '';
	}

	/**
	 * Make a "Sign in" page with the Sign in block, and choose it in Settings.
	 *
	 * @return void
	 */
	public static function create_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You cannot change these settings.', 'wormiss' ), '', [ 'response' => 403 ] );
		}
		check_admin_referer( 'wormiss_create_sign_in_page' );
		$page = self::page_id();
		if ( ! $page ) {
			$page = (int) wp_insert_post(
				[
					'post_type'    => 'page',
					'post_status'  => 'publish',
					'post_title'   => __( 'Sign in', 'wormiss' ),
					'post_name'    => 'sign-in',
					'post_content' => '<!-- wp:wormiss/sign-in /-->',
				]
			);
			if ( $page ) {
				update_option( Wormiss_Settings::OPTION, array_merge( Wormiss_Settings::all(), [ 'signin_page' => $page ] ) );
			}
		}
		wp_safe_redirect( admin_url( 'admin.php?page=' . Wormiss_Admin::SETTINGS_PAGE . '#wormiss-sign-in' ) );
		exit;
	}

	/**
	 * The Sign in block: the sign-in form, and the Become a member and website-account panels.
	 *
	 * @return string
	 */
	public static function render(): string {
		Wormiss_Members_Only::no_page_cache();
		$redirect = self::redirect_to();
		if ( is_user_logged_in() ) {
			$user = wp_get_current_user();
			$out  = add_query_arg( 'wormiss_sign_out', wp_create_nonce( 'wormiss_sign_out' ), (string) get_permalink() );
			return '<div class="wormiss-sign-in wormiss-sign-in--done"><div class="wormiss-sign-in__card">'
				. '<h2 class="wormiss-sign-in__title">' . esc_html__( 'You are signed in', 'wormiss' ) . '</h2>'
				/* translators: %s: name. */
				. '<p>' . esc_html( sprintf( __( 'Signed in as %s.', 'wormiss' ), $user->display_name ) ) . '</p>'
				. '<p class="wormiss-sign-in__actions"><a class="wormiss-sign-in__button" href="' . esc_url( $redirect ) . '">' . esc_html__( 'Continue', 'wormiss' ) . '</a>'
				. '<a class="wormiss-sign-in__link" href="' . esc_url( $out ) . '">' . esc_html__( 'Sign out', 'wormiss' ) . '</a></p>'
				. '</div></div>';
		}
		$forgot = (string) Wormiss_Settings::get( 'signin_forgot_url' );
		$join   = (string) Wormiss_Settings::get( 'signin_join_url' );
		$error  = static fn( string $form ): string => isset( self::$messages[ $form ] )
			? '<p class="wormiss-sign-in__error" role="alert">' . esc_html( self::$messages[ $form ] ) . '</p>' : '';
		$hidden = '<input type="hidden" name="redirect_to" value="' . esc_attr( $redirect ) . '" />';

		$html  = '<div class="wormiss-sign-in"><div class="wormiss-sign-in__card wormiss-sign-in__card--main">';
		$html .= '<h2 class="wormiss-sign-in__title">' . esc_html__( 'Sign in', 'wormiss' ) . '</h2>';
		$html .= '<p class="wormiss-sign-in__lead">' . esc_html__( 'Use the same details as the College\'s member site.', 'wormiss' ) . '</p>';
		$html .= $error( 'sign_in' );
		$html .= '<form method="post" class="wormiss-sign-in__form">' . $hidden
			. '<input type="hidden" name="wormiss_action" value="sign_in" />' . wp_nonce_field( 'wormiss_sign_in', '_wormiss_nonce', false, false )
			. '<p><label for="wormiss-log">' . esc_html__( 'iMIS ID or email', 'wormiss' ) . '</label>'
			. '<input type="text" id="wormiss-log" name="log" autocomplete="username" required value="' . esc_attr( (string) ( self::$messages['login'] ?? '' ) ) . '" /></p>'
			. '<p><label for="wormiss-pwd">' . esc_html__( 'Password', 'wormiss' ) . '</label>'
			. '<input type="password" id="wormiss-pwd" name="pwd" autocomplete="current-password" required /></p>'
			. '<p class="wormiss-sign-in__row"><label class="wormiss-sign-in__check"><input type="checkbox" name="rememberme" value="1" /> ' . esc_html__( 'Keep me signed in', 'wormiss' ) . '</label>'
			. ( '' !== $forgot ? '<a class="wormiss-sign-in__link" href="' . esc_url( $forgot ) . '">' . esc_html__( 'Forgot your password?', 'wormiss' ) . '</a>' : '' ) . '</p>'
			. '<p><button type="submit" class="wormiss-sign-in__button">' . esc_html__( 'Sign in', 'wormiss' ) . '</button></p></form></div>';

		$html .= '<div class="wormiss-sign-in__card wormiss-sign-in__card--join">';
		$html .= '<h2 class="wormiss-sign-in__title">' . esc_html__( 'Not a member yet?', 'wormiss' ) . '</h2>';
		$html .= '<p>' . esc_html__( 'Join the College for the members\' blog of over 5,000 cases, CPD, events and more.', 'wormiss' ) . '</p>';
		if ( '' !== $join ) {
			$html .= '<p><a class="wormiss-sign-in__button wormiss-sign-in__button--join" href="' . esc_url( $join ) . '">' . esc_html__( 'Become a member', 'wormiss' ) . '</a></p>';
		}
		if ( get_option( 'users_can_register' ) ) {
			$html .= '<details class="wormiss-sign-in__free"' . ( isset( self::$messages['sign_up'] ) ? ' open' : '' ) . '><summary>' . esc_html__( 'Or create a free website account', 'wormiss' ) . '</summary>'
				. '<p class="wormiss-sign-in__note">' . esc_html__( 'For events and news. It does not include members\' content such as the blog.', 'wormiss' ) . '</p>'
				. $error( 'sign_up' )
				. '<form method="post" class="wormiss-sign-in__form">' . $hidden
				. '<input type="hidden" name="wormiss_action" value="sign_up" />' . wp_nonce_field( 'wormiss_sign_up', '_wormiss_nonce', false, false )
				. '<p><label for="wormiss-name">' . esc_html__( 'Full name', 'wormiss' ) . '</label>'
				. '<input type="text" id="wormiss-name" name="wormiss_name" autocomplete="name" required value="' . esc_attr( (string) ( self::$messages['name'] ?? '' ) ) . '" /></p>'
				. '<p><label for="wormiss-email">' . esc_html__( 'Email', 'wormiss' ) . '</label>'
				. '<input type="email" id="wormiss-email" name="wormiss_email" autocomplete="email" required value="' . esc_attr( (string) ( self::$messages['email'] ?? '' ) ) . '" /></p>'
				. '<p><label for="wormiss-password">' . esc_html__( 'Password', 'wormiss' ) . '</label>'
				. '<input type="password" id="wormiss-password" name="wormiss_password" autocomplete="new-password" minlength="' . esc_attr( (string) self::MIN_PASSWORD ) . '" required /></p>'
				. '<p class="wormiss-sign-in__trap" aria-hidden="true"><label>' . esc_html__( 'Leave this empty', 'wormiss' ) . '<input type="text" name="wormiss_website" tabindex="-1" autocomplete="off" /></label></p>'
				. '<p><button type="submit" class="wormiss-sign-in__button wormiss-sign-in__button--quiet">' . esc_html__( 'Create account', 'wormiss' ) . '</button></p></form></details>';
		}
		return $html . '</div></div>';
	}
}
