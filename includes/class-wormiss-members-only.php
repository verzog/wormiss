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
 * Members-only posts and pages (teaser), the Members only block, and the editor panel.
 *
 * A members-only post shows non-members its title and excerpt, then a sign-in or
 * "not included" message. The content filter is what the page, feeds, the REST API
 * and post-content blocks all use, so the full text never reaches them.
 *
 * @package    wormiss
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined( 'ABSPATH' ) || exit;

/**
 * Enforces members-only content.
 */
class Wormiss_Members_Only {

	/**
	 * Hook the filters, meta and editor panel.
	 *
	 * @return void
	 */
	public static function init(): void {
		// After other plugins' custom post types (usually registered at priority 10).
		add_action( 'init', [ __CLASS__, 'register_meta' ], 20 );
		add_filter( 'the_content', [ __CLASS__, 'filter_content' ], 1 );
		add_action( 'enqueue_block_editor_assets', [ __CLASS__, 'editor_panel' ] );
	}

	/**
	 * Register the access post meta for every post type with an editor.
	 *
	 * @return void
	 */
	public static function register_meta(): void {
		$auth = static fn( $allowed, $meta_key, $post_id ) => current_user_can( 'edit_post', (int) $post_id );
		foreach ( get_post_types_by_support( 'editor' ) as $type ) {
			register_post_meta(
				$type,
				Wormiss_Access::META_ENABLED,
				[
					'type'          => 'boolean',
					'single'        => true,
					'default'       => false,
					'show_in_rest'  => true,
					'auth_callback' => $auth,
				]
			);
			foreach ( [ Wormiss_Access::META_TYPES, Wormiss_Access::META_STATUSES ] as $key ) {
				register_post_meta(
					$type,
					$key,
					[
						'type'              => 'string',
						'single'            => true,
						'default'           => '',
						'show_in_rest'      => true,
						'sanitize_callback' => static fn( $value ) => implode( ', ', Wormiss_Settings::parse_list( (string) $value ) ),
						'auth_callback'     => $auth,
					]
				);
			}
		}
	}

	/**
	 * Replace a members-only post's content with its teaser for visitors without access.
	 *
	 * @param string $content Post content.
	 * @return string
	 */
	public static function filter_content( $content ) {
		$post_id = (int) get_the_ID();
		if ( ! $post_id || null === Wormiss_Access::post_rules( $post_id ) ) {
			return $content;
		}
		// A password-protected post still needs its password: keep WordPress's password form,
		// which reveals nothing, rather than a teaser built from the hidden text.
		if ( post_password_required( $post_id ) ) {
			return $content;
		}
		if ( Wormiss_Access::can_view_post( $post_id ) ) {
			// A member's view of members-only content must not be stored by page caches.
			self::no_page_cache();
			return $content;
		}
		// Inside an excerpt (news cards, archives) just the teaser text, without the notice.
		if ( doing_filter( 'get_the_excerpt' ) ) {
			return esc_html( self::teaser_text( $post_id ) );
		}
		// Running first, this replaces the full text before blocks or shortcodes render it;
		// the later content filters only see the teaser.
		return self::teaser( $post_id );
	}

	/**
	 * The teaser text: the post's own excerpt, or the first 55 words of its text.
	 *
	 * @param int $post_id Post ID.
	 * @return string Plain text.
	 */
	public static function teaser_text( int $post_id ): string {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return '';
		}
		$text = '' !== trim( $post->post_excerpt )
			? $post->post_excerpt
			: wp_trim_words( excerpt_remove_blocks( strip_shortcodes( $post->post_content ) ), 55 );
		return trim( wp_strip_all_tags( $text ) );
	}

	/**
	 * The teaser: the teaser text, then the sign-in or "not included" notice.
	 *
	 * @param int $post_id Post ID.
	 * @return string HTML.
	 */
	public static function teaser( int $post_id ): string {
		$text = self::teaser_text( $post_id );
		$html = '' !== $text ? '<p class="wormiss-members-only__excerpt">' . esc_html( $text ) . '</p>' : '';
		return $html . Wormiss_Access::notice();
	}

	/**
	 * Render the Members only block: its inner blocks for members, the notice (or nothing) otherwise.
	 *
	 * @param array  $attributes Block attributes (types, statuses, showNotice, notice).
	 * @param string $content    Rendered inner blocks.
	 * @return string
	 */
	public static function render_block( array $attributes, string $content ): string {
		$rules   = [
			'types'    => Wormiss_Settings::parse_list( (string) ( $attributes['types'] ?? '' ) ),
			'statuses' => Wormiss_Settings::parse_list( (string) ( $attributes['statuses'] ?? '' ) ),
		];
		$post_id = (int) get_the_ID();
		if ( ( $post_id && current_user_can( 'edit_post', $post_id ) ) || Wormiss_Access::user_meets( $rules ) ) {
			self::no_page_cache();
			return $content;
		}
		if ( isset( $attributes['showNotice'] ) && ! $attributes['showNotice'] ) {
			return '';
		}
		return Wormiss_Access::notice( (string) ( $attributes['notice'] ?? '' ) );
	}

	/**
	 * Tell page-caching plugins and browsers not to store the page.
	 *
	 * @return void
	 */
	private static function no_page_cache(): void {
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- Caching-plugin convention.
			define( 'DONOTCACHEPAGE', true );
		}
		if ( ! headers_sent() ) {
			nocache_headers();
		}
	}

	/**
	 * Load the "Members only" panel into the post editor sidebar.
	 *
	 * @return void
	 */
	public static function editor_panel(): void {
		// Post and page editors only (not the Site Editor), for people who can edit posts.
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || 'post' !== $screen->base || ! current_user_can( 'edit_posts' ) ) {
			return;
		}
		$asset = include WORMISS_DIR . '/assets/editor-panel.asset.php';
		wp_enqueue_script(
			'wormiss-editor-panel',
			plugins_url( 'assets/editor-panel.js', WORMISS_FILE ),
			$asset['dependencies'],
			$asset['version'],
			true
		);
		wp_set_script_translations( 'wormiss-editor-panel', 'wormiss' );
		$data = [ 'accessQuery' => '' !== (string) Wormiss_Settings::get( 'access_query' ) ];
		wp_add_inline_script( 'wormiss-editor-panel', 'window.wormissAccess = ' . wp_json_encode( $data ) . ';', 'before' );
	}
}
