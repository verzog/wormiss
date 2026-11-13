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
 * Keeps background import batches small: a time limit per batch, and a record of the item
 * being worked on so one that stops the batch (killed by the host) is skipped after two tries.
 *
 * @package    wormiss
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined( 'ABSPATH' ) || exit;

/**
 * Batch time limit and stuck-item guard.
 */
class Wormiss_Batch {

	/**
	 * Seconds a batch starts new work for (less where PHP allows less time).
	 */
	const SECONDS = 20;

	/**
	 * Unfinished tries after which an item is skipped.
	 */
	const MAX_TRIES = 2;

	/**
	 * When this batch started (microtime), or 0.
	 *
	 * @var float
	 */
	private static $started = 0.0;

	/**
	 * Whether work was put off for the next batch (out of time or pictures).
	 *
	 * @var bool
	 */
	private static $stopped = false;

	/**
	 * Whether the item being worked on got further (a picture copied, a name looked up).
	 *
	 * @var bool
	 */
	private static $progress = false;

	/**
	 * Start a batch's clock.
	 *
	 * @return void
	 */
	public static function start(): void {
		self::$started = microtime( true );
		self::$stopped = false;
	}

	/**
	 * Seconds this batch may start new work for: SECONDS, or half of PHP's time limit if less.
	 *
	 * @return int
	 */
	public static function seconds(): int {
		$limit   = (int) ini_get( 'max_execution_time' );
		$seconds = $limit > 0 ? max( 5, min( self::SECONDS, intdiv( $limit, 2 ) ) ) : self::SECONDS;
		/**
		 * Seconds a background import batch starts new work for.
		 *
		 * @param int $seconds Seconds (default 20, or half of PHP's time limit if less).
		 */
		return max( 0, (int) apply_filters( 'wormiss_batch_seconds', $seconds ) );
	}

	/**
	 * Whether the batch has used its time.
	 *
	 * @return bool
	 */
	public static function out_of_time(): bool {
		return self::$started > 0 && microtime( true ) - self::$started >= self::seconds();
	}

	/**
	 * Put the rest of this batch's work off to the next batch.
	 *
	 * @return void
	 */
	public static function stop(): void {
		self::$stopped = true;
	}

	/**
	 * Whether work was put off (stop() called, or out of time).
	 *
	 * @return bool
	 */
	public static function stopped(): bool {
		return self::$stopped || self::out_of_time();
	}

	/**
	 * Whether stop() put work off (an item left part done).
	 *
	 * @return bool
	 */
	public static function put_off(): bool {
		return self::$stopped;
	}

	/**
	 * Record that an item is being worked on.
	 *
	 * @param string $option Option holding the item being worked on.
	 * @param string $key    Item key.
	 * @return int How many earlier batches started it and never finished.
	 */
	public static function begin( string $option, string $key ): int {
		self::$progress = false;
		$working        = get_option( $option );
		$tries          = is_array( $working ) && ( $working['key'] ?? '' ) === $key ? (int) ( $working['tries'] ?? 0 ) : 0;
		update_option(
			$option,
			[
				'key'   => $key,
				'tries' => $tries + 1,
			],
			false
		);
		return $tries;
	}

	/**
	 * Note that the item being worked on got further.
	 *
	 * @return void
	 */
	public static function progress(): void {
		self::$progress = true;
	}

	/**
	 * After an item: record it as finished, unless it was put off without getting any further,
	 * which counts as a try (so an item that never gets further is skipped after MAX_TRIES).
	 *
	 * @param string $option Option holding the item being worked on.
	 * @return void
	 */
	public static function done( string $option ): void {
		if ( ! self::$stopped || self::$progress ) {
			self::end( $option );
		}
	}

	/**
	 * Record that the item worked on is finished (or skipped).
	 *
	 * @param string $option Option holding the item being worked on.
	 * @return void
	 */
	public static function end( string $option ): void {
		delete_option( $option );
	}
}
