<?php
/**
 * Newspack Network Hub reader access endpoint.
 *
 * @package Newspack
 */

namespace Newspack_Network\Hub;

use Newspack_Network\Content_Gate\Reader_Access_Envelope;
use Newspack_Network\Hub\Database\Orders as Orders_DB;
use Newspack_Network\Hub\Database\Subscriptions as Subscriptions_DB;
use Newspack_Network\Incoming_Events\Product_Updated;
use Newspack_Network\Hub\Stores\Orders;
use Newspack_Network\Hub\Stores\Subscriptions;
use Newspack_Network\Utils\Requests;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Answers a node's question about what a reader holds on the rest of the network:
 * the subscriptions they own, their seats on other people's group subscriptions,
 * and their paid one-time orders.
 *
 * The answer comes from the copies the hub keeps of every site's subscriptions and
 * orders, found through indexed per-email keys, so it costs the hub a few lookups
 * and no requests to other sites.
 */
class Reader_Access_Endpoint {

	/**
	 * Most records of each kind read per answer.
	 */
	const MAX_RECORDS = 500;

	/**
	 * Every status the hub's subscription copies can have. Access only counts active
	 * and pending-cancel ones, but the others are answered too, so a node replaces a
	 * record it holds as active with the ended one.
	 */
	const SUBSCRIPTION_STATUSES = [ 'pending', 'active', 'on-hold', 'cancelled', 'switched', 'expired', 'pending-cancel' ];

	/**
	 * Order statuses that count as paid, as WooCommerce's own defaults do.
	 */
	const PAID_ORDER_STATUSES = [ 'processing', 'completed' ];

	/**
	 * Initializer.
	 */
	public static function init() {
		add_action( 'rest_api_init', [ __CLASS__, 'register_routes' ] );
	}

	/**
	 * Register the route.
	 */
	public static function register_routes() {
		register_rest_route(
			'newspack-network/v1',
			'/reader-access',
			[
				[
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => [ __CLASS__, 'handle_request' ],
					// The signed payload is verified in the callback, as the pull endpoint does.
					'permission_callback' => '__return_true',
				],
			]
		);
	}

	/**
	 * Answer a node's request.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function handle_request( $request ) {
		$verified_params = Requests::verify_request_to_hub( $request );
		if ( is_wp_error( $verified_params ) ) {
			return new WP_REST_Response( [ 'error' => $verified_params->get_error_message() ], 403 );
		}

		// The plaintext 'site' selected the key that verified the signature; the signed copy must agree.
		$site = $request['site'];
		if ( ( $verified_params['site'] ?? null ) !== $site ) {
			return new WP_REST_Response( [ 'error' => 'Site mismatch.' ], 403 );
		}

		$email      = sanitize_email( (string) ( $verified_params['email'] ?? '' ) );
		$request_id = (string) ( $verified_params['request_id'] ?? '' );
		if ( ! $email || ! $request_id ) {
			return new WP_REST_Response( [ 'error' => 'Bad request.' ], 400 );
		}

		$node     = Nodes::get_node_by_url( $site );
		$envelope = Reader_Access_Envelope::seal(
			[
				'email'      => $email,
				'request_id' => $request_id,
				'sites'      => self::collect( $email, $node->get_id() ),
			],
			$node->get_secret_key()
		);
		if ( is_wp_error( $envelope ) ) {
			return new WP_REST_Response( [ 'error' => $envelope->get_error_message() ], 500 );
		}
		return new WP_REST_Response( $envelope );
	}

	/**
	 * What the reader holds on every network site except one.
	 *
	 * @param string $email           Reader email.
	 * @param int    $excluded_node_id The site asking, whose own rules cover its own data: a node's ID, or 0 for the hub.
	 * @return array Site URL => [ 'subscriptions' => array, 'groups' => array[], 'orders' => array[] ], for sites with any.
	 */
	public static function collect( $email, $excluded_node_id ) {
		$email = strtolower( sanitize_email( $email ) );
		if ( ! $email ) {
			return [];
		}

		$sites = [];
		$add   = function ( $post_id, $type, $record, $key = null ) use ( &$sites, $excluded_node_id ) {
			$node_id = (int) get_post_meta( $post_id, 'node_id', true );
			if ( (int) $excluded_node_id === $node_id ) {
				return;
			}
			$site = self::get_site_url( $node_id );
			if ( ! $site ) {
				return;
			}
			if ( ! isset( $sites[ $site ] ) ) {
				$sites[ $site ] = [
					'subscriptions' => [],
					'groups'        => [],
					'orders'        => [],
				];
			}
			if ( null === $key ) {
				$sites[ $site ][ $type ][] = $record;
			} else {
				$sites[ $site ][ $type ][ $key ] = $record;
			}
		};

		foreach ( self::find_copies( Subscriptions_DB::POST_TYPE_SLUG, Subscriptions_DB::POST_STATUS_PREFIX, self::SUBSCRIPTION_STATUSES, Subscriptions::get_reader_key( $email ) ) as $post_id ) {
			$site     = self::get_site_url( (int) get_post_meta( $post_id, 'node_id', true ) );
			$products = [];
			foreach ( get_post_meta( $post_id, 'products', false ) as $product ) {
				$product    = (array) $product;
				$product_id = (int) ( $product['id'] ?? 0 );
				if ( $product_id ) {
					$products[ $product_id ] = array_merge( $product, [ 'network_id' => self::get_network_id( $site, $product_id ) ] );
				}
			}
			$remote_id = (int) get_post_meta( $post_id, 'remote_id', true );
			$add(
				$post_id,
				'subscriptions',
				[
					'id'       => $remote_id,
					'status'   => self::get_status( $post_id, Subscriptions_DB::POST_STATUS_PREFIX ),
					'products' => $products,
				],
				$remote_id
			);
		}

		foreach ( self::find_copies( Subscriptions_DB::POST_TYPE_SLUG, Subscriptions_DB::POST_STATUS_PREFIX, self::SUBSCRIPTION_STATUSES, Subscriptions::get_member_key( $email ) ) as $post_id ) {
			// A copy without a reader key predates copies being kept per site and may hold
			// another site's status and products; its seats wait until it is rewritten.
			if ( ! get_post_meta( $post_id, Subscriptions::READER_KEY_META, true ) ) {
				continue;
			}
			$site        = self::get_site_url( (int) get_post_meta( $post_id, 'node_id', true ) );
			$network_ids = [];
			foreach ( get_post_meta( $post_id, 'products', false ) as $product ) {
				$network_ids[] = self::get_network_id( $site, (int) ( ( (array) $product )['id'] ?? 0 ) );
			}
			$add(
				$post_id,
				'groups',
				[
					'id'          => (int) get_post_meta( $post_id, 'remote_id', true ),
					'status'      => self::get_status( $post_id, Subscriptions_DB::POST_STATUS_PREFIX ),
					'network_ids' => array_values( array_unique( array_filter( $network_ids ) ) ),
				]
			);
		}

		foreach ( self::get_newest_orders_per_network_id( $email ) as $post_id => $order ) {
			$add( $post_id, 'orders', $order );
		}

		return $sites;
	}

	/**
	 * The reader's paid orders that can grant one-time access: for each site and
	 * Network ID, only the newest, since an older order can never grant more.
	 *
	 * @param string $email Reader email.
	 * @return array Hub post ID => [ 'id', 'date_created', 'network_ids' ], newest first.
	 */
	private static function get_newest_orders_per_network_id( $email ) {
		$orders = [];
		foreach ( self::find_copies( Orders_DB::POST_TYPE_SLUG, Orders_DB::POST_STATUS_PREFIX, self::PAID_ORDER_STATUSES, Orders::get_reader_key( $email ) ) as $post_id ) {
			$node_id      = (int) get_post_meta( $post_id, 'node_id', true );
			$site         = self::get_site_url( $node_id );
			$date_created = self::parse_date( get_post_meta( $post_id, 'date_created', true ) );
			$network_ids  = [];
			foreach ( get_post_meta( $post_id, 'products', false ) as $product ) {
				$product = (array) $product;
				// Subscription products are left out: a renewal isn't a one-time purchase.
				if ( ! empty( $product['subscription'] ) || ! array_key_exists( 'subscription', $product ) ) {
					continue;
				}
				$network_id = self::get_network_id( $site, (int) ( $product['variation_id'] ?? 0 ) );
				if ( ! $network_id ) {
					$network_id = self::get_network_id( $site, (int) ( $product['id'] ?? 0 ) );
				}
				$network_ids[] = $network_id;
			}
			$network_ids = array_values( array_unique( array_filter( $network_ids ) ) );
			if ( $date_created && $network_ids ) {
				$orders[ $post_id ] = [
					'node_id'      => $node_id,
					'id'           => (int) get_post_meta( $post_id, 'remote_id', true ),
					'date_created' => $date_created,
					'network_ids'  => $network_ids,
				];
			}
		}

		uasort(
			$orders,
			function ( $a, $b ) {
				return $b['date_created'] <=> $a['date_created'];
			}
		);

		$seen   = [];
		$newest = [];
		foreach ( $orders as $post_id => $order ) {
			$new_network_ids = [];
			foreach ( $order['network_ids'] as $network_id ) {
				$key = $order['node_id'] . '|' . $network_id;
				if ( ! isset( $seen[ $key ] ) ) {
					$seen[ $key ]      = true;
					$new_network_ids[] = $network_id;
				}
			}
			if ( $new_network_ids ) {
				unset( $order['node_id'] );
				$order['network_ids'] = $new_network_ids;
				$newest[ $post_id ]   = $order;
			}
		}
		return $newest;
	}

	/**
	 * IDs of the hub's copies carrying a reader's lookup key.
	 *
	 * @param string   $post_type Post type of the copies.
	 * @param string   $prefix    The copies' status prefix.
	 * @param string[] $statuses  Statuses to include, without the prefix.
	 * @param string   $meta_key  The reader's lookup key (see Woo_Store::READER_KEY_PREFIX).
	 * @return int[]
	 */
	private static function find_copies( $post_type, $prefix, $statuses, $meta_key ) {
		$post_statuses = array_map(
			function ( $status ) use ( $prefix ) {
				return $prefix . $status;
			},
			$statuses
		);
		$posts         = get_posts(
			[
				'post_type'      => $post_type,
				'post_status'    => $post_statuses,
				'meta_query'     => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					[
						'key'     => $meta_key,
						'compare' => 'EXISTS',
					],
				],
				'posts_per_page' => self::MAX_RECORDS,
				'orderby'        => 'ID',
				'order'          => 'DESC',
				'no_found_rows'  => true,
			]
		);
		$ids           = [];
		foreach ( $posts as $post ) {
			// WP_Query drops statuses that aren't registered instead of matching nothing,
			// which would let a pending or refunded copy through, so check each one here too.
			if ( in_array( $post->post_status, $post_statuses, true ) ) {
				$ids[] = (int) $post->ID;
			}
		}
		return $ids;
	}

	/**
	 * A copy's status, without the hub's prefix.
	 *
	 * @param int    $post_id Hub post ID.
	 * @param string $prefix  Status prefix.
	 * @return string
	 */
	private static function get_status( $post_id, $prefix ) {
		$status = (string) get_post_status( $post_id );
		return 0 === strpos( $status, $prefix ) ? substr( $status, strlen( $prefix ) ) : $status;
	}

	/**
	 * The URL a copy's site is known by, as its events name it.
	 *
	 * @param int $node_id Node ID, or 0 for the hub.
	 * @return string Empty for a node that is no longer registered.
	 */
	private static function get_site_url( $node_id ) {
		static $urls = [];
		if ( ! $node_id ) {
			return get_bloginfo( 'url' );
		}
		if ( ! isset( $urls[ $node_id ] ) ) {
			$urls[ $node_id ] = (string) ( new Node( $node_id ) )->get_url();
		}
		return $urls[ $node_id ];
	}

	/**
	 * A product's Network ID, from the product data every site syncs to the hub.
	 *
	 * @param string $site       Site URL.
	 * @param int    $product_id Product ID on that site.
	 * @return string
	 */
	private static function get_network_id( $site, $product_id ) {
		if ( ! $product_id ) {
			return '';
		}
		$network_products = get_option( Product_Updated::OPTION_NAME, [] );
		return (string) ( $network_products[ $site ][ $product_id ]['network_id'] ?? '' );
	}

	/**
	 * An order's creation date, as the order event reported it, in Unix time.
	 *
	 * The event sends the date in UTC without a zone designator.
	 *
	 * @param mixed $date Date string.
	 * @return int 0 when missing or unreadable.
	 */
	private static function parse_date( $date ) {
		if ( ! is_string( $date ) || '' === $date ) {
			return 0;
		}
		$has_zone  = (bool) preg_match( '/(Z|[+-]\d{2}:?\d{2})$/', $date );
		$timestamp = strtotime( $has_zone ? $date : $date . 'Z' );
		return false === $timestamp ? 0 : $timestamp;
	}
}
