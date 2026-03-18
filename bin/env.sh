#!/bin/bash

source "$(dirname "${BASH_SOURCE[0]}")/_common.sh"

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
                        echo "Error: worktree $wt_repo/$wt_branch does not exist. Run: n worktree add $wt_repo $wt_branch"
                        exit 1
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
            used_ports=$(grep -h 'ports:' -A1 "$NABSPATH"/docker-compose.env-*.yml 2>/dev/null | grep -o '"[0-9]*:80"' | grep -o '[0-9]*:' | tr -d ':')
            port=8081
            while echo "$used_ports" | grep -qx "$port"; do
                port=$((port + 1))
            done
            echo "Auto-assigned port $port"
        fi
        validate_port "$port"
        compose_file="$NABSPATH/docker-compose.env-${env_name}.yml"
        container_name=$(echo "newspack_env_${env_name}" | tr '-' '_')
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
${worktree_volumes}      - ./html:/var/www/html
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
      - APACHE_RUN_USER=\${USE_CUSTOM_APACHE_USER:-www-data}
    extra_hosts:
      - "host.docker.internal:host-gateway"
YAML
        echo "Created $compose_file"
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
        docker compose -f "$NABSPATH/docker-compose.yml" -f "$compose_file" up -d "env-${env_name}"
        if [[ "$3" == "--build" ]]; then
            container_name=$(echo "newspack_env_${env_name}" | tr '-' '_')
            # Extract worktree repo names from the compose override volumes
            grep 'worktrees/' "$compose_file" | sed 's|.*/newspack-repos/||' | while read -r repo; do
                echo "Building $repo in env $env_name..."
                docker exec "$container_name" sh -c "/var/scripts/build-repos.sh $repo ci"
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
        docker stop "$container_name" 2>/dev/null
        docker rm "$container_name" 2>/dev/null
        rm -f "$NABSPATH/docker-compose.env-${env_name}.yml"
        echo "Destroyed environment '$env_name'"
        ;;
    list)
        echo "Environments:"
        for f in "$NABSPATH"/docker-compose.env-*.yml; do
            [[ -f "$f" ]] || continue
            name=$(basename "$f" | sed 's/docker-compose\.env-//' | sed 's/\.yml//')
            container_name=$(echo "newspack_env_${name}" | tr '-' '_')
            if status=$(docker inspect -f '{{.State.Status}}' "$container_name" 2>/dev/null); then
                echo "  $name ($status)"
            else
                echo "  $name (stopped)"
            fi
        done
        ;;
    *)
        echo "Usage: n env <create|up|down|destroy|list>"
        ;;
esac
