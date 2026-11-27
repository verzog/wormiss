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
 * Rebuild an imported iMIS page's column layout as WordPress block markup.
 *
 * @package    wormiss
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined( 'ABSPATH' ) || exit;

/**
 * An iMIS content page is a set of blocks ("content items"), each assigned to a numbered layout
 * zone, that a template arranges into a grid of rows and columns. The importer brings the content
 * blocks across; this class restores the arrangement as native WordPress column blocks
 * (wp:columns / wp:column), so a block theme lays the page out in a grid rather than one long
 * column.
 *
 * The exact column widths and the row boundaries within a section live in the page's template
 * (LayoutDocumentVersionKey), which this first pass does not read. It uses what the content document
 * alone gives: a cell that is only a heading starts a full-width section, and the run of cells
 * between headings becomes one equal-width row. That reproduces the icon and card rows; a section
 * that the template splits into several rows comes in as a single row until the template is read.
 */
class Wormiss_Page_Blocks {

	/**
	 * Whether imported pages are built as column blocks. On by default; a site can return the page to
	 * a single column of content with the 'wormiss_page_import_blocks' filter.
	 *
	 * @return bool
	 */
	public static function enabled(): bool {
		return (bool) apply_filters( 'wormiss_page_import_blocks', true );
	}

	/**
	 * Block markup for a page's body, from its content items (each with 'zone' and 'html'), in page
	 * order. A cell that is only the page title as a heading is left out.
	 *
	 * @param string     $title  Page title.
	 * @param array      $items  Content items from Wormiss_News_Import::parse().
	 * @param array|null $layout The page's layout template rows (from Wormiss_Page_Layout::rows()), to
	 *                           rebuild the exact grid; null to group by heading instead.
	 * @return string Block markup.
	 */
	public static function build( string $title, array $items, ?array $layout = null ): string {
		$cells = self::cells( $items, $title );
		if ( null !== $layout && array() !== $layout ) {
			return self::from_template( $cells, $layout );
		}
		$blocks = [];
		$run    = [];
		foreach ( $cells as $html ) {
			if ( self::is_heading( $html ) ) {
				$blocks[] = self::row( $run );
				$run      = [];
				$blocks[] = self::section( $html, true );
			} else {
				$run[] = $html;
			}
		}
		$blocks[] = self::row( $run );
		return trim( implode( "\n\n", array_filter( $blocks, static fn( string $b ): bool => '' !== $b ) ) );
	}

	/**
	 * Build the body from the page's layout template: each template row becomes a columns row with the
	 * template's exact widths (a zone with no content is left out; a row left with one cell, or a
	 * full-width cell, becomes a section). Content whose zone the template does not place is kept,
	 * full width, so nothing is lost.
	 *
	 * @param array $cells  Zone => cleaned cell HTML.
	 * @param array $layout Template rows: each a list of [ 'zone' => int, 'width' => int (twelfths) ].
	 * @return string
	 */
	private static function from_template( array $cells, array $layout ): string {
		$blocks = [];
		$placed = [];
		foreach ( $layout as $row ) {
			$columns = [];
			foreach ( (array) $row as $col ) {
				$zone = (int) ( $col['zone'] ?? 0 );
				if ( empty( $cells[ $zone ] ) ) {
					continue;
				}
				$placed[ $zone ] = true;
				$html            = $cells[ $zone ];
				$width           = (int) ( $col['width'] ?? 0 );
				if ( $width <= 0 || $width >= 12 ) {
					// A full-width cell is its own section: flush any columns before it first, in order.
					$blocks[] = self::row_cols( $columns );
					$columns  = [];
					$blocks[] = self::section( $html, self::is_heading( $html ) );
					continue;
				}
				$columns[] = [
					'html'  => $html,
					'width' => $width,
				];
			}
			$blocks[] = self::row_cols( $columns );
		}
		foreach ( $cells as $zone => $html ) {
			if ( empty( $placed[ $zone ] ) ) {
				$blocks[] = self::section( $html, self::is_heading( $html ) );
			}
		}
		return trim( implode( "\n\n", array_filter( $blocks, static fn( string $b ): bool => '' !== $b ) ) );
	}

	/**
	 * The page's cells: the cleaned HTML of each layout zone (items sharing a zone joined), keyed by
	 * zone number and in page order, skipping empty cells and a cell that is only the page title.
	 *
	 * @param array  $items Content items.
	 * @param string $title Page title.
	 * @return array<int, string> Cleaned cell HTML keyed by zone.
	 */
	private static function cells( array $items, string $title ): array {
		$cells = [];
		$zone  = null;
		$buf   = [];
		$flush = static function () use ( &$cells, &$buf, &$zone ) {
			$html = trim( implode( "\n\n", $buf ) );
			$buf  = [];
			if ( '' !== $html && null !== $zone ) {
				$cells[ $zone ] = isset( $cells[ $zone ] ) ? $cells[ $zone ] . "\n\n" . $html : $html;
			}
		};
		foreach ( $items as $item ) {
			$html = Wormiss_News_Import::clean( (string) ( $item['html'] ?? '' ) );
			if ( '' === $html ) {
				continue;
			}
			$this_zone = (int) ( $item['zone'] ?? 0 );
			if ( null !== $zone && $this_zone !== $zone ) {
				$flush();
			}
			$zone  = $this_zone;
			$buf[] = $html;
		}
		$flush();
		foreach ( $cells as $key => $html ) {
			if ( self::is_title( $html, $title ) ) {
				unset( $cells[ $key ] );
			}
		}
		return $cells;
	}

	/**
	 * A row block from a run of cells: nothing for an empty run, a full-width section for a single
	 * cell, or a columns block (one equal-width column per cell) for several.
	 *
	 * @param string[] $run Cell HTML.
	 * @return string
	 */
	private static function row( array $run ): string {
		if ( ! $run ) {
			return '';
		}
		if ( 1 === count( $run ) ) {
			return self::section( $run[0], false );
		}
		$columns = '';
		foreach ( $run as $html ) {
			$columns .= "<!-- wp:column -->\n<div class=\"wp-block-column\">" . self::cell_blocks( $html ) . "</div>\n<!-- /wp:column -->\n";
		}
		return "<!-- wp:columns {\"className\":\"wormiss-row\"} -->\n<div class=\"wp-block-columns wormiss-row\">\n" . $columns . "</div>\n<!-- /wp:columns -->";
	}

	/**
	 * A columns row from the template, each column at its own width (in twelfths): a single surviving
	 * column, or a full-width one, becomes a section instead.
	 *
	 * @param array $columns List of [ 'html' => string, 'width' => int (twelfths) ].
	 * @return string
	 */
	private static function row_cols( array $columns ): string {
		if ( ! $columns ) {
			return '';
		}
		if ( 1 === count( $columns ) ) {
			return self::section( $columns[0]['html'], self::is_heading( $columns[0]['html'] ) );
		}
		$out = '';
		foreach ( $columns as $column ) {
			$pct   = self::width_pct( (int) $column['width'] );
			$attr  = null !== $pct ? ' {"width":"' . $pct . '"}' : '';
			$style = null !== $pct ? ' style="flex-basis:' . $pct . '"' : '';
			$out  .= "<!-- wp:column$attr -->\n<div class=\"wp-block-column\"$style>" . self::cell_blocks( $column['html'] ) . "</div>\n<!-- /wp:column -->\n";
		}
		return "<!-- wp:columns {\"className\":\"wormiss-row\"} -->\n<div class=\"wp-block-columns wormiss-row\">\n" . $out . "</div>\n<!-- /wp:columns -->";
	}

	/**
	 * A column width as a percentage string ("66.67%"), from twelfths; null for a full-width (12) or
	 * invalid width, which leaves the column to share the row equally.
	 *
	 * @param int $twelfths Width in twelfths of the row.
	 * @return string|null
	 */
	private static function width_pct( int $twelfths ): ?string {
		if ( $twelfths <= 0 || $twelfths >= 12 ) {
			return null;
		}
		return rtrim( rtrim( number_format( $twelfths / 12 * 100, 2, '.', '' ), '0' ), '.' ) . '%';
	}

	/**
	 * A full-width section block wrapping one cell's HTML.
	 *
	 * @param string $html    Cell HTML.
	 * @param bool   $heading Whether the cell is a section heading (adds a class for styling).
	 * @return string
	 */
	private static function section( string $html, bool $heading ): string {
		$class = $heading ? 'wormiss-section wormiss-heading' : 'wormiss-section';
		return "<!-- wp:group {\"className\":\"$class\"} -->\n<div class=\"wp-block-group $class\">" . self::cell_blocks( $html ) . "</div>\n<!-- /wp:group -->";
	}

	/**
	 * A cell's cleaned HTML as native block markup: each top-level element becomes the matching core
	 * block (heading, paragraph, list, image, quote, table), so imported content is editable in the
	 * block editor and styled by the theme rather than sealed in one opaque HTML block. Anything
	 * unrecognised, and the whole cell when it cannot be parsed, falls back to an HTML block, so no
	 * imported content is ever lost.
	 *
	 * @param string $html Cell HTML (already cleaned to a small set of semantic tags).
	 * @return string
	 */
	private static function cell_blocks( string $html ): string {
		$html = trim( $html );
		if ( '' === $html ) {
			return '';
		}
		if ( ! class_exists( 'DOMDocument' ) ) {
			return self::html_fallback( $html );
		}
		$previous = libxml_use_internal_errors( true );
		$dom      = new DOMDocument();
		$loaded   = $dom->loadHTML(
			'<meta charset="utf-8"><div id="wormiss-cell-root">' . $html . '</div>',
			LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING
		);
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );
		if ( ! $loaded ) {
			return self::html_fallback( $html );
		}
		$xpath = new DOMXPath( $dom );
		$root  = $xpath->query( '//*[@id="wormiss-cell-root"]' )->item( 0 );
		if ( ! $root ) {
			return self::html_fallback( $html );
		}
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- PHP DOM API.
		$blocks = self::nodes_to_blocks( iterator_to_array( $root->childNodes ) );
		return $blocks ? implode( "\n\n", $blocks ) : self::html_fallback( $html );
	}

	/**
	 * A list of sibling DOM nodes as block markup: each block-level element becomes its own block, and a
	 * run of adjacent inline content (text and inline elements such as links or emphasis) is gathered
	 * into a single paragraph, so a sentence with a link in the middle stays one paragraph with its
	 * spacing intact rather than being split across blocks.
	 *
	 * @param DOMNode[] $nodes The sibling nodes.
	 * @return string[] Block markup, one per element.
	 */
	private static function nodes_to_blocks( array $nodes ): array {
		// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- PHP DOM API.
		$blocks = [];
		$run    = [];
		$flush  = static function () use ( &$blocks, &$run ): void {
			if ( $run ) {
				$block = self::inline_run_block( $run );
				if ( '' !== $block ) {
					$blocks[] = $block;
				}
				$run = [];
			}
		};
		foreach ( $nodes as $node ) {
			if ( XML_TEXT_NODE === $node->nodeType ) {
				// Non-whitespace text is inline; whitespace is kept only to space an open inline run.
				if ( '' !== trim( (string) $node->textContent ) || $run ) {
					$run[] = $node;
				}
				continue;
			}
			if ( XML_ELEMENT_NODE !== $node->nodeType ) {
				continue;
			}
			if ( self::is_inline_element( $node ) ) {
				$run[] = $node;
				continue;
			}
			$flush();
			$block = self::node_block( $node );
			if ( '' !== $block ) {
				$blocks[] = $block;
			}
		}
		$flush();
		// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		return $blocks;
	}

	/**
	 * Whether an element is inline-level (so it belongs in a paragraph with its neighbours rather than
	 * as its own block). An image counts as inline here: a lone image still becomes its own image block
	 * in inline_run_block(), but an image sitting among text stays within that text.
	 *
	 * @param DOMNode $node The element node.
	 * @return bool
	 */
	private static function is_inline_element( DOMNode $node ): bool {
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- PHP DOM API.
		$name = strtolower( $node->nodeName );
		return in_array(
			$name,
			[ 'a', 'strong', 'b', 'em', 'i', 'u', 'sup', 'sub', 'br', 'img' ],
			true
		);
	}

	/**
	 * A run of adjacent inline nodes as one block: an image on its own (only whitespace around it)
	 * becomes an image block, otherwise the run becomes a single paragraph keeping its inline markup
	 * and internal spacing. An empty or whitespace-only run yields nothing.
	 *
	 * @param DOMNode[] $run The inline nodes.
	 * @return string
	 */
	private static function inline_run_block( array $run ): string {
		// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- PHP DOM API.
		$has_text = false;
		$elements = [];
		foreach ( $run as $node ) {
			if ( XML_TEXT_NODE === $node->nodeType ) {
				if ( '' !== trim( (string) $node->textContent ) ) {
					$has_text = true;
				}
			} elseif ( XML_ELEMENT_NODE === $node->nodeType && 'br' !== strtolower( $node->nodeName ) ) {
				$elements[] = $node;
			}
		}
		if ( ! $has_text && 1 === count( $elements ) && 'img' === strtolower( $elements[0]->nodeName ) ) {
			return self::image_block( self::outer_html( $elements[0] ), '' );
		}
		$html = '';
		foreach ( $run as $node ) {
			$html .= self::outer_html( $node );
		}
		// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		$html = trim( $html );
		return '' === $html ? '' : "<!-- wp:paragraph -->\n<p>$html</p>\n<!-- /wp:paragraph -->";
	}

	/**
	 * One block-level DOM element as its core block: a paragraph its paragraph, a heading its heading, a
	 * list its list, a figure an image, a blockquote a quote and a table a table. Anything else is
	 * preserved as an HTML block. Inline nodes are handled by nodes_to_blocks(), not here.
	 *
	 * @param DOMNode $node The element node.
	 * @return string
	 */
	private static function node_block( DOMNode $node ): string {
		// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- PHP DOM API.
		if ( XML_ELEMENT_NODE !== $node->nodeType ) {
			return '';
		}
		$tag = strtolower( $node->nodeName );
		// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		switch ( $tag ) {
			case 'p':
				$inner = self::inner_html( $node );
				return '' === $inner ? '' : "<!-- wp:paragraph -->\n<p>$inner</p>\n<!-- /wp:paragraph -->";
			case 'h2':
			case 'h3':
			case 'h4':
			case 'h5':
			case 'h6':
				return self::heading_block( (int) substr( $tag, 1 ), self::inner_html( $node ) );
			case 'ul':
			case 'ol':
				return self::list_block( $node, 'ol' === $tag );
			case 'blockquote':
				return self::quote_block( $node );
			case 'figure':
				return self::figure_block( $node );
			case 'img':
				return self::image_block( self::outer_html( $node ), '' );
			case 'table':
				return "<!-- wp:table -->\n<figure class=\"wp-block-table\">" . self::outer_html( $node ) . "</figure>\n<!-- /wp:table -->";
			case 'br':
				return '';
			default:
				return self::html_fallback( self::outer_html( $node ) );
		}
	}

	/**
	 * A heading block at the given level. Level 2 is the block default, so its level attribute is left
	 * out to match WordPress's own serialization.
	 *
	 * @param int    $level Heading level, 2 to 6.
	 * @param string $inner The heading's inner HTML.
	 * @return string
	 */
	private static function heading_block( int $level, string $inner ): string {
		$attr = 2 === $level ? '' : " {\"level\":$level}";
		return "<!-- wp:heading$attr -->\n<h$level class=\"wp-block-heading\">$inner</h$level>\n<!-- /wp:heading -->";
	}

	/**
	 * A list block from a ul or ol, each item a list-item block. A list with an item that nests another
	 * list is kept whole as an HTML block rather than guessing at nested list-item blocks, and an empty
	 * list falls back the same way.
	 *
	 * @param DOMNode $node    The ul or ol node.
	 * @param bool    $ordered Whether it is an ordered list.
	 * @return string
	 */
	private static function list_block( DOMNode $node, bool $ordered ): string {
		// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- PHP DOM API.
		$items = [];
		foreach ( $node->childNodes as $child ) {
			if ( XML_ELEMENT_NODE !== $child->nodeType || 'li' !== strtolower( $child->nodeName ) ) {
				continue;
			}
			foreach ( $child->childNodes as $grandchild ) {
				if ( XML_ELEMENT_NODE === $grandchild->nodeType
					&& in_array( strtolower( $grandchild->nodeName ), [ 'ul', 'ol' ], true ) ) {
					return self::html_fallback( self::outer_html( $node ) );
				}
			}
			$items[] = "<!-- wp:list-item -->\n<li>" . self::inner_html( $child ) . "</li>\n<!-- /wp:list-item -->";
		}
		// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		if ( ! $items ) {
			return self::html_fallback( self::outer_html( $node ) );
		}
		$list_tag = $ordered ? 'ol' : 'ul';
		$attr     = $ordered ? ' {"ordered":true}' : '';
		return "<!-- wp:list$attr -->\n<$list_tag class=\"wp-block-list\">" . implode( '', $items ) . "</$list_tag>\n<!-- /wp:list -->";
	}

	/**
	 * A quote block, its children converted to their own blocks; a quote of inline content only is
	 * wrapped in a single paragraph so the block stays valid.
	 *
	 * @param DOMNode $node The blockquote node.
	 * @return string
	 */
	private static function quote_block( DOMNode $node ): string {
		// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- PHP DOM API.
		$inner = self::nodes_to_blocks( iterator_to_array( $node->childNodes ) );
		// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		if ( ! $inner ) {
			return '';
		}
		return "<!-- wp:quote -->\n<blockquote class=\"wp-block-quote\">" . implode( "\n\n", $inner ) . "</blockquote>\n<!-- /wp:quote -->";
	}

	/**
	 * An image block from a figure that holds exactly one image and, at most, one caption: the image,
	 * with any figcaption kept as the block's caption. A figure with no image, more than one image, or
	 * any other content (extra text or elements an image block could not carry) is preserved whole as
	 * an HTML block, so nothing beside the image is lost.
	 *
	 * @param DOMNode $node The figure node.
	 * @return string
	 */
	private static function figure_block( DOMNode $node ): string {
		// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- PHP DOM API.
		$img     = null;
		$caption = '';
		$extra   = false;
		foreach ( $node->childNodes as $child ) {
			if ( XML_TEXT_NODE === $child->nodeType ) {
				if ( '' !== trim( (string) $child->textContent ) ) {
					$extra = true;
				}
				continue;
			}
			if ( XML_ELEMENT_NODE !== $child->nodeType ) {
				continue;
			}
			$name = strtolower( $child->nodeName );
			if ( 'img' === $name && null === $img ) {
				$img = $child;
			} elseif ( 'figcaption' === $name && '' === $caption ) {
				$caption = self::inner_html( $child );
			} else {
				// A second image, a second caption, or any other element: more than one image block holds.
				$extra = true;
			}
		}
		// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		if ( null === $img || $extra ) {
			return self::html_fallback( self::outer_html( $node ) );
		}
		return self::image_block( self::outer_html( $img ), $caption );
	}

	/**
	 * An image block wrapping one image's markup, with an optional caption.
	 *
	 * @param string $img_html The img markup.
	 * @param string $caption  Caption HTML, or '' for none.
	 * @return string
	 */
	private static function image_block( string $img_html, string $caption ): string {
		$figure = '<figure class="wp-block-image">' . trim( $img_html );
		if ( '' !== trim( $caption ) ) {
			$figure .= '<figcaption class="wp-element-caption">' . $caption . '</figcaption>';
		}
		$figure .= '</figure>';
		return "<!-- wp:image -->\n$figure\n<!-- /wp:image -->";
	}

	/**
	 * The combined HTML of a node's children (its inner HTML), trimmed.
	 *
	 * @param DOMNode $node The node.
	 * @return string
	 */
	private static function inner_html( DOMNode $node ): string {
		// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- PHP DOM API.
		$doc = $node->ownerDocument;
		if ( ! $doc ) {
			return '';
		}
		$html = '';
		foreach ( $node->childNodes as $child ) {
			$html .= (string) $doc->saveHTML( $child );
		}
		// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		return trim( $html );
	}

	/**
	 * A node's own serialized HTML (its outer HTML).
	 *
	 * @param DOMNode $node The node.
	 * @return string
	 */
	private static function outer_html( DOMNode $node ): string {
		// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- PHP DOM API.
		$doc = $node->ownerDocument;
		// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		return $doc ? (string) $doc->saveHTML( $node ) : '';
	}

	/**
	 * An HTML block wrapping markup that has no native-block equivalent, so the imported content is
	 * kept exactly as it was rather than dropped.
	 *
	 * @param string $html The markup.
	 * @return string
	 */
	private static function html_fallback( string $html ): string {
		return "<!-- wp:html -->\n" . trim( $html ) . "\n<!-- /wp:html -->";
	}

	/**
	 * Whether a cell is a single heading element (so it reads as a section header, not row content).
	 *
	 * @param string $html Cell HTML.
	 * @return bool
	 */
	private static function is_heading( string $html ): bool {
		// Exactly one heading element, with inline markup allowed inside it but no sibling content and
		// no second heading: the negative lookahead keeps the content from spanning past the first
		// heading's close, so a card like "<h3>..</h3><p>..</p><h4>..</h4>" is not mistaken for a
		// section header.
		return 1 === preg_match( '#^<(h[1-4])\b[^>]*>(?:(?!</?h[1-4]\b).)*</\1>$#is', trim( $html ) );
	}

	/**
	 * Whether a cell is only the page title as a heading (the WordPress page already has the title).
	 *
	 * @param string $html  Cell HTML.
	 * @param string $title Page title.
	 * @return bool
	 */
	private static function is_title( string $html, string $title ): bool {
		if ( ! self::is_heading( $html ) ) {
			return false;
		}
		$text = trim( html_entity_decode( wp_strip_all_tags( $html ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
		return '' !== $text && 0 === strcasecmp( $text, trim( $title ) );
	}
}
