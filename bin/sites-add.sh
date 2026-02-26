#!/bin/bash

name=$1

user="${APACHE_RUN_USER:-www-data}"

if [ ! -d "/var/www/additional-sites-html/$name" ]; then
    mkdir -p "/var/www/additional-sites-html/$name"
    chown $user:$user "/var/www/additional-sites-html/$name"

    mysqladmin create $name -u root -p$MYSQL_ROOT_PASSWORD -h db
    mysql -u root -p$MYSQL_ROOT_PASSWORD -h db -e "GRANT ALL PRIVILEGES ON $name.* TO '$MYSQL_USER'@'%' IDENTIFIED BY '$MYSQL_PASSWORD' WITH GRANT OPTION; FLUSH PRIVILEGES;"

    /var/scripts/init-additional-site.sh $name
    sudo -E -u $user /var/scripts/reset-site.sh /var/www/additional-sites-html/$name

    echo
    echo "Site $name created!. Open https://${name}.local"
    echo
else
    echo
    echo "Site $name already exists!"
    echo
fi
