<?php
/**
 * Newspack Member Body Class.
 *
 * @package Newspack
 */

namespace Newspack;

defined( 'ABSPATH' ) || exit;

/**
 * Adds the `member-logged-in` body class for readers with paid access.
 *
 * WooCommerce Memberships adds this class for its members, and third-party tools key on
 * it: an ad vendor's placement rules, for example, can skip every page that carries it.
 * Those rules often live in the vendor's own settings rather than on the site, so without
 * an equivalent they stop recognizing members the day a site switches from Memberships to
 * Access Control (NPPD-2397).
 */
final class Member_Body_Class {

	/**
	 * The class name, unchanged from Memberships so existing consumers keep working.
	 */
	const CLASS_NAME = 'member-logged-in';

	/**
	 * Object cache group for per-reader results.
	 */
	const CACHE_GROUP = 'newspack_member_body_class';

	/**
	 * Initialize hooks.
	 */
	public static function init() {
		add_filter( 'body_class', [ __CLASS__, 'filter_body_class' ] );
		add_action( 'woocommerce_subscription_status_updated', [ __CLASS__, 'clear_cache_for_subscription' ] );
		add_action( 'woocommerce_order_status_changed', [ __CLASS__, 'clear_cache_for_order' ], 10, 4 );
	}

	/**
	 * Add the class for a logged-in reader with paid access.
	 *
	 * Stands down while Memberships is active, since Memberships adds the class itself.
	 * Logged-out visitors return before any lookup, and logged-in readers bypass the page
	 * cache, so a cached page never carries another reader's class.
	 *
	 * @param string[] $classes Body classes.
	 * @return string[]
	 */
	public static function filter_body_class( $classes ) {
		if ( Memberships::is_active() || ! Content_Gate::is_gating_active() || ! is_user_logged_in() ) {
			return $classes;
		}
		if ( in_array( self::CLASS_NAME, $classes, true ) ) {
			return $classes;
		}
		if ( self::user_has_paid_access( get_current_user_id() ) ) {
			$classes[] = self::CLASS_NAME;
		}
		return $classes;
	}

	/**
	 * Re-check a reader as soon as their subscription changes status, rather than waiting
	 * out the cache, so a new subscriber gets the class and a lapsed one loses it.
	 *
	 * @param \WC_Subscription $subscription Subscription.
	 */
	public static function clear_cache_for_subscription( $subscription ) {
		self::clear_cache_for_customer_of( $subscription );
	}

	/**
	 * Re-check a reader when one of their orders changes status, so a one-time purchase
	 * counts at once.
	 *
	 * @param int       $order_id    Order ID.
	 * @param string    $status_from Previous status.
	 * @param string    $status_to   New status.
	 * @param \WC_Order $order       Order.
	 */
	public static function clear_cache_for_order( $order_id, $status_from, $status_to, $order ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundBeforeLastUsed
		self::clear_cache_for_customer_of( $order );
	}

	/**
	 * Drop the cached result for the customer an order or subscription belongs to.
	 *
	 * @param \WC_Order|\WC_Subscription $order_or_subscription Order or subscription.
	 */
	private static function clear_cache_for_customer_of( $order_or_subscription ) {
		if ( is_object( $order_or_subscription ) && method_exists( $order_or_subscription, 'get_customer_id' ) ) {
			wp_cache_delete( (int) $order_or_subscription->get_customer_id(), self::CACHE_GROUP );
		}
	}

	/**
	 * Whether a reader has paid access, cached per reader.
	 *
	 * Staff who can edit others' posts count, as admins did under Memberships: Access
	 * Control never restricts them.
	 *
	 * @param int $user_id User ID.
	 * @return bool
	 */
	private static function user_has_paid_access( $user_id ) {
		$cached = wp_cache_get( $user_id, self::CACHE_GROUP );
		if ( false !== $cached ) {
			return 'yes' === $cached;
		}
		$has_access = user_can( $user_id, 'edit_others_posts' ) || self::passes_any_gate( $user_id );
		// The hooks above don't catch every change, so this TTL caps how long the rest take
		// to reach the class, such as gate edits, gift recipients and group members (whose
		// access follows someone else's subscription), a one-time purchase's access window
		// closing, email verification, reader data synced from an ESP, and institution grants
		// that depend on the reader's network.
		wp_cache_set( $user_id, $has_access ? 'yes' : 'no', self::CACHE_GROUP, 10 * MINUTE_IN_SECONDS );
		return $has_access;
	}

	/**
	 * Whether a reader passes the paid-access rules of any published, non-newsletter gate.
	 *
	 * Asks about the reader, not the page, so the answer holds site-wide, as the Memberships
	 * class did. Rules go through the same evaluator and payment-recovery setting as
	 * Content_Restriction_Control::is_post_restricted(), but the answer can differ from what
	 * the reader may read on a given post: any passing gate counts here, not only the one
	 * that decides for that post, registration settings aren't consulted, and rule groups
	 * that can't tell readers apart are left out.
	 *
	 * @param int $user_id User ID.
	 * @return bool
	 */
	private static function passes_any_gate( $user_id ) {
		foreach ( Content_Gate::get_gates( Content_Gate::GATE_CPT, 'publish' ) as $gate ) {
			$custom_access = $gate['custom_access'] ?? [];
			$rule_groups   = self::get_distinguishing_rule_groups( $custom_access['access_rules'] ?? [] );
			if ( empty( $custom_access['active'] ) || empty( $rule_groups ) ) {
				continue;
			}
			$rule_context = [ 'payment_recovery_grace' => $custom_access['payment_recovery_grace'] ?? true ];
			if ( Access_Rules::evaluate_rules_for_visitor( $rule_groups, $user_id, $rule_context ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * A gate's rule groups without the rules that pass every signed-in reader unchecked.
	 *
	 * Content gating lets a signed-in reader through a rule the site no longer registers
	 * (a promoted field whose integration was switched off), a rule with no slug, an empty
	 * group, or a blank rule whose empty value grants access. There that opens one gate's
	 * posts; here it would mark every signed-in reader a member on every page. So those
	 * rules are dropped, and a group left with none no longer counts.
	 *
	 * @see Access_Rules::evaluate_rules_for_visitor() for why content gating passes these.
	 *
	 * @param array $access_rules The gate's access rules.
	 * @return array[] Rule groups in grouped format.
	 */
	private static function get_distinguishing_rule_groups( $access_rules ) {
		$rule_groups = [];
		foreach ( Access_Rules::normalize_rules( $access_rules ) as $group ) {
			if ( ! is_array( $group ) ) {
				continue;
			}
			$group = array_values(
				array_filter(
					$group,
					function ( $rule ) {
						$definition = isset( $rule['slug'] ) ? Access_Rules::get_rule( $rule['slug'] ) : null;
						if ( empty( $definition['callback'] ) ) {
							return false;
						}
						$value = $rule['value'] ?? null;
						return empty( $definition['empty_grants_access'] ) || ! in_array( $value, [ null, [], '' ], true );
					}
				)
			);
			if ( $group ) {
				$rule_groups[] = $group;
			}
		}
		return $rule_groups;
	}
}
Member_Body_Class::init();
