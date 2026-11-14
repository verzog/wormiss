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
 * Tests for the sign-in page.
 *
 * @package    wormiss
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Sign-in page.
 *
 * @covers Wormiss_Sign_In
 */
class Test_Wormiss_Sign_In extends Wormiss_Test_Case {

	/**
	 * A member: website account 12345, linked to iMIS ID 12345.
	 *
	 * @return int
	 */
	private function member(): int {
		$user_id = self::factory()->user->create(
			[
				'user_login' => '12345',
				'user_email' => 'ann@example.org',
				'user_pass'  => 'website-pass',
				'role'       => 'subscriber',
			]
		);
		update_user_meta( $user_id, 'imis_id', '12345' );
		return $user_id;
	}

	/**
	 * When iMIS refuses a sign-in.
	 *
	 * @return void
	 */
	private function refuse(): void {
		$this->imis->reply( '/token', 400, [ 'error_description' => 'The user name or password is incorrect.' ] );
	}

	/**
	 * With a page chosen, every sign-in link goes there (with where to come back to).
	 *
	 * @return void
	 */
	public function test_login_url(): void {
		$this->assertStringContainsString( 'wp-login.php', wp_login_url() );
		$page = self::factory()->post->create(
			[
				'post_type'   => 'page',
				'post_status' => 'publish',
				'post_title'  => 'Sign in',
			]
		);
		update_option( Wormiss_Settings::OPTION, array_merge( Wormiss_Settings::all(), [ 'signin_page' => $page ] ) );
		$this->assertSame( get_permalink( $page ), wp_login_url() );
		$this->assertStringContainsString( 'redirect_to=' . rawurlencode( home_url( '/blogs/' ) ), wp_login_url( home_url( '/blogs/' ) ) );
	}

	/**
	 * Members sign in with their iMIS ID or email and iMIS password, never their website password.
	 *
	 * @return void
	 */
	public function test_members(): void {
		$member = $this->member();

		$this->imis->token();
		$user = Wormiss_Sign_In::authenticate( '12345', 'imis-pass' );
		$this->assertInstanceOf( WP_User::class, $user );
		$this->assertSame( $member, $user->ID );
		$this->assertSame( '12345', $this->imis->requests[0]['body']['username'], 'Checked with iMIS.' );

		$this->imis->token();
		$this->assertSame( $member, Wormiss_Sign_In::authenticate( 'ANN@example.org', 'imis-pass' )->ID, 'Or their email.' );

		$this->refuse();
		$this->assertWPError( Wormiss_Sign_In::authenticate( '12345', 'website-pass' ), 'Members use their iMIS password.' );

		$this->imis->token();
		$this->assertWPError( Wormiss_Sign_In::authenticate( '99999', 'imis-pass' ), 'No website account for that iMIS ID yet.' );
	}

	/**
	 * Staff and other website accounts (not linked to iMIS) use their website password; an iMIS
	 * sign-in never opens an account that is not linked to iMIS.
	 *
	 * @return void
	 */
	public function test_website_accounts(): void {
		$admin = self::factory()->user->create(
			[
				'user_login' => 'boss',
				'user_pass'  => 'admin-pass',
				'role'       => 'administrator',
			]
		);
		$this->refuse();
		$this->assertSame( $admin, Wormiss_Sign_In::authenticate( 'boss', 'admin-pass' )->ID );
		$this->refuse();
		$this->assertWPError( Wormiss_Sign_In::authenticate( 'boss', 'wrong' ) );

		$this->imis->token();
		$this->assertWPError( Wormiss_Sign_In::authenticate( 'boss', 'imis-pass' ), 'iMIS cannot open an unlinked account.' );
		$this->assertWPError( Wormiss_Sign_In::authenticate( '', '' ) );
	}

	/**
	 * Free website accounts: made with the default role; robots, short passwords and used emails
	 * are turned away.
	 *
	 * @return void
	 */
	public function test_create_account(): void {
		$this->assertWPError( Wormiss_Sign_In::create_account( 'Bob Smith', 'bob@example.org', 'long-enough-pass', 'http://spam' ) );
		$this->assertWPError( Wormiss_Sign_In::create_account( 'Bob Smith', 'bob@example.org', 'short' ) );
		$user = Wormiss_Sign_In::create_account( 'Bob Smith', 'bob@example.org', 'long-enough-pass' );
		$this->assertInstanceOf( WP_User::class, $user );
		$this->assertSame( 'bob', $user->user_login );
		$this->assertSame( 'Smith', $user->last_name );
		$this->assertContains( get_option( 'default_role' ), $user->roles );
		$this->assertTrue( wp_check_password( 'long-enough-pass', $user->user_pass, $user->ID ) );
		$this->assertWPError( Wormiss_Sign_In::create_account( 'Bob Again', 'bob@example.org', 'long-enough-pass' ), 'Email already used.' );
	}

	/**
	 * The page: the sign-in form, the forgot-password link and join button when set, and the
	 * free-account form only when sign-ups are on; signed in, a Continue and Sign out.
	 *
	 * @return void
	 */
	public function test_render(): void {
		update_option(
			Wormiss_Settings::OPTION,
			array_merge(
				Wormiss_Settings::all(),
				[
					'signin_forgot_url' => 'https://imis.example.org/forgot',
					'signin_join_url'   => 'https://imis.example.org/join',
				]
			)
		);
		update_option( 'users_can_register', 0 );
		$html = Wormiss_Sign_In::render();
		$this->assertStringContainsString( 'iMIS ID or email', $html );
		$this->assertStringContainsString( 'href="https://imis.example.org/forgot"', $html );
		$this->assertStringContainsString( 'href="https://imis.example.org/join"', $html );
		$this->assertStringNotContainsString( 'wormiss_name', $html );

		update_option( 'users_can_register', 1 );
		$this->assertStringContainsString( 'name="wormiss_name"', Wormiss_Sign_In::render() );

		wp_set_current_user( $this->member() );
		$html = Wormiss_Sign_In::render();
		$this->assertStringContainsString( 'Sign out', $html );
		$this->assertStringNotContainsString( 'name="pwd"', $html );
	}
}
