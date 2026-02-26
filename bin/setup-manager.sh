#!/bin/bash

sh /var/scripts/install-manager.sh

php /var/scripts/generate-manager-key-pair.php

FILE="/var/scripts/manager.${1}.key"
CONSTANT="NEWSPACK_MANAGER_API_${1^^}_KEY"

echo "Adding keys to wp-config.php"
cd /var/www/html
wp --allow-root config set NEWSPACK_MANAGER_API_PUBLIC_KEY "$(cat /var/scripts/manager.public.key)"
wp --allow-root plugin activate newspack-manager


cd /var/www/manager-html
wp --allow-root config set NEWSPACK_MANAGER_API_PRIVATE_KEY "$(cat /var/scripts/manager.private.key)"
wp --allow-root plugin activate newspack-manager-client

# For some reason, permalinks are not pretty by default. Because of this, the REST API wouldn't work.
wp rewrite structure '/%year%/%monthnum%/%postname%/' --allow-root
