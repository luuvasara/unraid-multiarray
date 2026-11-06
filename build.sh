#!/bin/bash
# build.sh [version] - build the package with the shared plugin kit (kit/). Workstation only, never on a server.
exec "$(dirname "$0")/kit/bin/build.sh" "$@"
