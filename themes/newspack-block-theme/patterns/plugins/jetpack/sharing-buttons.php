<?php
/**
 * Title: Jetpack sharing buttons
 * Slug: newspack-block-theme/jetpack-sharing-buttons
 * Viewport Width: 632
 * Inserter: false
 *
 * @package Newspack_Block_Theme
 */

$registry = WP_Block_Type_Registry::get_instance();

// Jetpack 16+ defers registering this block on the front end until it is found in a rendered block tree.
$has_sharing_buttons = $registry->get_registered( 'jetpack/sharing-buttons' )
	|| ( class_exists( 'Jetpack_Gutenberg' ) && in_array( 'sharing-buttons', Jetpack_Gutenberg::get_extensions(), true ) );

if ( $has_sharing_buttons ) :
	?>

	<!-- wp:jetpack/sharing-buttons {"styleType":"icon","layout":{"type":"flex","justifyContent":"left","flexWrap":"nowrap"}} -->
	<ul class="wp-block-jetpack-sharing-buttons has-normal-icon-size jetpack-sharing-buttons__services-list" id="jetpack-sharing-serivces-list">
		<!-- wp:jetpack/sharing-button {"service":"facebook","label":"Facebook"} /-->
		<!-- wp:jetpack/sharing-button {"service":"x","label":"X"} /-->
		<!-- wp:jetpack/sharing-button {"service":"mail","label":"Mail"} /-->
	</ul>
	<!-- /wp:jetpack/sharing-buttons -->

	<?php endif; ?>
