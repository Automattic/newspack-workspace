<?php
/**
 * Site-wide comment restriction keyed on gate access.
 *
 * @package Newspack
 */

namespace Newspack;

defined( 'ABSPATH' ) || exit;

/**
 * Lets a publisher make commenting a benefit of access, on every post rather
 * than only on the posts a gate restricts. The Access Control advanced settings
 * name a gate; a reader who passes that gate's access rules may comment, and
 * everyone else sees a message and a purchase link where the comment form was.
 *
 * "Passes" is the verdict {@see Content_Restriction_Control::evaluate_gate()}
 * gives for gated content, so group seat-holders, payment-recovery grace and
 * every other access rule count here exactly as they do on a restricted post.
 *
 * Covers reader comments only. Pingbacks, trackbacks, editor notes and
 * WooCommerce product reviews keep the rules they have without this setting.
 *
 * Unset by default, which leaves commenting to the Discussion Settings.
 */
class Comment_Restriction {

	/**
	 * Error code for a refused comment submission.
	 */
	const ERROR_CODE = 'newspack_comment_restricted';

	/**
	 * Per-request memo of the verdict. The comment form asks several times per render.
	 *
	 * @var array<int, bool> Keyed by user ID.
	 */
	private static array $verdicts = [];

	/**
	 * Initialize hooks.
	 */
	public static function init(): void {
		add_filter( 'comment_form_defaults', [ __CLASS__, 'filter_comment_form_defaults' ] );
		add_filter( 'comment_form_fields', [ __CLASS__, 'filter_comment_form_fields' ] );
		// Late, so it also replaces what other callbacks add to the field, such as Comment_Display_Name's prompt.
		add_filter( 'comment_form_submit_field', [ __CLASS__, 'filter_comment_form_submit_field' ], 99 );
		add_filter( 'comment_reply_link', [ __CLASS__, 'filter_comment_reply_link' ] );
		add_filter( 'pre_comment_approved', [ __CLASS__, 'refuse_comment_submission' ], 10, 2 );
		add_filter( 'rest_preprocess_comment', [ __CLASS__, 'refuse_rest_comment' ], 10, 2 );
	}

	/**
	 * The configured restriction, or null when commenting is not restricted.
	 *
	 * @return array{gate_id: int, message: string, purchase_url: string}|null
	 */
	public static function get_config(): ?array {
		$settings = Content_Gate_Advanced_Settings::get_settings();
		$gate_id  = (int) ( $settings['comment_restriction_gate_id'] ?? 0 );
		if ( ! $gate_id ) {
			return null;
		}
		$message = (string) ( $settings['comment_restriction_message'] ?? '' );
		return [
			'gate_id'      => $gate_id,
			'message'      => '' !== $message ? $message : self::get_default_message(),
			'purchase_url' => (string) ( $settings['comment_restriction_purchase_url'] ?? '' ),
		];
	}

	/**
	 * The message shown when the publisher leaves it empty.
	 *
	 * @return string
	 */
	public static function get_default_message(): string {
		return __( 'Only subscribers may post a comment.', 'newspack-plugin' );
	}

	/**
	 * Whether a user is refused commenting.
	 *
	 * The configured gate counts only while it is published, as it does for
	 * content. A gate that is unpublished, trashed or deleted refuses every
	 * reader: reopening commenting to all of them would quietly undo what the
	 * publisher chose, while a closed form is noticed and fixed in the settings.
	 *
	 * @param int $user_id User ID; 0 for a logged-out visitor.
	 *
	 * @return bool
	 */
	public static function is_restricted_for_user( int $user_id ): bool {
		if ( isset( self::$verdicts[ $user_id ] ) ) {
			return self::$verdicts[ $user_id ];
		}

		$config = self::get_config();
		if ( null === $config ) {
			$is_restricted = false;
		} elseif ( Memberships::is_active() || ! Content_Gate::is_gating_active() ) {
			// Access Control is not the gating engine on this site, so its gates decide nothing.
			$is_restricted = false;
		} elseif ( $user_id && user_can( $user_id, 'edit_posts' ) ) {
			// Anyone who writes for the site comments regardless, as the Memberships-based module allows.
			$is_restricted = false;
		} else {
			$gate          = Content_Gate::GATE_CPT === get_post_type( $config['gate_id'] ) ? Content_Gate::get_gate( $config['gate_id'] ) : null;
			$is_restricted = ! is_array( $gate ) || 'publish' !== $gate['status'] || Content_Restriction_Control::evaluate_gate( $gate, $user_id )['restricted'];
		}

		self::$verdicts[ $user_id ] = $is_restricted;
		return $is_restricted;
	}

	/**
	 * Reset the per-request memo.
	 */
	public static function reset_cache(): void {
		self::$verdicts = [];
	}

	/**
	 * Whether the comment form being rendered is refused to the current user.
	 *
	 * Product pages are left alone: their form collects reviews, not comments.
	 *
	 * @return bool
	 */
	private static function is_form_restricted(): bool {
		return 'product' !== get_post_type() && self::is_restricted_for_user( get_current_user_id() );
	}

	/**
	 * The message and purchase link, as shown in place of the comment form.
	 *
	 * @return string HTML.
	 */
	private static function get_message_html(): string {
		$config  = self::get_config();
		$message = esc_html( $config['message'] );
		if ( ! is_user_logged_in() ) {
			$message .= ' ' . wp_kses(
				/* translators: %s: sign in link URL */
				sprintf( __( 'Already a subscriber? <a href="%s">Sign in</a>.', 'newspack-plugin' ), '#signin_modal' ),
				[ 'a' => [ 'href' => [] ] ]
			);
		}
		if ( ! empty( $config['purchase_url'] ) ) {
			$message .= ' ' . sprintf(
				'<a href="%s">%s</a>',
				esc_url( $config['purchase_url'] ),
				esc_html__( 'Subscribe now', 'newspack-plugin' )
			);
		}
		return sprintf( '<p class="newspack-comment-restriction must-log-in">%s</p>', $message );
	}

	/**
	 * Replace the notices around the form: the "must log in" notice a logged-out
	 * visitor sees, and the "logged in as" line, which introduces fields that
	 * are not there.
	 *
	 * @param array $defaults Comment form defaults.
	 *
	 * @return array
	 */
	public static function filter_comment_form_defaults( $defaults ) {
		if ( ! self::is_form_restricted() ) {
			return $defaults;
		}
		$defaults['comment_notes_before'] = '';
		$defaults['comment_notes_after']  = '';
		$defaults['logged_in_as']         = '';
		$defaults['must_log_in']          = self::get_message_html();
		return $defaults;
	}

	/**
	 * Drop every field, the comment textarea included.
	 *
	 * @param array $fields Comment form fields.
	 *
	 * @return array
	 */
	public static function filter_comment_form_fields( $fields ) {
		return self::is_form_restricted() ? [] : $fields;
	}

	/**
	 * Show the message where the submit button was.
	 *
	 * @param string $submit_field Submit field markup.
	 *
	 * @return string
	 */
	public static function filter_comment_form_submit_field( $submit_field ) {
		return self::is_form_restricted() ? self::get_message_html() : $submit_field;
	}

	/**
	 * Hide "Reply" on existing comments, which would only move the message.
	 *
	 * @param string $link Reply link markup.
	 *
	 * @return string
	 */
	public static function filter_comment_reply_link( $link ) {
		return self::is_form_restricted() ? '' : $link;
	}

	/**
	 * Refuse a comment submitted through wp-comments-post.php or XML-RPC.
	 *
	 * Both reach wp_allow_comment(), which returns this error to the caller. The
	 * verdict is the comment author's, so a background job creating a comment
	 * on a reader's behalf is judged as that reader. Cron and WP-CLI are not
	 * reader submissions and pass through.
	 *
	 * @param int|string|\WP_Error $approved    Approval status.
	 * @param array                $commentdata Comment data.
	 *
	 * @return int|string|\WP_Error
	 */
	public static function refuse_comment_submission( $approved, $commentdata ) {
		if ( is_wp_error( $approved ) || wp_doing_cron() || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			return $approved;
		}
		// Staff may post on anyone's behalf, such as a reply from the comments screen.
		if ( current_user_can( 'edit_posts' ) ) {
			return $approved;
		}
		if ( ! self::is_reader_comment_type( $commentdata['comment_type'] ?? '' ) ) {
			return $approved;
		}
		$author_id = isset( $commentdata['user_id'] ) ? (int) $commentdata['user_id'] : get_current_user_id();
		if ( ! self::is_restricted_for_user( $author_id ) ) {
			return $approved;
		}
		// wp-comments-post.php reads the error data as the HTTP status.
		return new \WP_Error( self::ERROR_CODE, self::get_config()['message'], 403 );
	}

	/**
	 * Refuse a comment created through the REST API.
	 *
	 * Refused here, before the controller calls wp_allow_comment(), because the
	 * controller passes that function's errors through and REST reads the status
	 * from an array the submission path above cannot use.
	 *
	 * @param array|\WP_Error  $prepared_comment Prepared comment.
	 * @param \WP_REST_Request $request          Request.
	 *
	 * @return array|\WP_Error
	 */
	public static function refuse_rest_comment( $prepared_comment, $request ) {
		// The same filter runs when a comment is updated; only creation is restricted.
		if ( is_wp_error( $prepared_comment ) || ! empty( $request['id'] ) ) {
			return $prepared_comment;
		}
		if ( ! self::is_reader_comment_type( (string) ( $request['type'] ?? '' ) ) ) {
			return $prepared_comment;
		}
		if ( ! self::is_restricted_for_user( get_current_user_id() ) ) {
			return $prepared_comment;
		}
		return new \WP_Error( self::ERROR_CODE, self::get_config()['message'], [ 'status' => 403 ] );
	}

	/**
	 * Whether a comment type is a reader comment, the only kind this restricts.
	 *
	 * @param string $comment_type Comment type; empty for a plain comment.
	 *
	 * @return bool
	 */
	private static function is_reader_comment_type( string $comment_type ): bool {
		return in_array( $comment_type, [ '', 'comment' ], true );
	}
}
Comment_Restriction::init();
