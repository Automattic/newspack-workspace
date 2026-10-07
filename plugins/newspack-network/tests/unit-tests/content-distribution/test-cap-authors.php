<?php
/**
 * Class TestCapAuthors
 *
 * @package Newspack_Network
 */

namespace Test\Content_Distribution;

use Newspack_Network\Content_Distribution\Cap_Authors;
use Newspack_Network\User_Update_Watcher;

require_once __DIR__ . '/mock-co-authors-plus.php';

/**
 * Test the Cap_Authors class.
 */
class TestCapAuthors extends \WP_UnitTestCase {

	/**
	 * The global $coauthors_plus before the test replaced it.
	 *
	 * @var mixed
	 */
	private $original_coauthors_plus;

	/**
	 * Whether the user update watcher was enabled before the test.
	 *
	 * @var bool
	 */
	private $original_watcher_enabled;

	/**
	 * Swap in the Co-Authors Plus stand-in.
	 */
	public function set_up() {
		parent::set_up();
		global $coauthors_plus;
		$this->original_coauthors_plus  = $coauthors_plus;
		$this->original_watcher_enabled = User_Update_Watcher::$enabled;
		$coauthors_plus                 = new \CoAuthors_Plus(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
	}

	/**
	 * Restore the state the test changed.
	 */
	public function tear_down() {
		global $coauthors_plus;
		$coauthors_plus               = $this->original_coauthors_plus; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		User_Update_Watcher::$enabled = $this->original_watcher_enabled;
		parent::tear_down();
	}

	/**
	 * An incoming author that can't be matched to or created as a local user is
	 * left out of the byline, and the rest of the byline is still assigned.
	 */
	public function test_unresolvable_author_is_left_out_of_byline() {
		global $coauthors_plus;

		$post      = self::factory()->post->create_and_get();
		$post_data = [
			Cap_Authors::PAYLOAD_POST_DATA_AUTHORS_KEY => [
				[
					'type'         => 'wp_user',
					'ID'           => 11,
					'user_email'   => 'resolvable@example.test',
					'display_name' => 'Resolvable Author',
				],
				// No email, so there is nothing to match or create a local user by.
				[
					'type'         => 'wp_user',
					'ID'           => 12,
					'display_name' => 'Unresolvable Author',
				],
			],
		];

		Cap_Authors::ingest_incoming_for_post( $post_data, $post, 'https://origin.test' );

		$user = get_user_by( 'email', 'resolvable@example.test' );
		$this->assertInstanceOf( \WP_User::class, $user );
		$this->assertSame( [ $user->user_nicename ], $coauthors_plus->added_coauthors[ $post->ID ] );
	}
}
