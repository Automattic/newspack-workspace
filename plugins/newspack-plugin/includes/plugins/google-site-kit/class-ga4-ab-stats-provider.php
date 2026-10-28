<?php
/**
 * A/B test counts from GA4, through Site Kit.
 *
 * Newspack Campaigns asks one stats provider for raw per-variant counts and
 * computes rates and confidence itself. This provider answers from the GA4
 * Data API, using the events Newspack already sends and the custom
 * dimensions it already registers, so a site needs nothing beyond a Site Kit
 * Analytics connection.
 *
 * @package Newspack
 */

namespace Newspack;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * The GA4 stats provider for Campaigns A/B tests.
 *
 * Requests and responses are plain arrays in the Data API's JSON shape. The
 * runner, injected or Site Kit's, is the only place that touches Site Kit's
 * client classes, which keeps the request builders testable without Site Kit
 * installed and lets a test hand in canned responses.
 */
final class GA4_AB_Stats_Provider implements \Newspack_Popups_AB_Stats_Provider {

	const SLUG                  = 'ga4-site-kit';
	const MAX_REQUESTS_PER_TEST = 4;
	const ROW_LIMIT             = 100;
	const LOGGER_HEADER         = 'NEWSPACK-GA4-AB-STATS';

	/**
	 * Site Kit's Analytics settings option. Read by name because this class
	 * must answer whether Site Kit is connected when Site Kit's classes are
	 * not loaded.
	 */
	const SITE_KIT_SETTINGS_OPTION = 'googlesitekit_analytics-4_settings';

	const METRIC_READERS = 'totalUsers';
	const METRIC_EVENTS  = 'eventCount';

	/**
	 * The dimensions the requests use. All are GA4 custom dimensions that
	 * `GA4_Custom_Dimensions` registers on every connected property; the
	 * parameter names are the ones the tracking code sends.
	 */
	const DIM_PROMPT       = 'customEvent:newspack_popup_id';
	const DIM_LOGGED_IN    = 'customEvent:logged_in';
	const DIM_ACTION       = 'customEvent:action';
	const DIM_ACTION_TYPE  = 'customEvent:action_type';
	const DIM_PRODUCT_TYPE = 'customEvent:product_type';

	/**
	 * `logged_in` is sent as the strings `yes` and `no`, see
	 * `GoogleSiteKit::get_custom_event_parameters()`.
	 */
	const LOGGED_IN_VALUE = 'yes';

	const EVENT_PROMPT     = 'np_prompt_interaction';
	const EVENT_REGISTERED = 'np_reader_registered';
	const EVENT_NEWSLETTER = 'np_newsletter_subscribed';
	const EVENT_CHECKOUT   = 'np_modal_checkout_interaction';

	/**
	 * Runs one report: `( string $property_id, array $request )` returning the
	 * response as an array, or a WP_Error. Null means Site Kit's runner.
	 *
	 * @var callable|null
	 */
	private $runner;

	/**
	 * Reports run for the test in progress, against MAX_REQUESTS_PER_TEST.
	 *
	 * @var int
	 */
	private $requests_made = 0;

	/**
	 * Constructor.
	 *
	 * @param callable|null $runner Report runner; tests inject one.
	 */
	public function __construct( ?callable $runner = null ) {
		$this->runner = $runner;
	}

	/**
	 * Provider slug, as the dashboard reports it.
	 *
	 * @return string
	 */
	public function get_slug(): string {
		return self::SLUG;
	}

	/**
	 * Whether Site Kit can run reports on this site, without calling out.
	 *
	 * @return true|WP_Error
	 */
	public function is_available() {
		if ( null !== $this->runner ) {
			return true;
		}
		if ( ! defined( 'GOOGLESITEKIT_PLUGIN_MAIN_FILE' ) ) {
			return new WP_Error( 'newspack_ga4_ab_stats', __( 'Site Kit is not installed.', 'newspack' ) );
		}
		if ( ! self::get_property_id() ) {
			return new WP_Error( 'newspack_ga4_ab_stats', __( 'Connect Google Analytics through Site Kit.', 'newspack' ) );
		}
		return true;
	}

	/**
	 * Per-variant readers and conversions for one test and window.
	 *
	 * Readers are distinct users who saw one of the test's prompts. The
	 * logged-in share comes from a second readers report split on the
	 * `logged_in` dimension. Conversions are distinct users who completed the
	 * goal's event on one of the prompts; a goal that maps to two events sums
	 * two reports, so a reader who completed both is counted twice.
	 *
	 * @param array  $test  Test, with `variants` keyed by letter, each carrying a prompt `id`.
	 * @param string $goal  Conversion goal.
	 * @param string $start Window start, Y-m-d.
	 * @param string $end   Window end, Y-m-d.
	 * @return array|WP_Error
	 */
	public function get_counts( array $test, string $goal, string $start, string $end ) {
		$property_id = self::get_property_id();
		if ( ! $property_id ) {
			return new WP_Error( 'newspack_ga4_ab_stats', __( 'Connect Google Analytics through Site Kit.', 'newspack' ) );
		}

		$letters  = [];
		$variants = [];
		foreach ( $test['variants'] ?? [] as $letter => $variant ) {
			$variants[ $letter ] = [
				'readers'           => 0,
				'conversions'       => 0,
				'logged_in_readers' => 0,
			];
			if ( ! empty( $variant['id'] ) ) {
				$letters[ (string) $variant['id'] ] = $letter;
			}
		}
		$result = [
			'unit'     => 'readers',
			'note'     => __( 'Counts update once a day.', 'newspack' ),
			'variants' => $variants,
		];
		$prompt_ids = array_keys( $letters );
		if ( empty( $prompt_ids ) ) {
			return $result;
		}

		$this->requests_made = 0;
		$metric              = self::METRIC_READERS;

		$readers = $this->run( $property_id, self::build_readers_request( $prompt_ids, $start, $end, $metric, false ) );
		if ( is_wp_error( $readers ) ) {
			return $readers;
		}
		foreach ( self::parse_rows( $readers ) as $prompt_id => $row ) {
			if ( isset( $letters[ $prompt_id ] ) ) {
				$result['variants'][ $letters[ $prompt_id ] ]['readers'] += $row['count'];
			}
		}

		$split = $this->run( $property_id, self::build_readers_request( $prompt_ids, $start, $end, $metric, true ) );
		if ( is_wp_error( $split ) ) {
			return $split;
		}
		foreach ( self::parse_rows( $split, true ) as $prompt_id => $row ) {
			if ( isset( $letters[ $prompt_id ] ) ) {
				$result['variants'][ $letters[ $prompt_id ] ]['logged_in_readers'] += $row['logged_in'];
			}
		}

		foreach ( self::build_conversion_requests( $goal, $prompt_ids, $start, $end, $metric ) as $request ) {
			$conversions = $this->run( $property_id, $request );
			if ( is_wp_error( $conversions ) ) {
				return $conversions;
			}
			foreach ( self::parse_rows( $conversions ) as $prompt_id => $row ) {
				if ( isset( $letters[ $prompt_id ] ) ) {
					$result['variants'][ $letters[ $prompt_id ] ]['conversions'] += $row['count'];
				}
			}
		}

		return $result;
	}

	/**
	 * The readers report: distinct users who saw one of the prompts, per prompt.
	 *
	 * @param array  $prompt_ids      Prompt post IDs.
	 * @param string $start           Window start, Y-m-d.
	 * @param string $end             Window end, Y-m-d.
	 * @param string $metric          METRIC_READERS or METRIC_EVENTS.
	 * @param bool   $split_logged_in Add the `logged_in` dimension.
	 * @return array
	 */
	public static function build_readers_request( array $prompt_ids, string $start, string $end, string $metric, bool $split_logged_in ): array {
		$dimensions = [ self::DIM_PROMPT ];
		if ( $split_logged_in ) {
			$dimensions[] = self::DIM_LOGGED_IN;
		}
		return self::build_request(
			$dimensions,
			$metric,
			$start,
			$end,
			[
				self::exact( 'eventName', self::EVENT_PROMPT ),
				self::exact( self::DIM_ACTION, 'seen' ),
				self::in_list( self::DIM_PROMPT, $prompt_ids ),
			]
		);
	}

	/**
	 * The conversion reports for a goal: distinct users who completed the
	 * goal's event on one of the prompts, per prompt.
	 *
	 * A form submission completes as one of three events depending on the
	 * form, so it takes two reports: the registration and newsletter events
	 * share a report, and the checkout form has its own.
	 *
	 * @param string $goal       Conversion goal.
	 * @param array  $prompt_ids Prompt post IDs.
	 * @param string $start      Window start, Y-m-d.
	 * @param string $end        Window end, Y-m-d.
	 * @param string $metric     METRIC_READERS or METRIC_EVENTS.
	 * @return array[] Zero or more requests.
	 */
	public static function build_conversion_requests( string $goal, array $prompt_ids, string $start, string $end, string $metric ): array {
		$checkout_success = [
			self::exact( 'eventName', self::EVENT_CHECKOUT ),
			self::exact( self::DIM_ACTION, 'form_submission_success' ),
		];
		switch ( $goal ) {
			case 'link_click':
				$clauses = [
					[
						self::exact( 'eventName', self::EVENT_PROMPT ),
						self::exact( self::DIM_ACTION, 'clicked' ),
					],
				];
				break;
			case 'form_submission':
				$clauses = [
					[ self::in_list( 'eventName', [ self::EVENT_REGISTERED, self::EVENT_NEWSLETTER ] ) ],
					$checkout_success,
				];
				break;
			case 'donation':
				$clauses = [ array_merge( $checkout_success, [ self::exact( self::DIM_ACTION_TYPE, 'donation' ) ] ) ];
				break;
			case 'subscription':
				$clauses = [
					array_merge(
						$checkout_success,
						[
							self::exact( self::DIM_ACTION_TYPE, 'checkout_button' ),
							self::exact( self::DIM_PRODUCT_TYPE, 'subscription' ),
						]
					),
				];
				break;
			default:
				return [];
		}
		$requests = [];
		foreach ( $clauses as $filters ) {
			$filters[]  = self::in_list( self::DIM_PROMPT, $prompt_ids );
			$requests[] = self::build_request( [ self::DIM_PROMPT ], $metric, $start, $end, $filters );
		}
		return $requests;
	}

	/**
	 * Per-prompt totals from a report response.
	 *
	 * @param array $response        The report response as an array.
	 * @param bool  $split_logged_in Whether the rows carry the `logged_in` dimension second.
	 * @return array `[ '<prompt_id>' => [ 'count' => int, 'logged_in' => int ] ]`.
	 */
	public static function parse_rows( array $response, bool $split_logged_in = false ): array {
		$parsed = [];
		foreach ( $response['rows'] ?? [] as $row ) {
			$dimensions = $row['dimensionValues'] ?? [];
			$prompt_id  = (string) ( $dimensions[0]['value'] ?? '' );
			if ( '' === $prompt_id ) {
				continue;
			}
			$count = (int) ( $row['metricValues'][0]['value'] ?? 0 );
			if ( ! isset( $parsed[ $prompt_id ] ) ) {
				$parsed[ $prompt_id ] = [
					'count'     => 0,
					'logged_in' => 0,
				];
			}
			$parsed[ $prompt_id ]['count'] += $count;
			if ( $split_logged_in && self::LOGGED_IN_VALUE === ( $dimensions[1]['value'] ?? '' ) ) {
				$parsed[ $prompt_id ]['logged_in'] += $count;
			}
		}
		return $parsed;
	}

	/**
	 * Whether GA4 withheld rows from a response to protect small user counts.
	 *
	 * @param array $response The report response as an array.
	 * @return bool
	 */
	public static function is_thresholded( array $response ): bool {
		return ! empty( $response['metadata']['subjectToThresholding'] );
	}

	/**
	 * The connected GA4 property ID from Site Kit's settings, or an empty string.
	 *
	 * @return string
	 */
	public static function get_property_id(): string {
		$settings = get_option( self::SITE_KIT_SETTINGS_OPTION, [] );
		return is_array( $settings ) && ! empty( $settings['propertyID'] ) ? (string) $settings['propertyID'] : '';
	}

	/**
	 * Run one report within the per-test budget.
	 *
	 * @param string $property_id GA4 property ID.
	 * @param array  $request     Report request.
	 * @return array|WP_Error
	 */
	private function run( string $property_id, array $request ) {
		if ( $this->requests_made >= self::MAX_REQUESTS_PER_TEST ) {
			return new WP_Error( 'newspack_ga4_ab_stats_budget', __( 'This test needs more reports than the stats source allows.', 'newspack' ) );
		}
		++$this->requests_made;
		$runner   = $this->runner ?? [ $this, 'run_through_site_kit' ];
		$response = call_user_func( $runner, $property_id, $request );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		return is_array( $response ) ? $response : [];
	}

	/**
	 * Site Kit's runner. Set up in a later change; until then every report is an error.
	 *
	 * @param string $property_id GA4 property ID.
	 * @param array  $request     Report request.
	 * @return array|WP_Error
	 */
	private function run_through_site_kit( string $property_id, array $request ) {
		return new WP_Error( 'newspack_ga4_ab_stats', __( 'Site Kit is not installed.', 'newspack' ) );
	}

	/**
	 * A report request in the Data API's JSON shape.
	 *
	 * @param string[] $dimensions Dimension names.
	 * @param string   $metric     Metric name.
	 * @param string   $start      Window start, Y-m-d.
	 * @param string   $end        Window end, Y-m-d.
	 * @param array[]  $filters    Filter expressions, ANDed.
	 * @return array
	 */
	private static function build_request( array $dimensions, string $metric, string $start, string $end, array $filters ): array {
		return [
			'dimensions'      => array_map( fn( $name ) => [ 'name' => $name ], $dimensions ),
			'metrics'         => [ [ 'name' => $metric ] ],
			'dateRanges'      => [
				[
					'startDate' => $start,
					'endDate'   => $end,
				],
			],
			'dimensionFilter' => [ 'andGroup' => [ 'expressions' => $filters ] ],
			'limit'           => self::ROW_LIMIT,
		];
	}

	/**
	 * An exact-match filter expression.
	 *
	 * @param string $field Dimension name.
	 * @param string $value Value.
	 * @return array
	 */
	private static function exact( string $field, string $value ): array {
		return [
			'filter' => [
				'fieldName'    => $field,
				'stringFilter' => [
					'matchType' => 'EXACT',
					'value'     => $value,
				],
			],
		];
	}

	/**
	 * An in-list filter expression. Values are sent as strings, as GA4 stores them.
	 *
	 * @param string $field  Dimension name.
	 * @param array  $values Values.
	 * @return array
	 */
	private static function in_list( string $field, array $values ): array {
		return [
			'filter' => [
				'fieldName'    => $field,
				'inListFilter' => [ 'values' => array_values( array_map( 'strval', $values ) ) ],
			],
		];
	}
}
