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

// Jetpack 16+ registers this block only once it renders on the front end, so the registry check alone misses it.
$has_sharing_buttons = $registry->get_registered( 'jetpack/sharing-buttons' )
	|| ( is_callable( [ 'Jetpack_Gutenberg', 'get_extensions' ] ) && in_array( 'sharing-buttons', Jetpack_Gutenberg::get_extensions(), true ) );

if ( $has_sharing_buttons ) :
	?>

	<!-- wp:jetpack/sharing-buttons {"styleType":"icon","layout":{"type":"flex","justifyContent":"left","flexWrap":"nowrap"}} -->
	<ul class="wp-block-jetpack-sharing-buttons has-normal-icon-size jetpack-sharing-buttons__services-list" id="jetpack-sharing-serivces-list">
		<!-- wp:jetpack/sharing-button {"service":"facebook","label":"Facebook"} /-->
		<!-- wp:jetpack/sharing-button {"service":"x","label":"X"} /-->
		<!-- wp:jetpack/sharing-button {"service":"mail","label":"Mail"} /-->
		<?php if ( class_exists( 'Republication_Tracker_Tool' ) ) : ?>
		<!-- wp:jetpack/sharing-button {"service":"republish"} /-->
		<?php endif; ?>
		<?php if ( class_exists( '\Newspack\Content_Gifting' ) && \Newspack\Content_Gifting::is_enabled() ) : ?>
		<!-- wp:jetpack/sharing-button {"service":"newspack-gift-article"} /-->
		<?php endif; ?>
	</ul>
	<!-- /wp:jetpack/sharing-buttons -->

	<?php endif; ?>
