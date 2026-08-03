<?php
/**
 * My Account Account Details page.
 * Based on woocommerce/templates/myaccount/form-edit-account.php.
 *
 * @package Newspack
 * @version 8.7.0
 */

namespace Newspack;

use Newspack\WooCommerce_My_Account;
use Newspack\Reader_Activation;

defined( 'ABSPATH' ) || exit;

\do_action( 'newspack_woocommerce_before_edit_account_form' );

$newspack_reset_password_arg = WooCommerce_My_Account::RESET_PASSWORD_URL_PARAM;
$newspack_delete_account_arg = WooCommerce_My_Account::DELETE_ACCOUNT_URL_PARAM;

$message = false;
if ( isset( $_GET['message'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$message = $_GET['message']; // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
}

$is_error = false;
if ( isset( $_GET['is_error'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$is_error = $_GET['is_error']; // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
}

$without_password        = true === Reader_Activation::is_reader_without_password( $user );
$is_reader               = true === Reader_Activation::is_user_reader( $user );
$is_email_change_enabled = true === WooCommerce_My_Account::is_email_change_enabled();
$is_pending_email_change = $user->get( WooCommerce_My_Account::PENDING_EMAIL_CHANGE_META ) ? true : false;
$display_email           = $is_pending_email_change ? $user->get( WooCommerce_My_Account::PENDING_EMAIL_CHANGE_META ) : $user->user_email;
?>

<?php
if ( $message ) :
	?>
	<div class="newspack-wc-message <?php echo $is_error ? 'newspack-wc-message--error' : ''; ?>">
		<p><?php echo \esc_html( $message ); ?></p>
	</div>
	<?php
endif;
?>

<form class="woocommerce-EditAccountForm edit-account" action="" name="edit_account" method="post" <?php \do_action( 'newspack_woocommerce_edit_account_form_tag' ); ?> >

	<?php \do_action( 'newspack_woocommerce_edit_account_form_start' ); ?>

	<p class="woocommerce-form-row woocommerce-form-row--wide form-row form-row-wide mt0">
		<label for="account_display_name"><?php \esc_html_e( 'Display name', 'newspack-plugin' ); ?>&nbsp;<span class="required">*</span></label>
		<input
			type="text"
			class="woocommerce-Input woocommerce-Input--text input-text"
			name="account_display_name"
			id="account_display_name"
			autocomplete="name"
			placeholder="<?php esc_attr_e( 'Your Name', 'newspack-plugin' ); ?>"
			value="<?php echo ! Reader_Activation::reader_has_generic_display_name() ? \esc_attr( $user->display_name ) : ''; ?>"
		/>
		<span><em><?php esc_html_e( 'This is how your name is displayed publicly.', 'newspack-plugin' ); ?></em></span>
	</p>

	<p class="woocommerce-form-row woocommerce-form-row--wide form-row form-row-wide mt0">
		<label for="account_email_display"><?php \esc_html_e( 'Email address', 'newspack-plugin' ); ?>
		<?php
		if ( $is_email_change_enabled ) :
			?>
		<input type="email" class="woocommerce-Input woocommerce-Input--email input-text" name="newspack_account_email" id="newspack_account_email" autocomplete="email" <?php echo \esc_attr( $is_pending_email_change ? 'disabled' : '' ); ?> value="<?php echo \esc_attr( $display_email ); ?>" />
		<input type="hidden" class="woocommerce-Input woocommerce-Input--email input-text" name="account_email" id="account_email" autocomplete="email" value="<?php echo \esc_attr( $user->user_email ); ?>" />
			<?php if ( $is_pending_email_change ) : ?>
			<span>
				<em>
				<?php
				echo \wp_kses_post(
					sprintf(
						// Translators: %s is the account's current email address.
						__( 'This email address is pending verification. Please verify to complete the change request, or cancel the change to retain the current account email: %s', 'newspack-plugin' ),
						"<a href='mailto:$user->user_email'>$user->user_email</a>"
					)
				);
				?>
				</em>
			</span>
			<?php endif; ?>
		<?php else : ?>
		<input type="email" class="woocommerce-Input woocommerce-Input--email input-text" name="account_email_display" id="account_email_display" autocomplete="email" disabled value="<?php echo \esc_attr( $user->user_email ); ?>" />
		<input type="hidden" class="woocommerce-Input woocommerce-Input--email input-text" name="account_email" id="account_email" autocomplete="email" value="<?php echo \esc_attr( $user->user_email ); ?>" />
		<?php endif; ?>
	</p>

	<?php
		/**
		 * Hook where additional fields should be rendered.
		 *
		 * Newspack equivalent of do_action( 'woocommerce_edit_account_form_fields' );
		 */
		do_action( 'newspack_woocommerce_edit_account_form_fields' );
	?>

	<?php
		/**
		 * My Account edit account form.
		 *
		 * Newspack equivalent of do_action( 'woocommerce_edit_account_form' );
		 */
		\do_action( 'newspack_woocommerce_edit_account_form' );
	?>

	<p class="woocommerce-buttons-card">
		<?php \wp_nonce_field( 'save_account_details', 'save-account-details-nonce' ); ?>
		<?php if ( $is_email_change_enabled && $is_pending_email_change ) : ?>
			<a href="<?php echo esc_url( WooCommerce_My_Account::get_email_change_url( WooCommerce_My_Account::CANCEL_EMAIL_CHANGE_PARAM, $user->user_email ) ); ?>" class="woocommerce-Button button ma0"><?php \esc_html_e( 'Cancel email change', 'newspack-plugin' ); ?></a>
		<?php endif; ?>
		<button type="submit" class="woocommerce-Button button secondary ma0" name="save_account_details" value="<?php \esc_attr_e( 'Save changes', 'newspack-plugin' ); ?>"><?php \esc_html_e( 'Save changes', 'newspack-plugin' ); ?></button>
		<input type="hidden" name="action" value="save_account_details" />
	</p>

	<?php \do_action( 'newspack_woocommerce_edit_account_form_end' ); ?>
</form>

<hr class="is-style-wide" />

<div class="woocommerce-card woocommerce-form-row woocommerce-form-row--wide form-row form-row-wide">
	<a href="<?php echo '?' . \esc_attr( $newspack_reset_password_arg ) . '=' . \esc_attr( \wp_create_nonce( $newspack_reset_password_arg ) ); ?>">
		<span class="woocommerce-card__content">
			<h4 class="woocommerce-card__title">
				<?php
				if ( $without_password ) {
					\esc_html_e( 'Create a Password', 'newspack-plugin' );
				} else {
					\esc_html_e( 'Reset Password', 'newspack-plugin' );
				}
				?>
			</h4>
			<span class="woocommerce-card__description">
				<?php
				if ( $without_password ) {
					\esc_html_e( 'Email me a link to set my password', 'newspack-plugin' );
				} else {
					\esc_html_e( 'Email me a password reset link', 'newspack-plugin' );
				}
				?>
			</span>
		</span>
	</a>
</div>

<?php
if ( $is_reader ) :
	?>

<div class="woocommerce-card woocommerce-form-row woocommerce-form-row--wide form-row form-row-wide">
	<a href="<?php echo '?' . \esc_attr( $newspack_delete_account_arg ) . '=' . \esc_attr( \wp_create_nonce( $newspack_delete_account_arg ) ); ?>" class="is-destructive">
		<span class="woocommerce-card__content">
			<h4 class="woocommerce-card__title">
				<?php \esc_html_e( 'Delete Account', 'newspack-plugin' ); ?>
			</h4>
			<span class="woocommerce-card__description">
				<?php \esc_html_e( 'Request account deletion', 'newspack-plugin' ); ?>
			</span>
		</span>
	</a>
	<p>
		<?php \esc_html_e( 'Deleting your account will also cancel any newsletter subscriptions and recurring payments.', 'newspack-plugin' ); ?>
	</p>
</div>

<?php endif; ?>

<?php \do_action( 'newspack_woocommerce_after_edit_account_form' ); ?>
