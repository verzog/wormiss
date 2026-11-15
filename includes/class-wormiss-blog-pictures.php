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
 * Members' blog pictures: pictures one after another grouped side by side, and any picture
 * opened large.
 *
 * @package    wormiss
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined( 'ABSPATH' ) || exit;

/**
 * Many iMIS posts put their pictures one after another rather than in a row, so they stacked
 * down the page at full width. Two or more pictures in a row, with nothing between them, are
 * shown side by side instead, as on the iMIS blog: two as a pair, three across, four as two
 * pairs, more in threes. Pictures already in a row or grid are left as they are. This is done
 * as the post is shown, so it applies to every post without a new import.
 *
 * On the page, a click or tap on a picture opens it large (a small script).
 */
class Wormiss_Blog_Pictures {

	/**
	 * The id of the wrapper the HTML is parsed in.
	 */
	const ROOT = 'wormiss-blog-pictures-root';

	/**
	 * Hook in.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'wp_enqueue_scripts', [ __CLASS__, 'scripts' ] );
	}

	/**
	 * The script that opens pictures large, on blog posts.
	 *
	 * @return void
	 */
	public static function scripts(): void {
		if ( ! is_singular( Wormiss_Blog::POST_TYPE ) ) {
			return;
		}
		wp_enqueue_script(
			'wormiss-blog-pictures',
			plugins_url( 'assets/blog-pictures.js', WORMISS_FILE ),
			[],
			WORMISS_VERSION,
			[
				'in_footer' => true,
				'strategy'  => 'defer',
			]
		);
		wp_add_inline_script(
			'wormiss-blog-pictures',
			'window.wormissBlogPictures = ' . wp_json_encode(
				[
					'open'  => __( 'Open the picture large', 'wormiss' ),
					'close' => __( 'Close', 'wormiss' ),
				]
			) . ';',
			'before'
		);
	}

	/**
	 * How many columns a group of pictures gets.
	 *
	 * @param int $count Pictures.
	 * @return int
	 */
	public static function columns( int $count ): int {
		return 2 === $count || 4 === $count ? 2 : 3;
	}

	/**
	 * Pictures one after another put side by side.
	 *
	 * @param string $html HTML.
	 * @return string The same HTML when there was nothing to group.
	 */
	public static function group( string $html ): string {
		if ( substr_count( strtolower( $html ), '<img' ) < 2 || ! class_exists( 'DOMDocument' ) ) {
			return $html;
		}
		$dom      = new DOMDocument();
		$previous = libxml_use_internal_errors( true );
		$loaded   = $dom->loadHTML(
			'<?xml encoding="UTF-8"><div id="' . self::ROOT . '">' . $html . '</div>',
			LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET
		);
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );
		$root = $loaded ? $dom->getElementById( self::ROOT ) : null;
		if ( ! $root instanceof DOMElement ) {
			return $html;
		}
		if ( ! self::group_children( $dom, $root ) ) {
			return $html;
		}
		$out = '';
		foreach ( $root->childNodes as $child ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- DOM.
			$out .= $dom->saveHTML( $child );
		}
		return $out;
	}

	/**
	 * Group runs of pictures among an element's children, and within its children in turn.
	 *
	 * @param DOMDocument $dom     Document.
	 * @param DOMElement  $element Element.
	 * @return bool Whether anything was grouped.
	 */
	private static function group_children( DOMDocument $dom, DOMElement $element ): bool {
		$changed = false;
		// Already side by side: a grid, or a row of columns.
		if ( self::has_class( $element, 'wormiss-blog-grid' ) || self::has_class( $element, 'row' ) ) {
			return false;
		}
		$run = [];
		foreach ( iterator_to_array( $element->childNodes ) as $child ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- DOM.
			if ( $child instanceof DOMText && '' === trim( str_replace( "\xc2\xa0", ' ', $child->nodeValue ) ) ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- DOM.
				// Space between pictures does not end a run.
				continue;
			}
			$pictures = $child instanceof DOMElement ? self::pictures( $child ) : [];
			if ( $pictures ) {
				$run[] = [ $child, $pictures ];
				continue;
			}
			$changed = self::wrap( $dom, $element, $run ) || $changed;
			$run     = [];
			if ( $child instanceof DOMElement ) {
				$changed = self::group_children( $dom, $child ) || $changed;
			}
		}
		return self::wrap( $dom, $element, $run ) || $changed;
	}

	/**
	 * The pictures a child stands for, when it is only pictures: a picture figure, a picture on
	 * its own, or a paragraph (or link) holding nothing but pictures.
	 *
	 * @param DOMElement $node Child.
	 * @return DOMElement[] The nodes to place, one per picture (none: not a picture).
	 */
	private static function pictures( DOMElement $node ): array {
		$tag = strtolower( $node->tagName ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- DOM.
		if ( 'img' === $tag ) {
			return [ $node ];
		}
		if ( 'figure' === $tag ) {
			return 1 === $node->getElementsByTagName( 'img' )->length && ! self::has_class( $node, 'wormiss-blog-grid' ) ? [ $node ] : [];
		}
		if ( 'a' === $tag ) {
			return 1 === $node->getElementsByTagName( 'img' )->length && '' === trim( str_replace( "\xc2\xa0", ' ', $node->textContent ) ) ? [ $node ] : []; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- DOM.
		}
		if ( 'p' !== $tag ) {
			return [];
		}
		$out = [];
		foreach ( $node->childNodes as $child ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- DOM.
			if ( $child instanceof DOMText ) {
				if ( '' !== trim( str_replace( "\xc2\xa0", ' ', $child->nodeValue ) ) ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- DOM.
					return [];
				}
				continue;
			}
			if ( ! $child instanceof DOMElement ) {
				continue;
			}
			$tag = strtolower( $child->tagName ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- DOM.
			if ( 'br' === $tag ) {
				continue;
			}
			$inner = 'a' === $tag || 'img' === $tag ? self::pictures( $child ) : [];
			if ( ! $inner ) {
				return [];
			}
			$out = array_merge( $out, $inner );
		}
		return $out;
	}

	/**
	 * Put a run of two or more pictures in a grid where the run's first child was.
	 *
	 * @param DOMDocument $dom    Document.
	 * @param DOMElement  $holder The run's parent.
	 * @param array       $run    Pairs of the child and the pictures it holds.
	 * @return bool Whether a grid was made.
	 */
	private static function wrap( DOMDocument $dom, DOMElement $holder, array $run ): bool {
		$count = 0;
		foreach ( $run as $entry ) {
			$count += count( $entry[1] );
		}
		if ( $count < 2 ) {
			return false;
		}
		$grid = $dom->createElement( 'div' );
		$grid->setAttribute( 'class', 'wormiss-blog-grid wormiss-blog-grid--cols-' . self::columns( $count ) . ' wormiss-blog-gallery' );
		$holder->insertBefore( $grid, $run[0][0] );
		foreach ( $run as $entry ) {
			foreach ( $entry[1] as $picture ) {
				$item = $dom->createElement( 'div' );
				$item->setAttribute( 'class', 'wormiss-blog-grid__item' );
				$item->appendChild( $picture );
				$grid->appendChild( $item );
			}
			// A paragraph that held the pictures is now empty.
			if ( $entry[0]->parentNode === $holder ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- DOM.
				$holder->removeChild( $entry[0] );
			}
		}
		return true;
	}

	/**
	 * Whether an element has a class.
	 *
	 * @param DOMElement $node  Element.
	 * @param string     $name Class.
	 * @return bool
	 */
	private static function has_class( DOMElement $node, string $name ): bool {
		return in_array( $name, preg_split( '/\s+/', (string) $node->getAttribute( 'class' ) ), true );
	}
}
