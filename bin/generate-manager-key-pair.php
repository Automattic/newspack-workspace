<?php

echo 'Generating manager key pair...';

$sign_pair = sodium_crypto_sign_seed_keypair(random_bytes(SODIUM_CRYPTO_SIGN_SEEDBYTES));
$private = sodium_bin2base64(sodium_crypto_sign_secretkey($sign_pair),SODIUM_BASE64_VARIANT_ORIGINAL);
$public = sodium_bin2base64(sodium_crypto_sign_publickey($sign_pair),SODIUM_BASE64_VARIANT_ORIGINAL);

file_put_contents( '/var/scripts/manager.public.key', $public );
file_put_contents( '/var/scripts/manager.private.key', $private );

echo " done.\n";