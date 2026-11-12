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
 * Members register for (and cancel) free iMIS events from WordPress.
 *
 * @package    wormiss
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined( 'ABSPATH' ) || exit;

/**
 * Register and Cancel buttons for free events, and the iMIS calls behind them.
 *
 * Only events whose iMIS registration type is RegistrationRequired (registration with no fee)
 * get a button; paid events keep linking to iMIS. Only signed-in members with an iMIS ID can
 * register, always for themselves. Off, and a dry run, until an administrator turns it on.
 */
class Wormiss_Event_Registration {

	/** The iMIS registration type of a free event that needs registering. */
	const FREE_TYPE = 'RegistrationRequired';

	/** Seconds a result message waits to be shown after the redirect. */
	const NOTICE_SECONDS = 300;

	/**
	 * Hook in.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'admin_post_wormiss_register', [ __CLASS__, 'handle_register' ] );
		add_action( 'admin_post_wormiss_cancel_registration', [ __CLASS__, 'handle_cancel' ] );
		// Signed out: send them to sign in, then back.
		add_action( 'admin_post_nopriv_wormiss_register', [ __CLASS__, 'handle_signed_out' ] );
		add_action( 'admin_post_nopriv_wormiss_cancel_registration', [ __CLASS__, 'handle_signed_out' ] );
	}

	/**
	 * Whether registering from WordPress is switched on (and iMIS is set up).
	 *
	 * @return bool
	 */
	public static function is_on(): bool {
		return (bool) Wormiss_Settings::get( 'event_registration_enabled' ) && Wormiss_Settings::is_configured();
	}

	/**
	 * Whether an event can be registered for here: free, open, listed, not finished, and
	 * registration not closed.
	 *
	 * @param array $event Event from Wormiss_Events::normalise().
	 * @param int   $now   Current Unix time.
	 * @return bool
	 */
	public static function is_open( array $event, int $now ): bool {
		if ( self::FREE_TYPE !== ( $event['reg_type'] ?? '' ) ) {
			return false;
		}
		if ( ! Wormiss_Events::upcoming( [ $event ], '', $now ) ) {
			return false;
		}
		$closes = Wormiss_Events::timestamp( (string) ( $event['reg_end'] ?? '' ) );
		return null === $closes || $closes >= $now;
	}

	/**
	 * The Register (or "You're registered" and Cancel) part for one event, with any result
	 * message for it; '' when the event cannot be registered for here.
	 *
	 * @param array $event       Event from Wormiss_Events::normalise().
	 * @param bool  $show_status Look up whether the member is registered (one iMIS read, cached).
	 * @return string HTML.
	 */
	public static function button( array $event, bool $show_status = false ): string {
		$id = (string) $event['id'];
		// A waiting result message shows even when the button no longer does (e.g. it closed meanwhile).
		$notice = is_user_logged_in() ? self::take_notice( $id ) : '';
		if ( ! self::is_on() || ! self::is_open( $event, time() ) ) {
			return $notice;
		}
		if ( ! is_user_logged_in() ) {
			$login = wp_login_url( self::return_url() );
			return '<p class="wormiss-register"><a href="' . esc_url( $login ) . '">'
				. esc_html__( 'Sign in to register', 'wormiss' ) . '</a></p>';
		}
		$imis_id = Wormiss_Member::imis_id();
		if ( '' === $imis_id ) {
			return $notice;
		}
		// A member's view of their own registration must not be stored by page caches.
		Wormiss_Members_Only::no_page_cache();

		$html   = $notice;
		$status = $show_status ? self::cached_status( $id, $imis_id ) : null;
		if ( null !== $status && self::is_registered( $status ) ) {
			return $html . '<div class="wormiss-register wormiss-register--done"><p>'
				. esc_html__( "You're registered.", 'wormiss' ) . '</p>'
				. self::form( 'wormiss_cancel_registration', $id, __( 'Cancel my registration', 'wormiss' ), 'is-style-outline' )
				. '</div>';
		}
		return $html . self::form( 'wormiss_register', $id, __( 'Register', 'wormiss' ), '' );
	}

	/**
	 * A one-button form posting to admin-post.php.
	 *
	 * @param string $action   admin-post action.
	 * @param string $event_id iMIS event ID.
	 * @param string $label    Button text.
	 * @param string $extra    Extra class for the button wrapper.
	 * @return string
	 */
	private static function form( string $action, string $event_id, string $label, string $extra ): string {
		return '<form class="wormiss-register ' . esc_attr( $extra ) . '" method="post" action="'
			. esc_url( admin_url( 'admin-post.php' ) ) . '">'
			. '<input type="hidden" name="action" value="' . esc_attr( $action ) . '" />'
			. '<input type="hidden" name="event_id" value="' . esc_attr( $event_id ) . '" />'
			. wp_nonce_field( $action . '_' . $event_id, '_wpnonce', true, false )
			. '<button type="submit" class="wp-element-button">' . esc_html( $label ) . '</button></form>';
	}

	/**
	 * Register the signed-in member (admin-post), then go back to the page they came from.
	 *
	 * @return void
	 */
	public static function handle_register(): void {
		$event_id = self::posted_event( 'wormiss_register' );
		self::set_notice( $event_id, self::register( $event_id ) );
		self::go_back();
	}

	/**
	 * Cancel the signed-in member's registration (admin-post), then go back.
	 *
	 * @return void
	 */
	public static function handle_cancel(): void {
		$event_id = self::posted_event( 'wormiss_cancel_registration' );
		self::set_notice( $event_id, self::cancel( $event_id ) );
		self::go_back();
	}

	/**
	 * Someone signed out pressed a button: sign in, then return to the page.
	 *
	 * @return void
	 */
	public static function handle_signed_out(): void {
		wp_safe_redirect( wp_login_url( (string) wp_get_referer() ) );
		exit;
	}

	/**
	 * Register the current member for a free event.
	 *
	 * @param string $event_id iMIS event ID.
	 * @return string Result: registered, already, dry_run, closed, not_member, off or error.
	 */
	public static function register( string $event_id ): string {
		$imis_id = Wormiss_Member::imis_id();
		$check   = self::check( $event_id, $imis_id );
		if ( '' !== $check ) {
			return $check;
		}
		$client = new Wormiss_Rest_Client();
		try {
			$event = Wormiss_Events::normalise( $client->get_event( $event_id ) );
			if ( null === $event || ! self::is_open( $event, time() ) ) {
				return 'closed';
			}
			$status = $client->get_registration( $event_id, $imis_id );
			if ( null !== $status && self::is_registered( $status ) ) {
				self::forget_status( $event_id, $imis_id );
				return 'already';
			}
			if ( Wormiss_Settings::get( 'event_registration_dry_run' ) ) {
				Wormiss_Logger::record_call(
					'DRY RUN POST /api/EventRegistration/_execute (RegisterEvent ' . $event_id . ')',
					Wormiss_Logger::STATUS_SUCCESS,
					null,
					(string) wp_json_encode( Wormiss_Rest_Client::register_body( $event_id, $imis_id, (string) $event['reg_option'] ) ),
					null,
					$imis_id
				);
				return 'dry_run';
			}
			$client->register_event( $event_id, $imis_id, (string) $event['reg_option'] );
		} catch ( Wormiss_Exception $e ) {
			// The reason is in the audit log; members see a plain message.
			return 'error';
		}
		self::forget_status( $event_id, $imis_id );
		return 'registered';
	}

	/**
	 * Cancel the current member's registration for an event.
	 *
	 * @param string $event_id iMIS event ID.
	 * @return string Result: cancelled, not_registered, cannot_cancel, dry_run, not_member, off or error.
	 */
	public static function cancel( string $event_id ): string {
		$imis_id = Wormiss_Member::imis_id();
		$check   = self::check( $event_id, $imis_id );
		if ( '' !== $check ) {
			return $check;
		}
		$client = new Wormiss_Rest_Client();
		try {
			// Only free events are cancelled here (with no fee): re-read it, as a page may be out of date.
			$event = Wormiss_Events::normalise( $client->get_event( $event_id ) );
			if ( null === $event || self::FREE_TYPE !== ( $event['reg_type'] ?? '' ) ) {
				return 'cannot_cancel';
			}
			$status = $client->get_registration( $event_id, $imis_id );
			if ( null === $status || ! self::is_registered( $status ) || '' === $status['id'] ) {
				self::forget_status( $event_id, $imis_id );
				return 'not_registered';
			}
			if ( Wormiss_Settings::get( 'event_registration_dry_run' ) ) {
				Wormiss_Logger::record_call(
					'DRY RUN POST /api/EventRegistration/_execute (CancelEventRegistration ' . $status['id'] . ')',
					Wormiss_Logger::STATUS_SUCCESS,
					null,
					null,
					null,
					$imis_id
				);
				return 'dry_run';
			}
			$client->cancel_registration( $status['id'], $imis_id );
		} catch ( Wormiss_Exception $e ) {
			return 'error';
		}
		self::forget_status( $event_id, $imis_id );
		return 'cancelled';
	}

	/**
	 * What stops a member registering or cancelling before iMIS is contacted, or ''.
	 *
	 * @param string $event_id iMIS event ID.
	 * @param string $imis_id  The member's iMIS ID.
	 * @return string
	 */
	private static function check( string $event_id, string $imis_id ): string {
		if ( ! self::is_on() || ! Wormiss_Rest_Client::is_event_id( $event_id ) ) {
			return 'off';
		}
		return ( is_user_logged_in() && '' !== $imis_id ) ? '' : 'not_member';
	}

	/**
	 * Whether a registration status means registered (including pending checkout).
	 *
	 * @param array $status From Wormiss_Rest_Client::get_registration().
	 * @return bool
	 */
	public static function is_registered( array $status ): bool {
		return 0 === strpos( (string) $status['status'], 'Registered' );
	}

	/**
	 * The member's registration for an event, cached for the member cache time; null if none
	 * or if iMIS cannot be read (then the Register button shows, and registering checks again).
	 *
	 * @param string $event_id iMIS event ID.
	 * @param string $imis_id  The member's iMIS ID.
	 * @return array|null
	 */
	private static function cached_status( string $event_id, string $imis_id ): ?array {
		$key    = self::status_key( $event_id, $imis_id );
		$cached = get_transient( $key );
		if ( is_array( $cached ) ) {
			return $cached['status'] ?? null;
		}
		try {
			$status = ( new Wormiss_Rest_Client() )->get_registration( $event_id, $imis_id );
		} catch ( Wormiss_Exception $e ) {
			return null;
		}
		$minutes = max( 1, (int) Wormiss_Settings::get( 'member_cache_minutes' ) );
		set_transient( $key, [ 'status' => $status ], $minutes * MINUTE_IN_SECONDS );
		return $status;
	}

	/**
	 * Forget a cached registration status (after registering or cancelling).
	 *
	 * @param string $event_id iMIS event ID.
	 * @param string $imis_id  The member's iMIS ID.
	 * @return void
	 */
	private static function forget_status( string $event_id, string $imis_id ): void {
		delete_transient( self::status_key( $event_id, $imis_id ) );
	}

	/**
	 * Cache key for a member's registration on the current iMIS connection.
	 *
	 * @param string $event_id iMIS event ID.
	 * @param string $imis_id  The member's iMIS ID.
	 * @return string
	 */
	private static function status_key( string $event_id, string $imis_id ): string {
		return 'wormiss_reg_' . md5( Wormiss_Settings::base_url() . "\0" . $event_id . "\0" . $imis_id );
	}

	/**
	 * The event ID from a posted form, after checking its nonce.
	 *
	 * @param string $action admin-post action.
	 * @return string
	 */
	private static function posted_event( string $action ): string {
		$event_id = isset( $_POST['event_id'] ) ? sanitize_text_field( wp_unslash( $_POST['event_id'] ) ) : '';
		check_admin_referer( $action . '_' . $event_id );
		return $event_id;
	}

	/**
	 * Keep a result message for the member, shown next to the event's button after the redirect.
	 *
	 * @param string $event_id iMIS event ID.
	 * @param string $result   Result code.
	 * @return void
	 */
	public static function set_notice( string $event_id, string $result ): void {
		set_transient(
			'wormiss_reg_notice_' . get_current_user_id(),
			[
				'event'  => $event_id,
				'result' => $result,
			],
			self::NOTICE_SECONDS
		);
	}

	/**
	 * The member's waiting result message for an event, once.
	 *
	 * @param string $event_id iMIS event ID.
	 * @return string HTML.
	 */
	private static function take_notice( string $event_id ): string {
		$key    = 'wormiss_reg_notice_' . get_current_user_id();
		$notice = get_transient( $key );
		if ( ! is_array( $notice ) || ( $notice['event'] ?? '' ) !== $event_id ) {
			return '';
		}
		delete_transient( $key );
		$messages = self::messages();
		$text     = $messages[ $notice['result'] ] ?? $messages['error'];
		$type     = in_array( $notice['result'], [ 'registered', 'cancelled', 'dry_run' ], true ) ? 'success' : 'info';
		return '<p class="wormiss-register-notice wormiss-register-notice--' . esc_attr( $type ) . '" role="status">'
			. esc_html( $text ) . '</p>';
	}

	/**
	 * Result code => message for the member.
	 *
	 * @return string[]
	 */
	public static function messages(): array {
		return [
			'registered'     => __( "You're registered. A confirmation will come from us by email.", 'wormiss' ),
			'already'        => __( "You're already registered for this event.", 'wormiss' ),
			'cancelled'      => __( 'Your registration has been cancelled.', 'wormiss' ),
			'not_registered' => __( "You're not registered for this event.", 'wormiss' ),
			'dry_run'        => __( 'Test mode: nothing was sent to iMIS. The request is in the iMIS Bridge log.', 'wormiss' ),
			'closed'         => __( 'Registration for this event is closed.', 'wormiss' ),
			'cannot_cancel'  => __( "This registration can't be cancelled here. Please contact us.", 'wormiss' ),
			'not_member'     => __( 'Only signed-in members can register here.', 'wormiss' ),
			'off'            => __( 'Registering here is not available at the moment.', 'wormiss' ),
			'error'          => __( "Sorry, that didn't work. Please try again later, or contact us.", 'wormiss' ),
		];
	}

	/**
	 * Back to the page the form was on.
	 *
	 * @return void
	 */
	private static function go_back(): void {
		$back = wp_get_referer();
		wp_safe_redirect( $back ? $back : home_url( '/' ) );
		exit;
	}

	/**
	 * The current page's address, to come back to after signing in. REQUEST_URI already holds
	 * the site's own folder (e.g. /community/…), so that is taken off before home_url() adds it.
	 *
	 * @return string
	 */
	public static function return_url(): string {
		$uri  = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '/';
		$base = rtrim( (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH ), '/' );
		if ( '' !== $base && ( $uri === $base || 0 === strpos( $uri, $base . '/' ) || 0 === strpos( $uri, $base . '?' ) ) ) {
			$uri = substr( $uri, strlen( $base ) );
		}
		return home_url( '' !== $uri ? $uri : '/' );
	}
}
