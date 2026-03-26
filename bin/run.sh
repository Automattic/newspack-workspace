#!/bin/bash
set -e

# This file is run for the Docker image defined in Dockerfile.
# These commands will be run each time the container is run.
#
# If you modify anything here, remember to build the image again by running:
# build-image

user="${APACHE_RUN_USER:-www-data}"

chmod +x /var/scripts/init-wp.sh

mkdir -p /var/www/manager-html
chown $user:$user /var/www/manager-html

mkdir -p /var/www/additional-sites-html
chown $user:$user /var/www/additional-sites-html
cp /var/scripts/additional-sites-index.php /var/www/additional-sites-html/index.php
chown $user:$user /var/www/additional-sites-html/index.php

if [ $user != 'www-data' ];
	then
	echo Switching to user $user
	su -c "/var/scripts/init-wp.sh" -m $user
	su -c "/var/scripts/init-wp-manager.sh" -m $user
else
	echo Running as default user $user
	/var/scripts/init-wp.sh
	/var/scripts/init-wp-manager.sh
fi

WP_HOST_PORT=":$HOST_PORT"

if [ 80 -eq "$HOST_PORT" ]; then
	WP_HOST_PORT=""
fi

chmod +x /var/scripts/*.sh
/var/scripts/link-repos.sh

# Memcached
cp /var/scripts/object-cache.php /var/www/html/wp-content/

# Batcache
cp /var/scripts/advanced-cache.php /var/www/html/wp-content/

# Clean up pre-existing Apache pid file
APACHE_PID_FILE="/run/apache2/apache2.pid"
if [ -e $APACHE_PID_FILE ]; then
	rm -f $APACHE_PID_FILE
fi

echo
echo "Main site is available at https://${WP_DOMAIN}${WP_HOST_PORT}/"
echo "Newspack Manager site is available at https://manager.com${WP_HOST_PORT}/"
echo "Open http://localhost:8025 to see Mailhog inbox."
echo

# Start memcached
/etc/init.d/memcached start

# Run apache in the foreground so the container keeps running
echo "Running Apache in the foreground"
apachectl -D FOREGROUND
