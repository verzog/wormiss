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
 * Shows a signed-in member their own iMIS data (block and shortcode).
 *
 * An approved member query is run with the member's iMIS ID as its first prompt,
 * filled in on the server: a page or an editor can never choose whose data is read.
 *
 * @package    wormiss
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined( 'ABSPATH' ) || exit;

/**
 * Fetches (cached per member) and renders the signed-in member's results.
 */
class Wormiss_Member_Display {

	/** How long a member's last good copy is kept, to show while iMIS is unavailable. */
	const STALE_SECONDS = DAY_IN_SECONDS;

	/**
	 * Default display options: the shared ones plus the signed-out text.
	 *
	 * @return array
	 */
	public static function defaults(): array {
		return array_merge(
			Wormiss_Iqa_Display::defaults(),
			[
				'layout'          => 'details',
				'signed_out_text' => '',
			]
		);
	}

	/**
	 * The [wormiss_member] shortcode.
	 *
	 * @param array|string $atts Shortcode attributes.
	 * @return string
	 */
	public static function shortcode( $atts ): string {
		wp_enqueue_style( 'wormiss-iqa-style' );
		return self::render( shortcode_atts( self::defaults(), is_array( $atts ) ? $atts : [], 'wormiss_member' ) );
	}

	/**
	 * Render the block or shortcode for the current visitor.
	 *
	 * @param array $args Display options (see defaults()).
	 * @return string HTML.
	 */
	public static function render( array $args ): string {
		$args  = array_merge( self::defaults(), $args );
		$query = trim( (string) $args['query'] );

		if ( '' === $query ) {
			return Wormiss_Iqa_Display::editor_notice( __( 'Choose a member query in the block settings.', 'wormiss' ) );
		}
		if ( ! Wormiss_Settings::is_member_query( $query ) ) {
			return Wormiss_Iqa_Display::editor_notice(
				__( 'This iMIS query is not on the approved member-query list (Wormiss > Settings).', 'wormiss' )
			);
		}

		// Personal content: tell page-caching plugins and browsers not to store this page.
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- Caching-plugin convention.
			define( 'DONOTCACHEPAGE', true );
		}
		if ( ! headers_sent() ) {
			nocache_headers();
		}

		if ( ! is_user_logged_in() ) {
			return self::signed_out( (string) $args['signed_out_text'] );
		}
		$imis_id = Wormiss_Member::imis_id();
		if ( '' === $imis_id ) {
			return '<div class="wormiss-iqa wormiss-iqa--notice"><p>'
				. esc_html__( "Your account isn't linked to a membership record yet. Please contact us if you need help.", 'wormiss' )
				. '</p></div>';
		}

		// The member's ID always comes first; any extra prompt values follow it.
		$parameters = array_merge( [ $imis_id ], Wormiss_Iqa_Display::parse_parameters( (string) $args['parameters'] ) );
		try {
			$rows = self::get_rows( $query, $parameters, $imis_id );
		} catch ( Wormiss_Exception $e ) {
			return Wormiss_Iqa_Display::unavailable( $e->getMessage() );
		}
		return Wormiss_Iqa_Display::render_rows( $rows, $args );
	}

	/**
	 * A member's rows: cached copy, fresh read, or their last good copy if iMIS fails.
	 *
	 * @param string   $query      IQA path.
	 * @param string[] $parameters Prompt values, the member's ID first.
	 * @param string   $imis_id    The member's iMIS ID.
	 * @return array[]
	 * @throws Wormiss_Exception If iMIS fails and no earlier copy exists.
	 */
	public static function get_rows( string $query, array $parameters, string $imis_id ): array {
		$key    = self::cache_key( $query, $parameters, $imis_id );
		$cached = get_transient( $key );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		try {
			$rows = ( new Wormiss_Rest_Client() )->run_iqa( $query, $parameters, $imis_id );
		} catch ( Wormiss_Exception $e ) {
			$stale = get_transient( $key . '_stale' );
			if ( is_array( $stale ) ) {
				return $stale;
			}
			throw $e;
		}
		$minutes = max( 1, (int) Wormiss_Settings::get( 'member_cache_minutes' ) );
		set_transient( $key, $rows, $minutes * MINUTE_IN_SECONDS );
		set_transient( $key . '_stale', $rows, self::STALE_SECONDS );
		return $rows;
	}

	/**
	 * Cache key for one member's query on the current iMIS connection.
	 *
	 * @param string   $query      IQA path.
	 * @param string[] $parameters Prompt values.
	 * @param string   $imis_id    The member's iMIS ID.
	 * @return string
	 */
	public static function cache_key( string $query, array $parameters, string $imis_id ): string {
		$connection = Wormiss_Settings::base_url() . "\0" . Wormiss_Settings::username();
		return 'wormiss_member_' . md5( $connection . "\0" . $imis_id . "\0" . $query . "\0" . wp_json_encode( $parameters ) );
	}

	/**
	 * What signed-out visitors see: a sign-in link back to this page.
	 *
	 * @param string $text Custom text, or '' for the default.
	 * @return string
	 */
	private static function signed_out( string $text ): string {
		$text = '' !== trim( $text ) ? $text : __( 'Sign in to see your details.', 'wormiss' );
		$here = is_singular() ? (string) get_permalink() : home_url( '/' );
		return '<div class="wormiss-iqa wormiss-iqa--signed-out"><p><a href="' . esc_url( wp_login_url( $here ) ) . '">'
			. esc_html( $text ) . '</a></p></div>';
	}
}
