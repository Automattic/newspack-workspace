<?php
/**
 * WP-CLI command that moves Campaigns segments off the WooCommerce Memberships
 * criteria and onto the Access Control gate-access criteria.
 *
 * @package Newspack
 */

namespace Newspack\CLI;

use Newspack\Gate_Access_Reader_Data;
use WP_CLI;

defined( 'ABSPATH' ) || exit;

/**
 * Membership segment criteria → gate-access segment criteria.
 */
class Membership_Segments_Migration {

	/**
	 * Each Memberships criterion and the gate-access criterion that replaces it.
	 */
	const CRITERIA_MAP = [
		'active_memberships'     => 'can_access_gates',
		'not_active_memberships' => 'cannot_access_gates',
	];

	/**
	 * Rewrite segments that target "Has / Does not have active membership" onto
	 * "Can / Cannot access content gate".
	 *
	 * The membership criteria exist only while Memberships is active. Once it is
	 * deactivated they are unregistered, Campaigns skips them, and a segment
	 * built on them drops that condition: a "non-members" segment then matches
	 * members too. The gate-access criteria read a list that Access Control keeps
	 * current, and that counts group-subscription members.
	 *
	 * Run it while Memberships is still active. A criterion this command cannot
	 * map can then still be edited in the segment editor, which no longer shows
	 * membership criteria once Memberships is off.
	 *
	 * Each plan maps to the paid gates whose product rules require one of the
	 * plan's products, which is how `migrate-membership-gates` carried the plan
	 * across. Where the rewritten segment can match different readers than the
	 * plans did (a gate admitting holders of other products, a plan product that
	 * passes only together with others, a plan product no gate requires), the
	 * notes column says so. A plan with no gate equivalent leaves its segment criterion
	 * untouched and is reported:
	 *
	 * - a plan granted by free signup or manual assignment, not by a product;
	 * - a plan whose products no paid gate requires;
	 * - a plan ID that no longer exists.
	 *
	 * A segment that already uses the target criterion is reported and left alone,
	 * because combining the two lists would turn an AND into an OR.
	 *
	 * Plans are read from post meta, so the command works with Memberships
	 * deactivated. Run it after `migrate-membership-gates`. Re-running is safe:
	 * a rewritten segment no longer uses the membership criteria.
	 *
	 * Dry-run by default; pass --live to write.
	 *
	 * ## OPTIONS
	 *
	 * [--live]
	 * : Write the changes. Without this flag the command reports what it would do and writes nothing.
	 *
	 * ## EXAMPLES
	 *
	 *     wp newspack migrate-membership-segments
	 *     wp newspack migrate-membership-segments --live
	 *
	 * @param array $args       Positional args (unused).
	 * @param array $assoc_args Named args.
	 *
	 * @return void
	 */
	public function migrate_membership_segments( $args, $assoc_args ) {
		if ( ! class_exists( '\Newspack_Segments_Model' ) ) {
			WP_CLI::error( 'Newspack Campaigns is not active, so there are no segments to migrate.' );
		}
		// With Access Control off the target criteria match no reader, so a
		// rewritten segment would stop showing its prompts to anyone.
		if ( ! Gate_Access_Reader_Data::is_enabled() ) {
			WP_CLI::error( 'Access Control is not enabled (NEWSPACK_CONTENT_GATES). Rewritten segments would match no reader until it is, so enable it first.' );
		}
		$live = (bool) \WP_CLI\Utils\get_flag_value( $assoc_args, 'live', false );

		$gate_products = self::get_paid_gate_products();
		$rows          = [];
		$rewritten     = 0;

		foreach ( \Newspack_Segments_Model::get_segments() as $segment ) {
			$criteria = $segment['criteria'] ?? [];
			$changed  = false;
			foreach ( self::CRITERIA_MAP as $legacy_id => $target_id ) {
				$index = self::find_criterion( $criteria, $legacy_id );
				if ( null === $index ) {
					continue;
				}
				$plan_ids = self::parse_plan_ids( $criteria[ $index ]['value'] ?? [] );
				$row      = [
					'segment'   => sprintf( '%s (#%d)', $segment['name'] ?? '', $segment['id'] ?? 0 ),
					'criterion' => $legacy_id,
					'plans'     => implode( ', ', $plan_ids ),
					'gates'     => '—',
					'notes'     => '',
				];

				if ( null !== self::find_criterion( $criteria, $target_id ) ) {
					$rows[] = $row + [ 'result' => sprintf( 'skipped: the segment already uses %s; combine the two by hand', $target_id ) ];
					continue;
				}

				$gate_ids = [];
				$problems = [];
				foreach ( $plan_ids as $plan_id ) {
					$mapped = self::map_plan( $plan_id, $gate_products );
					if ( is_string( $mapped ) ) {
						$problems[] = $mapped;
					} else {
						$gate_ids = array_merge( $gate_ids, $mapped );
					}
				}
				if ( empty( $plan_ids ) ) {
					$problems[] = 'no plans selected';
				}
				if ( ! empty( $problems ) ) {
					$rows[] = $row + [ 'result' => 'skipped: ' . implode( '; ', $problems ) ];
					continue;
				}

				$gate_ids           = array_values( array_unique( $gate_ids ) );
				$row['notes']       = self::describe_mapping_gaps( $gate_ids, $plan_ids, $gate_products );
				$criteria[ $index ] = [
					'criteria_id' => $target_id,
					// Strings, matching the values the segment editor's checkboxes write.
					'value'       => array_map( 'strval', $gate_ids ),
				];
				$changed            = true;
				$rows[]             = array_merge( $row, [ 'gates' => implode( ', ', $gate_ids ) ] ) + [ 'result' => $live ? 'rewritten' : 'would rewrite' ];
			}

			if ( $changed ) {
				++$rewritten;
				if ( $live ) {
					$segment['criteria'] = $criteria;
					\Newspack_Segments_Model::update_segment( $segment );
				}
			}
		}

		if ( empty( $rows ) ) {
			WP_CLI::success( 'No segments use the membership criteria.' );
			return;
		}

		\WP_CLI\Utils\format_items( 'table', $rows, [ 'segment', 'criterion', 'plans', 'gates', 'result', 'notes' ] );

		$skipped = count( array_filter( $rows, fn( $row ) => str_starts_with( $row['result'], 'skipped' ) ) );
		if ( $skipped ) {
			WP_CLI::warning( sprintf( '%d criteria were left on membership plans. Edit those segments by hand while WooCommerce Memberships is active; the segment editor does not show membership criteria without it.', $skipped ) );
		}
		if ( $live ) {
			WP_CLI::success( sprintf( 'Rewrote %d segment(s).', $rewritten ) );
		} else {
			WP_CLI::success( sprintf( 'Dry run: %d segment(s) would be rewritten. Re-run with --live to write.', $rewritten ) );
		}
	}

	/**
	 * Products each paid gate's product rules require, keyed by gate ID. Built on
	 * the same gates and rule groups the criteria evaluate, so the migration never
	 * writes a gate the segment editor does not offer.
	 *
	 * @return array<int, int[]>
	 */
	private static function get_paid_gate_products(): array {
		$gate_products = [];
		foreach ( Gate_Access_Reader_Data::get_paid_gates() as $gate ) {
			$product_ids = [];
			foreach ( Gate_Access_Reader_Data::get_product_rule_groups( $gate ) as $group ) {
				foreach ( $group as $rule ) {
					$product_ids = array_merge( $product_ids, self::get_rule_product_ids( $rule ) );
				}
			}
			$gate_products[ (int) $gate['id'] ] = array_values( array_unique( $product_ids ) );
		}
		return $gate_products;
	}

	/**
	 * Name where the rewritten segment can match different readers than the plans
	 * did, so the operator can judge each case:
	 *
	 * - a mapped gate admits readers who hold no plan product (the segment widens);
	 * - a plan product passes a mapped gate only together with other products
	 *   (it narrows);
	 * - a plan product no mapped gate requires (its holders change sides).
	 *
	 * @param int[]             $gate_ids      Mapped gate IDs.
	 * @param int[]             $plan_ids      The criterion's plan IDs.
	 * @param array<int, int[]> $gate_products Products each paid gate requires.
	 *
	 * @return string
	 */
	private static function describe_mapping_gaps( array $gate_ids, array $plan_ids, array $gate_products ): string {
		$plan_products = [];
		foreach ( $plan_ids as $plan_id ) {
			$plan_products = array_merge( $plan_products, array_map( 'intval', (array) get_post_meta( $plan_id, '_product_ids', true ) ) );
		}
		$plan_products  = array_values( array_unique( array_filter( $plan_products ) ) );
		$notes          = [];
		$gated_products = [];
		foreach ( $gate_ids as $gate_id ) {
			$gated_products = array_merge( $gated_products, $gate_products[ $gate_id ] ?? [] );
			$gate           = \Newspack\Content_Gate::get_gate( $gate_id );
			$groups         = is_array( $gate ) ? Gate_Access_Reader_Data::get_product_rule_groups( $gate ) : [];

			// A group admits readers with no plan product when each of its rules
			// accepts some product outside the plans.
			$extra = [];
			foreach ( $groups as $group ) {
				$outside = array_map( fn( $rule ) => array_diff( self::get_rule_product_ids( $rule ), $plan_products ), $group );
				if ( ! in_array( [], $outside, true ) ) {
					$extra = array_merge( $extra, ...$outside );
				}
			}
			$extra = array_values( array_unique( $extra ) );
			if ( $extra ) {
				$notes[] = sprintf( 'gate %d also accepts product(s) %s', $gate_id, implode( ', ', $extra ) );
			}

			// A plan product grants the gate alone when some group's every rule accepts it.
			$needs_more = [];
			foreach ( array_intersect( $plan_products, $gate_products[ $gate_id ] ?? [] ) as $product_id ) {
				$passes_alone = false;
				foreach ( $groups as $group ) {
					if ( ! array_filter( $group, fn( $rule ) => ! in_array( $product_id, self::get_rule_product_ids( $rule ), true ) ) ) {
						$passes_alone = true;
						break;
					}
				}
				if ( ! $passes_alone ) {
					$needs_more[] = $product_id;
				}
			}
			if ( $needs_more ) {
				$notes[] = sprintf( 'gate %d requires plan product(s) %s together with other products', $gate_id, implode( ', ', $needs_more ) );
			}
		}
		$uncovered = array_diff( $plan_products, $gated_products );
		if ( $uncovered ) {
			$notes[] = sprintf( 'no mapped gate requires plan product(s) %s', implode( ', ', $uncovered ) );
		}
		return implode( '; ', $notes );
	}

	/**
	 * Products a product rule accepts.
	 *
	 * @param array $rule A subscription or one-time purchase rule.
	 *
	 * @return int[]
	 */
	private static function get_rule_product_ids( array $rule ): array {
		$value = $rule['value'] ?? [];
		return array_map( 'intval', (array) ( 'one_time_purchase' === ( $rule['slug'] ?? '' ) ? ( $value['product_ids'] ?? [] ) : $value ) );
	}

	/**
	 * The paid gates that carry a plan's access, or why there are none.
	 *
	 * @param int               $plan_id       Membership plan ID.
	 * @param array<int, int[]> $gate_products Products each paid gate requires.
	 *
	 * @return int[]|string Gate IDs, or a reason the plan cannot be mapped.
	 */
	private static function map_plan( int $plan_id, array $gate_products ) {
		$plan = get_post( $plan_id );
		if ( ! $plan || 'wc_membership_plan' !== $plan->post_type ) {
			return sprintf( 'plan %d not found', $plan_id );
		}
		$product_ids = array_values( array_filter( array_map( 'intval', (array) get_post_meta( $plan_id, '_product_ids', true ) ) ) );
		// Memberships treats a plan with products and no stored method as a purchase plan.
		$access_method = get_post_meta( $plan_id, '_access_method', true );
		$access_method = $access_method ? $access_method : ( $product_ids ? 'purchase' : '' );
		if ( 'purchase' !== $access_method || empty( $product_ids ) ) {
			return sprintf( '"%s" is not granted by a product purchase', $plan->post_title );
		}
		$gate_ids = array_keys( array_filter( $gate_products, fn( $required ) => (bool) array_intersect( $required, $product_ids ) ) );
		if ( empty( $gate_ids ) ) {
			return sprintf( '"%s": no paid gate requires its products', $plan->post_title );
		}
		return $gate_ids;
	}

	/**
	 * Index of a criterion in a segment's criteria list, or null.
	 *
	 * @param array  $criteria    Segment criteria.
	 * @param string $criteria_id Criterion ID.
	 *
	 * @return int|null
	 */
	private static function find_criterion( array $criteria, string $criteria_id ): ?int {
		foreach ( $criteria as $index => $criterion ) {
			if ( ( $criterion['criteria_id'] ?? '' ) === $criteria_id ) {
				return $index;
			}
		}
		return null;
	}

	/**
	 * Plan IDs from a criterion value, which the editor stores as a list and older
	 * segments may hold as a comma-separated string.
	 *
	 * @param mixed $value Criterion value.
	 *
	 * @return int[]
	 */
	private static function parse_plan_ids( $value ): array {
		if ( is_string( $value ) ) {
			$value = explode( ',', $value );
		}
		return array_values( array_filter( array_map( 'intval', (array) $value ) ) );
	}
}
