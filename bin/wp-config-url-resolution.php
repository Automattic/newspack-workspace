/**
 * Dynamic URL resolution for multi-environment support. Handles isolated environments
 * on different ports (e.g., localhost:8081) and external reverse proxies.
 *
 * For web requests: Uses HTTP_HOST if it matches the whitelist.
 * For CLI commands: Falls back to NEWSPACK_URL for database consistency.
 */
$newspack_allowed_hosts = [
	// Add external domains as needed (e.g., 'your-domain.com')
];

$site_url = null;
if ( ! empty( $_SERVER['HTTP_HOST'] ) ) {
	$host         = $_SERVER['HTTP_HOST'];
	$is_localhost = preg_match( '/^localhost(:\d+)?$/', $host );
	$is_allowed   = in_array( $host, $newspack_allowed_hosts, true );
	if ( $is_localhost || $is_allowed ) {
		$_SERVER['HTTPS'] = isset( $_SERVER['HTTP_X_FORWARDED_PROTO'] ) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https' ? 'on' : null;
		$scheme           = ! empty( $_SERVER['HTTPS'] ) ? 'https' : 'http';
		$site_url         = $scheme . '://' . $host;
	}
}
if ( ! $site_url ) {
	$site_url = NEWSPACK_URL;
}
define( 'WP_SITEURL', $site_url );
define( 'WP_HOME', $site_url );
