<?php
/** Shared, progressively enhanced history browser. No publishing or restoration actions. */

namespace HM\Post_History\Browser;

use Asset_Loader;
use HM\Post_History\REST;
use WP_Post;

/**
 * Register the shared browser hooks.
 */
function bootstrap() : void {
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
	Asset_Loader\enqueue_manifest_asset( HM_POST_HISTORY_DIR . 'assets/build/production-asset-manifest.json', 'browser.js', [
		'handle' => 'hm-history-browser',
		'dependencies' => [ 'wp-api-fetch', 'wp-i18n' ],
	] );
	Asset_Loader\enqueue_manifest_asset( HM_POST_HISTORY_DIR . 'assets/build/production-asset-manifest.json', 'browser.css', [ 'handle' => 'hm-history-browser' ] );
	wp_set_script_translations( 'hm-history-browser', 'hm-post-history' );
}

/**
 * Insert history controls outside the replaceable article markup.
 *
 * @param string $content Rendered current article.
 */
function content( string $content ) : string {
	if ( ! available() || ! is_main_query() || ! in_the_loop() ) {
		return $content;
	}
	$content = '<div class="hm-history-current">' . $content . '</div>';
	return apply_filters( 'hm_post_history_browser_in_content', true ) ? markup( get_the_ID() ) . $content : $content;
}

/**
 * Render the accessible history controls.
 *
 * @param int $id Parent post ID.
 * @param callable|null $container Optional host renderer receiving title, content and container attributes.
 */
function markup( int $id, ?callable $container = null ) : string {
	ob_start();
	?>
	<p data-history-status class="hm-history__sr-only" role="status" aria-live="polite"></p>
	<button type="button" data-history-retry hidden><?php esc_html_e( 'Try again', 'hm-post-history' ); ?></button>
	<div class="hm-history__workspace" hidden>
		<section class="hm-history__version" aria-label="<?php esc_attr_e( 'History controls', 'hm-post-history' ); ?>" aria-busy="false">
			<ol class="hm-history__timeline hm-history__timeline--fixed" data-history-fixed aria-label="<?php esc_attr_e( 'Comparison base', 'hm-post-history' ); ?>"></ol>
			<ol class="hm-history__timeline" data-history-versions aria-label="<?php esc_attr_e( 'Page versions', 'hm-post-history' ); ?>"><li class="hm-history__sentinel" data-history-more aria-hidden="true" hidden></li></ol>
			<button type="button" data-history-older hidden><?php esc_html_e( 'Load older versions', 'hm-post-history' ); ?></button>
			<p class="hm-history__notice" data-history-notice hidden></p>
		</section>
	</div>
	<noscript><p><?php esc_html_e( 'Enable JavaScript to browse page history.', 'hm-post-history' ); ?></p></noscript>
	<?php
	$content = ob_get_clean();
	$title = __( 'Page history', 'hm-post-history' );
	$attributes = [
		'class' => 'hm-history',
		'data-hm-history' => '',
		'data-endpoint' => rest_get_route_for_post( $id ),
	];
	if ( $container ) {
		return $container( esc_html( $title ), $content, $attributes );
	}
	ob_start();
	?>
	<details class="hm-history" data-hm-history data-endpoint="<?php echo esc_url( $attributes['data-endpoint'] ); ?>">
		<summary>
			<span><?php echo esc_html( $title ); ?></span>
		</summary>
		<div class="hm-history__body">
			<?php echo $content; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped above. ?>
		</div>
	</details>
	<?php
	return ob_get_clean();
}

/**
 * Describe a version without loading its content into the timeline.
 *
 * @param WP_Post $post Parent or native revision.
 */
function metadata( WP_Post $post ) : array {
	$modified = get_post_datetime( $post, 'modified', 'gmt' );
	return [
		'date' => $modified ? wp_date( 'j M Y, H:i', $modified->getTimestamp() ) : '',
		'author' => get_the_author_meta( 'display_name', $post->post_author ) ?: __( 'Unknown author', 'hm-post-history' ),
		'avatar' => esc_url_raw( get_avatar_url( (int) $post->post_author, [
			'size' => 64,
			'default' => '404',
		] ) ?: '' ),
	];
}

/**
 * Apply the host's historical presentation to normally rendered WordPress content.
 *
 * @param string $html Rendered article markup.
 */
function prepare_html( string $html ) : string {
	// Invisible comments may contain editorial notes; never include them in a comparison.
	$html = preg_replace( '/<!--.*?-->/s', '', $html );
	return wp_kses_post( $html );
}
