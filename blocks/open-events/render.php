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
 * Server render for the Open events block.
 *
 * @package    wormiss
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined( 'ABSPATH' ) || exit;

$wormiss_html = Wormiss_Open_Events::render(
	[
		'type'             => sanitize_title( (string) ( $attributes['type'] ?? '' ) ),
		'children'         => (bool) ( $attributes['children'] ?? true ),
		'limit'            => min( 50, max( 1, (int) ( $attributes['limit'] ?? 12 ) ) ),
		'show_description' => (bool) ( $attributes['showDescription'] ?? true ),
		'button_text'      => (string) ( $attributes['buttonText'] ?? '' ),
		'empty_text'       => (string) ( $attributes['emptyText'] ?? '' ),
	]
);
echo '<div ' . get_block_wrapper_attributes() . '>' . $wormiss_html . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped as it is built.
