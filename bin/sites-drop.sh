#!/bin/bash

name="$1"
parent_folder="/var/www/additional-sites-html"

read -p "Do you really want to delete ${name} and drop its database? (y/n): " choice

# Convert the choice to lowercase
choice=${choice,,}

# Check the user's choice
if [[ "$choice" != "y" ]]; then
    exit 0
fi

rm -rf "/var/www/additional-sites-html/${name}"

mysqladmin drop "$name" -u root -p$MYSQL_ROOT_PASSWORD -h db
