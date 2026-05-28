<?php
/**
 * Newspack Data Events Popups helper.
 *
 * @package Newspack
 */

namespace Newspack\Data_Events;

use Newspack_Popups_Data_Api;
use Newspack\Data_Events;
use WP_Error;

/**
 * Class to register the Popups listeners.
 */
final class Popups {

	/**
	 * The name of the action for form submissions
	 */
	const FORM_SUBMISSION = 'form_submission_received';

	/**
	 * The name of the action for form submissions
	 */
	const FORM_SUBMISSION_SUCCESS = 'form_submission_success';

	/**
	 * The name of the action for form submissions
	 */
	const FORM_SUBMISSION_FAILURE = 'form_submission_failure';

	/**
	 * Initialize the class by registering the listeners.
	 *
	 * @return void
	 */
	public static function init() {
		Data_Events::register_listener(
			'newspack_reader_registration_form_processed',
			'prompt_interaction',
			[ __CLASS__, 'registration_submission' ]
		);

		Data_Events::register_listener(
			'newspack_reader_registration_form_processed',
			'prompt_interaction',
			[ __CLASS__, 'registration_submission_with_status' ]
		);

		Data_Events::register_listener(
			'newspack_newsletters_subscribe_form_processed',
			'prompt_interaction',
			[ __CLASS__, 'newsletter_submission' ]
		);

		Data_Events::register_listener(
			'newspack_newsletters_subscribe_form_processed',
			'prompt_interaction',
			[ __CLASS__, 'newsletter_submission_with_status' ]
		);

		Data_Events::register_listener(
			'newspack_data_event_dispatch_donation_new',
			'prompt_interaction',
			[ __CLASS__, 'donation_submission_success' ]
		);

		Data_Events::register_listener(
			'newspack_data_event_dispatch_woocommerce_donation_order_processed',
			'prompt_interaction',
			[ __CLASS__, 'donation_submission_woocommerce' ]
		);

		Data_Events::register_listener(
			'newspack_data_event_dispatch_woocommerce_order_failed',
			'prompt_interaction',
			[ __CLASS__, 'donation_submission_woocommerce_error' ]
		);
	}

	/**
	 * A listener for the registration block form submission
	 *
	 * Will trigger the event with "form_submission" as action in all cases.
	 *
	 * @param string              $email   Email address of the reader.
	 * @param int|false|\WP_Error $user_id The created user ID in case of registration, false if not created or a WP_Error object.
	 * @param array               $metadata Array with metadata about the user being registered.
	 * @return ?array
	 */
	public static function registration_submission( $email, $user_id, $metadata ) {
		$popup_id = $metadata['newspack_popup_id'];
		if ( ! $popup_id ) {
			return;
		}
		$popup_data = Newspack_Popups_Data_Api::get_popup_metadata( $popup_id );
		$popup_data = array_merge(
			$popup_data,
			[
				'action'      => self::FORM_SUBMISSION,
				'action_type' => 'registration',
				'referer'     => $metadata['referer'],
			]
		);
		$popup_data['interaction_data']['registration_method'] = $metadata['registration_method'];
		return $popup_data;
	}

	/**
	 * A listener for the registration block form submission
	 *
	 * Will trigger the event with "form_submission_success" or "form_submission_failure" as action.
	 *
	 * @param string              $email   Email address of the reader.
	 * @param int|false|\WP_Error $user_id The created user ID in case of registration, false if not created or a WP_Error object.
	 * @param array               $metadata Array with metadata about the user being registered.
	 * @return ?array
	 */
	public static function registration_submission_with_status( $email, $user_id, $metadata ) {
		$popup_id = $metadata['newspack_popup_id'];
		if ( ! $popup_id ) {
			return;
		}
		$action = self::FORM_SUBMISSION_SUCCESS;
		if ( ! $user_id || \is_wp_error( $user_id ) ) {
			$action = self::FORM_SUBMISSION_FAILURE;
		}
		$popup_data = Newspack_Popups_Data_Api::get_popup_metadata( $popup_id );
		$popup_data = array_merge(
			$popup_data,
			[
				'action'      => $action,
				'action_type' => 'registration',
				'referer'     => $metadata['referer'],
			]
		);
		$popup_data['interaction_data']['registration_method'] = $metadata['registration_method'];
		return $popup_data;
	}

	/**
	 * A listener for the registration block form submission
	 *
	 * Will trigger the event with "form_submission" as action in all cases.
	 *
	 * @param string         $email  Email address of the reader.
	 * @param array|WP_Error $result Contact data if it was added, or error otherwise.
	 * @param array          $metadata Some metadata about the subscription. Always contains `current_page_url`, `newspack_popup_id` and `newsletters_subscription_method` keys.
	 * @return ?array
	 */
	public static function newsletter_submission( $email, $result, $metadata = [] ) {
		$popup_id = $metadata['newspack_popup_id'];
		if ( ! $popup_id ) {
			return;
		}
		$popup_data = Newspack_Popups_Data_Api::get_popup_metadata( $popup_id );
		$popup_data = array_merge(
			$popup_data,
			[
				'action'      => self::FORM_SUBMISSION,
				'action_type' => 'newsletters_subscription',
				'referer'     => $metadata['current_page_url'],
			]
		);
		$popup_data['interaction_data']['newsletters_subscription_method'] = $metadata['newsletters_subscription_method'];
		return $popup_data;
	}

	/**
	 * A listener for the registration block form submission
	 *
	 * Will trigger the event with "form_submission_success" or "form_submission_failure" as action.
	 *
	 * @param string         $email  Email address of the reader.
	 * @param array|WP_Error $result Contact data if it was added, or error otherwise.
	 * @param array          $metadata Some metadata about the subscription. Always contains `current_page_url`, `newspack_popup_id` and `newsletters_subscription_method` keys.
	 * @return ?array
	 */
	public static function newsletter_submission_with_status( $email, $result, $metadata = [] ) {
		$popup_id = $metadata['newspack_popup_id'];
		if ( ! $popup_id ) {
			return;
		}
		$action = self::FORM_SUBMISSION_SUCCESS;
		if ( ! $result || \is_wp_error( $result ) ) {
			$action = self::FORM_SUBMISSION_FAILURE;
		}
		$popup_data = Newspack_Popups_Data_Api::get_popup_metadata( $popup_id );
		$popup_data = array_merge(
			$popup_data,
			[
				'action'      => $action,
				'action_type' => 'newsletters_subscription',
				'referer'     => $metadata['current_page_url'],
			]
		);
		$popup_data['interaction_data']['newsletters_subscription_method'] = $metadata['newsletters_subscription_method'];
		return $popup_data;
	}

	/**
	 * A listener for the new_donation event
	 *
	 * @param int   $timestamp EventTimestamp.
	 * @param array $data      The new_donation event data.
	 * @return ?array
	 */
	public static function donation_submission_success( $timestamp, $data ) {
		$popup_id = $data['popup_id'] ?? false;
		if ( ! $popup_id || $data['is_renewal'] ) {
			return;
		}
		$popup_data = Newspack_Popups_Data_Api::get_popup_metadata( $popup_id );
		$popup_data = array_merge(
			$popup_data,
			[
				'action'      => self::FORM_SUBMISSION_SUCCESS,
				'action_type' => 'donation',
				'referer'     => $data['referer'],
			]
		);
		$popup_data['interaction_data']['donation_order_id']   = $data['platform_data']['order_id'];
		$popup_data['interaction_data']['donation_amount']     = $data['amount'];
		$popup_data['interaction_data']['donation_currency']   = $data['currency'];
		$popup_data['interaction_data']['donation_recurrence'] = $data['recurrence'];
		$popup_data['interaction_data']['donation_platform']   = $data['platform'];
		return $popup_data;
	}

	/**
	 * A listener for when woocommerce processes a donation
	 *
	 * @param int   $timestamp EventTimestamp.
	 * @param array $data      The new_donation event data.
	 * @return ?array
	 */
	public static function donation_submission_woocommerce( $timestamp, $data ) {
		$popup_id = $data['popup_id'] ?? false;
		if ( ! $popup_id || $data['is_renewal'] ) {
			return;
		}
		$popup_data = Newspack_Popups_Data_Api::get_popup_metadata( $popup_id );
		$popup_data = array_merge(
			$popup_data,
			[
				'action'      => self::FORM_SUBMISSION,
				'action_type' => 'donation',
				'referer'     => $data['referer'],
			]
		);

		$popup_data['interaction_data']['donation_order_id']   = $data['platform_data']['order_id'];
		$popup_data['interaction_data']['donation_amount']     = $data['amount'];
		$popup_data['interaction_data']['donation_currency']   = $data['currency'];
		$popup_data['interaction_data']['donation_recurrence'] = $data['recurrence'];
		$popup_data['interaction_data']['donation_platform']   = $data['platform'];
		return $popup_data;
	}

	/**
	 * A listener for when woocommerce fails to processes a donation
	 *
	 * @param int   $timestamp EventTimestamp.
	 * @param array $data      The new_donation event data.
	 * @return ?array
	 */
	public static function donation_submission_woocommerce_error( $timestamp, $data ) {
		$popup_id = $data['popup_id'] ?? false;
		if ( ! $popup_id || $data['is_renewal'] ) {
			return;
		}
		$popup_data = Newspack_Popups_Data_Api::get_popup_metadata( $popup_id );
		$popup_data = array_merge(
			$popup_data,
			[
				'action'      => self::FORM_SUBMISSION_FAILURE,
				'action_type' => 'donation',
				'referer'     => $data['referer'],
			]
		);

		$popup_data['interaction_data']['donation_order_id']   = $data['platform_data']['order_id'];
		$popup_data['interaction_data']['donation_amount']     = $data['amount'];
		$popup_data['interaction_data']['donation_currency']   = $data['currency'];
		$popup_data['interaction_data']['donation_recurrence'] = $data['recurrence'];
		$popup_data['interaction_data']['donation_platform']   = $data['platform'];
		return $popup_data;
	}
}

Popups::init();
