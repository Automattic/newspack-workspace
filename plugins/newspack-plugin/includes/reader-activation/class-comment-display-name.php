<?php
/**
 * Comment Display Name — requires readers with auto-generated display names
 * to choose a proper display name before commenting.
 *
 * @package Newspack
 */

namespace Newspack\Reader_Activation;

use Newspack\Reader_Activation;

defined( 'ABSPATH' ) || exit;

/**
 * Comment Display Name class.
 */
final class Comment_Display_Name {

	/**
	 * Initialize hooks.
	 */
	public static function init() {
		\add_filter( 'comment_form_submit_field', [ __CLASS__, 'render_display_name_field' ] );
		\add_filter( 'preprocess_comment', [ __CLASS__, 'validate_display_name' ] );
	}

	/**
	 * Whether the current user should be prompted for a display name.
	 *
	 * @return bool
	 */
	private static function should_prompt() {
		if ( ! \is_user_logged_in() ) {
			return false;
		}
		$user = \wp_get_current_user();
		if ( ! Reader_Activation::is_user_reader( $user ) ) {
			return false;
		}
		return Reader_Activation::reader_has_generic_display_name( $user->ID );
	}

	/**
	 * Validate and save the display name before a comment is inserted.
	 *
	 * Runs on `preprocess_comment` so the updated display name is used as the
	 * comment author, rather than the stale email-derived name WordPress read
	 * from the user profile earlier in the request.
	 *
	 * @param array $commentdata Comment data.
	 * @return array Comment data with updated comment_author.
	 */
	public static function validate_display_name( $commentdata ) {
		if ( ! self::should_prompt() ) {
			return $commentdata;
		}

		$display_name = isset( $_POST['comment_display_name'] ) ? \sanitize_text_field( \wp_unslash( $_POST['comment_display_name'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing

		if ( empty( $display_name ) ) {
			\wp_die(
				esc_html__( 'Please enter a display name.', 'newspack-plugin' ),
				esc_html__( 'Comment Submission Failure', 'newspack-plugin' ),
				[ 'back_link' => true ]
			);
		}

		$user  = \wp_get_current_user();
		$email = $user->user_email;
		if (
			Reader_Activation::generate_user_nicename( $email ) === $display_name ||
			Reader_Activation::strip_email_domain( $email ) === $display_name
		) {
			\wp_die(
				esc_html__( 'Please choose a display name that is not derived from your email address.', 'newspack-plugin' ),
				esc_html__( 'Comment Submission Failure', 'newspack-plugin' ),
				[ 'back_link' => true ]
			);
		}

		// Update the user profile.
		$user_data = [
			'ID'           => $user->ID,
			'display_name' => $display_name,
		];

		$name_parts              = explode( ' ', $display_name, 2 );
		$user_data['first_name'] = $name_parts[0];
		$user_data['last_name']  = $name_parts[1] ?? '';

		\wp_update_user( $user_data );

		// Override the comment author so this comment uses the new name.
		$commentdata['comment_author'] = $display_name;

		return $commentdata;
	}

	/**
	 * Render the display name field before the comment form submit button.
	 *
	 * @param string $submit_field The submit field HTML.
	 * @return string The submit field HTML, with display name field prepended if needed.
	 */
	public static function render_display_name_field( $submit_field ) {
		if ( ! self::should_prompt() ) {
			return $submit_field;
		}

		$field = '<p class="comment-form-display-name">'
			. '<label for="comment_display_name">'
			. esc_html__( 'Name', 'newspack-plugin' )
			. ' <span class="required" aria-hidden="true">*</span>'
			. '</label>'
			. '<input id="comment_display_name" name="comment_display_name" type="text" required="required" style="display:block;width:100%" />'
			. '</p>';

		return $field . $submit_field;
	}
}
Comment_Display_Name::init();
