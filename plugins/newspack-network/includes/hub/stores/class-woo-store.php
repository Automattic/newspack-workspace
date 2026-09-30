<?php
/**
 * Newspack Hub Woocommerce Generic Woo items store for orders and subscriptions
 *
 * @package Newspack
 */

namespace Newspack_Network\Hub\Stores;

use Newspack_Network\Debugger;
use Newspack_Network\Incoming_Events\Woo_Item_Changed;
use WP_REST_Request;
use WP_REST_Server;

/**
 * Class to handle Woocommerce Generic Woo items store for orders and subscriptions
 */
abstract class Woo_Store {

	/**
	 * Prefix of the meta key naming the customer on a copy: one key per email, so a
	 * reader's copies are found through the indexed meta_key column rather than a scan
	 * of every copy's email. A copy only carries it once it has been written by the
	 * per-site lookup below; copies from before may hold another site's data under
	 * this customer's email, and stay out of reach until an event or a rebuild
	 * rewrites them.
	 */
	const READER_KEY_PREFIX = 'np_reader_';

	/**
	 * Meta recording which reader key a copy carries, so it can be removed when the
	 * customer's email changes.
	 */
	const READER_KEY_META = 'reader_key';

	/**
	 * The lookup meta key for a customer email.
	 *
	 * @param string $email Email.
	 * @return string
	 */
	public static function get_reader_key( $email ) {
		return self::READER_KEY_PREFIX . md5( strtolower( trim( (string) $email ) ) );
	}

	/**
	 * Point a copy's lookup key at its customer's current email.
	 *
	 * @param int    $local_id The copy's post ID.
	 * @param string $email    Customer email.
	 * @return void
	 */
	protected static function update_reader_key( $local_id, $email ) {
		$previous = (string) get_post_meta( $local_id, self::READER_KEY_META, true );
		$key      = $email ? self::get_reader_key( $email ) : '';
		if ( $previous && $previous !== $key ) {
			delete_post_meta( $local_id, $previous );
		}
		if ( $key ) {
			update_post_meta( $local_id, $key, 1 );
			update_post_meta( $local_id, self::READER_KEY_META, $key );
		} else {
			delete_post_meta( $local_id, self::READER_KEY_META );
		}
	}
	/**
	 * Gets the post type slug
	 *
	 * @return string
	 */
	abstract protected static function get_post_type_slug();

	/**
	 * Gets the api endpoint prefix
	 *
	 * @return string
	 */
	abstract protected static function get_api_endpoint_prefix();

	/**
	 * Gets the name of the items class
	 *
	 * @return string
	 */
	abstract protected static function get_item_class();

	/**
	 * Gets the post status prefix
	 *
	 * @return string
	 */
	abstract protected static function get_post_status_prefix();

	/**
	 * Gets the post status for the database
	 *
	 * @param string $status The status slug.
	 * @return string The post status.
	 */
	public static function get_post_status_for_db( $status ) {
		if ( 0 === strpos( $status, static::get_post_status_prefix() ) ) {
			return $status;
		}
		return static::get_post_status_prefix() . $status;
	}

	/**
	 * Gets an item by its ID
	 *
	 * @param int $item_id The item ID.
	 * @return ?Woo_Item The Woo_Item object if the item is found.
	 */
	public static function get_item( $item_id ) {
		$item_class = __NAMESPACE__ . '\\' . static::get_item_class();
		$item       = new $item_class( $item_id );
		if ( $item->get_id() ) {
			return $item;
		}
	}

	/**
	 * Returns the local post ID for a given Woo_Item_Changed event.
	 *
	 * If there's no local post for the given Woo_Item_Changed event, creates one.
	 *
	 * @param Woo_Item_Changed $woo_item The Woo_Item_Changed event.
	 * @return int The local post ID.
	 */
	protected static function get_local_id( Woo_Item_Changed $woo_item ) {
		$local_id = static::find_local_id( $woo_item );
		return $local_id ? $local_id : self::create_item( $woo_item );
	}

	/**
	 * Returns the local post ID for a given Woo_Item_Changed event, if the hub has a copy.
	 *
	 * An item is identified by its ID together with its site: every site numbers its
	 * orders and subscriptions independently, so two sites can each have an item #500.
	 *
	 * @param Woo_Item_Changed $woo_item The Woo_Item_Changed event.
	 * @return int The local post ID, or 0.
	 */
	public static function find_local_id( Woo_Item_Changed $woo_item ) {
		$woo_item_id = $woo_item->get_id();
		$stored      = get_posts(
			[
				'post_type'      => static::get_post_type_slug(),
				'post_status'    => 'any',
				'meta_query'     => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					[
						'key'   => 'remote_id',
						'value' => $woo_item_id,
					],
					[
						'key'   => 'node_id',
						'value' => $woo_item->get_node_id(),
					],
				],
				'posts_per_page' => 1,
				'fields'         => 'ids',
			]
		);
		return empty( $stored ) ? 0 : (int) $stored[0];
	}

	/**
	 * Creates a local post for a given Woo_Item_Changed event.
	 *
	 * @param Woo_Item_Changed $woo_item The Woo_Item_Changed event.
	 * @return int The local post ID.
	 */
	protected static function create_item( Woo_Item_Changed $woo_item ) {
		$woo_item_id = $woo_item->get_id();
		$user_id     = 0;
		$user        = get_user_by( 'email', $woo_item->get_email() );
		if ( $user instanceof \WP_User ) {
			$user_id = $user->ID;
		}
		$post_arr = [
			'post_type'   => static::get_post_type_slug(),
			'post_status' => static::get_post_status_for_db( $woo_item->get_status_after() ),
			'post_title'  => '#' . $woo_item_id,
			'post_author' => $user_id,
		];
		$post_id  = wp_insert_post( $post_arr );

		add_post_meta( $post_id, 'remote_id', $woo_item_id );
		add_post_meta( $post_id, 'node_id', $woo_item->get_node_id() );
		add_post_meta( $post_id, 'user_email', $woo_item->get_email() );
		add_post_meta( $post_id, 'user_name', $woo_item->get_user_name() );

		return $post_id;
	}

	/**
	 * Fetches Woo data from the API.
	 *
	 * Note that the format of the output can be slightly different if fetch_data_from_local_api is called.
	 * Objects inside arrays (like line_items) can be arrays, and meta data can be WC_Meta_Data objects.
	 *
	 * @param Woo_Item_Changed $woo_item The Woo_Item_Changed event.
	 * @return object The subscription data.
	 */
	protected static function fetch_data_from_api( Woo_Item_Changed $woo_item ) {
		if ( $woo_item->is_local() ) {
			return self::fetch_data_from_local_api( $woo_item );
		}

		$woo_item_id = $woo_item->get_id();

		$endpoint    = sprintf( '%s/wp-json/wc/v3/%s/%d', $woo_item->get_node()->get_url(), static::get_api_endpoint_prefix(), $woo_item_id );
		$endpoint_id = 'get-woo-' . static::get_api_endpoint_prefix();

		$response = wp_remote_get( // phpcs:ignore
			$endpoint,
			[
				'headers' => $woo_item->get_node()->get_authorization_headers( $endpoint_id ),
			]
		);

		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			Debugger::log( 'API request failed' );
			return;
		}

		$body = wp_remote_retrieve_body( $response );

		return json_decode( $body );
	}

	/**
	 * Fetches data from the local API.
	 *
	 * @param Woo_Item_Changed $woo_item The Woo_Item_Changed event.
	 * @return object The subscription data.
	 */
	protected static function fetch_data_from_local_api( Woo_Item_Changed $woo_item ) {

		$woo_item_id = $woo_item->get_id();

		$endpoint = sprintf( '/wc/v3/%s/%d', static::get_api_endpoint_prefix(), $woo_item_id );

		$request = new WP_REST_Request(
			'GET',
			$endpoint
		);

		add_filter( 'woocommerce_rest_check_permissions', '__return_true' );
		$response = rest_get_server()->dispatch( $request );
		remove_filter( 'woocommerce_rest_check_permissions', '__return_true' );

		return (object) $response->get_data();
	}
}
