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
	 * Teardown after tests.
	 */
	public function tear_down() {
		foreach ( $this->gate_ids as $gate_id ) {
			wp_delete_post( $gate_id, true );
		}
		$this->gate_ids = [];
		foreach ( array_keys( Site_Meter::get_default_settings() ) as $key ) {
			delete_option( Site_Meter::OPTION_PREFIX . $key );
		}
		delete_option( Site_Meter::MIGRATED_OPTION );
		parent::tear_down();
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
		$this->assertSame( Site_Meter::METER_KEY, Metering::get_meter_key( $first, true ) );
	}

	/**
	 * An opted-out gate must not draw down the shared pool.
	 */
	public function test_an_opted_out_gate_counts_against_its_own_key() {
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

		$this->assertSame( 5, Site_Meter::get_settings( 'anonymous_count' ) );
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

		$this->assertSame( 1, Site_Meter::get_settings( 'anonymous_count' ), 'A second run must not overwrite an edited site meter' );
	}

	/**
	 * A negative count would read back through absint() as a positive allowance.
	 */
	public function test_counts_are_floored_at_zero() {
		Site_Meter::update_settings( [ 'anonymous_count' => -3 ] );

		$this->assertSame( 0, Site_Meter::get_settings( 'anonymous_count' ) );
	}

	/**
	 * An unknown period would otherwise reach the expiration maths.
	 */
	public function test_an_unknown_period_falls_back_to_the_default() {
		Site_Meter::update_settings( [ 'period' => 'fortnight' ] );

		$this->assertSame( 'month', Site_Meter::get_settings( 'period' ) );
	}
}
