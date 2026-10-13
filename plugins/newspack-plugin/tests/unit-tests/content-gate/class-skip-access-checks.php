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
	 * The answer belongs to the account, not to the page in view. Co-Authors
	 * Plus grants `edit_others_posts` on the fly to a co-author while their own
	 * post is the current one, and that grant must not let them past every check.
	 */
	public function test_capabilities_granted_for_one_request_do_not_count() {
		$contributor_id = self::factory()->user->create( [ 'role' => 'contributor' ] );
		$own_post_id    = self::factory()->post->create( [ 'post_author' => $contributor_id ] );
		// Shaped like the Co-Authors Plus filter: with no post named, it reads the current one.
		$grant = function ( $allcaps, $caps, $args ) {
			$post = empty( $args[2] ) ? ( $GLOBALS['post'] ?? null ) : get_post( $args[2] );
			if ( 'edit_others_posts' === $args[0] && $post && (int) $post->post_author === (int) ( $args[1] ?? 0 ) ) {
				$allcaps['edit_others_posts'] = true;
			}
			return $allcaps;
		};
		add_filter( 'user_has_cap', $grant, 10, 3 );
		wp_set_current_user( $contributor_id );
		$GLOBALS['post'] = get_post( $own_post_id ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited

		$granted_on_the_fly = user_can( $contributor_id, 'edit_others_posts' );
		$skips              = Content_Gate::should_skip_access_checks( $contributor_id );
		unset( $GLOBALS['post'] );
		remove_filter( 'user_has_cap', $grant, 10 );

		$this->assertTrue( $granted_on_the_fly, 'The premise: the capability is granted while the post is in view.' );
		$this->assertFalse( $skips, 'The account still does not skip access checks.' );
	}

	/**
	 * A value that is neither a user ID nor a user is nobody.
	 */
	public function test_other_values_never_skip_checks() {
		$this->assertTrue( Content_Gate::should_skip_access_checks( 1 ), 'The premise: user 1 is an administrator.' );

		$this->assertFalse( Content_Gate::should_skip_access_checks( true ), 'true is not user 1.' );
		$this->assertFalse( Content_Gate::should_skip_access_checks( [ 1 ] ), 'An array.' );
		$this->assertFalse( Content_Gate::should_skip_access_checks( new stdClass() ), 'An object that is not a user.' );
		$this->assertFalse( Content_Gate::should_skip_access_checks( 'administrator' ), 'A role name.' );
		$this->assertTrue( Content_Gate::should_skip_access_checks( '1' ), 'A numeric string is an ID.' );
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
