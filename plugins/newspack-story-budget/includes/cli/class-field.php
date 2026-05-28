<?php
/**
 * Newspack Story Budget - class for handling fields.
 *
 * @package Newspack_Story_Budget
 */

namespace Newspack_Story_Budget\CLI;

/**
 * CLI commands to manage story budget fields.
 */
class Field {
	/**
	 * List all registered fields and their properties.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<table|csv|json|yaml|ids|count>]
	 * : (Optional) Format to output results in.
	 *
	 * @param array $args Positional args.
	 * @param array $assoc_args Associative args.
	 */
	public static function cli_field_list( $args, $assoc_args ) {
		$all_fields = \Newspack_Story_Budget\Fields::get_all_fields();
		$format    = $assoc_args['format'] ?? 'table';
		$results   = [];
		foreach ( $all_fields as $field_slug => $field ) {
			$results[] = [
				'name'        => $field->get_name(),
				'slug'        => $field->get_slug(),
				'type'        => $field->get_type(),
				'is_editable' => $field->is_editable() ? 'yes' : 'no',
			];
		}

		\WP_CLI\Utils\format_items(
			$format,
			'ids' === $format ? array_column( $results, 'slug' ) : $results,
			[
				'name',
				'slug',
				'type',
				'is_editable',
			]
		);
		\WP_CLI::log( '' );
		\WP_CLI::success(
			sprintf(
				'Found %d fields.',
				count( $results )
			)
		);
	}
}
