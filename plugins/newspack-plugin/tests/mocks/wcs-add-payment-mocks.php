<?php // phpcs:disable WordPress.Files.FileName.InvalidClassFileName, Squiz.Commenting.FunctionComment.Missing, Squiz.Commenting.ClassComment.Missing, Squiz.Commenting.VariableComment.Missing, Squiz.Commenting.FileComment.Missing, Generic.Files.OneObjectStructurePerFile.MultipleFound, Universal.Files.SeparateFunctionsFromOO.Mixed, Squiz.Commenting.FunctionCommentThrowTag.Missing

// Scaffolding for the add-payment-method eligibility / follow-through tests
// (woocommerce-subscriptions-add-payment.php). Requires wc-mocks.php (for
// WC_Subscription) to be loaded first.
//
// Deliberately defines NO WooCommerce Subscriptions globals (wcs_* functions or
// WCS_* classes): those persist for the whole PHPUnit process and would change
// function_exists()/class_exists() results for every other test in the suite —
// an order-dependent failure. The store-level manual-renewal check is exercised
// through the `newspack_add_payment_method_store_requires_manual_renewal` filter
// instead.

// Subscription double that makes the follow-through's branches reachable. The
// shared WC_Subscription mock cannot return `0` from calculate_date(), throws on
// can_date_be_updated() / get_date_to_display(), and never fails update_dates() —
// so the guard, the unschedulable-date branch and the catch path would all be
// dead without these overrides. Each reads a staged value from $data, defaulting
// to the success path.
if ( class_exists( 'WC_Subscription' ) && ! class_exists( 'NPPD2170_Test_Subscription' ) ) {
	class NPPD2170_Test_Subscription extends WC_Subscription {
		public function can_date_be_updated( $date_type ) {
			unset( $date_type );
			return $this->data['can_date_be_updated'] ?? true;
		}

		public function get_date_to_display( $type ) {
			return $this->data['dates'][ $type ] ?? '';
		}

		public function calculate_date( $date_type = 'next_payment' ) {
			unset( $date_type );
			if ( array_key_exists( 'calculate_date', $this->data ) ) {
				return $this->data['calculate_date'];
			}
			return parent::calculate_date();
		}

		public function update_dates( $dates ) {
			if ( ! empty( $this->data['update_dates_throws'] ) ) {
				throw new \InvalidArgumentException( 'staged update_dates failure' );
			}
			parent::update_dates( $dates );
		}
	}
}
