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
 * Admin screens: Settings > iMIS Bridge (with a connection test) and Tools > iMIS Bridge log.
 *
 * @package    wormiss
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined( 'ABSPATH' ) || exit;

/**
 * Registers and renders the admin pages.
 */
class Wormiss_Admin {

	/** Settings page slug. */
	const PAGE = 'wormiss';

	/** Log page slug. */
	const LOG_PAGE = 'wormiss-log';

	/**
	 * Hook the admin screens.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'admin_menu', [ __CLASS__, 'menus' ] );
		add_action( 'admin_init', [ __CLASS__, 'register_settings' ] );
		add_action( 'admin_post_wormiss_test_connection', [ __CLASS__, 'test_connection' ] );
		add_filter( 'plugin_action_links_' . plugin_basename( WORMISS_FILE ), [ __CLASS__, 'action_links' ] );
	}

	/**
	 * Add the settings and log pages.
	 *
	 * @return void
	 */
	public static function menus(): void {
		add_options_page(
			__( 'iMIS Bridge', 'wormiss' ),
			__( 'iMIS Bridge', 'wormiss' ),
			'manage_options',
			self::PAGE,
			[ __CLASS__, 'render_settings' ]
		);
		add_management_page(
			__( 'iMIS Bridge log', 'wormiss' ),
			__( 'iMIS Bridge log', 'wormiss' ),
			'manage_options',
			self::LOG_PAGE,
			[ __CLASS__, 'render_log' ]
		);
	}

	/**
	 * A Settings link on the Plugins screen.
	 *
	 * @param array $links Existing links.
	 * @return array
	 */
	public static function action_links( array $links ): array {
		$url = admin_url( 'options-general.php?page=' . self::PAGE );
		array_unshift( $links, '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Settings', 'wormiss' ) . '</a>' );
		return $links;
	}

	/**
	 * Register the option and its fields.
	 *
	 * @return void
	 */
	public static function register_settings(): void {
		self::register_option();

		add_settings_section( 'wormiss_connection', __( 'iMIS connection', 'wormiss' ), [ __CLASS__, 'connection_intro' ], self::PAGE );
		self::field( 'base_url', __( 'iMIS address', 'wormiss' ), 'wormiss_connection' );
		self::field( 'username', __( 'Service-account username', 'wormiss' ), 'wormiss_connection' );
		self::field( 'password', __( 'Service-account password', 'wormiss' ), 'wormiss_connection' );
		self::field( 'timeout', __( 'Timeout (seconds)', 'wormiss' ), 'wormiss_connection' );

		add_settings_section( 'wormiss_queries', __( 'iMIS data on pages', 'wormiss' ), [ __CLASS__, 'queries_intro' ], self::PAGE );
		self::field( 'allowed_queries', __( 'Approved IQA queries', 'wormiss' ), 'wormiss_queries' );
		self::field( 'cache_minutes', __( 'Keep results for (minutes)', 'wormiss' ), 'wormiss_queries' );

		add_settings_section( 'wormiss_members', __( "Members' own data", 'wormiss' ), [ __CLASS__, 'members_intro' ], self::PAGE );
		self::field( 'member_id_source', __( "Where each member's iMIS ID is kept", 'wormiss' ), 'wormiss_members' );
		self::field( 'member_id_meta', __( 'User field name', 'wormiss' ), 'wormiss_members' );
		self::field( 'member_queries', __( 'Approved member queries', 'wormiss' ), 'wormiss_members' );
		self::field( 'member_cache_minutes', __( 'Keep member results for (minutes)', 'wormiss' ), 'wormiss_members' );

		add_settings_section( 'wormiss_access', __( 'Members-only content', 'wormiss' ), [ __CLASS__, 'access_intro' ], self::PAGE );
		self::field( 'access_query', __( 'Membership query', 'wormiss' ), 'wormiss_access' );
		self::field( 'access_type_column', __( 'Membership type column', 'wormiss' ), 'wormiss_access' );
		self::field( 'access_status_column', __( 'Membership status column', 'wormiss' ), 'wormiss_access' );
		self::field( 'access_statuses', __( 'Current statuses', 'wormiss' ), 'wormiss_access' );

		add_settings_section( 'wormiss_events', __( 'iMIS events', 'wormiss' ), [ __CLASS__, 'events_intro' ], self::PAGE );
		self::field( 'event_statuses', __( 'Event statuses to show', 'wormiss' ), 'wormiss_events' );
		self::field( 'event_url', __( 'Event page address', 'wormiss' ), 'wormiss_events' );

		$title = __( 'Record activity in iMIS', 'wormiss' );
		add_settings_section( 'wormiss_activity', $title, [ __CLASS__, 'activity_intro' ], self::PAGE );
		self::field( 'activity_enabled', __( 'Record activity', 'wormiss' ), 'wormiss_activity' );
		self::field( 'activity_dry_run', __( 'Dry run (log only)', 'wormiss' ), 'wormiss_activity' );
		self::field( 'activity_type', __( 'iMIS activity type', 'wormiss' ), 'wormiss_activity' );
		self::field( 'record_reads', __( 'Reads of members-only content', 'wormiss' ), 'wormiss_activity' );
		self::field( 'record_downloads', __( 'Tracked file downloads', 'wormiss' ), 'wormiss_activity' );
		self::field( 'record_forms', __( 'Form submissions', 'wormiss' ), 'wormiss_activity' );

		add_settings_section( 'wormiss_log', __( 'Audit log', 'wormiss' ), '__return_false', self::PAGE );
		self::field( 'log_enabled', __( 'Log calls to iMIS', 'wormiss' ), 'wormiss_log' );
		self::field( 'log_capture', __( 'Keep full request and response bodies', 'wormiss' ), 'wormiss_log' );
		self::field( 'log_retention_days', __( 'Keep log entries for (days)', 'wormiss' ), 'wormiss_log' );
	}

	/**
	 * Register the option with its sanitiser and defaults.
	 *
	 * @return void
	 */
	public static function register_option(): void {
		register_setting(
			self::PAGE,
			Wormiss_Settings::OPTION,
			[
				'type'              => 'array',
				'sanitize_callback' => [ 'Wormiss_Settings', 'sanitize' ],
				'default'           => Wormiss_Settings::defaults(),
			]
		);
	}

	/**
	 * Add one settings field.
	 *
	 * @param string $key     Setting name.
	 * @param string $label   Field label.
	 * @param string $section Section id.
	 * @return void
	 */
	private static function field( string $key, string $label, string $section ): void {
		add_settings_field(
			'wormiss_' . $key,
			$label,
			[ __CLASS__, 'render_field' ],
			self::PAGE,
			$section,
			[
				'key'       => $key,
				'label_for' => 'wormiss_' . $key,
			]
		);
	}

	/**
	 * Intro text for the connection section.
	 *
	 * @return void
	 */
	public static function connection_intro(): void {
		echo '<p>'
			. esc_html__( 'Use a dedicated iMIS service account with the RemoteService role and only the rights it needs.', 'wormiss' )
			. ' ' . esc_html__( 'Never use a person\'s login.', 'wormiss' )
			. ' ' . esc_html__( 'Each value can instead be set in wp-config.php, which keeps the password out of the database:', 'wormiss' )
			. ' <code>WORMISS_IMIS_URL</code>, <code>WORMISS_IMIS_USERNAME</code>, <code>WORMISS_IMIS_PASSWORD</code>.</p>';
	}

	/**
	 * Intro text for the queries section.
	 *
	 * @return void
	 */
	public static function queries_intro(): void {
		echo '<p>'
			. esc_html__( 'Only queries listed here can be shown on pages, whatever an editor types into a block.', 'wormiss' )
			. ' ' . esc_html__( 'Anyone can see a page, so approve only queries whose results are meant to be public.', 'wormiss' )
			. '</p>';
	}

	/**
	 * Intro text for the members section.
	 *
	 * @return void
	 */
	public static function members_intro(): void {
		echo '<p>'
			. esc_html__( 'The My iMIS data block shows a signed-in member their own data.', 'wormiss' )
			. ' ' . esc_html__( "Each member query is run with the member's iMIS ID as its first prompt, so build it in iMIS", 'wormiss' )
			. ' ' . esc_html__( 'with a required filter on the contact ID. Pages never choose whose data is read.', 'wormiss' )
			. '</p>';
	}

	/**
	 * Intro text for the members-only section.
	 *
	 * @return void
	 */
	public static function access_intro(): void {
		echo '<p>'
			. esc_html__( 'Posts, pages and Members only blocks can be limited to members, by membership type and status.', 'wormiss' )
			. ' ' . esc_html__( "The membership query reads a member's own record (their iMIS ID is its first prompt).", 'wormiss' )
			. ' ' . esc_html__( 'Without one, any signed-in member with an iMIS ID counts as a member.', 'wormiss' )
			. '</p>';
	}

	/**
	 * Intro text for the events section.
	 *
	 * @return void
	 */
	public static function events_intro(): void {
		echo '<p>'
			. esc_html__( 'The Upcoming iMIS events block lists events from the iMIS Events API, soonest first.', 'wormiss' )
			. ' ' . esc_html__( 'The service account needs read access to events.', 'wormiss' )
			. ' ' . esc_html__( 'Templates and unpublished events are left out.', 'wormiss' )
			. '</p>';
	}

	/**
	 * Intro text for the activity section (and the marker that its checkboxes were shown).
	 *
	 * @return void
	 */
	public static function activity_intro(): void {
		echo '<input type="hidden" name="' . esc_attr( Wormiss_Settings::OPTION ) . '[activity_section]" value="1" />';
		echo '<p>'
			. esc_html__( 'Records what signed-in members do here as iMIS activity records, sent in the background.', 'wormiss' )
			. ' ' . esc_html__( 'Create the activity type in iMIS first. Mark as done buttons are recorded whenever this is on.', 'wormiss' )
			. ' ' . esc_html__( 'Keep the dry run on until the audit log shows what you expect: it logs, but sends nothing.', 'wormiss' )
			. '</p>';
	}

	/**
	 * Render one field.
	 *
	 * @param array $args Field arguments (key).
	 * @return void
	 */
	public static function render_field( array $args ): void {
		$key   = $args['key'];
		$name  = Wormiss_Settings::OPTION . '[' . $key . ']';
		$id    = 'wormiss_' . $key;
		$value = Wormiss_Settings::get( $key );

		switch ( $key ) {
			case 'base_url':
			case 'username':
				if ( Wormiss_Settings::is_constant( $key ) ) {
					self::constant_note( 'base_url' === $key ? Wormiss_Settings::base_url() : Wormiss_Settings::username() );
					return;
				}
				printf(
					'<input type="%1$s" class="regular-text" id="%2$s" name="%3$s" value="%4$s" autocomplete="off" />',
					'base_url' === $key ? 'url' : 'text',
					esc_attr( $id ),
					esc_attr( $name ),
					esc_attr( (string) $value )
				);
				if ( 'base_url' === $key ) {
					echo '<p class="description">'
						. esc_html__( 'The iMIS site root, e.g. https://members.example.org (without /api).', 'wormiss' ) . '</p>';
				}
				return;
			case 'password':
				if ( Wormiss_Settings::is_constant( 'password' ) ) {
					self::constant_note( null );
					return;
				}
				$set = '' !== Wormiss_Settings::password();
				printf(
					'<input type="password" class="regular-text" id="%1$s" name="%2$s" value="" autocomplete="new-password" placeholder="%3$s" />',
					esc_attr( $id ),
					esc_attr( $name ),
					esc_attr( $set ? __( 'Saved: leave blank to keep', 'wormiss' ) : '' )
				);
				return;
			case 'allowed_queries':
			case 'member_queries':
				$queries = 'member_queries' === $key ? Wormiss_Settings::member_queries() : Wormiss_Settings::allowed_queries();
				printf(
					'<textarea class="large-text code" rows="5" id="%1$s" name="%2$s">%3$s</textarea>',
					esc_attr( $id ),
					esc_attr( $name ),
					esc_textarea( implode( "\n", $queries ) )
				);
				echo '<p class="description">'
					. esc_html__( 'One IQA document path per line, e.g. $/Events/UpcomingEvents.', 'wormiss' ) . '</p>';
				return;
			case 'member_id_source':
				printf( '<select id="%1$s" name="%2$s">', esc_attr( $id ), esc_attr( $name ) );
				printf(
					'<option value="meta" %1$s>%2$s</option><option value="login" %3$s>%4$s</option></select>',
					selected( $value, 'meta', false ),
					esc_html__( 'A user field set by single sign-on', 'wormiss' ),
					selected( $value, 'login', false ),
					esc_html__( 'The WordPress username', 'wormiss' )
				);
				return;
			case 'access_query':
			case 'access_type_column':
			case 'access_status_column':
				printf(
					'<input type="text" class="regular-text code" id="%1$s" name="%2$s" value="%3$s" />',
					esc_attr( $id ),
					esc_attr( $name ),
					esc_attr( (string) $value )
				);
				if ( 'access_query' === $key ) {
					echo '<p class="description">'
						. esc_html__( 'An IQA path, e.g. $/Members/MyMembership, returning one row for the member.', 'wormiss' )
						. '</p>';
				}
				return;
			case 'access_statuses':
				printf(
					'<input type="text" class="regular-text" id="%1$s" name="%2$s" value="%3$s" />',
					esc_attr( $id ),
					esc_attr( $name ),
					esc_attr( implode( ', ', (array) $value ) )
				);
				echo '<p class="description">'
					. esc_html__( 'Comma-separated, e.g. "A, Active". Used when a post or block names no statuses; blank for any.', 'wormiss' )
					. '</p>';
				return;
			case 'member_id_meta':
				printf(
					'<input type="text" class="regular-text code" id="%1$s" name="%2$s" value="%3$s" />',
					esc_attr( $id ),
					esc_attr( $name ),
					esc_attr( (string) $value )
				);
				echo '<p class="description">'
					. esc_html__( 'The user meta key your single sign-on plugin fills with the iMIS ID (default imis_id).', 'wormiss' )
					. '</p>';
				return;
			case 'event_statuses':
				printf(
					'<input type="text" class="regular-text" id="%1$s" name="%2$s" value="%3$s" />',
					esc_attr( $id ),
					esc_attr( $name ),
					esc_attr( implode( ', ', (array) $value ) )
				);
				echo '<p class="description">'
					. esc_html__( 'iMIS event status codes, comma-separated (default A, for active). Blank shows any status.', 'wormiss' )
					. '</p>';
				return;
			case 'event_url':
				printf(
					'<input type="text" class="large-text code" id="%1$s" name="%2$s" value="%3$s" />',
					esc_attr( $id ),
					esc_attr( $name ),
					esc_attr( (string) $value )
				);
				echo '<p class="description">'
					. esc_html__( 'Where each event links to. {EventId} or {EventCode} is replaced; a path starting with / is', 'wormiss' )
					. ' ' . esc_html__( 'on the iMIS site. Default:', 'wormiss' )
					. ' <code>' . esc_html( Wormiss_Settings::DEFAULT_EVENT_URL ) . '</code></p>';
				return;
			case 'activity_type':
				printf(
					'<input type="text" class="regular-text code" id="%1$s" name="%2$s" value="%3$s" placeholder="WEBACTIVITY" />',
					esc_attr( $id ),
					esc_attr( $name ),
					esc_attr( (string) $value )
				);
				return;
			case 'log_enabled':
			case 'log_capture':
			case 'activity_enabled':
			case 'activity_dry_run':
			case 'record_reads':
			case 'record_downloads':
			case 'record_forms':
				printf(
					'<input type="checkbox" id="%1$s" name="%2$s" value="1" %3$s />',
					esc_attr( $id ),
					esc_attr( $name ),
					checked( (bool) $value, true, false )
				);
				if ( 'log_capture' === $key ) {
					echo ' <span class="description">'
						. esc_html__( 'For troubleshooting only: bodies can hold members\' personal details.', 'wormiss' )
						. ' ' . esc_html__( 'Passwords and tokens are always removed.', 'wormiss' ) . '</span>';
				}
				return;
			default:
				printf(
					'<input type="number" class="small-text" id="%1$s" name="%2$s" value="%3$d" min="1" />',
					esc_attr( $id ),
					esc_attr( $name ),
					(int) $value
				);
		}
	}

	/**
	 * Note shown instead of a field fixed in wp-config.php.
	 *
	 * @param string|null $value Value to show, or null to hide it (the password).
	 * @return void
	 */
	private static function constant_note( ?string $value ): void {
		if ( null !== $value ) {
			echo '<code>' . esc_html( $value ) . '</code> ';
		}
		echo '<span class="description">' . esc_html__( 'Set in wp-config.php.', 'wormiss' ) . '</span>';
	}

	/**
	 * The settings page.
	 *
	 * @return void
	 */
	public static function render_settings(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		echo '<div class="wrap"><h1>' . esc_html__( 'iMIS Bridge', 'wormiss' ) . '</h1>';
		self::connection_result_notice();
		echo '<form action="options.php" method="post">';
		settings_fields( self::PAGE );
		do_settings_sections( self::PAGE );
		submit_button();
		echo '</form>';

		echo '<h2>' . esc_html__( 'Test the connection', 'wormiss' ) . '</h2>';
		echo '<p>'
			. esc_html__( 'Signs in to iMIS with the saved settings and runs the first approved query.', 'wormiss' )
			. ' ' . esc_html__( 'Nothing is changed in iMIS.', 'wormiss' ) . '</p>';
		echo '<form action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" method="post">';
		echo '<input type="hidden" name="action" value="wormiss_test_connection" />';
		wp_nonce_field( 'wormiss_test_connection' );
		submit_button( __( 'Test connection', 'wormiss' ), 'secondary', 'submit', false );
		echo '</form>';

		$log = admin_url( 'tools.php?page=' . self::LOG_PAGE );
		echo '<p><a href="' . esc_url( $log ) . '">' . esc_html__( 'Open the audit log', 'wormiss' ) . '</a></p></div>';
	}

	/**
	 * Handle the Test connection button.
	 *
	 * @return void
	 */
	public static function test_connection(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'wormiss' ), 403 );
		}
		check_admin_referer( 'wormiss_test_connection' );

		$result = self::run_connection_test();
		set_transient( 'wormiss_test_' . get_current_user_id(), $result, 5 * MINUTE_IN_SECONDS );
		wp_safe_redirect( admin_url( 'options-general.php?page=' . self::PAGE ) );
		exit;
	}

	/**
	 * Sign in and (if any are approved) run the first approved query.
	 *
	 * @return array {ok:bool, message:string}
	 */
	public static function run_connection_test(): array {
		if ( ! Wormiss_Settings::is_configured() ) {
			return [
				'ok'      => false,
				'message' => __( 'Fill in the iMIS address, username and password first.', 'wormiss' ),
			];
		}
		try {
			$client = new Wormiss_Rest_Client();
			$client->get_token();
			$queries = Wormiss_Settings::allowed_queries();
			if ( ! $queries ) {
				return [
					'ok'      => true,
					'message' => __( 'Signed in to iMIS. Add an approved query to test reading data.', 'wormiss' ),
				];
			}
			$rows = $client->run_iqa( $queries[0] );
			return [
				'ok'      => true,
				'message' => sprintf(
					/* translators: 1: IQA path, 2: number of rows. */
					_n( 'Signed in to iMIS and ran %1$s: %2$d row.', 'Signed in to iMIS and ran %1$s: %2$d rows.', count( $rows ), 'wormiss' ),
					$queries[0],
					count( $rows )
				),
			];
		} catch ( Wormiss_Exception $e ) {
			return [
				'ok'      => false,
				'message' => $e->getMessage(),
			];
		}
	}

	/**
	 * Show the result of the last connection test, once.
	 *
	 * @return void
	 */
	private static function connection_result_notice(): void {
		$key    = 'wormiss_test_' . get_current_user_id();
		$result = get_transient( $key );
		if ( ! is_array( $result ) ) {
			return;
		}
		delete_transient( $key );
		printf(
			'<div class="notice notice-%1$s"><p>%2$s</p></div>',
			$result['ok'] ? 'success' : 'error',
			esc_html( (string) $result['message'] )
		);
	}

	/**
	 * The audit log page (newest first, 50 per page).
	 *
	 * @return void
	 */
	public static function render_log(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$per_page = 50;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only paging.
		$paged = max( 1, absint( $_GET['paged'] ?? 1 ) );
		$total = Wormiss_Logger::count();
		$rows  = Wormiss_Logger::recent( $per_page, ( $paged - 1 ) * $per_page );

		echo '<div class="wrap"><h1>' . esc_html__( 'iMIS Bridge log', 'wormiss' ) . '</h1>';
		if ( ! Wormiss_Logger::is_enabled() ) {
			echo '<div class="notice notice-warning"><p>'
				. esc_html__( 'Logging is switched off in Settings > iMIS Bridge.', 'wormiss' ) . '</p></div>';
		}
		echo '<table class="widefat striped"><thead><tr>';
		$headings = [
			__( 'Time', 'wormiss' ),
			__( 'Call', 'wormiss' ),
			__( 'Result', 'wormiss' ),
			__( 'Error', 'wormiss' ),
			__( 'User', 'wormiss' ),
		];
		foreach ( $headings as $heading ) {
			echo '<th scope="col">' . esc_html( $heading ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		if ( ! $rows ) {
			echo '<tr><td colspan="5">' . esc_html__( 'No calls logged yet.', 'wormiss' ) . '</td></tr>';
		}
		foreach ( $rows as $row ) {
			$user = (int) $row['user_id'] ? get_userdata( (int) $row['user_id'] ) : false;
			printf(
				'<tr><td>%1$s</td><td><code>%2$s</code></td><td>%3$s</td><td>%4$s</td><td>%5$s</td></tr>',
				esc_html( get_date_from_gmt( $row['created'], get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ) ),
				esc_html( $row['endpoint'] ),
				esc_html( $row['status'] ),
				esc_html( (string) $row['error'] ),
				esc_html( $user ? $user->user_login : '' )
			);
		}
		echo '</tbody></table>';

		$pages = (int) ceil( $total / $per_page );
		if ( $pages > 1 ) {
			echo '<p>' . wp_kses_post(
				paginate_links(
					[
						'base'    => add_query_arg( 'paged', '%#%' ),
						'current' => $paged,
						'total'   => $pages,
					]
				)
			) . '</p>';
		}
		echo '</div>';
	}
}
