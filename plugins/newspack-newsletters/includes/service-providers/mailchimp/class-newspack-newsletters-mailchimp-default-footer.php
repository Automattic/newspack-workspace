<?php
/**
 * Service Provider: Mailchimp Default Footer
 *
 * @package Newspack
 */

defined( 'ABSPATH' ) || exit;

/**
 * Append default Mailchimp footer to all newsletter drafts when `newspack_mailchimp_auto_append_footer` is true.
 */
class Newspack_Newsletters_Mailchimp_Default_Footer {

	/**
	 * Get the default Mailchimp footer content.
	 *
	 * @return string Footer content.
	 */
	public static function get_footer_content() {

		$should_append_footer = 'mailchimp' === Newspack_Newsletters::service_provider() && get_option( 'newspack_mailchimp_auto_append_footer', false );
		if ( ! $should_append_footer ) {
			return '';
		}
		$manage_preferences_url = 'http://*|UPDATE_PROFILE|*';
		if ( function_exists( 'wc_get_account_endpoint_url' ) && method_exists( 'Newspack\Reader_Activation', 'is_enabled' ) && \Newspack\Reader_Activation::is_enabled() ) {
			$manage_preferences_url = wc_get_account_endpoint_url( Newspack_Newsletters_Subscription::WC_ENDPOINT );
		}

		// Translators: %s is replaced with the *|EMAIL|* Mailchimp merge tag.
		$sent_to = sprintf( __( 'This email was sent to %s', 'newspack-newsletters' ), '*|EMAIL|*' );

		$unsubscribe_this = __( 'Unsubscribe from this newsletter', 'newspack-newsletters' );

		// Translators: %s is replaced with the *|LIST:COMPANY|* mailchimp merge tag.
		$unsubscribe_all = sprintf( __( 'Opt out of all emails from %s', 'newspack-newsletters' ), '*|LIST:COMPANY|*' );

		return '<!-- wp:paragraph {"align":"center","fontSize":"small"} --><p class="has-text-align-center has-small-font-size"><br>' . $sent_to . '<br><a href="' . esc_url( $manage_preferences_url ) . '">' . $unsubscribe_this . '</a>&nbsp;—&nbsp;<a href="http://*|UNSUB|*">' . $unsubscribe_all . '</a><br>*|LIST_ADDRESSLINE_TEXT|**|IF:REWARDS|*<br><br>*|HTML:REWARDS|* *|END:IF|*</p><!-- /wp:paragraph -->';
	}
}
