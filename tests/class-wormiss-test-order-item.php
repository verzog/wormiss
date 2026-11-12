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
 * A stand-in WooCommerce order line for the tests.
 *
 * @package    wormiss
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * A stand-in for a WooCommerce order line (WooCommerce is not installed in the tests).
 */
class Wormiss_Test_Order_Item {

	/**
	 * Set up the line.
	 *
	 * @param string $name  Product name.
	 * @param int    $qty   Quantity.
	 * @param float  $total Line total.
	 */
	public function __construct( private string $name, private int $qty, private float $total ) {
	}

	/**
	 * Product name.
	 *
	 * @return string
	 */
	public function get_name(): string {
		return $this->name;
	}

	/**
	 * Quantity.
	 *
	 * @return int
	 */
	public function get_quantity(): int {
		return $this->qty;
	}

	/**
	 * Line total.
	 *
	 * @return float
	 */
	public function get_total(): float {
		return $this->total;
	}
}
