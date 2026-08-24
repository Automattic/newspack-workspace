<?php
/**
 * Newspack Content Gate Access Rules
 *
 * @package Newspack
 */

namespace Newspack;

use Newspack\WooCommerce_Connection;

/**
 * Main class.
 */
class Access_Rules {

	const META_KEY = 'access_rules';

	/**
	 * Registered rules.
	 *
	 * @var array
	 */
	private static $rules = [];

	/**
	 * Valid duration units for the one-time purchase rule.
	 *
	 * @var array
	 */
	const ONE_TIME_PURCHASE_DURATION_UNITS = [ 'days', 'months', 'forever' ];

	/**
	 * Request-scoped memo of one-time purchase evaluations, keyed by user ID and
	 * rule value. Front-end requests can evaluate the same rule several times
	 * (content restriction, block visibility, admin profile panel) — memoizing
	 * avoids repeating the order query within a request.
	 *
	 * @var array
	 */
	private static $one_time_purchase_memo = [];

	/**
	 * Context for the evaluation currently in progress, set by evaluate_rules()
	 * / evaluate_rule() from the settings of the gate being evaluated. Rule
	 * callbacks read it via get_evaluation_context(). Empty outside an
	 * evaluation, so callbacks must always provide a default.
	 *
	 * @var array
	 */
	private static $evaluation_context = [];

	/**
	 * Initialize hooks.
	 */
	public static function init() {
		add_action( 'init', [ __CLASS__, 'register_default_rules' ] );
	}
	/**
	 * Register a rule.
	 *
	 * @param array $config {
	 *     The rule configuration.
	 *
	 *     @type string   $id                 The rule ID.
	 *     @type string   $name               The rule name.
	 *     @type string   $description        The rule description.
	 *     @type mixed    $default            The rule default value.
	 *     @type array    $options            The rule options.
	 *     @type callable $callback           The rule callback.
	 *     @type callable $sanitize_callback  Optional. Sanitizes the rule's stored value; rules
	 *                                        with composite (non-scalar, non-list) value shapes
	 *                                        must provide one — Content_Gate_API delegates to it
	 *                                        instead of the generic list/scalar sanitization.
	 *     @type bool     $is_boolean         Whether the rule is a boolean rule.
	 *     @type bool     $supports_anonymous Whether the rule's callback can evaluate access for
	 *                                        a logged-out visitor (`user_id = 0`). Defaults to
	 *                                        false — `evaluate_rule` short-circuits to false for
	 *                                        anonymous users on rules that don't opt in. Rules
	 *                                        that opt in are responsible for cache-safety
	 *                                        (e.g. only running per-IP logic when the page is
	 *                                        already uncached).
	 * }
	 *
	 * @return void|\WP_Error
	 */
	public static function register_rule( $config ) {
		if ( ! isset( $config['id'] ) ) {
			return new \WP_Error( 'invalid_rule_id', __( 'Rule ID is required.', 'newspack' ) );
		}
		if ( isset( self::$rules[ $config['id'] ] ) ) {
			return new \WP_Error( 'rule_already_registered', __( 'Rule already registered.', 'newspack' ) );
		}
		if ( ! isset( $config['callback'] ) ) {
			return new \WP_Error( 'invalid_rule_callback', __( 'Rule callback is required.', 'newspack' ) );
		}
		if ( ! is_callable( $config['callback'] ) ) {
			return new \WP_Error( 'invalid_rule_callback', __( 'Rule callback is not callable.', 'newspack' ) );
		}
		$rule = wp_parse_args(
			$config,
			[
				'name'        => ucwords( str_replace( '_', ' ', $config['id'] ) ),
				'description' => '',
				'default'     => ! empty( $config['options'] ) ? [] : '',
				'options'     => [],
				'is_boolean'  => false,
			]
		);
		self::$rules[ $rule['id'] ] = $rule;
	}

	/**
	 * Get all registered rules.
	 *
	 * @return array The registered rules.
	 */
	public static function get_registered_rules() {
		return self::$rules;
	}

	/**
	 * Register the default access rules.
	 */
	public static function register_default_rules() {
		$rules = [
			'subscription'      => [
				'name'        => __( 'Active subscription', 'newspack-plugin' ),
				'description' => __( 'Requires an active subscription to selected products.', 'newspack-plugin' ),
				'options'     => [ __CLASS__, 'get_subscription_products_options' ],
				'callback'    => [ __CLASS__, 'has_active_subscription' ],
			],
			'one_time_purchase' => [
				'name'              => __( 'One-time purchase', 'newspack-plugin' ),
				'description'       => __( 'Grants access for a set period (or forever) after purchasing selected one-time products.', 'newspack-plugin' ),
				'options'           => [ __CLASS__, 'get_one_time_purchase_products_options' ],
				'callback'          => [ __CLASS__, 'has_one_time_purchase' ],
				'default'           => [
					'product_ids'    => [],
					'duration_value' => 0,
					'duration_unit'  => 'forever',
				],
				'sanitize_callback' => [ __CLASS__, 'sanitize_one_time_purchase_value' ],
			],
			'email_domain'      => [
				'name'        => __( 'Whitelisted email domain', 'newspack-plugin' ),
				'description' => __( 'Only allow readers with specific email domains.', 'newspack-plugin' ),
				'placeholder' => __( 'example.com,another.com', 'newspack-plugin' ),
				'callback'    => [ __CLASS__, 'is_email_domain_whitelisted' ],
			],
			'reader_data'       => [
				'name'        => __( 'Reader data', 'newspack-plugin' ),
				'description' => __( 'Set custom conditions based on reader data key/value pairs.', 'newspack-plugin' ),
				'callback'    => [ __CLASS__, 'has_reader_data' ],
			],
			'institution'       => [
				'name'               => __( 'Institutional access', 'newspack-plugin' ),
				'description'        => __( 'Grant access to readers from selected institutions.', 'newspack-plugin' ),
				'options'            => [ Institution::class, 'get_options' ],
				'callback'           => [ Institution::class, 'evaluate' ],
				'supports_anonymous' => true,
			],
		];

		foreach ( $rules as $id => $rule ) {
			self::register_rule( array_merge( $rule, [ 'id' => $id ] ) );
		}
	}

	/**
	 * Get access rules.
	 *
	 * @return array The access rules.
	 */
	public static function get_access_rules() {
		return array_map(
			function( $rule ) {
				if ( ! empty( $rule['options'] ) && is_callable( $rule['options'] ) ) {
					$rule['options'] = call_user_func( $rule['options'] );
				}
				return $rule;
			},
			self::$rules
		);
	}

	/**
	 * Get the access rules with PHP callables stripped, for client-side payloads
	 * (wp_localize_script and similar).
	 *
	 * @return array The registered rules with resolved options and no callables.
	 */
	public static function get_access_rules_for_client() {
		return array_map(
			function( $rule ) {
				unset( $rule['callback'], $rule['sanitize_callback'] );
				return $rule;
			},
			self::get_access_rules()
		);
	}

	/**
	 * Get the access rule by slug.
	 *
	 * @param string $slug Rule slug.
	 *
	 * @return array|null Rule config or null if not found.
	 */
	public static function get_rule( $slug ) {
		return self::$rules[ $slug ] ?? null;
	}

	/**
	 * Evaluate whether the given or current user can bypass the given access rule.
	 *
	 * @param string     $rule_slug Access rule slug.
	 * @param mixed      $args      Additional arguments for the access rule callback.
	 * @param int|null   $user_id   User ID. If not given, checks the current user.
	 * @param array|null $context   Optional evaluation context (e.g. the gate's
	 *                              custom_access settings relevant to rule callbacks,
	 *                              such as `payment_recovery_grace`). If null, the
	 *                              context already in place (set by evaluate_rules())
	 *                              is left untouched.
	 *
	 * @return bool
	 */
	public static function evaluate_rule( $rule_slug, $args = null, $user_id = null, $context = null ) {
		$rule = self::get_rule( $rule_slug );

		// Rule doesn't exist or lacks a callback function to execute, don't block access for it.
		if ( empty( $rule['callback'] ) ) {
			return true;
		}

		// If evaluating for the current user, they must be logged in (unless the rule supports anonymous evaluation).
		$user_id = $user_id ?? \get_current_user_id();
		if ( ! $user_id && empty( $rule['supports_anonymous'] ) ) {
			return false;
		}

		// Access rule must have a callable callback function.
		if ( ! is_callable( $rule['callback'] ) ) {
			return false;
		}

		// Context defaults differ on purpose between the two entry points: this
		// method is also the inner primitive invoked during a group evaluation,
		// so its null default means "inherit whatever context evaluate_rules()
		// already established" — while evaluate_rules() defaults to `[]`, always
		// establishing a fresh context so a caller that passes nothing gets the
		// rule callbacks' own defaults rather than a stale outer context.
		if ( null === $context ) {
			return call_user_func( $rule['callback'], $user_id, $args );
		}

		return self::with_evaluation_context(
			$context,
			function () use ( $rule, $user_id, $args ) {
				return call_user_func( $rule['callback'], $user_id, $args );
			}
		);
	}

	/**
	 * Run a callback with the given evaluation context in place, restoring the
	 * previous context afterwards.
	 *
	 * Use this to give rule callbacks a gate's settings when invoking them
	 * outside `evaluate_rule()` / `evaluate_rules()` — e.g. calling
	 * `has_active_subscription()` directly to attribute *why* a rule passed.
	 * Without it those calls silently fall back to the rule callbacks' own
	 * defaults instead of honoring the gate.
	 *
	 * @param array    $context  Evaluation context, as read by get_evaluation_context().
	 * @param callable $callback Callback to run.
	 *
	 * @return mixed The callback's return value.
	 */
	public static function with_evaluation_context( $context, $callback ) {
		$previous_context         = self::$evaluation_context;
		self::$evaluation_context = $context;
		try {
			// Rule callbacks are third-party-registerable; restoring in a finally
			// block guarantees a throwing callback can't leak this context into
			// later evaluations in the same request.
			return $callback();
		} finally {
			self::$evaluation_context = $previous_context;
		}
	}

	/**
	 * Get a value from the context of the evaluation currently in progress.
	 *
	 * @param string $key           Context key.
	 * @param mixed  $default_value Value to return when the key isn't part of the
	 *                              current context (or no evaluation is in progress).
	 *
	 * @return mixed
	 */
	public static function get_evaluation_context( $key, $default_value = null ) {
		return self::$evaluation_context[ $key ] ?? $default_value;
	}

	/**
	 * Determine whether the gate's custom_access rules grant access to an
	 * anonymous (logged-out) visitor.
	 *
	 * Only rules that (a) declare `supports_anonymous` and (b) have a populated
	 * `value` are considered. An unpopulated rule is treated as "not configured"
	 * rather than "matches everyone". How an evaluator itself reads an empty
	 * value varies by rule — `email_domain` returns true (no constraint), while
	 * `one_time_purchase` denies (unconfigured rules must never grant) — so this
	 * check deliberately does not delegate that decision to the evaluator, and a
	 * rule opting into `supports_anonymous` must not assume either reading.
	 *
	 * Groups containing any non-eligible rule are dropped (the AND-within-group
	 * semantics would force the group to fail for an anonymous visitor anyway,
	 * since non-anonymous rules return false for `user_id = 0`).
	 *
	 * @param array $access_rules Custom access rules in grouped or flat format.
	 *
	 * @return bool True if a populated, anonymous-capable rule grants access.
	 */
	public static function evaluate_anonymous_rules( $access_rules ) {
		if ( empty( $access_rules ) ) {
			return false;
		}
		$eligible_groups = [];
		foreach ( self::normalize_rules( $access_rules ) as $group ) {
			if ( empty( $group ) || ! is_array( $group ) ) {
				continue;
			}
			$group_eligible = true;
			foreach ( $group as $rule ) {
				// `empty()` is acceptable for `value` while the only `supports_anonymous` rule
				// (`institution`) stores an array of post IDs — empty array means "no institutions
				// selected." If a future anonymous-capable rule uses a falsy-but-valid scalar (e.g.
				// `0`, `'0'`, `false`), tighten this check accordingly.
				if ( ! isset( $rule['slug'] ) || empty( $rule['value'] ) ) {
					$group_eligible = false;
					break;
				}
				$rule_def = self::get_rule( $rule['slug'] );
				if ( empty( $rule_def['supports_anonymous'] ) ) {
					$group_eligible = false;
					break;
				}
			}
			if ( $group_eligible ) {
				$eligible_groups[] = $group;
			}
		}
		if ( empty( $eligible_groups ) ) {
			return false;
		}
		return self::evaluate_rules( $eligible_groups, 0 );
	}

	/**
	 * Evaluate access rules with OR logic between groups and AND logic within groups.
	 *
	 * Rules structure: [ [ rule1, rule2 ], [ rule3, rule4 ] ]
	 * - Groups use OR logic: reader must pass at least one group
	 * - Rules within a group use AND logic: reader must pass all rules in the group
	 *
	 * @param array $access_rules The access rules (array of groups, each group is an array of rules).
	 * @param int   $user_id     Optional. User ID to evaluate rules for. Defaults to current user.
	 * @param array $context     Optional evaluation context made available to rule
	 *                           callbacks via get_evaluation_context() (e.g. the
	 *                           gate's `payment_recovery_grace` setting).
	 *
	 * @return bool True if access is granted, false if restricted.
	 */
	public static function evaluate_rules( $access_rules, $user_id = null, $context = [] ) {
		if ( empty( $access_rules ) ) {
			return true;
		}

		// Normalize legacy flat rules structure to grouped format.
		$access_rules = self::normalize_rules( $access_rules );

		return self::with_evaluation_context(
			$context,
			function () use ( $access_rules, $user_id ) {
				// Evaluate each group with OR logic - if any group passes, grant access.
				foreach ( $access_rules as $group ) {
					if ( self::evaluate_rules_group( $group, $user_id ) ) {
						return true;
					}
				}

				// No group passed - restrict access.
				return false;
			}
		);
	}

	/**
	 * Evaluate a single group of access rules with AND logic.
	 *
	 * @param array $group   Array of rules in the group.
	 * @param int   $user_id Optional. User ID to evaluate rules for. Defaults to current user.
	 *
	 * @return bool True if all rules in the group pass, false otherwise.
	 */
	private static function evaluate_rules_group( $group, $user_id = null ) {
		if ( empty( $group ) || ! is_array( $group ) ) {
			return true;
		}

		foreach ( $group as $rule ) {
			if ( ! isset( $rule['slug'] ) ) {
				continue;
			}
			if ( ! self::evaluate_rule( $rule['slug'], $rule['value'] ?? null, $user_id ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Normalize access rules to grouped format.
	 *
	 * Converts flat rules [ rule1, rule2 ] to grouped format [ [ rule1 ], [ rule2 ] ],
	 * where each rule is its own group (OR logic). Already grouped rules are left as-is.
	 *
	 * @param array $access_rules The access rules.
	 *
	 * @return array Normalized access rules in grouped format.
	 */
	public static function normalize_rules( $access_rules ) {
		if ( empty( $access_rules ) ) {
			return [];
		}

		// Check if already in grouped format (array of arrays with rules).
		// A grouped format has arrays as first-level elements.
		// A flat format has rule objects (with 'slug' key) as first-level elements.
		$first_element = reset( $access_rules );
		if ( is_array( $first_element ) && ! isset( $first_element['slug'] ) ) {
			// Already in grouped format.
			return $access_rules;
		}

		// Convert flat format to OR logic: each rule becomes its own group.
		return array_map(
			function ( $rule ) {
				return [ $rule ];
			},
			$access_rules
		);
	}

	/**
	 * Get subscriptions eligible for access rules.
	 *
	 * @return array Active subscription IDs.
	 */
	public static function get_subscription_products_options() {
		if ( ! function_exists( 'wc_get_products' ) ) {
			return [];
		}
		$products = \wc_get_products(
			[
				'type'  => [ 'subscription', 'variable-subscription' ],
				'limit' => -1,
			]
		);
		$options = [];
		foreach ( $products as $product ) {
			$options[] = [
				'label' => $product->get_name(),
				'value' => $product->get_id(),
			];
		}
		return $options;
	}

	/**
	 * Whether the user has an active subscription for one of the given products.
	 * Also checks if the user is a member of a group subscription with the required products.
	 *
	 * Note: `$strict` only constrains the built-in ownership / group-membership checks.
	 * The `newspack_access_rules_has_active_subscription` filter is always applied and
	 * its return value is the final result, so a third-party filter callback can grant
	 * access even when `$strict` is true. Filter authors should opt in to the 4th `$strict`
	 * arg (`accepted_args` >= 4) and respect it — e.g., short-circuit and return
	 * `$has_subscription` unchanged when `$strict` is true and the access claim isn't
	 * strictly an owned subscription. Otherwise callers using `$strict` to distinguish
	 * owner-vs-member access (e.g., `Content_Gate` source labels) may misclassify
	 * filter-granted access as local ownership.
	 *
	 * @param int   $user_id     User ID.
	 * @param array $product_ids Required product IDs.
	 * @param bool  $strict      If true, only consider active subscriptions owned by $user_id (ignore group subscription memberships).
	 * @return bool
	 */
	public static function has_active_subscription( $user_id, $product_ids, $strict = false ) {
		$has_subscription = false;

		// Whether on-hold subscriptions in payment recovery (failed-payment retry
		// window) still grant access. Controlled per-gate by the custom_access
		// `payment_recovery_grace` setting; defaults to ON so gates saved before
		// the setting existed — and evaluations outside a gate context — keep
		// paying readers' access while their payment is being retried.
		$payment_recovery_grace = (bool) self::get_evaluation_context( 'payment_recovery_grace', true );

		// Check user's own subscriptions.
		if ( ! empty( WooCommerce_Connection::get_active_subscriptions_for_user( $user_id, $product_ids, $payment_recovery_grace ) ) ) {
			$has_subscription = true;
		}

		// Check group subscriptions the user is a member of.
		if ( ! $strict && ! $has_subscription && function_exists( 'wcs_get_subscription' ) ) {
			$group_subscriptions = Group_Subscription::get_group_subscriptions_for_user( $user_id );
			foreach ( $group_subscriptions as $subscription ) {
				if ( ! $subscription ) {
					continue;
				}
				$grants_access = $subscription->has_status( WooCommerce_Connection::ACTIVE_SUBSCRIPTION_STATUSES )
					|| ( $payment_recovery_grace && WooCommerce_Connection::is_subscription_in_payment_recovery( $subscription ) );
				if ( ! $grants_access ) {
					continue;
				}
				// If no product filter, any active group subscription grants access.
				if ( empty( $product_ids ) ) {
					$has_subscription = true;
					break;
				}
				// Check if the subscription has any of the required products.
				foreach ( $product_ids as $product_id ) {
					if ( $subscription->has_product( $product_id ) ) {
						$has_subscription = true;
						break 2;
					}
				}
			}
		}

		/**
		 * Filters whether a user has an active subscription for the given products.
		 *
		 * @param bool  $has_subscription Whether the user has an active subscription.
		 * @param int   $user_id          User ID.
		 * @param array $product_ids      Required product IDs.
		 * @param bool  $strict           If true, only consider active subscriptions owned by $user_id (ignore group subscription memberships).
		 */
		return apply_filters( 'newspack_access_rules_has_active_subscription', $has_subscription, $user_id, $product_ids, $strict );
	}

	/**
	 * Get non-subscription (one-time) products eligible for the one-time purchase rule.
	 *
	 * @return array Product options as label/value pairs.
	 */
	public static function get_one_time_purchase_products_options() {
		// Request-scoped memo: the full-catalog query would otherwise run once per
		// rule sanitized on a gate save (Content_Gate_API resolves all rule options
		// for each rule in the payload).
		//
		// TODO (NPPD-2132): unlike the subscription and institution options (also
		// full dumps, but inherently small), a shop's simple/variable catalog can be
		// large, and this list is serialized into every block-editor payload. Move to
		// a bounded/REST-searchable product picker; the memo only helps within a
		// single request.
		static $options = null;
		if ( null !== $options ) {
			return $options;
		}
		if ( ! function_exists( 'wc_get_products' ) ) {
			return [];
		}
		$products = \wc_get_products(
			[
				'type'  => [ 'simple', 'variable' ],
				'limit' => -1,
			]
		);
		$options  = [];
		foreach ( $products as $product ) {
			$options[] = [
				'label' => $product->get_name(),
				'value' => $product->get_id(),
			];
		}
		return $options;
	}

	/**
	 * Sanitize the one-time purchase rule value.
	 *
	 * An unrecognized or missing duration unit is preserved as an empty string so
	 * evaluation fails closed — malformed input must never widen a finite grant
	 * into a lifetime one.
	 *
	 * @param mixed $value Raw rule value.
	 *
	 * @return array Sanitized value with product_ids, duration_value, and duration_unit keys.
	 */
	public static function sanitize_one_time_purchase_value( $value ) {
		if ( ! is_array( $value ) ) {
			$value = [];
		}
		$duration_unit = $value['duration_unit'] ?? '';
		return [
			'product_ids'    => array_values( array_filter( array_map( 'absint', (array) ( $value['product_ids'] ?? [] ) ) ) ),
			'duration_value' => absint( $value['duration_value'] ?? 0 ),
			'duration_unit'  => in_array( $duration_unit, self::ONE_TIME_PURCHASE_DURATION_UNITS, true ) ? $duration_unit : '',
		];
	}

	/**
	 * Flush the request-scoped one-time purchase evaluation memo.
	 *
	 * Primarily for tests; in production the memo is per-request by nature.
	 */
	public static function flush_one_time_purchase_memo() {
		self::$one_time_purchase_memo = [];
	}

	/**
	 * Whether the user has purchased one of the given one-time (non-subscription)
	 * products within the rule's access duration.
	 *
	 * Only paid orders count (processing/completed via `wc_get_is_paid_statuses()`),
	 * so refunded, cancelled, failed, and pending orders never grant access. The
	 * order's creation date anchors the duration.
	 *
	 * @param int   $user_id User ID.
	 * @param array $args {
	 *     Rule value.
	 *
	 *     @type int[]  $product_ids    Product IDs that grant access.
	 *     @type int    $duration_value Number of duration units access lasts after purchase.
	 *     @type string $duration_unit  One of 'days', 'months', or 'forever'.
	 * }
	 * @return bool
	 */
	public static function has_one_time_purchase( $user_id, $args ) {
		$value        = self::sanitize_one_time_purchase_value( $args );
		$has_purchase = false;

		if ( ! empty( $value['product_ids'] ) && function_exists( 'wc_get_orders' ) ) {
			$memo_key = $user_id . ':' . md5( wp_json_encode( $value ) );
			if ( isset( self::$one_time_purchase_memo[ $memo_key ] ) ) {
				$has_purchase = self::$one_time_purchase_memo[ $memo_key ];
			} else {
				$user     = \get_userdata( $user_id );
				$email    = $user ? $user->user_email : '';
				$customer = array_values( array_filter( [ $user_id, $email ] ) );
				if ( empty( $customer ) ) {
					// Fail closed with no identity to match a purchase against. Both
					// paths need this guard, for different reasons. The finite path:
					// an empty customer constraint is dropped by both WooCommerce
					// order stores, which would widen the lookup to every customer's
					// paid orders. The forever path: wc_customer_bought_product()
					// returns the value of the `woocommerce_pre_customer_bought_product`
					// filter verbatim whenever it is non-null, ahead of its own
					// identity check, so a third-party filter can answer truthy for
					// nobody in particular. Neither branch is redundant.
					$has_purchase = false;
				} elseif ( 'forever' === $value['duration_unit'] ) {
					// Lifetime access: any paid order ever. wc_customer_bought_product()
					// is exhaustive across the customer's order history (matching both
					// user ID and billing email, so guest orders count), runs SQL-side,
					// and is cached by WooCommerce with invalidation on order writes.
					foreach ( $value['product_ids'] as $product_id ) {
						if ( \wc_customer_bought_product( $email, $user_id, $product_id ) ) {
							$has_purchase = true;
							break;
						}
					}
				} elseif ( in_array( $value['duration_unit'], [ 'days', 'months' ], true ) && $value['duration_value'] > 0 ) {
					// One cutoff shared by every order, rather than a per-order expiry
					// of purchase + N. Month arithmetic follows strtotime()'s rollover
					// semantics, and rolling backwards from now is the conservative
					// direction: "-1 month" from Mar 1 lands on Feb 1, so a Jan 31
					// purchase stops granting once the calendar month is up, whereas
					// "+1 month" from Jan 31 rolls forward through Feb 31 to Mar 3 and
					// would grant three extra days. The two readings agree except on
					// month-end anchors, where this one is both deny-biased and closer
					// to what "N months from purchase" means on a calendar.
					$cutoff       = strtotime( sprintf( '-%d %s', $value['duration_value'], $value['duration_unit'] ) );
					$has_purchase = self::customer_bought_product_after( $customer, $value['product_ids'], $cutoff );
				}
				// Any other duration configuration (missing/unrecognized unit, zero
				// finite duration) is misconfigured and fails closed.
				self::$one_time_purchase_memo[ $memo_key ] = $has_purchase;
			}
		}

		/**
		 * Filters whether a user has a qualifying one-time purchase for the given rule value.
		 *
		 * @param bool  $has_purchase Whether the user has a qualifying purchase.
		 * @param int   $user_id      User ID.
		 * @param array $value        Sanitized rule value (product_ids, duration_value, duration_unit).
		 */
		return apply_filters( 'newspack_access_rules_has_one_time_purchase', $has_purchase, $user_id, $value );
	}

	/**
	 * Whether the user has a paid order containing one of the given products,
	 * created after the given cutoff timestamp.
	 *
	 * The query is bounded by customer, paid statuses, and the date window, so it
	 * stays cheap on front-end requests even without a persistent cache. The
	 * `customer` parameter matches the user ID or the billing email, so guest
	 * orders count — mirroring wc_customer_bought_product() on the lifetime path.
	 *
	 * @param array $customer    Non-empty list of user IDs and/or billing emails to
	 *                           match. Callers must reject an empty list: both
	 *                           WooCommerce order stores drop an empty `customer`
	 *                           constraint and return every customer's orders.
	 * @param int[] $product_ids Product IDs to look for.
	 * @param int   $cutoff      Unix timestamp orders must be created after.
	 *
	 * @return bool
	 */
	private static function customer_bought_product_after( $customer, $product_ids, $cutoff ) {
		$paid_statuses = function_exists( 'wc_get_is_paid_statuses' ) ? \wc_get_is_paid_statuses() : [ 'processing', 'completed' ];
		$orders        = \wc_get_orders(
			[
				'customer'     => $customer,
				'status'       => $paid_statuses,
				'date_created' => '>' . $cutoff,
				'limit'        => -1,
				'return'       => 'objects',
			]
		);
		foreach ( $orders as $order ) {
			foreach ( $order->get_items() as $item ) {
				$item_product_id   = method_exists( $item, 'get_product_id' ) ? (int) $item->get_product_id() : 0;
				$item_variation_id = method_exists( $item, 'get_variation_id' ) ? (int) $item->get_variation_id() : 0;
				if ( in_array( $item_product_id, $product_ids, true ) || ( $item_variation_id && in_array( $item_variation_id, $product_ids, true ) ) ) {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * Whether the user’s email address contains one of the given domains.
	 *
	 * @param int    $user_id User ID.
	 * @param string $domains Comma-delimited list of domains.
	 * @return bool
	 */
	public static function is_email_domain_whitelisted( $user_id, $domains ) {
		// If no domains are specified, allow access.
		if ( empty( $domains ) ) {
			return true;
		}
		$domains = str_replace( PHP_EOL, ',', $domains );
		$domains = explode( ',', $domains );
		$domains = array_map( 'trim', $domains );
		$domains = array_map( 'strtolower', $domains );
		$user    = \get_userdata( $user_id );
		if ( ! $user ) {
			return false;
		}
		$email = $user->data->user_email;
		if ( ! $email ) {
			return false;
		}
		if ( Reader_Activation::is_reader_verified( $user ) === false ) {
			return false;
		}
		$email_domain = strtolower( substr( $email, strrpos( $email, '@' ) + 1 ) );
		return in_array( $email_domain, $domains, true );
	}

	/**
	 * Determine reader data key-values the reader must have.
	 *
	 * @param int    $user_id User ID.
	 * @param string $data    Key-value pairs separate by semicolon.
	 *
	 * @return bool Whether the reader has the required data.
	 */
	public static function has_reader_data( $user_id, $data ) {
		if ( empty( $data ) ) {
			return true;
		}
		$data = explode( ';', $data );
		$data = array_map( 'trim', $data );
		$data = array_filter( $data );
		$data = array_map(
			function( $item ) {
				return explode( '=', $item );
			},
			$data
		);
		$reader_data = Reader_Data::get_data( $user_id );
		foreach ( $data as $item ) {
			if ( ! isset( $reader_data[ $item[0] ] ) || $reader_data[ $item[0] ] !== $item[1] ) {
				return false;
			}
		}
		return true;
	}
}
Access_Rules::init();
