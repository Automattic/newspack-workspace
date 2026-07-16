<?php
/**
 * WP-CLI tooling to assign and verify product Network IDs across a Newspack Network.
 *
 * Access Control's cross-site paid access only grants something when the gate's products
 * carry a product Network ID ( the _newspack_network_product_id postmeta read by
 * \Newspack_Network\Content_Gate\Access ). The only writer in the product UI is a manual
 * per-product metabox, so networks migrated from Woo Memberships routinely end up with
 * healthy membership/subscription sync but zero tagged products, which silently strips
 * network-synced members on flip. This command derives those IDs from the membership plans
 * ( or an explicit operator mapping ) and verifies the synced product map before a flip.
 *
 * @package Newspack
 */

namespace Newspack_Network\CLI;

use Newspack_Network\Site_Role;
use Newspack_Network\Woocommerce\Product_Admin;
use Newspack_Network\Woocommerce_Memberships\Admin as Memberships_Admin;
use Newspack_Network\Incoming_Events\Product_Updated;
use WP_CLI;

/**
 * Product Network ID assignment and verification commands.
 */
class Product_Network_Ids {

	/**
	 * The membership plan postmeta holding the plan's linked product IDs ( set by WooCommerce Memberships ).
	 *
	 * @var string
	 */
	const PLAN_PRODUCT_IDS_META_KEY = '_product_ids';

	/**
	 * Initialize this class and register the WP-CLI commands.
	 *
	 * @return void
	 */
	public static function init() {
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			// These are migration tooling, so they take the migration flow's --apply flag ( dry-run by default )
			// rather than this plugin's older data-* commands' --live flag.
			WP_CLI::add_command( 'newspack-network assign-product-network-ids', [ __CLASS__, 'assign' ] );
			WP_CLI::add_command( 'newspack-network verify-product-network-ids', [ __CLASS__, 'verify' ] );
		}
	}

	/**
	 * Derive product => Network ID assignments from membership plans.
	 *
	 * Each plan carries a shared Network ID ( the same on the matching plan of every network site )
	 * and a list of linked product IDs. Every one of a plan's products should carry the plan's
	 * Network ID so that linked products across sites resolve to the same value. A product listed by
	 * two plans with different Network IDs is ambiguous: it is withheld from the assignments and
	 * reported as a conflict rather than guessed.
	 *
	 * @param array $plans Array of [ 'network_id' => string, 'product_ids' => int[] ].
	 * @return array {
	 *     @type array $assignments Map of product ID => Network ID.
	 *     @type array $conflicts   Map of product ID => list of the distinct Network IDs claiming it.
	 * }
	 */
	public static function derive_assignments_from_plans( array $plans ) {
		$claims = []; // Product ID => list of distinct Network IDs claiming it.
		foreach ( $plans as $plan ) {
			$network_id  = (string) ( $plan['network_id'] ?? '' );
			$product_ids = $plan['product_ids'] ?? [];
			if ( '' === $network_id || empty( $product_ids ) ) {
				continue;
			}
			foreach ( $product_ids as $product_id ) {
				$product_id = (int) $product_id;
				if ( ! isset( $claims[ $product_id ] ) ) {
					$claims[ $product_id ] = [];
				}
				if ( ! in_array( $network_id, $claims[ $product_id ], true ) ) {
					$claims[ $product_id ][] = $network_id;
				}
			}
		}

		$assignments = [];
		$conflicts   = [];
		foreach ( $claims as $product_id => $network_ids ) {
			if ( 1 === count( $network_ids ) ) {
				$assignments[ $product_id ] = $network_ids[0];
			} else {
				$conflicts[ $product_id ] = $network_ids;
			}
		}

		return [
			'assignments' => $assignments,
			'conflicts'   => $conflicts,
		];
	}

	/**
	 * Index the synced network-products option into a per-Network-ID site-presence map.
	 *
	 * @param array $network_products The Product_Updated option value: [ site => [ product_id => [ 'network_id' => ... ] ] ].
	 * @return array [ network_id => [ site => true ] ] -- which sites carry each Network ID. Untagged entries are ignored.
	 */
	public static function index_network_products_by_network_id( array $network_products ) {
		$index = [];
		foreach ( $network_products as $site => $products ) {
			if ( ! is_array( $products ) ) {
				continue;
			}
			foreach ( $products as $product ) {
				if ( ! is_array( $product ) ) {
					continue;
				}
				$network_id = (string) ( $product['network_id'] ?? '' );
				if ( '' === $network_id ) {
					continue;
				}
				$index[ $network_id ][ $site ] = true;
			}
		}
		return $index;
	}

	/**
	 * Verify local product tagging against the synced network-products map.
	 *
	 * A site can only see its own database plus the synced map option, so this checks, per product:
	 * whether it carries a Network ID at all, and whether any *other* site in the map carries the same
	 * Network ID ( without which cross-site access grants nothing ). The current site is excluded from
	 * the linkage count so a product is never counted as "linked to itself": a Hub self-includes its own
	 * products under its own URL key ( Product_Updated::always_process_in_hub ), so without this exclusion
	 * a Hub-only Network ID would read as linked and produce a false "ready to flip". ( A Node has no such
	 * self-entry -- it never receives its own events back -- so the exclusion is simply a no-op there. )
	 *
	 * @param array  $local_products   Map of product ID => Network ID, from local postmeta ( '' for untagged ).
	 * @param array  $network_products The Product_Updated option value.
	 * @param string $current_site     This site's URL ( the key it uses in the map ).
	 * @return array Map of product ID => [ 'network_id' => string, 'linked_sites' => string[] ].
	 */
	public static function verify_products( array $local_products, array $network_products, $current_site ) {
		$index    = self::index_network_products_by_network_id( $network_products );
		$findings = [];

		foreach ( $local_products as $product_id => $network_id ) {
			$product_id = (int) $product_id;

			// Other sites in the map that carry the same Network ID ( excluding this site's own entry -- see the docblock ).
			$linked_sites = [];
			foreach ( array_keys( $index[ $network_id ] ?? [] ) as $site ) {
				if ( $site !== $current_site ) {
					$linked_sites[] = $site;
				}
			}

			$findings[ $product_id ] = [
				'network_id'   => (string) $network_id,
				'linked_sites' => $linked_sites,
			];
		}

		return $findings;
	}

	/**
	 * Assign product Network IDs across the network's products.
	 *
	 * By default IDs are derived from the site's membership plans ( each plan's shared Network ID is
	 * written to every product the plan links ). Pass --map to assign an explicit operator mapping
	 * instead. Runs in dry-run mode unless --apply is given.
	 *
	 * ## OPTIONS
	 *
	 * [--map=<map>]
	 * : Assign an explicit mapping instead of deriving from membership plans. Either an inline JSON
	 * object of { "<product_id>": "<network_id>" } or a path to a file containing that JSON.
	 *
	 * [--overwrite]
	 * : Overwrite a product's existing Network ID when it differs from the derived/mapped value.
	 * By default, existing differing values are left untouched and reported.
	 *
	 * [--apply]
	 * : Write the changes. Without this flag the command only reports what it would do.
	 *
	 * ## EXAMPLES
	 *
	 *     wp newspack-network assign-product-network-ids
	 *     wp newspack-network assign-product-network-ids --apply
	 *     wp newspack-network assign-product-network-ids --map='{"123":"premium","456":"premium"}' --apply
	 *
	 * @param array $args       Positional arguments ( unused ).
	 * @param array $assoc_args Associative arguments.
	 * @return void
	 */
	public static function assign( $args, $assoc_args ) {
		self::require_network_site();

		$apply     = isset( $assoc_args['apply'] );
		$overwrite = isset( $assoc_args['overwrite'] );

		WP_CLI::line( '' );
		if ( $apply ) {
			WP_CLI::line( '⚡️ Running live: product Network IDs will be written.' );
		} else {
			WP_CLI::line( 'Running in dry-run mode. Use --apply to write changes.' );
		}
		WP_CLI::line( '' );

		if ( isset( $assoc_args['map'] ) ) {
			$assignments = self::parse_map( $assoc_args['map'] );
			WP_CLI::line( sprintf( 'Using explicit operator mapping ( %d product(s) ).', count( $assignments ) ) );
		} else {
			$derived     = self::derive_assignments_from_plans( self::get_plans() );
			$assignments = $derived['assignments'];
			WP_CLI::line( sprintf( 'Derived %d assignment(s) from membership plans.', count( $assignments ) ) );
			foreach ( $derived['conflicts'] as $product_id => $network_ids ) {
				WP_CLI::warning(
					sprintf(
						'Product #%d is linked by plans with different Network IDs ( %s ) - skipped. Resolve manually or via --map.',
						$product_id,
						implode( ', ', $network_ids )
					)
				);
			}
		}
		WP_CLI::line( '' );

		if ( empty( $assignments ) ) {
			WP_CLI::warning( 'No assignments to make.' );
			return;
		}

		$skipped  = 0;
		$already  = 0;
		$to_write = 0;
		foreach ( $assignments as $product_id => $network_id ) {
			$product_id = (int) $product_id;
			// Sanitize at the write path so the meta is consistent whatever the source ( plan meta or --map ),
			// matching the product metabox's sanitize_text_field(). Done before the preview so dry-run matches apply.
			$network_id = sanitize_text_field( (string) $network_id );

			$post_type = get_post_type( $product_id );
			if ( 'product' !== $post_type ) {
				// The Network ID lives on the parent product; variations resolve to it via Product_Admin::get_network_id.
				$reason = 'product_variation' === $post_type ? 'a product variation ( set the Network ID on its parent product )' : 'not a product';
				WP_CLI::warning( sprintf( 'Skipping #%d: %s.', $product_id, $reason ) );
				$skipped++;
				continue;
			}

			$current = (string) get_post_meta( $product_id, Product_Admin::NETWORK_ID_META_KEY, true );
			if ( $current === (string) $network_id ) {
				WP_CLI::line( sprintf( '  #%d already set to "%s".', $product_id, $network_id ) );
				$already++;
				continue;
			}
			if ( '' !== $current && ! $overwrite ) {
				WP_CLI::warning(
					sprintf( 'Skipping #%d: already set to "%s" ( would become "%s" ). Use --overwrite to change.', $product_id, $current, $network_id )
				);
				$skipped++;
				continue;
			}

			WP_CLI::line( sprintf( '  #%d "%s" => "%s"', $product_id, $current, $network_id ) );
			$to_write++;

			if ( $apply ) {
				update_post_meta( $product_id, Product_Admin::NETWORK_ID_META_KEY, $network_id );
				// Fire the same action the product metabox fires, so the existing emitter propagates the change.
				do_action( 'newspack_network_save_product', $product_id );
			}
		}

		WP_CLI::line( '' );
		if ( $apply ) {
			WP_CLI::success( sprintf( 'Wrote %d product Network ID(s), skipped %d, %d already set.', $to_write, $skipped, $already ) );
			WP_CLI::line( 'If propagation did not complete ( e.g. the Data Events listener was unavailable ), replay it with:' );
			WP_CLI::line( '  wp newspack-network data-backfill newspack_network_product_updated --live' );
		} else {
			WP_CLI::success( sprintf( 'Dry run: %d product Network ID(s) would be written, %d skipped, %d already set. Re-run with --apply.', $to_write, $skipped, $already ) );
		}
		WP_CLI::line( '' );
	}

	/**
	 * Verify that this site's products resolve to a Network ID and are linked across the network.
	 *
	 * Runs per-site: it can only read this site's database and the synced network-products map option.
	 * It reports, for each checked product, whether it carries a Network ID and whether any other site
	 * shares that ID ( the NPPD-2057 failure is a product tagged with a Network ID no other site carries,
	 * or not tagged at all, so the cross-site grant resolves to nothing ). Run it on every site: each
	 * site's linkage check confirms the other sites' products are present in its synced map.
	 *
	 * Limitation: the synced products option is append-only ( there is no product-deleted listener ), so a
	 * stale entry for a since-removed product or site can still report as "linked" -- the same class of
	 * caveat as relying on the site's URL staying byte-identical as the map key.
	 *
	 * ## OPTIONS
	 *
	 * [--products=<ids>]
	 * : Comma-separated product IDs to check ( e.g. a gate's products ). Defaults to every local product
	 * that carries a Network ID. To gate a flip, pass the gate's product IDs: the bare form only looks at
	 * already-tagged products, so a site with zero tagged products ( the NPPD-2057 failure ) passes it.
	 *
	 * ## EXAMPLES
	 *
	 *     wp newspack-network verify-product-network-ids
	 *     wp newspack-network verify-product-network-ids --products=123,456
	 *
	 * @param array $args       Positional arguments ( unused ).
	 * @param array $assoc_args Associative arguments.
	 * @return void
	 */
	public static function verify( $args, $assoc_args ) {
		self::require_network_site();

		$current_site     = get_bloginfo( 'url' );
		$network_products = get_option( Product_Updated::OPTION_NAME, [] );
		if ( ! is_array( $network_products ) ) {
			$network_products = [];
		}

		$explicit_products = isset( $assoc_args['products'] );
		$local_products    = self::get_products_to_check(
			$explicit_products ? wp_parse_id_list( $assoc_args['products'] ) : null
		);

		WP_CLI::line( '' );
		WP_CLI::line( sprintf( 'Verifying product Network IDs for %s.', $current_site ) );
		WP_CLI::line( 'Checked: whether this site\'s products carry a Network ID, and whether other sites in the synced map share it.' );
		WP_CLI::line( 'Not checked from here: other sites\' databases ( only their synced map entries are visible ). Run this on every site.' );
		WP_CLI::line( '' );

		if ( empty( $local_products ) ) {
			WP_CLI::warning( 'No products carry a Network ID on this site. Cross-site paid access will grant nothing here. Run assign-product-network-ids first.' );
			return;
		}

		$findings = self::verify_products( $local_products, $network_products, $current_site );
		$untagged = 0;
		$unlinked = 0;
		foreach ( $findings as $product_id => $finding ) {
			// An untagged product ( only reachable when passed explicitly via --products ) is the NPPD-2057 failure itself.
			if ( '' === $finding['network_id'] ) {
				WP_CLI::warning( sprintf( '#%d: no Network ID set ( cross-site access grants nothing ). Run assign-product-network-ids.', $product_id ) );
				$untagged++;
				continue;
			}

			if ( empty( $finding['linked_sites'] ) ) {
				WP_CLI::warning(
					sprintf( '#%d "%s": no other site carries this Network ID ( cross-site access grants nothing ).', $product_id, $finding['network_id'] )
				);
				$unlinked++;
			} else {
				WP_CLI::line(
					sprintf( '  ✓ #%d "%s" linked on: %s', $product_id, $finding['network_id'], implode( ', ', $finding['linked_sites'] ) )
				);
			}
		}

		WP_CLI::line( '' );
		WP_CLI::line( sprintf( 'Checked %d product(s): %d untagged, %d unlinked.', count( $findings ), $untagged, $unlinked ) );
		if ( $unlinked > 0 ) {
			WP_CLI::line( 'For unlinked products, make sure every site has run assign-product-network-ids and its product_updated events have propagated:' );
			WP_CLI::line( '  wp newspack-network data-backfill newspack_network_product_updated --live' );
		}
		if ( $untagged > 0 || $unlinked > 0 ) {
			// Exit non-zero so callers can gate a flip on this check.
			WP_CLI::error( 'Verification found issues ( see above ). Not ready to flip.' );
		}
		// A green run proves each product is linked to at least one other visible site, not the full mesh:
		// this site cannot see whether every other site received its products. Run on every site for full coverage.
		WP_CLI::success( 'All checked products carry a Network ID and are linked to at least one other site ( see the listed sites above ).' );
	}

	/**
	 * Ensure the command runs on a Hub or Node site, erroring out otherwise.
	 *
	 * @return void
	 */
	private static function require_network_site() {
		if ( ! Site_Role::is_hub() && ! Site_Role::is_node() ) {
			WP_CLI::error( 'This command can only be run on a Hub or Node site.' );
		}
	}

	/**
	 * Read the site's membership plans as [ 'network_id' => string, 'product_ids' => int[] ] rows.
	 *
	 * Uses raw postmeta ( not the WooCommerce Memberships plan object ) so it keeps working after the
	 * plugin is deactivated during a migration.
	 *
	 * @return array
	 */
	private static function get_plans() {
		$plan_posts = get_posts(
			[
				'post_type'   => Memberships_Admin::MEMBERSHIP_PLANS_CPT,
				'post_status' => 'any',
				'numberposts' => -1,
				'fields'      => 'ids',
			]
		);

		$plans = [];
		foreach ( $plan_posts as $plan_id ) {
			$product_ids = get_post_meta( $plan_id, self::PLAN_PRODUCT_IDS_META_KEY, true );
			$plans[]     = [
				'network_id'  => (string) get_post_meta( $plan_id, Memberships_Admin::NETWORK_ID_META_KEY, true ),
				'product_ids' => is_array( $product_ids ) ? $product_ids : [],
			];
		}
		return $plans;
	}

	/**
	 * Read the products to verify as a product ID => Network ID map.
	 *
	 * With an explicit list ( e.g. a gate's products ) every ID is returned, including untagged ones
	 * ( Network ID '' ) so verify can flag them as the failure they are. With null, only products that
	 * already carry a Network ID are returned.
	 *
	 * @param array|null $product_ids Explicit product IDs to look up; null reads every tagged product.
	 * @return array
	 */
	private static function get_products_to_check( $product_ids = null ) {
		if ( null !== $product_ids ) {
			$products = [];
			foreach ( $product_ids as $product_id ) {
				$products[ (int) $product_id ] = Product_Admin::get_network_id( $product_id );
			}
			return $products;
		}

		$tagged_ids = get_posts(
			[
				'post_type'   => 'product',
				'post_status' => 'any',
				'numberposts' => -1,
				'fields'      => 'ids',
				'meta_query'  => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					[
						'key'     => Product_Admin::NETWORK_ID_META_KEY,
						'compare' => '!=',
						'value'   => '',
					],
				],
			]
		);

		// The meta_query already excludes empty/absent Network IDs, so every ID here is tagged.
		$tagged = [];
		foreach ( $tagged_ids as $product_id ) {
			$tagged[ (int) $product_id ] = Product_Admin::get_network_id( $product_id );
		}
		return $tagged;
	}

	/**
	 * Parse the --map value ( inline JSON or a path to a JSON file ) into a product ID => Network ID map.
	 *
	 * @param string $map The raw --map argument.
	 * @return array
	 */
	private static function parse_map( $map ) {
		if ( is_string( $map ) && is_readable( $map ) ) {
			$map = file_get_contents( $map ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents, WordPressVIPMinimum.Performance.FetchingRemoteData.FileGetContentsUnknown
		}
		$decoded = json_decode( (string) $map, true );
		if ( ! is_array( $decoded ) ) {
			WP_CLI::error( 'Invalid --map: expected a JSON object of { product_id: network_id } or a path to such a file.' );
		}

		$assignments = [];
		foreach ( $decoded as $product_id => $network_id ) {
			if ( ! is_scalar( $network_id ) ) {
				WP_CLI::warning( sprintf( 'Skipping product #%d in --map: Network ID must be a string.', (int) $product_id ) );
				continue;
			}
			$network_id = sanitize_text_field( (string) $network_id );
			if ( '' === $network_id ) {
				WP_CLI::warning( sprintf( 'Skipping product #%d in --map: empty Network ID.', (int) $product_id ) );
				continue;
			}
			$assignments[ (int) $product_id ] = $network_id;
		}
		return $assignments;
	}
}
