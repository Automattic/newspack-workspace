<?php // phpcs:disable WordPress.Files.FileName.InvalidClassFileName, Squiz.Commenting, Generic.Files.OneObjectStructurePerFile.MultipleFound
/**
 * Class Newsletters Test Sync_Membership_Tied_Subscribers_CLI
 *
 * @package Newspack_Newsletters
 */

use Newspack\Newsletters\Subscription_List;
use Newspack\Newsletters\Subscription_Lists;

/**
 * Tests the Subscription_List class
 */
class Sync_Membership_Tied_Subscribers_CLI_Test extends WP_UnitTestCase {
	use WC_Memberships_Setup;

	public static $users = [
		[
			'email'                => 'bob@example.com',
			'membership_status'    => 'wcm-cancelled',
			'exists_in_esp'        => true,
			'currently_subscribed' => false,
		],
		[
			'email'                => 'alice@example.com',
			'membership_status'    => 'wcm-active',
			'exists_in_esp'        => true,
			'currently_subscribed' => false,
		],
		[
			'email'                => 'francis@example.com',
			'membership_status'    => 'wcm-active',
			'exists_in_esp'        => true,
			'currently_subscribed' => true,
		],
		[
			'email'                => 'john@example.com',
			'membership_status'    => 'wcm-active',
			'exists_in_esp'        => false,
			'currently_subscribed' => false,
		],
		[
			'email'                => 'jane@example.com',
			'membership_status'    => 'wcm-cancelled',
			'exists_in_esp'        => false,
			'currently_subscribed' => false,
		],
	];
	public static $list_remote_ids = [
		'main' => 'group-tag1-list1',
	];

	public static function get_mock_response( $response, $endpoint, $args = [] ) {
		$matching_users = array_filter(
			self::$users,
			function ( $user ) use ( $args ) {
				return $user['exists_in_esp'] && $user['email'] === $args['query'];
			}
		);

		foreach ( $matching_users as $user_data ) {
			$response_data = [
				'id'            => '123',
				'contact_id'    => 'aaa',
				'full_name'     => 'Test Name',
				'email_address' => $user_data['email'],
				'status'        => 'subscribed',
				'list_id'       => 'list1',
			];
			if ( $user_data['currently_subscribed'] ) {
				$response_data['interests'] = [
					'tag1' => true,
				];
			}
			$response['exact_matches']['members'][] = $response_data;
		}

		return $response;
	}

	public static function put_mock_response( $response, $endpoint, $args = [] ) {
		$members_endpoint = preg_match( '/lists\/(.*)\/members/', $endpoint, $matches );
		if ( $members_endpoint ) {
			return [ 'status' => 200 ];
		}
		return $response;
	}

	public static function create_test_membership( $user_data, $membership_plan ) {
		$user_id = wp_insert_user(
			[
				'user_login' => $user_data['email'],
				'user_pass'  => '123',
				'user_email' => $user_data['email'],
				'role'       => 'subscriber',
			]
		);
		return wp_insert_post(
			[
				'post_title'  => 'Test User Membership',
				'post_type'   => 'wc_user_membership',
				'post_status' => $user_data['membership_status'],
				'post_author' => $user_id,
				'meta_input'  => [
					'_membership_plan_id' => $membership_plan->get_id(),
					'_start_date'         => current_time( 'mysql' ),
				],
			]
		);
	}

	public static function setup_test_memberships() {
		// Set an ESP.
		\Newspack_Newsletters::set_service_provider( 'mailchimp' );
		update_option( 'newspack_mailchimp_api_key', 'test-us1' );

		// Create a subscription list.
		$subscription_list_post_id = wp_insert_post(
			[
				'post_title'  => 'Test Subscription List',
				'post_type'   => Subscription_Lists::CPT,
				'post_status' => 'publish',
				'meta_input'  => [
					Subscription_List::REMOTE_ID_META => self::$list_remote_ids['main'],
					Subscription_List::TYPE_META      => 'remote',
				],
			]
		);

		// Create a membership plan.
		$membership_plan_rule = new WC_Memberships_Membership_Plan_Rule(
			[
				'content_type_name' => Subscription_Lists::CPT,
				'object_id_rules'   => [ $subscription_list_post_id ],
			]
		);
		$membership_plan = new WC_Memberships_Membership_Plan( 1234 );
		$membership_plan->set_content_restriction_rules( [ $membership_plan_rule ] );

		// Create user memberships.
		foreach ( self::$users as $user_data ) {
			self::create_test_membership( $user_data, $membership_plan );
		}

		// Mock ESP responses.
		add_filter( 'mailchimp_mock_get', [ __CLASS__, 'get_mock_response' ], 10, 3 );
		add_filter( 'mailchimp_mock_put', [ __CLASS__, 'put_mock_response' ], 10, 3 );

		return [ $membership_plan ];
	}

	public function test_cli_sync_membership_tied_subscribers() {
		$expected = [
			'existing_subscribers' => [],
			'new_subscribers'      => [],
		];
		foreach ( self::$users as $user ) {

			if ( ! $user['exists_in_esp'] && $user['membership_status'] !== 'wcm-active' ) {
				continue;
			}

			$result = [ $user['email'] ];
			if ( $user['membership_status'] === 'wcm-active' && ! $user['currently_subscribed'] ) {
				$result[] = [ self::$list_remote_ids['main'] ]; // Lists to add.
				$result[] = []; // Lists to remove.
			} elseif ( $user['membership_status'] !== 'wcm-active' && $user['currently_subscribed'] ) {
				$result[] = []; // Lists to add.
				$result[] = [ self::$list_remote_ids['main'] ]; // Lists to remove.
			}

			if ( ! $user['exists_in_esp'] && $user['membership_status'] !== 'wcm-active' ) {
				continue;
			}
			if ( ! $user['exists_in_esp'] ) {
				$expected['new_subscribers'][] = $result;
			} elseif (
				( $user['membership_status'] === 'wcm-active' && ! $user['currently_subscribed'] ) ||
				( $user['membership_status'] !== 'wcm-active' && $user['currently_subscribed'] )
			) {
				$expected['existing_subscribers'][] = $result;
			}
		}

		global $cli_sync_membership_tied_subscribers_test_results;
		$cli_sync_membership_tied_subscribers_test_results['existing_subscribers'] = [];
		$cli_sync_membership_tied_subscribers_test_results['new_subscribers'] = [];
		add_action(
			'newspack_newsletters_update_contact_lists',
			function( $provider, $email, $lists_to_add, $lists_to_remove ) {
				global $cli_sync_membership_tied_subscribers_test_results;
				$cli_sync_membership_tied_subscribers_test_results['existing_subscribers'][] = [ $email, $lists_to_add, $lists_to_remove ];
			},
			10,
			4
		);
		add_action(
			'newspack_newsletters_contact_subscribed',
			function( $provider, $contact, $lists, $result, $is_updating, $context ) {
				if ( ! $is_updating ) {
					$this->assertEquals( 'Adding contact when running the sync-membership-tied-subscribers CLI sync script.', $context );

					global $cli_sync_membership_tied_subscribers_test_results;
					$cli_sync_membership_tied_subscribers_test_results['new_subscribers'][] = [ $contact['email'], $lists, [] ];
				}
			},
			10,
			6
		);

		\Newspack_Newsletters\CLI\Sync_Membership_Tied_Subscribers_CLI::cli_sync_membership_tied_subscribers(
			[],
			[
				'live'    => true,
				'verbose' => true,
			]
		);

		$users_to_process = array_filter(
			self::$users,
			function( $user ) {
				return ( $user['exists_in_esp'] && ( $user['membership_status'] === 'wcm-active' && ! $user['currently_subscribed'] ) ) ||
				( $user['exists_in_esp'] && ( $user['membership_status'] !== 'wcm-active' && $user['currently_subscribed'] ) ) ||
				( ! $user['exists_in_esp'] && $user['membership_status'] === 'wcm-active' );
			}
		);

		$this->assertEquals(
			count( $users_to_process ),
			count( \WP_CLI::get_test_output( 'success' ) ),
			'All users were processed successfully.'
		);

		$this->assertEqualsCanonicalizing( $expected['existing_subscribers'], $cli_sync_membership_tied_subscribers_test_results['existing_subscribers'] );
		$this->assertEqualsCanonicalizing( $expected['new_subscribers'], $cli_sync_membership_tied_subscribers_test_results['new_subscribers'] );
	}
}
