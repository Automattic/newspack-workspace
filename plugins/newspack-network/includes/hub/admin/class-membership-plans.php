<?php
/**
 * Newspack Hub Membership_Plans Admin pages
 *
 * @package Newspack
 */

namespace Newspack_Network\Hub\Admin;

use Newspack_Network\Admin as Network_Admin;
use Newspack_Network\Debugger;

/**
 * Class to handle Woo Membership_Plans
 */
abstract class Membership_Plans {
	const PAGE_SLUG = 'newspack-network-membership-plans';
	const OPTIONS_CACHE_KEY_PLANS = 'newspack-network-membership-plans';

	/**
	 * Runs the initialization.
	 */
	public static function init() {
		add_action( 'admin_menu', [ __CLASS__, 'add_menu' ] );
	}

	/**
	 * Adds the submenu page
	 *
	 * @return void
	 */
	public static function add_menu() {
		Network_Admin::add_submenu_page( __( 'Membership Plans', 'newspack-network' ), self::PAGE_SLUG, [ __CLASS__, 'render' ] );
	}

	/**
	 * Renders the page
	 */
	public static function render() {
		if ( isset( $_POST['action'] ) && $_POST['action'] === 'refetch' ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			delete_option( self::OPTIONS_CACHE_KEY_PLANS );
		}
		?>
			<div class="wrap newspack-network-membership-plans">
				<style>.newspack-network-membership-plans .tablenav{display: none;}</style>
				<h2><?php echo esc_html( __( 'Membership Plans', 'newspack-network' ) ); ?></h2>
				<?php
					$table = new Membership_Plans_Table();
					$table->prepare_items();
					$table->display();
				?>
				<?php $plans_cache = self::get_membership_plans_from_cache(); ?>
				<?php if ( $plans_cache && isset( $plans_cache['last_updated'] ) ) : ?>
					<p>
					<?php
					printf(
						/* translators: last fetch date. */
						esc_html__( 'Plans were last fetched on %s.', 'newspack-network' ),
						esc_html( gmdate( 'Y-m-d H:i', (int) $plans_cache['last_updated'] ) )
					);
					?>
					</p>
					<form method='post'>
						<input type="hidden" name="action" value="refetch">
						<input name='submit' type='submit' id='submit' class='button-secondary' value='<?php _e( 'Refetch Plans' ); ?>' />
					</form>
				<?php endif; ?>
			</div>
		<?php
	}

	/**
	 * Fetches Woo data from a Node site.
	 *
	 * @param \Newspack_Network\Node\Node $node The node.
	 * @param string                      $collection_endpoint The collection endpoint.
	 * @param string                      $collection_endpoint_id The collection endpoint ID.
	 * @param array                       $query_args The query args.
	 */
	public static function fetch_collection_from_api( $node, $collection_endpoint, $collection_endpoint_id, $query_args = [] ) {
		$endpoint = add_query_arg( $query_args, sprintf( '%s/wp-json/%s', $node->get_url(), $collection_endpoint ) );
		$response = wp_remote_get( // phpcs:ignore
			$endpoint,
			[
				'headers' => $node->get_authorization_headers( 'get-woo-' . $collection_endpoint_id ),
				'timeout' => 60, // phpcs:ignore
			]
		);
		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			Debugger::log( 'API request for node\'s memberships failed' );
			return null;
		}
		return json_decode( wp_remote_retrieve_body( $response ) );
	}

	/**
	 * Get membership plans from cache.
	 */
	private static function get_membership_plans_from_cache() {
		return get_option( self::OPTIONS_CACHE_KEY_PLANS, false );
	}

	/**
	 * Get membership plans from all nodes.
	 */
	public static function get_membership_plans_from_network() {
		$plans_cache = self::get_membership_plans_from_cache();
		if ( $plans_cache && isset( $plans_cache['plans'] ) ) {
			return $plans_cache;
		}
		$by_network_pass_id = [];
		$membership_plans = [];

		$local_membership_plans = self::get_local_membership_plans();
		foreach ( $local_membership_plans as $local_plan ) {
			if ( $local_plan['network_pass_id'] ) {
				$by_network_pass_id[ $local_plan['network_pass_id'] ][ $local_plan['site_url'] ] = $local_plan['active_members_emails'];
			}
		}
		$membership_plans = array_merge( $local_membership_plans, $membership_plans );

		$nodes = \Newspack_Network\Hub\Nodes::get_all_nodes();
		foreach ( $nodes as $node ) {
			$query_args = [];
			if ( \Newspack_Network\Admin::use_experimental_auditing_features() ) {
				$query_args['include_active_members_emails'] = 1;
			}
			$node_plans = self::fetch_collection_from_api( $node, 'wc/v2/memberships/plans', 'membership-plans', $query_args );
			if ( $node_plans === null ) {
				continue;
			}
			foreach ( $node_plans as $plan ) {
				$network_pass_id = null;
				foreach ( $plan->meta_data as $meta ) {
					if ( $meta->key === \Newspack_Network\Woocommerce_Memberships\Admin::NETWORK_ID_META_KEY ) {
						$network_pass_id = $meta->value;
					}
				}
				if ( $network_pass_id && Network_Admin::use_experimental_auditing_features() ) {
					if ( ! isset( $by_network_pass_id[ $network_pass_id ] ) ) {
						$by_network_pass_id[ $network_pass_id ] = [];
					}
					$by_network_pass_id[ $network_pass_id ][ $node->get_url() ] = $plan->active_members_emails;
				}
				$membership_plans[] = [
					'id'                         => $plan->id,
					'site_url'                   => $node->get_url(),
					'name'                       => $plan->name,
					'network_pass_id'            => $network_pass_id,
					'active_memberships_count'   => $plan->active_memberships_count,
					'active_subscriptions_count' => $plan->active_subscriptions_count,
				];
			}
		}

		$discrepancies_emails = [];
		if ( Network_Admin::use_experimental_auditing_features() ) {
			$discrepancies = [];
			foreach ( $by_network_pass_id as $plan_network_pass_id => $by_site ) {
				$shared_emails = array_intersect( ...array_values( $by_site ) );
				foreach ( $by_site as $site_url => $emails ) {
					$discrepancies[ $plan_network_pass_id ][ $site_url ] = array_diff( $emails, $shared_emails );
				}
			}

			// Get all emails which are discrepant across all sites.
			foreach ( $discrepancies as $plan_network_id => $plan_discrepancies ) {
				foreach ( $plan_discrepancies as $site_url => $plan_site_discrepancies ) {
					$discrepancies_emails = array_merge( $discrepancies_emails, $plan_site_discrepancies );
				}
			}
			$discrepancies_emails = array_unique( $discrepancies_emails );

			$membership_plans = array_map(
				function( $plan ) use ( $discrepancies ) {
					if ( isset(
						$plan['network_pass_id'],
						$discrepancies[ $plan['network_pass_id'] ],
						$discrepancies[ $plan['network_pass_id'] ][ $plan['site_url'] ]
					) ) {
						$plan['network_pass_discrepancies'] = $discrepancies[ $plan['network_pass_id'] ][ $plan['site_url'] ];
					}
					return $plan;
				},
				$membership_plans
			);
		}
		$memberships_data = [
			'plans'                => $membership_plans,
			'discrepancies_emails' => $discrepancies_emails,
			'last_updated'         => time(),
		];
		update_option( self::OPTIONS_CACHE_KEY_PLANS, $memberships_data );
		return $memberships_data;
	}

	/**
	 * Get local membership plans.
	 */
	public static function get_local_membership_plans() {
		$membership_plans = [];
		if ( ! function_exists( 'wc_memberships_get_membership_plans' ) ) {
			return [];
		}
		foreach ( wc_memberships_get_membership_plans() as $plan ) {
			$network_pass_id = get_post_meta( $plan->post->ID, \Newspack_Network\Woocommerce_Memberships\Admin::NETWORK_ID_META_KEY, true );
			$plan_data = [
				'id'                       => $plan->post->ID,
				'site_url'                 => get_site_url(),
				'name'                     => $plan->post->post_title,
				'network_pass_id'          => $network_pass_id,
				'active_memberships_count' => $plan->get_memberships_count( 'active' ),
			];
			if ( Network_Admin::use_experimental_auditing_features() ) {
				$plan_data['active_members_emails'] = \Newspack_Network\Woocommerce_Memberships\Admin::get_active_members_emails( $plan );
				if ( $network_pass_id ) {
					$plan_data['active_subscriptions_count'] = \Newspack_Network\Woocommerce_Memberships\Admin::get_plan_related_active_subscriptions( $plan );
				} else {
					$plan_data['active_subscriptions_count'] = __( 'Only displayed for plans with a Network ID.', 'newspack-network' );
				}
			}
			$membership_plans[] = $plan_data;
		}
		return $membership_plans;
	}
}
