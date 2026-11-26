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
	 * The gated endpoint URL for an attachment.
	 *
	 * @param int $attachment Attachment ID.
	 * @return string
	 */
	public static function endpoint_url( int $attachment ): string {
		return home_url( '/wormiss-media/' . $attachment );
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
		$file = get_attached_file( $attachment );
		if ( ! $file || ! is_file( $file ) || ! self::ensure_store() ) {
			return false;
		}
		$relative = $attachment . '/' . wp_basename( $file );
		$target   = self::protected_dir() . $relative;
		if ( ! wp_mkdir_p( dirname( $target ) ) ) {
			return false;
		}
		if ( ! @copy( $file, $target ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_copy -- Moving into the private store; failure is handled.
			return false;
		}
		update_post_meta( $attachment, self::META_FILE, $relative );
		// Remove the public original and any generated sizes so no public URL serves the bytes.
		self::remove_public_files( $attachment, $file );
		return true;
	}

	/**
	 * Delete the public original and its generated size files from the uploads directory.
	 *
	 * @param int    $attachment Attachment ID.
	 * @param string $file       Absolute path to the original.
	 * @return void
	 */
	private static function remove_public_files( int $attachment, string $file ): void {
		$dir  = trailingslashit( dirname( $file ) );
		$meta = wp_get_attachment_metadata( $attachment );
		if ( is_array( $meta ) && ! empty( $meta['sizes'] ) && is_array( $meta['sizes'] ) ) {
			foreach ( $meta['sizes'] as $size ) {
				if ( ! empty( $size['file'] ) ) {
					wp_delete_file( $dir . wp_basename( (string) $size['file'] ) );
				}
			}
		}
		wp_delete_file( $file );
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
			return self::endpoint_url( $attachment_id );
		}
		return $url;
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
	 * @param int $attachment Attachment ID.
	 * @param int $user_id    User ID (0 for the current user).
	 * @return array{status: int, mode: string, path: string, relative: string}
	 */
	public static function decide( int $attachment, int $user_id = 0 ): array {
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
		$relative = self::stored_relative( $attachment );
		if ( '' !== $relative && is_file( self::protected_dir() . $relative ) ) {
			$no_accel = defined( 'WORMISS_MEDIA_NO_XACCEL' ) && WORMISS_MEDIA_NO_XACCEL;
			return [
				'status'   => 200,
				'mode'     => $no_accel ? 'readfile' : 'x-accel',
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
	 * Serve a protected media request at the endpoint.
	 *
	 * @return void
	 */
	public static function maybe_serve(): void {
		$id = (int) get_query_var( self::QUERY_VAR );
		if ( $id < 1 ) {
			return;
		}
		$decision = self::decide( $id );
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
		if ( defined( 'WORMISS_MEDIA_XSENDFILE' ) && WORMISS_MEDIA_XSENDFILE ) {
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
		if ( ! Wormiss_Media_Protected::protected_term_ids() ) {
			// Nothing is flagged for protection yet, so the store is not needed.
			return;
		}
		echo '<div class="notice notice-warning"><p>'
			. esc_html__( 'Wormiss: protected media are stored under wp-content, which is not truly private. Set the WORMISS_PROTECTED_DIR constant (a path outside the web root) in wp-config.php and add the matching nginx internal location.', 'wormiss' )
			. '</p></div>';
	}
}
