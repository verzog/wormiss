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
 * Tests for Check a sign-in.
 *
 * @package    wormiss
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * The check lists each step without signing anyone in or counting a failure.
 *
 * @covers Wormiss_Sign_In_Check
 */
class Test_Wormiss_Sign_In_Check extends Wormiss_Test_Case {

	/**
	 * When iMIS refuses a sign-in (the check and the sign-in page's answer each ask once).
	 *
	 * @return void
	 */
	private function refuse_twice(): void {
		$this->imis->reply( '/token', 400, [ 'error_description' => 'The user name or password is incorrect.' ] );
		$this->imis->reply( '/token', 400, [ 'error_description' => 'The user name or password is incorrect.' ] );
	}

	/**
	 * A local administrator with the right and the wrong password.
	 *
	 * @return void
	 */
	public function test_run(): void {
		$admin = self::factory()->user->create(
			[
				'user_login' => 'boss',
				'user_email' => 'boss@example.org',
				'user_pass'  => 'admin-pass',
				'role'       => 'administrator',
			]
		);
		$this->refuse_twice();
		$steps = wp_list_pluck( Wormiss_Sign_In_Check::run( 'boss', 'admin-pass' ), 'ok', 'label' );
		$this->assertFalse( $steps['iMIS accepts these details'] );
		$this->assertTrue( $steps['Website account found'] );
		$this->assertTrue( $steps['The website password matches'] );
		$this->assertTrue( $steps['The sign-in page\'s answer'] );
		$this->assertSame( 0, get_current_user_id(), 'Nobody is signed in.' );

		$this->refuse_twice();
		$steps = wp_list_pluck( Wormiss_Sign_In_Check::run( 'boss@example.org', 'wrong' ), 'ok', 'label' );
		$this->assertFalse( $steps['The website password matches'] );
		$this->assertFalse( $steps['The sign-in page\'s answer'] );
		$this->assertFalse( Wormiss_Sign_In::locked( 'boss' ), 'No failed try is counted.' );

		$this->refuse_twice();
		$steps = wp_list_pluck( Wormiss_Sign_In_Check::run( 'nobody', 'x' ), 'ok', 'label' );
		$this->assertFalse( $steps['Website account found'] );
		unset( $admin );
	}

	/**
	 * How a password is stored, from its start only.
	 *
	 * @return void
	 */
	public function test_hash_kind(): void {
		$this->assertSame( 'MD5 (set directly in the database)', Wormiss_Sign_In_Check::hash_kind( md5( 'x' ) ) );
		$this->assertSame( 'WordPress phpass (older)', Wormiss_Sign_In_Check::hash_kind( '$P$Babcdefgh' ) );
		$this->assertSame( 'no password', Wormiss_Sign_In_Check::hash_kind( '' ) );
		$this->assertStringNotContainsString( 'abcdefgh', Wormiss_Sign_In_Check::hash_kind( '$P$Babcdefgh' ) );
	}
}
