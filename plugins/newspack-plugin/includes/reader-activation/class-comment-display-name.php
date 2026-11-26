<?php
/**
 * Comment Display Name — requires readers with auto-generated display names
 * to choose a proper display name before commenting. Publishers can raise that
 * to a first and last name, which readers must have and comment under.
 *
 * @package Newspack
 */

namespace Newspack\Reader_Activation;

use Newspack\Content_Gate_Advanced_Settings;
use Newspack\Reader_Activation;

defined( 'ABSPATH' ) || exit;

/**
 * Comment Display Name class.
 */
final class Comment_Display_Name {

	/**
	 * Error code for a REST comment from a reader who needs to give a name first.
	 */
	const ERROR_CODE = 'newspack_comment_name_required';

	/**
	 * User meta recording that a reader gave or confirmed their first and last name
	 * for their comments.
	 */
	const NAME_CONFIRMED_META = 'np_comment_full_name_confirmed';

	/**
	 * Initialize hooks.
	 */
	public static function init() {
		\add_filter( 'comment_form_submit_field', [ __CLASS__, 'render_display_name_field' ] );
		\add_filter( 'preprocess_comment', [ __CLASS__, 'validate_display_name' ] );
		\add_filter( 'rest_preprocess_comment', [ __CLASS__, 'refuse_rest_comment' ], 10, 2 );
		\add_filter( 'rest_pre_insert_comment', [ __CLASS__, 'use_full_name_on_rest_comment' ], 10, 2 );
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
	 * Whether the publisher requires a first and last name to comment.
	 *
	 * @return bool
	 */
	public static function is_full_name_required(): bool {
		return ! empty( Content_Gate_Advanced_Settings::get_settings()['comment_require_full_name'] );
	}

	/**
	 * Whether the first and last name requirement applies to the current user on a post.
	 *
	 * Product pages are left alone: their form collects reviews, not comments.
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	private static function requires_full_name( int $post_id ): bool {
		if ( ! self::is_full_name_required() || ! \is_user_logged_in() || 'product' === \get_post_type( $post_id ) ) {
			return false;
		}
		return Reader_Activation::is_user_reader( \wp_get_current_user() );
	}

	/**
	 * A user's first and last name.
	 *
	 * @param \WP_User $user User.
	 * @return string The full name; empty unless the profile has both.
	 */
	private static function get_full_name( \WP_User $user ): string {
		$first_name = trim( $user->first_name );
		$last_name  = trim( $user->last_name );
		return '' !== $first_name && '' !== $last_name ? $first_name . ' ' . $last_name : '';
	}

	/**
	 * Whether the current user has to give or confirm their first and last name.
	 *
	 * Names on a profile can come from elsewhere, such as billing details. A reader
	 * with another public name is asked once, so that their comments don't change
	 * to a name they never chose to publish.
	 *
	 * @return bool
	 */
	private static function needs_name_fields(): bool {
		$user      = \wp_get_current_user();
		$full_name = self::get_full_name( $user );
		if ( '' === $full_name ) {
			return true;
		}
		return $full_name !== $user->display_name
			&& ! self::should_prompt()
			&& ! \get_user_meta( $user->ID, self::NAME_CONFIRMED_META, true );
	}

	/**
	 * Validate the submitted first and last name and save them to the profile.
	 *
	 * Replaces the display name only when it is email-derived. A name the reader
	 * chose stays on their profile for everything but their comments.
	 *
	 * @return string The saved full name.
	 */
	private static function save_required_name(): string {
		$first_name = isset( $_POST['comment_first_name'] ) ? \sanitize_text_field( \wp_unslash( $_POST['comment_first_name'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$last_name  = isset( $_POST['comment_last_name'] ) ? \sanitize_text_field( \wp_unslash( $_POST['comment_last_name'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing

		// empty() rather than '': wp_update_user() drops a name of "0", so it could never be saved.
		if ( empty( $first_name ) || empty( $last_name ) ) {
			\wp_die(
				esc_html__( 'Please enter your first and last name.', 'newspack-plugin' ),
				esc_html__( 'Comment Submission Failure', 'newspack-plugin' ),
				[
					'response'  => 403,
					'back_link' => true,
				]
			);
		}

		$full_name = $first_name . ' ' . $last_name;

		// Comment authors are capped in bytes, and core checks the cap before the name is swapped in.
		if ( strlen( $full_name ) > \wp_get_comment_fields_max_lengths()['comment_author'] ) {
			\wp_die(
				esc_html__( 'Please enter a shorter name.', 'newspack-plugin' ),
				esc_html__( 'Comment Submission Failure', 'newspack-plugin' ),
				[
					'response'  => 403,
					'back_link' => true,
				]
			);
		}

		$user_data = [
			'ID'         => \get_current_user_id(),
			'first_name' => $first_name,
			'last_name'  => $last_name,
		];

		if ( self::should_prompt() ) {
			$user_data['display_name'] = $full_name;
		}

		// wp_update_user() unslashes what it saves, so a name like "\\" would otherwise be lost.
		if ( \is_wp_error( \wp_update_user( \wp_slash( $user_data ) ) ) ) {
			\wp_die(
				esc_html__( 'Your name could not be saved. Please try again.', 'newspack-plugin' ),
				esc_html__( 'Comment Submission Failure', 'newspack-plugin' ),
				[
					'response'  => 500,
					'back_link' => true,
				]
			);
		}

		\update_user_meta( $user_data['ID'], self::NAME_CONFIRMED_META, true );

		return $full_name;
	}

	/**
	 * Validate and save the reader's name before a comment is inserted: the first
	 * and last name where the site requires them, which the comment is then published
	 * under, otherwise the display name.
	 *
	 * Runs on `preprocess_comment` so the updated name is used as the comment
	 * author, rather than the name WordPress read from the user profile earlier
	 * in the request.
	 *
	 * @param array $commentdata Comment data.
	 * @return array Comment data with updated comment_author.
	 */
	public static function validate_display_name( $commentdata ) {
		if ( self::requires_full_name( (int) ( $commentdata['comment_post_ID'] ?? 0 ) ) ) {
			$full_name = self::needs_name_fields() ? self::save_required_name() : self::get_full_name( \wp_get_current_user() );

			$commentdata['comment_author'] = \wp_slash( $full_name );
			return $commentdata;
		}

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
		if ( Reader_Activation::is_display_name_derived_from_email( $display_name, $email ) ) {
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

		// Where both names are required, a one-word display name must not clear a
		// last name the reader has already given.
		if ( ! self::is_full_name_required() || '' === self::get_full_name( $user ) ) {
			$name_parts              = explode( ' ', $display_name, 2 );
			$user_data['first_name'] = $name_parts[0];
			$user_data['last_name']  = $name_parts[1] ?? '';
		}

		\wp_update_user( $user_data );

		// Override the comment author so this comment uses the new name.
		$commentdata['comment_author'] = $display_name;

		return $commentdata;
	}

	/**
	 * Render the name fields the reader needs before the comment form submit button.
	 *
	 * @param string $submit_field The submit field HTML.
	 * @return string The submit field HTML, with any name fields or notice prepended.
	 */
	public static function render_display_name_field( $submit_field ) {
		if ( self::requires_full_name( (int) \get_the_ID() ) ) {
			$full_name = self::get_full_name( \wp_get_current_user() );
			if ( self::needs_name_fields() ) {
				$notice = '<p class="comment-notes comment-form-published-name">' . esc_html__( 'New comments show the first and last name below. Check both before posting.', 'newspack-plugin' ) . '</p>';
				return $notice . self::render_required_name_fields() . $submit_field;
			}
			// The name on the comment can differ from the one the account shows, so the reader sees it before posting.
			/* translators: %s: reader's first and last name. */
			$notice = sprintf( __( 'Your comment will show as %s.', 'newspack-plugin' ), $full_name );
			return '<p class="comment-notes comment-form-published-name">' . esc_html( $notice ) . '</p>' . $submit_field;
		}

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

	/**
	 * Render the first and last name fields, filled with what the profile has.
	 *
	 * @return string The fields' HTML.
	 */
	private static function render_required_name_fields(): string {
		$user   = \wp_get_current_user();
		$fields = [
			'comment_first_name' => [ __( 'First name', 'newspack-plugin' ), 'given-name', $user->first_name ],
			'comment_last_name'  => [ __( 'Last name', 'newspack-plugin' ), 'family-name', $user->last_name ],
		];

		$html = '';
		foreach ( $fields as $name => list( $label, $autocomplete, $value ) ) {
			$html .= '<p class="comment-form-required-name">'
				. '<label for="' . esc_attr( $name ) . '">'
				. esc_html( $label )
				. ' <span class="required" aria-hidden="true">*</span>'
				. '</label>'
				. '<input id="' . esc_attr( $name ) . '" name="' . esc_attr( $name ) . '" type="text" maxlength="120" autocomplete="' . esc_attr( $autocomplete ) . '" value="' . esc_attr( $value ) . '" required="required" style="display:block;width:100%" />'
				. '</p>';
		}
		return $html;
	}

	/**
	 * Refuse a comment created through the REST API by a reader the comment form
	 * would ask for a name. REST inserts comments without `preprocess_comment`, and
	 * it has no form to collect the name from.
	 *
	 * Editor notes are not published, so they need no name. The type comes from the
	 * request because core adds it to the prepared comment after this filter runs.
	 *
	 * @param array|\WP_Error  $prepared_comment Prepared comment.
	 * @param \WP_REST_Request $request          Request.
	 * @return array|\WP_Error
	 */
	public static function refuse_rest_comment( array|\WP_Error $prepared_comment, \WP_REST_Request $request ): array|\WP_Error {
		// The same filter runs when a comment is updated; only creation needs the name.
		if ( \is_wp_error( $prepared_comment ) || ! empty( $request['id'] ) || 'note' === $request['type'] ) {
			return $prepared_comment;
		}
		if ( self::requires_full_name( (int) ( $prepared_comment['comment_post_ID'] ?? 0 ) ) ) {
			if ( self::needs_name_fields() ) {
				return new \WP_Error( self::ERROR_CODE, __( 'Add or confirm your first and last name to comment.', 'newspack-plugin' ), [ 'status' => 403 ] );
			}
			return $prepared_comment;
		}
		if ( self::should_prompt() ) {
			return new \WP_Error( self::ERROR_CODE, __( 'Add a display name to comment.', 'newspack-plugin' ), [ 'status' => 403 ] );
		}
		return $prepared_comment;
	}

	/**
	 * Publish a REST comment under the first and last name where the site requires them.
	 *
	 * Runs on `rest_pre_insert_comment` because core fills in the author after
	 * `rest_preprocess_comment`, and only when that filter left the author empty.
	 *
	 * @param array|\WP_Error  $prepared_comment Prepared comment.
	 * @param \WP_REST_Request $request          Request.
	 * @return array|\WP_Error
	 */
	public static function use_full_name_on_rest_comment( array|\WP_Error $prepared_comment, \WP_REST_Request $request ): array|\WP_Error {
		if ( \is_wp_error( $prepared_comment ) || 'note' === $request['type'] || ! self::requires_full_name( (int) ( $prepared_comment['comment_post_ID'] ?? 0 ) ) ) {
			return $prepared_comment;
		}
		$full_name = self::get_full_name( \wp_get_current_user() );
		if ( '' !== $full_name ) {
			$prepared_comment['comment_author'] = $full_name;
		}
		return $prepared_comment;
	}
}
Comment_Display_Name::init();
