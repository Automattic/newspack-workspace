#!/bin/bash

parent_folder="/var/www/additional-sites-html"

for folder in "$parent_folder"/*; do
    if [ -d "$folder" ]; then
        folder_name=$(basename "$folder")
        echo "- http://$folder_name.local/"
    fi
done