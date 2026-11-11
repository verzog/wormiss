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
			'base_url'             => '',
			'username'             => '',
			'timeout'              => 15,
			'cache_minutes'        => 15,
			'allowed_queries'      => [],
			'log_enabled'          => true,
			'log_capture'          => false,
			'log_retention_days'   => 30,
			// Members' own data: where each WordPress user's iMIS ID is kept, and the queries
			// that may be run for the signed-in member.
			'member_id_source'     => 'meta',
			'member_id_meta'       => 'imis_id',
			'member_queries'       => [],
			'member_cache_minutes' => 5,
			// Members-only content: the query that reads a member's own membership record
			// (run with their iMIS ID as the first prompt), its type and status columns, and
			// the statuses that count as current when a post or block names none.
			'access_query'         => '',
			'access_type_column'   => 'MemberType',
			'access_status_column' => 'Status',
			'access_statuses'      => [],
			// Recording activity in iMIS: off, and a dry run, until an administrator turns it on.
			'activity_enabled'     => false,
			'activity_dry_run'     => true,
			'activity_type'        => '',
			'record_reads'         => true,
			'record_downloads'     => true,
			'record_forms'         => false,
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
	 * The IQA queries approved for showing a signed-in member their own data.
	 *
	 * @return string[]
	 */
	public static function member_queries(): array {
		$queries = self::get( 'member_queries' );
		return is_array( $queries ) ? array_values( $queries ) : [];
	}

	/**
	 * Whether a query is on the approved member-query list (exact match).
	 *
	 * @param string $query IQA path.
	 * @return bool
	 */
	public static function is_member_query( string $query ): bool {
		return '' !== $query && in_array( $query, self::member_queries(), true );
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
	 * A comma-separated list as clean, unique, non-empty items (e.g. membership types).
	 *
	 * @param string|array $value Comma-separated text, or an already-cleaned list.
	 * @return string[]
	 */
	public static function parse_list( $value ): array {
		$items = is_array( $value ) ? $value : explode( ',', (string) $value );
		$out   = [];
		foreach ( $items as $item ) {
			$item = trim( sanitize_text_field( (string) $item ) );
			if ( '' !== $item ) {
				$out[ strtolower( $item ) ] = $item;
			}
		}
		return array_values( $out );
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
		if ( isset( $input['member_queries'] ) ) {
			$queries                 = $input['member_queries'];
			$clean['member_queries'] = self::parse_queries( is_array( $queries ) ? implode( "\n", $queries ) : (string) $queries );
		}
		if ( isset( $input['member_id_source'] ) ) {
			$clean['member_id_source'] = 'login' === $input['member_id_source'] ? 'login' : 'meta';
		}
		if ( isset( $input['member_id_meta'] ) ) {
			// Kept exactly as typed (case and punctuation matter: it must match the key the
			// single sign-on plugin writes); anything that is not a plausible meta key is refused.
			$key = trim( (string) $input['member_id_meta'] );
			if ( preg_match( '/^[A-Za-z0-9_.:\-]{1,255}$/', $key ) ) {
				$clean['member_id_meta'] = $key;
			} elseif ( '' !== $key ) {
				$message = __( 'The user field name can only use letters, digits and _ . : -', 'wormiss' );
				add_settings_error( self::OPTION, 'member_id_meta', $message );
			}
		}
		if ( isset( $input['access_query'] ) ) {
			$query                 = self::parse_queries( (string) $input['access_query'] );
			$clean['access_query'] = $query ? $query[0] : '';
		}
		foreach ( [ 'access_type_column', 'access_status_column' ] as $column ) {
			if ( isset( $input[ $column ] ) ) {
				$name = trim( (string) $input[ $column ] );
				if ( preg_match( '/^[A-Za-z0-9_]{1,128}$/', $name ) ) {
					$clean[ $column ] = $name;
				}
			}
		}
		if ( isset( $input['access_statuses'] ) ) {
			$clean['access_statuses'] = self::parse_list( $input['access_statuses'] );
		}
		if ( isset( $input['member_cache_minutes'] ) ) {
			$clean['member_cache_minutes'] = min( 60, max( 1, absint( $input['member_cache_minutes'] ) ) );
		}
		if ( isset( $input['log_retention_days'] ) ) {
			$clean['log_retention_days'] = min( 365, max( 1, absint( $input['log_retention_days'] ) ) );
		}
		if ( isset( $input['activity_type'] ) ) {
			$type = trim( (string) $input['activity_type'] );
			if ( '' === $type || preg_match( '/^[A-Za-z0-9_]{1,32}$/', $type ) ) {
				$clean['activity_type'] = $type;
			} else {
				$message = __( 'The iMIS activity type can only use letters, digits and _.', 'wormiss' );
				add_settings_error( self::OPTION, 'activity_type', $message );
			}
		}
		// Checkboxes: from the settings form (which marks its activity section, since an unticked
		// box is not submitted), or from an already-cleaned array (WordPress sanitises a first
		// save twice); otherwise they keep their saved values.
		foreach ( [ 'activity_enabled', 'activity_dry_run', 'record_reads', 'record_downloads', 'record_forms' ] as $box ) {
			if ( isset( $input['activity_section'] ) ) {
				$clean[ $box ] = ! empty( $input[ $box ] );
			} elseif ( array_key_exists( $box, $input ) ) {
				$clean[ $box ] = (bool) $input[ $box ];
			}
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
