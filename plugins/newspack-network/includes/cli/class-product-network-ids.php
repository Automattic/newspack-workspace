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
use Newspack_Network\Hub\Nodes as Hub_Nodes;
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
	 * How many product writes to make before flushing the Data Events dispatch queue.
	 *
	 * Data_Events::dispatch() only queues; the queue is drained on shutdown. Flushing periodically
	 * keeps the queued payloads from accumulating in memory for the whole run and means a run that
	 * dies mid-way has already propagated everything up to the last flush.
	 *
	 * @var int
	 */
	const DISPATCH_FLUSH_INTERVAL = 100;

	/**
	 * How many posts to prime the meta cache for at a time.
	 *
	 * Priming loads every meta row of every listed post, so it is chunked to bound peak memory on
	 * stores with many products.
	 *
	 * @var int
	 */
	const META_CACHE_CHUNK_SIZE = 500;

	/**
	 * Initialize this class and register hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'init', [ __CLASS__, 'register_commands' ] );
	}

	/**
	 * Register the WP-CLI commands.
	 *
	 * @return void
	 */
	public static function register_commands() {
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
	 * Plan Network IDs are sanitized here, before the emptiness check, so a whitespace-only ( or
	 * markup-only ) plan value is treated as "no Network ID" rather than surviving as an assignment
	 * that later sanitizes down to '' and blanks a product.
	 *
	 * @param array $plans Array of [ 'network_id' => string, 'product_ids' => int[] ].
	 * @return array {
	 *     @type array $assignments Map of product ID => Network ID.
	 *     @type array $conflicts   Map of product ID => list of the distinct Network IDs claiming it.
	 *     @type array $stats       Per-plan diagnostics: total, without_network_id, without_products.
	 * }
	 */
	public static function derive_assignments_from_plans( array $plans ) {
		$claims             = []; // Product ID => list of distinct Network IDs claiming it.
		$without_network_id = 0;
		$without_products   = 0;

		foreach ( $plans as $plan ) {
			$network_id  = sanitize_text_field( (string) ( $plan['network_id'] ?? '' ) );
			$product_ids = $plan['product_ids'] ?? [];
			if ( '' === $network_id ) {
				$without_network_id++;
			}
			if ( empty( $product_ids ) ) {
				$without_products++;
			}
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
			'stats'       => [
				'total'              => count( $plans ),
				'without_network_id' => $without_network_id,
				'without_products'   => $without_products,
			],
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
	 * Being linked to *some* other site is a weaker guarantee than access needs: a reader is only
	 * granted access from the specific site their subscription lives on, so a product linked hub<->node1
	 * grants nothing to a node2 subscriber. Pass $expected_sites ( the network's known sites ) to also
	 * get, per product, which of them are missing the Network ID -- that is what an operator has to act
	 * on, and what --expect-sites gates the exit code on.
	 *
	 * @param array  $local_products   Map of product ID => Network ID, from local postmeta ( '' for untagged ).
	 * @param array  $network_products The Product_Updated option value.
	 * @param string $current_site     This site's URL ( the key it uses in the map ).
	 * @param array  $expected_sites   Site URLs the network is known to contain, excluding this one. Empty when unknown.
	 * @return array Map of product ID => [ 'network_id' => string, 'linked_sites' => string[], 'missing_sites' => string[] ].
	 */
	public static function verify_products( array $local_products, array $network_products, $current_site, array $expected_sites = [] ) {
		$index    = self::index_network_products_by_network_id( $network_products );
		$findings = [];

		foreach ( $local_products as $product_id => $network_id ) {
			$product_id = (int) $product_id;

			// Other sites in the map that carry the same Network ID ( excluding this site's own entry -- see the docblock ).
			$linked_sites = [];
			foreach ( array_keys( $index[ $network_id ] ?? [] ) as $site ) {
				if ( self::normalize_site_url( $site ) !== self::normalize_site_url( $current_site ) ) {
					$linked_sites[] = $site;
				}
			}

			// Known network sites whose products never arrived carrying this ID. Compared on the
			// normalized URL: the map is keyed by each site's own get_bloginfo( 'url' ), while the
			// expected list comes from the Nodes CPT, so the two can differ by a trailing slash.
			$linked_lookup = [];
			foreach ( $linked_sites as $site ) {
				$linked_lookup[ self::normalize_site_url( $site ) ] = true;
			}
			$missing_sites = [];
			if ( '' !== (string) $network_id ) {
				foreach ( $expected_sites as $expected_site ) {
					if ( ! isset( $linked_lookup[ self::normalize_site_url( $expected_site ) ] ) ) {
						$missing_sites[] = $expected_site;
					}
				}
			}

			$findings[ $product_id ] = [
				'network_id'    => (string) $network_id,
				'linked_sites'  => $linked_sites,
				'missing_sites' => $missing_sites,
			];
		}

		return $findings;
	}

	/**
	 * Normalize a site URL for comparison between the synced map's keys and the Nodes CPT's URLs.
	 *
	 * @param string $url The site URL.
	 * @return string
	 */
	private static function normalize_site_url( $url ) {
		return untrailingslashit( strtolower( trim( (string) $url ) ) );
	}

	/**
	 * The other sites this network is known to contain.
	 *
	 * Only a Hub knows the network's membership ( the Nodes CPT ); a Node can see nothing but its own
	 * synced map, which is why --expect-sites also takes a plain count.
	 *
	 * @return string[] Node URLs, or an empty array when the membership is not knowable here.
	 */
	private static function get_known_network_sites() {
		if ( ! Site_Role::is_hub() ) {
			return [];
		}
		$urls = [];
		foreach ( Hub_Nodes::get_all_nodes() as $node ) {
			$url = untrailingslashit( (string) $node->get_url() );
			if ( '' !== $url ) {
				$urls[ self::normalize_site_url( $url ) ] = $url;
			}
		}
		// Sorted so the reported ( and JSON ) site list is stable across runs rather than following the
		// Nodes CPT's post order, which makes two sites' reports diffable.
		ksort( $urls );
		return array_values( $urls );
	}

	/**
	 * Assign product Network IDs across the network's products.
	 *
	 * By default IDs are derived from the site's membership plans ( each plan's shared Network ID is
	 * written to every product the plan links ). Pass --map to assign an explicit operator mapping
	 * instead. Runs in dry-run mode unless --apply is given.
	 *
	 * Exits non-zero when anything could not be assigned ( a plan carrying no Network ID, a product
	 * claimed by two plans with different IDs, a product already carrying a different ID without
	 * --overwrite ), so a scripted migration cannot record a green step for a partial run.
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
	 * [--repropagate]
	 * : Also emit a product_updated event for products that already carry the target Network ID.
	 * Use this to re-sync an already-tagged but desynced product, which otherwise fires no event.
	 *
	 * [--apply]
	 * : Write the changes. Without this flag the command only reports what it would do.
	 *
	 * ## EXAMPLES
	 *
	 *     wp newspack-network assign-product-network-ids
	 *     wp newspack-network assign-product-network-ids --apply
	 *     wp newspack-network assign-product-network-ids --map='{"123":"premium","456":"premium"}' --apply
	 *     wp newspack-network assign-product-network-ids --apply --repropagate
	 *
	 * @param array $args       Positional arguments ( unused ).
	 * @param array $assoc_args Associative arguments.
	 * @return void
	 */
	public static function assign( $args, $assoc_args ) {
		self::require_network_site();

		$apply       = (bool) \WP_CLI\Utils\get_flag_value( $assoc_args, 'apply', false );
		$overwrite   = (bool) \WP_CLI\Utils\get_flag_value( $assoc_args, 'overwrite', false );
		$repropagate = (bool) \WP_CLI\Utils\get_flag_value( $assoc_args, 'repropagate', false );
		$map         = \WP_CLI\Utils\get_flag_value( $assoc_args, 'map', null );

		// Writing the meta only propagates across the network through the newspack_network_product_updated
		// listener, which registers only when Newspack\Data_Events is loaded and emits only when wc_get_product
		// is available. With either missing the meta is written locally and nothing syncs -- surface that up
		// front so "Wrote N" is never mistaken for "propagated", which is the NPPD-2057 flip failure.
		$can_propagate = class_exists( 'Newspack\Data_Events' ) && function_exists( 'wc_get_product' );

		WP_CLI::line( '' );
		if ( $apply ) {
			WP_CLI::line( '⚡️ Running live: product Network IDs will be written.' );
		} else {
			WP_CLI::line( 'Running in dry-run mode. Use --apply to write changes.' );
		}
		WP_CLI::line( '' );

		if ( ! $can_propagate ) {
			WP_CLI::warning(
				'Cross-site propagation is unavailable here ( newspack_network_product_updated needs Newspack\\Data_Events and WooCommerce active ). Network IDs will be written to this site only; once both are active, re-run this command with --apply --repropagate to emit the events.'
			);
			WP_CLI::line( '' );
		}

		// Everything this command cannot resolve. Reported per item below and rolled into a non-zero
		// exit at the end so "assign, then verify" cannot record a green step on a partial run.
		$unresolved  = 0;
		$plans_found = 0;

		if ( null !== $map ) {
			$parsed      = self::parse_map( $map );
			$assignments = $parsed['assignments'];
			// An entry the operator wrote but this command could not use is a product they believe is
			// covered and which grants nothing -- it has to reach the non-zero exit like any other
			// withheld item, not just scroll past as a warning.
			$unresolved += $parsed['skipped'];
			WP_CLI::line( sprintf( 'Using explicit operator mapping ( %d product(s) ).', count( $assignments ) ) );
		} else {
			$plans       = self::get_plans();
			$plans_found = count( $plans );
			$derived     = self::derive_assignments_from_plans( $plans );
			$assignments = $derived['assignments'];
			$stats       = $derived['stats'];

			WP_CLI::line(
				sprintf(
					'Found %d membership plan(s): %d carry no Network ID, %d link no products.',
					$stats['total'],
					$stats['without_network_id'],
					$stats['without_products']
				)
			);
			WP_CLI::line( sprintf( 'Derived %d assignment(s) from membership plans.', count( $assignments ) ) );

			// A plan nobody ever tagged is the NPPD-2057 field condition, and this is the only place in
			// the system positioned to see it -- so it is reported, not silently skipped.
			if ( $stats['without_network_id'] > 0 ) {
				WP_CLI::warning(
					sprintf(
						'%d membership plan(s) carry no Network ID, so their products cannot be derived. Set the plan Network ID on every site ( the same value on matching plans ), or assign the products via --map.',
						$stats['without_network_id']
					)
				);
				$unresolved += $stats['without_network_id'];
			}

			foreach ( $derived['conflicts'] as $product_id => $network_ids ) {
				WP_CLI::warning(
					sprintf(
						'Product #%d is linked by plans with different Network IDs ( %s ) - skipped. Resolve manually or via --map.',
						$product_id,
						implode( ', ', $network_ids )
					)
				);
				$unresolved++;
			}
		}
		WP_CLI::line( '' );

		// One query for the whole working set: the loop below reads the post type and the Network ID
		// meta of every assignment, which would otherwise be two DB round trips per product.
		self::prime_post_caches( array_keys( $assignments ) );

		// A plan may link variation IDs; the Network ID lives on the parent product ( that is where
		// Product_Admin::get_network_id resolves it from ), so fold them in rather than skipping them.
		$folded      = self::fold_variations_into_parents( $assignments );
		$assignments = $folded['assignments'];
		foreach ( $folded['conflicts'] as $product_id => $network_ids ) {
			WP_CLI::warning(
				sprintf(
					'Product #%d is claimed with different Network IDs ( %s ) once its variations are resolved to it - skipped. Resolve manually or via --map.',
					$product_id,
					implode( ', ', $network_ids )
				)
			);
			$unresolved++;
		}

		if ( empty( $assignments ) ) {
			WP_CLI::warning( 'No assignments to make.' );
			if ( $plans_found > 0 || null !== $map || $unresolved > 0 ) {
				// Plans ( or a map ) exist but nothing could be derived from them: a no-op here reads as
				// "already migrated" if it exits 0, so fail instead.
				WP_CLI::error( 'Nothing could be assigned. Cross-site paid access will grant nothing here.' );
			}
			return;
		}

		$skipped      = 0;
		$already      = 0;
		$to_write     = 0;
		$inapplicable = 0;
		$dispatched   = 0;
		foreach ( $assignments as $product_id => $network_id ) {
			$product_id = (int) $product_id;
			// Sanitize at the write path so the meta is consistent whatever the source ( plan meta or --map ),
			// matching the product metabox's sanitize_text_field(). Done before the preview so dry-run matches apply.
			$network_id = sanitize_text_field( (string) $network_id );

			// Never write a blank Network ID: it is indistinguishable from "untagged", and with --overwrite
			// it would replace a correct value and propagate the blank to every site.
			if ( '' === $network_id ) {
				WP_CLI::warning( sprintf( 'Skipping #%d: the Network ID is empty - refusing to write a blank value.', $product_id ) );
				$skipped++;
				continue;
			}

			if ( 'product' !== get_post_type( $product_id ) ) {
				WP_CLI::warning( sprintf( 'Skipping #%d: not a product.', $product_id ) );
				$skipped++;
				continue;
			}

			// Access only ever grants from a reader's synced subscriptions, and the product metabox only
			// writes for these types, so tagging anything else would just bloat every site's synced map.
			if ( ! self::is_taggable_product( $product_id ) ) {
				WP_CLI::line( sprintf( '  #%d is not a subscription product; a Network ID would grant nothing - left alone.', $product_id ) );
				$inapplicable++;
				continue;
			}

			$current = (string) get_post_meta( $product_id, Product_Admin::NETWORK_ID_META_KEY, true );
			if ( $current === $network_id ) {
				WP_CLI::line( sprintf( '  #%d already set to "%s".', $product_id, $network_id ) );
				$already++;
				if ( $apply && $repropagate ) {
					do_action( 'newspack_network_save_product', $product_id );
					$dispatched++;
					self::maybe_flush_dispatch_queue( $dispatched );
				}
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
				$dispatched++;
				self::maybe_flush_dispatch_queue( $dispatched );
			}
		}
		$unresolved += $skipped;

		if ( $dispatched > 0 ) {
			self::flush_dispatch_queue();
		}

		WP_CLI::line( '' );
		if ( $apply ) {
			WP_CLI::line(
				sprintf(
					'Wrote %d product Network ID(s), skipped %d, %d already set, %d not applicable.',
					$to_write,
					$skipped,
					$already,
					$inapplicable
				)
			);
			if ( ! $can_propagate ) {
				WP_CLI::warning( 'These writes were NOT propagated ( see the warning above ). Once Data Events and WooCommerce are active, re-run with --apply --repropagate to emit the events.' );
			} elseif ( ! $repropagate ) {
				// A product already carrying the target Network ID fires no event, so a plain run cannot
				// re-sync an already-tagged-but-desynced product.
				WP_CLI::line( 'Products already carrying the target Network ID fired no event. To re-emit for every assigned product, re-run with --apply --repropagate.' );
				WP_CLI::line( 'Note: "data-backfill newspack_network_product_updated --live" is not a reliable replay for this - on a Hub it dedupes against the event log on the product\'s post_modified_gmt timestamp, which writing this meta does not change.' );
			}
		} else {
			WP_CLI::line(
				sprintf(
					'Dry run: %d product Network ID(s) would be written, %d skipped, %d already set, %d not applicable.',
					$to_write,
					$skipped,
					$already,
					$inapplicable
				)
			);
		}
		WP_CLI::line( '' );

		if ( $unresolved > 0 ) {
			// Exit non-zero so a scripted migration cannot treat a partial assignment as done: everything
			// withheld here is a product ( or plan ) that will grant nothing across the network.
			WP_CLI::error(
				sprintf(
					'%d item(s) could not be assigned ( see the warnings above ). Resolve them ( e.g. via --map ) and re-run; verify-product-network-ids will fail until they are.',
					$unresolved
				)
			);
		}

		if ( $apply ) {
			WP_CLI::success( 'Every derived product Network ID is assigned.' );
		} else {
			WP_CLI::success( 'Dry run complete: every derived product Network ID can be assigned. Re-run with --apply.' );
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
	 * The default product set is every product the site's membership plans link, plus every product that
	 * already carries a Network ID -- not just the tagged ones. Checking only tagged products would hide
	 * exactly what assign-product-network-ids withholds ( conflicts, skipped products ), so a network
	 * that assign could not finish would still verify green.
	 *
	 * By default a product passes once *any* other site carries its Network ID, which is weaker than what
	 * access needs: a reader is only granted from the specific site their subscription lives on, so a
	 * product linked Hub<->node1 grants nothing to a node2 subscriber. Pass --expect-sites to gate the
	 * exit code on real coverage -- "all" ( on a Hub, every registered Node ) or an explicit count.
	 *
	 * Limitation: the synced products option is append-only ( there is no product-deleted listener ), so a
	 * stale entry for a since-removed product or site can still report as "linked" -- the same class of
	 * caveat as relying on the site's URL staying byte-identical as the map key.
	 *
	 * ## OPTIONS
	 *
	 * [--products=<ids>]
	 * : Comma-separated product IDs to check ( e.g. a gate's products ). Defaults to every product linked
	 * by a membership plan plus every product that already carries a Network ID. Pass a gate's product IDs
	 * to check exactly what the gate depends on.
	 *
	 * [--expect-sites=<count|all>]
	 * : How many *other* sites each product must be linked on to pass. "all" requires every Node registered
	 * on this Hub ( unavailable on a Node, which cannot read the network's membership -- pass a count there ).
	 * Defaults to 1, which only proves the product is linked somewhere.
	 *
	 * [--format=<format>]
	 * : Output format. "json" prints a single machine-readable report ( per-product findings plus the summary
	 * and the pass/fail verdict ) instead of the human-readable log, for aggregating this per-site gate
	 * across a network. The exit code is the same either way.
	 * ---
	 * default: human
	 * options:
	 *   - human
	 *   - json
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp newspack-network verify-product-network-ids
	 *     wp newspack-network verify-product-network-ids --products=123,456
	 *     wp newspack-network verify-product-network-ids --expect-sites=all
	 *     wp newspack-network verify-product-network-ids --expect-sites=3 --format=json
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

		$format = (string) \WP_CLI\Utils\get_flag_value( $assoc_args, 'format', 'human' );
		if ( ! in_array( $format, [ 'human', 'json' ], true ) ) {
			WP_CLI::error( sprintf( 'Invalid --format "%s": expected "human" or "json".', $format ) );
		}
		$is_json = 'json' === $format;

		$known_sites = self::get_known_network_sites();
		$expectation = self::parse_expected_sites( \WP_CLI\Utils\get_flag_value( $assoc_args, 'expect-sites', null ), $known_sites );

		$product_ids = null;
		if ( isset( $assoc_args['products'] ) ) {
			$product_ids = wp_parse_id_list( $assoc_args['products'] );
			if ( empty( $product_ids ) ) {
				// An unset shell variable ( --products="$GATE_PRODUCTS" ) must never read as "nothing to
				// check, all good": this is the path a scripted flip gate runs.
				WP_CLI::error( '--products was passed but no product IDs could be parsed from it. Pass a comma-separated list of product IDs, or omit --products to check every plan-linked and tagged product.' );
			}
		}
		$local_products = self::get_products_to_check( $product_ids );

		if ( ! $is_json ) {
			WP_CLI::line( '' );
			WP_CLI::line( sprintf( 'Verifying product Network IDs for %s.', $current_site ) );
			WP_CLI::line( 'Checked: whether this site\'s products carry a Network ID, and whether other sites in the synced map share it.' );
			WP_CLI::line( 'Not checked from here: other sites\' databases ( only their synced map entries are visible ). Run this on every site.' );
			if ( ! empty( $known_sites ) ) {
				WP_CLI::line( sprintf( 'Network sites registered on this Hub: %s.', implode( ', ', $known_sites ) ) );
			}
			WP_CLI::line( sprintf( 'Passing requires each product to be linked on %s.', $expectation['label'] ) );
			WP_CLI::line( '' );
		}

		if ( empty( $local_products ) ) {
			WP_CLI::error( 'No products to check: no membership plan links a subscription product and no product carries a Network ID. Cross-site paid access will grant nothing here. Run assign-product-network-ids first, or pass --products with the gate\'s products.' );
		}

		$findings = self::verify_products( $local_products, $network_products, $current_site, $known_sites );
		$untagged = 0;
		$unlinked = 0;
		$report   = [];
		foreach ( $findings as $product_id => $finding ) {
			$linked_sites  = $finding['linked_sites'];
			$missing_sites = $finding['missing_sites'];

			if ( '' === $finding['network_id'] ) {
				$untagged++;
				$report[] = self::build_verify_report_row( $product_id, $finding, 'untagged' );
				if ( ! $is_json ) {
					WP_CLI::warning( sprintf( '#%d: no Network ID set ( cross-site access grants nothing ). Run assign-product-network-ids.', $product_id ) );
				}
				continue;
			}

			$passes = $expectation['require_all'] ? empty( $missing_sites ) : count( $linked_sites ) >= $expectation['minimum'];
			if ( ! $passes ) {
				$unlinked++;
				$report[] = self::build_verify_report_row( $product_id, $finding, 'unlinked' );
				if ( ! $is_json ) {
					WP_CLI::warning( self::describe_unlinked_product( $product_id, $finding, $expectation ) );
				}
				continue;
			}

			$report[] = self::build_verify_report_row( $product_id, $finding, 'ok' );
			if ( ! $is_json ) {
				WP_CLI::line(
					sprintf( '  ✓ #%d "%s" linked on: %s', $product_id, $finding['network_id'], implode( ', ', $linked_sites ) )
				);
				// A pass under the default expectation still leaves known sites uncovered; those are what an
				// operator has to act on, so name them even though they did not fail the run.
				if ( ! empty( $missing_sites ) ) {
					WP_CLI::line(
						sprintf( '    not carried by: %s ( readers subscribing there are granted nothing ).', implode( ', ', $missing_sites ) )
					);
				}
			}
		}

		$has_issues = $untagged > 0 || $unlinked > 0;

		if ( $is_json ) {
			WP_CLI::line(
				(string) wp_json_encode(
					[
						'site'          => $current_site,
						'expect_sites'  => $expectation['require_all'] ? 'all' : $expectation['minimum'],
						'known_sites'   => $known_sites,
						'checked'       => count( $findings ),
						'untagged'      => $untagged,
						'unlinked'      => $unlinked,
						'ready_to_flip' => ! $has_issues,
						'products'      => $report,
					]
				)
			);
		} else {
			WP_CLI::line( '' );
			WP_CLI::line( sprintf( 'Checked %d product(s): %d untagged, %d unlinked.', count( $findings ), $untagged, $unlinked ) );
			if ( $unlinked > 0 ) {
				WP_CLI::line( 'For unlinked products, make sure every site has run assign-product-network-ids, then re-emit the events from those sites with:' );
				WP_CLI::line( '  wp newspack-network assign-product-network-ids --apply --repropagate' );
			}
		}

		if ( $has_issues ) {
			// Exit non-zero so callers can gate a flip on this check.
			WP_CLI::error( 'Verification found issues ( see above ). Not ready to flip.' );
		}

		if ( $is_json ) {
			// JSON mode prints exactly one line, the report above, on both the passing and the failing
			// path ( WP_CLI::error writes to stderr ), so an aggregator can json_decode stdout as-is.
			// The verdict is carried by the exit code and by the report's own 'ready_to_flip'.
			return;
		}

		WP_CLI::success( sprintf( 'All checked products carry a Network ID and are linked on %s.', $expectation['label'] ) );
		if ( ! $expectation['require_all'] && 1 === $expectation['minimum'] ) {
			// Being linked *somewhere* is not the guarantee operators read into a green run: access is only
			// granted from the site a reader's own subscription lives on.
			WP_CLI::line( 'Note: this only proves each product is linked somewhere, not that every site a reader may subscribe on carries the ID. Re-run with --expect-sites=all ( on the Hub ) or --expect-sites=<count> to gate on real coverage.' );
		}
	}

	/**
	 * Resolve --expect-sites into the coverage each product must have to pass.
	 *
	 * @param mixed $raw         The raw flag value: null, "all", or a positive integer.
	 * @param array $known_sites The network's other known sites ( empty when not knowable here ).
	 * @return array {
	 *     @type bool   $require_all Whether every known site must carry the Network ID.
	 *     @type int    $minimum     The minimum number of other linked sites, when not requiring all.
	 *     @type string $label       Human description of the requirement.
	 * }
	 */
	private static function parse_expected_sites( $raw, array $known_sites ) {
		if ( null === $raw ) {
			return [
				'require_all' => false,
				'minimum'     => 1,
				'label'       => 'at least 1 other site',
			];
		}

		// A bare --expect-sites ( or --no-expect-sites ) arrives from WP-CLI as a boolean. (string) true
		// is '1', which would pass the integer guard below and silently resolve to the 1-site floor: a
		// green run for an operator who meant --expect-sites=all and fumbled the syntax.
		if ( is_bool( $raw ) ) {
			WP_CLI::error( '--expect-sites needs a value: pass "all" or a positive integer ( e.g. --expect-sites=all or --expect-sites=3 ). Omit the flag entirely for the default of 1 other site.' );
		}

		if ( 'all' === $raw ) {
			if ( empty( $known_sites ) ) {
				// A Node cannot read the network's membership, so "all" there would silently mean "any".
				WP_CLI::error( '--expect-sites=all needs the network\'s registered Nodes, which are only readable on a Hub with Nodes registered. Run it on the Hub, or pass an explicit count ( e.g. --expect-sites=3 ).' );
			}
			return [
				'require_all' => true,
				'minimum'     => count( $known_sites ),
				'label'       => sprintf( 'every registered network site ( %s )', implode( ', ', $known_sites ) ),
			];
		}

		if ( ! preg_match( '/^[1-9][0-9]*$/', (string) $raw ) ) {
			WP_CLI::error( 'Invalid --expect-sites: pass "all" or a positive integer ( how many other sites each product must be linked on ).' );
		}

		return [
			'require_all' => false,
			'minimum'     => (int) $raw,
			'label'       => sprintf( 'at least %d other site(s)', (int) $raw ),
		];
	}

	/**
	 * Build one product's row of the machine-readable verify report.
	 *
	 * @param int    $product_id The product ID.
	 * @param array  $finding    The verify_products() finding.
	 * @param string $status     One of 'ok', 'untagged', 'unlinked'.
	 * @return array
	 */
	private static function build_verify_report_row( $product_id, array $finding, $status ) {
		return [
			'id'            => (int) $product_id,
			'network_id'    => $finding['network_id'],
			'status'        => $status,
			'linked_sites'  => array_values( $finding['linked_sites'] ),
			'missing_sites' => array_values( $finding['missing_sites'] ),
		];
	}

	/**
	 * The warning for a product that did not meet the coverage requirement.
	 *
	 * @param int   $product_id  The product ID.
	 * @param array $finding     The verify_products() finding.
	 * @param array $expectation The parse_expected_sites() result.
	 * @return string
	 */
	private static function describe_unlinked_product( $product_id, array $finding, array $expectation ) {
		if ( empty( $finding['linked_sites'] ) ) {
			return sprintf( '#%d "%s": no other site carries this Network ID ( cross-site access grants nothing ).', $product_id, $finding['network_id'] );
		}
		if ( $expectation['require_all'] ) {
			return sprintf(
				'#%d "%s": not carried by %s ( readers subscribing there are granted nothing ). Linked on: %s.',
				$product_id,
				$finding['network_id'],
				implode( ', ', $finding['missing_sites'] ),
				implode( ', ', $finding['linked_sites'] )
			);
		}
		return sprintf(
			'#%d "%s": linked on %d other site(s), fewer than the %d required. Linked on: %s.',
			$product_id,
			$finding['network_id'],
			count( $finding['linked_sites'] ),
			$expectation['minimum'],
			implode( ', ', $finding['linked_sites'] )
		);
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
				'numberposts' => -1, // phpcs:ignore WordPressVIPMinimum.Performance.NoPaging -- Operator-run CLI command; unbounded by design.
				'fields'      => 'ids',
			]
		);

		self::prime_post_caches( $plan_posts );

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
	 * Every product ID linked by a membership plan, whatever the plan's Network ID.
	 *
	 * @return int[]
	 */
	private static function get_plan_linked_product_ids() {
		$product_ids = [];
		foreach ( self::get_plans() as $plan ) {
			foreach ( $plan['product_ids'] as $product_id ) {
				$product_ids[] = (int) $product_id;
			}
		}
		return array_values( array_unique( $product_ids ) );
	}

	/**
	 * Resolve a variation ID to its parent product ID, leaving anything else untouched.
	 *
	 * The Network ID lives on the parent product; Product_Admin::get_network_id() performs the same
	 * resolution when reading. Uses the post parent rather than wc_get_product() so it also works with
	 * WooCommerce deactivated mid-migration.
	 *
	 * @param int $product_id The product or variation ID.
	 * @return int
	 */
	private static function resolve_to_parent_product_id( $product_id ) {
		$product_id = (int) $product_id;
		if ( 'product_variation' !== get_post_type( $product_id ) ) {
			return $product_id;
		}
		$parent_id = (int) wp_get_post_parent_id( $product_id );
		return $parent_id ? $parent_id : $product_id;
	}

	/**
	 * Fold variation IDs in an assignment map into their parent products.
	 *
	 * A parent that ends up claimed with two different Network IDs is ambiguous and is withheld as a
	 * conflict, exactly like a product claimed by two plans.
	 *
	 * @param array $assignments Map of product ID => Network ID.
	 * @return array {
	 *     @type array $assignments Map of parent product ID => Network ID.
	 *     @type array $conflicts   Map of parent product ID => the distinct Network IDs claiming it.
	 * }
	 */
	private static function fold_variations_into_parents( array $assignments ) {
		$claims = [];
		foreach ( $assignments as $product_id => $network_id ) {
			$target_id = self::resolve_to_parent_product_id( $product_id );
			if ( ! isset( $claims[ $target_id ] ) ) {
				$claims[ $target_id ] = [];
			}
			if ( ! in_array( $network_id, $claims[ $target_id ], true ) ) {
				$claims[ $target_id ][] = $network_id;
			}
		}

		$folded    = [];
		$conflicts = [];
		foreach ( $claims as $product_id => $network_ids ) {
			if ( 1 === count( $network_ids ) ) {
				$folded[ $product_id ] = $network_ids[0];
			} else {
				$conflicts[ $product_id ] = $network_ids;
			}
		}

		return [
			'assignments' => $folded,
			'conflicts'   => $conflicts,
		];
	}

	/**
	 * Whether a Network ID on this product could ever grant cross-site access.
	 *
	 * Access resolves grants from a reader's synced subscriptions, and the product metabox only writes
	 * the meta for subscription products, so tagging any other type would only bloat every site's synced
	 * product map. With WooCommerce deactivated the type is unknowable, so nothing is filtered out.
	 *
	 * @param int $product_id The product ID.
	 * @return bool
	 */
	private static function is_taggable_product( $product_id ) {
		if ( ! function_exists( 'wc_get_product' ) ) {
			return true;
		}
		$product = wc_get_product( $product_id );
		return $product && $product->is_type( [ 'subscription', 'variable-subscription' ] );
	}

	/**
	 * Read the products to verify as a product ID => Network ID map.
	 *
	 * With an explicit list ( e.g. a gate's products ) every ID is returned as passed, including untagged
	 * ones ( Network ID '' ) so verify can flag them as the failure they are. With null, the set is every
	 * plan-linked subscription product plus every product that already carries a Network ID -- so products
	 * that assign-product-network-ids could not resolve are checked rather than defined away.
	 *
	 * @param array|null $product_ids Explicit product IDs to look up; null builds the default set.
	 * @return array
	 */
	private static function get_products_to_check( $product_ids = null ) {
		if ( null !== $product_ids ) {
			$product_ids = array_map( 'intval', $product_ids );

			// Prime up front, as on the default set below: get_network_id() reads the post and its meta,
			// which would otherwise be a DB round trip per product on exactly the input a scripted flip
			// gate passes.
			self::prime_post_caches( $product_ids );

			$products = [];
			foreach ( $product_ids as $product_id ) {
				$products[ $product_id ] = Product_Admin::get_network_id( $product_id );
			}
			return $products;
		}

		$tagged_ids = get_posts(
			[
				'post_type'   => 'product',
				'post_status' => 'any',
				'numberposts' => -1, // phpcs:ignore WordPressVIPMinimum.Performance.NoPaging -- Operator-run CLI command; unbounded by design.
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

		$candidate_ids = array_values( array_unique( array_merge( array_map( 'intval', $tagged_ids ), self::get_plan_linked_product_ids() ) ) );

		// Prime the post and postmeta caches so the reads below hit the cache instead of one DB round
		// trip per product ( fields => 'ids' skips WP's own priming ).
		self::prime_post_caches( $candidate_ids );

		$products = [];
		foreach ( $candidate_ids as $candidate_id ) {
			// A plan can link a variation, or a product that has since been deleted.
			$product_id = self::resolve_to_parent_product_id( $candidate_id );
			if ( 'product' !== get_post_type( $product_id ) ) {
				continue;
			}
			// A plan-linked product that could never grant cross-site access is not a flip blocker.
			if ( ! self::is_taggable_product( $product_id ) ) {
				continue;
			}
			$products[ $product_id ] = Product_Admin::get_network_id( $product_id );
		}
		return $products;
	}

	/**
	 * Prime the post and postmeta caches for a set of post IDs, in chunks.
	 *
	 * Priming pulls every meta row of every listed post, so the chunking bounds peak memory on stores
	 * with thousands of products.
	 *
	 * @param array $post_ids The post IDs.
	 * @return void
	 */
	private static function prime_post_caches( array $post_ids ) {
		$post_ids = array_map( 'intval', $post_ids );
		if ( empty( $post_ids ) ) {
			return;
		}
		foreach ( array_chunk( $post_ids, self::META_CACHE_CHUNK_SIZE ) as $chunk ) {
			_prime_post_caches( $chunk, false, true );
		}
	}

	/**
	 * Flush the Data Events dispatch queue every DISPATCH_FLUSH_INTERVAL dispatches.
	 *
	 * @param int $dispatched How many events have been dispatched so far.
	 * @return void
	 */
	private static function maybe_flush_dispatch_queue( $dispatched ) {
		if ( 0 === $dispatched % self::DISPATCH_FLUSH_INTERVAL ) {
			self::flush_dispatch_queue();
		}
	}

	/**
	 * Send the events queued so far by Data Events.
	 *
	 * Data_Events::dispatch() only appends to an in-memory queue drained on shutdown, so a long
	 * migration would hold every payload in memory and propagate nothing at all if the process were
	 * killed before shutdown -- exactly the "wrote but didn't sync" state this command exists to avoid.
	 *
	 * @return void
	 */
	private static function flush_dispatch_queue() {
		if ( method_exists( 'Newspack\Data_Events', 'execute_queued_dispatches' ) ) {
			\Newspack\Data_Events::execute_queued_dispatches();
		}
	}

	/**
	 * Parse the --map value ( inline JSON or a path to a JSON file ) into a product ID => Network ID map.
	 *
	 * Entries that cannot be used are reported and counted rather than dropped: the operator wrote them
	 * because they believe those products are covered, so they have to reach the caller's non-zero exit
	 * like any other withheld item.
	 *
	 * @param string $map The raw --map argument.
	 * @return array {
	 *     @type array $assignments Map of product ID => Network ID.
	 *     @type int   $skipped     How many entries could not be used.
	 * }
	 */
	private static function parse_map( $map ) {
		// Only treat the argument as a path when it's short enough to be one: passing a long inline-JSON
		// map straight to is_readable() can trip an E_WARNING ( "File name too long" ) on some platforms.
		if ( is_string( $map ) && strlen( $map ) < PHP_MAXPATHLEN && is_readable( $map ) ) {
			$map = file_get_contents( $map ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents, WordPressVIPMinimum.Performance.FetchingRemoteData.FileGetContentsUnknown
		}
		$decoded = json_decode( (string) $map, true );
		if ( ! is_array( $decoded ) ) {
			WP_CLI::error( 'Invalid --map: expected a JSON object of { "product_id": "network_id" } or a path to such a file.' );
		}
		if ( empty( $decoded ) ) {
			WP_CLI::error( 'Invalid --map: the mapping is empty.' );
		}
		// A JSON list is an array too, and its 0..n-1 keys would silently become product IDs.
		if ( array_keys( $decoded ) === range( 0, count( $decoded ) - 1 ) ) {
			WP_CLI::error( 'Invalid --map: expected a JSON object keyed by product ID, got a JSON list.' );
		}

		$assignments = [];
		$skipped     = 0;
		foreach ( $decoded as $product_id => $network_id ) {
			// JSON object keys arrive as PHP int keys only when they are canonical integers; anything else
			// ( "abc", "12abc" ) would cast to a real, unrelated product ID.
			if ( ! preg_match( '/^[1-9][0-9]*$/', (string) $product_id ) ) {
				WP_CLI::warning( sprintf( 'Skipping --map entry "%s": keys must be positive integer product IDs.', $product_id ) );
				$skipped++;
				continue;
			}
			if ( ! is_scalar( $network_id ) ) {
				WP_CLI::warning( sprintf( 'Skipping product #%s in --map: Network ID must be a string.', $product_id ) );
				$skipped++;
				continue;
			}
			$network_id = sanitize_text_field( (string) $network_id );
			if ( '' === $network_id ) {
				WP_CLI::warning( sprintf( 'Skipping product #%s in --map: empty Network ID.', $product_id ) );
				$skipped++;
				continue;
			}
			$assignments[ (int) $product_id ] = $network_id;
		}
		return [
			'assignments' => $assignments,
			'skipped'     => $skipped,
		];
	}
}
