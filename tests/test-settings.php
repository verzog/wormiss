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
 * Tests for Wormiss_Settings.
 *
 * @package    wormiss
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Settings parsing and sanitising.
 *
 * @covers Wormiss_Settings
 */
class Test_Wormiss_Settings extends Wormiss_Test_Case {

	/**
	 * The iMIS address must be https, with any trailing slash and /api removed.
	 *
	 * @return void
	 */
	public function test_normalise_base_url(): void {
		$this->assertSame( 'https://imis.example.org', Wormiss_Settings::normalise_base_url( 'https://imis.example.org/' ) );
		$this->assertSame( 'https://imis.example.org', Wormiss_Settings::normalise_base_url( ' https://imis.example.org/api/ ' ) );
		$this->assertSame( 'https://imis.example.org/site', Wormiss_Settings::normalise_base_url( 'https://imis.example.org/site' ) );
		$this->assertSame( '', Wormiss_Settings::normalise_base_url( 'http://imis.example.org' ) );
		$this->assertSame( '', Wormiss_Settings::normalise_base_url( 'javascript:alert(1)' ) );
		$this->assertSame( '', Wormiss_Settings::normalise_base_url( '' ) );
	}

	/**
	 * Only lines that are IQA paths are kept, once each.
	 *
	 * @return void
	 */
	public function test_parse_queries(): void {
		$text = "\$/Events/Upcoming\n\n  \$/Members/Directory  \nnot a path\n\$/Events/Upcoming\n<b>\$/X</b>";
		$this->assertSame( [ '$/Events/Upcoming', '$/Members/Directory', '$/X' ], Wormiss_Settings::parse_queries( $text ) );
	}

	/**
	 * Only exact approved paths are allowed.
	 *
	 * @return void
	 */
	public function test_is_allowed_query(): void {
		$this->assertTrue( Wormiss_Settings::is_allowed_query( self::QUERY ) );
		$this->assertFalse( Wormiss_Settings::is_allowed_query( '$/Events/UpcomingEvents2' ) );
		$this->assertFalse( Wormiss_Settings::is_allowed_query( '' ) );
	}

	/**
	 * A blank password field keeps the saved password; numbers are clamped.
	 *
	 * @return void
	 */
	public function test_sanitize_keeps_password_and_clamps(): void {
		$clean = Wormiss_Settings::sanitize(
			[
				'base_url'      => 'https://other.example.org/api',
				'password'      => '',
				'timeout'       => '999',
				'cache_minutes' => '0',
			]
		);
		$this->assertSame( 'https://other.example.org', $clean['base_url'] );
		$this->assertSame( 60, $clean['timeout'] );
		$this->assertSame( 1, $clean['cache_minutes'] );
		$this->assertArrayNotHasKey( 'password', $clean );
		$this->assertSame( 's3cret&pass', Wormiss_Settings::password() );

		Wormiss_Settings::sanitize( [ 'password' => 'n3w\\pa$$' ] );
		$this->assertSame( 'n3w\\pa$$', Wormiss_Settings::password() );
	}

	/**
	 * Saving twice (as WordPress does on a first save) keeps the approved queries.
	 *
	 * @return void
	 */
	public function test_sanitize_is_idempotent(): void {
		$once  = Wormiss_Settings::sanitize( [ 'allowed_queries' => "\$/A\n\$/B" ] );
		$twice = Wormiss_Settings::sanitize( $once );
		$this->assertSame( [ '$/A', '$/B' ], $twice['allowed_queries'] );

		// The real first save, through the Settings API.
		delete_option( Wormiss_Settings::OPTION );
		Wormiss_Admin::register_option();
		update_option( Wormiss_Settings::OPTION, [ 'allowed_queries' => "\$/Events/A\n\$/Events/B" ] );
		$this->assertSame( [ '$/Events/A', '$/Events/B' ], Wormiss_Settings::allowed_queries() );
	}

	/**
	 * A non-https address is rejected and the saved one kept.
	 *
	 * @return void
	 */
	public function test_sanitize_rejects_http(): void {
		$clean = Wormiss_Settings::sanitize( [ 'base_url' => 'http://insecure.example.org' ] );
		$this->assertSame( 'https://imis.example.org', $clean['base_url'] );
	}
}
