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
 * Plugin settings: the iMIS connection, approved queries, caching and the audit log.
 *
 * The connection can also be set in wp-config.php with the WORMISS_IMIS_URL,
 * WORMISS_IMIS_USERNAME and WORMISS_IMIS_PASSWORD constants, which take precedence
 * over the settings page and keep the password out of the database.
 *
 * @package    wormiss
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined( 'ABSPATH' ) || exit;

/**
 * Reads, validates and stores the plugin's options.
 */
class Wormiss_Settings {

	/** Option holding every setting except the password. */
	const OPTION = 'wormiss_settings';

	/** Option holding the service-account password (not autoloaded). */
	const PASSWORD_OPTION = 'wormiss_password';

	/** Longest IQA path accepted. */
	const MAX_QUERY_LENGTH = 255;

	/**
	 * Default values for every setting.
	 *
	 * @return array
	 */
	public static function defaults(): array {
		return [
			'base_url'           => '',
			'username'           => '',
			'timeout'            => 15,
			'cache_minutes'      => 15,
			'allowed_queries'    => [],
			'log_enabled'        => true,
			'log_capture'        => false,
			'log_retention_days' => 30,
		];
	}

	/**
	 * All settings, with defaults filled in.
	 *
	 * @return array
	 */
	public static function all(): array {
		$stored = get_option( self::OPTION, [] );
		return array_merge( self::defaults(), is_array( $stored ) ? $stored : [] );
	}

	/**
	 * One setting.
	 *
	 * @param string $key Setting name.
	 * @return mixed
	 */
	public static function get( string $key ) {
		$all = self::all();
		return $all[ $key ] ?? null;
	}

	/**
	 * The iMIS site root (no trailing slash, no /api), from wp-config.php or the settings.
	 *
	 * @return string
	 */
	public static function base_url(): string {
		$url = defined( 'WORMISS_IMIS_URL' ) ? (string) WORMISS_IMIS_URL : (string) self::get( 'base_url' );
		return self::normalise_base_url( $url );
	}

	/**
	 * The iMIS service-account username.
	 *
	 * @return string
	 */
	public static function username(): string {
		return defined( 'WORMISS_IMIS_USERNAME' ) ? (string) WORMISS_IMIS_USERNAME : (string) self::get( 'username' );
	}

	/**
	 * The iMIS service-account password.
	 *
	 * @return string
	 */
	public static function password(): string {
		return defined( 'WORMISS_IMIS_PASSWORD' ) ? (string) WORMISS_IMIS_PASSWORD : (string) get_option( self::PASSWORD_OPTION, '' );
	}

	/**
	 * Whether a setting is fixed in wp-config.php (and so not editable on the settings page).
	 *
	 * @param string $key One of base_url, username, password.
	 * @return bool
	 */
	public static function is_constant( string $key ): bool {
		$map = [
			'base_url' => 'WORMISS_IMIS_URL',
			'username' => 'WORMISS_IMIS_USERNAME',
			'password' => 'WORMISS_IMIS_PASSWORD',
		];
		return isset( $map[ $key ] ) && defined( $map[ $key ] );
	}

	/**
	 * Whether enough is set to contact iMIS.
	 *
	 * @return bool
	 */
	public static function is_configured(): bool {
		return '' !== self::base_url() && '' !== self::username() && '' !== self::password();
	}

	/**
	 * The IQA queries an administrator has approved for display on pages.
	 *
	 * @return string[]
	 */
	public static function allowed_queries(): array {
		$queries = self::get( 'allowed_queries' );
		return is_array( $queries ) ? array_values( $queries ) : [];
	}

	/**
	 * Whether a query is on the approved list (exact match).
	 *
	 * @param string $query IQA path.
	 * @return bool
	 */
	public static function is_allowed_query( string $query ): bool {
		return '' !== $query && in_array( $query, self::allowed_queries(), true );
	}

	/**
	 * Clean an iMIS base URL: https only, no trailing slash, no /api suffix.
	 *
	 * @param string $url Raw URL.
	 * @return string The cleaned URL, or '' if it is not a usable https URL.
	 */
	public static function normalise_base_url( string $url ): string {
		$url = trim( $url );
		if ( '' === $url ) {
			return '';
		}
		$url   = (string) preg_replace( '#/+$#', '', $url );
		$url   = (string) preg_replace( '#/api$#i', '', $url );
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || 'https' !== strtolower( $parts['scheme'] ?? '' ) || empty( $parts['host'] ) ) {
			return '';
		}
		return esc_url_raw( $url, [ 'https' ] );
	}

	/**
	 * Turn the approved-queries text box (one IQA path per line) into a clean list.
	 *
	 * Each line must be an IQA document path starting with "$/". Blank lines,
	 * duplicates and anything else are dropped.
	 *
	 * @param string $text One path per line.
	 * @return string[]
	 */
	public static function parse_queries( string $text ): array {
		$out = [];
		foreach ( preg_split( '/\R/', $text ) as $line ) {
			$line = trim( wp_strip_all_tags( $line ) );
			if ( '' === $line || 0 !== strpos( $line, '$/' ) || strlen( $line ) > self::MAX_QUERY_LENGTH ) {
				continue;
			}
			$out[ $line ] = $line;
		}
		return array_values( $out );
	}

	/**
	 * Sanitise the settings form (register_setting callback).
	 *
	 * @param mixed $input Submitted values.
	 * @return array
	 */
	public static function sanitize( $input ): array {
		$input = is_array( $input ) ? $input : [];
		$clean = self::all();

		if ( isset( $input['base_url'] ) ) {
			$url = self::normalise_base_url( (string) $input['base_url'] );
			if ( '' === $url && '' !== trim( (string) $input['base_url'] ) ) {
				add_settings_error( self::OPTION, 'base_url', __( 'The iMIS address must be an https:// web address.', 'wormiss' ) );
			} else {
				$clean['base_url'] = $url;
			}
		}
		if ( isset( $input['username'] ) ) {
			$clean['username'] = sanitize_text_field( (string) $input['username'] );
		}
		if ( isset( $input['timeout'] ) ) {
			$clean['timeout'] = min( 60, max( 5, absint( $input['timeout'] ) ) );
		}
		if ( isset( $input['cache_minutes'] ) ) {
			$clean['cache_minutes'] = min( 1440, max( 1, absint( $input['cache_minutes'] ) ) );
		}
		if ( isset( $input['allowed_queries'] ) ) {
			// WordPress runs this callback twice on a first save (update_option, then add_option),
			// so accept the already-cleaned list as well as the text box.
			$queries                  = $input['allowed_queries'];
			$clean['allowed_queries'] = self::parse_queries( is_array( $queries ) ? implode( "\n", $queries ) : (string) $queries );
		}
		if ( isset( $input['log_retention_days'] ) ) {
			$clean['log_retention_days'] = min( 365, max( 1, absint( $input['log_retention_days'] ) ) );
		}
		$clean['log_enabled'] = ! empty( $input['log_enabled'] );
		$clean['log_capture'] = ! empty( $input['log_capture'] );

		// The password lives in its own option; a blank field keeps the current one.
		if ( isset( $input['password'] ) && '' !== (string) $input['password'] && ! self::is_constant( 'password' ) ) {
			// options.php has already unslashed the submitted values.
			update_option( self::PASSWORD_OPTION, (string) $input['password'], false );
		}
		unset( $clean['password'] );

		return $clean;
	}
}
