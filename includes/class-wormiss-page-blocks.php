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
				$columns[]       = [
					'html'  => $cells[ $zone ],
					'width' => (int) ( $col['width'] ?? 0 ),
				];
			}
			if ( $columns ) {
				$blocks[] = self::row_cols( $columns );
			}
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
			$columns .= "<!-- wp:column -->\n<div class=\"wp-block-column\">" . self::html_block( $html ) . "</div>\n<!-- /wp:column -->\n";
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
		if ( 1 === count( $columns ) ) {
			return self::section( $columns[0]['html'], self::is_heading( $columns[0]['html'] ) );
		}
		$out = '';
		foreach ( $columns as $column ) {
			$pct   = self::width_pct( (int) $column['width'] );
			$attr  = null !== $pct ? ' {"width":"' . $pct . '"}' : '';
			$style = null !== $pct ? ' style="flex-basis:' . $pct . '"' : '';
			$out  .= "<!-- wp:column$attr -->\n<div class=\"wp-block-column\"$style>" . self::html_block( $column['html'] ) . "</div>\n<!-- /wp:column -->\n";
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
		return "<!-- wp:group {\"className\":\"$class\"} -->\n<div class=\"wp-block-group $class\">" . self::html_block( $html ) . "</div>\n<!-- /wp:group -->";
	}

	/**
	 * An HTML block wrapping a cell's markup (kept as HTML so the imported content is preserved
	 * exactly; a block theme styles the headings, paragraphs and images it contains).
	 *
	 * @param string $html Cell HTML.
	 * @return string
	 */
	private static function html_block( string $html ): string {
		return "<!-- wp:html -->\n" . $html . "\n<!-- /wp:html -->";
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
