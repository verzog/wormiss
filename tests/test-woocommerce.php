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
 * Tests for recording WooCommerce purchases in iMIS.
 *
 * @package    wormiss
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * WooCommerce purchases.
 *
 * @covers Wormiss_Woocommerce
 * @covers Wormiss_Activity
 */
class Test_Wormiss_Woocommerce extends Wormiss_Test_Case {

	/**
	 * Start with nothing queued.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();
		wp_unschedule_hook( Wormiss_Activity::SEND_HOOK );
	}

	/**
	 * Turn recording (and recording purchases) on.
	 *
	 * @param bool $dry_run   Dry run.
	 * @param bool $purchases Record purchases.
	 * @return void
	 */
	private function turn_on( bool $dry_run = false, bool $purchases = true ): void {
		update_option(
			Wormiss_Settings::OPTION,
			array_merge(
				Wormiss_Settings::all(),
				[
					'activity_enabled' => true,
					'activity_dry_run' => $dry_run,
					'activity_type'    => 'WEBACT',
					'record_purchases' => $purchases,
				]
			)
		);
	}

	/**
	 * A member with an iMIS ID (not signed in: payments often complete in the background).
	 *
	 * @return int User ID.
	 */
	private function member(): int {
		$user_id = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		update_user_meta( $user_id, 'imis_id', '12345' );
		wp_set_current_user( 0 );
		return $user_id;
	}

	/**
	 * An order for a member.
	 *
	 * @param int $user_id Buyer.
	 * @return Wormiss_Test_Order
	 */
	private static function order( int $user_id ): Wormiss_Test_Order {
		return new Wormiss_Test_Order(
			$user_id,
			[
				new Wormiss_Test_Order_Item( 'Dermoscopy Atlas', 1, 100.0 ),
				new Wormiss_Test_Order_Item( 'Workshop <b>notes</b>', 2, 20.5 ),
			],
			120.5
		);
	}

	/**
	 * Records queued to be sent.
	 *
	 * @return array[]
	 */
	private function queued(): array {
		$out = [];
		foreach ( (array) _get_cron_array() as $hooks ) {
			foreach ( $hooks[ Wormiss_Activity::SEND_HOOK ] ?? [] as $event ) {
				$out[] = $event['args'][0];
			}
		}
		return $out;
	}

	/**
	 * A property value from an activity body.
	 *
	 * @param array  $body Activity body.
	 * @param string $name Property name.
	 * @return mixed
	 */
	private static function prop( array $body, string $name ) {
		foreach ( $body['Properties']['$values'] as $prop ) {
			if ( $prop['Name'] === $name ) {
				return $prop['Value'] ?? null;
			}
		}
		return 'missing';
	}

	/**
	 * A paid order is recorded once for its buyer, with items, number and total in Amount.
	 *
	 * @return void
	 */
	public function test_order_recorded_once(): void {
		$this->turn_on();
		$order = self::order( $this->member() );

		$this->assertTrue( Wormiss_Woocommerce::record_order( $order ) );
		$this->assertFalse( Wormiss_Woocommerce::record_order( $order ) );
		$this->assertNotEmpty( $order->meta[ Wormiss_Woocommerce::META_RECORDED ] );
		$this->assertSame( 1, $order->saves );

		$queued = $this->queued();
		$this->assertCount( 1, $queued );
		$this->assertSame( 'purchase', $queued[0]['kind'] );
		$this->assertSame( '12345', $queued[0]['imis_id'] );
		$this->assertSame( 'Order #1042: Dermoscopy Atlas × 1, Workshop notes × 2', $queued[0]['description'] );
		$this->assertStringContainsString( 'Dermoscopy Atlas × 1: AUD 100.00', $queued[0]['note'] );
		$this->assertStringContainsString( 'Total: AUD 120.50', $queued[0]['note'] );

		$body = Wormiss_Activity::body( $queued[0] );
		$this->assertSame( 120.5, self::prop( $body, 'AMOUNT' )['$value'] );
		$this->assertSame( 'purchase', self::prop( $body, 'NOTE_2' ) );
	}

	/**
	 * Guest orders, buyers without an iMIS ID, and sites with purchases off are skipped.
	 *
	 * @return void
	 */
	public function test_skipped(): void {
		$this->turn_on();
		$this->assertFalse( Wormiss_Woocommerce::record_order( self::order( 0 ) ) );
		$no_id = self::factory()->user->create();
		$this->assertFalse( Wormiss_Woocommerce::record_order( self::order( $no_id ) ) );

		$this->turn_on( false, false );
		$order = self::order( $this->member() );
		Wormiss_Woocommerce::maybe_record_order( 1042, $order );
		$this->assertSame( [], $this->queued() );

		$this->turn_on();
		Wormiss_Woocommerce::maybe_record_order( 1042, $order );
		$this->assertCount( 1, $this->queued() );
	}

	/**
	 * A dry run logs the order but does not mark it, so it is still recorded once live.
	 *
	 * @return void
	 */
	public function test_dry_run_leaves_order_unmarked(): void {
		$this->turn_on( true );
		$order = self::order( $this->member() );
		$this->assertTrue( Wormiss_Woocommerce::record_order( $order ) );
		$this->assertSame( [], $this->queued() );
		$this->assertArrayNotHasKey( Wormiss_Woocommerce::META_RECORDED, $order->meta );

		$this->turn_on();
		$this->assertTrue( Wormiss_Woocommerce::record_order( $order ) );
		$this->assertCount( 1, $this->queued() );
	}

	/**
	 * Offline payments (cash on delivery, bank transfer, cheque) wait for "completed".
	 *
	 * @return void
	 */
	public function test_offline_payment_waits_for_completed(): void {
		$this->turn_on();
		$order                 = self::order( $this->member() );
		$order->payment_method = 'cod';
		$this->assertFalse( Wormiss_Woocommerce::record_order( $order ) );
		$this->assertSame( [], $this->queued() );

		$order->status = 'completed';
		$this->assertTrue( Wormiss_Woocommerce::record_order( $order ) );
		$this->assertCount( 1, $this->queued() );
	}

	/**
	 * The total is sent as WooCommerce gives it, not rounded.
	 *
	 * @return void
	 */
	public function test_total_not_rounded(): void {
		$this->turn_on();
		$order = new Wormiss_Test_Order( $this->member(), [ new Wormiss_Test_Order_Item( 'Pen', 1, 12.345 ) ], 12.345 );
		$this->assertTrue( Wormiss_Woocommerce::record_order( $order ) );
		$this->assertSame( 12.345, $this->queued()[0]['amount'] );
	}

	/**
	 * Records queued before amounts were recorded still send (Amount 0).
	 *
	 * @return void
	 */
	public function test_older_records_without_amount(): void {
		$this->turn_on();
		$body = Wormiss_Activity::body(
			[
				'kind'        => 'read',
				'imis_id'     => '12345',
				'description' => 'A page',
				'note'        => '',
				'points'      => 0,
				'post_id'     => 3,
				'date'        => '2027-01-01T09:00:00',
			]
		);
		$this->assertSame( 0.0, (float) self::prop( $body, 'AMOUNT' )['$value'] );
	}
}
