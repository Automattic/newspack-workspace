<?php
/**
 * Nicename change UI class.
 *
 * @package Newspack
 */

namespace Newspack;

defined( 'ABSPATH' ) || exit;


/**
 * This class adds a UI to the user profile page to allow users to change their nicename.
 */
class Nicename_Change_UI {

	/**
	 * Registers the hooks.
	 */
	public static function init() {

		/**
		 * Enables a UI for changing user nicenames (URL slugs) in the admin.
		 * Useful for Co-Authors Plus guest author management.
		 *
		 * @constant NEWSPACK_CHANGE_NICENAME_UI
		 * @type     bool
		 * @default  Nicename change UI disabled
		 * @status   draft
		 *
		 * @example define( 'NEWSPACK_CHANGE_NICENAME_UI', true );
		 */
		if ( ! defined( 'NEWSPACK_CHANGE_NICENAME_UI' ) || ! NEWSPACK_CHANGE_NICENAME_UI ) {
			return;
		}

		add_action( 'edit_user_profile', [ __CLASS__, 'edit_user_profile' ] );

		add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue_scripts' ] );

		add_action( 'wp_ajax_newspack_change_nicename', [ __CLASS__, 'change_nicename_ajax' ] );
		add_action( 'wp_ajax_newspack_change_nicename_check', [ __CLASS__, 'change_nicename_check_ajax' ] );
	}

	/**
	 * Enqueue scripts.
	 */
	public static function enqueue_scripts() {
		if ( ! is_admin() || \get_current_screen()->id !== 'user-edit' ) {
			return;
		}

		\wp_enqueue_script(
			'newspack-nicename-change',
			Newspack::plugin_url() . '/dist/nicename-change.js',
			[],
			Newspack::asset_version( 'nicename-change' ),
			true
		);

		wp_localize_script(
			'newspack-nicename-change',
			'newspack_change_nicename_params',
			[
				'ajax_url'      => admin_url( 'admin-ajax.php' ),
				'empty_message' => esc_html__( 'Please provide a new slug.', 'newspack-plugin' ),
			]
		);

		\wp_enqueue_style(
			'newspack-nicename-change',
			Newspack::plugin_url() . '/dist/nicename-change.css',
			[],
			Newspack::asset_version( 'nicename-change' )
		);
	}

	/**
	 * AJAX handler for checking the nicename availability.
	 */
	public static function change_nicename_check_ajax() {

		check_ajax_referer( 'newspack_change_nicename_nonce', 'nonce' );

		$new_nicename = isset( $_POST['new_nicename'] ) ? sanitize_title( wp_unslash( $_POST['new_nicename'] ) ) : '';

		$existing = Nicename_Change::get_existing_nicenames( $new_nicename );

		$response = [
			'success' => empty( $existing ),
			// translators: %s is the new nicename.
			'message' => sprintf( esc_html__( 'Slug %s is available.', 'newspack-plugin' ), $new_nicename ),
		];

		if ( ! empty( $existing ) ) {
			$response['message'] = esc_html__( 'Slug is not available.', 'newspack-plugin' );

			foreach ( $existing as $existing_nicename ) {
				$response['message'] .= '<br/>' . sprintf( ' - %s: %s (ID: %d, %d posts)', $existing_nicename['type'], $existing_nicename['value'], $existing_nicename['id'], $existing_nicename['num_posts'] );
			}
		}

		wp_send_json( $response );

		exit;
	}

	/**
	 * AJAX handler for changing the user nicename.
	 */
	public static function change_nicename_ajax() {

		check_ajax_referer( 'newspack_change_nicename_nonce', 'nonce' );

		$new_nicename = isset( $_POST['new_nicename'] ) ? sanitize_title( wp_unslash( $_POST['new_nicename'] ) ) : '';

		$existing = Nicename_Change::get_existing_nicenames( $new_nicename );

		if ( ! empty( $existing ) ) {
			return self::change_nicename_check_ajax();
		}

		$user_id = isset( $_POST['user_id'] ) ? (int) $_POST['user_id'] : 0;

		if ( ! $user_id ) {
			wp_send_json(
				[
					'success' => false,
					'message' => esc_html__( 'User not found.', 'newspack-plugin' ),
				]
			);
		}

		// Update the nicename.
		wp_update_user(
			[
				'ID'            => $user_id,
				'user_nicename' => $new_nicename,
			]
		);

		$response = [
			'success' => empty( $existing ),
			// translators: %s is the new nicename.
			'message' => sprintf( esc_html__( 'Slug updated to %s!', 'newspack-plugin' ), $new_nicename ),
		];

		wp_send_json( $response );

		exit;
	}

	/**
	 * Add user profile fields.
	 *
	 * @param WP_User $user The current WP_User object.
	 */
	public static function edit_user_profile( $user ) {

		$user = get_userdata( $user->ID ); // For some reason $user is not the full user object.
		if ( ! $user ) {
			return;
		}
		$current_nicename = $user->user_nicename;
		?>
		<div class="newspack-plugin-cap-options">

			<h2><?php echo esc_html__( 'Change User archive URL', 'newspack-plugin' ); ?></h2>

			<table class="form-table" role="presentation">
				<tr class="user-newspack_cap_custom_cap_option-wrap">
					<th scope="row">
						<?php esc_html_e( 'Current slug', 'newspack-plugin' ); ?>
					</th>
					<td>
						<?php echo esc_html( $current_nicename ); ?>
					</td>
				</tr>
				<tr class="user-newspack_cap_custom_cap_option-wrap">
					<th scope="row">
						<?php esc_html_e( 'Change slug', 'newspack-plugin' ); ?>
					</th>
					<td>
						<input type="text" name="newspack_change_nicename" id="newspack_change_nicename" value="" class="regular-text" />
						<div id="newspack_change_nicename_message_success" class="newspack-change-nicename-ui-message newspack-change-nicename-ui-success" style="display: none;"></div>
						<div id="newspack_change_nicename_message_error" class="newspack-change-nicename-ui-message newspack-change-nicename-ui-error" style="display: none;"></div>
						<p class="description">
							<?php
							esc_html_e(
								'Changing the slug will change the URL of your author archive page. The old URL will redirect to the new one after the change.',
								'newspack-plugin'
							);
							?>
						</p>
						<input type="hidden" id="newspack_change_nicename_nonce" value="<?php echo esc_attr( wp_create_nonce( 'newspack_change_nicename_nonce' ) ); ?>" />
						<button class="button newspack-change-nicename-button" id="newspack_change_nicename_check">
							<?php esc_html_e( 'Check availability', 'newspack-plugin' ); ?>
						</button>
						<button class="button newspack-change-nicename-button" id="newspack_change_nicename_submit" data-user-id="<?php echo esc_attr( $user->ID ); ?>">
							<?php esc_html_e( 'Change slug', 'newspack-plugin' ); ?>
						</button>
					</td>
				</tr>
			</table>
		</div>
		<?php
	}
}

Nicename_Change_UI::init();
