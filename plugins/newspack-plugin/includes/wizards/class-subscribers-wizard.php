<?php
/**
 * Newspack Subscribers management wizard.
 *
 * The admin-side, people-first view of the site's subscribers and group
 * subscriptions. Ported from the signed-off i2 design prototype; it lives under
 * the Audience menu and is visible to any admin who can `manage_options`.
 *
 * @package Newspack
 */

namespace Newspack;

defined( 'ABSPATH' ) || exit;

require_once NEWSPACK_ABSPATH . '/includes/wizards/class-wizard.php';

/**
 * Subscribers wizard.
 */
class Subscribers_Wizard extends Wizard {

	/**
	 * The slug of this wizard.
	 *
	 * @var string
	 */
	protected $slug = 'newspack-subscribers';

	/**
	 * The capability required to access this wizard.
	 *
	 * @var string
	 */
	protected $capability = 'manage_options';

	/**
	 * The parent menu slug this wizard hangs under.
	 *
	 * @var string
	 */
	protected $parent_slug = 'newspack-audience';

	/**
	 * Upper bound on the number of customer IDs a subscription-status/plan filter
	 * resolves to. Keeps both the subscription scan and the WP_User_Query IN()
	 * clause bounded on very large stores. See resolve_filter_include().
	 */
	const FILTER_INCLUDE_CAP = 10000;

	/**
	 * Per-request memo of the site's group subscriptions (each with its resolved
	 * settings). A single request can resolve these several times — once per active
	 * filter set plus the group list itself — and each resolution hydrates every
	 * group subscription, so it's cached for the life of the (per-request) wizard.
	 *
	 * @var array<int,array{subscription:\WC_Subscription,settings:array}>|null
	 */
	private $group_subscriptions_cache = null;

	/**
	 * Per-request memo mapping each user ID to their group memberships
	 * ({ id, plan, status, role }), built once from the site's groups so the
	 * subscriber list doesn't re-resolve group roles per row.
	 *
	 * @var array<int,array<int,array>>|null
	 */
	private $group_membership_index = null;

	/**
	 * Constructor.
	 *
	 * @param array $args Optional arguments.
	 */
	public function __construct( $args = [] ) {
		parent::__construct( $args );
		add_action( 'rest_api_init', [ $this, 'register_api_endpoints' ] );
	}

	/**
	 * Whether this wizard is available.
	 *
	 * The subscribers surface is the admin face of the group-subscription / Access
	 * Control feature, so it rides the same flag the rest of that code gates on
	 * rather than introducing a new one — a site without Access Control has no
	 * group data to manage here.
	 *
	 * @return bool
	 */
	public function is_feature_enabled() {
		return Content_Gate::is_newspack_feature_enabled();
	}

	/**
	 * Get the name for this wizard.
	 *
	 * @return string The wizard name.
	 */
	public function get_name() {
		return esc_html__( 'Audience Management / Subscribers', 'newspack-plugin' );
	}

	/**
	 * Add the Subscribers subpage under the Audience menu.
	 */
	public function add_page() {
		if ( ! $this->is_feature_enabled() ) {
			return;
		}
		add_submenu_page(
			$this->parent_slug,
			$this->get_name(),
			esc_html__( 'Subscribers', 'newspack-plugin' ),
			$this->capability,
			$this->slug,
			[ $this, 'render_wizard' ]
		);
	}

	/**
	 * Register REST endpoints.
	 */
	public function register_api_endpoints() {
		if ( ! $this->is_feature_enabled() ) {
			return;
		}
		register_rest_route(
			NEWSPACK_API_NAMESPACE,
			'/wizard/' . $this->slug . '/avatars',
			[
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'api_get_avatars' ],
				'permission_callback' => [ $this, 'api_permissions_check' ],
				'args'                => [
					'emails' => [
						'type'     => 'array',
						'required' => true,
						'items'    => [ 'type' => 'string' ],
					],
					'size'   => [
						'type'              => 'integer',
						'default'           => 64,
						'minimum'           => 16,
						'maximum'           => 512,
						'sanitize_callback' => 'absint',
						'validate_callback' => 'rest_validate_request_arg',
					],
				],
			]
		);

		register_rest_route(
			NEWSPACK_API_NAMESPACE,
			'/wizard/' . $this->slug . '/groups',
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ $this, 'api_get_groups' ],
				'permission_callback' => [ $this, 'api_permissions_check' ],
			]
		);

		register_rest_route(
			NEWSPACK_API_NAMESPACE,
			'/wizard/' . $this->slug . '/subscribers',
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ $this, 'api_get_subscribers' ],
				'permission_callback' => [ $this, 'api_permissions_check' ],
				'args'                => [
					'page'     => [
						'type'              => 'integer',
						'default'           => 1,
						'minimum'           => 1,
						'sanitize_callback' => 'absint',
						'validate_callback' => 'rest_validate_request_arg',
					],
					'per_page' => [
						'type'              => 'integer',
						'default'           => 20,
						'minimum'           => 1,
						'maximum'           => 100,
						'sanitize_callback' => 'absint',
						'validate_callback' => 'rest_validate_request_arg',
					],
					'search'   => [
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					],
					'orderby'  => [
						'type'              => 'string',
						'enum'              => [ 'name', 'memberSince' ],
						'default'           => 'memberSince',
						'validate_callback' => 'rest_validate_request_arg',
					],
					'order'    => [
						'type'              => 'string',
						'enum'              => [ 'asc', 'desc' ],
						'default'           => 'desc',
						'validate_callback' => 'rest_validate_request_arg',
					],
					'status'   => [
						'type'              => 'array',
						'items'             => [
							'type' => 'string',
							'enum' => [ 'active', 'pending', 'on-hold', 'cancelled' ],
						],
						'sanitize_callback' => 'rest_sanitize_request_arg',
						'validate_callback' => 'rest_validate_request_arg',
					],
					'plan'     => [
						'type'              => 'array',
						'items'             => [ 'type' => 'string' ],
						'sanitize_callback' => 'rest_sanitize_request_arg',
					],
				],
			]
		);
	}

	/**
	 * Map a WooCommerce Subscriptions status onto the prototype's status
	 * vocabulary (active / pending / on-hold / cancelled).
	 *
	 * The admin UI was designed against these four values; WCS carries a handful
	 * more that collapse cleanly onto them:
	 *   - active, pending-cancel → active (still entitled)
	 *   - pending               → pending (awaiting first payment)
	 *   - on-hold               → on-hold (failed/paused renewal)
	 *   - cancelled, expired    → cancelled (no longer entitled)
	 * Anything unrecognised is treated as on-hold, the safe "needs attention"
	 * bucket, rather than surfacing a raw WCS slug the UI has no label for.
	 *
	 * @param string $wcs_status The WooCommerce Subscriptions status slug.
	 *
	 * @return string One of 'active' | 'pending' | 'on-hold' | 'cancelled'.
	 */
	public static function map_subscription_status( $wcs_status ) {
		return match ( $wcs_status ) {
			'active', 'pending-cancel' => 'active',
			'pending'                  => 'pending',
			'cancelled', 'expired'     => 'cancelled',
			default                    => 'on-hold',
		};
	}

	/**
	 * GET the site's group subscriptions, hydrated for the admin group list.
	 *
	 * Returns the `{ items, total, pages }` envelope the wizard's data hooks
	 * expect. Groups are returned in full (the group list paginates client-side),
	 * so `pages` is always 1. Each group carries the owner-inclusive member count
	 * and the seat limit so the list can render "used / limit" without a second
	 * round-trip.
	 *
	 * @return \WP_REST_Response
	 */
	public function api_get_groups() {
		$this->group_subscriptions_cache = null;
		$this->group_membership_index    = null;
		$items                           = [];
		foreach ( $this->get_group_subscriptions() as $group ) {
			$items[] = $this->prepare_group( $group['subscription'], $group['settings'] );
		}

		return rest_ensure_response(
			[
				'items' => $items,
				'total' => count( $items ),
				'pages' => 1,
			]
		);
	}

	/**
	 * Every group-enabled subscription on the site, each paired with its resolved
	 * settings, keyed by subscription ID. Memoized for the request.
	 *
	 * The HPOS-safe site-wide query get_group_subscription_ids() can over-report
	 * under product inheritance, so each candidate is re-checked against its own
	 * settings — the authority on group membership.
	 *
	 * @return array<int,array{subscription:\WC_Subscription,settings:array}>
	 */
	private function get_group_subscriptions() {
		if ( null !== $this->group_subscriptions_cache ) {
			return $this->group_subscriptions_cache;
		}
		$groups = [];
		if ( class_exists( '\Newspack\Group_Subscription_Settings' ) && function_exists( 'wcs_get_subscription' ) ) {
			foreach ( Group_Subscription_Settings::get_group_subscription_ids() as $subscription_id ) {
				$subscription = \wcs_get_subscription( $subscription_id );
				if ( ! $subscription ) {
					continue;
				}
				$settings = Group_Subscription_Settings::get_subscription_settings( $subscription );
				if ( empty( $settings['enabled'] ) ) {
					continue;
				}
				$groups[ (int) $subscription->get_id() ] = [
					'subscription' => $subscription,
					'settings'     => $settings,
				];
			}
		}
		$this->group_subscriptions_cache = $groups;
		return $groups;
	}

	/**
	 * Shape a group subscription for the admin group list.
	 *
	 * @param \WC_Subscription $subscription The group subscription.
	 * @param array            $settings     Its resolved group settings (name + limit).
	 *
	 * @return array
	 */
	private function prepare_group( $subscription, $settings ) {
		$owner_id   = (int) $subscription->get_user_id();
		$owner      = $owner_id ? get_userdata( $owner_id ) : false;
		$created    = $subscription->get_date_created();
		$created_at = $created ? gmdate( 'Y-m-d', $created->getTimestamp() ) : null;

		return [
			'id'          => (int) $subscription->get_id(),
			'ownerId'     => $owner_id,
			'owner'       => $owner ? [
				'id'    => $owner_id,
				'name'  => $owner->display_name,
				'email' => $owner->user_email,
			] : null,
			'plan'        => (string) $settings['name'],
			'status'      => self::map_subscription_status( $subscription->get_status() ),
			// The configured limit is owner-inclusive (0 = unlimited); the member
			// count is likewise owner-inclusive, so "members / seatLimit" reads true.
			'seatLimit'   => (int) $settings['limit'],
			'members'     => Group_Subscription::get_member_count( $subscription ),
			'createdAt'   => $created_at,
			// Interim click-through target: the WooCommerce subscription edit
			// screen (HPOS-safe), until the in-wizard group detail lands (PR 4).
			'editUrl'     => method_exists( $subscription, 'get_edit_order_url' ) ? $subscription->get_edit_order_url() : '',
			// Seat requests are surfaced in a later slice (NPPD-1753 PR 7).
			'seatRequest' => null,
		];
	}

	/**
	 * GET a paginated page of subscribers (reader-role users), hydrated for the
	 * admin subscriber list.
	 *
	 * The list is server-paginated: filtering, sorting and paging all run against
	 * the database rather than a client-side array. Returns the
	 * `{ items, total, pages }` envelope the wizard's data hooks expect.
	 *
	 * Subscription-status and plan filters can't be expressed as user-table
	 * columns, so they run inverted: an HPOS-safe subscription query resolves the
	 * matching customer IDs, which are then intersected into the user query's
	 * `include` set. See resolve_filter_include().
	 *
	 * @param \WP_REST_Request $request The request.
	 *
	 * @return \WP_REST_Response
	 */
	public function api_get_subscribers( $request ) {
		$this->group_subscriptions_cache = null;
		$this->group_membership_index    = null;
		$per_page                        = (int) $request->get_param( 'per_page' );
		$page     = (int) $request->get_param( 'page' );
		$search   = trim( (string) $request->get_param( 'search' ) );

		$query_args = [
			'role__in'    => Reader_Activation::get_reader_roles(),
			'number'      => $per_page,
			'paged'       => $page,
			'orderby'     => 'name' === $request->get_param( 'orderby' ) ? 'display_name' : 'registered',
			'order'       => 'asc' === $request->get_param( 'order' ) ? 'ASC' : 'DESC',
			'count_total' => true,
			'fields'      => 'all',
		];

		if ( '' !== $search ) {
			$query_args['search']         = '*' . $search . '*';
			$query_args['search_columns'] = [ 'user_login', 'user_email', 'display_name' ];
		}

		// Invert subscription-status / plan filters into an `include` set. Tri-state:
		// null = no filter applied; [] = filter active but matched nobody (short-circuit
		// to an empty page); a populated list = restrict the query to those users.
		$include = $this->resolve_filter_include( $request );
		if ( is_array( $include ) ) {
			if ( empty( $include ) ) {
				return rest_ensure_response(
					[
						'items' => [],
						'total' => 0,
						'pages' => 0,
					]
				);
			}
			$query_args['include'] = $include;
		}

		$user_query = new \WP_User_Query( $query_args );
		$total      = (int) $user_query->get_total();

		$items = [];
		foreach ( $user_query->get_results() as $user ) {
			$items[] = $this->prepare_subscriber( $user );
		}

		return rest_ensure_response(
			[
				'items' => $items,
				'total' => $total,
				'pages' => (int) ceil( $total / $per_page ),
			]
		);
	}

	/**
	 * Shape a reader user for the admin subscriber list.
	 *
	 * @param \WP_User $user The reader user.
	 *
	 * @return array
	 */
	private function prepare_subscriber( $user ) {
		$user_id = (int) $user->ID;
		// Resolve the user's own subscriptions once and reuse them, so hydration
		// doesn't fetch the same list twice per row.
		$owned_subscriptions = function_exists( 'wcs_get_users_subscriptions' ) ? \wcs_get_users_subscriptions( $user_id ) : [];
		$subscriptions       = $this->get_individual_subscriptions( $user_id, $owned_subscriptions );
		$groups              = $this->get_group_memberships( $user_id );

		// user_registered can be a zero date ('0000-00-00 …'), which is truthy but
		// unparseable; guard on the parsed timestamp so it degrades to null, not 1970.
		$registered = $user->user_registered ? strtotime( $user->user_registered ) : false;

		return [
			'id'            => $user_id,
			'name'          => $user->display_name,
			'email'         => $user->user_email,
			// Interim click-through target: the native user-edit screen (self edits
			// resolve to profile.php), until the in-wizard person profile lands (PR 5).
			'editUrl'       => get_edit_user_link( $user_id ),
			'status'        => $this->reduced_status( $subscriptions, $groups ),
			'memberSince'   => $registered ? gmdate( 'Y-m-d', $registered ) : null,
			'lastPayment'   => $this->last_payment_date( $user_id ),
			// Wired to reader activity in a later slice; the column is hidden by default.
			'lastSeen'      => null,
			'subscriptions' => $subscriptions,
			'groups'        => $groups,
			// Tags and newsletters are populated in a later slice (NPPD-1753 PR 7).
			'tags'          => [],
			'newsletters'   => [],
		];
	}

	/**
	 * A reader's own (non-group) subscriptions, shaped as { id, plan, status }.
	 *
	 * The wcs_get_users_subscriptions() list is filtered to also surface subs the
	 * user is only a member of; those are excluded here (they belong to
	 * get_group_memberships()) by keeping only subs the user actually owns and
	 * that are not group-enabled.
	 *
	 * @param int                $user_id      The reader user ID.
	 * @param \WC_Subscription[] $owned_subscriptions The user's subscriptions, already fetched by the caller.
	 *
	 * @return array<int,array>
	 */
	private function get_individual_subscriptions( $user_id, $owned_subscriptions ) {
		$out = [];
		foreach ( $owned_subscriptions as $subscription ) {
			if ( ! $subscription instanceof \WC_Subscription || (int) $subscription->get_customer_id() !== $user_id ) {
				continue;
			}
			$settings = Group_Subscription_Settings::get_subscription_settings( $subscription );
			if ( ! empty( $settings['enabled'] ) ) {
				continue; // Group subscriptions are surfaced via get_group_memberships().
			}
			$out[] = [
				'id'     => (int) $subscription->get_id(),
				'plan'   => $this->individual_plan_name( $subscription ),
				'status' => self::map_subscription_status( $subscription->get_status() ),
			];
		}
		return $out;
	}

	/**
	 * Resolve the display name of an individual subscription's plan (its product name).
	 *
	 * @param \WC_Subscription $subscription The subscription.
	 *
	 * @return string The product name, or '' when it can't be resolved.
	 */
	private function individual_plan_name( $subscription ) {
		if ( ! class_exists( '\Newspack\WooCommerce_Subscriptions' ) || ! function_exists( 'wc_get_product' ) ) {
			return '';
		}
		$product_id = WooCommerce_Subscriptions::get_subscription_product_id( $subscription );
		$product    = $product_id ? \wc_get_product( $product_id ) : false;
		return $product ? (string) $product->get_name() : '';
	}

	/**
	 * A reader's group memberships, shaped as { id, plan, status, role }.
	 *
	 * Read from the per-request membership index rather than re-querying the
	 * user's owned/managed/member subscriptions per row.
	 *
	 * @param int $user_id The reader user ID.
	 *
	 * @return array<int,array>
	 */
	private function get_group_memberships( $user_id ) {
		$index = $this->get_group_membership_index();
		return $index[ $user_id ] ?? [];
	}

	/**
	 * Build (once per request) a map of user ID → their group memberships, each
	 * shaped as { id, plan, status, role }.
	 *
	 * Iterating the site's (few, memoized) groups and reading each group's people
	 * once is far cheaper than resolving every subscriber's owned/managed/member
	 * subscriptions individually while paginating a large reader list. Role
	 * precedence matches the per-group display: owner (the subscription customer),
	 * else manager (in get_managers), else member.
	 *
	 * @return array<int,array<int,array>> User ID → list of membership entries.
	 */
	private function get_group_membership_index() {
		if ( null !== $this->group_membership_index ) {
			return $this->group_membership_index;
		}
		$index = [];
		if ( class_exists( '\Newspack\Group_Subscription' ) ) {
			foreach ( $this->get_group_subscriptions() as $group ) {
				$subscription = $group['subscription'];
				$owner_id     = (int) $subscription->get_user_id();
				$managers     = array_map( 'intval', Group_Subscription::get_managers( $subscription ) );
				$entry        = [
					'id'     => (int) $subscription->get_id(),
					'plan'   => (string) $group['settings']['name'],
					'status' => self::map_subscription_status( $subscription->get_status() ),
				];
				foreach ( array_map( 'intval', Group_Subscription::get_all_members( $subscription ) ) as $member_id ) {
					if ( $member_id === $owner_id ) {
						$role = 'owner';
					} elseif ( in_array( $member_id, $managers, true ) ) {
						$role = 'manager';
					} else {
						$role = 'member';
					}
					$index[ $member_id ][] = array_merge( $entry, [ 'role' => $role ] );
				}
			}
		}
		$this->group_membership_index = $index;
		return $index;
	}

	/**
	 * Reduce a reader's many subscription statuses to a single subscriber-level
	 * status, mirroring the list's badge logic: active-first, with cancelled
	 * dropped whenever any live status remains. Empty when they have no
	 * subscription at all (a free reader shows no status badge).
	 *
	 * @param array $subscriptions Individual subscription entries.
	 * @param array $groups        Group membership entries.
	 *
	 * @return string One of 'active' | 'pending' | 'on-hold' | 'cancelled' | ''.
	 */
	private function reduced_status( $subscriptions, $groups ) {
		$statuses = array_values(
			array_unique(
				array_filter(
					array_merge(
						array_column( $subscriptions, 'status' ),
						array_column( $groups, 'status' )
					)
				)
			)
		);
		if ( empty( $statuses ) ) {
			return '';
		}
		$rank = [
			'active'    => 0,
			'pending'   => 1,
			'on-hold'   => 2,
			'cancelled' => 3,
		];
		$live = array_filter( $statuses, fn( $status ) => 'cancelled' !== $status );
		if ( ! empty( $live ) ) {
			$statuses = $live;
		}
		usort( $statuses, fn( $a, $b ) => ( $rank[ $a ] ?? 99 ) - ( $rank[ $b ] ?? 99 ) );
		return reset( $statuses );
	}

	/**
	 * The date of a reader's most recent completed/processing order, or null.
	 *
	 * @param int $user_id The reader user ID.
	 *
	 * @return string|null 'YYYY-MM-DD', or null when they have no paid order.
	 */
	private function last_payment_date( $user_id ) {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return null;
		}
		$orders = \wc_get_orders(
			[
				'customer_id' => $user_id,
				'status'      => [ 'wc-completed', 'wc-processing' ],
				'limit'       => 1,
				'orderby'     => 'date',
				'order'       => 'DESC',
			]
		);
		if ( empty( $orders ) ) {
			return null;
		}
		$paid = $orders[0]->get_date_paid();
		return $paid ? gmdate( 'Y-m-d', $paid->getTimestamp() ) : null;
	}

	/**
	 * Resolve the `include` user-ID set for the active subscription-status / plan
	 * filters, or null when neither is present.
	 *
	 * Each active filter resolves to a set of customer IDs; the sets are
	 * intersected so multiple filters narrow (AND) rather than widen. The result
	 * is capped at FILTER_INCLUDE_CAP to keep the WP_User_Query IN() clause bounded
	 * on large stores. Above that ceiling the extras are dropped, so the reported
	 * total/pages under-count — an accepted trade-off at a scale (10k+ filtered
	 * subscribers) where pixel-accurate paging matters less than not OOMing.
	 *
	 * @param \WP_REST_Request $request The request.
	 *
	 * @return int[]|null Customer IDs to include, or null when no filter applies.
	 */
	private function resolve_filter_include( $request ) {
		$status_filter = array_filter( array_map( 'sanitize_text_field', (array) $request->get_param( 'status' ) ) );
		$plan_filter   = array_filter( array_map( 'sanitize_text_field', (array) $request->get_param( 'plan' ) ) );
		if ( empty( $status_filter ) && empty( $plan_filter ) ) {
			return null;
		}

		$sets = [];
		if ( ! empty( $status_filter ) ) {
			$sets[] = $this->customer_ids_for_statuses( $status_filter );
		}
		if ( ! empty( $plan_filter ) ) {
			$sets[] = $this->customer_ids_for_plans( $plan_filter );
		}

		$include = array_shift( $sets );
		foreach ( $sets as $set ) {
			$include = array_intersect( $include, $set );
		}

		return array_values( array_unique( array_slice( $include, 0, self::FILTER_INCLUDE_CAP ) ) );
	}

	/**
	 * Customer IDs whose displayed status matches any of the given prototype
	 * statuses, mirroring the list's badge reduction (see reduced_status /
	 * displayStatuses): a live status always qualifies, but `cancelled` matches
	 * only fully-churned readers — anyone who also holds a live (active/pending/
	 * on-hold) subscription is dropped, since the badge hides cancelled while a
	 * live plan remains. Without this the Cancelled filter would surface readers
	 * whose row still reads Active.
	 *
	 * The live-subscription scan backing that exclusion is itself bounded by
	 * FILTER_INCLUDE_CAP, so on a store past that ceiling a reader whose only live
	 * plan sits beyond the cap could slip into the Cancelled set — an accepted
	 * edge at a scale where exact filtering matters less than a bounded query.
	 *
	 * @param string[] $prototype_statuses Prototype statuses (active/pending/on-hold/cancelled).
	 *
	 * @return int[]
	 */
	private function customer_ids_for_statuses( array $prototype_statuses ) {
		// Non-cancelled statuses are always displayed, so any matching subscription
		// qualifies the reader.
		$ids = $this->customer_ids_for_raw_statuses( array_values( array_diff( $prototype_statuses, [ 'cancelled' ] ) ) );

		// Cancelled matches only readers with no live plan.
		if ( in_array( 'cancelled', $prototype_statuses, true ) ) {
			$cancelled_ids = $this->customer_ids_for_raw_statuses( [ 'cancelled' ] );
			$live_ids      = $this->customer_ids_for_raw_statuses( [ 'active', 'pending', 'on-hold' ] );
			$ids           = array_merge( $ids, array_diff( $cancelled_ids, $live_ids ) );
		}

		return array_values( array_unique( array_filter( $ids ) ) );
	}

	/**
	 * Customer IDs holding a subscription (individual or group) in any of the
	 * given prototype statuses, without the display reduction — a raw status
	 * match. customer_ids_for_statuses() layers the cancelled reduction on top.
	 *
	 * @param string[] $prototype_statuses Prototype statuses (active/pending/on-hold/cancelled).
	 *
	 * @return int[]
	 */
	private function customer_ids_for_raw_statuses( array $prototype_statuses ) {
		if ( empty( $prototype_statuses ) ) {
			return [];
		}
		$ids = [];

		// Individual + owned subscriptions in a matching WCS status (HPOS-safe).
		// Bounded to the same ceiling the include set is capped at, so the filter
		// path can't load an unbounded number of subscription objects on a large store.
		$wcs_statuses = $this->wcs_statuses_for( $prototype_statuses );
		if ( ! empty( $wcs_statuses ) && function_exists( 'wcs_get_subscriptions' ) ) {
			$subs = \wcs_get_subscriptions(
				[
					'subscriptions_per_page' => self::FILTER_INCLUDE_CAP,
					'subscription_status'    => $wcs_statuses,
				]
			);
			foreach ( $subs as $subscription ) {
				$ids[] = (int) $subscription->get_customer_id();
			}
		}

		// Group members inherit their group's status.
		foreach ( $this->get_group_subscriptions() as $group ) {
			if ( in_array( self::map_subscription_status( $group['subscription']->get_status() ), $prototype_statuses, true ) ) {
				$ids = array_merge( $ids, array_map( 'intval', Group_Subscription::get_all_members( $group['subscription'] ) ) );
			}
		}

		return array_values( array_unique( array_filter( $ids ) ) );
	}

	/**
	 * Customer IDs holding a subscription (individual or group) on any of the
	 * named plans.
	 *
	 * @param string[] $plan_names Plan display names.
	 *
	 * @return int[]
	 */
	private function customer_ids_for_plans( array $plan_names ) {
		$ids = [];

		// Group plans, matched by the group's configured name.
		foreach ( $this->get_group_subscriptions() as $group ) {
			if ( in_array( (string) $group['settings']['name'], $plan_names, true ) ) {
				$ids = array_merge( $ids, array_map( 'intval', Group_Subscription::get_all_members( $group['subscription'] ) ) );
			}
		}

		// Individual plans, matched by product name → subscriptions for that product.
		$product_ids = $this->product_ids_for_names( $plan_names );
		if ( ! empty( $product_ids ) && function_exists( 'wcs_get_subscriptions_for_product' ) && function_exists( 'wcs_get_subscription' ) ) {
			$subscription_ids = [];
			foreach ( $product_ids as $product_id ) {
				foreach ( array_keys( \wcs_get_subscriptions_for_product( $product_id ) ) as $subscription_id ) {
					$subscription_ids[] = (int) $subscription_id;
				}
			}
			// Bound the number of subscription objects hydrated, mirroring the
			// status path's cap so a plan on a very popular product can't load an
			// unbounded set into memory.
			$subscription_ids = array_slice( array_unique( $subscription_ids ), 0, self::FILTER_INCLUDE_CAP );
			foreach ( $subscription_ids as $subscription_id ) {
				$subscription = \wcs_get_subscription( $subscription_id );
				if ( $subscription ) {
					$ids[] = (int) $subscription->get_customer_id();
				}
			}
		}

		return array_values( array_unique( array_filter( $ids ) ) );
	}

	/**
	 * Resolve product IDs whose title exactly matches any of the given names.
	 *
	 * @param string[] $names Product names.
	 *
	 * @return int[] Matching product IDs.
	 */
	private function product_ids_for_names( array $names ) {
		$ids = [];
		foreach ( array_unique( $names ) as $name ) {
			// Product titles aren't unique, so collect every published product that
			// carries this exact name rather than just the first match.
			$query = new \WP_Query(
				[
					'post_type'              => [ 'product', 'product_variation' ],
					'post_status'            => 'publish',
					'title'                  => $name,
					// Bounded to the filter cap; product titles collide rarely, so
					// this ceiling is only a runaway guard.
					'posts_per_page'         => self::FILTER_INCLUDE_CAP,
					'fields'                 => 'ids',
					'no_found_rows'          => true,
					'update_post_meta_cache' => false,
					'update_post_term_cache' => false,
				]
			);
			foreach ( $query->posts as $post_id ) {
				$ids[] = (int) $post_id;
			}
		}
		return array_values( array_unique( $ids ) );
	}

	/**
	 * Map prototype statuses onto the WooCommerce Subscriptions statuses that
	 * collapse into them (the inverse of map_subscription_status()).
	 *
	 * @param string[] $prototype_statuses Prototype statuses.
	 *
	 * @return string[] Distinct WCS status slugs.
	 */
	private function wcs_statuses_for( array $prototype_statuses ) {
		// on-hold is the catch-all display bucket in map_subscription_status(), so its
		// inverse carries the extra WCS statuses that also render as on-hold (e.g. a
		// mid-switch 'switched' subscription) to keep display and filter in step. Truly
		// unknown/custom statuses still can't be enumerated here — they display as
		// on-hold but aren't reachable by the individual-subscription filter scan.
		$map = [
			'active'    => [ 'active', 'pending-cancel' ],
			'pending'   => [ 'pending' ],
			'on-hold'   => [ 'on-hold', 'switched' ],
			'cancelled' => [ 'cancelled', 'expired' ],
		];
		$wcs = [];
		foreach ( $prototype_statuses as $status ) {
			if ( isset( $map[ $status ] ) ) {
				$wcs = array_merge( $wcs, $map[ $status ] );
			}
		}
		return array_values( array_unique( $wcs ) );
	}

	/**
	 * Resolve avatar URLs for a set of emails via core get_avatar_url(), which
	 * honors the Settings → Discussion avatar options and any avatar plugin.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return \WP_REST_Response
	 */
	public function api_get_avatars( $request ) {
		if ( ! get_option( 'show_avatars', true ) ) {
			return rest_ensure_response( [ 'show' => false ] );
		}
		// Callers request 2x their render size so avatars stay crisp on high-DPR
		// displays (list: 32px → 64, profile: 64px → 128).
		$size    = $request->get_param( 'size' );
		$avatars = [];
		// A single list page resolves at most `per_page` (≤100) avatars; bound the
		// batch so an oversized payload can't fan out into unbounded get_avatar_url() calls.
		$emails = array_slice( (array) $request->get_param( 'emails' ), 0, 200 );
		foreach ( $emails as $email ) {
			$email = sanitize_email( $email );
			if ( '' === $email ) {
				continue;
			}
			$avatars[ $email ] = get_avatar_url( $email, [ 'size' => $size ] );
		}
		return rest_ensure_response(
			[
				'show'    => true,
				'avatars' => $avatars,
			]
		);
	}

	/**
	 * Enqueue Subscribers wizard scripts and styles.
	 */
	public function enqueue_scripts_and_styles() {
		parent::enqueue_scripts_and_styles();

		if ( ! $this->is_wizard_page() || ! $this->is_feature_enabled() ) {
			return;
		}

		// Fall back gracefully when the built asset manifest is absent (fresh
		// checkout before a build, or a partial deploy) rather than emitting warnings.
		$asset_path = NEWSPACK_ABSPATH . 'dist/subscribers.asset.php';
		$asset      = file_exists( $asset_path ) ? include $asset_path : [];

		wp_enqueue_script(
			'newspack-subscribers',
			Newspack::plugin_url() . '/dist/subscribers.js',
			$asset['dependencies'] ?? [],
			$asset['version'] ?? NEWSPACK_PLUGIN_VERSION,
			true
		);

		// Mirror the publisher's configurable group/team label so the wizard stays
		// consistent with the Audience → Setup "Group labels" override.
		$group_label_singular = class_exists( '\Newspack\Group_Subscription' )
			? Group_Subscription::get_label( 'singular' )
			: __( 'Group', 'newspack-plugin' );
		$group_label_plural = class_exists( '\Newspack\Group_Subscription' )
			? Group_Subscription::get_label( 'plural' )
			: __( 'Groups', 'newspack-plugin' );

		wp_add_inline_script(
			'newspack-subscribers',
			'window.newspackSubscribers = ' . wp_json_encode(
				[
					'groupLabel'       => $group_label_singular,
					'groupLabelPlural' => $group_label_plural,
					// Drives the column layout synchronously; the avatar URLs
					// themselves come from the /avatars REST endpoint.
					'showAvatars'      => (bool) get_option( 'show_avatars', true ),
				]
			) . ';',
			'before'
		);

		wp_enqueue_style(
			'newspack-subscribers',
			Newspack::plugin_url() . '/dist/subscribers.css',
			[ 'wp-components' ],
			NEWSPACK_PLUGIN_VERSION
		);
	}
}
