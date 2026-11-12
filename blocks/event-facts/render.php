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
 * Server render for the event facts block: the event's facts and Register button.
 *
 * @package    wormiss
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 * @var WP_Block $block Block.
 */

defined( 'ABSPATH' ) || exit;

$wormiss_post = get_post( (int) ( $block->context['postId'] ?? get_the_ID() ) );
if ( $wormiss_post instanceof WP_Post && Wormiss_Event_Sync::POST_TYPE === $wormiss_post->post_type ) {
	$wormiss_html = Wormiss_Event_Sync::facts_html( $wormiss_post );
	if ( '' !== $wormiss_html ) {
		// Escaped in facts_html().
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '<div ' . get_block_wrapper_attributes() . '>' . $wormiss_html . '</div>';
	}
}
