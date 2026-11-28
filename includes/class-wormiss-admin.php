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
 * Admin screens under the Wormiss menu: Overview, Settings (with a connection test) and the audit log.
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

	/** The Wormiss menu's overview page slug (also the menu's own slug). */
	const MENU = 'wormiss';

	/** Settings page slug (the settings group stays PAGE). */
	const SETTINGS_PAGE = 'wormiss-settings';

	/** Log page slug. */
	const LOG_PAGE = 'wormiss-log';

	/**
	 * The "What are you adding?" page (an event or an event category).
	 */
	const ADD_PAGE = 'wormiss-add-new';

	/**
	 * The settings tabs and the sections on each (the first tab is the default).
	 */
	const TABS = [
		'connection' => [ 'wormiss_connection', 'wormiss_queries' ],
		'members'    => [ 'wormiss_members', 'wormiss_access', 'wormiss_user_sync' ],
		'sign-in'    => [ 'wormiss_sign_in' ],
		'events'     => [ 'wormiss_events', 'wormiss_event_sync', 'wormiss_event_import' ],
		'news'       => [ 'wormiss_news_import' ],
		'pages'      => [ 'wormiss_page_import' ],
		'doctors'    => [],
		'blog'       => [ 'wormiss_blog' ],
		'media'      => [ 'wormiss_media' ],
		'activity'   => [ 'wormiss_activity', 'wormiss_mail_divert', 'wormiss_log' ],
	];

	/**
	 * Hook the admin screens.
	 *
	 * @return void
	 */
	public static function init(): void {
		// Early, so the Events post type (listed under this menu) finds its parent.
		add_action( 'admin_menu', [ __CLASS__, 'menus' ], 9 );
		add_action( 'admin_menu', [ __CLASS__, 'more_menus' ], 11 );
		add_action( 'admin_init', [ __CLASS__, 'redirect_old_pages' ] );
		add_filter( 'parent_file', [ __CLASS__, 'parent_file' ] );
		add_action( 'admin_init', [ __CLASS__, 'register_settings' ] );
		add_action( 'admin_post_wormiss_test_connection', [ __CLASS__, 'test_connection' ] );
		add_action( 'load-post-new.php', [ __CLASS__, 'choose_first' ] );
		add_action( 'admin_post_wormiss_log_export', [ __CLASS__, 'export_log' ] );
		add_action( 'admin_post_wormiss_save_media_matrix', [ __CLASS__, 'save_media_matrix' ] );
		add_action( 'admin_post_wormiss_save_access_rules', [ __CLASS__, 'save_access_rules' ] );
		Wormiss_Content_Check::init();
		Wormiss_Blog_Check::init();
		Wormiss_Sign_In_Check::init();
		add_filter( 'plugin_action_links_' . plugin_basename( WORMISS_FILE ), [ __CLASS__, 'action_links' ] );
	}

	/**
	 * Add the settings and log pages.
	 *
	 * @return void
	 */
	public static function menus(): void {
		// Editors see the menu (for events); Settings and the audit log are for administrators.
		add_menu_page(
			__( 'Wormiss', 'wormiss' ),
			__( 'Wormiss', 'wormiss' ),
			'edit_posts',
			self::MENU,
			[ __CLASS__, 'render_overview' ],
			'dashicons-randomize',
			26
		);
		add_submenu_page( self::MENU, __( 'Wormiss', 'wormiss' ), __( 'Overview', 'wormiss' ), 'edit_posts', self::MENU );
	}

	/**
	 * The rest of the Wormiss menu, after the Events list WordPress adds for the post type.
	 *
	 * @return void
	 */
	public static function more_menus(): void {
		$type     = get_post_type_object( Wormiss_Event_Sync::POST_TYPE );
		$taxonomy = get_taxonomy( Wormiss_Event_Types::TAXONOMY );
		if ( $type ) {
			// First asks whether it is an event or an event category.
			add_submenu_page(
				self::MENU,
				$type->labels->add_new_item,
				$type->labels->add_new_item,
				'edit_posts',
				self::ADD_PAGE,
				[ __CLASS__, 'render_add_chooser' ]
			);
		}
		if ( $taxonomy ) {
			// Each list first, then its Add New.
			add_submenu_page(
				self::MENU,
				$taxonomy->labels->name,
				$taxonomy->labels->menu_name,
				'manage_categories',
				self::types_page()
			);
			add_submenu_page(
				self::MENU,
				$taxonomy->labels->add_new_item,
				$taxonomy->labels->add_new_item,
				'manage_categories',
				self::types_page() . '&wormiss_add=1'
			);
		}
		add_submenu_page(
			self::MENU,
			__( 'Wormiss settings', 'wormiss' ),
			__( 'Settings', 'wormiss' ),
			'manage_options',
			self::SETTINGS_PAGE,
			[ __CLASS__, 'render_settings' ]
		);
		add_submenu_page(
			self::MENU,
			__( 'Wormiss audit log', 'wormiss' ),
			__( 'Audit log', 'wormiss' ),
			'manage_options',
			self::LOG_PAGE,
			[ __CLASS__, 'render_log' ]
		);
	}

	/**
	 * The Event categories screen, as a menu path.
	 *
	 * @return string
	 */
	private static function types_page(): string {
		return 'edit-tags.php?taxonomy=' . Wormiss_Event_Types::TAXONOMY . '&post_type=' . Wormiss_Event_Sync::POST_TYPE;
	}

	/**
	 * Keep the Wormiss menu open on the Event categories screens (WordPress would look for an
	 * Events menu of their own).
	 *
	 * @param string $parent_file Parent menu.
	 * @return string
	 */
	public static function parent_file( $parent_file ) {
		global $submenu_file;
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( $screen && Wormiss_Event_Types::TAXONOMY === $screen->taxonomy ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only picks the menu item to highlight.
			$adding       = isset( $_GET['wormiss_add'] ) && 'edit-tags' === $screen->base;
			$submenu_file = self::types_page() . ( $adding ? '&wormiss_add=1' : '' ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Highlights the menu item.
			return self::MENU;
		}
		if ( $screen && 'post' === $screen->base && 'add' === $screen->action && Wormiss_Event_Sync::POST_TYPE === $screen->post_type ) {
			$submenu_file = self::ADD_PAGE; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Highlights the menu item.
			return self::MENU;
		}
		return $parent_file;
	}

	/**
	 * Adding a new event first asks what is being added, for people who may add event
	 * categories too (the Events list's "Add New Event" button included). The chooser's
	 * "An event" link carries wormiss_new, so it goes straight through.
	 *
	 * @return void
	 */
	public static function choose_first(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Only decides which page to show.
		$type = isset( $_GET['post_type'] ) ? sanitize_key( wp_unslash( $_GET['post_type'] ) ) : '';
		$skip = isset( $_GET['wormiss_new'] );
		// phpcs:enable
		if ( Wormiss_Event_Sync::POST_TYPE !== $type || $skip || ! current_user_can( 'manage_categories' ) ) {
			return;
		}
		wp_safe_redirect( admin_url( 'admin.php?page=' . self::ADD_PAGE ) );
		exit;
	}

	/**
	 * The choices on the "What are you adding?" page.
	 *
	 * @return array[] Each: url, title, text.
	 */
	public static function add_choices(): array {
		$choices = [
			[
				'url'   => admin_url( 'post-new.php?post_type=' . Wormiss_Event_Sync::POST_TYPE . '&wormiss_new=1' ),
				'title' => __( 'An event', 'wormiss' ),
				'text'  => __( 'One course, workshop or other event on a date, with its own page (sent to iMIS when sending is on).', 'wormiss' ),
			],
		];
		if ( current_user_can( 'manage_categories' ) ) {
			$choices[] = [
				'url'   => admin_url( self::types_page() . '&wormiss_add=1#tag-name' ),
				'title' => __( 'An event category', 'wormiss' ),
				'text'  => __( 'A group that events belong to, such as Course or Essential (under Course), with its iMIS template and category. Events are then put into it.', 'wormiss' ),
			];
		}
		return $choices;
	}

	/**
	 * The "What are you adding?" page.
	 *
	 * @return void
	 */
	public static function render_add_chooser(): void {
		$choices = self::add_choices();
		if ( 1 === count( $choices ) ) {
			// Only events can be added by this person: no question to ask.
			echo '<script>window.location.replace(' . wp_json_encode( $choices[0]['url'] ) . ');</script>';
		}
		echo '<div class="wrap"><h1>' . esc_html__( 'What are you adding?', 'wormiss' ) . '</h1>';
		echo '<div style="display:flex;flex-wrap:wrap;gap:1.5rem;margin-top:1.5rem">';
		foreach ( $choices as $choice ) {
			echo '<a class="card" style="display:block;max-width:24rem;margin:0;padding:1.5rem;text-decoration:none" href="' . esc_url( $choice['url'] ) . '">'
				. '<h2 style="margin-top:0">' . esc_html( $choice['title'] ) . '</h2>'
				. '<p>' . esc_html( $choice['text'] ) . '</p>'
				. '<span class="button button-primary">' . esc_html__( 'Add', 'wormiss' ) . ' ' . esc_html( strtolower( $choice['title'] ) ) . '</span></a>';
		}
		echo '</div></div>';
	}

	/**
	 * Old addresses (Wormiss > Settings, Wormiss > Audit log) go to the new pages.
	 *
	 * @return void
	 */
	public static function redirect_old_pages(): void {
		global $pagenow;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only redirects to another page.
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		$to   = self::old_page_target( (string) $pagenow, $page );
		if ( '' !== $to ) {
			wp_safe_redirect( $to );
			exit;
		}
	}

	/**
	 * Where an old Wormiss admin address now lives, or ''.
	 *
	 * @param string $pagenow Admin file, e.g. options-general.php.
	 * @param string $page    The page query value.
	 * @return string
	 */
	public static function old_page_target( string $pagenow, string $page ): string {
		if ( 'options-general.php' === $pagenow && self::PAGE === $page ) {
			return self::settings_url();
		}
		if ( 'tools.php' === $pagenow && self::LOG_PAGE === $page ) {
			return self::log_url();
		}
		return '';
	}

	/**
	 * The Settings page address.
	 *
	 * @param string $tab Tab to open (the first when empty or unknown).
	 * @return string
	 */
	public static function settings_url( string $tab = '' ): string {
		$url = admin_url( 'admin.php?page=' . self::SETTINGS_PAGE );
		return isset( self::TABS[ $tab ] ) ? $url . '&tab=' . $tab : $url;
	}

	/**
	 * The audit log address.
	 *
	 * @return string
	 */
	public static function log_url(): string {
		return admin_url( 'admin.php?page=' . self::LOG_PAGE );
	}

	/**
	 * The Overview page: what is set up and switched on, with links to each part.
	 *
	 * @return void
	 */
	public static function render_overview(): void {
		$on    = static fn( string $key ) => (bool) Wormiss_Settings::get( $key );
		$state = static function ( bool $enabled, bool $dry_run ) {
			if ( ! $enabled ) {
				return __( 'Off', 'wormiss' );
			}
			return $dry_run ? __( 'On (dry run: nothing is sent)', 'wormiss' ) : __( 'On', 'wormiss' );
		};
		$rows  = [
			__( 'iMIS connection', 'wormiss' )          => Wormiss_Settings::is_configured()
				? Wormiss_Settings::base_url() : __( 'Not set up', 'wormiss' ),
			__( 'Record activity', 'wormiss' )          => $state( $on( 'activity_enabled' ), $on( 'activity_dry_run' ) ),
			__( 'Send events to iMIS', 'wormiss' )      => $state( $on( 'event_sync_enabled' ), $on( 'event_sync_dry_run' ) ),
			__( 'Free event registration', 'wormiss' )  => $state( $on( 'event_registration_enabled' ), $on( 'event_registration_dry_run' ) ),
			__( 'Member sync', 'wormiss' )              => $state( $on( 'user_sync_enabled' ), $on( 'user_sync_dry_run' ) ),
			__( 'Member changes to iMIS', 'wormiss' )   => $state( $on( 'member_push_enabled' ), $on( 'member_push_dry_run' ) ),
			__( 'iMIS event import', 'wormiss' )        => $on( 'event_import_enabled' ) ? __( 'On (daily, as drafts)', 'wormiss' ) : __( 'Off', 'wormiss' ),
			__( 'CPD hours on event pages', 'wormiss' ) => $on( 'event_cpd' ) ? __( 'On (read from iMIS)', 'wormiss' ) : __( 'Off', 'wormiss' ),
			__( 'iMIS news import', 'wormiss' )         => $state( $on( 'news_import_enabled' ), $on( 'news_import_dry_run' ) ),
			__( 'iMIS page import', 'wormiss' )         => $state( $on( 'page_import_enabled' ), $on( 'page_import_dry_run' ) ),
			__( 'iMIS blog import', 'wormiss' )         => $state( $on( 'blog_import_enabled' ), $on( 'blog_import_dry_run' ) ),
		];
		echo '<div class="wrap"><h1>' . esc_html__( 'Wormiss', 'wormiss' ) . '</h1>';
		echo '<p>' . esc_html__( 'Connects this site to iMIS. Everything Wormiss adds is under this menu.', 'wormiss' ) . '</p>';
		echo '<table class="widefat striped" style="max-width:48rem"><tbody>';
		foreach ( $rows as $label => $value ) {
			echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td>' . esc_html( $value ) . '</td></tr>';
		}
		echo '</tbody></table><h2>' . esc_html__( 'Go to', 'wormiss' ) . '</h2><ul class="ul-disc">';
		$items = [ [ admin_url( 'edit.php?post_type=' . Wormiss_Event_Sync::POST_TYPE ), __( 'Events', 'wormiss' ) ] ];
		if ( current_user_can( 'manage_categories' ) ) {
			$items[] = [ admin_url( self::types_page() ), __( 'Event categories (iMIS templates, categories and capacity)', 'wormiss' ) ];
		}
		if ( current_user_can( 'manage_options' ) ) {
			$items[] = [ self::settings_url(), __( 'Settings', 'wormiss' ) ];
			$items[] = [ self::log_url(), __( 'Audit log (every call to iMIS)', 'wormiss' ) ];
		}
		foreach ( $items as [ $url, $text ] ) {
			echo '<li><a href="' . esc_url( $url ) . '">' . esc_html( $text ) . '</a></li>';
		}
		echo '</ul></div>';
	}

	/**
	 * A Settings link on the Plugins screen.
	 *
	 * @param array $links Existing links.
	 * @return array
	 */
	public static function action_links( array $links ): array {
		$url = self::settings_url();
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

		self::section( 'wormiss_connection', __( 'iMIS connection', 'wormiss' ), [ __CLASS__, 'connection_intro' ] );
		self::field( 'base_url', __( 'iMIS address', 'wormiss' ), 'wormiss_connection' );
		self::field( 'username', __( 'Service-account username', 'wormiss' ), 'wormiss_connection' );
		self::field( 'password', __( 'Service-account password', 'wormiss' ), 'wormiss_connection' );
		self::field( 'timeout', __( 'Timeout (seconds)', 'wormiss' ), 'wormiss_connection' );

		self::section( 'wormiss_queries', __( 'iMIS data on pages', 'wormiss' ), [ __CLASS__, 'queries_intro' ] );
		self::field( 'allowed_queries', __( 'Approved IQA queries', 'wormiss' ), 'wormiss_queries' );
		self::field( 'cache_minutes', __( 'Keep results for (minutes)', 'wormiss' ), 'wormiss_queries' );

		self::section( 'wormiss_members', __( "Members' own data", 'wormiss' ), [ __CLASS__, 'members_intro' ] );
		self::field( 'member_id_source', __( "Where each member's iMIS ID is kept", 'wormiss' ), 'wormiss_members' );
		self::field( 'member_id_meta', __( 'User field name', 'wormiss' ), 'wormiss_members' );
		self::field( 'member_queries', __( 'Approved member queries', 'wormiss' ), 'wormiss_members' );
		self::field( 'member_cache_minutes', __( 'Keep member results for (minutes)', 'wormiss' ), 'wormiss_members' );

		self::section( 'wormiss_access', __( 'Members-only content', 'wormiss' ), [ __CLASS__, 'access_intro' ] );
		self::field( 'access_query', __( 'Membership query', 'wormiss' ), 'wormiss_access' );
		self::field( 'access_type_column', __( 'Membership type column', 'wormiss' ), 'wormiss_access' );
		self::field( 'access_status_column', __( 'Membership status column', 'wormiss' ), 'wormiss_access' );
		self::field( 'access_statuses', __( 'Current statuses', 'wormiss' ), 'wormiss_access' );

		self::section( 'wormiss_events', __( 'iMIS events', 'wormiss' ), [ __CLASS__, 'events_intro' ] );
		self::field( 'event_statuses', __( 'Event statuses to show', 'wormiss' ), 'wormiss_events' );
		self::field( 'event_url', __( 'Event page address', 'wormiss' ), 'wormiss_events' );
		self::field( 'event_places_query', __( 'Places count IQA', 'wormiss' ), 'wormiss_events' );
		self::field( 'event_publish_dates', __( 'Follow iMIS publish dates', 'wormiss' ), 'wormiss_events' );
		self::field( 'event_cpd', __( 'Show CPD hours', 'wormiss' ), 'wormiss_events' );
		self::field( 'event_registration_enabled', __( 'Members register for free events here', 'wormiss' ), 'wormiss_events' );
		self::field( 'event_registration_dry_run', __( 'Registration dry run (log only)', 'wormiss' ), 'wormiss_events' );

		$title = __( 'Send WordPress events to iMIS', 'wormiss' );
		self::section( 'wormiss_event_sync', $title, [ __CLASS__, 'event_sync_intro' ] );
		self::field( 'event_sync_enabled', __( 'Send events to iMIS', 'wormiss' ), 'wormiss_event_sync' );
		self::field( 'event_sync_dry_run', __( 'Dry run (check only)', 'wormiss' ), 'wormiss_event_sync' );
		self::field( 'event_template_id', __( 'Default iMIS template event ID', 'wormiss' ), 'wormiss_event_sync' );
		self::field( 'event_cancel_on_trash', __( 'Cancel in iMIS when trashed', 'wormiss' ), 'wormiss_event_sync' );

		$title = __( 'Import iMIS events', 'wormiss' );
		self::section( 'wormiss_event_import', $title, [ __CLASS__, 'event_import_intro' ] );
		self::field( 'event_import_enabled', __( 'Import iMIS events daily', 'wormiss' ), 'wormiss_event_import' );
		self::field( 'event_import_publish', __( 'Publish imported pages', 'wormiss' ), 'wormiss_event_import' );
		self::field( 'event_import_since', __( 'Also import past events since', 'wormiss' ), 'wormiss_event_import' );

		$title = __( 'Import iMIS news', 'wormiss' );
		self::section( 'wormiss_news_import', $title, [ __CLASS__, 'news_import_intro' ] );
		self::field( 'news_import_enabled', __( 'Import iMIS news daily', 'wormiss' ), 'wormiss_news_import' );
		self::field( 'news_import_dry_run', __( 'News import dry run (report only)', 'wormiss' ), 'wormiss_news_import' );
		self::field( 'news_import_publish', __( 'Publish imported news', 'wormiss' ), 'wormiss_news_import' );
		self::field( 'news_import_query', __( 'News query', 'wormiss' ), 'wormiss_news_import' );
		self::field( 'news_import_category', __( 'Category for imported news', 'wormiss' ), 'wormiss_news_import' );
		self::field( 'news_import_author', __( 'Author for imported news', 'wormiss' ), 'wormiss_news_import' );
		self::field( 'news_import_incremental', __( 'Only re-read changed articles', 'wormiss' ), 'wormiss_news_import' );

		$title = __( 'Import iMIS pages', 'wormiss' );
		self::section( 'wormiss_page_import', $title, [ __CLASS__, 'page_import_intro' ] );
		self::field( 'page_import_enabled', __( 'Import iMIS pages daily', 'wormiss' ), 'wormiss_page_import' );
		self::field( 'page_import_dry_run', __( 'Page import dry run (report only)', 'wormiss' ), 'wormiss_page_import' );
		self::field( 'page_import_publish', __( 'Publish imported pages', 'wormiss' ), 'wormiss_page_import' );
		self::field( 'page_import_query', __( 'Page query', 'wormiss' ), 'wormiss_page_import' );
		self::field( 'page_import_menu', __( 'Build a navigation menu', 'wormiss' ), 'wormiss_page_import' );
		self::field( 'page_import_menu_name', __( 'Navigation menu name', 'wormiss' ), 'wormiss_page_import' );

		$title = __( 'Members\' blog', 'wormiss' );
		self::section( 'wormiss_blog', $title, [ __CLASS__, 'blog_intro' ] );
		self::field( 'blog_reader_roles', __( 'Who can read the members\' blog', 'wormiss' ), 'wormiss_blog' );
		self::field( 'blog_media_category', __( 'Media category for imported pictures', 'wormiss' ), 'wormiss_blog' );
		self::field( 'blog_import_enabled', __( 'Import the iMIS blog daily', 'wormiss' ), 'wormiss_blog' );
		self::field( 'blog_import_dry_run', __( 'Blog import dry run (report only)', 'wormiss' ), 'wormiss_blog' );
		self::field( 'blog_import_incremental', __( 'Only re-read changed posts', 'wormiss' ), 'wormiss_blog' );
		self::field( 'blog_batch_posts', __( 'Blog posts per batch', 'wormiss' ), 'wormiss_blog' );
		self::field( 'blog_batch_minutes', __( 'Minutes between blog batches', 'wormiss' ), 'wormiss_blog' );
		self::field( 'blog_batch_seconds', __( 'Seconds per blog batch', 'wormiss' ), 'wormiss_blog' );

		$title = __( 'Media categories', 'wormiss' );
		self::section( 'wormiss_media', $title, [ __CLASS__, 'media_intro' ] );
		self::field( 'media_staff_roles', __( 'Staff roles', 'wormiss' ), 'wormiss_media' );
		self::field( 'media_uncategorised_departments', __( 'Who also sees uncategorised images', 'wormiss' ), 'wormiss_media' );

		$title = __( 'Sign-in page', 'wormiss' );
		self::section( 'wormiss_sign_in', $title, [ __CLASS__, 'sign_in_intro' ] );
		self::field( 'signin_page', __( 'Sign-in page', 'wormiss' ), 'wormiss_sign_in' );
		self::field( 'signin_forgot_url', __( 'iMIS forgot-password page', 'wormiss' ), 'wormiss_sign_in' );
		self::field( 'signin_join_url', __( 'iMIS join page', 'wormiss' ), 'wormiss_sign_in' );
		self::field( 'signin_free_accounts', __( 'Free website accounts', 'wormiss' ), 'wormiss_sign_in' );
		self::field( 'signin_confirm_email', __( 'Confirm free accounts\' email', 'wormiss' ), 'wormiss_sign_in' );

		$title = __( 'Email testing', 'wormiss' );
		self::section( 'wormiss_mail_divert', $title, [ __CLASS__, 'mail_divert_intro' ] );
		self::field( 'mail_divert_to', __( 'Divert all emails to', 'wormiss' ), 'wormiss_mail_divert' );
		self::field( 'mail_divert_except', __( 'Do not divert emails to', 'wormiss' ), 'wormiss_mail_divert' );

		$title = __( 'Sync members into WordPress', 'wormiss' );
		self::section( 'wormiss_user_sync', $title, [ __CLASS__, 'user_sync_intro' ] );
		self::field( 'user_sync_enabled', __( 'Sync members daily', 'wormiss' ), 'wormiss_user_sync' );
		self::field( 'user_sync_dry_run', __( 'Member sync dry run (report only)', 'wormiss' ), 'wormiss_user_sync' );
		self::field( 'user_sync_query', __( 'Members query', 'wormiss' ), 'wormiss_user_sync' );
		self::field( 'user_sync_role', __( 'Role for new members', 'wormiss' ), 'wormiss_user_sync' );
		self::field( 'member_type_column', __( 'Member type column', 'wormiss' ), 'wormiss_user_sync' );
		self::field( 'member_join_column', __( 'Join date column', 'wormiss' ), 'wormiss_user_sync' );
		self::field( 'member_expiry_column', __( 'Expiry date column', 'wormiss' ), 'wormiss_user_sync' );
		self::field( 'member_push_enabled', __( 'Send member changes to iMIS', 'wormiss' ), 'wormiss_user_sync' );
		self::field( 'member_push_dry_run', __( 'Member changes dry run (log only)', 'wormiss' ), 'wormiss_user_sync' );

		$title = __( 'Record activity in iMIS', 'wormiss' );
		self::section( 'wormiss_activity', $title, [ __CLASS__, 'activity_intro' ] );
		self::field( 'activity_enabled', __( 'Record activity', 'wormiss' ), 'wormiss_activity' );
		self::field( 'activity_dry_run', __( 'Dry run (log only)', 'wormiss' ), 'wormiss_activity' );
		self::field( 'activity_type', __( 'iMIS activity type', 'wormiss' ), 'wormiss_activity' );
		self::field( 'cpd_ceu_type', __( 'CEU type for CPD points', 'wormiss' ), 'wormiss_activity' );
		self::field( 'record_reads', __( 'Reads of members-only content', 'wormiss' ), 'wormiss_activity' );
		self::field( 'record_downloads', __( 'Tracked file downloads', 'wormiss' ), 'wormiss_activity' );
		self::field( 'record_forms', __( 'Form submissions', 'wormiss' ), 'wormiss_activity' );
		self::field( 'record_purchases', __( 'WooCommerce purchases', 'wormiss' ), 'wormiss_activity' );

		self::section( 'wormiss_log', __( 'Audit log', 'wormiss' ), [ __CLASS__, 'log_intro' ] );
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
	 * The settings tabs' names.
	 *
	 * @return string[] By tab.
	 */
	public static function tab_names(): array {
		return [
			'connection' => __( 'Connection', 'wormiss' ),
			'members'    => __( 'Members', 'wormiss' ),
			'sign-in'    => __( 'Sign-in', 'wormiss' ),
			'events'     => __( 'Events', 'wormiss' ),
			'news'       => __( 'News', 'wormiss' ),
			'pages'      => __( 'Pages', 'wormiss' ),
			'doctors'    => __( 'Doctors', 'wormiss' ),
			'blog'       => __( 'Members\' blog', 'wormiss' ),
			'media'      => __( 'Media', 'wormiss' ),
			'activity'   => __( 'Activity & logs', 'wormiss' ),
		];
	}

	/**
	 * The settings tab being shown.
	 *
	 * @return string
	 */
	public static function current_tab(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only picks the tab to show.
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : '';
		return isset( self::TABS[ $tab ] ) ? $tab : (string) array_key_first( self::TABS );
	}

	/**
	 * The settings "page" a section is drawn on: one per tab, so each tab shows (and saves)
	 * only its own sections. Settings in other tabs keep their saved values.
	 *
	 * @param string $section Section id.
	 * @return string
	 */
	public static function section_page( string $section ): string {
		foreach ( self::TABS as $tab => $sections ) {
			if ( in_array( $section, $sections, true ) ) {
				return self::PAGE . '-' . $tab;
			}
		}
		return self::PAGE;
	}

	/**
	 * Add a settings section, on its tab.
	 *
	 * @param string   $id    Section id.
	 * @param string   $title Heading.
	 * @param callable $intro Intro text.
	 * @return void
	 */
	private static function section( string $id, string $title, callable $intro ): void {
		add_settings_section( $id, $title, $intro, self::section_page( $id ) );
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
			self::section_page( $section ),
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
		echo '<input type="hidden" name="' . esc_attr( Wormiss_Settings::OPTION ) . '[events_section]" value="1" />';
		echo '<p>'
			. esc_html__( 'The Upcoming iMIS events block lists events from the iMIS Events API, soonest first.', 'wormiss' )
			. ' ' . esc_html__( 'The service account needs read access to events.', 'wormiss' )
			. ' ' . esc_html__( 'Templates and unpublished events are left out.', 'wormiss' )
			. '</p>';
	}

	/**
	 * Intro text for the event sending section (and the marker that its checkboxes were shown).
	 *
	 * @return void
	 */
	public static function event_sync_intro(): void {
		echo '<input type="hidden" name="' . esc_attr( Wormiss_Settings::OPTION ) . '[event_sync_section]" value="1" />';
		echo '<p>'
			. esc_html__( 'When an editor publishes or updates an Event, it is sent to iMIS in the background.', 'wormiss' )
			. ' ' . esc_html__( 'A new event is a copy of the template event (prices, registration options and sessions),', 'wormiss' )
			. ' ' . esc_html__( 'with the title, description, dates, place, online link and capacity from WordPress.', 'wormiss' )
			. ' ' . esc_html__( 'The service account needs rights to create and edit events.', 'wormiss' )
			. ' ' . esc_html__( 'Keep the dry run on until it works: iMIS only checks the event, and nothing is created.', 'wormiss' )
			. '</p>';
	}

	/**
	 * Intro text for the event import section (and the marker that its checkbox was shown).
	 *
	 * @return void
	 */
	public static function event_import_intro(): void {
		echo '<input type="hidden" name="' . esc_attr( Wormiss_Settings::OPTION ) . '[event_import_section]" value="1" />';
		echo '<p>'
			. esc_html__( 'Once a day, each upcoming iMIS event (the same ones the events block lists) gets an Event page', 'wormiss' )
			. ' ' . esc_html__( 'linked to it, with its iMIS description, image and event category, published or as a draft.', 'wormiss' )
			. ' ' . esc_html__( 'Title and description follow iMIS; dates, place and places left always come from iMIS.', 'wormiss' )
			. ' ' . esc_html__( 'Nothing is changed in iMIS, and pages are never removed.', 'wormiss' )
			. '</p>';
	}

	/**
	 * Intro text for the news import section (and the marker that its checkboxes were shown).
	 *
	 * @return void
	 */
	public static function news_import_intro(): void {
		echo '<input type="hidden" name="' . esc_attr( Wormiss_Settings::OPTION ) . '[news_import_section]" value="1" />';
		echo '<p>'
			. esc_html__( 'Once a day, each news article the query lists becomes a post in the category below, with its', 'wormiss' )
			. ' ' . esc_html__( 'iMIS tags, publication date, summary, full text (formatting tidied) and images copied into the', 'wormiss' )
			. ' ' . esc_html__( 'Media Library. Members-only articles stay members-only. Later imports update the post unless', 'wormiss' )
			. ' ' . esc_html__( '"Stop updating from iMIS" is ticked on it. Nothing is changed in iMIS; posts are never removed.', 'wormiss' )
			. '</p>';
	}

	/**
	 * Intro text for the page import section (and the marker that its checkboxes were shown).
	 *
	 * @return void
	 */
	public static function page_import_intro(): void {
		echo '<input type="hidden" name="' . esc_attr( Wormiss_Settings::OPTION ) . '[page_import_section]" value="1" />';
		echo '<p>'
			. esc_html__( 'Once a day, each navigation item the query lists becomes a WordPress page, nested under its', 'wormiss' )
			. ' ' . esc_html__( 'parent as in iMIS, with the page content (formatting tidied, images copied into the Media Library). A page whose iMIS content is made', 'wormiss' )
			. ' ' . esc_html__( 'of iParts (a form, a map, a cart) has no portable HTML, so it is imported as an empty page in', 'wormiss' )
			. ' ' . esc_html__( 'the right place for an editor to fill in. Later imports update the page unless "Stop updating', 'wormiss' )
			. ' ' . esc_html__( 'from iMIS" is ticked on it. Nothing is changed in iMIS; pages are never removed.', 'wormiss' )
			. '</p>';
	}

	/**
	 * Intro text for the media categories section (and the marker that its fields were shown).
	 *
	 * @return void
	 */
	public static function media_intro(): void {
		echo '<input type="hidden" name="' . esc_attr( Wormiss_Settings::OPTION ) . '[media_section]" value="1" />';
		echo '<p>'
			. esc_html__( 'Media categories organise the Media Library, and who may see and use which images is controlled', 'wormiss' )
			. ' ' . esc_html__( 'by the department-by-category matrix below. A non-administrator sees images they uploaded plus', 'wormiss' )
			. ' ' . esc_html__( 'images in categories allowed to their departments; administrators see everything.', 'wormiss' )
			. '</p>';
	}

	/**
	 * Intro text for the members' blog section (and the marker that its checkboxes were shown).
	 *
	 * @return void
	 */
	public static function blog_intro(): void {
		echo '<input type="hidden" name="' . esc_attr( Wormiss_Settings::OPTION ) . '[blog_section]" value="1" />';
		echo '<p>'
			. esc_html__( 'The members\' blog (Members\' blog menu, at /blogs/). Readers see full posts, comments and, from', 'wormiss' )
			. ' ' . esc_html__( 'its reveal time, the solution; everyone else sees the intro and a sign-in message. Staff (editors', 'wormiss' )
			. ' ' . esc_html__( 'and administrators) can always read. The import copies every published iMIS (Smart Blogs) post', 'wormiss' )
			. ' ' . esc_html__( 'with its comments and pictures, in small batches; no CPD is recorded for imported activity and', 'wormiss' )
			. ' ' . esc_html__( 'nothing is changed in iMIS.', 'wormiss' )
			. '</p>';
	}

	/**
	 * Intro text for the email testing section.
	 *
	 * @return void
	 */
	public static function mail_divert_intro(): void {
		echo '<p>'
			. esc_html__( 'Like Moodle\'s "divert all emails": while testing, send every email to yourself instead of the people it is for.', 'wormiss' )
			. ' ' . esc_html__( 'A warning shows across the admin screens while it is on. On a test copy of the site, set it in wp-config.php', 'wormiss' )
			. ' ' . esc_html__( 'instead (WORMISS_DIVERT_EMAILS_TO and WORMISS_DIVERT_EMAILS_EXCEPT), so it cannot be turned off by accident.', 'wormiss' )
			. '</p>';
	}

	/**
	 * Intro text for the sign-in page section.
	 *
	 * @return void
	 */
	public static function sign_in_intro(): void {
		echo '<input type="hidden" name="' . esc_attr( Wormiss_Settings::OPTION ) . '[signin_section]" value="1" />';
		echo '<p id="wormiss-sign-in">'
			. esc_html__( 'A sign-in page on this site, so members never see the server\'s own login. Members sign in with their', 'wormiss' )
			. ' ' . esc_html__( 'iMIS ID (e.g. 20022) or email and their iMIS password.', 'wormiss' )
			. '</p>';
	}

	/**
	 * Intro text for the member sync section (and the marker that its checkboxes were shown).
	 *
	 * @return void
	 */
	public static function user_sync_intro(): void {
		echo '<input type="hidden" name="' . esc_attr( Wormiss_Settings::OPTION ) . '[user_sync_section]" value="1" />';
		echo '<p>'
			. esc_html__( 'Once a day, members from the query below are created or updated as WordPress users', 'wormiss' )
			. ' ' . esc_html__( '(login = iMIS ID; no emails are sent). Members whose status is not one of the Current', 'wormiss' )
			. ' ' . esc_html__( 'statuses above are blocked from signing in until it is current again. Staff accounts are', 'wormiss' )
			. ' ' . esc_html__( 'never changed. The query returns ID, Email, FirstName, LastName and Status, with no prompts.', 'wormiss' )
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
	 * The audit log section: marks it, as its boxes are not submitted when unticked.
	 *
	 * @return void
	 */
	public static function log_intro(): void {
		echo '<input type="hidden" name="' . esc_attr( Wormiss_Settings::OPTION ) . '[log_section]" value="1" />';
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
				if ( 'allowed_queries' === $key ) {
					self::query_rules_table( $queries );
				}
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
			case 'event_places_query':
				printf(
					'<input type="text" class="regular-text code" id="%1$s" name="%2$s" value="%3$s" />',
					esc_attr( $id ),
					esc_attr( $name ),
					esc_attr( (string) $value )
				);
				echo '<p class="description">'
					. esc_html__( 'Shows "12 of 30 places left" or "Full" on event pages.', 'wormiss' )
					. ' ' . esc_html__( 'An IQA path with one prompt, the event ID, returning one row per place taken', 'wormiss' )
					. ' ' . esc_html__( '(or a Registered column). Leave out cancelled and waitlisted registrations.', 'wormiss' )
					. ' ' . esc_html__( 'A capacity of 1000 or more shows "Places available". Blank shows no places.', 'wormiss' )
					. '</p>';
				return;
			case 'event_import_since':
				printf(
					'<input type="date" id="%1$s" name="%2$s" value="%3$s" max="%4$s" />',
					esc_attr( $id ),
					esc_attr( $name ),
					esc_attr( (string) $value ),
					esc_attr( gmdate( 'Y-m-d' ) )
				);
				echo '<p class="description">'
					. esc_html__( 'For historical records: finished events that started on or after this date are imported too,', 'wormiss' )
					. ' ' . esc_html__( 'in small batches (5 pages, 2 images) every 2 minutes in the background. Blank imports upcoming events only.', 'wormiss' )
					. '</p>';
				return;
			case 'signin_page':
				wp_dropdown_pages(
					[
						'name'              => esc_attr( $name ),
						'id'                => esc_attr( $id ),
						'selected'          => (int) $value,
						'show_option_none'  => esc_html__( 'WordPress\'s own sign-in', 'wormiss' ),
						'option_none_value' => '0',
					]
				);
				echo '<p class="description">'
					. esc_html__( 'Every "Sign in" link and redirect (including the members\' blog) goes to this page. Members sign in with', 'wormiss' )
					. ' ' . esc_html__( 'their iMIS ID or email and iMIS password; staff and other website accounts with their website password.', 'wormiss' )
					. '</p>';
				if ( ! Wormiss_Sign_In::page_id() ) {
					$url = wp_nonce_url( admin_url( 'admin-post.php?action=wormiss_create_sign_in_page' ), 'wormiss_create_sign_in_page' );
					echo '<p><a class="button" href="' . esc_url( $url ) . '">' . esc_html__( 'Create the sign-in page', 'wormiss' ) . '</a></p>';
				}
				return;
			case 'mail_divert_to':
				$locked = defined( Wormiss_Mail_Divert::CONSTANT_TO );
				printf(
					'<input type="email" class="regular-text" id="%1$s" name="%2$s" value="%3$s" placeholder="you@example.com" %4$s />',
					esc_attr( $id ),
					esc_attr( $name ),
					esc_attr( $locked ? Wormiss_Mail_Divert::to() : (string) $value ),
					disabled( $locked, true, false )
				);
				echo '<p class="description">' . ( $locked
					? esc_html__( 'Set in wp-config.php (WORMISS_DIVERT_EMAILS_TO).', 'wormiss' )
					: esc_html__( 'Every email this site sends goes here instead, its subject naming who it was for. Blank: emails go out as normal.', 'wormiss' ) )
					. '</p>';
				return;
			case 'mail_divert_except':
				$locked = defined( Wormiss_Mail_Divert::CONSTANT_EXCEPT );
				printf(
					'<textarea class="large-text code" rows="3" id="%1$s" name="%2$s" placeholder="@example.org, tester@example.com" %4$s>%3$s</textarea>',
					esc_attr( $id ),
					esc_attr( $name ),
					esc_textarea( $locked ? implode( ', ', Wormiss_Mail_Divert::exceptions() ) : (string) $value ),
					disabled( $locked, true, false )
				);
				echo '<p class="description">' . ( $locked
					? esc_html__( 'Set in wp-config.php (WORMISS_DIVERT_EMAILS_EXCEPT).', 'wormiss' )
					: esc_html__( 'Addresses (tester@example.com) or whole domains (@example.org) that still get their emails, separated by commas or new lines. An email sent to anyone else as well comes to you alone.', 'wormiss' ) )
					. '</p>';
				return;
			case 'signin_free_accounts':
			case 'signin_confirm_email':
				printf(
					'<label><input type="checkbox" id="%1$s" name="%2$s" value="1" %3$s /> %4$s</label>',
					esc_attr( $id ),
					esc_attr( $name ),
					checked( (bool) $value, true, false ),
					'signin_free_accounts' === $key
						? esc_html__( 'Offer a free website account (for events and news, not members\' content) beside the sign-in form', 'wormiss' )
						: esc_html__( 'Free accounts work only once their owner opens a link emailed to them (recommended)', 'wormiss' )
				);
				return;
			case 'signin_forgot_url':
			case 'signin_join_url':
				printf(
					'<input type="url" class="regular-text code" id="%1$s" name="%2$s" value="%3$s" placeholder="https://" />',
					esc_attr( $id ),
					esc_attr( $name ),
					esc_attr( (string) $value )
				);
				echo '<p class="description">' . ( 'signin_forgot_url' === $key
					? esc_html__( 'Where "Forgot your password?" goes: the iMIS page for resetting a password. Blank hides the link.', 'wormiss' )
					: esc_html__( 'Where "Become a member" goes: the iMIS page for joining. Blank hides the button.', 'wormiss' ) ) . '</p>';
				return;
			case 'blog_reader_roles':
				$chosen = array_map( 'strval', (array) $value );
				foreach ( wp_roles()->get_names() as $role => $label ) {
					printf(
						'<label style="display:block"><input type="checkbox" name="%1$s[]" value="%2$s" %3$s /> %4$s</label>',
						esc_attr( $name ),
						esc_attr( (string) $role ),
						checked( in_array( (string) $role, $chosen, true ), true, false ),
						esc_html( translate_user_role( $label ) )
					);
				}
				echo '<p class="description">'
					. esc_html__( 'Tick the roles (e.g. a paid membership role) that may read full blog posts and comment.', 'wormiss' )
					. ' ' . esc_html__( 'None ticked: only staff can read; everyone else sees intros.', 'wormiss' ) . '</p>';
				return;
			case 'blog_media_category':
				$current = (int) $value;
				printf( '<select name="%1$s" id="%1$s">', esc_attr( $name ) );
				printf(
					'<option value="0" %1$s>%2$s</option>',
					selected( 0, $current, false ),
					esc_html__( '— None (leave imported pictures uncategorised) —', 'wormiss' )
				);
				foreach ( Wormiss_Media_Categories::term_choices() as $choice ) {
					printf(
						'<option value="%1$d" %2$s>%3$s</option>',
						(int) $choice['id'],
						selected( (int) $choice['id'], $current, false ),
						esc_html( $choice['name'] )
					);
				}
				echo '</select>';
				echo '<p class="description">'
					. esc_html__( 'Imported blog pictures are filed into this media category.', 'wormiss' )
					. ' ' . esc_html__( 'Flag that category as protected (in the media library) to serve members\' blog pictures from the private store.', 'wormiss' )
					. '</p>';
				return;
			case 'media_staff_roles':
				$chosen = array_map( 'strval', (array) $value );
				foreach ( wp_roles()->get_names() as $role => $label ) {
					printf(
						'<label style="display:block"><input type="checkbox" name="%1$s[]" value="%2$s" %3$s /> %4$s</label>',
						esc_attr( $name ),
						esc_attr( (string) $role ),
						checked( in_array( (string) $role, $chosen, true ), true, false ),
						esc_html( translate_user_role( $label ) )
					);
				}
				echo '<p class="description">'
					. esc_html__( 'Roles that count as staff: their uploads are not auto-filed into "End-user images".', 'wormiss' )
					. ' ' . esc_html__( 'None ticked: administrators and the Wormiss Staff and Manager roles. Administrators are always staff.', 'wormiss' )
					. '</p>';
				return;
			case 'media_uncategorised_departments':
				$chosen = array_map( 'strval', (array) $value );
				foreach ( Wormiss_Roles::departments() as $slug => $label ) {
					printf(
						'<label style="display:block"><input type="checkbox" name="%1$s[]" value="%2$s" %3$s /> %4$s</label>',
						esc_attr( $name ),
						esc_attr( (string) $slug ),
						checked( in_array( (string) $slug, $chosen, true ), true, false ),
						esc_html( $label )
					);
				}
				echo '<p class="description">'
					. esc_html__( 'Departments whose members also see images with no category.', 'wormiss' )
					. ' ' . esc_html__( 'None ticked: uncategorised images are visible to their uploader and administrators only.', 'wormiss' )
					. '</p>';
				return;
			case 'news_import_author':
				wp_dropdown_users(
					[
						'name'              => $name,
						'id'                => $id,
						'selected'          => (int) $value,
						'capability'        => [ 'edit_posts' ],
						'show_option_none'  => __( 'First administrator', 'wormiss' ),
						'option_none_value' => 0,
					]
				);
				echo '<p class="description">'
					. esc_html__( 'Imported posts are by this person, including those made in the background.', 'wormiss' )
					. '</p>';
				return;
			case 'news_import_category':
				printf(
					'<input type="text" class="regular-text" id="%1$s" name="%2$s" value="%3$s" />',
					esc_attr( $id ),
					esc_attr( $name ),
					esc_attr( (string) $value )
				);
				return;
			case 'news_import_query':
				printf(
					'<input type="text" class="regular-text code" id="%1$s" name="%2$s" value="%3$s" />',
					esc_attr( $id ),
					esc_attr( $name ),
					esc_attr( (string) $value )
				);
				echo '<p class="description">'
					. esc_html__( 'An IQA path. One row per article and tag, with Document Version Key, and ideally Alternate', 'wormiss' )
					. ' ' . esc_html__( 'Name, Document Description, Publication Date, Tag Name, Thumbnail Image and Updated On. No prompts.', 'wormiss' )
					. '</p>';
				return;
			case 'page_import_query':
				printf(
					'<input type="text" class="regular-text code" id="%1$s" name="%2$s" value="%3$s" />',
					esc_attr( $id ),
					esc_attr( $name ),
					esc_attr( (string) $value )
				);
				echo '<p class="description">'
					. esc_html__( 'An IQA path listing the website\'s navigation items (DocumentTypeCode NAV), one row per', 'wormiss' )
					. ' ' . esc_html__( 'item, with Hierarchy Key and Document Version Key, and ideally Alternate Name. No prompts.', 'wormiss' )
					. '</p>';
				return;
			case 'page_import_menu_name':
				printf(
					'<input type="text" class="regular-text" id="%1$s" name="%2$s" value="%3$s" />',
					esc_attr( $id ),
					esc_attr( $name ),
					esc_attr( (string) $value )
				);
				echo '<p class="description">'
					. esc_html__( 'The WordPress menu built from the imported pages (made if missing). Default: "iMIS site".', 'wormiss' )
					. '</p>';
				return;
			case 'user_sync_query':
				printf(
					'<input type="text" class="regular-text code" id="%1$s" name="%2$s" value="%3$s" />',
					esc_attr( $id ),
					esc_attr( $name ),
					esc_attr( (string) $value )
				);
				echo '<p class="description">'
					. esc_html__( 'An IQA path, e.g. $/_SCCA/Membership/WebsiteMembers. Never shown on pages.', 'wormiss' ) . '</p>';
				return;
			case 'member_type_column':
			case 'member_join_column':
			case 'member_expiry_column':
				printf(
					'<input type="text" class="regular-text code" id="%1$s" name="%2$s" value="%3$s" />',
					esc_attr( $id ),
					esc_attr( $name ),
					esc_attr( (string) $value )
				);
				if ( 'member_expiry_column' === $key ) {
					echo '<p class="description">'
						. esc_html__( 'Columns in the members query. Each member\'s type, status (the Status column), join date and', 'wormiss' )
						. ' ' . esc_html__( 'expiry date are copied onto their account, for the My membership block and their profile.', 'wormiss' )
						. ' ' . esc_html__( 'Leave one blank to not copy it.', 'wormiss' ) . '</p>';
				}
				return;
			case 'user_sync_role':
				printf( '<select id="%1$s" name="%2$s">', esc_attr( $id ), esc_attr( $name ) );
				foreach ( wp_roles()->get_names() as $role => $label ) {
					if ( Wormiss_Settings::is_member_role( (string) $role ) ) {
						printf(
							'<option value="%1$s" %2$s>%3$s</option>',
							esc_attr( (string) $role ),
							selected( $value, $role, false ),
							esc_html( translate_user_role( $label ) )
						);
					}
				}
				echo '</select>';
				return;
			case 'event_template_id':
				printf(
					'<input type="text" class="regular-text code" id="%1$s" name="%2$s" value="%3$s" placeholder="TEMPLATE" />',
					esc_attr( $id ),
					esc_attr( $name ),
					esc_attr( (string) $value )
				);
				echo '<p class="description">'
					. esc_html__( 'The event code of an iMIS event template set up with your usual prices and options.', 'wormiss' )
					. ' ' . esc_html__( 'Used for events with no event category, or whose category has no template (Events > Event categories).', 'wormiss' )
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
			case 'cpd_ceu_type':
				printf(
					'<input type="text" class="regular-text code" id="%1$s" name="%2$s" value="%3$s" maxlength="10" placeholder="%4$s" />',
					esc_attr( $id ),
					esc_attr( $name ),
					esc_attr( (string) $value ),
					esc_attr( 'activity_type' === $key ? 'WEBACT' : 'CPD' )
				);
				echo '<p class="description">';
				if ( 'activity_type' === $key ) {
					echo esc_html__( 'Exactly as the Type code in iMIS (Settings > Contacts > Activity types), up to 10 characters.', 'wormiss' );
				} else {
					echo esc_html__( 'Points from Mark as done go in the activity\'s Quantity/CEU, with this CEU type.', 'wormiss' )
						. ' ' . esc_html__( 'Use a CEU type set up in iMIS. Blank leaves the CEU type empty.', 'wormiss' );
				}
				echo '</p>';
				return;
			case 'log_enabled':
			case 'log_capture':
			case 'activity_enabled':
			case 'activity_dry_run':
			case 'record_reads':
			case 'record_downloads':
			case 'record_forms':
			case 'record_purchases':
			case 'event_import_enabled':
			case 'event_import_publish':
			case 'news_import_enabled':
			case 'news_import_dry_run':
			case 'news_import_publish':
			case 'news_import_incremental':
			case 'page_import_enabled':
			case 'page_import_dry_run':
			case 'page_import_publish':
			case 'page_import_menu':
			case 'blog_import_enabled':
			case 'blog_import_dry_run':
			case 'blog_import_incremental':
			case 'user_sync_enabled':
			case 'user_sync_dry_run':
			case 'member_push_enabled':
			case 'member_push_dry_run':
			case 'event_registration_enabled':
			case 'event_registration_dry_run':
			case 'event_publish_dates':
			case 'event_cpd':
			case 'event_sync_enabled':
			case 'event_sync_dry_run':
			case 'event_cancel_on_trash':
				printf(
					'<input type="checkbox" id="%1$s" name="%2$s" value="1" %3$s />',
					esc_attr( $id ),
					esc_attr( $name ),
					checked( (bool) $value, true, false )
				);
				if ( 'event_registration_enabled' === $key ) {
					echo ' <span class="description">'
						. esc_html__( 'Free events (registration required, no fee) get Register and Cancel buttons for', 'wormiss' )
						. ' ' . esc_html__( 'signed-in members. Paid events still link to iMIS.', 'wormiss' )
						. '</span>';
				}
				if ( 'event_cpd' === $key ) {
					echo ' <span class="description">'
						. esc_html__( 'Event pages show the hours from each event\'s CPD Hours panel in iMIS (EventCPD), added up', 'wormiss' )
						. ' ' . esc_html__( 'per category, with its program level requirements.', 'wormiss' )
						. '</span>';
				}
				if ( 'event_publish_dates' === $key ) {
					echo ' <span class="description">'
						. esc_html__( 'Only list events between their publish date and expiry date in iMIS.', 'wormiss' )
						. '</span>';
				}
				if ( 'record_purchases' === $key ) {
					echo ' <span class="description">'
						. esc_html__( 'One activity per paid order, with its total in Amount. Orders are not created in iMIS.', 'wormiss' )
						. '</span>';
				}
				if ( 'event_import_publish' === $key ) {
					echo ' <span class="description">'
						. esc_html__( 'Imported pages go live with their iMIS description, image and event category. Off: drafts.', 'wormiss' )
						. ' ' . esc_html__( 'Drafts made earlier are published too; a page an editor unpublishes stays a draft.', 'wormiss' )
						. '</span>';
				}
				if ( 'news_import_publish' === $key ) {
					echo ' <span class="description">'
						. esc_html__( 'New imported posts go live. Off: drafts. Posts already made keep their status.', 'wormiss' )
						. '</span>';
				}
				if ( 'news_import_dry_run' === $key ) {
					echo ' <span class="description">'
						. esc_html__( 'Reads iMIS and reports which posts would be made or updated, but saves nothing.', 'wormiss' )
						. '</span>';
				}
				if ( 'news_import_incremental' === $key ) {
					echo ' <span class="description">'
						. esc_html__( 'Skip the per-article read for articles the query reports unchanged (needs an Updated On column', 'wormiss' )
						. ' ' . esc_html__( 'in the query). A full re-read runs weekly. Off, or with no such column, every article is re-read every import.', 'wormiss' )
						. '</span>';
				}
				if ( 'page_import_publish' === $key ) {
					echo ' <span class="description">'
						. esc_html__( 'New imported pages go live. Off: drafts. Pages already made keep their status.', 'wormiss' )
						. '</span>';
				}
				if ( 'page_import_dry_run' === $key ) {
					echo ' <span class="description">'
						. esc_html__( 'Reads iMIS and reports which pages would be made or updated, but saves nothing.', 'wormiss' )
						. '</span>';
				}
				if ( 'page_import_enabled' === $key ) {
					echo ' <span class="description">'
						. esc_html__( 'Once a day, each navigation item the query lists becomes a page, nested under its parent', 'wormiss' )
						. ' ' . esc_html__( 'as in iMIS. Pages whose iMIS content is made of iParts are imported empty, in place.', 'wormiss' )
						. '</span>';
				}
				if ( 'page_import_menu' === $key ) {
					echo ' <span class="description">'
						. esc_html__( 'After each import, make or update a navigation menu (named below) mirroring the iMIS tree:', 'wormiss' )
						. ' ' . esc_html__( 'one entry per imported, published page, nested and ordered as in iMIS. On a classic theme this is a', 'wormiss' )
						. ' ' . esc_html__( 'menu you assign to a theme location once under Appearance > Menus; on a block theme it is a Navigation', 'wormiss' )
						. ' ' . esc_html__( 'you point the Navigation block at once in the Site Editor. The import manages it, so avoid editing it by hand.', 'wormiss' )
						. '</span>';
				}
				if ( 'blog_import_incremental' === $key ) {
					echo ' <span class="description">'
						. esc_html__( 'Skip the per-post read for posts iMIS reports unchanged since the last import (much faster on a', 'wormiss' )
						. ' ' . esc_html__( 'large blog). A full re-read runs weekly to catch comment edits. Off: every post is re-read every import.', 'wormiss' )
						. '</span>';
				}
				if ( 'member_push_enabled' === $key ) {
					echo ' <span class="description">'
						. esc_html__( 'When a member changes their first name, last name or email in WordPress, it is saved on', 'wormiss' )
						. ' ' . esc_html__( 'their iMIS contact record in the background. Staff accounts are never sent.', 'wormiss' )
						. '</span>';
				}
				if ( 'event_cancel_on_trash' === $key ) {
					echo ' <span class="description">'
						. esc_html__( 'Off by default: cancelling affects registrants. Registrations stay in iMIS.', 'wormiss' )
						. '</span>';
				}
				if ( 'log_capture' === $key ) {
					echo ' <span class="description">'
						. esc_html__( 'For troubleshooting only: bodies can hold members\' personal details.', 'wormiss' )
						. ' ' . esc_html__( 'Passwords and tokens are always removed.', 'wormiss' ) . '</span>';
				}
				return;
			case 'blog_batch_posts':
			case 'blog_batch_minutes':
				$posts = 'blog_batch_posts' === $key;
				printf(
					'<input type="number" class="small-text" id="%1$s" name="%2$s" value="%3$d" min="1" max="%4$d" /> <span class="description">%5$s</span>',
					esc_attr( $id ),
					esc_attr( $name ),
					(int) $value,
					$posts ? (int) Wormiss_Blog_Import::BATCH_MAX : (int) Wormiss_Blog_Import::GAP_MAX,
					esc_html(
						$posts
							? __( 'Up to 20. Each batch also stops when its seconds (below) are up, so a busy or slow site does less.', 'wormiss' )
							: __( '1 to 10. Lower is faster; raise it if the site slows down while importing.', 'wormiss' )
					)
				);
				return;
			case 'blog_batch_seconds':
				printf(
					'<input type="number" class="small-text" id="%1$s" name="%2$s" value="%3$d" min="%4$d" max="%5$d" /> <span class="description">%6$s</span>',
					esc_attr( $id ),
					esc_attr( $name ),
					(int) $value,
					(int) Wormiss_Blog_Import::SECONDS_MIN,
					(int) Wormiss_Blog_Import::SECONDS_MAX,
					esc_html(
						sprintf(
							/* translators: %d: PHP's time limit in seconds. */
							__( '10 to 60. Each batch asks PHP for the time it needs (PHP allows %d seconds a request here); page views keep their limit.', 'wormiss' ),
							(int) ini_get( 'max_execution_time' )
						)
					)
				);
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
		$tab = self::current_tab();
		echo '<div class="wrap"><h1>' . esc_html__( 'Wormiss settings', 'wormiss' ) . '</h1>';
		// Pages outside the Settings menu show "Settings saved" and errors themselves. Not filtered
		// by option: WordPress files its "Settings saved" notice under "general".
		settings_errors();
		self::connection_result_notice();
		echo '<nav class="nav-tab-wrapper wp-clearfix" aria-label="' . esc_attr__( 'Settings', 'wormiss' ) . '">';
		foreach ( self::tab_names() as $key => $name ) {
			$class   = 'nav-tab' . ( $key === $tab ? ' nav-tab-active' : '' );
			$current = $key === $tab ? ' aria-current="page"' : '';
			echo '<a href="' . esc_url( self::settings_url( $key ) ) . '" class="' . esc_attr( $class ) . '"' . $current . '>' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Fixed markup.
				. esc_html( $name ) . '</a>';
		}
		echo '</nav>';
		// Only this tab's settings are sent; the others keep their saved values.
		echo '<form action="options.php" method="post">';
		settings_fields( self::PAGE );
		do_settings_sections( self::PAGE . '-' . $tab );
		submit_button();
		echo '</form>';

		switch ( $tab ) {
			case 'connection':
				self::render_test_connection();
				break;
			case 'members':
				self::render_run_now(
					'wormiss-user-sync',
					__( 'Sync members now', 'wormiss' ),
					Wormiss_User_Sync::summary(),
					__( 'The member sync has not run yet.', 'wormiss' ),
					Wormiss_User_Sync::notes(),
					'wormiss_user_sync_now',
					__( 'Run the member sync now', 'wormiss' )
				);
				self::render_access_rules();
				break;
			case 'doctors':
				Wormiss_Doctor_Sync::render_admin_section();
				break;
			case 'sign-in':
				Wormiss_Sign_In_Check::render();
				break;
			case 'events':
				self::render_event_push();
				self::render_run_now(
					'wormiss-event-import',
					__( 'Import iMIS events now', 'wormiss' ),
					Wormiss_Event_Import::summary(),
					__( 'The event import has not run yet.', 'wormiss' ),
					Wormiss_Event_Import::notes(),
					'wormiss_event_import_now',
					__( 'Import iMIS events now', 'wormiss' )
				);
				break;
			case 'news':
				self::render_run_now(
					'wormiss-news-import',
					__( 'Import iMIS news now', 'wormiss' ),
					Wormiss_News_Import::summary(),
					__( 'The news import has not run yet.', 'wormiss' ),
					Wormiss_News_Import::notes(),
					'wormiss_news_import_now',
					__( 'Import iMIS news now', 'wormiss' )
				);
				Wormiss_Content_Check::render();
				break;
			case 'pages':
				self::render_run_now(
					'wormiss-page-import',
					__( 'Import iMIS pages now', 'wormiss' ),
					Wormiss_Page_Import::summary(),
					__( 'The page import has not run yet.', 'wormiss' ),
					Wormiss_Page_Import::notes(),
					'wormiss_page_import_now',
					__( 'Import iMIS pages now', 'wormiss' )
				);
				break;
			case 'blog':
				self::render_run_now(
					'wormiss-blog-import',
					__( 'Import the iMIS blog now', 'wormiss' ),
					Wormiss_Blog_Import::summary(),
					__( 'The blog import has not run yet.', 'wormiss' ),
					Wormiss_Blog_Import::notes(),
					'wormiss_blog_import_now',
					__( 'Import the iMIS blog now', 'wormiss' )
				);
				Wormiss_Blog_Check::render();
				break;
			case 'media':
				self::render_media_matrix();
				self::render_media_bulk();
				self::render_media_migrate();
				break;
		}

		$log = self::log_url();
		echo '<p><a href="' . esc_url( $log ) . '">' . esc_html__( 'Open the audit log', 'wormiss' ) . '</a></p></div>';
	}

	/**
	 * The department x category access matrix editor (its own form, below the media settings).
	 *
	 * @return void
	 */
	public static function render_media_matrix(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only shows a "saved" notice.
		if ( isset( $_GET['wormiss_matrix_saved'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>'
				. esc_html__( 'Access matrix saved.', 'wormiss' ) . '</p></div>';
		}
		$departments = Wormiss_Roles::departments();
		$categories  = Wormiss_Media_Categories::term_choices();
		$matrix      = Wormiss_Media_Access::matrix();
		echo '<hr /><h2>' . esc_html__( 'Department access to categories', 'wormiss' ) . '</h2>';
		echo '<p class="description">'
			. esc_html__( 'Tick the media categories each department may see and use. Administrators always see everything.', 'wormiss' )
			. '</p>';
		if ( ! $categories ) {
			echo '<p>' . esc_html__( 'No media categories yet. Add some under Media > Media categories.', 'wormiss' ) . '</p>';
			return;
		}
		echo '<form action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" method="post">';
		wp_nonce_field( 'wormiss_save_media_matrix', 'wormiss_media_matrix_nonce' );
		echo '<input type="hidden" name="action" value="wormiss_save_media_matrix" />';
		foreach ( $departments as $slug => $label ) {
			$allowed = isset( $matrix[ $slug ] ) ? array_map( 'intval', $matrix[ $slug ] ) : [];
			echo '<fieldset style="margin:1em 0;border:1px solid #c3c4c7;padding:.5em 1em;">';
			echo '<legend style="font-weight:600;">' . esc_html( $label ) . '</legend>';
			foreach ( $categories as $choice ) {
				$term = (int) $choice['id'];
				printf(
					'<label style="display:inline-block;min-width:16em;margin:2px 1em 2px 0;"><input type="checkbox" name="matrix[%1$s][]" value="%2$d" %3$s /> %4$s</label>',
					esc_attr( (string) $slug ),
					absint( $term ),
					checked( in_array( $term, $allowed, true ), true, false ),
					esc_html( (string) $choice['name'] )
				);
			}
			echo '</fieldset>';
		}
		submit_button( __( 'Save access matrix', 'wormiss' ) );
		echo '</form>';
	}

	/**
	 * The bulk-categorise tool (add a category to many existing images at once).
	 *
	 * @return void
	 */
	public static function render_media_bulk(): void {
		echo '<hr /><h2 id="wormiss-media-bulk">' . esc_html__( 'Bulk categorise existing media', 'wormiss' ) . '</h2>';
		if ( ! Wormiss_Media_Bulk::enabled() ) {
			echo '<p class="description">' . esc_html__( 'Bulk categorising is turned off on this site.', 'wormiss' ) . '</p>';
			return;
		}
		$report = get_option( Wormiss_Media_Bulk::REPORT_OPTION );
		if ( is_array( $report ) && ! Wormiss_Media_Bulk::is_running() ) {
			$term = get_term( (int) $report['category'], Wormiss_Media_Categories::TAXONOMY );
			echo '<p class="description">' . esc_html(
				sprintf(
					/* translators: 1: number of images, 2: category name, 3: date. */
					__( 'Last run: added %1$d images to "%2$s" on %3$s.', 'wormiss' ),
					(int) $report['done'],
					$term instanceof WP_Term ? $term->name : __( '(deleted category)', 'wormiss' ),
					wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $report['time'] )
				)
			) . '</p>';
		}

		$job = Wormiss_Media_Bulk::job();
		if ( $job ) {
			$term = get_term( (int) $job['category'], Wormiss_Media_Categories::TAXONOMY );
			echo '<p>' . esc_html(
				sprintf(
					/* translators: 1: processed, 2: total, 3: category name. */
					__( 'Categorising in the background: %1$d of %2$d done, adding "%3$s". The rest continues automatically.', 'wormiss' ),
					(int) $job['done'],
					(int) $job['total'],
					$term instanceof WP_Term ? $term->name : ''
				)
			) . '</p>';
			echo '<form action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" method="post">';
			wp_nonce_field( 'wormiss_media_bulk_cancel', 'wormiss_media_bulk_nonce' );
			echo '<input type="hidden" name="action" value="wormiss_media_bulk_cancel" />';
			submit_button( __( 'Cancel bulk categorise', 'wormiss' ), 'secondary' );
			echo '</form>';
			return;
		}

		$categories = Wormiss_Media_Categories::term_choices();
		if ( ! $categories ) {
			echo '<p>' . esc_html__( 'No media categories yet. Add some under Media > Media categories.', 'wormiss' ) . '</p>';
			return;
		}
		$uncategorised = Wormiss_Media_Bulk::count( 'uncategorised' );
		$all           = Wormiss_Media_Bulk::count( 'all' );
		echo '<p class="description">'
			. esc_html__( 'Add one category to many existing images at once. It runs in the background in small batches.', 'wormiss' )
			. '</p>';
		echo '<form action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" method="post">';
		wp_nonce_field( 'wormiss_media_bulk_start', 'wormiss_media_bulk_nonce' );
		echo '<input type="hidden" name="action" value="wormiss_media_bulk_start" />';
		echo '<p><label>' . esc_html__( 'Category to add:', 'wormiss' ) . ' ';
		echo '<select name="wormiss_bulk_category">';
		foreach ( $categories as $choice ) {
			printf(
				'<option value="%1$d">%2$s</option>',
				absint( $choice['id'] ),
				esc_html( (string) $choice['name'] )
			);
		}
		echo '</select></label></p>';
		echo '<p>';
		printf(
			'<label style="display:block"><input type="radio" name="wormiss_bulk_scope" value="uncategorised" checked /> %s</label>',
			esc_html(
				sprintf(
					/* translators: %d: number of images. */
					_n( 'Images with no category (%d)', 'Images with no category (%d)', $uncategorised, 'wormiss' ),
					$uncategorised
				)
			)
		);
		printf(
			'<label style="display:block"><input type="radio" name="wormiss_bulk_scope" value="all" /> %s</label>',
			esc_html(
				sprintf(
					/* translators: %d: number of images. */
					_n( 'All media (%d)', 'All media (%d)', $all, 'wormiss' ),
					$all
				)
			)
		);
		echo '</p>';
		echo '<p><label><input type="checkbox" name="wormiss_bulk_confirm" value="1" required /> '
			. esc_html__( 'Yes, add the chosen category to the matching images.', 'wormiss' ) . '</label></p>';
		submit_button( __( 'Start bulk categorise', 'wormiss' ) );
		echo '</form>';
	}

	/**
	 * The "Move protected images into the private store" tool (migrates existing public originals of
	 * images in a protected or locked category).
	 *
	 * @return void
	 */
	public static function render_media_migrate(): void {
		echo '<hr /><h2 id="wormiss-media-migrate">' . esc_html__( 'Move protected images into the private store', 'wormiss' ) . '</h2>';
		if ( ! Wormiss_Media_Migrate::enabled() ) {
			echo '<p class="description">' . esc_html__( 'This tool is turned off on this site.', 'wormiss' ) . '</p>';
			return;
		}
		echo '<p class="description">'
			. esc_html__( 'Flagging a category as protected or lock-to-post gates how its images are linked, but an image already in the library keeps its public original file until it is moved into the private store.', 'wormiss' )
			. ' ' . esc_html__( 'This moves the originals of images already in a protected or locked category out of the public uploads folder. It runs in the background in small batches, and cannot be undone (the files are moved).', 'wormiss' )
			. '</p>';

		$report = get_option( Wormiss_Media_Migrate::REPORT_OPTION );
		if ( is_array( $report ) && ! Wormiss_Media_Migrate::is_running() ) {
			$line = sprintf(
				/* translators: 1: number of images, 2: date. */
				__( 'Last run: moved %1$d images into the private store on %2$s.', 'wormiss' ),
				(int) $report['moved'],
				wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $report['time'] )
			);
			if ( (int) $report['failed'] > 0 ) {
				/* translators: %d: number of images that could not be moved. */
				$line .= ' ' . sprintf( __( '%d could not be moved and their originals are still public.', 'wormiss' ), (int) $report['failed'] );
				$ids   = array_map( 'intval', (array) ( $report['failed_ids'] ?? [] ) );
				if ( $ids ) {
					/* translators: %s: comma-separated list of attachment IDs. */
					$line .= ' ' . sprintf( __( 'Attachment IDs: %s.', 'wormiss' ), implode( ', ', $ids ) );
				}
			}
			echo '<p class="description">' . esc_html( $line ) . '</p>';
		}

		$job = Wormiss_Media_Migrate::job();
		if ( $job ) {
			echo '<p>' . esc_html(
				sprintf(
					/* translators: 1: processed, 2: total. */
					__( 'Moving in the background: %1$d of %2$d done. The rest continues automatically.', 'wormiss' ),
					(int) $job['done'],
					(int) $job['total']
				)
			) . '</p>';
			echo '<form action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" method="post">';
			wp_nonce_field( 'wormiss_media_migrate_cancel', 'wormiss_media_migrate_nonce' );
			echo '<input type="hidden" name="action" value="wormiss_media_migrate_cancel" />';
			submit_button( __( 'Cancel move', 'wormiss' ), 'secondary' );
			echo '</form>';
			return;
		}

		$waiting = Wormiss_Media_Migrate::count();
		if ( 0 === $waiting ) {
			echo '<p>' . esc_html__( 'Nothing to move: every image in a protected or locked category is already in the private store.', 'wormiss' ) . '</p>';
			return;
		}
		if ( Wormiss_Media_Delivery::using_fallback() ) {
			echo '<p class="description"><strong>' . esc_html__( 'Note:', 'wormiss' ) . '</strong> '
				. esc_html__( 'the private store is the wp-content fallback, which is not truly outside the web root. Set WORMISS_PROTECTED_DIR (and the nginx location) first for real protection.', 'wormiss' )
				. '</p>';
		}
		echo '<form action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" method="post">';
		wp_nonce_field( 'wormiss_media_migrate_start', 'wormiss_media_migrate_nonce' );
		echo '<input type="hidden" name="action" value="wormiss_media_migrate_start" />';
		echo '<p><label><input type="checkbox" name="wormiss_migrate_confirm" value="1" required /> '
			. esc_html(
				sprintf(
					/* translators: %d: number of images. */
					_n( 'Yes, move %d image into the private store.', 'Yes, move %d images into the private store.', $waiting, 'wormiss' ),
					$waiting
				)
			) . '</label></p>';
		submit_button( __( 'Move protected images now', 'wormiss' ) );
		echo '</form>';
	}

	/**
	 * Save the department x category access matrix (its own admin-post handler).
	 *
	 * @return void
	 */
	public static function save_media_matrix(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to change the media access matrix.', 'wormiss' ) );
		}
		check_admin_referer( 'wormiss_save_media_matrix', 'wormiss_media_matrix_nonce' );
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.NonceVerification.Missing -- Nonce checked above; each value is cast to int and validated against the taxonomy in Wormiss_Media_Access::sanitize_matrix().
		$raw = isset( $_POST['matrix'] ) && is_array( $_POST['matrix'] ) ? wp_unslash( $_POST['matrix'] ) : [];
		Wormiss_Media_Access::save_matrix( $raw );
		wp_safe_redirect( add_query_arg( 'wormiss_matrix_saved', '1', self::settings_url( 'media' ) ) );
		exit;
	}

	/**
	 * The members-only rules editor: a table of site-wide rules (a category, tag, post type or page
	 * tree made members-only at once), below the member settings. Its own admin-post form.
	 *
	 * @return void
	 */
	public static function render_access_rules(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only shows a "saved" notice.
		if ( isset( $_GET['wormiss_rules_saved'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>'
				. esc_html__( 'Members-only rules saved.', 'wormiss' ) . '</p></div>';
		}
		echo '<hr /><h2>' . esc_html__( 'Members-only rules', 'wormiss' ) . '</h2>';
		echo '<p class="description">'
			. esc_html__( 'Make a whole category, tag, post type or page (with its child pages) members-only at once, instead of ticking each post.', 'wormiss' )
			. ' ' . esc_html__( 'Leave the membership types and statuses blank for "any current member"; otherwise enter a comma-separated list (as on a single post).', 'wormiss' )
			. ' ' . esc_html__( 'A single post\'s own Members only switch always overrides these rules.', 'wormiss' )
			. '</p>';

		$rules = Wormiss_Access_Rules::all();
		echo '<form action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" method="post">';
		wp_nonce_field( 'wormiss_save_access_rules', 'wormiss_access_rules_nonce' );
		echo '<input type="hidden" name="action" value="wormiss_save_access_rules" />';
		echo '<table class="widefat striped" style="max-width:60em;margin:.5em 0;"><thead><tr>';
		echo '<th>' . esc_html__( 'Content', 'wormiss' ) . '</th>';
		echo '<th>' . esc_html__( 'Membership types', 'wormiss' ) . '</th>';
		echo '<th>' . esc_html__( 'Statuses', 'wormiss' ) . '</th>';
		echo '<th>' . esc_html__( 'Remove', 'wormiss' ) . '</th>';
		echo '</tr></thead><tbody>';
		$index = 0;
		foreach ( $rules as $rule ) {
			self::render_access_rule_row( (string) $index, Wormiss_Access_Rules::token( $rule['kind'], $rule['ref'] ), implode( ', ', $rule['types'] ), implode( ', ', $rule['statuses'] ), true );
			++$index;
		}
		// One blank row to add a rule.
		self::render_access_rule_row( 'new', '', '', '', false );
		echo '</tbody></table>';
		submit_button( __( 'Save members-only rules', 'wormiss' ) );
		echo '</form>';
	}

	/**
	 * One row of the members-only rules table.
	 *
	 * @param string $index    Row index (a number, or "new" for the blank add row).
	 * @param string $selected Selected "kind:ref" token.
	 * @param string $types    Comma-separated membership types.
	 * @param string $statuses Comma-separated statuses.
	 * @param bool   $removable Whether to show a remove checkbox.
	 * @return void
	 */
	private static function render_access_rule_row( string $index, string $selected, string $types, string $statuses, bool $removable ): void {
		$name = 'rules[' . $index . ']';
		echo '<tr><td><select name="' . esc_attr( $name . '[target]' ) . '">';
		printf(
			'<option value="" %1$s>%2$s</option>',
			selected( '', $selected, false ),
			esc_html__( '— Choose content —', 'wormiss' )
		);
		echo self::access_target_options( $selected ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built from escaped parts in access_target_options().
		echo '</select></td>';
		printf(
			'<td><input type="text" class="regular-text" name="%1$s" value="%2$s" placeholder="%3$s" /></td>',
			esc_attr( $name . '[types]' ),
			esc_attr( $types ),
			esc_attr__( 'any', 'wormiss' )
		);
		printf(
			'<td><input type="text" class="regular-text" name="%1$s" value="%2$s" placeholder="%3$s" /></td>',
			esc_attr( $name . '[statuses]' ),
			esc_attr( $statuses ),
			esc_attr__( 'default', 'wormiss' )
		);
		echo '<td>';
		if ( $removable ) {
			printf( '<label><input type="checkbox" name="%1$s" value="1" /> %2$s</label>', esc_attr( $name . '[remove]' ), esc_html__( 'Remove', 'wormiss' ) );
		}
		echo '</td></tr>';
	}

	/**
	 * The grouped <optgroup> options for the rule "content" dropdown: categories, tags, post types
	 * and pages. Every part is escaped here.
	 *
	 * @param string $selected Selected "kind:ref" token.
	 * @return string HTML.
	 */
	private static function access_target_options( string $selected ): string {
		$groups = [];

		$categories = get_terms(
			[
				'taxonomy'   => 'category',
				'hide_empty' => false,
			]
		);
		$cat_opts   = [];
		foreach ( is_array( $categories ) ? $categories : [] as $term ) {
			$cat_opts[ Wormiss_Access_Rules::token( 'category', $term->term_id ) ] = $term->name;
		}
		$groups[ __( 'Categories (with sub-categories)', 'wormiss' ) ] = $cat_opts;

		$tags     = get_terms(
			[
				'taxonomy'   => 'post_tag',
				'hide_empty' => false,
			]
		);
		$tag_opts = [];
		foreach ( is_array( $tags ) ? $tags : [] as $term ) {
			$tag_opts[ Wormiss_Access_Rules::token( 'tag', $term->term_id ) ] = $term->name;
		}
		$groups[ __( 'Tags', 'wormiss' ) ] = $tag_opts;

		$type_opts = [];
		foreach ( get_post_types( [ 'show_ui' => true ], 'objects' ) as $type ) {
			if ( in_array( $type->name, [ 'attachment', 'wp_block', 'wp_navigation' ], true ) ) {
				continue;
			}
			$type_opts[ Wormiss_Access_Rules::token( 'post_type', $type->name ) ] = $type->labels->name;
		}
		$groups[ __( 'All of a post type', 'wormiss' ) ] = $type_opts;

		$page_opts = [];
		foreach ( get_pages(
			[
				'sort_column' => 'post_title',
				// Draft/private/future pages too, so a rule can target them and an existing rule's
				// page stays selectable if it is later unpublished.
				'post_status' => [ 'publish', 'pending', 'draft', 'future', 'private' ],
			]
		) as $page ) {
			$page_opts[ Wormiss_Access_Rules::token( 'page_tree', $page->ID ) ] = get_the_title( $page );
		}
		$groups[ __( 'Page and its child pages', 'wormiss' ) ] = $page_opts;

		$html    = '';
		$present = ( '' === $selected );
		foreach ( $groups as $label => $options ) {
			if ( ! $options ) {
				continue;
			}
			$html .= '<optgroup label="' . esc_attr( $label ) . '">';
			foreach ( $options as $value => $text ) {
				if ( $value === $selected ) {
					$present = true;
				}
				$html .= sprintf(
					'<option value="%1$s" %2$s>%3$s</option>',
					esc_attr( $value ),
					selected( $value, $selected, false ),
					esc_html( $text )
				);
			}
			$html .= '</optgroup>';
		}
		// A saved rule whose target is not in the lists above (e.g. a trashed page, or content hidden
		// from the current query) must still appear selected, or saving the form would silently drop
		// the rule and remove an active restriction. Carry it as its own option.
		if ( ! $present ) {
			$token = Wormiss_Access_Rules::parse_token( $selected );
			$label = null === $token ? '' : Wormiss_Access_Rules::label(
				[
					'kind' => $token['kind'],
					'ref'  => $token['ref'],
				]
			);
			$html  = '<optgroup label="' . esc_attr__( 'Current selection', 'wormiss' ) . '">'
				. sprintf(
					'<option value="%1$s" selected>%2$s</option>',
					esc_attr( $selected ),
					esc_html( '' !== $label ? $label : $selected )
				)
				. '</optgroup>' . $html;
		}
		return $html;
	}

	/**
	 * Save the members-only rules (its own admin-post handler).
	 *
	 * @return void
	 */
	public static function save_access_rules(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to change the members-only rules.', 'wormiss' ) );
		}
		check_admin_referer( 'wormiss_save_access_rules', 'wormiss_access_rules_nonce' );
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.NonceVerification.Missing -- Nonce checked above; each row is validated in Wormiss_Access_Rules::sanitize() (kind against a fixed list, ref against existing content).
		$raw  = isset( $_POST['rules'] ) && is_array( $_POST['rules'] ) ? wp_unslash( $_POST['rules'] ) : [];
		$rows = [];
		foreach ( $raw as $row ) {
			if ( ! is_array( $row ) || ! empty( $row['remove'] ) ) {
				continue;
			}
			$token = isset( $row['target'] ) ? Wormiss_Access_Rules::parse_token( (string) $row['target'] ) : null;
			if ( null === $token ) {
				continue;
			}
			$rows[] = [
				'kind'     => $token['kind'],
				'ref'      => $token['ref'],
				'types'    => (string) ( $row['types'] ?? '' ),
				'statuses' => (string) ( $row['statuses'] ?? '' ),
			];
		}
		Wormiss_Access_Rules::save( $rows );
		wp_safe_redirect( add_query_arg( 'wormiss_rules_saved', '1', self::settings_url( 'members' ) ) );
		exit;
	}

	/**
	 * The Test connection button.
	 *
	 * @return void
	 */
	private static function render_test_connection(): void {
		echo '<h2>' . esc_html__( 'Test the connection', 'wormiss' ) . '</h2>';
		echo '<p>'
			. esc_html__( 'Signs in to iMIS with the saved settings and runs the first approved query.', 'wormiss' )
			. ' ' . esc_html__( 'Nothing is changed in iMIS.', 'wormiss' ) . '</p>';
		echo '<form action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" method="post">';
		echo '<input type="hidden" name="action" value="wormiss_test_connection" />';
		wp_nonce_field( 'wormiss_test_connection' );
		submit_button( __( 'Test connection', 'wormiss' ), 'secondary', 'submit', false );
		echo '</form>';
	}

	/**
	 * The Send events to iMIS now button.
	 *
	 * @return void
	 */
	private static function render_event_push(): void {
		echo '<h2 id="wormiss-event-push">' . esc_html__( 'Send events to iMIS now', 'wormiss' ) . '</h2>';
		echo '<p class="description">'
			. esc_html__( 'Sends every published Event made here that iMIS does not have up to date (never sent, edited', 'wormiss' )
			. ' ' . esc_html__( 'since, or last sent with a problem), 10 a minute in the background; also once a day. Uses the', 'wormiss' )
			. ' ' . esc_html__( 'sending switch and dry run above. Events linked to iMIS are never sent.', 'wormiss' ) . '</p>';
		$summary = Wormiss_Event_Push::summary();
		echo '<p>' . esc_html( '' !== $summary ? $summary : __( 'Events have not been sent this way yet.', 'wormiss' ) ) . '</p>';
		echo '<form action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" method="post">';
		echo '<input type="hidden" name="action" value="wormiss_event_push_now" />';
		wp_nonce_field( 'wormiss_event_push_now' );
		submit_button( __( 'Send events to iMIS now', 'wormiss' ), 'secondary', 'submit', false );
		echo '</form>';
	}

	/**
	 * A "run now" block: the last run's summary and notes, and the button.
	 *
	 * @param string   $id      Heading id (the button comes back here).
	 * @param string   $title   Heading.
	 * @param string   $summary Last run's summary.
	 * @param string   $not_run Shown when it has not run.
	 * @param string[] $notes   Last run's notes.
	 * @param string   $action  admin-post action (also the nonce action).
	 * @param string   $button  Button text.
	 * @return void
	 */
	private static function render_run_now( string $id, string $title, string $summary, string $not_run, array $notes, string $action, string $button ): void {
		echo '<h2 id="' . esc_attr( $id ) . '">' . esc_html( $title ) . '</h2>';
		echo '<p>' . esc_html( '' !== $summary ? $summary : $not_run ) . '</p>';
		if ( $notes ) {
			echo '<ul class="ul-disc">';
			foreach ( $notes as $note ) {
				echo '<li>' . esc_html( $note ) . '</li>';
			}
			echo '</ul>';
		}
		echo '<form action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" method="post">';
		echo '<input type="hidden" name="action" value="' . esc_attr( $action ) . '" />';
		wp_nonce_field( $action );
		submit_button( $button, 'secondary', 'submit', false );
		echo '</form>';
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
		wp_safe_redirect( self::settings_url() );
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
	 * Each approved query's rules: who may see it, the only columns that may show, whether it
	 * lists people (then never on the website), and the pages that show it.
	 *
	 * @param string[] $queries Approved queries.
	 * @return void
	 */
	private static function query_rules_table( array $queries ): void {
		if ( ! $queries ) {
			return;
		}
		$used      = Wormiss_Iqa_Display::where_used();
		$audiences = [
			'staff'    => __( 'Staff only (signed-in editors and administrators)', 'wormiss' ),
			'members'  => __( 'Signed-in members', 'wormiss' ),
			'everyone' => __( 'Everyone', 'wormiss' ),
		];
		echo '<h4>' . esc_html__( 'What each approved query may show', 'wormiss' ) . '</h4>';
		echo '<p class="description">' . esc_html__( 'Only the columns listed here are ever shown, whatever a page asks for, and only to the audience chosen.', 'wormiss' )
			. ' ' . esc_html__( 'A query that lists people (or whose columns look personal: names, emails, phones, addresses, birth dates, IDs) is never shown on the website.', 'wormiss' )
			. ' ' . esc_html__( 'Only administrators can add iMIS data to pages. Save the list above first to set up a newly approved query.', 'wormiss' ) . '</p>';
		echo '<table class="widefat striped"><thead><tr><th scope="col">' . esc_html__( 'Query', 'wormiss' ) . '</th><th scope="col">'
			. esc_html__( 'Who may see it', 'wormiss' ) . '</th><th scope="col">' . esc_html__( 'Columns that may show (comma-separated)', 'wormiss' )
			. '</th><th scope="col">' . esc_html__( 'Lists people', 'wormiss' ) . '</th><th scope="col">' . esc_html__( 'Used on', 'wormiss' )
			. '</th></tr></thead><tbody>';
		foreach ( $queries as $query ) {
			$rule = Wormiss_Settings::query_rule( $query );
			// Keyed by a hash: a path can hold [ and ], which form field names cannot.
			$base = Wormiss_Settings::OPTION . '[query_rules][' . md5( $query ) . ']';
			echo '<tr><td><code>' . esc_html( $query ) . '</code></td><td><select name="' . esc_attr( $base . '[audience]' ) . '" aria-label="'
				. esc_attr__( 'Who may see it', 'wormiss' ) . '">';
			foreach ( $audiences as $audience => $label ) {
				printf( '<option value="%1$s"%2$s>%3$s</option>', esc_attr( $audience ), selected( $rule['audience'], $audience, false ), esc_html( $label ) );
			}
			echo '</select></td><td><input type="text" class="regular-text code" name="' . esc_attr( $base . '[columns]' ) . '" value="'
				. esc_attr( implode( ', ', $rule['columns'] ) ) . '" aria-label="' . esc_attr__( 'Columns that may show', 'wormiss' ) . '" />';
			$personal = array_values( array_filter( $rule['columns'], [ 'Wormiss_Iqa_Display', 'is_personal_column' ] ) );
			if ( $personal ) {
				echo '<p class="description" style="color:#b32d2e"><strong>' . esc_html__( 'Personal data:', 'wormiss' ) . '</strong> '
					. esc_html( implode( ', ', $personal ) ) . ' — ' . esc_html__( 'so this query is never shown on the website.', 'wormiss' ) . '</p>';
			} elseif ( ! $rule['columns'] ) {
				echo '<p class="description">' . esc_html__( 'None yet, so nothing shows.', 'wormiss' ) . '</p>';
			}
			printf(
				'</td><td><label><input type="checkbox" name="%1$s" value="1"%2$s /> %3$s</label></td><td>',
				esc_attr( $base . '[people]' ),
				checked( $rule['people'], true, false ),
				esc_html__( 'Yes: never on the website', 'wormiss' )
			);
			$pages = $used[ $query ] ?? [];
			if ( ! $pages ) {
				echo esc_html__( 'Not used', 'wormiss' );
			}
			foreach ( $pages as $page ) {
				$link = get_edit_post_link( $page->ID );
				echo '<a href="' . esc_url( (string) $link ) . '">' . esc_html( '' !== $page->post_title ? $page->post_title : '#' . $page->ID ) . '</a> ('
					. esc_html( $page->post_status ) . ')<br />';
			}
			echo '</td></tr>';
		}
		echo '</tbody></table>';
	}

	/**
	 * A log row's kept request and response (redacted when saved), folded away under "Show",
	 * so a rejected call can be checked field by field. Empty when nothing was kept (the
	 * "Keep full request and response bodies" setting was off, or the call has no body).
	 *
	 * @param array $row Log row.
	 * @return string Escaped HTML.
	 */
	public static function log_bodies( array $row ): string {
		$parts = [];
		foreach (
			[
				'request'  => __( 'Sent to iMIS', 'wormiss' ),
				'response' => __( 'iMIS replied', 'wormiss' ),
			] as $key => $label
		) {
			$body = (string) ( $row[ $key ] ?? '' );
			if ( '' === $body ) {
				continue;
			}
			$json = json_decode( $body );
			if ( null !== $json ) {
				$body = (string) wp_json_encode( $json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
			}
			$parts[] = '<p><strong>' . esc_html( $label ) . '</strong></p>'
				. '<pre style="max-height:24em;overflow:auto;white-space:pre-wrap;word-break:break-all;">'
				. esc_html( $body ) . '</pre>';
		}
		if ( ! $parts ) {
			return '';
		}
		return '<details><summary>' . esc_html__( 'Show', 'wormiss' ) . '</summary>' . implode( '', $parts ) . '</details>';
	}

	/**
	 * How many of the newest entries the text download can hold.
	 */
	const LOG_EXPORT_SIZES = [ 20, 50, 200, 1000 ];

	/**
	 * The Download as text form above the audit log.
	 *
	 * @return void
	 */
	private static function log_export_form(): void {
		echo '<form action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" method="post" style="margin:1em 0">';
		echo '<input type="hidden" name="action" value="wormiss_log_export" />';
		wp_nonce_field( 'wormiss_log_export' );
		echo '<label for="wormiss-log-count">' . esc_html__( 'Newest entries', 'wormiss' ) . '</label> ';
		echo '<select id="wormiss-log-count" name="wormiss_log_count">';
		foreach ( self::LOG_EXPORT_SIZES as $size ) {
			printf( '<option value="%1$d" %2$s>%1$d</option>', (int) $size, selected( 50, $size, false ) );
		}
		echo '</select> ';
		echo '<label><input type="checkbox" name="wormiss_log_bodies" value="1" checked /> '
			. esc_html__( 'Include what was sent and what iMIS replied', 'wormiss' ) . '</label> ';
		submit_button( __( 'Download as text', 'wormiss' ), 'secondary', 'submit', false );
		echo '<p class="description">'
			. esc_html__( 'A plain text file of the newest entries, newest first. Passwords and tokens are never in it,', 'wormiss' )
			. ' ' . esc_html__( 'but replies can hold members\' details: keep the file safe and delete it when done.', 'wormiss' )
			. '</p></form>';
	}

	/**
	 * The Download as text button: administrators only.
	 *
	 * @return void
	 */
	public static function export_log(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'wormiss' ), 403 );
		}
		check_admin_referer( 'wormiss_log_export' );
		$count = isset( $_POST['wormiss_log_count'] ) ? absint( $_POST['wormiss_log_count'] ) : 50;
		$count = in_array( $count, self::LOG_EXPORT_SIZES, true ) ? $count : 50;
		$text  = self::log_text( Wormiss_Logger::recent( $count ), ! empty( $_POST['wormiss_log_bodies'] ) );
		nocache_headers();
		header( 'Content-Type: text/plain; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="wormiss-audit-log-' . wp_date( 'Ymd-Hi' ) . '.txt"' );
		header( 'X-Content-Type-Options: nosniff' );
		echo $text; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- A plain text download, not HTML.
		exit;
	}

	/**
	 * Log rows as plain text: each entry's time (site time), call, result, error, user and iMIS
	 * ID, and if asked what was sent and what iMIS replied (JSON laid out for reading).
	 *
	 * @param array[] $rows   Log rows, as Wormiss_Logger::recent() gives them.
	 * @param bool    $bodies Include what was sent and replied.
	 * @return string
	 */
	public static function log_text( array $rows, bool $bodies ): string {
		$format = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
		$lines  = [
			sprintf(
				/* translators: 1: site name, 2: date and time, 3: number of entries. */
				__( 'Wormiss audit log for %1$s, downloaded %2$s: %3$d entries, newest first.', 'wormiss' ),
				get_bloginfo( 'name' ),
				wp_date( $format ),
				count( $rows )
			),
		];
		foreach ( $rows as $row ) {
			$user    = (int) ( $row['user_id'] ?? 0 ) ? get_userdata( (int) $row['user_id'] ) : false;
			$lines[] = '';
			$lines[] = str_repeat( '=', 72 );
			$lines[] = __( 'Time:', 'wormiss' ) . ' ' . get_date_from_gmt( (string) ( $row['created'] ?? '' ), $format );
			$lines[] = __( 'Call:', 'wormiss' ) . ' ' . (string) ( $row['endpoint'] ?? '' );
			$lines[] = __( 'Result:', 'wormiss' ) . ' ' . (string) ( $row['status'] ?? '' );
			foreach (
				[
					__( 'Error:', 'wormiss' )   => (string) ( $row['error'] ?? '' ),
					__( 'User:', 'wormiss' )    => $user ? $user->user_login : '',
					__( 'iMIS ID:', 'wormiss' ) => (string) ( $row['imis_id'] ?? '' ),
				] as $label => $value
			) {
				if ( '' !== $value ) {
					$lines[] = $label . ' ' . $value;
				}
			}
			if ( ! $bodies ) {
				continue;
			}
			foreach (
				[
					'request'  => __( 'Sent to iMIS:', 'wormiss' ),
					'response' => __( 'iMIS replied:', 'wormiss' ),
				] as $key => $label
			) {
				$body = (string) ( $row[ $key ] ?? '' );
				if ( '' === $body ) {
					continue;
				}
				$json = json_decode( $body );
				if ( null !== $json ) {
					$body = (string) wp_json_encode( $json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
				}
				$lines[] = $label;
				$lines[] = $body;
			}
		}
		return implode( "\n", $lines ) . "\n";
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

		echo '<div class="wrap"><h1>' . esc_html__( 'Wormiss audit log', 'wormiss' ) . '</h1>';
		if ( ! Wormiss_Logger::is_enabled() ) {
			echo '<div class="notice notice-warning"><p>'
				. esc_html__( 'Logging is switched off in Wormiss > Settings.', 'wormiss' ) . '</p></div>';
		}
		self::log_export_form();
		echo '<table class="widefat striped"><thead><tr>';
		$headings = [
			__( 'Time', 'wormiss' ),
			__( 'Call', 'wormiss' ),
			__( 'Result', 'wormiss' ),
			__( 'Error', 'wormiss' ),
			__( 'User', 'wormiss' ),
			__( 'Details', 'wormiss' ),
		];
		foreach ( $headings as $heading ) {
			echo '<th scope="col">' . esc_html( $heading ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		if ( ! $rows ) {
			echo '<tr><td colspan="6">' . esc_html__( 'No calls logged yet.', 'wormiss' ) . '</td></tr>';
		}
		foreach ( $rows as $row ) {
			$user = (int) $row['user_id'] ? get_userdata( (int) $row['user_id'] ) : false;
			printf(
				'<tr><td>%1$s</td><td><code>%2$s</code></td><td>%3$s</td><td>%4$s</td><td>%5$s</td><td>%6$s</td></tr>',
				esc_html( get_date_from_gmt( $row['created'], get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ) ),
				esc_html( $row['endpoint'] ),
				esc_html( $row['status'] ),
				esc_html( (string) $row['error'] ),
				esc_html( $user ? $user->user_login : '' ),
				self::log_bodies( $row ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in log_bodies().
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
