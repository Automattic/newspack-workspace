<?php
/**
 * Newspack Group Subscriptions - WooCommerce Teams `join-team` links.
 *
 * @package Newspack
 */

namespace Newspack;

defined( 'ABSPATH' ) || exit;

/**
 * Resolves WooCommerce Teams `join-team` links against group subscriptions.
 *
 * WooCommerce Teams invites readers with `/my-account/join-team/i_<token>`, and
 * offers a team's owner an open `/my-account/join-team/<registration-key>` link
 * anyone may follow. Both are My Account endpoints the Teams plugin registers, so
 * deactivating it at the Access Control flip unregisters the route and every link
 * already sitting in a reader's inbox starts returning a 404 — indefinitely, since
 * neither link carries an expiry.
 *
 * This registers the same route once Teams is gone and maps each token onto the
 * Access Control equivalent of what it used to open: an invitation onto an invite
 * for that one address, a registration key onto the group's invite link. Resolution
 * happens at click time against the `wc_team_invitation` and `wc_memberships_team`
 * rows the flip leaves behind, keyed on the source team migrate-teams stamped on the
 * group subscription it created. Nothing is built at migration time, so there is no
 * mapping table to keep in step with either side.
 *
 * Two configurations reach only the fallback notice. A site that uninstalled
 * WooCommerce Teams *with data deletion* rather than deactivating it has no rows left
 * to resolve against. And a half-done flip — Teams deactivated, WooCommerce
 * Memberships left active — cannot resolve a group subscription at all, because
 * Group_Subscription::is_group_subscription() deliberately answers false on My
 * Account pages while Memberships owns the front end, and this route is a My Account
 * page. The migration deactivates both together, so the second is transient.
 *
 * One deliberate parity gap: Teams stored an invited member's role on the invitation
 * (`post_mime_type`), and an outstanding *manager* invitation resolved here produces
 * a plain member. migrate-teams promotes managers from team member roles rather than
 * from pending invitations, so honouring the role here would be the only place in the
 * migration that reads it.
 */
class Group_Subscription_Teams_Invite {
	/**
	 * Option holding the My Account endpoint slug, as WooCommerce Teams stores it. A
	 * publisher who renamed the endpoint has that value baked into every invitation
	 * email already sent, so the stored slug is what the route has to answer on.
	 */
	const ENDPOINT_OPTION = 'woocommerce_myaccount_join_team_endpoint';

	/**
	 * The endpoint slug WooCommerce Teams defaults to.
	 */
	const DEFAULT_ENDPOINT = 'join-team';

	/**
	 * Prefix marking a token as an invitation token rather than a team registration
	 * key. WooCommerce Teams puts it there when it builds the URL; the two token
	 * kinds are otherwise indistinguishable.
	 */
	const INVITATION_TOKEN_PREFIX = 'i_';

	/**
	 * Post type of a WooCommerce Teams invitation. The invitee's email is the post
	 * title, the team is the post parent, and the token is the post password.
	 */
	const INVITATION_POST_TYPE = 'wc_team_invitation';

	/**
	 * Post type of a WooCommerce Teams team. Its registration key is the post
	 * password, and its owner is the post author.
	 */
	const TEAM_POST_TYPE = 'wc_memberships_team';

	/**
	 * Status of an invitation nobody has accepted yet. Accepted and cancelled
	 * invitations keep their token, so the status is what separates a link that
	 * should still open something from one that was already spent or withdrawn.
	 */
	const PENDING_INVITATION_STATUS = 'wcmti-pending';

	/**
	 * Status WooCommerce Teams gave an invitation once its reader joined. Writing it
	 * is what makes a link single-use: Teams set it in Invitation::accept(), and
	 * without it a reader removed from the group could re-admit themselves from the
	 * same email indefinitely, with no way for a manager to revoke it.
	 */
	const ACCEPTED_INVITATION_STATUS = 'wcmti-accepted';

	/**
	 * Option recording the endpoint slug the stored rewrite rules were generated for.
	 * The slug rather than a bare flag, so renaming the endpoint re-arms the flush
	 * instead of leaving the route with no rule and no way to notice.
	 */
	const REWRITE_FLUSH_OPTION = 'newspack_join_team_rewrite_rules_slug';

	/**
	 * Register hooks.
	 */
	public static function init() {
		// Same gate as Group_Subscription_Invite, whose acceptance handlers every
		// redirect here lands in: without the flag there are no group subscriptions to
		// map a token onto, and the handler on the other end would not be listening.
		if ( ! Content_Gate::is_newspack_feature_enabled() ) {
			return;
		}
		add_filter( 'woocommerce_get_query_vars', [ __CLASS__, 'add_query_var' ] );
		add_action( 'wp_loaded', [ __CLASS__, 'maybe_flush_rewrite_rules' ] );
		add_action( 'template_redirect', [ __CLASS__, 'handle_request' ] );
		add_action( 'newspack_group_subscription_invite_accepted', [ __CLASS__, 'close_source_invitation' ], 10, 3 );
	}

	/**
	 * Whether WooCommerce Teams is active on this site.
	 *
	 * While it is, it owns the route and serves these links itself, so this class
	 * stays out of the way — a site is fully configured for Access Control for a
	 * stretch before the flip, and the reader must keep reaching Teams until Teams is
	 * the thing that goes away.
	 *
	 * Checked inside the callbacks rather than in init(): the plugin files are
	 * included at plugin-load time, before WooCommerce Teams has necessarily loaded.
	 *
	 * @return bool
	 */
	private static function teams_is_active() {
		return function_exists( 'wc_memberships_for_teams_get_teams' );
	}

	/**
	 * The endpoint slug to answer on.
	 *
	 * @return string
	 */
	public static function get_endpoint() {
		$endpoint = get_option( self::ENDPOINT_OPTION, self::DEFAULT_ENDPOINT );
		return is_string( $endpoint ) && '' !== $endpoint ? $endpoint : self::DEFAULT_ENDPOINT;
	}

	/**
	 * Register the endpoint as a My Account query var.
	 *
	 * WooCommerce turns each of its query vars into a rewrite endpoint itself
	 * (WC_Query::add_endpoints()), so the filter is the whole registration — there is
	 * no separate add_rewrite_endpoint() call to keep in step with it.
	 *
	 * @param array $query_vars WooCommerce query vars.
	 *
	 * @return array
	 */
	public static function add_query_var( $query_vars ) {
		if ( self::teams_is_active() ) {
			return $query_vars;
		}
		$endpoint = self::get_endpoint();
		// A publisher who renamed the Teams endpoint to one WooCommerce already owns
		// would otherwise have this overwrite it, taking out that account page.
		if ( ! isset( $query_vars[ $endpoint ] ) ) {
			$query_vars[ $endpoint ] = $endpoint;
		}
		return $query_vars;
	}

	/**
	 * Generate the endpoint's rewrite rule on the first request after WooCommerce
	 * Teams goes away.
	 *
	 * On `wp_loaded` rather than `init` for two reasons. WP_Rewrite::flush_rules()
	 * defers its own work to `wp_loaded` when called earlier, so the option would
	 * otherwise be written before the flush it records had happened — and a fatal in
	 * anything else's `init` in between would leave the option claiming a rule that
	 * was never written, with nothing to retry it. And by `wp_loaded` every plugin's
	 * endpoints are registered, so the rules this writes are the complete set.
	 */
	public static function maybe_flush_rewrite_rules() {
		if ( self::teams_is_active() ) {
			return;
		}
		$endpoint = self::get_endpoint();
		if ( get_option( self::REWRITE_FLUSH_OPTION ) === $endpoint ) {
			return;
		}
		flush_rewrite_rules(); // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.flush_rewrite_rules_flush_rewrite_rules
		update_option( self::REWRITE_FLUSH_OPTION, $endpoint );
	}

	/**
	 * Whether this request is for the endpoint.
	 *
	 * Narrower than the route WooCommerce Teams answered on, which also served the
	 * `EP_ROOT` form (`/join-team/<token>`). No link Teams ever sent uses that form —
	 * Team::get_registration_url() builds every one of them off the My Account page.
	 *
	 * @return bool
	 */
	private static function is_endpoint_request() {
		if ( ! function_exists( 'is_account_page' ) || ! is_account_page() ) {
			return false;
		}
		global $wp;
		$endpoint = self::get_endpoint();
		return isset( $wp->query_vars[ $endpoint ] ) || isset( $_GET[ $endpoint ] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	}

	/**
	 * Read the token out of an endpoint request.
	 *
	 * Both permalink structures are honoured, matching how WooCommerce Teams built
	 * the URLs: a path segment when permalinks are on, a query arg otherwise.
	 *
	 * @return string The token, empty when the endpoint was reached without one.
	 */
	private static function get_token() {
		global $wp;
		$endpoint = self::get_endpoint();
		$token    = '';
		if ( ! empty( $wp->query_vars[ $endpoint ] ) && is_string( $wp->query_vars[ $endpoint ] ) ) {
			$token = sanitize_text_field( $wp->query_vars[ $endpoint ] );
		} elseif ( ! empty( $_GET[ $endpoint ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$token = sanitize_text_field( wp_unslash( $_GET[ $endpoint ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}
		return $token;
	}

	/**
	 * Resolve a token of either kind to the Access Control link it now stands for.
	 *
	 * The prefix is the only thing distinguishing an email-bound invitation from a
	 * team's open registration key, so this split is what decides which of the two
	 * resolvers a reader reaches. Exposed for testing.
	 *
	 * @param string $token The token from the URL, prefix included.
	 *
	 * @return string|\WP_Error The URL to send the reader to, or an error to show them.
	 */
	public static function resolve_token( $token ) {
		$token = (string) $token;
		if ( 0 === strpos( $token, self::INVITATION_TOKEN_PREFIX ) ) {
			return self::resolve_invitation_token( substr( $token, strlen( self::INVITATION_TOKEN_PREFIX ) ) );
		}
		return self::resolve_registration_token( $token );
	}

	/**
	 * Resolve a `join-team` request and send the reader on to the Access Control
	 * equivalent, or to My Account with an explanation.
	 */
	public static function handle_request() {
		if ( self::teams_is_active() || ! self::is_endpoint_request() ) {
			return;
		}

		// An empty token means a link that arrived truncated — a mail client wrapping a
		// long URL. Without this the endpoint renders the account page with no content
		// of its own and no hint of why the link did nothing.
		$destination = self::resolve_token( self::get_token() );

		if ( is_wp_error( $destination ) ) {
			Group_Subscription_Invite::redirect_with_result( $destination->get_error_code() );
		}
		wp_safe_redirect( $destination );
		exit;
	}

	/**
	 * Map an invitation token onto an invite for the address it was sent to.
	 *
	 * The invite is minted silently: the reader is already looking at the page, so
	 * mailing them a second copy of an email they just followed helps nobody. An
	 * invite already live for that address is reused rather than replaced, so a
	 * forwarded or repeatedly-clicked link cannot turn into an unbounded run of
	 * subscription writes.
	 *
	 * Exposed for testing.
	 *
	 * @param string $token The invitation token, prefix already stripped.
	 *
	 * @return string|\WP_Error The invite URL, or an error to show the reader.
	 */
	public static function resolve_invitation_token( $token ) {
		$invitation = self::find_pending_invitation( $token );
		if ( ! $invitation ) {
			return self::invalid_link_error();
		}
		$email = is_email( $invitation->post_title );
		if ( ! $email ) {
			return self::invalid_link_error();
		}
		$subscription = self::find_group_subscription_for_team( (int) $invitation->post_parent );
		if ( ! $subscription ) {
			return self::invalid_link_error();
		}

		// Answered before the reuse lookup below, which would otherwise hand a member
		// back an invite they have already spent. generate_invite() refuses this case
		// too, but only on the mint path.
		$invitee = get_user_by( 'email', $email );
		if ( $invitee && Group_Subscription::user_is_member( $invitee->ID, $subscription ) ) {
			return self::existing_member_error();
		}

		$live = self::find_live_invite( $subscription, $email );
		if ( ! $live ) {
			// generate_invite() is the single gate on everything that decides whether
			// this address may join at all — reader account, existing membership, the
			// group's seat limit, and the group still being active — so a full group
			// fails closed here rather than overfilling.
			$invite = Group_Subscription_Invite::generate_invite( $subscription, $email, false );
			if ( is_wp_error( $invite ) ) {
				return 'newspack_group_subscription_invite_existing_user' === $invite->get_error_code()
					? self::existing_member_error()
					: self::invalid_link_error();
			}
			$live = self::find_live_invite( $subscription, $email );
			if ( ! $live ) {
				return self::invalid_link_error();
			}
		}

		// The address the invite was stored under, not the invitation row's: the two
		// can differ in case, and the acceptance handler compares strictly. Redirecting
		// with the row's casing would hand the reader a link that refuses them.
		return Group_Subscription_Invite::get_invite_url( $subscription->get_id(), $live['key'], $live['email'] );
	}

	/**
	 * Map a team registration key onto the group's invite link.
	 *
	 * A registration key is open to anyone holding it, which is what the invite link
	 * is, so the two carry the same semantic across the flip — unlike an invitation,
	 * neither is bound to an address.
	 *
	 * Exposed for testing.
	 *
	 * @param string $token The team registration key.
	 *
	 * @return string|\WP_Error The invite-link URL, or an error to show the reader.
	 */
	public static function resolve_registration_token( $token ) {
		$team_id = self::find_team_by_registration_key( $token );
		if ( ! $team_id ) {
			return self::invalid_link_error();
		}
		$subscription = self::find_group_subscription_for_team( $team_id );
		if ( ! $subscription ) {
			return self::invalid_link_error();
		}

		$entry = Group_Subscription_Invite::get_link_invite( $subscription );
		if ( empty( $entry['key'] ) ) {
			// Only ever minted when the group has no link at all: generate_link_invite()
			// replaces whatever is stored, so calling it on a group whose managers are
			// already circulating a link would revoke that link from under them.
			// Attributed to the owner, who manages the group by definition.
			$entry = Group_Subscription_Invite::generate_link_invite( $subscription, (int) $subscription->get_user_id() );
			if ( is_wp_error( $entry ) ) {
				return self::invalid_link_error();
			}
		}

		return Group_Subscription_Invite::get_link_invite_url( $subscription->get_id(), $entry['key'] );
	}

	/**
	 * Find the pending invitation a token belongs to.
	 *
	 * Read straight from the posts table rather than through WP_Query, because with
	 * WooCommerce Teams gone `wcmti-pending` is an unregistered status and WP_Query's
	 * handling of one is not the same in every context: an anonymous front-end
	 * request — the only kind that ever reaches this route — narrows to `publish` and
	 * finds nothing, while an admin or WP-CLI request drops the clause and returns
	 * every status. The status decides whether a link is still live, so it is checked
	 * here in PHP, on the row, where the answer cannot change with the caller.
	 *
	 * @param string $token The invitation token.
	 *
	 * @return \WP_Post|null The invitation post, or null if there is no pending one.
	 */
	private static function find_pending_invitation( $token ) {
		$post = self::find_post_by_password( self::INVITATION_POST_TYPE, $token );
		if ( ! $post ) {
			return null;
		}
		// An accepted or cancelled invitation keeps its token, so this is what stops a
		// spent link from granting access.
		return self::PENDING_INVITATION_STATUS === $post->post_status ? $post : null;
	}

	/**
	 * Find the team a registration key belongs to.
	 *
	 * Only a published team, matching what WooCommerce Teams resolved: a trashed team
	 * keeps its registration key, and the group subscription migrated from it stays
	 * active, so without the status check a team the publisher deleted before the flip
	 * would have its open-join link start working again.
	 *
	 * @param string $token The team registration key.
	 *
	 * @return int The team post ID, or 0 if there is none.
	 */
	private static function find_team_by_registration_key( $token ) {
		$team = self::find_post_by_password( self::TEAM_POST_TYPE, $token );
		return ( $team && 'publish' === $team->post_status ) ? (int) $team->ID : 0;
	}

	/**
	 * Find a post of a given type by its post password.
	 *
	 * @param string $post_type The post type.
	 * @param string $password  The post password to match.
	 *
	 * @return \WP_Post|null The post, or null if nothing matches.
	 */
	private static function find_post_by_password( $post_type, $password ) {
		// An empty password is what every post without one stores, so an empty token
		// would match arbitrary posts rather than nothing.
		if ( '' === (string) $password ) {
			return null;
		}
		global $wpdb;
		// No ORDER BY: Teams tokens are unique, and ordering on the primary key tempts
		// the planner into a full table scan on a miss — which anyone can trigger, since
		// this route takes an unauthenticated token.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- No core API reads by post password without WP_Query's context-dependent status handling; one row per click on a link followed at most a handful of times.
		$post_id = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND post_password = %s LIMIT 1",
				$post_type,
				(string) $password
			)
		);
		return $post_id ? get_post( $post_id ) : null;
	}

	/**
	 * Find the active group subscription migrate-teams created for a team.
	 *
	 * Read through the team owner's own subscriptions, keyed on the marker
	 * migrate-teams stamps: that is a short, indexed list whatever the site's order
	 * storage, where a meta query across every subscription is neither.
	 *
	 * @param int $team_id The team post ID.
	 *
	 * @return \WC_Subscription|null The group subscription, or null if the team never migrated.
	 */
	private static function find_group_subscription_for_team( $team_id ) {
		$team_id = absint( $team_id );
		if ( ! $team_id || ! function_exists( 'wcs_get_users_subscriptions' ) ) {
			return null;
		}
		$team = get_post( $team_id );
		if ( ! $team || self::TEAM_POST_TYPE !== $team->post_type ) {
			return null;
		}
		$owner_id = (int) $team->post_author;
		if ( ! $owner_id ) {
			return null;
		}

		foreach ( wcs_get_users_subscriptions( $owner_id ) as $subscription ) {
			// wcs_get_users_subscriptions() is filtered to include the groups a user is
			// only a member of, so ownership is required rather than assumed.
			if ( (int) $subscription->get_user_id() !== $owner_id ) {
				continue;
			}
			if ( (int) $subscription->get_meta( Group_Subscription::MIGRATED_TEAM_ID_META_KEY ) !== $team_id ) {
				continue;
			}
			if ( ! Group_Subscription::is_group_subscription( $subscription ) ) {
				continue;
			}
			if ( ! $subscription->has_status( WooCommerce_Connection::ACTIVE_SUBSCRIPTION_STATUSES ) ) {
				continue;
			}
			return $subscription;
		}

		return null;
	}

	/**
	 * An unexpired invite the subscription already holds for an address.
	 *
	 * Returns the stored email as well as the key, because the two are not
	 * interchangeable: the match here is case-insensitive (a stored invite and the
	 * address on the invitation row can differ in case), while the acceptance handler
	 * compares the invite's email strictly.
	 *
	 * @param \WC_Subscription $subscription The group subscription.
	 * @param string           $email        The invitee's email.
	 *
	 * @return array|null [ 'key' => string, 'email' => string ], or null if there is no live invite.
	 */
	private static function find_live_invite( $subscription, $email ) {
		foreach ( Group_Subscription_Invite::get_invites( $subscription, false ) as $key => $invite ) {
			if ( empty( $invite['email'] ) ) {
				continue;
			}
			if ( strtolower( $invite['email'] ) === strtolower( $email ) ) {
				return [
					'key'   => (string) $key,
					'email' => $invite['email'],
				];
			}
		}
		return null;
	}

	/**
	 * Spend the WooCommerce Teams invitation a reader joined through.
	 *
	 * Teams' own acceptance flipped the invitation to `wcmti-accepted`, which is what
	 * made an invitation link single-use. Nothing else carries that here: the reader
	 * has joined, but the row is still pending, so a manager who later removes them
	 * cannot stop the original email re-admitting them — the link is a bearer token
	 * that no Access Control screen shows.
	 *
	 * Written straight to the row. Post-flip both the post type and the status are
	 * unregistered, and wp_update_post() on an unregistered type would sanitise the
	 * status it is given and fire a save cascade for a type nothing has declared.
	 *
	 * Runs on every invite acceptance, so it leaves early on the sites that have no
	 * teams behind them: a group subscription with no migration marker costs one meta
	 * read and no query.
	 *
	 * @param \WC_Subscription $subscription The group subscription joined.
	 * @param string           $email        The address the invite was issued to.
	 * @param int              $user_id      The reader who joined. Unused; part of the action's signature.
	 */
	public static function close_source_invitation( $subscription, $email, $user_id ) {
		// While Teams is active it runs its own acceptance and owns these rows.
		if ( self::teams_is_active() || ! $subscription || ! $email ) {
			return;
		}
		$team_id = (int) $subscription->get_meta( Group_Subscription::MIGRATED_TEAM_ID_META_KEY );
		if ( ! $team_id ) {
			return;
		}

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The post type is unregistered post-flip, so WP_Query's status handling cannot be relied on here; see find_pending_invitation().
		$invitation_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND post_parent = %d AND post_status = %s AND LOWER( post_title ) = %s",
				self::INVITATION_POST_TYPE,
				$team_id,
				self::PENDING_INVITATION_STATUS,
				strtolower( $email )
			)
		);

		foreach ( $invitation_ids as $invitation_id ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- See above; the cache is cleared immediately below.
			$wpdb->update(
				$wpdb->posts,
				[ 'post_status' => self::ACCEPTED_INVITATION_STATUS ],
				[ 'ID' => (int) $invitation_id ]
			);
			clean_post_cache( (int) $invitation_id );
		}
	}

	/**
	 * The result code shown for every link that resolves to nothing.
	 *
	 * One code for all of them — no invitation row, a team that never migrated, a
	 * group subscription since cancelled, a group at its seat limit. The reader can do
	 * the same thing about each, and naming which one applies would tell an
	 * unauthenticated caller what the site holds.
	 *
	 * @return \WP_Error
	 */
	private static function invalid_link_error() {
		return new \WP_Error( Group_Subscription_Invite::RESULT_JOIN_TEAM_INVALID );
	}

	/**
	 * The result code shown to a reader who already has what the link offers.
	 *
	 * @return \WP_Error
	 */
	private static function existing_member_error() {
		return new \WP_Error(
			is_user_logged_in()
				? Group_Subscription_Invite::RESULT_JOIN_TEAM_MEMBER
				: Group_Subscription_Invite::RESULT_JOIN_TEAM_SIGN_IN
		);
	}
}

Group_Subscription_Teams_Invite::init();
