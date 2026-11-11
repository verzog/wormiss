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
 * The "Mark as done" block: a member records a completion (with optional CPD points).
 *
 * The button posts only which block was clicked; the activity name and points are
 * read from the saved block on the server, so a member cannot change them. Each
 * completion is stored on the member's account and counted once.
 *
 * @package    wormiss
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined( 'ABSPATH' ) || exit;

/**
 * Renders the Mark as done block and handles its button.
 */
class Wormiss_Completion {

	/** User meta key prefix for completions: _wormiss_done_{post}_{completion id}. */
	const META_PREFIX = '_wormiss_done_';

	/**
	 * Hook the button handler.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'admin_post_wormiss_complete', [ __CLASS__, 'handle_post' ] );
		add_action( 'admin_post_nopriv_wormiss_complete', [ __CLASS__, 'handle_post' ] );
	}

	/**
	 * The user meta key for one completion.
	 *
	 * @param int    $post_id       Post ID.
	 * @param string $completion_id Block's completion ID.
	 * @return string
	 */
	public static function meta_key( int $post_id, string $completion_id ): string {
		return self::META_PREFIX . $post_id . '_' . $completion_id;
	}

	/**
	 * A clean completion ID (letters, digits, dash; up to 64), or ''.
	 *
	 * @param string $id Raw ID.
	 * @return string
	 */
	public static function clean_id( string $id ): string {
		return preg_match( '/^[A-Za-z0-9-]{1,64}$/', $id ) ? $id : '';
	}

	/**
	 * Render the block.
	 *
	 * @param array $attributes Block attributes (completionId, label, doneLabel, activity, points).
	 * @return string HTML.
	 */
	public static function render( array $attributes ): string {
		$post_id = (int) get_the_ID();
		$id      = self::clean_id( (string) ( $attributes['completionId'] ?? '' ) );
		if ( ! $post_id || '' === $id ) {
			return '';
		}
		$label = '' !== trim( (string) ( $attributes['label'] ?? '' ) ) ? (string) $attributes['label'] : __( 'Mark as done', 'wormiss' );
		$done  = '' !== trim( (string) ( $attributes['doneLabel'] ?? '' ) ) ? (string) $attributes['doneLabel'] : __( 'Done', 'wormiss' );

		if ( ! is_user_logged_in() ) {
			$here = (string) get_permalink( $post_id );
			return '<p class="wormiss-complete"><a href="' . esc_url( wp_login_url( $here ) ) . '">'
				. esc_html__( 'Sign in to mark this as done.', 'wormiss' ) . '</a></p>';
		}
		if ( '' === Wormiss_Member::imis_id() ) {
			return '';
		}

		$when = (int) get_user_meta( get_current_user_id(), self::meta_key( $post_id, $id ), true );
		if ( $when ) {
			return '<p class="wormiss-complete wormiss-complete--done" id="wormiss-complete-' . esc_attr( $id ) . '">✓ '
				/* translators: 1: done label, 2: date. */
				. esc_html( sprintf( __( '%1$s on %2$s', 'wormiss' ), $done, wp_date( (string) get_option( 'date_format' ), $when ) ) )
				. '</p>';
		}

		return '<form class="wormiss-complete" id="wormiss-complete-' . esc_attr( $id ) . '" method="post" action="'
			. esc_url( admin_url( 'admin-post.php' ) ) . '">'
			. '<input type="hidden" name="action" value="wormiss_complete" />'
			. '<input type="hidden" name="post_id" value="' . esc_attr( (string) $post_id ) . '" />'
			. '<input type="hidden" name="completion_id" value="' . esc_attr( $id ) . '" />'
			. wp_nonce_field( 'wormiss_complete_' . $post_id . '_' . $id, '_wpnonce', true, false )
			. '<div class="wp-block-button"><button type="submit" class="wp-block-button__link wp-element-button">'
			. esc_html( $label ) . '</button></div></form>';
	}

	/**
	 * Find a Mark as done block in a post by its completion ID (searching nested blocks).
	 *
	 * @param int    $post_id       Post ID.
	 * @param string $completion_id Completion ID.
	 * @return array|null The block's attributes, or null.
	 */
	public static function find_block( int $post_id, string $completion_id ): ?array {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return null;
		}
		$stack = parse_blocks( $post->post_content );
		while ( $stack ) {
			$block = array_shift( $stack );
			if ( 'wormiss/complete' === ( $block['blockName'] ?? '' ) && ( $block['attrs']['completionId'] ?? '' ) === $completion_id ) {
				return $block['attrs'];
			}
			foreach ( $block['innerBlocks'] ?? [] as $inner ) {
				$stack[] = $inner;
			}
		}
		return null;
	}

	/**
	 * Record a completion for the current member.
	 *
	 * @param int    $post_id       Post ID.
	 * @param string $completion_id Completion ID.
	 * @return bool Whether it was newly completed.
	 */
	public static function complete( int $post_id, string $completion_id ): bool {
		$completion_id = self::clean_id( $completion_id );
		$user_id       = get_current_user_id();
		if ( ! $user_id || '' === $completion_id || '' === Wormiss_Member::imis_id( $user_id ) ) {
			return false;
		}
		// The member must be able to read the post (published, not password-locked, and not members-only beyond them).
		if ( 'publish' !== get_post_status( $post_id ) || post_password_required( $post_id )
			|| ! Wormiss_Access::can_view_post( $post_id ) ) {
			return false;
		}
		$attributes = self::find_block( $post_id, $completion_id );
		$key        = self::meta_key( $post_id, $completion_id );
		if ( null === $attributes || get_user_meta( $user_id, $key, true ) ) {
			return false;
		}
		update_user_meta( $user_id, $key, time() );

		$activity = trim( (string) ( $attributes['activity'] ?? '' ) );
		$activity = '' !== $activity ? $activity : get_the_title( $post_id );
		Wormiss_Activity::record(
			'completion',
			$activity,
			[
				'note'    => sprintf( 'Completed: %s (%s)', $activity, get_permalink( $post_id ) ),
				'points'  => max( 0, (float) ( $attributes['points'] ?? 0 ) ),
				'post_id' => $post_id,
			]
		);
		return true;
	}

	/**
	 * Handle the button (admin-post.php), then go back to the post.
	 *
	 * @return void
	 */
	public static function handle_post(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- The nonce names the post and block, so it is checked just below.
		$post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
		$raw_id  = isset( $_POST['completion_id'] ) ? sanitize_text_field( wp_unslash( $_POST['completion_id'] ) ) : '';
		// phpcs:enable
		$id = self::clean_id( $raw_id );
		check_admin_referer( 'wormiss_complete_' . $post_id . '_' . $id );
		self::complete( $post_id, $id );
		$back = get_permalink( $post_id );
		wp_safe_redirect( ( $back ? $back : home_url( '/' ) ) . '#wormiss-complete-' . rawurlencode( $id ) );
		exit;
	}

	/**
	 * Remove a person's completions (privacy erasure).
	 *
	 * @param int $user_id User ID.
	 * @return int Number removed.
	 */
	public static function delete_for_user( int $user_id ): int {
		$removed = 0;
		foreach ( array_keys( (array) get_user_meta( $user_id ) ) as $key ) {
			if ( 0 === strpos( (string) $key, self::META_PREFIX ) ) {
				delete_user_meta( $user_id, $key );
				++$removed;
			}
		}
		return $removed;
	}
}
