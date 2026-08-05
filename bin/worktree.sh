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

# Create a git worktree at <worktree_dir> for <branch>, running git in <git_dir>
# (the workspace for monorepo worktrees, or a standalone repos/ checkout). Shared
# by `add` and `add-repos`. Fetches the branch into its remote-tracking ref first
# with an explicit, forced refspec -- `git fetch origin <branch>` alone only
# writes FETCH_HEAD, leaving refs/remotes/origin/<branch> absent, so a remote-only
# branch would be missed and a new local branch wrongly created from HEAD. Falls
# back to creating the branch from <git_dir>'s current HEAD when it exists nowhere.
# Returns the worktree-add exit status.
_worktree_create() {
    local git_dir="$1" worktree_dir="$2" branch="$3"
    mkdir -p "$(dirname "$worktree_dir")"
    git -C "$git_dir" fetch origin "+$branch:refs/remotes/origin/$branch" 2>/dev/null
    if git -C "$git_dir" show-ref --verify --quiet "refs/heads/$branch" || \
       git -C "$git_dir" show-ref --verify --quiet "refs/remotes/origin/$branch"; then
        git -C "$git_dir" worktree add "$worktree_dir" "$branch"
    else
        echo "Creating branch '$branch' from $(git -C "$git_dir" rev-parse --abbrev-ref HEAD)..."
        git -C "$git_dir" worktree add -b "$branch" "$worktree_dir"
    fi
}

# Echo the name of the first environment whose compose file mounts <pattern>
# (a fixed host-path substring), or nothing. Shared by `remove` and `remove-repos`
# to block removal of an in-use worktree. Callers pass a pattern anchored with a
# trailing '/' or ':' so branch 'feat' isn't matched inside 'feature'; -F keeps
# any dot in the name/branch literal. Returns non-zero when no env matches.
_worktree_env_using() {
    local pattern="$1" f
    for f in "$NABSPATH"/docker-compose.env-*.yml; do
        [[ -f "$f" ]] || continue
        if grep -qF "$pattern" "$f" 2>/dev/null; then
            basename "$f" | sed 's/docker-compose\.env-//' | sed 's/\.yml//'
            return 0
        fi
    done
    return 1
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
        _worktree_create "$NABSPATH" "$worktree_dir" "$branch" || exit 1
        echo "Created worktree at worktrees/$safe_branch"
        ;;
    add-repos)
        # Usage: worktree.sh add-repos <name> <branch>
        # Creates a worktree of a *standalone* checkout under repos/ (its own git
        # repo, e.g. newspack-manager), stored at worktrees-repos/<name>/<safe_branch>
        # so it never lands under repos/ itself (link-repos.sh would otherwise
        # symlink it as a second plugin). The env system mounts it over the
        # canonical container path (/newspack-repos/{plugins,themes}/<name>).
        name="$2"
        branch="$3"
        if [[ -z "$name" || -z "$branch" ]]; then
            echo "Usage: n worktree add-repos <name> <branch>"
            exit 1
        fi
        validate_name "$name" "repo"
        validate_name "$(sanitize_branch "$branch")" "branch"
        host_path=$(get_standalone_repo_host_path "$name")
        if [[ -z "$host_path" ]]; then
            echo "Error: no standalone checkout 'repos/plugins/$name' or 'repos/themes/$name' found."
            echo "Clone or unzip it into repos/ first."
            exit 1
        fi
        if ! is_standalone_git_repo "$host_path"; then
            echo "Error: $host_path is not its own git repository, so it can't be worktree'd."
            echo "(Its git lookups resolve to the monorepo. Standalone worktrees need a separate repo with its own .git.)"
            exit 1
        fi
        repos_dir="$NABSPATH/$host_path"
        safe_branch=$(sanitize_branch "$branch")
        worktree_dir="$NABSPATH/worktrees-repos/$name/$safe_branch"
        if [[ -d "$worktree_dir" ]]; then
            echo "Worktree already exists at worktrees-repos/$name/$safe_branch"
            exit 0
        fi
        _worktree_create "$repos_dir" "$worktree_dir" "$branch" || exit 1
        echo "Created worktree at worktrees-repos/$name/$safe_branch"
        ;;
    remove-repos)
        # Usage: worktree.sh remove-repos <name> <safe_branch> [--yes]
        # Removes a standalone-repo worktree created by add-repos. Unlike the
        # monorepo `remove`, this does NOT delete the branch: standalone repos
        # carry long-lived feature branches the user still wants, and the worktree
        # is the only disposable artifact. Re-creating reuses the existing branch.
        skip_confirm=false
        shift  # consume "remove-repos"
        args=()
        for arg in "$@"; do
            if [[ "$arg" == "--yes" ]]; then
                skip_confirm=true
            else
                args+=("$arg")
            fi
        done
        name="${args[0]}"
        safe_branch="${args[1]}"
        if [[ -z "$name" || -z "$safe_branch" ]]; then
            echo "Usage: n worktree remove-repos <name> <safe_branch> [--yes]"
            exit 1
        fi
        # Reject '..'/leading-'/' etc. before these reach the rm target below --
        # otherwise a direct call like `remove-repos ../.. x` would delete outside
        # worktrees-repos. (The add path validates too; the destructive path must.)
        validate_name "$name" "repo"
        validate_name "$safe_branch" "branch"
        worktree_dir="$NABSPATH/worktrees-repos/$name/$safe_branch"
        host_path=$(get_standalone_repo_host_path "$name")
        if [[ ! -d "$worktree_dir" ]]; then
            # Dir already gone; prune any stale registration the source repo keeps.
            if [[ -n "$host_path" ]] && is_standalone_git_repo "$host_path"; then
                git -C "$NABSPATH/$host_path" worktree prune 2>/dev/null || true
            fi
            echo "Nothing to remove: no worktree at worktrees-repos/$name/$safe_branch."
            exit 0
        fi
        # Block removal if an environment mounts this worktree (host path anchored
        # with a trailing ':' so branch 'feat' isn't matched inside 'feature').
        env_name=$(_worktree_env_using "worktrees-repos/$name/$safe_branch:")
        if [[ -n "$env_name" ]]; then
            echo "Error: worktree $name/$safe_branch is used by environment '$env_name'."
            echo "Destroy the environment first: n env destroy $env_name"
            exit 1
        fi
        echo "Worktree: $worktree_dir"
        changes=$(cd "$worktree_dir" && git status --porcelain 2>/dev/null)
        if [[ -n "$changes" ]]; then
            echo ""
            echo "WARNING: Worktree has uncommitted changes:"
            echo "$changes" | head -10
        fi
        if [[ "$skip_confirm" != true ]]; then
            echo ""
            read -p "Remove worktree? (branch is kept) (y/N): " confirm
            if [[ ! "$confirm" =~ ^[Yy]$ ]]; then
                echo "Aborted."
                exit 0
            fi
        fi
        if [[ -n "$host_path" ]] && is_standalone_git_repo "$host_path"; then
            git -C "$NABSPATH/$host_path" worktree remove --force "$worktree_dir" || rm -rf "$worktree_dir"
            # Clear the registration if the remove failed and we rm'd the dir instead.
            git -C "$NABSPATH/$host_path" worktree prune 2>/dev/null || true
        else
            # Source repo is gone; drop the directory directly.
            rm -rf "$worktree_dir"
        fi
        # Tidy the per-repo parent dir when it holds no more worktrees.
        rmdir "$NABSPATH/worktrees-repos/$name" 2>/dev/null || true
        echo "Removed worktree worktrees-repos/$name/$safe_branch"
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
        # Block removal if an environment mounts this worktree (host path anchored
        # with a trailing '/' -- the monorepo mount is worktrees/<safe_branch>/plugins/…
        # -- so branch 'feat' isn't matched inside 'feat-2').
        env_name=$(_worktree_env_using "worktrees/$safe_branch/")
        if [[ -n "$env_name" ]]; then
            echo "Error: worktree $safe_branch is used by environment '$env_name'."
            echo "Destroy the environment first: n env destroy $env_name"
            exit 1
        fi
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
        echo "Usage: n worktree <add|add-repos|list|remove|remove-repos|cleanup> [args]"
        echo "  add <branch>                       Create a monorepo worktree at the given branch"
        echo "  add-repos <name> <branch>          Create a worktree of a standalone repos/ checkout"
        echo "  list                               List all worktrees"
        echo "  remove <branch> [--yes]            Remove a monorepo worktree and delete the branch"
        echo "  remove-repos <name> <safe_branch> [--yes]  Remove a standalone-repo worktree (keeps the branch)"
        echo "  cleanup [--all] [--yes]            Interactive bulk cleanup"
        ;;
esac
