<?php // phpcs:ignore WordPress.Files.FileName.InvalidClassFileName
/**
 * The control override's active rule and interval sanitization.
 *
 * @package Newspack_Popups
 */

/**
 * Control override settings test.
 */
class ContextualPromptSettingsTest extends WP_UnitTestCase {
	/**
	 * Clear the control options.
	 */
	public function tear_down() {
		delete_option( Newspack_Popups_Settings::CONTROL_ENABLED_OPTION );
		delete_option( Newspack_Popups_Settings::CONTROL_BODY_OPTION );
		delete_option( Newspack_Popups_Settings::CONTROL_INTERVAL_OPTION );
		parent::tear_down();
	}

	/**
	 * Off by default.
	 */
	public function test_control_is_inactive_by_default() {
		$this->assertFalse( Newspack_Popups_Settings::is_control_active() );
		$this->assertSame( 3, Newspack_Popups_Settings::get_control_interval() );
	}

	/**
	 * Enabled with copy is active.
	 */
	public function test_control_is_active_with_copy() {
		update_option( Newspack_Popups_Settings::CONTROL_ENABLED_OPTION, '1' );
		update_option( Newspack_Popups_Settings::CONTROL_BODY_OPTION, 'Support local news.' );
		$this->assertTrue( Newspack_Popups_Settings::is_control_active() );
	}

	/**
	 * An enabled control with no copy would blank every Nth card, so it is inactive.
	 */
	public function test_control_with_empty_copy_is_inactive() {
		update_option( Newspack_Popups_Settings::CONTROL_ENABLED_OPTION, '1' );
		update_option( Newspack_Popups_Settings::CONTROL_BODY_OPTION, '   ' );
		$this->assertFalse( Newspack_Popups_Settings::is_control_active() );
	}

	/**
	 * The interval is clamped on save so "every 1st story" (all of them) and
	 * absurd values cannot be stored.
	 */
	public function test_interval_is_clamped_on_save() {
		Newspack_Popups_Settings::save_ai_copy_assistant_fields( [ Newspack_Popups_Settings::CONTROL_INTERVAL_OPTION => '1' ] );
		$this->assertSame( 2, Newspack_Popups_Settings::get_control_interval() );
		Newspack_Popups_Settings::save_ai_copy_assistant_fields( [ Newspack_Popups_Settings::CONTROL_INTERVAL_OPTION => '99' ] );
		$this->assertSame( 20, Newspack_Popups_Settings::get_control_interval() );
		Newspack_Popups_Settings::save_ai_copy_assistant_fields( [ Newspack_Popups_Settings::CONTROL_INTERVAL_OPTION => 'abc' ] );
		$this->assertSame( 2, Newspack_Popups_Settings::get_control_interval() );
	}

	/**
	 * The fields reach the settings tab under their own section.
	 */
	public function test_control_fields_are_exposed_in_the_control_section() {
		$fields = Newspack_Popups_Settings::get_ai_copy_assistant_fields();
		$keys   = wp_list_pluck( wp_list_filter( $fields, [ 'section' => 'control' ] ), 'key' );
		$this->assertSame(
			[
				Newspack_Popups_Settings::CONTROL_ENABLED_OPTION,
				Newspack_Popups_Settings::CONTROL_BODY_OPTION,
				Newspack_Popups_Settings::CONTROL_INTERVAL_OPTION,
			],
			array_values( $keys )
		);
	}
}
