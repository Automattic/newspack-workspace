#!/bin/bash
source /var/scripts/repos.sh
source /var/scripts/jn-functions.sh

echo -n Password: 
read -s password
echo

echo "Uploading Newspack plugin"
process_plugin newspack-plugin $password

echo "Uploading Woocommerce premium extensions"
process_plugin woocommerce-name-your-price $password
process_plugin woocommerce-subscriptions $password

sshpass -p $password ssh -o StrictHostKeyChecking=no $USER@$DOMAIN "cd $dest_folder; wp plugin install woocommerce; wp plugin activate newspack-plugin woocommerce; wp newspack setup"

echo "Copying secrets"
copy_secrets $password