#!/bin/bash

# Create service accounts in every site
APP_NAME="Distributor"
SERVICE_ACCOUNTS_FILE="/var/scripts/newspack-network/service-accounts.secret"
NODES_KEYS_FILE="/var/scripts/newspack-network/nodes-keys.json"
NODES=""

# Ensure the newspack-network directory exists
mkdir -p /var/scripts/newspack-network

# Create empty JSON file if it doesn't exist
if [ ! -f "$NODES_KEYS_FILE" ]; then
    echo '{}' > "$NODES_KEYS_FILE"
fi

if ! wp --allow-root user application-password exists 1 $APP_NAME --path=/var/www/html; then
    wp --allow-root plugin activate distributor --path=/var/www/html
    wp --allow-root plugin activate newspack-network --path=/var/www/html

    echo "Setting main site as the Network Hub"
    wp --allow-root option set newspack_network_site_role hub --path=/var/www/html

    echo "Creating service account for $WP_DOMAIN"
    PASSWORD=$(wp --allow-root user application-password create 1 $APP_NAME --porcelain --path=/var/www/html)
    echo "$WP_DOMAIN:$PASSWORD" >> $SERVICE_ACCOUNTS_FILE
else
    echo "Service account for $WP_DOMAIN already exists"
fi
# Loop through subfolders of /var/www/additional-sites-html
for site in $(ls -d /var/www/additional-sites-html/*/); do
    wp --allow-root plugin activate distributor --path=$site
    wp --allow-root plugin activate newspack-network --path=$site

    # Get the site name from the path
    site_name=$(echo $site | sed -e 's/.*additional-sites-html\///' | cut -d'/' -f1)

    echo "Setting $site_name as a Network Node"
    wp --allow-root option set newspack_network_site_role node --path=$site

    NODES="$NODES,$site_name"
    # Create the service account
    if ! wp --allow-root user application-password exists 1 $APP_NAME --path=$site; then
        echo "Creating service account for $site_name.local"
        PASSWORD=$(wp --allow-root user application-password create 1 $APP_NAME --porcelain --path=$site)
        echo "$site_name:$PASSWORD" >> $SERVICE_ACCOUNTS_FILE
    else
        echo "Service account for $site_name.local already exists"
    fi
done

# Configure Hub
## Create all nodes and store the keys
wp --allow-root eval-file /var/scripts/newspack-network/create-nodes.php $NODES $NODES_KEYS_FILE --path=/var/www/html
## Connect Distributor to all sites

while IFS= read -r line; do

    # Get the site name from the line
    site_name=$(echo $line | cut -d':' -f1)

    # skip if site_name is localhost
    if [ "$site_name" == "localhost" ]; then
        continue
    fi

    # Get the key from the line
    key=$(echo $line | cut -d':' -f2)

    # Connect Distributor to the site
    wp --allow-root eval-file /var/scripts/newspack-network/connect-distributor.php $site_name $key $WP_ADMIN_USER --path=/var/www/html

done < $SERVICE_ACCOUNTS_FILE


# Configure Nodes
## Enter hub domain and key
for site in $(ls -d /var/www/additional-sites-html/*/); do
    # Get the site name from the path
    site_name=$(echo $site | sed -e 's/.*additional-sites-html\///' | cut -d'/' -f1)

    wp --allow-root option set newspack_node_hub_url http://$WP_DOMAIN --path=$site
    # open NODES_KEYS_FILE and get the key of this site from the json

    NODE_KEY=`jq -r ".$site_name" "$NODES_KEYS_FILE"`
    wp --allow-root option set newspack_node_secret_key $NODE_KEY --path=$site

    echo "Node $site_name configured"

    ## Connect Distributor to all sites
    while IFS= read -r line; do

        # Get the site name from the line
        target_site_name=$(echo $line | cut -d':' -f1)

        # skip if site_name is the current site
        if [ "$target_site_name" == "$site_name" ]; then
            continue
        fi

        # skip if site_name is localhost
        # TODO: The connection back to the hub is not working yet
        if [ "$site_name" == "localhost" ]; then
            continue
        fi

        # Get the key from the line
        key=$(echo $line | cut -d':' -f2)

        # Connect Distributor to the site
        wp  --allow-root eval-file /var/scripts/newspack-network/connect-distributor.php $target_site_name $key $WP_ADMIN_USER --path=$site

    done < $SERVICE_ACCOUNTS_FILE

done