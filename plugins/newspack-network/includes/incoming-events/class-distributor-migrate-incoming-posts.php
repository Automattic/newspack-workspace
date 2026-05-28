<?php
/**
 * Newspack Network Distributor Migrate Incoming Posts.
 *
 * @package Newspack
 */

namespace Newspack_Network\Incoming_Events;

use Newspack_Network\Debugger;
use Newspack_Network\Content_Distribution\Distributor_Migrator;

/**
 * Class to handle the network post update.
 */
class Distributor_Migrate_Incoming_Posts extends Abstract_Incoming_Event {
	/**
	 * Processes the event
	 *
	 * @return void
	 */
	public function post_process_in_hub() {
		$this->process_migration();
	}

	/**
	 * Process event in Node
	 *
	 * @return void
	 */
	public function process_in_node() {
		$this->process_migration();
	}

	/**
	 * Log a message.
	 *
	 * If "newspack_log" is available, we'll use it. Otherwise, we'll fallback to
	 * the Network's debugger.
	 *
	 * @param string $message The message to log.
	 * @param string $type    The log type. Either 'error' or 'debug'.
	 *                        Default is 'error'.
	 *
	 * @return void
	 */
	protected function log( $message, $type = 'error' ) {
		if ( method_exists( 'Newspack\Logger', 'newspack_log' ) ) {
			\Newspack\Logger::newspack_log(
				'distributor_migrate_incoming_posts',
				$message,
				(array) $this->get_data(),
				$type
			);
		} else {
			$prefix = '[distributor_migrate_incoming_posts]';
			if ( ! empty( $this->payload ) ) {
				$prefix .= ' ' . $this->payload['network_post_id'];
			}
			Debugger::log( $prefix . ' ' . $message );
		}
	}

	/**
	 * Process incoming post migration.
	 */
	protected function process_migration() {
		$data = (array) $this->get_data();

		self::log( 'Processing incoming posts migration', 'debug' );

		foreach ( $data['incoming_posts'] as $incoming_post ) {
			if ( $incoming_post['site_url'] !== untrailingslashit( get_bloginfo( 'url' ) ) ) {
				continue;
			}
			$result = Distributor_Migrator::migrate_incoming_post( $incoming_post['post_id'] );
			if ( is_wp_error( $result ) ) {
				self::log(
					sprintf(
						'Error processing post ID %d: %s',
						$incoming_post['post_id'],
						$result->get_error_message()
					)
				);
			}
		}
	}
}
