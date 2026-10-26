<?php
/**
 * Functions and setup for REST API.
 *
 * @package HM\PostHistory
 */

namespace HM\Post_History\REST;

use const HM\Post_History\DEFAULT_PER_PAGE;
use const HM\Post_History\REST_NAMESPACE;
use DateTime;
use WP_Error;
use WP_Query;
use WP_REST_Request;

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
 * @return void
 */
function setup() : void {
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
	$default_per_page = apply_filters( 'hm_post_history_default_per_page', DEFAULT_PER_PAGE );
	return \HM\Post_History\Versions\query( (int) ( $args['id'] ?? get_the_ID() ), [
		// phpcs:ignore WordPress.WP.PostsPerPage.posts_per_page_posts_per_page -- Legacy callers remain capped at 50, with a three-item default.
		'posts_per_page' => min( 50, max( 1, (int) ( $args['per_page'] ?? $default_per_page ) ) ),
		'paged' => max( 1, (int) ( $args['paged'] ?? 1 ) ),
		'no_found_rows' => false,
	] );
}

/**
 * Compile formatted list of revisions.
 *
 * @param \WP_Query $query Query containing revisions.
 *
 * @return array
 * @throws \Exception If a saved revision date is invalid.
 */
function build_revisions_list( WP_Query $query ) : array {
	$revisions = [];
	foreach ( $query->posts as $revision ) {

		if ( ! \HM\Post_History\Versions\can_read_version( get_post( $revision->post_parent ), $revision ) ) {
			continue;
		}
		$date = new DateTime( $revision->post_modified_gmt );

		$revisions[] = [
			'id' => $revision->ID,
			'content' => \HM\Post_History\Browser\saved_html( $revision->post_content ),
			'date' => $date->format( 'Y-m-d @ H:i' ),
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
 * @throws \Exception If a saved revision date is invalid.
 */
function get_revisions_response( WP_REST_Request $request ) : \WP_REST_Response {

	$query = get_revisions_query( [
		'id' => $request->get_param( 'id' ),
		'per_page' => $request->get_param( 'per_page' ),
		'paged' => $request->get_param( 'paged' ),
	] );

	return \HM\Post_History\Browser\response( [
		'revisions' => build_revisions_list( $query ),
		'hasMore' => max( 1, (int) $request->get_param( 'paged' ) ) < $query->max_num_pages,
	] );

}

/**
 * Request permissions callback.
 *
 * Authentication and parent access are required; version visibility is filtered in the shared query.
 *
 * @param WP_REST_Request $request Full data about the request.
 *
 * @return WP_Error|boolean
 */
function revisions_request_permissions_callback( WP_REST_Request $request ) {

	return \HM\Post_History\Versions\can_read( (int) $request['id'] ) ?: new WP_Error(
		'rest_cannot_read', __( 'Sorry, you cannot view history for this page.', 'hm-post-history' ),
		[ 'status' => rest_authorization_required_code() ]
	);
}
