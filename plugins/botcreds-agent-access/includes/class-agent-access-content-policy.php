<?php
/**
 * Content Policy — govern what agents can publish and how much they can create.
 *
 * Hooks rest_pre_insert_post / rest_pre_insert_attachment to intercept the
 * prepared post object before it is written to the database.  This lets the
 * policy silently downgrade a 'publish' status to 'draft' or 'pending', and
 * block creation once a daily post cap is reached.
 *
 * Post counts are tracked via transients keyed to the credential UUID, not the
 * user, so separate credentials for the same user are governed independently.
 *
 * Predefined tiers:
 *   strict   — publish → pending review; max 10 posts/day
 *   standard — publish → draft (default); unlimited
 *   open     — publish allowed; unlimited
 *
 * @package BotCreds Agent Access
 * @since   2.5.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Agent_Access_Content_Policy {

	const META_PREFIX    = '_agent_access_cp_';
	const DEFAULT_POLICY = 'standard';

	/**
	 * Register hooks.
	 */
	public static function init() {
		// Intercept before post / attachment is written to DB.
		add_filter( 'rest_pre_insert_post',       array( __CLASS__, 'apply' ), 10, 2 );
		add_filter( 'rest_pre_insert_attachment',  array( __CLASS__, 'apply' ), 10, 2 );

		// Count new posts AFTER successful creation (only real creates, not updates).
		add_action( 'rest_after_insert_post',       array( __CLASS__, 'record_creation' ), 10, 3 );
		add_action( 'rest_after_insert_attachment', array( __CLASS__, 'record_creation' ), 10, 3 );
	}

	// ──────────────────────────────────────────────
	// Policy enforcement
	// ──────────────────────────────────────────────

	/**
	 * Apply content policy to a prepared post object.
	 *
	 * Called by rest_pre_insert_{post_type} — returning WP_Error aborts the write.
	 *
	 * @param stdClass        $prepared_post
	 * @param WP_REST_Request $request
	 * @return stdClass|WP_Error
	 */
	public static function apply( $prepared_post, $request ) {
		// Only govern Agent Access credentials.
		$source = Agent_Access_Activity_Log::detect_source();
		if ( null === $source || Agent_Access_Activity_Log::SOURCE_AA !== $source['source'] ) {
			return $prepared_post;
		}

		$uuid = function_exists( 'rest_get_authenticated_app_password' )
			? rest_get_authenticated_app_password()
			: null;

		if ( ! $uuid ) {
			return $prepared_post;
		}

		$user_id    = get_current_user_id();
		$policy_key = self::get( $user_id, $uuid );
		$policy     = self::get_policy( $policy_key );

		if ( ! $policy ) {
			return $prepared_post;
		}

		$is_create = empty( $prepared_post->ID );

		// ── Daily post cap (new posts only) ────────
		if ( $is_create && $policy['max_daily_posts'] > 0 ) {
			$today_count = self::get_daily_count( $uuid );
			if ( $today_count >= $policy['max_daily_posts'] ) {
				return new WP_Error(
					'agent_daily_post_limit',
					sprintf(
						/* translators: %d: daily post limit */
						__( 'This agent has reached its daily post limit of %d. Try again tomorrow.', 'botcreds-agent-access' ),
						$policy['max_daily_posts']
					),
					array( 'status' => 429 )
				);
			}
		}

		// ── Publish gating ─────────────────────────
		if ( 'allow' !== $policy['publish_gating'] ) {
			if ( isset( $prepared_post->post_status ) && 'publish' === $prepared_post->post_status ) {
				$prepared_post->post_status = $policy['publish_gating']; // 'draft' | 'pending'
			}
		}

		return $prepared_post;
	}

	/**
	 * Increment the daily creation counter after a successful REST insert.
	 *
	 * @param WP_Post         $post
	 * @param WP_REST_Request $request
	 * @param bool            $creating True on create, false on update.
	 */
	public static function record_creation( $post, $request, $creating ) {
		if ( ! $creating ) {
			return;
		}

		$source = Agent_Access_Activity_Log::detect_source();
		if ( null === $source || Agent_Access_Activity_Log::SOURCE_AA !== $source['source'] ) {
			return;
		}

		$uuid = function_exists( 'rest_get_authenticated_app_password' )
			? rest_get_authenticated_app_password()
			: null;

		if ( $uuid ) {
			self::increment_daily_count( $uuid );
		}
	}

	// ──────────────────────────────────────────────
	// Daily count helpers
	// ──────────────────────────────────────────────

	/**
	 * Transient key for the current UTC day + uuid.
	 *
	 * @param string $uuid
	 * @return string
	 */
	private static function daily_key( $uuid ) {
		return 'aa_cp_' . substr( md5( $uuid ), 0, 12 ) . '_' . gmdate( 'Ymd' );
	}

	/**
	 * Get the number of posts created by this credential today.
	 *
	 * @param string $uuid
	 * @return int
	 */
	public static function get_daily_count( $uuid ) {
		return (int) get_transient( self::daily_key( $uuid ) );
	}

	/**
	 * Increment today's creation counter. TTL = rest of day + 5 min buffer.
	 *
	 * @param string $uuid
	 */
	private static function increment_daily_count( $uuid ) {
		$key   = self::daily_key( $uuid );
		$count = (int) get_transient( $key );
		$ttl   = strtotime( 'tomorrow' ) - time() + 300;
		set_transient( $key, $count + 1, $ttl );
	}

	// ──────────────────────────────────────────────
	// Policy storage
	// ──────────────────────────────────────────────

	/**
	 * Save a content policy tier for a credential.
	 *
	 * @param int    $user_id
	 * @param string $uuid
	 * @param string $policy_key
	 */
	public static function save( $user_id, $uuid, $policy_key ) {
		$allowed = array_keys( self::get_policies() );
		if ( ! in_array( $policy_key, $allowed, true ) ) {
			$policy_key = self::DEFAULT_POLICY;
		}
		update_user_meta( (int) $user_id, self::META_PREFIX . $uuid, $policy_key );
	}

	/**
	 * Get the saved policy key for a user+UUID pair.
	 *
	 * @param int    $user_id
	 * @param string $uuid
	 * @return string
	 */
	public static function get( $user_id, $uuid ) {
		$key = get_user_meta( (int) $user_id, self::META_PREFIX . $uuid, true );
		return $key ?: self::DEFAULT_POLICY;
	}

	/**
	 * Delete policy meta when a credential is revoked.
	 *
	 * @param int    $user_id
	 * @param string $uuid
	 */
	public static function delete( $user_id, $uuid ) {
		delete_user_meta( (int) $user_id, self::META_PREFIX . $uuid );
	}

	// ──────────────────────────────────────────────
	// Policy definitions
	// ──────────────────────────────────────────────

	/**
	 * Return all content policy tiers.
	 *
	 * publish_gating: 'allow' | 'draft' | 'pending'
	 * max_daily_posts: 0 = unlimited
	 *
	 * @return array
	 */
	public static function get_policies() {
		return array(
			'strict'   => array(
				'label'           => __( 'Strict (pending review, 10/day)', 'botcreds-agent-access' ),
				'description'     => __( 'Agent posts go to Pending Review. Max 10 posts per day. Good for untrusted or new agents.', 'botcreds-agent-access' ),
				'publish_gating'  => 'pending',
				'max_daily_posts' => 10,
			),
			'standard' => array(
				'label'           => __( 'Standard (draft, unlimited)', 'botcreds-agent-access' ),
				'description'     => __( 'Agent posts are saved as drafts. A human publishes. Recommended default.', 'botcreds-agent-access' ),
				'publish_gating'  => 'draft',
				'max_daily_posts' => 0,
			),
			'open'     => array(
				'label'           => __( 'Open (publish allowed)', 'botcreds-agent-access' ),
				'description'     => __( 'Agent may publish directly. Use only for fully trusted, supervised agents.', 'botcreds-agent-access' ),
				'publish_gating'  => 'allow',
				'max_daily_posts' => 0,
			),
		);
	}

	/**
	 * Return a single policy by key, or null.
	 *
	 * @param string $key
	 * @return array|null
	 */
	public static function get_policy( $key ) {
		$policies = self::get_policies();
		return $policies[ $key ] ?? null;
	}

	/**
	 * Return the display label for a policy key.
	 *
	 * @param string $key
	 * @return string
	 */
	public static function get_label( $key ) {
		$policy = self::get_policy( $key );
		return $policy ? $policy['label'] : ucfirst( $key );
	}
}
