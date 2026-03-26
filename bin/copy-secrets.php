<?php

if ( ! is_readable('/tmp/secrets.json') ) {
    exit;
}

$secrets = json_decode( file_get_contents('/tmp/secrets.json') );

$stripe_data = [
    'enabled' => true,
	'testMode' => true,
	'publishableKey' => "",
	'secretKey' => "",
	'currency' => "USD",
	'location_code' => "US",
	'newsletter_list_id' => "",
	'_locale' => "user",
	'connection_error' => false,
	'fee_multiplier' => "2.9",
	'fee_static' => "0.3",
];

$stripe_secrets = $secrets->stripe;

// Process stripe.
if (
    method_exists( 'Newspack\Stripe_Connection', 'update_stripe_data' ) &&
    class_exists( 'WC_Payment_Gateways' ) &&
    ! empty( $stripe_secrets ) &&
    ! empty( $stripe_secrets->testPublishableKey ) &&
    ! empty( $stripe_secrets->testSecretKey )
) {
    echo "Processing Stripe config\n";
    $stripe_data['testPublishableKey'] = $stripe_secrets->testPublishableKey;
    $stripe_data['usedPublishableKey'] = $stripe_secrets->testPublishableKey;
    $stripe_data['testSecretKey'] = $stripe_secrets->testSecretKey;
    $stripe_data['usedSecretKey'] = $stripe_secrets->testSecretKey;

    // Accepted overrides
    $overrides = [ 'currency', 'location_code', 'newsletter_list_id' ];

    foreach( $overrides as $override ) {
        if ( ! empty( $stripe_secrets->$override ) ) {
            $stripe_data[ $override ] = $stripe_secrets->$override;
        }
    }

    Newspack\Stripe_Connection::update_stripe_data( $stripe_data );
}

echo "Processing options\n";
foreach ( $secrets->options as $option_name => $option_value ) {
    echo "Procesing $option_name\n";
    if ( ! empty( $option_value ) ) {
        update_option( $option_name, $option_value );
    }
}

echo "Processing constants\n";
$constants = '';
$wpconfig = new WPConfigTransformer( ABSPATH . '/wp-config.php' );
foreach ( $secrets->constants as $constant_name => $constant_value ) {
    echo "Procesing $constant_name\n";
    if ( ! empty( $constant_value ) ) {
		$wpconfig->update( 'constant', $constant_name, $constant_value, [ 'raw' => ! is_string( $constant_value ) ] );
    }
}