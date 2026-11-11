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
 * Server render for the Upcoming iMIS events block.
 *
 * @package    wormiss
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 * @var array $attributes Block attributes.
 */

defined( 'ABSPATH' ) || exit;

$wormiss_html = Wormiss_Events::render(
	[
		'limit'            => (int) ( $attributes['limit'] ?? 6 ),
		'layout'           => (string) ( $attributes['layout'] ?? 'cards' ),
		'category'         => (string) ( $attributes['category'] ?? '' ),
		'show_description' => (bool) ( $attributes['showDescription'] ?? true ),
		'show_image'       => (bool) ( $attributes['showImage'] ?? true ),
		'button_text'      => (string) ( $attributes['buttonText'] ?? '' ),
		'empty_text'       => (string) ( $attributes['emptyText'] ?? '' ),
	]
);

if ( '' !== $wormiss_html ) {
	// The events class escapes every value it outputs.
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	echo '<div ' . get_block_wrapper_attributes() . '>' . $wormiss_html . '</div>';
}
