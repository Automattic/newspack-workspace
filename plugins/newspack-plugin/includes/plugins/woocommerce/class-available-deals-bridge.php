<?php
/**
 * Available Deals bridge — computes each reader's advertisable dynamic-pricing
 * deals via the engine's Available_Deals_Query and pushes them to the Campaigns
 * store as a read-only `available_deals` reader-data item, and registers the
 * `has_available_deal` segmentation criterion. The server→client mirror of the
 * segment snapshot (DP spec 19). Inert when the dynamic-pricing engine is absent.
 *
 * @package Newspack
 */

namespace Newspack;

defined( 'ABSPATH' ) || exit;

/**
 * Newspack ↔ dynamic-pricing "available deals" advertising bridge.
 */
final class Available_Deals_Bridge {
	const STORE_KEY   = 'available_deals';
	const QUERY_CLASS = '\Automattic\WooCommerce\DynamicPricing\Subscriptions\Available_Deals_Query';

	/**
	 * Register hooks.
	 */
	public static function init(): void {
		// Idempotent: the class self-inits at file load, and tests may call init()
		// again — register the hooks only once so filters aren't double-added.
		static $done = false;
		if ( $done ) {
			return;
		}
		$done = true;
		add_filter( 'newspack_reader_data_read_only_keys', [ __CLASS__, 'register_read_only_key' ] );
		add_action( 'init', [ __CLASS__, 'register_data_event_handlers' ] );
		add_filter( 'newspack_popups_default_criteria', [ __CLASS__, 'register_criteria' ] );
	}

	/**
	 * Mark `available_deals` server-owned (the client must not forge it).
	 *
	 * @param string[] $keys Read-only keys.
	 * @return string[]
	 */
	public static function register_read_only_key( $keys ) {
		$keys[] = self::STORE_KEY;
		return $keys;
	}

	/**
	 * Recompute on login and on subscription-status changes (both flip
	 * win-back/save eligibility).
	 */
	public static function register_data_event_handlers(): void {
		if ( ! class_exists( 'Newspack\Data_Events' ) ) {
			return;
		}
		Data_Events::register_handler( [ __CLASS__, 'refresh_on_event' ], 'reader_logged_in' );
		Data_Events::register_handler( [ __CLASS__, 'refresh_on_event' ], 'product_subscription_changed' );
	}

	/**
	 * Data-event handler: recompute available deals for the event's user.
	 *
	 * @param int   $timestamp Event timestamp.
	 * @param array $data      Event data (carries user_id).
	 */
	public static function refresh_on_event( $timestamp, $data ): void {
		$user_id = (int) ( $data['user_id'] ?? 0 );
		if ( $user_id > 0 ) {
			self::refresh( $user_id );
		}
	}

	/**
	 * Compute and store the reader's available deal IDs.
	 *
	 * @param int $user_id Reader user ID.
	 */
	public static function refresh( int $user_id ): void {
		if ( $user_id <= 0 || ! class_exists( self::QUERY_CLASS ) || ! class_exists( '\WC_Customer' ) ) {
			return;
		}
		try {
			$customer = new \WC_Customer( $user_id );
		} catch ( \Exception $e ) {
			return;
		}
		$ids = call_user_func( [ self::QUERY_CLASS, 'for_customer' ], $customer );
		Reader_Data::update_item( $user_id, self::STORE_KEY, wp_json_encode( array_values( array_map( 'intval', (array) $ids ) ) ) );
	}

	/**
	 * Register the `has_available_deal` Campaigns criterion (per-deal, list__in).
	 * Options are the advertisable (`publicize`) rules from the engine.
	 *
	 * @param array $criteria Default criteria.
	 * @return array
	 */
	public static function register_criteria( $criteria ) {
		$criteria['has_available_deal'] = [
			'name'               => __( 'Has available deal', 'newspack-plugin' ),
			'description'        => __( 'Reader qualifies for one of the selected advertisable pricing deals.', 'newspack-plugin' ),
			'category'           => 'reader_revenue',
			'matching_function'  => 'list__in',
			'matching_attribute' => self::STORE_KEY,
			'options'            => self::deal_options(),
		];
		return $criteria;
	}

	/**
	 * Active pricing rules as criterion options ({ label, value }), or [] when the
	 * engine is absent. Any published/active rule is selectable, regardless of the
	 * publicize (cart-display) flag — the segment selection is the targeting.
	 *
	 * @return array
	 */
	private static function deal_options(): array {
		$engine_class = '\Automattic\WooCommerce\DynamicPricing\Pricing_Engine';
		if ( ! class_exists( $engine_class ) ) {
			return [];
		}
		$repo = call_user_func( [ $engine_class, 'instance' ] )->repository();
		if ( ! $repo ) {
			return [];
		}
		// Lead with an "Any deal" option (empty value) so the criterion is optional,
		// like the other option criteria (donation, user_account, …): an empty
		// selection is pruned on save (segments-model filter_criteria), making the
		// criterion inert. String values match the SelectControl's string output;
		// list__in compares loosely against the reader's available_deals.
		$out = [
			[
				'label' => __( 'Any deal', 'newspack-plugin' ),
				'value' => '',
			],
		];
		foreach ( $repo->active() as $rule ) {
			$out[] = [
				'label' => $rule->title,
				'value' => (string) $rule->id,
			];
		}
		return $out;
	}
}

Available_Deals_Bridge::init();
