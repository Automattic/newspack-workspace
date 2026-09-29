<?php
/**
 * Contextual prompt donation listeners: a donation that started from a
 * contextual prompt yields a contextual_prompt_interaction payload; any other
 * donation, or a renewal, yields none.
 *
 * @package Newspack\Tests
 */

use Newspack\Data_Events\Popups;

/**
 * Test the contextual prompt donation listeners.
 */
class Newspack_Test_Contextual_Prompt_Donation_Events extends WP_UnitTestCase {
	/**
	 * A donation_new payload, as Utils::get_donation_data() shapes it.
	 *
	 * @param array $overrides Fields to override.
	 * @return array
	 */
	private function donation( $overrides = [] ) {
		return array_merge(
			[
				'amount'            => 10.0,
				'currency'          => 'USD',
				'recurrence'        => 'once',
				'platform'          => 'wc',
				'referer'           => 'https://example.com/story/',
				'popup_id'          => '',
				'is_renewal'        => false,
				'platform_data'     => [ 'order_id' => 55 ],
				'contextual_prompt' => [
					'post_id'   => 12,
					'placement' => 'mid',
					'condition' => 'generic_control',
				],
			],
			$overrides
		);
	}

	/**
	 * The listener reports the triple with the donation details.
	 */
	public function test_donation_from_a_contextual_prompt_is_reported() {
		$payload = Popups::contextual_prompt_donation_success( time(), $this->donation() );
		$this->assertSame( 'form_submission_success', $payload['action'] );
		$this->assertSame( 'donation', $payload['action_type'] );
		$this->assertSame( 12, $payload['contextual_prompt_post_id'] );
		$this->assertSame( 'mid', $payload['contextual_prompt_placement'] );
		$this->assertSame( 'generic_control', $payload['contextual_prompt_condition'] );
		$this->assertSame( 55, $payload['interaction_data']['donation_order_id'] );
		$this->assertSame( 10.0, $payload['interaction_data']['donation_amount'] );
	}

	/**
	 * No source, no event.
	 */
	public function test_other_donations_are_ignored() {
		$this->assertNull( Popups::contextual_prompt_donation_success( time(), $this->donation( [ 'contextual_prompt' => [] ] ) ) );
	}

	/**
	 * Renewals are not new conversions.
	 */
	public function test_renewals_are_ignored() {
		$this->assertNull( Popups::contextual_prompt_donation_success( time(), $this->donation( [ 'is_renewal' => true ] ) ) );
	}
}
