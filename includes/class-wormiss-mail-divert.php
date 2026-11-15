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
 * Email diversion for testing, like Moodle's "divert all emails": every email the site sends
 * goes to one address instead, with the subject naming who it was for, except to the addresses
 * and domains listed as exceptions.
 *
 * Set in Wormiss > Settings > Email testing, or in wp-config.php (which wins, so a test copy of
 * the site can never email real people):
 *
 *     define( 'WORMISS_DIVERT_EMAILS_TO', 'you@example.com' );
 *     define( 'WORMISS_DIVERT_EMAILS_EXCEPT', '@example.org, tester@example.com' );
 *
 * @package    wormiss
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined( 'ABSPATH' ) || exit;

/**
 * Diverts the site's emails while testing.
 */
class Wormiss_Mail_Divert {

	/** Constant: the address every email goes to instead. */
	const CONSTANT_TO = 'WORMISS_DIVERT_EMAILS_TO';

	/** Constant: addresses and domains that still get their emails. */
	const CONSTANT_EXCEPT = 'WORMISS_DIVERT_EMAILS_EXCEPT';

	/**
	 * Hook in.
	 *
	 * @return void
	 */
	public static function init(): void {
		// Last, so the email is diverted as it is finally sent.
		add_filter( 'wp_mail', [ __CLASS__, 'divert' ], PHP_INT_MAX );
		add_action( 'admin_notices', [ __CLASS__, 'notice' ] );
	}

	/**
	 * The address emails are diverted to ('' when diversion is off).
	 *
	 * @return string
	 */
	public static function to(): string {
		$to = defined( self::CONSTANT_TO ) ? (string) constant( self::CONSTANT_TO ) : (string) Wormiss_Settings::get( 'mail_divert_to' );
		return is_email( trim( $to ) ) ? trim( $to ) : '';
	}

	/**
	 * The addresses (you@example.com) and domains (@example.com or example.com) not diverted.
	 *
	 * @return string[] Lower case.
	 */
	public static function exceptions(): array {
		$raw = defined( self::CONSTANT_EXCEPT ) ? (string) constant( self::CONSTANT_EXCEPT ) : (string) Wormiss_Settings::get( 'mail_divert_except' );
		return array_values( array_filter( array_map( 'strtolower', array_map( 'trim', (array) preg_split( '/[\s,;]+/', $raw ) ) ) ) );
	}

	/**
	 * Whether an address still gets its emails.
	 *
	 * @param string $email Address.
	 * @return bool
	 */
	public static function excepted( string $email ): bool {
		$email  = strtolower( trim( $email ) );
		$domain = (string) substr( (string) strrchr( $email, '@' ), 1 );
		foreach ( self::exceptions() as $rule ) {
			if ( $rule === $email || ltrim( $rule, '@' ) === $domain ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * The email addresses in a To, Cc or Bcc value ("Name <a@b.c>, d@e.f" or a list).
	 *
	 * @param string|array $value Value.
	 * @return string[]
	 */
	public static function addresses( $value ): array {
		$found = [];
		foreach ( is_array( $value ) ? $value : explode( ',', (string) $value ) as $part ) {
			$part = trim( (string) $part );
			if ( preg_match( '/<([^>]+)>/', $part, $match ) ) {
				$part = $match[1];
			}
			if ( is_email( $part ) ) {
				$found[] = $part;
			}
		}
		return $found;
	}

	/**
	 * Divert an email: recipients not excepted (including Cc and Bcc) are replaced by the divert
	 * address, and the subject names them.
	 *
	 * @param array $args wp_mail() arguments (to, subject, message, headers, attachments).
	 * @return array
	 */
	public static function divert( $args ): array {
		$args = (array) $args;
		$to   = self::to();
		if ( '' === $to ) {
			return $args;
		}
		$recipients = self::addresses( $args['to'] ?? [] );
		$headers    = $args['headers'] ?? [];
		$lines      = is_array( $headers ) ? $headers : explode( "\n", str_replace( "\r\n", "\n", (string) $headers ) );
		$kept       = [];
		foreach ( $lines as $line ) {
			if ( preg_match( '/^\s*(cc|bcc)\s*:(.*)$/i', (string) $line, $match ) ) {
				// Copies are recipients too; they are dealt with with the others.
				$recipients = array_merge( $recipients, self::addresses( $match[2] ) );
				continue;
			}
			if ( '' !== trim( (string) $line ) ) {
				$kept[] = $line;
			}
		}
		$keep     = array_values( array_filter( $recipients, [ __CLASS__, 'excepted' ] ) );
		$diverted = array_values( array_diff( $recipients, $keep ) );
		if ( ! $diverted ) {
			return $args;
		}
		$args['to']      = array_values( array_unique( array_merge( $keep, [ $to ] ) ) );
		$args['headers'] = $kept;
		$args['subject'] = '[' . sprintf(
			/* translators: %s: the addresses the email was for. */
			__( 'Diverted from %s', 'wormiss' ),
			implode( ', ', array_unique( $diverted ) )
		) . '] ' . (string) ( $args['subject'] ?? '' );
		return $args;
	}

	/**
	 * A reminder across the admin screens while emails are diverted.
	 *
	 * @return void
	 */
	public static function notice(): void {
		$to = self::to();
		if ( '' === $to || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$except = self::exceptions();
		echo '<div class="notice notice-warning"><p><strong>' . esc_html__( 'Emails are being diverted for testing.', 'wormiss' ) . '</strong> '
			. esc_html(
				sprintf(
					/* translators: %s: email address. */
					__( 'Every email this site sends goes to %s instead', 'wormiss' ),
					$to
				)
			)
			. ( $except ? esc_html(
				sprintf(
					/* translators: %s: addresses and domains. */
					__( ', except to %s', 'wormiss' ),
					implode( ', ', $except )
				)
			) : '' )
			. '. ' . ( defined( self::CONSTANT_TO )
				? esc_html__( 'Set in wp-config.php.', 'wormiss' )
				: esc_html__( 'Turn it off in Wormiss > Settings > Email testing.', 'wormiss' ) )
			. '</p></div>';
	}
}
