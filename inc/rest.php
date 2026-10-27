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
use function HM\Post_History\should_enable;
use HM\Post_History\Browser;
use WP_Error;
use WP_Post;
use WP_Query;
use WP_REST_Request;
use WP_REST_Response;

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
 * @return WP_Query
 */
function get_revisions_query( array $args ) : WP_Query {
	$id = (int) ( $args['id'] ?? get_the_ID() );
	$default_per_page = apply_filters( 'hm_post_history_default_per_page', DEFAULT_PER_PAGE );
	return new WP_Query( [
		'post_parent' => $id,
		'post_type' => 'revision',
		'post_status' => 'inherit',
		'post__in' => can_read( $id ) ? [] : [ 0 ],
		// phpcs:ignore WordPress.WP.PostsPerPage.posts_per_page_posts_per_page -- Preserve bounded legacy pagination and its default-size filter.
		'posts_per_page' => min( 50, max( 1, (int) ( $args['per_page'] ?? $default_per_page ) ) ),
		'paged' => max( 1, (int) ( $args['paged'] ?? 1 ) ),
	] );
}

/**
 * Compile formatted list of revisions.
 *
 * @param WP_Query $query Query containing revisions.
 *
 * @return array
 */
function build_revisions_list( WP_Query $query ) : array {
	$revisions = [];
	foreach ( $query->posts as $revision ) {

		if ( ! can_read_revision( get_post( $revision->post_parent ), $revision ) ) {
			continue;
		}
		$date = new DateTime( $revision->post_modified_gmt );

		$revisions[] = [
			'id' => $revision->ID,
			'content' => Browser\saved_html( $revision->post_content ),
			'date' => $date->format( 'Y-m-d @ H:i' ),
			'author' => get_the_author_meta( 'display_name', $revision->post_author ),
		];

	}

	return $revisions;
}

/**
 * Get post revisions API request response.
 *
 * @param WP_REST_Request $request Request
 *
 * @return WP_REST_Response Response
 */
function get_revisions_response( WP_REST_Request $request ) : WP_REST_Response {

	$query = get_revisions_query( [
		'id' => $request->get_param( 'id' ),
		'per_page' => $request->get_param( 'per_page' ),
		'paged' => $request->get_param( 'paged' ),
	] );

	return Browser\response( [
		'revisions' => build_revisions_list( $query ),
		'hasMore' => max( 1, (int) $request->get_param( 'paged' ) ) < $query->max_num_pages,
	] );

}

/**
 * Request permissions callback.
 *
 * Core's parent edit permission is required; individual revisions are checked before rendering.
 *
 * @param WP_REST_Request $request Full data about the request.
 *
 * @return WP_Error|boolean
 */
function revisions_request_permissions_callback( WP_REST_Request $request ) {

	return can_read( (int) $request['id'] ) ?: new WP_Error(
		'rest_cannot_read', __( 'Sorry, you cannot view history for this page.', 'hm-post-history' ),
		[ 'status' => rest_authorization_required_code() ]
	);
}

/**
 * Match Core's revision access: editors of the parent can inspect its saved revisions.
 *
 * @param int $id Parent post ID.
 */
function can_read( int $id ) : bool {
	$post = get_post( $id );
	return $post && should_enable() && post_type_supports( $post->post_type, 'hm-post-history' )
		&& ( ! is_multisite() || is_super_admin() || is_user_member_of_blog() )
		&& current_user_can( 'edit_post', $id ) && current_user_can( 'read_post', $id )
		&& ! post_password_required( $post )
		&& (bool) apply_filters( 'hm_post_history_can_read_post', true, $post, get_current_user_id() );
}

/**
 * Validate ownership before rendering a revision or using it as a comparison base.
 *
 * @param WP_Post $parent Revision parent.
 * @param WP_Post $revision Native revision to check.
 */
function can_read_revision( WP_Post $parent, WP_Post $revision ) : bool {
	return can_read( $parent->ID ) && (int) wp_is_post_revision( $revision ) === $parent->ID
		&& ! wp_is_post_autosave( $revision );
}

/**
 * Retrieve native revisions through Core, with bounded ID-cursor pagination.
 *
 * @param int $id Parent post ID.
 * @param array $args Native revision query arguments and an optional before cursor.
 * @return WP_Post[] Native revisions keyed by ID, as returned by Core.
 */
function get_revisions( int $id, array $args = [] ) : array {
	if ( ! can_read( $id ) ) {
		return [];
	}
	global $wpdb;
	$before = (int) ( $args['before'] ?? 0 );
	unset( $args['before'] );
	$where = static function ( $sql, $query ) use ( $wpdb, $before ) {
		if ( ! $query->get( 'hm_history_query' ) ) {
			return $sql;
		}
		// Exclude autosaves before limiting results; Core does not expose an ID-cursor argument.
		$sql .= " AND {$wpdb->posts}.post_name NOT LIKE '%-autosave-%'";
		if ( $before ) {
			$sql .= $wpdb->prepare( " AND {$wpdb->posts}.ID < %d", $before );
		}
		return $sql;
	};
	add_filter( 'posts_where', $where, 10, 2 );
	try {
		return wp_get_post_revisions( $id, array_merge( [
			'posts_per_page' => 20,
			'orderby' => 'ID',
			'order' => 'DESC',
			'suppress_filters' => false,
			'update_post_term_cache' => false,
			'hm_history_query' => true,
		], $args ) );
	} finally {
		remove_filter( 'posts_where', $where, 10 );
	}
}
