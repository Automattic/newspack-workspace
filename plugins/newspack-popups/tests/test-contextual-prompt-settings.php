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
		delete_option( 'newspack_contextual_prompts_coverage_area' );
		remove_all_actions( 'newspack_contextual_prompts_render_settings_changed' );
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
		// An emptied field means "no preference", which is the default — not the
		// minimum an empty string would clamp to.
		Newspack_Popups_Settings::save_ai_copy_assistant_fields( [ Newspack_Popups_Settings::CONTROL_INTERVAL_OPTION => '  ' ] );
		$this->assertSame( 3, Newspack_Popups_Settings::get_control_interval() );
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

	/**
	 * Saving a render-affecting field (override/control sections) flushes the
	 * `batcache` group — where Batcache keeps rendered pages — so the front end
	 * reflects the new setting without waiting for entries to expire. The flush is
	 * group-scoped where the object cache supports it, so a probe in another group
	 * has to survive: a plain wp_cache_flush() would clear it too, and clearing it
	 * would leave the narrowing unproven.
	 */
	public function test_saving_a_control_field_flushes_the_cache_and_fires_the_action() {
		wp_cache_set( 'nppd2249-probe', 'cached', 'batcache' );
		wp_cache_set( 'nppd2249-probe-other', 'cached', 'options' );
		$fired = 0;
		add_action(
			'newspack_contextual_prompts_render_settings_changed',
			function () use ( &$fired ) {
				$fired++;
			}
		);
		// The flush is for a persistent cache; the test env has none, so one is
		// declared for the duration of the save.
		$this->using_ext_object_cache( true );
		try {
			Newspack_Popups_Settings::save_ai_copy_assistant_fields(
				[
					Newspack_Popups_Settings::CONTROL_ENABLED_OPTION => '1',
					Newspack_Popups_Settings::CONTROL_BODY_OPTION    => 'Support local news.',
				]
			);
		} finally {
			$this->using_ext_object_cache( false );
		}
		$this->assertFalse( wp_cache_get( 'nppd2249-probe', 'batcache' ), 'The batcache group is flushed.' );
		$this->assertSame( 'cached', wp_cache_get( 'nppd2249-probe-other', 'options' ), 'Another group is left intact: the flush is group-scoped.' );
		$this->assertSame( 1, $fired );
	}

	/**
	 * Without a persistent cache there is nothing to purge — flushing the local
	 * array would only throw away the current request's work — but the change
	 * still has to be announced.
	 */
	public function test_saving_without_a_persistent_cache_still_fires_the_action() {
		wp_cache_set( 'nppd2249-probe', 'cached', 'batcache' );
		$fired = 0;
		add_action(
			'newspack_contextual_prompts_render_settings_changed',
			function () use ( &$fired ) {
				$fired++;
			}
		);
		Newspack_Popups_Settings::save_ai_copy_assistant_fields(
			[
				Newspack_Popups_Settings::CONTROL_ENABLED_OPTION => '1',
				Newspack_Popups_Settings::CONTROL_BODY_OPTION    => 'Support local news.',
			]
		);
		$this->assertSame( 'cached', wp_cache_get( 'nppd2249-probe', 'batcache' ) );
		$this->assertSame( 1, $fired );
	}

	/**
	 * Declare (or withdraw) a persistent object cache for the duration of a save.
	 *
	 * @param bool $using Whether wp_using_ext_object_cache() should report one.
	 */
	private function using_ext_object_cache( $using ) {
		$GLOBALS['_wp_using_ext_object_cache'] = $using; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
	}

	/**
	 * A profile-only save, or a save that changes nothing, leaves the cache alone.
	 */
	public function test_profile_or_unchanged_saves_do_not_flush() {
		update_option( Newspack_Popups_Settings::CONTROL_BODY_OPTION, 'Support local news.' );
		$fired = 0;
		add_action(
			'newspack_contextual_prompts_render_settings_changed',
			function () use ( &$fired ) {
				$fired++;
			}
		);

		wp_cache_set( 'nppd2249-probe', 'cached', 'batcache' );
		Newspack_Popups_Settings::save_ai_copy_assistant_fields( [ 'newspack_contextual_prompts_coverage_area' => 'Springfield' ] );
		$this->assertSame( 'cached', wp_cache_get( 'nppd2249-probe', 'batcache' ) );

		Newspack_Popups_Settings::save_ai_copy_assistant_fields( [ Newspack_Popups_Settings::CONTROL_BODY_OPTION => 'Support local news.' ] );
		$this->assertSame( 'cached', wp_cache_get( 'nppd2249-probe', 'batcache' ) );
		$this->assertSame( 0, $fired );
	}
}
