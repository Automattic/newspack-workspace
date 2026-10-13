<?php
/**
 * Tests for Content_Gate::should_skip_access_checks() (NPPD-2410).
 *
 * @package Newspack
 */

use Newspack\Content_Gate;

/**
 * Access Control skips its checks for an account that can edit other people's
 * posts, and applies them to every other account, whatever its role is called.
 *
 * @group Content_Gate_Skip_Access_Checks
 */
class Test_Content_Gate_Skip_Access_Checks extends WP_UnitTestCase {

	/**
	 * A custom role that can edit other people's posts.
	 */
	private const EDITING_ROLE = 'newspack_test_managing_editor';

	/**
	 * A custom role that cannot.
	 */
	private const PLAIN_ROLE = 'newspack_test_member';

	/**
	 * The Guest Contributor role's name.
	 */
	private const GUEST_ROLE = 'contributor_no_edit';

	/**
	 * Whether this test added the Guest Contributor role.
	 *
	 * @var bool
	 */
	private $added_guest_role = false;

	/**
	 * Set up.
	 */
	public function set_up() {
		parent::set_up();
		// phpcs:disable WordPressVIPMinimum.Functions.RestrictedFunctions.custom_role_add_role
		add_role(
			self::EDITING_ROLE,
			'Managing editor',
			[
				'read'              => true,
				'edit_posts'        => true,
				'edit_others_posts' => true,
			]
		);
		add_role( self::PLAIN_ROLE, 'Member', [ 'read' => true ] );
		if ( ! get_role( self::GUEST_ROLE ) ) {
			add_role( self::GUEST_ROLE, 'Guest Contributor', [ 'read' => true ] );
			$this->added_guest_role = true;
		}
		// phpcs:enable
	}

	/**
	 * Tear down.
	 */
	public function tear_down() {
		remove_role( self::EDITING_ROLE );
		remove_role( self::PLAIN_ROLE );
		if ( $this->added_guest_role ) {
			remove_role( self::GUEST_ROLE );
		}
		remove_all_filters( 'newspack_content_gate_should_skip_access_checks' );
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	/**
	 * Roles and whether access checks are skipped for each.
	 *
	 * @return array[]
	 */
	public function data_roles() {
		return [
			'administrator'                      => [ 'administrator', true ],
			'editor'                             => [ 'editor', true ],
			'custom role with editing powers'    => [ self::EDITING_ROLE, true ],
			'author'                             => [ 'author', false ],
			'contributor'                        => [ 'contributor', false ],
			'guest contributor'                  => [ self::GUEST_ROLE, false ],
			'custom role without editing powers' => [ self::PLAIN_ROLE, false ],
			'subscriber'                         => [ 'subscriber', false ],
		];
	}

	/**
	 * The answer is the same given an ID, a user object, or the current user.
	 *
	 * @dataProvider data_roles
	 *
	 * @param string $role     Role name.
	 * @param bool   $expected Whether access checks are skipped.
	 */
	public function test_only_accounts_that_edit_others_posts_skip_checks( $role, $expected ) {
		$user_id = self::factory()->user->create( [ 'role' => $role ] );

		$this->assertSame( $expected, Content_Gate::should_skip_access_checks( $user_id ), 'By user ID.' );
		$this->assertSame( $expected, Content_Gate::should_skip_access_checks( get_user_by( 'id', $user_id ) ), 'By user object.' );
		wp_set_current_user( $user_id );
		$this->assertSame( $expected, Content_Gate::should_skip_access_checks(), 'As the current user.' );
	}

	/**
	 * Holding an editing role alongside another role still skips the checks.
	 */
	public function test_an_editing_role_among_several_skips_checks() {
		$user_id = self::factory()->user->create( [ 'role' => 'author' ] );
		get_user_by( 'id', $user_id )->add_role( 'editor' );

		$this->assertTrue( Content_Gate::should_skip_access_checks( $user_id ) );
	}

	/**
	 * Without a real user the checks always apply, and the filter is not asked.
	 */
	public function test_no_user_never_skips_checks() {
		$calls = 0;
		add_filter(
			'newspack_content_gate_should_skip_access_checks',
			function () use ( &$calls ) {
				++$calls;
				return true;
			}
		);
		wp_set_current_user( 0 );

		$this->assertFalse( Content_Gate::should_skip_access_checks(), 'Logged out.' );
		$this->assertFalse( Content_Gate::should_skip_access_checks( 0 ), 'User ID 0.' );
		$this->assertFalse( Content_Gate::should_skip_access_checks( 999999 ), 'A user that does not exist.' );
		$this->assertSame( 0, $calls, 'The filter never runs without a real user.' );
	}

	/**
	 * A site can move the line in either direction.
	 */
	public function test_filter_decides_in_both_directions() {
		$author_id = self::factory()->user->create( [ 'role' => 'author' ] );
		$editor_id = self::factory()->user->create( [ 'role' => 'editor' ] );
		add_filter(
			'newspack_content_gate_should_skip_access_checks',
			function ( $skips, $user_id ) use ( $author_id, $editor_id ) {
				if ( $user_id === $author_id ) {
					return true;
				}
				return $user_id === $editor_id ? false : $skips;
			},
			10,
			2
		);

		$this->assertTrue( Content_Gate::should_skip_access_checks( $author_id ), 'Filtered in.' );
		$this->assertFalse( Content_Gate::should_skip_access_checks( $editor_id ), 'Filtered out.' );
	}
}
