#!/bin/bash

WP_PATH="${1:-/var/www/html}"
DOMAIN_TO_INSTALL="$WP_DOMAIN"
SITE_TITLE="$WP_TITLE"

if [[ "$WP_PATH" == *"additional-sites-html"* ]]; then
    SITE_TITLE=$(echo "$WP_PATH" | sed -e 's/.*additional-sites-html\///' | cut -d'/' -f1)
    DOMAIN_TO_INSTALL="$SITE_TITLE.test"
fi

if wp --allow-root --path="$WP_PATH" core is-installed; then
	echo
	echo "WordPress has already been installed. Uninstall it first by running:"
	echo
	echo "n uninstall"
	echo
	exit 1;
fi

# Install WP core
wp --allow-root --path="$WP_PATH" core install \
	--url="${DOMAIN_TO_INSTALL}" \
	--title="${SITE_TITLE}" \
	--admin_user="${WP_ADMIN_USER}" \
	--admin_password="${WP_ADMIN_PASSWORD}" \
	--admin_email="${WP_ADMIN_EMAIL}" \
	--skip-email

echo
echo "WordPress installed. Open ${DOMAIN_TO_INSTALL}"
echo
