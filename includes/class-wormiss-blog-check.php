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
 * Check a blog post: ask iMIS, read only, what Smart Blogs keeps for one post.
 *
 * @package    wormiss
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined( 'ABSPATH' ) || exit;

/**
 * Before the members' blog is moved into WordPress, see how iMIS (Smart Blogs) keeps it: one
 * post with its text and preview decoded, its first comments, the fields of posts, comments
 * and the CPD logbook, the blog CPD rates, categories and settings. Administrators only; only
 * fixed lookups that read; only a blog key in the expected form is accepted.
 */
class Wormiss_Blog_Check {

	/**
	 * The admin-post action.
	 */
	public const ACTION = 'wormiss_blog_check';

	/**
	 * The lookups ({key} is the post's blog key, already made safe for an address).
	 */
	public const LOOKUPS = [
		[
			'label'  => 'Blog post',
			'method' => 'GET',
			'path'   => '/api/Smart_Blogs_Blog_Post?BlogKey={key}',
		],
		[
			'label'  => 'Its first comments (up to 25)',
			'method' => 'GET',
			'path'   => '/api/Smart_Blogs_Comment?BlogKey={key}&Limit=25',
		],
		[
			'label'  => 'Blog post fields',
			'method' => 'GET',
			'path'   => '/api/metadata/Smart_Blogs_Blog_Post',
		],
		[
			'label'  => 'Comment fields',
			'method' => 'GET',
			'path'   => '/api/metadata/Smart_Blogs_Comment',
		],
		[
			'label'  => 'Blog CPD rates',
			'method' => 'GET',
			'path'   => '/api/CPD_Smart_Blog',
		],
		[
			'label'  => 'CPD logbook fields',
			'method' => 'GET',
			'path'   => '/api/metadata/CPDLogbook',
		],
		[
			'label'  => 'Blog categories',
			'method' => 'GET',
			'path'   => '/api/Smart_Blogs_Category',
		],
		[
			'label'  => 'Smart Blogs settings',
			'method' => 'GET',
			'path'   => '/api/Smart_Blogs_Configuration',
		],
		[
			'label'  => 'Blog templates (layouts decoded)',
			'method' => 'GET',
			'path'   => '/api/Smart_Blogs_Template',
		],
		[
			'label'  => 'Blog template fields',
			'method' => 'GET',
			'path'   => '/api/metadata/Smart_Blogs_Template',
		],
	];

	/**
	 * Hook the button.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'admin_post_' . self::ACTION, [ __CLASS__, 'handle' ] );
	}

	/**
	 * Whether the text is a blog key: letters, digits, hyphens and underscores, up to 64.
	 *
	 * @param string $key Text entered.
	 * @return bool
	 */
	public static function is_key( string $key ): bool {
		return 1 === preg_match( '/^[A-Za-z0-9_-]{1,64}$/', $key );
	}

	/**
	 * Make the lookups for one post.
	 *
	 * @param string $key Blog key.
	 * @return array[] One per lookup (see Wormiss_Content_Check::lookup()).
	 */
	public static function run( string $key ): array {
		$key = trim( $key );
		if ( ! self::is_key( $key ) ) {
			return [];
		}
		$client  = new Wormiss_Rest_Client();
		$results = [];
		foreach ( self::LOOKUPS as $lookup ) {
			$results[] = Wormiss_Content_Check::lookup( $client, $lookup, rawurlencode( $key ) );
		}
		return $results;
	}

	/**
	 * Handle the button: administrators only, then show the replies once.
	 *
	 * @return void
	 */
	public static function handle(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'wormiss' ), 403 );
		}
		check_admin_referer( self::ACTION );
		$key = isset( $_POST['wormiss_blog_key'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['wormiss_blog_key'] ) ) ) : '';
		if ( ! self::is_key( $key ) ) {
			$result = [ 'error' => __( 'Enter a blog key (the BlogKey column of the blog post IQA).', 'wormiss' ) ];
		} elseif ( ! Wormiss_Settings::is_configured() ) {
			$result = [ 'error' => __( 'Fill in the iMIS address, username and password first.', 'wormiss' ) ];
		} else {
			$result = [
				'key'     => $key,
				'lookups' => self::run( $key ),
			];
		}
		set_transient( self::transient(), $result, 10 * MINUTE_IN_SECONDS );
		wp_safe_redirect( Wormiss_Admin::settings_url( 'blog' ) . '#wormiss-blog-check' );
		exit;
	}

	/**
	 * Where this administrator's last result is kept.
	 *
	 * @return string
	 */
	private static function transient(): string {
		return 'wormiss_blog_check_' . get_current_user_id();
	}

	/**
	 * The section on the settings page: the form, then the last result (shown once).
	 *
	 * @return void
	 */
	public static function render(): void {
		echo '<h2 id="wormiss-blog-check">' . esc_html__( 'Check a blog post', 'wormiss' ) . '</h2>';
		echo '<p class="description">'
			. esc_html__( 'Shows what iMIS (Smart Blogs) keeps for one members\' blog post, to plan moving the blog here:', 'wormiss' )
			. ' ' . esc_html__( 'the post (text and preview decoded), its first comments, the fields of posts, comments and the', 'wormiss' )
			. ' ' . esc_html__( 'CPD logbook, the blog CPD rates, categories and settings, and the blog templates with their', 'wormiss' )
			. ' ' . esc_html__( 'layouts (to match posts to the template they were made from). Nothing is changed in iMIS. Comments', 'wormiss' )
			. ' ' . esc_html__( 'include members\' iMIS IDs: the replies are also kept in the audit log.', 'wormiss' ) . '</p>';

		$result = get_transient( self::transient() );
		if ( is_array( $result ) ) {
			delete_transient( self::transient() );
			Wormiss_Content_Check::render_result( $result );
		}

		echo '<form action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" method="post">';
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '" />';
		wp_nonce_field( self::ACTION );
		echo '<p><label for="wormiss-blog-key">' . esc_html__( 'Blog key', 'wormiss' ) . '</label> ';
		echo '<input type="text" class="regular-text code" id="wormiss-blog-key" name="wormiss_blog_key" /> ';
		submit_button( __( 'Check blog post', 'wormiss' ), 'secondary', 'submit', false );
		echo '</p></form>';
	}
}
