<?php
/** Shared, progressively enhanced history browser. No publishing or restoration actions. */

namespace HM\Post_History\Browser;

use Asset_Loader;
use HM\Post_History\REST;
use WP_Error;
use WP_Post;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Register the shared browser hooks.
 */
function bootstrap() : void {
	add_action( 'rest_api_init', __NAMESPACE__ . '\\routes' );
	add_action( 'wp_enqueue_scripts', __NAMESPACE__ . '\\assets', 30 );
	add_filter( 'the_content', __NAMESPACE__ . '\\content', 30 );
}

/**
 * Whether the theme opts into the shared browser.
 */
function enabled() : bool {
	return current_theme_supports( 'hm-post-history-browser' ) && (bool) apply_filters( 'hm_post_history_should_show_browser', true );
}

/** Whether this request will render the replacement history interface. */
function available() : bool {
	return enabled() && is_singular() && REST\can_read( get_queried_object_id() );
}

/**
 * Enqueue the browser only where its interface is available.
 */
function assets() : void {
	if ( ! available() ) {
		return;
	}
	// Older consumers still ship Asset Loader before the manifest API was named explicitly.
	$enqueue = function_exists( Asset_Loader::class . '\\enqueue_manifest_asset' ) ? Asset_Loader::class . '\\enqueue_manifest_asset' : Asset_Loader::class . '\\enqueue_asset';
	$enqueue( HM_POST_HISTORY_DIR . 'assets/build/production-asset-manifest.json', 'browser.js', [
		'handle' => 'hm-history-browser',
		'dependencies' => [ 'wp-i18n' ],
	] );
	$enqueue( HM_POST_HISTORY_DIR . 'assets/build/production-asset-manifest.json', 'browser.css', [ 'handle' => 'hm-history-browser' ] );
	wp_set_script_translations( 'hm-history-browser', 'hm-post-history' );
}

/**
 * Insert history controls outside the replaceable article markup.
 *
 * @param string $content Rendered current article.
 */
function content( string $content ) : string {
	if ( ! available() || ! is_main_query() || ! in_the_loop() || ! apply_filters( 'hm_post_history_browser_in_content', true ) ) {
		return $content;
	}
	return markup( get_the_ID() ) . $content;
}

/**
 * Render the accessible history controls.
 *
 * @param int $id Parent post ID.
 * @param bool $sidebar Whether to use the host sidebar widget presentation.
 * @param callable|null $container Optional host renderer receiving title, content and container attributes.
 */
function markup( int $id, bool $sidebar = false, ?callable $container = null ) : string {
	ob_start();
	?>
	<p data-history-status class="hm-history__sr-only" role="status" aria-live="polite"></p>
	<button type="button" data-history-retry hidden><?php esc_html_e( 'Try again', 'hm-post-history' ); ?></button>
	<div class="hm-history__workspace" hidden>
		<section class="hm-history__version" aria-label="<?php esc_attr_e( 'History controls', 'hm-post-history' ); ?>" aria-busy="false">
			<ol class="hm-history__timeline hm-history__timeline--fixed" data-history-fixed aria-label="<?php esc_attr_e( 'Comparison base', 'hm-post-history' ); ?>"></ol>
			<ol class="hm-history__timeline" data-history-versions aria-label="<?php esc_attr_e( 'Page versions', 'hm-post-history' ); ?>"><li class="hm-history__sentinel" data-history-more aria-hidden="true" hidden></li></ol>
			<p class="hm-history__notice" data-history-notice hidden></p>
		</section>
	</div>
	<noscript><p><?php esc_html_e( 'Enable JavaScript to browse page history. The current page remains available below.', 'hm-post-history' ); ?></p></noscript>
	<?php
	$content = ob_get_clean();
	$title = __( 'Page history', 'hm-post-history' );
	$attributes = [
		'class' => 'hm-history',
		'data-hm-history' => '',
		'data-endpoint' => rest_url( 'post-history/v1/versions/' . $id ),
		'data-nonce' => wp_create_nonce( 'wp_rest' ),
	];
	if ( $container ) {
		return $container( esc_html( $title ), $content, $attributes );
	}
	ob_start();
	?>
	<details class="hm-history<?php echo $sidebar ? ' hm-history--sidebar hm-widget' : ''; ?>" data-hm-history data-endpoint="<?php echo esc_url( $attributes['data-endpoint'] ); ?>" data-nonce="<?php echo esc_attr( $attributes['data-nonce'] ); ?>">
		<summary<?php echo $sidebar ? ' class="hm-widget__title"' : ''; ?>>
			<span><?php echo esc_html( $title ); ?></span>
			<?php if ( $sidebar ) : ?>
				<svg class="hm-history__chevron" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path fill="currentColor" d="M12 18.999c-.4 0-.776-.156-1.059-.438L.22 7.841A.745.745 0 0 1 0 7.31c0-.2.078-.389.22-.53a.744.744 0 0 1 1.06 0L12 17.499 22.72 6.78a.744.744 0 0 1 1.06 0 .744.744 0 0 1 0 1.06L13.06 18.56a1.487 1.487 0 0 1-1.06.439z"/></svg>
			<?php endif; ?>
		</summary>
		<div class="hm-history__body<?php echo $sidebar ? ' hm-widget__content' : ''; ?>">
			<?php echo $content; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped above. ?>
		</div>
	</details>
	<?php
	return ob_get_clean();
}

/**
 * Register the read-only version endpoints.
 */
function routes() : void {
	$id = [
		'type' => 'integer',
		'minimum' => 1,
	];
	$common = [
		'methods' => 'GET',
		'permission_callback' => REST::class . '\\revisions_request_permissions_callback',
	];
	register_rest_route( 'post-history/v1', '/versions/(?P<id>\d+)', array_merge( $common, [
		'callback' => __NAMESPACE__ . '\\listing',
		'args' => [
			'id' => $id,
			'before' => [
				'type' => 'integer',
				'minimum' => 0,
				'default' => 0,
			],
		],
	] ) );
	register_rest_route( 'post-history/v1', '/versions/(?P<id>\d+)/(?P<version>\d+)', array_merge( $common, [
		'callback' => __NAMESPACE__ . '\\detail',
		'args' => [
			'id' => $id,
			'version' => [
				'type' => 'integer',
				'minimum' => 0,
			],
			'base' => [
				'type' => 'integer',
				'minimum' => 0,
				'default' => 0,
			],
		],
	] ) );
}

/**
 * Create a private, non-cacheable history response.
 *
 * @param array $data Authorized response data.
 */
function response( array $data ) : WP_REST_Response {
	$response = rest_ensure_response( $data );
	$response->header( 'Cache-Control', 'private, no-store, max-age=0' );
	$response->header( 'Vary', 'Cookie' );
	return $response;
}

/**
 * Describe a version without loading its content into the timeline.
 *
 * @param WP_Post $post Parent or native revision.
 * @param bool $current Whether this is the live parent.
 */
function metadata( WP_Post $post, bool $current = false ) : array {
	$modified = get_post_datetime( $post, 'modified', 'gmt' );
	return [
		'id' => $current ? 0 : $post->ID,
		'date' => $modified ? wp_date( 'j M Y, H:i', $modified->getTimestamp() ) : '',
		'author' => get_the_author_meta( 'display_name', $post->post_author ) ?: __( 'Unknown author', 'hm-post-history' ),
		'avatar' => esc_url_raw( get_avatar_url( (int) $post->post_author, [
			'size' => 64,
			'default' => '404',
		] ) ?: '' ),
		'current' => $current,
	];
}

/**
 * Return one bounded page of authorized version metadata.
 *
 * @param WP_REST_Request $request Validated list request.
 */
function listing( WP_REST_Request $request ) : WP_REST_Response {
	$parent = get_post( $request['id'] );
	$revisions = REST\get_revisions( $parent->ID, [
		'before' => $request['before'],
		'posts_per_page' => 21,
	] );
	$has_more = count( $revisions ) > 20;
	$posts = array_slice( $revisions, 0, 20 );
	$items = [];
	foreach ( $posts as $post ) {
		$item = metadata( $post );
		$item['matches_current'] = matches_current( $post, $parent );
		$items[] = $item;
	}
	return response( [
		'current' => metadata( $parent, true ),
		'versions' => $items,
		'next' => $has_more ? end( $posts )->ID : null,
	] );
}

/**
 * Render saved static block markup without evaluating shortcodes, dynamic blocks or content filters.
 *
 * @param string $content Saved post content.
 */
function saved_html( string $content ) : string {
	$hidden_blocks = (array) apply_filters( 'hm_post_history_hidden_blocks', [] );
	$render = static function ( array $blocks ) use ( &$render, $hidden_blocks ) : string {
		$html = '';
		foreach ( $blocks as $block ) {
			// Match blocks the host deliberately suppresses in its normal reading view.
			if ( in_array( $block['blockName'], $hidden_blocks, true ) ) {
				continue;
			}
			if ( $block['blockName'] === 'core/block' ) {
				// A reference does not contain the shared block's historical contents.
				$html .= '<p>' . esc_html__( 'Shared block content is unavailable for this revision.', 'hm-post-history' ) . '</p>';
				continue;
			}
			// Hidden/editorial blocks are never rendered from saved HTML, even for editors.
			if ( ! empty( $block['attrs']['blockVisibility'] ) || preg_match( '~(?:^|/)(?:note|notes|editorial-note|suggestion)s?$~', (string) $block['blockName'] ) ) {
				$html .= '<p>' . esc_html__( '[Restricted block omitted from history]', 'hm-post-history' ) . '</p>';
				continue;
			}
			// Only saved core presentation markup is trusted. Custom/editorial blocks must not leak fallback content.
			if ( $block['blockName'] !== null && ! in_array( $block['blockName'], [
				'core/paragraph',
				'core/heading',
				'core/list',
				'core/list-item',
				'core/table',
				'core/group',
				'core/columns',
				'core/column',
				'core/image',
				'core/gallery',
				'core/quote',
				'core/pullquote',
				'core/preformatted',
				'core/code',
				'core/verse',
				'core/separator',
				'core/spacer',
				'core/details',
				'core/cover',
				'core/media-text',
				'core/buttons',
				'core/button',
				'core/freeform',
				'core/html',
			], true ) ) {
				$html .= '<p>' . esc_html__( '[Interactive block omitted from history]', 'hm-post-history' ) . '</p>';
				continue;
			}
			$child = 0;
			foreach ( $block['innerContent'] as $part ) {
				$html .= $part === null ? $render( [ $block['innerBlocks'][ $child++ ] ] ) : $part;
			}
		}
		return $html;
	};
	$content = omit_shortcodes( $content );
	$html = $render( parse_blocks( $content ) );
	// Comments can contain editorial notes even though they are invisible in the page.
	$html = preg_replace( '/<!--.*?-->/s', '', $html );
	$html = wp_kses_post( has_blocks( $content ) ? $html : wpautop( $html ) );
	return wp_kses_post( apply_filters( 'hm_post_history_saved_html', $html ) );
}

/**
 * Remove entire enclosed shortcode regions, including nested tags, without calling any shortcode.
 *
 * @param string $content Saved post content.
 */
function omit_shortcodes( string $content ) : string {
	preg_match_all( '~\[(/?)([a-zA-Z][\w-]*)(?:\s[^\]]*)?(/?)\]~', $content, $tokens, PREG_SET_ORDER | PREG_OFFSET_CAPTURE );
	$paired = [];
	foreach ( $tokens as $token ) {
		if ( $token[1][0] === '/' ) {
			$paired[ strtolower( $token[2][0] ) ] = true;
		}
	}
	$stack = [];
	$output = '';
	$cursor = 0;
	foreach ( $tokens as $token ) {
		if ( ! $stack ) {
			$output .= substr( $content, $cursor, $token[0][1] - $cursor );
		}
		$name = strtolower( $token[2][0] );
		if ( $token[1][0] === '/' ) {
			if ( end( $stack ) === $name ) {
				array_pop( $stack );
			}
		} else {
			if ( ! $stack ) {
				$output .= esc_html__( '[Shortcode omitted from history]', 'hm-post-history' );
			}
			if ( isset( $paired[ $name ] ) && $token[3][0] !== '/' ) {
				$stack[] = $name;
			}
		}
		$cursor = $token[0][1] + strlen( $token[0][0] );
	}
	return $output . ( $stack ? '' : substr( $content, $cursor ) );
}

/**
 * Avoid duplicating the current article in the timeline; no stored fingerprint is needed.
 *
 * @param WP_Post $revision Saved revision.
 * @param WP_Post $parent Current post.
 */
function matches_current( WP_Post $revision, WP_Post $parent ) : bool {
	return $revision->post_title === $parent->post_title
		&& $revision->post_content === $parent->post_content
		&& $revision->post_excerpt === $parent->post_excerpt;
}

/**
 * Order a pair consistently, including saves made within the same second.
 *
 * @param WP_Post $selected Selected snapshot.
 * @param WP_Post $base Comparison base.
 */
function chronological_versions( WP_Post $selected, WP_Post $base ) : array {
	$order = strcmp( $selected->post_modified_gmt, $base->post_modified_gmt );
	if ( $order === 0 ) {
		// The live parent follows its latest snapshot, despite having a lower ID.
		$order = (int) ( $selected->post_type !== 'revision' ) <=> (int) ( $base->post_type !== 'revision' );
		$order = $order ?: ( $selected->ID <=> $base->ID );
	}
	return $order <= 0 ? [ $selected, $base ] : [ $base, $selected ];
}

/**
 * Return an authorized snapshot and chronological comparison.
 *
 * @param WP_REST_Request $request Validated version and comparison request.
 */
function detail( WP_REST_Request $request ) {
	$parent = get_post( $request['id'] );
	$version = (int) $request['version'];
	$post = $version ? wp_get_post_revision( $version ) : $parent;
	if ( ! $post || ( $version && ! REST\can_read_revision( $parent, $post ) ) ) {
		return new WP_Error( 'history_unavailable', __( 'This version is unavailable.', 'hm-post-history' ), [ 'status' => 404 ] );
	}
	$base = (int) $request['base'];
	$other = $base ? wp_get_post_revision( $base ) : $parent;
	if ( ! $other || ( $base && ! REST\can_read_revision( $parent, $other ) ) ) {
		return new WP_Error( 'history_unavailable', __( 'This comparison version is unavailable.', 'hm-post-history' ), [ 'status' => 404 ] );
	}
	// Render each saved version once; the existing HTML diff library compares them in the browser.
	$html = saved_html( $post->post_content );
	$other_html = $other->ID === $post->ID ? $html : saved_html( $other->post_content );
	[ $older, $newer ] = chronological_versions( $post, $other );
	$before_html = $older === $post ? $html : $other_html;
	$after_html = $older === $post ? $other_html : $html;
	$inline = [
		'before' => [
			'title' => $older->post_title,
			'html' => $before_html,
		],
		'after' => [
			'title' => $newer->post_title,
			'html' => $after_html,
		],
	];
	// Limit client-side HTML diff work on unusually large documents; retain the saved preview.
	if ( strlen( $before_html ) + strlen( $after_html ) > 200000 || substr_count( $before_html . $after_html, "\n" ) > 2000 ) {
		$inline = null;
	}
	$data = [
		'version' => metadata( $post, ! $version ),
		'title' => $post->post_title,
		'inline' => $inline,
		'comparison' => metadata( $other, ! $base ),
	];
	if ( ! $inline ) {
		$data['html'] = $html;
	}
	return response( $data );
}
