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
		register_block_type( WORMISS_DIR . '/blocks/iqa' );
		add_shortcode( 'wormiss_iqa', [ 'Wormiss_Iqa_Display', 'shortcode' ] );
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
				. ' ' . esc_html__( 'A log of each request to iMIS (when, what, and whether it worked) is kept for a while.', 'wormiss' )
				. '</p>'
		);
	}
}
