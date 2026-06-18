<?php


$nodes = explode(',', $args[0]);
$keys_filename = $args[1];
$nodes_keys = file_exists( $keys_filename ) ? json_decode( file_get_contents( $keys_filename ), true ) : [];

foreach( $nodes as $node ) {
    if ( empty( $node ) ) {
        continue;
    }
    $domain = $node . '.test';
    $existing_node = Newspack_Network\Hub\Nodes::get_node_by_url( 'http://' . $domain );
    if ( $existing_node ) {
        echo "Node $domain already exists.\n";
        echo "Making sure we have the secret key.\n";
        $nodes_keys[ $node ] = $existing_node->get_secret_key();
        continue;
    }
    $node_id = wp_insert_post( [
        'post_title' => $domain,
        'post_type' => Newspack_Network\Hub\Nodes::POST_TYPE_SLUG,
        'post_status' => 'publish',
    ] );

    echo "Creating node $domain\n";

    update_post_meta( $node_id, 'node-url', 'http://' . $domain );
    $secret_key = Newspack_Network\Crypto::generate_secret_key();
    update_post_meta( $node_id, 'secret-key', $secret_key );
    $nodes_keys[ $node ] = $secret_key;

}

echo "Saving keys to $keys_filename.\n";
file_put_contents( $keys_filename, json_encode( $nodes_keys ) );