<?php
/**
 * REST surface for the Newsletter Ads list DataView.
 *
 * @package Newspack_Newsletters
 */

namespace Newspack\Newsletters\Admin;

defined( 'ABSPATH' ) || exit;

use Newspack_Newsletters\Ads;
use WP_Error;
use WP_Post;
use WP_REST_Request;

/**
 * Adds a read-only `newspack_newsletters_ad_status` REST field on the
 * ads CPT consolidating `post_status` + date-driven lifecycle into a
 * single payload, plus the status-filter and meta-sort rails the
 * React DataView needs.
 */
class Ads_List_REST {
	use Rest_Status_Field;
	use Status_Filter_Builder;

	const STATUS_QUERY_PARAM = 'newspack_newsletters_ad_status';

	/**
	 * Boot hooks.
	 */
	public static function init(): void {
		add_action( 'rest_api_init', [ __CLASS__, 'register_rest_fields' ] );
		add_action( 'init', [ __CLASS__, 'register_meta' ] );
		add_filter(
			'rest_' . Ads::CPT . '_collection_params',
			[ __CLASS__, 'extend_collection_params' ]
		);
		add_filter(
			'rest_' . Ads::CPT . '_query',
			[ __CLASS__, 'filter_rest_query' ],
			10,
			2
		);
		add_filter(
			'rest_' . Ads::CPT . '_query',
			[ __CLASS__, 'translate_virtual_orderby' ],
			20,
			2
		);
		add_filter( 'posts_clauses', [ __CLASS__, 'apply_meta_sort_clauses' ], 10, 2 );
		add_filter( 'rest_request_before_callbacks', [ __CLASS__, 'guard_invalid_dates' ], 10, 3 );
	}

	/**
	 * Reject REST writes that supply a non-empty but non-ISO start_date or
	 * expiry_date — e.g. '06/29/2026'. An empty string is an intentional
	 * clear and is allowed through; sanitize_ad_date normalises valid ISO
	 * datetime strings to a bare Y-m-d before they reach the meta table.
	 *
	 * @param mixed           $response Earlier filter result; passed through if non-null.
	 * @param array           $handler  Route handler details.
	 * @param WP_REST_Request $request  Incoming REST request.
	 * @return mixed WP_Error with status 400 when an invalid date is found, otherwise $response.
	 */
	public static function guard_invalid_dates( $response, $handler, $request ) {
		unset( $handler );

		if ( null !== $response ) {
			return $response;
		}

		if ( ! $request instanceof WP_REST_Request ) {
			return $response;
		}

		$route   = $request->get_route();
		$pattern = '#^/wp/v2/' . preg_quote( Ads::CPT, '#' ) . '(?:/\d+)?$#';
		if ( ! preg_match( $pattern, $route ) ) {
			return $response;
		}

		$method = $request->get_method();
		if ( ! in_array( $method, [ 'POST', 'PUT', 'PATCH' ], true ) ) {
			return $response;
		}

		$meta = $request->get_param( 'meta' );
		if ( ! is_array( $meta ) ) {
			return $response;
		}

		foreach ( [ 'start_date', 'expiry_date' ] as $key ) {
			if ( ! isset( $meta[ $key ] ) ) {
				continue;
			}
			$value = $meta[ $key ];
			if ( is_string( $value ) && '' !== $value && '' === Ads::sanitize_ad_date( $value ) ) {
				return new WP_Error(
					'newspack_newsletters_ad_invalid_date',
					__( 'Start and expiration dates must be valid YYYY-MM-DD dates.', 'newspack-newsletters' ),
					[ 'status' => 400 ]
				);
			}
		}

		return $response;
	}

	/**
	 * Virtual REST orderby tokens. Applied via `posts_clauses` LEFT
	 * JOIN in `apply_meta_sort_clauses`, not WP_Query meta args.
	 */
	const VIRTUAL_ORDERBY_TOKENS = [
		'start_date'  => [
			'meta_key' => 'start_date',
			'is_num'   => false,
		],
		'expiry_date' => [
			'meta_key' => 'expiry_date',
			'is_num'   => false,
		],
		'price'       => [
			'meta_key' => 'price',
			'is_num'   => true,
		],
		'impressions' => [
			'meta_key' => 'tracking_impressions',
			'is_num'   => true,
		],
		'clicks'      => [
			'meta_key' => 'tracking_clicks',
			'is_num'   => true,
		],
	];

	/**
	 * Register tracking impression / click meta as REST-readable.
	 *
	 * `auth_callback => __return_false` makes the meta read-only via
	 * REST — these are server-managed counters; direct `update_post_meta`
	 * from the tracking layer isn't gated by `auth_callback`.
	 */
	public static function register_meta(): void {
		$readonly_counter_args = [
			'show_in_rest'  => [
				'schema' => [
					'type'     => 'integer',
					'readonly' => true,
				],
			],
			'type'          => 'integer',
			'single'        => true,
			'auth_callback' => '__return_false',
			'default'       => 0,
		];
		register_post_meta( Ads::CPT, 'tracking_impressions', $readonly_counter_args );
		register_post_meta( Ads::CPT, 'tracking_clicks', $readonly_counter_args );
	}

	/**
	 * Valid kind values accepted on the `STATUS_QUERY_PARAM`. Anything
	 * outside this list is ignored.
	 */
	const VALID_KINDS = [ 'active', 'scheduled', 'expired', 'draft', 'trash' ];

	/**
	 * Translate the kind-based status filter into native query args
	 * plus a `posts_where` callback that ORs each kind into its own
	 * bucket (`post_status` + date-driven meta condition).
	 *
	 * Bucketing is what lets `draft`/`trash` mix with the publish-
	 * driven kinds in the same selection — a flat `meta_query` would
	 * AND the meta condition across all rows and drop the drafts.
	 *
	 * @param array            $args    Query args being assembled.
	 * @param \WP_REST_Request $request Incoming REST request.
	 * @return array
	 */
	public static function filter_rest_query( $args, $request ) {
		$kinds = array_values(
			array_intersect( self::VALID_KINDS, self::parse_status_values( $request->get_param( self::STATUS_QUERY_PARAM ) ) )
		);
		if ( empty( $kinds ) ) {
			return $args;
		}

		global $wpdb;
		$today = current_time( 'Y-m-d' );

		$post_status_set = [];
		$bucket_clauses  = [];

		// `private` is publish-equivalent for lifecycle kinds (a private ad with valid dates is just a published ad with restricted visibility); `future` folds into `scheduled` only.
		foreach ( $kinds as $kind ) {
			switch ( $kind ) {
				case 'trash':
					$post_status_set[] = 'trash';
					$bucket_clauses[]  = "{$wpdb->posts}.post_status = 'trash'";
					break;
				case 'draft':
					$post_status_set    = array_merge( $post_status_set, [ 'draft', 'pending', 'auto-draft' ] );
					$bucket_clauses[]   = "{$wpdb->posts}.post_status IN ( 'draft', 'pending', 'auto-draft' )";
					break;
				case 'expired':
					$post_status_set    = array_merge( $post_status_set, [ 'publish', 'private' ] );
					$bucket_clauses[]   = $wpdb->prepare(
						"( {$wpdb->posts}.post_status IN ( 'publish', 'private' ) AND EXISTS ( SELECT 1 FROM {$wpdb->postmeta} WHERE post_id = {$wpdb->posts}.ID AND meta_key = 'expiry_date' AND ( STR_TO_DATE( LEFT( meta_value, 10 ), '%%Y-%%m-%%d' ) + INTERVAL 0 DAY ) < %s ) )",
						$today
					);
					break;
				case 'scheduled':
					$post_status_set    = array_merge( $post_status_set, [ 'publish', 'private', 'future' ] );
					$bucket_clauses[]   = $wpdb->prepare(
						"( ( {$wpdb->posts}.post_status IN ( 'publish', 'private' ) AND EXISTS ( SELECT 1 FROM {$wpdb->postmeta} WHERE post_id = {$wpdb->posts}.ID AND meta_key = 'start_date' AND ( STR_TO_DATE( LEFT( meta_value, 10 ), '%%Y-%%m-%%d' ) + INTERVAL 0 DAY ) > %s ) ) OR {$wpdb->posts}.post_status = 'future' )",
						$today
					);
					break;
				case 'active':
					$post_status_set    = array_merge( $post_status_set, [ 'publish', 'private' ] );
					$bucket_clauses[]   = $wpdb->prepare(
						"( {$wpdb->posts}.post_status IN ( 'publish', 'private' )"
						. " AND ( NOT EXISTS ( SELECT 1 FROM {$wpdb->postmeta} sm1 WHERE sm1.post_id = {$wpdb->posts}.ID AND sm1.meta_key = 'start_date' AND ( STR_TO_DATE( LEFT( sm1.meta_value, 10 ), '%%Y-%%m-%%d' ) + INTERVAL 0 DAY ) IS NOT NULL )"
						. " OR EXISTS ( SELECT 1 FROM {$wpdb->postmeta} sm2 WHERE sm2.post_id = {$wpdb->posts}.ID AND sm2.meta_key = 'start_date' AND ( STR_TO_DATE( LEFT( sm2.meta_value, 10 ), '%%Y-%%m-%%d' ) + INTERVAL 0 DAY ) <= %s ) )"
						. " AND ( NOT EXISTS ( SELECT 1 FROM {$wpdb->postmeta} em1 WHERE em1.post_id = {$wpdb->posts}.ID AND em1.meta_key = 'expiry_date' AND ( STR_TO_DATE( LEFT( em1.meta_value, 10 ), '%%Y-%%m-%%d' ) + INTERVAL 0 DAY ) IS NOT NULL )"
						. " OR EXISTS ( SELECT 1 FROM {$wpdb->postmeta} em2 WHERE em2.post_id = {$wpdb->posts}.ID AND em2.meta_key = 'expiry_date' AND ( STR_TO_DATE( LEFT( em2.meta_value, 10 ), '%%Y-%%m-%%d' ) + INTERVAL 0 DAY ) >= %s ) ) )",
						$today,
						$today
					);
					break;
			}
		}

		$args['post_status'] = array_values( array_unique( $post_status_set ) );

		return self::install_bucket_filter( $args, $bucket_clauses, '_newspack_ads_bucket_token' );
	}

	/**
	 * Widen the REST orderby enum to accept our virtual tokens —
	 * `rest_validate_request_arg` runs the enum check before
	 * `rest_${CPT}_query` can rewrite.
	 *
	 * @param array $params Collection params from the posts controller.
	 * @return array
	 */
	public static function extend_collection_params( $params ) {
		if ( isset( $params['orderby']['enum'] ) && is_array( $params['orderby']['enum'] ) ) {
			$params['orderby']['enum'] = array_values(
				array_unique(
					array_merge(
						$params['orderby']['enum'],
						array_keys( self::VIRTUAL_ORDERBY_TOKENS )
					)
				)
			);
		}
		return $params;
	}

	/**
	 * Query var carrying meta-sort intent through to `apply_meta_sort_clauses`.
	 */
	const META_SORT_QUERY_VAR = 'newspack_ads_meta_sort';

	/**
	 * Stash meta-sort intent on the query args.
	 *
	 * @param array            $args    Prepared WP_Query args.
	 * @param \WP_REST_Request $request Incoming REST request.
	 * @return array
	 */
	public static function translate_virtual_orderby( $args, $request ) {
		unset( $request );
		$orderby = isset( $args['orderby'] ) ? $args['orderby'] : null;
		if ( ! is_string( $orderby ) || ! isset( self::VIRTUAL_ORDERBY_TOKENS[ $orderby ] ) ) {
			return $args;
		}
		$mapping = self::VIRTUAL_ORDERBY_TOKENS[ $orderby ];

		$args['orderby'] = 'none';
		$args[ self::META_SORT_QUERY_VAR ] = [
			'meta_key' => $mapping['meta_key'],
			'is_num'   => $mapping['is_num'],
			'order'    => ( isset( $args['order'] ) && 'asc' === strtolower( (string) $args['order'] ) ) ? 'ASC' : 'DESC',
		];

		return $args;
	}

	/**
	 * LEFT JOIN postmeta and order by it so rows missing the sorted
	 * key still appear (a plain `meta_key` arg would inner-join them out).
	 *
	 * @param array     $clauses WP_Query SQL clauses.
	 * @param \WP_Query $query   The WP_Query running the SQL.
	 * @return array
	 */
	public static function apply_meta_sort_clauses( $clauses, $query ) {
		if ( ! ( $query instanceof \WP_Query ) || Ads::CPT !== $query->get( 'post_type' ) ) {
			return $clauses;
		}
		$sort = $query->get( self::META_SORT_QUERY_VAR );
		if ( ! is_array( $sort ) || empty( $sort['meta_key'] ) ) {
			return $clauses;
		}
		global $wpdb;
		$clauses['join'] .= $wpdb->prepare(
			" LEFT JOIN {$wpdb->postmeta} AS newspack_sort_meta ON newspack_sort_meta.post_id = {$wpdb->posts}.ID AND newspack_sort_meta.meta_key = %s",
			$sort['meta_key']
		);
		// `+ 0` coerces to DOUBLE so decimal prices don't truncate (matches WP_Query's meta_value_num).
		$value_expr = ! empty( $sort['is_num'] ) ? 'newspack_sort_meta.meta_value + 0' : 'newspack_sort_meta.meta_value'; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
		$order = ( isset( $sort['order'] ) && 'ASC' === $sort['order'] ) ? 'ASC' : 'DESC';
		$clauses['orderby'] = $value_expr . ' ' . $order . ", {$wpdb->posts}.ID DESC";
		return $clauses;
	}

	/**
	 * Register REST fields on the ads CPT.
	 */
	public static function register_rest_fields(): void {
		self::register_status_field(
			Ads::CPT,
			'newspack_newsletters_ad_status',
			[
				'kind'       => [
					'type' => 'string',
					'enum' => [ 'active', 'scheduled', 'expired', 'draft', 'trash' ],
				],
				'starts_at'  => [
					'type' => [ 'integer', 'null' ],
				],
				'expires_at' => [
					'type' => [ 'integer', 'null' ],
				],
			]
		);
	}

	/**
	 * Compute the consolidated status payload for an ad post.
	 *
	 * @param WP_Post|null $post Post object.
	 * @return array { kind, starts_at, expires_at }
	 */
	public static function get_status_for_post( $post ): array {
		$payload = [
			'kind'       => 'draft',
			'starts_at'  => null,
			'expires_at' => null,
		];

		if ( ! $post instanceof WP_Post ) {
			return $payload;
		}

		if ( 'trash' === $post->post_status ) {
			$payload['kind'] = 'trash';
			return $payload;
		}

		// `future` (WP's Publish-Schedule) is `scheduled`; `post_date_gmt` is the auto-publish moment, and `start_date` / `expiry_date` meta don't apply until it publishes.
		if ( 'future' === $post->post_status ) {
			$payload['kind']      = 'scheduled';
			$starts_at            = strtotime( $post->post_date_gmt . ' UTC' );
			$payload['starts_at'] = false === $starts_at ? null : $starts_at;
			return $payload;
		}

		if ( in_array( $post->post_status, [ 'publish', 'private' ], true ) ) {
			$today = current_time( 'Y-m-d' );
			// Normalize legacy ISO datetime meta to bare dates.
			$start_date  = Ads::sanitize_ad_date( get_post_meta( $post->ID, 'start_date', true ) );
			$expiry_date = Ads::sanitize_ad_date( get_post_meta( $post->ID, 'expiry_date', true ) );

			// Noon UTC so the timestamp lands on the intended calendar day in any reasonable site timezone; date-only meta makes the time-of-day a presentation safeguard.
			if ( '' !== $start_date ) {
				$starts_at            = strtotime( $start_date . ' 12:00:00 UTC' );
				$payload['starts_at'] = false === $starts_at ? null : $starts_at;
			}
			if ( '' !== $expiry_date ) {
				$expires_at            = strtotime( $expiry_date . ' 12:00:00 UTC' );
				$payload['expires_at'] = false === $expires_at ? null : $expires_at;
			}

			if ( '' !== $expiry_date && $expiry_date < $today ) {
				$payload['kind'] = 'expired';
				return $payload;
			}
			if ( '' !== $start_date && $start_date > $today ) {
				$payload['kind'] = 'scheduled';
				return $payload;
			}
			$payload['kind'] = 'active';
			return $payload;
		}

		return $payload;
	}
}
