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
 * Every path here is reached by a bearer token no Access Control screen displays, so
 * a link stops working when the thing it points at is withdrawn. Joining spends the
 * source invitation, cancelling the minted invite spends it too, an address that is
 * already a member spends it, and a group whose owner disabled their invite link does
 * not get a replacement minted by the next click on an old registration URL.
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
	 * Our own key in WooCommerce's query-var map, whose value is the endpoint slug.
	 * WooCommerce keys that map by name and registers the value as the rewrite
	 * endpoint (WC_Query::add_endpoints(), ::parse_request()), so a key of our own
	 * means a publisher who renamed the Teams endpoint onto one WooCommerce already
	 * owns cannot have this overwrite it — and the key's presence on a request is
	 * itself the answer to "did we register this route?".
	 */
	const QUERY_VAR = 'newspack_join_team';

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
	 * is what makes a link single-use: Teams set it in Invitation::accept().
	 */
	const ACCEPTED_INVITATION_STATUS = 'wcmti-accepted';

	/**
	 * Post meta stamped on an invitation this plugin closed, recording the group
	 * subscription it was redeemed against. The status write alone is
	 * indistinguishable from a genuine WooCommerce Teams acceptance, which matters if
	 * a site's flip is ever rolled back: without this there is no way to tell the
	 * invitations redeemed through this route from ones Teams itself accepted, and so
	 * no way to restore them.
	 */
	const CLOSED_BY_META = '_newspack_join_team_redeemed_subscription';

	/**
	 * Register hooks.
	 */
	public static function init(): void {
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
		add_action( 'newspack_group_subscription_invites_cancelled', [ __CLASS__, 'close_cancelled_invitations' ], 10, 2 );
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
	 */
	private static function teams_is_active(): bool {
		return function_exists( 'wc_memberships_for_teams_get_teams' );
	}

	/**
	 * The endpoint slug to answer on.
	 */
	public static function get_endpoint(): string {
		$endpoint = get_option( self::ENDPOINT_OPTION, self::DEFAULT_ENDPOINT );
		return is_string( $endpoint ) && '' !== $endpoint ? $endpoint : self::DEFAULT_ENDPOINT;
	}

	/**
	 * Register the endpoint as a My Account query var.
	 *
	 * WooCommerce turns each of its query vars into a rewrite endpoint itself, so the
	 * filter is the whole registration. The slug is refused outright when WooCommerce
	 * already answers on it, by key or by value: a publisher who renamed the Teams
	 * endpoint to `orders` would otherwise have every visit to their orders page
	 * redirected away by this handler.
	 *
	 * @param array $query_vars WooCommerce query vars.
	 *
	 * @return array
	 */
	public static function add_query_var( $query_vars ): array {
		$query_vars = (array) $query_vars;
		if ( self::teams_is_active() ) {
			return $query_vars;
		}
		$endpoint = self::get_endpoint();
		if ( isset( $query_vars[ $endpoint ] ) || in_array( $endpoint, $query_vars, true ) ) {
			return $query_vars;
		}
		$query_vars[ self::QUERY_VAR ] = $endpoint;
		return $query_vars;
	}

	/**
	 * Generate the endpoint's rewrite rule whenever the stored rules lack one.
	 *
	 * The condition is the rule's absence, not a record that a flush once ran. Those
	 * come apart on a rollback: reactivating WooCommerce Teams makes add_query_var()
	 * stand down, so any flush from any source while it is loaded drops this rule from
	 * the stored set — and a guard that remembered "flushed for this slug" would then
	 * never flush again, leaving every invitation link 404ing permanently, which is
	 * the exact failure this class exists to prevent. Asserting the state instead
	 * self-heals on the next request.
	 *
	 * On `wp_loaded` because WP_Rewrite::flush_rules() defers its own work there
	 * anyway, and by then every plugin's endpoints are registered, so the rules this
	 * writes are the complete set. Soft flush: this adds endpoint rules only, and the
	 * hard form rewrites .htaccess on an anonymous front-end request for no gain.
	 *
	 * Nothing is asserted unless the endpoint is registered, because only a registered
	 * endpoint can put its pattern in the stored rules. Without that floor a site
	 * where the rule can never appear — WooCommerce inactive, or a slug collision that
	 * made add_query_var() stand down — would regenerate the whole rule set on every
	 * request, forever.
	 */
	public static function maybe_flush_rewrite_rules(): void {
		if ( self::teams_is_active() || ! self::endpoint_is_registered() ) {
			return;
		}
		$rules = get_option( 'rewrite_rules' );
		// No stored rules at all means permalinks are off, or something else is
		// mid-flush; either way this is not ours to fix.
		if ( empty( $rules ) || ! is_array( $rules ) ) {
			return;
		}
		$needle = '/' . self::get_endpoint() . '(';
		foreach ( array_keys( $rules ) as $pattern ) {
			if ( str_contains( (string) $pattern, $needle ) ) {
				return;
			}
		}
		flush_rewrite_rules( false ); // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.flush_rewrite_rules_flush_rewrite_rules
	}

	/**
	 * Whether the endpoint slug is registered as a rewrite endpoint on this request.
	 *
	 * WooCommerce is what registers it, from the query var add_query_var() supplies,
	 * so this answers "can a rule for this slug exist at all" — false while WooCommerce
	 * is inactive, and false when add_query_var() stood down over a slug WooCommerce
	 * already answers on. Exposed for testing.
	 */
	public static function endpoint_is_registered(): bool {
		global $wp_rewrite;
		if ( ! $wp_rewrite instanceof \WP_Rewrite ) {
			return false;
		}
		$endpoint = self::get_endpoint();
		foreach ( (array) $wp_rewrite->endpoints as $registered ) {
			// Each entry is places, name, query var, as WP_Rewrite::add_endpoint() stores it.
			if ( isset( $registered[1] ) && $registered[1] === $endpoint ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * The token from an endpoint request, or null when this is not one.
	 *
	 * Reads only our own query var: WC_Query::parse_request() populates it from the
	 * path segment or the `?slug=` form, so both of the shapes Teams built URLs in
	 * arrive here. Its presence also means add_query_var() registered the route
	 * rather than standing down over a slug collision.
	 *
	 * @return string|null The token, or null when this request is not for the endpoint.
	 */
	private static function get_request_token(): ?string {
		if ( ! function_exists( 'is_account_page' ) || ! is_account_page() ) {
			return null;
		}
		global $wp;
		if ( ! isset( $wp->query_vars[ self::QUERY_VAR ] ) ) {
			return null;
		}
		$token = $wp->query_vars[ self::QUERY_VAR ];
		return is_string( $token ) ? sanitize_text_field( $token ) : '';
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
	public static function resolve_token( string $token ) {
		if ( str_starts_with( $token, self::INVITATION_TOKEN_PREFIX ) ) {
			return self::resolve_invitation_token( substr( $token, strlen( self::INVITATION_TOKEN_PREFIX ) ) );
		}
		return self::resolve_registration_token( $token );
	}

	/**
	 * Resolve a `join-team` request and send the reader on to the Access Control
	 * equivalent, or to My Account with an explanation.
	 */
	public static function handle_request(): void {
		if ( self::teams_is_active() ) {
			return;
		}
		$token = self::get_request_token();
		if ( null === $token ) {
			return;
		}

		$destination = self::resolve_token( $token );

		if ( is_wp_error( $destination ) ) {
			// A logged-out reader goes to the My Account root rather than the site home
			// page redirect_with_result() would otherwise pick: the root renders the
			// login form, and one of the two messages tells them to sign in, which the
			// home page gives them no way to do. A logged-in reader must not be sent
			// there — WooCommerce_My_Account::redirect_to_account_details() forwards
			// the root on to `edit-account` for them and drops the query string on the
			// way, so the notice would never be rendered. They take
			// redirect_with_result()'s own default, which is that endpoint directly.
			Group_Subscription_Invite::redirect_with_result(
				$destination->get_error_code(),
				! is_user_logged_in() && function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'myaccount' ) : null
			);
			return;
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
	public static function resolve_invitation_token( string $token ) {
		$invitation = self::find_pending_invitation( $token );
		if ( ! $invitation ) {
			return self::invalid_link_error( 'no_pending_invitation' );
		}
		$email = is_email( $invitation->post_title );
		if ( ! $email ) {
			return self::invalid_link_error( 'invitation_address_invalid', [ 'invitation_id' => $invitation->ID ] );
		}
		$subscription = self::find_group_subscription_for_team( (int) $invitation->post_parent );
		if ( ! $subscription ) {
			return self::invalid_link_error( self::unresolved_team_reason(), [ 'team_id' => (int) $invitation->post_parent ] );
		}

		// Answered before the reuse lookup below, which would otherwise hand a member
		// back an invite they have already spent. generate_invite() refuses this case
		// too, but only on the mint path.
		$invitee = get_user_by( 'email', $email );
		if ( $invitee && Group_Subscription::user_is_member( $invitee->ID, $subscription ) ) {
			return self::spend_for_existing_member( $subscription, $email, (int) $invitee->ID );
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
					? self::spend_for_existing_member( $subscription, $email, $invitee ? (int) $invitee->ID : 0 )
					: self::invalid_link_error( 'invite_refused', [ 'reason' => $invite->get_error_code() ] );
			}
			$live = self::find_live_invite( $subscription, $email );
			if ( ! $live ) {
				return self::invalid_link_error( 'invite_not_readable_after_mint' );
			}
		}

		// The address the invite was stored under, not the invitation row's: the two
		// can differ in case, and this is the address an account gets created under
		// when the invitee turns out to be new to the site.
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
	public static function resolve_registration_token( string $token ) {
		$team_id = self::find_team_by_registration_key( $token );
		if ( ! $team_id ) {
			return self::invalid_link_error( 'no_team_for_registration_key' );
		}
		$subscription = self::find_group_subscription_for_team( $team_id );
		if ( ! $subscription ) {
			return self::invalid_link_error( self::unresolved_team_reason(), [ 'team_id' => $team_id ] );
		}

		// Invite links belong to a manager, and a link is revoked when its manager is
		// removed from the group. The owner is the one manager who cannot be removed,
		// so their link is the only one that lasts as long as the emails pointing at
		// it — which have no expiry of their own.
		$owner_id = (int) $subscription->get_user_id();
		$entry    = Group_Subscription_Invite::get_link_invite( $subscription, $owner_id );
		if ( empty( $entry['key'] ) ) {
			// An absent link means one of two things, and only one of them may be
			// minted into. delete_link_invite() removes the entry outright, so a link
			// the owner deliberately disabled looks exactly like one that never
			// existed — and minting here would put the revoked link back into
			// circulation for everyone still holding an old registration URL. The
			// withdrawal itself is what records the difference, whoever minted the
			// link: this route, or the owner in the group panel.
			if ( Group_Subscription_Invite::link_invite_was_revoked( $subscription, $owner_id ) ) {
				return self::invalid_link_error( 'link_invite_revoked', [ 'subscription_id' => $subscription->get_id() ] );
			}
			$entry = Group_Subscription_Invite::generate_link_invite( $subscription, $owner_id );
			if ( is_wp_error( $entry ) ) {
				return self::invalid_link_error( 'link_invite_refused', [ 'reason' => $entry->get_error_code() ] );
			}
		}

		return Group_Subscription_Invite::get_link_invite_url( $subscription->get_id(), $owner_id, $entry['key'] );
	}

	/**
	 * Find the pending invitation a token belongs to.
	 *
	 * Read straight from the posts table rather than through WP_Query, because with
	 * WooCommerce Teams gone `wcmti-pending` is an unregistered status and WP_Query
	 * drops the clause for one entirely: supplying an unregistered status produces no
	 * status condition at all, in every context, so the query would match an already
	 * accepted or cancelled invitation and hand out access on a spent link. The status
	 * is therefore checked here in PHP, on the row.
	 *
	 * @param string $token The invitation token.
	 *
	 * @return \WP_Post|null The invitation post, or null if there is no pending one.
	 */
	private static function find_pending_invitation( string $token ): ?\WP_Post {
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
	private static function find_team_by_registration_key( string $token ): int {
		$team = self::find_post_by_password( self::TEAM_POST_TYPE, $token );
		return ( $team && 'publish' === $team->post_status ) ? (int) $team->ID : 0;
	}

	/**
	 * Find a post of a given type by its post password.
	 *
	 * `post_password` carries no index in core, so this query's cost is bounded only
	 * by how many rows the post type holds, through the leading column of
	 * `type_status_date`. That is what makes it safe to run for an unauthenticated
	 * caller against a few hundred Teams rows, and what would stop being true if this
	 * helper were pointed at a high-volume post type.
	 *
	 * The match is finished in PHP with hash_equals(), because the SQL comparison runs
	 * under the table's collation — case-insensitive and trailing-space-insensitive on
	 * the utf8mb4_*_ci default — and these tokens are bearer credentials. Several rows
	 * are read rather than one so a collision cannot shadow the exact match.
	 *
	 * @param string $post_type The post type.
	 * @param string $password  The post password to match.
	 *
	 * @return \WP_Post|null The post, or null if nothing matches exactly.
	 */
	private static function find_post_by_password( string $post_type, string $password ): ?\WP_Post {
		// An empty password is what every post without one stores, so an empty token
		// would match arbitrary posts rather than nothing.
		if ( '' === $password ) {
			return null;
		}
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- No core API reads by post password without WP_Query's handling of an unregistered status; a few rows per click on a link followed at most a handful of times.
		$post_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND post_password = %s ORDER BY ID ASC LIMIT 10",
				$post_type,
				$password
			)
		);
		foreach ( $post_ids as $post_id ) {
			$post = get_post( (int) $post_id );
			if ( $post && hash_equals( (string) $post->post_password, $password ) ) {
				return $post;
			}
		}
		return null;
	}

	/**
	 * Find the active group subscription migrate-teams created for a team.
	 *
	 * Two lookups, because one does not cover the field. The team owner's own
	 * subscriptions catch every group the migration created, and are a short list
	 * whatever the site's order storage. But migrate-teams also reuses a team's
	 * *linked* subscription when its customer is somebody other than the team owner —
	 * the paid, linked teams — and that subscription is in nobody's owned list here:
	 * the filter that surfaces a member's group subscriptions only fires for a member
	 * viewing their own account page, and the visitor on this route is an anonymous
	 * invitee. So the team's own `_subscription_id` is the second place to look, which
	 * is the same link the migration read when it decided to reuse it.
	 *
	 * The marker is checked either way: it is unique per team, and it is what tells a
	 * subscription this team migrated into from one merely linked to it.
	 *
	 * @param int $team_id The team post ID.
	 *
	 * @return \WC_Subscription|null The group subscription, or null if the team never migrated.
	 */
	private static function find_group_subscription_for_team( int $team_id ): ?\WC_Subscription {
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
			if ( self::subscription_is_group_for_team( $subscription, $team_id ) ) {
				return $subscription;
			}
		}

		$linked_id = (int) get_post_meta( $team_id, '_subscription_id', true );
		if ( $linked_id && function_exists( 'wcs_get_subscription' ) ) {
			$linked = wcs_get_subscription( $linked_id );
			if ( $linked && self::subscription_is_group_for_team( $linked, $team_id ) ) {
				return $linked;
			}
		}

		return null;
	}

	/**
	 * Whether a subscription is the live group this team migrated into.
	 *
	 * @param \WC_Subscription $subscription The subscription to test.
	 * @param int              $team_id      The team post ID.
	 */
	private static function subscription_is_group_for_team( $subscription, int $team_id ): bool {
		if ( ! $subscription ) {
			return false;
		}
		if ( (int) $subscription->get_meta( Group_Subscription::MIGRATED_TEAM_ID_META_KEY ) !== $team_id ) {
			return false;
		}
		if ( ! Group_Subscription::is_group_subscription( $subscription ) ) {
			return false;
		}
		return (bool) $subscription->has_status( WooCommerce_Connection::ACTIVE_SUBSCRIPTION_STATUSES );
	}

	/**
	 * An unexpired invite the subscription already holds for an address.
	 *
	 * Returns the stored email as well as the key, because the two are not
	 * interchangeable: the match here is case-insensitive (a stored invite and the
	 * address on the invitation row can differ in case), while the URL has to carry
	 * the stored address — it is the one an account gets created under downstream.
	 *
	 * @param \WC_Subscription $subscription The group subscription.
	 * @param string           $email        The invitee's email.
	 *
	 * @return array|null [ 'key' => string, 'email' => string ], or null if there is no live invite.
	 */
	private static function find_live_invite( $subscription, string $email ): ?array {
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
	 * cannot stop the original email re-admitting them.
	 *
	 * @param \WC_Subscription $subscription The group subscription joined.
	 * @param string           $email        The address the invite was issued to.
	 * @param int              $user_id      The reader who joined. Unused; part of the action's signature.
	 */
	public static function close_source_invitation( $subscription, $email, $user_id ): void {
		self::close_invitations_for( $subscription, [ $email ] );
	}

	/**
	 * Spend the source invitations behind invites a manager cancelled.
	 *
	 * Cancelling is the control a manager reaches for before a reader has joined,
	 * which is exactly the window an unspent legacy link is live in: without this, the
	 * next click on that link mints a replacement and undoes the cancellation.
	 *
	 * @param \WC_Subscription $subscription The group subscription.
	 * @param string[]         $emails       The addresses whose invites were cancelled.
	 */
	public static function close_cancelled_invitations( $subscription, $emails ): void {
		self::close_invitations_for( $subscription, (array) $emails );
	}

	/**
	 * Mark a group's pending Teams invitations for the given addresses as spent.
	 *
	 * Written straight to the row. Post-flip both the post type and the status are
	 * unregistered, and wp_update_post() on an unregistered type would sanitise the
	 * status it is given and fire a save cascade for a type nothing has declared.
	 *
	 * Runs on every invite acceptance and cancellation, so it leaves early on the
	 * sites that have no teams behind them: a group subscription with no migration
	 * marker costs one meta read and no query.
	 *
	 * @param \WC_Subscription $subscription The group subscription.
	 * @param string[]         $emails       Addresses to close invitations for.
	 */
	private static function close_invitations_for( $subscription, array $emails ): void {
		// While Teams is active it runs its own acceptance and owns these rows.
		if ( self::teams_is_active() || ! $subscription ) {
			return;
		}
		$needles = [];
		foreach ( $emails as $email ) {
			if ( is_string( $email ) && '' !== $email ) {
				$needles[] = strtolower( $email );
			}
		}
		if ( empty( $needles ) ) {
			return;
		}
		$team_id = (int) $subscription->get_meta( Group_Subscription::MIGRATED_TEAM_ID_META_KEY );
		if ( ! $team_id ) {
			return;
		}

		global $wpdb;
		// One address per query rather than an IN() list: the caller passes one on an
		// acceptance and a handful on a cancellation, and a fixed statement keeps the
		// placeholders out of string building.
		foreach ( array_unique( $needles ) as $needle ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The post type is unregistered post-flip, so WP_Query's status handling cannot be relied on here; see find_pending_invitation().
			$invitation_ids = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND post_parent = %d AND post_status = %s AND LOWER( post_title ) = %s",
					self::INVITATION_POST_TYPE,
					$team_id,
					self::PENDING_INVITATION_STATUS,
					$needle
				)
			);

			foreach ( $invitation_ids as $invitation_id ) {
				$invitation_id = (int) $invitation_id;
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- See above; the cache is cleared immediately below.
				$wpdb->update(
					$wpdb->posts,
					[ 'post_status' => self::ACCEPTED_INVITATION_STATUS ],
					[ 'ID' => $invitation_id ]
				);
				// Stamped so a rolled-back site can tell these from invitations
				// WooCommerce Teams itself accepted, and restore them.
				update_post_meta( $invitation_id, self::CLOSED_BY_META, $subscription->get_id() );
				clean_post_cache( $invitation_id );
			}
		}
	}

	/**
	 * The result code shown for every link that resolves to nothing.
	 *
	 * One code for all of them — no invitation row, a team that never migrated, a
	 * group subscription since cancelled, a group at its seat limit. The reader can do
	 * the same thing about each, and naming which one applies would tell an
	 * unauthenticated caller what the site holds. The cause is recorded server-side
	 * instead, so a publisher can be told how many of their pending invitations are
	 * dead and why.
	 *
	 * @param string $reason  Which of the causes applied. Server-side only.
	 * @param array  $context Extra detail for the log entry.
	 *
	 * @return \WP_Error
	 */
	private static function invalid_link_error( string $reason, array $context = [] ): \WP_Error {
		do_action(
			'newspack_log',
			'newspack_join_team_link_unresolved',
			sprintf( 'A legacy join-team link could not be resolved: %s.', $reason ),
			[
				'type' => 'debug',
				'data' => array_merge( [ 'reason' => $reason ], $context ),
			]
		);
		return new \WP_Error( Group_Subscription_Invite::RESULT_JOIN_TEAM_INVALID );
	}

	/**
	 * Which cause to record when a team resolves to no group subscription.
	 *
	 * A half-done flip is indistinguishable from a team nobody migrated by the time
	 * the lookup returns: is_group_subscription() answers false on every My Account
	 * page while WooCommerce Memberships owns the front end, and this route is one.
	 * Only the difference in the two reasons lets a publisher be told which they have,
	 * and the half-flip one clears itself when Memberships is deactivated.
	 */
	private static function unresolved_team_reason(): string {
		return Memberships::is_active() ? 'memberships_still_active' : 'team_not_migrated';
	}

	/**
	 * Spend the source invitation for an address that is already a member, and say so.
	 *
	 * The link offers membership and the address already holds it, which is what spent
	 * means here. Nothing else closes the row on this path — removing a member does
	 * not cancel invites, so the acceptance and cancellation listeners never fire for
	 * it — and leaving it pending would let a reader the manager later removes re-admit
	 * themselves with the original email.
	 *
	 * @param \WC_Subscription $subscription The group subscription.
	 * @param string           $email        The address the invitation was sent to.
	 * @param int              $invitee_id   The reader holding that address, if any.
	 */
	private static function spend_for_existing_member( $subscription, string $email, int $invitee_id ): \WP_Error {
		self::close_invitations_for( $subscription, [ $email ] );
		return self::existing_member_error( $invitee_id );
	}

	/**
	 * The result code shown to a reader who already has what the link offers.
	 *
	 * The membership tested upstream is the invited address's, and the visitor holding
	 * the link is only that reader when they are signed in as them. A signed-in visitor
	 * following somebody else's forwarded invitation gets the dead-link message
	 * instead: "you already have access" is false for them, and would confirm that the
	 * invited address holds an account in this group.
	 *
	 * @param int $invitee_id The reader holding the invited address, if any.
	 */
	private static function existing_member_error( int $invitee_id ): \WP_Error {
		if ( ! is_user_logged_in() ) {
			return new \WP_Error( Group_Subscription_Invite::RESULT_JOIN_TEAM_SIGN_IN );
		}
		if ( $invitee_id && get_current_user_id() === $invitee_id ) {
			return new \WP_Error( Group_Subscription_Invite::RESULT_JOIN_TEAM_MEMBER );
		}
		return self::invalid_link_error( 'invitation_for_another_member' );
	}
}

Group_Subscription_Teams_Invite::init();
