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
 * Tests for the admin connection test and permissions.
 *
 * @package    wormiss
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Admin screens.
 *
 * @covers Wormiss_Admin
 */
class Test_Wormiss_Admin extends Wormiss_Test_Case {

	/**
	 * The connection test signs in and runs the first approved query without changing iMIS.
	 *
	 * @return void
	 */
	public function test_connection_test(): void {
		$this->imis->token()->reply( '/api/iqa', 200, Wormiss_Fake_Imis::page( [ [ 'A' => 1 ], [ 'A' => 2 ] ] ) );
		$result = Wormiss_Admin::run_connection_test();
		$this->assertTrue( $result['ok'] );
		$this->assertStringContainsString( '2 rows', $result['message'] );
		foreach ( $this->imis->requests as $request ) {
			$this->assertContains( $request['method'], [ 'GET', 'POST' ] );
		}
	}

	/**
	 * A wrong password is reported, and an unconfigured connection asks for details first.
	 *
	 * @return void
	 */
	public function test_connection_test_failures(): void {
		$this->imis->reply( '/token', 400, [ 'error_description' => 'The user name or password is incorrect.' ] );
		$result = Wormiss_Admin::run_connection_test();
		$this->assertFalse( $result['ok'] );
		$this->assertStringContainsString( 'incorrect', $result['message'] );

		delete_option( Wormiss_Settings::PASSWORD_OPTION );
		$result = Wormiss_Admin::run_connection_test();
		$this->assertStringContainsString( 'Fill in', $result['message'] );
	}

	/**
	 * The editor gets the approved queries, but never the connection details.
	 *
	 * @return void
	 */
	public function test_editor_data_has_no_secrets(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );
		wp_register_script( 'wormiss-iqa-editor-script', 'x.js', [], '1', false );
		Wormiss_Plugin::editor_data();
		$inline = implode( '', (array) wp_scripts()->get_data( 'wormiss-iqa-editor-script', 'before' ) );
		$this->assertStringContainsString( 'UpcomingEvents', $inline );
		$this->assertStringNotContainsString( 's3cret', $inline );
		$this->assertStringNotContainsString( 'svc-wordpress', $inline );
		$this->assertStringNotContainsString( 'imis.example.org', $inline );

		wp_register_script( 'wormiss-member-editor-script', 'y.js', [], '1', false );
		Wormiss_Plugin::editor_data();
		$member = implode( '', (array) wp_scripts()->get_data( 'wormiss-member-editor-script', 'before' ) );
		$this->assertStringContainsString( 'MyMembership', $member );
		$this->assertStringNotContainsString( 's3cret', $member );
		$this->assertStringNotContainsString( 'imis.example.org', $member );
	}

	/**
	 * Everything Wormiss adds sits under one Wormiss menu: Overview, Events, Add New Event,
	 * Event types, Settings and the audit log.
	 *
	 * @return void
	 */
	public function test_wormiss_menu(): void {
		global $menu, $submenu, $_wp_submenu_nopriv;
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		Wormiss_Event_Types::register();
		Wormiss_Event_Sync::register();
		$menu               = []; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Test set-up.
		$submenu            = []; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Test set-up.
		$_wp_submenu_nopriv = []; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Test set-up.

		Wormiss_Admin::menus();
		Wormiss_Admin::more_menus();
		$slugs = array_column( $submenu[ Wormiss_Admin::MENU ] ?? [], 2 );
		$this->assertSame( Wormiss_Admin::MENU, $slugs[0] );
		$this->assertContains( 'post-new.php?post_type=wormiss_event', $slugs );
		$this->assertContains( 'edit-tags.php?taxonomy=wormiss_event_type&post_type=wormiss_event', $slugs );
		$this->assertContains( Wormiss_Admin::SETTINGS_PAGE, $slugs );
		$this->assertContains( Wormiss_Admin::LOG_PAGE, $slugs );
		$this->assertSame( 'wormiss', get_post_type_object( Wormiss_Event_Sync::POST_TYPE )->show_in_menu );
	}

	/**
	 * The old Settings and Tools addresses lead to the new pages, and the Plugins page links there.
	 *
	 * @return void
	 */
	public function test_old_addresses(): void {
		$settings = Wormiss_Admin::old_page_target( 'options-general.php', 'wormiss' );
		$this->assertSame( admin_url( 'admin.php?page=wormiss-settings' ), $settings );
		$this->assertSame( admin_url( 'admin.php?page=wormiss-log' ), Wormiss_Admin::old_page_target( 'tools.php', 'wormiss-log' ) );
		$this->assertSame( '', Wormiss_Admin::old_page_target( 'admin.php', 'wormiss-settings' ) );
		$this->assertStringContainsString( 'admin.php?page=wormiss-settings', implode( '', Wormiss_Admin::action_links( [] ) ) );
	}

	/**
	 * The Overview shows what is switched on; editors get the Events links, not Settings.
	 *
	 * @return void
	 */
	public function test_overview(): void {
		update_option(
			Wormiss_Settings::OPTION,
			array_merge(
				Wormiss_Settings::all(),
				[
					'activity_enabled' => true,
					'activity_dry_run' => true,
				]
			)
		);
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );
		ob_start();
		Wormiss_Admin::render_overview();
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( 'On (dry run: nothing is sent)', $html );
		$this->assertStringContainsString( 'edit.php?post_type=wormiss_event', $html );
		$this->assertStringContainsString( 'edit-tags.php?taxonomy=wormiss_event_type', $html );
		$this->assertStringNotContainsString( 'page=wormiss-settings', $html );
	}

	/**
	 * The settings page, now outside the Settings menu, shows WordPress's "Settings saved".
	 *
	 * @return void
	 */
	public function test_settings_saved_notice(): void {
		require_once ABSPATH . 'wp-admin/includes/template.php';
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		add_settings_error( 'general', 'settings_updated', 'Settings saved.', 'success' );
		ob_start();
		Wormiss_Admin::render_settings();
		$this->assertStringContainsString( 'Settings saved.', (string) ob_get_clean() );
	}
}
