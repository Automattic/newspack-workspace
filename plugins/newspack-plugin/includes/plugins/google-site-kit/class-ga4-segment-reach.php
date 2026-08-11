<?php
/**
 * Fetches and caches per-segment reach from GA4, for display in the
 * Campaigns segments list.
 *
 * Reads back the events newspack-popups sends (np_segment_matched /
 * np_segment_won). The report asks for `sessions` rather than `eventCount`:
 * the client aims for one event per segment per session but cannot guarantee
 * it — np_segment_won fires again when a session's priority winner changes,
 * and a reader whose localStorage is unwritable dispatches on every pageview —
 * so counting events would overstate both figures. GA4 deduplicates sessions
 * itself.
 *
 * One runReport a day covers every segment, and its TOTAL aggregation carries
 * the denominator: the deduplicated count of sessions where segmentation ran,
 * which the list reads each segment's share against.
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
	const FAILURE_OPTION         = 'newspack_ga4_segment_reach_failures';
	const PRIORITY_OPTION        = 'newspack_ga4_segment_reach_priority_changed';
	const REFRESH_ACTION         = 'newspack_ga4_segment_reach_refresh';
	const GROUP                  = 'newspack';
	const ASYNC_GROUP            = 'newspack-async';
	const LAST_ATTEMPT_TRANSIENT = 'newspack_ga4_segment_reach_attempt';
	const LOGGER_HEADER          = 'NEWSPACK-GA4-REACH';
	const RANGE_DAYS             = 7;

	/**
	 * Consecutive failed refreshes after which this site stops trying.
	 *
	 * A report that cannot succeed — most commonly because the `segment_id`
	 * custom dimension is not on the property yet — would otherwise retry on
	 * the daily schedule plus once an hour of admin traffic, forever, with
	 * nothing to observe it: the only record is a `Logger::log` call, which
	 * compiles out unless the site defines `NEWSPACK_LOG_LEVEL`. Give up after
	 * a handful of attempts and record why; connecting a different property or
	 * a fresh dimension provisioning run clears the count.
	 */
	const MAX_FAILURES = 5;

	/**
	 * Register hooks.
	 */
	public static function init() {
		add_action( self::REFRESH_ACTION, [ __CLASS__, 'refresh' ] );
		add_action( 'admin_init', [ __CLASS__, 'maybe_schedule' ] );
		// Segment priority lives in term meta, and Campaigns writes it from
		// several paths — the drag-reorder, a single-segment save, and the
		// reindex that follows a deletion. Watching the meta key catches all
		// of them, including changes made outside the wizard.
		add_action( 'updated_term_meta', [ __CLASS__, 'record_priority_change' ], 10, 3 );
		add_action( 'added_term_meta', [ __CLASS__, 'record_priority_change' ], 10, 3 );
	}

	/**
	 * Note when segment priority last changed.
	 *
	 * Reordering is the moment someone most expects these numbers to move, and
	 * it is the one thing that cannot move them: the report still covers the
	 * same days, most of which ran under the previous order. Recording the day
	 * lets the list say so rather than showing figures that quietly answer a
	 * different question.
	 *
	 * @param int    $meta_id  Meta row ID (unused).
	 * @param int    $term_id  Term the meta belongs to.
	 * @param string $meta_key Meta key.
	 */
	public static function record_priority_change( $meta_id, $term_id, $meta_key ) {
		if ( 'priority' !== $meta_key ) {
			return;
		}
		// Campaigns owns the taxonomy, so prefer its constant; the literal
		// keeps this working (and testable) when newspack-popups is not
		// loaded, where the hook simply never matches a real segment.
		$taxonomy = class_exists( 'Newspack_Segments_Model' ) ? \Newspack_Segments_Model::TAX_SLUG : 'popup_segment';
		$term     = get_term( $term_id );
		if ( ! $term || is_wp_error( $term ) || $taxonomy !== $term->taxonomy ) {
			return;
		}
		// A reorder rewrites every segment's priority, so this fires once per
		// segment. Each write carries the same timestamp within a second, and
		// update_option short-circuits an unchanged value, so the burst costs
		// at most a couple of writes to a non-autoloaded option. Cheaper than
		// a per-request static, which would also swallow a second legitimate
		// change in a long-running WP-CLI process.
		update_option( self::PRIORITY_OPTION, time(), false );
	}

	/**
	 * The day priority last changed, when that falls inside the reported
	 * window — otherwise null, since a change older than the window no longer
	 * describes any of these sessions.
	 *
	 * A change *after* the window still counts: the whole report then predates
	 * the current order, which is the most misleading case of all.
	 *
	 * @param string $as_of      Last day the report covers, `Y-m-d`.
	 * @param int    $range_days Days the report covers.
	 * @return string|null `Y-m-d`, or null.
	 */
	private static function priority_change_in_window( $as_of, $range_days ) {
		$changed_at = (int) get_option( self::PRIORITY_OPTION, 0 );
		if ( ! $changed_at ) {
			return null;
		}
		$changed_on   = gmdate( 'Y-m-d', $changed_at );
		$window_start = gmdate( 'Y-m-d', strtotime( $as_of . ' -' . max( 0, $range_days - 1 ) . ' days' ) );
		// Both are Y-m-d, which compares correctly as a string.
		return $changed_on < $window_start ? null : $changed_on;
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
	 * The consecutive-failure record for the currently connected property.
	 *
	 * Scoped to a property for the same reason the cache is: a count carried
	 * over from a property that no longer exists here says nothing about this
	 * one, so connecting a different property starts from zero.
	 *
	 * @param string $property_id Connected property.
	 * @return array `count` (int) and `last_error` (string).
	 */
	private static function get_failures( $property_id ) {
		$record = get_option( self::FAILURE_OPTION, false );
		if ( ! is_array( $record ) || ( $record['property_id'] ?? '' ) !== $property_id ) {
			return [
				'count'      => 0,
				'last_error' => '',
			];
		}
		return [
			'count'      => isset( $record['count'] ) ? (int) $record['count'] : 0,
			'last_error' => isset( $record['last_error'] ) ? (string) $record['last_error'] : '',
		];
	}

	/**
	 * Whether this site has stopped trying for the connected property.
	 *
	 * @param string $property_id Connected property.
	 * @return bool
	 */
	private static function has_given_up( $property_id ) {
		return self::get_failures( $property_id )['count'] >= self::MAX_FAILURES;
	}

	/**
	 * Clear the failure record so refreshes resume.
	 *
	 * Called on a successful fetch, and by GA4_Custom_Dimensions after a
	 * provisioning run — a run that adds the `segment_id` dimension is exactly
	 * what makes a previously impossible report possible, so it should not
	 * have to wait for a property switch.
	 */
	public static function reset_failures() {
		delete_option( self::FAILURE_OPTION );
		delete_transient( self::LAST_ATTEMPT_TRANSIENT );
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
		// `admin_init` also fires on admin-ajax.php, authenticated or not, so
		// this runs on every heartbeat tick, autosave and nopriv AJAX hit.
		// Rescheduling only needs to happen when someone who could have
		// changed the GA4 connection is looking at the admin.
		if ( wp_doing_ajax() ) {
			return;
		}
		// Cheap check first: a non-autoloaded, object-cached get_option, so
		// sites with no GA4 property never pay for a query against
		// actionscheduler_actions (a large table on busy sites).
		$property_id = GA4_Client::get_property_id();
		if ( ! $property_id ) {
			if (
				function_exists( 'as_unschedule_all_actions' )
				&& as_has_scheduled_action( self::REFRESH_ACTION, [], self::GROUP )
			) {
				as_unschedule_all_actions( self::REFRESH_ACTION, [], self::GROUP );
			}
			return;
		}
		if ( ! as_has_scheduled_action( self::REFRESH_ACTION, [], self::GROUP ) ) {
			as_schedule_recurring_action( time() + DAY_IN_SECONDS, DAY_IN_SECONDS, self::REFRESH_ACTION, [], self::GROUP );
			Logger::log( 'Scheduled daily GA4 segment reach refresh.', self::LOGGER_HEADER );
		}
		// First fetch, also covering a switch to a new property: a cache from
		// the old one is not usable (see get_valid_cache), and waiting a day
		// for the recurring refresh would leave the list blank meanwhile. The
		// transient backs off while the fetch keeps failing (e.g. no auth
		// route), so a broken site attempts at most once an hour rather than
		// on every admin page load — and the failure ceiling stops it entirely
		// once the report looks impossible rather than merely unlucky.
		if (
			null === self::get_valid_cache( $property_id )
			&& ! self::has_given_up( $property_id )
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
		// Stop calling Google once the report looks impossible rather than
		// merely unlucky. The recurring action stays scheduled so a later
		// reset_failures() — a property switch, or a provisioning run that
		// adds the missing dimension — resumes it without rescheduling.
		if ( self::has_given_up( $property_id ) ) {
			return;
		}
		// Armed before the call so a refresh that dies mid-flight still backs
		// off; cleared on success so a property switch minutes later gets its
		// immediate fetch rather than waiting out the hour.
		set_transient( self::LAST_ATTEMPT_TRANSIENT, time(), HOUR_IN_SECONDS );
		$request = [
			'dateRanges'         => [
				[
					'startDate' => self::RANGE_DAYS . 'daysAgo',
					'endDate'   => 'yesterday',
				],
			],
			'dimensions'         => [
				[ 'name' => 'customEvent:segment_id' ],
				[ 'name' => 'eventName' ],
			],
			// `sessions`, not `eventCount`. The events are deduplicated per
			// session by the client, but only on a best effort: a reader whose
			// localStorage is unwritable falls back to dispatching on every
			// pageview, and np_segment_won fires once per *segment* per session,
			// so a session whose priority winner changes — a reader registering
			// mid-session, say — emits more than one. Counting events would
			// inflate both figures by however much of that happens. GA4
			// deduplicates `sessions` itself, so the numbers mean what the UI
			// says they mean regardless of dispatch volume.
			'metrics'            => [ [ 'name' => 'sessions' ] ],
			// `sessions` is non-additive, so GA4 computes this total over the
			// underlying data rather than summing the rows — the deduplicated
			// count of sessions touched by either event. Every session where
			// segmentation ran emits at least one np_segment_matched (a real
			// segment, or `none`), which makes this the denominator: the
			// audience Campaigns could actually see and classify.
			'metricAggregations' => [ 'TOTAL' ],
			'dimensionFilter'    => [
				'filter' => [
					'fieldName'    => 'eventName',
					'inListFilter' => [ 'values' => [ 'np_segment_matched', 'np_segment_won' ] ],
				],
			],
			'limit'              => '10000',
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
			self::record_failure( $property_id, $response->get_error_message() );
			return;
		}
		// A response that is not a report must not overwrite good numbers.
		// Both client paths can hand back a non-error, non-report value: the
		// OAuth client returns [] for a 2xx carrying a non-JSON body (an edge
		// error page, a proxy interstitial), and the Site Kit client returns
		// null if the re-encode fails. Either would parse to zero rows and
		// blank every segment for a day. A report always carries `rows` or,
		// when it genuinely found nothing, `rowCount`.
		if ( ! is_array( $response ) || ( ! isset( $response['rows'] ) && ! isset( $response['rowCount'] ) ) ) {
			self::record_failure( $property_id, 'Response was not a report.' );
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
				'property_id'    => $property_id,
				'fetched_at'     => time(),
				'range_days'     => self::RANGE_DAYS,
				'as_of'          => self::report_end_date( $response ),
				'total_sessions' => self::report_total( $response ),
				'rows'           => $rows,
			],
			false
		);
		self::reset_failures();
		Logger::log( sprintf( 'Stored reach for %d segment IDs on property %s.', count( $rows ), $property_id ), self::LOGGER_HEADER );
	}

	/**
	 * Total sessions where segmentation ran — the denominator the percentages
	 * are read against.
	 *
	 * The `TOTAL` aggregation requested above, not a sum of the rows: a session
	 * matching three segments appears in three rows, so summing would count it
	 * three times. GA4 computes the total for a non-additive metric over the
	 * underlying data, deduplicated.
	 *
	 * @param array $response Decoded runReport response.
	 * @return int Sessions, or 0 when the total is absent.
	 */
	private static function report_total( array $response ) {
		return isset( $response['totals'][0]['metricValues'][0]['value'] )
			? (int) $response['totals'][0]['metricValues'][0]['value']
			: 0;
	}

	/**
	 * The last calendar day the report covers.
	 *
	 * The request asks for `yesterday`, which GA4 resolves against the
	 * property's own reporting timezone — not ours. Deriving the label from
	 * our clock disagrees with what GA actually counted whenever the two are
	 * on different days: a refresh at 01:00 UTC against a Los Angeles property
	 * covers through the 5th locally while a UTC derivation says the 6th. The
	 * report response carries the property timezone, so use it; fall back to
	 * the UTC derivation only when it is absent.
	 *
	 * @param array $response Decoded runReport response.
	 * @return string `Y-m-d`.
	 */
	private static function report_end_date( array $response ) {
		$timezone = $response['metadata']['timeZone'] ?? '';
		if ( $timezone ) {
			try {
				$yesterday = new \DateTimeImmutable( 'yesterday', new \DateTimeZone( $timezone ) );
				return $yesterday->format( 'Y-m-d' );
			} catch ( \Exception $e ) {
				Logger::log( "Unusable report timezone '$timezone'; falling back to UTC.", self::LOGGER_HEADER );
			}
		}
		return gmdate( 'Y-m-d', time() - DAY_IN_SECONDS );
	}

	/**
	 * Record a failed refresh and log why.
	 *
	 * @param string $property_id Connected property.
	 * @param string $message     Failure reason.
	 */
	private static function record_failure( $property_id, $message ) {
		$count = self::get_failures( $property_id )['count'] + 1;
		update_option(
			self::FAILURE_OPTION,
			[
				'property_id' => $property_id,
				'count'       => $count,
				'last_error'  => $message,
				'last_failed' => time(),
			],
			false
		);
		Logger::log( sprintf( 'Reach refresh failed (%d/%d): %s', $count, self::MAX_FAILURES, $message ), self::LOGGER_HEADER );
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
		// Recorded at fetch time from the property's own reporting timezone
		// (see report_end_date). Caches written before that was stored fall
		// back to the old derivation: the range ends the day before the fetch.
		$as_of = $cache['as_of'] ?? gmdate( 'Y-m-d', ( isset( $cache['fetched_at'] ) ? (int) $cache['fetched_at'] : time() ) - DAY_IN_SECONDS );
		// Surfaced so the label can name the window instead of hardcoding it.
		$range_days = isset( $cache['range_days'] ) ? (int) $cache['range_days'] : self::RANGE_DAYS;
		// Raw counts and the denominator both travel; the percentages are
		// computed for display. Storing the ratio instead would fix the
		// presentation at fetch time and lose the sample size, which is what
		// tells a publisher whether a share is worth acting on.
		$total = isset( $cache['total_sessions'] ) ? (int) $cache['total_sessions'] : 0;
		// Site-wide rather than per-segment: reordering changes which segment
		// wins for a shared set of readers, so a change anywhere in the list
		// qualifies every row's prompt figure, not just the moved segment's.
		$priority_changed = self::priority_change_in_window( $as_of, $range_days );
		foreach ( $segments as &$segment ) {
			if ( ! is_array( $segment ) || ! isset( $segment['id'] ) ) {
				continue;
			}
			$row              = $cache['rows'][ (string) $segment['id'] ] ?? null;
			$segment['reach'] = [
				'matched'          => $row ? (int) $row['matched'] : null,
				'won'              => $row ? (int) $row['won'] : null,
				'total_sessions'   => $total,
				'as_of'            => $as_of,
				'range_days'       => $range_days,
				'priority_changed' => $priority_changed,
			];
		}
		// The loop variable stays bound to the last element, so a caller that
		// copies the result and writes to that element would also mutate this
		// array. Costs one line to close.
		unset( $segment );
		return $segments;
	}
}
GA4_Segment_Reach::init();
