<?php
/**
 * Newsletter signup detection: checks a reader's newsletter subscription on
 * the server after a third-party signup form submits.
 *
 * @package Newspack
 */

namespace Newspack;

defined( 'ABSPATH' ) || exit;

/**
 * Newsletter Signup Detection Class.
 */
final class Newsletter_Signup_Detection {

	/**
	 * Gravity Forms add-ons whose feeds add the submitter to a newsletter list.
	 *
	 * @var string[]
	 */
	const GRAVITY_FORMS_ESP_ADDONS = [
		'gravityformsmailchimp',
		'gravityformsactivecampaign',
	];

	/**
	 * Initialize hooks.
	 */
	public static function init() {
		add_action( 'mc4wp_form_subscribed', [ __CLASS__, 'handle_mc4wp_subscribed' ] );
		// Late, so add-on feeds that run during submission have reached the provider.
		add_action( 'gform_after_submission', [ __CLASS__, 'handle_gform_submission' ], 100, 2 );
	}

	/**
	 * Mailchimp for WordPress form subscribed.
	 */
	public static function handle_mc4wp_subscribed() {
		self::check_current_reader();
	}

	/**
	 * Gravity Forms submission.
	 *
	 * @param array $entry The entry.
	 * @param array $form  The form.
	 */
	public static function handle_gform_submission( $entry, $form ) {
		if ( ! self::can_check_current_reader() ) {
			return;
		}
		if ( empty( $form['id'] ) || ! self::form_has_esp_feed( (int) $form['id'] ) ) {
			return;
		}
		self::check_current_reader();
	}

	/**
	 * Whether the form has an active feed from a newsletter add-on.
	 *
	 * @param int $form_id Form ID.
	 *
	 * @return bool
	 */
	private static function form_has_esp_feed( int $form_id ): bool {
		if ( ! class_exists( '\GFAPI' ) || ! method_exists( '\GFAPI', 'get_feeds' ) ) {
			return false;
		}
		foreach ( self::GRAVITY_FORMS_ESP_ADDONS as $addon_slug ) {
			$feeds = \GFAPI::get_feeds( null, $form_id, $addon_slug, true );
			if ( is_array( $feeds ) && ! empty( $feeds ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether there is a reader whose own session can be checked.
	 *
	 * @return bool
	 */
	private static function can_check_current_reader(): bool {
		return \is_user_logged_in() && ! Reader_Data::is_switched_session();
	}

	/**
	 * Check the current reader's newsletter subscription, and check again
	 * shortly when the provider does not yet show one.
	 */
	private static function check_current_reader() {
		if ( ! self::can_check_current_reader() ) {
			return;
		}
		$user_id = \get_current_user_id();
		if ( true !== Reader_Data::refresh_newsletter_subscription( $user_id ) ) {
			Reader_Data::schedule_recheck( $user_id );
		}
	}
}
Newsletter_Signup_Detection::init();
