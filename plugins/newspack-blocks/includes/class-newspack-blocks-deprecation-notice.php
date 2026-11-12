<?php
/**
 * Newspack Blocks deprecation notice.
 *
 * Tells sites that aren't connected to Newspack Manager where the plugin's blocks are moving.
 *
 * @package Newspack_Blocks
 */

defined( 'ABSPATH' ) || exit;

/**
 * Admin notice pointing open-source sites to the replacement plugins.
 */
class Newspack_Blocks_Deprecation_Notice {
	const STATE_MOVE  = 'move';
	const STATE_READY = 'ready';

	const CONTENT_BLOCKS_PLUGIN       = 'newspack-content-blocks/newspack-content-blocks.php';
	const CONTENT_BLOCKS_DOWNLOAD_URL = 'https://newspack.com/downloads/newspack-content-blocks/latest';
	const NEWSPACK_PLUGIN_DOWNLOAD_URL = 'https://newspack.com/downloads/newspack-plugin/latest';

	const DISMISSED_META_KEY = 'newspack_blocks_deprecation_dismissed';
	const DISMISS_ACTION     = 'newspack_blocks_dismiss_deprecation_notice';

	/**
	 * Hook into WordPress.
	 */
	public static function init() {
		add_action( 'admin_notices', [ __CLASS__, 'render' ] );
		add_action( 'wp_ajax_' . self::DISMISS_ACTION, [ __CLASS__, 'handle_dismiss' ] );
	}

	/**
	 * Whether the site is connected to Newspack Manager.
	 *
	 * @return bool
	 */
	private static function is_manager_connected() {
		return method_exists( 'Newspack_Manager', 'is_connected_to_manager' ) && \Newspack_Manager::is_connected_to_manager();
	}

	/**
	 * Whether Newspack Manager is installed, which marks a Newspack platform site.
	 *
	 * @return bool
	 */
	private static function is_manager_installed() {
		/**
		 * Filters whether Newspack Manager is installed. A test seam.
		 *
		 * @param bool $is_installed Whether the Newspack_Manager class exists.
		 */
		return (bool) apply_filters( 'newspack_blocks_deprecation_notice_manager_installed', class_exists( 'Newspack_Manager' ) );
	}

	/**
	 * Whether the Newspack plugin, with the copy of these blocks it bundles, is installed.
	 *
	 * @return bool
	 */
	private static function has_bundled_copy() {
		/**
		 * Filters whether the Newspack plugin's bundled copy of these blocks is present.
		 *
		 * @param bool $has_bundled_copy Whether the Newspack\Bundled_Blocks class exists.
		 */
		return (bool) apply_filters( 'newspack_blocks_deprecation_notice_has_bundled_copy', class_exists( 'Newspack\Bundled_Blocks' ) );
	}

	/**
	 * Which notice to show, if any.
	 *
	 * @return string|null Null for no notice, otherwise 'move' or 'ready'.
	 */
	public static function get_state() {
		// Manager installed means a Newspack platform site, connected recently or not.
		if ( ! current_user_can( 'install_plugins' ) || self::is_manager_installed() || self::is_manager_connected() ) {
			return null;
		}
		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php'; // phpcs:ignore WordPressVIPMinimum.Files.IncludingFile.UsingCustomConstant
		}
		if ( is_plugin_active( self::CONTENT_BLOCKS_PLUGIN ) && self::has_bundled_copy() ) {
			return self::STATE_READY;
		}
		return self::STATE_MOVE;
	}

	/**
	 * States the current user has dismissed.
	 *
	 * @param int $user_id User ID.
	 *
	 * @return string[]
	 */
	private static function get_dismissed( $user_id ) {
		$dismissed = get_user_meta( $user_id, self::DISMISSED_META_KEY, true );
		return is_array( $dismissed ) ? $dismissed : [];
	}

	/**
	 * Whether the notice shows on the current screen.
	 *
	 * @return bool
	 */
	private static function is_notice_screen() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		return $screen && in_array( $screen->id, [ 'dashboard', 'plugins' ], true );
	}

	/**
	 * The notice message for a state.
	 *
	 * @param string $state Notice state.
	 *
	 * @return string Escaped HTML.
	 */
	private static function get_message( $state ) {
		if ( self::STATE_READY === $state ) {
			return esc_html__( 'Newspack Content Blocks and the Newspack plugin are both active now, so you can deactivate Newspack Blocks.', 'newspack-blocks' );
		}
		return sprintf(
			/* translators: 1: link to download Newspack Content Blocks. 2: link to download the Newspack plugin. */
			esc_html__( 'Newspack Blocks is being retired. Content Loop and Carousel are moving to %1$s, and Author List, Author Profile, Iframe, Video Playlist, Donate and Checkout Button are moving into %2$s. Install and activate both, then deactivate Newspack Blocks.', 'newspack-blocks' ),
			'<a href="' . esc_url( self::CONTENT_BLOCKS_DOWNLOAD_URL ) . '">' . esc_html__( 'Newspack Content Blocks', 'newspack-blocks' ) . '</a>',
			'<a href="' . esc_url( self::NEWSPACK_PLUGIN_DOWNLOAD_URL ) . '">' . esc_html__( 'the Newspack plugin', 'newspack-blocks' ) . '</a>'
		);
	}

	/**
	 * Render the notice.
	 */
	public static function render() {
		if ( ! self::is_notice_screen() ) {
			return;
		}
		$state = self::get_state();
		if ( null === $state || in_array( $state, self::get_dismissed( get_current_user_id() ), true ) ) {
			return;
		}
		$nonce = wp_create_nonce( self::DISMISS_ACTION );
		?>
		<div class="notice notice-warning is-dismissible newspack-blocks-deprecation-notice" data-state="<?php echo esc_attr( $state ); ?>">
			<p><?php echo wp_kses( self::get_message( $state ), [ 'a' => [ 'href' => [] ] ] ); ?></p>
		</div>
		<script>
			document.addEventListener( 'click', function ( event ) {
				var notice = event.target.closest( '.newspack-blocks-deprecation-notice' );
				if ( ! notice || ! event.target.classList.contains( 'notice-dismiss' ) ) {
					return;
				}
				var body = new URLSearchParams( {
					action: <?php echo wp_json_encode( self::DISMISS_ACTION ); ?>,
					nonce: <?php echo wp_json_encode( $nonce ); ?>,
					state: notice.dataset.state
				} );
				fetch( <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>, { method: 'POST', credentials: 'same-origin', body: body } );
			} );
		</script>
		<?php
	}

	/**
	 * Store a dismissal for the current user and state.
	 */
	public static function handle_dismiss() {
		check_ajax_referer( self::DISMISS_ACTION, 'nonce' );
		if ( ! current_user_can( 'install_plugins' ) ) {
			wp_send_json_error( null, 403 );
		}
		$state = isset( $_POST['state'] ) ? sanitize_key( wp_unslash( $_POST['state'] ) ) : '';
		if ( ! in_array( $state, [ self::STATE_MOVE, self::STATE_READY ], true ) ) {
			wp_send_json_error( null, 400 );
		}
		$user_id   = get_current_user_id();
		$dismissed = array_values( array_unique( array_merge( self::get_dismissed( $user_id ), [ $state ] ) ) );
		update_user_meta( $user_id, self::DISMISSED_META_KEY, $dismissed );
		wp_send_json_success();
	}
}
