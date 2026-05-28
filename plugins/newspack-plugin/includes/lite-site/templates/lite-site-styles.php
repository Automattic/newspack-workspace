<?php
/**
 * Shared styles for lite site templates
 *
 * @package newspack
 */

namespace Newspack;

?>
<style>
	body {
		font-family: system-ui, -apple-system, sans-serif;
		font-size: clamp( 1.125rem, 0.929rem + 0.402vw, 1.25rem );
		line-height: 1.6;
		margin: 0 auto;
		max-width: 39.5rem;
		padding: 2rem 1rem;
	}
	h1 {
		font-size: clamp( 1.75rem, -0.213rem + 4.016vw, 3rem );
		line-height: 1.25;
		margin: 0 0 2rem;
	}
	ul.post-list {
		list-style: none;
		padding-left: 0;
	}
	.post-list li {
		margin-bottom: 1rem;
	}
	a {
		color: currentcolor;
	}
	hr.separator {
		border: 0.125rem solid <?php echo esc_attr( Lite_Site::get_primary_color() ); ?>;
		margin: 2rem 0;
	}
	.back {
		display: block;
		font-size: 1rem;
		line-height: 1.5;
		margin: 0 0 0.5rem;
	}
	.meta {
		color: currentcolor;
		margin-bottom: 2rem;
	}
	.meta .date {
		margin-top: 0.5rem;
	}
	.site-footer {
		font-size: 1rem;
		line-height: 1.5;
		margin-top: 2rem;
	}
	.site-footer > :last-child {
		margin-bottom: 0;
	}

	<?php
	/**
	 * Hook fired at the end of the style tag in the Lite Site templates.
	 */
	do_action( 'newspack_lite_site_styles' );
	?>

</style>
