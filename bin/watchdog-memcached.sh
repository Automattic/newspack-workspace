#!/bin/bash
#
# Keeps memcached alive inside the container.
#
# Nothing else supervises it, and a dead memcached is invisible from the outside:
# the object cache drop-in keeps serving a request-scoped array, so the site looks
# fine while cache invalidation silently stops working.
#
# This lives in bin/ (bind-mounted at /var/scripts) rather than inline in run.sh so
# that changes to the supervision logic take effect on a container restart, without
# rebuilding the image.
#
# Deliberately no `set -e`: a failed probe or a failed restart must not end the
# loop. A watchdog that exits on the first bad night reintroduces exactly the
# silent degradation it exists to prevent.
#
# Named watchdog-memcached rather than memcached-watchdog deliberately: the kernel
# truncates process names to 15 characters, and `pkill memcached` matches that name
# as a substring. The other ordering truncates to "memcached-watch", so anyone
# reaching for pkill to clear a stuck memcached would take the supervisor with it,
# precisely when it is needed most.

INTERVAL="${MEMCACHED_WATCHDOG_INTERVAL:-30}"
if ! [[ "$INTERVAL" =~ ^[0-9]+$ ]] || [ "$INTERVAL" -lt 1 ]; then
	# Without `set -e` a non-numeric interval would fail every sleep instantly
	# and spin the loop at full CPU for the container's whole life.
	echo "[watchdog-memcached] MEMCACHED_WATCHDOG_INTERVAL='$INTERVAL' is not a positive integer; falling back to 30 seconds."
	INTERVAL=30
fi

while true; do
	sleep "$INTERVAL"

	if (echo > /dev/tcp/127.0.0.1/11211) 2>/dev/null; then
		continue
	fi

	echo "[watchdog-memcached] memcached is not answering on 11211; restarting it."

	# Kill by exact process name rather than using the init script's stop/restart:
	# those match on a pid file memcached may have left stale after a crash, and
	# container pids recycle fast enough that signalling it could hit an unrelated
	# process. The start wrapper unlinks a stale pid file before forking.
	pkill -x memcached >/dev/null 2>&1

	# Give the old process a moment to release 11211, so the restart below has a
	# port to bind to. Losing the race only costs one cycle, but the wait is free.
	sleep 1

	/etc/init.d/memcached start >/dev/null 2>&1 || true
done
