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
 * Tests for syncing members from iMIS into WordPress users.
 *
 * @package    wormiss
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Member sync.
 *
 * @covers Wormiss_User_Sync
 */
class Test_Wormiss_User_Sync extends Wormiss_Test_Case {

	/** The members query used by the tests. */
	const SYNC_QUERY = '$/Members/WebsiteMembers';

	/**
	 * Emails WordPress tried to send.
	 *
	 * @var int
	 */
	private int $mails = 0;

	/**
	 * Turn syncing on (live) and count any email.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();
		$this->turn_on( false );
		$this->mails = 0;
		add_filter(
			'pre_wp_mail',
			function () {
				++$this->mails;
				return false;
			}
		);
	}

	/**
	 * Turn syncing on.
	 *
	 * @param bool  $dry_run Dry run.
	 * @param array $more    More settings.
	 * @return void
	 */
	private function turn_on( bool $dry_run, array $more = [] ): void {
		update_option(
			Wormiss_Settings::OPTION,
			array_merge(
				Wormiss_Settings::all(),
				[
					'user_sync_enabled' => true,
					'user_sync_dry_run' => $dry_run,
					'user_sync_query'   => self::SYNC_QUERY,
					'user_sync_role'    => 'subscriber',
					'access_statuses'   => [ 'A' ],
				],
				$more
			)
		);
	}

	/**
	 * Serve members from the query.
	 *
	 * @param array[] $rows Rows.
	 * @return void
	 */
	private function serve( array $rows ): void {
		$this->imis->token()->reply( '/api/iqa', 200, Wormiss_Fake_Imis::page( $rows ) );
	}

	/**
	 * A member row.
	 *
	 * @param string $id     iMIS ID.
	 * @param string $status Status.
	 * @param array  $more   Columns to add or replace.
	 * @return array
	 */
	private static function row( string $id, string $status = 'A', array $more = [] ): array {
		return array_merge(
			[
				'ID'        => $id,
				'Email'     => 'm' . $id . '@example.org',
				'FirstName' => 'Mem',
				'LastName'  => 'Ber' . $id,
				'Status'    => $status,
			],
			$more
		);
	}

	/**
	 * A member user linked to an iMIS ID.
	 *
	 * @param string $id   iMIS ID.
	 * @param string $role Role.
	 * @return int User ID.
	 */
	private function linked( string $id, string $role = 'subscriber' ): int {
		$user_id = self::factory()->user->create(
			[
				'role'       => $role,
				'user_email' => 'old' . $id . '@example.org',
			]
		);
		update_user_meta( $user_id, 'imis_id', $id );
		return $user_id;
	}

	/**
	 * Columns are found by name, ignoring case, spaces and underscores.
	 *
	 * @return void
	 */
	public function test_member_columns(): void {
		$member = Wormiss_User_Sync::member(
			[
				'Contact ID'    => '777',
				'Email Address' => 'a@example.org',
				'first_name'    => 'Ann',
				'LASTNAME'      => 'Lee',
				'Member Status' => 'A',
			]
		);
		$this->assertSame( '777', $member['id'] );
		$this->assertSame( 'a@example.org', $member['email'] );
		$this->assertSame( 'Ann', $member['first'] );
		$this->assertSame( 'Lee', $member['last'] );
		$this->assertSame( 'A', $member['status'] );
	}

	/**
	 * New members get an account (login = iMIS ID, the sync role, the iMIS ID stored) and no email.
	 *
	 * @return void
	 */
	public function test_creates_members(): void {
		$this->serve( [ self::row( '101' ) ] );
		$report = Wormiss_User_Sync::run();
		$this->assertSame( 1, $report['created'] );
		$user = get_user_by( 'login', '101' );
		$this->assertInstanceOf( WP_User::class, $user );
		$this->assertSame( 'm101@example.org', $user->user_email );
		$this->assertSame( 'Mem', $user->first_name );
		$this->assertSame( [ 'subscriber' ], $user->roles );
		$this->assertSame( '101', get_user_meta( $user->ID, 'imis_id', true ) );
		$this->assertSame( 0, $this->mails );
		$this->assertStringContainsString( '1 created', Wormiss_User_Sync::summary() );
	}

	/**
	 * Existing members are updated, found by iMIS ID or, failing that, by email.
	 *
	 * @return void
	 */
	public function test_updates_members(): void {
		$by_id    = $this->linked( '201' );
		$by_email = self::factory()->user->create( [ 'user_email' => 'm202@example.org' ] );
		$this->serve( [ self::row( '201' ), self::row( '202', 'A', [ 'FirstName' => 'New' ] ) ] );
		$report = Wormiss_User_Sync::run();
		$this->assertSame( 2, $report['updated'] );
		$this->assertSame( 0, $report['created'] );
		$this->assertSame( 'm201@example.org', get_userdata( $by_id )->user_email );
		$this->assertSame( 'New', get_userdata( $by_email )->first_name );
		$this->assertSame( '202', get_user_meta( $by_email, 'imis_id', true ) );
		$this->assertSame( 0, $this->mails );
	}

	/**
	 * A lapsed member is blocked from signing in and signed out; current again, unblocked.
	 *
	 * @return void
	 */
	public function test_blocks_and_unblocks_lapsed(): void {
		$user_id = $this->linked( '301' );
		$this->serve( [ self::row( '301', 'L' ) ] );
		$this->assertSame( 1, Wormiss_User_Sync::run()['blocked'] );
		$this->assertTrue( Wormiss_User_Sync::is_blocked( $user_id ) );
		$refused = Wormiss_User_Sync::refuse_blocked( get_userdata( $user_id ) );
		$this->assertWPError( $refused );
		$this->assertSame( 'wormiss_lapsed', $refused->get_error_code() );
		$this->assertSame( 0, Wormiss_User_Sync::drop_blocked( $user_id ) );

		$this->serve( [ self::row( '301', 'A' ) ] );
		$this->assertSame( 1, Wormiss_User_Sync::run()['unblocked'] );
		$this->assertFalse( Wormiss_User_Sync::is_blocked( $user_id ) );
		$this->assertSame( $user_id, Wormiss_User_Sync::drop_blocked( $user_id ) );
	}

	/**
	 * Staff are never changed or blocked; lapsed members are not given new accounts.
	 *
	 * @return void
	 */
	public function test_staff_and_lapsed_newcomers(): void {
		$editor = $this->linked( '401', 'editor' );
		$this->serve( [ self::row( '401', 'L' ), self::row( '402', 'L' ) ] );
		$report = Wormiss_User_Sync::run();
		$this->assertSame( 2, $report['skipped'] );
		$this->assertFalse( Wormiss_User_Sync::is_blocked( $editor ) );
		$this->assertSame( 'old401@example.org', get_userdata( $editor )->user_email );
		$this->assertFalse( get_user_by( 'login', '402' ) );
	}

	/**
	 * A dry run reports what would happen and changes nothing.
	 *
	 * @return void
	 */
	public function test_dry_run(): void {
		$this->turn_on( true );
		$user_id = $this->linked( '501' );
		$this->serve( [ self::row( '501', 'L' ), self::row( '502' ) ] );
		$report = Wormiss_User_Sync::run();
		$this->assertTrue( $report['dry_run'] );
		$this->assertSame( 1, $report['created'] );
		$this->assertSame( 1, $report['blocked'] );
		$this->assertFalse( get_user_by( 'login', '502' ) );
		$this->assertFalse( Wormiss_User_Sync::is_blocked( $user_id ) );
		$this->assertSame( 'old501@example.org', get_userdata( $user_id )->user_email );
		$this->assertStringContainsString( 'Dry run', Wormiss_User_Sync::summary() );
	}

	/**
	 * A run that would block an unusually large share of members blocks no one.
	 *
	 * @return void
	 */
	public function test_mass_block_is_held_back(): void {
		$rows = [];
		for ( $i = 600; $i < 612; $i++ ) {
			$this->linked( (string) $i );
			$rows[] = self::row( (string) $i, 'L' );
		}
		$this->serve( $rows );
		$report = Wormiss_User_Sync::run();
		$this->assertSame( 0, $report['blocked'] );
		$this->assertStringContainsString( 'no one was blocked', implode( ' ', $report['notes'] ) );
	}

	/**
	 * If iMIS cannot be read, nothing changes; with syncing off, iMIS is not contacted.
	 *
	 * @return void
	 */
	public function test_failure_and_off(): void {
		$this->imis->token()->reply( '/api/iqa', 500, 'Server Error' );
		$this->assertSame( 'error', Wormiss_User_Sync::run()['status'] );
		$this->assertStringContainsString( 'nothing changed', Wormiss_User_Sync::summary() );

		$this->turn_on( false, [ 'user_sync_enabled' => false ] );
		$this->imis->requests = [];
		$this->assertSame( 'off', Wormiss_User_Sync::run()['status'] );
		$this->assertSame( [], $this->imis->requests );
	}

	/**
	 * Only roles without editing rights can be given to synced members.
	 *
	 * @return void
	 */
	public function test_settings(): void {
		$clean = Wormiss_Settings::sanitize(
			[
				'user_sync_section' => '1',
				'user_sync_enabled' => '1',
				'user_sync_query'   => ' ' . self::SYNC_QUERY . ' ',
				'user_sync_role'    => 'editor',
			]
		);
		$this->assertTrue( $clean['user_sync_enabled'] );
		$this->assertFalse( $clean['user_sync_dry_run'] );
		$this->assertSame( self::SYNC_QUERY, $clean['user_sync_query'] );
		$this->assertSame( 'subscriber', $clean['user_sync_role'] );
		$this->assertTrue( Wormiss_Settings::is_member_role( 'subscriber' ) );
		$this->assertFalse( Wormiss_Settings::is_member_role( 'administrator' ) );
	}
}
