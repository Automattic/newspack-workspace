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
        cd "$repo_dir" || exit 1
        # Fetch so we see remote branches that haven't been pulled yet.
        git fetch origin "$branch" 2>/dev/null
        if git show-ref --verify --quiet "refs/heads/$branch" || git show-ref --verify --quiet "refs/remotes/origin/$branch"; then
            git worktree add "$worktree_dir" "$branch" || exit 1
        else
            echo "Creating branch '$branch' from $(git rev-parse --abbrev-ref HEAD)..."
            git worktree add -b "$branch" "$worktree_dir" || exit 1
        fi
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
        skip_confirm=false
        if [[ "$4" == "--yes" || "$2" == "--yes" ]]; then
            skip_confirm=true
        fi
        # Support: n worktree remove --yes <repo> <branch>
        if [[ "$2" == "--yes" ]]; then
            repo="$3"
            branch="$4"
        fi
        if [[ -z "$repo" || -z "$branch" ]]; then
            echo "Usage: n worktree remove <repo> <branch> [--yes]"
            exit 1
        fi
        validate_name "$repo" "repo"
        validate_name "$branch" "branch"
        worktree_dir="$NABSPATH/worktrees/$repo/$branch"
        repo_dir="$NABSPATH/repos/$repo"
        cd "$repo_dir" || exit 1
        # Check if worktree or branch actually exists.
        if [[ ! -d "$worktree_dir" ]] && ! git show-ref --verify --quiet "refs/heads/$branch"; then
            echo "Nothing to remove: no worktree or branch '$branch' found for $repo."
            exit 0
        fi
        # Block removal if an environment mounts this worktree.
        for f in "$NABSPATH"/docker-compose.env-*.yml; do
            [[ -f "$f" ]] || continue
            if grep -q "worktrees/$repo/$branch" "$f" 2>/dev/null; then
                env_name=$(basename "$f" | sed 's/docker-compose\.env-//' | sed 's/\.yml//')
                echo "Error: worktree $repo/$branch is used by environment '$env_name'."
                echo "Destroy the environment first: n env destroy $env_name"
                exit 1
            fi
        done
        # Show details and confirm.
        echo "Worktree: $worktree_dir"
        echo "Branch:   $branch (will be deleted)"
        echo "Repo:     $repo"
        # Check for uncommitted changes.
        if [[ -d "$worktree_dir" ]]; then
            changes=$(cd "$worktree_dir" && git status --porcelain 2>/dev/null)
            if [[ -n "$changes" ]]; then
                echo ""
                echo "WARNING: Worktree has uncommitted changes:"
                echo "$changes" | head -10
                count=$(echo "$changes" | wc -l | tr -d ' ')
                if [[ "$count" -gt 10 ]]; then
                    echo "  ... and $((count - 10)) more"
                fi
            fi
            # Show unpushed commits.
            unpushed=$(cd "$worktree_dir" && git log --oneline "origin/$branch..$branch" 2>/dev/null)
            if [[ -n "$unpushed" ]]; then
                echo ""
                echo "WARNING: Branch has unpushed commits:"
                echo "$unpushed"
            fi
        fi
        if [[ "$skip_confirm" != true ]]; then
            echo ""
            read -p "Remove worktree and delete branch? (y/N): " confirm
            if [[ ! "$confirm" =~ ^[Yy]$ ]]; then
                echo "Aborted."
                exit 0
            fi
        fi
        if [[ -d "$worktree_dir" ]]; then
            git worktree remove --force "$worktree_dir" || exit 1
        else
            # Directory already gone — clean up stale git worktree reference.
            git worktree prune
        fi
        # Delete the local branch.
        git branch -D "$branch" 2>/dev/null && echo "Deleted branch $branch"
        # Clean up empty parent dirs left by branch names with slashes.
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
