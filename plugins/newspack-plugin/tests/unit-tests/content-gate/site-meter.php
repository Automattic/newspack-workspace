<?php
/**
 * Tests for the shared site meter.
 *
 * @package Newspack\Tests\Content_Gate
 */

namespace Newspack\Tests\Content_Gate;

use Newspack\Content_Gate;
use Newspack\Metering;
use Newspack\Site_Meter;

/**
 * Tests for the Site_Meter class and the scope resolution in Metering.
 */
class Test_Site_Meter extends \WP_UnitTestCase {

	/**
	 * Gate IDs for cleanup.
	 *
	 * @var int[]
	 */
	protected $gate_ids = [];

	/**
	 * Post IDs for cleanup.
	 *
	 * @var int[]
	 */
	protected $post_ids = [];

	/**
	 * User IDs for cleanup.
	 *
	 * @var int[]
	 */
	protected $user_ids = [];

	/**
	 * Setup before tests.
	 */
	public function set_up() {
		parent::set_up();
		if ( ! defined( 'NEWSPACK_CONTENT_GATES' ) ) {
			define( 'NEWSPACK_CONTENT_GATES', true );
		}
	}

	/**
	 * Teardown after tests.
	 */
	public function tear_down() {
		foreach ( $this->gate_ids as $gate_id ) {
			wp_delete_post( $gate_id, true );
		}
		$this->gate_ids = [];
		foreach ( $this->post_ids as $post_id ) {
			wp_delete_post( $post_id, true );
		}
		$this->post_ids = [];
		foreach ( $this->user_ids as $user_id ) {
			wp_delete_user( $user_id );
		}
		$this->user_ids = [];
		wp_set_current_user( 0 );
		foreach ( array_keys( Site_Meter::get_default_settings() ) as $key ) {
			delete_option( Site_Meter::OPTION_PREFIX . $key );
		}
		delete_option( Site_Meter::ADOPTED_OPTION );
		delete_option( Site_Meter::CLAIM_OPTION );
		parent::tear_down();
	}

	/**
	 * Sign in a reader who can be metered.
	 *
	 * @return int User ID.
	 */
	private function sign_in_reader() {
		$user_id = $this->factory->user->create( [ 'role' => 'subscriber' ] );
		$this->user_ids[] = $user_id;
		wp_set_current_user( $user_id );
		return $user_id;
	}

	/**
	 * Create an article for a reader to spend an allowance on.
	 *
	 * @return int Post ID.
	 */
	private function create_article() {
		$post_id = $this->factory->post->create();
		$this->post_ids[] = $post_id;
		return $post_id;
	}

	/**
	 * Read one article as the signed-in reader, standing in front of a nominated gate.
	 *
	 * `is_logged_in_metering_allowed()` resolves the gate for itself from the current
	 * request, so the filter is how a test says which gate the article sits behind.
	 *
	 * @param int $gate_id Gate the article sits behind.
	 * @param int $post_id Article being read.
	 *
	 * @return bool Whether the reader was let through.
	 */
	private function read_article( $gate_id, $post_id ) {
		$this->forget_metering_decisions();
		$pin_gate = function () use ( $gate_id ) {
			return $gate_id;
		};
		add_filter( 'newspack_content_gate_post_id', $pin_gate );
		$allowed = Metering::is_logged_in_metering_allowed( $post_id );
		remove_filter( 'newspack_content_gate_post_id', $pin_gate );
		return $allowed;
	}

	/**
	 * Forget the per-request memo of who is allowed through.
	 *
	 * `Metering` answers from a static keyed by post ID before it reads any counter,
	 * which is right within one request and wrong for a test standing in for several.
	 * Without this, a second read of the same article is answered from the memo and the
	 * counter logic the assertion is about never runs.
	 *
	 * @return void
	 */
	private function forget_metering_decisions() {
		$memo = new \ReflectionProperty( Metering::class, 'logged_in_metering_cache' );
		$memo->setAccessible( true );
		$memo->setValue( null, [] );
	}

	/**
	 * The views a reader has spent against a counter key.
	 *
	 * @param int    $user_id   Reader.
	 * @param string $meter_key Counter key, as `Metering::get_meter_key()` returns it.
	 *
	 * @return array Post IDs already read.
	 */
	private function get_spent_views( $user_id, $meter_key ) {
		$data = get_user_meta( $user_id, Metering::METERING_META_KEY . '_' . $meter_key, true );
		return is_array( $data ) && isset( $data['content'] ) ? $data['content'] : [];
	}

	/**
	 * Create a gate metering both audience paths.
	 *
	 * @param array       $anonymous  Metering settings for the registration wall.
	 * @param array       $registered Metering settings for the paywall.
	 * @param string|null $scope      Scope to apply to both, or null to leave unset.
	 *
	 * @return int Gate ID.
	 */
	private function create_gate( $anonymous, $registered, $scope = null ) {
		$gate_id = Content_Gate::create_gate( [ 'title' => 'Test Gate' ] );
		$this->gate_ids[] = $gate_id;

		$with_scope = function ( $metering ) use ( $scope ) {
			return null === $scope ? $metering : array_merge( $metering, [ 'scope' => $scope ] );
		};

		Content_Gate::update_gate_settings(
			$gate_id,
			[
				'title'         => 'Test Gate',
				'status'        => 'publish',
				'priority'      => 0,
				'content_rules' => [
					[
						'slug'  => 'post_types',
						'value' => [ 'post' ],
					],
				],
				'registration'  => [
					'active'   => true,
					'metering' => $with_scope( $anonymous ),
				],
				'custom_access' => [
					'active'       => true,
					'metering'     => $with_scope( $registered ),
					'access_rules' => [],
				],
			]
		);

		return $gate_id;
	}

	/**
	 * Record adoption as complete, as the one-time routine does once it has seeded the
	 * shared allowance. Until then gates deliberately read their own settings.
	 *
	 * @return void
	 */
	private function mark_adopted() {
		update_option( Site_Meter::ADOPTED_OPTION, 1 );
	}

	/**
	 * A gate saved before the site meter existed carries no scope, and must adopt the
	 * shared allowance rather than keep whatever count is sitting on it.
	 */
	public function test_a_gate_without_a_scope_reads_the_site_meter() {
		Site_Meter::update_settings(
			[
				'anonymous_count'  => 2,
				'registered_count' => 4,
				'period'           => 'week',
			]
		);
		$this->mark_adopted();
		$gate_id = $this->create_gate(
			[
				'enabled' => true,
				'count'   => 9,
				'period'  => 'month',
			],
			[
				'enabled' => true,
				'count'   => 9,
				'period'  => 'month',
			]
		);

		$anonymous = Metering::get_anonymous_settings( $gate_id );
		$this->assertSame( 2, $anonymous['count'], 'Anonymous readers should get the site meter count' );
		$this->assertSame( 'week', $anonymous['period'], 'The reset period should come from the site meter' );

		$registered = Metering::get_registered_settings( $gate_id );
		$this->assertSame( 4, $registered['count'], 'Registered readers should get the site meter count' );
	}

	/**
	 * Enablement stays with the gate, so a hard wall and a metered gate can share one
	 * pool without the site meter switching metering on everywhere.
	 */
	public function test_the_site_meter_does_not_enable_metering_on_a_gate() {
		Site_Meter::update_settings( [ 'anonymous_count' => 5 ] );
		$this->mark_adopted();
		$gate_id = $this->create_gate(
			[
				'enabled' => false,
				'count'   => 0,
				'period'  => 'month',
			],
			[
				'enabled' => true,
				'count'   => 0,
				'period'  => 'month',
			]
		);

		$this->assertFalse( Metering::get_anonymous_settings( $gate_id )['enabled'], 'A gate that does not meter stays unmetered' );
		$this->assertTrue( Metering::get_registered_settings( $gate_id )['enabled'], 'A gate that meters keeps metering' );
	}

	/**
	 * The opt-out is what preserves a gate's own allowance.
	 */
	public function test_an_opted_out_gate_keeps_its_own_allowance() {
		Site_Meter::update_settings( [ 'anonymous_count' => 2 ] );
		$gate_id = $this->create_gate(
			[
				'enabled' => true,
				'count'   => 9,
				'period'  => 'week',
			],
			[
				'enabled' => true,
				'count'   => 9,
				'period'  => 'week',
			],
			Site_Meter::SCOPE_GATE
		);

		$anonymous = Metering::get_anonymous_settings( $gate_id );
		$this->assertSame( 9, $anonymous['count'], 'An opted-out gate keeps its own count' );
		$this->assertSame( 'week', $anonymous['period'], 'An opted-out gate keeps its own period' );
	}

	/**
	 * The counter key is what actually collapses several gates onto one allowance;
	 * matching settings alone would still hand a reader a fresh count per gate.
	 */
	public function test_sharing_gates_count_against_one_key() {
		$this->mark_adopted();
		$metering = [
			'enabled' => true,
			'count'   => 3,
			'period'  => 'month',
		];
		$first = $this->create_gate( $metering, $metering );
		$second = $this->create_gate( $metering, $metering );

		$this->assertSame(
			Metering::get_meter_key( $first, true ),
			Metering::get_meter_key( $second, true ),
			'Two sharing gates must count against the same key'
		);
		// Against the gate IDs, not get_shared_meter_key(): comparing the key to the call
		// it is built from would pass even on a key that collided with a gate ID.
		$this->assertNotSame( (string) $first, Metering::get_meter_key( $first, true ) );
		$this->assertNotSame( (string) $second, Metering::get_meter_key( $second, true ) );
	}

	/**
	 * The behaviour the shared meter exists to deliver: an allowance spent in one
	 * section is gone in the next, rather than starting again per gate.
	 */
	public function test_a_reader_spends_one_allowance_across_two_gates() {
		Site_Meter::update_settings( [ 'registered_count' => 2 ] );
		$this->mark_adopted();
		$metering = [
			'enabled' => true,
			'count'   => 2,
			'period'  => 'month',
		];
		$news    = $this->create_gate( $metering, $metering );
		$sport   = $this->create_gate( $metering, $metering );
		$user_id = $this->sign_in_reader();

		$this->assertTrue( $this->read_article( $news, $this->create_article() ), 'First article is free' );
		$this->assertTrue( $this->read_article( $news, $this->create_article() ), 'Second article is free' );
		$this->assertFalse(
			$this->read_article( $sport, $this->create_article() ),
			'The allowance was spent under the other gate, so this one gates the reader'
		);

		$this->assertCount(
			2,
			$this->get_spent_views( $user_id, Site_Meter::get_shared_meter_key() ),
			'Both reads are recorded against the one shared counter'
		);
		$this->assertSame(
			[],
			$this->get_spent_views( $user_id, (string) $news ),
			'Nothing is written to the per-gate counter while the gate shares the pool'
		);
	}

	/**
	 * The opt-out has to hold at the counter, not only in the reported settings.
	 */
	public function test_an_opted_out_gate_spends_its_own_allowance() {
		Site_Meter::update_settings( [ 'registered_count' => 1 ] );
		$this->mark_adopted();
		$metering = [
			'enabled' => true,
			'count'   => 1,
			'period'  => 'month',
		];
		$shared    = $this->create_gate( $metering, $metering );
		$separate  = $this->create_gate( $metering, $metering, Site_Meter::SCOPE_GATE );
		$user_id   = $this->sign_in_reader();

		$this->assertTrue( $this->read_article( $shared, $this->create_article() ), 'The shared allowance is spent' );
		$this->assertFalse( $this->read_article( $shared, $this->create_article() ), 'The shared allowance is now gone' );
		$this->assertTrue(
			$this->read_article( $separate, $this->create_article() ),
			'A gate keeping its own allowance is unaffected by the shared pool'
		);

		$this->assertCount( 1, $this->get_spent_views( $user_id, Site_Meter::get_shared_meter_key() ) );
		$this->assertCount( 1, $this->get_spent_views( $user_id, (string) $separate ) );
	}

	/**
	 * Re-reading an article a reader already spent a view on must not cost a second
	 * view, or a shared allowance would drain far faster than a per-gate one did.
	 */
	public function test_rereading_an_article_does_not_spend_a_second_view() {
		Site_Meter::update_settings( [ 'registered_count' => 1 ] );
		$this->mark_adopted();
		$metering = [
			'enabled' => true,
			'count'   => 1,
			'period'  => 'month',
		];
		$news    = $this->create_gate( $metering, $metering );
		$sport   = $this->create_gate( $metering, $metering );
		$article = $this->create_article();
		$user_id = $this->sign_in_reader();

		$this->assertTrue( $this->read_article( $news, $article ) );
		$this->assertTrue(
			$this->read_article( $sport, $article ),
			'The same article behind another gate is already paid for'
		);
		$this->assertCount( 1, $this->get_spent_views( $user_id, Site_Meter::get_shared_meter_key() ) );
	}

	/**
	 * Move a reader's shared record to a nominated reset time.
	 *
	 * The rollover is judged by comparing the reader's stored reset against the one the
	 * current period resolves to. Driving it by editing the site meter period would make
	 * the test depend on the calendar: `week` resolves to next Monday and `month` to the
	 * first of next month, and which of the two falls later changes through the month.
	 *
	 * @param int $user_id    Reader.
	 * @param int $expiration Reset timestamp to store.
	 *
	 * @return void
	 */
	private function set_shared_expiration( $user_id, $expiration ) {
		$meta_key = Metering::METERING_META_KEY . '_' . Site_Meter::get_shared_meter_key();
		$data     = get_user_meta( $user_id, $meta_key, true );
		$data['expiration'] = $expiration;
		update_user_meta( $user_id, $meta_key, $data );
	}

	/**
	 * Set up a reader who has spent their whole shared allowance.
	 *
	 * @return array{gate: int, user: int} The gate they spent it on and the reader.
	 */
	private function spend_the_shared_allowance() {
		Site_Meter::update_settings( [ 'registered_count' => 1 ] );
		$this->mark_adopted();
		$metering = [
			'enabled' => true,
			'count'   => 1,
			'period'  => 'month',
		];
		$gate_id = $this->create_gate( $metering, $metering );
		$user_id = $this->sign_in_reader();

		$this->assertTrue( $this->read_article( $gate_id, $this->create_article() ), 'The allowance is spent' );

		return [
			'gate' => $gate_id,
			'user' => $user_id,
		];
	}

	/**
	 * Once the reader's period has rolled over, the shared allowance starts again.
	 */
	public function test_an_expired_shared_record_starts_a_fresh_allowance() {
		[
			'gate' => $gate_id,
			'user' => $user_id,
		] = $this->spend_the_shared_allowance();

		$this->set_shared_expiration( $user_id, 1 );

		$this->assertTrue(
			$this->read_article( $gate_id, $this->create_article() ),
			'A reset that has passed hands the reader a new allowance'
		);
		$this->assertCount(
			1,
			$this->get_spent_views( $user_id, Site_Meter::get_shared_meter_key() ),
			'The spent views are cleared rather than added to'
		);
	}

	/**
	 * Shortening the window a reader is in must not refund views they already spent.
	 */
	public function test_a_shorter_window_keeps_the_views_already_spent() {
		[
			'gate' => $gate_id,
			'user' => $user_id,
		] = $this->spend_the_shared_allowance();

		$this->set_shared_expiration( $user_id, time() + YEAR_IN_SECONDS );

		$this->assertFalse(
			$this->read_article( $gate_id, $this->create_article() ),
			'Bringing the reset closer must not refund the view already spent'
		);
		$this->assertCount( 1, $this->get_spent_views( $user_id, Site_Meter::get_shared_meter_key() ) );
	}

	/**
	 * An opted-out gate must not draw down the shared pool.
	 */
	public function test_an_opted_out_gate_counts_against_its_own_key() {
		$this->mark_adopted();
		$metering = [
			'enabled' => true,
			'count'   => 3,
			'period'  => 'month',
		];
		$shared = $this->create_gate( $metering, $metering );
		$separate = $this->create_gate( $metering, $metering, Site_Meter::SCOPE_GATE );

		$this->assertSame( (string) $separate, Metering::get_meter_key( $separate, true ) );
		$this->assertNotSame( Metering::get_meter_key( $shared, true ), Metering::get_meter_key( $separate, true ) );
	}

	/**
	 * A site with one metered configuration must come out of the upgrade behaving
	 * identically, which means the site meter adopts that configuration.
	 */
	public function test_adoption_seeds_the_site_meter_from_a_single_configuration() {
		$metering = [
			'enabled' => true,
			'count'   => 4,
			'period'  => 'week',
		];
		$this->create_gate( $metering, $metering, Site_Meter::SCOPE_GATE );

		Site_Meter::maybe_adopt_gate_settings();

		$settings = Site_Meter::get_settings();
		$this->assertSame( 4, $settings['anonymous_count'] );
		$this->assertSame( 4, $settings['registered_count'] );
		$this->assertSame( 'week', $settings['period'] );
	}

	/**
	 * Gates that disagree cannot all be folded into one allowance without changing
	 * someone's behavior, so they are pinned to their own meters instead.
	 */
	public function test_adoption_pins_conflicting_gates_to_their_own_meters() {
		$this->create_gate(
			[
				'enabled' => true,
				'count'   => 2,
				'period'  => 'month',
			],
			[
				'enabled' => true,
				'count'   => 2,
				'period'  => 'month',
			]
		);
		$second = $this->create_gate(
			[
				'enabled' => true,
				'count'   => 7,
				'period'  => 'month',
			],
			[
				'enabled' => true,
				'count'   => 7,
				'period'  => 'month',
			]
		);

		Site_Meter::maybe_adopt_gate_settings();

		$this->assertSame( Site_Meter::get_default_settings(), Site_Meter::get_settings(), 'Conflicting gates must not seed the site meter' );
		$this->assertSame( 7, Metering::get_anonymous_settings( $second )['count'], 'Each gate keeps the allowance it had' );
		$this->assertSame( (string) $second, Metering::get_meter_key( $second, true ), 'Each gate keeps its own counter' );
	}

	/**
	 * A disabled meter imposes no allowance, so it cannot be the thing that blocks
	 * adoption for the gates that do meter.
	 */
	public function test_adoption_ignores_gates_that_do_not_meter() {
		$this->create_gate(
			[
				'enabled' => false,
				'count'   => 99,
				'period'  => 'week',
			],
			[
				'enabled' => false,
				'count'   => 99,
				'period'  => 'week',
			]
		);
		$this->create_gate(
			[
				'enabled' => true,
				'count'   => 5,
				'period'  => 'month',
			],
			[
				'enabled' => true,
				'count'   => 5,
				'period'  => 'month',
			]
		);

		Site_Meter::maybe_adopt_gate_settings();

		$this->assertSame( 5, Site_Meter::get_setting( 'anonymous_count' ) );
	}

	/**
	 * Adoption rewrites gate settings, so it must never run twice.
	 */
	public function test_adoption_runs_once() {
		$metering = [
			'enabled' => true,
			'count'   => 4,
			'period'  => 'month',
		];
		$this->create_gate( $metering, $metering, Site_Meter::SCOPE_GATE );

		Site_Meter::maybe_adopt_gate_settings();
		Site_Meter::update_settings( [ 'anonymous_count' => 1 ] );
		Site_Meter::maybe_adopt_gate_settings();

		$this->assertSame( 1, Site_Meter::get_setting( 'anonymous_count' ), 'A second run must not overwrite an edited site meter' );
	}

	/**
	 * The shared counters are split by reader, so the allowance has to be too. Picking
	 * it by audience path instead would let a signed-out reader spend the signed-in
	 * allowance on one gate and be locked out by the smaller one on the next.
	 */
	public function test_the_shared_allowance_follows_the_reader_not_the_gate_path() {
		Site_Meter::update_settings(
			[
				'anonymous_count'  => 1,
				'registered_count' => 5,
			]
		);
		$this->mark_adopted();
		$paywall_only = [
			'active'   => true,
			'metering' => [
				'enabled' => true,
				'count'   => 9,
				'period'  => 'month',
			],
		];

		$this->assertSame( 1, Metering::resolve_path_settings( $paywall_only, false )['count'], 'A signed-out reader draws on the signed-out allowance' );
		$this->assertSame( 5, Metering::resolve_path_settings( $paywall_only, true )['count'], 'A signed-in reader draws on the signed-in allowance' );
	}

	/**
	 * Until adoption has seeded the shared allowance, its defaults are nobody's
	 * configuration, so serving them would change a publisher's allowance the moment
	 * the plugin updates.
	 */
	public function test_a_gate_keeps_its_own_allowance_until_adoption_runs() {
		Site_Meter::update_settings( [ 'anonymous_count' => 2 ] );
		$gate_id = $this->create_gate(
			[
				'enabled' => true,
				'count'   => 9,
				'period'  => 'week',
			],
			[
				'enabled' => true,
				'count'   => 9,
				'period'  => 'week',
			]
		);

		$this->assertSame( 9, Metering::get_anonymous_settings( $gate_id )['count'], 'The gate keeps its own count before adoption' );
		$this->assertSame( (string) $gate_id, Metering::get_meter_key( $gate_id, true ), 'The gate keeps its own counter before adoption' );
	}

	/**
	 * The site meter holds a count per audience, so a site metering fewer views before
	 * its registration wall than before its paywall is not a conflict.
	 */
	public function test_adoption_keeps_differing_signed_out_and_signed_in_allowances() {
		$gate_id = $this->create_gate(
			[
				'enabled' => true,
				'count'   => 3,
				'period'  => 'month',
			],
			[
				'enabled' => true,
				'count'   => 5,
				'period'  => 'month',
			]
		);

		Site_Meter::maybe_adopt_gate_settings();

		$settings = Site_Meter::get_settings();
		$this->assertSame( 3, $settings['anonymous_count'], 'The signed-out allowance is adopted on its own' );
		$this->assertSame( 5, $settings['registered_count'], 'The signed-in allowance is adopted on its own' );
		$this->assertSame(
			Site_Meter::SCOPE_SITE,
			Site_Meter::sanitize_scope( Content_Gate::get_registration_settings( $gate_id )['metering']['scope'] ?? null ),
			'A gate the site meter can express must not be pinned'
		);
	}

	/**
	 * Pinning a path whose meter is off would hand the publisher a per-gate counter
	 * they never asked for the day they switch that meter on.
	 */
	public function test_adoption_leaves_an_unmetered_path_on_the_shared_allowance() {
		$first = $this->create_gate(
			[
				'enabled' => false,
				'count'   => 2,
				'period'  => 'month',
			],
			[
				'enabled' => true,
				'count'   => 2,
				'period'  => 'month',
			]
		);
		$this->create_gate(
			[
				'enabled' => true,
				'count'   => 7,
				'period'  => 'month',
			],
			[
				'enabled' => true,
				'count'   => 7,
				'period'  => 'month',
			]
		);

		Site_Meter::maybe_adopt_gate_settings();

		$this->assertSame(
			Site_Meter::SCOPE_SITE,
			Site_Meter::sanitize_scope( Content_Gate::get_registration_settings( $first )['metering']['scope'] ?? null ),
			'A path that does not meter keeps the shared allowance'
		);
		$this->assertSame(
			Site_Meter::SCOPE_GATE,
			Site_Meter::sanitize_scope( Content_Gate::get_custom_access_settings( $first )['metering']['scope'] ?? null ),
			'A path that meters is pinned to its own allowance'
		);
	}

	/**
	 * A gate with no registration wall still meters signed-out readers, through its
	 * paywall and against the signed-out allowance. Judging the two audience paths
	 * against their own counts reports such a gate as unmetered.
	 */
	public function test_a_paywall_only_gate_counts_as_metered_for_signed_out_readers() {
		Site_Meter::update_settings(
			[
				'anonymous_count'  => 5,
				'registered_count' => 0,
			]
		);
		$this->mark_adopted();
		$gate_id = Content_Gate::create_gate( [ 'title' => 'Paywall Only' ] );
		$this->gate_ids[] = $gate_id;
		Content_Gate::update_gate_settings(
			$gate_id,
			[
				'title'         => 'Paywall Only',
				'status'        => 'publish',
				'priority'      => 0,
				'content_rules' => [
					[
						'slug'  => 'post_types',
						'value' => [ 'post' ],
					],
				],
				'registration'  => [
					'active'   => false,
					'metering' => [
						'enabled' => false,
						'count'   => 0,
						'period'  => 'month',
					],
				],
				'custom_access' => [
					'active'       => true,
					'metering'     => [
						'enabled' => true,
						'count'   => 9,
						'period'  => 'month',
					],
					'access_rules' => [],
				],
			]
		);

		$this->assertTrue( Metering::is_gate_metered( $gate_id ), 'A paywall-only gate meters signed-out readers' );
	}

	/**
	 * A binned gate enforces nothing. Letting it veto adoption would ship the shared
	 * meter inert over a configuration the publisher already discarded.
	 */
	public function test_a_trashed_gate_does_not_block_adoption() {
		$live = $this->create_gate(
			[
				'enabled' => true,
				'count'   => 5,
				'period'  => 'month',
			],
			[
				'enabled' => true,
				'count'   => 5,
				'period'  => 'month',
			]
		);
		$binned = $this->create_gate(
			[
				'enabled' => true,
				'count'   => 10,
				'period'  => 'month',
			],
			[
				'enabled' => true,
				'count'   => 10,
				'period'  => 'month',
			]
		);
		wp_trash_post( $binned );

		Site_Meter::maybe_adopt_gate_settings();

		$this->assertSame( 5, Site_Meter::get_setting( 'anonymous_count' ), 'The live gate seeds the site meter' );
		$this->assertSame(
			Site_Meter::SCOPE_SITE,
			Site_Meter::sanitize_scope( Content_Gate::get_registration_settings( $live )['metering']['scope'] ?? null ),
			'The live gate is not pinned'
		);
		$this->assertSame(
			Site_Meter::SCOPE_GATE,
			Site_Meter::sanitize_scope( Content_Gate::get_registration_settings( $binned )['metering']['scope'] ?? null ),
			'Restoring the binned gate must not change what it grants'
		);
	}

	/**
	 * A gate only gates a reader once published, so an unpublished one has never
	 * enforced the allowance it carries and cannot speak for the site.
	 */
	public function test_an_unpublished_gate_does_not_block_adoption() {
		$this->create_gate(
			[
				'enabled' => true,
				'count'   => 5,
				'period'  => 'month',
			],
			[
				'enabled' => true,
				'count'   => 5,
				'period'  => 'month',
			]
		);
		$draft = $this->create_gate(
			[
				'enabled' => true,
				'count'   => 10,
				'period'  => 'month',
			],
			[
				'enabled' => true,
				'count'   => 10,
				'period'  => 'month',
			]
		);
		wp_update_post(
			[
				'ID'          => $draft,
				'post_status' => 'draft',
			]
		);

		Site_Meter::maybe_adopt_gate_settings();

		$this->assertSame( 5, Site_Meter::get_setting( 'anonymous_count' ), 'The published gate seeds the site meter' );
		$this->assertSame(
			Site_Meter::SCOPE_GATE,
			Site_Meter::sanitize_scope( Content_Gate::get_registration_settings( $draft )['metering']['scope'] ?? null ),
			'Publishing the draft must not change what it grants'
		);
	}

	/**
	 * The front end reads these on every gated request, so adoption must leave real
	 * rows behind even when it settles on the defaults.
	 */
	public function test_adoption_writes_the_settings_it_resolved() {
		Site_Meter::maybe_adopt_gate_settings();

		foreach ( array_keys( Site_Meter::get_default_settings() ) as $key ) {
			$this->assertNotFalse( get_option( Site_Meter::OPTION_PREFIX . $key, false ), "The {$key} option should exist after adoption" );
		}
	}

	/**
	 * Asking whether a gate meters signed-in readers is a hypothetical. Answering it
	 * from the visitor's own session reads a verification state that is not theirs,
	 * and on a signed-out request there is no user to read it from at all.
	 */
	public function test_a_verification_gate_is_judged_without_the_visitors_session() {
		$gate_id = Content_Gate::create_gate( [ 'title' => 'Verified Wall' ] );
		$this->gate_ids[] = $gate_id;
		Content_Gate::update_gate_settings(
			$gate_id,
			[
				'title'         => 'Verified Wall',
				'status'        => 'publish',
				'priority'      => 0,
				'content_rules' => [
					[
						'slug'  => 'post_types',
						'value' => [ 'post' ],
					],
				],
				'registration'  => [
					'active'               => true,
					'require_verification' => true,
					'metering'             => [
						'enabled' => false,
						'count'   => 0,
						'period'  => 'month',
					],
				],
				'custom_access' => [
					'active'       => true,
					'metering'     => [
						'enabled' => true,
						'count'   => 3,
						'period'  => 'month',
					],
					'access_rules' => [],
				],
			]
		);
		wp_set_current_user( 0 );

		$this->assertTrue( Metering::is_gate_metered( $gate_id ), 'The paywall meters, and asking must not depend on who is asking' );

		// The reader the wall holds. Their session matches the signed-in case the loop asks
		// about, so comparing the two alone would report this gate unmetered for them alone.
		// Registered without authenticating, which would write a cookie outliving the test.
		$reader = \Newspack\Reader_Activation::register_reader( 'unverified@site-meter-test.com', 'Test Reader', false );
		$this->assertIsInt( $reader, 'The reader is registered, so the assertions below are not vacuous' );
		$this->user_ids[] = $reader;
		wp_set_current_user( $reader );

		$this->assertFalse(
			\Newspack\Reader_Activation::is_reader_verified( get_userdata( $reader ) ),
			'The reader is unverified, which is the state that used to change the answer'
		);
		$this->assertTrue(
			Metering::is_gate_metered( $gate_id ),
			'An unverified reader asking gets the same answer as everyone else'
		);
	}

	/**
	 * A signed-in reader who has not verified is held at the registration wall while
	 * still drawing the signed-in allowance, so that wall has to agree with the paywall
	 * before the two can share one count.
	 */
	public function test_a_verification_wall_that_disagrees_with_its_paywall_is_a_conflict() {
		$gate_id = Content_Gate::create_gate( [ 'title' => 'Verified Wall' ] );
		$this->gate_ids[] = $gate_id;
		Content_Gate::update_gate_settings(
			$gate_id,
			[
				'title'         => 'Verified Wall',
				'status'        => 'publish',
				'priority'      => 0,
				'content_rules' => [
					[
						'slug'  => 'post_types',
						'value' => [ 'post' ],
					],
				],
				'registration'  => [
					'active'               => true,
					'require_verification' => true,
					'metering'             => [
						'enabled' => true,
						'count'   => 3,
						'period'  => 'month',
					],
				],
				'custom_access' => [
					'active'       => true,
					'metering'     => [
						'enabled' => true,
						'count'   => 5,
						'period'  => 'month',
					],
					'access_rules' => [],
				],
			]
		);

		Site_Meter::maybe_adopt_gate_settings();

		$this->assertSame(
			Site_Meter::SCOPE_GATE,
			Site_Meter::sanitize_scope( Content_Gate::get_registration_settings( $gate_id )['metering']['scope'] ?? null ),
			'An unverified signed-in reader must not have their allowance changed'
		);
	}

	/**
	 * The site meter holds only weekly and monthly, so a gate resetting daily cannot be
	 * folded into it without quietly changing when its readers get their views back.
	 */
	public function test_a_period_the_site_meter_cannot_hold_is_a_conflict() {
		$gate_id = $this->create_gate(
			[
				'enabled' => true,
				'count'   => 2,
				'period'  => 'day',
			],
			[
				'enabled' => true,
				'count'   => 2,
				'period'  => 'day',
			]
		);

		Site_Meter::maybe_adopt_gate_settings();

		$this->assertSame( 'month', Site_Meter::get_setting( 'period' ), 'The site meter keeps its own default' );
		$this->assertSame(
			Site_Meter::SCOPE_GATE,
			Site_Meter::sanitize_scope( Content_Gate::get_registration_settings( $gate_id )['metering']['scope'] ?? null ),
			'A daily gate keeps its own reset period'
		);
	}

	/**
	 * A negative count would read back through absint() as a positive allowance.
	 */
	public function test_counts_are_floored_at_zero() {
		Site_Meter::update_settings( [ 'anonymous_count' => -3 ] );

		$this->assertSame( 0, Site_Meter::get_setting( 'anonymous_count' ) );
	}

	/**
	 * An unknown period would otherwise reach the expiration maths.
	 *
	 * Written straight to the option rather than through `update_settings()`, which
	 * sanitises on the way in: a bad value never reaches the database that way, so
	 * the read-side fallback this covers would never be exercised.
	 */
	public function test_an_unknown_period_falls_back_to_the_default() {
		update_option( Site_Meter::OPTION_PREFIX . 'period', 'fortnight' );

		$this->assertSame( 'month', Site_Meter::get_setting( 'period' ) );
	}

	/**
	 * A premium newsletter gate is hidden from every publisher-facing surface, so it
	 * must not be able to decide the allowance for the gates that are visible.
	 */
	public function test_a_newsletter_gate_does_not_vote_in_adoption() {
		$this->create_gate(
			[
				'enabled' => true,
				'count'   => 5,
				'period'  => 'month',
			],
			[
				'enabled' => true,
				'count'   => 5,
				'period'  => 'month',
			]
		);
		$newsletter = $this->create_gate(
			[
				'enabled' => true,
				'count'   => 10,
				'period'  => 'month',
			],
			[
				'enabled' => true,
				'count'   => 10,
				'period'  => 'month',
			]
		);
		update_post_meta( $newsletter, 'is_newsletter', true );

		Site_Meter::maybe_adopt_gate_settings();

		$this->assertSame(
			5,
			Site_Meter::get_setting( 'anonymous_count' ),
			'The visible gate seeds the site meter, unopposed by one no publisher can see'
		);
		$this->assertSame(
			Site_Meter::SCOPE_GATE,
			Site_Meter::sanitize_scope( Content_Gate::get_registration_settings( $newsletter )['metering']['scope'] ?? null ),
			'A gate that cannot be reconciled from the UI keeps its own allowance'
		);
	}

	/**
	 * A site left marked adopted on settings that were never written would serve the
	 * defaults to every gate, so a failed write has to leave the run open to retry.
	 */
	public function test_a_failed_write_does_not_mark_the_site_adopted() {
		update_option( Site_Meter::OPTION_PREFIX . 'anonymous_count', 3 );
		$this->create_gate(
			[
				'enabled' => true,
				'count'   => 5,
				'period'  => 'month',
			],
			[
				'enabled' => true,
				'count'   => 5,
				'period'  => 'month',
			]
		);
		// Holding the stored value against the write is how update_option() reports failure.
		$refuse_write = function () {
			return 3;
		};
		add_filter( 'pre_update_option_' . Site_Meter::OPTION_PREFIX . 'anonymous_count', $refuse_write );

		Site_Meter::maybe_adopt_gate_settings();

		remove_filter( 'pre_update_option_' . Site_Meter::OPTION_PREFIX . 'anonymous_count', $refuse_write );

		$this->assertFalse( Site_Meter::has_adopted(), 'A run that could not write must not record itself as done' );
		$this->assertFalse(
			get_option( Site_Meter::CLAIM_OPTION, false ),
			'The claim is released so the next request can retry'
		);
	}

	/**
	 * A key that does not exist is reported the same way by both entry points, so a
	 * caller that handles one handles the other.
	 */
	public function test_an_unknown_setting_key_is_an_error_from_both_entry_points() {
		$this->assertWPError( Site_Meter::get_setting( 'nope' ) );
		$this->assertWPError( Site_Meter::sanitize_setting( 'nope', 1 ) );
	}
}
