#!/bin/bash

SITE_NAME=$1

cd "/var/www/additional-sites-html/$SITE_NAME"

# Download WordPress
[ -f "/var/www/additional-sites-html/$SITE_NAME"/xmlrpc.php ] || wp --allow-root core download

# Configure WordPress
if [ ! -f "/var/www/additional-sites-html/$SITE_NAME/wp-config.php" ]; then
	echo "Creating wp-config.php..."
	# Loop until wp cli exits with 0
	# because if running the containers for the first time,
	# the mysql container will reject connections until it has set the database data
	# See "No connections until MySQL init completes" in https://hub.docker.com/_/mysql/
	times=15
	i=1
	while [ "$i" -le "$times" ]; do
		sleep 3
		wp --allow-root config create \
			--dbhost=${MYSQL_HOST} \
			--dbname=${SITE_NAME} \
			--dbuser=${MYSQL_USER} \
			--dbpass=${MYSQL_PASSWORD} \
			&& break
		[ ! $? -eq 0 ] || break;
		echo "Waiting for creating wp-config.php until mysql is ready to receive connections"
		(( i++ ))
	done

	echo "Setting other wp-config.php constants..."
	wp --allow-root config set WP_DEBUG true --raw --type=constant
	wp --allow-root config set WP_DEBUG_LOG true --raw --type=constant
	wp --allow-root config set WP_DEBUG_DISPLAY false --raw --type=constant
	wp --allow-root config set SCRIPT_DEBUG true --raw --type=constant
	wp --allow-root config set WP_AUTO_UPDATE_CORE true --raw --type=constant
	wp --allow-root config set AUTOMATIC_UPDATER_DISABLED true --raw --type=constant
	wp --allow-root config set WP_ENVIRONMENT_TYPE local --type=constant
	wp --allow-root config set WP_CACHE true --type=constant
fi

# Copy single site htaccess if none is present
if [ ! -f "/var/www/additional-sites-html/$SITE_NAME/.htaccess" ]; then
	cp /var/lib/jetpack-config/htaccess "/var/www/additional-sites-html/$SITE_NAME"/.htaccess
fi

# MU Plugin
mkdir -p "/var/www/additional-sites-html/$SITE_NAME/wp-content/mu-plugins"
cp /var/scripts/newspack-docker-mu.php "/var/www/additional-sites-html/$SITE_NAME/wp-content/mu-plugins"

# link plugins and themes
/var/scripts/link-repos.sh "/var/www/additional-sites-html/$SITE_NAME/wp-content"

# SSL
sudo /usr/local/bin/ssl "${SITE_NAME}.local"

# check if a VirtualHost block for the site already exists
if ! grep -q "ServerName ${SITE_NAME}.local:443" /etc/apache2/sites-available/002-additional.conf; then
echo "Adding SSL VirtualHost block to the apache config file for ${SITE_NAME}…"
# https://stackoverflow.com/questions/41979785/how-to-configure-multiple-ssl-certs-on-apache-virtual-host-with-aliases
sudo cat <<EOF >> /etc/apache2/sites-available/002-additional.conf
<VirtualHost *:443>
	ServerName ${SITE_NAME}.local:443
	DocumentRoot /var/www/additional-sites-html/${SITE_NAME}
	SSLEngine on
	SSLCertificateFile /etc/ssl/certs/${SITE_NAME}.local.pem
	SSLCertificateKeyFile /etc/ssl/certs/${SITE_NAME}.local-key.pem
	<Directory /var/www/additional-sites-html/${SITE_NAME}>
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>
EOF

# Reload apache after adding the VirtualHost block
service apache2 reload

else
	echo "SSL VirtualHost block already found for ${SITE_NAME}…"
fi
