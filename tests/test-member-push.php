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
 * Tests for sending members' name and email changes to iMIS.
 *
 * @package    wormiss
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * A member's WordPress name and email changes are saved on their iMIS contact.
 *
 * @covers Wormiss_Member_Push
 * @covers Wormiss_Rest_Client
 */
class Test_Wormiss_Member_Push extends Wormiss_Test_Case {

	/**
	 * Clear any queued sends.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();
		wp_unschedule_hook( Wormiss_Member_Push::HOOK );
	}

	/**
	 * Turn sending member changes on.
	 *
	 * @param bool $dry_run Dry run.
	 * @return void
	 */
	private function turn_on( bool $dry_run ): void {
		update_option(
			Wormiss_Settings::OPTION,
			array_merge(
				Wormiss_Settings::all(),
				[
					'member_push_enabled' => true,
					'member_push_dry_run' => $dry_run,
				]
			)
		);
	}

	/**
	 * A member with an iMIS ID.
	 *
	 * @param string $role  Role.
	 * @param string $email Email (a new one if blank: emails must be unique).
	 * @return int User ID.
	 */
	private function member( string $role = 'subscriber', string $email = '' ): int {
		$user_id = self::factory()->user->create(
			[
				'role'       => $role,
				'user_email' => '' !== $email ? $email : wp_generate_password( 8, false ) . '@example.org',
				'first_name' => 'Ann',
				'last_name'  => 'Smith',
			]
		);
		update_user_meta( $user_id, 'imis_id', '12345' );
		return $user_id;
	}

	/**
	 * An iMIS contact as GET /api/Party returns it.
	 *
	 * @return array
	 */
	private static function party(): array {
		return [
			'$type'      => 'Asi.Soa.Membership.DataContracts.PersonData, Asi.Contracts',
			'PartyId'    => '12345',
			'PersonName' => [
				'$type'     => 'Asi.Soa.Membership.DataContracts.PersonNameData, Asi.Contracts',
				'FirstName' => 'Ann',
				'LastName'  => 'Smith',
			],
			'Emails'     => [
				'$type'   => 'Asi.Soa.Membership.DataContracts.EmailDataCollection, Asi.Contracts',
				'$values' => [
					[
						'Address'   => 'work@example.org',
						'EmailType' => 'Work',
						'IsPrimary' => false,
					],
					[
						'Address'   => 'old@example.org',
						'EmailType' => '_Primary',
						'IsPrimary' => true,
					],
				],
			],
		];
	}

	/**
	 * Off by default; the new settings are saved with the member sync section.
	 *
	 * @return void
	 */
	public function test_off_by_default(): void {
		$this->assertFalse( Wormiss_Settings::defaults()['member_push_enabled'] );
		$this->assertTrue( Wormiss_Settings::defaults()['member_push_dry_run'] );
		$user_id = $this->member();
		wp_update_user(
			[
				'ID'         => $user_id,
				'first_name' => 'Anne',
			]
		);
		$this->assertFalse( wp_next_scheduled( Wormiss_Member_Push::HOOK, [ $user_id ] ) );
		$this->assertSame( 'off', Wormiss_Member_Push::push( $user_id ) );
	}

	/**
	 * Only the first name, last name and primary email change; blanks never clear iMIS's; a
	 * contact with no email gets one.
	 *
	 * @return void
	 */
	public function test_apply(): void {
		$party   = json_decode( (string) wp_json_encode( self::party() ) );
		$details = [
			'first' => 'Anne',
			'last'  => '',
			'email' => 'new@example.org',
		];
		$this->assertTrue( Wormiss_Member_Push::apply( $party, $details ) );
		// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- iMIS fields.
		$this->assertSame( 'Anne', $party->PersonName->FirstName );
		$this->assertSame( 'Smith', $party->PersonName->LastName );
		$this->assertSame( 'new@example.org', $party->Emails->{'$values'}[1]->Address );
		$this->assertSame( 'work@example.org', $party->Emails->{'$values'}[0]->Address );
		$this->assertFalse( Wormiss_Member_Push::apply( $party, $details ) );

		$bare = (object) [ 'PersonName' => (object) [] ];
		$this->assertTrue( Wormiss_Member_Push::apply( $bare, $details ) );
		$this->assertSame( 'new@example.org', $bare->Emails->{'$values'}[0]->Address );
		$this->assertTrue( $bare->Emails->{'$values'}[0]->IsPrimary );

		// No primary email: the Work address is kept and a primary one is added.
		$work = json_decode( '{"Emails":{"$values":[{"Address":"work@example.org","EmailType":"Work","IsPrimary":false}]}}' );
		$this->assertTrue( Wormiss_Member_Push::apply( $work, $details ) );
		$this->assertSame( 'work@example.org', $work->Emails->{'$values'}[0]->Address );
		$this->assertFalse( $work->Emails->{'$values'}[0]->IsPrimary );
		$this->assertSame( 'new@example.org', $work->Emails->{'$values'}[1]->Address );
		$this->assertTrue( $work->Emails->{'$values'}[1]->IsPrimary );

		// No primary email, but one already holds this address: it becomes the primary.
		$same = json_decode( '{"Emails":{"$values":[{"Address":"work@example.org","EmailType":"Work","IsPrimary":false}]}}' );
		$this->assertTrue( Wormiss_Member_Push::apply( $same, [ 'email' => 'WORK@example.org' ] + $details ) );
		$this->assertCount( 1, $same->Emails->{'$values'} );
		$this->assertTrue( $same->Emails->{'$values'}[0]->IsPrimary );
		// phpcs:enable
	}

	/**
	 * A save that changes neither name nor email (a password, a biography) sends nothing, even
	 * for a member never sent before, so older WordPress details never replace newer iMIS ones.
	 *
	 * @return void
	 */
	public function test_other_changes_send_nothing(): void {
		$this->turn_on( false );
		$user_id = $this->member();
		wp_update_user(
			[
				'ID'        => $user_id,
				'user_pass' => 'A new password 123!',
			]
		);
		wp_update_user(
			[
				'ID'          => $user_id,
				'description' => 'Bio only',
			]
		);
		$this->assertFalse( wp_next_scheduled( Wormiss_Member_Push::HOOK, [ $user_id ] ) );
	}

	/**
	 * When iMIS cannot be reached, the send is tried again later, a few times, then stops.
	 *
	 * @return void
	 */
	public function test_retries(): void {
		$this->turn_on( false );
		$user_id = $this->member();
		for ( $try = 1; $try < Wormiss_Member_Push::MAX_TRIES; $try++ ) {
			$this->imis->token()->reply( '/api/Party/12345', 503, 'Service Unavailable' );
			$this->assertSame( 'error', Wormiss_Member_Push::push( $user_id ) );
			$this->assertNotFalse( wp_next_scheduled( Wormiss_Member_Push::HOOK, [ $user_id ] ), 'Try ' . $try );
			$this->assertSame( $try, (int) get_user_meta( $user_id, Wormiss_Member_Push::META_TRIES, true ) );
			wp_unschedule_hook( Wormiss_Member_Push::HOOK );
		}
		$this->imis->token()->reply( '/api/Party/12345', 503, 'Service Unavailable' );
		$this->assertSame( 'error', Wormiss_Member_Push::push( $user_id ) );
		$this->assertFalse( wp_next_scheduled( Wormiss_Member_Push::HOOK, [ $user_id ] ), 'Stops after the last try.' );
		$this->assertSame( '', get_user_meta( $user_id, Wormiss_Member_Push::META_TRIES, true ) );
	}

	/**
	 * A member's change is queued; staff, members without an iMIS ID and the member sync's own
	 * changes are not.
	 *
	 * @return void
	 */
	public function test_queue_rules(): void {
		$this->turn_on( false );
		$member = $this->member();
		wp_update_user(
			[
				'ID'         => $member,
				'first_name' => 'Anne',
			]
		);
		$this->assertNotFalse( wp_next_scheduled( Wormiss_Member_Push::HOOK, [ $member ] ) );

		$staff = $this->member( 'editor' );
		wp_update_user(
			[
				'ID'        => $staff,
				'last_name' => 'Jones',
			]
		);
		$this->assertFalse( wp_next_scheduled( Wormiss_Member_Push::HOOK, [ $staff ] ) );

		$none = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		wp_update_user(
			[
				'ID'        => $none,
				'last_name' => 'Jones',
			]
		);
		$this->assertFalse( wp_next_scheduled( Wormiss_Member_Push::HOOK, [ $none ] ) );

		$synced                      = $this->member();
		Wormiss_User_Sync::$applying = true;
		wp_update_user(
			[
				'ID'        => $synced,
				'last_name' => 'From iMIS',
			]
		);
		Wormiss_User_Sync::$applying = false;
		$this->assertFalse( wp_next_scheduled( Wormiss_Member_Push::HOOK, [ $synced ] ) );
	}

	/**
	 * Live: the contact is read, changed and saved; a second send with nothing new sends nothing.
	 *
	 * @return void
	 */
	public function test_sends(): void {
		$this->turn_on( false );
		$user_id = $this->member();
		wp_update_user(
			[
				'ID'         => $user_id,
				'user_email' => 'new@example.org',
			]
		);
		$this->imis->token()
			->reply( '/api/Party/12345', 200, self::party() )
			->reply( '/api/Party/12345', 200, '{}' );
		$this->assertSame( 'sent', Wormiss_Member_Push::push( $user_id ) );
		$this->assertSame( 'PUT', $this->imis->requests[2]['method'] );
		$body = json_decode( (string) $this->imis->requests[2]['body'], true );
		$this->assertSame( 'new@example.org', $body['Emails']['$values'][1]['Address'] );
		$this->assertSame( 'Asi.Soa.Membership.DataContracts.PersonData, Asi.Contracts', $body['$type'] );

		// Saving again with nothing iMIS needs changed queues nothing.
		wp_unschedule_hook( Wormiss_Member_Push::HOOK );
		wp_update_user(
			[
				'ID'          => $user_id,
				'description' => 'Bio only',
			]
		);
		$this->assertFalse( wp_next_scheduled( Wormiss_Member_Push::HOOK, [ $user_id ] ) );
	}

	/**
	 * A dry run logs what would be saved and saves nothing; unchanged contacts send nothing.
	 *
	 * @return void
	 */
	public function test_dry_run_and_unchanged(): void {
		$this->turn_on( true );
		$user_id = $this->member();
		wp_update_user(
			[
				'ID'         => $user_id,
				'first_name' => 'Anne',
			]
		);
		$this->imis->token()->reply( '/api/Party/12345', 200, self::party() );
		$this->assertSame( 'dry_run', Wormiss_Member_Push::push( $user_id ) );
		$this->assertSame( 1, $this->imis->count( '/api/Party/12345' ) );

		$same = $this->member( 'subscriber', 'old@example.org' ); // As in iMIS.
		$this->imis->token()->reply( '/api/Party/12345', 200, self::party() );
		$this->assertSame( 'unchanged', Wormiss_Member_Push::push( $same ) );
	}
}
