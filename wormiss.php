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
 * Plugin Name:       Wormiss – iMIS bridge
 * Plugin URI:        https://github.com/verzog/wormiss
 * Description:       Connects WordPress to iMIS: iMIS data on pages, members' data, members-only content and activity records.
 * Version:           0.12.0
 * Requires at least: 6.6
 * Requires PHP:      8.1
 * Author:            Vernon Spain
 * License:           GPL-3.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain:       wormiss
 *
 * @package    wormiss
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined( 'ABSPATH' ) || exit;

define( 'WORMISS_VERSION', '0.12.0' );
define( 'WORMISS_FILE', __FILE__ );
define( 'WORMISS_DIR', __DIR__ );

require_once __DIR__ . '/includes/class-wormiss-exception.php';
require_once __DIR__ . '/includes/class-wormiss-settings.php';
require_once __DIR__ . '/includes/class-wormiss-logger.php';
require_once __DIR__ . '/includes/class-wormiss-rest-client.php';
require_once __DIR__ . '/includes/class-wormiss-iqa-display.php';
require_once __DIR__ . '/includes/class-wormiss-events.php';
require_once __DIR__ . '/includes/class-wormiss-event-types.php';
require_once __DIR__ . '/includes/class-wormiss-event-sync.php';
require_once __DIR__ . '/includes/class-wormiss-event-registration.php';
require_once __DIR__ . '/includes/class-wormiss-member.php';
require_once __DIR__ . '/includes/class-wormiss-member-display.php';
require_once __DIR__ . '/includes/class-wormiss-access.php';
require_once __DIR__ . '/includes/class-wormiss-members-only.php';
require_once __DIR__ . '/includes/class-wormiss-activity.php';
require_once __DIR__ . '/includes/class-wormiss-completion.php';
require_once __DIR__ . '/includes/class-wormiss-woocommerce.php';
require_once __DIR__ . '/includes/class-wormiss-admin.php';
require_once __DIR__ . '/includes/class-wormiss-plugin.php';

register_activation_hook( __FILE__, [ 'Wormiss_Plugin', 'activate' ] );
register_deactivation_hook( __FILE__, [ 'Wormiss_Plugin', 'deactivate' ] );

Wormiss_Plugin::init();
