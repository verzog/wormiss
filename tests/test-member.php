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
 * Tests for members' own data: iMIS IDs, the My iMIS data block, profiles and privacy.
 *
 * @package    wormiss
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Members' own iMIS data.
 *
 * @covers Wormiss_Member
 * @covers Wormiss_Member_Display
 */
class Test_Wormiss_Member extends Wormiss_Test_Case {

	/**
	 * A subscriber with an iMIS ID in the default user field.
	 *
	 * @param string $imis_id iMIS ID ('' for none).
	 * @return int User ID.
	 */
	private function member( string $imis_id = '12345' ): int {
		$user_id = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		if ( '' !== $imis_id ) {
			update_user_meta( $user_id, 'imis_id', $imis_id );
		}
		return $user_id;
	}

	/**
	 * Queue a token and the member's membership row.
	 *
	 * @param string $status Membership status.
	 * @return void
	 */
	private function serve_membership( string $status = 'Current' ): void {
		$this->imis->token()->reply(
			'/api/iqa',
			200,
			Wormiss_Fake_Imis::page(
				[
					[
						'MemberType' => 'Fellow',
						'Status'     => $status,
						'PaidThru'   => '2027-06-30T00:00:00',
					],
				]
			)
		);
	}

	/**
	 * The iMIS ID comes from the user field, or the username, or a filter; bad values are refused.
	 *
	 * @return void
	 */
	public function test_imis_id_sources(): void {
		$this->assertSame( '', Wormiss_Member::imis_id() );

		$user_id = $this->member( ' 12345 ' );
		$this->assertSame( '12345', Wormiss_Member::imis_id( $user_id ) );

		update_user_meta( $user_id, 'imis_id', "123'; DROP" );
		$this->assertSame( '', Wormiss_Member::imis_id( $user_id ) );

		update_option( Wormiss_Settings::OPTION, array_merge( Wormiss_Settings::all(), [ 'member_id_source' => 'login' ] ) );
		$sso_user = self::factory()->user->create( [ 'user_login' => '54321' ] );
		$this->assertSame( '54321', Wormiss_Member::imis_id( $sso_user ) );
		// A username that is not an iMIS ID (it has a space) counts as no ID.
		$this->assertSame( '', Wormiss_Member::imis_id( self::factory()->user->create( [ 'user_login' => 'Jane Smith' ] ) ) );

		$filter = static fn() => 'SSO-777';
		add_filter( 'wormiss_member_imis_id', $filter );
		$this->assertSame( 'SSO-777', Wormiss_Member::imis_id( $sso_user ) );
		remove_filter( 'wormiss_member_imis_id', $filter );
	}

	/**
	 * Visitors who are not signed in get a sign-in link, and iMIS is not contacted.
	 *
	 * @return void
	 */
	public function test_signed_out(): void {
		$html = Wormiss_Member_Display::render( [ 'query' => self::MEMBER_QUERY ] );
		$this->assertStringContainsString( 'Sign in to see your details.', $html );
		$this->assertStringContainsString( 'wp-login.php?redirect_to=', $html );
		$this->assertSame( [], $this->imis->requests );
		$this->assertTrue( defined( 'DONOTCACHEPAGE' ) );
	}

	/**
	 * A signed-in user with no iMIS ID is told so, and iMIS is not contacted.
	 *
	 * @return void
	 */
	public function test_no_imis_id(): void {
		wp_set_current_user( $this->member( '' ) );
		$html = Wormiss_Member_Display::render( [ 'query' => self::MEMBER_QUERY ] );
		$this->assertStringContainsString( 'isn&#039;t linked to a membership record', $html );
		$this->assertSame( [], $this->imis->requests );
	}

	/**
	 * Only approved member queries run: a public query cannot be used as a member query.
	 *
	 * @return void
	 */
	public function test_unapproved_member_query_is_never_run(): void {
		wp_set_current_user( $this->member() );
		$this->assertSame( '', Wormiss_Member_Display::render( [ 'query' => self::QUERY ] ) );
		$this->assertSame( '', Wormiss_Member_Display::render( [ 'query' => '$/Members/Everyone' ] ) );
		$this->assertSame( [], $this->imis->requests );
	}

	/**
	 * The member's ID is the first prompt; the block's extra values come after it.
	 *
	 * @return void
	 */
	public function test_member_id_is_first_parameter(): void {
		wp_set_current_user( $this->member( '12345' ) );
		$this->serve_membership();
		$html = Wormiss_Member_Display::render(
			[
				'query'      => self::MEMBER_QUERY,
				'parameters' => '99999 | 2027',
				'columns'    => 'Membership=MemberType, Status, Paid to=PaidThru',
			]
		);
		$url  = $this->imis->requests[1]['url'];
		$this->assertStringContainsString( '&Parameter=12345&Parameter=99999&Parameter=2027&', $url );
		$this->assertStringContainsString( '<dt>Membership</dt><dd>Fellow</dd>', $html );
		$this->assertStringContainsString( '<dt>Paid to</dt><dd>30/06/2027</dd>', $html );

		$log = Wormiss_Logger::recent( 1 )[0];
		$this->assertSame( '12345', $log['imis_id'] );
	}

	/**
	 * One member's cached results are never shown to another.
	 *
	 * @return void
	 */
	public function test_cache_is_per_member(): void {
		$first_member = $this->member( '111' );
		wp_set_current_user( $first_member );
		$this->serve_membership( 'Current' );
		$this->assertStringContainsString( 'Current', Wormiss_Member_Display::render( [ 'query' => self::MEMBER_QUERY ] ) );

		wp_set_current_user( $this->member( '222' ) );
		$this->serve_membership( 'Lapsed' );
		$second = Wormiss_Member_Display::render( [ 'query' => self::MEMBER_QUERY ] );
		$this->assertStringContainsString( 'Lapsed', $second );
		$this->assertStringNotContainsString( 'Current', $second );
		$this->assertSame( 2, $this->imis->count( '/api/iqa' ) );

		// The first member again: their own cached copy, with no new request.
		wp_set_current_user( $first_member );
		$again = Wormiss_Member_Display::render( [ 'query' => self::MEMBER_QUERY ] );
		$this->assertStringContainsString( 'Current', $again );
		$this->assertSame( 2, $this->imis->count( '/api/iqa' ) );
	}

	/**
	 * The block and the shortcode render the member's data.
	 *
	 * @return void
	 */
	public function test_block_and_shortcode(): void {
		wp_set_current_user( $this->member() );
		$this->serve_membership();
		$block = do_blocks( '<!-- wp:wormiss/member {"query":"' . self::MEMBER_QUERY . '","columns":"Status"} /-->' );
		$this->assertStringContainsString( 'wp-block-wormiss-member', $block );
		$this->assertStringContainsString( '<dt>Status</dt><dd>Current</dd>', $block );

		$short = do_shortcode( '[wormiss_member query="' . self::MEMBER_QUERY . '" columns="Status" layout="list"]' );
		$this->assertStringContainsString( '<li>Current</li>', $short );
		$this->assertSame( 1, $this->imis->count( '/api/iqa' ) );
	}

	/**
	 * Administrators can set a user's iMIS ID; members cannot change their own.
	 *
	 * @return void
	 */
	public function test_profile_field_permissions(): void {
		$member = $this->member( '12345' );

		// The member edits their own profile: the iMIS ID is not changed.
		wp_set_current_user( $member );
		$_POST['wormiss_imis_id'] = '99999';
		$_REQUEST['_wpnonce']     = wp_create_nonce( 'update-user_' . $member );
		Wormiss_Member::save_profile_field( $member );
		$this->assertSame( '12345', Wormiss_Member::imis_id( $member ) );

		// An administrator can change it; an invalid value keeps the saved one.
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		if ( is_multisite() ) {
			grant_super_admin( get_current_user_id() );
		}
		$_REQUEST['_wpnonce'] = wp_create_nonce( 'update-user_' . $member );
		Wormiss_Member::save_profile_field( $member );
		$this->assertSame( '99999', Wormiss_Member::imis_id( $member ) );

		$_POST['wormiss_imis_id'] = 'not valid!';
		Wormiss_Member::save_profile_field( $member );
		$this->assertSame( '99999', Wormiss_Member::imis_id( $member ) );

		unset( $_POST['wormiss_imis_id'], $_REQUEST['_wpnonce'] );
	}

	/**
	 * Privacy export and erasure cover a member's log entries, by user and by iMIS ID.
	 *
	 * @return void
	 */
	public function test_privacy_export_and_erase(): void {
		$member = $this->member( '12345' );
		wp_set_current_user( $member );
		$this->serve_membership();
		Wormiss_Member_Display::render( [ 'query' => self::MEMBER_QUERY ] );
		wp_set_current_user( 0 );
		Wormiss_Logger::record_call( 'GET /api/iqa?QueryName=x', Wormiss_Logger::STATUS_SUCCESS, null, null, null, '12345' );

		$email  = get_userdata( $member )->user_email;
		$export = Wormiss_Plugin::export_personal_data( $email );
		$this->assertGreaterThanOrEqual( 3, count( $export['data'] ) );
		$this->assertTrue( $export['done'] );

		$erase = Wormiss_Plugin::erase_personal_data( $email );
		$this->assertTrue( $erase['items_removed'] );
		$this->assertSame( [], Wormiss_Logger::for_person( $member, '12345' ) );
	}
}
