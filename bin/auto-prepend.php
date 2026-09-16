<?php
/**
 * Runs before every PHP request in the local environment (auto_prepend_file).
 *
 * Loads the site's custom-redirects.php when there is one. The site root is the
 * document root, or on the CLI the `--path` argument or the current directory,
 * when that is a WordPress root.
 *
 * @package Newspack
 */

$newspack_prepend_site_root = '';
if ( ! empty( $_SERVER['DOCUMENT_ROOT'] ) ) {
	$newspack_prepend_site_root = rtrim( $_SERVER['DOCUMENT_ROOT'], '/' );
} elseif ( PHP_SAPI === 'cli' ) {
	// `wp --path=<site>` targets a site other than the current directory.
	$newspack_prepend_site_root = getcwd();
	foreach ( $argv ?? [] as $newspack_prepend_arg ) {
		if ( 0 === strpos( $newspack_prepend_arg, '--path=' ) ) {
			$newspack_prepend_site_root = rtrim( substr( $newspack_prepend_arg, 7 ), '/' );
		}
	}
	if ( ! is_file( $newspack_prepend_site_root . '/wp-load.php' ) ) {
		$newspack_prepend_site_root = '';
	}
}

if ( '' !== $newspack_prepend_site_root && is_file( $newspack_prepend_site_root . '/custom-redirects.php' ) ) {
	require $newspack_prepend_site_root . '/custom-redirects.php';
}

unset( $newspack_prepend_site_root, $newspack_prepend_arg );
