<?php
/**
 * Reader Segment condition matcher — gates a pricing rule on the reader's
 * Newspack segment membership (any-of), read from the segment snapshot.
 *
 * Registered into the dynamic-pricing engine by Dynamic_Pricing_Bridges, and
 * referenced only there — so this class (which implements the engine's
 * interface) is autoloaded only when the engine is active. The matching logic
 * lives in the engine-free Reader_Segment_Eligibility helper.
 *
 * @package Newspack
 */

namespace Newspack;

use Automattic\WooCommerce\DynamicPricing\Condition_Matcher;
use Automattic\WooCommerce\DynamicPricing\Pricing_Context;

defined( 'ABSPATH' ) || exit;

/**
 * Reader-segment eligibility condition for the dynamic-pricing engine.
 */
final class Reader_Segment_Condition_Matcher implements Condition_Matcher {
	/**
	 * Stable matcher id.
	 */
	public function id(): string {
		return 'reader_segment';
	}

	/**
	 * Any-of: pass when the reader is in any selected segment. Empty value → off
	 * (pass); guest / no account → fail (cannot be in a segment).
	 *
	 * @param Pricing_Context $ctx   Pricing context.
	 * @param mixed           $value Selected segment IDs.
	 */
	public function matches( Pricing_Context $ctx, mixed $value ): bool {
		$ids = is_array( $value ) ? $value : [];
		if ( empty( $ids ) ) {
			return true;
		}
		// Preview mode: the impact preview prices as-if a reader in a segment by
		// passing an assume_segments signal — match against that instead of a real
		// reader's snapshot.
		if ( isset( $ctx->signals['assume_segments'] ) ) {
			return Reader_Segment_Eligibility::matches_assumed( $ids, (array) $ctx->signals['assume_segments'] );
		}
		$user_id = $ctx->customer instanceof \WC_Customer ? (int) $ctx->customer->get_id() : 0;
		return Reader_Segment_Eligibility::is_in_any( $user_id, $ids );
	}

	/**
	 * Operator-facing label.
	 */
	public function display_name(): string {
		return __( 'Reader segment', 'newspack-plugin' );
	}

	/**
	 * Vocab descriptor: a multi-select of the publisher's segments.
	 */
	public function describe(): array {
		return [
			'field_type' => 'select',
			'multiple'   => true,
			'label'      => $this->display_name(),
			'help'       => __( 'Only apply to readers in any of the selected Campaigns segments. Uses the reader\'s last-known segment match.', 'newspack-plugin' ),
			'options'    => self::segment_options(),
		];
	}

	/**
	 * Segment options ({ value:int, label:string }), or [] when Campaigns is inactive.
	 */
	private static function segment_options(): array {
		if ( ! class_exists( '\Newspack_Segments_Model' ) ) {
			return [];
		}
		$out = [];
		foreach ( \Newspack_Segments_Model::get_segments() as $segment ) {
			if ( isset( $segment['id'], $segment['name'] ) ) {
				$out[] = [
					'value' => (int) $segment['id'],
					'label' => (string) $segment['name'],
				];
			}
		}
		return $out;
	}

	/**
	 * Classic-metabox multi-select (parity with the React editor).
	 *
	 * @param mixed $value Selected segment IDs.
	 */
	public function render_form( mixed $value ): void {
		$selected = is_array( $value ) ? array_map( 'strval', $value ) : [];
		echo '<select name="wcdp_conditions[reader_segment][]" multiple size="5">';
		foreach ( self::segment_options() as $opt ) {
			printf(
				'<option value="%1$s" %2$s>%3$s</option>',
				esc_attr( (string) $opt['value'] ),
				selected( in_array( (string) $opt['value'], $selected, true ), true, false ),
				esc_html( $opt['label'] )
			);
		}
		echo '</select>';
	}

	/**
	 * Parse the classic-metabox submission to an int[] of segment IDs (or null).
	 *
	 * @param array $post Raw $_POST.
	 */
	public function parse_form( array $post ): mixed {
		$raw = $post['wcdp_conditions']['reader_segment'] ?? [];
		$ids = is_array( $raw ) ? array_values( array_map( 'intval', $raw ) ) : [];
		return empty( $ids ) ? null : $ids;
	}
}
