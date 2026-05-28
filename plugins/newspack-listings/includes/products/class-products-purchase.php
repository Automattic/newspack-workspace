<?php
/**
 * Newspack Listings - Handles purchase form input via self-serve listings block
 * and checkout via WooCommerce. In the future, if we support other purchase
 * platforms (e.g. Stripe) these should get added here, or this class should get
 * broken out into additional provider classes.
 *
 * @package Newspack_Listings
 */

namespace Newspack_Listings;

use Newspack_Listings\Settings;
use Newspack_Listings\Core;
use Newspack_Listings\Block_Patterns;
use Newspack_Listings\Featured;

defined( 'ABSPATH' ) || exit;

/**
 * Products class.
 * Sets up WooCommerce products for listings.
 */
final class Products_Purchase extends Products {
	/**
	 * Constructor.
	 */
	public function __construct() {
		// Handle form input and pass to the checkout page.
		add_action( 'wp_loaded', [ $this, 'handle_purchase_form' ], 99 );

		// WooCommerce checkout actions (when purchasing a listing product).
		add_filter( 'pre_option_woocommerce_enable_guest_checkout', [ $this, 'force_require_account_for_listings' ] );
		add_filter( 'pre_option_woocommerce_enable_signup_and_login_from_checkout', [ $this, 'force_account_creation_and_login_for_listings' ] );
		add_filter( 'pre_option_woocommerce_enable_checkout_login_reminder', [ $this, 'force_account_creation_and_login_for_listings' ] );
		add_action( 'woocommerce_checkout_billing', [ $this, 'listing_details_summary' ] );
		add_filter( 'woocommerce_billing_fields', [ $this, 'listing_details_billing_fields' ] );
		add_action( 'woocommerce_checkout_update_order_meta', [ $this, 'listing_checkout_update_order_meta' ] );
		add_action( 'woocommerce_thankyou_order_received_text', [ $this, 'listing_append_thank_you' ], 99, 2 );
	}

	/**
	 * Remove all listing products from the cart.
	 */
	public function clear_cart() {
		$products = self::get_products();
		if ( ! $products ) {
			return;
		}

		$cart = \WC()->cart;

		if ( $cart ) {
			foreach ( $cart->get_cart() as $cart_key => $cart_item ) {
				if ( ! empty( $cart_item['product_id'] ) && in_array( $cart_item['product_id'], array_values( $products ) ) ) {
					$cart->remove_cart_item( $cart_key );
				}
			}
		}
	}

	/**
	 * Handle submission of the purchase form block.
	 */
	public function handle_purchase_form() {
		$purchase_type = filter_input( INPUT_GET, 'listing-purchase-type', FILTER_SANITIZE_FULL_SPECIAL_CHARS );

		// Only if coming from a non-checkout page.
		if ( is_checkout() ) {
			return;
		}

		// Only if purchase type is valid.
		if ( 'single' !== $purchase_type && 'subscription' !== $purchase_type ) {
			return;
		}

		$is_single       = 'single' === $purchase_type;
		$is_subscription = 'subscription' === $purchase_type;

		// Get form submission data.
		$title_single       = filter_input( INPUT_GET, 'listing-title-single', FILTER_SANITIZE_FULL_SPECIAL_CHARS );
		$single_type        = filter_input( INPUT_GET, 'listing-single-type', FILTER_SANITIZE_FULL_SPECIAL_CHARS );
		$featured_upgrade   = filter_input( INPUT_GET, 'listing-featured-upgrade', FILTER_SANITIZE_FULL_SPECIAL_CHARS );
		$title_subscription = filter_input( INPUT_GET, 'listing-title-subscription', FILTER_SANITIZE_FULL_SPECIAL_CHARS );
		$premium_upgrade    = filter_input( INPUT_GET, 'listing-premium-upgrade', FILTER_SANITIZE_FULL_SPECIAL_CHARS );
		$listing_to_renew   = filter_input( INPUT_GET, 'listing-renew', FILTER_SANITIZE_NUMBER_INT );
		$listing_title      = __( 'Untitled listing', 'newspack-listings' );

		// If a title was provided, use it.
		if ( $is_single && ! empty( $title_single ) ) {
			$listing_title = $title_single;
		} elseif ( $is_subscription && ! empty( $title_subscription ) ) {
			$listing_title = $title_subscription;
		}

		// Single purchases must have a valid listing type.
		if ( $is_single && empty( $single_type ) ) {
			return;
		}

		$products = self::get_products();
		if ( ! $products ) {
			return;
		}

		$this->clear_cart();
		$products_to_purchase = [];
		$checkout_query_args  = [
			'listing-title' => urlencode( sanitize_text_field( $listing_title ) ),
			'purchase-type' => urlencode( sanitize_text_field( $purchase_type ) ),
		];

		if ( $is_single ) {
			$products_to_purchase[]              = $products[ self::PRODUCT_META_KEYS['single'] ];
			$checkout_query_args['listing-type'] = urlencode( sanitize_text_field( $single_type ) );

			if ( ! empty( $listing_to_renew ) ) {
				$checkout_query_args['listing_renewed'] = urlencode( $listing_to_renew );
			}

			if ( 'on' === $featured_upgrade ) {
				$products_to_purchase[] = $products[ self::PRODUCT_META_KEYS['featured'] ];
			}
		} else {
			$products_to_purchase[] = $products[ self::PRODUCT_META_KEYS['subscription'] ];

			if ( 'on' === $premium_upgrade ) {
				$products_to_purchase[] = $products[ self::PRODUCT_META_KEYS['premium'] ];
			}
		}

		foreach ( $products_to_purchase as $product_id ) {
			\WC()->cart->add_to_cart( $product_id );
		}

		$checkout_url = add_query_arg(
			$checkout_query_args,
			\wc_get_page_permalink( 'checkout' )
		);

		// Redirect to checkout.
		\wp_safe_redirect( $checkout_url );
		exit;
	}

	/**
	 * For self-serve listings, a customer account is required so the user can log in and manage their listings.
	 * If a listing product is in the cart, force the checkout to require an account regardless of WC settings.
	 *
	 * @param string $value String value 'yes' or 'no' of the WC setting to allow guest checkout.
	 *
	 * @return string Filtered value.
	 */
	public function force_require_account_for_listings( $value ) {
		$products = self::get_products();
		if ( ! $products ) {
			return $value;
		}

		$cart = \WC()->cart;

		if ( $cart ) {
			foreach ( $cart->get_cart() as $cart_key => $cart_item ) {
				if ( ! empty( $cart_item['product_id'] ) && in_array( $cart_item['product_id'], array_values( $products ) ) ) {
					$value = 'no';
					break;
				}
			}
		}

		return $value;
	}

	/**
	 * For self-serve listings, a customer account is required so the user can log in and manage their listings.
	 * If a listing product is in the cart, force the checkout to allow account creation/login regardless of WC settings.
	 *
	 * @param string $value String value 'yes' or 'no' of the WC setting to allow guest checkout.
	 *
	 * @return string Filtered value.
	 */
	public function force_account_creation_and_login_for_listings( $value ) {
		$products = self::get_products();
		if ( ! $products ) {
			return $value;
		}

		$cart = \WC()->cart;

		if ( $cart ) {
			foreach ( $cart->get_cart() as $cart_key => $cart_item ) {
				if ( ! empty( $cart_item['product_id'] ) && in_array( $cart_item['product_id'], array_values( $products ) ) ) {
					$value = 'yes';
					break;
				}
			}
		}

		return $value;
	}

	/**
	 * Show listing details in checkout summary.
	 */
	public function listing_details_summary() {
		$params        = filter_input_array( INPUT_GET, FILTER_SANITIZE_FULL_SPECIAL_CHARS );
		$listing_title = isset( $params['listing-title'] ) ? $params['listing-title'] : null;
		$is_renewal    = isset( $params['listing_renewed'] ) ? $params['listing_renewed'] : false;
		$listing_types = self::get_listing_types();
		$listing_type  = array_reduce(
			$listing_types,
			function( $acc, $type ) use ( $params ) {
				if ( isset( $params['listing-type'] ) && $type['slug'] === $params['listing-type'] ) {
					$acc = $type['name'];
				}
				return $acc;
			},
			null
		);

		if ( $listing_title || $listing_type ) : ?>
			<h4><?php echo esc_html__( 'Listing Details', 'newspack-listings' ); ?></h4>
			<?php if ( $is_renewal ) : ?>
				<?php $single_expiration_period = Settings::get_settings( 'newspack_listings_single_purchase_expiration' ); ?>
				<p>
					<?php
						echo esc_html(
							sprintf(
								// Translators: if renewing, explain what that does.
								__( 'The following listing will be extended by %d days:', 'newspack-listings' ),
								$single_expiration_period
							)
						);
					?>
				</p>
			<?php else : ?>
				<p><?php echo esc_html__( 'You can update listing details after purchase.', 'newspack-listings' ); ?></p>
			<?php endif; ?>
			<ul>
			<?php
		endif;

		if ( $listing_title ) :
			?>
			<li><strong><?php echo esc_html__( 'Listing Title: ', 'newspack-listings' ); ?></strong><?php echo esc_html( $listing_title ); ?></li>
			<?php
		endif;
		if ( $listing_type ) :
			?>
			<li><strong><?php echo esc_html__( 'Listing Type: ', 'newspack-listings' ); ?></strong><?php echo esc_html( $listing_type ); ?></li>
			<?php
		endif;
		if ( $listing_title || $listing_type ) :
			?>
			</ul>
			<?php
		endif;
	}


	/**
	 * Add hidden billing fields for listing details.
	 *
	 * @param Array $form_fields WC form fields.
	 */
	public function listing_details_billing_fields( $form_fields ) {
		$params = filter_input_array( INPUT_GET, FILTER_SANITIZE_FULL_SPECIAL_CHARS );
		$fields = array_keys( self::ORDER_META_KEYS );

		if ( is_array( $params ) ) {
			foreach ( $params as $param => $value ) {
				if ( ! empty( $value ) && in_array( str_replace( '-', '_', $param ), $fields ) ) {
					$form_fields[ sanitize_text_field( $param ) ] = [
						'type'    => 'text',
						'default' => sanitize_text_field( $value ),
						'class'   => [ 'hide' ],
					];
				}
			}
		}

		return $form_fields;
	}

	/**
	 * Update WC order with listing details from hidden form fields.
	 * Also mark the purchasing customer as a Listings customer, create a listing post for the order, and associate it with the customer.
	 * This is the big function that ties together Newspack Listings with WooCommerce functionality.
	 *
	 * @param String $order_id WC order id.
	 */
	public function listing_checkout_update_order_meta( $order_id ) {
		$params = filter_input_array( INPUT_POST, FILTER_SANITIZE_FULL_SPECIAL_CHARS );

		if ( is_array( $params ) ) {
			foreach ( $params as $param => $value ) {
				if ( in_array( $param, array_keys( self::ORDER_META_KEYS ) ) ) {
					$order = wc_get_order( $order_id );
					if ( ! $order ) {
						continue;
					}
					$order->update_meta_data( $order_id, sanitize_text_field( self::ORDER_META_KEYS[ $param ] ), sanitize_text_field( $value ) );
					$order->save();
				}
			}
		}

		// Look up the purchasing customer and set relevant user meta.
		$order = \wc_get_order( $order_id );
		if ( $order ) {
			$customer_id = $order->get_customer_id();
			if ( $customer_id ) {
				// Get info on purchased products.
				$products        = self::get_products();
				$purchased_items = array_values(
					array_map(
						function( $item ) {
							return $item->get_product_id();
						},
						$order->get_items()
					)
				);

				// If none of the purchased items is a listing product, no need to proceed.
				if ( 0 === count( array_intersect( array_values( $products ), $purchased_items ) ) ) {
					return;
				}

				// If the order does include listing products, the purchaser is a customer.
				update_user_meta( $customer_id, self::CUSTOMER_META_KEYS['is_listings_customer'], 1 );
				$customer = new \WP_User( $customer_id );
				$customer->add_cap( 'edit_posts' ); // Let this customer edit their own posts.
				$customer->add_cap( 'edit_published_posts' ); // Let this customer edit their own posts even after they're published.
				$customer->add_cap( 'upload_files' ); // Let this customer upload media for featured and inline images.

				$purchase_type    = isset( $params['purchase-type'] ) ? $params['purchase-type'] : 'single';
				$is_subscription  = 'subscription' === $purchase_type && in_array( $products[ self::PRODUCT_META_KEYS['subscription'] ], $purchased_items );
				$is_single        = ! $is_subscription && in_array( $products[ self::PRODUCT_META_KEYS['single'] ], $purchased_items );
				$listing_type     = isset( $params['listing-type'] ) ? $params['listing-type'] : null;
				$single_upgrade   = $is_single && in_array( $products[ self::PRODUCT_META_KEYS['featured'] ], $purchased_items );
				$premium_upgrade  = $is_subscription && in_array( $products[ self::PRODUCT_META_KEYS['premium'] ], $purchased_items );
				$post_title       = isset( $params['listing-title'] ) ? $params['listing-title'] : __( 'Untitled listing', 'newspack-listings' );
				$post_type        = Core::NEWSPACK_LISTINGS_POST_TYPES['marketplace'];
				$listing_to_renew = isset( $params['listing_renewed'] ) ? $params['listing_renewed'] : null;
				$post_content     = false;
				$block_pattern    = false;
				$subscriptions    = false;

				// If we're rewnewing a previously purchased listing, republish it instead of creating a new listing.
				if ( $listing_to_renew ) {
					$listing = get_post( (int) $listing_to_renew );

					if ( $listing ) {
						$original_order_id        = get_post_meta( $listing->ID, self::POST_META_KEYS['listing_order'], true );
						$single_expiration_period = Settings::get_settings( 'newspack_listings_single_purchase_expiration' );
						$set_expiration           = Core::get_expiration_date( $listing->ID );
						$expires_date             = $set_expiration ? $set_expiration : ( new \DateTime( $listing->post_date ) )->modify( '+' . (string) $single_expiration_period . ' days' );
						$now                      = current_time( 'mysql' ); // Current time in local timezone.
						$update_args              = [
							'ID'            => $listing->ID,
							'post_date'     => $now,
							'post_date_gmt' => get_gmt_from_date( $now ),
							'post_status'   => 'publish',
							'meta_input'    => [
								'newspack_listings_expiration_date' => $expires_date->modify( '+' . (string) $single_expiration_period . ' days' )->format( 'Y-m-d\TH:i:s' ),
							],
						];

						// When renewing a single-purchase post, set the post status to 'publish' and
						// also extend the expiration date to reset the expiration clock.
						wp_update_post( $update_args );

						// Clear "expired" meta flag so that the listing is no longer displayed as expired in the My Account UI.
						delete_post_meta( $listing->ID, self::POST_META_KEYS['listing_has_expired'] );

						// Associate the original order with the new order so we can show details in the Order Details screen.
						if ( $original_order_id ) {
							update_post_meta( $order_id, sanitize_text_field( self::ORDER_META_KEYS['listing_original_order'] ), $original_order_id );
						}
					} else {
						return new \WP_Error(
							'newspack_listings_renew_self_serve_listing_error',
							sprintf(
								// Translators: error message when we're not able to renew the given post ID.
								__( 'Error renewing listing with ID %d. Please contact the site administrators to renew.', 'newspack-listings' ),
								$listing_to_renew
							)
						);
					}

					return;
				}

				if ( $is_subscription ) {
					$post_type     = Core::NEWSPACK_LISTINGS_POST_TYPES['place'];
					$block_pattern = Block_Patterns::get_block_patterns( 'business_1' );
				} else {
					if ( 'event' === $listing_type ) {
						$post_type = Core::NEWSPACK_LISTINGS_POST_TYPES['event'];
					}
					if ( 'classified' === $listing_type ) {
						$block_pattern = Block_Patterns::get_block_patterns( 'classified_1' );
					}
					if ( 'real-estate' === $listing_type ) {
						$block_pattern = Block_Patterns::get_block_patterns( 'real_estate_1' );
					}
				}

				if ( $block_pattern ) {
					$post_content = $block_pattern['settings']['content'];
				}

				$args = [
					'post_author' => $customer_id,
					'post_status' => 'draft',
					'post_title'  => $post_title,
					'post_type'   => $post_type,
				];

				if ( $post_content ) {
					$args['post_content'] = $post_content;
				}

				$post_id = wp_insert_post( $args );

				if ( is_wp_error( $post_id ) ) {
					return new \WP_Error(
						'newspack_listings_create_self_serve_listing_error',
						esc_html__( 'Error creating a listing for this purchase. Please contact the site administrators to create a listing.', 'newspack-listings' )
					);
				}

				// Associate the generated post with this order.
				update_post_meta( $post_id, self::POST_META_KEYS['listing_order'], $order_id );

				// If purchasing an upgrade product, set the post to featured status.
				if ( $single_upgrade || $premium_upgrade ) {
					Featured::set_featured_status( $post_id );
				}
			}
		}
	}

	/**
	 * For listing purchases, append links to edit the purchased listing or manage the account to the thank you message.
	 *
	 * @param string   $message Thank you message, set in Customizer for Newspack sites.
	 * @param WC_Order $order WooCommerce order object.
	 *
	 * @return string Filtered message.
	 */
	public static function listing_append_thank_you( $message, $order ) {
		if ( ! $order ) {
			return $message;
		}

		$order_id          = $order->get_id();
		$original_order_id = get_post_meta( $order_id, self::ORDER_META_KEYS['listing_original_order'], true );

		if ( $original_order_id ) {
			$order_id = $original_order_id;
		}

		$listing      = self::get_listing_by_order_id( $order_id );
		$account_page = get_option( 'woocommerce_myaccount_page_id', false );

		if ( $listing ) {
			$message .= sprintf(
				// Translators: edit listing message and link.
				__( ' You can now <a href="%1$s">edit your listing</a>%2$s.', 'newspack-listings' ),
				get_edit_post_link( $listing->ID ),
				// Translators: manage account message and link.
				$account_page ? sprintf( __( ' or <a href="%s">manage your account</a>', 'newspack-listings' ), get_permalink( $account_page ) ) : ''
			);
		}

		return $message;
	}
}
