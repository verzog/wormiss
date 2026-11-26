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
 * Tests for the internal Staff and Manager roles and the per-user departments.
 *
 * @package    wormiss
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Internal roles and departments.
 *
 * @covers Wormiss_Roles
 */
class Test_Wormiss_Roles extends Wormiss_Test_Case {

	/**
	 * Register the roles.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();
		Wormiss_Roles::install();
	}

	/**
	 * Both roles are registered, and the capability-set version is recorded.
	 *
	 * @return void
	 */
	public function test_install_registers_roles(): void {
		$this->assertInstanceOf( WP_Role::class, get_role( Wormiss_Roles::ROLE_STAFF ) );
		$this->assertInstanceOf( WP_Role::class, get_role( Wormiss_Roles::ROLE_MANAGER ) );
		$this->assertSame( Wormiss_Roles::CAPS_VERSION, (int) get_option( Wormiss_Roles::VERSION_OPTION ) );
		$this->assertSame( [ Wormiss_Roles::ROLE_STAFF, Wormiss_Roles::ROLE_MANAGER ], Wormiss_Roles::role_slugs() );
	}

	/**
	 * Staff can author and upload; Manager is a superset with oversight and the Wormiss caps; neither
	 * is a site administrator.
	 *
	 * @return void
	 */
	public function test_capabilities(): void {
		$staff   = get_role( Wormiss_Roles::ROLE_STAFF );
		$manager = get_role( Wormiss_Roles::ROLE_MANAGER );

		$this->assertTrue( $staff->has_cap( 'upload_files' ) );
		$this->assertTrue( $staff->has_cap( 'edit_posts' ) );
		$this->assertTrue( $staff->has_cap( 'publish_posts' ) );
		$this->assertFalse( $staff->has_cap( 'edit_others_posts' ) );
		$this->assertFalse( $staff->has_cap( Wormiss_Roles::CAP_CONFIGURE ) );
		$this->assertFalse( $staff->has_cap( 'manage_options' ) );

		// Manager keeps every Staff capability.
		$this->assertTrue( $manager->has_cap( 'upload_files' ) );
		$this->assertTrue( $manager->has_cap( 'edit_posts' ) );
		// ...and adds oversight, reporting, configuration and media management.
		$this->assertTrue( $manager->has_cap( 'edit_others_posts' ) );
		$this->assertTrue( $manager->has_cap( Wormiss_Roles::CAP_VIEW_REPORTS ) );
		$this->assertTrue( $manager->has_cap( Wormiss_Roles::CAP_CONFIGURE ) );
		$this->assertTrue( $manager->has_cap( Wormiss_Roles::CAP_MANAGE_MEDIA ) );
		$this->assertFalse( $manager->has_cap( 'manage_options' ), 'Manager is not a site administrator.' );
	}

	/**
	 * Re-syncing the roles (reactivation or a caps-version upgrade) keeps the site's default role,
	 * even when that default is one of the Wormiss roles.
	 *
	 * @return void
	 */
	public function test_install_preserves_default_role(): void {
		update_option( 'default_role', Wormiss_Roles::ROLE_STAFF );
		Wormiss_Roles::install();
		$this->assertSame( Wormiss_Roles::ROLE_STAFF, get_option( 'default_role' ), 'Default role not reset to subscriber.' );
		$this->assertInstanceOf( WP_Role::class, get_role( Wormiss_Roles::ROLE_STAFF ) );
	}

	/**
	 * The six departments are defined.
	 *
	 * @return void
	 */
	public function test_departments(): void {
		$slugs = array_keys( Wormiss_Roles::departments() );
		$this->assertSame(
			[ 'marketing', 'membership', 'education', 'management', 'events', 'operations' ],
			$slugs
		);
		$this->assertTrue( Wormiss_Roles::is_department( 'events' ) );
		$this->assertFalse( Wormiss_Roles::is_department( 'finance' ) );
	}

	/**
	 * A user's departments round-trip; unknown slugs are dropped; a person can be in several; an
	 * empty set clears the stored value.
	 *
	 * @return void
	 */
	public function test_user_departments(): void {
		$user = self::factory()->user->create( [ 'role' => Wormiss_Roles::ROLE_STAFF ] );

		Wormiss_Roles::set_user_departments( $user, [ 'marketing', 'education', 'bogus' ] );
		$this->assertSame( [ 'marketing', 'education' ], Wormiss_Roles::user_departments( $user ), 'Multi-department, unknown dropped.' );
		$this->assertTrue( Wormiss_Roles::in_department( $user, 'marketing' ) );
		$this->assertFalse( Wormiss_Roles::in_department( $user, 'operations' ) );

		Wormiss_Roles::set_user_departments( $user, [] );
		$this->assertSame( [], Wormiss_Roles::user_departments( $user ) );
		$this->assertSame( '', get_user_meta( $user, Wormiss_Roles::META_DEPARTMENTS, true ), 'Cleared, not stored.' );
	}

	/**
	 * Only the Manager role reports as a manager.
	 *
	 * @return void
	 */
	public function test_is_manager(): void {
		$manager = self::factory()->user->create( [ 'role' => Wormiss_Roles::ROLE_MANAGER ] );
		$staff   = self::factory()->user->create( [ 'role' => Wormiss_Roles::ROLE_STAFF ] );
		$admin   = self::factory()->user->create( [ 'role' => 'administrator' ] );
		$this->assertTrue( Wormiss_Roles::is_manager( $manager ) );
		$this->assertFalse( Wormiss_Roles::is_manager( $staff ) );
		$this->assertFalse( Wormiss_Roles::is_manager( $admin ) );

		wp_set_current_user( $manager );
		$this->assertTrue( Wormiss_Roles::is_manager() );
	}

	/**
	 * Saving the profile field needs the capability and a valid nonce, then stores the chosen
	 * departments.
	 *
	 * @return void
	 */
	public function test_save_departments(): void {
		$target = self::factory()->user->create( [ 'role' => Wormiss_Roles::ROLE_STAFF ] );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$_POST['wormiss_departments']       = [ 'marketing', 'events' ];
		$_POST['wormiss_departments_nonce'] = wp_create_nonce( 'wormiss_departments_' . $target );
		Wormiss_Roles::save_departments( $target );
		$this->assertSame( [ 'marketing', 'events' ], Wormiss_Roles::user_departments( $target ) );

		// A bad nonce changes nothing.
		$_POST['wormiss_departments']       = [ 'operations' ];
		$_POST['wormiss_departments_nonce'] = 'not-a-real-nonce';
		Wormiss_Roles::save_departments( $target );
		$this->assertSame( [ 'marketing', 'events' ], Wormiss_Roles::user_departments( $target ), 'Bad nonce ignored.' );

		// Without the capability, nothing changes (even with a would-be nonce).
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );
		$_POST['wormiss_departments']       = [ 'operations' ];
		$_POST['wormiss_departments_nonce'] = wp_create_nonce( 'wormiss_departments_' . $target );
		Wormiss_Roles::save_departments( $target );
		$this->assertSame( [ 'marketing', 'events' ], Wormiss_Roles::user_departments( $target ), 'No capability, no change.' );

		// A staff member cannot set their OWN departments through their profile (edit_user is granted
		// for one's own account, but edit_users is required).
		$self = self::factory()->user->create( [ 'role' => Wormiss_Roles::ROLE_STAFF ] );
		wp_set_current_user( $self );
		$_POST['wormiss_departments']       = [ 'management' ];
		$_POST['wormiss_departments_nonce'] = wp_create_nonce( 'wormiss_departments_' . $self );
		Wormiss_Roles::save_departments( $self );
		$this->assertSame( [], Wormiss_Roles::user_departments( $self ), 'Cannot self-assign departments.' );

		unset( $_POST['wormiss_departments'], $_POST['wormiss_departments_nonce'] );
	}

	/**
	 * Uninstall removes the roles, the version option and the stored departments.
	 *
	 * @return void
	 */
	public function test_uninstall(): void {
		$user = self::factory()->user->create( [ 'role' => Wormiss_Roles::ROLE_STAFF ] );
		Wormiss_Roles::set_user_departments( $user, [ 'marketing' ] );

		Wormiss_Roles::uninstall();
		$this->assertNull( get_role( Wormiss_Roles::ROLE_STAFF ) );
		$this->assertNull( get_role( Wormiss_Roles::ROLE_MANAGER ) );
		$this->assertFalse( get_option( Wormiss_Roles::VERSION_OPTION ) );
		$this->assertSame( [], Wormiss_Roles::user_departments( $user ) );
	}
}
