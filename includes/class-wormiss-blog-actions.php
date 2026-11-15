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
 * Members' blog actions: like and save a post, share its link, jump to its comments.
 *
 * @package    wormiss
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined( 'ABSPATH' ) || exit;

/**
 * The rail beside a blog post, as on the iMIS blog. Readers can like a post (everyone sees how
 * many have) and save it to find again (the blog filters can show saved posts only). A like is
 * one post meta row holding the member's user ID; a save is one user meta row holding the post
 * ID, so neither needs a read-modify-write that two clicks at once could spoil.
 *
 * The buttons are a plain form posted to the post itself, so they work without JavaScript; the
 * script sends the same form in the background and updates the buttons.
 */
class Wormiss_Blog_Actions {

	/**
	 * Post meta: one row per member who likes the post (their user ID).
	 */
	const META_LIKE = '_wormiss_blog_like';

	/**
	 * User meta: one row per post the member has saved (its ID).
	 */
	const META_SAVED = 'wormiss_blog_saved';

	/**
	 * The form field naming the action.
	 */
	const FIELD = 'wormiss_blog_action';

	/**
	 * Hook in.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'template_redirect', [ __CLASS__, 'handle' ], 5 );
		add_action( 'deleted_user', [ __CLASS__, 'delete_for_user' ] );
	}

	/**
	 * The nonce action for a post's buttons.
	 *
	 * @param int $post_id Post ID.
	 * @return string
	 */
	private static function nonce_action( int $post_id ): string {
		return 'wormiss_blog_action_' . $post_id;
	}

	/**
	 * Whether a post is a published blog post.
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	private static function is_blog( int $post_id ): bool {
		$post = get_post( $post_id );
		return $post instanceof WP_Post && Wormiss_Blog::POST_TYPE === $post->post_type && 'publish' === $post->post_status;
	}

	/**
	 * How many members like a post.
	 *
	 * @param int $post_id Post ID.
	 * @return int
	 */
	public static function like_count( int $post_id ): int {
		// Unique: two presses at once could add a member twice.
		return count( array_unique( array_map( 'strval', get_post_meta( $post_id, self::META_LIKE, false ) ) ) );
	}

	/**
	 * Whether a member likes a post.
	 *
	 * @param int $post_id Post ID.
	 * @param int $user_id User ID.
	 * @return bool
	 */
	public static function likes( int $post_id, int $user_id ): bool {
		return $user_id > 0 && in_array( (string) $user_id, array_map( 'strval', get_post_meta( $post_id, self::META_LIKE, false ) ), true );
	}

	/**
	 * The posts a member has saved.
	 *
	 * @param int $user_id User ID.
	 * @return int[]
	 */
	public static function saved( int $user_id ): array {
		if ( $user_id <= 0 ) {
			return [];
		}
		return array_values( array_unique( array_filter( array_map( 'intval', (array) get_user_meta( $user_id, self::META_SAVED, false ) ) ) ) );
	}

	/**
	 * Whether a member has saved a post.
	 *
	 * @param int $post_id Post ID.
	 * @param int $user_id User ID.
	 * @return bool
	 */
	public static function has_saved( int $post_id, int $user_id ): bool {
		return in_array( $post_id, self::saved( $user_id ), true );
	}

	/**
	 * Like or unlike, save or unsave a post for a member who can read the blog.
	 *
	 * @param int    $post_id Post ID.
	 * @param int    $user_id User ID.
	 * @param string $action  like or save.
	 * @return array|null The state afterwards (liked, likes, saved), or null when not allowed.
	 */
	public static function toggle( int $post_id, int $user_id, string $action ): ?array {
		if ( $user_id <= 0 || ! self::is_blog( $post_id ) || ! Wormiss_Blog::can_read( $user_id ) ) {
			return null;
		}
		if ( 'like' === $action ) {
			if ( self::likes( $post_id, $user_id ) ) {
				delete_post_meta( $post_id, self::META_LIKE, (string) $user_id );
			} else {
				add_post_meta( $post_id, self::META_LIKE, (string) $user_id );
			}
		} elseif ( 'save' === $action ) {
			if ( self::has_saved( $post_id, $user_id ) ) {
				delete_user_meta( $user_id, self::META_SAVED, (string) $post_id );
			} else {
				add_user_meta( $user_id, self::META_SAVED, (string) $post_id );
			}
		} else {
			return null;
		}
		return self::state( $post_id, $user_id );
	}

	/**
	 * A post's state for a member.
	 *
	 * @param int $post_id Post ID.
	 * @param int $user_id User ID.
	 * @return array liked, likes, saved.
	 */
	public static function state( int $post_id, int $user_id ): array {
		return [
			'liked' => self::likes( $post_id, $user_id ),
			'likes' => self::like_count( $post_id ),
			'saved' => self::has_saved( $post_id, $user_id ),
		];
	}

	/**
	 * A button press, sent to the post: checked, applied, then back to the post (or, from the
	 * script, the new state as JSON).
	 *
	 * @return void
	 */
	public static function handle(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Checked below, before anything changes.
		if ( ! isset( $_SERVER['REQUEST_METHOD'] ) || 'POST' !== $_SERVER['REQUEST_METHOD'] || ! isset( $_POST[ self::FIELD ] ) ) {
			return;
		}
		$action  = sanitize_key( wp_unslash( $_POST[ self::FIELD ] ) );
		$post_id = isset( $_POST['wormiss_blog_post'] ) ? absint( $_POST['wormiss_blog_post'] ) : 0;
		$nonce   = isset( $_POST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ) : '';
		$ajax    = isset( $_POST['wormiss_ajax'] );
		// phpcs:enable WordPress.Security.NonceVerification.Missing
		$state = null;
		if ( is_user_logged_in() && wp_verify_nonce( $nonce, self::nonce_action( $post_id ) ) ) {
			$state = self::toggle( $post_id, get_current_user_id(), $action );
		}
		if ( $ajax ) {
			if ( null === $state ) {
				wp_send_json_error( [ 'message' => __( 'That did not work. Please reload the page and try again.', 'wormiss' ) ], 403 );
			}
			wp_send_json_success( $state );
		}
		$back = $post_id && self::is_blog( $post_id ) ? (string) get_permalink( $post_id ) : home_url( '/' );
		wp_safe_redirect( $back );
		exit;
	}

	/**
	 * Remove a member's likes and saves (account deleted, or privacy erasure).
	 *
	 * @param int $user_id User ID.
	 * @return int How many were removed.
	 */
	public static function delete_for_user( $user_id ): int {
		global $wpdb;
		$user_id = (int) $user_id;
		if ( $user_id <= 0 ) {
			return 0;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.SlowDBQuery -- One count before a bulk delete.
		$likes = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = %s", self::META_LIKE, (string) $user_id ) );
		if ( $likes ) {
			delete_metadata( 'post', 0, self::META_LIKE, (string) $user_id, true );
		}
		$saves = count( self::saved( $user_id ) );
		delete_user_meta( $user_id, self::META_SAVED );
		return $likes + $saves;
	}

	/**
	 * The rail for the Blog post actions block.
	 *
	 * @param int $post_id Post ID.
	 * @return string
	 */
	public static function render( int $post_id ): string {
		$post = get_post( $post_id );
		if ( ! $post instanceof WP_Post || Wormiss_Blog::POST_TYPE !== $post->post_type ) {
			return '';
		}
		$user_id  = get_current_user_id();
		$reader   = $user_id && Wormiss_Blog::can_read( $user_id );
		$state    = self::state( $post_id, $user_id );
		$comments = (int) get_comments_number( $post );
		$html     = '';
		if ( $reader ) {
			$html .= '<form class="wormiss-blog-actions__form" method="post" action="' . esc_url( (string) get_permalink( $post ) ) . '">'
				. wp_nonce_field( self::nonce_action( $post_id ), '_wpnonce', false, false )
				. '<input type="hidden" name="wormiss_blog_post" value="' . esc_attr( (string) $post_id ) . '" />'
				. self::button( 'like', $state['liked'], __( 'Like', 'wormiss' ), __( 'Liked', 'wormiss' ), $state['likes'] )
				. self::button( 'save', $state['saved'], __( 'Save', 'wormiss' ), __( 'Saved', 'wormiss' ), null )
				. '</form>';
		} else {
			$html .= '<span class="wormiss-blog-actions__item wormiss-blog-actions__likes">' . self::icon( 'like' )
				. '<span class="wormiss-blog-actions__label">' . esc_html__( 'Likes', 'wormiss' ) . '</span>'
				. '<span class="wormiss-blog-actions__count">' . esc_html( number_format_i18n( $state['likes'] ) ) . '</span></span>';
		}
		// The Comments title carries id="comments" once there are some; before that, the form.
		$html .= '<a class="wormiss-blog-actions__item wormiss-blog-actions__comments" href="' . ( $comments ? '#comments' : '#respond' ) . '">'
			. self::icon( 'comment' ) . '<span class="wormiss-blog-actions__label">' . esc_html__( 'Comments', 'wormiss' ) . '</span>'
			. '<span class="wormiss-blog-actions__count">' . esc_html( number_format_i18n( $comments ) ) . '</span></a>';
		// Sharing needs the script (copy the link, or the device's share sheet): hidden until it runs.
		$html .= '<button type="button" class="wormiss-blog-actions__item wormiss-blog-actions__share" hidden'
			. ' data-url="' . esc_url( (string) get_permalink( $post ) ) . '" data-title="' . esc_attr( wp_strip_all_tags( get_the_title( $post ) ) ) . '"'
			. ' data-copied="' . esc_attr__( 'Link copied', 'wormiss' ) . '">'
			. self::icon( 'share' ) . '<span class="wormiss-blog-actions__label">' . esc_html__( 'Share', 'wormiss' ) . '</span></button>';
		return $html . '<span class="wormiss-blog-actions__status" role="status" aria-live="polite" data-error="'
			. esc_attr__( 'That did not work. Please reload the page and try again.', 'wormiss' ) . '"></span>';
	}

	/**
	 * A like or save button.
	 *
	 * @param string   $action  like or save.
	 * @param bool     $pressed Whether it is on.
	 * @param string   $off     Label when off.
	 * @param string   $on      Label when on.
	 * @param int|null $count   A count to show, or null.
	 * @return string
	 */
	private static function button( string $action, bool $pressed, string $off, string $on, ?int $count ): string {
		return '<button type="submit" class="wormiss-blog-actions__item wormiss-blog-actions__' . esc_attr( $action ) . '" name="' . esc_attr( self::FIELD ) . '"'
			. ' value="' . esc_attr( $action ) . '" aria-pressed="' . ( $pressed ? 'true' : 'false' ) . '"'
			. ' data-off="' . esc_attr( $off ) . '" data-on="' . esc_attr( $on ) . '">'
			. self::icon( $action ) . '<span class="wormiss-blog-actions__label">' . esc_html( $pressed ? $on : $off ) . '</span>'
			. ( null === $count ? '' : '<span class="wormiss-blog-actions__count">' . esc_html( number_format_i18n( $count ) ) . '</span>' )
			. '</button>';
	}

	/**
	 * A small line icon.
	 *
	 * @param string $name like, save, comment or share.
	 * @return string SVG.
	 */
	private static function icon( string $name ): string {
		$paths = [
			'like'    => '<path d="M12 21s-7.5-4.6-9.5-9.2C1 8.2 3.4 4.5 7 4.5c2 0 3.6 1.1 5 3 1.4-1.9 3-3 5-3 3.6 0 6 3.7 4.5 7.3C19.5 16.4 12 21 12 21z"/>',
			'save'    => '<path d="M6 3h12v18l-6-4-6 4z"/>',
			'comment' => '<path d="M21 12a8 8 0 0 1-11.6 7.1L4 20l1-4.6A8 8 0 1 1 21 12z"/>',
			'share'   => '<circle cx="18" cy="5" r="3"/><circle cx="6" cy="12" r="3"/><circle cx="18" cy="19" r="3"/><path d="M8.6 13.5l6.8 4M15.4 6.5l-6.8 4"/>',
		];
		return '<svg class="wormiss-blog-actions__icon" width="20" height="20" viewBox="0 0 24 24" aria-hidden="true" focusable="false" fill="none"'
			. ' stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' . ( $paths[ $name ] ?? '' ) . '</svg>';
	}
}
