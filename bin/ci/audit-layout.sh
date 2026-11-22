#!/usr/bin/env bash
# The layout audit of every package: a class whose name says its role (Controller, Listener, Subscriber,
# Command, Exception) lives in the folder of that role. Exits 1 and lists the classes that are not.
# Run once by bin/ci/mcp-server.sh; by hand too, from anywhere:
#
#   bin/ci/audit-layout.sh
set -euo pipefail

cd "$(dirname "${BASH_SOURCE[0]}")/../../packages"

declare -A want=([Controller]=Controller [Listener]=EventListener [Subscriber]=EventSubscriber [Command]=Command [Exception]=Exception)
misplaced=0
for package in */; do
    package=${package%/}
    [ -d "$package/src" ] || continue
    while IFS= read -r file; do
        rel=${file#"$package/src/"}
        base=$(basename "$file" .php)
        dir=$(dirname "$rel")
        for suffix in Controller Listener Subscriber Command Exception; do
            if [[ "$base" == *"$suffix" && "$base" != "$suffix" ]]; then
                expected=${want[$suffix]}
                if [[ "/$dir/" != *"/$expected/"* ]]; then
                    echo "packages/$package/src/$rel: expected in $expected/" >&2
                    misplaced=$((misplaced + 1))
                fi
            fi
        done
    done < <(find "$package/src" -name '*.php' | sort)
done

if [ "$misplaced" -gt 0 ]; then
    echo "$misplaced class(es) outside the folder of their role." >&2
    exit 1
fi
echo "Layout audit: every class is in the folder of its role."
