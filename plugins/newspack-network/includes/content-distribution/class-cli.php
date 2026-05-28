<?php
/**
 * Network Content Distribution commands.
 *
 * @package Newspack
 */

namespace Newspack_Network\Content_Distribution;

use Newspack_Network\Content_Distribution as Content_Distribution_Class;
use Newspack_Network\Utils\Network;
use WP_CLI;
use WP_CLI\ExitException;
use WP_Error;

/**
 * Class Distribution.
 */
class CLI {
	/**
	 * Initialize this class and register hooks
	 *
	 * @return void
	 */
	public static function init(): void {
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			add_action( 'init', [ __CLASS__, 'register_commands' ] );
		}
	}

	/**
	 * Callback to register the WP-CLI commands.
	 *
	 * @return void
	 * @throws \Exception If something goes wrong.
	 */
	public static function register_commands(): void {
		WP_CLI::add_command(
			'newspack network distribute post',
			[ __CLASS__, 'cmd_distribute_post' ],
			[
				'shortdesc' => __( 'Distribute a post to all the network or the specified sites', 'newspack-network' ),
				'synopsis'  => [
					[
						'type'        => 'positional',
						'name'        => 'post-id',
						'description' => sprintf(
							// translators: %s: list of supported post types.
							__( 'The ID of the post to distribute. Supported post types are: %s', 'newspack-network' ),
							implode(
								', ',
								Content_Distribution_Class::get_distributed_post_types()
							)
						),
						'optional'    => false,
						'repeating'   => false,
					],
					[
						'type'        => 'assoc',
						'name'        => 'sites',
						'description' => __( "Networked site url(s) comma separated to distribute the post to – or 'all' to distribute to all sites in the network.", 'newspack-network' ),
						'optional'    => false,
					],
					[
						'type'        => 'assoc',
						'name'        => 'status_on_publish',
						'description' => __( 'The post status when creating the post. Default is to distribute as draft.' ),
						'optional'    => true,
					],
				],
			]
		);
		WP_CLI::add_command(
			'newspack network distributor migrate',
			[ __CLASS__, 'cmd_distributor_migrate' ],
			[
				'shortdesc' => __( 'Migrate posts from Distributor to Newspack Network\'s content distribution', 'newspack-network' ),
				'synopsis'  => [
					[
						'type'        => 'positional',
						'name'        => 'post-id',
						'description' => __( 'The ID of the post to migrate.', 'newspack-network' ),
						'repeating'   => false,
						'optional'    => true,
					],
					[
						'type'        => 'flag',
						'name'        => 'all',
						'description' => __( 'Migrate all posts.', 'newspack-network' ),
						'optional'    => true,
					],
					[
						'type'        => 'assoc',
						'name'        => 'batch-size',
						'description' => __( 'Number of posts to migrate in each batch.', 'newspack-network' ),
						'optional'    => true,
						'default'     => 50,
					],
					[
						'type'        => 'flag',
						'name'        => 'strict',
						'description' => __( 'Whether to only migrate if all distributed posts can be migrated.', 'newspack-network' ),
						'optional'    => true,
					],
					[
						'type'        => 'flag',
						'name'        => 'delete',
						'description' => __( 'Whether to deactivate and delete the Distributor plugin after migrating all posts. This will only take effect if all posts were able to migrate.', 'newspack-network' ),
						'optional'    => true,
					],
					[
						'type'        => 'flag',
						'name'        => 'dry-run',
						'description' => __( 'Whether to run the migration in dry-run mode.', 'newspack-network' ),
						'optional'    => true,
					],
				],
			]
		);
	}

	/**
	 * Callback for the `newspack-network distribute post` command.
	 *
	 * @param array $pos_args   Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 *
	 * @throws ExitException If something goes wrong.
	 */
	public function cmd_distribute_post( array $pos_args, array $assoc_args ): void {
		$post_id = $pos_args[0];
		if ( ! is_numeric( $post_id ) ) {
			WP_CLI::error( 'Post ID must be a number.' );
		}

		if ( 'all' === $assoc_args['sites'] ) {
			$sites = Network::get_networked_urls();
		} else {
			$sites = array_map(
				fn( $site ) => untrailingslashit( trim( $site ) ),
				explode( ',', $assoc_args['sites'] )
			);
		}

		try {
			$outgoing_post = Content_Distribution_Class::get_distributed_post( $post_id ) ?? new Outgoing_Post( $post_id );
			$sites = $outgoing_post->set_distribution( $sites );
			if ( is_wp_error( $sites ) ) {
				WP_CLI::error( $sites->get_error_message() );
			}

			Content_Distribution_Class::distribute_post( $outgoing_post, $assoc_args['status_on_publish'] ?? 'draft' );
			WP_CLI::success( sprintf( 'Post with ID %d is distributed to %d sites: %s', $post_id, count( $sites ), implode( ', ', $sites ) ) );

		} catch ( \Exception $e ) {
			WP_CLI::error( $e->getMessage() );
		}
	}

	/**
	 * Callback for the `newspack-network distributor migrate` command.
	 *
	 * @param array $pos_args   Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 *
	 * @throws ExitException If something goes wrong.
	 */
	public function cmd_distributor_migrate( array $pos_args, array $assoc_args ): void {
		$post_id = $pos_args[0] ?? null;
		if ( ! is_numeric( $post_id ) && ! isset( $assoc_args['all'] ) ) {
			WP_CLI::error( 'Post ID must be a number.' );
		}

		$dry_run = isset( $assoc_args['dry-run'] );

		if ( is_numeric( $post_id ) && isset( $assoc_args['all'] ) ) {
			WP_CLI::error( 'The --all flag cannot be used with a post ID.' );
		}

		if ( ! isset( $assoc_args['all'] ) && isset( $assoc_args['delete'] ) ) {
			WP_CLI::error( 'The --delete flag can only be used with the --all flag.' );
		}

		if ( isset( $assoc_args['batch-size'] ) && ! is_numeric( $assoc_args['batch-size'] ) ) {
			WP_CLI::error( 'Batch size must be a number.' );
		}

		if ( isset( $assoc_args['all'] ) ) {
			$strict = isset( $assoc_args['strict'] );

			$subscription_ids = Distributor_Migrator::get_distributor_subscriptions();

			if ( empty( $subscription_ids ) ) {
				WP_CLI::success( 'No subscriptions found.' );
				return;
			}

			WP_CLI::log( sprintf( 'Found %d subscriptions.', count( $subscription_ids ) ) );

			// In strict mode, only continue if all posts can be migrated.
			if ( $strict ) {
				$errors = new WP_Error();
				foreach ( $subscription_ids as $subscription_id ) {
					$can_migrate = Distributor_Migrator::can_migrate_subscription( $subscription_id );
					if ( is_wp_error( $can_migrate ) ) {
						$errors->add( $can_migrate->get_error_code(), sprintf( 'Unable to migrate subscription %d: %s', $subscription_id, $can_migrate->get_error_message() ) );
					}
				}
				if ( $errors->has_errors() ) {
					WP_CLI::error( 'Strict mode is enabled: ' . PHP_EOL . implode( PHP_EOL, $errors->get_error_messages() ) );
				}
			}

			// Migrate posts.
			$errors     = new WP_Error();
			$batch_size = $assoc_args['batch-size'] ?? 50;
			$batches    = array_chunk( $subscription_ids, $batch_size );

			foreach ( $batches as $i => $batch ) {
				if ( ! $dry_run ) {
					$result = Distributor_Migrator::migrate_subscriptions( $batch );
					if ( is_wp_error( $result ) ) {
						$message = sprintf( '(%d/%d) Error migrating batch: %s', $i + 1, count( $batches ), $result->get_error_message() );
						if ( $strict ) {
							WP_CLI::error( $message );
						} else {
							$errors->add( $result->get_error_code(), $result->get_error_message() );
							WP_CLI::log( $message );
						}
					} else {
						WP_CLI::log( sprintf( '(%d/%d) Batch migrated.', $i + 1, count( $batches ) ) );
					}
				} else {
					WP_CLI::log( sprintf( '(%d/%d) Batch would be migrated.', $i + 1, count( $batches ) ) );
				}
			}

			if ( ! $dry_run && isset( $assoc_args['delete'] ) && ! $errors->has_errors() ) {
				deactivate_plugins( [ 'distributor/distributor.php' ] );
				delete_plugins( [ 'distributor/distributor.php' ] );
				WP_CLI::log( 'Distributor plugin is deactivated and deleted.' );
			}
		} else {
			$can_migrate = Distributor_Migrator::can_migrate_outgoing_post( $post_id );
			if ( is_wp_error( $can_migrate ) ) {
				WP_CLI::error( $can_migrate->get_error_message() );
			}
			if ( ! $dry_run ) {
				$result = Distributor_Migrator::migrate_outgoing_post( $post_id );
				if ( is_wp_error( $result ) ) {
					WP_CLI::error( $result->get_error_message() );
				}
			}
		}

		WP_CLI::success( 'Migration completed.' . ( $dry_run ? ' (Dry-run)' : '' ) );
	}
}
