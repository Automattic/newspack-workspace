<?php
/**
 * Campaigns Delete All CLI command.
 *
 * @package Newspack
 */

namespace Newspack\Campaigns\CLI;

use WP_CLI;
use WP_CLI_Command;
use Newspack_Popups;
use Newspack_Popups_Model;
use Newspack_Segments_Model;

/**
 * Delete all campaigns, segments, and prompts.
 */
class Delete_All extends WP_CLI_Command {

	/**
	 * Permanently delete all campaigns, segments, and prompts.
	 *
	 * ## OPTIONS
	 *
	 * [--campaigns-only]
	 * : Delete only campaigns.
	 *
	 * [--segments-only]
	 * : Delete only segments.
	 *
	 * [--prompts-only]
	 * : Delete only prompts.
	 *
	 * ## EXAMPLES
	 *
	 * wp newspack-popups delete-all
	 * wp newspack-popups delete-all --campaigns-only
	 * wp newspack-popups delete-all --segments-only
	 * wp newspack-popups delete-all --prompts-only
	 */
	/**
	 * Command entrypoint.
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 * @return void
	 */
	public function __invoke( $args, $assoc_args ) {
		$delete_campaigns = empty( $assoc_args['prompts-only'] ) && empty( $assoc_args['segments-only'] );
		$delete_segments  = empty( $assoc_args['prompts-only'] ) && empty( $assoc_args['campaigns-only'] );
		$delete_prompts   = empty( $assoc_args['segments-only'] ) && empty( $assoc_args['campaigns-only'] );

		if ( $delete_campaigns ) {
			$this->delete_all_campaigns();
		}

		if ( $delete_segments ) {
			$this->delete_all_segments();
		}

		if ( $delete_prompts ) {
			$this->delete_all_prompts();
		}

		WP_CLI::success( __( 'Requested data deleted!', 'newspack-popups' ) );
	}

	/**
	 * Delete all campaigns.
	 *
	 * @return void
	 */
	private function delete_all_campaigns() {
		$campaigns = \Newspack_Popups::get_groups();
		foreach ( $campaigns as $campaign ) {
			\wp_delete_term( $campaign->term_id, \Newspack_Popups::NEWSPACK_POPUPS_TAXONOMY );
		}
		$count = count( $campaigns );
		if ( 1 === $count ) {
			WP_CLI::success( __( 'Deleted 1 campaign', 'newspack-popups' ) );
		} else {
			// translators: %d is the number of deleted campaigns.
			WP_CLI::success( sprintf( __( 'Deleted %d campaigns', 'newspack-popups' ), $count ) );
		}
	}

	/**
	 * Delete all segments.
	 *
	 * @return void
	 */
	private function delete_all_segments() {
		$segments = \Newspack_Segments_Model::get_segments( true );
		foreach ( $segments as $segment ) {
			\wp_delete_term( $segment['id'], \Newspack_Segments_Model::TAX_SLUG );
		}
		$count = count( $segments );
		if ( 1 === $count ) {
			WP_CLI::success( __( 'Deleted 1 segment', 'newspack-popups' ) );
		} else {
			// translators: %d is the number of deleted segments.
			WP_CLI::success( sprintf( __( 'Deleted %d segments', 'newspack-popups' ), $count ) );
		}
	}

	/**
	 * Delete all prompts.
	 *
	 * @return void
	 */
	private function delete_all_prompts() {
		$prompts = \Newspack_Popups_Model::retrieve_popups( true, true );
		$ids     = array_map(
			function( $prompt ) {
				return (int) $prompt['id'];
			},
			$prompts
		);
		foreach ( $ids as $post_id ) {
			\wp_delete_post( $post_id, true );
		}
		$count = count( $ids );
		if ( 1 === $count ) {
			WP_CLI::success( __( 'Deleted 1 prompt', 'newspack-popups' ) );
		} else {
			// translators: %d is the number of deleted prompts.
			WP_CLI::success( sprintf( __( 'Deleted %d prompts', 'newspack-popups' ), $count ) );
		}
	}
}
