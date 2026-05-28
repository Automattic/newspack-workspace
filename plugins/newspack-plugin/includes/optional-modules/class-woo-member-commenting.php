<?php
/**
 * Woo member commenting.
 *
 * @package Newspack
 */

namespace Newspack;

defined( 'ABSPATH' ) || exit;

/**
 * Woo member commenting.
 */
class Woo_Member_Commenting {

	/**
	 * Initialize.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'init', [ __CLASS__, 'on_init' ] );
	}

	/**
	 * Action callback on init.
	 *
	 * @return void
	 */
	public static function on_init() {
		if ( ! self::should_load() ) {
			return;
		}

		add_filter( 'comment_form_defaults', [ __CLASS__, 'filter_comment_form_defaults' ] );
		add_filter( 'comment_form_fields', [ __CLASS__, 'filter_comment_form_fields' ] );
		add_filter( 'comment_form_submit_field', [ __CLASS__, 'filter_comment_form_submit_field' ] );
	}

	/**
	 * Filter callback.
	 *
	 * @param array $defaults The defaults array to filter.
	 *
	 * @return mixed
	 */
	public static function filter_comment_form_defaults( $defaults ) {
		if ( ! self::require_membership_to_comment_for_user() ) {
			return $defaults;
		}

		$defaults['comment_notes_before'] = '';
		$defaults['must_log_in']          = self::get_membership_required_message();

		return $defaults;
	}


	/**
	 * Filter callback.
	 *
	 * @param array $fields The fields array to filter.
	 *
	 * @return array The filtered fields array.
	 */
	public static function filter_comment_form_fields( $fields ) {
		return self::require_membership_to_comment_for_user() ? [] : $fields;
	}

	/**
	 *  Filter callback.
	 *
	 * @param string $field Field to filter.
	 *
	 * @return string The filtered field.
	 */
	public static function filter_comment_form_submit_field( $field ) {
		if ( ! self::require_membership_to_comment_for_user() ) {
			return $field;
		}

		return self::get_membership_required_message();
	}

	/**
	 * Get the message to display to users that don't have access to commenting.
	 *
	 * @return string The message to display.
	 */
	private static function get_membership_required_message(): string {
		$message = __( 'Only Members may post a comment.', 'newspack-plugin' );
		if ( ! is_user_logged_in() ) {
			$sign_in_url = function_exists( '\wc_get_page_permalink' ) ? \wc_get_page_permalink( 'myaccount' ) : wp_login_url( get_permalink() );
			/* translators: %s: sign in URL */
			$message .= ' ' . sprintf( __( 'If you already have a membership, then <a href="%s">sign in</a>.', 'newspack-plugin' ), $sign_in_url );
		}
		$message .= ' ' . self::get_purchase_membership_link();

		return sprintf( '<p class="np-woo-member-commenting must-log-in">%s</p>', $message );
	}

	/**
	 * Whether class should suppress commenting for non-members.
	 *
	 * @return bool True if we should suppress commenting for non-members, false otherwise.
	 */
	public static function should_load(): bool {
		if ( empty( self::get_plan_slugs() ) ) {
			return false;
		}

		return Optional_Modules::is_optional_module_active( 'woo-member-commenting' ) && function_exists( 'wc_memberships_get_user_memberships' ) && ! current_user_can( 'edit_posts' );
	}

	/**
	 * Whether membership is required to comment for the current user.
	 *
	 * @param bool $skip_cache Whether to skip the cache.
	 *
	 * @return bool True if membership is required to comment, false otherwise.
	 */
	public static function require_membership_to_comment_for_user( bool $skip_cache = false ): bool {
		// This gets called a lot – so cache the result.
		static $require_membership_to_comment;
		if ( $skip_cache || null !== $require_membership_to_comment ) {
			return $require_membership_to_comment;
		}

		if ( ! self::should_load() ) {
			$require_membership_to_comment = false;
		} else {
			$memberships_required_plans    = array_filter(
				wc_memberships_get_user_memberships(
					get_current_user_id(),
					[
						'status' => Memberships::$active_statuses,
					]
				),
				fn( $membership ) => in_array( $membership->get_plan()->get_slug(), self::get_plan_slugs() )
			);
			$require_membership_to_comment = empty( $memberships_required_plans );
		}

		return $require_membership_to_comment;
	}

	/**
	 * Get slugs for membership plans that should allow commenting.
	 *
	 * This is managed in the constant for now – note that the membership_plan_slug value in the array can be a single string or an array of strings.
	 *
	 * @return array Array of plan slugs that allow commenting.
	 */
	private static function get_plan_slugs(): array {
		$slugs = self::get_module_setting( 'membership_plan_slug' );
		if ( empty( $slugs ) ) {
			return [];
		}
		if ( ! is_array( $slugs ) ) {
			return [ $slugs ];
		}

		return $slugs;
	}

	/**
	 * Get the link to purchase a membership.
	 *
	 * If the setting is empty, then an empty string is returned.
	 *
	 * @return string The link to purchase a membership or empty string.
	 */
	private static function get_purchase_membership_link(): string {
		$post_id = self::get_module_setting( 'membership_purchase_post_id' );
		if ( empty( $post_id ) || ! get_post( $post_id ) ) {
			return '';
		}

		$post_url = get_permalink( (int) $post_id );
		$message  = self::get_module_setting( 'membership_required_message' );
		if ( empty( $message ) ) {
			$message = __( 'Become a member now', 'newspack-plugin' );
		}

		return sprintf( '<a href="%s">%s</a>', $post_url, $message );
	}

	/**
	 * Get setting for this module.
	 *
	 * For now, settings are just a constant with an array.
	 *
	 * @param string $setting_name The setting to get from the constant array.
	 *
	 * @return false|mixed The value of the setting, or false if not found.
	 */
	private static function get_module_setting( string $setting_name ) {
		if ( ! defined( 'NP_WC_MEMBER_COMMENT_SETTINGS' ) || empty( NP_WC_MEMBER_COMMENT_SETTINGS ) || ! is_array( NP_WC_MEMBER_COMMENT_SETTINGS ) ) {
			return false;
		}

		$settings = wp_parse_args(
			NP_WC_MEMBER_COMMENT_SETTINGS,
			[
				'membership_plan_slug'        => [],
				'membership_purchase_post_id' => 0,
				'membership_required_message' => '',
			]
		);

		return $settings[ $setting_name ] ?? false;
	}
}

Woo_Member_Commenting::init();
