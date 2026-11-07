<?php
/**
 * Tests for the My Account delete-account modal.
 *
 * @package Newspack\Tests
 */

use Newspack\Active_Subscriptions_Reader_Data;
use Newspack\Group_Subscription;
use Newspack\Group_Subscription_Settings;
use Newspack\My_Account_UI_V1;
use Newspack\Reader_Activation;
use Newspack\Reader_Data;

/**
 * Test what the delete-account modal tells a reader about their donations and
 * the subscriptions they pay for.
 *
 * @group My_Account_Delete_Account_Modal
 */
class Newspack_Test_My_Account_Delete_Account_Modal extends WP_UnitTestCase {

	/**
	 * The signed-in reader.
	 *
	 * @var int
	 */
	private $reader_id;

	/**
	 * Load the WooCommerce Subscriptions mocks the subscription tests build from.
	 */
	public static function set_up_before_class() {
		parent::set_up_before_class();
		require_once dirname( __DIR__, 2 ) . '/mocks/wc-mocks.php';
		require_once dirname( __DIR__, 2 ) . '/mocks/wcs-gifting-mock.php';
	}

	/**
	 * Sign in a reader for each test, with no subscriptions in the mock store.
	 */
	public function set_up() {
		parent::set_up();
		global $subscriptions_database;
		$subscriptions_database   = [];
		\WCS_Gifting::$recipients = [];
		Group_Subscription::reset_cache();
		$this->reader_id = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		update_user_meta( $this->reader_id, Reader_Activation::READER, true );
		wp_set_current_user( $this->reader_id );
	}

	/**
	 * Sign the reader out and empty the mock subscription store and the gifting
	 * stub's recipient map, which is static and shared across test classes.
	 */
	public function tear_down() {
		global $subscriptions_database;
		$subscriptions_database   = [];
		\WCS_Gifting::$recipients = [];
		Group_Subscription::reset_cache();
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	/**
	 * The modal markup as the signed-in reader sees it.
	 *
	 * @return string Modal markup.
	 */
	private function render_delete_account_modal() {
		ob_start();
		My_Account_UI_V1::delete_account_modal();
		return ob_get_clean();
	}

	/**
	 * Create an active subscription with one line item, because the reader data
	 * item reads its products from the line items.
	 *
	 * @param int    $customer_id Who pays for it.
	 * @param string $status      Subscription status.
	 *
	 * @return \WC_Subscription
	 */
	private function create_subscription( int $customer_id, string $status = 'active' ) {
		return \wcs_create_subscription(
			[
				'customer_id' => $customer_id,
				'status'      => $status,
				'products'    => [ 101 ],
				'items'       => [ new \WC_Order_Item_Product( [ 'product_id' => 101 ] ) ],
			]
		);
	}

	/**
	 * A former donor has no recurring donation left, so the modal neither warns
	 * about cancelling one nor points them at their subscriptions.
	 */
	public function test_former_donor_is_not_warned_about_recurring_payments() {
		Reader_Data::set_is_donor( time(), [ 'user_id' => $this->reader_id ] );
		$donor_modal = $this->render_delete_account_modal();
		$this->assertStringContainsString( 'recurring payments will be cancelled', $donor_modal );
		$this->assertStringContainsString( 'Manage subscriptions', $donor_modal );

		Reader_Data::set_is_former_donor( time(), [ 'user_id' => $this->reader_id ] );
		$former_donor_modal = $this->render_delete_account_modal();
		$this->assertStringNotContainsString( 'recurring payments will be cancelled', $former_donor_modal );
		$this->assertStringNotContainsString( 'Manage subscriptions', $former_donor_modal );
	}

	/**
	 * The request and the email-sent result are steps of one dialog, so the request
	 * can turn into its result.
	 */
	public function test_request_and_email_sent_are_steps_of_one_dialog() {
		$modal = $this->render_delete_account_modal();

		$this->assertSame( 1, substr_count( $modal, '<dialog' ) );
		$this->assertStringContainsString( 'data-step="request"', $modal );
		$this->assertMatchesRegularExpression( '/class="newspack-ui__modal__step[^"]*" data-step="email-sent" inert>/', $modal );
		$this->assertStringContainsString( '&quot;next&quot;:&quot;email-sent&quot;', $modal );
	}

	/**
	 * The request only sends an email, so Delete account takes the outline red after
	 * Cancel and nothing claims focus over the alternatives.
	 */
	public function test_request_leads_with_cancel_and_outline_delete() {
		$modal   = $this->render_delete_account_modal();
		$request = substr( $modal, 0, strpos( $modal, 'data-step="email-sent"' ) );

		$this->assertStringNotContainsString( 'data-autofocus', $modal );
		$this->assertStringContainsString( 'newspack-ui__button--destructive newspack-ui__button--outline', $request );
		$this->assertLessThan( strpos( $request, '<span>Delete account</span>' ), strpos( $request, '<span>Cancel</span>' ) );
	}

	/**
	 * The request lays its actions out in a row when it is medium, while the small
	 * email-sent step keeps its Continue button full width.
	 */
	public function test_actions_follow_each_step_size() {
		$continue = '/class="newspack-ui__button newspack-ui__button--wide newspack-ui__button--primary newspack-ui__modal__close"/';

		Reader_Data::set_is_donor( time(), [ 'user_id' => $this->reader_id ] );
		$modal      = $this->render_delete_account_modal();
		$split      = strpos( $modal, 'data-step="email-sent"' );
		$request    = substr( $modal, 0, $split );
		$email_sent = substr( $modal, $split );
		$this->assertStringContainsString( 'newspack-ui__modal--medium" data-step="request"', $modal );
		$this->assertStringContainsString( 'newspack-ui__stack--justify-end', $request );
		$this->assertStringContainsString( 'newspack-ui__modal--small" data-step="email-sent"', $modal );
		$this->assertStringNotContainsString( 'newspack-ui__stack--justify-end', $email_sent );
		$this->assertMatchesRegularExpression( $continue, $email_sent );

		Reader_Data::set_is_former_donor( time(), [ 'user_id' => $this->reader_id ] );
		$modal   = $this->render_delete_account_modal();
		$request = substr( $modal, 0, strpos( $modal, 'data-step="email-sent"' ) );
		$this->assertStringContainsString( 'newspack-ui__modal--small" data-step="request"', $modal );
		$this->assertStringNotContainsString( 'newspack-ui__stack--justify-end', $request );
	}

	/**
	 * A reader who bought a gift pays for it but doesn't hold it, which leaves
	 * their `active_subscriptions` item empty. Deleting their account still
	 * changes the gift, because WooCommerce Subscriptions removes its recipient,
	 * so they are warned. The warning has to come from what the buyer pays for.
	 */
	public function test_gift_buyer_is_warned_about_recurring_payments() {
		$recipient_id = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		$gift         = $this->create_subscription( $this->reader_id );
		\WCS_Gifting::$recipients[ $gift->get_id() ] = $recipient_id;
		add_filter(
			'wcs_get_users_subscriptions',
			function ( $subscriptions, $user_id ) use ( $gift, $recipient_id ) {
				if ( (int) $user_id === $recipient_id ) {
					$subscriptions[ $gift->get_id() ] = $gift;
				}
				return $subscriptions;
			},
			10,
			2
		);
		Active_Subscriptions_Reader_Data::refresh( $this->reader_id );
		$this->assertSame( [], Reader_Data::get_active_subscriptions( $this->reader_id ), 'The buyer does not hold the gift.' );

		$modal = $this->render_delete_account_modal();

		$this->assertStringContainsString( 'recurring payments will be cancelled', $modal );
		$this->assertStringContainsString( 'Manage subscriptions', $modal );
	}

	/**
	 * A gift's recipient holds its product, so their `active_subscriptions` item is
	 * not empty, but the buyer pays for it. The recipient has no recurring payment
	 * to warn about or manage, so the warning can't come from the item.
	 */
	public function test_gift_recipient_is_not_warned_about_recurring_payments() {
		$buyer_id = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		$gift     = $this->create_subscription( $buyer_id );
		\WCS_Gifting::$recipients[ $gift->get_id() ] = $this->reader_id;
		add_filter(
			'wcs_get_users_subscriptions',
			function ( $subscriptions, $user_id ) use ( $gift ) {
				if ( (int) $user_id === $this->reader_id ) {
					$subscriptions[ $gift->get_id() ] = $gift;
				}
				return $subscriptions;
			},
			10,
			2
		);
		Active_Subscriptions_Reader_Data::refresh( $this->reader_id );
		$this->assertSame( [ 101 ], Reader_Data::get_active_subscriptions( $this->reader_id ), 'The recipient holds the gift product.' );

		$modal = $this->render_delete_account_modal();

		$this->assertStringNotContainsString( 'recurring payments will be cancelled', $modal );
		$this->assertStringNotContainsString( 'Manage subscriptions', $modal );
	}

	/**
	 * A member of a group subscription uses the owner's subscription and pays for
	 * nothing, so there is no recurring payment to warn about or manage.
	 */
	public function test_group_member_is_not_warned_about_recurring_payments() {
		$owner_id     = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		$subscription = $this->create_subscription( $owner_id );
		$subscription->update_meta_data( Group_Subscription_Settings::GROUP_SUBSCRIPTION_META_PREFIX . 'enabled', 'yes' );
		add_user_meta( $this->reader_id, Group_Subscription::GROUP_SUBSCRIPTION_USER_META_KEY, $subscription->get_id() );
		Group_Subscription::reset_cache();
		Reader_Activation::set_reader_verified( $this->reader_id );
		Active_Subscriptions_Reader_Data::refresh( $this->reader_id );
		$this->assertSame( [ 101 ], Reader_Data::get_active_subscriptions( $this->reader_id ), 'The member holds the group product.' );

		$modal = $this->render_delete_account_modal();

		$this->assertStringNotContainsString( 'recurring payments will be cancelled', $modal );
		$this->assertStringNotContainsString( 'Manage subscriptions', $modal );
	}

	/**
	 * A reader with an active subscription of their own is warned, and stops being
	 * warned once it is cancelled.
	 */
	public function test_subscriber_is_warned_until_their_subscription_is_cancelled() {
		$subscription = $this->create_subscription( $this->reader_id );
		$modal        = $this->render_delete_account_modal();
		$this->assertStringContainsString( 'recurring payments will be cancelled', $modal );
		$this->assertStringContainsString( 'Manage subscriptions', $modal );

		$subscription->update_status( 'cancelled' );
		$modal = $this->render_delete_account_modal();
		$this->assertStringNotContainsString( 'recurring payments will be cancelled', $modal );
		$this->assertStringNotContainsString( 'Manage subscriptions', $modal );
	}
}
