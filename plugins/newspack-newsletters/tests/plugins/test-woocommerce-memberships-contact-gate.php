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

/**
 * The membership gate on newsletter lists checks the contact being written, and
 * the subscribe form does not answer with an error when it leaves no list in.
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
		WC_Memberships_Gate_Fixture::$restricted = [ $this->restricted_list => [ $this->member_id ] ];
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
		WC_Memberships_Gate_Fixture::$restricted = [];
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

	public function test_the_gate_checks_the_contact_not_the_session() {
		wp_set_current_user( $this->member_id );
		$this->assertSame( [ $this->public_id( $this->open_list ) ], $this->gate( [ 'email' => 'reader@example.test' ] ) );
	}

	public function test_an_entitled_contact_passes_without_a_session() {
		$this->assertSame(
			[ $this->public_id( $this->open_list ), $this->public_id( $this->restricted_list ) ],
			$this->gate( [ 'email' => 'member@example.test' ] )
		);
	}

	public function test_an_address_with_no_account_is_gated_without_an_error() {
		$this->assertSame( [ $this->public_id( $this->open_list ) ], $this->gate( [ 'email' => 'nobody@example.test' ] ) );
	}

	public function test_a_contact_without_a_valid_email_is_gated_whatever_the_session() {
		wp_set_current_user( $this->member_id );
		$this->assertSame( [ $this->public_id( $this->open_list ) ], $this->gate( [ 'email' => '' ] ) );
	}

	/**
	 * The lists offered on screen take no contact, so they keep following the
	 * session; this guards them from the contact handling above.
	 */
	public function test_lists_offered_on_screen_still_follow_the_session() {
		wp_set_current_user( $this->member_id );
		$this->assertSame(
			[ $this->public_id( $this->restricted_list ) ],
			Woocommerce_Memberships::filter_lists( [ $this->public_id( $this->restricted_list ) ] )
		);
	}

	/**
	 * No provider is configured in this suite, so a request that reached the
	 * write path would come back as an error.
	 */
	public function test_a_request_with_no_open_list_answers_as_a_subscribe() {
		wp_set_current_user( $this->member_id );
		$_REQUEST[ \Newspack_Newsletters\Blocks\Subscribe\FORM_ACTION ] = '1';
		$_REQUEST['npe']   = 'reader@example.test';
		$_REQUEST['lists'] = [ $this->public_id( $this->restricted_list ) ];
		$processed = [];
		$record    = function ( $email, $result ) use ( &$processed ) {
			$processed[] = $result;
		};
		add_action( 'newspack_newsletters_subscribe_form_processed', $record, 10, 2 );
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
			remove_action( 'newspack_newsletters_subscribe_form_processed', $record, 10 );
			if ( null === $original_accept ) {
				unset( $_SERVER['HTTP_ACCEPT'] );
			} else {
				$_SERVER['HTTP_ACCEPT'] = $original_accept;
			}
		}
		$response = json_decode( $output, true );
		$this->assertSame( 1, $response['newspack_newsletters_subscribed'] ?? null, $output );
		$this->assertArrayNotHasKey( 'message', $response );
		$this->assertCount( 1, $processed, 'prompt analytics still hear about the submission' );
		$this->assertSame( 'newspack_newsletters_no_open_lists', $processed[0]->get_error_code() );
	}
}
