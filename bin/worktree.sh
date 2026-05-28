#!/bin/bash

source "$(dirname "${BASH_SOURCE[0]}")/_common.sh"
source "$(dirname "${BASH_SOURCE[0]}")/repos.sh"

# In the monorepo, all worktrees are of the single workspace repo.
# A worktree at branch "feat/foo" lives at worktrees/feat-foo/ and
# contains the entire monorepo tree. The env system mounts specific
# subdirectories (plugins/<name>, themes/<name>) into the container.

# Sanitize a branch name for use as a directory: feat/foo -> feat-foo.
sanitize_branch() {
    echo "$1" | tr '/' '-'
}

case $1 in
    add)
        # Usage: worktree.sh add <branch>
        # Or legacy compat: worktree.sh add <repo> <branch>
        # (repo is ignored since there's only one git repo now)
        if [[ -n "$3" ]]; then
            # Legacy two-arg form: worktree.sh add <repo> <branch>
            branch="$3"
        else
            branch="$2"
        fi
        if [[ -z "$branch" ]]; then
            echo "Usage: n worktree add <branch>"
            echo "   or: n worktree add <plugin> <branch>  (plugin name is informational only)"
            exit 1
        fi
        validate_name "$(sanitize_branch "$branch")" "branch"
        safe_branch=$(sanitize_branch "$branch")
        worktree_dir="$NABSPATH/worktrees/$safe_branch"
        if [[ -d "$worktree_dir" ]]; then
            echo "Worktree already exists at worktrees/$safe_branch"
            exit 0
        fi
        mkdir -p "$(dirname "$worktree_dir")"
        cd "$NABSPATH" || exit 1
        # Fetch so we see remote branches.
        git fetch origin "$branch" 2>/dev/null
        if git show-ref --verify --quiet "refs/heads/$branch" || git show-ref --verify --quiet "refs/remotes/origin/$branch"; then
            git worktree add "$worktree_dir" "$branch" || exit 1
        else
            echo "Creating branch '$branch' from $(git rev-parse --abbrev-ref HEAD)..."
            git worktree add -b "$branch" "$worktree_dir" || exit 1
        fi
        echo "Created worktree at worktrees/$safe_branch"
        ;;
    list)
        cd "$NABSPATH" || exit 1
        git worktree list
        ;;
    remove)
        skip_confirm=false
        shift  # consume "remove"
        # Parse flags.
        args=()
        for arg in "$@"; do
            if [[ "$arg" == "--yes" ]]; then
                skip_confirm=true
            else
                args+=("$arg")
            fi
        done
        # Support legacy two-arg form: remove <repo> <branch> (repo ignored).
        if [[ ${#args[@]} -ge 2 ]]; then
            branch="${args[1]}"
        elif [[ ${#args[@]} -eq 1 ]]; then
            branch="${args[0]}"
        else
            echo "Usage: n worktree remove <branch> [--yes]"
            exit 1
        fi
        safe_branch=$(sanitize_branch "$branch")
        worktree_dir="$NABSPATH/worktrees/$safe_branch"
        cd "$NABSPATH" || exit 1
        if [[ ! -d "$worktree_dir" ]] && ! git show-ref --verify --quiet "refs/heads/$branch"; then
            echo "Nothing to remove: no worktree or branch '$branch' found."
            exit 0
        fi
        # Block removal if an environment mounts this worktree.
        for f in "$NABSPATH"/docker-compose.env-*.yml; do
            [[ -f "$f" ]] || continue
            if grep -q "worktrees/$safe_branch" "$f" 2>/dev/null; then
                env_name=$(basename "$f" | sed 's/docker-compose\.env-//' | sed 's/\.yml//')
                echo "Error: worktree $safe_branch is used by environment '$env_name'."
                echo "Destroy the environment first: n env destroy $env_name"
                exit 1
            fi
        done
        echo "Worktree: $worktree_dir"
        echo "Branch:   $branch (will be deleted)"
        if [[ -d "$worktree_dir" ]]; then
            changes=$(cd "$worktree_dir" && git status --porcelain 2>/dev/null)
            if [[ -n "$changes" ]]; then
                echo ""
                echo "WARNING: Worktree has uncommitted changes:"
                echo "$changes" | head -10
            fi
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
            git worktree prune
        fi
        git branch -D "$branch" 2>/dev/null && echo "Deleted branch $branch"
        ;;
    cleanup)
        shift
        cleanup_all=false
        cleanup_yes=false
        while [[ $# -gt 0 ]]; do
            case $1 in
                --all) cleanup_all=true; shift ;;
                --yes) cleanup_yes=true; shift ;;
                *) echo "Usage: n worktree cleanup [--all] [--yes]"; exit 1 ;;
            esac
        done
        cd "$NABSPATH" || exit 1
        # Collect all worktrees (skip the main one).
        worktrees=()
        worktree_branches=()
        while IFS= read -r line; do
            wt_path=$(echo "$line" | awk '{print $1}')
            wt_branch=$(echo "$line" | sed 's/.*\[//' | sed 's/\]//')
            [[ "$wt_path" == "$NABSPATH" ]] && continue
            [[ -z "$wt_branch" ]] && continue
            worktrees+=("$wt_path")
            worktree_branches+=("$wt_branch")
        done < <(git worktree list 2>/dev/null)
        if [[ ${#worktrees[@]} -eq 0 ]]; then
            echo "No worktrees to clean up."
            exit 0
        fi
        if [[ "$cleanup_all" != true ]]; then
            if ! [ -t 0 ] || ! [ -t 1 ]; then
                echo "Interactive mode requires a terminal. Use --all --yes for non-interactive cleanup."
                exit 1
            fi
            keep_flags=()
            for i in "${!worktrees[@]}"; do keep_flags[$i]=false; done
            while true; do
                echo ""
                echo "Worktrees (marked for REMOVAL unless toggled):"
                for i in "${!worktrees[@]}"; do
                    branch="${worktree_branches[$i]}"
                    safe=$(sanitize_branch "$branch")
                    env_label=""
                    for f in "$NABSPATH"/docker-compose.env-*.yml; do
                        [[ -f "$f" ]] || continue
                        if grep -q "worktrees/$safe" "$f" 2>/dev/null; then
                            env_name=$(basename "$f" | sed 's/docker-compose\.env-//' | sed 's/\.yml//')
                            env_label=" (env: $env_name)"
                            break
                        fi
                    done
                    if [[ "${keep_flags[$i]}" == true ]]; then
                        echo "  $((i+1)). [KEEP]    $branch$env_label"
                    else
                        echo "  $((i+1)). [REMOVE]  $branch$env_label"
                    fi
                done
                echo ""
                echo "Enter a number to toggle, 'a' to select all for removal, or 'delete' to proceed:"
                read -p "> " choice
                if [[ "$choice" == "delete" ]]; then
                    break
                elif [[ "$choice" == "a" ]]; then
                    for i in "${!worktrees[@]}"; do keep_flags[$i]=false; done
                elif [[ "$choice" =~ ^[0-9]+$ ]] && [[ "$choice" -ge 1 && "$choice" -le ${#worktrees[@]} ]]; then
                    idx=$((choice-1))
                    if [[ "${keep_flags[$idx]}" == true ]]; then
                        keep_flags[$idx]=false
                    else
                        keep_flags[$idx]=true
                    fi
                fi
            done
            to_remove=()
            to_remove_branches=()
            for i in "${!worktrees[@]}"; do
                if [[ "${keep_flags[$i]}" != true ]]; then
                    to_remove+=("${worktrees[$i]}")
                    to_remove_branches+=("${worktree_branches[$i]}")
                fi
            done
        else
            to_remove=("${worktrees[@]}")
            to_remove_branches=("${worktree_branches[@]}")
        fi
        if [[ ${#to_remove[@]} -eq 0 ]]; then
            echo "Nothing to remove."
            exit 0
        fi
        echo "Will remove: ${to_remove_branches[*]}"
        if [[ "$cleanup_yes" != true ]]; then
            read -p "Confirm? (y/N): " confirm
            if [[ ! "$confirm" =~ ^[Yy]$ ]]; then
                echo "Aborted."
                exit 0
            fi
        fi
        for i in "${!to_remove[@]}"; do
            echo ""
            echo "--- Removing ${to_remove_branches[$i]} ---"
            "$NABSPATH/bin/worktree.sh" remove --yes "${to_remove_branches[$i]}"
        done
        ;;
    *)
        echo "Usage: n worktree <add|list|remove|cleanup> [branch]"
        echo "  add <branch>              Create a monorepo worktree at the given branch"
        echo "  list                      List all worktrees"
        echo "  remove <branch> [--yes]   Remove a worktree and delete the branch"
        echo "  cleanup [--all] [--yes]   Interactive bulk cleanup"
        ;;
esac
