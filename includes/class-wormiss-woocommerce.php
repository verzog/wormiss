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
 * WooCommerce purchases recorded in iMIS as activity records (one per paid order).
 *
 * @package    wormiss
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined( 'ABSPATH' ) || exit;

/**
 * Records each paid WooCommerce order against the buyer's iMIS record.
 *
 * Only an activity is written (items, order number, total in Amount); no order, invoice
 * or payment is created in iMIS, so iMIS finance is never touched.
 */
class Wormiss_Woocommerce {

	/** Order meta: when the order was recorded in iMIS, so it is recorded once. */
	const META_RECORDED = '_wormiss_imis_recorded';

	/** Payment methods paid outside the checkout: their "processing" orders may be unpaid. */
	const OFFLINE_METHODS = [ 'cod', 'bacs', 'cheque' ];

	/** Longest note sent (iMIS notes are long text, but keep it readable). */
	const NOTE_LENGTH = 2000;

	/**
	 * Hook in. Nothing happens unless WooCommerce is active and recording purchases is on.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'before_woocommerce_init', [ __CLASS__, 'declare_compatibility' ] );
		// An order is paid once it is processing (or completed, for orders that skip processing).
		add_action( 'woocommerce_order_status_processing', [ __CLASS__, 'maybe_record_order' ], 10, 2 );
		add_action( 'woocommerce_order_status_completed', [ __CLASS__, 'maybe_record_order' ], 10, 2 );
	}

	/**
	 * Tell WooCommerce this plugin works with its order tables (HPOS): orders are only read
	 * through the order object.
	 *
	 * @return void
	 */
	public static function declare_compatibility(): void {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', WORMISS_FILE, true );
		}
	}

	/**
	 * When an order is paid, record it if recording purchases is on.
	 *
	 * @param int        $order_id Order ID.
	 * @param mixed|null $order    The order (WC_Order), when WooCommerce passes it.
	 * @return void
	 */
	public static function maybe_record_order( $order_id, $order = null ): void {
		if ( ! Wormiss_Settings::get( 'record_purchases' ) ) {
			return;
		}
		if ( ! is_object( $order ) && function_exists( 'wc_get_order' ) ) {
			$order = wc_get_order( (int) $order_id );
		}
		if ( is_object( $order ) ) {
			self::record_order( $order );
		}
	}

	/**
	 * Record one order for its buyer, once.
	 *
	 * Guest orders (no WordPress account) and buyers without an iMIS ID are skipped. A dry run
	 * logs the record but does not mark the order, so it is still recorded once live.
	 *
	 * @param object $order WC_Order.
	 * @return bool Whether a record was queued (or logged, in a dry run).
	 */
	public static function record_order( object $order ): bool {
		if ( $order->get_meta( self::META_RECORDED ) ) {
			return false;
		}
		$user_id = (int) $order->get_user_id();
		if ( ! $user_id ) {
			return false;
		}
		// Cash on delivery, bank transfer and cheque orders reach "processing" before they are
		// paid: record them only once the shop marks them completed.
		$offline = (array) apply_filters( 'wormiss_offline_payment_methods', self::OFFLINE_METHODS );
		if ( in_array( (string) $order->get_payment_method(), $offline, true ) && 'completed' !== $order->get_status() ) {
			return false;
		}

		$currency = strtoupper( (string) $order->get_currency() );
		// The shop's own number of decimals (usually 2) for the note; the total is sent unrounded.
		$decimals = function_exists( 'wc_get_price_decimals' ) ? (int) wc_get_price_decimals() : 2;
		$number   = (string) $order->get_order_number();
		$names    = [];
		$lines    = [];
		foreach ( $order->get_items() as $item ) {
			$name    = wp_strip_all_tags( (string) $item->get_name() );
			$qty     = (int) $item->get_quantity();
			$names[] = sprintf( '%s × %d', $name, $qty );
			$lines[] = sprintf( '%s × %d: %s %s', $name, $qty, $currency, number_format( (float) $item->get_total(), $decimals, '.', '' ) );
		}
		$total = (float) $order->get_total();

		/* translators: 1: order number, 2: items. */
		$description = sprintf( __( 'Order #%1$s: %2$s', 'wormiss' ), $number, implode( ', ', $names ) );
		$note        = sprintf(
			/* translators: 1: order number, 2: one line per item, 3: currency, 4: total. */
			__( "WooCommerce order #%1\$s\n%2\$s\nTotal: %3\$s %4\$s", 'wormiss' ),
			$number,
			implode( "\n", $lines ),
			$currency,
			number_format( $total, $decimals, '.', '' )
		);

		$recorded = Wormiss_Activity::record(
			'purchase',
			$description,
			[
				'note'    => mb_substr( $note, 0, self::NOTE_LENGTH ),
				'amount'  => $total,
				'user_id' => $user_id,
			]
		);
		if ( $recorded && ! Wormiss_Settings::get( 'activity_dry_run' ) ) {
			$order->update_meta_data( self::META_RECORDED, time() );
			$order->save();
		}
		return $recorded;
	}
}
