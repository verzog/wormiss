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
 * Tests for the membership details copied from iMIS.
 *
 * @package    wormiss
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */


/**
 * Membership details on members' accounts.
 *
 * @covers Wormiss_Membership
 */
class Test_Wormiss_Membership extends Wormiss_Test_Case {

	/**
	 * Turn the member sync on (live).
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();
		update_option(
			Wormiss_Settings::OPTION,
			array_merge(
				Wormiss_Settings::all(),
				[
					'user_sync_enabled' => true,
					'user_sync_dry_run' => false,
					'user_sync_query'   => '$/Members/WebsiteMembers',
					'access_statuses'   => [ 'A' ],
				]
			)
		);
		update_option( 'date_format', 'd/m/Y' );
		add_filter( 'pre_wp_mail', '__return_false' );
	}

	/**
	 * Serve one member row.
	 *
	 * @param array $more Columns to add or replace.
	 * @return void
	 */
	private function serve( array $more = [] ): void {
		$row = array_merge(
			[
				'ID'          => '501',
				'Email'       => 'm501@example.org',
				'FirstName'   => 'Mem',
				'LastName'    => 'Ber',
				'Status'      => 'A',
				'MemberType'  => 'FELLOW',
				'JoinDate'    => '2019-03-01T00:00:00',
				'PaidThrough' => '2099-12-31T00:00:00',
			],
			$more
		);
		$this->imis->token()->reply( '/api/iqa', 200, Wormiss_Fake_Imis::page( [ $row ] ) );
	}

	/**
	 * Dates from iMIS are kept as Y-m-d; blanks, iMIS's 1900 "no date" and nonsense are dropped.
	 *
	 * @return void
	 */
	public function test_dates(): void {
		$this->assertSame( '2026-12-31', Wormiss_Membership::date( '2026-12-31T00:00:00' ) );
		$this->assertSame( '2026-03-01', Wormiss_Membership::date( '1/3/2026' ), 'Day first.' );
		$this->assertSame( '', Wormiss_Membership::date( '1900-01-01T00:00:00' ) );
		$this->assertSame( '', Wormiss_Membership::date( '2026-02-30' ) );
		$this->assertSame( '', Wormiss_Membership::date( '' ) );
		$this->assertSame( '', Wormiss_Membership::date( 'soon' ) );
	}

	/**
	 * The sync copies the details onto new accounts, and keeps them up to date.
	 *
	 * @return void
	 */
	public function test_sync_copies_details(): void {
		$this->serve();
		Wormiss_User_Sync::run();
		$user = get_user_by( 'login', '501' );
		$this->assertInstanceOf( WP_User::class, $user );
		$this->assertSame(
			[
				'type'    => 'FELLOW',
				'status'  => 'A',
				'joined'  => '2019-03-01',
				'expires' => '2099-12-31',
			],
			Wormiss_Membership::get( $user->ID )
		);

		// Only the expiry changed: the account is updated (the names are left alone).
		$this->serve( [ 'PaidThrough' => '2100-06-30T00:00:00' ] );
		$report = Wormiss_User_Sync::run();
		$this->assertSame( 1, $report['updated'] );
		$this->assertSame( '2100-06-30', Wormiss_Membership::get( $user->ID )['expires'] );

		// Nothing changed: nothing written.
		$this->serve( [ 'PaidThrough' => '2100-06-30T00:00:00' ] );
		$this->assertSame( 0, Wormiss_User_Sync::run()['updated'] );
	}

	/**
	 * Column names come from the settings; a blank one is not copied.
	 *
	 * @return void
	 */
	public function test_column_settings(): void {
		update_option(
			Wormiss_Settings::OPTION,
			array_merge(
				Wormiss_Settings::all(),
				[
					'member_type_column'   => 'Customer Type',
					'member_join_column'   => '',
					'member_expiry_column' => 'Renewal_Date',
				]
			)
		);
		$member = Wormiss_User_Sync::member(
			[
				'ID'            => '9',
				'Customer Type' => 'STUDENT',
				'JoinDate'      => '2020-01-01',
				'RenewalDate'   => '2027-01-31',
			]
		);
		$this->assertSame( 'STUDENT', $member['type'] );
		$this->assertSame( '', $member['joined'], 'Blank column: not copied.' );
		$this->assertSame( '2027-01-31', $member['expires'] );

		require_once ABSPATH . 'wp-admin/includes/template.php';
		$this->assertSame( 'Joined On', Wormiss_Settings::sanitize( [ 'member_join_column' => ' Joined On ' ] )['member_join_column'] );
		$clean = Wormiss_Settings::sanitize( [ 'member_join_column' => 'Join <b>' ] );
		$this->assertSame( '', $clean['member_join_column'], 'Refused, so the saved (blank) one stays.' );
	}

	/**
	 * The block shows the signed-in member's details (expired ones marked), nothing to visitors.
	 *
	 * @return void
	 */
	public function test_block(): void {
		$this->assertSame( '', Wormiss_Membership::block_html() );

		$user_id = self::factory()->user->create();
		wp_set_current_user( $user_id );
		$this->assertStringContainsString( 'after the next daily update', Wormiss_Membership::block_html() );

		Wormiss_Membership::save(
			$user_id,
			[
				'type'    => 'FELLOW',
				'status'  => 'A',
				'joined'  => '2019-03-01',
				'expires' => '2020-06-30',
			]
		);
		$html = Wormiss_Membership::block_html();
		$this->assertStringContainsString( '<dt>Member type</dt><dd>FELLOW</dd>', $html );
		$this->assertStringContainsString( '<dd>01/03/2019</dd>', $html );
		$this->assertStringContainsString( '30/06/2020 (expired)', $html );
	}

	/**
	 * The details are on the user's profile, in personal data exports, and erased with the rest.
	 *
	 * @return void
	 */
	public function test_profile_and_privacy(): void {
		$user_id = self::factory()->user->create( [ 'user_email' => 'p@example.org' ] );
		ob_start();
		Wormiss_Membership::profile_section( get_userdata( $user_id ) );
		$this->assertSame( '', (string) ob_get_clean(), 'Nothing when there are no details.' );

		Wormiss_Membership::save(
			$user_id,
			[
				'type'   => 'FELLOW',
				'status' => 'A',
			]
		);
		ob_start();
		Wormiss_Membership::profile_section( get_userdata( $user_id ) );
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( 'iMIS membership', $html );
		$this->assertStringContainsString( 'FELLOW', $html );

		$export = Wormiss_Plugin::export_personal_data( 'p@example.org', 1 );
		$this->assertSame( 'wormiss-membership', $export['data'][0]['group_id'] );
		$this->assertSame( [], Wormiss_Plugin::export_personal_data( 'p@example.org', 2 )['data'] );

		$erased = Wormiss_Plugin::erase_personal_data( 'p@example.org' );
		$this->assertTrue( $erased['items_removed'] );
		$this->assertFalse( Wormiss_Membership::has( $user_id ) );
	}
}
