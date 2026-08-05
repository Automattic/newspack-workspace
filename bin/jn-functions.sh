if [ $# -eq 0 ]; then
	echo "No arguments provided"
    echo "Inform the Jurassic Ninja site user and domain"
    echo "Example: jn-cp user123 red-rat.jurassic.ninja"
	exit 1
fi

USER="$1"
DOMAIN="$2"
#dest_folder="/srv/users/$USER/apps/$USER/public"
dest_folder="/srv/htdocs/wp-content/plugins"

# Jurassic Ninja hands out throwaway sites on recycled random subdomains, so the same
# host name legitimately presents a different key each time it is reused. Strict host
# key checking would fail on every reuse, hence StrictHostKeyChecking=no. The trade-off
# is accepted because these are disposable test sites; do not reuse this for a durable
# host, where an unverified key means a MITM can read whatever is uploaded.
SSH_OPTS=(-o StrictHostKeyChecking=no)

# Pass the password through the environment rather than argv. sshpass -p overwrites its
# own command line at startup, but only after exec, leaving a brief window where ps can
# read the plaintext; -e never puts it on the command line at all.
jn_scp() {
    local password="$1"
    shift
    SSHPASS="$password" sshpass -e scp "${SSH_OPTS[@]}" "$@"
}

jn_ssh() {
    local password="$1"
    shift
    SSHPASS="$password" sshpass -e ssh "${SSH_OPTS[@]}" "$@"
}

process_plugin() {

    local plugin=$1
    # Let's check if is one of our plugins, if it is, let's use the release script
    for i in "${newspack_plugins[@]}"
    do
        if [[ $i == $plugin ]]
        then
            process_newspack_plugin "$plugin" "$2"
            return
        fi
    done

    process_custom_plugin "$plugin" "$2"
}

process_newspack_plugin() {
    local plugin="$1"
    local password="$2"

    source /var/scripts/resolve-project-path.sh
    cd "$(resolve_project_path "$plugin")"
    echo "Creating package for $plugin"
    npm run --silent release:archive > /dev/null
    echo Uploading...
    jn_scp "$password" "release/$plugin.zip" "$USER@$DOMAIN:$dest_folder/"
    jn_ssh "$password" "$USER@$DOMAIN" "cd $dest_folder; wp plugin install $plugin.zip --force --activate"
}

process_custom_plugin() {
    local plugin="$1"
    local password="$2"

    cd /var/www/html/wp-content/plugins/
    echo "Creating package for $plugin"
    zip -r "$plugin.zip" "$plugin/" > /dev/null
    echo Uploading...
    jn_scp "$password" "$plugin.zip" "$USER@$DOMAIN:$dest_folder/"
    jn_ssh "$password" "$USER@$DOMAIN" "cd $dest_folder; wp plugin install $plugin.zip --force --activate"
    rm "$plugin.zip"
}

copy_secrets() {
    local password="$1"
    if [ -f /var/scripts/secrets.json ]; then
        jn_scp "$password" /var/scripts/secrets.json "$USER@$DOMAIN:/tmp/"
        jn_scp "$password" /var/scripts/copy-secrets.php "$USER@$DOMAIN:/tmp/"
        jn_ssh "$password" "$USER@$DOMAIN" "cd $dest_folder; wp eval-file /tmp/copy-secrets.php; rm /tmp/secrets.json; rm /tmp/copy-secrets.php"
    else
        echo "No secrets.json file found."
    fi

}
