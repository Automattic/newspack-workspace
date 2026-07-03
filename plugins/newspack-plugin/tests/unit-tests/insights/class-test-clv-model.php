<?php
/**
 * Test Clv_Model (NEWS-2603).
 *
 * Covers the pure Reader Revenue Development 3-year CLV formula —
 * `Clv_Model::three_year()` — a storage-free function shared by the Subscribers,
 * Donors, and Audience CLV cards. Tested directly (no DB, no mock).
 *
 * @package Newspack\Tests\Insights
 */

namespace Newspack\Tests\Insights;

use Newspack\Insights\Clv_Model;
use WP_UnitTestCase;

/**
 * Clv_Model test class.
 *
 * @group insights
 */
class Test_Clv_Model extends WP_UnitTestCase {

	/**
	 * Canonical case: three years of ARPU scaled by cumulative survival
	 * (r, r^2, r^3), each net of the 5% maintenance rate.
	 * arpu 100, r 0.8: 0.95 * 100 * (0.8 + 0.64 + 0.512) = 185.44.
	 */
	public function test_canonical_value() {
		$this->assertSame( 185.44, Clv_Model::three_year( 100.0, 0.8 ) );
	}

	/**
	 * Perfect retention keeps all three years at full ARPU:
	 * 0.95 * arpu * 3 = 2.85 * arpu.
	 */
	public function test_full_retention() {
		$this->assertSame( 285.0, Clv_Model::three_year( 100.0, 1.0 ) );
	}

	/**
	 * Zero ARPU or zero retention yields zero value.
	 */
	public function test_zero_inputs() {
		$this->assertSame( 0.0, Clv_Model::three_year( 0.0, 0.9 ) );
		$this->assertSame( 0.0, Clv_Model::three_year( 100.0, 0.0 ) );
	}

	/**
	 * Retention is clamped to [0, 1]: out-of-range inputs behave as the bound.
	 */
	public function test_retention_is_clamped() {
		$this->assertSame( Clv_Model::three_year( 100.0, 1.0 ), Clv_Model::three_year( 100.0, 1.5 ) );
		$this->assertSame( 0.0, Clv_Model::three_year( 100.0, -0.5 ) );
	}

	/**
	 * A Year-1 intro discount is subtracted from the modeled value.
	 */
	public function test_intro_discount_is_subtracted() {
		$this->assertSame( 275.0, Clv_Model::three_year( 100.0, 1.0, 10.0 ) );
	}

	/**
	 * A discount larger than the modeled value floors at zero, never negative.
	 */
	public function test_never_negative() {
		$this->assertSame( 0.0, Clv_Model::three_year( 1.0, 0.1, 1000.0 ) );
	}
}
