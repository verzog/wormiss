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
 * Internal staff roles and departments.
 *
 * Two levels of internal role — Staff (use and data entry) and Manager (reporting and
 * configuration) — paired with a separate, multi-valued "departments" field on each user's
 * profile (Marketing, Membership, Education, Management, Events, Operations). A person has one
 * level and may belong to several departments; the department decides what they may see or touch
 * (wired into the media categories and, later, content), the level decides how much they may do.
 *
 * Wormiss owns these roles: it registers them on activation, keeps their capabilities in step on
 * update, and removes them on uninstall.
 *
 * @package    wormiss
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined( 'ABSPATH' ) || exit;

/**
 * Registers the internal roles and the per-user departments.
 */
class Wormiss_Roles {

	/** Staff role (use and data entry). */
	const ROLE_STAFF = 'wormiss_staff';

	/** Manager role (reporting and configuration). */
	const ROLE_MANAGER = 'wormiss_manager';

	/** User meta holding the departments a person belongs to (an array of slugs). */
	const META_DEPARTMENTS = 'wormiss_departments';

	/** Option holding the capability-set version, so an update can re-sync the roles. */
	const VERSION_OPTION = 'wormiss_roles_version';

	/** Current capability-set version (bump when the capabilities below change). */
	const CAPS_VERSION = 1;

	/** Managers may see Wormiss reports. */
	const CAP_VIEW_REPORTS = 'wormiss_view_reports';

	/** Managers may change their department's Wormiss configuration. */
	const CAP_CONFIGURE = 'wormiss_configure';

	/** Managers may manage media categories and the role x category matrix (wired in a later phase). */
	const CAP_MANAGE_MEDIA = 'wormiss_manage_media_categories';

	/**
	 * The departments (slug => label).
	 *
	 * @return array<string, string>
	 */
	public static function departments(): array {
		return [
			'marketing'  => __( 'Marketing', 'wormiss' ),
			'membership' => __( 'Membership', 'wormiss' ),
			'education'  => __( 'Education', 'wormiss' ),
			'management' => __( 'Management', 'wormiss' ),
			'events'     => __( 'Events', 'wormiss' ),
			'operations' => __( 'Operations', 'wormiss' ),
		];
	}

	/**
	 * The Wormiss internal role slugs.
	 *
	 * @return string[]
	 */
	public static function role_slugs(): array {
		return [ self::ROLE_STAFF, self::ROLE_MANAGER ];
	}

	/**
	 * Hook in (the per-user departments field; the roles themselves are stored in the database by
	 * install()).
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'show_user_profile', [ __CLASS__, 'department_field' ] );
		add_action( 'edit_user_profile', [ __CLASS__, 'department_field' ] );
		add_action( 'personal_options_update', [ __CLASS__, 'save_departments' ] );
		add_action( 'edit_user_profile_update', [ __CLASS__, 'save_departments' ] );
	}

	/**
	 * The capabilities for each role. Manager is a superset of Staff.
	 *
	 * Staff: author and publish their own posts, and upload and categorise media. Manager adds
	 * oversight of others' posts, Wormiss reports and configuration, and media-category management.
	 * Neither gets full site administration (manage_options stays with administrators).
	 *
	 * @return array<string, array{0: string, 1: array<string, bool>}> Role slug => [label, caps].
	 */
	private static function definitions(): array {
		$staff   = [
			'read'                   => true,
			'upload_files'           => true,
			'edit_posts'             => true,
			'edit_published_posts'   => true,
			'publish_posts'          => true,
			'delete_posts'           => true,
			'delete_published_posts' => true,
		];
		$manager = array_merge(
			$staff,
			[
				'edit_others_posts'    => true,
				'delete_others_posts'  => true,
				'read_private_posts'   => true,
				'edit_private_posts'   => true,
				'delete_private_posts' => true,
				self::CAP_VIEW_REPORTS => true,
				self::CAP_CONFIGURE    => true,
				self::CAP_MANAGE_MEDIA => true,
			]
		);
		return [
			self::ROLE_STAFF   => [ __( 'Wormiss Staff', 'wormiss' ), $staff ],
			self::ROLE_MANAGER => [ __( 'Wormiss Manager', 'wormiss' ), $manager ],
		];
	}

	/**
	 * Create (or re-sync) the roles. Safe to call repeatedly: a role is re-added with the current
	 * capabilities, which keeps definitions in step without changing who holds each role.
	 *
	 * @return void
	 */
	public static function install(): void {
		foreach ( self::definitions() as $slug => $definition ) {
			list( $label, $caps ) = $definition;
			// Re-add so the capabilities always match the definition above; users keep the role.
			remove_role( $slug );
			add_role( $slug, $label, $caps );
		}
		update_option( self::VERSION_OPTION, self::CAPS_VERSION, false );
	}

	/**
	 * Re-sync the roles after an update, when the capability set has changed (activation does not
	 * run on an update).
	 *
	 * @return void
	 */
	public static function maybe_install(): void {
		if ( (int) get_option( self::VERSION_OPTION, 0 ) !== self::CAPS_VERSION ) {
			self::install();
		}
	}

	/**
	 * Remove the roles and the stored departments (for uninstall).
	 *
	 * @return void
	 */
	public static function uninstall(): void {
		foreach ( self::role_slugs() as $slug ) {
			remove_role( $slug );
		}
		delete_option( self::VERSION_OPTION );
		delete_metadata( 'user', 0, self::META_DEPARTMENTS, '', true );
	}

	/**
	 * Whether a slug is a known department.
	 *
	 * @param string $slug Department slug.
	 * @return bool
	 */
	public static function is_department( string $slug ): bool {
		return array_key_exists( $slug, self::departments() );
	}

	/**
	 * The departments a user belongs to (only known departments, in no particular order).
	 *
	 * @param int $user_id User ID.
	 * @return string[]
	 */
	public static function user_departments( int $user_id ): array {
		$value = get_user_meta( $user_id, self::META_DEPARTMENTS, true );
		$value = is_array( $value ) ? $value : [];
		return array_values( array_intersect( array_map( 'strval', $value ), array_keys( self::departments() ) ) );
	}

	/**
	 * Set the departments a user belongs to (unknown slugs are dropped; an empty list clears them).
	 *
	 * @param int      $user_id User ID.
	 * @param string[] $slugs   Department slugs.
	 * @return void
	 */
	public static function set_user_departments( int $user_id, array $slugs ): void {
		$clean = array_values( array_intersect( array_map( 'sanitize_key', $slugs ), array_keys( self::departments() ) ) );
		if ( $clean ) {
			update_user_meta( $user_id, self::META_DEPARTMENTS, $clean );
		} else {
			delete_user_meta( $user_id, self::META_DEPARTMENTS );
		}
	}

	/**
	 * Whether a user belongs to a department.
	 *
	 * @param int    $user_id    User ID.
	 * @param string $department Department slug.
	 * @return bool
	 */
	public static function in_department( int $user_id, string $department ): bool {
		return in_array( $department, self::user_departments( $user_id ), true );
	}

	/**
	 * Whether a user is a Wormiss Manager.
	 *
	 * @param int|WP_User|null $user User, ID, or null for the current user.
	 * @return bool
	 */
	public static function is_manager( $user = null ): bool {
		if ( $user instanceof WP_User ) {
			$object = $user;
		} elseif ( $user ) {
			$object = get_userdata( (int) $user );
		} else {
			$object = wp_get_current_user();
		}
		return $object instanceof WP_User && $object->exists()
			&& in_array( self::ROLE_MANAGER, array_map( 'strval', (array) $object->roles ), true );
	}

	/**
	 * The departments field on the user profile (a checkbox per department).
	 *
	 * @param WP_User $user The user being edited.
	 * @return void
	 */
	public static function department_field( $user ): void {
		if ( ! $user instanceof WP_User || ! current_user_can( 'edit_user', $user->ID ) ) {
			return;
		}
		$selected = self::user_departments( $user->ID );
		echo '<h2>' . esc_html__( 'Wormiss departments', 'wormiss' ) . '</h2>';
		wp_nonce_field( 'wormiss_departments_' . $user->ID, 'wormiss_departments_nonce' );
		echo '<table class="form-table" role="presentation"><tr>';
		echo '<th scope="row">' . esc_html__( 'Departments', 'wormiss' ) . '</th><td>';
		foreach ( self::departments() as $slug => $label ) {
			printf(
				'<label style="display:block;margin:2px 0;"><input type="checkbox" name="wormiss_departments[]" value="%1$s"%2$s /> %3$s</label>',
				esc_attr( $slug ),
				checked( in_array( $slug, $selected, true ), true, false ),
				esc_html( $label )
			);
		}
		echo '<p class="description">'
			. esc_html__( 'The departments this person works in. Used for media and content access.', 'wormiss' )
			. '</p>';
		echo '</td></tr></table>';
	}

	/**
	 * Save the departments field (capability, then nonce, then sanitise).
	 *
	 * @param int $user_id The user being saved.
	 * @return void
	 */
	public static function save_departments( $user_id ): void {
		$user_id = (int) $user_id;
		if ( ! current_user_can( 'edit_user', $user_id ) ) {
			return;
		}
		$nonce = isset( $_POST['wormiss_departments_nonce'] ) ? sanitize_key( wp_unslash( $_POST['wormiss_departments_nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'wormiss_departments_' . $user_id ) ) {
			return;
		}
		$raw = isset( $_POST['wormiss_departments'] ) && is_array( $_POST['wormiss_departments'] )
			? array_map( 'sanitize_key', wp_unslash( $_POST['wormiss_departments'] ) )
			: [];
		self::set_user_departments( $user_id, $raw );
	}
}
