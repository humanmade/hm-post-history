<?php
/**
 * Plugin Name: HM Post History
 * Description: Display post history inline, with diffs.
 * Author: Human Made Limited
 * Author URI: https://humanmade.com/
 * Version: 1.5.0
 * Text Domain: hm-post-history
 */

// Define directory constant for use in included files.
define( 'HM_POST_HISTORY_DIR', trailingslashit( plugin_dir_path( __FILE__ ) ) );
// Define URL constant for use in includes files.
define( 'HM_POST_HISTORY_URL', trailingslashit( plugin_dir_url( __FILE__ ) ) );

require_once __DIR__ . '/inc/class-post-history-widget.php';
require_once __DIR__ . '/inc/rest.php';
require_once __DIR__ . '/inc/namespace.php';

// Start it up.
\HM\Post_History\bootstrap();
