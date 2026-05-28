ncd() {

	# If no argument is provided, go to the root of the newspack-workspace folder:
	if [ -z "$1" ]
	then
		cd $NEWSPACK_DOCKER_ROOT
		return
	fi

	# Newspack Manager html:
	if [[ "$1" == "manager" ]]; then
		cd "$NEWSPACK_DOCKER_ROOT/manager-html"
		return
	fi

	# Monorepo layout: check plugins/, themes/, then packages/.
	for dir in plugins themes packages; do
		if [[ -d "$NEWSPACK_DOCKER_ROOT/$dir/$1" ]]; then
			cd "$NEWSPACK_DOCKER_ROOT/$dir/$1"
			return
		fi
		if [[ -d "$NEWSPACK_DOCKER_ROOT/$dir/newspack-$1" ]]; then
			cd "$NEWSPACK_DOCKER_ROOT/$dir/newspack-$1"
			return
		fi
	done

	# An additional site:
	if [[ -d "$NEWSPACK_DOCKER_ROOT/additional-sites-html/$1" ]]; then
		cd "$NEWSPACK_DOCKER_ROOT/additional-sites-html/$1"
		return
	fi

	# A plugin in the main site:
	if [[ -d "$NEWSPACK_DOCKER_ROOT/html/wp-content/plugins/$1" ]]; then
		cd "$NEWSPACK_DOCKER_ROOT/html/wp-content/plugins/$1"
		return
	fi

	echo "No matches found for $1"

}
