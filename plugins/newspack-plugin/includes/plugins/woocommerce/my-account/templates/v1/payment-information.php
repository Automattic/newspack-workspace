<?php
/**
 * My Account Payment Information page. Replaces both "Payment Methods" and "Addresses" pages.
 *
 * @author   Newspack
 * @category WooCommerce Subscriptions/Templates
 * @package  Newspack
 */

namespace Newspack;

defined( 'ABSPATH' ) || exit;

$saved_methods = \wc_get_customer_saved_methods_list( get_current_user_id() );
$has_methods   = (bool) $saved_methods;
$types         = \wc_get_account_payment_methods_types();

\do_action( 'newspack_woocommerce_before_account_payment_methods', $has_methods ); ?>

<section id="payment-methods">
	<h4 class="newspack-ui__font--m newspack-ui__spacing-top--0"><?php \esc_html_e( 'Payment methods', 'newspack-plugin' ); ?></h4>
	<?php if ( $has_methods ) : ?>

		<div class="newspack-my-account__payment-methods newspack-ui__row newspack-ui__row--no-padding">
		<?php $payment_method_columns = \wc_get_account_payment_methods_columns(); ?>
		<?php foreach ( $saved_methods as $type => $methods ) : // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited ?>
			<?php
			foreach ( $methods as $method ) :
				$delete_url          = $method['actions']['delete']['url'] ?? '';
				$delete_modal_suffix = $delete_url ? md5( $delete_url ) : '';
				?>
				<div class="newspack-ui__box newspack-ui__box--border payment-method<?php echo ! empty( $method['is_default'] ) ? ' default-payment-method' : ''; ?>">
					<div class="payment-method__content">
					<?php
					$parsed_date = null;
					if ( ! empty( $method['expires'] ) ) :
						$parsed_date = date_parse_from_format( 'n/y', $method['expires'] );
					endif;
					foreach ( $payment_method_columns as $column_id => $column_name ) :
						?>
							<?php
							if ( \has_action( 'newspack_woocommerce_account_payment_methods_column_' . $column_id ) ) {
								\do_action( 'newspack_woocommerce_account_payment_methods_column_' . $column_id, $method );
							} elseif ( 'method' === $column_id ) {
								?>
								<h3 class="newspack-ui__font--s">
									<strong><?php echo \esc_html( \wc_get_credit_card_type_label( $method['method']['brand'] ) ); ?></strong>
								</h3>
								<?php if ( ! empty( $method['method']['last4'] ) ) : ?>
									<p class="newspack-ui__font--s">
										<?php
										printf(
											/* translators: last 4 digits */
											\esc_html__( 'Ending in %s', 'newspack-plugin' ),
											\esc_html( $method['method']['last4'] )
										);
										?>
									</p>
									<?php
								endif;
							} elseif ( 'expires' === $column_id && isset( $parsed_date['error_count'] ) && 0 === $parsed_date['error_count'] ) {
								?>
								<p class="newspack-ui__font--s">
									<?php
									printf(
										/* translators: expiration date */
										\esc_html__( 'Exp. %s', 'newspack-plugin' ),
										\esc_html( $method['expires'] )
									);
									?>
								</p>
								<?php
							} elseif ( 'actions' === $column_id ) {
								// Close the content div and start the actions div.
								?>
								</div>
								<div class="newspack-ui__box__actions">
									<?php
									// Check if we have badges to display.
									$has_expired_badge = (
										! empty( $method['expires'] ) &&
										! empty( $parsed_date ) &&
										empty( $parsed_date['errors'] ) &&
										! empty( $parsed_date['year'] ) &&
										! empty( $parsed_date['month'] ) &&
										(
											(int) gmdate( 'Y' ) > (int) $parsed_date['year'] ||
											( (int) gmdate( 'Y' ) === (int) $parsed_date['year'] && (int) gmdate( 'm' ) > (int) $parsed_date['month'] )
										)
									);
									$has_default_badge = ! empty( $method['is_default'] );

									// Only show badges container if we have badges.
									if ( $has_expired_badge || $has_default_badge ) :
										?>
										<div class="newspack-ui__box__badges">
											<?php if ( $has_expired_badge ) : ?>
												<span class="newspack-ui__badge newspack-ui__badge--secondary"><?php \esc_html_e( 'Expired', 'newspack-plugin' ); ?></span>
											<?php endif; ?>
											<?php if ( $has_default_badge ) : ?>
												<span class="newspack-ui__badge newspack-ui__badge--secondary"><?php \esc_html_e( 'Default', 'newspack-plugin' ); ?></span>
											<?php endif; ?>
										</div>
										<?php
									endif;
									ksort( $method['actions'] );
									?>
									<div class="newspack-ui__dropdown">
										<button class="newspack-ui__dropdown__toggle newspack-ui__button newspack-ui__button--icon newspack-ui__button--ghost newspack-ui__button--small">
											<?php Newspack_UI_Icons::print_svg( 'more' ); ?>
											<span class="screen-reader-text"><?php \esc_html_e( 'More', 'newspack-plugin' ); ?></span>
										</button>
										<div class="newspack-ui__dropdown__content">
											<ul>
											<?php
											foreach ( $method['actions'] as $key => $action ) : // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
												if ( 'delete' === $key || 'wcs_deletion_error' === $key ) {
													$action['name'] = __( 'Delete payment method', 'newspack-plugin' ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
												}

												$action_classes = trim( 'newspack-ui__button newspack-ui__button--ghost ' . \sanitize_html_class( $key ) . ( 'wcs_deletion_error' === $key ? ' disabled' : '' ) );
												$attribute_html = '';

												if ( 'delete' === $key && ! empty( $delete_modal_suffix ) ) {
													$action_classes .= ' newspack-my-account__delete-payment-method';
													$attribute_html  = sprintf( ' data-payment-method="%s"', esc_attr( $delete_modal_suffix ) );
												}
												?>
												<li>
													<?php
													printf(
														'<a href="%1$s" class="%2$s"%3$s>%4$s</a>',
														esc_url( $action['url'] ),
														esc_attr( $action_classes ),
														wp_kses_data( $attribute_html ),
														esc_html( $action['name'] )
													);
													?>
												</li>
											<?php endforeach; ?>
											</ul>
										</div>
									</div>
								</div>
								<?php
							}
							?>
						<?php endforeach; ?>
					<?php if ( ! array_key_exists( 'actions', $payment_method_columns ) ) : ?>
						</div>
					<?php endif; ?>
					</div>
				<?php endforeach; ?>
			<?php endforeach; ?>
		</div>

	<?php else : ?>
		<p>
			<?php \esc_html_e( 'You don’t have any payment methods saved yet.', 'newspack-plugin' ); ?>
		</p>

	<?php endif; ?>

	<?php do_action( 'newspack_woocommerce_after_account_payment_methods', $has_methods ); ?>

	<?php if ( \WC()->payment_gateways->get_available_payment_gateways() ) : ?>
		<a class="newspack-ui__button newspack-ui__button--primary newspack-my-account__add-payment-method" href="<?php echo \esc_url( \wc_get_endpoint_url( 'add-payment-method' ) ); ?>"><?php \esc_html_e( 'Add payment method', 'newspack-plugin' ); ?></a>
	<?php endif; ?>
</section>

<section id="addresses">
	<h4 class="newspack-ui__font--m"><?php \esc_html_e( 'Addresses', 'newspack-plugin' ); ?></h4>
	<?php
	$address_types = [ 'billing' => __( 'Billing', 'newspack-plugin' ) ];
	if ( ! \wc_ship_to_billing_address_only() && \wc_shipping_enabled() ) {
		$address_types['shipping'] = __( 'Shipping', 'newspack-plugin' );
	}
	$address_types    = \apply_filters( 'woocommerce_my_account_get_addresses', $address_types );
	$addresses_to_add = [];
	$addresses        = [];
	if ( ! empty( $address_types ) ) :
		?>
		<div class="newspack-my-account__addresses newspack-ui__row newspack-ui__row--no-padding">
		<?php
		foreach ( $address_types as $address_type => $address_label ) :
			$address = \wc_get_account_formatted_address( $address_type );
			if ( $address ) :
				$addresses[ $address_type ] = $address;
				?>
				<div class="newspack-ui__box newspack-ui__box--border woocommerce-Address">
					<div class="address__content">
						<address class="newspack-ui__font--s">
							<?php echo \wp_kses_post( $address ); ?>
						</address>
						<?php
						/**
						 * Used to output content after core address fields.
						 *
						 * @param string $name Address type.
						 * @since 8.7.0
						 */
						do_action( 'newspack_woocommerce_my_account_after_my_address', $address_type );
						?>
					</div>
					<div class="newspack-ui__box__actions">
						<div class="newspack-ui__box__badges">
							<span class="newspack-ui__badge newspack-ui__badge--secondary"><?php echo \esc_html( $address_label ); ?></span>
						</div>
						<div class="newspack-ui__dropdown">
							<button class="newspack-ui__dropdown__toggle newspack-ui__button newspack-ui__button--icon newspack-ui__button--ghost newspack-ui__button--small">
								<?php Newspack_UI_Icons::print_svg( 'more' ); ?>
								<span class="screen-reader-text"><?php \esc_html_e( 'More', 'newspack-plugin' ); ?></span>
							</button>
							<div class="newspack-ui__dropdown__content">
								<ul>
									<li>
										<a href="<?php echo esc_url( \wc_get_endpoint_url( 'edit-address', $address_type ) ); ?>" class="newspack-my-account__edit-address newspack-ui__button newspack-ui__button--ghost edit" data-address-type="<?php echo esc_attr( $address_type ); ?>">
											<?php \esc_html_e( 'Edit', 'newspack-plugin' ); ?>
										</a>
									</li>
									<li>
										<a href="<?php echo esc_url( \wc_get_endpoint_url( 'edit-address', $address_type ) ); ?>" class="newspack-my-account__delete-address newspack-ui__button newspack-ui__button--ghost delete" data-address-type="<?php echo esc_attr( $address_type ); ?>">
											<?php \esc_html_e( 'Delete address', 'newspack-plugin' ); ?>
										</a>
									</li>
								</ul>
							</div>
						</div>
					</div>
				</div>
				<?php
			else :
				$addresses_to_add[] = $address_type;
			endif;
		endforeach;
		?>
		<?php if ( empty( $addresses ) ) : ?>
			<p>
				<?php \esc_html_e( 'You don’t have any addresses saved yet.', 'newspack-plugin' ); ?>
			</p>
		<?php endif; ?>
		</div>
		<?php if ( ! empty( $addresses_to_add ) ) : ?>
			<div class="newspack-ui__button__row">
				<?php foreach ( $addresses_to_add as $address_type ) : ?>
					<a href="<?php echo esc_url( \wc_get_endpoint_url( 'edit-address', $address_type ) ); ?>" class="newspack-my-account__edit-address newspack-ui__button newspack-ui__button--primary" data-address-type="<?php echo esc_attr( $address_type ); ?>">
						<?php
						printf(
							/* translators: address type */
							\esc_html__( 'Add %s address', 'newspack-plugin' ),
							\esc_html( $address_type )
						);
						?>
					</a>
				<?php endforeach; ?>
			</div>
		<?php endif; ?>
	<?php endif; ?>
	<?php do_action( 'newspack_woocommerce_after_account_addresses', $addresses ); ?>
</section>
