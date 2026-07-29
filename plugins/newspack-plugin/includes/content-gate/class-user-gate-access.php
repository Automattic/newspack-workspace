<?php
/**
 * Newspack Content Gate User Access.
 *
 * Displays gate bypass information on user profile pages.
 *
 * @package Newspack
 */

namespace Newspack;

defined( 'ABSPATH' ) || exit;

/**
 * User Gate Access class.
 */
class User_Gate_Access {

	/**
	 * Initialize hooks.
	 */
	public static function init() {
		add_action( 'edit_user_profile', [ __CLASS__, 'render_user_gate_access' ] );
		add_action( 'show_user_profile', [ __CLASS__, 'render_user_gate_access' ] );
	}

	/**
	 * Get published gates that have custom access enabled.
	 *
	 * @return array Array of gates with active custom access.
	 */
	private static function get_custom_access_gates() {
		$gates = Content_Gate::get_gates( Content_Gate::GATE_CPT, 'publish' );
		$custom_access_gates = array_filter(
			$gates,
			function( $gate ) {
				return ! is_wp_error( $gate ) && ! empty( $gate['custom_access']['active'] );
			}
		);
		return array_values( $custom_access_gates );
	}

	/**
	 * Evaluate gate access for a specific user and return detailed results.
	 *
	 * @param array $gate    Gate data from Content_Gate::get_gate().
	 * @param int   $user_id User ID to evaluate.
	 *
	 * @return array {
	 *     @type bool  $can_bypass Whether the user can bypass the gate.
	 *     @type array $groups     Array of group results, each containing:
	 *         @type bool  $passes Whether the group passes (AND logic).
	 *         @type array $rules  Array of rule results with slug, name, value, and passes.
	 *     @type array $context    The evaluation context the rules were evaluated under.
	 *                             Returned so callers that re-run a rule callback to
	 *                             explain *why* it passed (e.g. the contact-metadata
	 *                             sync's source labels) can evaluate under the same
	 *                             gate settings rather than the callbacks' defaults.
	 * }
	 */
	public static function evaluate_gate_for_user( $gate, $user_id ) {
		$access_rules = Access_Rules::normalize_rules( $gate['custom_access']['access_rules'] ?? [] );
		$context      = [ 'payment_recovery_grace' => $gate['custom_access']['payment_recovery_grace'] ?? true ];

		// Empty rules means the gate does not restrict — matches Content_Restriction_Control behavior.
		if ( empty( $access_rules ) ) {
			return [
				'can_bypass' => true,
				'groups'     => [],
				'context'    => $context,
			];
		}

		$can_bypass = false;
		$groups     = [];

		foreach ( $access_rules as $group_rules ) {
			$group_passes = true;
			$rules        = [];

			foreach ( $group_rules as $rule ) {
				if ( ! isset( $rule['slug'] ) ) {
					continue;
				}
				$rule_config = Access_Rules::get_rule( $rule['slug'] );
				$passes      = Access_Rules::evaluate_rule( $rule['slug'], $rule['value'] ?? null, $user_id, $context );

				if ( ! $passes ) {
					$group_passes = false;
				}

				$rules[] = [
					'slug'   => $rule['slug'],
					'name'   => $rule_config ? $rule_config['name'] : $rule['slug'],
					'value'  => $rule['value'] ?? '',
					'passes' => $passes,
				];
			}

			if ( $group_passes ) {
				$can_bypass = true;
			}

			$groups[] = [
				'passes' => $group_passes,
				'rules'  => $rules,
			];
		}

		return [
			'can_bypass' => $can_bypass,
			'groups'     => $groups,
			'context'    => $context,
		];
	}

	/**
	 * Format rule value for human-readable display.
	 *
	 * @param string $slug  Rule slug.
	 * @param mixed  $value Rule value.
	 *
	 * @return string Formatted value.
	 */
	private static function format_rule_value( $slug, $value ) {
		// Ahead of the generic empty-value branch below: an unconfigured
		// one_time_purchase rule denies access, so reporting it as "(any)" would
		// tell the reader the opposite of how the rule just evaluated.
		if ( 'one_time_purchase' === $slug ) {
			$sanitized_value = Access_Rules::sanitize_one_time_purchase_value( $value );
			$products_label  = empty( $sanitized_value['product_ids'] )
				? __( '(no products selected)', 'newspack-plugin' )
				: self::format_product_names( $sanitized_value['product_ids'] );
			if ( 'forever' === $sanitized_value['duration_unit'] ) {
				/* translators: %s: list of product names. */
				return sprintf( __( '%s (forever)', 'newspack-plugin' ), $products_label );
			}
			$duration_value = $sanitized_value['duration_value'];
			if ( 'days' === $sanitized_value['duration_unit'] ) {
				/* translators: 1: list of product names, 2: number of days. */
				return sprintf( _n( '%1$s (%2$d day from purchase)', '%1$s (%2$d days from purchase)', $duration_value, 'newspack-plugin' ), $products_label, $duration_value );
			}
			if ( 'months' === $sanitized_value['duration_unit'] ) {
				/* translators: 1: list of product names, 2: number of months. */
				return sprintf( _n( '%1$s (%2$d month from purchase)', '%1$s (%2$d months from purchase)', $duration_value, 'newspack-plugin' ), $products_label, $duration_value );
			}
			/* translators: %s: list of product names. Shown when the stored duration is unrecognized; the rule then never grants access. */
			return sprintf( __( '%s (invalid duration, grants no access)', 'newspack-plugin' ), $products_label );
		}

		if ( empty( $value ) ) {
			return __( '(any)', 'newspack-plugin' );
		}

		if ( 'subscription' === $slug && is_array( $value ) ) {
			return self::format_product_names( $value );
		}

		if ( is_array( $value ) ) {
			return implode( ', ', $value );
		}

		return (string) $value;
	}

	/**
	 * Format a list of product IDs as a comma-separated list of product names.
	 *
	 * @param array $product_ids Product IDs.
	 *
	 * @return string Comma-separated product names.
	 */
	private static function format_product_names( $product_ids ) {
		$names = array_map(
			function( $product_id ) {
				if ( function_exists( 'wc_get_product' ) ) {
					$product = wc_get_product( $product_id );
					if ( $product ) {
						return $product->get_name();
					}
				}
				return '#' . $product_id;
			},
			$product_ids
		);
		return implode( ', ', $names );
	}

	/**
	 * Render gate access info on user profile page.
	 *
	 * @param \WP_User $user The user being viewed.
	 */
	public static function render_user_gate_access( $user ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$gates = self::get_custom_access_gates();
		if ( empty( $gates ) ) {
			return;
		}
		?>
		<h2><?php esc_html_e( 'Content Gate Access', 'newspack-plugin' ); ?></h2>
		<table class="form-table" role="presentation">
			<?php foreach ( $gates as $gate ) : ?>
				<?php $result = self::evaluate_gate_for_user( $gate, $user->ID ); ?>
				<tr>
					<th>
						<span style="margin-right: 5px;" aria-hidden="true">
							<?php echo wp_kses( $result['can_bypass'] ? '<span style="color: #00a32a;">&#10003;</span>' : '<span style="color: #d63638;">&#10005;</span>', [ 'span' => [ 'style' => [] ] ] ); ?>
						</span>
						<span class="screen-reader-text"><?php echo $result['can_bypass'] ? esc_html__( 'Pass', 'newspack-plugin' ) : esc_html__( 'Fail', 'newspack-plugin' ); ?></span>
						<?php echo esc_html( $gate['title'] ); ?>
					</th>
					<td>
						<?php if ( empty( $result['groups'] ) ) : ?>
							<p class="description"><?php esc_html_e( 'No access rules configured.', 'newspack-plugin' ); ?></p>
						<?php else : ?>
							<?php
							$has_and_groups = false;
							foreach ( $result['groups'] as $group ) {
								if ( count( $group['rules'] ) > 1 ) {
									$has_and_groups = true;
									break;
								}
							}
							?>
							<?php foreach ( $result['groups'] as $group_index => $group ) : ?>
								<?php if ( $has_and_groups && count( $result['groups'] ) > 1 ) : ?>
									<p><strong>
										<?php
										printf(
											/* translators: %d: group number. */
											esc_html__( 'Group %d:', 'newspack-plugin' ),
											intval( $group_index + 1 )
										);
										?>
									</strong></p>
								<?php elseif ( $group_index > 0 ) : ?>
									<p style="color: #757575; margin: 8px 0;"><em><?php esc_html_e( 'or', 'newspack-plugin' ); ?></em></p>
								<?php endif; ?>
								<ul style="margin: 4px 0;">
									<?php foreach ( $group['rules'] as $rule ) : ?>
										<li style="margin: 2px 0;">
											<span style="margin-right: 5px;" aria-hidden="true">
												<?php echo wp_kses( $rule['passes'] ? '<span style="color: #00a32a;">&#10003;</span>' : '<span style="color: #d63638;">&#10005;</span>', [ 'span' => [ 'style' => [] ] ] ); ?>
											</span>
											<span class="screen-reader-text"><?php echo $rule['passes'] ? esc_html__( 'Pass', 'newspack-plugin' ) : esc_html__( 'Fail', 'newspack-plugin' ); ?></span>
											<?php echo esc_html( $rule['name'] ); ?>:
											<code><?php echo esc_html( self::format_rule_value( $rule['slug'], $rule['value'] ) ); ?></code>
										</li>
									<?php endforeach; ?>
								</ul>
							<?php endforeach; ?>
						<?php endif; ?>
					</td>
				</tr>
			<?php endforeach; ?>
		</table>
		<?php
	}
}
User_Gate_Access::init();
