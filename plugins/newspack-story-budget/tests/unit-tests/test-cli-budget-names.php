<?php
/**
 * Tests for budget names given to the budget WP-CLI commands.
 *
 * @package Newspack_Story_Budget
 */

namespace Newspack_Story_Budget;

use Newspack_Story_Budget\CLI\Budget as CLI_Budget;

require_once dirname( __DIR__ ) . '/stubs/class-wp-cli.php';

/**
 * A budget name or description given to `wp newspack story budget` is stored character for character.
 */
class Test_CLI_Budget_Names extends \WP_UnitTestCase {

	/**
	 * Read a budget back from the database.
	 *
	 * @param int $term_id Budget term ID.
	 *
	 * @return \WP_Term
	 */
	private function stored_budget( $term_id ) {
		clean_term_cache( $term_id, Budgets::TAXONOMY );
		return get_term( $term_id, Budgets::TAXONOMY );
	}

	/**
	 * `budget create` keeps backslashes in the name and description.
	 */
	public function test_create_keeps_backslashes() {
		CLI_Budget::cli_budget_create(
			[],
			[
				'name'        => 'Archive C:\\2026',
				'description' => 'Files in D:\\drafts',
			]
		);

		$budget_ids = get_terms(
			[
				'taxonomy'   => Budgets::TAXONOMY,
				'hide_empty' => false,
				'fields'     => 'ids',
			]
		);
		$this->assertCount( 1, $budget_ids );

		$budget = $this->stored_budget( $budget_ids[0] );
		$this->assertSame( 'Archive C:\\2026', $budget->name );
		$this->assertSame( 'Files in D:\\drafts', $budget->description );
	}

	/**
	 * `budget update` keeps backslashes in the name and description.
	 */
	public function test_update_keeps_backslashes() {
		$budget_id = self::factory()->term->create(
			[
				'taxonomy' => Budgets::TAXONOMY,
				'name'     => 'Original name',
			]
		);

		CLI_Budget::cli_budget_update(
			[ (string) $budget_id ],
			[
				'name'        => 'Notes D:\\q4',
				'description' => 'Files in E:\\final',
			]
		);

		$budget = $this->stored_budget( $budget_id );
		$this->assertSame( 'Notes D:\\q4', $budget->name );
		$this->assertSame( 'Files in E:\\final', $budget->description );
	}
}
