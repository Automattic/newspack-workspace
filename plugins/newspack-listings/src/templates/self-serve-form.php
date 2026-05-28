<?php
/**
 * Template for a self-serve listing purchase form.
 *
 * @global array $attributes Block attributes.
 * @package WordPress
 */

use Newspack_Listings\Core;
use Newspack_Listings\Settings;
use Newspack_Listings\Utils;

call_user_func(
	function( $data ) {
		$attributes               = $data['attributes'];
		$allowed_listing_types    = $attributes['allowedSingleListingTypes'];
		$allow_subscription       = $attributes['allowSubscription']; // Legacy attribute superseded by allowedPurchases.
		$allowed_purchases        = $attributes['allowedPurchases'];
		$button_text              = $attributes['buttonText'];
		$single_description       = $attributes['singleDescription'];
		$subscription_description = $attributes['subscriptionDescription'];
		$client_id                = $attributes['clientId'];
		$single_expiration_period = Settings::get_settings( 'newspack_listings_single_purchase_expiration' );

		$class_names   = [ 'newspack-listings__self-serve-form', 'wpbnbd' ];
		$class_names[] = $allowed_purchases;

		if ( empty( $allow_subscription ) ) {
			$class_names[] = 'single-only';
		}

		$allow_single       = 'subscription-only' !== $allowed_purchases || empty( $allow_subscription );
		$allow_subscription = 'single-only' !== $allowed_purchases && ! empty( $allow_subscription );

		if ( empty( $attributes ) ) {
			return;
		}
		?>
<div class="<?php echo esc_attr( implode( ' ', $class_names ) ); ?>">
	<form>
		<div class="frequencies">
			<?php if ( $allow_single ) : ?>
				<div class="newspack-listings__form-tabs frequency">
					<input
						name="listing-purchase-type"
						class="newspack-listings__tab-input"
						id="listing-single-<?php echo esc_attr( $client_id ); ?>"
						type="radio"
						checked
						value="single"
					/>
					<label
						class="freq-label listing-single"
						for="listing-single-<?php echo esc_attr( $client_id ); ?>"
					>
						<?php echo esc_html( __( 'Single Listing' ) ); ?>
					</label>
					<div class="input-container listing-details">
						<p><?php echo wp_kses_post( $single_description ); ?></p>
						<?php if ( 0 < $single_expiration_period ) : ?>
							<p class="newspack-listings__help">
								<?php
								echo esc_html(
									sprintf(
										// Translators: user-facing explanation of expiration behavior.
										__(
											'Single-purchase listings expire %d days after the date of publication.',
											'newspack-listings'
										),
										$single_expiration_period
									)
								);
								?>
							</p>
						<?php endif; ?>
						<hr />
						<h3><?php echo esc_html( __( 'Listing Details', 'newspack-listings' ) ); ?></h3>
						<label for="listing-title-single-<?php echo esc_attr( $client_id ); ?>">
							<?php echo esc_html( __( 'Listing Title', 'newspack-listings' ) ); ?>
						</label>
						<input
							type="text"
							id="listing-title-single-<?php echo esc_attr( $client_id ); ?>"
							name="listing-title-single"
							value=""
							placeholder="<?php echo esc_attr( __( 'My Listing Title' ) ); ?>"
						/>
						<label for="listing-type-<?php echo esc_attr( $client_id ); ?>">
							<?php echo esc_html( __( 'Listing Type', 'newspack-listings' ) ); ?>
						</label>
						<select
							id="listing-type-<?php echo esc_attr( $client_id ); ?>"
							name="listing-single-type"
						>
							<?php
							array_map(
								function ( $listing_type ) {
									?>
									<option value="<?php echo esc_attr( $listing_type['slug'] ); ?>">
										<?php echo esc_html( $listing_type['name'] ); ?>
									</option>
									<?php
								},
								$allowed_listing_types
							);
				?>
						</select>
						<input
							type="checkbox"
							id="listing-single-upgrade-<?php echo esc_attr( $client_id ); ?>"
							name="listing-featured-upgrade"
						/>
						<label for="listing-single-upgrade-<?php echo esc_attr( $client_id ); ?>">
							<?php echo esc_html( __( 'Upgrade to a featured listing', 'newspack-listings' ) ); ?>
						</label>
						<p class="newspack-listings__help">
							<?php
							echo esc_html(
								__(
									'Featured listings appear first in lists, directory pages and search results.',
									'newspack-listings'
								)
							);
							?>
						</p>
					</div>
				</div>
			<?php endif; ?>
			<?php if ( $allow_subscription ) : ?>
				<div class="newspack-listings__form-tabs frequency">
					<input
						name="listing-purchase-type"
						class="newspack-listings__tab-input"
						id="listing-subscription-<?php echo esc_attr( $client_id ); ?>"
						type="radio"
						value="subscription"
						<?php if ( 'subscription-only' === $allowed_purchases ) : ?>
							checked
						<?php endif; ?>
					/>
					<label
						class="freq-label listing-subscription"
						for="listing-subscription-<?php echo esc_attr( $client_id ); ?>"
					>
						<?php echo esc_html( __( 'Listing Subscription' ) ); ?>
					</label>
					<div class="input-container listing-details">
						<p><?php echo wp_kses_post( $subscription_description ); ?></p>
						<p class="newspack-listings__help">
							<?php echo esc_html( __( 'Subscription listings remain live as long as the subscription is active.', 'newspack-listings' ) ); ?>
						</p>
						<hr />
						<h3><?php echo esc_html( __( 'Listing Details', 'newspack-listings' ) ); ?></h3>
						<label for="listing-title-subscription-<?php echo esc_attr( $client_id ); ?>">
							<?php echo esc_html( __( 'Listing Title', 'newspack-listings' ) ); ?>
						</label>
						<input
							type="text"
							id="listing-title-subscription-<?php echo esc_attr( $client_id ); ?>"
							name="listing-title-subscription"
							value=""
							placeholder="<?php echo esc_attr( __( 'My Listing Title' ) ); ?>"
						/>
						<input
							type="checkbox"
							id="listing-subscription-upgrade-<?php echo esc_attr( $client_id ); ?>"
							name="listing-premium-upgrade"
						/>
						<label for="listing-subscription-upgrade-<?php echo esc_attr( $client_id ); ?>">
							<?php echo esc_html( __( 'Upgrade to a premium subscription', 'newspack-listings' ) ); ?>
						</label>
						<p class="newspack-listings__help">
							<?php
							echo esc_html(
								__(
									'A premium subscription upgrades your listing to "featured" status and lets you create up to 10 additional Marketplace or Event listings.',
									'newspack-listings'
								)
							);
							?>
						</p>
					</div>
				</div>
			<?php endif; ?>
		</div>
		<button type="submit">
			<?php echo esc_html( $button_text ); ?>
		</button>
	</form>
</div>
		<?php
	},
	$data // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UndefinedVariable
);
