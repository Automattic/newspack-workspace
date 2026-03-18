#!/bin/bash

source "$(dirname "${BASH_SOURCE[0]}")/_common.sh"

# Sanitize env name for use as a database name (replace dashes with underscores).
db_name_for_env() {
    echo "wordpress_$(echo "$1" | tr '-' '_')"
}

# Find the next available loopback IP (127.0.0.2+).
# Checks both compose files and running containers to avoid conflicts.
next_loopback_ip() {
    used_ips=""
    for f in "$NABSPATH"/docker-compose.env-*.yml; do
        [[ -f "$f" ]] || continue
        ip=$(grep -o '127\.0\.0\.[0-9]*' "$f" | head -1)
        [[ -n "$ip" ]] && used_ips="$used_ips $ip"
    done
    # Also check IPs bound by running containers (covers stale/recreated envs).
    for ip in $(docker ps --format '{{.Ports}}' 2>/dev/null | grep -o '127\.0\.0\.[0-9]*' | sort -u); do
        used_ips="$used_ips $ip"
    done
    octet=2
    while echo "$used_ips" | grep -q "127.0.0.$octet"; do
        octet=$((octet + 1))
    done
    echo "127.0.0.$octet"
}

# Read domain from a compose file's WP_DOMAIN env var.
domain_for_env() {
    grep 'WP_DOMAIN=' "$1" | head -1 | sed 's/.*WP_DOMAIN=//'
}

# Read loopback IP from a compose file.
ip_for_env() {
    grep -o '127\.0\.0\.[0-9]*' "$1" | head -1
}

case $1 in
    create)
        env_name="$2"
        if [[ -z "$env_name" ]]; then
            echo "Usage: n env create <name> --worktree <repo>:<branch> [--worktree ...] [--domain <domain>] [--up] [--setup]"
            exit 1
        fi
        validate_env_name "$env_name"
        shift 2
        worktree_volumes=""
        domain=""
        auto_up=false
        auto_setup=false
        while [[ $# -gt 0 ]]; do
            case $1 in
                --worktree)
                    if [[ -z "$2" || "$2" == --* ]]; then
                        echo "Error: --worktree requires a value (repo:branch)"
                        exit 1
                    fi
                    IFS=':' read -r wt_repo wt_branch <<< "$2"
                    validate_name "$wt_repo" "repo"
                    validate_name "$wt_branch" "branch"
                    worktree_dir="./worktrees/$wt_repo/$wt_branch"
                    if [[ ! -d "$NABSPATH/worktrees/$wt_repo/$wt_branch" ]]; then
                        echo "Creating worktree $wt_repo/$wt_branch..."
                        "$NABSPATH/bin/worktree.sh" add "$wt_repo" "$wt_branch" || exit 1
                    fi
                    worktree_volumes="$worktree_volumes      - $worktree_dir:/newspack-repos/$wt_repo
"
                    shift 2
                    ;;
                --domain)
                    if [[ -z "$2" || "$2" == --* ]]; then
                        echo "Error: --domain requires a value"
                        exit 1
                    fi
                    domain="$2"
                    shift 2
                    ;;
                --up)
                    auto_up=true
                    shift
                    ;;
                --setup)
                    auto_up=true
                    auto_setup=true
                    shift
                    ;;
                *)
                    echo "Unknown option: $1"
                    exit 1
                    ;;
            esac
        done
        ip=$(next_loopback_ip)
        if [[ -z "$domain" ]]; then
            domain="$ip"
        fi
        compose_file="$NABSPATH/docker-compose.env-${env_name}.yml"
        container_name=$(echo "newspack_env_${env_name}" | tr '-' '_')
        db_name=$(db_name_for_env "$env_name")
        # Create isolated html directory.
        mkdir -p "$NABSPATH/envs/${env_name}/html"
        cat > "$compose_file" <<YAML
services:
  env-${env_name}:
    container_name: ${container_name}
    platform: linux/arm64
    depends_on:
      - db
    image: newspack-dev:latest
    volumes:
      - ./logs/env-${env_name}/apache2:/var/log/apache2
      - ./logs/env-${env_name}/php:/var/log/php
      - ./bin:/var/scripts
      - ./repos:/newspack-repos
${worktree_volumes}      - ./envs/${env_name}/html:/var/www/html
      - ./manager-html:/var/www/manager-html
      - ./additional-sites-html:/var/www/additional-sites-html
      - ./snapshots:/snapshots
    ports:
      - "${ip}:80:80"
      - "${ip}:443:443"
    env_file:
      - default.env
      - .env
    environment:
      - HOST_PORT=80
      - MYSQL_DATABASE=${db_name}
      - WP_CACHE_KEY_SALT=env_${env_name}_
      - WP_DOMAIN=${domain}
      - APACHE_RUN_USER=\${USE_CUSTOM_APACHE_USER:-www-data}
    extra_hosts:
      - "host.docker.internal:host-gateway"
    networks:
      - default
YAML
        echo "Created $compose_file (db: $db_name, domain: $domain, ip: $ip)"
        # Check networking prerequisites.
        if ! ifconfig lo0 2>/dev/null | grep -q "$ip"; then
            echo "Note: loopback alias for $ip is missing. Run 'n start' or: sudo ifconfig lo0 alias $ip"
        fi
        # Custom domains (not IP-based) need a /etc/hosts entry.
        if [[ "$domain" != "$ip" ]] && ! grep -q "[[:space:]]${domain}" /etc/hosts 2>/dev/null; then
            if [ -t 0 ] && [ -t 1 ]; then
                read -p "Add $domain to /etc/hosts? (Y/n): " choice
                choice=$(echo "$choice" | tr '[:upper:]' '[:lower:]')
                if [[ "$choice" != "n" ]]; then
                    echo "$ip $domain" | sudo tee -a /etc/hosts > /dev/null
                fi
            else
                echo "Note: add hosts entry before browser access: sudo sh -c 'echo \"$ip $domain\" >> /etc/hosts'"
            fi
        fi
        # Start the environment immediately or prompt.
        up_args=("up" "$env_name")
        [[ "$auto_setup" == true ]] && up_args+=("--setup")
        if [[ "$auto_up" == true ]]; then
            exec "$NABSPATH/bin/env.sh" "${up_args[@]}"
        elif [ -t 0 ] && [ -t 1 ]; then
            read -p "Start environment now? (Y/n): " choice
            choice=$(echo "$choice" | tr '[:upper:]' '[:lower:]')
            if [[ "$choice" != "n" ]]; then
                exec "$NABSPATH/bin/env.sh" "${up_args[@]}"
            else
                echo "Run: n env up $env_name"
            fi
        else
            echo "Run: n env up $env_name"
        fi
        ;;
    up)
        env_name="$2"
        if [[ -z "$env_name" ]]; then
            echo "Usage: n env up <name> [--build] [--setup]"
            exit 1
        fi
        validate_env_name "$env_name"
        shift 2
        auto_setup=false
        auto_build=false
        while [[ $# -gt 0 ]]; do
            case $1 in
                --setup) auto_setup=true; shift ;;
                --build) auto_build=true; shift ;;
                *) echo "Unknown option: $1"; exit 1 ;;
            esac
        done
        compose_file="$NABSPATH/docker-compose.env-${env_name}.yml"
        if [[ ! -f "$compose_file" ]]; then
            echo "Error: environment '$env_name' not found. Run: n env create $env_name ..."
            exit 1
        fi
        container_name=$(echo "newspack_env_${env_name}" | tr '-' '_')
        db_name=$(db_name_for_env "$env_name")
        domain=$(domain_for_env "$compose_file")
        ip=$(ip_for_env "$compose_file")
        # Ensure loopback alias exists (macOS requires this for 127.0.0.x where x>1).
        if [[ -n "$ip" && "$ip" != "127.0.0.1" ]] && ! ifconfig lo0 | grep -q "$ip"; then
            echo "Error: loopback alias for $ip is not set up."
            echo "Run 'n start' to set up networking, or manually: sudo ifconfig lo0 alias $ip"
            exit 1
        fi
        # Custom domains (not IP-based) need a /etc/hosts entry.
        if [[ -n "$domain" && "$domain" != "$ip" ]] && ! grep -q "[[:space:]]${domain}" /etc/hosts 2>/dev/null; then
            if [ -t 0 ] && [ -t 1 ]; then
                echo "$ip $domain" | sudo tee -a /etc/hosts > /dev/null
                echo "Added $domain to /etc/hosts"
            else
                echo "Warning: $domain not in /etc/hosts. Browser access won't work until added."
                echo "Run: sudo sh -c 'echo \"$ip $domain\" >> /etc/hosts'"
            fi
        fi
        # Source env files for DB credentials.
        set -a
        source "$NABSPATH/default.env"
        [[ -f "$NABSPATH/.env" ]] && source "$NABSPATH/.env"
        set +a
        # Ensure db is running and create the environment database.
        docker compose -f "$NABSPATH/docker-compose.yml" up -d db
        echo "Creating database $db_name..."
        docker compose -f "$NABSPATH/docker-compose.yml" exec -T db \
            mysql -h localhost -u root -p"${MYSQL_ROOT_PASSWORD}" \
            -e "CREATE DATABASE IF NOT EXISTS \`${db_name}\`; GRANT ALL PRIVILEGES ON \`${db_name}\`.* TO '${MYSQL_USER}'@'%'; FLUSH PRIVILEGES;" 2>/dev/null
        # Start the env container.
        if ! docker compose -f "$NABSPATH/docker-compose.yml" -f "$compose_file" up -d "env-${env_name}"; then
            echo "Error: failed to start container"
            exit 1
        fi
        # Generate SSL certificate using host mkcert (trusted CA) and copy into container.
        echo "Setting up SSL for $domain..."
        certs_dir="$NABSPATH/envs/${env_name}/certs"
        mkdir -p "$certs_dir"
        if command -v mkcert >/dev/null 2>&1 && [[ ! -f "$certs_dir/${domain}.pem" ]]; then
            (cd "$certs_dir" && mkcert "$domain" 2>/dev/null)
        fi
        if [[ -f "$certs_dir/${domain}.pem" ]]; then
            docker cp "$certs_dir/${domain}.pem" "$container_name":/etc/ssl/certs/${domain}.pem
            docker cp "$certs_dir/${domain}-key.pem" "$container_name":/etc/ssl/certs/${domain}-key.pem
        else
            # Fallback to container-side mkcert (untrusted CA, but functional).
            docker exec "$container_name" /usr/local/bin/ssl "$domain" 2>/dev/null
        fi
        # Update Apache config: replace any ServerName, ServerAdmin, and cert paths with the env domain.
        docker exec "$container_name" bash -c "sed -i \
            -e 's|ServerName .*|ServerName $domain|' \
            -e 's|ServerAdmin .*|ServerAdmin webmaster@$domain|' \
            -e 's|SSLCertificateFile .*|SSLCertificateFile /etc/ssl/certs/${domain}.pem|' \
            -e 's|SSLCertificateKeyFile .*|SSLCertificateKeyFile /etc/ssl/certs/${domain}-key.pem|' \
            /etc/apache2/sites-available/000-default.conf"
        # Auto-install WordPress if not already installed.
        echo "Waiting for WordPress setup..."
        for i in $(seq 1 20); do
            if docker exec "$container_name" wp --allow-root core is-installed 2>/dev/null; then
                break
            fi
            if docker exec "$container_name" test -f /var/www/html/wp-config.php 2>/dev/null; then
                echo "Installing WordPress..."
                docker exec "$container_name" wp --allow-root core install \
                    --url="https://${domain}" \
                    --title="${WP_TITLE:-Newspack}" \
                    --admin_user="${WP_ADMIN_USER:-admin}" \
                    --admin_password="${WP_ADMIN_PASSWORD:-password}" \
                    --admin_email="${WP_ADMIN_EMAIL:-wordpress@example.com}" \
                    --skip-email
                break
            fi
            sleep 3
        done
        # Reload Apache to pick up SSL config (it's running by now).
        docker exec "$container_name" apachectl graceful 2>/dev/null
        echo "Environment '$env_name' is ready at https://${domain}/"
        # Copy built assets from main repos into worktrees.
        if [[ "$auto_build" == true ]]; then
            grep 'worktrees/' "$compose_file" | sed 's|.*/newspack-repos/||' | while read -r repo; do
                src="$NABSPATH/repos/$repo"
                # Extract worktree path from the compose volume line.
                wt_path=$(grep "newspack-repos/$repo" "$compose_file" | sed 's/^ *- //' | cut -d: -f1)
                dst="$NABSPATH/${wt_path#./}"
                echo "Copying built assets for $repo..."
                for dir in node_modules vendor dist build; do
                    if [[ -d "$src/$dir" ]]; then
                        cp -al "$src/$dir" "$dst/$dir" 2>/dev/null || cp -a "$src/$dir" "$dst/$dir"
                    fi
                done
            done
        fi
        # Run site setup immediately or prompt.
        if [[ "$auto_setup" == true ]]; then
            docker exec "$container_name" sh -c "/var/scripts/site-setup.sh /var/www/html --yes"
        elif [ -t 0 ] && [ -t 1 ]; then
            read -p "Run Newspack site setup? (Y/n): " choice
            choice=$(echo "$choice" | tr '[:upper:]' '[:lower:]')
            if [[ "$choice" != "n" ]]; then
                docker exec -it "$container_name" sh -c "/var/scripts/site-setup.sh /var/www/html"
            fi
        fi
        ;;
    down)
        env_name="$2"
        if [[ -z "$env_name" ]]; then
            echo "Usage: n env down <name>"
            exit 1
        fi
        validate_env_name "$env_name"
        container_name=$(echo "newspack_env_${env_name}" | tr '-' '_')
        docker stop "$container_name" 2>/dev/null
        docker rm "$container_name" 2>/dev/null
        ;;
    destroy)
        env_name="$2"
        if [[ -z "$env_name" ]]; then
            echo "Usage: n env destroy <name>"
            exit 1
        fi
        validate_env_name "$env_name"
        compose_file="$NABSPATH/docker-compose.env-${env_name}.yml"
        container_name=$(echo "newspack_env_${env_name}" | tr '-' '_')
        db_name=$(db_name_for_env "$env_name")
        # Read domain, IP, and worktrees before removing compose file.
        domain=""
        ip=""
        worktree_entries=()
        if [[ -f "$compose_file" ]]; then
            domain=$(domain_for_env "$compose_file")
            ip=$(ip_for_env "$compose_file")
            while IFS= read -r line; do
                # Extract repo and branch from worktree volume lines like: ./worktrees/repo/branch:/newspack-repos/repo
                wt=$(echo "$line" | grep -o 'worktrees/[^:]*' | sed 's|worktrees/||')
                [[ -n "$wt" ]] && worktree_entries+=("$wt")
            done < <(grep 'worktrees/' "$compose_file")
        fi
        docker stop "$container_name" 2>/dev/null
        docker rm "$container_name" 2>/dev/null
        rm -f "$compose_file"
        # Drop the environment database.
        if docker inspect newspack-docker-db-1 >/dev/null 2>&1; then
            set -a
            source "$NABSPATH/default.env"
            [[ -f "$NABSPATH/.env" ]] && source "$NABSPATH/.env"
            set +a
            docker exec newspack-docker-db-1 \
                mysql -h localhost -u root -p"${MYSQL_ROOT_PASSWORD}" -e "DROP DATABASE IF EXISTS \`${db_name}\`" 2>/dev/null
            echo "Dropped database $db_name"
        fi
        # Remove env html directory.
        if [[ -d "$NABSPATH/envs/${env_name}" ]]; then
            rm -rf "$NABSPATH/envs/${env_name}"
            echo "Removed envs/${env_name}/"
        fi
        # Remove /etc/hosts entry (only for custom domains, not IP-based).
        if [[ -n "$domain" && "$domain" != "$ip" ]] && grep -q "$domain" /etc/hosts 2>/dev/null; then
            sudo sed -i '' "/$domain/d" /etc/hosts
            echo "Removed $domain from /etc/hosts"
        fi
        # Remove worktrees that were mounted by this environment.
        for wt in "${worktree_entries[@]}"; do
            IFS='/' read -r wt_repo wt_branch <<< "$wt"
            "$NABSPATH/bin/worktree.sh" remove --yes "$wt_repo" "$wt_branch"
        done
        echo "Destroyed environment '$env_name'"
        ;;
    list)
        echo "Environments:"
        for f in "$NABSPATH"/docker-compose.env-*.yml; do
            [[ -f "$f" ]] || continue
            name=$(basename "$f" | sed 's/docker-compose\.env-//' | sed 's/\.yml//')
            container_name=$(echo "newspack_env_${name}" | tr '-' '_')
            domain=$(domain_for_env "$f")
            if status=$(docker inspect -f '{{.State.Status}}' "$container_name" 2>/dev/null); then
                echo "  $name ($status) https://${domain}/"
            else
                echo "  $name (stopped) https://${domain}/"
            fi
        done
        ;;
    *)
        echo "Usage: n env <create|up|down|destroy|list>"
        ;;
esac
