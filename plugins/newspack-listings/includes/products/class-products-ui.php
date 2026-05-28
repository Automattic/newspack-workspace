<?php
/**
 * Newspack Listings - Adds self-serve listings UI to WooCommerce My Account pages.
 *
 * @package Newspack_Listings
 */

namespace Newspack_Listings;

use Newspack_Listings\Core;
use Newspack_Listings\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Products class.
 * Sets up WooCommerce products for listings.
 */
final class Products_Ui extends Products {
	/**
	 * Constructor.
	 */
	public function __construct() {
		// Number of included listings granted to premium subscribers.
		$this->total_included_listings = 10;

		// String representing the "create listing" action, for security purposes.
		$this->create_nonce = 'newspack_listings_create_nonce';

		// String representing the "delete listing" action, for security purposes.
		$this->delete_nonce = 'newspack_listings_delete_nonce';

		// WooCommerce account actions (post-purchase).
		add_action( 'woocommerce_my_account_my_orders_actions', [ $this, 'listing_append_edit_action' ], 10, 2 );
		add_filter( 'woocommerce_my_account_my_orders_columns', [ $this, 'listing_order_status_column' ] );
		add_action( 'woocommerce_my_account_my_orders_column_order-listing-status', [ $this, 'listing_order_status_column_content' ] );
		add_action( 'woocommerce_order_details_after_order_table', [ $this, 'listing_append_details' ] );
		add_action( 'woocommerce_subscription_status_active', [ $this, 'listing_subscription_associate_primary_post' ] );
		add_action( 'woocommerce_subscription_status_updated', [ $this, 'listing_subscription_unpublish_associated_posts' ], 10, 3 );
		add_action( 'woocommerce_subscription_details_after_subscription_table', [ $this, 'listing_subscription_manage_premium_listings' ] );
		add_action( 'wcs_user_removed_item', [ $this, 'listing_subscription_removed_item' ], 10, 2 );
		add_action( 'wcs_user_readded_item', [ $this, 'listing_subscription_readded_item' ], 10, 2 );

		// Create or delete marketplace or event listings via the subscription UI.
		add_action( 'wp_loaded', [ $this, 'create_or_delete_premium_listing' ], 99 );
	}

	/**
	 * Get the base URL of the current page or the My Account page, stripped of query args.
	 *
	 * @return string Cleaned URL.
	 */
	public function get_base_url() {
		return isset( $_SERVER['REQUEST_URI'] ) ? site_url( strtok( sanitize_text_field( $_SERVER['REQUEST_URI'] ), '?' ) ) : get_permalink( get_option( 'woocommerce_myaccount_page_id' ) );
	}

	/**
	 * Append an "edit listing" action button to the order action column when viewing orders in My Account.
	 *
	 * @param array    $actions Actions to be shown for each order.
	 * @param WC_Order $order WooCommerce order object.
	 *
	 * @return array Filtered actions array.
	 */
	public function listing_append_edit_action( $actions, $order ) {
		// Rename default "View" button to avoid confusion with "View Listing" button.
		if ( isset( $actions['view'] ) ) {
			$actions['view']['name'] = __( 'View Details', 'newspack-listings' );
		}

		if ( ! $order ) {
			return $actions;
		}

		$order_id = $order->get_id();
		$listing  = self::get_listing_by_order_id( $order_id );

		if ( $listing ) {
			if ( get_post_meta( $listing->ID, self::POST_META_KEYS['listing_has_expired'], true ) ) {
				$listing_type = Core::NEWSPACK_LISTINGS_POST_TYPES['marketplace'] === $listing->post_type ? 'marketplace' : 'event';
				$renew_url    = add_query_arg(
					[
						'listing-purchase-type' => 'single',
						'listing-renew'         => $listing->ID,
						'listing-title-single'  => $listing->post_title,
						'listing-single-type'   => $listing_type,
					],
					$this->get_base_url()
				);

				$actions['renew'] = [
					'url'  => $renew_url,
					'name' => __( 'Renew Listing', 'newspack-listings' ),
				];
			} else {
				$actions['edit'] = [
					'url'  => get_edit_post_link( $listing->ID ),
					'name' => __( 'Edit Listing', 'newspack-listings' ),
				];
			}
			$actions['preview'] = [
				'url'  => get_permalink( $listing->ID ),
				// Translators: view or preview listing button link.
				'name' => sprintf( __( '%s Listing', 'newspack-listings' ), 'publish' === $listing->post_status ? __( 'View', 'newspack-listings' ) : __( 'Preview', 'newspack-listings' ) ),
			];
		}

		// If this order was a renewal of an existing listing, link back to the original order.
		$original_order_id = get_post_meta( $order_id, self::ORDER_META_KEYS['listing_original_order'], true );
		if ( $original_order_id ) {
			$original_order = \wc_get_order( $original_order_id );

			if ( $original_order ) {
				$actions['original'] = [
					'url'  => $original_order->get_view_order_url(),
					'name' => __( 'View Original Order', 'newspack-listings' ),
				];
			}
		}

		return $actions;
	}

	/**
	 * Add a column to display the listing's current status in the My Orders table.
	 *
	 * @param array $columns Array of table columns.
	 *
	 * @return array Filtered array of table columns.
	 */
	public function listing_order_status_column( $columns ) {
		$new_columns = [];

		foreach ( $columns as $key => $name ) {
			$new_columns[ $key ] = $name;

			// Add Listing Status column after Total column.
			if ( 'order-total' === $key ) {
				$new_columns['order-listing-status'] = __( 'Listing Status', 'newspack-listings' );
			}
		}

		return $new_columns;
	}

	/**
	 * Get the string representation of the given listing's post status.
	 *
	 * @param WP_Post $listing WP Post object representing a listing.
	 *
	 * @return string Listing status: 'published', 'pending', 'future', 'draft', or 'expired'.
	 */
	public function get_listing_status( $listing ) {
		if ( ! $listing->ID ) {
			return __( 'Listing not found: please contact the site administrators.', 'newspack-listings' );
		}

		// If the listing is flagged as expired.
		if ( get_post_meta( $listing->ID, self::POST_META_KEYS['listing_has_expired'], true ) ) {
			return __( 'expired', 'newspack-listings' );
		}

		$single_expiration_period = Settings::get_settings( 'newspack_listings_single_purchase_expiration' );
		$is_published             = 'publish' === $listing->post_status;
		if ( $is_published && 0 < $single_expiration_period ) {
			$publish_date = new \DateTime( $listing->post_date );
			$expires_soon = ( $publish_date->getTimestamp() < strtotime( '-' . strval( $single_expiration_period - 3 ) . ' days' ) );

			// Warn users when a listing will expire within 3 days.
			if ( $expires_soon ) {
				return __( 'published (expires soon)', 'newspack-listings' );
			}
		}

		return $is_published ? __( 'published', 'newspack-listings' ) : $listing->post_status;
	}

	/**
	 * Render content in the new Listing Status column in the My Orders table.
	 * This column is rendered for every order if the Listings plugin is enabled,
	 * but will only show content for orders that represent a listings purchase.
	 *
	 * @param WC_Order $order Order object for the current table row.
	 */
	public function listing_order_status_column_content( $order ) {
		$order_id          = $order->get_id();
		$original_order_id = get_post_meta( $order_id, self::ORDER_META_KEYS['listing_original_order'], true );

		// If this order was a renewal of an existing listing, use the original order ID to get the listing details.
		if ( $original_order_id ) {
			$order_id = $original_order_id;
		}

		$listing = self::get_listing_by_order_id( $order_id );
		if ( $listing ) {
			$status                  = $this->get_listing_status( $listing );
			$expired_or_expires_soon = 'expired' === $status || false !== stripos( $status, 'expires soon' ); // TODO: refactor to handle non-English sites.
			if ( $expired_or_expires_soon ) :
				?>
				<mark class="order-status">
				<?php
			endif;

			echo esc_html( $status );

			if ( $expired_or_expires_soon ) :
				?>
				</mark>
				<?php
			endif;
		} else {
			// Translators: status to output when the current order is not a listing, a.k.a. "not available".
			echo esc_html__( 'n/a', 'newspack-listings' );
		}
	}

	/**
	 * When viewing a single listing order, append details about the listing and links to edit it.
	 *
	 * @param WC_Order $order WooCommerce order object.
	 */
	public function listing_append_details( $order ) {
		if ( ! $order ) {
			return;
		}

		$order_id          = $order->get_id();
		$original_order    = false;
		$original_order_id = get_post_meta( $order_id, self::ORDER_META_KEYS['listing_original_order'], true );

		// If this order was a renewal of an existing listing, use the original order ID to get the listing details.
		if ( $original_order_id ) {
			$order_id       = $original_order_id;
			$original_order = \wc_get_order( $original_order_id );
		}

		// Get the listing associated with this order ID.
		$listing = self::get_listing_by_order_id( $order_id );

		if ( $listing ) :
			$status       = $this->get_listing_status( $listing );
			$is_expired   = 'expired' === $status || Core::listing_has_expired( $listing->ID );
			$is_published = 'publish' === $listing->post_status;
			$listing_type = Core::NEWSPACK_LISTINGS_POST_TYPES['marketplace'] === $listing->post_type ? 'marketplace' : 'event';
			$renew_url    = add_query_arg(
				[
					'listing-purchase-type' => 'single',
					'listing-renew'         => $listing->ID,
					'listing-title-single'  => $listing->post_title,
					'listing-single-type'   => $listing_type,
				],
				$this->get_base_url()
			);

			$single_expiration_period = Settings::get_settings( 'newspack_listings_single_purchase_expiration' );

			?>
			<h3><?php echo esc_html__( 'Listing Details', 'newspack-listings' ); ?></h3>

			<?php if ( $original_order ) : ?>
				<p>
					<?php echo esc_html__( 'This order was a renewal of an expired listing.', 'newspack-listings' ); ?>
					<a href="<?php echo esc_url( $original_order->get_view_order_url() ); ?>"><?php echo esc_html__( 'Click here to view the original order details', 'newspack-listings' ); ?></a>.
				</p>
			<?php endif; ?>
			<ul>
				<li><strong><?php echo esc_html__( 'Listing Title:', 'newspack-listings' ); ?></strong> <a href="<?php echo esc_url( get_permalink( $listing->ID ) ); ?>"><?php echo esc_html( $listing->post_title ); ?></a></li>
				<li><strong><?php echo esc_html__( 'Listing Status:', 'newspack-listings' ); ?></strong> <?php echo esc_html( $status ); ?></strong></li>
			</ul>

			<?php if ( $is_expired ) : ?>
				<p><?php echo esc_html__( 'Your listing has expired and is no longer published.', 'newspack-listings' ); ?></p>
			<?php else : ?>
				<p>
					<a href="<?php echo esc_url( get_edit_post_link( $listing->ID ) ); ?>">
						<?php echo esc_html__( 'Edit this listing', 'newspack-listings' ); ?>
					</a>

					<?php
					echo esc_html(
						sprintf(
							// Translators: listing details edit message and link.
							__( 'to update its content or %s. ', 'newspack-listings' ),
							'publish' === $listing->post_status || 'pending' === $listing->post_status ? __( 'unpublish it', 'newspack-listings' ) : __( 'submit it for review', 'newspack-listings' )
						)
					);
					?>
				</p>
				<?php
			endif;

			if ( $is_published || $is_expired ) :
				$expires_in = '';

				if ( $is_published && ! $is_expired ) {
					$set_expiration = Core::get_expiration_date( $listing->ID );
					$expires_date   = $set_expiration ? $set_expiration : ( new \DateTime( $listing->post_date ) )->modify( '+' . (string) $single_expiration_period . ' days' );

					$date_diff  = $expires_date->diff( new \DateTime() );
					$expires_in = sprintf(
						// Translators: message describing how many days are left before this listing expires.
						__( 'This listing is set to expire after %s.' ),
						$expires_date->format( 'F j, Y' )
					);
				}
				?>
				<p>
					<?php
						echo esc_html(
							sprintf(
								// Translators: message explaining how many days single-purchase listings are active, and how to renew.
								__( 'Listings are active for %1$d days after publication by default. %2$sTo extend your listing by an additional %3$s:', 'newspack-listings' ),
								$single_expiration_period,
								$expires_in,
								1 < $single_expiration_period ?
									// Translators: If $single_expiration_period is more than one, show the number.
									sprintf( __( '%d days', 'newspack-listings' ), $single_expiration_period ) :
									__( 'day', 'newspack-listings' )
							)
						);
					?>
				</p>
				<p><a href="<?php echo esc_url( $renew_url ); ?>" class="button"><?php esc_html_e( 'Extend', 'woocommerce' ); ?></a></p>
				<?php
			endif;

			if ( $order->has_status( apply_filters( 'woocommerce_valid_order_statuses_for_order_again', [ 'completed' ] ) ) ) :
				?>
				<p><?php echo esc_html__( 'To quickly purchase a new blank Marketplace listing:', 'newspack-listings' ); ?></p>
				<?php
			endif;
		endif;
	}

	/**
	 * Once a subscription is activated, look up the listing post associated with its purchase order and
	 * associate the subscription with the listing. This will let us unpublish the associated listings
	 * when the subscription expires or is canceled.
	 *
	 * @param WC_Subscription $subscription Subscription object for the activated subscription.
	 */
	public function listing_subscription_associate_primary_post( $subscription ) {
		$order_id = $subscription->get_parent_id();
		$listing  = self::get_listing_by_order_id( $order_id );

		if ( $listing ) {
			// Mark this subscription as a listing subscription.
			update_post_meta( $subscription->get_id(), self::SUBSCRIPTION_META_KEYS['listing_subscription'], 1 );

			// Associate the post created during purchase with this subscription.
			update_post_meta( $listing->ID, self::POST_META_KEYS['listing_subscription'], $subscription->get_id() );

			// If the order included a premium upgrade, mark this subscription as premium.
			$order           = \wc_get_order( $order_id );
			$products        = self::get_products();
			$purchased_items = array_map(
				function( $item ) {
					return $item->get_product_id();
				},
				$order->get_items()
			);
			$premium_upgrade = in_array( $products[ self::PRODUCT_META_KEYS['premium'] ], $purchased_items );
			if ( $premium_upgrade ) {
				update_post_meta( $subscription->get_id(), self::SUBSCRIPTION_META_KEYS['is_premium'], 1 );
			}
		}
	}

	/**
	 * If a subscription's status changes from active to something other than active, unpublish any listings
	 * associated with that subscription.
	 *
	 * @param WC_Subscription $subscription Subscription object for the subscription whose status has changed.
	 * @param string          $new_status The string representation of the new status applied to the subscription.
	 * @param string          $old_status The string representation of the subscriptions status before the change was applied.
	 */
	public function listing_subscription_unpublish_associated_posts( $subscription, $new_status, $old_status ) {
		if ( 'active' === $old_status && 'active' !== $new_status ) {
			$associated_listings = get_posts(
				[
					'meta_key'    => self::POST_META_KEYS['listing_subscription'],
					'meta_value'  => $subscription->get_id(), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
					'post_status' => 'publish',
					'post_type'   => array_values( Core::NEWSPACK_LISTINGS_POST_TYPES ),
				]
			);

			foreach ( $associated_listings as $listing ) {
				wp_update_post(
					[
						'ID'          => $listing->ID,
						'post_status' => 'draft',
					]
				);
			}
		}
	}

	/**
	 * Active premium subscriptions grant customers the ability to create up to 10 Marketplace or Event listings.
	 * Show controls to create and manage these listings in the Subscription account page.
	 *
	 * @param WC_Subscription $subscription Subscription object for the subscription whose status has changed.
	 */
	public function listing_subscription_manage_premium_listings( $subscription ) {
		$subscription_id  = $subscription->get_id();
		$is_active        = 'active' === $subscription->get_status();
		$is_premium       = $is_active && get_post_meta( $subscription_id, self::SUBSCRIPTION_META_KEYS['is_premium'], true );
		$customer_id      = $subscription->get_user_id();
		$premium_listings = get_posts(
			[
				'meta_key'       => self::POST_META_KEYS['listing_subscription'],
				'meta_value'     => $subscription->get_id(), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'post_status'    => [ 'draft', 'future', 'pending', 'private', 'publish' ],
				'post_type'      => [
					Core::NEWSPACK_LISTINGS_POST_TYPES['event'],
					Core::NEWSPACK_LISTINGS_POST_TYPES['marketplace'],
				],
				'posts_per_page' => $this->total_included_listings, // phpcs:ignore WordPress.WP.PostsPerPage.posts_per_page_posts_per_page
			]
		);

		// If not a premium subscription and there are no previously created premium listings, no need to show the UI.
		if ( ! $is_premium && 0 === count( $premium_listings ) ) {
			return;
		}

		$remaining       = $this->total_included_listings - count( $premium_listings );
		$base_url        = $this->get_base_url();
		$marketplace_url = wp_nonce_url(
			add_query_arg(
				[
					'customer_id'     => $customer_id,
					'subscription_id' => $subscription_id,
					'create'          => 'marketplace',
				],
				$base_url
			),
			$this->create_nonce
		);
		$event_url       = wp_nonce_url(
			add_query_arg(
				[
					'customer_id'     => $customer_id,
					'subscription_id' => $subscription_id,
					'create'          => 'event',
				],
				$base_url
			),
			$this->create_nonce
		);

		?>
			<h2><?php echo esc_html( __( 'Premium subscription listings', 'newspack-listings' ) ); ?></h2>
			<p>
				<?php
					echo esc_html(
						sprintf(
							// translators: explanation of premium subscription benefits.
							__( 'A premium subscription lets you create up to %1$d additional Marketplace or Event listings. %2$s', 'newspack-listings' ),
							$this->total_included_listings,
							$remaining && $is_premium ?
								// translators: explanation of remaining included listings.
								sprintf( __( 'You have %d included listings remaining.', 'newspack-listings' ), $remaining ) :
								__( 'You don’t have any included listings available. To create additional listings, please purchase them.', 'newspack-listings' )
						)
					);
				?>
			</p>
		<?php

		// To create new listings, subscription must be active and premium, and must have not used up all included listings.
		if ( $is_premium && $remaining ) :
			?>
			<p>
				<a class="woocommerce-button button" href="<?php echo esc_url( $marketplace_url ); ?>"><?php echo esc_html__( 'Create New Marketplace Listing', 'newspack-listings' ); ?></a>
				<a class="woocommerce-button button" href="<?php echo esc_url( $event_url ); ?>"><?php echo esc_html__( 'Create New Event Listing', 'newspack-listings' ); ?></a>
			</p>
			<?php
		endif;

		// Show a table with previously created premium listings. This is shown even if the subscription is no longer premium (so that the user can still see their own previously created listings), but if that's the case the "edit" button will no longer be available.
		if ( 0 < count( $premium_listings ) ) :
			?>
			<table class="shop_table shop_table_responsive my_account_orders">
				<thead>
					<tr>
						<th class="woocommerce-orders-table__header"><?php echo esc_html__( 'Listing Title', 'newspack-listings' ); ?></th>
						<th class="woocommerce-orders-table__header"><?php echo esc_html__( 'Listing Status', 'newspack-listings' ); ?></th>
						<th class="woocommerce-orders-table__header"></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $premium_listings as $listing ) : ?>
						<?php
						$trash_listing_url = wp_nonce_url(
							add_query_arg(
								[
									'customer_id'     => $customer_id,
									'subscription_id' => $subscription_id,
									'delete'          => $listing->ID,
									'redirect_uri'    => urlencode( $base_url ),
								],
								$base_url
							),
							$this->delete_nonce
						);
						?>
					<tr>
						<td class="woocommerce-orders-table__cell"><a href="<?php echo esc_url( get_edit_post_link( $listing->ID ) ); ?>"><?php echo wp_kses_post( $listing->post_title ); ?></a></td>
						<td class="woocommerce-orders-table__cell"><?php echo esc_html( $this->get_listing_status( $listing ) ); ?></td>
						<td class="woocommerce-orders-table__cell">
							<?php if ( $is_premium ) : ?>
								<a class="woocommerce-button button" href="<?php echo esc_url( get_edit_post_link( $listing->ID ) ); ?>"><?php echo esc_html__( 'Edit', 'newspack-listings' ); ?></a>
							<?php endif; ?>
							<a class="woocommerce-button button" href="<?php echo esc_url( get_permalink( $listing->ID ) ); ?>"><?php echo esc_html( 'publish' === $listing->post_status ? __( 'View', 'newspack-listings' ) : __( 'Preview', 'newspack-listings' ) ); ?></a>
							<a class="woocommerce-button button" href="<?php echo esc_url( $trash_listing_url ); ?>" onclick="return confirm(' <?php echo esc_html__( 'Are you sure you want to delete this listing? This cannot be undone.', 'newspack-listings' ); ?> ');"><?php echo esc_html__( 'Delete', 'newspack-listings' ); ?></a>
						</td>
					</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<?php
		endif;
	}

	/**
	 * Intercept GET params and create a Marketplace or Event listing for a premium subscription.
	 */
	public function create_or_delete_premium_listing() {
		$nonce           = filter_input( INPUT_GET, '_wpnonce', FILTER_SANITIZE_FULL_SPECIAL_CHARS );
		$customer_id     = filter_input( INPUT_GET, 'customer_id', FILTER_SANITIZE_FULL_SPECIAL_CHARS );
		$subscription_id = filter_input( INPUT_GET, 'subscription_id', FILTER_SANITIZE_FULL_SPECIAL_CHARS );
		$post_type_slug  = filter_input( INPUT_GET, 'create', FILTER_SANITIZE_FULL_SPECIAL_CHARS );
		$delete_post     = filter_input( INPUT_GET, 'delete', FILTER_SANITIZE_NUMBER_INT );
		$redirect_uri    = filter_input( INPUT_GET, 'redirect_uri', FILTER_SANITIZE_FULL_SPECIAL_CHARS );

		if ( $redirect_uri ) {
			$redirect_uri = urldecode( $redirect_uri );
		}

		// Only if we have all of the required query args.
		if ( ! $customer_id || ! $subscription_id || ( ! $delete_post && 'event' !== $post_type_slug && 'marketplace' !== $post_type_slug ) ) {
			return;
		}

		// If deleting the post.
		if ( $delete_post && $redirect_uri ) {
			// Check nonce in case someone tries to delete a listing by visiting the URL with the expected params.
			if ( ! wp_verify_nonce( $nonce, $this->delete_nonce ) ) {
				return;
			}

			wp_trash_post( $delete_post );
			wp_safe_redirect( $redirect_uri );
			exit;
		}

		// Check nonce in case someone tries to create a listing by visiting the URL with the expected params.
		if ( ! wp_verify_nonce( $nonce, $this->create_nonce ) ) {
			return;
		}

		// Get order info and remaining premium listings.
		$subscription = new \WC_Subscription( $subscription_id );
		$args         = [
			'post_author' => $customer_id,
			'post_status' => 'draft',
			'post_title'  => sprintf(
				// translators: default "untitled" listing title.
				__( 'Untitled %s listing', 'newspack-listings' ),
				'event' === $post_type_slug ? __( 'event', 'newspack-listings' ) : __( 'marketplace', 'newspack-listings' )
			),
			'post_type'   => 'event' === $post_type_slug ? Core::NEWSPACK_LISTINGS_POST_TYPES['event'] : Core::NEWSPACK_LISTINGS_POST_TYPES['marketplace'],
		];
		$post_id      = wp_insert_post( $args );

		if ( $post_id ) {
			// Associate the new post with this subscription order.
			update_post_meta( $post_id, self::POST_META_KEYS['listing_subscription'], $subscription_id );

			// Redirect to post editor.
			wp_safe_redirect( html_entity_decode( get_edit_post_link( $post_id ) ) );
			exit;
		} else {
			return new \WP_Error(
				'newspack_listings_create_listing_error',
				__( 'There was an error creating your listing. Please try again or contact the site administrators for help.', 'newspack-listings' )
			);
		}
	}

	/**
	 * When a subscription product is removed from a listing subscription, also unpublish the primary listing, if published.
	 * WHen an upgrade product is removed from a premium subscription, unset the Featured status of the primary listing.
	 * Also disallow creation of new related Marketplace listings.
	 *
	 * @param WC_Product      $line_item Product object of the item removed from the subscription.
	 * @param WC_Subscription $subscription Subscription object from which the product was removed.
	 */
	public function listing_subscription_removed_item( $line_item, $subscription ) {
		$product_id              = $line_item->get_product_id();
		$products                = self::get_products();
		$is_subscription_product = $product_id === $products[ self::PRODUCT_META_KEYS['subscription'] ];
		$is_premium_product      = $product_id === $products[ self::PRODUCT_META_KEYS['premium'] ];
		$associated_listings     = [];

		if ( $is_subscription_product || $is_premium_product ) {
			$associated_listings = get_posts(
				[
					'meta_key'    => self::POST_META_KEYS['listing_subscription'],
					'meta_value'  => $subscription->get_id(), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
					'post_status' => 'publish',
					'post_type'   => array_values( Core::NEWSPACK_LISTINGS_POST_TYPES ),
				]
			);
		}

		// If removing the subscription product, unpublish any associated listings.
		if ( $is_subscription_product ) {
			foreach ( $associated_listings as $listing ) {
				wp_update_post(
					[
						'ID'          => $listing->ID,
						'post_status' => 'draft',
					]
				);
			}
		}

		// If removing the premium upgrade product, unset the featured status of any associated listings.
		if ( $is_premium_product ) {
			delete_post_meta( $subscription->get_id(), self::SUBSCRIPTION_META_KEYS['is_premium'] );

			foreach ( $associated_listings as $listing ) {
				Featured::unset_featured_status( $listing->ID, true );
			}
		}
	}

	/**
	 * When a product is removed from a subscription, the customer has an opportunity to undo this action.
	 * When undoing a product removal, we should restore the Featured status of any premium subscription items
	 * and re-allow creation of new related Marketplace listings.
	 *
	 * If a primary listing was unpublished due to removal of a subscription product, the customer will need to
	 * resubmit the listing for review before it can be published again. This is to prevent a loophole where
	 * customers could theoretically publish changes without editorial approval by removing and readding the
	 * subscription product from their subscription.
	 *
	 * @param WC_Product      $line_item Product object of the item re-added to the subscription.
	 * @param WC_Subscription $subscription Subscription object to which the product was re-added.
	 */
	public function listing_subscription_readded_item( $line_item, $subscription ) {
		$product_id          = $line_item->get_product_id();
		$products            = self::get_products();
		$is_premium_product  = $product_id === $products[ self::PRODUCT_META_KEYS['premium'] ];
		$associated_listings = [];

		// If re-adding the premium upgrade product, reset the featured status for any associated listings.
		// Feature priority will be reset to the default (5) since there's no way to retrieve older values.
		if ( $is_premium_product ) {
			update_post_meta( $subscription->get_id(), self::SUBSCRIPTION_META_KEYS['is_premium'], 1 );
			$associated_listings = get_posts(
				[
					'meta_key'    => self::POST_META_KEYS['listing_subscription'],
					'meta_value'  => $subscription->get_id(), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
					'post_status' => 'publish',
					'post_type'   => array_values( Core::NEWSPACK_LISTINGS_POST_TYPES ),
				]
			);

			foreach ( $associated_listings as $listing ) {
				$priority = Featured::get_priority( $listing->ID );

				if ( ! $priority ) {
					Featured::set_featured_status( $listing->ID );
				}
			}
		}
	}
}
