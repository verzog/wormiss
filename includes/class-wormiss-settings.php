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

	/** The usual public page for an event in iMIS. */
	const DEFAULT_EVENT_URL = '/iCore/Events/Event_display.aspx?EventKey={EventId}';

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
			'base_url'                   => '',
			'username'                   => '',
			'timeout'                    => 15,
			'cache_minutes'              => 15,
			'allowed_queries'            => [],
			// For each approved query: who may see it, which of its columns may show, and whether
			// it lists people (then it is never shown on the website). See query_rule().
			'query_rules'                => [],
			'log_enabled'                => true,
			'log_capture'                => false,
			'log_retention_days'         => 30,
			// Members' own data: where each WordPress user's iMIS ID is kept, and the queries
			// that may be run for the signed-in member.
			'member_id_source'           => 'meta',
			'member_id_meta'             => 'imis_id',
			'member_queries'             => [],
			'member_cache_minutes'       => 5,
			// Members-only content: the query that reads a member's own membership record
			// (run with their iMIS ID as the first prompt), its type and status columns, and
			// the statuses that count as current when a post or block names none.
			'access_query'               => '',
			'access_type_column'         => 'MemberType',
			'access_status_column'       => 'Status',
			'access_statuses'            => [],
			// Recording activity in iMIS: off, and a dry run, until an administrator turns it on.
			'activity_enabled'           => false,
			'activity_dry_run'           => true,
			'activity_type'              => '',
			// The iMIS CEU type given to activities that carry CPD points ('' to leave it empty).
			'cpd_ceu_type'               => '',
			'record_reads'               => true,
			'record_downloads'           => true,
			'record_forms'               => false,
			'record_purchases'           => false,
			// Members synced into WordPress users from an IQA: off, and a dry run, until turned on.
			'user_sync_enabled'          => false,
			'user_sync_dry_run'          => true,
			'user_sync_query'            => '',
			'user_sync_role'             => 'subscriber',
			// The members query's columns copied onto each account ('' to not copy that one).
			'member_type_column'         => 'MemberType',
			'member_join_column'         => 'JoinDate',
			'member_expiry_column'       => 'PaidThrough',
			// Members' WordPress name and email changes sent to their iMIS record: off, and a dry run.
			'member_push_enabled'        => false,
			'member_push_dry_run'        => true,
			// iMIS events on pages: the statuses that count as open, and each event's public page
			// in iMIS ({EventId} or {EventCode}; a path starting with / is on the iMIS site).
			'event_statuses'             => [ 'A' ],
			'event_url'                  => self::DEFAULT_EVENT_URL,
			// The IQA that counts an event's places taken ('' shows no places), and iMIS publish dates.
			'event_places_query'         => '',
			'event_publish_dates'        => false,
			// Show each event's CPD hours from its iMIS CPD Hours panel (EventCPD): off until turned on.
			'event_cpd'                  => false,
			// Members registering for free events here: off, and a dry run, until turned on.
			'event_registration_enabled' => false,
			'event_registration_dry_run' => true,
			// Sending WordPress events to iMIS: off, and a dry run, until an administrator turns it on.
			'event_sync_enabled'         => false,
			'event_sync_dry_run'         => true,
			'event_template_id'          => '',
			'event_cancel_on_trash'      => false,
			// Importing iMIS events as draft Event pages: off until an administrator turns it on.
			'event_import_enabled'       => false,
			// Publish imported pages (else they are drafts for an editor to publish).
			'event_import_publish'       => false,
			// Also import finished events that started on or after this date (YYYY-MM-DD; '' for none).
			'event_import_since'         => '',
			// Importing iMIS news articles as posts: off, and a dry run, until turned on.
			'news_import_enabled'        => false,
			'news_import_dry_run'        => true,
			// Publish imported posts (else drafts for an editor to publish).
			'news_import_publish'        => false,
			// The IQA that lists the articles (one row per article and tag).
			'news_import_query'          => '',
			// The category imported posts go in (made if missing).
			'news_import_category'       => 'News',
			// Who imported posts are by (user ID; 0 for the first administrator).
			'news_import_author'         => 0,
			// Media categories: the roles that count as staff, whose uploads are not auto-filed into
			// the reserved "End-user images" category (empty: administrators and the Wormiss Staff and
			// Manager roles). Administrators always count as staff. The role x category matrix and its
			// settings UI come in a later phase.
			'media_staff_roles'          => [],
			// The members' blog: roles that may read full posts (staff always can), and the import.
			'blog_reader_roles'          => [],
			'blog_import_enabled'        => false,
			'blog_import_dry_run'        => true,
			'blog_batch_posts'           => 10,
			'blog_batch_minutes'         => 1,
			'blog_batch_seconds'         => 40,
			// The sign-in page (page ID; 0 for WordPress's own), and the iMIS forgot-password and join pages.
			'signin_page'                => 0,
			'signin_forgot_url'          => '',
			'signin_join_url'            => '',
			// Free website accounts on the sign-in page, and email confirmation for them: off until turned on.
			'signin_free_accounts'       => false,
			'signin_confirm_email'       => false,
			// Email testing: divert every email to one address, except to these addresses and domains.
			'mail_divert_to'             => '',
			'mail_divert_except'         => '',
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

	/** Who may see an approved query on the website. */
	const AUDIENCES = [ 'staff', 'members', 'everyone' ];

	/**
	 * An approved query's rules: audience (staff, members or everyone; staff until an
	 * administrator chooses), the only columns that may show (none until chosen), and whether
	 * it lists people.
	 *
	 * @param string $query IQA path.
	 * @return array{audience: string, columns: string[], people: bool}
	 */
	public static function query_rule( string $query ): array {
		$rules = self::get( 'query_rules' );
		$rule  = is_array( $rules ) && is_array( $rules[ $query ] ?? null ) ? $rules[ $query ] : [];
		return [
			'audience' => in_array( $rule['audience'] ?? '', self::AUDIENCES, true ) ? $rule['audience'] : 'staff',
			'columns'  => is_array( $rule['columns'] ?? null ) ? array_values( array_map( 'strval', $rule['columns'] ) ) : [],
			'people'   => ! empty( $rule['people'] ),
		];
	}

	/**
	 * Column names from text: comma or line separated, letters, digits, _ and spaces only.
	 *
	 * @param mixed $text Raw text, or an already-cleaned list.
	 * @return string[]
	 */
	public static function parse_columns( $text ): array {
		$text    = is_array( $text ) ? implode( ',', $text ) : (string) $text;
		$columns = [];
		foreach ( preg_split( '/[,\n]+/', $text ) as $column ) {
			$column = trim( (string) $column );
			if ( 1 === preg_match( '/^[A-Za-z0-9_ ]{1,64}$/', $column ) ) {
				$columns[ $column ] = $column;
			}
		}
		return array_values( $columns );
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
	 * Whether a role may be given to synced members: it exists and cannot edit posts.
	 *
	 * @param string $role Role name.
	 * @return bool
	 */
	public static function is_member_role( string $role ): bool {
		$object = get_role( $role );
		return null !== $object && ! $object->has_cap( 'edit_posts' );
	}

	/**
	 * Whether an event page address is usable: an https:// address or an iMIS path, with no spaces.
	 *
	 * @param string $url Address, possibly with {EventId} or {EventCode}.
	 * @return bool
	 */
	public static function is_event_url( string $url ): bool {
		if ( preg_match( '/[\s"\'<>]/', $url ) ) {
			return false;
		}
		return 1 === preg_match( '#^(https://[^/]+|/(?!/))#i', $url );
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
		if ( isset( $input['query_rules'] ) && is_array( $input['query_rules'] ) ) {
			// Only for approved queries (as just saved, else as stored).
			$approved             = $clean['allowed_queries'] ?? self::allowed_queries();
			$clean['query_rules'] = [];
			// The form keys each rule by md5( path ) (paths can hold [ and ]); a saved list by path.
			$by_hash = [];
			foreach ( $approved as $path ) {
				$by_hash[ md5( $path ) ] = $path;
			}
			foreach ( $input['query_rules'] as $key => $rule ) {
				$query = in_array( (string) $key, $approved, true ) ? (string) $key : ( $by_hash[ (string) $key ] ?? '' );
				if ( '' === $query || ! is_array( $rule ) ) {
					continue;
				}
				$clean['query_rules'][ (string) $query ] = [
					'audience' => in_array( $rule['audience'] ?? '', self::AUDIENCES, true ) ? $rule['audience'] : 'staff',
					'columns'  => self::parse_columns( $rule['columns'] ?? '' ),
					'people'   => ! empty( $rule['people'] ),
				];
			}
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
		// The membership columns may be blank (not copied); spaces are allowed, as IQA column
		// names can have them.
		foreach ( [ 'member_type_column', 'member_join_column', 'member_expiry_column' ] as $column ) {
			if ( isset( $input[ $column ] ) ) {
				$name = trim( (string) $input[ $column ] );
				if ( '' === $name || preg_match( '/^[A-Za-z0-9_ ]{1,128}$/', $name ) ) {
					$clean[ $column ] = $name;
				} else {
					add_settings_error( self::OPTION, $column, __( 'Membership column names can only use letters, digits, spaces and _.', 'wormiss' ) );
				}
			}
		}
		if ( isset( $input['access_statuses'] ) ) {
			$clean['access_statuses'] = self::parse_list( $input['access_statuses'] );
		}
		if ( isset( $input['event_statuses'] ) ) {
			$clean['event_statuses'] = self::parse_list( $input['event_statuses'] );
		}
		if ( isset( $input['event_url'] ) ) {
			$url = trim( (string) $input['event_url'] );
			if ( '' === $url || self::is_event_url( $url ) ) {
				$clean['event_url'] = $url;
			} else {
				$message = __( 'The event page address must start with https:// or /, with no spaces.', 'wormiss' );
				add_settings_error( self::OPTION, 'event_url', $message );
			}
		}
		if ( isset( $input['event_import_since'] ) ) {
			$since = trim( (string) $input['event_import_since'] );
			$date  = DateTimeImmutable::createFromFormat( '!Y-m-d', $since );
			if ( '' === $since || ( $date && $date->format( 'Y-m-d' ) === $since && $since <= gmdate( 'Y-m-d' ) ) ) {
				$clean['event_import_since'] = $since;
			} else {
				add_settings_error( self::OPTION, 'event_import_since', __( 'The past events date must be a real date, not in the future.', 'wormiss' ) );
			}
		}
		if ( isset( $input['event_template_id'] ) ) {
			$template = strtoupper( trim( (string) $input['event_template_id'] ) );
			if ( '' === $template || Wormiss_Rest_Client::is_event_id( $template ) ) {
				$clean['event_template_id'] = $template;
			} else {
				$message = __( 'The template event ID can only use letters, digits, _ and -.', 'wormiss' );
				add_settings_error( self::OPTION, 'event_template_id', $message );
			}
		}
		foreach ( [ 'event_registration_enabled', 'event_registration_dry_run', 'event_publish_dates', 'event_cpd' ] as $box ) {
			if ( isset( $input['events_section'] ) ) {
				$clean[ $box ] = ! empty( $input[ $box ] );
			} elseif ( array_key_exists( $box, $input ) ) {
				$clean[ $box ] = (bool) $input[ $box ];
			}
		}
		foreach ( [ 'event_sync_enabled', 'event_sync_dry_run', 'event_cancel_on_trash' ] as $box ) {
			if ( isset( $input['event_sync_section'] ) ) {
				$clean[ $box ] = ! empty( $input[ $box ] );
			} elseif ( array_key_exists( $box, $input ) ) {
				$clean[ $box ] = (bool) $input[ $box ];
			}
		}
		if ( isset( $input['event_places_query'] ) ) {
			$query                       = self::parse_queries( (string) $input['event_places_query'] );
			$clean['event_places_query'] = $query ? $query[0] : '';
		}
		if ( isset( $input['news_import_query'] ) ) {
			$query                      = self::parse_queries( (string) $input['news_import_query'] );
			$clean['news_import_query'] = $query ? $query[0] : '';
		}
		if ( isset( $input['news_import_category'] ) ) {
			$category                      = sanitize_text_field( (string) $input['news_import_category'] );
			$clean['news_import_category'] = '' !== $category ? $category : 'News';
		}
		if ( isset( $input['news_import_author'] ) ) {
			$author = absint( $input['news_import_author'] );
			if ( 0 === $author || user_can( $author, 'edit_posts' ) ) {
				$clean['news_import_author'] = $author;
			} else {
				add_settings_error( self::OPTION, 'news_import_author', __( 'The news author must be able to write posts.', 'wormiss' ) );
			}
		}
		if ( isset( $input['blog_section'] ) ) {
			$roles                      = isset( $input['blog_reader_roles'] ) && is_array( $input['blog_reader_roles'] ) ? $input['blog_reader_roles'] : [];
			$known                      = array_keys( wp_roles()->get_names() );
			$clean['blog_reader_roles'] = array_values( array_intersect( array_map( 'sanitize_key', $roles ), $known ) );
		} elseif ( isset( $input['blog_reader_roles'] ) && is_array( $input['blog_reader_roles'] ) ) {
			$clean['blog_reader_roles'] = array_values( array_intersect( array_map( 'sanitize_key', $input['blog_reader_roles'] ), array_keys( wp_roles()->get_names() ) ) );
		}
		if ( isset( $input['blog_batch_posts'] ) ) {
			$clean['blog_batch_posts'] = min( Wormiss_Blog_Import::BATCH_MAX, max( 1, absint( $input['blog_batch_posts'] ) ) );
		}
		if ( isset( $input['blog_batch_seconds'] ) ) {
			$clean['blog_batch_seconds'] = min( Wormiss_Blog_Import::SECONDS_MAX, max( Wormiss_Blog_Import::SECONDS_MIN, absint( $input['blog_batch_seconds'] ) ) );
		}
		if ( isset( $input['blog_batch_minutes'] ) ) {
			$clean['blog_batch_minutes'] = min( Wormiss_Blog_Import::GAP_MAX, max( 1, absint( $input['blog_batch_minutes'] ) ) );
		}
		if ( isset( $input['signin_page'] ) ) {
			$page                 = absint( $input['signin_page'] );
			$clean['signin_page'] = $page && 'page' === get_post_type( $page ) ? $page : 0;
		}
		if ( isset( $input['mail_divert_to'] ) ) {
			$divert                  = sanitize_email( trim( (string) $input['mail_divert_to'] ) );
			$clean['mail_divert_to'] = is_email( $divert ) ? $divert : '';
			if ( '' === $clean['mail_divert_to'] && '' !== trim( (string) $input['mail_divert_to'] ) ) {
				add_settings_error( self::OPTION, 'mail_divert_to', __( 'The divert address must be an email address.', 'wormiss' ) );
			}
		}
		if ( isset( $input['mail_divert_except'] ) ) {
			$rules = preg_split( '/[\s,;]+/', strtolower( (string) $input['mail_divert_except'] ) );
			$keep  = [];
			foreach ( (array) $rules as $rule ) {
				$rule = trim( $rule );
				// An address, or a domain with or without its @.
				if ( is_email( $rule ) || preg_match( '/^@?[a-z0-9-]+(\.[a-z0-9-]+)+$/', $rule ) ) {
					$keep[] = $rule;
				}
			}
			$clean['mail_divert_except'] = implode( ', ', array_unique( $keep ) );
		}
		foreach ( [ 'signin_free_accounts', 'signin_confirm_email' ] as $box ) {
			if ( isset( $input['signin_section'] ) ) {
				$clean[ $box ] = ! empty( $input[ $box ] );
			} elseif ( array_key_exists( $box, $input ) ) {
				$clean[ $box ] = (bool) $input[ $box ];
			}
		}
		foreach ( [ 'signin_forgot_url', 'signin_join_url' ] as $key ) {
			if ( isset( $input[ $key ] ) ) {
				$url           = esc_url_raw( trim( (string) $input[ $key ] ), [ 'https' ] );
				$clean[ $key ] = $url;
				if ( '' === $url && '' !== trim( (string) $input[ $key ] ) ) {
					add_settings_error( self::OPTION, $key, __( 'The sign-in page\'s iMIS addresses must be https:// web addresses.', 'wormiss' ) );
				}
			}
		}
		foreach ( [ 'blog_import_enabled', 'blog_import_dry_run' ] as $box ) {
			if ( isset( $input['blog_section'] ) ) {
				$clean[ $box ] = ! empty( $input[ $box ] );
			} elseif ( array_key_exists( $box, $input ) ) {
				$clean[ $box ] = (bool) $input[ $box ];
			}
		}
		foreach ( [ 'news_import_enabled', 'news_import_dry_run', 'news_import_publish' ] as $box ) {
			if ( isset( $input['news_import_section'] ) ) {
				$clean[ $box ] = ! empty( $input[ $box ] );
			} elseif ( array_key_exists( $box, $input ) ) {
				$clean[ $box ] = (bool) $input[ $box ];
			}
		}
		if ( isset( $input['user_sync_query'] ) ) {
			$query                    = self::parse_queries( (string) $input['user_sync_query'] );
			$clean['user_sync_query'] = $query ? $query[0] : '';
		}
		if ( isset( $input['user_sync_role'] ) ) {
			$role = sanitize_key( (string) $input['user_sync_role'] );
			if ( self::is_member_role( $role ) ) {
				$clean['user_sync_role'] = $role;
			} else {
				$message = __( 'Members can only be given a role without editing rights.', 'wormiss' );
				add_settings_error( self::OPTION, 'user_sync_role', $message );
			}
		}
		if ( isset( $input['event_import_section'] ) ) {
			$clean['event_import_enabled'] = ! empty( $input['event_import_enabled'] );
			$clean['event_import_publish'] = ! empty( $input['event_import_publish'] );
		} else {
			foreach ( [ 'event_import_enabled', 'event_import_publish' ] as $box ) {
				if ( array_key_exists( $box, $input ) ) {
					$clean[ $box ] = (bool) $input[ $box ];
				}
			}
		}
		foreach ( [ 'user_sync_enabled', 'user_sync_dry_run', 'member_push_enabled', 'member_push_dry_run' ] as $box ) {
			if ( isset( $input['user_sync_section'] ) ) {
				$clean[ $box ] = ! empty( $input[ $box ] );
			} elseif ( array_key_exists( $box, $input ) ) {
				$clean[ $box ] = (bool) $input[ $box ];
			}
		}
		if ( isset( $input['member_cache_minutes'] ) ) {
			$clean['member_cache_minutes'] = min( 60, max( 1, absint( $input['member_cache_minutes'] ) ) );
		}
		if ( isset( $input['log_retention_days'] ) ) {
			$clean['log_retention_days'] = min( 365, max( 1, absint( $input['log_retention_days'] ) ) );
		}
		// iMIS keeps activity type and CEU type codes to 10 characters.
		$codes = [
			'activity_type' => __( 'The iMIS activity type can only use letters, digits and _, up to 10 characters.', 'wormiss' ),
			'cpd_ceu_type'  => __( 'The iMIS CEU type can only use letters, digits and _, up to 10 characters.', 'wormiss' ),
		];
		foreach ( $codes as $key => $message ) {
			if ( isset( $input[ $key ] ) ) {
				$code = trim( (string) $input[ $key ] );
				if ( '' === $code || preg_match( '/^[A-Za-z0-9_]{1,10}$/', $code ) ) {
					$clean[ $key ] = $code;
				} else {
					add_settings_error( self::OPTION, $key, $message );
				}
			}
		}
		// Checkboxes: from the settings form (which marks its activity section, since an unticked
		// box is not submitted), or from an already-cleaned array (WordPress sanitises a first
		// save twice); otherwise they keep their saved values.
		$boxes = [ 'activity_enabled', 'activity_dry_run', 'record_reads', 'record_downloads', 'record_forms', 'record_purchases' ];
		foreach ( $boxes as $box ) {
			if ( isset( $input['activity_section'] ) ) {
				$clean[ $box ] = ! empty( $input[ $box ] );
			} elseif ( array_key_exists( $box, $input ) ) {
				$clean[ $box ] = (bool) $input[ $box ];
			}
		}
		foreach ( [ 'log_enabled', 'log_capture' ] as $box ) {
			if ( isset( $input['log_section'] ) ) {
				$clean[ $box ] = ! empty( $input[ $box ] );
			} elseif ( array_key_exists( $box, $input ) ) {
				$clean[ $box ] = (bool) $input[ $box ];
			}
		}

		// The password lives in its own option; a blank field keeps the current one.
		if ( isset( $input['password'] ) && '' !== (string) $input['password'] && ! self::is_constant( 'password' ) ) {
			// options.php has already unslashed the submitted values.
			update_option( self::PASSWORD_OPTION, (string) $input['password'], false );
		}
		unset( $clean['password'] );

		return $clean;
	}
}
