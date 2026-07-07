#!/bin/bash

source "$(dirname "${BASH_SOURCE[0]}")/_common.sh"
source "$(dirname "${BASH_SOURCE[0]}")/repos.sh"
source "$(dirname "${BASH_SOURCE[0]}")/worktree-tooling.sh"

# Two kinds of worktrees:
#
#   Tier 1 (default) — workspace worktrees of the monorepo itself. A worktree
#   at branch "feat/foo" lives at worktrees/feat-foo/ and contains the entire
#   monorepo tree. The env system mounts specific subdirectories
#   (plugins/<name>, themes/<name>) into the container.
#
#   Tier 2 (opt-in via --repo) — worktrees of a standalone checkout at
#   repos/{plugins,themes}/<name>/. Lives at worktrees/standalone/<name>/<safe_branch>/,
#   created from the standalone repo's own git history. The checkout is
#   discovered by path -- no registration needed.

# Sanitize a branch name for use as a directory: feat/foo -> feat-foo.
sanitize_branch() {
    echo "$1" | tr '/' '-'
}

# Resolve a standalone repos/ checkout's host path by auto-discovery: a checkout
# at repos/{plugins,themes}/<name>/ is matched by path (no registration). Prints
# the relative host path (repos/plugins/<name>) or nothing if none is found.
resolve_standalone_host_path() {
    local name="$1"
    if [[ -e "$NABSPATH/repos/plugins/$name/.git" ]]; then
        echo "repos/plugins/$name"
    elif [[ -e "$NABSPATH/repos/themes/$name/.git" ]]; then
        echo "repos/themes/$name"
    fi
}

case $1 in
    add)
        # Usage: worktree.sh add <branch>                 # tier 1 (workspace)
        #    or: worktree.sh add <repo> <branch>          # legacy tier 1 (repo informational)
        #    or: worktree.sh add <branch> --repo <name>   # tier 2 (standalone repo)
        shift  # consume "add"
        repo=""
        no_install=false
        positionals=()
        while [[ $# -gt 0 ]]; do
            case "$1" in
                --repo) repo="$2"; shift 2 ;;
                --no-install) no_install=true; shift ;;
                *) positionals+=("$1"); shift ;;
            esac
        done
        if [[ ${#positionals[@]} -eq 1 ]]; then
            branch="${positionals[0]}"
        elif [[ ${#positionals[@]} -eq 2 && -z "$repo" ]]; then
            # Legacy two-arg form: add <repo> <branch>. The repo arg is informational
            # for tier 1 (there's one workspace repo); use the second positional as branch.
            branch="${positionals[1]}"
        else
            echo "Usage: n worktree add <branch> [--repo <name>] [--no-install]"
            echo "   or: n worktree add <repo> <branch>  (legacy; repo arg ignored for tier 1)"
            exit 1
        fi
        validate_name "$branch" "branch"
        safe_branch=$(sanitize_branch "$branch")

        if [[ -n "$repo" ]]; then
            # Tier 2: worktree of a standalone repos/ checkout (auto-discovered by path).
            repo_path=$(resolve_standalone_host_path "$repo")
            if [[ -z "$repo_path" ]]; then
                echo "Error: no repos/plugins/$repo or repos/themes/$repo checkout found (standalone repos are discovered by path)"
                exit 1
            fi
            standalone_dir="$NABSPATH/$repo_path"
            worktree_dir="$NABSPATH/worktrees/standalone/$repo/$safe_branch"
            if [[ -d "$worktree_dir" ]]; then
                echo "Worktree already exists at worktrees/standalone/$repo/$safe_branch"
                exit 0
            fi
            mkdir -p "$(dirname "$worktree_dir")"
            cd "$standalone_dir" || exit 1
            git fetch origin "$branch" 2>/dev/null
            if git show-ref --verify --quiet "refs/heads/$branch" || git show-ref --verify --quiet "refs/remotes/origin/$branch"; then
                git worktree add "$worktree_dir" "$branch" || exit 1
            else
                echo "Creating branch '$branch' in $repo from $(git rev-parse --abbrev-ref HEAD)..."
                git worktree add -b "$branch" "$worktree_dir" || exit 1
            fi
            echo "Created worktree at worktrees/standalone/$repo/$safe_branch"
            exit 0
        fi

        # Tier 1: workspace worktree.
        worktree_dir="$NABSPATH/worktrees/$safe_branch"
        if [[ -d "$worktree_dir" ]]; then
            echo "Worktree already exists at worktrees/$safe_branch"
            exit 0
        fi
        mkdir -p "$(dirname "$worktree_dir")"
        cd "$NABSPATH" || exit 1
        git fetch origin "$branch" 2>/dev/null
        if git show-ref --verify --quiet "refs/heads/$branch" || git show-ref --verify --quiet "refs/remotes/origin/$branch"; then
            git worktree add "$worktree_dir" "$branch" || exit 1
        else
            echo "Creating branch '$branch' from $(git rev-parse --abbrev-ref HEAD)..."
            git worktree add -b "$branch" "$worktree_dir" || exit 1
        fi
        echo "Created worktree at worktrees/$safe_branch"
        activate_worktree_tooling "$worktree_dir" "$no_install"
        ;;
    list)
        cd "$NABSPATH" || exit 1
        git worktree list
        # Also enumerate worktrees of standalone repos (tier 2). Standalone
        # checkouts live at repos/{plugins,themes}/<name> (typed); resolve the
        # actual path via get_repo_host_path rather than assuming a flat repos/.
        for kind in plugins themes; do
            for d in "$NABSPATH/repos/$kind"/*/; do
                [[ -e "${d}.git" ]] || continue
                r=$(basename "$d")
                echo ""
                echo "[$r]"
                ( cd "$d" && git worktree list )
            done
        done
        ;;
    remove)
        # Usage: worktree.sh remove <branch> [--yes]                  # tier 1
        #    or: worktree.sh remove <repo> <branch> [--yes]           # legacy tier 1
        #    or: worktree.sh remove <branch> --repo <name> [--yes]    # tier 2
        skip_confirm=false
        shift  # consume "remove"
        repo=""
        positionals=()
        while [[ $# -gt 0 ]]; do
            case "$1" in
                --yes) skip_confirm=true; shift ;;
                --repo) repo="$2"; shift 2 ;;
                *) positionals+=("$1"); shift ;;
            esac
        done
        if [[ ${#positionals[@]} -eq 1 ]]; then
            branch="${positionals[0]}"
        elif [[ ${#positionals[@]} -eq 2 && -z "$repo" ]]; then
            # Legacy: remove <repo> <branch>. Repo arg is informational for tier 1.
            branch="${positionals[1]}"
        else
            echo "Usage: n worktree remove <branch> [--repo <name>] [--yes]"
            echo "   or: n worktree remove <repo> <branch> [--yes]  (legacy; repo arg ignored for tier 1)"
            exit 1
        fi
        safe_branch=$(sanitize_branch "$branch")

        if [[ -n "$repo" ]]; then
            # Tier 2: standalone repos/ checkout worktree (auto-discovered by path).
            repo_path=$(resolve_standalone_host_path "$repo")
            if [[ -z "$repo_path" ]]; then
                echo "Error: no repos/plugins/$repo or repos/themes/$repo checkout found (standalone repos are discovered by path)"
                exit 1
            fi
            standalone_dir="$NABSPATH/$repo_path"
            worktree_dir="$NABSPATH/worktrees/standalone/$repo/$safe_branch"
            if [[ ! -d "$worktree_dir" ]] && ! (cd "$standalone_dir" 2>/dev/null && git show-ref --verify --quiet "refs/heads/$branch"); then
                echo "Nothing to remove: no worktree or branch '$branch' found in $repo."
                exit 0
            fi
            # Block removal if an environment mounts this worktree.
            for f in "$NABSPATH"/docker-compose.env-*.yml; do
                [[ -f "$f" ]] || continue
                if grep -q "worktrees/standalone/$repo/$safe_branch" "$f" 2>/dev/null; then
                    env_name=$(basename "$f" | sed 's/docker-compose\.env-//' | sed 's/\.yml//')
                    echo "Error: worktree standalone/$repo/$safe_branch is used by environment '$env_name'."
                    echo "Destroy the environment first: n env destroy $env_name"
                    exit 1
                fi
            done
            echo "Worktree: $worktree_dir"
            echo "Branch:   $branch in $repo (will be deleted)"
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
            cd "$standalone_dir" || exit 1
            if [[ -d "$worktree_dir" ]]; then
                git worktree remove --force "$worktree_dir" || exit 1
            else
                git worktree prune
            fi
            git branch -D "$branch" 2>/dev/null && echo "Deleted branch $branch in $repo"
            # Best-effort cleanup of empty parent dirs.
            rmdir "$NABSPATH/worktrees/standalone/$repo" 2>/dev/null
            rmdir "$NABSPATH/worktrees/standalone" 2>/dev/null
            exit 0
        fi

        # Tier 1: workspace worktree.
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
        echo "Usage: n worktree <add|list|remove|cleanup> [args]"
        echo "  add <branch> [--repo <name>] [--no-install]  Create a worktree at the given branch"
        echo "                                               (--repo: a standalone repos/{plugins,themes}/<name> checkout)"
        echo "                                               (--no-install: skip auto 'pnpm install'; ignored for --repo/tier-2)"
        echo "  list                                         List all worktrees (workspace + standalone)"
        echo "  remove <branch> [--repo <name>] [--yes]      Remove a worktree and delete the branch"
        echo "  cleanup [--all] [--yes]                      Interactive bulk cleanup (workspace worktrees only)"
        ;;
esac
