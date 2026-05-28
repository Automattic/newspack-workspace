<?php
/**
 * Newspack Story Budget - class for handling story budgets.
 *
 * @package Newspack_Story_Budget
 */

namespace Newspack_Story_Budget\CLI;

use Newspack_Story_Budget\Budgets;
use Newspack_Story_Budget\Budget as Single_Budget;
use Newspack_Story_Budget\Story;

/**
 * CLI commands to manage story budgets.
 */
class Budget {
	/**
	 * List all story budgets.
	 *
	 * ## OPTIONS
	 *
	 * [--include-archived]
	 * : (Optional) Include archived story budgets.
	 *
	 * [--format=<table|csv|json|yaml|ids|count>]
	 * : (Optional) Format to output results in.
	 *
	 * @param array $args Positional args.
	 * @param array $assoc_args Associative args.
	 */
	public static function cli_budget_list( $args, $assoc_args ) {
		$include_archived = ! empty( $assoc_args['include-archived'] );
		$all_budgets      = Budgets::get_budgets( $include_archived );
		$format           = $assoc_args['format'] ?? 'table';
		$results          = [];
		foreach ( $all_budgets as $budget_slug => $budget ) {
			$is_archived = $budget->archived;
			$count       = $budget->term->count;
			$budget      = $budget->to_array();
			$result      = [
				'id'          => $budget['id'],
				'name'        => $budget['name'],
				'slug'        => $budget['slug'],
				'description' => $budget['description'],
				'story count' => $count,
			];

			if ( $include_archived ) {
				$result['archived'] = $is_archived ? 'yes' : 'no';
			}
			$results[] = $result;
		}

		$columns = [
			'id',
			'name',
			'slug',
			'description',
			'story count',
		];
		if ( $include_archived ) {
			$columns[] = 'archived';
		}

		\WP_CLI\Utils\format_items(
			$format,
			'ids' === $format ? array_column( $results, 'id' ) : $results,
			$columns
		);
		\WP_CLI::log( '' );
		\WP_CLI::success(
			sprintf(
				'Found %d story budgets.',
				count( $results )
			)
		);
	}

	/**
	 * Get a story budget's info by ID or slug.
	 *
	 * ## OPTIONS
	 *
	 * [<id-or-slug>]
	 * : ID or slug of the story budget to get.
	 *
	 * [--format=<table|csv|json|yaml|ids|count>]
	 * : (Optional) Format to output results in.
	 *
	 * @param array $args Positional args.
	 * @param array $assoc_args Associative args.
	 */
	public static function cli_budget_get( $args, $assoc_args ) {
		$budget_id = $args[0];
		$format    = $assoc_args['format'] ?? 'table';
		if ( empty( $budget_id ) ) {
			\WP_CLI::error( 'Please provide ID or slug of the story budget to update.' );
		}

		$budget_term = \get_term_by(
			is_numeric( $budget_id ) ? 'id' : 'slug',
			$budget_id,
			Budgets::TAXONOMY
		);
		if ( ! $budget_term ) {
			\WP_CLI::error( sprintf( 'Story budget with ID or slug "%s" not found.', $budget_id ) );
		}
		$budget = new Single_Budget( $budget_term );
		if ( ! $budget->is_valid() ) {
			\WP_CLI::error(
				implode( ' ', $budget->get_budget_errors()->get_error_messages() )
			);
		}

		$is_archived = $budget->archived;
		$count       = $budget->term->count;
		$budget      = $budget->to_array();
		$result      = [
			'id'          => $budget['id'],
			'name'        => $budget['name'],
			'slug'        => $budget['slug'],
			'description' => $budget['description'],
			'story count' => $count,
			'archived'    => $is_archived ? 'yes' : 'no',
		];
		$formatted_result = [];
		foreach ( $result as $field => $value ) {
			$formatted_result[] = [
				'field' => $field,
				'value' => $value,
			];
		}
		\WP_CLI\Utils\format_items(
			$format,
			'ids' === $format ? [ $budget['id'] ] : $formatted_result,
			[
				'field',
				'value',
			]
		);
	}

	/**
	 * Create a new story budget.
	 *
	 * ## OPTIONS
	 *
	 * [--name=<name>]
	 * : Human-readable name for the story budget.
	 *
	 * [--slug=<slug>]
	 * : (Optional) Unique slug for the story budget. If not passed, will be generated from name.
	 *
	 * [--description=<description>]
	 * : (Optional) Description for the story budget.
	 *
	 * [--archived]
	 * : (Optional) If set, the story budget will be archived immediately.
	 *
	 * @param array $args Positional args.
	 * @param array $assoc_args Associative args.
	 */
	public static function cli_budget_create( $args, $assoc_args ) {
		$name        = $assoc_args['name'] ?? '';
		$slug        = $assoc_args['slug'] ?? '';
		$description = $assoc_args['description'] ?? '';
		$archived    = ! empty( $assoc_args['archived'] );

		if ( empty( $name ) ) {
			\WP_CLI::error( 'Please provide a name for the story budget.' );
		}

		$budget_term = \wp_insert_term(
			$name,
			Budgets::TAXONOMY,
			[
				'description' => $description,
				'slug'        => $slug,
			]
		);
		if ( is_wp_error( $budget_term ) ) {
			\WP_CLI::error( $budget_term->get_error_message() );
		}
		$budget = new Single_Budget( \get_term( $budget_term['term_id'], Budgets::TAXONOMY ) );
		if ( ! $budget->is_valid() ) {
			\WP_CLI::error(
				implode( ' ', $budget->get_budget_errors()->get_error_messages() )
			);
		}

		if ( $archived ) {
			$budget->archive();
		}

		$budget = $budget->to_array();

		\WP_CLI::log( '' );
		\WP_CLI::success(
			sprintf(
				'Created story budget "%s" with ID %d.',
				$budget['name'],
				$budget['id']
			)
		);
	}

	/**
	 * Delete a story budget.
	 *
	 * ## OPTIONS
	 *
	 * [<ids-or-slugs>...]
	 * : IDs or slugs of the story budgets to delete.
	 *
	 * [--dry-run]
	 * : If passed, output results but do not modify database.
	 *
	 * @param array $args Positional args.
	 * @param array $assoc_args Associative args.
	 */
	public static function cli_budget_delete( $args, $assoc_args ) {
		$is_dry_run = ! empty( $assoc_args['dry-run'] );
		$budget_ids = $args;
		if ( empty( $budget_ids ) ) {
			\WP_CLI::error( 'Please provide IDs or slugs of the story budgets to delete.' );
		}
		$results = 0;
		foreach ( $budget_ids as $budget_id ) {
			$budget_term = \get_term_by(
				is_numeric( $budget_id ) ? 'id' : 'slug',
				$budget_id,
				Budgets::TAXONOMY
			);
			if ( ! $budget_term ) {
				\WP_CLI::warning( sprintf( 'Story budget with ID or slug "%s" not found.', $budget_id ) );
				continue;
			}
			if ( $is_dry_run ) {
				\WP_CLI::log( sprintf( 'Would delete story budget "%s" with ID %d.', $budget_term->name, $budget_term->term_id ) );
			} else {
				$result = \wp_delete_term( $budget_term->term_id, Budgets::TAXONOMY );
				if ( ! $result || \is_wp_error( $result ) ) {
					\WP_CLI::error( sprintf( 'Failed to delete story budget "%s" with ID %d.', $budget_term->name, $budget_term->term_id ) );
				}
				\WP_CLI::log( sprintf( 'Deleted story budget "%s" with ID %d.', $budget_term->name, $budget_term->term_id ) );
			}
			$results++;
		}
		\WP_CLI::log( '' );
		\WP_CLI::success(
			sprintf(
				'%s %d story %s.',
				$is_dry_run ? 'Would delete' : 'Deleted',
				$results,
				$results > 1 ? 'budgets' : 'budget'
			)
		);
	}

	/**
	 * Update a story budget's info.
	 *
	 * ## OPTIONS
	 *
	 * [<id-or-slug>]
	 * : ID or slug of the story budget to update.
	 *
	 * [--dry-run]
	 * : If passed, output results but do not modify database.
	 *
	 * [--name=<name>]
	 * : (Optional) New name for the story budget.
	 *
	 * [--slug=<slug>]
	 * : (Optional) New slug for the story budget.
	 *
	 * [--description=<slug>]
	 * : (Optional) New description for the story budget.
	 *
	 * [--archived=<0|1|true|false>]
	 * : (Optional) Archive or unarchive the story budget.
	 *
	 * @param array $args Positional args.
	 * @param array $assoc_args Associative args.
	 */
	public static function cli_budget_update( $args, $assoc_args ) {
		$is_dry_run  = ! empty( $assoc_args['dry-run'] );
		$budget_id   = $args[0];
		$name        = $assoc_args['name'] ?? '';
		$slug        = $assoc_args['slug'] ?? '';
		$description = $assoc_args['description'] ?? '';
		$archive     = isset( $assoc_args['archived'] ) ? filter_var( $assoc_args['archived'], FILTER_VALIDATE_BOOLEAN ) : null;
		if ( empty( $budget_id ) ) {
			\WP_CLI::error( 'Please provide ID or slug of the story budget to update.' );
		}
		if ( empty( $name ) && empty( $slug ) && empty( $description ) && is_null( $archive ) ) {
			\WP_CLI::error( 'Please provide at least one argument to update.' );
		}

		$budget_term = \get_term_by(
			is_numeric( $budget_id ) ? 'id' : 'slug',
			$budget_id,
			Budgets::TAXONOMY
		);
		if ( ! $budget_term ) {
			\WP_CLI::error( sprintf( 'Story budget with ID or slug "%s" not found.', $budget_id ) );
		}
		if ( $is_dry_run ) {
			\WP_CLI::log( sprintf( 'Would update story budget "%s" with ID %d.', $budget_term->name, $budget_term->term_id ) );
		} else {
			$update_args = [];
			if ( ! empty( $name ) ) {
				$update_args['name'] = $name;
			}
			if ( ! empty( $slug ) ) {
				$update_args['slug'] = $slug;
			}
			if ( ! empty( $description ) ) {
				$update_args['description'] = $description;
			}

			$update_result = \wp_update_term(
				$budget_term->term_id,
				Budgets::TAXONOMY,
				$update_args
			);

			$archive_result = true;
			if ( ! is_null( $archive ) ) {
				$budget = new Single_Budget( $budget_term );
				if ( $archive ) {
					$archive_result = $budget->archive();
				} else {
					$archive_result = $budget->unarchive();
				}
			}
			$result = $update_result && $archive_result;
			if ( ! $result || \is_wp_error( $update_result ) ) {
				\WP_CLI::error( sprintf( 'Failed to update budget "%s" with ID %d.', $budget_term->name, $budget_term->term_id ) );
			}
			\WP_CLI::log( sprintf( 'Updated story budget "%s" with ID %d.', $budget_term->name, $budget_term->term_id ) );
		}
		\WP_CLI::log( '' );
		\WP_CLI::success(
			sprintf(
				'%s story budget "%s" with ID %d.',
				$is_dry_run ? 'Would update' : 'Updated',
				$budget_term->name,
				$budget_id
			)
		);
	}

	/**
	 * Add posts to a story budget.
	 *
	 * ## OPTIONS
	 *
	 * [<id-or-slug>]
	 * : ID or slug of the story budget to update.
	 *
	 * [--dry-run]
	 * : If passed, output results but do not modify database.
	 *
	 * [--post-ids=<post-ids>]
	 * : Comma-separated list of post IDs to assign to the story budget.
	 *
	 * @param array $args Positional args.
	 * @param array $assoc_args Associative args.
	 */
	public static function cli_budget_add_stories( $args, $assoc_args ) {
		$is_dry_run = ! empty( $assoc_args['dry-run'] );
		$budget_id  = $args[0];
		$post_ids   = ! empty( $assoc_args['post-ids'] ) ? explode( ',', $assoc_args['post-ids'] ) : [];
		if ( empty( $budget_id ) ) {
			\WP_CLI::error( 'Please provide ID or slug of the story budget to update.' );
		}
		if ( empty( $post_ids ) ) {
			\WP_CLI::error( 'Please provide post IDs to assign to the story budget.' );
		}

		$budget_term = \get_term_by(
			is_numeric( $budget_id ) ? 'id' : 'slug',
			$budget_id,
			Budgets::TAXONOMY
		);
		if ( ! $budget_term ) {
			\WP_CLI::error( sprintf( 'Story budget with ID or slug "%s" not found.', $budget_id ) );
		}
		$results = 0;
		foreach ( $post_ids as $post_id ) {
			$story = new Story( $post_id );
			if ( ! $story->is_valid() ) {
				\WP_CLI::warning( sprintf( 'Invalid story ID "%d".', $post_id ) );
				continue;
			}
			\WP_CLI::log(
				sprintf(
					'Adding post "%s" with ID %d to story budget "%s"...',
					\get_the_title( $post_id ),
					$post_id,
					$budget_term->name
				)
			);
			$results++;
		}

		if ( ! $is_dry_run ) {
			$budget  = new Single_Budget( $budget_term );
			$results = $budget->add_stories( $post_ids );
		}
		\WP_CLI::log( '' );
		\WP_CLI::success(
			sprintf(
				'%s %d %s to story budget "%s"',
				$is_dry_run ? 'Would add' : 'Added',
				$results,
				$results > 1 ? 'posts' : 'post',
				$budget_term->name
			)
		);
	}

	/**
	 * Remove posts from a story budget.
	 *
	 * ## OPTIONS
	 *
	 * [<id-or-slug>]
	 * : ID or slug of the story budget to update.
	 *
	 * [--dry-run]
	 * : If passed, output results but do not modify database.
	 *
	 * [--post-ids=<post-ids>]
	 * : Comma-separated list of post IDs to remove from the story budget.
	 *
	 * @param array $args Positional args.
	 * @param array $assoc_args Associative args.
	 */
	public static function cli_budget_remove_stories( $args, $assoc_args ) {
		$is_dry_run = ! empty( $assoc_args['dry-run'] );
		$budget_id  = $args[0];
		$post_ids   = ! empty( $assoc_args['post-ids'] ) ? explode( ',', $assoc_args['post-ids'] ) : [];
		if ( empty( $budget_id ) ) {
			\WP_CLI::error( 'Please provide ID or slug of the story budget to update.' );
		}
		if ( empty( $post_ids ) ) {
			\WP_CLI::error( 'Please provide post IDs to remove from the story budget.' );
		}

		$budget_term = \get_term_by(
			is_numeric( $budget_id ) ? 'id' : 'slug',
			$budget_id,
			Budgets::TAXONOMY
		);
		if ( ! $budget_term ) {
			\WP_CLI::error( sprintf( 'Story budget with ID or slug "%s" not found.', $budget_id ) );
		}
		$results = 0;
		foreach ( $post_ids as $post_id ) {
			$story = new Story( $post_id );
			if ( ! $story->is_valid() ) {
				\WP_CLI::warning( sprintf( 'Invalid story ID "%d".', $post_id ) );
				continue;
			}
			\WP_CLI::log(
				sprintf(
					'Removing post "%s" with ID %d from story budget "%s"...',
					\get_the_title( $post_id ),
					$post_id,
					$budget_term->name
				)
			);
			$results++;
		}
		if ( ! $is_dry_run ) {
			$budget  = new Single_Budget( $budget_term );
			$results = $budget->remove_stories( $post_ids );
		}
		\WP_CLI::log( '' );
		\WP_CLI::success(
			sprintf(
				'%s %d %s from story budget "%s"',
				$is_dry_run ? 'Would remomve' : 'Removed',
				$results,
				$results > 1 ? 'posts' : 'post',
				$budget_term->name
			)
		);
	}
}
