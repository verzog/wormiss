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
 * Tests for members-only content: posts, the Members only block and access rules.
 *
 * @package    wormiss
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Members-only content.
 *
 * @covers Wormiss_Access
 * @covers Wormiss_Members_Only
 */
class Test_Wormiss_Members_Only extends Wormiss_Test_Case {

	/** Membership query used by the tests. */
	const ACCESS_QUERY = '$/Members/MyMembership';

	/** Text only members should ever receive. */
	const SECRET = 'SECRET-MEMBERS-TEXT';

	/**
	 * Clear the per-request membership memo.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();
		Wormiss_Access::reset();
	}

	/**
	 * A members-only post with a public excerpt and private body.
	 *
	 * @param string $types    Allowed membership types.
	 * @param string $statuses Allowed statuses.
	 * @return int Post ID.
	 */
	private function members_post( string $types = '', string $statuses = '' ): int {
		$post_id = self::factory()->post->create(
			[
				'post_title'   => 'Members news',
				'post_excerpt' => 'Public teaser sentence.',
				'post_content' => '<!-- wp:paragraph --><p>Body with ' . self::SECRET . '.</p><!-- /wp:paragraph -->',
			]
		);
		update_post_meta( $post_id, Wormiss_Access::META_ENABLED, true );
		update_post_meta( $post_id, Wormiss_Access::META_TYPES, $types );
		update_post_meta( $post_id, Wormiss_Access::META_STATUSES, $statuses );
		return $post_id;
	}

	/**
	 * A subscriber with an iMIS ID.
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
	 * Turn on the membership query and serve the member's record.
	 *
	 * @param string $type   Membership type.
	 * @param string $status Status.
	 * @return void
	 */
	private function membership( string $type, string $status ): void {
		update_option( Wormiss_Settings::OPTION, array_merge( Wormiss_Settings::all(), [ 'access_query' => self::ACCESS_QUERY ] ) );
		$this->imis->token()->reply(
			'/api/iqa',
			200,
			Wormiss_Fake_Imis::page(
				[
					[
						'MemberType' => $type,
						'Status'     => $status,
					],
				]
			)
		);
	}

	/**
	 * The post's content as the current visitor would get it.
	 *
	 * @param int $post_id Post ID.
	 * @return string
	 */
	private function content( int $post_id ): string {
		$GLOBALS['post'] = get_post( $post_id ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Set up the loop.
		setup_postdata( $GLOBALS['post'] );
		$html = apply_filters( 'the_content', get_post_field( 'post_content', $post_id ) );
		wp_reset_postdata();
		return $html;
	}

	/**
	 * Ordinary posts are untouched.
	 *
	 * @return void
	 */
	public function test_public_post_untouched(): void {
		$post_id = self::factory()->post->create( [ 'post_content' => 'Hello ' . self::SECRET ] );
		$this->assertNull( Wormiss_Access::post_rules( $post_id ) );
		$this->assertStringContainsString( self::SECRET, $this->content( $post_id ) );
	}

	/**
	 * Visitors who are not signed in get the teaser and a sign-in link; iMIS is not contacted.
	 *
	 * @return void
	 */
	public function test_signed_out_gets_teaser(): void {
		$html = $this->content( $this->members_post() );
		$this->assertStringContainsString( 'Public teaser sentence.', $html );
		$this->assertStringContainsString( 'Sign in to read it.', $html );
		$this->assertStringNotContainsString( self::SECRET, $html );
		$this->assertSame( [], $this->imis->requests );
	}

	/**
	 * Without a membership query, any signed-in member with an iMIS ID reads the post; others do not.
	 *
	 * @return void
	 */
	public function test_linked_member_without_query(): void {
		$post_id = $this->members_post();
		wp_set_current_user( $this->member( '' ) );
		$this->assertStringNotContainsString( self::SECRET, $this->content( $post_id ) );

		Wormiss_Access::reset();
		wp_set_current_user( $this->member( '12345' ) );
		$this->assertStringContainsString( self::SECRET, $this->content( $post_id ) );
		$this->assertSame( [], $this->imis->requests );
	}

	/**
	 * Type and status rules use the member's record, ignoring case; the member ID is the first prompt.
	 *
	 * @return void
	 */
	public function test_type_and_status_rules(): void {
		wp_set_current_user( $this->member( '12345' ) );
		$this->membership( 'FELLOW', 'active' );
		$this->assertStringContainsString( self::SECRET, $this->content( $this->members_post( 'Fellow, Member', 'Active' ) ) );
		$this->assertStringContainsString( '&Parameter=12345&', $this->imis->requests[1]['url'] );

		// Same member, now a type the post does not allow (the record is cached per member).
		Wormiss_Access::reset();
		$html = $this->content( $this->members_post( 'Associate' ) );
		$this->assertStringNotContainsString( self::SECRET, $html );
		$this->assertStringContainsString( 'your membership doesn&#039;t include it', $html );
	}

	/**
	 * With no statuses on the post, the site's current statuses apply.
	 *
	 * @return void
	 */
	public function test_default_statuses(): void {
		wp_set_current_user( $this->member( '12345' ) );
		$this->membership( 'Member', 'Lapsed' );
		update_option( Wormiss_Settings::OPTION, array_merge( Wormiss_Settings::all(), [ 'access_statuses' => [ 'A', 'Active' ] ] ) );
		$this->assertStringNotContainsString( self::SECRET, $this->content( $this->members_post() ) );
	}

	/**
	 * If iMIS cannot be read and there is no earlier copy, the content stays hidden.
	 *
	 * @return void
	 */
	public function test_fails_closed(): void {
		wp_set_current_user( $this->member( '12345' ) );
		update_option( Wormiss_Settings::OPTION, array_merge( Wormiss_Settings::all(), [ 'access_query' => self::ACCESS_QUERY ] ) );
		$this->imis->token()->reply( '/api/iqa', 500, 'Server Error' );
		$this->assertStringNotContainsString( self::SECRET, $this->content( $this->members_post() ) );

		// A member with no membership record is not a member either.
		Wormiss_Access::reset();
		wp_set_current_user( $this->member( '67890' ) );
		$this->imis->token()->reply( '/api/iqa', 200, Wormiss_Fake_Imis::page( [] ) );
		$this->assertStringNotContainsString( self::SECRET, $this->content( $this->members_post() ) );
	}

	/**
	 * A password-protected members-only post keeps its password form and reveals no text.
	 *
	 * @return void
	 */
	public function test_password_protection_comes_first(): void {
		$post_id = $this->members_post();
		wp_update_post(
			[
				'ID'            => $post_id,
				'post_password' => 'letmein',
				'post_excerpt'  => '',
			]
		);
		$html = $this->content( $post_id );
		$this->assertStringContainsString( 'post-password-form', $html );
		$this->assertStringNotContainsString( self::SECRET, $html );
		$this->assertStringNotContainsString( 'Body with', $html );
	}

	/**
	 * Anyone who can edit the post always sees it in full.
	 *
	 * @return void
	 */
	public function test_editors_see_everything(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );
		$this->assertStringContainsString( self::SECRET, $this->content( $this->members_post( 'Fellow' ) ) );
		$this->assertSame( [], $this->imis->requests );
	}

	/**
	 * Excerpts (news cards, archives) carry the teaser text only: no body, no notice.
	 *
	 * @return void
	 */
	public function test_excerpt_is_teaser_text(): void {
		$post_id = $this->members_post();
		wp_update_post(
			[
				'ID'           => $post_id,
				'post_excerpt' => '',
			]
		);
		$long = str_repeat( 'Intro words here. ', 30 ) . self::SECRET;
		wp_update_post(
			[
				'ID'           => $post_id,
				'post_content' => '<!-- wp:paragraph --><p>' . $long . '</p><!-- /wp:paragraph -->',
			]
		);
		// As in a real loop (news cards, archives): the post is the current post.
		$GLOBALS['post'] = get_post( $post_id ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Set up the loop.
		setup_postdata( $GLOBALS['post'] );
		$excerpt = get_the_excerpt();
		wp_reset_postdata();
		$this->assertStringContainsString( 'Intro words here.', $excerpt );
		$this->assertStringNotContainsString( self::SECRET, $excerpt );
		$this->assertStringNotContainsString( 'Sign in', $excerpt );
	}

	/**
	 * The REST API gives visitors the teaser, never the members' text.
	 *
	 * @return void
	 */
	public function test_rest_api_gets_teaser(): void {
		$post_id  = $this->members_post();
		$response = rest_get_server()->dispatch( new WP_REST_Request( 'GET', '/wp/v2/posts/' . $post_id ) );
		$data     = $response->get_data();
		$this->assertStringNotContainsString( self::SECRET, $data['content']['rendered'] );
		$this->assertArrayNotHasKey( 'raw', $data['content'] );
		$this->assertStringContainsString( 'Sign in to read it.', $data['content']['rendered'] );
	}

	/**
	 * The Members only block shows its inner blocks to members, a notice or nothing to others.
	 *
	 * @return void
	 */
	public function test_members_only_block(): void {
		$markup = '<!-- wp:wormiss/members-only {"types":"Fellow"} --><!-- wp:paragraph --><p>'
			. self::SECRET . '</p><!-- /wp:paragraph --><!-- /wp:wormiss/members-only -->';
		$hidden = '<!-- wp:wormiss/members-only {"showNotice":false} --><!-- wp:paragraph --><p>'
			. self::SECRET . '</p><!-- /wp:paragraph --><!-- /wp:wormiss/members-only -->';

		$html = do_blocks( $markup );
		$this->assertStringNotContainsString( self::SECRET, $html );
		$this->assertStringContainsString( 'Sign in to read it.', $html );
		$this->assertSame( '', trim( do_blocks( $hidden ) ) );

		wp_set_current_user( $this->member( '12345' ) );
		$this->membership( 'Fellow', 'Active' );
		$this->assertStringContainsString( self::SECRET, do_blocks( $markup ) );
	}

	/**
	 * The access settings are registered for the editor, and lists are cleaned on save.
	 *
	 * @return void
	 */
	public function test_meta_registered_and_cleaned(): void {
		// The test framework resets post types before each test; register as init would.
		Wormiss_Members_Only::register_meta();
		$this->assertTrue( registered_meta_key_exists( 'post', Wormiss_Access::META_ENABLED, 'post' ) );
		$this->assertTrue( registered_meta_key_exists( 'post', Wormiss_Access::META_TYPES, 'page' ) );
		$post_id = self::factory()->post->create();
		update_post_meta( $post_id, Wormiss_Access::META_TYPES, ' Fellow ,, member , Fellow' );
		$this->assertSame( 'Fellow, member', get_post_meta( $post_id, Wormiss_Access::META_TYPES, true ) );
	}
}
