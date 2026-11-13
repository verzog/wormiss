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
 * Uploaded pictures scaled down on upload: imported news and blog pictures and anything
 * staff upload.
 *
 * @package    wormiss
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined( 'ABSPATH' ) || exit;

/**
 * Upload size limit.
 */
class Wormiss_Images {

	/**
	 * Longest side, in pixels, that an uploaded picture is scaled down to.
	 */
	const MAX_SIZE = 1600;

	/**
	 * Hook in.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_filter( 'big_image_size_threshold', [ __CLASS__, 'threshold' ], 20 );
	}

	/**
	 * WordPress scales a bigger upload down to this (and uses the smaller copy everywhere).
	 *
	 * @param int|false $threshold WordPress's limit (2560 by default).
	 * @return int|false
	 */
	public static function threshold( $threshold ) {
		/**
		 * Longest side, in pixels, that uploaded pictures are scaled down to (0 for WordPress's own).
		 *
		 * @param int $size Pixels (default 1600).
		 */
		$size = (int) apply_filters( 'wormiss_image_max_size', self::MAX_SIZE );
		return $size > 0 ? $size : $threshold;
	}
}
