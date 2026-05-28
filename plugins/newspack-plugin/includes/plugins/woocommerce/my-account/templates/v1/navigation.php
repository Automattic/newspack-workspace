<?php
/**
 * My Account navigation menu.
 *
 * @author   Newspack
 * @category WooCommerce Subscriptions/Templates
 * @package  Newspack
 */

use Newspack\My_Account_UI_V1;
use Newspack\Newspack_UI_Icons;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

do_action( 'woocommerce_before_account_navigation' );
$site_icon_url = apply_filters( 'newspack_my_account_site_logo_url', get_site_icon_url( 96 ) );

// Get the current page name from account menu items.
$current_page_name = __( 'My account', 'newspack-plugin' );
$account_menu_items = wc_get_account_menu_items();
foreach ( $account_menu_items as $endpoint => $label ) {
	if ( wc_is_current_account_menu_item( $endpoint ) ) {
		$current_page_name = $label;
		break;
	}
}

// Teams for WooCommerce Memberships.
$is_teams_area_page = false;
$team = null;
$teams_area_sections = [];
if ( function_exists( 'wc_memberships_for_teams' ) ) {
	$teams_area = wc_memberships_for_teams()->get_frontend_instance()->get_teams_area_instance();
	$is_teams_area_page = $teams_area->is_teams_area();
	$team = $teams_area->get_teams_area_team();
	$teams_area_sections = $teams_area->get_teams_area_navigation_items( $team );
}
?>

<div class="newspack-my-account__navigation-topbar">
	<h1 class="newspack-ui__font--s newspack-ui__spacing-top--0 newspack-ui__spacing-bottom--0"><?php echo esc_html( $current_page_name ); ?></h1>

	<div class="newspack-my-account__navigation-topbar__button">
		<button class="newspack-ui__button newspack-ui__button--medium newspack-ui__button--ghost newspack-ui__button--icon" aria-expanded="false" aria-label="<?php esc_attr_e( 'Open navigation', 'newspack-plugin' ); ?>" data-label-close="<?php esc_attr_e( 'Close navigation', 'newspack-plugin' ); ?>" data-label-open="<?php esc_attr_e( 'Open navigation', 'newspack-plugin' ); ?>">
			<?php Newspack_UI_Icons::print_svg( 'menu' ); ?>
			<?php Newspack_UI_Icons::print_svg( 'close' ); ?>
		</button>
	</div>
</div>

<nav class="woocommerce-MyAccount-navigation newspack-ui" aria-label="<?php esc_attr_e( 'Account pages', 'newspack-plugin' ); ?>">
	<div class="newspack-my-account__navigation-header">
		<?php if ( ! empty( $site_icon_url ) ) : ?>
		<a class="newspack-my-account__site-logo" href="<?php echo esc_url( home_url( '/' ) ); ?>" title="<?php esc_attr_e( 'Back to Homepage', 'newspack-plugin' ); ?>">
			<img src="<?php echo esc_url( $site_icon_url ); ?>" />
		</a>
		<?php endif; ?>

		<?php
		if ( $team ) :
			$back_section = array_shift( $teams_area_sections );
			?>
			<a href="<?php echo esc_url( $back_section['url'] ); ?>" class="newspack-my-account__home-link newspack-ui__button newspack-ui__button--small newspack-ui__button--ghost-light">
				<?php Newspack_UI_Icons::print_svg( 'chevronLeft' ); ?>
				<?php echo esc_html( $back_section['label'] ); ?>
			</a>
			<ul>
				<?php
				foreach ( $teams_area_sections as $section_id => $section_data ) :
					$classes = wc_get_account_menu_item_classes( $section_id ) . ' ' . $section_data['class'];
					$is_current_item = strpos( $classes, 'is-active' ) !== false;
					?>
					<li class="<?php echo esc_attr( $classes ); ?>">
						<a href="<?php echo esc_url( $section_data['url'] ); ?>" class="newspack-ui__button newspack-ui__button--small <?php echo $is_current_item ? 'newspack-ui__button--accent' : 'newspack-ui__button--ghost'; ?>">
							<?php echo esc_html( $section_data['label'] ); ?>
						</a>
					</li>
				<?php endforeach; ?>
			</ul>
		<?php else : ?>
			<a href="<?php echo esc_url( home_url( '/' ) ); ?>" class="newspack-my-account__home-link newspack-ui__button newspack-ui__button--small newspack-ui__button--ghost-light">
				<?php Newspack_UI_Icons::print_svg( 'chevronLeft' ); ?>
				<?php _e( 'Back to Homepage', 'newspack-plugin' ); ?>
			</a>
			<ul>
				<?php foreach ( $account_menu_items as $endpoint => $label ) : ?>
					<?php
					// Highlight subscriptions menu item if viewing a single subscription.
					$is_current_item = wc_is_current_account_menu_item( $endpoint ) || My_Account_UI_V1::is_subscription_page( $endpoint );
					?>
					<li class="<?php echo esc_attr( wc_get_account_menu_item_classes( $endpoint ) ); ?>">
						<a href="<?php echo esc_url( wc_get_account_endpoint_url( $endpoint ) ); ?>" <?php echo $is_current_item ? 'aria-current="page"' : ''; ?> class="newspack-ui__button newspack-ui__button--small <?php echo $is_current_item ? 'newspack-ui__button--accent' : 'newspack-ui__button--ghost'; ?>">
							<?php echo esc_html( $label ); ?>
						</a>
					</li>
				<?php endforeach; ?>
			</ul>
		<?php endif; ?>
	</div>

	<div class="newspack-my-account__navigation-footer">
		<ul>
			<?php foreach ( apply_filters( 'newspack_my_account_navigation_footer_items', [] ) as $item ) : ?>
				<li class="newspack-my-account__navigation-footer-item">
					<a href="<?php echo esc_url( $item['url'] ); ?>"><?php echo esc_html( $item['label'] ); ?></a>
				</li>
			<?php endforeach; ?>
			<li class="<?php echo esc_attr( wc_get_account_menu_item_classes( 'customer-logout' ) ); ?>">
				<a href="<?php echo esc_url( wp_logout_url( wc_get_account_endpoint_url( 'customer-logout' ) ) ); ?>" class="newspack-ui__button newspack-ui__button--small newspack-ui__button--ghost">
					<?php _e( 'Sign out', 'newspack-plugin' ); ?>
					<?php Newspack_UI_Icons::print_svg( 'logout' ); ?>
				</a>
			</li>
		</ul>
	</div>
</nav>

<?php do_action( 'woocommerce_after_account_navigation' ); ?>
