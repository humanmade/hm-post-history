<?php
/**
 * Functions and setup for REST API.
 *
 * @package HM\PostHistory
 */

namespace HM\Post_History\REST;

use \DateTime;
use \WP_Query;
use \WP_REST_Request;
use \WP_Error;
use const HM\Post_History\DEFAULT_PER_PAGE;
use const HM\Post_History\REST_NAMESPACE;

/**
 * Set up everything the REST API needs.
 *
 * @return void
 */
function bootstrap() : void {
	add_action( 'rest_api_init', __NAMESPACE__ . '\\setup', 20 );
}

/**
 * Setup API. Register rest routes.
 *
 * @return null
 */
function setup() {
	register_rest_route(
		REST_NAMESPACE,
		'/revisions/(?P<id>\d+)',
		[
			'methods' => 'GET',
			'callback' => __NAMESPACE__ . '\\get_revisions_response',
			'permission_callback' => __NAMESPACE__ . '\\revisions_request_permissions_callback',
			'args' => [
				'id' => [
					'sanitize_callback' => 'absint',
					'validate_callback' => function ( $param, $request, $key ) {
						return is_numeric( $param );
					},
				],
				'paged' => [
					'sanitize_callback' => 'absint',
					'validate_callback' => function ( $param, $request, $key ) {
						return is_numeric( $param );
					},
				],
				'per_page' => [
					'sanitize_callback' => 'absint',
					'validate_callback' => function ( $param, $request, $key ) {
						return is_numeric( $param );
					},
				],
			],
		]
	);

}

/**
 * Returns a WP_Query containing revisions.
 *
 * @param array $args Args to modify the query.
 *
 * @return \WP_Query
 */
function get_revisions_query( array $args ) : WP_Query {
	$per_page = $args['per_page'] ?? apply_filters( 'hm_post_history_default_per_page', DEFAULT_PER_PAGE );
	$paged = $args['paged'] ?? 1;
	$id = $args['id'] ?? get_the_ID();

	return new WP_Query( [
		'post_parent' => $id,
		'post_type' => 'revision',
		'post_status' => 'inherit',
		'posts_per_page' => $per_page,
		'paged' => $paged,
	] );
}

/**
 * Compile formatted list of revisions.
 *
 * @param \WP_Query $query Query containing revisions.
 *
 * @return array
 * @throws \Exception
 */
function build_revisions_list( WP_Query $query ) : array {
	$revisions = [];
	foreach ( $query->posts as $revision ) {

		$date = new DateTime( $revision->post_modified_gmt );

		$revisions[] = [
			'id' => $revision->ID,
			'content' => apply_filters( 'the_content', $revision->post_content ),
			'date' => $date->format( 'j M y @ H:i' ),
			'author' => get_the_author_meta( 'display_name', $revision->post_author ),
		];

	}

	return $revisions;
}

/**
 * Get post revisions API request response.
 *
 * @param \WP_REST_Request $request Request
 *
 * @return \WP_REST_Response Response
 * @throws \Exception
 */
function get_revisions_response( WP_REST_Request $request ) : \WP_REST_Response {

	$query = get_revisions_query( [
		'id' => $request->get_param( 'id' ),
		'per_page' => $request->get_param( 'per_page' ),
		'paged' => $request->get_param( 'paged' ),
	] );

	return rest_ensure_response( [
		'revisions' => build_revisions_list( $query ),
		'hasMore' => $request->get_param( 'paged' ) < $query->max_num_pages,
	] );

}

/**
 * Request permissions callback.
 *
 * Revisions of published posts are public. Otherwise they fall back to the edit_post cap.
 *
 * @param WP_REST_Request $request Full data about the request.
 *
 * @return WP_Error|boolean
 */
function revisions_request_permissions_callback( WP_REST_Request $request ) {

	$post_id = $request->get_param( 'id' );

	if ( 'publish' === get_post_status( $post_id ) ) {
		return true;
	}

	$parent = apply_filters( 'rest_the_post', get_post( $post_id ), $post_id );

	if ( ! $parent ) {
		return true;
	}

	$parent_post_type_obj = get_post_type_object( $parent->post_type );

	if ( ! current_user_can( $parent_post_type_obj->cap->edit_post, $parent->ID ) ) {
		return new WP_Error( 'rest_cannot_read', __( 'Sorry, you cannot view revisions of this post.' ),
			[ 'status' => rest_authorization_required_code() ] );
	}

	return true;

}
