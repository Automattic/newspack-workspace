<?php
/**
 * Tests for the post-checkout group subscription prompt.
 *
 * @package Newspack\Tests
 */

use Newspack\Group_Subscription;
use Newspack\Group_Subscription_Checkout_Prompt;
use Newspack\Group_Subscription_MyAccount;
use Newspack\Group_Subscription_Settings;
use Newspack\Reader_Activation;

require_once dirname( __DIR__, 4 ) . '/mocks/wc-mocks.php';

/**
 * The order confirmation points a group's buyer at the group page, and nobody else.
 *
 * @group group-subscription-checkout-prompt
 */
class Test_Group_Subscription_Checkout_Prompt extends WP_UnitTestCase {

	/**
	 * The buyer, who owns the group the order created.
	 *
	 * @var int
	 */
	private int $buyer_id;

	/**
	 * Set up empty order stores, a buyer, and a site-wide group label.
	 */
	public function set_up() {
		parent::set_up();
		// A separate-process test loads the mocks from inside a function, so their
		// file-level stores never become globals there.
		global $subscriptions_database, $orders_database;
		$subscriptions_database = [];
		$orders_database        = [];
		$this->buyer_id = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		update_option( Group_Subscription::get_label_option_key( 'singular' ), 'Team' );
	}

	/**
	 * Reset the mock order and subscription stores.
	 */
	public function tear_down() {
		global $subscriptions_database, $orders_database;
		$subscriptions_database = [];
		$orders_database        = [];
		set_query_var( 'order-received', '' );
		wp_set_current_user( 0 );
		delete_option( Group_Subscription::get_label_option_key( 'singular' ) );
		parent::tear_down();
	}

	/**
	 * Place an order that creates one subscription for the buyer.
	 *
	 * @param bool   $is_group            Whether the subscription is a group subscription.
	 * @param string $subscription_status The subscription's status after checkout.
	 *
	 * @return array{0: WC_Order, 1: WC_Subscription} The order and the subscription it created.
	 */
	private function place_order( bool $is_group, string $subscription_status = 'active' ): array {
		$order        = new WC_Order(
			[
				'status'      => 'processing',
				'customer_id' => $this->buyer_id,
			]
		);
		$subscription = wcs_create_subscription(
			[
				'customer_id' => $this->buyer_id,
				'status'      => $subscription_status,
				'parent_id'   => $order->get_id(),
			]
		);
		if ( $is_group ) {
			$subscription->update_meta_data( Group_Subscription_Settings::GROUP_SUBSCRIPTION_META_PREFIX . 'enabled', 'yes' );
			$subscription->update_meta_data( Group_Subscription_Settings::GROUP_SUBSCRIPTION_META_PREFIX . 'limit', 10 );
			$subscription->update_meta_data( Group_Subscription_Settings::GROUP_SUBSCRIPTION_META_PREFIX . 'name', 'Newsroom Desk' );
		}
		return [ $order, $subscription ];
	}

	/**
	 * The buyer sees the group's name, its free seats in the site's own label, and
	 * a link to the group page.
	 */
	public function test_group_order_prompts_the_buyer() {
		[ $order, $subscription ] = $this->place_order( true );

		$html = Group_Subscription_Checkout_Prompt::get_prompt_html( $order, $this->buyer_id );

		$this->assertStringContainsString( 'Newsroom Desk', $html );
		// Ten seats, one of them the owner's.
		$this->assertStringContainsString( '9 seats are available in your team.', $html );
		$this->assertStringContainsString( 'Add team members', $html );
		$this->assertStringContainsString( esc_url( Group_Subscription_MyAccount::get_group_url( $subscription ) ), $html );
	}

	/**
	 * Both confirmation screens are wired up. The modal's hook really is spelled
	 * "newpack", so a well-meant rename would silently drop the modal prompt.
	 */
	public function test_prompt_is_hooked_into_both_confirmation_screens() {
		if ( ! defined( 'NEWSPACK_CONTENT_GATES' ) ) {
			define( 'NEWSPACK_CONTENT_GATES', true );
		}
		Group_Subscription_Checkout_Prompt::init();

		$this->assertNotFalse( has_action( 'woocommerce_before_thankyou', [ Group_Subscription_Checkout_Prompt::class, 'render_for_order_id' ] ) );
		$this->assertNotFalse( has_action( 'newpack_blocks_modal_checkout_thankyou', [ Group_Subscription_Checkout_Prompt::class, 'render_for_modal_checkout' ] ) );
	}

	/**
	 * A group with no free seat isn't sent to an invite form that would refuse it.
	 */
	public function test_full_group_points_at_the_group_instead_of_adding_members() {
		[ $order, $subscription ] = $this->place_order( true );
		$subscription->update_meta_data( Group_Subscription_Settings::GROUP_SUBSCRIPTION_META_PREFIX . 'limit', 2 );
		add_user_meta( self::factory()->user->create(), Group_Subscription::GROUP_SUBSCRIPTION_USER_META_KEY, $subscription->get_id() );

		$html = Group_Subscription_Checkout_Prompt::get_prompt_html( $order, $this->buyer_id );

		$this->assertStringContainsString( 'Every seat in your team is taken.', $html );
		$this->assertStringContainsString( 'Manage your team', $html );
		$this->assertStringNotContainsString( 'Add team members', $html );
	}

	/**
	 * The modal finds the order from the confirmation URL, and only its link breaks
	 * out of the checkout iframe, closing the modal on the way.
	 */
	public function test_modal_confirmation_renders_the_prompt_for_its_order() {
		[ $order ] = $this->place_order( true );
		wp_set_current_user( $this->buyer_id );
		set_query_var( 'order-received', (string) $order->get_id() );

		ob_start();
		Group_Subscription_Checkout_Prompt::render_for_modal_checkout();
		$modal_html = ob_get_clean();
		ob_start();
		Group_Subscription_Checkout_Prompt::render_for_order_id( $order->get_id() );
		$page_html = ob_get_clean();

		$this->assertStringContainsString( 'Newsroom Desk', $modal_html );
		$this->assertStringContainsString( 'target="_top"', $modal_html );
		// Leaving through the modal's close flow, so its after-checkout steps still run.
		$this->assertStringContainsString( 'parent.newspackCloseModalCheckout()', $modal_html );
		$this->assertStringNotContainsString( 'newspackCloseModalCheckout', $page_html );
		$this->assertStringContainsString( 'Newsroom Desk', $page_html );
		$this->assertStringNotContainsString( 'target="_top"', $page_html );
	}

	/**
	 * While WooCommerce Memberships is active the group page redirects to the
	 * dashboard, so the prompt has nowhere to send the buyer.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_no_prompt_while_memberships_is_active() {
		require dirname( __DIR__, 4 ) . '/mocks/wc-memberships-active-mock.php';
		[ $order ] = $this->place_order( true );

		$this->assertSame( '', Group_Subscription_Checkout_Prompt::get_prompt_html( $order, $this->buyer_id ) );
	}

	/**
	 * Cases that must render no prompt.
	 *
	 * @return array<string, array{0: bool, 1: string, 2: string}> Is group, subscription status, viewer.
	 */
	public function data_no_prompt() {
		return [
			'order without a group'          => [ false, 'active', 'buyer' ],
			'group still waiting on payment' => [ true, 'pending', 'buyer' ],
			'viewer who cannot manage it'    => [ true, 'active', 'other' ],
			'logged-out viewer'              => [ true, 'active', 'nobody' ],
			'buyer not yet verified'         => [ true, 'active', 'unverified buyer' ],
		];
	}

	/**
	 * Only an active group's verified managers get the prompt: an unpaid group can't
	 * take invites yet, an unverified reader's My Account shows only a verification
	 * prompt, and anyone else would be turned away by the group page.
	 *
	 * @dataProvider data_no_prompt
	 *
	 * @param bool   $is_group            Whether the order creates a group.
	 * @param string $subscription_status The subscription's status.
	 * @param string $viewer              Who views the confirmation.
	 */
	public function test_no_prompt( bool $is_group, string $subscription_status, string $viewer ) {
		[ $order ] = $this->place_order( $is_group, $subscription_status );
		if ( 'unverified buyer' === $viewer ) {
			update_user_meta( $this->buyer_id, Reader_Activation::READER, true );
			update_user_meta( $this->buyer_id, Reader_Activation::EMAIL_VERIFIED, false );
		}
		$viewer_id = [
			'buyer'            => $this->buyer_id,
			'unverified buyer' => $this->buyer_id,
			'other'            => self::factory()->user->create( [ 'role' => 'subscriber' ] ),
			'nobody'           => 0,
		][ $viewer ];

		$this->assertSame( '', Group_Subscription_Checkout_Prompt::get_prompt_html( $order, $viewer_id ) );
	}
}
