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
 * Read an iMIS page layout template into a grid of rows and columns.
 *
 * @package    wormiss
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined( 'ABSPATH' ) || exit;

/**
 * An iMIS page's row-and-column arrangement lives in a ContentLayout document, whose LayoutMarkup is
 * a Bootstrap grid with a numbered placeholder ("{1}", "{2}", ...) where each content block's layout
 * zone goes. For example:
 *
 *   <div class="row"><div class="col-sm-8"> {2} </div><div class="col-sm-4"> {3} </div></div>
 *
 * means zone 2 fills eight twelfths of that row and zone 3 the other four. This class turns that
 * markup into an ordered list of rows, each a list of { zone, width } (width in twelfths), so the
 * page import can rebuild the exact grid.
 */
class Wormiss_Page_Layout {

	/**
	 * The template's rows, from its decoded ContentLayout XML: an ordered list of rows, each an
	 * ordered list of [ 'zone' => int, 'width' => int (twelfths) ]. Null when there is no usable
	 * LayoutMarkup.
	 *
	 * @param string $xml The layout document's decoded Data (the ContentLayout XML).
	 * @return array[]|null
	 */
	public static function rows( string $xml ): ?array {
		if ( '' === trim( $xml ) || ! class_exists( 'DOMDocument' ) ) {
			return null;
		}
		if ( ! preg_match( '#<LayoutMarkup[^>]*>(.*?)</LayoutMarkup>#s', $xml, $match ) ) {
			return null;
		}
		$markup = $match[1];
		if ( false !== strpos( $markup, '&lt;' ) ) {
			// Some templates store the markup escaped; decode it to real tags first.
			$markup = html_entity_decode( $markup, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		}
		if ( false === stripos( $markup, 'col-sm-' ) || false === strpos( $markup, '{' ) ) {
			return null;
		}
		$previous = libxml_use_internal_errors( true );
		$dom      = new DOMDocument();
		// Parse the fragment as plain HTML (no iMIS namespaces), so the div/class selectors are simple.
		$loaded = $dom->loadHTML(
			'<meta charset="utf-8"><div id="wormiss-layout-root">' . $markup . '</div>',
			LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING
		);
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );
		if ( ! $loaded ) {
			return null;
		}
		$xpath = new DOMXPath( $dom );
		// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- PHP DOM API.
		$rows = [];
		foreach ( $xpath->query( '//div[contains(concat(" ", normalize-space(@class), " "), " row ")]' ) as $row_node ) {
			$columns = [];
			foreach ( $xpath->query( './/div[contains(@class, "col-sm-")]', $row_node ) as $col_node ) {
				if ( ! preg_match( '/col-sm-(\d+)/', $col_node->getAttribute( 'class' ), $width )
					|| ! preg_match( '/^\{(\d+)\}$/', trim( $col_node->textContent ), $zone ) ) {
					continue; // Not a leaf placeholder column (e.g. a wrapper holding a nested row).
				}
				$columns[] = [
					'zone'  => (int) $zone[1],
					'width' => (int) $width[1],
				];
			}
			if ( $columns ) {
				$rows[] = $columns;
			}
		}
		// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		return $rows ? $rows : null;
	}
}
