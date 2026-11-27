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
 * Uninstall: remove the plugin's settings, cached results and audit log.
 *
 * @package    wormiss
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

delete_option( 'wormiss_settings' );
delete_option( 'wormiss_password' );
delete_option( 'wormiss_db_version' );
delete_option( 'wormiss_rewrite_version' );
delete_option( 'wormiss_access_rules' );
delete_option( 'wormiss_media_enduser_term' );
delete_option( 'wormiss_media_matrix' );
delete_option( 'wormiss_media_department_terms' );
delete_option( 'wormiss_media_bulk_job' );
delete_option( 'wormiss_media_bulk_report' );
delete_option( 'wormiss_media_rewrite' );
// The _wormiss_protected_file mapping is deliberately kept: for an attachment whose original was
// moved into the private store, it is the only pointer back to those bytes (its public file was
// removed). Deleting it would orphan the stored files, so a later reinstall can still recover.
wp_clear_scheduled_hook( 'wormiss_media_bulk' );
delete_option( 'wormiss_media_migrate_job' );
delete_option( 'wormiss_media_migrate_report' );
wp_clear_scheduled_hook( 'wormiss_media_migrate' );
// Remove the internal Staff and Manager roles and the per-user departments.
if ( file_exists( __DIR__ . '/includes/class-wormiss-roles.php' ) ) {
	require_once __DIR__ . '/includes/class-wormiss-roles.php';
	Wormiss_Roles::uninstall();
}
delete_option( 'wormiss_user_sync_report' );
delete_metadata( 'user', 0, '_wormiss_member_push_tries', '', true );
delete_metadata( 'user', 0, '_wormiss_membership', '', true );
delete_option( 'wormiss_user_sync_offset' );
delete_option( 'wormiss_event_import_report' );
delete_option( 'wormiss_event_import_lock' );
delete_option( 'wormiss_event_import_done' );
delete_option( 'wormiss_event_import_list' );
delete_option( 'wormiss_event_push_report' );
delete_option( 'wormiss_event_push_done' );
delete_option( 'wormiss_event_push_lock' );
wp_clear_scheduled_hook( 'wormiss_cleanup_log' );

// Cached IQA results are transients named wormiss_iqa_*.
// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- One-off clean-up on uninstall.
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
		$wpdb->esc_like( '_transient_wormiss_' ) . '%',
		$wpdb->esc_like( '_transient_timeout_wormiss_' ) . '%'
	)
);
// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Plugin's own table.
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}wormiss_log" );
