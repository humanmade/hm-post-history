<?php
/**
 * Functions and setup for REST API.
 *
 * @package HM\PostHistory
 */

namespace HM\Post_History\REST;

use HM\Post_History\Browser;
use WP_Post;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Set up everything the REST API needs.
 *
 * @return void
 */
function bootstrap() : void {
	add_action( 'rest_api_init', __NAMESPACE__ . '\\setup', 20 );
	add_filter( 'rest_request_before_callbacks', __NAMESPACE__ . '\\prepare_browser_request', 10, 3 );
	add_filter( 'rest_request_after_callbacks', __NAMESPACE__ . '\\finish_browser_request', 10, 3 );
	add_filter( 'rest_prepare_revision', __NAMESPACE__ . '\\prepare_item', 10, 3 );
}

/**
 * Register timeline presentation fields on Core post and revision responses.
 *
 * @return void
 */
function setup() : void {
	$types = get_post_types_by_support( 'revisions' );
	$revision_types = array_map( static fn( $type ) => $type . '-revision', $types );
	register_rest_field( array_merge( $types, $revision_types ), 'hm_history', [
		'schema' => [
			'type' => 'object',
			'readonly' => true,
			'context' => [ 'view' ],
			'properties' => [
				'date' => [ 'type' => 'string' ],
				'author' => [ 'type' => 'string' ],
				'avatar' => [
					'type' => 'string',
					'format' => 'uri',
				],
				'matches_current' => [ 'type' => 'boolean' ],
			],
		],
	] );
	foreach ( $types as $type ) {
		add_filter( 'rest_prepare_' . $type, __NAMESPACE__ . '\\prepare_item', 10, 3 );
	}
}

/**
 * Use Core's permission check for browser visibility.
 *
 * @param int $id Parent post ID.
 */
function can_read( int $id ) : bool {
	$post_type = get_post_type_object( get_post_type( $id ) );
	if ( ! $post_type ) {
		return false;
	}
	$request = new WP_REST_Request( 'GET' );
	$request['parent'] = $id;
	$controller = $post_type->get_revisions_rest_controller();
	return $controller && $controller->get_items_permissions_check( $request ) === true;
}

/**
 * Whether this request explicitly asks for the history browser's additional field.
 *
 * @param WP_REST_Request $request REST request.
 */
function is_browser_request( WP_REST_Request $request ) : bool {
	return $request->is_method( 'GET' ) && ( $request['context'] ?? 'view' ) === 'view'
		&& ! empty( $request['_fields'] ) && rest_is_field_included( 'hm_history', wp_parse_list( $request['_fields'] ) );
}

/**
 * Scope the host's article presentation to requests made by the history browser.
 *
 * @param mixed $response Earlier short-circuit response.
 * @param array $handler Matched route handler.
 * @param WP_REST_Request $request REST request.
 * @return mixed Unchanged response; Core owns authorization.
 */
function prepare_browser_request( $response, array $handler, WP_REST_Request $request ) {
	if ( is_browser_request( $request ) ) {
		add_filter( 'pre_render_block', __NAMESPACE__ . '\\hide_history_blocks', 10, 2 );
	}
	return $response;
}

/**
 * Respect blocks the host deliberately omits from the article reading view.
 *
 * @param string|null $rendered Earlier short-circuit output.
 * @param array $block Parsed block.
 * @return string|null Existing output, or an empty hidden block.
 */
function hide_history_blocks( $rendered, array $block ) {
	if ( in_array( $block['blockName'], (array) apply_filters( 'hm_post_history_hidden_blocks', [] ), true ) ) {
		return '';
	}
	return $rendered;
}

/**
 * Remove request-scoped block presentation after Core finishes the response.
 *
 * @param mixed $response Core response or error.
 * @param array $handler Route handler.
 * @param WP_REST_Request $request REST request.
 * @return mixed Unchanged Core response.
 */
function finish_browser_request( $response, array $handler, WP_REST_Request $request ) {
	if ( is_browser_request( $request ) ) {
		remove_filter( 'pre_render_block', __NAMESPACE__ . '\\hide_history_blocks', 10 );
	}
	return $response;
}

/**
 * Add timeline metadata and prepare article markup through Core's per-item filter.
 *
 * @param WP_REST_Response $response Core post or revision response.
 * @param WP_Post $post Post represented by the response.
 * @param WP_REST_Request $request REST request.
 */
function prepare_item( WP_REST_Response $response, WP_Post $post, WP_REST_Request $request ) : WP_REST_Response {
	if ( ! is_browser_request( $request ) ) {
		return $response;
	}
	$data = $response->get_data();
	$parent = $post->post_type === 'revision' ? get_post( $post->post_parent ) : $post;
	// Autosaves remain accessible through Core, but are not timeline entries.
	if ( $parent && ! wp_is_post_autosave( $post ) ) {
		$data['hm_history'] = Browser\metadata( $post );
		$data['hm_history']['matches_current'] = $post->post_title === $parent->post_title
			&& $post->post_content === $parent->post_content
			&& $post->post_excerpt === $parent->post_excerpt;
	}
	if ( isset( $data['content']['rendered'] ) ) {
		$data['content']['rendered'] = Browser\prepare_html( $data['content']['rendered'] );
	}
	$response->set_data( $data );
	return $response;
}
