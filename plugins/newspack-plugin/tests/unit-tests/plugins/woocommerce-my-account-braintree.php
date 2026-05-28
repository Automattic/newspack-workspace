<?php
/**
 * Tests for Braintree-specific methods in WooCommerce_My_Account.
 *
 * @package Newspack\Tests
 */

use Newspack\WooCommerce_My_Account;

require_once __DIR__ . '/../../mocks/wc-mocks.php';

/**
 * Test Braintree payment token handling in WooCommerce_My_Account.
 */
class Newspack_Test_WooCommerce_My_Account_Braintree extends WP_UnitTestCase {

	/**
	 * Test that allow_braintree_token_deletion returns true for Braintree tokens
	 * even when the caller passes false (i.e., when it is the only payment method).
	 */
	public function test_braintree_token_deletion_is_allowed() {
		$braintree_token = new WC_Payment_Token( 'braintree_cc' );
		$result          = WooCommerce_My_Account::allow_braintree_token_deletion( false, $braintree_token );
		$this->assertTrue( $result, 'Deletion should be allowed for a Braintree payment token even when it is the only method.' );
	}

	/**
	 * Test that remove_braintree_edit_actions removes edit and save actions
	 * from the item array for Braintree payment methods.
	 */
	public function test_braintree_edit_and_save_actions_are_removed() {
		$braintree_token = new WC_Payment_Token( 'braintree_paypal' );
		$item            = [
			'actions' => [
				'edit'   => [
					'url'  => '/edit',
					'name' => 'Edit',
				],
				'save'   => [
					'url'  => '/save',
					'name' => 'Save',
				],
				'delete' => [
					'url'  => '/delete',
					'name' => 'Delete',
				],
			],
		];

		$result = WooCommerce_My_Account::remove_braintree_edit_actions( $item, $braintree_token );

		$this->assertArrayNotHasKey( 'edit', $result['actions'], 'The edit action should be removed for Braintree payment methods.' );
		$this->assertArrayNotHasKey( 'save', $result['actions'], 'The save action should be removed for Braintree payment methods.' );
		$this->assertArrayHasKey( 'delete', $result['actions'], 'The delete action should remain for Braintree payment methods.' );
	}

	/**
	 * Test that non-Braintree payment methods are not affected by either filter.
	 */
	public function test_non_braintree_payment_methods_are_unaffected() {
		$stripe_token = new WC_Payment_Token( 'stripe' );
		$item         = [
			'actions' => [
				'edit'   => [
					'url'  => '/edit',
					'name' => 'Edit',
				],
				'save'   => [
					'url'  => '/save',
					'name' => 'Save',
				],
				'delete' => [
					'url'  => '/delete',
					'name' => 'Delete',
				],
			],
		];

		// allow_braintree_token_deletion should pass through the original value.
		$allow_deletion = WooCommerce_My_Account::allow_braintree_token_deletion( false, $stripe_token );
		$this->assertFalse( $allow_deletion, 'Deletion flag should be unchanged for non-Braintree payment tokens.' );

		// remove_braintree_edit_actions should leave the item unchanged.
		$result = WooCommerce_My_Account::remove_braintree_edit_actions( $item, $stripe_token );
		$this->assertArrayHasKey( 'edit', $result['actions'], 'The edit action should remain for non-Braintree payment methods.' );
		$this->assertArrayHasKey( 'save', $result['actions'], 'The save action should remain for non-Braintree payment methods.' );
		$this->assertArrayHasKey( 'delete', $result['actions'], 'The delete action should remain for non-Braintree payment methods.' );
	}
}
