<?php // phpcs:disable WordPress.Files.FileName.InvalidClassFileName, Squiz.Commenting.FileComment.Missing

/**
 * Provide Newspack_Blocks\Modal_Checkout when newspack-blocks is not loaded in
 * the test environment, by aliasing the shared Newspack_Test_Modal_Checkout
 * double. A single suite-wide implementation avoids competing doubles: the
 * WooCommerce Subscriptions tests alias the same class when it does not exist
 * yet, and both consumers get the full is_modal_checkout() +
 * get_user_id_from_email() surface, driven by request superglobals.
 */
if ( ! class_exists( 'Newspack_Blocks\Modal_Checkout', false ) ) {
	require_once __DIR__ . '/../unit-tests/plugins/woocommerce-subscriptions/class-newspack-test-modal-checkout.php';
	class_alias( 'Newspack_Test_Modal_Checkout', 'Newspack_Blocks\Modal_Checkout' );
}
