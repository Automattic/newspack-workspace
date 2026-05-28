<?php
/**
 * Newspack Story Budget - class for handling story budget stories.
 *
 * @package Newspack_Story_Budget
 */

namespace Newspack_Story_Budget\CLI;

use Newspack_Story_Budget\Budgets;


/**
 * CLI commands to manage story budget stories.
 */
class Story {
	/**
	 * List stories assigned to story budgets.
	 *
	 * ## OPTIONS
	 *
	 * [--fields=<field1,field2,etc>]
	 * : (Optional) Comma-separated list of fields to show.
	 *
	 * [--budget-id=<budget-id>]
	 * : (Optional) Show stories assigned to a specific budget. If not given, will fetch all stories associated with active story budgets.
	 *
	 * [--max=<max-results>]
	 * : (Optional) Maximum number of results to show.
	 *
	 * [--offset=<offset>]
	 * : (Optional) Offset to start from.
	 *
	 * [--format=<table|csv|json|yaml|ids|count>]
	 * : (Optional) Format to output results in.
	 *
	 * @param array $args Positional args.
	 * @param array $assoc_args Associative args.
	 */
	public static function cli_story_list( $args, $assoc_args ) {
		$budget_id     = ! empty( $assoc_args['budget-id'] ) ? $assoc_args['budget-id'] : null;
		$fields_to_show = ! empty( $assoc_args['fields'] ) ? explode( ',', $assoc_args['fields'] ) : [];
		$max_results   = ! empty( $assoc_args['max'] ) ? (int) $assoc_args['max'] : -1;
		$offset        = ! empty( $assoc_args['offset'] ) ? (int) $assoc_args['offset'] : 0;
		$format        = $assoc_args['format'] ?? 'table';
		$query_args    = [
			'posts_per_page' => $max_results,
			'offset'         => $offset,
		];

		$stories = Budgets::get_stories( $query_args, $budget_id );
		$results = [];
		$fields   = [];
		foreach ( $stories as $story ) {
			$story = $story->to_array();
			foreach ( $story as $field => $value ) {
				if ( ! empty( $fields_to_show ) && ! empty( array_intersect( $fields_to_show, array_keys( $story ) ) ) && ! in_array( $field, $fields_to_show, true ) ) {
					unset( $story[ $field ] );
				}
			}
			if ( empty( $fields ) ) {
				$fields = array_keys( $story );
			}
			if ( empty( $story ) ) {
				continue;
			}
			$results[] = $story;
		}

		\WP_CLI\Utils\format_items(
			$format,
			'ids' === $format ? array_column( $results, 'id' ) : $results,
			$fields
		);
		\WP_CLI::log( '' );
		\WP_CLI::success(
			sprintf(
				'Found %d stories.',
				count( $results )
			)
		);
	}

	/**
	 * Get a story by ID.
	 *
	 * ## OPTIONS
	 *
	 * [<id>]
	 * : Post ID of the story to get.
	 *
	 * [--fields=<field1,field2,etc>]
	 * : (Optional) Comma-separated list of fields to show.
	 *
	 * [--format=<table|csv|json|yaml|ids|count>]
	 * : (Optional) Format to output results in.
	 *
	 * @param array $args Positional args.
	 * @param array $assoc_args Associative args.
	 */
	public static function cli_story_get( $args, $assoc_args ) {
		$post_id       = $args[0];
		$fields_to_show = ! empty( $assoc_args['fields'] ) ? explode( ',', $assoc_args['fields'] ) : [];
		$format        = $assoc_args['format'] ?? 'table';
		if ( empty( $post_id ) ) {
			\WP_CLI::error( 'Please provide ID or slug of the story budget to update.' );
		}

		$story = new \Newspack_Story_Budget\Story( $post_id );
		if ( ! $story->is_valid() ) {
			\WP_CLI::error( 'Invalid story ID.' );
		}

		$story = $story->to_array();
		$formatted_result = [];
		foreach ( $story as $field => $value ) {
			if ( ! empty( $fields_to_show ) && ! in_array( $field, $fields_to_show, true ) ) {
				continue;
			}
			$formatted_result[] = [
				'field' => $field,
				'value' => ! empty( $value ) ? $value : '',
			];
		}
		\WP_CLI\Utils\format_items(
			$format,
			'ids' === $format ? [ $post_id ] : $formatted_result,
			[
				'field',
				'value',
			]
		);
	}
}
