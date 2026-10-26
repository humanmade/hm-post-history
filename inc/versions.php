<?php
/** Published-version evidence and access policy shared by every history surface. */

namespace HM\Post_History\Versions;

const RELEASE_META = '_hm_post_history_release';

/**
 * Capture publication evidence after native revision metadata is saved.
 */
function bootstrap() : void {
	// Core has saved revisioned metadata by this point. Never mark autosaves or infer old releases.
	add_action( 'wp_after_insert_post', __NAMESPACE__ . '\\capture_release', 100, 2 );
}

/**
 * A host can further restrict this decision, but cannot bypass authentication or post access.
 *
 * @param int $id Parent post ID.
 */
function can_read( int $id ) : bool {
	$post = get_post( $id );
	$type = $post ? get_post_type_object( $post->post_type ) : null;
	return $post && $type && $type->public && is_user_logged_in()
		&& ( ! is_multisite() || is_super_admin() || is_user_member_of_blog() )
		&& \HM\Post_History\should_enable()
		&& post_type_supports( $post->post_type, 'hm-post-history' )
		&& current_user_can( 'read_post', $id ) && ! post_password_required( $post )
		&& (bool) apply_filters( 'hm_post_history_can_read_post', true, $post, get_current_user_id() );
}

/**
 * Broad SSO roles are not proof of a need to see unreleased history. Assign this capability deliberately.
 *
 * @param int $id Parent post ID.
 */
function can_read_unreleased( int $id ) : bool {
	return current_user_can( 'edit_post', $id ) && current_user_can( 'read_unpublished_post_history' );
}

/**
 * Only explicitly opted-in, natively revisioned metadata is rendered or compared.
 *
 * @param \WP_Post $post Parent post defining the field policy.
 */
function meta_fields( \WP_Post $post ) : array {
	$fields = apply_filters( 'hm_post_history_meta_fields', [], $post->post_type );
	$keys = function_exists( 'wp_post_revision_meta_keys' ) ? wp_post_revision_meta_keys( $post->post_type ) : [];
	return array_intersect_key( $fields, array_flip( $keys ) );
}

/**
 * Collect only fields that can be reconstructed from a native revision.
 *
 * @param \WP_Post $post Parent or native revision.
 */
function snapshot( \WP_Post $post ) : array {
	$parent = $post->post_type === 'revision' ? get_post( $post->post_parent ) : $post;
	$data = [
		'title' => $post->post_title,
		'content' => $post->post_content,
		'excerpt' => $post->post_excerpt,
	];
	foreach ( meta_fields( $parent ) as $key => $label ) {
		$data['meta'][ $key ] = get_post_meta( $post->ID, $key, false );
	}
	return $data;
}

/**
 * Fingerprint the exact saved content and opted-in revisioned fields.
 *
 * @param \WP_Post $post Parent or native revision.
 */
function fingerprint( \WP_Post $post ) : string {
	return hash( 'sha256', wp_json_encode( snapshot( $post ) ) );
}

/**
 * Let the host exclude snapshots that cannot represent a published version.
 *
 * @param \WP_Post $revision Native revision being considered.
 */
function is_release_candidate( \WP_Post $revision ) : bool {
	// A host may reject a snapshot, but cannot bypass fingerprint or access checks.
	return ! wp_is_post_autosave( $revision )
		&& (bool) apply_filters( 'hm_post_history_is_release_candidate', true, $revision );
}

/**
 * Bind publication evidence to the exact matching native revision.
 *
 * @param int $id Saved parent post ID.
 * @param \WP_Post $post Saved parent post.
 */
function capture_release( int $id, \WP_Post $post ) : void {
	if ( ( defined( 'WP_IMPORTING' ) && WP_IMPORTING ) || ! apply_filters( 'hm_post_history_capture_release', true, $post ) || $post->post_status !== 'publish' || ! post_type_supports( $post->post_type, 'hm-post-history' ) || ! wp_revisions_enabled( $post ) ) {
		return;
	}
	wp_save_post_revision( $id );
	$revisions = wp_get_post_revisions( $id, [
		'posts_per_page' => 1,
		'check_enabled' => false,
	] );
	$revision = reset( $revisions );
	if ( ! $revision || ! is_release_candidate( $revision ) || fingerprint( $revision ) !== fingerprint( $post ) ) {
		return;
	}
	// add_post_meta redirects revisions to their parent; use the metadata API to bind evidence to this exact native revision.
	add_metadata( 'post', $revision->ID, RELEASE_META, [
		'fingerprint' => fingerprint( $revision ),
		'released_at' => gmdate( 'c' ),
	], true );
}

/**
 * Check immutable publication evidence against the current snapshot.
 *
 * @param \WP_Post $revision Native revision to check.
 */
function is_released( \WP_Post $revision ) : bool {
	if ( ! is_release_candidate( $revision ) ) {
		return false;
	}
	$evidence = get_post_meta( $revision->ID, RELEASE_META, true );
	return is_array( $evidence ) && isset( $evidence['fingerprint'] )
		&& hash_equals( $evidence['fingerprint'], fingerprint( $revision ) );
}

/**
 * Require both parent access and authorization for this exact revision.
 *
 * @param \WP_Post $parent Revision parent.
 * @param \WP_Post $revision Native revision to check.
 */
function can_read_version( \WP_Post $parent, \WP_Post $revision ) : bool {
	if ( ! can_read( $parent->ID ) || (int) $revision->post_parent !== $parent->ID || $revision->post_type !== 'revision' || wp_is_post_autosave( $revision ) ) {
		return false;
	}
	return can_read_unreleased( $parent->ID ) || ( $parent->post_status === 'publish' && is_released( $revision ) );
}

/**
 * Bounded database query. Unreleased revisions are filtered before pagination, never in the browser.
 *
 * @param int $id Parent post ID.
 * @param array $args Bounded query and cursor overrides.
 */
function query( int $id, array $args = [] ) : \WP_Query {
	global $wpdb;
	$parent = get_post( $id );
	if ( ! $parent || ! can_read( $id ) ) {
		return new \WP_Query( [
			'post__in' => [ 0 ],
			'post_type' => 'revision',
		] );
	}
	$query = array_merge( [
		'post_parent' => $id,
		'post_type' => 'revision',
		'post_status' => 'inherit',
		'posts_per_page' => 20,
		'orderby' => 'ID',
		'order' => 'DESC',
		'no_found_rows' => true,
		'update_post_term_cache' => false,
		'hm_history_query' => true,
	], $args );
	if ( ! can_read_unreleased( $id ) ) {
		if ( $parent->post_status !== 'publish' ) {
			$query['post__in'] = [ 0 ];
		}
		// phpcs:ignore HM.Performance.SlowMetaQuery.dynamic_query -- Indexed release markers restrict a bounded query before pagination.
		$query['meta_query'] = [
			[
				'key' => RELEASE_META,
				'compare' => 'EXISTS',
			],
		];
	}
	$where = static function ( $sql, $q ) use ( $wpdb, $args ) {
		if ( ! $q->get( 'hm_history_query' ) ) {
			return $sql;
		}
		$sql .= " AND {$wpdb->posts}.post_name NOT LIKE '%-autosave-%'";
		if ( ! empty( $args['before'] ) ) {
			$sql .= $wpdb->prepare( " AND {$wpdb->posts}.ID < %d", $args['before'] );
		}
		if ( ! empty( $args['after'] ) ) {
			$sql .= $wpdb->prepare( " AND {$wpdb->posts}.ID > %d", $args['after'] );
		}
		return $sql;
	};
	add_filter( 'posts_where', $where, 10, 2 );
	$result = new \WP_Query( $query );
	remove_filter( 'posts_where', $where, 10 );
	return $result;
}
