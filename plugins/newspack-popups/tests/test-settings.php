<?php
/**
 * Class Test Settings
 *
 * @package Newspack_Popups
 */

/**
 * Settings test case.
 */
class DonorLandingPageSettingTest extends WP_UnitTestCase {
	const SECTION = 'donor_settings';
	const KEY     = 'newspack_popups_donor_landing_page';

	/**
	 * Reset the donor landing page option between tests.
	 */
	public function set_up() {
		parent::set_up();
		delete_option( self::KEY );
	}

	/**
	 * Create a page and return its ID.
	 *
	 * @param array $args Overrides for the post array.
	 *
	 * @return int
	 */
	private function create_page( $args = [] ) {
		return self::factory()->post->create(
			array_merge(
				[
					'post_type'   => 'page',
					'post_status' => 'publish',
				],
				$args
			)
		);
	}

	/**
	 * Update the donor landing page setting.
	 *
	 * @param mixed $value The value to save.
	 *
	 * @return bool|WP_Error
	 */
	private function update( $value ) {
		return Newspack_Popups_Settings::update_setting( self::SECTION, self::KEY, $value );
	}

	/**
	 * A published top-level page saves.
	 */
	public function test_published_page_saves() {
		$page_id = $this->create_page();

		$this->assertNotWPError( $this->update( (string) $page_id ) );
		$this->assertSame( (string) $page_id, get_option( self::KEY ) );
	}

	/**
	 * A child page saves. The page list this setting used to validate against was
	 * limited to top-level pages, so child pages could not be selected.
	 */
	public function test_child_page_saves() {
		$parent_id = $this->create_page();
		$child_id  = $this->create_page( [ 'post_parent' => $parent_id ] );

		$this->assertNotWPError( $this->update( (string) $child_id ) );
		$this->assertSame( (string) $child_id, get_option( self::KEY ) );
	}

	/**
	 * Values that don't identify a published page are rejected, and the stored value
	 * is left untouched.
	 *
	 * @param mixed $value The value to save.
	 *
	 * @dataProvider invalid_value_provider
	 */
	public function test_invalid_values_are_rejected( $value ) {
		$saved_id = $this->create_page();
		$this->update( (string) $saved_id );

		$this->assertWPError( $this->update( $value ) );
		$this->assertSame( (string) $saved_id, get_option( self::KEY ), 'The stored value should survive a rejected update.' );
	}

	/**
	 * Values that must not be accepted as a donor landing page.
	 *
	 * @return array
	 */
	public function invalid_value_provider() {
		return [
			'non-numeric string' => [ 'abc' ],
			'trailing garbage'   => [ '12abc' ],
			'decimal'            => [ '5.9' ],
			'nonexistent ID'     => [ '999999' ],
		];
	}

	/**
	 * A draft page is rejected: only published pages qualify.
	 */
	public function test_draft_page_is_rejected() {
		$draft_id = $this->create_page( [ 'post_status' => 'draft' ] );

		$this->assertWPError( $this->update( (string) $draft_id ) );
	}

	/**
	 * A post is rejected: only pages qualify.
	 */
	public function test_post_is_rejected() {
		$post_id = self::factory()->post->create( [ 'post_type' => 'post' ] );

		$this->assertWPError( $this->update( (string) $post_id ) );
	}

	/**
	 * Empty values clear the setting. The Audience wizard's save path passes int 0 to
	 * clear it, so that has to clear rather than fail.
	 *
	 * @param mixed $value The value to save.
	 *
	 * @dataProvider empty_value_provider
	 */
	public function test_empty_values_clear_the_setting( $value ) {
		$page_id = $this->create_page();
		$this->update( (string) $page_id );

		$this->assertNotWPError( $this->update( $value ) );
		$this->assertSame( '', get_option( self::KEY ) );
	}

	/**
	 * Values that should clear the setting.
	 *
	 * @return array
	 */
	public function empty_value_provider() {
		return [
			'empty string' => [ '' ],
			'string zero'  => [ '0' ],
			'integer zero' => [ 0 ],
		];
	}

	/**
	 * The setting reports the saved page for the picker to display.
	 */
	public function test_settings_report_the_saved_page() {
		$page_id = $this->create_page( [ 'post_title' => 'Donate & Support' ] );
		$this->update( (string) $page_id );

		$setting = $this->get_donor_landing_setting();

		$this->assertSame( 'page', $setting['control'] );
		$this->assertSame( (string) $page_id, $setting['value'] );
		$this->assertSame( $page_id, $setting['selected']['value'] );
		$this->assertArrayNotHasKey( 'options', $setting, 'The setting should no longer enumerate pages.' );
	}

	/**
	 * A saved page that is no longer published shows nothing in the picker, while the
	 * stored value is reported as-is.
	 */
	public function test_unpublished_saved_page_shows_nothing_in_the_picker() {
		$page_id = $this->unpublish_saved_page();

		$setting = $this->get_donor_landing_setting();

		$this->assertNull( $setting['selected'], 'The picker should show nothing for a page that is no longer published.' );
		$this->assertSame( (string) $page_id, $setting['value'], 'The stored value should still be reported.' );
	}

	/**
	 * Saving a section resubmits every field it holds, so re-saving an unchanged value
	 * neither fails validation nor clears the setting — even once the saved page is no
	 * longer published. Without this, saving an unrelated donor setting would wipe it.
	 */
	public function test_resaving_an_unchanged_stale_value_is_a_no_op() {
		$page_id = $this->unpublish_saved_page();

		$setting = $this->get_donor_landing_setting();

		$this->assertNotWPError( $this->update( $setting['value'] ) );
		$this->assertSame( (string) $page_id, get_option( self::KEY ), 'An unchanged submission should leave the stored value alone.' );
	}

	/**
	 * Clearing the setting still works while the saved page is unpublished.
	 */
	public function test_stale_value_can_still_be_cleared() {
		$this->unpublish_saved_page();

		$this->assertNotWPError( $this->update( '' ) );
		$this->assertSame( '', get_option( self::KEY ) );
	}

	/**
	 * Save a published page as the donor landing page, then unpublish it.
	 *
	 * @return int The page ID.
	 */
	private function unpublish_saved_page() {
		$page_id = $this->create_page();
		$this->update( (string) $page_id );

		wp_update_post(
			[
				'ID'          => $page_id,
				'post_status' => 'draft',
			]
		);

		return $page_id;
	}

	/**
	 * Get the donor landing page setting configuration.
	 *
	 * @return array
	 */
	private function get_donor_landing_setting() {
		foreach ( Newspack_Popups_Settings::get_settings() as $setting ) {
			if ( isset( $setting['key'] ) && self::KEY === $setting['key'] ) {
				return $setting;
			}
		}
		$this->fail( 'The donor landing page setting was not found.' );
	}
}
