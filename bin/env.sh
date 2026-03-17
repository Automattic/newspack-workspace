#!/bin/bash

source "$(dirname "${BASH_SOURCE[0]}")/_common.sh"

# Sanitize env name for use as a database name (replace dashes with underscores).
db_name_for_env() {
    echo "wordpress_$(echo "$1" | tr '-' '_')"
}

case $1 in
    create)
        env_name="$2"
        if [[ -z "$env_name" ]]; then
            echo "Usage: n env create <name> --worktree <repo>:<branch> [--worktree ...] [--port <port>]"
            exit 1
        fi
        validate_env_name "$env_name"
        shift 2
        worktree_volumes=""
        port=""
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
                --port)
                    if [[ -z "$2" || "$2" == --* ]]; then
                        echo "Error: --port requires a value"
                        exit 1
                    fi
                    port="$2"
                    shift 2
                    ;;
                *)
                    echo "Unknown option: $1"
                    exit 1
                    ;;
            esac
        done
        if [[ -z "$port" ]]; then
            used_ports=$(docker ps --format '{{.Ports}}' 2>/dev/null | grep -o '0.0.0.0:[0-9]*' | cut -d: -f2)
            port=8081
            while echo "$used_ports" | grep -qx "$port"; do
                port=$((port + 1))
            done
            echo "Auto-assigned port $port"
        fi
        validate_port "$port"
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
      - "${port}:80"
    env_file:
      - default.env
      - .env
    environment:
      - HOST_PORT=${port}
      - MYSQL_DATABASE=${db_name}
      - WP_DOMAIN=localhost
      - APACHE_RUN_USER=\${USE_CUSTOM_APACHE_USER:-www-data}
    extra_hosts:
      - "host.docker.internal:host-gateway"
    networks:
      - default
YAML
        echo "Created $compose_file (db: $db_name, html: envs/${env_name}/html/)"
        echo "Run: n env up $env_name"
        ;;
    up)
        env_name="$2"
        if [[ -z "$env_name" ]]; then
            echo "Usage: n env up <name> [--build]"
            exit 1
        fi
        validate_env_name "$env_name"
        compose_file="$NABSPATH/docker-compose.env-${env_name}.yml"
        if [[ ! -f "$compose_file" ]]; then
            echo "Error: environment '$env_name' not found. Run: n env create $env_name ..."
            exit 1
        fi
        container_name=$(echo "newspack_env_${env_name}" | tr '-' '_')
        db_name=$(db_name_for_env "$env_name")
        # Read port from compose file.
        port=$(grep -o '"[0-9]*:80"' "$compose_file" | grep -o '[0-9]*:' | tr -d ':')
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
        # Auto-install WordPress if not already installed.
        echo "Waiting for WordPress setup..."
        for i in $(seq 1 20); do
            if docker exec "$container_name" wp --allow-root core is-installed 2>/dev/null; then
                break
            fi
            if docker exec "$container_name" test -f /var/www/html/wp-config.php 2>/dev/null; then
                echo "Installing WordPress..."
                docker exec "$container_name" wp --allow-root core install \
                    --url="http://localhost:${port}" \
                    --title="${WP_TITLE:-Newspack}" \
                    --admin_user="${WP_ADMIN_USER:-admin}" \
                    --admin_password="${WP_ADMIN_PASSWORD:-password}" \
                    --admin_email="${WP_ADMIN_EMAIL:-wordpress@example.com}" \
                    --skip-email
                break
            fi
            sleep 3
        done
        echo "Environment '$env_name' is ready at http://localhost:${port}/"
        # Copy built assets from main repos into worktrees.
        if [[ "$3" == "--build" ]]; then
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
        container_name=$(echo "newspack_env_${env_name}" | tr '-' '_')
        db_name=$(db_name_for_env "$env_name")
        docker stop "$container_name" 2>/dev/null
        docker rm "$container_name" 2>/dev/null
        rm -f "$NABSPATH/docker-compose.env-${env_name}.yml"
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
        echo "Destroyed environment '$env_name'"
        ;;
    list)
        echo "Environments:"
        for f in "$NABSPATH"/docker-compose.env-*.yml; do
            [[ -f "$f" ]] || continue
            name=$(basename "$f" | sed 's/docker-compose\.env-//' | sed 's/\.yml//')
            container_name=$(echo "newspack_env_${name}" | tr '-' '_')
            port=$(grep -o '"[0-9]*:80"' "$f" | grep -o '[0-9]*:' | tr -d ':')
            if status=$(docker inspect -f '{{.State.Status}}' "$container_name" 2>/dev/null); then
                echo "  $name ($status) http://localhost:${port}/"
            else
                echo "  $name (stopped) http://localhost:${port}/"
            fi
        done
        ;;
    *)
        echo "Usage: n env <create|up|down|destroy|list>"
        ;;
esac
