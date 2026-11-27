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
 * Shared helpers for copying iMIS images into the WordPress Media Library.
 *
 * @package    wormiss
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined( 'ABSPATH' ) || exit;

/**
 * Media helpers used by every importer that sideloads an iMIS image (pages, blog posts, news
 * articles and events).
 *
 * Each importer runs its own download, caching, batch budget and reporting, but they all faced the
 * same trap when naming the file for media_handle_sideload(): iMIS often serves an image from an
 * address with no file extension (a "GetImage" handler) or a misleading one (.aspx), and WordPress
 * decides a file's type from its name, so it refused the upload with "you are not allowed to upload
 * this file type". This class holds the one piece of logic that avoids that, so the four importers
 * behave the same and a fix lands in a single place.
 */
class Wormiss_Media {

	/**
	 * A sideload filename for a downloaded image: its name taken from the source address but given an
	 * extension WordPress accepts for the image's real type, sniffed from the bytes rather than
	 * trusted from the address.
	 *
	 * The accepted set is read from get_allowed_mime_types(), so it matches exactly what
	 * media_handle_sideload() will take and grows with WordPress core (WebP, AVIF and so on) without a
	 * hard-coded list here. A file that is not an image type WordPress accepts (for example an SVG,
	 * which core blocks by default) returns null, so the caller can skip it rather than fail.
	 *
	 * @param string $tmp_file   Path of the already-downloaded file.
	 * @param string $source_url The address it came from, for the base of the name.
	 * @param string $fallback   Base name to use when the address has none (no extension).
	 * @return string|null The filename to sideload under, or null when the type is not one WordPress
	 *                     accepts as an image.
	 */
	public static function sideload_name( string $tmp_file, string $source_url, string $fallback = 'image' ): ?string {
		$mime = function_exists( 'wp_get_image_mime' ) ? (string) wp_get_image_mime( $tmp_file ) : '';
		if ( '' === $mime ) {
			return null;
		}
		$ext = '';
		foreach ( get_allowed_mime_types() as $exts => $allowed ) {
			if ( $allowed === $mime ) {
				// get_allowed_mime_types() keys list every extension for the type ("jpg|jpeg|jpe");
				// the first is WordPress's canonical one.
				$ext = (string) strtok( $exts, '|' );
				break;
			}
		}
		if ( '' === $ext ) {
			return null;
		}
		$base = sanitize_file_name( rawurldecode( wp_basename( (string) wp_parse_url( $source_url, PHP_URL_PATH ) ) ) );
		$base = (string) preg_replace( '/\.[^.\/]+$/', '', $base );
		return ( '' !== $base ? $base : $fallback ) . '.' . $ext;
	}
}
