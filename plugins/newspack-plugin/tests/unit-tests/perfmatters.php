<?php
/**
 * Tests the Perfmatters integration (NPPM-2934).
 *
 * @package Newspack\Tests
 */

use Newspack\Perfmatters;

require_once __DIR__ . '/../mocks/newspack-popups-model-mock.php';

/**
 * Tests the Perfmatters above-header prompt handling.
 */
class Newspack_Test_Perfmatters extends WP_UnitTestCase {
	public function tear_down() { // phpcs:ignore Squiz.Commenting.FunctionComment.Missing
		\Newspack_Popups_Model::$has_above_header = false;
		parent::tear_down();
	}

	/**
	 * Without above-header prompts, the prompt reveal scripts stay in the JS delay queue.
	 */
	public function test_reveal_scripts_delayed_without_above_header_prompts() {
		\Newspack_Popups_Model::$has_above_header = false;

		$options = Perfmatters::set_defaults( [] );

		$this->assertContains( 'newspack-popups', $options['assets']['delay_js_inclusions'] );
		$this->assertContains( 'window.newspack', $options['assets']['delay_js_inclusions'] );
		$this->assertNotContains( 'newspack-popups', $options['assets']['js_exclusions'] );
		$this->assertNotContains( 'newspack-plugin', $options['assets']['js_exclusions'] );
	}

	/**
	 * With published above-header prompts, the reveal scripts are removed from the JS
	 * delay queue and excluded from deferral so the prompts appear immediately.
	 */
	public function test_reveal_scripts_undelayed_with_above_header_prompts() {
		\Newspack_Popups_Model::$has_above_header = true;

		$options = Perfmatters::set_defaults( [] );

		$this->assertNotContains( 'newspack-popups', $options['assets']['delay_js_inclusions'] );
		$this->assertNotContains( 'window.newspack', $options['assets']['delay_js_inclusions'] );
		$this->assertNotContains( 'newspack-plugin', $options['assets']['delay_js_inclusions'] );

		$this->assertContains( 'newspack-popups', $options['assets']['js_exclusions'] );
		$this->assertContains( 'newspack-plugin', $options['assets']['js_exclusions'] );
	}

	/**
	 * Unrelated scripts remain delayed regardless of above-header prompts.
	 */
	public function test_other_scripts_still_delayed_with_above_header_prompts() {
		\Newspack_Popups_Model::$has_above_header = true;

		$options = Perfmatters::set_defaults( [] );

		$this->assertContains( 'newspack-blocks', $options['assets']['delay_js_inclusions'] );
		$this->assertContains( 'recaptcha', $options['assets']['delay_js_inclusions'] );
	}
}
