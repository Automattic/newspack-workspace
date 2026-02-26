#!/bin/bash

source "$(dirname "${BASH_SOURCE[0]}")/_common.sh"

case $1 in
    add)
        repo="$2"
        branch="$3"
        if [[ -z "$repo" || -z "$branch" ]]; then
            echo "Usage: n worktree add <repo> <branch>"
            exit 1
        fi
        validate_name "$repo" "repo"
        validate_name "$branch" "branch"
        repo_dir="$NABSPATH/repos/$repo"
        if [[ ! -d "$repo_dir/.git" ]]; then
            echo "Error: $repo is not a valid git repo in repos/"
            exit 1
        fi
        worktree_dir="$NABSPATH/worktrees/$repo/$branch"
        mkdir -p "$(dirname "$worktree_dir")"
        cd "$repo_dir" && git worktree add "$worktree_dir" "$branch"
        ;;
    list)
        repo="$2"
        if [[ -n "$repo" ]]; then
            validate_name "$repo" "repo"
            repo_dir="$NABSPATH/repos/$repo"
            if [[ -d "$repo_dir/.git" ]]; then
                cd "$repo_dir" && git worktree list
            else
                echo "Error: $repo is not a valid git repo in repos/"
                exit 1
            fi
        else
            for dir in "$NABSPATH"/repos/*/; do
                if [[ -d "$dir/.git" ]]; then
                    name=$(basename "$dir")
                    worktrees=$(cd "$dir" && git worktree list | tail -n +2)
                    if [[ -n "$worktrees" ]]; then
                        echo "=== $name ==="
                        echo "$worktrees"
                        echo
                    fi
                fi
            done
        fi
        ;;
    remove)
        repo="$2"
        branch="$3"
        if [[ -z "$repo" || -z "$branch" ]]; then
            echo "Usage: n worktree remove <repo> <branch>"
            exit 1
        fi
        validate_name "$repo" "repo"
        validate_name "$branch" "branch"
        worktree_dir="$NABSPATH/worktrees/$repo/$branch"
        repo_dir="$NABSPATH/repos/$repo"
        cd "$repo_dir" || exit 1
        git worktree remove --force "$worktree_dir" || exit 1
        # Clean up empty parent dirs left by branch names with slashes
        dir="$(dirname "$worktree_dir")"
        while [[ "$dir" != "$NABSPATH/worktrees" && "$dir" != "$NABSPATH" ]]; do
            rmdir "$dir" 2>/dev/null || break
            dir="$(dirname "$dir")"
        done
        exit 0
        ;;
    *)
        echo "Usage: n worktree <add|list|remove> [repo] [branch]"
        ;;
esac
