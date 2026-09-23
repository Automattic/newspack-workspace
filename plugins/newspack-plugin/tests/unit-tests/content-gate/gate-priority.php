<?php
/**
 * Tests for gate priority: the highest-priority gate matching a post decides access alone.
 *
 * @package Newspack\Tests
 */

namespace Newspack\Tests\Content_Gate;

use Newspack\Content_Gate;
use Newspack\Content_Restriction_Control;
use Newspack\Reader_Activation;

/**
 * Gate composition is first-match by priority (NPPD-2289).
 *
 * Each case puts two gates on the same post: a registration wall, which any
 * signed-in reader passes, and a paid wall, which only readers on the paying
 * email domain pass. The reader who tells the two semantics apart is a
 * registered reader without paid access: under first-match the top gate's
 * answer is final, so ranking the registration wall first admits them.
 */
class Test_Gate_Priority extends \WP_UnitTestCase {

	use \Newspack\Tests\Content_Gate\Traits\Trait_Restriction_Cache_Test;

	/**
	 * Email domain the paid wall's access rule accepts.
	 */
	const PAID_DOMAIN = 'paid.example';

	/**
	 * Post both gates apply to.
	 *
	 * @var int
	 */
	private $post_id;

	/**
	 * Enable the content gating feature flag.
	 */
	public static function set_up_before_class() {
		parent::set_up_before_class();
		if ( ! defined( 'NEWSPACK_CONTENT_GATES' ) ) {
			define( 'NEWSPACK_CONTENT_GATES', true );
		}
	}

	/**
	 * Start each case from a fresh post and empty request caches.
	 */
	public function set_up() {
		parent::set_up();
		$this->reset_restriction_cache();
		$this->post_id = $this->factory->post->create();
	}

	/**
	 * Delete every gate and reset the reader.
	 */
	public function tear_down() {
		foreach ( Content_Gate::get_gates() as $gate ) {
			wp_delete_post( $gate['id'], true );
		}
		wp_set_current_user( 0 );
		$this->reset_restriction_cache();
		parent::tear_down();
	}

	/**
	 * Create a published registration wall on all posts.
	 *
	 * @param int   $priority      Gate priority.
	 * @param array $content_rules Content rules. Defaults to all posts.
	 * @return int Gate ID.
	 */
	private function create_registration_gate( $priority, $content_rules = null ) {
		return $this->create_gate( 'Registration wall', $priority, $content_rules, false );
	}

	/**
	 * Create a published paid wall on all posts: registration plus a paid access rule.
	 *
	 * @param int   $priority      Gate priority.
	 * @param array $content_rules Content rules. Defaults to all posts.
	 * @return int Gate ID.
	 */
	private function create_paid_gate( $priority, $content_rules = null ) {
		return $this->create_gate( 'Paid wall', $priority, $content_rules, true );
	}

	/**
	 * Create a published gate.
	 *
	 * @param string     $title         Gate title.
	 * @param int        $priority      Gate priority.
	 * @param array|null $content_rules Content rules, or null for all posts.
	 * @param bool       $paid          Whether the gate requires the paid access rule.
	 * @return int Gate ID.
	 */
	private function create_gate( $title, $priority, $content_rules, $paid ) {
		$gate_id = Content_Gate::create_gate( [ 'title' => $title ] );
		$gate    = Content_Gate::get_gate( $gate_id );
		Content_Gate::update_gate_settings(
			$gate_id,
			[
				'title'         => $title,
				'status'        => 'publish',
				'priority'      => $priority,
				'content_rules' => $content_rules ?? [
					[
						'slug'  => 'post_types',
						'value' => [ 'post' ],
					],
				],
				'registration'  => [
					'active'               => true,
					'metering'             => [
						'enabled' => false,
						'count'   => 0,
						'period'  => 'month',
					],
					'require_verification' => false,
					'gate_layout_id'       => $gate['registration']['gate_layout_id'],
				],
				'custom_access' => [
					'active'         => $paid,
					'metering'       => [
						'enabled' => false,
						'count'   => 0,
						'period'  => 'month',
					],
					'gate_layout_id' => $gate['custom_access']['gate_layout_id'],
					'access_rules'   => $paid ? [
						[
							[
								'slug'  => 'email_domain',
								'value' => self::PAID_DOMAIN,
							],
						],
					] : [],
				],
			]
		);
		$this->reset_restriction_cache();
		return $gate_id;
	}

	/**
	 * Create a verified reader and make them the current user.
	 *
	 * @param string $domain Email domain.
	 * @return int User ID.
	 */
	private function sign_in_reader( $domain ) {
		$user_id = $this->factory->user->create(
			[
				'role'       => 'subscriber',
				'user_email' => 'reader@' . $domain,
			]
		);
		Reader_Activation::set_reader_verified( $user_id );
		wp_set_current_user( $user_id );
		return $user_id;
	}

	/**
	 * With the registration wall ranked first, a registered reader is admitted
	 * even though the paid wall below it would refuse them. This is the case
	 * that needs gate composition to be first-match: a free section inside a
	 * paid one.
	 */
	public function test_registration_gate_ranked_first_admits_a_registered_reader() {
		$this->create_registration_gate( 0 );
		$this->create_paid_gate( 1 );
		$this->sign_in_reader( 'free.example' );

		$this->assertFalse( Content_Gate::is_post_restricted( $this->post_id ) );
	}

	/**
	 * With the paid wall ranked first, the same reader is refused, and the
	 * paid wall's paid-access layout is the one recorded to render.
	 */
	public function test_paid_gate_ranked_first_restricts_a_registered_reader() {
		$this->create_registration_gate( 1 );
		$paid_gate_id = $this->create_paid_gate( 0 );
		$this->sign_in_reader( 'free.example' );

		$this->assertTrue( Content_Gate::is_post_restricted( $this->post_id ) );
		$this->assertSame( $paid_gate_id, Content_Restriction_Control::get_gate_post_id( $this->post_id ) );
		$this->assertSame(
			Content_Gate::get_gate( $paid_gate_id )['custom_access']['gate_layout_id'],
			Content_Restriction_Control::get_gate_layout_id( $this->post_id )
		);
	}

	/**
	 * Priority orders the gates that match the post. A higher-priority gate
	 * whose content rules miss the post does not take part, so the next
	 * matching gate decides.
	 */
	public function test_first_matching_gate_decides_not_the_first_gate_overall() {
		$unmatched_category = $this->factory->category->create();
		$this->create_registration_gate(
			0,
			[
				[
					'slug'  => 'category',
					'value' => [ $unmatched_category ],
				],
			]
		);
		$paid_gate_id = $this->create_paid_gate( 1 );
		$this->sign_in_reader( 'free.example' );

		$this->assertTrue( Content_Gate::is_post_restricted( $this->post_id ) );
		$this->assertSame( $paid_gate_id, Content_Restriction_Control::get_gate_post_id( $this->post_id ) );
	}

	/**
	 * A gate that refuses a reader but has no layout to show them cannot
	 * render, which has always meant the evaluator passes it over. It must not
	 * decide by admitting the reader: the next matching gate decides instead,
	 * so a broken registration wall on top does not open the paid wall below.
	 */
	public function test_gate_without_a_layout_is_passed_over() {
		$registration_gate_id = $this->create_registration_gate( 0 );
		$paid_gate_id         = $this->create_paid_gate( 1 );

		$registration                   = get_post_meta( $registration_gate_id, 'registration', true );
		$registration['gate_layout_id'] = 0;
		update_post_meta( $registration_gate_id, 'registration', $registration );
		$this->reset_restriction_cache();

		$this->assertTrue( Content_Gate::is_post_restricted( $this->post_id ) );
		$this->assertSame( $paid_gate_id, Content_Restriction_Control::get_gate_post_id( $this->post_id ) );
	}

	/**
	 * Two gates can end up with the same priority, e.g. after a partial
	 * priority save. The older gate then ranks first, so the outcome does not
	 * depend on the query's newest-first date order.
	 */
	public function test_equal_priorities_rank_the_older_gate_first() {
		$registration_gate_id = $this->create_registration_gate( 0 );
		$paid_gate_id         = $this->create_paid_gate( 0 );
		// Distinct dates, so the query's own newest-first order would rank the paid wall first.
		wp_update_post(
			[
				'ID'        => $registration_gate_id,
				'post_date' => '2020-01-01 00:00:00',
			]
		);
		wp_update_post(
			[
				'ID'        => $paid_gate_id,
				'post_date' => '2021-01-01 00:00:00',
			]
		);
		$this->reset_restriction_cache();
		$this->sign_in_reader( 'free.example' );

		$this->assertSame(
			[ $registration_gate_id, $paid_gate_id ],
			array_column( Content_Gate::get_gates(), 'id' ),
			'Equal priorities fall back to creation order.'
		);
		$this->assertFalse( Content_Gate::is_post_restricted( $this->post_id ) );
	}
}
