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
 * Tests for Wormiss_Logger.
 *
 * @package    wormiss
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * The audit log.
 *
 * @covers Wormiss_Logger
 * @covers Wormiss_Admin::log_bodies
 */
class Test_Wormiss_Logger extends Wormiss_Test_Case {

	/**
	 * Tokens and passwords are removed in every format iMIS uses.
	 *
	 * @return void
	 */
	public function test_redact(): void {
		$this->assertSame( 'Authorization: Bearer [redacted]', Wormiss_Logger::redact( 'Authorization: Bearer abc.DEF-123_x/y+z=' ) );
		$this->assertSame(
			'{"access_token":"[redacted]","expires_in":3599}',
			Wormiss_Logger::redact( '{"access_token":"tok","expires_in":3599}' )
		);
		$this->assertSame( '{"password":"[redacted]"}', Wormiss_Logger::redact( '{"password":"hunter2"}' ) );
		$this->assertSame(
			'grant_type=password&password=[redacted]&x=1',
			Wormiss_Logger::redact( 'grant_type=password&password=hunter2&x=1' )
		);
	}

	/**
	 * Calls are logged, bodies only when capturing, and old rows purged.
	 *
	 * @return void
	 */
	public function test_record_capture_and_purge(): void {
		Wormiss_Logger::record_call( 'GET /api/iqa', Wormiss_Logger::STATUS_SUCCESS, null, '{"password":"p"}', '{"rows":1}' );
		$row = Wormiss_Logger::recent( 1 )[0];
		$this->assertSame( 'GET /api/iqa', $row['endpoint'] );
		$this->assertNull( $row['request'] );
		$this->assertNull( $row['response'] );

		update_option( Wormiss_Settings::OPTION, array_merge( Wormiss_Settings::all(), [ 'log_capture' => true ] ) );
		Wormiss_Logger::record_call( 'POST /x', Wormiss_Logger::STATUS_ERROR, 'Bearer abc failed', '{"password":"p"}', 'body' );
		$row = Wormiss_Logger::recent( 1 )[0];
		$this->assertSame( '{"password":"[redacted]"}', $row['request'] );
		$this->assertSame( 'Bearer [redacted] failed', $row['error'] );

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Age a row for the purge test.
		$wpdb->update( Wormiss_Logger::table(), [ 'created' => '2000-01-01 00:00:00' ], [ 'id' => $row['id'] ] );
		$this->assertSame( 1, Wormiss_Logger::purge( 30 ) );
	}

	/**
	 * Nothing is written when logging is off.
	 *
	 * @return void
	 */
	public function test_disabled(): void {
		update_option( Wormiss_Settings::OPTION, array_merge( Wormiss_Settings::all(), [ 'log_enabled' => false ] ) );
		$before = Wormiss_Logger::count();
		Wormiss_Logger::record_call( 'GET /x', Wormiss_Logger::STATUS_SUCCESS );
		$this->assertSame( $before, Wormiss_Logger::count() );
	}

	/**
	 * The log page shows a kept request and response, escaped and folded away; nothing when
	 * none was kept.
	 *
	 * @return void
	 */
	public function test_log_bodies(): void {
		$this->assertSame(
			'',
			Wormiss_Admin::log_bodies(
				[
					'request'  => null,
					'response' => null,
				]
			)
		);
		$html = Wormiss_Admin::log_bodies(
			[
				'request'  => '{"Name":"<b>Course</b>"}',
				'response' => 'Not JSON <script>',
			]
		);
		$this->assertStringContainsString( '<details><summary>Show</summary>', $html );
		$this->assertStringContainsString( '&quot;Name&quot;: &quot;&lt;b&gt;Course&lt;/b&gt;&quot;', $html );
		$this->assertStringContainsString( 'Not JSON &lt;script&gt;', $html );
		$this->assertStringNotContainsString( '<script>', $html );
	}
}
