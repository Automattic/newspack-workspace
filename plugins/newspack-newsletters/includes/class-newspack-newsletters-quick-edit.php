<?php
/**
 * Newspack Newsletters Quick Edit
 *
 * @package Newspack
 */

defined( 'ABSPATH' ) || exit;

/**
 * Newspack Newsletters Quick Edit Class.
 */
class Newspack_Newsletters_Quick_Edit {
	/**
	 * Set up hooks.
	 */
	public static function init() {
		add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue_scripts' ] );
		add_action( 'quick_edit_custom_box', [ __CLASS__, 'quick_edit_box' ], 10, 2 );
		add_action( 'save_post_newspack_nl_cpt', [ __CLASS__, 'save' ] );
	}
  
	/**
	 * Enqueue Quick Edit scripts used to update inputs.
	 */
	public static function enqueue_scripts() {
		$screen = get_current_screen();
		if ( Newspack_Newsletters::NEWSPACK_NEWSLETTERS_CPT !== $screen->post_type ) {
			return;
		}
		wp_enqueue_script(
			'newspack-newsletters-quickEdit',
			plugins_url( '../dist/quickEdit.js', __FILE__ ),
			[ 'jquery', 'wp-api-fetch' ],
			filemtime( NEWSPACK_NEWSLETTERS_PLUGIN_FILE . 'dist/quickEdit.js' ),
			true
		);
	}

	/**
	 * Display "Make newsletter page public" checkbox field
	 * 
	 * @param string $column_name Coulumn name.
	 * @param string $post_type   Post Type.
	 */
	public static function quick_edit_box( $column_name, $post_type ) {
		if (
			'public_page' !== $column_name ||
			Newspack_Newsletters::NEWSPACK_NEWSLETTERS_CPT !== $post_type
		) {
			return;
		}
		?>
		<fieldset class="inline-edit-col-right">
			<div class="inline-edit-col">
				<div class="inline-edit-group wp-clearfix">
					<label class="alignleft">
						<input type="checkbox" name="switch_public_page">
						<span class="checkbox-title"><?php _e( 'Make newsletter page public?', 'newspack-newsletters' ); ?></span>
					</label>
				</div>
			</div>
			<?php wp_nonce_field( 'newspack_nl_quick_edit', 'newspack_nl_quick_edit_nonce' ); ?>
		</fieldset>
		<?php
	}

	/**
	 * Save Quick Edit action values.
	 * 
	 * @param int $post_id Post ID.
	 */
	public static function save( $post_id ) {
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		if (
			! isset( $_POST['newspack_nl_quick_edit_nonce'] ) ||
			! wp_verify_nonce( sanitize_text_field( $_POST['newspack_nl_quick_edit_nonce'] ), 'newspack_nl_quick_edit' )
		) {
			return;
		}
		update_post_meta(
			$post_id,
			'is_public',
			isset( $_POST['switch_public_page'] ) && sanitize_text_field( $_POST['switch_public_page'] )
		);
	}
}

if ( is_admin() ) {
	Newspack_Newsletters_Quick_Edit::init();
}
