<?php
/**
 * Displays header site branding
 *
 * @package Newspack
 */
?>
<div class="site-branding">

	<?php newspack_the_custom_logo(); ?>

	<div class="site-identity">
		<?php
		newspack_the_site_title();

		$description = get_bloginfo( 'description', 'display' );
		if ( $description || is_customize_preview() ) :
			?>
				<p class="site-description">
					<?php echo esc_html( $description ); ?>
				</p>
		<?php endif; ?>
	</div><!-- .site-identity -->

</div><!-- .site-branding -->
