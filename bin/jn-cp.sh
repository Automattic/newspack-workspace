#!/bin/bash
source /var/scripts/repos.sh
source /var/scripts/jn-functions.sh

shopt -s extglob nullglob
basedir=/var/www/html/wp-content/plugins

# You may omit the following subdirectories
# the syntax is that of extended globs, e.g.,
# omitdir="cmmdm|not_this_+([[:digit:]])|keep_away*"
# If you don't want to omit any subdirectories, leave empty: omitdir=
omitdir=cmmdm

# Create array
if [[ -z $omitdir ]]; then
   all_plugins=( "$basedir"/*/ )
else
   all_plugins=( "$basedir"/!($omitdir)/ )
fi
# remove leading basedir:
all_plugins=( "${all_plugins[@]#"$basedir/"}" )
all_plugins=( "${all_plugins[@]%/}" )

declare -A opts
for i in "${all_plugins[@]}"
do
    opts["$i"]="( )"
done

choice () {
    local choice=$1
    echo $1
    if [[ "${opts["$choice"]}" == "(x)" ]] # toggle
    then
        opts["$choice"]="( )"
    else
        opts["$choice"]="(x)"
    fi
}

selectedAll=false

PS3='Please enter your choice: '
while :
do
    clear
    declare -A options
    
    for i in "${all_plugins[@]}"
    do
        options["$i"]="${opts["$i"]} $i"
    done
    options[9998]="All"
    options[9999]="Continue"
    select opt in "${options[@]}"
    do
        case $opt in
            "Continue")
                break 2
                ;;
            "All")
                selectedAll=true
                break 2
                ;;
            *)
                choice ${opt:4}
                break
                ;;
        esac
    done
done

if $selectedAll ; then
    for opt in "${!opts[@]}"
    do
        opts["$opt"]="(x)"
    done
fi

echo -n Password: 
read -s password
echo

for opt in "${!opts[@]}"
do
    if [[ ${opts["$opt"]} == "(x)" ]]
    then
        process_plugin $opt $password
    fi
done

