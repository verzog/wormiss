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
	}
}
