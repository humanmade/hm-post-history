<?php

namespace HM\Post_History;

use function HM\Post_History\REST\get_revisions_query;

class Post_History_Widget extends \WP_Widget {

	public function __construct() {
		parent::__construct( 'hm-post-history-widget', 'Post History' );
	}

	/**
	 * Entry point for rendering widget.
	 *
	 * @param $args
	 * @param $instance
	 *
	 * @return void
	 */
	public function widget( $args, $instance ) {
		if ( ! $this->has_revisions( $args, $instance ) ) {
			// If there are no revisions, there's no point to this widget.
			return;
		}

		echo wp_kses_post( $args['before_widget'] ?? '' );

		if ( isset( $instance['title'] ) ) {
			echo wp_kses_post( sprintf(
				'%s%s%s',
				$args['before_title'] ?: '<h3>',
				$instance['title'],
				$args['after_title'] ?: '</h3>'
			) );
		}

		$this->render_diff_list( $args, $instance );
		$this->render_load_button( $args, $instance );

		echo wp_kses_post( $args['after_widget'] ?? '' );
	}

	/**
	 * Determine if this post has any revisions.
	 *
	 * @param array $args
	 * @param array $instance
	 *
	 * @return bool
	 */
	public function has_revisions( array $args, array $instance ) : bool {
		$query = get_revisions_query( [
			'id' => get_the_ID(),
			'per_page' => 1,
			'paged' => 1,
		] );

		return $query->post_count > 0;
	}

	/**
	 * Render the list that will hold revisions.
	 *
	 * @param array $args
	 * @param array $instance
	 *
	 * @return void
	 */
	public function render_diff_list( array $args, array $instance ) : void {
		?>
		<nav class="hm-post-history__diffs">
			<ul class="hm-post-history__list" data-post-history-diff-list></ul>
		</nav>
		<?php
	}

	/**
	 * Render the Load button for loading revisions.
	 *
	 * @param array $args
	 * @param array $instance
	 *
	 * @return void
	 */
	public function render_load_button( array $args, array $instance ) : void {
		?>
		<button class="hm-post-history__load-more" type="button" data-post-history-load-more="1">
			<span class="hm-post-history__load-text">
				<?php esc_html_e( 'Load Revisions', 'hm-post-history' ) ?>
			</span>
		</button>
		<?php
	}

	/**
	 * Render an admin form for modifying the widget.
	 *
	 * @param array $instance Data for this instance of the widget.
	 *
	 * @return void
	 */
	public function form( $instance ) {
		$title = $instance['title'] ?? __( 'Revisions', 'hm-post-history' );
		?>
		<p>
			<label for="<?php echo esc_attr( $this->get_field_id( 'title' ) ) ?>">
				<?php
				esc_html_e( 'Heading', 'hm-term-resources' );
				?>
			</label>
			<input class="widefat" id="<?php echo esc_attr( $this->get_field_id( 'title' ) ) ?>"
					name="<?php echo esc_attr( $this->get_field_name( 'title' ) ) ?>" type="text"
					value="<?php echo esc_attr( $title ); ?>"/>
		</p>
		<?php
	}
}
