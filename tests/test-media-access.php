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
 * Tests for the department x category media visibility gate.
 *
 * @package    wormiss
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Media access control (media spec phase 2).
 *
 * @covers Wormiss_Media_Access
 */
class Test_Wormiss_Media_Access extends Wormiss_Test_Case {

	/**
	 * Register the taxonomy and roles, and build the department categories and default matrix.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();
		Wormiss_Media_Categories::register();
		Wormiss_Roles::install();
		Wormiss_Media_Access::install();
	}

	/**
	 * A department's category term ID.
	 *
	 * @param string $department Department slug.
	 * @return int
	 */
	private function department_term( string $department ): int {
		$terms = get_option( Wormiss_Media_Access::DEPARTMENT_TERMS_OPTION );
		return (int) ( $terms[ $department ] ?? 0 );
	}

	/**
	 * An attachment with an author and optional categories.
	 *
	 * @param int   $author   Author user ID.
	 * @param int[] $term_ids Media category term IDs.
	 * @return int
	 */
	private function attachment( int $author, array $term_ids = [] ): int {
		$id = (int) self::factory()->post->create(
			[
				'post_type'      => 'attachment',
				'post_mime_type' => 'image/jpeg',
				'post_status'    => 'inherit',
				'post_author'    => $author,
			]
		);
		if ( $term_ids ) {
			wp_set_object_terms( $id, $term_ids, Wormiss_Media_Categories::TAXONOMY );
		}
		return $id;
	}

	/**
	 * A Wormiss Staff user in the given departments.
	 *
	 * @param string[] $departments Department slugs.
	 * @return int
	 */
	private function staff( array $departments = [] ): int {
		$id = (int) self::factory()->user->create( [ 'role' => Wormiss_Roles::ROLE_STAFF ] );
		if ( $departments ) {
			Wormiss_Roles::set_user_departments( $id, $departments );
		}
		return $id;
	}

	/**
	 * The attachment IDs the media modal/grid query returns for a user (gated).
	 *
	 * @param int $user_id User ID.
	 * @return int[]
	 */
	private function visible_to( int $user_id ): array {
		wp_set_current_user( $user_id );
		$args  = Wormiss_Media_Access::gate_ajax_args(
			[
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'posts_per_page' => -1,
				'orderby'        => 'ID',
				'order'          => 'ASC',
				'fields'         => 'ids',
			]
		);
		$query = new WP_Query( $args );
		return array_map( 'intval', $query->posts );
	}

	/**
	 * Installing creates a category per department and defaults the matrix to each department's own
	 * category.
	 *
	 * @return void
	 */
	public function test_install(): void {
		$terms = get_option( Wormiss_Media_Access::DEPARTMENT_TERMS_OPTION );
		$this->assertCount( 6, $terms );
		foreach ( Wormiss_Roles::departments() as $slug => $label ) {
			$term = get_term( (int) $terms[ $slug ], Wormiss_Media_Categories::TAXONOMY );
			$this->assertInstanceOf( WP_Term::class, $term );
			$this->assertSame( $label, $term->name );
			$this->assertSame( 0, (int) $term->parent );
		}
		$matrix = Wormiss_Media_Access::matrix();
		$this->assertSame( [ $this->department_term( 'marketing' ) ], $matrix['marketing'] );
	}

	/**
	 * Allowed terms are the union across a user's departments, including sub-categories.
	 *
	 * @return void
	 */
	public function test_allowed_term_ids(): void {
		$marketing = $this->department_term( 'marketing' );
		$education = $this->department_term( 'education' );
		$child     = (int) self::factory()->term->create(
			[
				'taxonomy' => Wormiss_Media_Categories::TAXONOMY,
				'name'     => 'Campaigns',
				'parent'   => $marketing,
			]
		);

		$user    = $this->staff( [ 'marketing', 'education' ] );
		$allowed = Wormiss_Media_Access::allowed_term_ids( $user );
		sort( $allowed );
		$expected = [ $marketing, $education, $child ];
		sort( $expected );
		$this->assertSame( $expected, $allowed, 'Union of both departments plus the sub-category.' );

		$this->assertSame( [], Wormiss_Media_Access::allowed_term_ids( $this->staff() ), 'No departments: nothing allowed.' );
	}

	/**
	 * Administrators are exempt; staff are not.
	 *
	 * @return void
	 */
	public function test_is_exempt(): void {
		$admin = (int) self::factory()->user->create( [ 'role' => 'administrator' ] );
		$this->assertTrue( Wormiss_Media_Access::is_exempt( $admin ) );
		$this->assertFalse( Wormiss_Media_Access::is_exempt( $this->staff( [ 'marketing' ] ) ) );
	}

	/**
	 * A non-admin sees their own uploads and images in their departments' categories, and nothing
	 * else; an administrator's query is not gated.
	 *
	 * @return void
	 */
	public function test_query_gate(): void {
		$admin    = (int) self::factory()->user->create( [ 'role' => 'administrator' ] );
		$mk_staff = $this->staff( [ 'marketing' ] );
		$ed_staff = $this->staff( [ 'education' ] );

		$mk_img      = $this->attachment( $admin, [ $this->department_term( 'marketing' ) ] );
		$ed_img      = $this->attachment( $admin, [ $this->department_term( 'education' ) ] );
		$own_uncat   = $this->attachment( $mk_staff );
		$other_uncat = $this->attachment( $ed_staff );
		$end_user    = $this->attachment( $admin, [ Wormiss_Media_Categories::reserved_term_id() ] );

		$seen = $this->visible_to( $mk_staff );
		sort( $seen );
		$expected = [ $mk_img, $own_uncat ];
		sort( $expected );
		$this->assertSame( $expected, $seen, 'Marketing image and own upload only.' );
		$this->assertNotContains( $ed_img, $seen, 'Not another department.' );
		$this->assertNotContains( $other_uncat, $seen, 'Not another staffer\'s uncategorised upload.' );
		$this->assertNotContains( $end_user, $seen, 'Not End-user images (ungranted by default).' );

		// Administrators are not gated: gate_ajax_args adds no flag and they see everything.
		wp_set_current_user( $admin );
		$args = Wormiss_Media_Access::gate_ajax_args( [ 'post_type' => 'attachment' ] );
		$this->assertArrayNotHasKey( Wormiss_Media_Access::QUERY_FLAG, $args );
		$all = $this->visible_to( $admin );
		$this->assertContains( $mk_img, $all );
		$this->assertContains( $other_uncat, $all );
		$this->assertContains( $end_user, $all );
	}

	/**
	 * A staffer with no departments sees only their own uploads.
	 *
	 * @return void
	 */
	public function test_no_department_sees_only_own(): void {
		$admin  = (int) self::factory()->user->create( [ 'role' => 'administrator' ] );
		$loner  = $this->staff();
		$own    = $this->attachment( $loner );
		$others = $this->attachment( $admin, [ $this->department_term( 'marketing' ) ] );

		$seen = $this->visible_to( $loner );
		$this->assertSame( [ $own ], $seen );
		$this->assertNotContains( $others, $seen );
	}

	/**
	 * Per-image capability checks deny edit/read/delete of an attachment the user may not see, and
	 * allow it for their own upload, an allowed category, or an administrator.
	 *
	 * @return void
	 */
	public function test_map_cap(): void {
		$admin    = (int) self::factory()->user->create( [ 'role' => 'administrator' ] );
		$mk_staff = $this->staff( [ 'marketing' ] );

		$mk_img    = $this->attachment( $admin, [ $this->department_term( 'marketing' ) ] );
		$ed_img    = $this->attachment( $admin, [ $this->department_term( 'education' ) ] );
		$own_uncat = $this->attachment( $mk_staff );

		foreach ( [ 'edit_post', 'read_post', 'delete_post' ] as $cap ) {
			$this->assertSame(
				[ 'do_not_allow' ],
				Wormiss_Media_Access::map_cap( [ 'exists' ], $cap, $mk_staff, [ $ed_img ] ),
				"$cap denied for another department's image."
			);
			$this->assertSame(
				[ 'exists' ],
				Wormiss_Media_Access::map_cap( [ 'exists' ], $cap, $mk_staff, [ $mk_img ] ),
				"$cap allowed for an image in the user's category."
			);
			$this->assertSame(
				[ 'exists' ],
				Wormiss_Media_Access::map_cap( [ 'exists' ], $cap, $mk_staff, [ $own_uncat ] ),
				"$cap allowed for the user's own upload."
			);
			$this->assertSame(
				[ 'exists' ],
				Wormiss_Media_Access::map_cap( [ 'exists' ], $cap, $admin, [ $ed_img ] ),
				"$cap allowed for an administrator."
			);
		}

		// Non-attachment posts and unrelated capabilities are untouched.
		$post = (int) self::factory()->post->create( [ 'post_author' => $admin ] );
		$this->assertSame( [ 'exists' ], Wormiss_Media_Access::map_cap( [ 'exists' ], 'edit_post', $mk_staff, [ $post ] ) );
		$this->assertSame( [ 'exists' ], Wormiss_Media_Access::map_cap( [ 'exists' ], 'manage_options', $mk_staff, [ $mk_img ] ) );
	}

	/**
	 * Single-item reads are gated too: the REST media item endpoint returns 403 for an image the user
	 * may not see, and 200 for their own or an allowed category; administrators always get 200.
	 *
	 * @return void
	 */
	public function test_single_item_reads(): void {
		$admin    = (int) self::factory()->user->create( [ 'role' => 'administrator' ] );
		$mk_staff = $this->staff( [ 'marketing' ] );
		$mk_img   = $this->attachment( $admin, [ $this->department_term( 'marketing' ) ] );
		$ed_img   = $this->attachment( $admin, [ $this->department_term( 'education' ) ] );
		$own      = $this->attachment( $mk_staff );

		$status = function ( int $user, int $id ): int {
			wp_set_current_user( $user );
			$response = rest_do_request( new WP_REST_Request( 'GET', '/wp/v2/media/' . $id ) );
			return $response->get_status();
		};

		$this->assertSame( 403, $status( $mk_staff, $ed_img ), 'Another department: forbidden.' );
		$this->assertSame( 200, $status( $mk_staff, $mk_img ), 'Allowed category: visible.' );
		$this->assertSame( 200, $status( $mk_staff, $own ), 'Own upload: visible.' );
		$this->assertSame( 200, $status( $admin, $ed_img ), 'Administrator: visible.' );

		// The single-item AJAX handler relies on this representation; it is emptied when forbidden.
		wp_set_current_user( $mk_staff );
		$this->assertSame( [], Wormiss_Media_Access::gate_js_item( [ 'id' => $ed_img ], get_post( $ed_img ) ) );
		$this->assertNotSame( [], Wormiss_Media_Access::gate_js_item( [ 'id' => $mk_img ], get_post( $mk_img ) ) );
		wp_set_current_user( $admin );
		$this->assertNotSame( [], Wormiss_Media_Access::gate_js_item( [ 'id' => $ed_img ], get_post( $ed_img ) ), 'Admin not gated.' );
	}

	/**
	 * The REST media query is gated for a non-admin and left alone for an administrator.
	 *
	 * @return void
	 */
	public function test_rest_and_list_gating(): void {
		$admin    = (int) self::factory()->user->create( [ 'role' => 'administrator' ] );
		$mk_staff = $this->staff( [ 'marketing' ] );
		$request  = new WP_REST_Request( 'GET', '/wp/v2/media' );

		wp_set_current_user( $mk_staff );
		$args = Wormiss_Media_Access::gate_rest_args( [ 'post_type' => 'attachment' ], $request );
		$this->assertSame( $mk_staff, $args[ Wormiss_Media_Access::QUERY_FLAG ] );

		wp_set_current_user( $admin );
		$args = Wormiss_Media_Access::gate_rest_args( [ 'post_type' => 'attachment' ], $request );
		$this->assertArrayNotHasKey( Wormiss_Media_Access::QUERY_FLAG, $args );
	}
}
