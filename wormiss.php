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
 * Version:           0.74.0
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

define( 'WORMISS_VERSION', '0.74.0' );
define( 'WORMISS_FILE', __FILE__ );
define( 'WORMISS_DIR', __DIR__ );

require_once __DIR__ . '/includes/class-wormiss-exception.php';
require_once __DIR__ . '/includes/class-wormiss-settings.php';
require_once __DIR__ . '/includes/class-wormiss-logger.php';
require_once __DIR__ . '/includes/class-wormiss-rest-client.php';
require_once __DIR__ . '/includes/class-wormiss-iqa-display.php';
require_once __DIR__ . '/includes/class-wormiss-events.php';
require_once __DIR__ . '/includes/class-wormiss-open-events.php';
require_once __DIR__ . '/includes/class-wormiss-event-types.php';
require_once __DIR__ . '/includes/class-wormiss-event-type-bulk.php';
require_once __DIR__ . '/includes/class-wormiss-event-sync.php';
require_once __DIR__ . '/includes/class-wormiss-event-editor.php';
require_once __DIR__ . '/includes/class-wormiss-event-cpd.php';
require_once __DIR__ . '/includes/class-wormiss-event-registration.php';
require_once __DIR__ . '/includes/class-wormiss-event-import.php';
require_once __DIR__ . '/includes/class-wormiss-event-push.php';
require_once __DIR__ . '/includes/class-wormiss-batch.php';
require_once __DIR__ . '/includes/class-wormiss-media.php';
require_once __DIR__ . '/includes/class-wormiss-images.php';
require_once __DIR__ . '/includes/class-wormiss-roles.php';
require_once __DIR__ . '/includes/class-wormiss-media-categories.php';
require_once __DIR__ . '/includes/class-wormiss-media-access.php';
require_once __DIR__ . '/includes/class-wormiss-media-bulk.php';
require_once __DIR__ . '/includes/class-wormiss-media-migrate.php';
require_once __DIR__ . '/includes/class-wormiss-media-protected.php';
require_once __DIR__ . '/includes/class-wormiss-media-delivery.php';
require_once __DIR__ . '/includes/class-wormiss-news-import.php';
require_once __DIR__ . '/includes/class-wormiss-page-layout.php';
require_once __DIR__ . '/includes/class-wormiss-page-blocks.php';
require_once __DIR__ . '/includes/class-wormiss-page-import.php';
require_once __DIR__ . '/includes/class-wormiss-page-menu.php';
require_once __DIR__ . '/includes/class-wormiss-blog.php';
require_once __DIR__ . '/includes/class-wormiss-blog-import.php';
require_once __DIR__ . '/includes/class-wormiss-blog-filters.php';
require_once __DIR__ . '/includes/class-wormiss-blog-lock.php';
require_once __DIR__ . '/includes/class-wormiss-blog-actions.php';
require_once __DIR__ . '/includes/class-wormiss-blog-pictures.php';
require_once __DIR__ . '/includes/class-wormiss-sign-in.php';
require_once __DIR__ . '/includes/class-wormiss-sign-in-check.php';
require_once __DIR__ . '/includes/class-wormiss-mail-divert.php';
require_once __DIR__ . '/includes/class-wormiss-user-sync.php';
require_once __DIR__ . '/includes/class-wormiss-member-push.php';
require_once __DIR__ . '/includes/class-wormiss-member.php';
require_once __DIR__ . '/includes/class-wormiss-membership.php';
require_once __DIR__ . '/includes/class-wormiss-member-display.php';
require_once __DIR__ . '/includes/class-wormiss-access.php';
require_once __DIR__ . '/includes/class-wormiss-access-rules.php';
require_once __DIR__ . '/includes/class-wormiss-members-only.php';
require_once __DIR__ . '/includes/class-wormiss-activity.php';
require_once __DIR__ . '/includes/class-wormiss-completion.php';
require_once __DIR__ . '/includes/class-wormiss-woocommerce.php';
require_once __DIR__ . '/includes/class-wormiss-content-check.php';
require_once __DIR__ . '/includes/class-wormiss-blog-check.php';
require_once __DIR__ . '/includes/class-wormiss-admin.php';
require_once __DIR__ . '/includes/class-wormiss-plugin.php';

register_activation_hook( __FILE__, [ 'Wormiss_Plugin', 'activate' ] );
register_deactivation_hook( __FILE__, [ 'Wormiss_Plugin', 'deactivate' ] );

Wormiss_Plugin::init();
