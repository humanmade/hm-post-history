<?php // phpcs:ignore PSR1.Files.SideEffects.FoundWithSymbols -- WordPress plugin entry point.
/**
 * Plugin Name: HM Post History
 * Description: Display post history inline, with diffs.
 * Author: Human Made Limited
 * Author URI: https://humanmade.com/
 * Version: 2.0.1
 * Requires at least: 6.4
 * Text Domain: hm-post-history
 */

use HM\Post_History;

// Define directory constant for use in included files.
define( 'HM_POST_HISTORY_DIR', trailingslashit( plugin_dir_path( __FILE__ ) ) );

require_once __DIR__ . '/inc/browser.php';
require_once __DIR__ . '/inc/rest.php';

// Start it up.
Post_History\REST\bootstrap();

Post_History\Browser\bootstrap();
