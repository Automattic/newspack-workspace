#!/bin/bash

WP_PATH=${1:-"/var/www/html"}

/var/scripts/uninstall.sh $WP_PATH
/var/scripts/install.sh $WP_PATH

wp --allow-root --path="$WP_PATH" plugin install woocommerce
wp --allow-root --path="$WP_PATH" plugin activate newspack-plugin woocommerce

# TODO: Have just one copy of the plugin symnlinked to all sites
wp --allow-root --path="$WP_PATH" plugin install https://github.com/10up/distributor/releases/download/2.1.0/distributor.zip --force

wp --allow-root --path="$WP_PATH" newspack setup

/var/scripts/import-secrets.sh $WP_PATH

# For some reason, permalinks are not pretty by default. Because of this, the REST API wouldn't work.
wp rewrite structure '/%year%/%monthnum%/%postname%/' --allow-root
