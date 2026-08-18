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
	 * Upper bound on the number of avatars one /avatars request resolves. A single
	 * list page needs at most `per_page` (≤100); the client batches larger sets.
	 */
	const AVATAR_BATCH_CAP = 200;

	/**
	 * How many subscriptions the filter scan hydrates at a time. The scan itself
	 * is bounded by FILTER_INCLUDE_CAP, but WooCommerce returns fully hydrated
	 * WC_Subscription objects and only their customer ID is needed here — walking
	 * the set a page at a time keeps peak memory at one chunk rather than the
	 * whole cap. See customer_ids_for_raw_statuses().
	 */
	const FILTER_SCAN_CHUNK = 500;

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
	 * Per-request memo of customer IDs keyed by the sorted status set they were
	 * resolved for. See customer_ids_for_raw_statuses().
	 *
	 * @var array<string,int[]>
	 */
	private $raw_status_ids_cache = [];

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
						'type'              => 'array',
						'required'          => true,
						'items'             => [ 'type' => 'string' ],
						// Sanitized declaratively, like the sibling `size` arg. Invalid
						// entries are dropped rather than failing the whole request:
						// one malformed address shouldn't blank a page of avatars.
						'sanitize_callback' => [ __CLASS__, 'sanitize_emails_arg' ],
						'validate_callback' => 'rest_validate_request_arg',
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
						'validate_callback' => 'rest_validate_request_arg',
					],
				],
			]
		);

		// The single-subscriber read backing the person profile (L1). Registered
		// after the collection route; the two regexes are disjoint, so order is
		// presentational rather than load-bearing.
		register_rest_route(
			NEWSPACK_API_NAMESPACE,
			'/wizard/' . $this->slug . '/subscribers/(?P<id>\d+)',
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ $this, 'api_get_subscriber' ],
				'permission_callback' => [ $this, 'api_permissions_check' ],
				'args'                => [
					'id' => [
						'type'              => 'integer',
						'required'          => true,
						'minimum'           => 1,
						'sanitize_callback' => 'absint',
						'validate_callback' => 'rest_validate_request_arg',
					],
				],
			]
		);

		// The wizard's first person-profile write endpoints: on-hold recovery
		// (NPPD-1753). Both act on one individual subscription; group money
		// actions deliberately live with the group surface instead.
		register_rest_route(
			NEWSPACK_API_NAMESPACE,
			'/wizard/' . $this->slug . '/subscriptions/(?P<id>\d+)/reactivate',
			[
				'methods'             => \WP_REST_Server::EDITABLE,
				'callback'            => [ $this, 'api_reactivate_subscription' ],
				'permission_callback' => [ $this, 'api_permissions_check' ],
				'args'                => [
					'id'   => [
						'type'              => 'integer',
						'required'          => true,
						'minimum'           => 1,
						'sanitize_callback' => 'absint',
						'validate_callback' => 'rest_validate_request_arg',
					],
					'mode' => [
						'type'              => 'string',
						'required'          => true,
						'enum'              => [ 'free', 'charge' ],
						'validate_callback' => 'rest_validate_request_arg',
					],
				],
			]
		);

		register_rest_route(
			NEWSPACK_API_NAMESPACE,
			'/wizard/' . $this->slug . '/subscriptions/(?P<id>\d+)/payment-link',
			[
				'methods'             => \WP_REST_Server::EDITABLE,
				'callback'            => [ $this, 'api_send_payment_link' ],
				'permission_callback' => [ $this, 'api_permissions_check' ],
				'args'                => [
					'id' => [
						'type'              => 'integer',
						'required'          => true,
						'minimum'           => 1,
						'sanitize_callback' => 'absint',
						'validate_callback' => 'rest_validate_request_arg',
					],
				],
			]
		);
	}

	/**
	 * Sanitize the /avatars `emails` arg: valid addresses only, deduplicated and
	 * bounded to AVATAR_BATCH_CAP.
	 *
	 * Invalid entries are dropped rather than rejected so a single malformed
	 * address can't fail the whole batch.
	 *
	 * @param mixed $emails The raw `emails` request arg.
	 *
	 * @return string[] Sanitized, unique, capped email addresses.
	 */
	public static function sanitize_emails_arg( $emails ) {
		$sanitized = array_filter( array_map( 'sanitize_email', (array) $emails ) );
		return array_values( array_slice( array_unique( $sanitized ), 0, self::AVATAR_BATCH_CAP ) );
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
		$this->reset_request_caches();
		$items = [];
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
		$created_at = $this->local_date( $created ? $created->getTimestamp() : null );

		return [
			'id'          => (int) $subscription->get_id(),
			'ownerId'     => $owner_id,
			'owner'       => $owner ? [
				'id'      => $owner_id,
				'name'    => $owner->display_name,
				'email'   => $owner->user_email,
				// The owner's name links to the person, not to the subscription —
				// see the group list's `editUrl` for the subscription itself.
				'editUrl' => (string) get_edit_user_link( $owner_id ),
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
			'editUrl'     => $this->subscription_edit_url( $subscription ),
			// Seat requests are surfaced in a later slice (NPPD-1753 PR 7).
			'seatRequest' => null,
		];
	}

	/**
	 * The admin edit-screen URL for a subscription (HPOS-safe).
	 *
	 * Every subscription the wizard surfaces — a group, a group membership, or an
	 * individual plan — carries this so a plan name always links to that plan,
	 * while a person's name always links to that person.
	 *
	 * @param \WC_Subscription $subscription The subscription.
	 *
	 * @return string The edit URL, or '' when it can't be resolved.
	 */
	private function subscription_edit_url( $subscription ) {
		return method_exists( $subscription, 'get_edit_order_url' ) ? (string) $subscription->get_edit_order_url() : '';
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
		$this->reset_request_caches();
		$per_page = (int) $request->get_param( 'per_page' );
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
	 * GET one subscriber in full, hydrated for the admin person profile (L1).
	 *
	 * Where the collection read returns just enough to draw a row, this returns
	 * everything one person's profile shows: every subscription they hold —
	 * individual and group alike — each with the billing detail its card renders.
	 *
	 * RESPONSE SHAPE (NPPD-1753 §3.3) — a group arrives as a WHOLE OBJECT, not an
	 * ID the client resolves. A group card needs the group's name, status, seat
	 * usage, owner identity, billing rate and this reader's role and joined date;
	 * with IDs the screen would have to issue a request per group just to draw a
	 * card it already knows it needs. The embedded object is prepare_group()'s
	 * output — the same shape the /groups collection returns — plus billing,
	 * `role` and `joinedAt`, so the group-detail read can hand back the same
	 * object and both screens can share one card component.
	 *
	 * Any user who belongs to the current site resolves, not only reader-role
	 * ones: a group owner or manager is often an editor or shop manager, and their
	 * profile must still be reachable from the group they own. The lookup is bound
	 * to the current site (is_user_member_of_blog) so that on multisite this can
	 * only read users an admin already administers here, matching the reach of the
	 * native user-edit screen this stands in for — not every user on the network.
	 *
	 * @param \WP_REST_Request $request The request.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function api_get_subscriber( $request ) {
		$this->reset_request_caches();

		$user_id = (int) $request->get_param( 'id' );
		$user    = get_userdata( $user_id );
		// A missing user, or (on multisite) one who is not a member of this site,
		// is a 404 distinguished from an empty profile so the screen can say "no
		// such subscriber" rather than render a blank person.
		if ( ! $user || ! is_user_member_of_blog( $user_id, get_current_blog_id() ) ) {
			return new \WP_Error(
				'newspack_subscriber_not_found',
				__( 'Subscriber not found.', 'newspack-plugin' ),
				[ 'status' => 404 ]
			);
		}

		return rest_ensure_response( $this->prepare_subscriber( $user, true ) );
	}

	/**
	 * POST one on-hold subscription back to active (NPPD-1753 on-hold recovery).
	 *
	 * Two modes, chosen by the admin in the reactivate flow:
	 *
	 * - `free`: no payment. WCS's own on-hold → active transition recalculates
	 *   the next payment date when the stored one is in the past, so billing
	 *   resumes on the regular schedule without charging anything now.
	 * - `charge`: process a renewal against the saved payment method — see
	 *   process_renewal_charge() for how the charge is dispatched and how its
	 *   outcome is decided.
	 *
	 * @param \WP_REST_Request $request The request.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function api_reactivate_subscription( $request ) {
		$this->reset_request_caches();

		$subscription = $this->resolve_on_hold_subscription( (int) $request->get_param( 'id' ) );
		if ( \is_wp_error( $subscription ) ) {
			return $subscription;
		}

		$admin_login = wp_get_current_user()->user_login;

		if ( 'charge' === $request->get_param( 'mode' ) ) {
			return $this->process_renewal_charge( $subscription, $admin_login );
		}

		// Real WCS throws when the transition is not allowed — e.g. the
		// subscription's product no longer exists. That is a business-rule
		// refusal, not a server fault: surface it as a conflict, with the
		// likely causes named, since WCS's own message names none. One known
		// divergence: WCS lets an admin override the unavailable-product
		// refusal only inside wp-admin (`is_admin()`), which a REST request is
		// not — so the native subscription screen may still allow what this
		// endpoint refuses; the message points there.
		try {
			$subscription->update_status( 'active' );
		} catch ( \Exception $e ) {
			return new \WP_Error(
				'newspack_subscribers_reactivate_failed',
				sprintf(
					/* translators: %s: the refusal reported by WooCommerce. */
					__( '%s The subscription\'s product may no longer be purchasable, or its term may have ended. Reactivating from the WooCommerce subscription screen may still be possible.', 'newspack-plugin' ),
					$e->getMessage()
				),
				[ 'status' => 409 ]
			);
		}

		// After the transition, so a refused reactivation never leaves a note
		// claiming one happened — the note is the audit trail.
		/* translators: %s: the acting admin's login. */
		$subscription->add_order_note( sprintf( __( 'Reactivated without payment by %s from the Subscribers admin.', 'newspack-plugin' ), $admin_login ) );

		return rest_ensure_response( $this->reactivation_payload( $subscription ) );
	}

	/**
	 * Charge a renewal for an on-hold subscription against its saved payment
	 * method, and decide the outcome honestly.
	 *
	 * Dispatch: the gateway leg of WCS's renewal chain
	 * (WC_Subscriptions_Payment_Gateways::gateway_scheduled_subscription_payment),
	 * called directly rather than via the `woocommerce_scheduled_subscription_payment`
	 * action. The umbrella action is how WCS's retry system recognises a
	 * *scheduled* attempt (WCS_Retry_Manager checks `doing_action()` on it), so
	 * firing it here would enroll every admin click in the automatic retry
	 * ladder — whose final rule on Newspack sites expires the subscription. The
	 * direct call charges the same order through the same gateway hook without
	 * impersonating the billing schedule.
	 *
	 * Outcome: a gateway charge is not always synchronous — Stripe can leave the
	 * money in flight (a charge awaiting asynchronous capture or manual review,
	 * an SCA charge awaiting the customer's authentication, a mandated debit
	 * scheduled for later). So the outcome is read from state, three ways:
	 * the subscription reactivated (success); the renewal order no longer needs
	 * payment but the subscription is not active yet (payment in flight —
	 * reported as pending, NOT as a failure, so nobody retries a live charge);
	 * otherwise a failure that names both possible causes.
	 *
	 * Concurrency: a short-lived per-subscription transient lock guards the
	 * dispatch. It is best-effort — the check-then-set is not atomic and the
	 * TTL bounds a stuck request rather than the charge — but it closes the
	 * re-click and two-admins window from a full gateway round-trip to
	 * milliseconds, and neither WCS nor the gateways deduplicate renewal
	 * attempts on their own.
	 *
	 * @param \WC_Subscription $subscription The on-hold subscription.
	 * @param string           $admin_login  The acting admin's login, for the audit note.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	private function process_renewal_charge( $subscription, $admin_login ) {
		if ( ! $this->can_charge( $subscription ) ) {
			return new \WP_Error(
				'newspack_subscribers_cannot_charge',
				__( 'This subscription has no payment method on file that can be charged. Send a payment link instead.', 'newspack-plugin' ),
				[ 'status' => 409 ]
			);
		}

		$lock_key = 'newspack_subscribers_charge_' . $subscription->get_id();
		if ( false !== get_transient( $lock_key ) ) {
			return new \WP_Error(
				'newspack_subscribers_charge_in_progress',
				__( 'A charge for this subscription is already being processed. Refresh the profile to see the outcome.', 'newspack-plugin' ),
				[ 'status' => 409 ]
			);
		}
		set_transient( $lock_key, 1, MINUTE_IN_SECONDS );

		try {
			$renewal_order = $this->latest_or_new_renewal_order( $subscription );
			if ( \is_wp_error( $renewal_order ) ) {
				return $renewal_order;
			}

			/* translators: %s: the acting admin's login. */
			$subscription->add_order_note( sprintf( __( 'Renewal payment initiated by %s from the Subscribers admin.', 'newspack-plugin' ), $admin_login ) );

			// The gateway leg of the renewal chain (see the method docblock for
			// why it is called directly), with the umbrella action as the
			// fallback for an environment where WCS's handler is absent.
			if ( class_exists( 'WC_Subscriptions_Payment_Gateways' ) ) {
				\WC_Subscriptions_Payment_Gateways::gateway_scheduled_subscription_payment( $subscription->get_id() );
			} else {
				do_action( 'woocommerce_scheduled_subscription_payment', $subscription->get_id() );
			}

			$subscription  = function_exists( 'wcs_get_subscription' ) ? \wcs_get_subscription( $subscription->get_id() ) : $subscription;
			$renewal_order = function_exists( 'wc_get_order' ) ? \wc_get_order( $renewal_order->get_id() ) : $renewal_order;

			if ( $subscription && 'active' === $subscription->get_status() ) {
				return rest_ensure_response( $this->reactivation_payload( $subscription ) );
			}

			// The order stopped needing payment but the subscription did not
			// reactivate: the money is in flight (asynchronous capture, manual
			// review, a webhook-confirmed gateway). Not a failure — saying
			// "declined" here is what gets a live charge retried.
			if ( $subscription && $renewal_order && ! $renewal_order->needs_payment() ) {
				return rest_ensure_response(
					array_merge(
						$this->reactivation_payload( $subscription ),
						[ 'pendingConfirmation' => true ]
					)
				);
			}

			return new \WP_Error(
				'newspack_subscribers_charge_failed',
				__( 'The payment did not complete. The card may have been declined, or the charge may be waiting for the subscriber to authenticate it — check the renewal order\'s notes before retrying or sending a payment link.', 'newspack-plugin' ),
				[ 'status' => 402 ]
			);
		} finally {
			delete_transient( $lock_key );
		}
	}

	/**
	 * POST a payment link to the subscription's customer.
	 *
	 * The link is the unpaid renewal order's checkout payment URL — the same
	 * order the failed renewal left behind, so paying it resumes the existing
	 * billing history rather than starting a parallel one. The customer gets it
	 * by email (WooCommerce's customer invoice email, which carries the pay
	 * link), and the URL is returned so the admin can hand it over directly.
	 *
	 * @param \WP_REST_Request $request The request.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function api_send_payment_link( $request ) {
		$this->reset_request_caches();

		$subscription = $this->resolve_on_hold_subscription( (int) $request->get_param( 'id' ) );
		if ( \is_wp_error( $subscription ) ) {
			return $subscription;
		}

		// While a charge is mid-flight the renewal order still needs payment,
		// so without this check the customer would be emailed a pay link for
		// the very order the gateway is charging — and paying it is a second
		// real payment. Same lock the charge path holds.
		if ( false !== get_transient( 'newspack_subscribers_charge_' . $subscription->get_id() ) ) {
			return new \WP_Error(
				'newspack_subscribers_charge_in_progress',
				__( 'A charge for this subscription is being processed. Wait for it to finish before sending a payment link.', 'newspack-plugin' ),
				[ 'status' => 409 ]
			);
		}

		$renewal_order = $this->latest_or_new_renewal_order( $subscription );
		if ( \is_wp_error( $renewal_order ) ) {
			return $renewal_order;
		}

		// `emailSent` is honest to what can be known here: the invoice email
		// no-ops without a recipient, so an order with no billing email means no
		// email went out — and the client falls back to showing the URL itself.
		$email_sent = false;
		if ( function_exists( 'WC' ) && \WC()->mailer() && '' !== (string) $renewal_order->get_billing_email() ) {
			// The same envelope WC core's own "Email invoice" order action uses,
			// so email-logging integrations see this send too.
			do_action( 'woocommerce_before_resend_order_emails', $renewal_order, 'customer_invoice' );
			\WC()->mailer()->customer_invoice( $renewal_order );
			do_action( 'woocommerce_after_resend_order_emails', $renewal_order, 'customer_invoice' );
			$email_sent = true;
			/* translators: %s: the acting admin's login. */
			$subscription->add_order_note( sprintf( __( 'Payment link emailed to the customer by %s from the Subscribers admin.', 'newspack-plugin' ), wp_get_current_user()->user_login ) );
		}

		return rest_ensure_response(
			[
				'paymentUrl' => $renewal_order->get_checkout_payment_url(),
				'emailSent'  => $email_sent,
			]
		);
	}

	/**
	 * Resolve an id to an individual on-hold subscription, or the error that
	 * explains why the write cannot proceed.
	 *
	 * A group-enabled subscription reports not-found: its money actions belong
	 * to the group surface, and this route not acknowledging it keeps the two
	 * surfaces from drifting into two ways of charging the same group.
	 *
	 * @param int $subscription_id The subscription ID.
	 *
	 * @return \WC_Subscription|\WP_Error
	 */
	private function resolve_on_hold_subscription( $subscription_id ) {
		$subscription = function_exists( 'wcs_get_subscription' ) ? \wcs_get_subscription( $subscription_id ) : false;
		$settings     = $subscription && class_exists( '\Newspack\Group_Subscription_Settings' )
			? Group_Subscription_Settings::get_subscription_settings( $subscription )
			: [];
		if ( ! $subscription || ! empty( $settings['enabled'] ) ) {
			return new \WP_Error(
				'newspack_subscribers_subscription_not_found',
				__( 'That subscription could not be found.', 'newspack-plugin' ),
				[ 'status' => 404 ]
			);
		}
		// On-hold only, and raw status rather than the mapped vocabulary: the
		// mapped "on-hold" bucket also absorbs unknown WCS statuses, which are
		// exactly the ones a recovery write should not touch blind.
		if ( 'on-hold' !== $subscription->get_status() ) {
			return new \WP_Error(
				'newspack_subscribers_not_on_hold',
				__( 'This subscription is not on hold, so it cannot be reactivated.', 'newspack-plugin' ),
				[ 'status' => 409 ]
			);
		}
		return $subscription;
	}

	/**
	 * Whether a renewal charge could even be attempted: an automatic payment
	 * method is on file, its gateway is actually registered (a stored gateway id
	 * whose plugin was deactivated would fire a charge into a void), there is an
	 * amount to charge, and the gateway lets WCS trigger the charge (rather than
	 * scheduling payments itself, in which case an admin-triggered charge would
	 * double-bill).
	 *
	 * @param \WC_Subscription $subscription The subscription.
	 *
	 * @return bool
	 */
	private function can_charge( $subscription ) {
		return ! $subscription->is_manual()
			&& '' !== (string) $subscription->get_payment_method()
			&& (float) $subscription->get_total() > 0
			&& ! $subscription->payment_method_supports( 'gateway_scheduled_payments' )
			&& function_exists( 'wc_get_payment_gateway_by_order' )
			&& (bool) \wc_get_payment_gateway_by_order( $subscription );
	}

	/**
	 * The renewal order recovery acts on, creating one when the latest is settled.
	 *
	 * Selection matches the code that will actually charge:
	 * WC_Subscriptions_Payment_Gateways::gateway_scheduled_subscription_payment()
	 * acts on `get_last_order( 'all', 'renewal' )` — the newest renewal order,
	 * paid or not — and skips it when it no longer needs payment. Selecting any
	 * *other* order here (say, an older unpaid one behind a newer settled one)
	 * would name and email one order while the gateway charges, or skips,
	 * another. So: reuse the latest renewal order when it still needs payment —
	 * the common failed-renewal case — otherwise create a fresh one, which then
	 * IS the latest, keeping the named order and the charged order the same
	 * object.
	 *
	 * @param \WC_Subscription $subscription The subscription.
	 *
	 * @return \WC_Order|\WP_Error
	 */
	private function latest_or_new_renewal_order( $subscription ) {
		$latest_renewal = $subscription->get_last_order( 'all', [ 'renewal' ] );
		if ( is_object( $latest_renewal ) && $latest_renewal->needs_payment() ) {
			return $latest_renewal;
		}

		// A latest renewal order held on-hold WITH a transaction recorded means
		// a gateway payment is in flight — a charge awaiting asynchronous
		// capture or manual review parks the order there, with the money
		// possibly already moved. Creating (and charging or invoicing) a
		// second order underneath it is how a subscriber gets billed twice.
		// The transaction id is the discriminator: offline gateways (BACS,
		// cheque) also park orders on-hold but record no transaction, and for
		// those the payment link IS the remedy, so they must not dead-end
		// here. Settled orders — completed or processing (an admin-suspended
		// subscription after a successful renewal), cancelled, refunded —
		// don't block a fresh attempt either.
		if ( is_object( $latest_renewal ) && $latest_renewal->has_status( [ 'on-hold' ] ) && '' !== (string) $latest_renewal->get_transaction_id() ) {
			return new \WP_Error(
				'newspack_subscribers_payment_unresolved',
				__( 'A payment for this subscription is still awaiting confirmation. Wait for it to resolve before charging again or sending a payment link.', 'newspack-plugin' ),
				[ 'status' => 409 ]
			);
		}

		$renewal_order = function_exists( 'wcs_create_renewal_order' ) ? \wcs_create_renewal_order( $subscription ) : false;
		if ( ! $renewal_order || \is_wp_error( $renewal_order ) ) {
			return new \WP_Error(
				'newspack_subscribers_renewal_order_failed',
				__( 'A renewal order could not be created for this subscription.', 'newspack-plugin' ),
				[ 'status' => 500 ]
			);
		}
		if ( $subscription->is_manual() ) {
			// Parity with WCS's process_renewal() for manual subscriptions, so
			// integrations listening for manual renewal orders see this one too.
			do_action( 'woocommerce_generated_manual_renewal_order', $renewal_order->get_id(), $subscription );
			$renewal_order->add_order_note( __( 'Manual renewal order awaiting customer payment.', 'newspack-plugin' ) );
		} elseif ( function_exists( 'wc_get_payment_gateway_by_order' ) ) {
			// Carry the subscription's gateway onto the fresh order so the charge
			// path knows what to trigger — same as WCS's process_renewal().
			$gateway = \wc_get_payment_gateway_by_order( $subscription );
			if ( $gateway ) {
				$renewal_order->set_payment_method( $gateway );
				$renewal_order->save();
			}
		}
		return $renewal_order;
	}

	/**
	 * What the reactivate flow needs to confirm the outcome: the mapped status
	 * plus the next billing date WCS recalculated.
	 *
	 * @param \WC_Subscription $subscription The subscription.
	 *
	 * @return array
	 */
	private function reactivation_payload( $subscription ) {
		return [
			'status'          => self::map_subscription_status( $subscription->get_status() ),
			'nextBillingDate' => $this->subscription_date( $subscription, 'next_payment' ),
		];
	}

	/**
	 * Clear the per-request memoized caches at the start of each endpoint.
	 *
	 * Every callback resolves group data fresh; the memos exist only to avoid
	 * re-resolving within a single request, so each entry point starts clean.
	 */
	private function reset_request_caches() {
		$this->group_subscriptions_cache = null;
		$this->group_membership_index    = null;
		$this->raw_status_ids_cache      = [];
	}

	/**
	 * Shape a reader user for the admin subscriber list.
	 *
	 * Cost note: group memberships are indexed once per request
	 * (get_group_membership_index), but two lookups here are inherently per-row —
	 * wcs_get_users_subscriptions() and last_payment_date()'s wc_get_orders() —
	 * so a page costs ~2 × `per_page` (≤100) WooCommerce queries. Both are
	 * "newest/owned rows for one customer" lookups that WooCommerce can't answer
	 * per-customer in a single batched query: batching last_payment_date() over
	 * the page's customer IDs would need either an unbounded `limit => -1` (worse
	 * peak memory than the bounded per-row queries) or a truncated scan that
	 * silently blanks the last payment of a reader whose most recent order falls
	 * outside it. The bounded per-row shape is the correct trade-off here, and
	 * matches the existing convention elsewhere in the plugin
	 * (Sync\Contact_Metadata\Engagement::get_latest_order).
	 *
	 * @param \WP_User $user     The reader user.
	 * @param bool     $detailed Whether to hydrate the per-subscription billing
	 *                           detail the person profile renders. Off for the
	 *                           list, whose rows never show it and would pay for
	 *                           it on every row of every page.
	 *
	 * @return array
	 */
	private function prepare_subscriber( $user, $detailed = false ) {
		$user_id = (int) $user->ID;
		// Resolve the user's own subscriptions once and reuse them, so hydration
		// doesn't fetch the same list twice per row.
		$owned_subscriptions = function_exists( 'wcs_get_users_subscriptions' ) ? \wcs_get_users_subscriptions( $user_id ) : [];
		$subscriptions       = $this->get_individual_subscriptions( $user_id, $owned_subscriptions, $detailed );
		$groups              = $this->get_group_memberships( $user_id, $detailed );

		// user_registered is stored in GMT, so anchor the parse to UTC before it is
		// localized. It can also be a zero date ('0000-00-00 …'), which is truthy but
		// unparseable; guard on the parsed timestamp so it degrades to null, not 1970.
		$registered = $user->user_registered ? strtotime( $user->user_registered . ' UTC' ) : false;

		return [
			'id'            => $user_id,
			'name'          => $user->display_name,
			'email'         => $user->user_email,
			// The native user-edit screen (self edits resolve to profile.php). The
			// in-wizard profile does not yet cover editing the WordPress user, so the
			// profile keeps this as a header action rather than stranding the admin.
			'editUrl'       => get_edit_user_link( $user_id ),
			'status'        => $this->reduced_status( $subscriptions, $groups ),
			'memberSince'   => $this->local_date( $registered ),
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
	 * A reader's own (non-group) subscriptions, shaped as { id, plan, status, editUrl }.
	 *
	 * The wcs_get_users_subscriptions() list is filtered to also surface subs the
	 * user is only a member of; those are excluded here (they belong to
	 * get_group_memberships()) by keeping only subs the user actually owns and
	 * that are not group-enabled.
	 *
	 * @param int                $user_id             The reader user ID.
	 * @param \WC_Subscription[] $owned_subscriptions The user's subscriptions, already fetched by the caller.
	 * @param bool               $detailed            Whether to add the billing detail (see subscription_billing()).
	 *
	 * @return array<int,array>
	 */
	private function get_individual_subscriptions( $user_id, $owned_subscriptions, $detailed = false ) {
		$out = [];
		foreach ( $owned_subscriptions as $subscription ) {
			if ( ! $subscription instanceof \WC_Subscription || (int) $subscription->get_customer_id() !== $user_id ) {
				continue;
			}
			$settings = Group_Subscription_Settings::get_subscription_settings( $subscription );
			if ( ! empty( $settings['enabled'] ) ) {
				continue; // Group subscriptions are surfaced via get_group_memberships().
			}
			$entry = [
				'id'      => (int) $subscription->get_id(),
				'plan'    => $this->individual_plan_name( $subscription ),
				'status'  => self::map_subscription_status( $subscription->get_status() ),
				'editUrl' => $this->subscription_edit_url( $subscription ),
			];
			$out[] = $detailed
				? array_merge(
					$entry,
					$this->subscription_billing( $subscription ),
					[
						// Whether a renewal charge could even be attempted, so
						// the reactivate flow can hide "Charge now" when there
						// is nothing on file to charge.
						'canCharge'     => $this->can_charge( $subscription ),
						// Whether the reactivate action applies at all. Gated on
						// the RAW status, matching the write endpoint's rule: the
						// mapped "on-hold" bucket also absorbs unknown WCS
						// statuses, and offering an action the server will refuse
						// with a 409 is worse than not offering it.
						'canReactivate' => 'on-hold' === $subscription->get_status(),
					]
				)
				: $entry;
		}
		return $out;
	}

	/**
	 * The billing shape a subscription card renders: what it costs, how often, and
	 * the four dates the card's label/value rows show.
	 *
	 * Shared by individual subscriptions and groups so one card component renders
	 * both, and so the person profile and the group-detail billing drawer cannot
	 * disagree about a subscription's cadence or currency.
	 *
	 * Amount and currency travel as raw values rather than a pre-formatted price:
	 * a store can bill in more than one currency, and the client formats via Intl
	 * in the viewer's locale.
	 *
	 * @param \WC_Subscription $subscription The subscription.
	 *
	 * @return array
	 */
	private function subscription_billing( $subscription ) {
		$total  = $subscription->get_total();
		$period = (string) $subscription->get_billing_period();

		return [
			'amount'          => is_numeric( $total ) ? (float) $total : null,
			'currency'        => (string) $subscription->get_currency(),
			'billingPeriod'   => $period,
			'billingInterval' => (int) $subscription->get_billing_interval(),
			'startDate'       => $this->subscription_date( $subscription, 'start' ),
			'nextBillingDate' => $this->subscription_date( $subscription, 'next_payment' ),
			// When a subscription is ending — cancelled outright, or in its prepaid
			// term after a pending-cancel — the card shows this in place of the
			// next-billing row. `end` is the access-end date the reader actually
			// keeps until; `cancelled` (the moment cancellation was *requested*) is
			// only the fallback for the rare cancelled subscription with no end date
			// recorded, so the row never blanks. WCS deletes next_payment on
			// pending-cancel, so a subscription with an end date and no next payment
			// is ending even while its status still maps to active (still entitled).
			'endDate'         => $this->subscription_date( $subscription, 'end' ) ?? $this->subscription_date( $subscription, 'cancelled' ),
			'lastPayment'     => $this->subscription_date( $subscription, 'last_order_date_paid' ),
		];
	}

	/**
	 * The calendar day an instant falls on in the publisher's timezone, as a bare
	 * 'Y-m-d' string.
	 *
	 * EVERY date this wizard emits goes through here, so one profile cannot mix
	 * bases: a UTC-formatted "First subscribed" sitting directly above a localized
	 * "Last payment" can read a day apart for the same instant on a negative-offset
	 * site, and the publisher cross-checks these numbers against WooCommerce's own
	 * admin screens, which localize. wp_date() formats a Unix timestamp in the site
	 * timezone; callers are responsible for handing over a UTC-anchored timestamp.
	 *
	 * @param int|false|null $timestamp A Unix timestamp, or a falsy value when the date is unset.
	 *
	 * @return string|null 'YYYY-MM-DD', or null when there is no timestamp.
	 */
	private function local_date( $timestamp ) {
		return $timestamp ? wp_date( 'Y-m-d', (int) $timestamp ) : null;
	}

	/**
	 * One of a subscription's dates as a bare 'Y-m-d' string in the site's
	 * timezone, or null when unset.
	 *
	 * WC_Subscription::get_date() hands back a GMT 'Y-m-d H:i:s' string, or the
	 * integer 0 when the date is not set. The wizard shows calendar days, so the
	 * GMT instant is converted to the publisher's timezone before the time is
	 * dropped — see local_date().
	 *
	 * @param \WC_Subscription $subscription The subscription.
	 * @param string           $date_type    A WCS date type, e.g. 'next_payment'.
	 *
	 * @return string|null 'YYYY-MM-DD', or null when the date is not set.
	 */
	private function subscription_date( $subscription, $date_type ) {
		$date = $subscription->get_date( $date_type );
		if ( empty( $date ) ) {
			return null;
		}
		// get_date() returns GMT; anchor the parse to UTC so a server default
		// timezone can't shift the instant before it is localized.
		return $this->local_date( strtotime( (string) $date . ' UTC' ) );
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
	 * A reader's group memberships, shaped as { id, plan, status, role, editUrl }.
	 *
	 * On the list path (`$detailed` false) this reads from the per-request
	 * membership index — built once by walking the site's groups — because the
	 * cost amortizes across a whole page of rows.
	 *
	 * On the single-profile path (`$detailed` true) that index is the wrong tool:
	 * nothing amortizes it for one person, and building it walks every group and
	 * every group's full membership. A single site here can hold a group with tens
	 * of thousands of members. So the detail path resolves only the groups THIS
	 * user is attached to — via the per-user helpers that already exist — and
	 * widens each into the whole group object the profile's card renders
	 * (prepare_group + billing + this reader's role and joinedAt). See
	 * api_get_subscriber() for why the group travels whole rather than as an ID.
	 *
	 * @param int  $user_id  The reader user ID.
	 * @param bool $detailed Whether to resolve the whole group object per membership.
	 *
	 * @return array<int,array>
	 */
	private function get_group_memberships( $user_id, $detailed = false ) {
		if ( ! $detailed ) {
			$index = $this->get_group_membership_index();
			return $index[ $user_id ] ?? [];
		}
		return $this->get_detailed_group_memberships( $user_id );
	}

	/**
	 * The whole group object for each group a single user is attached to, for the
	 * person profile.
	 *
	 * Resolves only this user's groups rather than the whole estate: the groups
	 * they own or manage via get_managed_subscriptions_for_user(), and those they
	 * are a plain member of via get_group_subscriptions_for_user(), unioned by ID.
	 * Role is derived from the (small, owner-inclusive) manager set, so the
	 * expensive full-membership walk is only ever done for the counts prepare_group
	 * needs on the user's own group(s) — never once per group site-wide.
	 *
	 * @param int $user_id The user ID.
	 *
	 * @return array<int,array>
	 */
	private function get_detailed_group_memberships( $user_id ) {
		if ( ! class_exists( '\Newspack\Group_Subscription' ) || ! class_exists( '\Newspack\Group_Subscription_Settings' ) ) {
			return [];
		}

		// Union owned/managed and member-of subscriptions, keyed by ID so a user
		// who is both (a promoted manager was first a member) is resolved once.
		$subscriptions = [];
		foreach ( Group_Subscription::get_managed_subscriptions_for_user( $user_id ) as $subscription ) {
			$subscriptions[ (int) $subscription->get_id() ] = $subscription;
		}
		foreach ( Group_Subscription::get_group_subscriptions_for_user( $user_id ) as $subscription ) {
			$subscriptions[ (int) $subscription->get_id() ] = $subscription;
		}

		$out = [];
		foreach ( $subscriptions as $subscription ) {
			$settings = Group_Subscription_Settings::get_subscription_settings( $subscription );
			if ( empty( $settings['enabled'] ) ) {
				continue;
			}
			// Role precedence matches the list index: owner (the subscription
			// customer), else manager (get_managers is owner-inclusive and small),
			// else plain member.
			if ( (int) $subscription->get_user_id() === $user_id ) {
				$role = 'owner';
			} elseif ( in_array( $user_id, array_map( 'intval', Group_Subscription::get_managers( $subscription ) ), true ) ) {
				$role = 'manager';
			} else {
				$role = 'member';
			}
			$joined_at = Group_Subscription::get_member_joined_at( $user_id, $subscription );
			$out[]     = array_merge(
				$this->prepare_group( $subscription, $settings ),
				$this->subscription_billing( $subscription ),
				[
					'role'     => $role,
					// Null for a member who predates the joined-at meta, rather than a
					// misleading Unix epoch.
					'joinedAt' => $this->local_date( $joined_at ),
				]
			);
		}
		return $out;
	}

	/**
	 * Build (once per request) a map of user ID → their group memberships, each
	 * shaped as { id, plan, status, role, editUrl }.
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
					'id'      => (int) $subscription->get_id(),
					'plan'    => (string) $group['settings']['name'],
					'status'  => self::map_subscription_status( $subscription->get_status() ),
					'editUrl' => $this->subscription_edit_url( $subscription ),
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
	 * SOURCE OF TRUTH for the status-reduction rule. The rule is:
	 *
	 *   Rank statuses active → pending → on-hold → cancelled, and drop
	 *   `cancelled` entirely whenever the reader still holds any live
	 *   (non-cancelled) subscription; a reader with no subscription at all has
	 *   no status.
	 *
	 * It is re-encoded in three other places, each of which points back here and
	 * must be changed in step or the endpoint's filter will contradict the badge
	 * it filters on:
	 *   - customer_ids_for_statuses() below — the endpoint's `status` filter.
	 *   - displayStatuses() in src/wizards/subscribers/status.js — the row's
	 *     status badges.
	 *   - visiblePlanEntries in src/wizards/subscribers/screens/SubscriberList.jsx
	 *     — the Subscription column.
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
		return $this->local_date( $paid ? $paid->getTimestamp() : null );
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
	 * statuses, mirroring the list's badge reduction (reduced_status() above is
	 * the documented source of truth for that rule): a live status always
	 * qualifies, but `cancelled` matches
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
		// Memoized per status set: customer_ids_for_statuses() asks for up to three
		// overlapping sets per request (and a filter like ['active','cancelled']
		// asks for `active` twice), each of which would otherwise repeat the full
		// subscription scan below.
		sort( $prototype_statuses );
		$cache_key = implode( ',', $prototype_statuses );
		if ( isset( $this->raw_status_ids_cache[ $cache_key ] ) ) {
			return $this->raw_status_ids_cache[ $cache_key ];
		}
		$ids = [];

		// Individual + owned subscriptions in a matching WCS status (HPOS-safe).
		// Bounded to the same ceiling the include set is capped at, so the filter
		// path can't load an unbounded number of subscription objects on a large
		// store, and walked a chunk at a time: WooCommerce hands back fully
		// hydrated WC_Subscription objects and only the customer ID is read here,
		// so paging keeps peak memory at one chunk instead of the whole cap.
		//
		// The walk pages with `offset`, not `paged`: wcs_get_subscriptions()
		// declares `paged` among its own defaults, so it is stripped from the
		// extra args and never reaches the WC_Order_Query — passing it would
		// re-scan the first chunk on every iteration. `orderby => ID` gives the
		// walk a deterministic tiebreak; the default start-date sort can shuffle
		// rows sharing a date across a chunk boundary and skip a customer.
		$wcs_statuses = $this->wcs_statuses_for( $prototype_statuses );
		if ( ! empty( $wcs_statuses ) && function_exists( 'wcs_get_subscriptions' ) ) {
			for ( $page = 1; ( $page - 1 ) * self::FILTER_SCAN_CHUNK < self::FILTER_INCLUDE_CAP; $page++ ) {
				$subs = \wcs_get_subscriptions(
					[
						'subscriptions_per_page' => self::FILTER_SCAN_CHUNK,
						'offset'                 => ( $page - 1 ) * self::FILTER_SCAN_CHUNK,
						'orderby'                => 'ID',
						'subscription_status'    => $wcs_statuses,
					]
				);
				foreach ( $subs as $subscription ) {
					$ids[] = (int) $subscription->get_customer_id();
				}
				if ( count( $subs ) < self::FILTER_SCAN_CHUNK ) {
					break;
				}
			}
		}

		// Group members inherit their group's status.
		foreach ( $this->get_group_subscriptions() as $group ) {
			if ( in_array( self::map_subscription_status( $group['subscription']->get_status() ), $prototype_statuses, true ) ) {
				$ids = array_merge( $ids, array_map( 'intval', Group_Subscription::get_all_members( $group['subscription'] ) ) );
			}
		}

		$this->raw_status_ids_cache[ $cache_key ] = array_values( array_unique( array_filter( $ids ) ) );
		return $this->raw_status_ids_cache[ $cache_key ];
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
		// Already sanitized, deduplicated and capped at AVATAR_BATCH_CAP by the
		// arg's sanitize_callback (sanitize_emails_arg), so an oversized payload
		// can't fan out into unbounded get_avatar_url() calls.
		foreach ( (array) $request->get_param( 'emails' ) as $email ) {
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
					// The /avatars endpoint truncates anything past this cap rather
					// than erroring, so the client must batch to the same number. It
					// is published here so there is one authority instead of two
					// constants that can drift apart silently.
					'avatarBatchCap'   => self::AVATAR_BATCH_CAP,
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
