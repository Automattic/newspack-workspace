<?php // phpcs:ignore WordPress.Files.FileName.InvalidClassFileName
/**
 * Class Contextual Prompt Opt-In Audit Test
 *
 * The opt-in is what starts drafts travelling to an external model service, and
 * some newsrooms are contractually barred from AI use, so accepting it has to
 * leave a forensic record rather than a bare option write.
 *
 * @package Newspack_Popups
 */

require_once __DIR__ . '/mocks/class-newspack-logger.php';

/**
 * Opt-in audit trail test case.
 */
class ContextualPromptOptInAuditTest extends WP_UnitTestCase {
	/**
	 * Start each test from a known opt-in state and an empty log.
	 */
	public function set_up() {
		parent::set_up();
		delete_option( Newspack_Popups_Settings::AI_COPY_ASSISTANT_ENABLED_OPTION );
		delete_option( Newspack_Popups_Settings::AI_COPY_ASSISTANT_AUDIT_OPTION );
		\Newspack\Logger::$entries = [];
	}

	/**
	 * Leave no opt-in behind for the rest of the suite.
	 */
	public function tear_down() {
		delete_option( Newspack_Popups_Settings::AI_COPY_ASSISTANT_ENABLED_OPTION );
		delete_option( Newspack_Popups_Settings::AI_COPY_ASSISTANT_AUDIT_OPTION );
		\Newspack\Logger::$entries = [];
		parent::tear_down();
	}

	/**
	 * The audit records travelling through the always-on log.
	 *
	 * @return array
	 */
	private function entries() {
		return array_values(
			array_filter(
				\Newspack\Logger::$entries,
				function ( $entry ) {
					return 'newspack_contextual_prompts' === $entry['code'];
				}
			)
		);
	}

	/**
	 * Hooked on the option rather than the REST route, so a flip made over
	 * WP-CLI or by another plugin is recorded the same way.
	 */
	public function test_audit_is_hooked_on_the_option_itself() {
		$option = Newspack_Popups_Settings::AI_COPY_ASSISTANT_ENABLED_OPTION;

		$this->assertNotFalse( has_action( 'add_option_' . $option ) );
		$this->assertNotFalse( has_action( 'update_option_' . $option ) );
	}

	/**
	 * Accepting AI use names who did it and when.
	 */
	public function test_accepting_records_the_actor_and_the_time() {
		$user_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $user_id );

		update_option( Newspack_Popups_Settings::AI_COPY_ASSISTANT_ENABLED_OPTION, true );

		$entries = $this->entries();
		$this->assertCount( 1, $entries );

		$entry = $entries[0];
		$this->assertStringContainsString( 'accepted', $entry['message'] );
		$this->assertTrue( $entry['data']['enabled'] );
		$this->assertSame( $user_id, $entry['data']['user_id'] );
		$this->assertSame( wp_get_current_user()->user_login, $entry['data']['user_login'] );
		$this->assertNotEmpty( $entry['data']['timestamp'] );
		// A parseable absolute time, so the record stands on its own rather than
		// depending on when a consumer received it.
		$this->assertNotFalse( strtotime( $entry['data']['timestamp'] ) );
		// Where the flip ran, so an unattended change (user 0) is explainable.
		$this->assertSame( 'web', $entry['data']['context'] );
	}

	/**
	 * The last state change also lands in a non-autoloaded option, so "who
	 * accepted, and when" is answerable on the site itself even when the logger
	 * pipeline's consumer is unavailable.
	 */
	public function test_the_last_state_change_is_queryable_on_the_site() {
		$user_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $user_id );

		update_option( Newspack_Popups_Settings::AI_COPY_ASSISTANT_ENABLED_OPTION, true );

		$record = get_option( Newspack_Popups_Settings::AI_COPY_ASSISTANT_AUDIT_OPTION );
		$this->assertTrue( $record['enabled'] );
		$this->assertSame( $user_id, $record['user_id'] );
		$this->assertNotFalse( strtotime( $record['timestamp'] ) );
		// A forensic record must not ride into every request's alloptions.
		$this->assertArrayNotHasKey( Newspack_Popups_Settings::AI_COPY_ASSISTANT_AUDIT_OPTION, wp_load_alloptions( true ) );

		delete_option( Newspack_Popups_Settings::AI_COPY_ASSISTANT_ENABLED_OPTION );

		$record = get_option( Newspack_Popups_Settings::AI_COPY_ASSISTANT_AUDIT_OPTION );
		$this->assertFalse( $record['enabled'] );
	}

	/**
	 * Withdrawal is as auditable as acceptance: it closes the window in which
	 * content was travelling off-site.
	 */
	public function test_withdrawing_is_recorded_too() {
		update_option( Newspack_Popups_Settings::AI_COPY_ASSISTANT_ENABLED_OPTION, true );
		\Newspack\Logger::$entries = [];

		update_option( Newspack_Popups_Settings::AI_COPY_ASSISTANT_ENABLED_OPTION, false );

		$entries = $this->entries();
		$this->assertCount( 1, $entries );
		$this->assertStringContainsString( 'withdrawn', $entries[0]['message'] );
		$this->assertFalse( $entries[0]['data']['enabled'] );
	}

	/**
	 * Deleting the option leaves the site un-opted-in just as surely as setting
	 * it false, and `wp option delete` is a live path, so it has to be recorded.
	 */
	public function test_deleting_the_option_records_a_withdrawal() {
		update_option( Newspack_Popups_Settings::AI_COPY_ASSISTANT_ENABLED_OPTION, true );
		\Newspack\Logger::$entries = [];

		delete_option( Newspack_Popups_Settings::AI_COPY_ASSISTANT_ENABLED_OPTION );

		$entries = $this->entries();
		$this->assertCount( 1, $entries );
		$this->assertStringContainsString( 'withdrawn', $entries[0]['message'] );
		$this->assertFalse( $entries[0]['data']['enabled'] );
	}

	/**
	 * Deleting an option that was never written is not a withdrawal, and core
	 * does not fire the hook for it, so nothing is recorded.
	 */
	public function test_deleting_an_absent_option_records_nothing() {
		delete_option( Newspack_Popups_Settings::AI_COPY_ASSISTANT_ENABLED_OPTION );

		$this->assertCount( 0, $this->entries() );
	}

	/**
	 * Neither is deleting an option already storing false: the site was already
	 * un-opted-in, so a second withdrawal record would be noise, and it would
	 * displace the record of the change that actually closed the window.
	 */
	public function test_deleting_an_already_false_option_records_nothing() {
		update_option( Newspack_Popups_Settings::AI_COPY_ASSISTANT_ENABLED_OPTION, true );
		update_option( Newspack_Popups_Settings::AI_COPY_ASSISTANT_ENABLED_OPTION, false );
		$withdrawal                = get_option( Newspack_Popups_Settings::AI_COPY_ASSISTANT_AUDIT_OPTION );
		\Newspack\Logger::$entries = [];

		delete_option( Newspack_Popups_Settings::AI_COPY_ASSISTANT_ENABLED_OPTION );

		$this->assertCount( 0, $this->entries() );
		$this->assertSame( $withdrawal, get_option( Newspack_Popups_Settings::AI_COPY_ASSISTANT_AUDIT_OPTION ), 'The real withdrawal keeps its place in the record.' );
	}

	/**
	 * A save that changes nothing is not a state change, so it leaves no record.
	 * Otherwise the trail would fill with noise and bury the real acceptances.
	 */
	public function test_a_rewrite_of_the_same_value_records_nothing() {
		update_option( Newspack_Popups_Settings::AI_COPY_ASSISTANT_ENABLED_OPTION, true );
		\Newspack\Logger::$entries = [];

		update_option( Newspack_Popups_Settings::AI_COPY_ASSISTANT_ENABLED_OPTION, true );

		$this->assertCount( 0, $this->entries() );
	}

	/**
	 * The wizard's own enable endpoint travels the same path.
	 */
	public function test_the_rest_route_records_through_the_same_hook() {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$request = new WP_REST_Request( 'POST', '/newspack-popups/v1/contextual-prompt/enable' );
		$request->set_param( 'enabled', true );
		Newspack_Popups_Api::api_set_contextual_prompt_enabled( $request );

		$entries = $this->entries();
		$this->assertCount( 1, $entries );
		$this->assertTrue( $entries[0]['data']['enabled'] );
	}
}
