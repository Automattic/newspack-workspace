<?php
/**
 * My Account Account Settings page.
 * Based on woocommerce/templates/myaccount/form-edit-account.php.
 *
 * @author   Newspack
 * @category WooCommerce Subscriptions/Templates
 * @package  Newspack
 */

namespace Newspack;

use Newspack\WooCommerce_My_Account;
use Newspack\Reader_Activation;
use Newspack\Emails;
use Newspack\My_Account_UI_V1;

defined( 'ABSPATH' ) || exit;

\do_action( 'newspack_woocommerce_before_edit_account_form' );

$newspack_reset_password_arg = WooCommerce_My_Account::RESET_PASSWORD_URL_PARAM;
$newspack_delete_account_arg = WooCommerce_My_Account::DELETE_ACCOUNT_URL_PARAM;

$without_password        = true === Reader_Activation::is_reader_without_password( $user );
$is_reader               = true === Reader_Activation::is_user_reader( $user );
$is_email_change_enabled = true === WooCommerce_My_Account::is_email_change_enabled();
$is_pending_email_change = $user->get( WooCommerce_My_Account::PENDING_EMAIL_CHANGE_META ) ? true : false;
$display_email           = $is_pending_email_change ? $user->get( WooCommerce_My_Account::PENDING_EMAIL_CHANGE_META ) : $user->user_email;
?>

<section id="account-profile">
	<h4 class="newspack-ui__font--m newspack-ui__spacing-top--0"><?php \esc_html_e( 'Profile', 'newspack-plugin' ); ?></h4>
	<form class="woocommerce-EditAccountForm edit-profile" action="" name="edit_account" method="post" <?php \do_action( 'newspack_woocommerce_edit_account_form_tag' ); ?> >

		<?php \do_action( 'newspack_woocommerce_edit_account_form_start' ); ?>

		<p class="woocommerce-form-row woocommerce-form-row--first form-row form-row-first">
			<label for="account_first_name"><?php esc_html_e( 'First name', 'newspack-plugin' ); ?></label>
			<input
				type="text"
				class="woocommerce-Input woocommerce-Input--text input-text"
				name="account_first_name"
				id="account_first_name"
				autocomplete="given-name"
				placeholder="<?php esc_attr_e( 'Your First Name', 'newspack-plugin' ); ?>"
				value="<?php echo esc_attr( $user->first_name ); ?>"
				aria-required="true"
			/>
		</p>
		<p class="woocommerce-form-row woocommerce-form-row--last form-row form-row-last">
			<label for="account_last_name"><?php esc_html_e( 'Last name', 'newspack-plugin' ); ?></label>
			<input
				type="text"
				class="woocommerce-Input woocommerce-Input--text input-text"
				name="account_last_name"
				id="account_last_name"
				autocomplete="family-name"
				placeholder="<?php esc_attr_e( 'Your Last Name', 'newspack-plugin' ); ?>"
				value="<?php echo esc_attr( $user->last_name ); ?>"
				aria-required="true"
			/>
		</p>
		<div class="clear"></div>

		<p class="woocommerce-form-row woocommerce-form-row--wide form-row form-row-wide mt0">
			<label for="account_display_name"><?php \esc_html_e( 'Display name', 'newspack-plugin' ); ?></label>
			<input
				type="text"
				class="woocommerce-Input woocommerce-Input--text input-text"
				name="account_display_name"
				id="account_display_name"
				autocomplete="name"
				placeholder="<?php esc_attr_e( 'Your Display Name', 'newspack-plugin' ); ?>"
				value="<?php echo ! Reader_Activation::reader_has_generic_display_name() ? \esc_attr( $user->display_name ) : ''; ?>"
			/>
			<span class="legend"><?php esc_html_e( 'This is how your name is displayed publicly.', 'newspack-plugin' ); ?></span>
		</p>

		<p class="woocommerce-form-row woocommerce-form-row--wide form-row form-row-wide mt0">
			<label for="account_email_display"><?php \esc_html_e( 'Email address', 'newspack-plugin' ); ?>
				<?php if ( $is_email_change_enabled ) : ?>
					&nbsp;<span class="required">*</span>
				<?php endif; ?>
			</label>
			<?php
			if ( $is_email_change_enabled ) :
				?>
				<input
					type="email"
					class="woocommerce-Input woocommerce-Input--email input-text"
					name="newspack_account_email"
					id="newspack_account_email"
					autocomplete="email"
					<?php echo \esc_attr( $is_pending_email_change ? 'disabled' : '' ); ?>
					value="<?php echo \esc_attr( $display_email ); ?>"
					required
					aria-required="true"
				/>
				<input
					type="hidden"
					class="woocommerce-Input woocommerce-Input--email input-text"
					name="account_email"
					id="account_email"
					autocomplete="email"
					value="<?php echo \esc_attr( $user->user_email ); ?>"
					required
					aria-required="true"
				/>
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
			<span class="legend">
				<?php
				echo wp_kses_post(
					sprintf(
						// Translators: %s is the reply-to email address for the site.
						__( 'To update your email address, please <a href="mailto:%s">contact us</a>.', 'newspack-plugin' ),
						Emails::get_reply_to_email()
					)
				);
				?>
			</span>
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
			<input type="hidden" name="action" value="save_account_details" />
			<button type="submit" class="woocommerce-Button button primary newspack-ui__button--wide-on-mobile newspack-ui--block-on-interaction" name="save_account_details" value="<?php \esc_attr_e( 'Save changes', 'newspack-plugin' ); ?>"><?php \esc_html_e( 'Update profile', 'newspack-plugin' ); ?></button>
		</p>

		<?php \do_action( 'newspack_woocommerce_edit_account_form_end' ); ?>
	</form>
</section>

<?php if ( $is_reader ) : ?>
<section id="account-password">
	<h4 class="newspack-ui__font--m"><?php \esc_html_e( 'Password', 'newspack-plugin' ); ?></h4>
	<?php if ( $without_password || ! empty( filter_input( INPUT_GET, My_Account_UI_V1_Passwords::RESET_PASSWORD_URL_PARAM, FILTER_SANITIZE_FULL_SPECIAL_CHARS ) ) ) : ?>
	<p>
		<?php echo \esc_html( $without_password ? __( 'Create a password to secure your account.', 'newspack-plugin' ) : __( 'Reset your account password.', 'newspack-plugin' ) ); ?>
	</p>
	<a id="newspack-my-account__reset-password" class="woocommerce-Button button primary newspack-ui__button--wide-on-mobile" href="<?php echo '?' . \esc_attr( $newspack_reset_password_arg ) . '=' . \esc_attr( \wp_create_nonce( $newspack_reset_password_arg ) ); ?>">
		<?php echo \esc_html( $without_password ? __( 'Create a password', 'newspack-plugin' ) : __( 'Reset password', 'newspack-plugin' ) ); ?>
	</a>
	<?php else : ?>
	<form method="post" class="woocommerce-ResetPassword lost_reset_password">
		<p class="woocommerce-form-row woocommerce-form-row--wide form-row form-row-wide">
			<label for="current_password"><?php esc_html_e( 'Current password', 'newspack-plugin' ); ?>&nbsp;<span class="required" aria-hidden="true">*</span><span class="screen-reader-text"><?php esc_html_e( 'Required', 'newspack-plugin' ); ?></span></label>
			<input
				type="password"
				class="woocommerce-Input woocommerce-Input--text input-text"
				name="current_password"
				id="current_password"
				required
				aria-required="true"
			/>
		</p>
		<p class="woocommerce-form-row woocommerce-form-row--wide form-row form-row-wide">
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
		<p class="woocommerce-form-row woocommerce-form-row--wide form-row form-row-wide">
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

		<p class="woocommerce-buttons-card">
			<input type="hidden" name="wc_reset_password" value="true" />
			<input type="hidden" name="action" value="newspack_my_account_reset_password" />
			<button
				type="submit"
				class="woocommerce-Button button primary newspack-ui__button--wide-on-mobile newspack-ui--block-on-interaction"
				value="<?php esc_attr_e( 'Save', 'newspack-plugin' ); ?>"
			>
				<?php echo esc_html( $without_password ? __( 'Set password', 'newspack-plugin' ) : __( 'Update password', 'newspack-plugin' ) ); ?>
			</button>
			<a id="newspack-my-account__reset-password" class="woocommerce-Button button ghost newspack-ui__button--wide-on-mobile newspack-ui--block-on-interaction" href="<?php echo '?' . \esc_attr( $newspack_reset_password_arg ) . '=' . \esc_attr( \wp_create_nonce( $newspack_reset_password_arg ) ); ?>">
				<?php \esc_html_e( 'Forgot password', 'newspack-plugin' ); ?>
			</a>
		</p>

		<?php wp_nonce_field( 'reset_password', 'woocommerce-reset-password-nonce' ); ?>

	</form>
	<?php endif; ?>
</section>

<section id="delete-account">

	<h4 class="newspack-ui__font--m is-destructive"><?php \esc_html_e( 'Delete account', 'newspack-plugin' ); ?></h4>
	<p>
		<?php \esc_html_e( 'Please note, account deletion is final, and there will be no way to restore your account.', 'newspack-plugin' ); ?>
	</p>
	<p class="woocommerce-buttons-card">
		<a class="newspack-ui__button newspack-ui__button--destructive newspack-ui__button--wide-on-mobile" href="<?php echo '?' . \esc_attr( $newspack_delete_account_arg ) . '=' . \esc_attr( WooCommerce_My_Account::get_delete_account_nonce() ); ?>">
		<?php \esc_html_e( 'Delete Account', 'newspack-plugin' ); ?>
		</a>
	</p>
</section>
<?php endif; ?>

<?php \do_action( 'newspack_woocommerce_after_edit_account_form' ); ?>
