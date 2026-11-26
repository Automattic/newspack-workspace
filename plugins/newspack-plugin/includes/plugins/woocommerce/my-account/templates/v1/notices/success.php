<?php
/**
 * Show success notifications as snackbar components.
 *
 * @author   Newspack
 * @category WooCommerce Subscriptions/Templates
 * @package  Newspack
 */

namespace Newspack;

defined( 'ABSPATH' ) || exit;

if ( ! $notices ) {
	return;
}
My_Account_UI_V1::add_snackbar_notices( $notices, 'success' );
