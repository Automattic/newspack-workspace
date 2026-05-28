<?php
/**
 * Memberships Paywall with Three Tiers Pattern.
 *
 * @package Newspack
 */

$member_features = [
	__( 'Unlimited access to our content', 'newspack-plugin' ),
	__( 'Puzzles and recipes', 'newspack-plugin' ),
	__( 'Support quality journalism', 'newspack-plugin' ),
];

$patron_features = [
	__( 'Everything Members get', 'newspack-plugin' ),
	__( 'Exclusive podcasts and newsletters', 'newspack-plugin' ),
	__( 'Our appreciation and love', 'newspack-plugin' ),
];

$vip_features = [
	__( 'Everything Patrons get', 'newspack-plugin' ),
	__( 'Special offers from our partners', 'newspack-plugin' ),
	__( 'Priority invitations to events', 'newspack-plugin' ),
];

?>
<!-- wp:group {"metadata":{"name":"<?php esc_html_e( 'Subscription', 'newspack-plugin' ); ?>"},"align":"wide","style":{"spacing":{"padding":{"top":"var:preset|spacing|80","bottom":"var:preset|spacing|80","left":"var:preset|spacing|80","right":"var:preset|spacing|80"}},"border":{"radius":"8px","width":"1px"}},"borderColor":"base-3","layout":{"type":"constrained"}} -->
<div class="wp-block-group alignwide has-border-color has-base-3-border-color" style="border-width:1px;border-radius:8px;padding-top:var(--wp--preset--spacing--80);padding-right:var(--wp--preset--spacing--80);padding-bottom:var(--wp--preset--spacing--80);padding-left:var(--wp--preset--spacing--80)">

	<!-- wp:heading {"textAlign":"center","level":3,"metadata":{"name":"<?php esc_html_e( 'Title', 'newspack-plugin' ); ?>"}} -->
	<h3 class="wp-block-heading has-text-align-center">
		<?php esc_html_e( 'Choose an option to continue reading', 'newspack-plugin' ); ?>
	</h3>
	<!-- /wp:heading -->

	<!-- wp:paragraph {"align":"center"} -->
	<p class="has-text-align-center">
		<?php esc_html_e( 'Subscribe to the Member, Patron or VIP monthly memberships to gain unlimited access to exclusive content.', 'newspack-plugin' ); ?>
	</p>
	<!-- /wp:paragraph -->

	<!-- wp:group {"align":"wide","layout":{"type":"constrained","wideSize":"964px"}} -->
	<div class="wp-block-group alignwide">
		<!-- wp:columns {"metadata":{"name":"<?php esc_html_e( 'Tiers', 'newspack-plugin' ); ?>"},"align":"wide","className":"is-style-borders"} -->
		<div class="wp-block-columns alignwide is-style-borders">

			<!-- wp:column -->
			<div class="wp-block-column">

				<!-- wp:group {"metadata":{"name":"<?php esc_html_e( 'Content', 'newspack-plugin' ); ?>"},"style":{"dimensions":{"minHeight":"100%"}},"layout":{"type":"flex","orientation":"vertical","verticalAlignment":"space-between","justifyContent":"stretch"}} -->
				<div class="wp-block-group" style="min-height:100%">
					<!-- wp:group {"metadata":{"name":"<?php esc_html_e( 'Details', 'newspack-plugin' ); ?>"},"layout":{"type":"flex","orientation":"vertical","justifyContent":"stretch"}} -->
					<div class="wp-block-group">
						<!-- wp:group {"metadata":{"name":"<?php esc_html_e( 'Top', 'newspack-plugin' ); ?>"},"style":{"spacing":{"blockGap":"var:preset|spacing|20"}},"layout":{"type":"flex","orientation":"vertical","justifyContent":"stretch"}} -->
						<div class="wp-block-group">
							<!-- wp:heading {"level":4} -->
							<h4 class="wp-block-heading">
								<?php esc_html_e( 'Member', 'newspack-plugin' ); ?>
							</h4>
							<!-- /wp:heading -->
							<!-- wp:group {"metadata":{"name":"<?php esc_html_e( 'Price', 'newspack-plugin' ); ?>"},"style":{"spacing":{"blockGap":"var:preset|spacing|20"}},"className":"align-items\u002d\u002dbaseline","layout":{"type":"flex","flexWrap":"nowrap"}} -->
							<div class="wp-block-group align-items--baseline">
								<!-- wp:paragraph {"metadata":{"name":"<?php esc_html_e( 'Amount', 'newspack-plugin' ); ?>"},"fontSize":"xx-large"} -->
								<p class="has-xx-large-font-size"><strong><?php esc_html_e( '$7', 'newspack-plugin' ); ?></strong></p>
								<!-- /wp:paragraph -->
								<!-- wp:paragraph {"metadata":{"name":"<?php esc_html_e( 'Frequency', 'newspack-plugin' ); ?>"},"style":{"elements":{"link":{"color":{"text":"var:preset|color|contrast-3"}}}},"textColor":"contrast-3","fontSize":"x-small"} -->
								<p class="has-contrast-3-color has-text-color has-link-color has-x-small-font-size"><?php esc_html_e( 'per month', 'newspack-plugin' ); ?></p>
								<!-- /wp:paragraph -->
							</div>
							<!-- /wp:group -->
						</div>
						<!-- /wp:group -->

						<!-- wp:list {"className":"is-style-checked","fontSize":"small"} -->
						<ul class="is-style-checked has-small-font-size">
							<?php foreach ( $member_features as $feature ) : ?>
								<!-- wp:list-item -->
								<li><?php echo esc_html( $feature ); ?></li>
								<!-- /wp:list-item -->
							<?php endforeach; ?>
						</ul>
						<!-- /wp:list -->
					</div>
					<!-- /wp:group -->

					<!-- wp:newspack-blocks/checkout-button {"text":"<?php esc_html_e( 'Become a Member', 'newspack-plugin' ); ?>","width":100,"className":"is-style-outline"} /-->
				</div>
				<!-- /wp:group -->

			</div>
			<!-- /wp:column -->

			<!-- wp:column -->
			<div class="wp-block-column">

				<!-- wp:group {"metadata":{"name":"<?php esc_html_e( 'Content', 'newspack-plugin' ); ?>"},"style":{"dimensions":{"minHeight":"100%"}},"layout":{"type":"flex","orientation":"vertical","verticalAlignment":"space-between","justifyContent":"stretch"}} -->
				<div class="wp-block-group" style="min-height:100%">
					<!-- wp:group {"metadata":{"name":"<?php esc_html_e( 'Details', 'newspack-plugin' ); ?>"},"layout":{"type":"flex","orientation":"vertical","justifyContent":"stretch"}} -->
					<div class="wp-block-group">
						<!-- wp:group {"metadata":{"name":"<?php esc_html_e( 'Top', 'newspack-plugin' ); ?>"},"style":{"spacing":{"blockGap":"var:preset|spacing|20"}},"layout":{"type":"flex","orientation":"vertical","justifyContent":"stretch"}} -->
						<div class="wp-block-group">
							<!-- wp:group {"metadata":{"name":"<?php esc_html_e( 'Title', 'newspack-plugin' ); ?>"},"style":{"spacing":{"blockGap":"var:preset|spacing|20"}},"layout":{"type":"flex","flexWrap":"wrap","justifyContent":"space-between"}} -->
							<div class="wp-block-group">
								<!-- wp:heading {"level":4} -->
								<h4 class="wp-block-heading">
									<?php esc_html_e( 'Patron', 'newspack-plugin' ); ?>
								</h4>
								<!-- /wp:heading -->
								<!-- wp:group {"metadata":{"name":"<?php esc_html_e( 'Badge', 'newspack-plugin' ); ?>"},"style":{"elements":{"link":{"color":{"text":"var:preset|color|base"}}},"border":{"radius":"0.125rem"},"spacing":{"padding":{"top":"0.125rem","bottom":"0.125rem","left":"0.375rem","right":"0.375rem"}},"typography":{"textTransform":"uppercase","fontStyle":"normal","fontWeight":"600"}},"backgroundColor":"contrast","textColor":"base","layout":{"type":"flex","flexWrap":"nowrap"},"fontSize":"x-small"} -->
								<div class="wp-block-group has-base-color has-contrast-background-color has-text-color has-background has-link-color has-x-small-font-size" style="border-radius:0.125rem;padding-top:0.125rem;padding-right:0.375rem;padding-bottom:0.125rem;padding-left:0.375rem;font-style:normal;font-weight:600;text-transform:uppercase">
									<!-- wp:paragraph -->
									<p><?php esc_html_e( 'Best value', 'newspack-plugin' ); ?></p>
									<!-- /wp:paragraph -->
								</div>
								<!-- /wp:group -->
							</div>
							<!-- /wp:group -->
							<!-- wp:group {"metadata":{"name":"<?php esc_html_e( 'Price', 'newspack-plugin' ); ?>"},"style":{"spacing":{"blockGap":"var:preset|spacing|20"}},"className":"align-items\u002d\u002dbaseline","layout":{"type":"flex","flexWrap":"nowrap"}} -->
							<div class="wp-block-group align-items--baseline">
								<!-- wp:paragraph {"metadata":{"name":"<?php esc_html_e( 'Amount', 'newspack-plugin' ); ?>"},"fontSize":"xx-large"} -->
								<p class="has-xx-large-font-size"><strong><?php esc_html_e( '$15', 'newspack-plugin' ); ?></strong></p>
								<!-- /wp:paragraph -->
								<!-- wp:paragraph {"metadata":{"name":"<?php esc_html_e( 'Frequency', 'newspack-plugin' ); ?>"},"style":{"elements":{"link":{"color":{"text":"var:preset|color|contrast-3"}}}},"textColor":"contrast-3","fontSize":"x-small"} -->
								<p class="has-contrast-3-color has-text-color has-link-color has-x-small-font-size"><?php esc_html_e( 'per month', 'newspack-plugin' ); ?></p>
								<!-- /wp:paragraph -->
							</div>
							<!-- /wp:group -->
						</div>
						<!-- /wp:group -->

						<!-- wp:list {"className":"is-style-checked","fontSize":"small"} -->
						<ul class="is-style-checked has-small-font-size">
							<?php foreach ( $patron_features as $feature ) : ?>
								<!-- wp:list-item -->
								<li><?php echo esc_html( $feature ); ?></li>
								<!-- /wp:list-item -->
							<?php endforeach; ?>
						</ul>
						<!-- /wp:list -->
					</div>
					<!-- /wp:group -->

					<!-- wp:newspack-blocks/checkout-button {"text":"<?php esc_html_e( 'Become a Patron', 'newspack-plugin' ); ?>","width":100} /-->
				</div>
				<!-- /wp:group -->

			</div>
			<!-- /wp:column -->

			<!-- wp:column -->
			<div class="wp-block-column">

				<!-- wp:group {"metadata":{"name":"<?php esc_html_e( 'Content', 'newspack-plugin' ); ?>"},"style":{"dimensions":{"minHeight":"100%"}},"layout":{"type":"flex","orientation":"vertical","verticalAlignment":"space-between","justifyContent":"stretch"}} -->
				<div class="wp-block-group" style="min-height:100%">
					<!-- wp:group {"metadata":{"name":"<?php esc_html_e( 'Details', 'newspack-plugin' ); ?>"},"layout":{"type":"flex","orientation":"vertical","justifyContent":"stretch"}} -->
					<div class="wp-block-group">
						<!-- wp:group {"metadata":{"name":"<?php esc_html_e( 'Top', 'newspack-plugin' ); ?>"},"style":{"spacing":{"blockGap":"var:preset|spacing|20"}},"layout":{"type":"flex","orientation":"vertical","justifyContent":"stretch"}} -->
						<div class="wp-block-group">
							<!-- wp:heading {"level":4} -->
							<h4 class="wp-block-heading">
								<?php esc_html_e( 'VIP', 'newspack-plugin' ); ?>
							</h4>
							<!-- /wp:heading -->
							<!-- wp:group {"metadata":{"name":"<?php esc_html_e( 'Price', 'newspack-plugin' ); ?>"},"style":{"spacing":{"blockGap":"var:preset|spacing|20"}},"className":"align-items\u002d\u002dbaseline","layout":{"type":"flex","flexWrap":"nowrap"}} -->
							<div class="wp-block-group align-items--baseline">
								<!-- wp:paragraph {"metadata":{"name":"<?php esc_html_e( 'Amount', 'newspack-plugin' ); ?>"},"fontSize":"xx-large"} -->
								<p class="has-xx-large-font-size"><strong><?php esc_html_e( '$30', 'newspack-plugin' ); ?></strong></p>
								<!-- /wp:paragraph -->
								<!-- wp:paragraph {"metadata":{"name":"<?php esc_html_e( 'Frequency', 'newspack-plugin' ); ?>"},"style":{"elements":{"link":{"color":{"text":"var:preset|color|contrast-3"}}}},"textColor":"contrast-3","fontSize":"x-small"} -->
								<p class="has-contrast-3-color has-text-color has-link-color has-x-small-font-size"><?php esc_html_e( 'per month', 'newspack-plugin' ); ?></p>
								<!-- /wp:paragraph -->
							</div>
							<!-- /wp:group -->
						</div>
						<!-- /wp:group -->

						<!-- wp:list {"className":"is-style-checked","fontSize":"small"} -->
						<ul class="is-style-checked has-small-font-size">
							<?php foreach ( $vip_features as $feature ) : ?>
								<!-- wp:list-item -->
								<li><?php echo esc_html( $feature ); ?></li>
								<!-- /wp:list-item -->
							<?php endforeach; ?>
						</ul>
						<!-- /wp:list -->
					</div>
					<!-- /wp:group -->

					<!-- wp:newspack-blocks/checkout-button {"text":"<?php esc_html_e( 'Become a VIP', 'newspack-plugin' ); ?>","width":100,"className":"is-style-outline"} /-->
				</div>
				<!-- /wp:group -->

			</div>
			<!-- /wp:column -->

		</div>
		<!-- /wp:columns -->
	</div>
	<!-- /wp:group -->

	<!-- wp:buttons {"layout":{"type":"flex","justifyContent":"center"}} -->
	<div class="wp-block-buttons">
		<!-- wp:button {"backgroundColor":"base","textColor":"contrast","style":{"elements":{"link":{"color":{"text":"var:preset|color|contrast"}}}}} -->
		<div class="wp-block-button">
			<a class="wp-block-button__link has-contrast-color has-base-background-color has-text-color has-background has-link-color wp-element-button" href="#signin_modal">
				<?php esc_html_e( 'Sign in to an existing account', 'newspack-plugin' ); ?>
			</a>
		</div>
		<!-- /wp:button -->
	</div>
	<!-- /wp:buttons -->

</div>
<!-- /wp:group -->
