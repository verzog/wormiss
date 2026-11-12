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
 * PHPUnit bootstrap: loads the WordPress test library (wp-phpunit) and the plugin.
 *
 * @package    wormiss
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

$wormiss_root  = dirname( __DIR__ );
$wormiss_tests = getenv( 'WP_PHPUNIT__DIR' );
if ( ! $wormiss_tests ) {
	$wormiss_tests = $wormiss_root . '/vendor/wp-phpunit/wp-phpunit';
}

require_once $wormiss_root . '/vendor/autoload.php';
define( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH', $wormiss_root . '/vendor/yoast/phpunit-polyfills' );

require_once $wormiss_tests . '/includes/functions.php';

tests_add_filter(
	'muplugins_loaded',
	static function () use ( $wormiss_root ) {
		require $wormiss_root . '/wormiss.php';
	}
);

require $wormiss_tests . '/includes/bootstrap.php';

// Activation hooks do not run in tests: create the log table once, outside any test transaction.
Wormiss_Logger::install();

require_once __DIR__ . '/class-wormiss-fake-imis.php';
require_once __DIR__ . '/class-wormiss-test-order-item.php';
require_once __DIR__ . '/class-wormiss-test-order.php';
require_once __DIR__ . '/class-wormiss-test-case.php';
