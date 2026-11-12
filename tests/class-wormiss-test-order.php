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
 * A stand-in WooCommerce order for the tests.
 *
 * @package    wormiss
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * A stand-in for a WooCommerce order, with the methods the plugin uses.
 */
class Wormiss_Test_Order {

	/**
	 * Order meta.
	 *
	 * @var array
	 */
	public array $meta = [];

	/**
	 * Times save() was called.
	 *
	 * @var int
	 */
	public int $saves = 0;

	/**
	 * Set up the order.
	 *
	 * @param int                       $user_id Buyer's WordPress user ID (0 for a guest).
	 * @param Wormiss_Test_Order_Item[] $items   Lines.
	 * @param float                     $total   Order total.
	 */
	public function __construct( private int $user_id, private array $items, private float $total ) {
	}

	/**
	 * Buyer's user ID.
	 *
	 * @return int
	 */
	public function get_user_id(): int {
		return $this->user_id;
	}

	/**
	 * Order number.
	 *
	 * @return string
	 */
	public function get_order_number(): string {
		return '1042';
	}

	/**
	 * Currency.
	 *
	 * @return string
	 */
	public function get_currency(): string {
		return 'aud';
	}

	/**
	 * Lines.
	 *
	 * @return Wormiss_Test_Order_Item[]
	 */
	public function get_items(): array {
		return $this->items;
	}

	/**
	 * Total.
	 *
	 * @return float
	 */
	public function get_total(): float {
		return $this->total;
	}

	/**
	 * Read meta.
	 *
	 * @param string $key Key.
	 * @return mixed
	 */
	public function get_meta( string $key ) {
		return $this->meta[ $key ] ?? '';
	}

	/**
	 * Set meta.
	 *
	 * @param string $key   Key.
	 * @param mixed  $value Value.
	 * @return void
	 */
	public function update_meta_data( string $key, $value ): void {
		$this->meta[ $key ] = $value;
	}

	/**
	 * Save.
	 *
	 * @return void
	 */
	public function save(): void {
		++$this->saves;
	}
}
