<?php
/**
 * Newspack Authors Primary Brand.
 *
 * @package Newspack
 */

namespace Newspack_Multibranded_Site\Admin;

use Newspack_Multibranded_Site\Meta\User_Primary_Brand as Meta;

defined( 'ABSPATH' ) || exit;

/**
 * Newspack Authors Primary Brand.
 */
class User_Primary_Brand {

	/**
	 * Initialize hooks.
	 */
	public static function init() {
		\add_action( 'edit_user_profile', [ __CLASS__, 'edit_user_profile' ] );
		\add_action( 'show_user_profile', [ __CLASS__, 'edit_user_profile' ] );
		\add_action( 'edit_user_profile_update', [ __CLASS__, 'edit_user_profile_update' ] );
		\add_action( 'personal_options_update', [ __CLASS__, 'edit_user_profile_update' ] );
	}

	/**
	 * Save custom fields.
	 *
	 * @param int $user_id User ID.
	 */
	public static function edit_user_profile_update( $user_id ) {
		if ( empty( $_POST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), 'update-user_' . $user_id ) ) {
			return;
		}

		if ( ! current_user_can( 'edit_user', $user_id ) ) {
			return false;
		}
		if ( isset( $_POST[ Meta::get_key() ] ) ) {
			\update_user_meta( $user_id, Meta::get_key(), sanitize_text_field( wp_unslash( $_POST[ Meta::get_key() ] ) ) );
		}
	}

	/**
	 * Add user profile fields.
	 *
	 * @param WP_User $user The current WP_User object.
	 */
	public static function edit_user_profile( $user ) {
		$brands = get_terms(
			[
				'taxonomy'   => 'brand',
				'hide_empty' => false,
			]
		);
		?>
		<div class="newspack-multibranded-site-options">
		<?php \wp_nonce_field( 'newspack_multibranded_site', 'newspack_multibranded_site_nonce' ); ?>
			<h2><?php echo esc_html__( 'Multibranded site Options', 'newspack-multibranded-site' ); ?></h2>
			<?php echo esc_html__( 'The user primary brand defines what brand will be applied to the user\'s archive.', 'newspack-multibranded-site' ); ?>
			<table class="form-table" role="presentation">
				<tr class="user-<?php echo esc_attr( Meta::get_key() ); ?>-wrap">
					<th>
						<label for="<?php echo esc_attr( Meta::get_key() ); ?>">
						<?php echo esc_html__( 'Primary Brand', 'newspack-multibranded-site' ); ?>
						</label>
					</th>
					<td>
						<select name="<?php echo esc_attr( Meta::get_key() ); ?>" id="<?php echo esc_attr( Meta::get_key() ); ?>">
							<option value=""><?php echo esc_html__( 'None', 'newspack-multibranded-site' ); ?></option>
							<?php foreach ( $brands as $brand ) : ?>
								<option value="<?php echo esc_attr( $brand->term_id ); ?>" <?php selected( $brand->term_id, \get_user_meta( $user->ID, Meta::get_key(), true ) ); ?>>
									<?php echo esc_html( $brand->name ); ?>
								</option>
							<?php endforeach; ?>
						</select>
					</td>
				</tr>
			</table>
		</div>
		<?php
	}
}
