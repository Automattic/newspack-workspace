<?php
/**
 * CLI tools for Mailchimp.
 *
 * @package Newspack
 */

namespace Newspack\CLI;

use WP_CLI;
use Newspack\Reader_Activation;
use Newspack\Reader_Activation\Sync\Metadata;
use Newspack\Mailchimp_API;

defined( 'ABSPATH' ) || exit;

/**
 * Newspack Mailchimp CLI Class.
 */
class Mailchimp {
	/**
	 * List all or matching merge fields in the connected Mailchimp audience.
	 *
	 * ## OPTIONS
	 *
	 * [--fields=<field1,field2,etc>]
	 * : Field slugs to match. These should match raw field slugs as defined in Newspack\Reader_Activation\Sync\Metadata. If specified, only merge fields matching these slugs will be shown.
	 *
	 * [--prefix=<prefix>]
	 * : If specified, only fields with a matching prefix will be shown.
	 *
	 * @param array $args Positional args.
	 * @param array $assoc_args Associative args.
	 */
	public static function cli_mailchimp_list_merge_fields( $args, $assoc_args ) {
		$is_dry_run    = ! empty( $assoc_args['dry-run'] );
		$fields_to_show = ! empty( $assoc_args['fields'] ) ? explode( ',', $assoc_args['fields'] ) : false;
		$prefix         = $assoc_args['prefix'] ?? '';

		$all_fields = Metadata::get_all_fields();
		if ( $fields_to_show ) {
			$fields_to_show = array_reduce(
				$fields_to_show,
				function( $acc, $field_slug ) use ( $prefix, $all_fields ) {
					if ( ! empty( $all_fields[ $field_slug ] ) ) {
						$acc[] = trim( $prefix . $all_fields[ $field_slug ] );
					} elseif ( ! empty( Metadata::get_utm_key( $field_slug ) ) && ( ! $prefix || 0 === strpos( Metadata::get_utm_key( $field_slug ), $prefix ) ) ) {
						$acc[] = trim( $prefix . str_replace( Metadata::PREFIX, '', Metadata::get_utm_key( $field_slug ) ) );
					} else {
						\WP_CLI::warning( sprintf( 'Field %s not recognized.', $field_slug ) );
					}
					return $acc;
				},
				[]
			);
		}

		$audience_id = Reader_Activation::get_setting( 'mailchimp_audience_id' );
		if ( empty( $audience_id ) ) {
			\WP_CLI::error( __( 'Mailchimp audience ID not set.', 'newspack-plugin' ) );
		}

		$result = Mailchimp_API::get( "lists/$audience_id/merge-fields?count=1000" );
		if ( is_wp_error( $result ) || empty( $result['merge_fields'] || ! is_array( $result['merge_fields'] ) ) ) {
			\WP_CLI::error( __( 'Could not connect to Mailchimp API. Is the site connected to Mailchimp?', 'newspack-plugin' ) );
		}
		$fields = $result['merge_fields'];

		$matching = 0;
		$results  = [];
		foreach ( $fields as $field ) {
			$name_parts = explode( '_', $field['name'] );
			$field_name = $prefix ? $field['name'] : end( $name_parts );
			if ( ( ! $fields_to_show || in_array( $field_name, $fields_to_show, true ) ) && ( ! $prefix || 0 === strpos( $field['name'], $prefix ) ) ) {
				$results[] = [
					'id'   => $field['merge_id'],
					'tag'  => $field['tag'],
					'name' => $field['name'],
					'type' => $field['type'],
				];
				$matching++;
			}
		}

		\WP_CLI\Utils\format_items(
			'table',
			$results,
			[
				'id',
				'tag',
				'name',
				'type',
			]
		);
		\WP_CLI::success(
			sprintf(
				'Found %d merge fields.',
				$matching
			)
		);
	}

	/**
	 * Delete the specified merge fields in the connected Mailchimp audience. WARNING: Any data in the deleted fields will be lost.
	 *
	 * ## OPTIONS
	 *
	 * [--dry-run]
	 * : If passed, output results but do not modify any fields.
	 *
	 * [--fields=<field1,field2,etc>]
	 * : (required) Field slugs to delete, comma-separated. These should match raw field slugs as defined in Newspack\Reader_Activation\Sync\Metadata.
	 *
	 * [--prefix=<prefix>]
	 * : If specified, only fields with a matching prefix will be deleted.
	 *
	 * @param array $args Positional args.
	 * @param array $assoc_args Associative args.
	 */
	public static function cli_mailchimp_delete_merge_fields( $args, $assoc_args ) {
		$is_dry_run       = ! empty( $assoc_args['dry-run'] );
		$fields_to_delete = ! empty( $assoc_args['fields'] ) ? explode( ',', $assoc_args['fields'] ) : false;
		$prefix           = $assoc_args['prefix'] ?? '';

		if ( empty( $fields_to_delete ) ) {
			\WP_CLI::error( __( 'Please specify at least one field to delete.', 'newspack-plugin' ) );
		}

		$all_fields       = Metadata::get_all_fields();
		$fields_to_delete = array_reduce(
			$fields_to_delete,
			function( $acc, $field_slug ) use ( $prefix, $all_fields ) {
				if ( ! empty( $all_fields[ $field_slug ] ) ) {
					$acc[] = trim( $prefix . $all_fields[ $field_slug ] );
				} elseif ( ! empty( Metadata::get_utm_key( $field_slug ) ) && ( ! $prefix || 0 === strpos( Metadata::get_utm_key( $field_slug ), $prefix ) ) ) {
					$acc[] = trim( $prefix . str_replace( Metadata::PREFIX, '', Metadata::get_utm_key( $field_slug ) ) );
				} else {
					\WP_CLI::warning( sprintf( 'Field %s not recognized.', $field_slug ) );
				}
				return $acc;
			},
			[]
		);

		$audience_id = Reader_Activation::get_setting( 'mailchimp_audience_id' );
		if ( empty( $audience_id ) ) {
			\WP_CLI::error( __( 'Mailchimp audience ID not set.', 'newspack-plugin' ) );
		}

		$result = Mailchimp_API::get( "lists/$audience_id/merge-fields?count=1000" );
		if ( is_wp_error( $result ) || empty( $result['merge_fields'] || ! is_array( $result['merge_fields'] ) ) ) {
			\WP_CLI::error( __( 'Could not connect to Mailchimp API. Is the site connected to Mailchimp?', 'newspack-plugin' ) );
		}
		$fields = $result['merge_fields'];

		$deleted = 0;
		foreach ( $fields as $field ) {
			$name_parts = explode( '_', $field['name'] );
			$field_name = $prefix ? $field['name'] : end( $name_parts );

			if ( in_array( $field_name, $fields_to_delete, true ) ) {
				if ( ! $is_dry_run ) {
					Mailchimp_API::delete( "lists/$audience_id/merge-fields/" . $field['merge_id'] );
				}
				\WP_CLI::log(
					sprintf(
						'%s merge field %s.',
						$is_dry_run ? 'Would delete' : 'Deleted',
						$field['name']
					)
				);
				$deleted++;
			}
		}

		\WP_CLI::success(
			sprintf(
				'%s %d merge fields.',
				$is_dry_run ? 'Would delete' : 'Deleted',
				$deleted
			)
		);
	}

	/**
	 * Identifies duplicate merge fields with the same name in the connected Mailchimp account and consolidates all data into a single instance of the field.
	 *
	 * ## OPTIONS
	 *
	 * [--dry-run]
	 * : If passed, output results but do not modify any data.
	 *
	 * @param array $args Positional args.
	 * @param array $assoc_args Associative args.
	 */
	public static function cli_mailchimp_fix_duplicate_merge_fields( $args, $assoc_args ) {
		$is_dry_run = ! empty( $assoc_args['dry-run'] );

		// Filter request timeout.
		add_filter( // phpcs:ignore WordPressVIPMinimum.Hooks.RestrictedHooks.http_request_timeout
			'http_request_timeout',
			function() {
				return 60;
			}
		);

		$lists = Mailchimp_API::get( 'lists?count=1000' );
		if ( \is_wp_error( $lists ) ) {
			WP_CLI::error( 'Error fetching audiences: ' . $lists->get_error_message() );
			return;
		}

		foreach ( $lists['lists'] as $list ) {
			WP_CLI::log(
				sprintf(
					'Fixing duplicate merge fields in audience %s... %s',
					$list['id'],
					$is_dry_run ? '(DRY RUN MODE)' : ''
				)
			);
			WP_CLI::line( '' );
			$list_id = $list['id'];

			// First, consolidate data in duplicate fields into one instance.
			$fixed = self::fix_duplicate_fields_for_list( $list_id, $is_dry_run );
			if ( \is_wp_error( $fixed ) ) {
				WP_CLI::error( 'Error fixing audience ' . $list_id . ': ' . $fixed->get_error_message() );
			}
		}
	}

	/**
	 * Fix duplicate merge fields for a list given its ID.
	 *
	 * @param string $list_id List ID.
	 * @param bool   $is_dry_run Whether to run in dry-run mode.
	 *
	 * @return \WP_Error|void
	 */
	private static function fix_duplicate_fields_for_list( $list_id, $is_dry_run = true ) {
		$config = self::get_duplicate_merge_fields_config( $list_id );
		if ( \is_wp_error( $config ) ) {
			return $config;
		}
		if ( empty( $config['duplicate'] ) ) {
			WP_CLI::log( 'Skipping: no duplicate merge fields found.' );
			return;
		}

		// Create segment for each duplicate.
		$segment_groups = [];
		foreach ( $config['duplicate'] as $merge_field_name => $merge_fields ) {
			WP_CLI::log( 'Found ' . count( $merge_fields ) . " duplicate merge field(s) for $merge_field_name" );

			if ( ! $is_dry_run ) {
				$conditions = array_map(
					function( $merge_field ) {
						return [
							'condition_type' => 'TextMerge',
							'field'          => $merge_field['tag'],
							'op'             => 'blank_not',
						];
					},
					$merge_fields
				);
				// Split conditions in groups of 5. (Mailchimp limit).
				$conditions_groups = array_chunk( $conditions, 5 );

				// Create temporary segments to migrate date between fields.
				foreach ( $conditions_groups as $i => $conditions_group ) {
					$segment = Mailchimp_API::post(
						"lists/$list_id/segments",
						[
							'name'    => "Merge field: $merge_field_name #$i",
							'options' => [
								'match'      => 'any',
								'conditions' => $conditions_group,
							],
						]
					);
					if ( \is_wp_error( $segment ) ) {
						return new \WP_Error( 'newspack_cli_mailchimp_error', "Error creating temporary segment #$i for $merge_field_name: " . $segment->get_error_message() );
					} else {
						WP_CLI::log( "Created segment {$segment['id']} for $merge_field_name" );
					}
					$segment_groups[ $merge_field_name ][] = $segment;
				}
			}
		}

		// Fix for each merge field using the temporary segments.
		if ( ! $is_dry_run ) {
			foreach ( $segment_groups as $merge_field_name => $segments ) {
				foreach ( $segments as $i => $segment ) {
					// Fetch segment members.
					$members = Mailchimp_API::get(
						"lists/$list_id/segments/{$segment['id']}/members?include_cleaned=1&include_transactional=1&include_unsubscribed=1&count=1000"
					);
					if ( \is_wp_error( $members ) ) {
						return new \WP_Error( 'newspack_cli_mailchimp_error', "Error fetching members for temporary segment #$i for $merge_field_name: " . $members->get_error_message() );
					}
					WP_CLI::log( "$merge_field_name {$segment['id']}: Found " . count( $members['members'] ) . ' members' );
					if ( empty( $members ) ) {
						continue;
					}

					// Update members.
					$merge_field = $config['unique'][ $merge_field_name ];
					$duplicates = $config['duplicate'][ $merge_field_name ];
					foreach ( $members['members'] as $member ) {
						// If member already has a value for the merge field, skip.
						if ( ! empty( $member[ $merge_field['tag'] ] ) ) {
							continue;
						}
						// Get value from first duplicate that has a value.
						$value = null;
						$found_duplicate = null;
						foreach ( $duplicates as $duplicate ) {
							if ( ! empty( $member['merge_fields'][ $duplicate['tag'] ] ) ) {
								$found_duplicate = $duplicate;
								$value = $member['merge_fields'][ $duplicate['tag'] ];
								break;
							}
						}
						// Skip if no value found.
						if ( empty( $value ) ) {
							continue;
						}
						// Update member.
						WP_CLI::log( "$merge_field_name #$i: Updating member \"{$member['email_address']}\" with value \"$value\" from tag \"{$found_duplicate['tag']}\"" );
						Mailchimp_API::put(
							"lists/$list_id/members/" . $member['id'],
							[
								'merge_fields' => [
									$merge_field['tag'] => $value,
									$found_duplicate['tag'] => '',
								],
							]
						);
					}
					self::delete_segment( $list_id, $segment['id'] );
				}
			}
		}

		WP_CLI::line( '' );
		WP_CLI::success(
			sprintf(
				'%s in duplicate fields for audience %s',
				$is_dry_run ? 'Would consolidate data' : 'Consolidated data',
				$list_id
			)
		);

		// Next, delete the duplicate fields.
		$deleted = self::delete_duplicate_fields_for_list( $list_id, $is_dry_run );
		if ( \is_wp_error( $deleted ) ) {
			WP_CLI::error( 'Error deleting duplicate fields for audience ' . $list_id . ': ' . $deleted->get_error_message() );
		} elseif ( is_array( $deleted ) ) {
			WP_CLI::line( '' );
			WP_CLI::success(
				sprintf(
					'%s duplicate fields for audience %s: %s',
					$is_dry_run ? 'Would delete' : 'Deleted',
					$list_id,
					implode( ', ', $deleted )
				)
			);
		}
	}

	/**
	 * Delete a segment.
	 *
	 * @param string $list_id List ID.
	 * @param string $segment_id Segment ID.
	 */
	private static function delete_segment( $list_id, $segment_id ) {
		try {
			$res = Mailchimp_API::request( 'DELETE', "lists/$list_id/segments/$segment_id" );
		} catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
			// This will always throw an error, even on success.
			return true;
		}
		if ( \is_wp_error( $res ) ) {
			return $res;
		}
	}

	/**
	 * Delete duplicate merge fields for a list given its ID.
	 *
	 * @param string  $list_id List ID.
	 * @param boolean $is_dry_run If true, log but don't execute the deletion.
	 *
	 * @return \WP_Error|void
	 */
	private static function delete_duplicate_fields_for_list( $list_id, $is_dry_run = true ) {
		$config = self::get_duplicate_merge_fields_config( $list_id );
		if ( \is_wp_error( $config ) ) {
			return $config;
		}
		if ( empty( $config['duplicate'] ) ) {
			WP_CLI::log( '		Skipping: no duplicate merge fields found.' );
			return;
		}

		$deleted_merge_fields = [];

		foreach ( $config['duplicate'] as $merge_field_name => $merge_fields ) {
			foreach ( $merge_fields as $merge_field ) {
				WP_CLI::log( "		Deleting merge field {$merge_field['tag']} ({$merge_field['merge_id']}) for $merge_field_name" );
				try {
					$res = true;
					if ( $is_dry_run ) {
						WP_CLI::log(
							sprintf(
								'		DRY RUN: would have deleted merge field %s (%s) in audience %s.',
								$merge_field['tag'],
								$merge_field_name,
								$list_id
							)
						);
					} else {
						$res = Mailchimp_API::request( 'DELETE', "lists/$list_id/merge-fields/{$merge_field['merge_id']}" );
						WP_CLI::success(
							sprintf(
								'		Deleted merge field %s in audience %s.',
								$merge_field['tag'],
								$list_id
							)
						);
					}
				} catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
					// This will always throw an error, even on success.
				}
				if ( \is_wp_error( $res ) ) {
					return new \WP_Error( 'newspack_cli_mailchimp_error', "Error deleting merge field {$merge_field['merge_id']} for $merge_field_name: " . $res->get_error_message() );
				}

				$deleted_merge_fields[] = $merge_field_name;
			}
		}
		return $deleted_merge_fields;
	}

	/**
	 * Determine which fields to check for duplicates.
	 *
	 * @return array
	 */
	private static function get_fields_to_check_for_duplicates() {
		/**
		 * Apply the filters that allow other plugins to add their own fields.
		 *
		 * This filter is documented in Newspack\Reader_Activation\Sync\Metadata::get_keys().
		 */
		$fields = \apply_filters( 'newspack_ras_metadata_keys', Metadata::get_all_fields() );
		$fields = array_map(
			function( $key ) {
				return Metadata::get_key( $key );
			},
			array_keys( $fields )
		);

		// Additional fields.
		$fields = array_merge(
			$fields,
			[
				// Standard Mailchimp merge fields.
				'First Name',
				'Last Name',
				'Phone',
				'Address',

				// Other Newspack-specific fields.
				'origin_newspack',
				'newsletters_subscription_method',
				'current_page_url',
				'newspack_popup_id',
				'registration_method',
			]
		);
		return $fields;
	}

	/**
	 * Get duplicate merge fields config for a list given its ID.
	 *
	 * @param string $list_id List ID.
	 *
	 * @return array|\WP_Error
	 */
	private static function get_duplicate_merge_fields_config( $list_id ) {
		// Get all merge fields and sort by display order.
		$merge_fields = Mailchimp_API::get( "lists/$list_id/merge-fields?count=1000" );
		if ( \is_wp_error( $merge_fields ) ) {
			return new \WP_Error( 'newspack_cli_mailchimp_error', 'Error fetching merge fields: ' . $merge_fields->get_error_message() );
		}
		usort(
			$merge_fields['merge_fields'],
			function( $a, $b ) {
				return $a['display_order'] - $b['display_order'];
			}
		);

		// Which field names to check for duplicates.
		$fields = self::get_fields_to_check_for_duplicates();
		if ( \is_wp_error( $fields ) ) {
			return $fields;
		}

		// Group merge fields by name.
		$unique = [];
		$duplicate = [];
		foreach ( $merge_fields['merge_fields'] as $merge_field ) {
			if ( ! in_array( $merge_field['name'], $fields ) ) {
				continue;
			}
			if ( ! isset( $unique[ $merge_field['name'] ] ) ) {
				$unique[ $merge_field['name'] ] = $merge_field;
			} else {
				if ( ! isset( $duplicate[ $merge_field['name'] ] ) ) {
					$duplicate[ $merge_field['name'] ] = [];
				}
				$duplicate[ $merge_field['name'] ][] = $merge_field;
			}
		}

		return [
			'unique'    => $unique,
			'duplicate' => $duplicate,
		];
	}
}
