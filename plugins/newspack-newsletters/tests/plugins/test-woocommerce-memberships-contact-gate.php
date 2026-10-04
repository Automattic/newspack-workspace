<?php // phpcs:disable Squiz.Commenting, Universal.Files, Generic.Files
/**
 * The membership gate on newsletter lists checks the contact being written.
 *
 * @package Newspack_Newsletters
 */

use Newspack_Newsletters\Plugins\Woocommerce_Memberships;
use Newspack\Newsletters\Subscription_List;
use Newspack\Newsletters\Subscription_Lists;
use function Newspack_Newsletters\Blocks\Subscribe\process_form;

// The gate runs only when these exist. Nothing else in the suite defines them,
// and a list counts as restricted only when a test names it below, so other
// tests see every valid list as open.
if ( ! class_exists( 'WC_Memberships_Loader' ) ) {
	class WC_Memberships_Loader {} // phpcs:ignore Generic.Classes.OpeningBraceSameLine
}
if ( ! function_exists( 'wc_memberships_is_post_content_restricted' ) ) {
	function wc_memberships_is_post_content_restricted( $post_id ) {
		return isset( WCM_Gate_Fixture::$restricted[ $post_id ] );
	}
}
if ( ! function_exists( 'wc_memberships_user_can' ) ) {
	function wc_memberships_user_can( $user_id, $action, $target ) {
		$post_id = $target['post'] ?? 0;
		return $user_id && in_array( (int) $user_id, WCM_Gate_Fixture::$restricted[ $post_id ] ?? [], true );
	}
}

class WCM_Gate_Fixture {
	/**
	 * Restricted list post ID => user IDs allowed to view it.
	 *
	 * @var array<int,int[]>
	 */
	public static $restricted = [];
}

/**
 * The membership gate checks the contact being written, and the subscribe form
 * does not reveal what it decided.
 *
 * @group subscribe-block
 */
class Woocommerce_Memberships_Contact_Gate_Test extends WP_UnitTestCase {
	private $member_id;
	private $non_member_id;
	private $open_list;
	private $restricted_list;

	public function set_up() {
		parent::set_up();
		$this->member_id     = self::factory()->user->create( [ 'user_email' => 'member@example.test' ] );
		$this->non_member_id = self::factory()->user->create( [ 'user_email' => 'reader@example.test' ] );
		$this->open_list       = $this->create_list( 'Open' );
		$this->restricted_list = $this->create_list( 'Members only' );
		WCM_Gate_Fixture::$restricted = [ $this->restricted_list => [ $this->member_id ] ];
		self::clear_user_in_scope();
	}

	/**
	 * A membership activation earlier in the run leaves its user in scope, and
	 * the gate checks that user ahead of the contact.
	 */
	private static function clear_user_in_scope() {
		$scope = new ReflectionProperty( Woocommerce_Memberships::class, 'user_id_in_scope' );
		$scope->setAccessible( true );
		$scope->setValue( null, null );
	}

	public function tear_down() {
		WCM_Gate_Fixture::$restricted = [];
		self::clear_user_in_scope();
		wp_set_current_user( 0 );
		unset( $_REQUEST[ \Newspack_Newsletters\Blocks\Subscribe\FORM_ACTION ], $_REQUEST['npe'], $_REQUEST['lists'] );
		parent::tear_down();
	}

	private function create_list( $title ) {
		$post_id = wp_insert_post(
			[
				'post_title'  => $title,
				'post_type'   => Subscription_Lists::CPT,
				'post_status' => 'publish',
			]
		);
		update_post_meta(
			$post_id,
			Subscription_List::META_KEY,
			[
				'mailchimp' => [
					'list'   => 'aud1',
					'tag_id' => $title,
				],
			]
		);
		return $post_id;
	}

	private function public_id( $post_id ) {
		return ( new Subscription_List( $post_id ) )->get_public_id();
	}

	private function gate( $contact ) {
		return apply_filters( 'newspack_newsletters_contact_lists', [ $this->public_id( $this->open_list ), $this->public_id( $this->restricted_list ) ], $contact, 'mailchimp' );
	}

	public function test_a_member_session_cannot_pass_the_gate_for_another_address() {
		wp_set_current_user( $this->member_id );
		$this->assertSame( [ $this->public_id( $this->open_list ) ], $this->gate( [ 'email' => 'reader@example.test' ] ) );
	}

	public function test_an_entitled_address_passes_without_a_session() {
		$this->assertSame(
			[ $this->public_id( $this->open_list ), $this->public_id( $this->restricted_list ) ],
			$this->gate( [ 'email' => 'member@example.test' ] )
		);
	}

	public function test_an_address_with_no_account_is_gated_without_an_error() {
		$this->assertSame( [ $this->public_id( $this->open_list ) ], $this->gate( [ 'email' => 'nobody@example.test' ] ) );
	}

	public function test_lists_offered_on_screen_still_follow_the_session() {
		wp_set_current_user( $this->member_id );
		$this->assertSame(
			[ $this->public_id( $this->restricted_list ) ],
			Woocommerce_Memberships::filter_lists( [ $this->public_id( $this->restricted_list ) ] )
		);
	}

	/**
	 * An error here would tell a member whether another address holds the plan.
	 */
	public function test_a_request_left_with_no_list_answers_as_a_subscribe_and_writes_nothing() {
		wp_set_current_user( $this->member_id );
		$_REQUEST[ \Newspack_Newsletters\Blocks\Subscribe\FORM_ACTION ] = '1';
		$_REQUEST['npe']   = 'reader@example.test';
		$_REQUEST['lists'] = [ $this->public_id( $this->restricted_list ) ];
		$writes = 0;
		$count  = function () use ( &$writes ) {
			++$writes;
		};
		add_action( 'newspack_newsletters_upsert', $count );
		add_action( 'newspack_newsletters_subscribe_form_processed', $count );
		$original_accept        = $_SERVER['HTTP_ACCEPT'] ?? null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- restored below.
		$_SERVER['HTTP_ACCEPT'] = 'application/json';
		add_filter( 'wp_doing_ajax', '__return_true' );
		add_filter( 'wp_die_ajax_handler', [ $this, 'get_wp_die_handler' ] );
		ob_start();
		try {
			process_form();
		} catch ( WPDieException $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
			// wp_send_json() always ends in wp_die().
		} finally {
			$output = ob_get_clean();
			remove_filter( 'wp_die_ajax_handler', [ $this, 'get_wp_die_handler' ] );
			remove_filter( 'wp_doing_ajax', '__return_true' );
			remove_action( 'newspack_newsletters_upsert', $count );
			remove_action( 'newspack_newsletters_subscribe_form_processed', $count );
			if ( null === $original_accept ) {
				unset( $_SERVER['HTTP_ACCEPT'] );
			} else {
				$_SERVER['HTTP_ACCEPT'] = $original_accept;
			}
		}
		$response = json_decode( $output, true );
		$this->assertSame( 1, $response['newspack_newsletters_subscribed'] ?? null, $output );
		$this->assertArrayNotHasKey( 'message', $response );
		$this->assertSame( 0, $writes );
	}
}
