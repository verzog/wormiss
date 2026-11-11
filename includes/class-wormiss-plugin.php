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
		if ( is_admin() ) {
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
		register_block_type( WORMISS_DIR . '/blocks/iqa' );
		register_block_type( WORMISS_DIR . '/blocks/member' );
		register_block_type( WORMISS_DIR . '/blocks/members-only' );
		register_block_type( WORMISS_DIR . '/blocks/complete' );
		add_shortcode( 'wormiss_iqa', [ 'Wormiss_Iqa_Display', 'shortcode' ] );
		add_shortcode( 'wormiss_member', [ 'Wormiss_Member_Display', 'shortcode' ] );
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
			'queries'    => Wormiss_Settings::allowed_queries(),
			'configured' => Wormiss_Settings::is_configured(),
		];
		wp_add_inline_script( 'wormiss-iqa-editor-script', 'window.wormissIqa = ' . wp_json_encode( $data ) . ';', 'before' );
		$member = [
			'queries'    => Wormiss_Settings::member_queries(),
			'configured' => Wormiss_Settings::is_configured(),
		];
		wp_add_inline_script( 'wormiss-member-editor-script', 'window.wormissMember = ' . wp_json_encode( $member ) . ';', 'before' );
	}

	/**
	 * Activation: create the log table and schedule the daily clean-up.
	 *
	 * @return void
	 */
	public static function activate(): void {
		Wormiss_Logger::install();
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
	}

	/**
	 * Add the audit-log exporter to Tools > Export Personal Data.
	 *
	 * @param array $exporters Registered exporters.
	 * @return array
	 */
	public static function register_exporter( array $exporters ): array {
		$exporters['wormiss'] = [
			'exporter_friendly_name' => __( 'iMIS Bridge log', 'wormiss' ),
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
			'eraser_friendly_name' => __( 'iMIS Bridge log', 'wormiss' ),
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
		$data     = [];
		foreach ( $rows as $row ) {
			$data[] = [
				'group_id'    => 'wormiss-log',
				'group_label' => __( 'iMIS Bridge log', 'wormiss' ),
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
			// "Mark as done" completions stored on the account (records already in iMIS stay there).
			$removed += Wormiss_Completion::delete_for_user( $user->ID );
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
				. ' ' . esc_html__( 'A log of each request to iMIS (when, what, and whether it worked) is kept for a while.', 'wormiss' )
				. '</p>'
		);
	}
}
