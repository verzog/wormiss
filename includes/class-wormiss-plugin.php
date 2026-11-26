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
 * Plugin bootstrap: hooks, activation and scheduled clean-up.
 *
 * @package    wormiss
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined( 'ABSPATH' ) || exit;

/**
 * Wires the plugin into WordPress.
 */
class Wormiss_Plugin {

	/** Daily audit-log clean-up hook. */
	const CLEANUP_HOOK = 'wormiss_cleanup_log';

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'init', [ __CLASS__, 'register_content' ] );
		add_action( 'enqueue_block_editor_assets', [ __CLASS__, 'editor_data' ] );
		add_action( self::CLEANUP_HOOK, [ 'Wormiss_Logger', 'purge' ] );
		add_action( 'admin_init', [ __CLASS__, 'privacy_policy' ] );
		add_action( 'plugins_loaded', [ __CLASS__, 'maybe_upgrade' ] );
		add_filter( 'wp_privacy_personal_data_exporters', [ __CLASS__, 'register_exporter' ] );
		add_filter( 'wp_privacy_personal_data_erasers', [ __CLASS__, 'register_eraser' ] );
		Wormiss_Member::init();
		Wormiss_Members_Only::init();
		Wormiss_Activity::init();
		Wormiss_Completion::init();
		Wormiss_Woocommerce::init();
		Wormiss_Event_Types::init();
		Wormiss_Event_Registration::init();
		Wormiss_User_Sync::init();
		Wormiss_Membership::init();
		Wormiss_Member_Push::init();
		Wormiss_Event_Sync::init();
		Wormiss_Event_Editor::init();
		Wormiss_Event_Cpd::init();
		Wormiss_Event_Import::init();
		Wormiss_News_Import::init();
		Wormiss_Images::init();
		Wormiss_Roles::init();
		Wormiss_Media_Categories::init();
		Wormiss_Media_Access::init();
		Wormiss_Media_Bulk::init();
		Wormiss_Media_Protected::init();
		Wormiss_Blog::init();
		Wormiss_Blog_Lock::init();
		Wormiss_Blog_Actions::init();
		Wormiss_Blog_Pictures::init();
		Wormiss_Sign_In::init();
		Wormiss_Mail_Divert::init();
		Wormiss_Blog_Filters::init();
		Wormiss_Blog_Import::init();
		Wormiss_Event_Push::init();
		if ( is_admin() ) {
			Wormiss_Event_Type_Bulk::init();
			Wormiss_Admin::init();
		}
	}

	/**
	 * Register the iMIS data block and shortcode.
	 *
	 * @return void
	 */
	public static function register_content(): void {
		// The iqa block registers the shared display stylesheet, so it comes first.
		// Only administrators can add the iMIS data block (it can show any approved query).
		add_filter( 'block_type_metadata', [ __CLASS__, 'iqa_block_admins_only' ] );
		register_block_type( WORMISS_DIR . '/blocks/iqa' );
		register_block_type( WORMISS_DIR . '/blocks/member' );
		register_block_type( WORMISS_DIR . '/blocks/my-membership' );
		register_block_type( WORMISS_DIR . '/blocks/members-only' );
		register_block_type( WORMISS_DIR . '/blocks/complete' );
		wp_register_style( 'wormiss-blog-filters', plugins_url( 'assets/blog-filters.css', WORMISS_FILE ), [], WORMISS_VERSION );
		wp_register_script(
			'wormiss-blog-filters-view',
			plugins_url( 'assets/blog-filters.js', WORMISS_FILE ),
			[],
			WORMISS_VERSION,
			[
				'in_footer' => true,
				'strategy'  => 'defer',
			]
		);
		register_block_type( WORMISS_DIR . '/blocks/events' );
		wp_register_style( 'wormiss-open-events', plugins_url( 'assets/open-events.css', WORMISS_FILE ), [], WORMISS_VERSION );
		register_block_type( WORMISS_DIR . '/blocks/open-events' );
		register_block_type( WORMISS_DIR . '/blocks/blog-filters' );
		register_block_type( WORMISS_DIR . '/blocks/blog-meta' );
		wp_register_style( 'wormiss-blog-actions', plugins_url( 'assets/blog-actions.css', WORMISS_FILE ), [], WORMISS_VERSION );
		wp_register_script(
			'wormiss-blog-actions-view',
			plugins_url( 'assets/blog-actions.js', WORMISS_FILE ),
			[],
			WORMISS_VERSION,
			[
				'in_footer' => true,
				'strategy'  => 'defer',
			]
		);
		register_block_type( WORMISS_DIR . '/blocks/blog-actions' );
		wp_register_style( 'wormiss-sign-in', plugins_url( 'assets/sign-in.css', WORMISS_FILE ), [], WORMISS_VERSION );
		register_block_type( WORMISS_DIR . '/blocks/sign-in' );
		// The event blocks share one editor script and style.
		wp_register_script(
			'wormiss-event-blocks',
			plugins_url( 'assets/event-blocks.js', WORMISS_FILE ),
			[ 'wp-block-editor', 'wp-blocks', 'wp-components', 'wp-core-data', 'wp-data', 'wp-element', 'wp-hooks', 'wp-i18n', 'wp-preferences', 'wp-server-side-render' ],
			WORMISS_VERSION,
			true
		);
		wp_register_style( 'wormiss-event-blocks-editor', plugins_url( 'assets/event-blocks-editor.css', WORMISS_FILE ), [], WORMISS_VERSION );
		wp_add_inline_script(
			'wormiss-event-blocks',
			'window.wormissEventBlocks = ' . wp_json_encode(
				[
					'requirements' => Wormiss_Event_Cpd::REQUIREMENTS,
					'maxRows'      => Wormiss_Event_Cpd::MAX_ROWS,
					'countries'    => Wormiss_Event_Sync::COUNTRIES,
				]
			) . ';',
			'before'
		);
		register_block_type( WORMISS_DIR . '/blocks/event-facts' );
		register_block_type( WORMISS_DIR . '/blocks/event-cpd' );
		register_block_type( WORMISS_DIR . '/blocks/event-imis' );
		add_shortcode( 'wormiss_iqa', [ 'Wormiss_Iqa_Display', 'shortcode' ] );
		add_shortcode( 'wormiss_member', [ 'Wormiss_Member_Display', 'shortcode' ] );
		add_shortcode( 'wormiss_events', [ 'Wormiss_Events', 'shortcode' ] );
	}

	/**
	 * Hide the iMIS data block from the inserter for anyone but administrators. (Pages they make
	 * with it anyway show nothing: see Wormiss_Iqa_Display::placed_by_admin().)
	 *
	 * @param array $metadata Block metadata.
	 * @return array
	 */
	public static function iqa_block_admins_only( $metadata ) {
		if ( is_array( $metadata ) && 'wormiss/iqa' === ( $metadata['name'] ?? '' ) && ! current_user_can( 'manage_options' ) ) {
			$metadata['supports']             = is_array( $metadata['supports'] ?? null ) ? $metadata['supports'] : [];
			$metadata['supports']['inserter'] = false;
		}
		return $metadata;
	}

	/**
	 * Give the block editor the approved queries for the block's dropdown.
	 *
	 * @return void
	 */
	public static function editor_data(): void {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return;
		}
		$data = [
			// Only administrators place iMIS data, so only they get the list of approved queries.
			'queries'    => current_user_can( 'manage_options' ) ? Wormiss_Settings::allowed_queries() : [],
			'configured' => Wormiss_Settings::is_configured(),
		];
		wp_add_inline_script( 'wormiss-iqa-editor-script', 'window.wormissIqa = ' . wp_json_encode( $data ) . ';', 'before' );
		$member = [
			'queries'    => Wormiss_Settings::member_queries(),
			'configured' => Wormiss_Settings::is_configured(),
		];
		wp_add_inline_script( 'wormiss-member-editor-script', 'window.wormissMember = ' . wp_json_encode( $member ) . ';', 'before' );
		$events = [ 'configured' => Wormiss_Settings::is_configured() ];
		wp_add_inline_script( 'wormiss-events-editor-script', 'window.wormissEvents = ' . wp_json_encode( $events ) . ';', 'before' );
	}

	/**
	 * Activation: create the log table and schedule the daily clean-up.
	 *
	 * @return void
	 */
	public static function activate(): void {
		Wormiss_Logger::install();
		// The internal Staff and Manager roles.
		Wormiss_Roles::install();
		// The reserved "End-user images" media category (not deletable).
		Wormiss_Media_Categories::ensure_reserved_term();
		// The per-department media categories and the default role x category matrix.
		Wormiss_Media_Access::install();
		if ( ! wp_next_scheduled( self::CLEANUP_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CLEANUP_HOOK );
		}
	}

	/**
	 * Deactivation: stop the scheduled clean-up (settings and log are kept).
	 *
	 * @return void
	 */
	public static function deactivate(): void {
		wp_clear_scheduled_hook( self::CLEANUP_HOOK );
		// Unsent activity records are dropped: nothing is sent while the plugin is inactive.
		wp_unschedule_hook( Wormiss_Activity::SEND_HOOK );
		wp_unschedule_hook( Wormiss_Event_Sync::SYNC_HOOK );
		wp_unschedule_hook( Wormiss_Event_Sync::CANCEL_HOOK );
		wp_clear_scheduled_hook( Wormiss_User_Sync::HOOK );
		wp_clear_scheduled_hook( Wormiss_User_Sync::MORE_HOOK );
		wp_unschedule_hook( Wormiss_Member_Push::HOOK );
		wp_clear_scheduled_hook( Wormiss_Event_Import::HOOK );
		wp_clear_scheduled_hook( Wormiss_Event_Import::MORE_HOOK );
		wp_clear_scheduled_hook( Wormiss_Event_Push::HOOK );
		wp_clear_scheduled_hook( Wormiss_Event_Push::MORE_HOOK );
		wp_clear_scheduled_hook( Wormiss_News_Import::HOOK );
		wp_clear_scheduled_hook( Wormiss_News_Import::MORE_HOOK );
		wp_clear_scheduled_hook( Wormiss_Blog_Import::HOOK );
		wp_clear_scheduled_hook( Wormiss_Blog_Import::MORE_HOOK );
		wp_clear_scheduled_hook( Wormiss_Media_Bulk::HOOK );
		// The Events post type goes away with the plugin: rebuild permalinks next time.
		delete_option( 'wormiss_rewrite_version' );
		flush_rewrite_rules( false );
	}

	/**
	 * Create or update the log table after a plugin update (activation does not run then).
	 *
	 * @return void
	 */
	public static function maybe_upgrade(): void {
		if ( get_option( 'wormiss_db_version' ) !== Wormiss_Logger::DB_VERSION ) {
			Wormiss_Logger::install();
		}
		// Keep the internal roles' capabilities in step after an update.
		Wormiss_Roles::maybe_install();
	}

	/**
	 * Add the audit-log exporter to Tools > Export Personal Data.
	 *
	 * @param array $exporters Registered exporters.
	 * @return array
	 */
	public static function register_exporter( array $exporters ): array {
		$exporters['wormiss'] = [
			'exporter_friendly_name' => __( 'Wormiss (iMIS membership and audit log)', 'wormiss' ),
			'callback'               => [ __CLASS__, 'export_personal_data' ],
		];
		return $exporters;
	}

	/**
	 * Add the audit-log eraser to Tools > Erase Personal Data.
	 *
	 * @param array $erasers Registered erasers.
	 * @return array
	 */
	public static function register_eraser( array $erasers ): array {
		$erasers['wormiss'] = [
			'eraser_friendly_name' => __( 'Wormiss audit log', 'wormiss' ),
			'callback'             => [ __CLASS__, 'erase_personal_data' ],
		];
		return $erasers;
	}

	/**
	 * Export a person's audit-log entries (by WordPress user or iMIS ID).
	 *
	 * @param string $email Email address.
	 * @param int    $page  Page, from 1.
	 * @return array
	 */
	public static function export_personal_data( string $email, int $page = 1 ): array {
		$user = get_user_by( 'email', $email );
		if ( ! $user ) {
			return [
				'data' => [],
				'done' => true,
			];
		}
		$per_page = 100;
		$rows     = Wormiss_Logger::for_person( $user->ID, Wormiss_Member::imis_id( $user->ID ), $per_page, ( $page - 1 ) * $per_page );
		// The membership details copied from iMIS, with the first page.
		$data = 1 === $page ? Wormiss_Membership::export_items( $user->ID ) : [];
		foreach ( $rows as $row ) {
			$data[] = [
				'group_id'    => 'wormiss-log',
				'group_label' => __( 'Wormiss audit log', 'wormiss' ),
				'item_id'     => 'wormiss-log-' . $row['id'],
				'data'        => [
					[
						'name'  => __( 'Time (UTC)', 'wormiss' ),
						'value' => $row['created'],
					],
					[
						'name'  => __( 'Request to iMIS', 'wormiss' ),
						'value' => $row['endpoint'],
					],
					[
						'name'  => __( 'Result', 'wormiss' ),
						'value' => $row['status'],
					],
				],
			];
		}
		return [
			'data' => $data,
			'done' => count( $rows ) < $per_page,
		];
	}

	/**
	 * Erase a person's audit-log entries (by WordPress user or iMIS ID).
	 *
	 * @param string $email Email address.
	 * @param int    $page  Page, from 1 (all rows go in one pass).
	 * @return array
	 */
	public static function erase_personal_data( string $email, int $page = 1 ): array {
		unset( $page );
		$user    = get_user_by( 'email', $email );
		$removed = $user ? Wormiss_Logger::delete_for_person( $user->ID, Wormiss_Member::imis_id( $user->ID ) ) : 0;
		if ( $user ) {
			// "Mark as done" completions stored on the account, records not yet sent to iMIS
			// (records already in iMIS stay there).
			$removed += Wormiss_Completion::delete_for_user( $user->ID );
			$removed += Wormiss_Activity::unschedule_for_person( $user->ID, Wormiss_Member::imis_id( $user->ID ) );
			// Blog likes and saved posts.
			$removed += Wormiss_Blog_Actions::delete_for_user( $user->ID );
			// The membership details copied from iMIS (the next member sync copies them again
			// while the person is in the members query: iMIS is their record).
			$removed += Wormiss_Membership::delete_for_user( $user->ID );
		}
		return [
			'items_removed'  => $removed > 0,
			'items_retained' => false,
			'messages'       => [],
			'done'           => true,
		];
	}

	/**
	 * Suggested text for the site's privacy policy (Settings > Privacy).
	 *
	 * @return void
	 */
	public static function privacy_policy(): void {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}
		wp_add_privacy_policy_content(
			__( 'Wormiss – iMIS bridge', 'wormiss' ),
			'<p>'
				. esc_html__( 'This site reads information from our iMIS membership system to show on pages, e.g. events.', 'wormiss' )
				. ' ' . esc_html__( 'Results are kept on this site for a short time to make pages faster.', 'wormiss' )
				. ' ' . esc_html__( 'Signed-in members can see their own iMIS details, read using the iMIS ID on their account.', 'wormiss' )
				. ' ' . esc_html__( 'When switched on, some things members do here are recorded in iMIS against their record:', 'wormiss' )
				. ' ' . esc_html__( 'reading members-only pages, downloading files, completing activities and submitting forms.', 'wormiss' )
				. ' ' . esc_html__( 'If you buy from our online shop, the order number, items and total may be recorded too.', 'wormiss' )
				. ' ' . esc_html__( 'If you register for an event here, the registration is made in iMIS under your record.', 'wormiss' )
				. ' ' . esc_html__( 'Members\' website accounts may be created and updated from iMIS (name, email, iMIS ID, and membership type, status, join and expiry dates).', 'wormiss' )
				. ' ' . esc_html__( 'A log of each request to iMIS (when, what, and whether it worked) is kept for a while.', 'wormiss' )
				. '</p>'
		);
	}
}
