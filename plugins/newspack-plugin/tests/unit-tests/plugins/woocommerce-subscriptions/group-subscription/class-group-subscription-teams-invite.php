<?php
/**
 * Tests for the WooCommerce Teams `join-team` link handler (NPPD-2252).
 *
 * The handler runs on the post-flip configuration: WooCommerce Teams is gone, so
 * its post statuses are unregistered and its API is unavailable, and all that
 * survives are the `wc_team_invitation` and `wc_memberships_team` rows plus the
 * source-team marker migrate-teams stamped on each group subscription. Every test
 * here is written against that configuration — the suite never activates Teams,
 * which is what makes the unregistered-status assertions below meaningful.
 *
 * @package Newspack\Tests
 * @group WooCommerce_Subscriptions_Integration
 */

use Newspack\Emails;
use Newspack\Group_Subscription;
use Newspack\Group_Subscription_Invite;
use Newspack\Group_Subscription_Teams_Invite;
use Newspack\Group_Subscription_Settings;

require_once dirname( __DIR__, 4 ) . '/mocks/newsletters-mocks.php';

/**
 * Test token resolution for legacy `join-team` links.
 *
 * @group WooCommerce_Subscriptions_Integration
 */
class Test_Group_Subscription_Teams_Invite extends WP_UnitTestCase {

	/**
	 * User IDs to clean up.
	 *
	 * @var int[]
	 */
	private $user_ids = [];

	/**
	 * Team and invitation post IDs to clean up.
	 *
	 * @var int[]
	 */
	private $post_ids = [];

	/**
	 * The invitation email config callback, when a test registered one.
	 *
	 * @var callable|null
	 */
	private $email_config_filter = null;

	/**
	 * Post ID of the invitation email post, when a test published one.
	 *
	 * @var int|null
	 */
	private $email_post_id = null;

	/**
	 * Load the WooCommerce mocks.
	 */
	public static function set_up_before_class() {
		parent::set_up_before_class();
		require_once dirname( __DIR__, 4 ) . '/mocks/wc-mocks.php';
	}

	/**
	 * Reset the mock subscription store and the per-request caches between tests.
	 */
	public function set_up() {
		parent::set_up();
		global $subscriptions_database, $products_database;
		$subscriptions_database = [];
		$products_database      = [];
		Group_Subscription::reset_cache();
		reset_phpmailer_instance();
	}

	/**
	 * Clean up fixtures.
	 */
	public function tear_down() {
		global $subscriptions_database;
		$subscriptions_database = [];
		if ( $this->email_config_filter ) {
			remove_filter( 'newspack_email_configs', $this->email_config_filter );
			$this->email_config_filter = null;
		}
		Emails::reset_email_configs_cache();
		if ( $this->email_post_id ) {
			wp_delete_post( $this->email_post_id, true );
			$this->email_post_id = null;
		}
		remove_filter( 'newspack_guest_author_mail_guard_active', '__return_false' );
		reset_phpmailer_instance();
		foreach ( $this->user_ids as $user_id ) {
			wp_delete_user( $user_id );
		}
		foreach ( $this->post_ids as $post_id ) {
			wp_delete_post( $post_id, true );
		}
		$this->user_ids = [];
		$this->post_ids = [];
		parent::tear_down();
	}

	/**
	 * Create a reader user.
	 *
	 * @param string|null $email Account email; a unique one is generated when null.
	 *
	 * @return int User ID.
	 */
	private function create_reader( ?string $email = null ): int {
		$user_id = wp_insert_user(
			[
				'user_login' => 'user-' . wp_generate_password( 6, false ),
				'user_pass'  => wp_generate_password(),
				'user_email' => $email ?? 'user-' . wp_generate_password( 6, false ) . '@test.com',
				'role'       => 'subscriber',
			]
		);
		$this->assertNotWPError( $user_id, 'Fixture user creation should succeed.' );
		$this->user_ids[] = $user_id;
		update_user_meta( $user_id, '_newspack_reader', true );
		return $user_id;
	}

	/**
	 * Create an active, group-enabled subscription owned by $owner_id, marked as
	 * migrated from $team_id — the shape migrate-teams leaves behind.
	 *
	 * @param int      $owner_id Owner user ID.
	 * @param int|null $team_id  Source team post ID, or null to leave it unmarked.
	 * @param int      $limit    Seat limit; 0 for unlimited.
	 *
	 * @return WC_Subscription
	 */
	private function create_migrated_group_subscription( int $owner_id, ?int $team_id = null, int $limit = 0 ) {
		$subscription = wcs_create_subscription(
			[
				'customer_id'    => $owner_id,
				'status'         => 'active',
				'billing_period' => 'month',
			]
		);
		$subscription->update_meta_data( Group_Subscription_Settings::GROUP_SUBSCRIPTION_META_PREFIX . 'enabled', 'yes' );
		if ( $limit ) {
			$subscription->update_meta_data( Group_Subscription_Settings::GROUP_SUBSCRIPTION_META_PREFIX . 'limit', $limit );
		}
		if ( $team_id ) {
			$subscription->update_meta_data( Group_Subscription::MIGRATED_TEAM_ID_META_KEY, $team_id );
		}
		$subscription->save();
		return $subscription;
	}

	/**
	 * Create a `wc_memberships_team` post.
	 *
	 * @param int      $owner_id               Team owner user ID (the post author).
	 * @param string   $registration_key       The team's open registration key (the post password).
	 * @param int|null $linked_subscription_id Subscription the team is linked to, as `_subscription_id`.
	 *
	 * @return int Team post ID.
	 */
	private function create_team( int $owner_id, string $registration_key = '', ?int $linked_subscription_id = null ): int {
		$team_id = wp_insert_post(
			[
				'post_type'     => 'wc_memberships_team',
				'post_status'   => 'publish',
				'post_title'    => 'Team ' . wp_generate_password( 4, false ),
				'post_author'   => $owner_id,
				'post_password' => $registration_key,
			]
		);
		$this->assertNotWPError( $team_id, 'Fixture team creation should succeed.' );
		$this->post_ids[] = $team_id;
		if ( $linked_subscription_id ) {
			update_post_meta( $team_id, '_subscription_id', $linked_subscription_id );
		}
		return $team_id;
	}

	/**
	 * Create a `wc_team_invitation` post.
	 *
	 * @param int    $team_id Team post ID (the post parent).
	 * @param string $email   Invitee email (the post title).
	 * @param string $token   Invitation token (the post password).
	 * @param string $status  Invitation post status.
	 *
	 * @return int Invitation post ID.
	 */
	private function create_team_invitation( int $team_id, string $email, string $token, string $status = 'wcmti-pending' ): int {
		$invitation_id = wp_insert_post(
			[
				'post_type'     => 'wc_team_invitation',
				'post_status'   => $status,
				'post_title'    => $email,
				'post_parent'   => $team_id,
				'post_password' => $token,
			]
		);
		$this->assertNotWPError( $invitation_id, 'Fixture invitation creation should succeed.' );
		$this->post_ids[] = $invitation_id;
		return $invitation_id;
	}

	/**
	 * Register and publish the group invitation email, so a send would actually
	 * dispatch mail.
	 *
	 * Without this the invite email is unregistered and every send returns false
	 * before reaching wp_mail(), which would make a "nothing was emailed" assertion
	 * pass against a handler that tried to email everybody.
	 */
	private function make_invite_email_sendable(): void {
		add_filter( 'newspack_guest_author_mail_guard_active', '__return_false' );
		$this->email_config_filter = function ( $configs ) {
			return Group_Subscription_Invite::add_email_config( $configs );
		};
		add_filter( 'newspack_email_configs', $this->email_config_filter );
		Emails::reset_email_configs_cache();
		$this->email_post_id = wp_insert_post(
			[
				'post_type'   => Emails::POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => 'Group subscription invitation (test)',
				'meta_input'  => [
					Emails::EMAIL_CONFIG_NAME_META         => Group_Subscription_Invite::EMAIL_TYPE,
					\Newspack_Newsletters::EMAIL_HTML_META => '<p>*INVITE_URL*</p>',
				],
			]
		);
	}

	/**
	 * Parse a resolved URL's query string.
	 *
	 * @param string $url The URL.
	 *
	 * @return array The query args.
	 */
	private function query_args_of( string $url ): array {
		parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $args );
		return $args;
	}

	/**
	 * The core mapping: a pending invitation's token resolves to a group invite for
	 * the address it was sent to, stored on the group the team migrated into — and
	 * nothing is emailed, because the reader is already looking at the link.
	 */
	public function test_invitation_token_resolves_to_an_invite_for_the_invited_address() {
		$this->make_invite_email_sendable();
		$owner        = $this->create_reader();
		$team_id      = $this->create_team( $owner );
		$subscription = $this->create_migrated_group_subscription( $owner, $team_id );
		$this->create_team_invitation( $team_id, 'invitee@test.com', 'tok-pending' );

		$url = Group_Subscription_Teams_Invite::resolve_invitation_token( 'tok-pending' );

		$this->assertIsString( $url, 'A pending invitation should resolve to a URL.' );
		$args = $this->query_args_of( $url );
		$this->assertSame( Group_Subscription_Invite::QUERY_ARG, $args['action'], 'The reader should land on the invite acceptance handler.' );
		$this->assertSame( (string) $subscription->get_id(), $args['subscription'], 'The invite should be on the group the team migrated into.' );
		$this->assertSame( 'invitee@test.com', $args['email'], 'The invite should be bound to the invited address.' );

		$stored = Group_Subscription_Invite::get_invites( $subscription );
		$this->assertArrayHasKey( $args['key'], $stored, 'The URL key should name an invite stored on the subscription.' );
		$this->assertSame( 'invitee@test.com', $stored[ $args['key'] ]['email'] );
		$this->assertEmpty( tests_retrieve_phpmailer_instance()->mock_sent, 'Resolving a link the reader is already holding must not email them a second copy.' );
	}

	/**
	 * An accepted or cancelled invitation keeps its token, so the status is the only
	 * thing separating a live link from a spent one — and with Teams deactivated the
	 * status is unregistered, which makes WP_Query silently drop the clause and
	 * return every status instead of narrowing on it. The handler re-checks in PHP;
	 * without that, a withdrawn invitation would still hand out access.
	 */
	public function test_spent_invitation_tokens_resolve_to_nothing() {
		$owner   = $this->create_reader();
		$team_id = $this->create_team( $owner );
		$this->create_migrated_group_subscription( $owner, $team_id );
		$this->create_team_invitation( $team_id, 'accepted@test.com', 'tok-accepted', 'wcmti-accepted' );
		$this->create_team_invitation( $team_id, 'cancelled@test.com', 'tok-cancelled', 'wcmti-cancelled' );

		$this->assertNull( get_post_status_object( 'wcmti-pending' ), 'The suite must run with the Teams statuses unregistered — the configuration this handler exists for.' );

		foreach ( [ 'tok-accepted', 'tok-cancelled' ] as $token ) {
			$result = Group_Subscription_Teams_Invite::resolve_invitation_token( $token );
			$this->assertWPError( $result, sprintf( '%s must not resolve.', $token ) );
			$this->assertSame( Group_Subscription_Invite::RESULT_JOIN_TEAM_INVALID, $result->get_error_code() );
		}
	}

	/**
	 * A link can be clicked repeatedly, or forwarded and clicked by several people.
	 * Each click reuses the invite already stored for that address rather than
	 * minting another, so a circulating link cannot grow the subscription's meta
	 * without bound.
	 */
	public function test_repeat_clicks_reuse_the_stored_invite() {
		$owner        = $this->create_reader();
		$team_id      = $this->create_team( $owner );
		$subscription = $this->create_migrated_group_subscription( $owner, $team_id );
		$this->create_team_invitation( $team_id, 'invitee@test.com', 'tok-repeat' );

		$first  = Group_Subscription_Teams_Invite::resolve_invitation_token( 'tok-repeat' );
		$second = Group_Subscription_Teams_Invite::resolve_invitation_token( 'tok-repeat' );

		$this->assertSame( $first, $second, 'A second click should hand back the same invite.' );
		$this->assertCount( 1, Group_Subscription_Invite::get_invites( $subscription ), 'Only one invite should ever be stored for the address.' );
	}

	/**
	 * A group with no seats left fails closed. The seat check is generate_invite()'s,
	 * not a second copy of it here — the point of routing every mint through that
	 * one gate.
	 */
	public function test_a_full_group_refuses_rather_than_overfilling() {
		$owner        = $this->create_reader();
		$member       = $this->create_reader();
		$team_id      = $this->create_team( $owner );
		$subscription = $this->create_migrated_group_subscription( $owner, $team_id, 2 );
		Group_Subscription::update_members( $subscription, [ $member ] );
		$this->create_team_invitation( $team_id, 'one-too-many@test.com', 'tok-full' );

		$result = Group_Subscription_Teams_Invite::resolve_invitation_token( 'tok-full' );

		$this->assertWPError( $result, 'A full group must not admit another invitee.' );
		$this->assertEmpty( Group_Subscription_Invite::get_invites( $subscription ), 'A refused invitee must leave no invite behind.' );
	}

	/**
	 * A reader who already has the access the link offers is told to sign in, rather
	 * than shown a dead end they cannot act on — and the source invitation is spent,
	 * because the address already holds what it offers. Nothing else closes the row on
	 * this path: removing a member cancels no invites, so neither listener ever fires
	 * for it, and a pending row would re-admit a reader the manager removed.
	 */
	public function test_an_existing_member_is_told_to_sign_in_and_spends_the_invitation() {
		$member_email = 'already-in@test.com';
		$owner        = $this->create_reader();
		$member       = $this->create_reader( $member_email );
		$team_id      = $this->create_team( $owner );
		$subscription = $this->create_migrated_group_subscription( $owner, $team_id );
		// A live invite for the same address, from before they joined. Without the
		// membership check running first, the reuse path would hand it straight back.
		Group_Subscription_Invite::generate_invite( $subscription, $member_email, false );
		Group_Subscription::update_members( $subscription, [ $member ] );
		$invitation_id = $this->create_team_invitation( $team_id, $member_email, 'tok-member' );

		$result = Group_Subscription_Teams_Invite::resolve_invitation_token( 'tok-member' );

		$this->assertWPError( $result, 'A reader who is already a member must not be handed an invite.' );
		$this->assertSame( Group_Subscription_Invite::RESULT_JOIN_TEAM_SIGN_IN, $result->get_error_code(), 'A signed-out member needs the sign-in message, not the generic one.' );
		$this->assertSame( 'wcmti-accepted', get_post( $invitation_id )->post_status, 'An address that already holds what the link offers has spent it.' );

		// The manager changes their mind.
		Group_Subscription::update_members( $subscription, [], [ $member ] );

		$second = Group_Subscription_Teams_Invite::resolve_invitation_token( 'tok-member' );
		$this->assertWPError( $second, 'A removed reader must not re-admit themselves with the original link.' );
		$this->assertSame( Group_Subscription_Invite::RESULT_JOIN_TEAM_INVALID, $second->get_error_code() );
	}

	/**
	 * The membership that decides this is the invited address's, and the visitor
	 * holding the link is that reader only when they are signed in as them. Somebody
	 * else following a forwarded invitation gets the dead-link message: "you already
	 * have access" would be false for them, and would confirm that the invited address
	 * holds an account in this group.
	 */
	public function test_the_already_a_member_message_is_only_for_the_invited_reader() {
		$owner        = $this->create_reader();
		$team_id      = $this->create_team( $owner );
		$subscription = $this->create_migrated_group_subscription( $owner, $team_id );
		$invitee      = $this->create_reader( 'invited-member@test.com' );
		$other_member = $this->create_reader( 'other-member@test.com' );
		$bystander    = $this->create_reader();
		Group_Subscription::update_members( $subscription, [ $invitee, $other_member ] );
		// Two rows, for two addresses: resolving one spends every pending invitation
		// for its address, so a single row could not carry both halves.
		$this->create_team_invitation( $team_id, 'invited-member@test.com', 'tok-own' );
		$this->create_team_invitation( $team_id, 'other-member@test.com', 'tok-forwarded' );

		wp_set_current_user( $invitee );
		$own = Group_Subscription_Teams_Invite::resolve_invitation_token( 'tok-own' );
		wp_set_current_user( $bystander );
		$forwarded = Group_Subscription_Teams_Invite::resolve_invitation_token( 'tok-forwarded' );
		wp_set_current_user( 0 );

		$this->assertSame( Group_Subscription_Invite::RESULT_JOIN_TEAM_MEMBER, $own->get_error_code(), 'The invited reader is told they already have access.' );
		$this->assertSame( Group_Subscription_Invite::RESULT_JOIN_TEAM_INVALID, $forwarded->get_error_code(), 'Anyone else holding the link is not told whose account it is.' );
	}

	/**
	 * The rule this asserts on can only exist while the endpoint is registered, and
	 * WooCommerce is what registers it. Without that floor, a site where the rule can
	 * never appear — WooCommerce inactive, or a slug collision that made
	 * add_query_var() stand down — regenerates the whole rule set on every request,
	 * indefinitely.
	 */
	public function test_rewrite_rules_flush_only_when_the_endpoint_is_registered() {
		global $wp_rewrite;
		$original_endpoints = $wp_rewrite->endpoints;
		$flushes            = 0;
		$count_flushes      = function ( $value ) use ( &$flushes ) {
			$flushes++;
			return $value;
		};
		update_option( 'rewrite_rules', [ 'sentinel/?$' => 'index.php?sentinel=1' ] );
		add_filter( 'pre_update_option_rewrite_rules', $count_flushes );

		$wp_rewrite->endpoints = [];
		$flushes               = 0;
		Group_Subscription_Teams_Invite::maybe_flush_rewrite_rules();
		$this->assertSame( 0, $flushes, 'An endpoint nothing registered can never reach the stored rules, so asserting it would flush forever.' );

		// An entry of places, name and query var, as WooCommerce leaves it after add_endpoints().
		$wp_rewrite->endpoints = [ [ EP_PAGES, 'join-team', 'join-team' ] ];
		$flushes               = 0;
		Group_Subscription_Teams_Invite::maybe_flush_rewrite_rules();
		$this->assertSame( 1, $flushes, 'A registered endpoint missing from the stored rules must flush.' );

		remove_filter( 'pre_update_option_rewrite_rules', $count_flushes );
		update_option( 'rewrite_rules', [ 'my-account/join-team(/(.*))?/?$' => 'index.php?pagename=my-account&newspack_join_team=$matches[2]' ] );
		add_filter( 'pre_update_option_rewrite_rules', $count_flushes );
		$flushes = 0;
		Group_Subscription_Teams_Invite::maybe_flush_rewrite_rules();
		$this->assertSame( 0, $flushes, 'A rule already in the stored set is the whole point of the check.' );

		remove_filter( 'pre_update_option_rewrite_rules', $count_flushes );
		$wp_rewrite->endpoints = $original_endpoints;
	}

	/**
	 * The URL the reader is handed carries the address the invite was stored under, not
	 * the casing on the Teams invitation row. That address is the one an account gets
	 * created under when the invitee is new to the site, so the two are not
	 * interchangeable even though acceptance matches them case-insensitively.
	 */
	public function test_reused_invite_is_addressed_with_its_stored_casing() {
		$owner        = $this->create_reader();
		$team_id      = $this->create_team( $owner );
		$subscription = $this->create_migrated_group_subscription( $owner, $team_id );
		$this->create_reader( 'Reader@Example.test' );
		// A manager invited them from the group panel with different casing than the
		// Teams invitation row carries.
		Group_Subscription_Invite::generate_invite( $subscription, 'Reader@Example.test', false );
		$this->create_team_invitation( $team_id, 'reader@example.test', 'tok-case' );

		$url  = Group_Subscription_Teams_Invite::resolve_invitation_token( 'tok-case' );
		$args = $this->query_args_of( $url );

		$this->assertSame( 'Reader@Example.test', $args['email'], 'The stored address must survive into the URL.' );
	}

	/**
	 * The prefix is the only thing telling an email-bound invitation from a team's open
	 * registration key. This pins the dispatch it decides: each token kind reaches its
	 * own resolver, and the prefix is stripped rather than merely detected — the bare
	 * form of an invitation token is a registration key, and must not resolve as one.
	 */
	public function test_token_prefix_decides_which_resolver_runs() {
		$owner        = $this->create_reader();
		$team_id      = $this->create_team( $owner, 'reg-key-dispatch' );
		$subscription = $this->create_migrated_group_subscription( $owner, $team_id );
		$this->create_team_invitation( $team_id, 'dispatch@test.com', 'inv-tok-dispatch' );

		$invitation_url   = Group_Subscription_Teams_Invite::resolve_token( 'i_inv-tok-dispatch' );
		$registration_url = Group_Subscription_Teams_Invite::resolve_token( 'reg-key-dispatch' );

		$this->assertSame( Group_Subscription_Invite::QUERY_ARG, $this->query_args_of( $invitation_url )['action'], 'An i_-prefixed token is an invitation.' );
		$this->assertSame( Group_Subscription_Invite::LINK_QUERY_ARG, $this->query_args_of( $registration_url )['action'], 'A bare token is a registration key.' );
		$this->assertWPError( Group_Subscription_Teams_Invite::resolve_token( 'inv-tok-dispatch' ), 'The bare form of an invitation token is not a registration key.' );
	}

	/**
	 * A linked team resolves through its own `_subscription_id`.
	 *
	 * The migration reuses a team's linked subscription even when its customer is not
	 * the team owner, adding the owner as a member so they keep access. Those are the
	 * linked, paid teams, and nothing surfaces another customer's subscription to the
	 * anonymous reader following the link, so requiring ownership would strand exactly
	 * the cohort a publisher is least willing to lose.
	 */
	public function test_a_team_resolves_when_its_subscription_belongs_to_another_customer() {
		$team_owner   = $this->create_reader();
		$bill_payer   = $this->create_reader();
		$team_id      = $this->create_team( $team_owner );
		$subscription = $this->create_migrated_group_subscription( $bill_payer, $team_id );
		// A linked team: migrate-teams reused the subscription the bill payer owns and
		// added the team owner as a member. The reader following the link is anonymous,
		// so nothing surfaces another customer's subscription in the owner's list —
		// the team's own _subscription_id is the only route to it.
		update_post_meta( $team_id, '_subscription_id', $subscription->get_id() );
		Group_Subscription::update_members( $subscription, [ $team_owner ] );
		$this->create_team_invitation( $team_id, 'crossowner@test.com', 'tok-crossowner' );

		$url = Group_Subscription_Teams_Invite::resolve_invitation_token( 'tok-crossowner' );

		$this->assertIsString( $url, 'A team whose subscription another customer pays for must still resolve.' );
		$this->assertSame( (string) $subscription->get_id(), $this->query_args_of( $url )['subscription'] );
	}

	/**
	 * Cancelling is the control a manager reaches for before a reader has joined,
	 * which is the window an unspent legacy link is live in. If cancelling does not
	 * spend the source invitation, the next click mints a replacement and undoes it,
	 * using a token the manager cannot see or reach.
	 */
	public function test_cancelling_the_invite_spends_the_source_invitation() {
		add_action( 'newspack_group_subscription_invites_cancelled', [ Group_Subscription_Teams_Invite::class, 'close_cancelled_invitations' ], 10, 2 );

		$owner         = $this->create_reader();
		$team_id       = $this->create_team( $owner );
		$subscription  = $this->create_migrated_group_subscription( $owner, $team_id );
		$invitation_id = $this->create_team_invitation( $team_id, 'cancelled@test.com', 'tok-cancel' );

		$this->assertIsString( Group_Subscription_Teams_Invite::resolve_invitation_token( 'tok-cancel' ), 'The first click mints an invite.' );

		Group_Subscription_Invite::cancel_invite( $subscription, 'cancelled@test.com' );

		$this->assertSame( 'wcmti-accepted', get_post( $invitation_id )->post_status, 'Cancelling must spend the source invitation.' );
		$result = Group_Subscription_Teams_Invite::resolve_invitation_token( 'tok-cancel' );
		$this->assertWPError( $result, 'A cancelled invite must not be re-minted by the next click.' );
		$this->assertSame( Group_Subscription_Invite::RESULT_JOIN_TEAM_INVALID, $result->get_error_code() );
	}

	/**
	 * An absent invite link means either "never had one" or "the owner disabled it",
	 * and delete_link_invite() removes the entry outright. Minting into the second puts
	 * a revoked link back into circulation for everyone still holding an old
	 * registration URL, so the withdrawal is what has to be recorded — whichever side
	 * minted the link. Post-flip the group panel is where owners manage these, so the
	 * link this seeds is minted there rather than through the route.
	 */
	public function test_a_disabled_invite_link_is_not_re_minted() {
		$owner        = $this->create_reader();
		$team_id      = $this->create_team( $owner, 'reg-key-revoke' );
		$subscription = $this->create_migrated_group_subscription( $owner, $team_id );
		$this->assertNotWPError( Group_Subscription_Invite::generate_link_invite( $subscription, $owner ), 'Fixture: the owner mints a link in the group panel.' );

		// The owner uses the Disable control.
		Group_Subscription_Invite::delete_link_invite( $subscription, $owner );

		$result = Group_Subscription_Teams_Invite::resolve_registration_token( 'reg-key-revoke' );
		$this->assertWPError( $result, 'A revoked link must stay revoked.' );
		$this->assertSame( Group_Subscription_Invite::RESULT_JOIN_TEAM_INVALID, $result->get_error_code() );
		$this->assertNull( Group_Subscription_Invite::get_link_invite( $subscription ), 'Nothing should have been minted.' );

		// Minting again is the owner's own decision, and it supersedes the withdrawal:
		// the marker must not outlive the link it was recorded against.
		$this->assertNotWPError( Group_Subscription_Invite::generate_link_invite( $subscription, $owner ) );
		$this->assertIsString( Group_Subscription_Teams_Invite::resolve_registration_token( 'reg-key-revoke' ), 'A link minted again must resolve.' );
	}

	/**
	 * The invite is bound to the address the Teams row carries, which is whatever a
	 * manager typed years ago; the reader's account carries their own casing. The
	 * acceptance handler compares the two, so a difference in case decides whether
	 * the reader gets in or is told the invitation is for somebody else.
	 */
	public function test_a_case_mismatched_account_still_accepts() {
		$owner        = $this->create_reader();
		$team_id      = $this->create_team( $owner );
		$subscription = $this->create_migrated_group_subscription( $owner, $team_id );
		$reader       = $this->create_reader( 'reader@example.test' );
		// The manager typed it with different casing when inviting through Teams.
		$this->create_team_invitation( $team_id, 'Reader@Example.test', 'tok-mixedcase' );

		$args = $this->query_args_of( Group_Subscription_Teams_Invite::resolve_invitation_token( 'tok-mixedcase' ) );
		$this->assertSame( 'Reader@Example.test', $args['email'], 'The URL carries the address the invite was stored under.' );

		// Accepted against the reader's own address rather than the invite's: that is
		// the pair the acceptance path actually compares, and a strict comparison of it
		// is what tells the reader their invitation belongs to someone else.
		wp_set_current_user( $reader );
		$this->assertTrue(
			Group_Subscription_Invite::accept_invite( $subscription, $args['key'], 'reader@example.test' ),
			'The reader must be admitted despite the casing difference.'
		);
		wp_set_current_user( 0 );
	}

	/**
	 * The token is a bearer credential, and the SQL comparison runs under a
	 * case-insensitive collation, so the exact match has to be settled in PHP.
	 */
	public function test_a_case_variant_token_does_not_resolve() {
		$owner   = $this->create_reader();
		$team_id = $this->create_team( $owner );
		$this->create_migrated_group_subscription( $owner, $team_id );
		$this->create_team_invitation( $team_id, 'exact@test.com', 'AbCdEf123456' );

		$this->assertIsString( Group_Subscription_Teams_Invite::resolve_invitation_token( 'AbCdEf123456' ), 'The exact token resolves.' );
		$this->assertWPError( Group_Subscription_Teams_Invite::resolve_invitation_token( 'abcdef123456' ), 'A case variant must not.' );
	}

	/**
	 * A publisher who renamed the endpoint has that slug baked into every invitation
	 * email already sent, so the route has to answer on the stored value.
	 */
	public function test_endpoint_slug_follows_the_publisher_option() {
		$this->assertSame( 'join-team', Group_Subscription_Teams_Invite::get_endpoint(), 'The Teams default applies when the publisher set nothing.' );

		update_option( 'woocommerce_myaccount_join_team_endpoint', 'join-my-team' );
		$this->assertSame( 'join-my-team', Group_Subscription_Teams_Invite::get_endpoint() );
		// The slug is the registered value; the key is ours, so a renamed endpoint can
		// never displace one of WooCommerce's own.
		$this->assertSame(
			[ Group_Subscription_Teams_Invite::QUERY_VAR => 'join-my-team' ],
			Group_Subscription_Teams_Invite::add_query_var( [] ),
			'The renamed slug is what gets registered, under our own key.'
		);

		// An empty stored value is not a slug; falling through to it would unregister
		// the route rather than rename it.
		update_option( 'woocommerce_myaccount_join_team_endpoint', '' );
		$this->assertSame( 'join-team', Group_Subscription_Teams_Invite::get_endpoint() );
		delete_option( 'woocommerce_myaccount_join_team_endpoint' );
	}

	/**
	 * A publisher who renamed the endpoint onto one WooCommerce already owns would
	 * otherwise have this overwrite it, taking out that account page.
	 */
	public function test_registration_never_overwrites_an_existing_query_var() {
		update_option( 'woocommerce_myaccount_join_team_endpoint', 'orders' );

		// WooCommerce's own endpoints are renameable too, so its stored value need not
		// equal the key — and only a differing value makes an overwrite visible.
		// A collision by value, not by key: WooCommerce's endpoints are renameable too,
		// and registering ours anyway would have this handler redirect away from the
		// account page the guard exists to protect.
		$this->assertSame(
			[ 'orders' => 'orders' ],
			Group_Subscription_Teams_Invite::add_query_var( [ 'orders' => 'orders' ] ),
			'A slug WooCommerce already answers on must not be claimed.'
		);
		$this->assertSame(
			[ 'downloads' => 'orders' ],
			Group_Subscription_Teams_Invite::add_query_var( [ 'downloads' => 'orders' ] ),
			'The collision is on the slug WooCommerce serves, whatever it is keyed under.'
		);
		delete_option( 'woocommerce_myaccount_join_team_endpoint' );
	}

	/**
	 * An empty password is what every post without one stores, so an empty token
	 * must be refused before it reaches the query — otherwise it would match
	 * arbitrary posts and resolve to somebody else's group.
	 */
	public function test_an_empty_token_matches_nothing() {
		$owner   = $this->create_reader();
		$team_id = $this->create_team( $owner );
		$this->create_migrated_group_subscription( $owner, $team_id );
		// A team and an invitation that both carry no password, as the fixture default.
		$this->create_team_invitation( $team_id, 'passwordless@test.com', '' );

		$this->assertWPError( Group_Subscription_Teams_Invite::resolve_invitation_token( '' ), 'An empty invitation token must resolve to nothing.' );
		$this->assertWPError( Group_Subscription_Teams_Invite::resolve_registration_token( '' ), 'An empty registration key must resolve to nothing.' );
	}

	/**
	 * A team's open registration key maps onto the group's invite link, which carries
	 * the same open-to-anyone semantic. The existing link is handed back rather than
	 * replaced: generate_link_invite() overwrites, so minting here would revoke the
	 * link the group's managers are already circulating.
	 */
	public function test_registration_key_resolves_to_the_groups_existing_link_without_replacing_it() {
		$owner        = $this->create_reader();
		$team_id      = $this->create_team( $owner, 'team-reg-key' );
		$subscription = $this->create_migrated_group_subscription( $owner, $team_id );
		$existing     = Group_Subscription_Invite::generate_link_invite( $subscription, $owner );
		$this->assertNotWPError( $existing, 'Fixture link invite should be created.' );

		$url = Group_Subscription_Teams_Invite::resolve_registration_token( 'team-reg-key' );

		$this->assertIsString( $url, 'A registration key should resolve to a URL.' );
		$args = $this->query_args_of( $url );
		$this->assertSame( Group_Subscription_Invite::LINK_QUERY_ARG, $args['action'] );
		$this->assertSame( (string) $subscription->get_id(), $args['subscription'] );
		$this->assertSame( $existing['key'], $args['key'], 'The link already in circulation must survive the redirect.' );
	}

	/**
	 * An invitation link is single-use, as it was under WooCommerce Teams: joining
	 * spends the source row. Without that, a manager who removes a reader cannot stop
	 * the original email re-admitting them — the link is a bearer token no Access
	 * Control screen shows, so there is nothing to revoke.
	 *
	 * The listener is attached here rather than relied on from init(), which
	 * early-returns without the Access Control flag the suite does not define. What
	 * this pins is the pair that carries the behaviour: that accepting an invite
	 * announces it, and that the announcement spends the row.
	 */
	public function test_joining_spends_the_invitation_so_a_removed_reader_cannot_return() {
		add_action( 'newspack_group_subscription_invite_accepted', [ Group_Subscription_Teams_Invite::class, 'close_source_invitation' ], 10, 3 );

		$invitee_email = 'returning@test.com';
		$owner         = $this->create_reader();
		$invitee       = $this->create_reader( $invitee_email );
		$team_id       = $this->create_team( $owner );
		$subscription  = $this->create_migrated_group_subscription( $owner, $team_id );
		$invitation_id = $this->create_team_invitation( $team_id, $invitee_email, 'tok-return' );

		$args = $this->query_args_of( Group_Subscription_Teams_Invite::resolve_invitation_token( 'tok-return' ) );
		$this->assertTrue( Group_Subscription_Invite::accept_invite( $subscription, $args['key'], $args['email'] ), 'The reader should join.' );

		$this->assertSame( 'wcmti-accepted', get_post( $invitation_id )->post_status, 'Joining must spend the source invitation.' );

		// The manager changes their mind.
		Group_Subscription::update_members( $subscription, [], [ $invitee ] );
		$this->assertFalse( (bool) Group_Subscription::user_is_member( $invitee, $subscription ), 'Fixture: the reader is out of the group.' );

		$result = Group_Subscription_Teams_Invite::resolve_invitation_token( 'tok-return' );
		$this->assertWPError( $result, 'A spent link must not re-admit a reader the manager removed.' );
		$this->assertSame( Group_Subscription_Invite::RESULT_JOIN_TEAM_INVALID, $result->get_error_code() );
	}

	/**
	 * A trashed team keeps its registration key, and the group subscription migrated
	 * from it stays active — so without a status check, deleting a team would quietly
	 * bring its open-join link back to life. WooCommerce Teams resolved published
	 * teams only.
	 */
	public function test_a_trashed_teams_registration_key_stops_working() {
		$owner   = $this->create_reader();
		$team_id = $this->create_team( $owner, 'trashed-reg-key' );
		$this->create_migrated_group_subscription( $owner, $team_id );

		$this->assertIsString( Group_Subscription_Teams_Invite::resolve_registration_token( 'trashed-reg-key' ), 'The link works while the team is published.' );

		wp_trash_post( $team_id );

		$result = Group_Subscription_Teams_Invite::resolve_registration_token( 'trashed-reg-key' );
		$this->assertWPError( $result, 'Trashing the team must revoke its open-join link.' );
		$this->assertSame( Group_Subscription_Invite::RESULT_JOIN_TEAM_INVALID, $result->get_error_code() );
	}

	/**
	 * A group that never had an invite link gets one minted, attributed to the owner
	 * — who manages the group by definition, which is what generate_link_invite()
	 * requires.
	 */
	public function test_registration_key_mints_a_link_when_the_group_has_none() {
		$owner        = $this->create_reader();
		$team_id      = $this->create_team( $owner, 'team-no-link' );
		$subscription = $this->create_migrated_group_subscription( $owner, $team_id );
		$this->assertNull( Group_Subscription_Invite::get_link_invite( $subscription ), 'Fixture group should start with no link.' );

		$url = Group_Subscription_Teams_Invite::resolve_registration_token( 'team-no-link' );

		$this->assertIsString( $url );
		$minted = Group_Subscription_Invite::get_link_invite( $subscription );
		$this->assertNotEmpty( $minted['key'], 'A link should have been minted.' );
		$this->assertSame( $minted['key'], $this->query_args_of( $url )['key'] );
		$this->assertSame( $owner, (int) $minted['created_by'], 'The minted link should be attributed to the group owner.' );
	}

	/**
	 * Rows survive a deactivation, but a team nobody migrated has no group to map
	 * onto. Both token kinds fall through to the same message: the reader can do the
	 * same thing about either, and saying which case applies would tell an
	 * unauthenticated caller what the site holds.
	 */
	public function test_tokens_for_an_unmigrated_team_resolve_to_nothing() {
		$owner   = $this->create_reader();
		$team_id = $this->create_team( $owner, 'unmigrated-key' );
		$this->create_team_invitation( $team_id, 'stranded@test.com', 'unmigrated-tok' );
		// A group subscription of the same owner, but marked for no team at all.
		$this->create_migrated_group_subscription( $owner, null );

		$invitation_result   = Group_Subscription_Teams_Invite::resolve_invitation_token( 'unmigrated-tok' );
		$registration_result = Group_Subscription_Teams_Invite::resolve_registration_token( 'unmigrated-key' );

		$this->assertWPError( $invitation_result, 'An invitation for a team that never migrated should refuse.' );
		$this->assertWPError( $registration_result, 'A registration key for a team that never migrated should refuse.' );
		$this->assertSame( Group_Subscription_Invite::RESULT_JOIN_TEAM_INVALID, $invitation_result->get_error_code() );
		$this->assertSame( Group_Subscription_Invite::RESULT_JOIN_TEAM_INVALID, $registration_result->get_error_code(), 'Both kinds fall through to the same message.' );
	}
}
