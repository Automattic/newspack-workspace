<?php
/**
 * "Reset Password" functionality for Newspack "My Account" UI v1.x.x.
 *
 * @package Newspack
 */

namespace Newspack;

use Newspack\Reader_Activation;
use Newspack\Newspack_UI;

defined( 'ABSPATH' ) || exit;

/**
 * Newspack "My Account" customizations v1.x.x.
 */
class My_Account_UI_V1_Passwords {
	const RESET_PASSWORD_ACTION = 'newspack_my_account_reset_password';
	const RESET_PASSWORD_URL_PARAM = 'newspack-reset-password';

	/**
	 * Initialize.
	 *
	 * @codeCoverageIgnore
	 */
	public static function init() {
		\add_action( 'wp_loaded', [ __CLASS__, 'maybe_generate_password_reset_key' ] );
		\add_action( 'template_redirect', [ __CLASS__, 'redirect_reset_password_link' ], 11 );
		\add_filter( 'validate_password_reset', [ __CLASS__, 'validate_password_reset' ], 10, 2 );
		\add_action( 'wp_loaded', [ __CLASS__, 'maybe_password_reset_success' ] );
		\add_action( 'newspack_woocommerce_after_edit_account_form', [ __CLASS__, 'add_reset_password_modal' ] );
	}

	/**
	 * Intercept a password reset request from Account Settings page.
	 * The key must be generated before the user's password can be reset,
	 * but not before submitting the form, otherwise it will break the Forgot Password flow.
	 */
	public static function maybe_generate_password_reset_key() {
		// Only if the user is logged in and a reader.
		$user = \wp_get_current_user();
		if ( ! \is_user_logged_in() || ! Reader_Activation::is_user_reader( $user ) ) {
			return;
		}

		// Only if updating password from Account Settings page.
		$action = filter_input( INPUT_POST, 'action', FILTER_SANITIZE_FULL_SPECIAL_CHARS );
		$nonce  = filter_input( INPUT_POST, 'woocommerce-reset-password-nonce', FILTER_SANITIZE_FULL_SPECIAL_CHARS );
		if ( self::RESET_PASSWORD_ACTION !== $action || empty( $nonce ) || ! \wp_verify_nonce( $nonce, 'reset_password' ) ) {
			return;
		}

		// Generate a password reset key.
		$key   = \get_password_reset_key( $user );
		$login = $user->data->user_login;
		if ( \is_wp_error( $key ) ) {
			return;
		}

		// Pass the key and login to the posted data.
		$_POST['reset_key']   = $key;
		$_POST['reset_login'] = $login;
	}

	/**
	 * Check if the user has a valid password reset key.
	 *
	 * @return bool True if the user has a valid password reset key, false otherwise.
	 */
	public static function check_password_reset_key() {
		if ( isset( $_COOKIE[ 'wp-resetpass-' . COOKIEHASH ] ) && 0 < strpos( $_COOKIE[ 'wp-resetpass-' . COOKIEHASH ], ':' ) ) {  // @codingStandardsIgnoreLine
			list( $rp_id, $rp_key ) = array_map( 'wc_clean', explode( ':', \wp_unslash( $_COOKIE[ 'wp-resetpass-' . COOKIEHASH ] ), 2 ) ); // @codingStandardsIgnoreLine
			$userdata               = \get_userdata( absint( $rp_id ) );
			$rp_login               = $userdata ? $userdata->user_login : '';
			$user                   = \check_password_reset_key( $rp_key, $rp_login );

			if ( is_a( $user, 'WP_User' ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Redirect the user to the Account Settings page to reset their password via a custom modal.
	 */
	public static function redirect_reset_password_link() {
		// Only in My Account.
		if ( ! function_exists( 'is_account_page' ) || ! \is_account_page() ) {
			return;
		}

		// Only if the user is logged in and a reader.
		if ( ! \is_user_logged_in() || ! Reader_Activation::is_user_reader( \wp_get_current_user() ) ) {
			return;
		}

		// Only if showing the password reset form.
		if ( empty( filter_input( INPUT_GET, 'show-reset-form', FILTER_VALIDATE_BOOLEAN ) ) ) {
			return;
		}

		// Only if the user has a valid password reset key.
		if ( ! self::check_password_reset_key() ) {
			return;
		}

		\wp_safe_redirect(
			\add_query_arg(
				self::RESET_PASSWORD_URL_PARAM,
				\wp_create_nonce( 'newspack_my_account_reset_password' ),
				\wc_get_account_endpoint_url( 'edit-account' )
			)
		);
		exit;
	}

	/**
	 * Validate new reader password before being saved.
	 *
	 * @param WP_Error $errors Errors object.
	 * @param WP_User  $user   The user object.
	 *
	 * @return array
	 */
	public static function validate_password_reset( $errors, $user ) {
		// Only if the user is logged in and a reader.
		if ( ! \is_user_logged_in() || ! Reader_Activation::is_user_reader( $user ) ) {
			return $errors;
		}

		// Only if updating password from Account Settings page.
		$action = filter_input( INPUT_POST, 'action', FILTER_SANITIZE_FULL_SPECIAL_CHARS );
		if ( self::RESET_PASSWORD_ACTION !== $action ) {
			return $errors;
		}

		// If resetting via the email-based flow, skip the password check.
		$reset_password = filter_input( INPUT_GET, self::RESET_PASSWORD_URL_PARAM, FILTER_SANITIZE_FULL_SPECIAL_CHARS );
		if ( $reset_password ) {
			return $errors;
		}

		// Check if the current password is correct.
		$is_without_password = Reader_Activation::is_reader_without_password( $user );
		$current_password    = filter_input( INPUT_POST, 'current_password', FILTER_SANITIZE_FULL_SPECIAL_CHARS );
		if ( ! $is_without_password && empty( $current_password ) ) {
			$errors->add( 'missing_current_password', __( 'Please enter your current password.', 'newspack-plugin' ) );
			return $errors;
		}
		$password_check = $is_without_password || \wp_check_password( $current_password, $user->data->user_pass, $user->ID );
		if ( ! $password_check ) {
			$errors->add( 'invalid_current_password', __( 'Invalid current password.', 'newspack-plugin' ) );
			return $errors;
		}

		return $errors;
	}

	/**
	 * Display a success notice if the password was updated.
	 */
	public static function maybe_password_reset_success() {
		if ( \is_user_logged_in() && filter_input( INPUT_GET, 'password-reset', FILTER_VALIDATE_BOOLEAN ) ) {
			\wc_add_notice( __( 'Password updated.', 'newspack-plugin' ), 'success' );
		}
	}

	/**
	 * Display a modal to reset the user's password.
	 */
	public static function add_reset_password_modal() {
		// Only if the user is logged in and a reader.
		if ( ! \is_user_logged_in() || ! Reader_Activation::is_user_reader( \wp_get_current_user() ) ) {
			return;
		}

		// If the user has clicked the button from the reset password email, show the modal.
		$reset_password = filter_input( INPUT_GET, self::RESET_PASSWORD_URL_PARAM, FILTER_SANITIZE_FULL_SPECIAL_CHARS );
		if ( ! $reset_password || ! \wp_verify_nonce( $reset_password, 'newspack_my_account_reset_password' ) ) {
			return;
		}

		ob_start();
		?>
		<p>
			<label for="password_1"><?php esc_html_e( 'New password', 'newspack-plugin' ); ?>&nbsp;<span class="required" aria-hidden="true">*</span><span class="screen-reader-text"><?php esc_html_e( 'Required', 'newspack-plugin' ); ?></span></label>
			<input
				type="password"
				class="woocommerce-Input woocommerce-Input--text input-text"
				name="password_1"
				id="password_1"
				autocomplete="new-password"
				required
				aria-required="true"
			/>
		</p>
		<p>
			<label for="password_2"><?php esc_html_e( 'Re-enter new password', 'newspack-plugin' ); ?>&nbsp;<span class="required" aria-hidden="true">*</span><span class="screen-reader-text"><?php esc_html_e( 'Required', 'newspack-plugin' ); ?></span></label>
			<input
				type="password"
				class="woocommerce-Input woocommerce-Input--text input-text"
				name="password_2"
				id="password_2"
				autocomplete="new-password"
				required
				aria-required="true"
			/>
		</p>

		<?php do_action( 'newspack_woocommerce_resetpassword_form' ); ?>

		<input type="hidden" name="wc_reset_password" value="true" />
		<input type="hidden" name="action" value="<?php echo \esc_attr( self::RESET_PASSWORD_ACTION ); ?>" />

		<?php wp_nonce_field( 'reset_password', 'woocommerce-reset-password-nonce' ); ?>

		<?php
		$content = ob_get_clean();
		$user    = \wp_get_current_user();
		$action  = filter_input( INPUT_POST, 'action', FILTER_SANITIZE_FULL_SPECIAL_CHARS );

		Newspack_UI::generate_modal(
			[
				'id'         => 'reset-password-modal',
				'title'      => Reader_Activation::is_reader_without_password( $user ) ? __( 'Set password', 'newspack-plugin' ) : __( 'Reset password', 'newspack-plugin' ),
				'content'    => $content,
				'size'       => 'small',
				'form'       => 'POST',
				'form_class' => 'lost_reset_password',
				'state'      => self::RESET_PASSWORD_ACTION === $action ? 'closed' : 'open',
				'actions'    => [
					'continue' => [
						'label' => __( 'Save password', 'newspack-plugin' ),
						'type'  => 'primary',
					],
					'cancel'   => [
						'label'  => __( 'Cancel', 'newspack-plugin' ),
						'type'   => 'ghost',
						'action' => 'close',
					],
				],
			]
		);
	}
}
My_Account_UI_V1_Passwords::init();
