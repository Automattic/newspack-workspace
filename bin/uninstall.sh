#!/bin/bash

WP_PATH="${1:-/var/www/html}"

# Flush cache to make sure memcached does not hold anything
wp --allow-root --path="$WP_PATH" cache flush

# Empty DB
wp --allow-root --path="$WP_PATH" db reset --yes

# Ensure we have single-site htaccess instead of multisite,
# just like we would have in fresh container.
cp -f /var/lib/jetpack-config/htaccess "${WP_PATH}/.htaccess"

# Remove "uploads" and "upgrade" folders
rm -fr "${WP_PATH}/wp-content/uploads" "${WP_PATH}/wp-content/upgrade"

# Empty WP debug log
truncate -s 0 "${WP_PATH}/wp-content/debug.log"

# Ensure wp-config.php doesn't have multi-site settings
echo
echo "Clearing out possible multi-site related settings from wp-config.php"
echo "It's okay to see errors if these did't exist..."
wp --allow-root --path="$WP_PATH" config delete WP_ALLOW_MULTISITE
wp --allow-root --path="$WP_PATH" config delete MULTISITE
wp --allow-root --path="$WP_PATH" config delete SUBDOMAIN_INSTALL
wp --allow-root --path="$WP_PATH" config delete base
wp --allow-root --path="$WP_PATH" config delete DOMAIN_CURRENT_SITE
wp --allow-root --path="$WP_PATH" config delete PATH_CURRENT_SITE
wp --allow-root --path="$WP_PATH" config delete SITE_ID_CURRENT_SITE
wp --allow-root --path="$WP_PATH" config delete BLOG_ID_CURRENT_SITE

echo
echo "WordPress uninstalled. To install it again, run:"
echo
echo " n install"
echo
