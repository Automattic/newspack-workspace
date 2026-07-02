<?php

$site_name = $args[0];
$app_pass = $args[1];
$wp_user = $args[2];

$this_site = get_option('siteurl');

$pt = 'dt_ext_connection';

$existing_connection = get_posts(
    [
        'post_type' => $pt,
        'title' => $site_name,
        'post_status' => 'publish',
        'posts_per_page' => 1,
    ]
);

if ( ! empty( $existing_connection ) ) {
    echo "Distributor Connection to $site_name already exists.\n";
    exit;
}

$auth = [
    'username' => $wp_user,
    'base64_encoded' => base64_encode( $wp_user . ':' . $app_pass ),
];

$site_url = 'http://' . $site_name . '.test/wp-json/';

echo "Connecting Distributor in site $this_site to $site_url\n";

$post_id = wp_insert_post( [
    'post_title' => $site_name,
    'post_type' => $pt,
    'post_status' => 'publish',
] );

$auth_handler = new Distributor\ExternalConnections\WordPressExternalConnection::$auth_handler_class( $auth );

$connections = new Distributor\ExternalConnections\WordPressExternalConnection( get_the_title( $post_id ), esc_url_raw( wp_unslash( $site_url ) ), $post_id, $auth_handler );

$meta = [
    'dt_external_connection_type' => 'wp',
    'dt_external_connection_allowed_roles' => [
        'administrator',
        'editor',
    ],
    'dt_external_connection_url' => $site_url,
    'dt_external_connection_auth' => $auth,
    'dt_external_connections' => $connections->check_connections(),
    'dt_external_connection_check_time' => time(),
];

foreach ( $meta as $key => $value ) {
    update_post_meta( $post_id, $key, $value );
}

