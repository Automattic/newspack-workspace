<?php
/**
 * Mock of the newspack-plugin Newsletters_Access class.
 *
 * The account-param arrival handler requires a valid newsletter pass on the
 * same link before it resolves an account (verify()), and strips the pass
 * unless will_consume_inbound_pass() says the handler will consume it.
 *
 * @package Newspack_Popups
 */

namespace Newspack;

if ( ! class_exists( __NAMESPACE__ . '\Newsletters_Access' ) ) {
	/**
	 * Minimal stand-in for the newsletter-pass verifier.
	 */
	class Newsletters_Access {
		/**
		 * Passes that verify, mapped to the newsletter ID they sign.
		 *
		 * @var array<string, int>
		 */
		public static $valid_passes = [];

		/**
		 * Overrides will_consume_inbound_pass() when not null.
		 *
		 * @var bool|null
		 */
		public static $will_consume = null;

		/**
		 * Whether the inbound handler will consume the pass: the override if
		 * set, else whether it is hooked and doesn't skip the current user, as
		 * in newspack-plugin.
		 *
		 * @return bool
		 */
		public static function will_consume_inbound_pass() {
			if ( null !== self::$will_consume ) {
				return self::$will_consume;
			}
			return false !== has_action( 'init', [ __CLASS__, 'handle_inbound_request_action' ] )
				&& ! ( is_user_logged_in() && current_user_can( 'edit_others_posts' ) );
		}

		/**
		 * Verify a newsletter pass.
		 *
		 * @param string $token Pass from the npnl query param.
		 * @return array|false
		 */
		public static function verify( $token ) {
			if ( ! is_string( $token ) || ! isset( self::$valid_passes[ $token ] ) ) {
				return false;
			}
			return [
				'newsletter_id' => self::$valid_passes[ $token ],
				'sent_at'       => time(),
			];
		}

		/**
		 * Inbound pass handler. Never runs in tests; only its registration on
		 * `init` matters, which tells the arrival handler the pass is consumed.
		 */
		public static function handle_inbound_request_action() {}
	}
}
