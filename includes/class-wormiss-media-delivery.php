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
 * Byte-level protected delivery for flagged media categories (media spec phase 4, part two).
 *
 * Images in a category flagged for protected delivery (Wormiss_Media_Protected) are served through
 * a gated endpoint, /wormiss-media/<id>, instead of a public file URL. The endpoint checks the same
 * access the editor/library gate uses (administrators, the department x category matrix) plus the
 * members-only / blog gate for front-end members, then streams the bytes with nginx
 * X-Accel-Redirect (Apache X-Sendfile, or a PHP readfile fallback). The raw file is kept in a
 * private store outside the public path, so it has no directly reachable URL.
 *
 * Populating the private store for members' blog images, and rewriting old <img src> in blog
 * content, is wired in a following part; this part builds the store, the endpoint and the gate.
 *
 * nginx needs one internal location mapping the endpoint's X-Accel-Redirect to the private store,
 * e.g. (adjust the path to WORMISS_PROTECTED_DIR):
 *
 *   location /wormiss-media-internal/ { internal; alias /path/outside/webroot/wormiss-protected/; }
 *
 * @package    wormiss
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined( 'ABSPATH' ) || exit;

/**
 * The protected store and the gated delivery endpoint.
 */
class Wormiss_Media_Delivery {

	/** Query var the endpoint sets to the requested attachment ID. */
	const QUERY_VAR = 'wormiss_media';

	/** Attachment meta: the file's path inside the protected store (relative to protected_dir()). */
	const META_FILE = '_wormiss_protected_file';

	/** The nginx internal location the X-Accel-Redirect points into. */
	const INTERNAL_LOCATION = '/wormiss-media-internal/';

	/** Option marking the rewrite rule as registered (bumped to force a flush). */
	const REWRITE_OPTION = 'wormiss_media_rewrite';

	/** Current rewrite version. */
	const REWRITE_VERSION = 1;

	/** Query arg carrying the lock-to-post access token. */
	const LOCK_ARG = 't';

	/** Seconds a lock-to-post token is valid for (filterable via wormiss_media_lock_ttl). */
	const LOCK_TTL = 600;

	/**
	 * Hook in.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'init', [ __CLASS__, 'add_rewrite' ] );
		add_filter( 'query_vars', [ __CLASS__, 'query_vars' ] );
		add_action( 'template_redirect', [ __CLASS__, 'maybe_serve' ] );
		add_filter( 'wp_get_attachment_url', [ __CLASS__, 'filter_url' ], 10, 2 );
		// Sized images and srcset must not point at per-size file URLs the endpoint can't serve.
		add_filter( 'image_downsize', [ __CLASS__, 'filter_downsize' ], 10, 3 );
		add_filter( 'wp_calculate_image_srcset', [ __CLASS__, 'filter_srcset' ], 10, 5 );
		// Rewrite hardcoded protected-image URLs in post content (e.g. blog posts imported before the
		// category was protected) to the gated endpoint. Runs after Wormiss_Blog's content filter.
		add_filter( 'the_content', [ __CLASS__, 'rewrite_content' ], 20 );
		add_action( 'admin_init', [ __CLASS__, 'maybe_flush' ] );
		add_action( 'admin_notices', [ __CLASS__, 'fallback_notice' ] );
	}

	/**
	 * Register the endpoint rewrite rule.
	 *
	 * @return void
	 */
	public static function add_rewrite(): void {
		add_rewrite_rule( '^wormiss-media/([0-9]+)/?$', 'index.php?' . self::QUERY_VAR . '=$matches[1]', 'top' );
	}

	/**
	 * Flush the rewrite rules once after the endpoint is added or changed.
	 *
	 * @return void
	 */
	public static function maybe_flush(): void {
		if ( (int) get_option( self::REWRITE_OPTION, 0 ) !== self::REWRITE_VERSION ) {
			self::add_rewrite();
			flush_rewrite_rules( false );
			update_option( self::REWRITE_OPTION, self::REWRITE_VERSION, false );
		}
	}

	/**
	 * Register the endpoint query var.
	 *
	 * @param string[] $vars Query vars.
	 * @return string[]
	 */
	public static function query_vars( $vars ) {
		$vars   = is_array( $vars ) ? $vars : [];
		$vars[] = self::QUERY_VAR;
		return $vars;
	}

	/**
	 * The private store's directory (with a trailing slash). WORMISS_PROTECTED_DIR (an absolute path
	 * outside the web root) is used when set; otherwise a hardened folder under wp-content, which is
	 * not truly outside the web root (see fallback_notice()).
	 *
	 * @return string
	 */
	public static function protected_dir(): string {
		if ( defined( 'WORMISS_PROTECTED_DIR' ) && '' !== (string) WORMISS_PROTECTED_DIR ) {
			$dir = (string) WORMISS_PROTECTED_DIR;
		} else {
			$dir = WP_CONTENT_DIR . '/wormiss-protected';
		}
		/**
		 * The directory holding protected media (absolute path, outside the web root for real
		 * protection).
		 *
		 * @param string $dir Directory.
		 */
		$dir = (string) apply_filters( 'wormiss_protected_dir', $dir );
		return rtrim( $dir, '/\\' ) . '/';
	}

	/**
	 * Whether the store is the wp-content fallback (not truly outside the web root).
	 *
	 * @return bool
	 */
	public static function using_fallback(): bool {
		return ! ( defined( 'WORMISS_PROTECTED_DIR' ) && '' !== (string) WORMISS_PROTECTED_DIR );
	}

	/**
	 * Ensure the store exists, and harden the fallback (deny file and an index) so it is not served
	 * directly even though it sits under wp-content.
	 *
	 * @return bool Whether the directory is present.
	 */
	public static function ensure_store(): bool {
		$dir = self::protected_dir();
		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
			return false;
		}
		if ( self::using_fallback() ) {
			if ( ! file_exists( $dir . 'index.php' ) ) {
				// Silence directory listing.
				file_put_contents( $dir . 'index.php', "<?php\n// Silence is golden.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Local hardening file.
			}
			if ( ! file_exists( $dir . '.htaccess' ) ) {
				file_put_contents( $dir . '.htaccess', "Require all denied\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Apache deny for the fallback store.
			}
		}
		return is_dir( $dir );
	}

	/**
	 * The gated endpoint URL for an attachment, optionally carrying a lock-to-post token.
	 *
	 * @param int    $attachment Attachment ID.
	 * @param string $token      Lock token ('' for none).
	 * @return string
	 */
	public static function endpoint_url( int $attachment, string $token = '' ): string {
		$url = home_url( '/wormiss-media/' . $attachment );
		return '' !== $token ? add_query_arg( self::LOCK_ARG, $token, $url ) : $url;
	}

	/**
	 * The secret the lock tokens are signed with (per site; rotating the auth salt invalidates
	 * outstanding tokens, which only shortens their already-brief life).
	 *
	 * @return string
	 */
	private static function lock_secret(): string {
		return wp_salt( 'auth' );
	}

	/**
	 * How long a lock token is valid for, in seconds.
	 *
	 * @return int
	 */
	private static function lock_ttl(): int {
		/**
		 * Seconds a lock-to-post media token is valid for.
		 *
		 * @param int $ttl Seconds (default LOCK_TTL).
		 */
		$ttl = (int) apply_filters( 'wormiss_media_lock_ttl', self::LOCK_TTL );
		return max( 30, $ttl );
	}

	/**
	 * Mint a lock-to-post token binding an attachment to a post, a viewer and a short expiry.
	 *
	 * @param int $attachment Attachment ID.
	 * @param int $post_id    Post the image is embedded in.
	 * @param int $user_id    Viewer's user ID.
	 * @return string Token "post.expiry.signature".
	 */
	public static function mint_lock_token( int $attachment, int $post_id, int $user_id ): string {
		if ( $attachment < 1 || $post_id < 1 || $user_id < 1 ) {
			return '';
		}
		$expiry    = time() + self::lock_ttl();
		$signature = self::lock_signature( $attachment, $post_id, $user_id, $expiry );
		return $post_id . '.' . $expiry . '.' . $signature;
	}

	/**
	 * The HMAC for a lock token's parts.
	 *
	 * @param int $attachment Attachment ID.
	 * @param int $post_id    Post ID.
	 * @param int $user_id    User ID.
	 * @param int $expiry     Unix expiry.
	 * @return string
	 */
	private static function lock_signature( int $attachment, int $post_id, int $user_id, int $expiry ): string {
		$data = implode( '|', [ 'wormiss-media-lock', $attachment, $post_id, $user_id, $expiry ] );
		return hash_hmac( 'sha256', $data, self::lock_secret() );
	}

	/**
	 * Whether a lock token is valid for an attachment and the given viewer (right signature, not
	 * expired). The post is carried in the token and bound into the signature.
	 *
	 * @param int    $attachment Attachment ID.
	 * @param int    $user_id    Viewer's user ID.
	 * @param string $token      Token from the request.
	 * @return bool
	 */
	public static function valid_lock_token( int $attachment, int $user_id, string $token ): bool {
		if ( $attachment < 1 || $user_id < 1 || '' === $token ) {
			return false;
		}
		$parts = explode( '.', $token );
		if ( 3 !== count( $parts ) ) {
			return false;
		}
		$post_id = (int) $parts[0];
		$expiry  = (int) $parts[1];
		$given   = (string) $parts[2];
		if ( $post_id < 1 || $expiry < time() ) {
			return false;
		}
		// The token's post must be the image's own post (its post_parent), so a token can only serve
		// the image in the post it is attached to, never bound to some other page.
		if ( (int) get_post_field( 'post_parent', $attachment ) !== $post_id ) {
			return false;
		}
		$expected = self::lock_signature( $attachment, $post_id, $user_id, $expiry );
		return hash_equals( $expected, $given );
	}

	/**
	 * A lock token for the current render context (the post in the loop and the signed-in member),
	 * or '' when the attachment is not locked, there is no post context, or the viewer is an
	 * administrator (who does not need one).
	 *
	 * @param int $attachment Attachment ID.
	 * @return string
	 */
	private static function current_lock_token( int $attachment ): string {
		if ( ! Wormiss_Media_Protected::attachment_is_locked( $attachment ) ) {
			return '';
		}
		$user_id = get_current_user_id();
		// Mint only while actually rendering a post in the loop, for a signed-in member (admins need
		// no token). This stops bare wp_get_attachment_url() calls outside a post render — the
		// download handler, REST, the admin — from minting a token.
		if ( $user_id < 1 || Wormiss_Media_Access::is_exempt( $user_id ) || ! in_the_loop() ) {
			return '';
		}
		// The image must be the one attached to the post being rendered: lock-to-post binds an image
		// to its own post (its post_parent), which imported blog pictures are set to.
		$post_id = (int) get_the_ID();
		if ( $post_id < 1 || (int) get_post_field( 'post_parent', $attachment ) !== $post_id ) {
			return '';
		}
		return self::mint_lock_token( $attachment, $post_id, $user_id );
	}

	/**
	 * The protected-store path recorded for an attachment (relative to protected_dir()), or ''.
	 *
	 * @param int $attachment Attachment ID.
	 * @return string
	 */
	public static function stored_relative( int $attachment ): string {
		$rel = (string) get_post_meta( $attachment, self::META_FILE, true );
		// Keep it within the store: no absolute paths, no traversal.
		if ( '' === $rel || 0 === strpos( $rel, '/' ) || false !== strpos( $rel, '..' ) ) {
			return '';
		}
		return ltrim( $rel, '/\\' );
	}

	/**
	 * Whether an attachment's file is in the protected store.
	 *
	 * @param int $attachment Attachment ID.
	 * @return bool
	 */
	public static function is_stored( int $attachment ): bool {
		$rel = self::stored_relative( $attachment );
		return '' !== $rel && is_file( self::protected_dir() . $rel );
	}

	/**
	 * Move an attachment's original file into the protected store and remove the public copies, so
	 * its bytes are no longer reachable by a public URL. Records the store path on the attachment.
	 *
	 * @param int $attachment Attachment ID.
	 * @return bool Whether the file is now in the store.
	 */
	public static function store( int $attachment ): bool {
		if ( self::is_stored( $attachment ) ) {
			return true;
		}
		if ( ! self::ensure_store() ) {
			return false;
		}
		// Store the true original (the big-image original where WordPress scaled the upload down).
		$original = function_exists( 'wp_get_original_image_path' ) ? wp_get_original_image_path( $attachment ) : '';
		$source   = $original && is_file( $original ) ? $original : get_attached_file( $attachment );
		if ( ! $source || ! is_file( $source ) ) {
			return false;
		}
		$relative = $attachment . '/' . wp_basename( $source );
		$target   = self::protected_dir() . $relative;
		if ( ! wp_mkdir_p( dirname( $target ) ) ) {
			return false;
		}
		if ( ! @copy( $source, $target ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_copy -- Moving into the private store; failure is handled.
			return false;
		}
		update_post_meta( $attachment, self::META_FILE, $relative );
		// Remove every public copy — the attached (possibly scaled) file, the big-image original,
		// every generated size and every edit backup — so no public URL serves the bytes. Core's
		// own routine covers all of these.
		$attached = (string) get_attached_file( $attachment );
		$meta     = wp_get_attachment_metadata( $attachment );
		$meta     = is_array( $meta ) ? $meta : [];
		if ( empty( $meta['file'] ) && '' !== $attached ) {
			// wp_delete_attachment_files() reads $meta['file']; supply it for attachments that have
			// no generated metadata.
			$meta['file'] = _wp_relative_upload_path( $attached );
		}
		$backup = get_post_meta( $attachment, '_wp_attachment_backup_sizes', true );
		wp_delete_attachment_files( $attachment, $meta, is_array( $backup ) ? $backup : [], $attached );
		return true;
	}

	/**
	 * File an imported blog picture into the configured media category and, when that category is
	 * protected, move its bytes into the private store. Called at import time before the post's
	 * image URLs are resolved, so a protected picture's content URL is emitted as the gated endpoint.
	 *
	 * @param int $attachment Attachment ID.
	 * @return bool False when the picture should have been stored privately but could not be (so the
	 *              caller can leave the import incomplete and retry); true otherwise.
	 */
	public static function protect_imported( int $attachment ): bool {
		if ( $attachment < 1 ) {
			return false;
		}
		$term = (int) Wormiss_Settings::get( 'blog_media_category' );
		if ( $term > 0 && get_term( $term, Wormiss_Media_Categories::TAXONOMY ) instanceof WP_Term ) {
			wp_set_object_terms( $attachment, [ $term ], Wormiss_Media_Categories::TAXONOMY, true );
		}
		if ( Wormiss_Media_Protected::attachment_is_protected( $attachment ) ) {
			return self::store( $attachment );
		}
		return true;
	}

	/**
	 * Point a protected attachment's URL at the gated endpoint instead of its (now private) file.
	 *
	 * @param string $url           The attachment URL.
	 * @param int    $attachment_id Attachment ID.
	 * @return string
	 */
	public static function filter_url( $url, $attachment_id ) {
		$attachment_id = (int) $attachment_id;
		if ( $attachment_id > 0 && Wormiss_Media_Protected::attachment_is_protected( $attachment_id ) ) {
			return self::endpoint_url( $attachment_id, self::current_lock_token( $attachment_id ) );
		}
		return $url;
	}

	/**
	 * Serve any requested size of a protected image as the full image through the gated endpoint.
	 * Core's image_downsize() would otherwise rewrite the endpoint URL's basename to a per-size
	 * filename (e.g. /wormiss-media/photo-150x150.jpg), which the endpoint cannot serve.
	 *
	 * @param array|false  $out  Short-circuit value (false to let core handle it).
	 * @param int          $id   Attachment ID.
	 * @param string|int[] $size Requested size.
	 * @return array|false
	 */
	public static function filter_downsize( $out, $id, $size ) {
		unset( $size );
		$id = (int) $id;
		if ( $id < 1 || ! Wormiss_Media_Protected::attachment_is_protected( $id ) ) {
			return $out;
		}
		$meta   = wp_get_attachment_metadata( $id );
		$width  = is_array( $meta ) && isset( $meta['width'] ) ? (int) $meta['width'] : 0;
		$height = is_array( $meta ) && isset( $meta['height'] ) ? (int) $meta['height'] : 0;
		return [ self::endpoint_url( $id, self::current_lock_token( $id ) ), $width, $height, false ];
	}

	/**
	 * Drop the responsive srcset for a protected image: its per-size file URLs are not served by
	 * the endpoint. The single gated src (from filter_downsize) is used instead.
	 *
	 * @param array  $sources       Srcset sources.
	 * @param array  $size_array    Size.
	 * @param string $image_src    Image src.
	 * @param array  $image_meta    Image meta.
	 * @param int    $attachment_id Attachment ID.
	 * @return array
	 */
	public static function filter_srcset( $sources, $size_array, $image_src, $image_meta, $attachment_id ) {
		unset( $size_array, $image_src, $image_meta );
		return Wormiss_Media_Protected::attachment_is_protected( (int) $attachment_id ) ? [] : $sources;
	}

	/**
	 * The attachment ID an image src refers to: a gated endpoint URL (/wormiss-media/<id>, as the
	 * blog import stores) by its ID, or an uploads-file URL via core's resolver. 0 when neither.
	 *
	 * @param string $src Image src.
	 * @return int
	 */
	private static function attachment_from_src( string $src ): int {
		if ( preg_match( '#/wormiss-media/(\d+)\b#', $src, $matches ) ) {
			return (int) $matches[1];
		}
		$id = (int) attachment_url_to_postid( $src );
		if ( $id > 0 ) {
			return $id;
		}
		// A generated size (e.g. photo-300x200.jpg) is not its own attachment and attachment_url_to_postid
		// matches only the full-size file; strip the -WxH suffix and resolve that.
		$base = (string) preg_replace( '#\?.*$#', '', $src );
		$full = (string) preg_replace( '#-\d+x\d+(\.\w+)$#', '$1', $base );
		return $full !== $base ? (int) attachment_url_to_postid( $full ) : 0;
	}

	/**
	 * Rewrite hardcoded image URLs in post content so a protected picture's src points at the gated
	 * endpoint and its responsive attributes are dropped, minting a fresh lock token each render for
	 * a locked image. Resolves both uploads-file URLs (posts stored before their category was
	 * protected) and already-gated endpoint URLs (stored by the blog import, whose token must be
	 * refreshed per render). A no-op when nothing is protected.
	 *
	 * @param string $content Post content HTML.
	 * @return string
	 */
	public static function rewrite_content( $content ) {
		$content = (string) $content;
		if ( '' === $content || ! class_exists( '\WP_HTML_Tag_Processor' ) ) {
			return $content;
		}
		if ( ! Wormiss_Media_Protected::flagged_term_ids() || false === stripos( $content, '<img' ) ) {
			return $content;
		}
		$processor = new \WP_HTML_Tag_Processor( $content );
		$cache     = [];
		while ( $processor->next_tag( 'img' ) ) {
			$src = $processor->get_attribute( 'src' );
			if ( ! is_string( $src ) || '' === $src ) {
				continue;
			}
			// Prefer the wp-image-<ID> class WordPress adds to inserted images: it is exact and works
			// for a generated-size src. Fall back to resolving the src (endpoint, full, or sized URL).
			$class = (string) $processor->get_attribute( 'class' );
			if ( preg_match( '#\bwp-image-(\d+)\b#', $class, $m ) ) {
				$attachment = (int) $m[1];
			} else {
				if ( ! array_key_exists( $src, $cache ) ) {
					$cache[ $src ] = self::attachment_from_src( $src );
				}
				$attachment = $cache[ $src ];
			}
			if ( $attachment < 1 || ! Wormiss_Media_Protected::attachment_is_protected( $attachment ) ) {
				continue;
			}
			$processor->set_attribute( 'src', self::endpoint_url( $attachment, self::current_lock_token( $attachment ) ) );
			$processor->remove_attribute( 'srcset' );
			$processor->remove_attribute( 'sizes' );
		}
		return $processor->get_updated_html();
	}

	/**
	 * Whether a user may view a protected attachment: administrators, a user the editor/library gate
	 * allows (the department x category matrix), or a front-end member who may read members' content.
	 *
	 * @param int $attachment Attachment ID.
	 * @param int $user_id    User ID (0 for the current user).
	 * @return bool
	 */
	public static function can_view( int $attachment, int $user_id = 0 ): bool {
		$user_id = $user_id > 0 ? $user_id : get_current_user_id();
		if ( $user_id > 0 && Wormiss_Media_Access::user_can_see( $user_id, $attachment ) ) {
			return true;
		}
		// Front-end members (the same gate the members' blog uses).
		return Wormiss_Blog::can_read( $user_id );
	}

	/**
	 * Decide how to serve an endpoint request: the outcome and, when serving, the file.
	 *
	 * @param int    $attachment Attachment ID.
	 * @param int    $user_id    User ID (0 for the current user).
	 * @param string $token      Lock-to-post token from the request ('' when none).
	 * @return array{status: int, mode: string, path: string, relative: string}
	 */
	public static function decide( int $attachment, int $user_id = 0, string $token = '' ): array {
		$none = [
			'status'   => 404,
			'mode'     => 'none',
			'path'     => '',
			'relative' => '',
		];
		if ( 'attachment' !== get_post_type( $attachment ) || ! Wormiss_Media_Protected::attachment_is_protected( $attachment ) ) {
			// Not a protected image: nothing for this endpoint to serve.
			return $none;
		}
		if ( ! self::can_view( $attachment, $user_id ) ) {
			return array_merge( $none, [ 'status' => 403 ] );
		}
		// A locked image also needs a valid post-scoped token for this viewer; administrators are
		// exempt. Without it the URL is Forbidden out of the post it is embedded in.
		$uid = $user_id > 0 ? $user_id : get_current_user_id();
		if ( Wormiss_Media_Protected::attachment_is_locked( $attachment )
			&& ! Wormiss_Media_Access::is_exempt( $uid )
			&& ! self::valid_lock_token( $attachment, $uid, $token ) ) {
			return array_merge( $none, [ 'status' => 403 ] );
		}
		$relative = self::stored_relative( $attachment );
		if ( '' !== $relative && is_file( self::protected_dir() . $relative ) ) {
			return [
				'status'   => 200,
				'mode'     => self::serve_mode(),
				'path'     => self::protected_dir() . $relative,
				'relative' => $relative,
			];
		}
		// Not yet moved into the store (e.g. before the blog-import wiring): stream it through PHP,
		// still gated. Its public URL is only truly unreachable once the file is in the store.
		$file = get_attached_file( $attachment );
		if ( $file && is_file( $file ) ) {
			return [
				'status'   => 200,
				'mode'     => 'readfile',
				'path'     => $file,
				'relative' => '',
			];
		}
		return $none;
	}

	/**
	 * How a stored file is streamed: X-Sendfile (Apache, when configured) takes precedence, then the
	 * PHP readfile fallback (when X-Accel is disabled), else nginx X-Accel-Redirect.
	 *
	 * @return string One of 'x-sendfile', 'readfile', 'x-accel'.
	 */
	public static function serve_mode(): string {
		if ( defined( 'WORMISS_MEDIA_XSENDFILE' ) && WORMISS_MEDIA_XSENDFILE ) {
			$mode = 'x-sendfile';
		} elseif ( defined( 'WORMISS_MEDIA_NO_XACCEL' ) && WORMISS_MEDIA_NO_XACCEL ) {
			$mode = 'readfile';
		} else {
			$mode = 'x-accel';
		}
		/**
		 * How stored protected files are streamed ('x-accel', 'x-sendfile' or 'readfile').
		 *
		 * @param string $mode Mode.
		 */
		$mode = (string) apply_filters( 'wormiss_media_serve_mode', $mode );
		return in_array( $mode, [ 'x-accel', 'x-sendfile', 'readfile' ], true ) ? $mode : 'x-accel';
	}

	/**
	 * Serve a protected media request at the endpoint.
	 *
	 * @return void
	 */
	public static function maybe_serve(): void {
		$id = (int) get_query_var( self::QUERY_VAR );
		if ( $id < 1 ) {
			return;
		}
		// The lock token authenticates the request itself (an HMAC, not a form), so there is no nonce.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Token is verified in decide(); value is sanitised here.
		$token    = isset( $_GET[ self::LOCK_ARG ] ) ? sanitize_text_field( wp_unslash( $_GET[ self::LOCK_ARG ] ) ) : '';
		$decision = self::decide( $id, 0, $token );
		if ( 200 !== $decision['status'] ) {
			status_header( $decision['status'] );
			nocache_headers();
			exit;
		}
		self::send( $decision );
	}

	/**
	 * Send the file for a 200 decision (X-Accel-Redirect, X-Sendfile, or readfile), then stop.
	 *
	 * @param array $decision The decide() result.
	 * @return void
	 */
	private static function send( array $decision ): void {
		$path = (string) $decision['path'];
		$type = (string) ( wp_check_filetype( $path )['type'] ?? '' );
		nocache_headers();
		if ( '' !== $type ) {
			header( 'Content-Type: ' . $type );
		}
		if ( 'x-accel' === $decision['mode'] ) {
			$segments = array_map( 'rawurlencode', explode( '/', (string) $decision['relative'] ) );
			header( 'X-Accel-Redirect: ' . self::INTERNAL_LOCATION . implode( '/', $segments ) );
			exit;
		}
		if ( 'x-sendfile' === $decision['mode'] ) {
			header( 'X-Sendfile: ' . $path );
			exit;
		}
		$size = filesize( $path );
		header( 'Content-Length: ' . (string) ( false !== $size ? $size : 0 ) );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- Streaming a gated private file.
		readfile( $path );
		exit;
	}

	/**
	 * Warn administrators when the fallback store is in use (not outside the web root).
	 *
	 * @return void
	 */
	public static function fallback_notice(): void {
		if ( ! current_user_can( 'manage_options' ) || ! self::using_fallback() ) {
			return;
		}
		if ( ! Wormiss_Media_Protected::flagged_term_ids() ) {
			// Nothing is flagged for protection or lock-to-post yet, so the store is not needed.
			return;
		}
		echo '<div class="notice notice-warning"><p>'
			. esc_html__( 'Wormiss: protected media are stored under wp-content, which is not truly private. Set the WORMISS_PROTECTED_DIR constant (a path outside the web root) in wp-config.php and add the matching nginx internal location.', 'wormiss' )
			. '</p></div>';
	}
}
