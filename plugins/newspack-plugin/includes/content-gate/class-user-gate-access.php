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
		add_action( 'edit_user_profile', [ __CLASS__, 'render_user_gate_access' ], 9 );
		add_action( 'show_user_profile', [ __CLASS__, 'render_user_gate_access' ], 9 );
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
		if ( 'institution' === $slug && is_array( $value ) ) {
			return self::format_institution_names( $value );
		}

		return sprintf(
			/* translators: %s: value or comma-separated list of values. */
			'<code>%s</code>',
			is_array( $value ) ? implode( ', ', $value ) : (string) $value
		);
	}

	/**
	 * Format a list of product IDs as a comma-separated list of linked product names.
	 *
	 * @param array $product_ids Product IDs.
	 *
	 * @return string Comma-separated, linked product names (HTML).
	 */
	private static function format_product_names( $product_ids ) {
		$names = array_map(
			function( $product_id ) {
				if ( function_exists( 'wc_get_product' ) ) {
					$product = wc_get_product( $product_id );
					if ( $product ) {
						// A variation has no edit screen of its own; its parent's
						// product editor is where it is managed.
						$edit_id = $product->get_parent_id() ? $product->get_parent_id() : $product->get_id();
						return self::link( get_edit_post_link( $edit_id ), $product->get_name() );
					}
				}
				return '#' . intval( $product_id );
			},
			$product_ids
		);
		return implode( ', ', $names );
	}

	/**
	 * Format a list of institution IDs as a comma-separated list of linked institution names.
	 *
	 * @param array $institution_ids Institution IDs.
	 *
	 * @return string Comma-separated, linked institution names (HTML).
	 */
	private static function format_institution_names( $institution_ids ) {
		$names = array_map(
			function( $institution_id ) {
				$institution = get_post( $institution_id );
				if ( $institution && Institution::POST_TYPE === $institution->post_type ) {
					return self::link(
						admin_url( 'admin.php?page=newspack-audience-access-control#/institutions/' . intval( $institution_id ) ),
						$institution->post_title
					);
				}
				return '#' . intval( $institution_id );
			},
			$institution_ids
		);
		return implode( ', ', $names );
	}

	/**
	 * Build an escaped link, or plain escaped text when there is nothing to link to.
	 *
	 * @param string|null $url  URL, or empty when the item has no admin screen.
	 * @param string      $text Link text (unescaped).
	 *
	 * @return string HTML safe to print through wp_kses() with `a[href]` allowed.
	 */
	private static function link( $url, $text ) {
		if ( empty( $url ) ) {
			return esc_html( $text );
		}
		return sprintf( '<a href="%1$s">%2$s</a>', esc_url( $url ), esc_html( $text ) );
	}

	/**
	 * Links to the specific subscriptions or orders that satisfy a passing rule
	 * for the user.
	 *
	 * Only the two ownership rules map to concrete records a publisher can open;
	 * every other rule returns nothing. Access granted by a third-party filter
	 * (e.g. a Newspack Network sibling site) has no local record, so a rule can
	 * pass with an empty list here.
	 *
	 * @param string $slug    Rule slug.
	 * @param mixed  $value   Rule value.
	 * @param int    $user_id User ID.
	 * @param array  $context Evaluation context from evaluate_gate_for_user().
	 *
	 * @return string[] Escaped `<a>` links labelled `#<id>`, safe to print through wp_kses() with `a[href]` allowed.
	 */
	public static function get_granting_entity_links( $slug, $value, $user_id, $context = [] ) {
		$entities = [];
		if ( 'subscription' === $slug && function_exists( 'wcs_get_subscription' ) ) {
			foreach ( self::get_granting_subscription_ids( $value, $user_id, $context ) as $subscription_id ) {
				$entities[ $subscription_id ] = \wcs_get_subscription( $subscription_id );
			}
		} elseif ( 'one_time_purchase' === $slug && function_exists( 'wc_get_order' ) ) {
			foreach ( Access_Rules::get_one_time_purchase_order_ids( $user_id, $value ) as $order_id ) {
				$entities[ $order_id ] = \wc_get_order( $order_id );
			}
		}

		$links = [];
		foreach ( $entities as $id => $entity ) {
			$url = $entity && method_exists( $entity, 'get_edit_order_url' ) ? $entity->get_edit_order_url() : '';
			$links[] = self::link( $url, '#' . $id );
		}
		return $links;
	}

	/**
	 * IDs of the user's subscriptions that satisfy a subscription rule: owned
	 * subscriptions plus group subscriptions the user is a member of, using
	 * the same status and payment-recovery-grace reading as the rule itself.
	 *
	 * @param mixed $value   Rule value: product IDs, or empty for any subscription.
	 * @param int   $user_id User ID.
	 * @param array $context Evaluation context from evaluate_gate_for_user().
	 *
	 * @return int[] Subscription IDs.
	 */
	private static function get_granting_subscription_ids( $value, $user_id, $context ) {
		// A malformed value fails the rule closed, so there is nothing to list.
		if ( Access_Rules::is_malformed_options_backed_value( $value ) ) {
			return [];
		}
		$product_ids = is_array( $value ) ? $value : [];
		return Access_Rules::with_evaluation_context(
			$context,
			function () use ( $product_ids, $user_id ) {
				$grace = (bool) Access_Rules::get_evaluation_context( 'payment_recovery_grace', true );
				$ids   = WooCommerce_Connection::get_active_subscriptions_for_user( $user_id, $product_ids, $grace );

				if ( class_exists( __NAMESPACE__ . '\\Group_Subscription' ) ) {
					foreach ( Group_Subscription::get_group_subscriptions_for_user( $user_id ) as $subscription ) {
						if ( ! $subscription ) {
							continue;
						}
						$grants_access = $subscription->has_status( WooCommerce_Connection::ACTIVE_SUBSCRIPTION_STATUSES )
							|| ( $grace && WooCommerce_Connection::is_subscription_in_payment_recovery( $subscription ) );
						if ( ! $grants_access ) {
							continue;
						}
						$has_product = empty( $product_ids );
						foreach ( $product_ids as $product_id ) {
							if ( $subscription->has_product( $product_id ) ) {
								$has_product = true;
								break;
							}
						}
						if ( $has_product ) {
							$ids[] = $subscription->get_id();
						}
					}
				}

				return array_values( array_unique( array_map( 'intval', $ids ) ) );
			}
		);
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
		<h2><?php esc_html_e( 'Access Control', 'newspack-plugin' ); ?></h2>
		<p>
			<?php esc_html_e( 'Shows the active content gate(s) the user can bypass, which access rules grant access, and how.', 'newspack-plugin' ); ?>
			<?php
			echo wp_kses(
				sprintf(
				/* translators: %s: link to the Newspack Content Gate settings page. */
					__( '<a href="%s">Configure content gates</a>.', 'newspack-plugin' ),
					esc_url( admin_url( 'admin.php?page=newspack-audience-access-control' ) )
				),
				[ 'a' => [ 'href' => [] ] ]
			);
			?>
		</p>
		<table class="form-table" role="presentation">
			<?php foreach ( $gates as $gate ) : ?>
				<?php $result = self::evaluate_gate_for_user( $gate, $user->ID ); ?>
				<tr>
					<th>
						<span style="margin-right: 5px;" aria-hidden="true">
							<?php echo wp_kses( $result['can_bypass'] ? '<span style="color: #00a32a;">&#10003;</span>' : '<span style="color: #d63638;">&#10005;</span>', [ 'span' => [ 'style' => [] ] ] ); ?>
						</span>
						<span class="screen-reader-text"><?php echo $result['can_bypass'] ? esc_html__( 'Pass', 'newspack-plugin' ) : esc_html__( 'Fail', 'newspack-plugin' ); ?></span>
						<?php
						echo wp_kses(
							self::link( admin_url( 'admin.php?page=newspack-audience-access-control#/edit/' . intval( $gate['id'] ) ), $gate['title'] ),
							[ 'a' => [ 'href' => [] ] ]
						);
						?>
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
											<strong><?php echo esc_html( $rule['name'] ); ?>:</strong>
											<?php
											echo wp_kses(
												self::format_rule_value( $rule['slug'], $rule['value'] ),
												[
													'a'    => [ 'href' => [] ],
													'code' => [],
												]
											);
											?>
											<?php
											$granting_links = $rule['passes'] ? self::get_granting_entity_links( $rule['slug'], $rule['value'], $user->ID, $result['context'] ) : [];
											if ( ! empty( $granting_links ) ) :
												?>
												(<?php echo wp_kses( implode( ', ', $granting_links ), [ 'a' => [ 'href' => [] ] ] ); ?>)
											<?php endif; ?>
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
