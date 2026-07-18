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

		// `window.newspack` is an inline token; deferral only applies to external <script src>
		// files, so it is intentionally kept out of the defer exclusions even while present in
		// the delay exclusions. Assert the asymmetry so the two lists cannot silently drift.
		$this->assertNotContains( 'window.newspack', $options['assets']['js_exclusions'] );
	}

	/**
	 * Perfmatters persists its delay list whenever its settings are saved through the UI,
	 * so on a configured site the stored option already contains the reveal scripts. They
	 * must be subtracted from the merged list, not merely omitted from Newspack's own
	 * contribution – otherwise the merge puts them back and the prompts stay delayed on
	 * exactly the sites this targets.
	 */
	public function test_reveal_scripts_undelayed_when_already_in_saved_option() {
		\Newspack_Popups_Model::$has_above_header = true;

		$options = Perfmatters::set_defaults(
			[
				'assets' => [
					'delay_js_inclusions' => [ 'newspack-popups', 'window.newspack', 'newspack-plugin', 'publisher-script' ],
				],
			]
		);

		$this->assertNotContains( 'newspack-popups', $options['assets']['delay_js_inclusions'] );
		$this->assertNotContains( 'window.newspack', $options['assets']['delay_js_inclusions'] );
		$this->assertNotContains( 'newspack-plugin', $options['assets']['delay_js_inclusions'] );

		// The publisher's own entries survive.
		$this->assertContains( 'publisher-script', $options['assets']['delay_js_inclusions'] );
	}

	/**
	 * Without above-header prompts, a saved delay list keeps the reveal scripts.
	 */
	public function test_saved_delay_list_is_preserved_without_above_header_prompts() {
		\Newspack_Popups_Model::$has_above_header = false;

		$options = Perfmatters::set_defaults(
			[
				'assets' => [
					'delay_js_inclusions' => [ 'newspack-popups', 'publisher-script' ],
				],
			]
		);

		$this->assertContains( 'newspack-popups', $options['assets']['delay_js_inclusions'] );
		$this->assertContains( 'publisher-script', $options['assets']['delay_js_inclusions'] );
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
