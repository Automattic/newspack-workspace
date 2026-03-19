<?php
/**
 * Agent Access @mentions — detect and notify when users are @mentioned in comments.
 *
 * @package Agent Access
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Agent_Access_Mentions {

	/**
	 * Regex pattern to match @mentions.
	 * Matches @username where username is 1-60 chars of letters, numbers, underscores, hyphens, or dots.
	 */
	const MENTION_PATTERN = '/@([a-zA-Z0-9_.\-]{1,60})\b/';

	/**
	 * Register hooks.
	 */
	public function init() {
		add_action( 'wp_insert_comment', array( $this, 'handle_new_comment' ), 10, 2 );
		add_filter( 'comment_text', array( $this, 'render_mentions' ), 20, 1 );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_styles' ) );
	}

	/**
	 * Enqueue front-end mention styles on singular pages with comments.
	 */
	public function enqueue_styles() {
		if ( is_singular() && comments_open() ) {
			wp_enqueue_style(
				'agent-access-mentions',
				AGENT_ACCESS_PLUGIN_URL . 'assets/css/mentions.css',
				array(),
				AGENT_ACCESS_VERSION
			);
		}
	}

	/**
	 * Handle a new comment — detect @mentions and notify mentioned users.
	 *
	 * @param int        $comment_id The comment ID.
	 * @param WP_Comment $comment    The comment object.
	 */
	public function handle_new_comment( $comment_id, $comment ) {
		// Skip spam and trash.
		if ( in_array( $comment->comment_approved, array( 'spam', 'trash' ), true ) ) {
			return;
		}

		$mentions = $this->extract_mentions( $comment->comment_content );
		if ( empty( $mentions ) ) {
			return;
		}

		// Store mentions as comment meta for potential future use.
		update_comment_meta( $comment_id, '_agent_access_mentions', $mentions );

		// Notify each mentioned user.
		foreach ( $mentions as $username ) {
			$user = get_user_by( 'login', $username );
			if ( ! $user ) {
				// Try display_name and nicename as fallbacks.
				$user = $this->find_user_fuzzy( $username );
			}
			if ( $user ) {
				$this->notify_user( $user, $comment );
			}
		}
	}

	/**
	 * Extract @mentions from comment text.
	 *
	 * @param string $text The comment content.
	 * @return string[] Array of unique usernames (without the @ prefix).
	 */
	public function extract_mentions( $text ) {
		if ( ! preg_match_all( self::MENTION_PATTERN, $text, $matches ) ) {
			return array();
		}

		return array_unique( array_map( 'strtolower', $matches[1] ) );
	}

	/**
	 * Try to find a user by display_name or user_nicename.
	 *
	 * @param string $name The name to search for.
	 * @return WP_User|false
	 */
	private function find_user_fuzzy( $name ) {
		// Try nicename (slug) first — most reliable after login.
		$user = get_user_by( 'slug', $name );
		if ( $user ) {
			return $user;
		}

		// Try display_name as a last resort.
		$users = get_users( array(
			'search'         => $name,
			'search_columns' => array( 'display_name' ),
			'number'         => 1,
		) );

		return ! empty( $users ) ? $users[0] : false;
	}

	/**
	 * Send an email notification to a mentioned user.
	 *
	 * @param WP_User    $user    The mentioned user.
	 * @param WP_Comment $comment The comment containing the mention.
	 */
	private function notify_user( $user, $comment ) {
		// Don't notify users about their own comments.
		if ( (int) $comment->user_id === (int) $user->ID ) {
			return;
		}

		/**
		 * Filter whether to send a mention notification.
		 *
		 * @param bool       $send    Whether to send the notification. Default true.
		 * @param WP_User    $user    The mentioned user.
		 * @param WP_Comment $comment The comment.
		 */
		if ( ! apply_filters( 'agent_access_send_mention_notification', true, $user, $comment ) ) {
			return;
		}

		$post    = get_post( $comment->comment_post_ID );
		$author  = sanitize_text_field( $comment->comment_author ?: __( 'Someone', 'agent-access' ) );
		$excerpt = wp_trim_words( wp_strip_all_tags( $comment->comment_content ), 40 );

		$post_title = $post ? sanitize_text_field( $post->post_title ) : __( 'a post', 'agent-access' );

		$subject = sprintf(
			/* translators: 1: comment author name, 2: post title */
			__( '%1$s mentioned you on "%2$s"', 'agent-access' ),
			$author,
			$post_title
		);

		$comment_url = get_comment_link( $comment );

		$message = sprintf(
			/* translators: 1: mentioned user display name, 2: comment author, 3: post title, 4: comment excerpt, 5: comment URL */
			__(
				"Hey %1\$s,\n\n%2\$s mentioned you in a comment on \"%3\$s\":\n\n\"%4\$s\"\n\nView the comment:\n%5\$s\n\n—\nAgent Access @ %6\$s",
				'agent-access'
			),
			$user->display_name,
			$author,
			$post_title,
			$excerpt,
			$comment_url,
			get_bloginfo( 'name' )
		);

		/**
		 * Fires when a user is @mentioned in a comment.
		 *
		 * Allows other plugins (or Agent Access webhooks) to act on mentions.
		 *
		 * @param WP_User    $user    The mentioned user.
		 * @param WP_Comment $comment The comment.
		 * @param WP_Post    $post    The post the comment is on.
		 */
		do_action( 'agent_access_user_mentioned', $user, $comment, $post );

		wp_mail( $user->user_email, $subject, $message );
	}

	/**
	 * Render @mentions as styled links in comment display.
	 *
	 * Uses a static cache to avoid repeated DB queries for the same username
	 * within a single request, and caps at 20 mentions per comment.
	 *
	 * @param string $text The comment text.
	 * @return string
	 */
	public function render_mentions( $text ) {
		static $user_cache = array();
		$count = 0;
		$max   = 20;

		return preg_replace_callback( self::MENTION_PATTERN, function ( $matches ) use ( &$user_cache, &$count, $max ) {
			if ( ++$count > $max ) {
				return $matches[0];
			}

			$username = strtolower( $matches[1] );

			if ( ! array_key_exists( $username, $user_cache ) ) {
				$user = get_user_by( 'login', $username );
				if ( ! $user ) {
					$user = $this->find_user_fuzzy( $username );
				}
				$user_cache[ $username ] = $user ?: null;
			}

			$user = $user_cache[ $username ];

			if ( $user ) {
				$profile_url = get_author_posts_url( $user->ID );
				return sprintf(
					'<a href="%s" class="agent-access-mention" title="@%s">@%s</a>',
					esc_url( $profile_url ),
					esc_attr( $user->user_login ),
					esc_html( $user->display_name )
				);
			}

			// Not a real user — render as styled but unlinked.
			return sprintf(
				'<span class="agent-access-mention agent-access-mention--unknown">@%s</span>',
				esc_html( $matches[1] )
			);
		}, $text );
	}
}
