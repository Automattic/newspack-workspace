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

	# The complete name of a repo:
	if [[ -d "$NEWSPACK_DOCKER_ROOT/repos/$1" ]]; then
		cd "$NEWSPACK_DOCKER_ROOT/repos/$1"
		return
	fi

	# The name of a repo without the newspack- prefix:
	if [[ -d "$NEWSPACK_DOCKER_ROOT/repos/newspack-$1" ]]; then
		cd "$NEWSPACK_DOCKER_ROOT/repos/newspack-$1"
		return
	fi

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
