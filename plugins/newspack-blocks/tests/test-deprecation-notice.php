<?php
/**
 * Tests for the deprecation notice.
 *
 * @package Newspack_Blocks
 */

if ( ! class_exists( 'Newspack_Manager' ) ) {
	// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound
	/**
	 * Newspack Manager stub.
	 */
	class Newspack_Manager { // phpcs:ignore
		/**
		 * Connected flag.
		 *
		 * @var bool
		 */
		public static $connected = false;

		/**
		 * Whether connected.
		 *
		 * @return bool
		 */
		public static function is_connected_to_manager() {
			return self::$connected;
		}
	}
}

/**
 * Deprecation notice tests.
 */
class Newspack_Blocks_Test_Deprecation_Notice extends WP_UnitTestCase {
	/**
	 * Set up: an administrator on the dashboard, nothing else active.
	 */
	public function set_up() {
		parent::set_up();
		Newspack_Manager::$connected = false;
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		set_current_screen( 'dashboard' );
		update_option( 'active_plugins', [] );
		add_filter( 'newspack_blocks_deprecation_notice_has_bundled_copy', '__return_false' );
		add_filter( 'newspack_blocks_deprecation_notice_has_newspack_plugin', '__return_false' );
		// The Manager stub class always exists in this file; treat it as not installed unless a test says so.
		add_filter( 'newspack_blocks_deprecation_notice_manager_installed', '__return_false' );
	}

	/**
	 * Tear down.
	 */
	public function tear_down() {
		unset( $_POST['nonce'], $_POST['state'], $_REQUEST['nonce'] );
		Newspack_Manager::$connected = false;
		set_current_screen( 'front' );
		parent::tear_down();
	}

	/**
	 * Activate the content blocks plugin and the bundled copy.
	 *
	 * @param bool $with_bundled_copy Whether the Newspack plugin's copy is present.
	 */
	private function activate_replacements( $with_bundled_copy = true ) {
		update_option( 'active_plugins', [ Newspack_Blocks_Deprecation_Notice::CONTENT_BLOCKS_PLUGIN ] );
		remove_filter( 'newspack_blocks_deprecation_notice_has_bundled_copy', '__return_false' );
		if ( $with_bundled_copy ) {
			add_filter( 'newspack_blocks_deprecation_notice_has_bundled_copy', '__return_true' );
		} else {
			add_filter( 'newspack_blocks_deprecation_notice_has_bundled_copy', '__return_false' );
		}
	}

	/**
	 * Replacement for wp_die() that stops execution the way it would.
	 *
	 * @throws \RuntimeException Always.
	 */
	public function throw_on_die() {
		throw new \RuntimeException( 'wp_die' );
	}

	/**
	 * Rendered notice HTML.
	 *
	 * @return string
	 */
	private function render() {
		ob_start();
		Newspack_Blocks_Deprecation_Notice::render();
		return ob_get_clean();
	}

	/**
	 * No notice on Manager-connected sites.
	 */
	public function test_no_notice_when_manager_connected() {
		Newspack_Manager::$connected = true;
		$this->assertNull( Newspack_Blocks_Deprecation_Notice::get_state() );
		$this->assertSame( '', $this->render() );
	}

	/**
	 * No notice when Manager is installed, even if not connected.
	 */
	public function test_no_notice_when_manager_installed_but_not_connected() {
		Newspack_Manager::$connected = false;
		remove_filter( 'newspack_blocks_deprecation_notice_manager_installed', '__return_false' );
		$this->assertTrue( class_exists( 'Newspack_Manager' ) );
		$this->assertNull( Newspack_Blocks_Deprecation_Notice::get_state() );
		$this->assertSame( '', $this->render() );
	}

	/**
	 * Move state while content blocks isn't active.
	 */
	public function test_move_state_when_content_blocks_inactive() {
		$this->assertSame( 'move', Newspack_Blocks_Deprecation_Notice::get_state() );
		$html = $this->render();
		$this->assertStringContainsString( Newspack_Blocks_Deprecation_Notice::CONTENT_BLOCKS_DOWNLOAD_URL, $html );
		$this->assertStringContainsString( Newspack_Blocks_Deprecation_Notice::NEWSPACK_PLUGIN_DOWNLOAD_URL, $html );
	}

	/**
	 * Ready state once both replacements are active.
	 */
	public function test_ready_state_when_both_active() {
		$this->activate_replacements();
		$this->assertSame( 'ready', Newspack_Blocks_Deprecation_Notice::get_state() );
		$html = $this->render();
		$this->assertStringContainsString( 'its blocks now ship in other plugins', $html );
		$this->assertStringContainsString( 'you can deactivate Newspack Blocks', $html );
	}

	/**
	 * Move state when content blocks is active but the Newspack plugin isn't.
	 */
	public function test_move_state_when_content_blocks_active_without_newspack_plugin() {
		$this->activate_replacements( false );
		$this->assertSame( 'move', Newspack_Blocks_Deprecation_Notice::get_state() );
	}

	/**
	 * The move notice asks only for what's missing when the Newspack plugin is active.
	 */
	public function test_move_copy_with_newspack_plugin() {
		remove_filter( 'newspack_blocks_deprecation_notice_has_newspack_plugin', '__return_false' );
		add_filter( 'newspack_blocks_deprecation_notice_has_newspack_plugin', '__return_true' );
		$html = $this->render();
		$this->assertStringContainsString( 'update the Newspack plugin', $html );
		$this->assertStringNotContainsString( Newspack_Blocks_Deprecation_Notice::NEWSPACK_PLUGIN_DOWNLOAD_URL, $html );

		remove_filter( 'newspack_blocks_deprecation_notice_has_bundled_copy', '__return_false' );
		add_filter( 'newspack_blocks_deprecation_notice_has_bundled_copy', '__return_true' );
		$html = $this->render();
		$this->assertStringContainsString( 'now ship in the Newspack plugin', $html );
		$this->assertStringNotContainsString( 'update the Newspack plugin', $html );
		$this->assertStringContainsString( Newspack_Blocks_Deprecation_Notice::CONTENT_BLOCKS_DOWNLOAD_URL, $html );
	}

	/**
	 * Dismissal is per state.
	 */
	public function test_dismissed_state_hidden_other_state_shown() {
		update_user_meta( get_current_user_id(), Newspack_Blocks_Deprecation_Notice::DISMISSED_META_KEY, [ 'move' ] );
		$this->assertSame( '', $this->render() );

		$this->activate_replacements();
		$this->assertStringContainsString( 'data-state="ready"', $this->render() );
	}

	/**
	 * Only the dashboard and plugins screens.
	 */
	public function test_only_on_dashboard_and_plugins_screens() {
		$this->assertNotSame( '', $this->render() );
		set_current_screen( 'plugins' );
		$this->assertNotSame( '', $this->render() );
		set_current_screen( 'edit-post' );
		$this->assertSame( '', $this->render() );
	}

	/**
	 * Users who can't install plugins see nothing.
	 */
	public function test_hidden_for_users_without_install_plugins() {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );
		$this->assertNull( Newspack_Blocks_Deprecation_Notice::get_state() );
		$this->assertSame( '', $this->render() );
	}

	/**
	 * The dismissal endpoint stores the state for the user.
	 */
	public function test_dismiss_endpoint_stores_state() {
		$_POST['nonce'] = wp_create_nonce( Newspack_Blocks_Deprecation_Notice::DISMISS_ACTION );
		$_REQUEST['nonce'] = $_POST['nonce'];
		$_POST['state'] = 'move';
		add_filter( 'wp_die_ajax_handler', fn() => [ $this, 'throw_on_die' ] );
		add_filter( 'wp_doing_ajax', '__return_true' );
		ob_start();
		try {
			Newspack_Blocks_Deprecation_Notice::handle_dismiss();
		} catch ( \RuntimeException $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
			unset( $e );
		}
		ob_end_clean();
		$this->assertSame( [ 'move' ], get_user_meta( get_current_user_id(), Newspack_Blocks_Deprecation_Notice::DISMISSED_META_KEY, true ) );
	}

	/**
	 * The dismissal endpoint rejects a bad nonce.
	 */
	public function test_dismiss_endpoint_rejects_bad_nonce() {
		$_POST['nonce'] = 'bad';
		$_REQUEST['nonce'] = 'bad';
		$_POST['state'] = 'move';
		add_filter( 'wp_die_ajax_handler', fn() => [ $this, 'throw_on_die' ] );
		add_filter( 'wp_doing_ajax', '__return_true' );
		ob_start();
		try {
			Newspack_Blocks_Deprecation_Notice::handle_dismiss();
		} catch ( \RuntimeException $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
			unset( $e );
		}
		ob_end_clean();
		$this->assertEmpty( get_user_meta( get_current_user_id(), Newspack_Blocks_Deprecation_Notice::DISMISSED_META_KEY, true ) );
	}
}
