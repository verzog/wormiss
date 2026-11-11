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
 * Members-only content: who may see a post, page or block, by iMIS membership.
 *
 * A member's membership record is read with the approved access query (their iMIS ID
 * as the first prompt), through the per-member cache. Access fails closed: no
 * sign-in, no iMIS ID, no matching record or an unreachable iMIS (with no earlier
 * copy) all mean no access.
 *
 * @package    wormiss
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined( 'ABSPATH' ) || exit;

/**
 * Decides access to members-only content.
 */
class Wormiss_Access {

	/** Post meta: whether the post is members-only. */
	const META_ENABLED = '_wormiss_members_only';

	/** Post meta: membership types allowed (comma-separated; blank for any). */
	const META_TYPES = '_wormiss_member_types';

	/** Post meta: membership statuses allowed (comma-separated; blank for the site default). */
	const META_STATUSES = '_wormiss_member_statuses';

	/**
	 * Membership records already read this request, by user ID.
	 *
	 * @var array
	 */
	private static array $profiles = [];

	/**
	 * Forget the records read this request (tests, and after a user changes).
	 *
	 * @return void
	 */
	public static function reset(): void {
		self::$profiles = [];
	}

	/**
	 * A post's access rules, or null if it is not members-only.
	 *
	 * @param int $post_id Post ID.
	 * @return array|null {types: string[], statuses: string[]}
	 */
	public static function post_rules( int $post_id ): ?array {
		if ( ! $post_id || ! get_post_meta( $post_id, self::META_ENABLED, true ) ) {
			return null;
		}
		return [
			'types'    => Wormiss_Settings::parse_list( (string) get_post_meta( $post_id, self::META_TYPES, true ) ),
			'statuses' => Wormiss_Settings::parse_list( (string) get_post_meta( $post_id, self::META_STATUSES, true ) ),
		];
	}

	/**
	 * Whether a user meets access rules.
	 *
	 * @param array    $rules   {types: string[], statuses: string[]}; empty lists mean any
	 *                          type and the site's default statuses.
	 * @param int|null $user_id User (default: current).
	 * @return bool
	 */
	public static function user_meets( array $rules, ?int $user_id = null ): bool {
		$user_id = null === $user_id ? get_current_user_id() : $user_id;
		if ( ! $user_id ) {
			return false;
		}
		$types    = array_map( 'strtolower', $rules['types'] ?? [] );
		$statuses = array_map( 'strtolower', $rules['statuses'] ?? [] );
		if ( ! $statuses ) {
			$statuses = array_map( 'strtolower', (array) Wormiss_Settings::get( 'access_statuses' ) );
		}

		$profile = self::profile( $user_id );
		if ( null === $profile ) {
			return false;
		}
		if ( $types && ! in_array( strtolower( (string) $profile['type'] ), $types, true ) ) {
			return false;
		}
		if ( $statuses && ! in_array( strtolower( (string) $profile['status'] ), $statuses, true ) ) {
			return false;
		}
		return true;
	}

	/**
	 * Whether the current user may see a post in full (editors of the post always may).
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	public static function can_view_post( int $post_id ): bool {
		$rules = self::post_rules( $post_id );
		if ( null === $rules || current_user_can( 'edit_post', $post_id ) ) {
			return true;
		}
		return self::user_meets( $rules );
	}

	/**
	 * A user's membership record: {type, status}, or null if they have none.
	 *
	 * Without an access query, any user with an iMIS ID counts as a member with no
	 * known type or status (so only rules without types or statuses can pass).
	 *
	 * @param int $user_id User ID.
	 * @return array|null
	 */
	public static function profile( int $user_id ): ?array {
		if ( array_key_exists( $user_id, self::$profiles ) ) {
			return self::$profiles[ $user_id ];
		}
		$profile = null;
		$imis_id = Wormiss_Member::imis_id( $user_id );
		$query   = (string) Wormiss_Settings::get( 'access_query' );
		if ( '' !== $imis_id && '' === $query ) {
			$profile = [
				'type'   => null,
				'status' => null,
			];
		} elseif ( '' !== $imis_id ) {
			try {
				$rows = Wormiss_Member_Display::get_rows( $query, [ $imis_id ], $imis_id );
				if ( $rows ) {
					$profile = [
						'type'   => self::scalar( $rows[0][ (string) Wormiss_Settings::get( 'access_type_column' ) ] ?? null ),
						'status' => self::scalar( $rows[0][ (string) Wormiss_Settings::get( 'access_status_column' ) ] ?? null ),
					];
				}
			} catch ( Wormiss_Exception $e ) {
				// iMIS could not be read and there is no earlier copy: no access (fail closed).
				$profile = null;
			}
		}
		self::$profiles[ $user_id ] = $profile;
		return $profile;
	}

	/**
	 * A value as a trimmed string, or null.
	 *
	 * @param mixed $value Value.
	 * @return string|null
	 */
	private static function scalar( $value ): ?string {
		return is_scalar( $value ) ? trim( (string) $value ) : null;
	}

	/**
	 * The message shown in place of members-only content.
	 *
	 * @param string $custom Custom text for signed-in visitors without access ('' for the default).
	 * @return string HTML.
	 */
	public static function notice( string $custom = '' ): string {
		if ( ! is_user_logged_in() ) {
			$here = is_singular() ? (string) get_permalink() : home_url( '/' );
			return '<div class="wormiss-members-only"><p>'
				. esc_html__( 'This content is for members.', 'wormiss' ) . ' <a href="' . esc_url( wp_login_url( $here ) ) . '">'
				. esc_html__( 'Sign in to read it.', 'wormiss' ) . '</a></p></div>';
		}
		$text = '' !== trim( $custom )
			? $custom
			: __( "This content is for members only, and your membership doesn't include it.", 'wormiss' );
		return '<div class="wormiss-members-only"><p>' . esc_html( $text ) . '</p></div>';
	}
}
