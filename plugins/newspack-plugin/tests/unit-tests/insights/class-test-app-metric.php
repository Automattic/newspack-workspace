<?php
/**
 * Test App_Metric (Tab 10, NPPD-1882).
 *
 * Covers the PR0 connection/property-selection layer: property persistence and
 * its dynamic-defaults discipline, the pure property-summary flattening (with a
 * captured separate-account shape), and the not-connected config shape. Network
 * paths (Client::account_summaries / has_valid_credentials) resolve to
 * not-connected in the test env, so no HTTP is exercised.
 *
 * @package Newspack\Tests\Insights
 */

namespace Newspack\Tests\Insights;

use Newspack\Insights\App_Metric;
use WP_UnitTestCase;

/**
 * App_Metric test class.
 *
 * @group insights
 */
class Test_App_Metric extends WP_UnitTestCase {

	/**
	 * Clean the option between tests so persistence assertions don't leak.
	 */
	public function tear_down() {
		delete_option( App_Metric::OPTION_PROPERTY_ID );
		parent::tear_down();
	}

	/**
	 * Nothing saved → empty string.
	 */
	public function test_get_selected_property_defaults_empty() {
		$this->assertSame( '', App_Metric::get_selected_property_id() );
	}

	/**
	 * A saved id round-trips.
	 */
	public function test_set_then_get_property() {
		App_Metric::set_selected_property_id( '459878437' );
		$this->assertSame( '459878437', App_Metric::get_selected_property_id() );
	}

	/**
	 * Dynamic-defaults discipline: an empty value clears the option (delete),
	 * it does not persist an empty string.
	 */
	public function test_empty_clears_the_option() {
		App_Metric::set_selected_property_id( '123' );
		App_Metric::set_selected_property_id( '' );
		$this->assertFalse( get_option( App_Metric::OPTION_PROPERTY_ID, false ) );
		$this->assertSame( '', App_Metric::get_selected_property_id() );
	}

	/**
	 * Whitespace-only is treated as empty (clears).
	 */
	public function test_whitespace_clears_the_option() {
		App_Metric::set_selected_property_id( '123' );
		App_Metric::set_selected_property_id( '   ' );
		$this->assertFalse( get_option( App_Metric::OPTION_PROPERTY_ID, false ) );
	}

	/**
	 * Flattening spans accounts and keeps a stable row shape — the app property
	 * lives in a *separate* account from the web property (the Source Media case).
	 */
	public function test_flatten_property_summaries_spans_accounts() {
		$summaries = [
			[
				'account'           => 'accounts/53865026',
				'displayName'       => 'Richland Source',
				'propertySummaries' => [
					[
						'property'    => 'properties/270060996',
						'displayName' => 'Richland Source - GA4',
					],
				],
			],
			[
				'account'           => 'accounts/364032778',
				'displayName'       => 'Default Account for Firebase',
				'propertySummaries' => [
					[
						'property'    => 'properties/499821327',
						'displayName' => 'source-media-app',
					],
				],
			],
		];

		$rows = App_Metric::flatten_property_summaries( $summaries );

		$this->assertCount( 2, $rows );
		$this->assertSame(
			[
				'account_id'    => '53865026',
				'account_name'  => 'Richland Source',
				'property_id'   => '270060996',
				'property_name' => 'Richland Source - GA4',
			],
			$rows[0]
		);
		// The app property in the separate Firebase account is surfaced.
		$this->assertSame( '499821327', $rows[1]['property_id'] );
		$this->assertSame( 'Default Account for Firebase', $rows[1]['account_name'] );
		$this->assertSame( 'source-media-app', $rows[1]['property_name'] );
	}

	/**
	 * Malformed rows are skipped rather than throwing: a property with no
	 * resource name is dropped, a non-array account is ignored, and a missing
	 * display name falls back to the bare property id.
	 */
	public function test_flatten_is_defensive() {
		$summaries = [
			'not-an-array',
			[
				'account'           => 'accounts/1',
				'propertySummaries' => [
					[ 'property' => '' ],                       // dropped: no id.
					[ 'property' => 'properties/999' ],         // kept: name falls back to id.
				],
			],
		];

		$rows = App_Metric::flatten_property_summaries( $summaries );

		$this->assertCount( 1, $rows );
		$this->assertSame( '999', $rows[0]['property_id'] );
		$this->assertSame( '999', $rows[0]['property_name'] );
		$this->assertSame( '', $rows[0]['account_name'] );
	}

	/**
	 * With no Google connection (test env), the config reports not-connected with
	 * an empty property list and a Connections URL, and never throws.
	 */
	public function test_get_config_not_connected_shape() {
		$config = App_Metric::get_config();

		$this->assertFalse( $config['connected'] );
		$this->assertNull( $config['selected_property'] );
		$this->assertFalse( $config['selected_is_visible'] );
		$this->assertSame( [], $config['properties'] );
		$this->assertArrayHasKey( 'settings_url', $config );
		$this->assertStringContainsString( 'page=newspack-settings', $config['settings_url'] );
	}
}
