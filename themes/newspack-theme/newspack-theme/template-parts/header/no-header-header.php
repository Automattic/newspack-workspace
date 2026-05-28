<?php
/**
 * Replaces the header.php for the No Header or Footer template, and the Homepage splash page option.
 *
 * This is the template that displays all of the <head> section and everything up until <div id="content">
 *
 * @package Newspack
 */
?><!doctype html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>" />
	<meta name="viewport" content="width=device-width, initial-scale=1" />
	<link rel="profile" href="https://gmpg.org/xfn/11" />
	<?php wp_head(); ?>
</head>

<body <?php body_class(); ?> data-amp-auto-lightbox-disable>
<?php do_action( 'wp_body_open' ); ?>

<div id="page" class="site">
	<a class="skip-link screen-reader-text" href="#main"><?php esc_html_e( 'Skip to content', 'newspack-theme' ); ?></a>

	<div id="content" class="site-content">
