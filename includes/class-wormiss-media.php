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

	/** The largest SVG, in bytes, that will be sanitised and imported. Icons are a few KB. */
	const MAX_SVG_BYTES = 512000;

	/**
	 * Whether SVG images may be imported. WordPress blocks SVG uploads by default because an SVG is
	 * XML that can carry script, so an imported SVG is always sanitised first (see
	 * sanitize_svg_file()). This is on by default because iMIS sites use SVG icons; a site can turn
	 * it off with the 'wormiss_import_svg' filter.
	 *
	 * @return bool
	 */
	public static function svg_import_allowed(): bool {
		return (bool) apply_filters( 'wormiss_import_svg', true );
	}

	/**
	 * A sideload filename for a downloaded SVG, after sanitising it in place, or null when SVG import
	 * is off or the file is not a safe, parseable SVG.
	 *
	 * @param string $tmp_file   Path of the already-downloaded file (sanitised in place on success).
	 * @param string $source_url The address it came from, for the base of the name.
	 * @param string $fallback   Base name to use when the address has none.
	 * @return string|null The ".svg" filename to sideload under, or null.
	 */
	public static function svg_name( string $tmp_file, string $source_url, string $fallback = 'image' ): ?string {
		if ( ! self::svg_import_allowed() ) {
			return null;
		}
		if ( ! self::sanitize_svg_file( $tmp_file ) ) {
			return null;
		}
		$base = sanitize_file_name( rawurldecode( wp_basename( (string) wp_parse_url( $source_url, PHP_URL_PATH ) ) ) );
		$base = (string) preg_replace( '/\.[^.\/]+$/', '', $base );
		return ( '' !== $base ? $base : $fallback ) . '.svg';
	}

	/**
	 * Sideload a file into the Media Library, allowing the SVG type for the duration of the call when
	 * the file is a (already sanitised) SVG. Everything else behaves exactly like media_handle_sideload().
	 *
	 * @param array  $file      {'name', 'tmp_name'} as media_handle_sideload() expects.
	 * @param int    $post_id   Post to attach to.
	 * @param string $title     Description/title.
	 * @param bool   $is_svg    True to allow image/svg+xml for this call.
	 * @param array  $post_data Extra attachment post fields.
	 * @return int|WP_Error Attachment ID or error.
	 */
	public static function handle_sideload( array $file, int $post_id, string $title, bool $is_svg = false, array $post_data = [] ) {
		if ( ! $is_svg ) {
			return media_handle_sideload( $file, $post_id, $title, $post_data );
		}
		$allow = static function ( $mimes ) {
			$mimes['svg'] = 'image/svg+xml';
			return $mimes;
		};
		// WordPress sniffs the file and, finding no binary image header, reports no type for an SVG; tell
		// it the type from the (already verified) .svg name so the upload is accepted.
		$verify = static function ( $data, $unused_file, $filename ) {
			if ( empty( $data['type'] ) && preg_match( '/\.svg$/i', (string) $filename ) ) {
				$data['type'] = 'image/svg+xml';
				$data['ext']  = 'svg';
			}
			return $data;
		};
		add_filter( 'upload_mimes', $allow );
		add_filter( 'wp_check_filetype_and_ext', $verify, 10, 3 );
		$result = media_handle_sideload( $file, $post_id, $title, $post_data );
		remove_filter( 'wp_check_filetype_and_ext', $verify, 10 );
		remove_filter( 'upload_mimes', $allow );
		return $result;
	}

	// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- PHP DOM API property names (nodeName, childNodes, ...).
	/**
	 * Sanitise an SVG file in place: keep only an allow-list of drawing elements and attributes, and
	 * drop anything that could run script (a <script>, an on* handler, a javascript: or external
	 * reference, a DOCTYPE). Returns false when the file is not a safe, parseable SVG, so the caller
	 * leaves it alone.
	 *
	 * The approach mirrors established SVG sanitisers: an allow-list is safer than trying to name
	 * every dangerous construct. iMIS content is treated as untrusted input even though it is the
	 * site's own, per the plugin's trust-boundary rule.
	 *
	 * @param string $tmp_file Path of the downloaded file.
	 * @return bool True when a sanitised SVG was written back, false otherwise.
	 */
	public static function sanitize_svg_file( string $tmp_file ): bool {
		// Reject by size before reading: all four importers now pass unsupported downloads through
		// here, so a huge non-image file must not be read whole (it could exhaust memory and abort
		// the batch).
		$size = filesize( $tmp_file );
		if ( false === $size || $size > self::MAX_SVG_BYTES ) {
			return false;
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents, WordPress.WP.AlternativeFunctions.file_system_operations_file_get_contents -- Reading the download's own local temporary file, not a URL.
		$raw = (string) file_get_contents( $tmp_file );
		$raw = (string) preg_replace( '/^\xEF\xBB\xBF/', '', $raw );
		if ( '' === trim( $raw ) || false === stripos( $raw, '<svg' ) ) {
			return false;
		}
		// A DOCTYPE can define entities (billion-laughs, XXE); refuse rather than try to make it safe.
		if ( preg_match( '/<!DOCTYPE/i', $raw ) || preg_match( '/<!ENTITY/i', $raw ) ) {
			return false;
		}
		$previous = libxml_use_internal_errors( true );
		$dom      = new DOMDocument();
		$loaded   = $dom->loadXML( $raw, LIBXML_NONET );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );
		if ( ! $loaded || ! $dom->documentElement || 'svg' !== strtolower( $dom->documentElement->localName ) ) {
			return false;
		}
		self::scrub_svg_node( $dom->documentElement );
		$clean = $dom->saveXML( $dom->documentElement );
		if ( ! is_string( $clean ) || '' === trim( $clean ) ) {
			return false;
		}
		$xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . $clean;
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Writing the download's own temporary file.
		return false !== file_put_contents( $tmp_file, $xml );
	}

	/**
	 * Recursively remove disallowed elements and attributes from an SVG DOM node, including this
	 * node's own attributes (so the root <svg> is scrubbed too, not only its children).
	 *
	 * @param DOMElement $node Node to scrub.
	 * @return void
	 */
	private static function scrub_svg_node( DOMElement $node ): void {
		self::scrub_svg_attributes( $node );
		// Walk a static list of children: the live list shifts as nodes are removed.
		$children = [];
		foreach ( $node->childNodes as $child ) {
			$children[] = $child;
		}
		foreach ( $children as $child ) {
			if ( XML_COMMENT_NODE === $child->nodeType ) {
				$node->removeChild( $child );
				continue;
			}
			if ( ! $child instanceof DOMElement ) {
				continue;
			}
			if ( ! in_array( strtolower( $child->localName ), self::SVG_ELEMENTS, true ) ) {
				$node->removeChild( $child );
				continue;
			}
			self::scrub_svg_node( $child );
		}
	}

	/**
	 * Remove disallowed attributes from one SVG element, and neutralise href and style values.
	 *
	 * @param DOMElement $el Element to clean.
	 * @return void
	 */
	private static function scrub_svg_attributes( DOMElement $el ): void {
		$attrs = [];
		foreach ( $el->attributes as $attr ) {
			$attrs[] = $attr;
		}
		foreach ( $attrs as $attr ) {
			$name  = strtolower( $attr->nodeName );
			$local = strtolower( $attr->localName );
			if ( str_starts_with( $name, 'xmlns' ) ) {
				continue; // Namespace declarations are needed to render.
			}
			if ( str_starts_with( $local, 'on' ) ) {
				$el->removeAttributeNode( $attr );
				continue;
			}
			if ( 'href' === $local ) {
				// Only internal references (#id) are safe; drop javascript:, data:, and external refs.
				if ( ! str_starts_with( ltrim( $attr->value ), '#' ) ) {
					$el->removeAttributeNode( $attr );
				}
				continue;
			}
			if ( ! in_array( $local, self::SVG_ATTRIBUTES, true ) ) {
				$el->removeAttributeNode( $attr );
				continue;
			}
			if ( 'style' === $local && preg_match( '/javascript:|expression\s*\(|@import|behavior\s*:|url\s*\(\s*["\']?\s*javascript:/i', $attr->value ) ) {
				$el->removeAttributeNode( $attr );
			}
		}
	}
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase

	/** SVG drawing elements kept by the sanitiser (lower-case local names). */
	const SVG_ELEMENTS = [
		'svg',
		'g',
		'path',
		'circle',
		'ellipse',
		'line',
		'polyline',
		'polygon',
		'rect',
		'defs',
		'use',
		'symbol',
		'title',
		'desc',
		'metadata',
		'lineargradient',
		'radialgradient',
		'stop',
		'clippath',
		'mask',
		'pattern',
		'marker',
		'style',
		'text',
		'tspan',
		'textpath',
		'switch',
		'view',
		'filter',
		'feblend',
		'fecolormatrix',
		'fecomponenttransfer',
		'fecomposite',
		'feconvolvematrix',
		'fediffuselighting',
		'fedisplacementmap',
		'fedistantlight',
		'feflood',
		'fefunca',
		'fefuncb',
		'fefuncg',
		'fefuncr',
		'fegaussianblur',
		'femerge',
		'femergenode',
		'femorphology',
		'feoffset',
		'fepointlight',
		'fespecularlighting',
		'fespotlight',
		'fetile',
		'feturbulence',
	];

	/** SVG attributes kept by the sanitiser (lower-case local names); href and style are special-cased. */
	const SVG_ATTRIBUTES = [
		'id',
		'class',
		'style',
		'fill',
		'fill-opacity',
		'fill-rule',
		'stroke',
		'stroke-width',
		'stroke-linecap',
		'stroke-linejoin',
		'stroke-dasharray',
		'stroke-dashoffset',
		'stroke-opacity',
		'stroke-miterlimit',
		'opacity',
		'color',
		'd',
		'points',
		'x',
		'y',
		'x1',
		'x2',
		'y1',
		'y2',
		'cx',
		'cy',
		'r',
		'rx',
		'ry',
		'width',
		'height',
		'viewbox',
		'preserveaspectratio',
		'transform',
		'gradientunits',
		'gradienttransform',
		'offset',
		'stop-color',
		'stop-opacity',
		'spreadmethod',
		'fx',
		'fy',
		'clip-path',
		'clip-rule',
		'mask',
		'patternunits',
		'patterncontentunits',
		'patterntransform',
		'markerwidth',
		'markerheight',
		'refx',
		'refy',
		'orient',
		'marker-start',
		'marker-end',
		'marker-mid',
		'version',
		'enable-background',
		'display',
		'visibility',
		'overflow',
		'font-family',
		'font-size',
		'font-weight',
		'font-style',
		'text-anchor',
		'letter-spacing',
		'word-spacing',
		'dominant-baseline',
		'baseline-shift',
		'dx',
		'dy',
		'rotate',
		'filter',
		'flood-color',
		'flood-opacity',
		'stddeviation',
		'in',
		'in2',
		'result',
		'mode',
		'type',
		'values',
		'operator',
		'k1',
		'k2',
		'k3',
		'k4',
		'scale',
		'xchannelselector',
		'ychannelselector',
		'radius',
		'maskunits',
		'maskcontentunits',
		'clippathunits',
	];
}
