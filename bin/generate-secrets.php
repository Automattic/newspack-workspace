<?php

$options = [
    'newspack_newsletters_active_campaign_key',
    'newspack_newsletters_active_campaign_url',
    'newspack_newsletters_campaign_monitor_api_key',
    'newspack_newsletters_campaign_monitor_client_id',
    'newspack_newsletters_constant_contact_api_secret',
    'newspack_newsletters_constant_contact_api_key',
    'newspack_newsletters_letterhead_api_key',
    'newspack_mailchimp_api_key',
    'newspack_reader_revenue_platform',
    'newspack_newsletters_service_provider',
];
$stripe_data_keys = [
    'testPublishableKey',
    'testSecretKey',
    'currency',
    'location_code',
    'newsletter_list_id',
];
$constants = [
    'NEWSPACK_MANAGER_API_PUBLIC_KEY',
    'NEWSPACK_GOOGLE_OAUTH_PROXY_OVERRIDE',
    'NEWSPACK_FIVETRAN_PROXY_OVERRIDE',
];

$output = [
    'options' => [],
    'stripe' => [],
    'constants' => [],
];

foreach ( $options as $option ) {
    $value = get_option( $option );
    if ( ! $value ) {
        $value = '';
    }
    $output['options'][ $option ] = $value;
}

foreach ( $constants as $constant ) {
    $value = defined( $constant ) ? constant( $constant ) : '';
    $output['constants'][ $constant ] = $value;
}

if ( method_exists( 'Newspack\Stripe_Connection', 'get_stripe_data' ) ) {
    $data = Newspack\Stripe_Connection::get_stripe_data();
    foreach( $stripe_data_keys as $key ) {
        $value = ! empty( $data[ $key ] ) ? $data[ $key ] : '';
        $output['stripe'][ $key ] = $value;
    }
}

echo json_encode( $output );
