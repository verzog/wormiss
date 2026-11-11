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
 * Shared set-up for the plugin tests: a configured connection and a fake iMIS.
 *
 * @package    wormiss
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Base test case: configures the plugin against a fake iMIS.
 */
abstract class Wormiss_Test_Case extends WP_UnitTestCase {

	/** Approved query used by the tests. */
	const QUERY = '$/Events/UpcomingEvents';

	/**
	 * Value.
	 *
	 * @var Wormiss_Fake_Imis
	 */
	protected Wormiss_Fake_Imis $imis;

	/**
	 * Configure the connection and start the fake iMIS.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();
		update_option(
			Wormiss_Settings::OPTION,
			array_merge(
				Wormiss_Settings::defaults(),
				[
					'base_url'        => 'https://imis.example.org',
					'username'        => 'svc-wordpress',
					'allowed_queries' => [ self::QUERY ],
				]
			)
		);
		update_option( Wormiss_Settings::PASSWORD_OPTION, 's3cret&pass' );
		update_option( 'date_format', 'd/m/Y' );
		update_option( 'time_format', 'g:i a' );
		update_option( 'timezone_string', 'Australia/Sydney' );
		$this->imis = new Wormiss_Fake_Imis();
	}

	/**
	 * Stop the fake iMIS.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		$this->imis->stop();
		parent::tear_down();
	}
}
