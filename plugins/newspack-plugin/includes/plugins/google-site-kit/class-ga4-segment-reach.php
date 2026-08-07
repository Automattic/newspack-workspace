<?php
/**
 * Fetches and caches per-segment reach from GA4, for display in the
 * Campaigns segments list.
 *
 * Reads back the events newspack-popups sends (np_segment_matched /
 * np_segment_won, one per segment per GA4 session), so `eventCount` is the
 * "sessions matching segment" figure directly. One runReport a day covers
 * every segment.
 *
 * @package Newspack
 */

namespace Newspack;

defined( 'ABSPATH' ) || exit;

/**
 * GA4 segment reach cache.
 */
final class GA4_Segment_Reach {

	const OPTION                 = 'newspack_ga4_segment_reach';
	const REFRESH_ACTION         = 'newspack_ga4_segment_reach_refresh';
	const GROUP                  = 'newspack';
	const ASYNC_GROUP            = 'newspack-async';
	const LAST_ATTEMPT_TRANSIENT = 'newspack_ga4_segment_reach_attempt';
	const LOGGER_HEADER          = 'NEWSPACK-GA4-REACH';
	const RANGE_DAYS             = 7;

	/**
	 * Register hooks.
	 */
	public static function init() {
		add_action( self::REFRESH_ACTION, [ __CLASS__, 'refresh' ] );
		add_action( 'admin_init', [ __CLASS__, 'maybe_schedule' ] );
	}

	/**
	 * The cached reach for the currently connected property, if any.
	 *
	 * A cache left over from a previously connected property is not "the
	 * cache" — its numbers describe a different site's audience. Both the
	 * display path and the scheduling path treat it as absent, which is why
	 * this lives in one place: if only the display path discounted it, a
	 * property switch would show nothing until the next daily refresh.
	 *
	 * @param string|false $property_id Connected property, or false to look it up.
	 * @return array|null Cache payload for the connected property, or null.
	 */
	private static function get_valid_cache( $property_id = false ) {
		if ( false === $property_id ) {
			$property_id = GA4_Client::get_property_id();
		}
		if ( ! $property_id ) {
			return null;
		}
		$cache = get_option( self::OPTION, false );
		if (
			! is_array( $cache )
			|| ! isset( $cache['rows'] )
			|| ! is_array( $cache['rows'] )
			|| ( $cache['property_id'] ?? '' ) !== $property_id
		) {
			return null;
		}
		return $cache;
	}

	/**
	 * Keep a daily refresh scheduled while a GA4 property is connected, drop
	 * it when none is, and enqueue an immediate first fetch when there is no
	 * usable cache yet — a fresh site, or one just pointed at a new property,
	 * should see numbers on the next cron tick, not after a day. Idempotent;
	 * runs on every admin page load.
	 */
	public static function maybe_schedule() {
		if ( ! function_exists( 'as_schedule_recurring_action' ) || ! function_exists( 'as_has_scheduled_action' ) ) {
			return;
		}
		$is_scheduled = as_has_scheduled_action( self::REFRESH_ACTION, [], self::GROUP );
		$property_id  = GA4_Client::get_property_id();
		if ( ! $property_id ) {
			if ( $is_scheduled && function_exists( 'as_unschedule_all_actions' ) ) {
				as_unschedule_all_actions( self::REFRESH_ACTION, [], self::GROUP );
			}
			return;
		}
		if ( ! $is_scheduled ) {
			as_schedule_recurring_action( time() + DAY_IN_SECONDS, DAY_IN_SECONDS, self::REFRESH_ACTION, [], self::GROUP );
			Logger::log( 'Scheduled daily GA4 segment reach refresh.', self::LOGGER_HEADER );
		}
		// First fetch, also covering a switch to a new property: a cache from
		// the old one is not usable (see get_valid_cache), and waiting a day
		// for the recurring refresh would leave the list blank meanwhile. The
		// transient backs off retries while the fetch keeps failing (e.g. no
		// auth route), so a broken site attempts at most once an hour rather
		// than on every admin page load.
		if (
			null === self::get_valid_cache( $property_id )
			&& false === get_transient( self::LAST_ATTEMPT_TRANSIENT )
			&& function_exists( 'as_enqueue_async_action' )
			&& ! as_has_scheduled_action( self::REFRESH_ACTION, [], self::ASYNC_GROUP )
		) {
			as_enqueue_async_action( self::REFRESH_ACTION, [], self::ASYNC_GROUP );
		}
	}

	/**
	 * Fetch the last week of reach from GA4 and cache the parsed counts.
	 *
	 * One report: sessions per (segment, event) pair. A failure keeps the
	 * previous cache — the list shows staler numbers, never none.
	 */
	public static function refresh() {
		$property_id = GA4_Client::get_property_id();
		if ( ! $property_id ) {
			return;
		}
		set_transient( self::LAST_ATTEMPT_TRANSIENT, time(), HOUR_IN_SECONDS );
		$request  = [
			'dateRanges'      => [
				[
					'startDate' => self::RANGE_DAYS . 'daysAgo',
					'endDate'   => 'yesterday',
				],
			],
			'dimensions'      => [
				[ 'name' => 'customEvent:segment_id' ],
				[ 'name' => 'eventName' ],
			],
			'metrics'         => [ [ 'name' => 'eventCount' ] ],
			'dimensionFilter' => [
				'filter' => [
					'fieldName'    => 'eventName',
					'inListFilter' => [ 'values' => [ 'np_segment_matched', 'np_segment_won' ] ],
				],
			],
			'limit'           => '10000',
		];
		$response = GA4_Client::with_admin_client(
			function ( $client, $source ) use ( $property_id, $request ) {
				try {
					return $client->run_report( $property_id, $request );
				} catch ( \Throwable $e ) {
					return new \WP_Error( 'newspack_ga4_segment_reach', 'Failed running reach report: ' . $e->getMessage() );
				}
			},
			[ 'require_edit_scope' => false ]
		);
		if ( is_wp_error( $response ) ) {
			Logger::log( 'Reach refresh failed: ' . $response->get_error_message(), self::LOGGER_HEADER );
			return;
		}

		$rows      = [];
		$row_items = isset( $response['rows'] ) && is_array( $response['rows'] ) ? $response['rows'] : [];
		foreach ( $row_items as $row ) {
			$segment_id = isset( $row['dimensionValues'][0]['value'] ) ? (string) $row['dimensionValues'][0]['value'] : '';
			$event_name = isset( $row['dimensionValues'][1]['value'] ) ? (string) $row['dimensionValues'][1]['value'] : '';
			$count      = isset( $row['metricValues'][0]['value'] ) ? (int) $row['metricValues'][0]['value'] : 0;
			if ( '' === $segment_id ) {
				continue;
			}
			if ( ! isset( $rows[ $segment_id ] ) ) {
				$rows[ $segment_id ] = [
					'matched' => 0,
					'won'     => 0,
				];
			}
			if ( 'np_segment_matched' === $event_name ) {
				$rows[ $segment_id ]['matched'] = $count;
			} elseif ( 'np_segment_won' === $event_name ) {
				$rows[ $segment_id ]['won'] = $count;
			}
		}

		update_option(
			self::OPTION,
			[
				'property_id' => $property_id,
				'fetched_at'  => time(),
				'range_days'  => self::RANGE_DAYS,
				'rows'        => $rows,
			],
			false
		);
		Logger::log( sprintf( 'Stored reach for %d segment IDs on property %s.', count( $rows ), $property_id ), self::LOGGER_HEADER );
	}

	/**
	 * Attach cached reach to the segments payload the wizard returns.
	 *
	 * Three states, deliberately distinct for the UI: no valid same-property
	 * cache — payload untouched, so the feature stays invisible where the
	 * pipeline cannot work; cache with a row — numbers; cache without a row —
	 * explicit nulls, rendered as "no data yet" rather than zero (a young
	 * segment and GA thresholding look the same from here).
	 *
	 * @param mixed $segments Segments array from the configuration manager.
	 * @return mixed Decorated segments, or the input untouched.
	 */
	public static function decorate_segments( $segments ) {
		if ( ! is_array( $segments ) ) {
			return $segments;
		}
		$cache = self::get_valid_cache();
		if ( null === $cache ) {
			return $segments;
		}
		// The report range ends the day before the fetch.
		$as_of = gmdate( 'Y-m-d', ( isset( $cache['fetched_at'] ) ? (int) $cache['fetched_at'] : time() ) - DAY_IN_SECONDS );
		foreach ( $segments as &$segment ) {
			if ( ! is_array( $segment ) || ! isset( $segment['id'] ) ) {
				continue;
			}
			$row              = $cache['rows'][ (string) $segment['id'] ] ?? null;
			$segment['reach'] = [
				'matched' => $row ? (int) $row['matched'] : null,
				'won'     => $row ? (int) $row['won'] : null,
				'as_of'   => $as_of,
			];
		}
		return $segments;
	}
}
GA4_Segment_Reach::init();
