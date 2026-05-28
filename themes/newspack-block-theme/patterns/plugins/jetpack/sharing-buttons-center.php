<?php
/**
 * Title: Jetpack sharing buttons
 * Slug: newspack-block-theme/jetpack-sharing-buttons-center
 * Viewport Width: 632
 * Inserter: false
 *
 * @package Newspack_Block_Theme
 */

$registry = WP_Block_Type_Registry::get_instance();

if ( $registry->get_registered( 'jetpack/sharing-buttons' ) ) :
	?>

	<!-- wp:jetpack/sharing-buttons {"styleType":"icon","layout":{"type":"flex","justifyContent":"center"}} -->
	<ul class="wp-block-jetpack-sharing-buttons has-normal-icon-size jetpack-sharing-buttons__services-list" id="jetpack-sharing-serivces-list">
		<!-- wp:jetpack/sharing-button {"service":"facebook","label":"Facebook"} /-->
		<!-- wp:jetpack/sharing-button {"service":"x","label":"X"} /-->
		<!-- wp:jetpack/sharing-button {"service":"mail","label":"Mail"} /-->
	</ul>
	<!-- /wp:jetpack/sharing-buttons -->

	<?php endif; ?>
