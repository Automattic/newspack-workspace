<?php
/**
 * My Account navigation markup, shared by the WooCommerce and native account pages.
 *
 * Expects these variables in scope:
 *
 * @var array{url: string, label: string} $back_link        Where the back link goes.
 * @var array[]                          $nav_items        Items with `url`, `label`, `classes` and `is_current`.
 * @var string                           $sign_out_url     Sign-out URL, or an empty string to leave sign-out out.
 * @var string                           $sign_out_label   Sign-out label.
 * @var string                           $sign_out_classes Classes for the footer's sign-out item.
 *
 * @package Newspack
 */

use Newspack\Newspack_UI_Icons;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$site_icon_url = apply_filters( 'newspack_my_account_site_logo_url', get_site_icon_url( 96 ) );
$site_name     = get_bloginfo( 'name' );
?>
<nav class="woocommerce-MyAccount-navigation newspack-ui" aria-label="<?php esc_attr_e( 'Account pages', 'newspack-plugin' ); ?>">
	<div class="newspack-my-account__navigation-header">
		<?php if ( ! empty( $site_icon_url ) ) : ?>
		<a class="newspack-my-account__site-logo" href="<?php echo esc_url( home_url( '/' ) ); ?>">
			<img src="<?php echo esc_url( $site_icon_url ); ?>" alt="<?php echo esc_attr( $site_name ? $site_name : __( 'Back to homepage', 'newspack-plugin' ) ); ?>" />
		</a>
		<?php endif; ?>

		<div class="newspack-my-account__navigation-back newspack-ui__stack newspack-ui__stack--horizontal newspack-ui__stack--justify-between newspack-ui__stack--align-center newspack-ui__stack--gap-3">
			<a href="<?php echo esc_url( $back_link['url'] ); ?>" class="newspack-my-account__home-link newspack-ui__link newspack-ui__link--muted">
				<?php Newspack_UI_Icons::print_svg( 'chevronLeft' ); ?>
				<?php echo esc_html( $back_link['label'] ); ?>
			</a>
			<?php if ( $sign_out_url ) : ?>
			<a href="<?php echo esc_url( $sign_out_url ); ?>" class="newspack-my-account__navigation-sign-out newspack-ui__link newspack-ui__link--muted">
				<?php echo esc_html( $sign_out_label ); ?>
				<?php Newspack_UI_Icons::print_svg( 'logout' ); ?>
			</a>
			<?php endif; ?>
		</div>

		<ul class="newspack-my-account__navigation-list newspack-ui__stack newspack-ui__stack--vertical newspack-ui__stack--gap-half">
			<?php foreach ( $nav_items as $nav_item ) : ?>
				<li class="<?php echo esc_attr( $nav_item['classes'] ); ?>">
					<a href="<?php echo esc_url( $nav_item['url'] ); ?>" <?php echo $nav_item['is_current'] ? 'aria-current="page"' : ''; ?> class="newspack-ui__button <?php echo $nav_item['is_current'] ? 'newspack-ui__button--secondary' : 'newspack-ui__button--ghost'; ?>">
						<?php echo esc_html( $nav_item['label'] ); ?>
					</a>
				</li>
			<?php endforeach; ?>
		</ul>

		<div class="newspack-ui__tabs newspack-ui__tabs--scroll newspack-my-account__navigation-tabs">
			<div class="newspack-ui__tabs__list">
				<?php foreach ( $nav_items as $nav_item ) : ?>
					<a href="<?php echo esc_url( $nav_item['url'] ); ?>" <?php echo $nav_item['is_current'] ? 'aria-current="page"' : ''; ?>><?php echo esc_html( $nav_item['label'] ); ?></a>
				<?php endforeach; ?>
			</div>
		</div>
	</div>

	<div class="newspack-my-account__navigation-footer">
		<ul>
			<?php foreach ( apply_filters( 'newspack_my_account_navigation_footer_items', [] ) as $item ) : ?>
				<li class="newspack-my-account__navigation-footer-item">
					<a class="newspack-ui__link newspack-ui__link--muted" href="<?php echo esc_url( $item['url'] ); ?>"><?php echo esc_html( $item['label'] ); ?></a>
				</li>
			<?php endforeach; ?>
			<?php if ( $sign_out_url ) : ?>
			<li class="<?php echo esc_attr( $sign_out_classes ); ?>">
				<a href="<?php echo esc_url( $sign_out_url ); ?>" class="newspack-ui__button newspack-ui__button--ghost">
					<?php echo esc_html( $sign_out_label ); ?>
					<?php Newspack_UI_Icons::print_svg( 'logout' ); ?>
				</a>
			</li>
			<?php endif; ?>
		</ul>
	</div>
</nav>
