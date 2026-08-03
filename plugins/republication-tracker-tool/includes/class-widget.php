<?php
/**
 * Republication Tracker Tool Settings.
 *
 * @since   1.0
 * @package Trust_Indicators
 */

/**
 * Republication Tracker Tool Settings class.
 *
 * @since 1.0
 */
class Republication_Tracker_Tool_Widget extends WP_Widget {

	/**
	 * Whether the widget has been instantiated.
	 *
	 * @var bool
	 */
	public $has_instance = false;

	/**
	 * Sets up the widgets name etc.
	 */
	public function __construct() {
		$widget_ops = array(
			'classname'   => 'republication_tracker_tool',
			'description' => esc_html__( 'Republication Tracker Tool', 'republication-tracker-tool' ),
		);
		parent::__construct( 'republication_tracker_tool', 'Republication Tracker Tool', $widget_ops );
	}

	/**
	 * Outputs the content of the widget.
	 *
	 * If this is not a single post, don't output the widget. It won't work outside single posts.
	 *
	 * @param array $args Sidebar arguments.
	 * @param array $instance This instance of the widget.
	 */
	public function widget( $args, $instance ) {
		if ( ! is_single() ) {
			return;
		}

		global $post;

		$license_key = get_option( 'republication_tracker_tool_license', REPUBLICATION_TRACKER_TOOL_DEFAULT_LICENSE );

		$using_license = isset( REPUBLICATION_TRACKER_TOOL_LICENSES[ $license_key ] );

		// our post `republication-tracker-tool-hide-widget` meta is our default filter value.
		$hide_republication_widget_on_post = apply_filters( 'hide_republication_widget', get_post_meta( $post->ID, 'republication-tracker-tool-hide-widget', true ), $post );

		// if `republication-tracker-tool-hide-widget` meta is set to true, don't show the shareable content widget.
		// OR if the `hide_republication_widget` filter is set to true, don't show the shareable content widget.
		if ( true == $hide_republication_widget_on_post ) {
			return;
		}

		$is_amp = self::is_amp();

		if ( ! $is_amp ) {
			wp_enqueue_script( 'republication-tracker-tool-clipboard-utils', plugins_url( 'assets/clipboard-utils.js', __DIR__ ), array(), filemtime( plugin_dir_path( __FILE__ ) ), false );
			wp_enqueue_script( 'republication-tracker-tool-js', plugins_url( 'assets/widget.js', __DIR__ ), array( 'jquery', 'republication-tracker-tool-clipboard-utils' ), filemtime( plugin_dir_path( __FILE__ ) ), false );
		}
		wp_enqueue_style( 'republication-tracker-tool-css', plugins_url( 'assets/widget.css', __DIR__ ), array(), filemtime( plugin_dir_path( __FILE__ ) ) );

		echo wp_kses_post( $args['before_widget'] );

		if ( ! empty( $instance['title'] ) ) {
			echo wp_kses_post( $args['before_title'] . esc_html( apply_filters( 'widget_title', $instance['title'] ) ) . $args['after_title'] );
		}

		echo '<div class="license">';

		if ( empty( $instance['layout'] ) || 'modal' === $instance['layout'] ) {
			printf(
				'<p><button ' . ( $is_amp ? 'on="tap:republication-tracker-tool-modal"' : '' ) . ' name="%1$s" id="cc-btn" class="republication-tracker-tool-button modal">%1$s</button></p>',
				esc_html__( 'Republish This Story', 'republication-tracker-tool' )
			);
		}

		if ( ! empty( $instance['layout'] ) && 'page' === $instance['layout'] ) {
			printf(
				'<p><button name="%1$s" id="cc-btn" class="republication-tracker-tool-button page" onclick="window.location.href=\'%2$s\'" role="link">%1$s</button></p>',
				esc_html__( 'Republish This Story', 'republication-tracker-tool' ),
				esc_url( '/republish' . ( isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '' ) )
			);
		}

		if ( $using_license ) {
			printf(
				'<p><a class="license" rel="noreferrer license" target="_blank" href="%s"><img alt="%s" style="border-width:0" src="%s" /></a></p>',
				esc_url( REPUBLICATION_TRACKER_TOOL_LICENSES[ $license_key ]['url'] ),
				esc_html__( 'Creative Commons License', 'republication-tracker-tool' ),
				esc_url( plugin_dir_url( __DIR__ ) ) . 'assets/img/' . esc_attr( $license_key ) . '.png'
			);
		}

		echo '</div>';

		printf(
			'<div class="message">%s</div>',
			wp_kses_post( wpautop( esc_html( $instance['text'] ) ) )
		);

		echo wp_kses_post( $args['after_widget'] );

		// if has_instance is false, we can continue with displaying the modal.
		if ( isset( $this->has_instance ) && false === $this->has_instance ) {

			// update has_instance so the next time the widget is created on the same page, it does not create a second modal.
			$this->has_instance = true;

			// define our path to grab file content from.
			$modal_content_path = plugin_dir_path( __FILE__ ) . 'shareable-content.php';

			if ( $is_amp ) {
				?>
					<amp-lightbox id="republication-tracker-tool-modal" layout="nodisplay" role="dialog" aria-modal="true" aria-labelledby="republish-modal-label">
						<?php echo esc_html( include_once $modal_content_path ); ?>
					</amp-lightbox>
				<?php
			} else {
				?>
					<div id="republication-tracker-tool-modal" style="display:none;" data-postid="<?php echo esc_attr( $post->ID ); ?>" data-pluginsdir="<?php echo esc_attr( plugins_url() ); ?>" role="dialog" aria-modal="true" aria-labelledby="republish-modal-label">
						<?php echo esc_html( include_once $modal_content_path ); ?>
					</div>
				<?php
			}
		}
	}

	/**
	 * Outputs the options form on admin.
	 *
	 * @param array $instance The widget options.
	 */
	public function form( $instance ) {
		printf( '<p><em>%s</em></p>', esc_html__( 'This widget will only display on single articles.', 'republication-tracker-tool' ) );
		$title  = ! empty( $instance['title'] ) ? $instance['title'] : '';
		$text   = ! empty( $instance['text'] ) ? $instance['text'] : esc_html__( 'Republish our articles for free, online or in print, under a Creative Commons license.', 'republication-tracker-tool' );
		$layout = ! empty( $instance['layout'] ) ? $instance['layout'] : 'modal'
		?>
		<p>
			<label for="<?php echo esc_attr( $this->get_field_id( 'title' ) ); ?>"><?php esc_html_e( 'Title:', 'republication-tracker-tool' ); ?></label>
			<input class="widefat" id="<?php echo esc_attr( $this->get_field_id( 'title' ) ); ?>" name="<?php echo esc_attr( $this->get_field_name( 'title' ) ); ?>" type="text" value="<?php echo esc_attr( $title ); ?>">
		</p>
		<p>
			<textarea class="widefat" id="<?php echo esc_attr( $this->get_field_id( 'text' ) ); ?>" name="<?php echo esc_attr( $this->get_field_name( 'text' ) ); ?>" type="text" cols="30" rows="10"><?php echo esc_html( $text ); ?></textarea>
		</p>
		<p>
			<label>
				<input class="widefat" id="<?php echo esc_attr( $this->get_field_id( 'layout' ) ); ?>-modal" name="<?php echo esc_attr( $this->get_field_name( 'layout' ) ); ?>" type="radio" value="modal" <?php echo 'modal' === $layout ? 'checked' : ''; ?> />
				<?php esc_html_e( 'Modal', 'republication-tracker-tool' ); ?>
			</label>
			<label>
				<input class="widefat" id="<?php echo esc_attr( $this->get_field_id( 'layout' ) ); ?>-page" name="<?php echo esc_attr( $this->get_field_name( 'layout' ) ); ?>" type="radio" value="page" <?php echo 'page' === $layout ? 'checked' : ''; ?> />
				<?php _e( 'Page', 'republication-tracker-tool' ); ?>
			</label>
		</p>
		<?php
	}

	/**
	 * Processing widget options on save
	 *
	 * @param array $new_instance The new options.
	 * @param array $old_instance The previous options.
	 *
	 * @return array
	 */
	public function update( $new_instance, $old_instance ) {
		$instance = array();

		$instance['title']  = ( ! empty( $new_instance['title'] ) ) ? wp_strip_all_tags( $new_instance['title'] ) : '';
		$instance['text']   = ( ! empty( $new_instance['text'] ) ) ? $new_instance['text'] : '';
		$instance['layout'] = ( ! empty( $new_instance['layout'] ) ) ? $new_instance['layout'] : '';

		return $instance;
	}

	/**
	 * Checks if the current page is an AMP endpoint.
	 *
	 * @return bool
	 */
	public static function is_amp() {
		return function_exists( 'is_amp_endpoint' ) && is_amp_endpoint();
	}
}
