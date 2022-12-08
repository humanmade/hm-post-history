<?php
/**
 * Root namespaced function for post history plugin.
 *
 * @package HM\PostHistory
 */

namespace HM\Post_History;

use Asset_Loader;
use HM\Post_History\REST;

const FEATURE = 'hm-post-history';
const REST_NAMESPACE = 'post-history/v1';
const MARKER_DATA_ATTR = 'data-hm-post-history-content';
const DEFAULT_PER_PAGE = 3;

/**
 * Attach necessary hooks, etc, to start up the plugin.
 *
 * @return void
 */
function bootstrap() : void {
	add_action( 'init', __NAMESPACE__ . '\\setup_post_types' );
	add_action( 'widgets_init', __namespace__ . '\\setup_widget' );

	// Some bootstrap methods we want behind a conditional that requires WP to be loaded.
	add_action( 'init', __NAMESPACE__ . '\\conditional_bootstrap' );
}

function conditional_bootstrap() : void {
	if ( ! should_enable() ) {
		return;
	}

	// Set up content marker.
	add_filter( 'the_content', __NAMESPACE__ . '\\add_content_marker' );

	// Assets.
	add_action( 'wp_enqueue_scripts', __NAMESPACE__ . '\\enqueue_assets' );
	add_action( 'wp_enqueue_scripts', __NAMESPACE__ . '\\insert_server_side_settings', 20 );
	add_action( 'wp_enqueue_scripts', __NAMESPACE__ . '\\insert_per_item_data', 20 );

	// REST API.
	REST\bootstrap();
}

function setup_widget() {
	register_widget( Post_History_Widget::class );
}

/**
 * Determines whether this feature should be enabled at runtime.
 *
 * This is primarily used to gate enabling the REST API and enqueuing assets,
 * but could be used for other tests too. The questions being answered here are:
 *
 * - Can this user access this content?
 * - Are there any resources on this site that support this feature?
 *
 * @return bool
 */
function should_enable() : bool {
	// Only logged-in users can see this content; eventually this logic may be more complex.
	$valid_user = is_user_logged_in();

	$post_types_with_support = get_post_types_by_support( FEATURE );

	return apply_filters(
		'hm_post_history_should_enable',
		$valid_user && count( $post_types_with_support ) > 0
	);
}

/**
 * Adds this feature to the 'supports' array of the specified post types.
 *
 * By default, support is added to any post type that is already declaring
 * support for revisions.
 *
 * To add support for another post type, either add this feature to the
 * 'supports' array when declaring a custom post type, or filter
 * 'hm_post_history_supported_post_types' to add/remove post types.
 *
 * @return void
 */
function setup_post_types() : void {
	array_map(
		fn( $type ) => add_post_type_support( $type, FEATURE ),
		apply_filters( 'hm_post_history_supported_post_types', get_post_types_by_support( 'revisions' ) )
	);
}

/**
 * Enqueue any necessary assets.
 *
 * @return void
 */
function enqueue_assets() : void {
	Asset_Loader\enqueue_asset(
		HM_POST_HISTORY_DIR . 'assets/build/production-asset-manifest.json',
		'frontend.js',
		[
			'handle' => FEATURE,
		]
	);

	Asset_Loader\enqueue_asset(
		HM_POST_HISTORY_DIR . 'assets/build/production-asset-manifest.json',
		'frontend.css',
		[
			'handle' => FEATURE,
		]
	);
}

/**
 * Create a JS object containing settings needed by JS at runtime.
 *
 * These values are things that remain relatively static and are unlikely to
 * change.
 *
 * @return void
 */
function insert_server_side_settings() : void {
	wp_localize_script(
		FEATURE,
		'HMPostHistorySettings',
		[
			'api_base' => rest_url( REST_NAMESPACE ),
			'api_nonce' => wp_create_nonce( 'wp_rest' ),
			'per_page_default' => apply_filters( 'hm_post_history_default_per_page', DEFAULT_PER_PAGE ),
		]
	);
}

/**
 * Create a JS object containing per-item settings needed by JS at runtime.
 *
 * These values are things that must vary per item (like the ID) or that an
 * implementor may way to vary depending on other conditions (such as the
 * selector used by the content marker).
 *
 * @return void
 */
function insert_per_item_data() : void {
	wp_localize_script(
		FEATURE,
		'HMPostHistoryCurrentItem',
		[
			'post_id' => get_the_ID(),
			'marker_selector' => apply_filters(
				'hm_post_history_marker_selector',
				sprintf( '[%s]', MARKER_DATA_ATTR )
			),
		]
	);
}

/**
 * Conditionally adds a hidden element to the post content.
 *
 * This element is used by JavaScript to detect the content on the frontend,
 * so that it knows what HTML element to replace.
 *
 * @param string $content Post content pre-output.
 *
 * @return string
 */
function add_content_marker( string $content ) : string {
	$should_add_marker = apply_filters(
		'hm_post_history_should_add_marker',
		is_main_query() && is_singular(),
		$content
	);
	if ( $should_add_marker ) {
		// Prepend our marker to the content.
		$content = sprintf( '<a hidden %s="%d"></a>', MARKER_DATA_ATTR, get_the_ID() ) . $content;
	}

	return $content;
}
